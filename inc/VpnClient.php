<?php
declare(strict_types=1);

/**
 * VPN Client Management Class
 * Handles creation and management of VPN client configurations
 * Based on amnezia_client_config_v2.php
 */
class VpnClient {
    private readonly ?int $clientId;
    private ?array $data = null;
    
    public function __construct(?int $clientId = null) {
        $this->clientId = $clientId;
        if ($clientId) {
            $this->load();
        }
    }
    
    /**
     * Load client data from database
     */
    private function load(): void {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('
            SELECT c.*, s.name as server_name, s.host as server_host 
            FROM vpn_clients c
            LEFT JOIN vpn_servers s ON c.server_id = s.id
            WHERE c.id = ? AND c.deleted_at IS NULL
        ');
        $stmt->execute([$this->clientId]);
        $this->data = $stmt->fetch();
        if (!$this->data) {
            throw new Exception('Client not found or has been deleted');
        }

        // Map status to Enum
        if (isset($this->data['status'])) {
            $this->data['status'] = ClientStatus::tryFrom($this->data['status']) ?? ClientStatus::ACTIVE;
        }
    }
    
    /**
     * Create new VPN client
     * 
     * @param int $serverId Server ID
     * @param int $userId User ID
     * @param string $name Client name
     * @param int|null $expiresInDays Days until expiration (null = never expires)
     * @return int Client ID
     */
    public static function create(int $serverId, int $userId, string $name, ?int $expiresInDays = null): int {
        $pdo = DB::conn();
        
        // Sanitize and validate client name
        $name = trim($name);
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $name)) {
            $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
        }
        if (empty($name)) $name = 'client_' . time();
        
        // Get server data to validate
        $server = new VpnServer($serverId);
        $serverData = $server->getData();
        
        if (!$serverData || $serverData['status'] !== ServerStatus::ACTIVE->value) {
            throw new Exception('Server is not active');
        }
        
        // Generate keys locally if possible or via fixed path inside container
        // To fix the security risk, we use fixed temp files instead of dynamic ones
        $keys = self::generateClientKeys($server, 'tmp_provision');
        
        // Get next available IP
        $clientIP = self::getNextClientIP($serverData);
        
        // Calculate expiration date
        $expiresAt = $expiresInDays ? date('Y-m-d H:i:s', strtotime("+{$expiresInDays} days")) : null;
        
