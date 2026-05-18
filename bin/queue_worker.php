<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/Logger.php';
require_once __DIR__ . '/../inc/Enums.php';
require_once __DIR__ . '/../inc/Lock.php';
require_once __DIR__ . '/../inc/VpnServer.php';
require_once __DIR__ . '/../inc/VpnClient.php';
require_once __DIR__ . '/../inc/ProxyServer.php';
require_once __DIR__ . '/../inc/Translator.php';
require_once __DIR__ . '/../inc/Job.php';
require_once __DIR__ . '/../inc/EventBus.php';

use Pheanstalk\Pheanstalk;
use Pheanstalk\Values\TubeName;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

// Disable time limit for the worker
set_time_limit(0);
ini_set('memory_limit', '512M');

Logger::info('Starting Queue Worker');

$host = getenv('QUEUE_HOST') ?: 'beanstalkd';
$port = (int)(getenv('QUEUE_PORT') ?: 11300);

try {
    $pheanstalk = Pheanstalk::create($host, $port);
    $pheanstalk->watch(new TubeName('deployments'));
    Logger::info('Queue worker watching tube: deployments');
} catch (\Throwable $e) {
    Logger::error('Worker failed to connect to Beanstalkd', ['error' => $e->getMessage()]);
    exit(1);
}

while (true) {
    try {
        // Reserve a job with a 5-second timeout so we can gracefully exit if needed
        $job = $pheanstalk->reserveWithTimeout(5);
        if (!$job) {
            continue;
        }

        $payload = json_decode($job->getData(), true);
        
        Logger::channel('control-plane')->info('Processing job', ['payload' => $payload]);

        if (empty($payload['type'])) {
            throw new Exception("Job missing 'type'");
        }

        // Spawn isolated process to handle the job
        $processScript = __DIR__ . '/process_job.php';
        
        $process = new Process(['php', $processScript, $job->getData()]);
        
        // Hard execution timeout: 600 seconds (10 minutes).
        $process->setTimeout(600);
        $process->setIdleTimeout(120);
        
        $startTime = microtime(true);
        $exitCode = null;
        $outputStr = '';
        
        try {
            $process->run();
            $exitCode = $process->getExitCode();
            $outputStr = trim($process->getErrorOutput() . "\n" . $process->getOutput());
        } catch (ProcessTimedOutException $e) {
            $exitCode = 124; // Standard timeout exit code
            $outputStr = "Process timed out after {$process->getTimeout()} seconds. Idle timeout was {$process->getIdleTimeout()} seconds.";
            throw new Exception("Job execution timeout exceeded: " . $e->getMessage());
        } finally {
            $durationMs = (int)((microtime(true) - $startTime) * 1000);
        }

        if ($exitCode === 0) {
            // Job succeeded, delete it from the queue
            $pheanstalk->delete($job);
            Logger::channel('control-plane')->info('Job completed successfully', ['payload' => $payload]);
        } elseif ($exitCode === 2) {
            // Lock active, transient failure, throw to be caught by the retry logic
            throw new Exception("Job locked or transient failure. Output: " . $outputStr);
        } elseif ($exitCode === 3) {
            // Permanent failure
            throw new \InvalidArgumentException("Permanent job failure. Output: " . $outputStr);
        } else {
            // Unknown or general failure (e.g. fatal error, segfault)
            throw new Exception("Job failed with exit code {$exitCode}. Output: " . $outputStr);
        }

    } catch (\Throwable $e) {
        if (isset($job)) {
            $stats = $pheanstalk->statsJob($job);
            $reserves = (int)($stats->reserves ?? 0);
            
            // Classification: Permanent vs Transient
            $isPermanent = (
                $e instanceof \InvalidArgumentException || 
                strpos($e->getMessage(), 'Validation failed') !== false ||
                strpos($e->getMessage(), 'already exists') !== false ||
                strpos($e->getMessage(), 'not found') !== false ||
                ($exitCode === 3)
            );

            Logger::error('Job failed', [
                'payload' => $payload ?? null,
                'error' => $e->getMessage(),
                'attempt' => $reserves,
                'is_permanent' => $isPermanent,
                'exit_code' => $exitCode,
                'duration_ms' => $durationMs ?? null
            ]);

            // Set DB state to error if max retries reached or permanent error
            if ($reserves >= 5 || $isPermanent) {
                $type = $payload['type'] ?? 'unknown';
                $pdo = DB::conn();
                
                // --- DLQ Quarantine ---
                // Record the failed job in the jobs table if it isn't tracked yet
                $jobId = $payload['job_id'] ?? null;
                $hostname = gethostname();
                $errorSummary = mb_substr($e->getMessage() . "\n" . ($outputStr ?? ''), 0, 5000);
                
                if ($jobId) {
                    $pdo->prepare("UPDATE jobs SET status = 'error', error_summary = ?, attempts = ?, exit_code = ?, duration_ms = ?, worker_hostname = ?, completed_at = NOW() WHERE id = ?")
                        ->execute([$errorSummary, $reserves, $exitCode, $durationMs ?? null, $hostname, $jobId]);
                } else {
                    $pdo->prepare("INSERT INTO jobs (type, server_id, status, payload, error_summary, attempts, exit_code, duration_ms, worker_hostname, started_at, completed_at) VALUES (?, ?, 'error', ?, ?, ?, ?, ?, ?, NOW(), NOW())")
                        ->execute([$type, $payload['server_id'] ?? null, json_encode($payload), $errorSummary, $reserves, $exitCode, $durationMs ?? null, $hostname]);
                }
                
                if ($type === 'provision_server' && isset($payload['server_id'])) {
                    $pdo->prepare("UPDATE vpn_servers SET status = 'error', error_message = ? WHERE id = ?")
                        ->execute([$e->getMessage(), $payload['server_id']]);
                }
                
                if (($type === 'provision_client' || $type === 'sync_server') && isset($payload['client_id'])) {
                    $pdo->prepare("UPDATE vpn_clients SET status = 'error' WHERE id = ?")
                        ->execute([$payload['client_id']]);
                }

                $pheanstalk->delete($job);
                Logger::channel('control-plane')->warning("Job deleted after max retries or permanent error", ['payload' => $payload]);
            } else {
                // Transient error: Retry with exponential backoff (15s, 30s, 60s...)
                $delay = (int)pow(2, $reserves) * 15;
                $pheanstalk->release($job, 10, $delay);
                Logger::info("Job released for retry", ['type' => $payload['type'] ?? 'unknown', 'delay' => $delay]);
            }
        }
    }
    
    // DB::conn() automatically handles closed connections and pinging
}
