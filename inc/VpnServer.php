<?php
declare(strict_types=1);

require_once __DIR__ . '/SshClient.php';

/**
 * VPN Server Management Class
 * Handles deployment and management of Amnezia VPN servers
 * Based on amnezia_deploy_v2.php
 */
class VpnServer
{
    private readonly ?int $serverId;
    private ?array $data = null;

    /** @var Job|null Current active background job for tracking */
    private ?Job $currentJob = null;

    private ?SshClient $sshClient = null;

    public function __construct(?int $serverId = null)
    {
        $this->serverId = $serverId;
        if ($serverId) {
            $this->load();
        }
    }

    public function getId(): ?int
    {
        return $this->serverId;
    }

    public function getSshClient(): SshClient
    {
        if ($this->sshClient === null) {
            $config = new SshConnectionConfig(
                $this->data['host'] ?? '',
                (int)($this->data['port'] ?? 22),
                $this->data['username'] ?? 'root',
                $this->data['password'] ?? null,
                $this->data['ssh_private_key'] ?? null
            );
            $this->sshClient = new SshClient($config, $this->serverId, $this->currentJob);
        }
        return $this->sshClient;
    }

    public function setJob(?Job $job): void
    {
        $this->currentJob = $job;
        if ($this->sshClient) {
            $this->sshClient->setJob($job);
        }
    }

    public function getJob(): ?Job
    {
        return $this->currentJob;
    }

    /**
     * Run a deployment step with automatic logging and event emitting
     */
    public function runStep(string $title, string $stepType, callable $work): mixed
    {
        if ($this->currentJob) {
            // Cooperative Abort check
            if ($this->currentJob->isCancelled()) {
                throw new Exception("Job was cancelled. Aborting at step: $title");
            }

            $this->currentJob->heartbeat();
            $this->currentJob->startStep($stepType);
            $this->currentJob->emit('step.start', $title, ['step' => $stepType]);
        }

        try {
            $result = $work();
            
            if ($this->currentJob) {
                $duration = $this->currentJob->endStep($stepType);
                $this->currentJob->emit('step.end', "Completed: $title", [
                    'step' => $stepType, 
                    'success' => true,
                    'duration_ms' => $duration
                ]);
            }
            
            return $result;
        } catch (\Throwable $e) {
            if ($this->currentJob) {
                $duration = $this->currentJob->endStep($stepType);
                $this->currentJob->emit('step.error', "Failed: $title", [
                    'step' => $stepType,
                    'error' => $e->getMessage(),
                    'duration_ms' => $duration
                ], 'error');
            }
            throw $e;
        }
    }



    /**
     * Set server status with transition validation
     */
    public function setStatus(ServerStatus $newStatus): bool
    {
        if (!$this->data) return false;
        
        $currentStatus = $this->getStatus();
        if (!$currentStatus->canTransitionTo($newStatus)) {
            throw new Exception("Invalid status transition from {$currentStatus->value} to {$newStatus->value}");
        }

        $pdo = DB::conn();
        $stmt = $pdo->prepare('UPDATE vpn_servers SET status = ? WHERE id = ?');
        if ($stmt->execute([$newStatus->value, $this->serverId])) {
            $this->data['status'] = $newStatus;
            return true;
        }
        return false;
    }

    /**
     * Update server GeoIP information
     */
    public function updateGeoIp(): bool
    {
        if (!$this->data || empty($this->data['host'])) return false;

        $ip = $this->data['host'];
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        try {
            $url = "http://ip-api.com/json/{$ip}?fields=status,message,country,countryCode,city,isp,org,query";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);

            if (!$response) return false;

            $geo = json_decode($response, true);
            if (($geo['status'] ?? '') !== 'success') return false;

            $pdo = DB::conn();
            $stmt = $pdo->prepare('
                UPDATE vpn_servers 
                SET country = ?, country_code = ?, city = ?, isp = ?, org = ? 
                WHERE id = ?
            ');
            
            $stmt->execute([
                $geo['country'] ?? null,
                $geo['countryCode'] ?? null,
                $geo['city'] ?? null,
                $geo['isp'] ?? null,
                $geo['org'] ?? null,
                $this->serverId
            ]);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Load server data from database
     */
    public function load(): void
    {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT * FROM vpn_servers WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$this->serverId]);
        $data = $stmt->fetch();
        if (!$data) {
            throw new Exception('Server not found or has been deleted');
        }
        $this->data = $data;

        // Map status to Enum
        $this->data['status'] = ServerStatus::tryFrom((string)($this->data['status'] ?? '')) ?? ServerStatus::ERROR;

        // Decode JSON parameters
        if (isset($this->data['awg_params']) && is_string($this->data['awg_params'])) {
            $this->data['awg_params'] = json_decode($this->data['awg_params'], true);
        }
    }