        $pdo->beginTransaction();
        try {
            // Check for existing client with same name on this server
            $checkNameStmt = $pdo->prepare('SELECT id FROM vpn_clients WHERE server_id = ? AND name = ? AND deleted_at IS NULL');
            $checkNameStmt->execute([$serverId, $name]);
            if ($checkNameStmt->fetch()) {
                throw new Exception("A client with name '{$name}' already exists on this server.");
            }

            // Final safety check for IP collision within transaction
            $checkStmt = $pdo->prepare('SELECT id FROM vpn_clients WHERE server_id = ? AND client_ip = ? AND deleted_at IS NULL FOR UPDATE');
            $checkStmt->execute([$serverId, $clientIP]);
            if ($checkStmt->fetch()) {
                 throw new Exception("IP collision detected for {$clientIP}. Please try again.");
            }

            // Insert into database with PROVISIONING status
            $stmt = $pdo->prepare('
                INSERT INTO vpn_clients 
                (server_id, user_id, name, client_ip, public_key, private_key, preshared_key, config, status, expires_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            
            $stmt->execute([
                $serverId,
                $userId,
                $name,
                $clientIP,
                $keys['public'],
                $keys['private'],
                $serverData['preshared_key'],
                '', // Config will be built after sync
                ClientStatus::PROVISIONING->value,
                $expiresAt
            ]);
            
            $clientId = (int)$pdo->lastInsertId();
            $pdo->commit();
            
            // Queue the infrastructure sync
            require_once __DIR__ . '/Queue.php';
            Queue::push('deployments', [
                'type' => 'provision_client',
                'client_id' => $clientId,
                'server_id' => $serverId
            ]);

            return $clientId;
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Synchronize client to remote server (Idempotent)
     */
    public function syncToRemote(): bool
    {
        if (!$this->data) return false;
        
        $server = new VpnServer((int)$this->data['server_id']);
        $serverData = $server->getData();
        
        try {
            // Build client configuration
            $awgParams = $serverData['awg_params'] ?? [];
            if (is_string($awgParams)) {
                $awgParams = json_decode($awgParams, true) ?: [];
            }

            $config = self::buildClientConfig(
                $this->data['private_key'],
                $this->data['client_ip'],
                $serverData['server_public_key'],
                $serverData['preshared_key'],
                $serverData['host'],
                $serverData['vpn_port'],
                $awgParams,
                $serverData['name'],
                $this->data['name']
            );

            // Add to WireGuard
            self::addClientToServer($server, $this->data['public_key'], $this->data['client_ip'], $this->data['private_key'], $this->data['name']);
            
            // Set status to VERIFYING
            $pdo = DB::conn();
            $pdo->prepare('UPDATE vpn_clients SET status = ? WHERE id = ?')
                ->execute([ClientStatus::VERIFYING->value, $this->clientId]);

            // VERIFICATION: Check if peer is actually in the running interface
            $verified = self::verifyPeerInRuntime($server, $this->data['public_key'], true, $this->data['client_ip']);
            if (!$verified) {
                throw new Exception("Infrastructure verification failed: Peer {$this->data['public_key']} not active or misconfigured in kernel interface after 5 attempts.");
            }

            // Update DB to ACTIVE
            $pdo = DB::conn();
            $stmt = $pdo->prepare('UPDATE vpn_clients SET status = ?, config = ? WHERE id = ?');
            $stmt->execute([ClientStatus::ACTIVE->value, $config, $this->clientId]);
            
            $this->data['status'] = ClientStatus::ACTIVE;
            return true;
        } catch (Exception $e) {
            $pdo = DB::conn();
            $pdo->prepare('UPDATE vpn_clients SET status = ? WHERE id = ?')
                ->execute([ClientStatus::ERROR->value, $this->clientId]);
            throw $e;
        }
    }
    
    /**
     * Generate client keys on remote server
     */
    private static function generateClientKeys(array|VpnServer $server, string $clientName): array {
        $serverData = is_array($server) ? $server : $server->getData();
        $containerName = $serverData['container_name'];
        
        // SECURITY: Use fixed filenames for the temporary keys to prevent shell injection 
        // through the clientName, even if it has been regex-filtered.
        $privFile = "/tmp/nk_provision_priv.key";
        $pubFile = "/tmp/nk_provision_pub.key";

        $cmd = sprintf(
            "docker exec -i %s sh -c \"umask 077; /usr/local/bin/awg genkey | tee %s | /usr/local/bin/awg pubkey > %s; cat %s; echo '---'; cat %s; rm -f %s %s\"",
            $containerName,
            $privFile, $pubFile, $privFile, $pubFile, $privFile, $pubFile
        );
        
        if (is_array($server)) {
            $server = new VpnServer((int)$server['id']);
        }
        $out = $server->executeCommand($cmd, true);
        
        $parts = explode("---", trim($out));
        
        if (count($parts) < 2) {
            throw new Exception("Invalid key generation output: " . $out);
        }
        
        return [
            'private' => trim($parts[0]),
            'public' => trim($parts[1])
        ];
    }
    
    /**
     * Get next available client IP
     */
    private static function getNextClientIP(array $serverData): string {
        $pdo = DB::conn();
        
        // Get used IPs from database (only for non-deleted clients)
        $stmt = $pdo->prepare('SELECT client_ip FROM vpn_clients WHERE server_id = ? AND deleted_at IS NULL');
        $stmt->execute([$serverData['id']]);
        $usedIPs = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Parse subnet
        $parts = explode('/', $serverData['vpn_subnet']);
        $networkLong = ip2long($parts[0]);
        $mask = (int)($parts[1] ?? 24);
        
        // Reserve network and gateway address
        $used = [long2ip($networkLong) => true, long2ip($networkLong + 1) => true];
        foreach ($usedIPs as $ip) {
            $used[$ip] = true;
        }
        
        // Find next free IP starting from .1
        for ($i = 1; $i <= 253; $i++) {
            $candidate = long2ip($networkLong + $i);
            if (!isset($used[$candidate])) {
                return $candidate;
            }
        }
        
        throw new Exception('No free IP addresses in subnet');
    }
    
    /**
     * Build client configuration file
     */
    public static function buildClientConfig(
        string $privateKey,
        string $clientIP,
        string $serverPublicKey,
        string $presharedKey,
        string $serverHost,
        int $serverPort,
        array $awgParams,
        string $serverName = '',
        string $clientName = ''
    ): string {
        $config = "[Interface]\n";
        if ($clientName) {
            $config .= "# Name = {$clientName}\n";
        }
        $config .= "PrivateKey = {$privateKey}\n";
        $config .= "Address = {$clientIP}/32\n";
        $config .= "DNS = 1.1.1.1, 1.0.0.1\n";

        // Accept both key casings and normalize to I1-I5 in exported config.
        for ($idx = 1; $idx <= 5; $idx++) {
            $lowerKey = 'i' . $idx;
            $upperKey = 'I' . $idx;
            if (!isset($awgParams[$upperKey]) && isset($awgParams[$lowerKey])) {
                $awgParams[$upperKey] = $awgParams[$lowerKey];
            }
        }
        
        // Add AWG parameters (V1 + V2.0)
        // We use lowercase keys (jc, jmin, i1, i2, etc.) to match the XanMod 
        // kernel module's expected format and ensure cross-client compatibility.
        $keys = [
            'Jc' => ['Jc', 'jc'],
            'Jmin' => ['Jmin', 'jmin'],
            'Jmax' => ['Jmax', 'jmax'],
            'S1' => ['S1', 's1'],
            'S2' => ['S2', 's2'],
            'S3' => ['S3', 's3'],
            'S4' => ['S4', 's4'],
            'H1' => ['H1', 'h1'],
            'H2' => ['H2', 'h2'],
            'H3' => ['H3', 'h3'],
            'H4' => ['H4', 'h4'],
            'i1' => ['I1', 'i1'],
            'i2' => ['I2', 'i2'],
            'i3' => ['I3', 'i3'],
            'i4' => ['I4', 'i4'],
            'i5' => ['I5', 'i5']
        ];

        foreach ($keys as $outputKey => $sourceKeys) {
            foreach ($sourceKeys as $src) {
                if (isset($awgParams[$src]) && $awgParams[$src] !== '' && $awgParams[$src] !== null) {
                    $val = $awgParams[$src];
                    // S1-S4 should never be 0
                    if (in_array($outputKey, ['S1', 'S2', 'S3', 'S4']) && (int)$val === 0) {
                        $val = 1;
                    }
                    $config .= "{$outputKey} = {$val}\n";
                    break;
                }
            }
        }
        
        $config .= "\n[Peer]\n# Name = {$serverName}\n";
        $config .= "PublicKey = {$serverPublicKey}\n";
        $config .= "PresharedKey = {$presharedKey}\n";
        $config .= "Endpoint = {$serverHost}:{$serverPort}\n";
        $config .= "AllowedIPs = 0.0.0.0/0\n";
        $config .= "PersistentKeepalive = 25\n";
        $config .= "MTU = 1280\n";
        
        return $config;
    }
    
    /**
     * Add client to server using official method (append + wg syncconf)
     */
    public static function addClientToServer(array|VpnServer $server, string $publicKey, string $clientIP, string $privateKey = '', string $name = ''): void {
        $serverData = is_array($server) ? $server : $server->getData();
        $containerName = $serverData['container_name'];
        
        // 1. Get current config
        $readCmd = "docker exec -i {$containerName} cat /opt/amnezia/awg/wg0.conf";
        $currentConfig = self::executeServerCommand($server, $readCmd, true);
        
        // 2. Check if peer already exists (IDEMPOTENCY)
        if (strpos($currentConfig, $publicKey) !== false) {
            return; 
        }

        // 3. Build full new config
        $newPeer = "\n# Name = " . ($name ?: $clientIP) . "\n";
        $newPeer .= "[Peer]\n";
        $newPeer .= "PublicKey = {$publicKey}\n";
        $newPeer .= "PresharedKey = {$serverData['preshared_key']}\n";
        $newPeer .= "AllowedIPs = {$clientIP}/32\n";
        
        $fullConfig = rtrim($currentConfig) . $newPeer;
        $base64 = base64_encode($fullConfig);
        
        // 4. ATOMIC WRITE: Write to temp, then move
        $writeCmd = sprintf(
            "docker exec -i %s bash -c " . escapeshellarg(
                "echo " . escapeshellarg($base64) . " | base64 -d > /opt/amnezia/awg/wg0.conf.tmp && " .
                "mv /opt/amnezia/awg/wg0.conf.tmp /opt/amnezia/awg/wg0.conf && " .
                "chmod 600 /opt/amnezia/awg/wg0.conf && " .
                "/usr/local/bin/awg syncconf wg0 <(/usr/local/bin/awg-quick strip /opt/amnezia/awg/wg0.conf)"
            ),
            $containerName
        );
        
        self::executeServerCommand($server, $writeCmd, true);
        
        // Update clientsTable with private key for discovery resilience
        self::updateClientsTable($server, $publicKey, $name ?: $clientIP, $privateKey);
    }
    
    /**
     * Update clientsTable on server
     */
    private static function updateClientsTable(array|VpnServer $server, string $publicKey, string $name, string $privateKey = ''): void {
        $serverData = is_array($server) ? $server : $server->getData();
        $containerName = $serverData['container_name'];
        
        // Read current table
        $cmd = sprintf("docker exec -i %s cat /opt/amnezia/awg/clientsTable 2>/dev/null", $containerName);
        $tableJson = self::executeServerCommand($server, $cmd, true);
        $table = json_decode(trim($tableJson), true);
        
        if (!is_array($table)) {
            $table = [];
        }
        
        // Add new client
        $table[] = [
            'clientId' => $publicKey,
            'public_key' => $publicKey, // for easier discovery
            'private_key' => $privateKey, // for discovery resilience
            'name' => $name,
            'userData' => [
                'clientName' => $name,
                'creationDate' => date('D M j H:i:s Y')
            ]
        ];
        
        // Save back
        $newTableJson = json_encode($table, JSON_PRETTY_PRINT);
        $escaped = addslashes($newTableJson);
        $updateCmd = sprintf("docker exec -i %s sh -c 'echo \"%s\" > /opt/amnezia/awg/clientsTable'", $containerName, $escaped);
        self::executeServerCommand($server, $updateCmd, true);
    }
    
    /**
     * Execute command on server.
     * Delegates to VpnServer::executeCommand for hardened logic and error handling.
     */
    private static function executeServerCommand(array|VpnServer $server, string $command, bool $sudo = false): string {
        if (is_array($server)) {
            $server = new VpnServer((int)$server['id']);
        }
        // Use true for checkExit to ensure failures throw exceptions
        return $server->executeCommand($command, $sudo, true);
    }
    

    
    /**
     * Get all clients for a server
     */
    public static function listByServer(int $serverId): array {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT * FROM vpn_clients WHERE server_id = ? AND deleted_at IS NULL ORDER BY created_at DESC');
        $stmt->execute([$serverId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get all clients for a user
     */
    public static function listByUser(int $userId): array {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('
            SELECT c.*, s.name as server_name, s.host as server_host
            FROM vpn_clients c
            LEFT JOIN vpn_servers s ON c.server_id = s.id
            WHERE c.user_id = ? AND c.deleted_at IS NULL
            ORDER BY c.created_at DESC
        ');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /**
     * List all clients (admin only)
     */
    public static function listAll(): array {
        $pdo = DB::conn();
        $stmt = $pdo->query('
            SELECT c.*, s.name as server_name, s.host as server_host
            FROM vpn_clients c
            LEFT JOIN vpn_servers s ON c.server_id = s.id
            WHERE c.deleted_at IS NULL
            ORDER BY c.created_at DESC
        ');
        return $stmt->fetchAll();
    }
    
    /**
     */
    public function revoke(): bool {
        $this->setStatus(ClientStatus::DISABLED);
        
        // Queue the infrastructure removal
        require_once __DIR__ . '/Queue.php';
        Queue::push('deployments', [
            'type' => 'revoke_client',
            'client_id' => $this->clientId,
            'server_id' => $this->data['server_id']
        ]);
        
        return true;
    }
    
    /**
     * Restore client access
     */
    public function restore(): bool {
        if (!$this->data) {
            throw new Exception('Client not loaded');
        }
        
        $this->setStatus(ClientStatus::PROVISIONING);
        
        // Re-queue the provisioning
        require_once __DIR__ . '/Queue.php';
        Queue::push('deployments', [
            'type' => 'provision_client',
            'client_id' => $this->clientId,
            'server_id' => $this->data['server_id']
        ]);
        
        return true;
    }
    
    /**
     * Delete client permanently
     */
    public function delete(): bool {
        if (!$this->data) {
            throw new Exception('Client not loaded');
        }
        
        // Set to DELETING status first
        $pdo = DB::conn();
        $pdo->prepare('UPDATE vpn_clients SET status = ? WHERE id = ?')
            ->execute([ClientStatus::DELETING->value, $this->clientId]);
            
        // Queue the infrastructure removal + DB cleanup
        require_once __DIR__ . '/Queue.php';
        Queue::push('deployments', [
            'type' => 'delete_client',
            'client_id' => $this->clientId,
            'server_id' => $this->data['server_id']
        ]);
        
        return true;
    }
    
    /**
     * Remove client from server WireGuard configuration
     */
    public static function removeClientFromServer(array|VpnServer $server, string $publicKey): void {
        $serverData = is_array($server) ? $server : $server->getData();
        $containerName = $serverData['container_name'];
        
        // 1. UPDATE CONFIG (Desired State)
        $readCmd = sprintf("docker exec -i %s cat /opt/amnezia/awg/wg0.conf", $containerName);
        $config = self::executeServerCommand($server, $readCmd, true);
        $newConfig = self::removePeerFromConfig($config, $publicKey);
        
        $base64Config = base64_encode($newConfig);
        $writeCmd = sprintf(
            "docker exec -i %s sh -c " . escapeshellarg(
                "echo " . escapeshellarg($base64Config) . " | base64 -d > /opt/amnezia/awg/wg0.conf.tmp && " .
                "mv /opt/amnezia/awg/wg0.conf.tmp /opt/amnezia/awg/wg0.conf && " .
                "chmod 600 /opt/amnezia/awg/wg0.conf"
            ),
            $containerName
        );
        self::executeServerCommand($server, $writeCmd, true);

        // 2. APPLY (syncconf)
        $syncCmd = sprintf(
            "docker exec -i %s bash -c '/usr/local/bin/awg syncconf wg0 <(/usr/local/bin/awg-quick strip /opt/amnezia/awg/wg0.conf)'",
            $containerName
        );
        self::executeServerCommand($server, $syncCmd, true);
        
        // 3. VERIFICATION (Retry loop)
        $verified = self::verifyPeerInRuntime($server, $publicKey, false);
        if (!$verified) {
            throw new Exception("Runtime removal failed: Peer {$publicKey} still exists in kernel memory after 5 attempts.");
        }

        // 4. METADATA REMOVAL
        self::removeFromClientsTable($server, $publicKey);
    }
    
    /**
     * Remove peer section from WireGuard config
     */
    private static function removePeerFromConfig(string $config, string $publicKey): string {
        $lines = explode("\n", rtrim($config));
        $newLines = [];
        $currentBlock = [];
        $isPeerBlock = false;
        $shouldSkipBlock = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // New section starts
            if (strpos($trimmed, '[') === 0) {
                // Process previous block before starting new one
                if (!empty($currentBlock)) {
                    if (!$shouldSkipBlock) {
                        foreach ($currentBlock as $blockLine) {
                            $newLines[] = $blockLine;
                        }
                    }
                    $currentBlock = [];
                }
                
                $isPeerBlock = ($trimmed === '[Peer]');
                $shouldSkipBlock = false;
                $currentBlock[] = $line;
                continue;
            }

            // If we are in a peer block, check for the public key
            if ($isPeerBlock && strpos($trimmed, 'PublicKey') === 0) {
                $parts = explode('=', $line, 2);
                if (count($parts) === 2 && trim($parts[1]) === $publicKey) {
                    $shouldSkipBlock = true;
                }
            }

            // If we are not in a section, we might be in global config or inside a section
            if (!empty($currentBlock)) {
                $currentBlock[] = $line;
            } else {
                $newLines[] = $line;
            }
        }

        // Process the final block
        if (!empty($currentBlock) && !$shouldSkipBlock) {
            foreach ($currentBlock as $blockLine) {
                $newLines[] = $blockLine;
            }
        }

        return implode("\n", $newLines) . "\n";
    }
    
    /**
     * Remove client from clientsTable
     */
    private static function removeFromClientsTable(array|VpnServer $server, string $publicKey): void {
        $serverData = is_array($server) ? $server : $server->getData();
        $containerName = $serverData['container_name'];
        
        // Read current table - ignore failure if table doesn't exist
        $cmd = sprintf("docker exec -i %s cat /opt/amnezia/awg/clientsTable 2>/dev/null", $containerName);
        $tableJson = self::executeServerCommand($server, $cmd, false);
        $table = json_decode(trim($tableJson), true);
        
        if (!is_array($table)) {
            return;
        }
        
        // Filter out the client
        $table = array_filter($table, function($client) use ($publicKey) {
            return ($client['clientId'] ?? '') !== $publicKey;
        });
        
        // Re-index array
        $table = array_values($table);
        
        // Save back
        $newTableJson = json_encode($table, JSON_PRETTY_PRINT);
        $escaped = addslashes($newTableJson);
        $updateCmd = sprintf("docker exec -i %s sh -c 'echo \"%s\" > /opt/amnezia/awg/clientsTable'", $containerName, $escaped);
        self::executeServerCommand($server, $updateCmd, true);
    }
    
    /**
     * Get current client status as Enum
     */
    public function getStatus(): ClientStatus
    {
        if (isset($this->data['status']) && $this->data['status'] instanceof ClientStatus) {
            return $this->data['status'];
        }
        return ClientStatus::tryFrom((string)($this->data['status'] ?? '')) ?? ClientStatus::ACTIVE;
    }

    /**
     * Set client status with transition validation
     */
    public function setStatus(ClientStatus $newStatus): bool
    {
        if (!$this->data) return false;
        
        $currentStatus = $this->getStatus();
        if (!$currentStatus->canTransitionTo($newStatus)) {
            throw new Exception("Invalid status transition from {$currentStatus->value} to {$newStatus->value}");
        }

        $pdo = DB::conn();
        $stmt = $pdo->prepare('UPDATE vpn_clients SET status = ? WHERE id = ?');
        if ($stmt->execute([$newStatus->value, $this->data['id']])) {
            $this->data['status'] = $newStatus;
            return true;
        }
        return false;
    }

    /**
     * Get client data
     */
    public function getData(): ?array {
        if (!$this->data) return null;
        
        $data = $this->data;
        
        // Calculate connection status — threshold: 300s (5 min) matches WireGuard keepalive
        if (empty($data['last_handshake'])) {
            $data['connection_status'] = 'never';
        } else {
            $handshakeTime = strtotime($data['last_handshake']);
            $data['connection_status'] = (time() - $handshakeTime < 300) ? 'online' : 'offline';
        }
        if ($data['status'] instanceof ClientStatus) {
            $data['status'] = $data['status']->value;
        }
        
        return $data;
    }
    
    /**
     * Get configuration file content
     */
    public function getConfig(): string {
        $config = $this->data['config'] ?? '';
        // If config is missing or old, regenerate it
        if (!$config || strpos($config, '# Name:') === false) {
            try {
                $this->regenerateConfig();
                return $this->data['config'] ?? '';
            } catch (Exception $e) {
                // Fallback to existing config if regeneration fails
            }
        }
        return $config;
    }
    
    /**
     * Regenerate and save client configuration
     */
    public function regenerateConfig(): void {
        if (!$this->data) {
            throw new Exception('Client not loaded');
        }
        
        $server = new VpnServer($this->data['server_id']);
        $serverData = $server->getData();
        
        if (!$serverData) {
            throw new Exception('Server not found');
        }
        
        // Parse AWG params
        $awgParams = $serverData['awg_params'] ?? [];
        if (is_string($awgParams)) {
            $awgParams = json_decode($awgParams, true) ?: [];
        }
        
        $config = self::buildClientConfig(
            $this->data['private_key'],
            $this->data['client_ip'],
            $serverData['server_public_key'],
            $serverData['preshared_key'],
            $serverData['host'],
            $serverData['vpn_port'],
            $awgParams,
            $serverData['name'],
            $this->data['name']
        );
        
        $pdo = DB::conn();
        $stmt = $pdo->prepare('UPDATE vpn_clients SET config = ? WHERE id = ?');
        $stmt->execute([$config, $this->clientId]);
        
        $this->data['config'] = $config;
    }
    

    
    /**
     * Sync traffic statistics from server
     */
    public function syncStats(): bool {
        if (!$this->data) {
            throw new Exception('Client not loaded');
        }
        
        $server = new VpnServer($this->data['server_id']);
        $serverData = $server->getData();

        if (!$serverData) {
            throw new Exception('Server not found for this client');
        }
        if ($serverData['status'] !== ServerStatus::ACTIVE->value) {
            throw new Exception('Server is not active (status: ' . $serverData['status'] . ')');
        }

        // 1. Sync traffic via ServerMonitoring to prevent counter resets and double counting
        try {
            require_once __DIR__ . '/ServerMonitoring.php';
            $monitoring = new ServerMonitoring($this->data['server_id']);
            $metricsData = $monitoring->collectClientMetrics(); 
            $allStats = $metricsData['peer_stats'] ?? [];
        } catch (Exception $e) {
            \Logger::error('syncStats(): ServerMonitoring failed for client #' . $this->data['id'] . ': ' . $e->getMessage());
            $allStats = []; 
        }

        // 2. If monitoring didn't return the peer map, fetch it manually (legacy fallback or error)
        if (empty($allStats)) {
            $allStats = self::getAllPeerStatsFromServer($serverData);
        }

        $publicKey = $this->data['public_key'];
        if (!isset($allStats[$publicKey])) {
            throw new Exception('Client peer not found in server WireGuard dump (key: ' . substr($publicKey, 0, 12) . '…)');
        }

        $stats = $allStats[$publicKey];
        $newExternalIp = $stats['endpoint'] ?? null;

        // Fetch geo data if the external IP changed OR if we don't have geo data yet
        if ($newExternalIp && $newExternalIp !== '(none)' && ($newExternalIp !== ($this->data['external_ip'] ?? null) || empty($this->data['ip_country']))) {
            $geo = self::lookupIpGeo($newExternalIp);
            if ($geo) {
                $pdo = DB::conn();
                $stmt = $pdo->prepare('
                    UPDATE vpn_clients
                    SET external_ip = ?,
                        ip_country = ?, ip_country_code = ?, ip_city = ?, ip_isp = ?, ip_org = ?,
                        ip_lat = ?, ip_lon = ?
                    WHERE id = ?
                ');
                return $stmt->execute([
                    $newExternalIp,
                    $geo['country'], $geo['countryCode'], $geo['city'], $geo['isp'], $geo['org'],
                    $geo['lat'] ?? null, $geo['lon'] ?? null,
                    $this->clientId
                ]);
            }
        }

        return true;
    }
    
    /**
     * Get ALL peer statistics from server in a single SSH call.
     * Returns a map of publicKey => stats.
     * 
     * Dump format (tab-separated):
     * Server line:  iface  privateKey  publicKey  listenPort  fwmark  ...
     * Peer line:    iface  publicKey   presharedKey  endpoint  allowedIPs  latestHandshake  transferRx  transferTx  persistentKeepalive
     */
    private static function getAllPeerStatsFromServer(array $serverData): array {
        $containerName = $serverData['container_name'];
        
        $cmd = sprintf("docker exec -i %s /usr/local/bin/awg show all dump", $containerName);
        $output = self::executeServerCommand($serverData, $cmd, true);
        
        $peers = [];
        $lines = explode("\n", trim($output));
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Split by any whitespace (tabs or spaces) to be more resilient
            $parts = preg_split('/\s+/', $line);
            $count = count($parts);
            
            // Search for the Public Key (44 chars ending in =) to identify the peer.
            $isKey0 = (strlen($parts[0]) === 44 && str_ends_with($parts[0], '='));
            $isKey1 = (isset($parts[1]) && strlen($parts[1]) === 44 && str_ends_with($parts[1], '='));

            if ($isKey0) {
                $offset = 0;
            } elseif ($isKey1) {
                $offset = 1;
            } else {
                continue;
            }

            if ($count < (5 + $offset)) continue;

            $publicKey = $parts[0 + $offset];
            $endpoint = $parts[2 + $offset] ?? '(none)';
            $handshake = (int)($parts[4 + $offset] ?? 0);
            $bytesRx = (int)($parts[5 + $offset] ?? 0);
            $bytesTx = (int)($parts[6 + $offset] ?? 0);
            
            // Clean endpoint IP (strip port, handle IPv6)
            $externalIp = $endpoint;
            if ($endpoint !== '(none)') {
                if (strpos($endpoint, ']:') !== false) { // IPv6 [addr]:port
                    $externalIp = substr($endpoint, 1, strpos($endpoint, ']:') - 1);
                } elseif (strpos($endpoint, ':') !== false) { // IPv4 addr:port
                    $externalIp = explode(':', $endpoint)[0];
                }
            }
            
            $peers[$publicKey] = [
                'bytes_sent' => $bytesRx,       // client upload
                'bytes_received' => $bytesTx,   // client download
                'last_handshake' => $handshake,
                'endpoint' => $externalIp,
            ];
        }
        
        return $peers;
    }
    
    /**
     * Sync stats for all active clients on a server.
     * Uses a single SSH call to get all peer data at once.
     */
    public static function syncAllStatsForServer(int $serverId): int {
        $pdo = DB::conn();

        // Get server data
        $server = new VpnServer($serverId);
        $serverData = $server->getData();

        if (!$serverData || $serverData['status'] !== ServerStatus::ACTIVE->value) {
            return 0;
        }

        // Bypassing legacy active polling for servers migrated to the push telemetry model
        if (!empty($serverData['telemetry_token'])) {
            return 0;
        }

        // 1. Sync traffic via ServerMonitoring to prevent counter resets and double counting
        try {
            require_once __DIR__ . '/ServerMonitoring.php';
            $monitoring = new ServerMonitoring($serverId);
            $monitoring->collectClientMetrics();
        } catch (Exception $e) {
            \Logger::error('syncAllStatsForServer(): ServerMonitoring failed for server #' . $serverId . ': ' . $e->getMessage());
        }

        // 2. Sync Geo Data and IP
        $stmt = $pdo->prepare('SELECT id, public_key, external_ip, ip_country, ip_country_code, ip_city, ip_isp, ip_org, ip_lat, ip_lon FROM vpn_clients WHERE server_id = ? AND status = ?');
        $stmt->execute([$serverId, ClientStatus::ACTIVE->value]);
        $clients = $stmt->fetchAll();

        if (empty($clients)) {
            // Even if no active clients in DB, we should still reconcile to clean orphans
            self::reconcilePeers($serverId);
            return 0;
        }

        // Full reconciliation: prune orphans and restore missing peers
        self::reconcilePeers($serverId);

        // Single SSH call to get all peer stats (we need this for the IP endpoint)
        $allStats = self::getAllPeerStatsFromServer($serverData);

        $synced = 0;
        $updateStmt = $pdo->prepare('
            UPDATE vpn_clients
            SET external_ip = ?,
                ip_country = ?, ip_country_code = ?, ip_city = ?, ip_isp = ?, ip_org = ?,
                ip_lat = ?, ip_lon = ?
            WHERE id = ?
        ');

        // Wrap all writes in a transaction for consistency
        $pdo->beginTransaction();
        try {
            foreach ($clients as $client) {
                $publicKey = $client['public_key'];
                if (!isset($allStats[$publicKey])) {
                    continue;
                }

                $stats = $allStats[$publicKey];
                $newIp = $stats['endpoint'];

                // Default to existing geo values
                $geo = [
                    'country'     => $client['ip_country'] ?? null,
                    'countryCode' => $client['ip_country_code'] ?? null,
                    'city'        => $client['ip_city'] ?? null,
                    'isp'         => $client['ip_isp'] ?? null,
                    'org'         => $client['ip_org'] ?? null,
                    'lat'         => $client['ip_lat'] ?? null,
                    'lon'         => $client['ip_lon'] ?? null,
                ];

                // Refresh geo only when IP changes or geo is missing
                if ($newIp && ($newIp !== $client['external_ip'] || empty($client['ip_country']))) {
                    $freshGeo = self::lookupIpGeo($newIp);
                    if ($freshGeo) {
                        $geo = [
                            'country'     => $freshGeo['country'],
                            'countryCode' => $freshGeo['countryCode'],
                            'city'        => $freshGeo['city'],
                            'isp'         => $freshGeo['isp'],
                            'org'         => $freshGeo['org'],
                            'lat'         => $freshGeo['lat'] ?? null,
                            'lon'         => $freshGeo['lon'] ?? null,
                        ];
                    }
                }

                $updateStmt->execute([
                    $newIp,
                    $geo['country'],
                    $geo['countryCode'],
                    $geo['city'],
                    $geo['isp'],
                    $geo['org'],
                    $geo['lat'],
                    $geo['lon'],
                    $client['id'],
                ]);
                $synced++;
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            \Logger::error('syncAllStatsForServer(): transaction rolled back for server #' . $serverId . ': ' . $e->getMessage());
            return 0;
        }

        return $synced;
    }
    
    /**
     * Get human-readable traffic statistics
     */
    public function getFormattedStats(): array {
        if (!$this->data) {
            return ['sent' => 'N/A', 'received' => 'N/A', 'total' => 'N/A', 'last_seen' => 'Never'];
        }
        
        $sent = $this->formatBytes($this->data['bytes_sent'] ?? 0);
        $received = $this->formatBytes($this->data['bytes_received'] ?? 0);
        $total = $this->formatBytes(($this->data['bytes_sent'] ?? 0) + ($this->data['bytes_received'] ?? 0));
        
        $lastSeen = 'Never';
        if (!empty($this->data['last_handshake'])) {
            $lastHandshake = strtotime($this->data['last_handshake']);
            $diff = time() - $lastHandshake;
            
            if ($diff < 300) {
                $lastSeen = 'Online';
            } elseif ($diff < 3600) {
                $lastSeen = floor($diff / 60) . ' minutes ago';
            } elseif ($diff < 86400) {
                $lastSeen = floor($diff / 3600) . ' hours ago';
            } else {
                $lastSeen = floor($diff / 86400) . ' days ago';
            }
        }
        
        return [
            'sent' => $sent,
            'received' => $received,
            'total' => $total,
            'last_seen' => $lastSeen,
            'is_online' => !empty($this->data['last_handshake']) && (time() - strtotime($this->data['last_handshake'])) < 300,
            'speed_up' => self::formatSpeed((float)($this->data['speed_up_kbps'] ?? 0)),
            'speed_down' => self::formatSpeed((float)($this->data['speed_down_kbps'] ?? 0))
        ];
    }

    public static function formatSpeed(float $kbps): string {
        if ($kbps >= 1000) {
            return number_format($kbps / 1000, 1) . ' Mbps';
        }
        return number_format($kbps, 0) . ' Kbps';
    }
    
    /**
     * Format bytes to human-readable string (auto-scale to MB/GB)
     */
    private function formatBytes(int $bytes): string {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }
        return number_format($bytes / 1048576, 2) . ' MB';
    }
    
    /**
     * Set client expiration date
     * 
     * @param int $clientId Client ID
     * @param string|null $expiresAt Expiration date (Y-m-d H:i:s) or null for never expires
     * @return bool Success
     */
    public static function setExpiration(int $clientId, ?string $expiresAt): bool {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('UPDATE vpn_clients SET expires_at = ? WHERE id = ?');
        return $stmt->execute([$expiresAt, $clientId]);
    }
    
    /**
     * Extend client expiration by days
     * 
     * @param int $clientId Client ID
     * @param int $days Days to extend
     * @return bool Success
     */
    public static function extendExpiration(int $clientId, int $days): bool {
        $pdo = DB::conn();
        
        // Get current expiration
        $stmt = $pdo->prepare('SELECT expires_at FROM vpn_clients WHERE id = ?');
        $stmt->execute([$clientId]);
        $client = $stmt->fetch();
        
        if (!$client) {
            return false;
        }
        
        // Calculate new expiration from current or now
        $baseDate = $client['expires_at'] ? strtotime($client['expires_at']) : time();
        $newExpiration = date('Y-m-d H:i:s', strtotime("+{$days} days", $baseDate));
        
        return self::setExpiration($clientId, $newExpiration);
    }
    
    /**
     * Get clients expiring soon
     * 
     * @param int $days Check for clients expiring within N days
     * @return array List of expiring clients
     */
    public static function getExpiringClients(int $days = 7): array {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('
            SELECT c.*, s.name as server_name, s.host, u.name as user_name, u.email
            FROM vpn_clients c
            JOIN vpn_servers s ON c.server_id = s.id
            JOIN users u ON c.user_id = u.id
            WHERE c.expires_at IS NOT NULL 
            AND c.expires_at <= DATE_ADD(NOW(), INTERVAL ? DAY)
            AND c.expires_at > NOW()
            AND c.status = ?
            ORDER BY c.expires_at ASC
        ');
        $stmt->execute([$days, ClientStatus::ACTIVE->value]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get expired clients
     * 
     * @return array List of expired clients
     */
    public static function getExpiredClients(): array {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('
            SELECT c.*, s.name as server_name, s.host
            FROM vpn_clients c
            JOIN vpn_servers s ON c.server_id = s.id
            WHERE c.expires_at IS NOT NULL 
            AND c.expires_at <= NOW()
            AND c.status = ?
            ORDER BY c.expires_at DESC
        ');
        $stmt->execute([ClientStatus::ACTIVE->value]);
        return $stmt->fetchAll();
    }
    
    /**
     * Disable expired clients automatically
     * 
     * @return int Number of clients disabled
     */
    public static function disableExpiredClients(): int {
        return 0; // Functionality disabled per user request
    }
    
    /**
     * Check if client is expired
     * 
     * @return bool True if expired
     */
    public function isExpired(): bool {
        if (!$this->data) {
            return false;
        }
        
        return $this->data['expires_at'] !== null && strtotime($this->data['expires_at']) <= time();
    }
    
    /**
     * Get days until expiration
     * 
     * @return int|null Days until expiration (negative if expired, null if never expires)
     */
    public function getDaysUntilExpiration(): ?int {
        if (!$this->data || $this->data['expires_at'] === null) {
            return null;
        }
        
        $diff = strtotime($this->data['expires_at']) - time();
        return (int)floor($diff / 86400);
    }
    
    /**
     * Set traffic limit for client
     * 
     * @param int|null $limitBytes Traffic limit in bytes (NULL = unlimited)
     * @return bool Success
     */
    public function setTrafficLimit(?int $limitBytes): bool {
        if (!$this->data) {
            throw new Exception('Client not loaded');
        }
        
        $pdo = DB::conn();
        $stmt = $pdo->prepare('UPDATE vpn_clients SET traffic_limit = ? WHERE id = ?');
        $result = $stmt->execute([$limitBytes, $this->clientId]);
        
        if ($result) {
            $this->data['traffic_limit'] = $limitBytes;
        }
        
        return $result;
    }
    
    /**
     * Get total traffic used (sent + received)
     * 
     * @return int Total traffic in bytes
     */
    public function getTotalTraffic(): int {
        if (!$this->data) {
            return 0;
        }
        
        return (int)($this->data['bytes_sent'] ?? 0) + (int)($this->data['bytes_received'] ?? 0);
    }
    
    /**
     * Check if client has exceeded traffic limit
     * 
     * @return bool True if over limit
     */
    public function isOverLimit(): bool {
        if (!$this->data || $this->data['traffic_limit'] === null) {
            return false; // No limit set
        }
        
        $totalTraffic = $this->getTotalTraffic();
        return $totalTraffic >= (int)$this->data['traffic_limit'];
    }
    
    /**
     * Get traffic limit status
     * 
     * @return array Status info
     */
    public function getTrafficLimitStatus(): array {
        $totalTraffic = $this->getTotalTraffic();
        $limit = $this->data['traffic_limit'] ?? null;
        
        return [
            'total_traffic' => $totalTraffic,
            'traffic_limit' => $limit,
            'is_unlimited' => $limit === null,
            'is_over_limit' => $this->isOverLimit(),
            'percentage_used' => $limit ? min(100, round(($totalTraffic / $limit) * 100, 2)) : 0,
            'remaining' => $limit ? max(0, $limit - $totalTraffic) : null
        ];
    }
    
    /**
 * Get all clients that exceeded their traffic limit
 * 
 * @return array List of client IDs over limit
 */
public static function getClientsOverLimit(): array {
    $pdo = DB::conn();
    $stmt = $pdo->prepare('
        SELECT id, name, bytes_sent, bytes_received, traffic_limit 
        FROM vpn_clients 
        WHERE traffic_limit IS NOT NULL 
        AND (bytes_sent + bytes_received) >= traffic_limit 
        AND status = ?
        ORDER BY id
    ');
    $stmt->execute([ClientStatus::ACTIVE->value]);
    return $stmt->fetchAll();
}
    
    /**
     * Disable all clients that exceeded their traffic limit
     * 
     * @return int Number of clients disabled
     */
    public static function disableClientsOverLimit(): int {
        return 0; // Functionality disabled per user request
    }

    /**
     * Lookup IP Geolocation data using ip-api.com
     */
    private static function lookupIpGeo(string $ip): ?array {
        // Defensive: strip port if present
        if (strpos($ip, ':') !== false) {
            $ip = (strpos($ip, ']:') !== false) ? substr($ip, 1, strpos($ip, ']:') - 1) : explode(':', $ip)[0];
        }

        // First try ip-api.com (HTTP only for free tier)
        $fields = 'status,message,country,countryCode,city,isp,org,lat,lon,query';
        $url = "http://ip-api.com/json/{$ip}?fields={$fields}";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, 'NK-Panel/1.0');
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        // curl_close is no longer needed in PHP 8.0+
        
        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (($data['status'] ?? '') === 'success') {
                return $data;
            }
            if (class_exists('Logger')) {
                Logger::warning("GeoIP lookup (ip-api.com) failed for {$ip}: " . ($data['message'] ?? 'Unknown error'));
            }
        } else {
            if (class_exists('Logger')) {
                Logger::warning("GeoIP lookup (ip-api.com) unreachable for {$ip}. HTTP: {$httpCode}, Error: {$error}");
            }
        }

        // Fallback to freeipapi.com (Supports HTTPS)
        if (class_exists('Logger')) {
            Logger::info("Trying fallback GeoIP (freeipapi.com) for {$ip}");
        }
        
        $url = "https://freeipapi.com/api/json/{$ip}";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, 'NK-Panel/1.0');
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // curl_close is no longer needed in PHP 8.0+
        
        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (isset($data['countryName'])) {
                return [
                    'status' => 'success',
                    'country' => $data['countryName'],
                    'countryCode' => $data['countryCode'],
                    'city' => $data['cityName'],
                    'isp' => $data['as'] ?? 'Unknown',
                    'org' => $data['as'] ?? 'Unknown',
                    'lat' => $data['latitude'] ?? null,
                    'lon' => $data['longitude'] ?? null,
                    'query' => $ip
                ];
            }
        }

        return null;
    }

    /**
     * Reconcile runtime peers with DB state.
     * Prunes orphans and restores missing active peers.
     */
    public static function reconcilePeers(int $serverId): array {
        $server = new VpnServer($serverId);
        $serverData = $server->getData();
        if (!$serverData || $serverData['status'] !== ServerStatus::ACTIVE->value) return [];

        $runtimePeers = self::getAllPeerStatsFromServer($serverData);
        $runtimeKeys = array_keys($runtimePeers);

        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT public_key FROM vpn_clients WHERE server_id = ? AND deleted_at IS NULL AND status IN ("active", "disabled", "provisioning", "verifying")');
        $stmt->execute([$serverId]);
        $dbKeys = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // 1. Find Orphans: In runtime but NOT in DB (or status deleted/error)
        $orphans = array_diff($runtimeKeys, $dbKeys);
        foreach ($orphans as $orphanKey) {
            \Logger::channel('deployments')->warning("Found orphan peer on server #$serverId, removing", ['publicKey' => $orphanKey]);
            try {
                self::removeClientFromServer($serverData, $orphanKey);
            } catch (Exception $e) {
                \Logger::error("Failed to remove orphan #$orphanKey: " . $e->getMessage());
            }
        }

        // 2. Find Missing: In DB (ACTIVE) but NOT in runtime
        $stmt = $pdo->prepare('SELECT id, public_key FROM vpn_clients WHERE server_id = ? AND status = ? AND deleted_at IS NULL');
        $stmt->execute([$serverId, ClientStatus::ACTIVE->value]);
        $activeClients = $stmt->fetchAll();
        
        $readded = 0;
        foreach ($activeClients as $row) {
            if (!in_array($row['public_key'], $runtimeKeys)) {
                \Logger::channel('deployments')->info("Active client #{$row['id']} missing from runtime, re-adding", ['publicKey' => $row['public_key']]);
                try {
                    $client = new VpnClient((int)$row['id']);
                    $client->syncToRemote();
                    $readded++;
                } catch (Exception $e) {
                    \Logger::error("Failed to re-add missing client #{$row['id']}: " . $e->getMessage());
                }
            }
        }
        
        return [
            'removed_orphans' => count($orphans),
            'readded_missing' => $readded
        ];
    }

    /**
     * Deep runtime verification with retries.
     * Checks if peer exists (or doesn't) and optionally validates AllowedIPs.
     */
    private static function verifyPeerInRuntime(array|VpnServer $server, string $publicKey, bool $shouldExist, ?string $expectedIP = null): bool {
        $serverData = is_array($server) ? $server : $server->getData();
        $containerName = $serverData['container_name'];
        $verifyCmd = "docker exec -i {$containerName} /usr/local/bin/awg show wg0";
        
        for ($i = 0; $i < 5; $i++) {
            try {
                $output = self::executeServerCommand($server, $verifyCmd, false);
                $lines = explode("\n", (string)$output);
                
                $found = false;
                $ipMatch = false;
                $inPeerBlock = false;
                
                foreach ($lines as $line) {
                    $trimmed = trim($line);
                    if (strpos($trimmed, 'peer: ' . $publicKey) === 0) {
                        $found = true;
                        $inPeerBlock = true;
                        continue;
                    }
                    if ($inPeerBlock && strpos($trimmed, 'peer: ') === 0) {
                        $inPeerBlock = false;
                    }
                    
                    if ($inPeerBlock && $expectedIP && strpos($trimmed, 'allowed ips: ' . $expectedIP) === 0) {
                        $ipMatch = true;
                    }
                }
                
                if ($shouldExist) {
                    if ($found && (!$expectedIP || $ipMatch)) return true;
                } else {
                    if (!$found) return true;
                }
            } catch (Exception $e) {
                // Ignore SSH/exec errors during verification retries
            }
            
            usleep(500000); // 500ms between retries
        }
        
        return false;
    }
}
