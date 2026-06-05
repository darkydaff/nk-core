#!/usr/bin/env php
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

// Load configuration
Config::load(__DIR__ . '/../.env');

// Set timezone
date_default_timezone_set('Europe/Moscow');

if ($argc < 2) {
    fwrite(STDERR, "Missing payload argument\n");
    exit(1);
}

$payloadJson = $argv[1];
$payload = json_decode($payloadJson, true);

if (!$payload) {
    fwrite(STDERR, "Invalid JSON payload\n");
    exit(1);
}

try {
    if (empty($payload['type'])) {
        throw new Exception("Job missing 'type'");
    }

    switch ($payload['type']) {
        case 'provision_server':
            if (empty($payload['server_id'])) throw new Exception("Missing server_id");
            
            $serverId = (int)$payload['server_id'];
            $jobId = isset($payload['job_id']) ? (int)$payload['job_id'] : null;
            $lockName = "server:{$serverId}:deploy";
            
            if (!Lock::acquire($lockName, 600)) {
                // Exit with code 2 to indicate temporary lock (should retry)
                exit(2);
            }

            $orchestration = $jobId ? new Job($jobId) : null;
            
            try {
                if ($orchestration) $orchestration->start();
                
                Logger::channel('deployments')->info('Starting server deployment', ['server_id' => $serverId, 'job_id' => $jobId]);
                $vpnServer = new VpnServer($serverId);
                if ($orchestration) $vpnServer->setJob($orchestration);
                
                $result = $vpnServer->deploy(false);
                
                if (!$result->success) {
                    throw new Exception("Deployment failed: " . $result->errorMessage);
                }

                if ($orchestration) $orchestration->success($result->toArray());
                Logger::channel('deployments')->info('Server deployment successful', ['server_id' => $serverId]);
            } catch (\Throwable $e) {
                if ($orchestration) $orchestration->fail($e->getMessage());
                throw $e;
            } finally {
                Lock::release($lockName);
            }
            break;

        case 'provision_client':
            if (empty($payload['client_id'])) throw new Exception("Missing client_id");
            
            $clientId = (int)$payload['client_id'];
            $serverId = (int)($payload['server_id'] ?? 0);
            
            $lockName = "server:{$serverId}:infra";

            if (!Lock::acquire($lockName, 300)) {
                exit(2);
            }

            try {
                Logger::channel('deployments')->info('Starting client provisioning', ['client_id' => $clientId, 'server_id' => $serverId]);
                $client = new VpnClient($clientId);
                $client->syncToRemote();
                Logger::channel('deployments')->info('Client provisioning successful', ['client_id' => $clientId, 'server_id' => $serverId]);
            } finally {
                Lock::release($lockName);
            }
            break;

        case 'delete_server':
            if (empty($payload['server_id'])) throw new Exception("Missing server_id");
            $serverId = (int)$payload['server_id'];
            
            $lockName = "server:{$serverId}:infra";
            if (!Lock::acquire($lockName, 60)) exit(2);

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
            if (!Lock::acquire($lockName, 60)) exit(2);

            try {
                Logger::channel('deployments')->info('Revoking client infrastructure', ['client_id' => $clientId, 'server_id' => $serverId]);
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
            if (!Lock::acquire($lockName, 120)) exit(2);

            try {
                Logger::channel('deployments')->info('Deleting client infrastructure', ['client_id' => $clientId, 'server_id' => $serverId]);
                $client = new VpnClient($clientId);
                $clientData = $client->getData();
                
                if ($clientData) {
                    $server = new VpnServer($serverId);
                    VpnClient::removeClientFromServer($server->getData(), $clientData['public_key']);
                }
                
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
            require_once __DIR__ . '/../inc/Queue.php';
            $servers = VpnServer::listAll();
            foreach ($servers as $serverData) {
                if ($serverData['status'] === 'active' || $serverData['status'] === 'error') {
                    // Queue stats sync
                    Queue::push('deployments', [
                        'type' => 'sync_server',
                        'server_id' => (int)$serverData['id']
                    ]);
                    // Queue config and traffic shaping sync
                    Queue::push('deployments', [
                        'type' => 'sync_server_config',
                        'server_id' => (int)$serverData['id']
                    ]);
                }
            }
            break;

        case 'sync_server_config':
            if (empty($payload['server_id'])) throw new Exception("Missing server_id");
            $serverId = (int)$payload['server_id'];
            $lockName = "server:{$serverId}:infra"; // Reuse infra lock for config modifications
            if (!Lock::acquire($lockName, 120)) exit(2);
            try {
                Logger::info('Syncing server config', ['server_id' => $serverId]);
                $vs = new VpnServer($serverId);
                require_once __DIR__ . '/../inc/VpnConfigRenderer.php';
                $renderer = new VpnConfigRenderer($vs);
                $renderer->syncDeclarative();
            } finally {
                Lock::release($lockName);
            }
            break;

        case 'sync_server':
            if (empty($payload['server_id'])) throw new Exception("Missing server_id");
            $serverId = (int)$payload['server_id'];
            $lockName = "server:{$serverId}:sync";

            if (!Lock::acquire($lockName, 60)) exit(0); // Quietly skip if already syncing

            try {
                Logger::info('Syncing server', ['server_id' => $serverId]);
                $vs = new VpnServer($serverId);
                $vs->updatePingAndStatus();
                $vs->updateGeoIp();
                VpnClient::syncAllStatsForServer($serverId);

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

    exit(0);

} catch (\Throwable $e) {
    // Classification: Permanent vs Transient
    $isPermanent = (
        $e instanceof \InvalidArgumentException || 
        strpos($e->getMessage(), 'Validation failed') !== false ||
        strpos($e->getMessage(), 'already exists') !== false ||
        strpos($e->getMessage(), 'not found') !== false
    );

    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");

    if ($isPermanent) {
        exit(3); // Permanent failure exit code
    }
    
    exit(1); // Transient failure exit code
}
