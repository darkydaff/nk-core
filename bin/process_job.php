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

                $serverData = $vs->getData();
                if (($serverData['warp_installed'] ?? 0) == 1) {
                    require_once __DIR__ . '/../inc/Queue.php';
                    Queue::push('deployments', [
                        'type' => 'warp_health_check',
                        'server_id' => $serverId
                    ]);
                }

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

        case 'warp_install':
            if (empty($payload['server_id'])) throw new Exception("Missing server_id");
            $serverId = (int)$payload['server_id'];
            $jobId = isset($payload['job_id']) ? (int)$payload['job_id'] : null;
            $lockName = "server:{$serverId}:warp";
            
            if (!Lock::acquire($lockName, 600)) {
                exit(2);
            }
            
            $orchestration = $jobId ? new Job($jobId) : null;
            try {
                if ($orchestration) $orchestration->start();
                Logger::channel('deployments')->info('Starting Cloudflare WARP installation', ['server_id' => $serverId]);
                
                $vs = new VpnServer($serverId);
                if ($orchestration) $vs->setJob($orchestration);
                
                $serverData = $vs->getData();
                $vpnSubnet = $serverData['vpn_subnet'] ?? '10.8.1.0/24';
                
                require_once __DIR__ . '/../inc/LinuxProvisioner.php';
                $linux = new LinuxProvisioner($vs->getSshClient(), $serverId);
                
                $db = DB::conn();
                $db->prepare("UPDATE vpn_servers SET warp_status = 'installing' WHERE id = ?")->execute([$serverId]);
                
                $vs->runStep("Installing Cloudflare WARP and routing tables", "warp_setup_rules", function() use ($linux, $vpnSubnet) {
                    $linux->setupWarpHostRules($vpnSubnet);
                });
                
                $vs->runStep("Installing systemd watchdog timer", "warp_watchdog", function() use ($linux) {
                    $linux->installWarpWatchdog();
                });
                
                $diagnostics = $vs->runStep("Verifying connectivity", "warp_verify", function() use ($linux) {
                    return $linux->runWarpDiagnostics();
                });
                
                $status = ($diagnostics['status'] ?? 'error') === 'connected' ? 'connected' : 'error';
                
                $accIdOut = $vs->getSshClient()->executeCommand("grep -oP \"account_id\\s*=\\s*'([^']+)'\" /etc/wireguard/wgcf-account.toml 2>/dev/null | cut -d\"'\" -f2 || true", true);
                $accountId = trim($accIdOut);
                
                $verOut = $vs->getSshClient()->executeCommand("/usr/local/bin/wgcf version 2>/dev/null | head -1 || echo \"unknown\"", true);
                $warpVersion = trim($verOut);

                $db->prepare("
                    UPDATE vpn_servers 
                    SET warp_status = ?,
                        warp_installed = 1,
                        warp_initialized = 1,
                        warp_connected = ?,
                        warp_cloudflare_ip = ?,
                        warp_account_id = ?,
                        warp_version = ?,
                        warp_last_check_status = ?,
                        warp_last_check_at = NOW(),
                        warp_initialized_at = NOW()
                    WHERE id = ?
                ")->execute([
                    $status,
                    ($status === 'connected' ? 1 : 0),
                    $diagnostics['cloudflare_ip'] ?? null,
                    !empty($accountId) ? $accountId : null,
                    !empty($warpVersion) ? $warpVersion : null,
                    $diagnostics['last_check_status'] ?? 'Completed',
                    $serverId
                ]);
                
                require_once __DIR__ . '/../inc/VpnConfigRenderer.php';
                $renderer = new VpnConfigRenderer($vs);
                $renderer->syncWarpRoutingClients();

                if ($orchestration) $orchestration->success(['status' => $status]);
                Logger::channel('deployments')->info('Cloudflare WARP installation successful', ['server_id' => $serverId]);
            } catch (\Throwable $e) {
                $db = DB::conn();
                $db->prepare("UPDATE vpn_servers SET warp_status = 'error', warp_last_error = ? WHERE id = ?")
                   ->execute([$e->getMessage(), $serverId]);
                if ($orchestration) $orchestration->fail($e->getMessage());
                throw $e;
            } finally {
                Lock::release($lockName);
            }
            break;

        case 'warp_reinstall':
            if (empty($payload['server_id'])) throw new Exception("Missing server_id");
            $serverId = (int)$payload['server_id'];
            $jobId = isset($payload['job_id']) ? (int)$payload['job_id'] : null;
            $lockName = "server:{$serverId}:warp";
            
            if (!Lock::acquire($lockName, 600)) {
                exit(2);
            }
            
            $orchestration = $jobId ? new Job($jobId) : null;
            try {
                if ($orchestration) $orchestration->start();
                Logger::channel('deployments')->info('Starting Cloudflare WARP reinstallation', ['server_id' => $serverId]);
                
                $vs = new VpnServer($serverId);
                if ($orchestration) $vs->setJob($orchestration);
                
                $serverData = $vs->getData();
                $vpnSubnet = $serverData['vpn_subnet'] ?? '10.8.1.0/24';
                
                require_once __DIR__ . '/../inc/LinuxProvisioner.php';
                $linux = new LinuxProvisioner($vs->getSshClient(), $serverId);
                
                $db = DB::conn();
                $db->prepare("UPDATE vpn_servers SET warp_status = 'installing' WHERE id = ?")->execute([$serverId]);
                
                // Wipe existing configuration to force fresh registration
                $vs->getSshClient()->executeCommand("systemctl stop wg-quick@wg-warp 2>/dev/null || true");
                $vs->getSshClient()->executeCommand("rm -f /etc/wireguard/wg-warp.conf /etc/wireguard/wgcf-account.toml 2>/dev/null || true");
                
                $vs->runStep("Installing Cloudflare WARP and routing tables", "warp_setup_rules", function() use ($linux, $vpnSubnet) {
                    $linux->setupWarpHostRules($vpnSubnet);
                });
                
                $vs->runStep("Installing systemd watchdog timer", "warp_watchdog", function() use ($linux) {
                    $linux->installWarpWatchdog();
                });
                
                $diagnostics = $vs->runStep("Verifying connectivity", "warp_verify", function() use ($linux) {
                    return $linux->runWarpDiagnostics();
                });
                
                $status = ($diagnostics['status'] ?? 'error') === 'connected' ? 'connected' : 'error';
                
                $accIdOut = $vs->getSshClient()->executeCommand("grep -oP \"account_id\\s*=\\s*'([^']+)'\" /etc/wireguard/wgcf-account.toml 2>/dev/null | cut -d\"'\" -f2 || true", true);
                $accountId = trim($accIdOut);
                
                $verOut = $vs->getSshClient()->executeCommand("/usr/local/bin/wgcf version 2>/dev/null | head -1 || echo \"unknown\"", true);
                $warpVersion = trim($verOut);

                $db->prepare("
                    UPDATE vpn_servers 
                    SET warp_status = ?,
                        warp_installed = 1,
                        warp_initialized = 1,
                        warp_connected = ?,
                        warp_cloudflare_ip = ?,
                        warp_account_id = ?,
                        warp_version = ?,
                        warp_last_check_status = ?,
                        warp_last_check_at = NOW(),
                        warp_initialized_at = NOW()
                    WHERE id = ?
                ")->execute([
                    $status,
                    ($status === 'connected' ? 1 : 0),
                    $diagnostics['cloudflare_ip'] ?? null,
                    !empty($accountId) ? $accountId : null,
                    !empty($warpVersion) ? $warpVersion : null,
                    $diagnostics['last_check_status'] ?? 'Completed',
                    $serverId
                ]);
                
                require_once __DIR__ . '/../inc/VpnConfigRenderer.php';
                $renderer = new VpnConfigRenderer($vs);
                $renderer->syncWarpRoutingClients();

                if ($orchestration) $orchestration->success(['status' => $status]);
                Logger::channel('deployments')->info('Cloudflare WARP reinstallation successful', ['server_id' => $serverId]);
            } catch (\Throwable $e) {
                $db = DB::conn();
                $db->prepare("UPDATE vpn_servers SET warp_status = 'error', warp_last_error = ? WHERE id = ?")
                   ->execute([$e->getMessage(), $serverId]);
                if ($orchestration) $orchestration->fail($e->getMessage());
                throw $e;
            } finally {
                Lock::release($lockName);
            }
            break;

        case 'warp_repair':
            if (empty($payload['server_id'])) throw new Exception("Missing server_id");
            $serverId = (int)$payload['server_id'];
            $jobId = isset($payload['job_id']) ? (int)$payload['job_id'] : null;
            $lockName = "server:{$serverId}:warp";
            
            if (!Lock::acquire($lockName, 600)) {
                exit(2);
            }
            
            $orchestration = $jobId ? new Job($jobId) : null;
            try {
                if ($orchestration) $orchestration->start();
                Logger::channel('deployments')->info('Starting Cloudflare WARP repair', ['server_id' => $serverId]);
                
                $vs = new VpnServer($serverId);
                if ($orchestration) $vs->setJob($orchestration);
                
                $serverData = $vs->getData();
                $vpnSubnet = $serverData['vpn_subnet'] ?? '10.8.1.0/24';
                
                require_once __DIR__ . '/../inc/LinuxProvisioner.php';
                $linux = new LinuxProvisioner($vs->getSshClient(), $serverId);
                
                $db = DB::conn();
                $db->prepare("UPDATE vpn_servers SET warp_status = 'initializing' WHERE id = ?")->execute([$serverId]);
                
                $vs->runStep("Repairing Cloudflare WARP and routing tables", "warp_setup_rules", function() use ($linux, $vpnSubnet) {
                    $linux->setupWarpHostRules($vpnSubnet);
                });
                
                $vs->runStep("Reinstalling systemd watchdog timer", "warp_watchdog", function() use ($linux) {
                    $linux->installWarpWatchdog();
                });
                
                $diagnostics = $vs->runStep("Verifying connectivity after repair", "warp_verify", function() use ($linux) {
                    return $linux->runWarpDiagnostics();
                });
                
                $status = ($diagnostics['status'] ?? 'error') === 'connected' ? 'connected' : 'error';
                
                $accIdOut = $vs->getSshClient()->executeCommand("grep -oP \"account_id\\s*=\\s*'([^']+)'\" /etc/wireguard/wgcf-account.toml 2>/dev/null | cut -d\"'\" -f2 || true", true);
                $accountId = trim($accIdOut);
                
                $verOut = $vs->getSshClient()->executeCommand("/usr/local/bin/wgcf version 2>/dev/null | head -1 || echo \"unknown\"", true);
                $warpVersion = trim($verOut);

                $db->prepare("
                    UPDATE vpn_servers 
                    SET warp_status = ?,
                        warp_connected = ?,
                        warp_cloudflare_ip = ?,
                        warp_account_id = ?,
                        warp_version = ?,
                        warp_last_check_status = ?,
                        warp_last_check_at = NOW(),
                        warp_last_repair_at = NOW(),
                        warp_last_repair_result = 'Manual Repair Completed'
                    WHERE id = ?
                ")->execute([
                    $status,
                    ($status === 'connected' ? 1 : 0),
                    $diagnostics['cloudflare_ip'] ?? null,
                    !empty($accountId) ? $accountId : null,
                    !empty($warpVersion) ? $warpVersion : null,
                    $diagnostics['last_check_status'] ?? 'Completed',
                    $serverId
                ]);
                
                require_once __DIR__ . '/../inc/VpnConfigRenderer.php';
                $renderer = new VpnConfigRenderer($vs);
                $renderer->syncWarpRoutingClients();

                if ($orchestration) $orchestration->success(['status' => $status]);
                Logger::channel('deployments')->info('Cloudflare WARP repair successful', ['server_id' => $serverId]);
            } catch (\Throwable $e) {
                $db = DB::conn();
                $db->prepare("UPDATE vpn_servers SET warp_status = 'error', warp_last_error = ?, warp_last_repair_at = NOW(), warp_last_repair_result = ? WHERE id = ?")
                   ->execute([$e->getMessage(), 'Repair Failed: ' . $e->getMessage(), $serverId]);
                if ($orchestration) $orchestration->fail($e->getMessage());
                throw $e;
            } finally {
                Lock::release($lockName);
            }
            break;

        case 'warp_uninstall':
            if (empty($payload['server_id'])) throw new Exception("Missing server_id");
            $serverId = (int)$payload['server_id'];
            $jobId = isset($payload['job_id']) ? (int)$payload['job_id'] : null;
            $lockName = "server:{$serverId}:warp";
            
            if (!Lock::acquire($lockName, 120)) {
                exit(2);
            }
            
            $orchestration = $jobId ? new Job($jobId) : null;
            try {
                if ($orchestration) $orchestration->start();
                Logger::channel('deployments')->info('Uninstalling Cloudflare WARP', ['server_id' => $serverId]);
                $vs = new VpnServer($serverId);
                if ($orchestration) $vs->setJob($orchestration);
                
                require_once __DIR__ . '/../inc/LinuxProvisioner.php';
                $linux = new LinuxProvisioner($vs->getSshClient(), $serverId);
                
                $linux->removeWarp();
                
                $db = DB::conn();
                $db->prepare("
                    UPDATE vpn_servers 
                    SET warp_status = 'not_installed',
                        warp_installed = 0,
                        warp_initialized = 0,
                        warp_connected = 0,
                        warp_version = NULL,
                        warp_account_id = NULL,
                        warp_client_count = 0,
                        warp_cloudflare_ip = NULL,
                        warp_last_check_status = NULL,
                        warp_last_error = NULL
                    WHERE id = ?
                ")->execute([$serverId]);
                
                if ($orchestration) $orchestration->success();
                Logger::channel('deployments')->info('Cloudflare WARP uninstalled successfully', ['server_id' => $serverId]);
            } catch (\Throwable $e) {
                if ($orchestration) $orchestration->fail($e->getMessage());
                throw $e;
            } finally {
                Lock::release($lockName);
            }
            break;

        case 'warp_sync':
            if (empty($payload['server_id'])) throw new Exception("Missing server_id");
            $serverId = (int)$payload['server_id'];
            $lockName = "server:{$serverId}:infra";
            
            if (!Lock::acquire($lockName, 60)) {
                exit(2);
            }
            
            try {
                $vs = new VpnServer($serverId);
                $serverData = $vs->getData();
                if (($serverData['warp_status'] ?? 'not_installed') !== 'not_installed') {
                    require_once __DIR__ . '/../inc/VpnConfigRenderer.php';
                    $renderer = new VpnConfigRenderer($vs);
                    $renderer->syncWarpRoutingClients();
                }
            } finally {
                Lock::release($lockName);
            }
            break;

        case 'warp_health_check':
            if (empty($payload['server_id'])) throw new Exception("Missing server_id");
            $serverId = (int)$payload['server_id'];
            $lockName = "server:{$serverId}:sync";
            
            if (!Lock::acquire($lockName, 60)) {
                exit(0);
            }
            
            try {
                $vs = new VpnServer($serverId);
                $serverData = $vs->getData();
                if (($serverData['warp_status'] ?? 'not_installed') !== 'not_installed') {
                    require_once __DIR__ . '/../inc/LinuxProvisioner.php';
                    $linux = new LinuxProvisioner($vs->getSshClient(), $serverId);
                    
                    $diagnostics = $linux->runWarpDiagnostics();
                    
                    $status = $diagnostics['status'] ?? 'error';
                    if (!in_array($status, ['connected', 'degraded', 'error'], true)) {
                        $status = 'error';
                    }
                    
                    $db = DB::conn();
                    $db->prepare("
                        UPDATE vpn_servers 
                        SET warp_status = ?,
                            warp_connected = ?,
                            warp_cloudflare_ip = ?,
                            warp_last_check_status = ?,
                            warp_last_check_at = NOW(),
                            warp_last_repair_at = CASE WHEN ? IS NOT NULL THEN NOW() ELSE warp_last_repair_at END,
                            warp_last_repair_result = CASE WHEN ? IS NOT NULL THEN ? ELSE warp_last_repair_result END
                        WHERE id = ?
                    ")->execute([
                        $status,
                        ($status === 'connected' ? 1 : 0),
                        $diagnostics['cloudflare_ip'] ?? null,
                        $diagnostics['last_check_status'] ?? 'Completed',
                        !empty($diagnostics['last_repair_at']) ? $diagnostics['last_repair_at'] : null,
                        !empty($diagnostics['last_repair_result']) ? $diagnostics['last_repair_result'] : null,
                        !empty($diagnostics['last_repair_result']) ? $diagnostics['last_repair_result'] : null,
                        $serverId
                    ]);
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
