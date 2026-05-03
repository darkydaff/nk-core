<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/Logger.php';
require_once __DIR__ . '/../inc/Enums.php';
require_once __DIR__ . '/../inc/VpnServer.php';

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
                
                // Update state to provisioning
                $pdo = DB::conn();
                $pdo->prepare("UPDATE vpn_servers SET status = 'deploying' WHERE id = ?")
                    ->execute([$serverId]);
                    
                Logger::channel('deployments')->info('Starting server deployment', ['server_id' => $serverId]);
                
                $vpnServer = new VpnServer($serverId);
                $vpnServer->deploy(false); // We can split this later if needed
                
                Logger::channel('deployments')->info('Server deployment successful', ['server_id' => $serverId]);
                break;
                
            default:
                throw new Exception("Unknown job type: " . $payload['type']);
        }

        // Job succeeded, delete it from the queue
        $pheanstalk->delete($job);
        
        Logger::channel('control-plane')->info('Job completed successfully', ['payload' => $payload]);

    } catch (\Throwable $e) {
        if (isset($job)) {
            Logger::error('Job failed', [
                'payload' => $payload ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Set DB state to error if it was a server deployment
            if (isset($payload['type']) && $payload['type'] === 'provision_server' && isset($payload['server_id'])) {
                try {
                    $pdo = DB::conn();
                    $pdo->prepare("UPDATE vpn_servers SET status = 'error', error_message = ? WHERE id = ?")
                        ->execute([$e->getMessage(), $payload['server_id']]);
                } catch (\Throwable $dbErr) {
                    Logger::error('Failed to update server status to error', ['error' => $dbErr->getMessage()]);
                }
            }

            // Bury the job so it doesn't get retried infinitely without manual intervention
            $pheanstalk->bury($job);
        }
    }
    
    // DB::conn() automatically handles closed connections and pinging
}
