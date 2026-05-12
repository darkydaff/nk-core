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

        switch ($payload['type']) {
            case 'provision_server':
                if (empty($payload['server_id'])) {
                    throw new Exception("Missing server_id");
                }
                
                $serverId = (int)$payload['server_id'];
                $jobId = isset($payload['job_id']) ? (int)$payload['job_id'] : null;
                $lockName = "server:{$serverId}:deploy";
                
                if (!Lock::acquire($lockName, 600)) {
                    Logger::warning("Deployment already in progress for server $serverId, skipping.");
                    break;
                }

                $orchestration = $jobId ? new Job($jobId) : null;
                
                try {
                    if ($orchestration) $orchestration->start();
                    
                    Logger::channel('deployments')->info('Starting server deployment', ['server_id' => $serverId, 'job_id' => $jobId]);
                    $vpnServer = new VpnServer($serverId);
                    if ($orchestration) $vpnServer->setJob($orchestration);
                    
                    $result = $vpnServer->deploy(false);
                    
                    if ($orchestration) $orchestration->success($result);
                    Logger::channel('deployments')->info('Server deployment successful', ['server_id' => $serverId]);
                } catch (\Throwable $e) {
                    if ($orchestration) $orchestration->fail($e->getMessage());
                    throw $e;
                } finally {
                    Lock::release($lockName);
                }
                break;

            case 'provision_client':
                if (empty($payload['client_id'])) {
                    throw new Exception("Missing client_id");
                }
                
                $clientId = (int)$payload['client_id'];
                $serverId = (int)($payload['server_id'] ?? 0);
                
                // Use a server-level lock to prevent concurrent config writes
                $lockName = "server:{$serverId}:infra";

                if (!Lock::acquire($lockName, 300)) {
                    Logger::warning("Server #$serverId infrastructure is locked, delaying client $clientId", ['client_id' => $clientId]);
                    $pheanstalk->release($job, 10, 30); // Retry in 30s
                    continue 2;
                }

                try {
                    Logger::channel('deployments')->info('Starting client provisioning', ['client_id' => $clientId, 'server_id' => $serverId]);
                    require_once __DIR__ . '/../inc/VpnClient.php';
                    $client = new VpnClient($clientId);
                    $client->syncToRemote();
                    Logger::channel('deployments')->info('Client provisioning successful', ['client_id' => $clientId, 'server_id' => $serverId]);
                } finally {
                    Lock::release($lockName);
                }
                break;

            case 'delete_server':
                if (empty($payload['server_id'])) {
                    throw new Exception("Missing server_id");
                }
                $serverId = (int)$payload['server_id'];
                
                // Use server-level lock
                $lockName = "server:{$serverId}:infra";
                if (!Lock::acquire($lockName, 60)) {
                    $pheanstalk->release($job, 10, 60);
                    continue 2;
                }

                try {
                    Logger::channel('deployments')->info('Starting server deletion cleanup', ['server_id' => $serverId]);
                    
                    $vs = new VpnServer($serverId);
                    $vs->cleanupRemoteResources();
                    
                    Logger::channel('deployments')->info('Server deletion cleanup successful', ['server_id' => $serverId]);
                } finally {
                    Lock::release($lockName);
                }
                break;

            case 'revoke_client':
                if (empty($payload['client_id'])) throw new Exception("Missing client_id");
                $clientId = (int)$payload['client_id'];
                $serverId = (int)($payload['server_id'] ?? 0);
                
                $lockName = "server:{$serverId}:infra";
                if (!Lock::acquire($lockName, 60)) {
                    $pheanstalk->release($job, 10, 30);
                    continue 2;
                }

                try {
                    Logger::channel('deployments')->info('Revoking client infrastructure', ['client_id' => $clientId, 'server_id' => $serverId]);
                    require_once __DIR__ . '/../inc/VpnClient.php';
                    $client = new VpnClient($clientId);
                    $clientData = $client->getData();
                    
                    if ($clientData) {
                        $server = new VpnServer($serverId);
                        VpnClient::removeClientFromServer($server->getData(), $clientData['public_key']);
                    }
                } finally {
                    Lock::release($lockName);
                }
                break;

            case 'delete_client':
                if (empty($payload['client_id'])) throw new Exception("Missing client_id");
                $clientId = (int)$payload['client_id'];
                $serverId = (int)($payload['server_id'] ?? 0);
                
                $lockName = "server:{$serverId}:infra";
                if (!Lock::acquire($lockName, 120)) {
                    $pheanstalk->release($job, 10, 30);
                    continue 2;
                }

                try {
                    Logger::channel('deployments')->info('Deleting client infrastructure', ['client_id' => $clientId, 'server_id' => $serverId]);
                    require_once __DIR__ . '/../inc/VpnClient.php';
                    
                    // We need to get the public key BEFORE we mark it as deleted if possible
                    $client = new VpnClient($clientId);
                    $clientData = $client->getData();
                    
                    if ($clientData) {
                        $server = new VpnServer($serverId);
                        VpnClient::removeClientFromServer($server->getData(), $clientData['public_key']);
                    }
                    
                    // Final soft-delete in DB
                    $pdo = DB::conn();
                    $pdo->prepare('UPDATE vpn_clients SET deleted_at = NOW(), status = ? WHERE id = ?')
                        ->execute([ClientStatus::DELETED->value, $clientId]);
                        
                    Logger::channel('deployments')->info('Client deletion successful', ['client_id' => $clientId, 'server_id' => $serverId]);
                } finally {
                    Lock::release($lockName);
                }
                break;

            case 'sync_all_servers':
                Logger::channel('control-plane')->info('Starting mass server sync');
                $servers = VpnServer::listAll();
                foreach ($servers as $serverData) {
                    if ($serverData['status'] === 'active' || $serverData['status'] === 'error') {
                        Queue::push('deployments', [
                            'type' => 'sync_server',
                            'server_id' => (int)$serverData['id']
                        ]);
                    }
                }
                break;

            case 'sync_server':
                if (empty($payload['server_id'])) {
                    throw new Exception("Missing server_id");
                }
                $serverId = (int)$payload['server_id'];
                $lockName = "server:{$serverId}:sync";

                if (!Lock::acquire($lockName, 60)) {
                    // Quietly skip if already syncing
                    break;
                }

                try {
                    Logger::info('Syncing server', ['server_id' => $serverId]);
                    $vs = new VpnServer($serverId);
                    $vs->updatePingAndStatus();
                    VpnClient::syncAllStatsForServer($serverId);

                    // Only sync proxies if this server actually has any — avoids redundant SSH connections
                    $pxCount = DB::conn()->prepare('SELECT COUNT(*) FROM http_proxies WHERE server_id = ? AND deleted_at IS NULL');
                    $pxCount->execute([$serverId]);
                    if ((int)$pxCount->fetchColumn() > 0) {
                        $proxy = new ProxyServer($serverId);
                        $proxy->syncUsers();
                        $proxy->updateTrafficStats();
                    }
                } finally {
                    Lock::release($lockName);
                }
                break;
                
            default:
                throw new Exception("Unknown job type: " . $payload['type']);
        }

        // Job succeeded, delete it from the queue
        $pheanstalk->delete($job);
        
        Logger::channel('control-plane')->info('Job completed successfully', ['payload' => $payload]);

    } catch (\Throwable $e) {
        if (isset($job)) {
            $stats = $pheanstalk->statsJob($job);
            $reserves = (int)($stats->reserves ?? 0);
            
            // Classification: Permanent vs Transient
            $isPermanent = (
                $e instanceof \InvalidArgumentException || 
                strpos($e->getMessage(), 'Validation failed') !== false ||
                strpos($e->getMessage(), 'already exists') !== false ||
                strpos($e->getMessage(), 'not found') !== false
            );

            Logger::error('Job failed', [
                'payload' => $payload ?? null,
                'error' => $e->getMessage(),
                'attempt' => $reserves,
                'is_permanent' => $isPermanent
            ]);

            // Set DB state to error if max retries reached or permanent error
            if ($reserves >= 5 || $isPermanent) {
                $type = $payload['type'] ?? '';
                $pdo = DB::conn();
                
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