    /**
     * Create new VPN server in database
     */
    public static function create(array $data): int
    {
        $pdo = DB::conn();

        // Validate required fields
        $required = ['user_id', 'name', 'host', 'port', 'username', 'password'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new Exception("Field {$field} is required");
            }
        }

        $stmt = $pdo->prepare('
            INSERT INTO vpn_servers 
            (user_id, name, host, port, username, password, container_name, vpn_port, vpn_subnet, awg_params, status, deployed_at, last_check_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL)
        ');

        $stmt->execute([
            $data['user_id'],
            $data['name'],
            $data['host'],
            $data['port'],
            $data['username'],
            $data['password'],
            $data['container_name'] ?? 'nk-awg-v2',
            $data['vpn_port'] ?? NULL,
            $data['vpn_subnet'] ?? '10.8.1.0/24',
            json_encode(['mimicry_type' => $data['mimicry_type'] ?? 'quic']),
            ServerStatus::DEPLOYING->value
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Deploy or Re-deploy VPN server using existing data if available
     */
    public function deploy(bool $forceRebuild = false): DeploymentResult
    {
        require_once __DIR__ . "/DeploymentResult.php";
        require_once __DIR__ . "/VpnProvisioner.php";
        
        $provisioner = new VpnProvisioner(
            new LinuxProvisioner($this->getSshClient(), $this->getId()),
            new AwgConfigGenerator()
        );
        return $provisioner->deploy($this, $forceRebuild);
    }

    public function testConnection(): bool
    {
        return $this->getSshClient()->testConnection();
    }

    /**
     * Execute command on remote server and return output.
     * Uses SSH multiplexing when available for near-zero latency.
     * Throws an exception if the command exits non-zero.
     */
    public function executeCommand(string $command, bool $sudo = false, bool $checkExit = false, bool $silent = false, int $timeout = 60): string
    {
        return $this->getSshClient()->executeCommand($command, $sudo, $checkExit, $silent, $timeout);
    }

    

    

    

    

    

    

    

    

    

    

    

    

    

    /**
     * Get default mimicry presets for AWG 2.0
     */
    public static function getMimicryPresets(): array
    {
        return [
            'none' => [],
            'quic' => [
                'I1' => '<b 0xc700000001><rc 8><t><r 80>',
                'I2' => '<b 0xc000000001><t><rc 12><r 64>'
            ],
            'dns' => [
                'I1' => '<b 0x123401000001000000000000><rd 4><b 0x03636f6d0000010001><t><r 32>'
            ],
            'stun' => [
                'I1' => '<b 0x000100002112a442><r 12><t><r 48>'
            ],
            'sip' => [
                'I1' => '<b 0x4f5054494f4e53207369703a><rc 12><b 0x205349502f322e300d0a><t><r 40>'
            ],
        ];
    }

    

    

    

    

    /**
     * Get server status Enum
     */
    public function getStatus(): ServerStatus
    {
        return $this->data['status'] ?? ServerStatus::ERROR;
    }

    /**
     * Ping the server to check connectivity and measure latency
     */
    public function pingNode(): ?int
    {
        $host = $this->data['host'];
        $startTime = microtime(true);

        $pingResult = false;
        // Simple TCP check instead of ICMP ping to avoid permission issues and firewalls
        $connection = @fsockopen($host, $this->data['port'], $errno, $errstr, 2);

        if (is_resource($connection)) {
            fclose($connection);
            $endTime = microtime(true);
            $latency = (int) (($endTime - $startTime) * 1000);
            return $latency;
        }

        return null;
    }

    /**
     * Update server status and ping in the database
     */
    public function updatePingAndStatus(): bool {
        if (!$this->data) return false;
        
        $host = $this->data['host'];
        $port = $this->data['port'] ?? 22;
        
        $latency = null;
        $newStatus = ServerStatus::STOPPED; // Must be enum from the start for === comparisons and ->value access
        
        // 1. Try standard system ping (ICMP)
        $pingCmd = "ping -c 1 -W 2 " . escapeshellarg($host) . " 2>&1";
        $pingOut = shell_exec($pingCmd);
        
        // Match both "time=XX.X ms" and "min/avg/max = XX/XX/XX" formats
        if ($pingOut && preg_match('/\/([0-9.]+)\/[0-9.]+\/[0-9.]+ ms/', $pingOut, $m)) {
            $latency = (int)round((float)$m[1]);
            $newStatus = ServerStatus::ACTIVE;
        } elseif ($pingOut && preg_match('/time=([0-9.]+)/', $pingOut, $m)) {
            $latency = (int)round((float)$m[1]);
            $newStatus = ServerStatus::ACTIVE;
        }
        
        // 2. Fallback: If ICMP is blocked, use a real TCP handshake via curl (bypassing local proxies)
        if ($newStatus === ServerStatus::STOPPED || $latency === null || $latency <= 1) {
            $tcpCmd = "curl --noproxy '*' -o /dev/null -s -w '%{time_connect}' --connect-timeout 2 " . escapeshellarg($host . ":" . $port);
            $tcpOut = shell_exec($tcpCmd);
            
            if ($tcpOut !== null && is_numeric(trim($tcpOut)) && (float)trim($tcpOut) > 0.0001) {
                $latency = (int)round((float)trim($tcpOut) * 1000);
                $newStatus = ServerStatus::ACTIVE;
            }
        }
        
        // Last resort fallback
        if ($newStatus === ServerStatus::STOPPED) {
            $connection = @fsockopen($host, $port, $errno, $errstr, 1.5);
            if ($connection) {
                fclose($connection);
                $newStatus = ServerStatus::ACTIVE;
                $latency = 1;
            }
        }
        
        // Don't override 'deploying' or 'error' status automatically unless it's now 'active'
        $currentStatus = $this->data['status'];
        if ($newStatus === ServerStatus::ACTIVE || ($currentStatus !== ServerStatus::DEPLOYING && $currentStatus !== ServerStatus::ERROR)) {
            $pdo = DB::conn();
            $stmt = $pdo->prepare('UPDATE vpn_servers SET status = ?, last_ping_ms = ?, last_check_at = NOW(), error_message = CASE WHEN ? = ? THEN NULL ELSE error_message END WHERE id = ?');
            $stmt->execute([$newStatus->value, $latency, $newStatus->value, ServerStatus::ACTIVE->value, $this->serverId]);
            $this->data['status'] = ServerStatus::tryFrom($newStatus->value) ?? ServerStatus::ERROR;
            $this->data['last_ping_ms'] = $latency;
        }
        
        return ($this->data['status'] ?? '') === ServerStatus::ACTIVE;
    }

    /**
     * Get all servers for a user
     */
    public static function listByUser(int $userId): array
    {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('
            SELECT s.*, COUNT(c.id) as client_count 
            FROM vpn_servers s 
            LEFT JOIN vpn_clients c ON s.id = c.server_id AND c.deleted_at IS NULL
            WHERE s.user_id = ? AND s.deleted_at IS NULL
            GROUP BY s.id 
            ORDER BY s.created_at DESC
        ');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /**
     * Get all servers (admin only)
     */
    public static function listAll(): array
    {
        $pdo = DB::conn();
        $stmt = $pdo->query('
            SELECT s.*, MAX(u.email) as user_email, COUNT(c.id) as client_count 
            FROM vpn_servers s 
            LEFT JOIN users u ON s.user_id = u.id 
            LEFT JOIN vpn_clients c ON s.id = c.server_id AND c.deleted_at IS NULL
            WHERE s.deleted_at IS NULL
            GROUP BY s.id 
            ORDER BY s.created_at DESC
        ');
        return $stmt->fetchAll();
    }

    /**
     * Delete server
     */
    public function delete(): bool
    {
        if (!$this->serverId) return false;

        $pdo = DB::conn();
        
        // 1. Mark as deleting in DB
        $stmt = $pdo->prepare('UPDATE vpn_servers SET status = ? WHERE id = ?');
        $stmt->execute([ServerStatus::DELETING->value, $this->serverId]);

        // 2. Queue for remote cleanup
        require_once __DIR__ . '/Queue.php';
        Queue::push('deployments', [
            'type' => 'delete_server',
            'server_id' => $this->serverId
        ]);

        return true;
    }

    /**
     * Perform actual remote resource cleanup (called by worker)
     */
    public function cleanupRemoteResources(): void
    {
        if (!$this->data) return;
        
        $pdo = DB::conn();
        $host = $this->data['host'] ?? '';

        // Stop and remove container
        try {
            $containerName = $this->data['container_name'] ?? 'nk-awg-v2';
            
            // 1. Try to bring down the interface first (on host since --network host was used)
            // We try both awg-quick down and manual ip link delete for robustness
            $this->executeCommand("awg-quick down wg0 2>/dev/null || ip link delete wg0 2>/dev/null || true", true);

            // 2. Stop and remove Docker container
            $this->executeCommand("docker stop {$containerName} 2>/dev/null || true", true);
            $this->executeCommand("docker rm -fv {$containerName} 2>/dev/null || true", true);
            
            // 3. Remove configuration directory
            $this->executeCommand("rm -rf /opt/amnezia/nk-awg-v2", true);

            // 4. Cleanup firewall
            if (isset($this->data['vpn_port'])) {
                $port = (int)$this->data['vpn_port'];
                $this->executeCommand("iptables -D INPUT -p udp --dport {$port} -j ACCEPT 2>/dev/null || true", true);
            }

            // 5. Intelligent Package Cleanup: Only remove software if no other servers share this host
            if (!empty($host)) {
                $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM vpn_servers WHERE host = ? AND id != ? AND deleted_at IS NULL');
                $stmtCount->execute([$host, $this->serverId]);
                $otherServersOnHost = (int)$stmtCount->fetchColumn();

                if ($otherServersOnHost === 0) {
                    Logger::channel('deployments')->info("No other servers on host {$host}, cleaning up AmneziaWG packages", ['server_id' => $this->serverId]);
                    
                    // Remove packages for both Apt and Yum/Dnf systems
                    $this->executeCommand("apt-get remove -y amneziawg 2>/dev/null || yum remove -y amneziawg-dkms amneziawg-tools 2>/dev/null || dnf remove -y amneziawg-dkms amneziawg-tools 2>/dev/null || true", true);
                    
                    // Remove repository files
                    $this->executeCommand("rm -f /etc/apt/sources.list.d/amnezia.list /etc/yum.repos.d/amneziavpn-amneziawg.repo 2>/dev/null || true", true);
                } else {
                    Logger::channel('deployments')->info("Found {$otherServersOnHost} other servers on host {$host}, skipping package uninstallation", ['server_id' => $this->serverId]);
                }
            }

        } catch (Exception $e) {
            // Ignore errors during cleanup but log them
            Logger::error("Cleanup failed for server {$this->serverId}: " . $e->getMessage());
        }

        // Mark as deleted in database instead of deleting row
        // Also soft-delete all clients of this server to ensure consistency
        $stmtClients = $pdo->prepare('UPDATE vpn_clients SET deleted_at = NOW(), status = ? WHERE server_id = ? AND deleted_at IS NULL');
        $stmtClients->execute([ClientStatus::DISABLED->value, $this->serverId]);

        // Also soft-delete all proxies of this server
        $stmtProxies = $pdo->prepare('UPDATE http_proxies SET deleted_at = NOW(), status = ? WHERE server_id = ? AND deleted_at IS NULL');
        $stmtProxies->execute(['deleted', $this->serverId]);

        $stmtServer = $pdo->prepare('UPDATE vpn_servers SET deleted_at = NOW(), status = ? WHERE id = ?');
        $stmtServer->execute([ServerStatus::STOPPED->value, $this->serverId]);
    }

    /**
     * Get server data. Status is returned as string for template/API compatibility.
     */
    public function getData(): ?array
    {
        if (!$this->data) return null;
        $data = $this->data;
        if ($data['status'] instanceof ServerStatus) {
            $data['status'] = $data['status']->value;
        }
        $data['container_name'] = $data['container_name'] ?? 'nk-awg-v2';
        return $data;
    }

    /**
     * Create backup of server configuration and all clients
     * 
     * @param int $userId User who creates the backup
     * @param string $backupType Type: 'manual' or 'automatic'
     * @return int Backup ID
     */
    public function createBackup(int $userId, string $backupType = 'manual'): int
    {
        if (!$this->data) {
            throw new Exception('Server not loaded');
        }

        $pdo = DB::conn();
        $backupName = 'backup_' . $this->serverId . '_' . date('Y-m-d_His') . '.json';
        $backupDir = '/var/www/html/backups';
        $backupPath = $backupDir . '/' . $backupName;

        // Create backups directory if not exists
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        try {
            // Get all clients for this server
            $stmt = $pdo->prepare('
                SELECT id, name, client_ip, public_key, private_key, preshared_key, 
                       config, status, expires_at, created_at
                FROM vpn_clients 
                WHERE server_id = ? AND deleted_at IS NULL
            ');
            $stmt->execute([$this->serverId]);
            $clients = $stmt->fetchAll();

            // Prepare backup data
            $backupData = [
                'server' => [
                    'name' => $this->data['name'],
                    'host' => $this->data['host'],
                    'port' => $this->data['port'],
                    'vpn_port' => $this->data['vpn_port'],
                    'vpn_subnet' => $this->data['vpn_subnet'],
                    'container_name' => $this->data['container_name'],
                    'server_public_key' => $this->data['server_public_key'],
                    'preshared_key' => $this->data['preshared_key'],
                    'awg_params' => $this->data['awg_params'],
                ],
                'clients' => $clients,
                'backup_date' => date('Y-m-d H:i:s'),
                'version' => '1.0'
            ];

            // Write backup to file
            $json = json_encode($backupData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            file_put_contents($backupPath, $json);

            $backupSize = filesize($backupPath);

            // Insert backup record
            $stmt = $pdo->prepare('
                INSERT INTO server_backups 
                (server_id, backup_name, backup_path, backup_size, clients_count, backup_type, status, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');

            $stmt->execute([
                $this->serverId,
                $backupName,
                $backupPath,
                $backupSize,
                count($clients),
                $backupType,
                'completed',
                $userId
            ]);

            return (int) $pdo->lastInsertId();

        } catch (Exception $e) {
            // Mark backup as failed
            if (isset($stmt)) {
                $stmt = $pdo->prepare('
                    INSERT INTO server_backups 
                    (server_id, backup_name, backup_path, backup_type, status, error_message, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ');

                $stmt->execute([
                    $this->serverId,
                    $backupName,
                    $backupPath,
                    $backupType,
                    'failed',
                    $e->getMessage(),
                    $userId
                ]);
            }

            throw $e;
        }
    }

    /**
     * List all backups for this server
     * 
     * @return array List of backups
     */
    public function listBackups(): array
    {
        if (!$this->data) {
            throw new Exception('Server not loaded');
        }

        $pdo = DB::conn();
        $stmt = $pdo->prepare('
            SELECT b.*, u.name as created_by_name, u.email as created_by_email
            FROM server_backups b
            LEFT JOIN users u ON b.created_by = u.id
            WHERE b.server_id = ?
            ORDER BY b.created_at DESC
        ');
        $stmt->execute([$this->serverId]);
        return $stmt->fetchAll();
    }

    /**
     * Restore server from backup
     * Note: This only restores client configurations to database
     * Server must already be deployed
     * 
     * @param int $backupId Backup ID
     * @return array Restoration results
     */
    public function restoreBackup(int $backupId): array
    {
        if (!$this->data) {
            throw new Exception('Server not loaded');
        }

        if ($this->data['status'] !== ServerStatus::ACTIVE) {
            throw new Exception('Server must be active to restore backup');
        }

        $pdo = DB::conn();

        // Get backup record
        $stmt = $pdo->prepare('SELECT * FROM server_backups WHERE id = ? AND server_id = ?');
        $stmt->execute([$backupId, $this->serverId]);
        $backup = $stmt->fetch();

        if (!$backup) {
            throw new Exception('Backup not found');
        }

        if (!file_exists($backup['backup_path'])) {
            throw new Exception('Backup file not found');
        }

        // Read backup data
        $backupData = json_decode(file_get_contents($backup['backup_path']), true);

        if (!$backupData || !isset($backupData['clients'])) {
            throw new Exception('Invalid backup format');
        }

        $restored = 0;
        $failed = 0;
        $errors = [];

        foreach ($backupData['clients'] as $clientData) {
            try {
                // Check if client already exists by IP
                $stmt = $pdo->prepare('SELECT id FROM vpn_clients WHERE server_id = ? AND client_ip = ?');
                $stmt->execute([$this->serverId, $clientData['client_ip']]);
                $existing = $stmt->fetch();

                if ($existing) {
                    $errors[] = "Client {$clientData['name']} already exists";
                    $failed++;
                    continue;
                }

                // Insert client
                $stmt = $pdo->prepare('
                    INSERT INTO vpn_clients 
                    (server_id, user_id, name, client_ip, public_key, private_key, preshared_key, 
                     config, status, expires_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');

                $stmt->execute([
                    $this->serverId,
                    $this->data['user_id'],
                    $clientData['name'],
                    $clientData['client_ip'],
                    $clientData['public_key'],
                    $clientData['private_key'],
                    $clientData['preshared_key'],
                    $clientData['config'],
                    ServerStatus::ACTIVE->value, // Restore as active since we're adding it to the server
                    $clientData['expires_at']
                ]);

                // Add client to server container
                VpnClient::addClientToServer($this->data, $clientData['public_key'], $clientData['client_ip']);

                $restored++;

            } catch (Exception $e) {
                $failed++;
                $errors[] = "Failed to restore {$clientData['name']}: " . $e->getMessage();
            }
        }

        return [
            'success' => true, // Always success if process completed
            'restored' => $restored,
            'failed' => $failed,
            'total' => count($backupData['clients']),
            'errors' => $errors,
            'message' => $restored > 0 ? "Restored $restored clients" : "No clients restored"
        ];
    }

    /**
     * Delete backup
     * 
     * @param int $backupId Backup ID
     * @return bool Success
     */
    public static function deleteBackup(int $backupId): bool
    {
        $pdo = DB::conn();

        // Get backup path
        $stmt = $pdo->prepare('SELECT backup_path FROM server_backups WHERE id = ?');
        $stmt->execute([$backupId]);
        $backup = $stmt->fetch();

        if (!$backup) {
            return false;
        }

        // Delete file
        if (file_exists($backup['backup_path'])) {
            unlink($backup['backup_path']);
        }

        // Delete record
        $stmt = $pdo->prepare('DELETE FROM server_backups WHERE id = ?');
        return $stmt->execute([$backupId]);
    }

    /**
     * Get backup by ID
     * 
     * @param int $backupId Backup ID
     * @return array|null Backup data
     */
    public static function getBackup(int $backupId): ?array
    {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT * FROM server_backups WHERE id = ?');
        $stmt->execute([$backupId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Rebuild entire wg0.conf from database and push to server
     * This is useful after a database restore to ensure server state matches DB.
     */
    public function syncClientsWithServer(): void
    {
        if (!$this->data || $this->data['status'] !== ServerStatus::ACTIVE)
            return;

        $containerName = $this->data['container_name'];

        // Check if container exists. If not, trigger re-deploy.
        $containerStatus = trim($this->executeCommand("docker inspect --format='{{.State.Running}}' {$containerName} 2>/dev/null", true));
        if ($containerStatus !== 'true') {
            \Logger::error("VPN Server {$this->serverId} container missing or stopped. Triggering re-deployment...");
            try {
                $this->deploy();
                $this->load(); // Refresh data from DB after deployment (new keys, params, etc)
                // After deploy, initializeServerConfig has already written an empty wg0.conf
                // We continue here to populate it with all existing clients
            } catch (Exception $e) {
                \Logger::error("Failed to re-deploy VPN server {$this->serverId}: " . $e->getMessage());
                return;
            }
        }

        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT * FROM vpn_clients WHERE server_id = ? AND status = ? AND deleted_at IS NULL');
        $stmt->execute([$this->serverId, ClientStatus::ACTIVE->value]);
        $clients = $stmt->fetchAll();

        // 1. Build Base Config
        $privKey = $this->data['server_private_key'] ?? null;
        if (empty($privKey)) {
            // Fallback for old servers: read from remote if missing in DB
            $privKey = trim($this->executeCommand("docker exec -i {$this->data['container_name']} cat /opt/amnezia/awg/server_private.key", true));
        }

        $awgParams = $this->data['awg_params'];
        if (is_string($awgParams))
            $awgParams = json_decode($awgParams, true) ?: [];

        $wgConfig = "[Interface]\n";
        $wgConfig .= "PrivateKey = {$privKey}\n";
        $subnetBase = preg_replace('/\.\d+\/\d+$/', '', $this->data['vpn_subnet']);
        $wgConfig .= "Address = {$subnetBase}.1/24\n";
        $wgConfig .= "ListenPort = {$this->data['vpn_port']}\n";
        $wgConfig .= "MTU = 1280\n";

        foreach ($awgParams as $key => $value) {
            if ($value === null || $value === '' || $key === 'mimicry_type')
                continue;
            $wgConfig .= "{$key} = {$value}\n";
        }
        $wgConfig .= "\n";

        // 2. Add All Peers
        foreach ($clients as $client) {
            $wgConfig .= "# Name = {$client['name']}\n";
            $wgConfig .= "[Peer]\n";
            $wgConfig .= "PublicKey = {$client['public_key']}\n";
            $wgConfig .= "PresharedKey = {$this->data['preshared_key']}\n";
            $wgConfig .= "AllowedIPs = {$client['client_ip']}/32\n\n";
        }

        // 3. Rebuild clientsTable JSON
        $clientsTable = [];
        foreach ($clients as $client) {
            $clientsTable[] = [
                'clientId' => $client['public_key'],
                'name' => $client['name'],
                'public_key' => $client['public_key'],
                'private_key' => $client['private_key'],
                'client_ip' => $client['client_ip'],
                'created_at' => $client['created_at'],
                'userData' => [
                    'clientName' => $client['name'],
                    'creationDate' => $client['created_at']
                ]
            ];
        }
        $clientsTableJson = json_encode($clientsTable);
        $clientsTableBase64 = base64_encode($clientsTableJson);

        // 4. Push and Apply
        $containerName = $this->data['container_name'];
        $base64 = base64_encode($wgConfig);
        $this->executeCommand("echo \"{$base64}\" | docker exec -i {$containerName} sh -c 'base64 -d > /opt/amnezia/awg/wg0.conf && chmod 600 /opt/amnezia/awg/wg0.conf'", true);
        $this->executeCommand("echo \"{$clientsTableBase64}\" | docker exec -i {$containerName} sh -c 'base64 -d > /opt/amnezia/awg/clientsTable'", true);
        $this->executeCommand("docker exec -i {$containerName} bash -c '/usr/local/bin/awg syncconf wg0 <(/usr/local/bin/awg-quick strip /opt/amnezia/awg/wg0.conf)'", true);
    }

    

    

    /**
     * Synchronize all VPN servers
     */
    public static function syncAllServers(): void
    {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT id FROM vpn_servers WHERE status = ?');
        $stmt->execute([ServerStatus::ACTIVE->value]);
        $serverIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($serverIds as $serverId) {
            try {
                $vs = new self((int) $serverId);
                $vs->syncClientsWithServer();

                // Refresh GeoIP data for servers missing it
                if (empty($vs->getData()['country_code'])) {
                    try {
                        $vs->updateGeoIp();
                    } catch (\Exception $geoE) {
                        // Non-critical — log and continue
                        \Logger::warning("GeoIP update failed for server $serverId: " . $geoE->getMessage());
                    }
                }
            } catch (Exception $e) {
                \Logger::error("Failed to sync VPN server $serverId after restore: " . $e->getMessage());
            }
        }
    }
}
