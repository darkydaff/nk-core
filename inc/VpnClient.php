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
        $stmt = $pdo->prepare('SELECT * FROM vpn_clients WHERE id = ? AND deleted_at IS NULL');
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
        $pdo->beginTransaction();
        
        try {
        
        // Sanitize and validate client name
        $name = trim($name);
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $name)) {
            // If it contains "forbidden" chars, fallback to a safe version but keep it recognizable
            $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
        }
        if (empty($name)) $name = 'client_' . time();
        
        // Get server data
        $server = new VpnServer($serverId);
        $serverData = $server->getData();
        
        if (!$serverData || $serverData['status'] !== ServerStatus::ACTIVE->value) {
            throw new Exception('Server is not active');
        }
        
        // Generate client keys
        $containerName = $serverData['container_name'];
        $keys = self::generateClientKeys($serverData, $name);
        
        // Get next available IP
        $clientIP = self::getNextClientIP($serverData);

        // Final safety check for IP collision within transaction
        $checkStmt = $pdo->prepare('SELECT id FROM vpn_clients WHERE server_id = ? AND client_ip = ? AND deleted_at IS NULL FOR UPDATE');
        $checkStmt->execute([$serverId, $clientIP]);
        if ($checkStmt->fetch()) {
             throw new Exception("IP collision detected for {$clientIP}. Please try again.");
        }
        
        // Get AWG parameters from server
        $awgParams = $serverData['awg_params'] ?? [];
        if (is_string($awgParams)) {
            $awgParams = json_decode($awgParams, true) ?: [];
        }
        
        // Build client configuration
        $config = self::buildClientConfig(
            $keys['private'],
            $clientIP,
            $serverData['server_public_key'],
            $serverData['preshared_key'],
            $serverData['host'],
            $serverData['vpn_port'],
            $awgParams,
            $serverData['name'],
            $name
        );
        
        // Add client to server
        self::addClientToServer($serverData, $keys['public'], $clientIP);
        
        // Calculate expiration date
        $expiresAt = $expiresInDays ? date('Y-m-d H:i:s', strtotime("+{$expiresInDays} days")) : null;
        
        // Insert into database
        $stmt = $pdo->prepare('
            INSERT INTO vpn_clients 
            (server_id, user_id, name, client_ip, public_key, private_key, preshared_key, config, status, expires_at, last_handshake, last_sync_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL)
        ');
        
        $stmt->execute([
            $serverId,
            $userId,
            $name,
            $clientIP,
            $keys['public'],
            $keys['private'],
            $serverData['preshared_key'],
            $config,
            ClientStatus::ACTIVE->value,
            $expiresAt
        ]);
        
        $clientId = (int)$pdo->lastInsertId();
        $pdo->commit();
        
        return $clientId;
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
    
    /**
     * Generate client keys on remote server
     */
    private static function generateClientKeys(array $serverData, string $clientName): array {
        $containerName = $serverData['container_name'];
        
        $cmd = sprintf(
            "docker exec -i %s sh -c \"umask 077; /usr/local/bin/awg genkey | tee /tmp/%s_priv.key | /usr/local/bin/awg pubkey > /tmp/%s_pub.key; cat /tmp/%s_priv.key; echo '---'; cat /tmp/%s_pub.key; rm -f /tmp/%s_priv.key /tmp/%s_pub.key\"",
            $containerName,
            $clientName, $clientName, $clientName, $clientName, $clientName, $clientName
        );
        
        $server = new VpnServer((int)$serverData['id']);
        
        try {
            $out = $server->executeCommand($cmd, true);
        } catch (Exception $e) {
            throw new Exception("Failed to generate client keys: " . $e->getMessage());
        }
        
        $parts = explode("---", trim($out));
        
        if (count($parts) < 2) {
            throw new Exception("Failed to generate client keys: Unexpected output from server: " . $out);
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
        $keys = ['Jc', 'Jmin', 'Jmax', 'S1', 'S2', 'S3', 'S4', 'H1', 'H2', 'H3', 'H4', 'I1', 'I2', 'I3', 'I4', 'I5'];
        foreach ($keys as $key) {
            if (isset($awgParams[$key]) && $awgParams[$key] !== '' && $awgParams[$key] !== null) {
                $val = $awgParams[$key];
                // S1-S4 should never be 0 (as per user requirement)
                if (in_array($key, ['S1', 'S2', 'S3', 'S4']) && (int)$val === 0) {
                    $val = 1;
                }
                $config .= "{$key} = {$val}\n";
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
    public static function addClientToServer(array $serverData, string $publicKey, string $clientIP): void {
        $containerName = $serverData['container_name'];
        
        // Build peer block
        $peerBlock = "\n[Peer]\n";
        $peerBlock .= "PublicKey = {$publicKey}\n";
        $peerBlock .= "PresharedKey = {$serverData['preshared_key']}\n";
        $peerBlock .= "AllowedIPs = {$clientIP}/32\n";
        
        $base64 = base64_encode($peerBlock);
        // Build and apply configuration in a single SSH call for speed
        $cmd = sprintf(
            "docker exec -i %s sh -c 'echo \"%s\" | base64 -d | tee -a /opt/amnezia/awg/wg0.conf > /dev/null && " .
            "/usr/local/bin/awg syncconf wg0 <(/usr/local/bin/awg-quick strip /opt/amnezia/awg/wg0.conf)'",
            $containerName,
            $base64
        );
        self::executeServerCommand($serverData, $cmd, true);
        
        // Update clientsTable
        self::updateClientsTable($serverData, $publicKey, $clientIP);
    }
    
    /**
     * Update clientsTable on server
     */
    private static function updateClientsTable(array $serverData, string $publicKey, string $name): void {
        $containerName = $serverData['container_name'];
        
        // Read current table
        $cmd = sprintf("docker exec -i %s cat /opt/amnezia/awg/clientsTable 2>/dev/null", $containerName);
        $tableJson = self::executeServerCommand($serverData, $cmd, true);
        $table = json_decode(trim($tableJson), true);
        
        if (!is_array($table)) {
            $table = [];
        }
        
        // Add new client
        $table[] = [
            'clientId' => $publicKey,
            'userData' => [
                'clientName' => $name,
                'creationDate' => date('D M j H:i:s Y')
            ]
        ];
        
        // Save back
        $newTableJson = json_encode($table, JSON_PRETTY_PRINT);
        $escaped = addslashes($newTableJson);
        $updateCmd = sprintf("docker exec -i %s sh -c 'echo \"%s\" > /opt/amnezia/awg/clientsTable'", $containerName, $escaped);
        self::executeServerCommand($serverData, $updateCmd, true);
    }
    
    /**
     * Execute command on server.
     * Tries to reuse an existing SSH ControlMaster socket if one exists
     * (e.g. from a VpnServer instance), otherwise falls back to sshpass.
     */
    private static function executeServerCommand(array $serverData, string $command, bool $sudo = false): string {
        if ($sudo && strtolower($serverData['username']) !== 'root') {
            $command = sprintf(
                "echo %s | sudo -S sh -c %s",
                escapeshellarg($serverData['password']),
                escapeshellarg($command)
            );
        }
        
        $escapedCommand = escapeshellarg($command);

        // Check for an existing ControlMaster socket from VpnServer
        $muxDir = '/tmp/ssh_mux';
        $muxSocket = $muxDir . '/nk_' . md5($serverData['host'] . ':' . $serverData['port']) . '_' . getmypid();

        $commonOpts = sprintf(
            '-p %d -q -o LogLevel=ERROR -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no ' .
            '-o ServerAliveInterval=30 -o ServerAliveCountMax=20 -o AddressFamily=inet -o Compression=no ' .
            '-o Ciphers=aes128-ctr,aes128-gcm@openssh.com,chacha20-poly1305@openssh.com',
            $serverData['port']
        );

        if (file_exists($muxSocket)) {
            // Reuse multiplexed connection
            $sshCommand = sprintf(
                'ssh -o ControlPath=%s %s %s@%s %s 2>&1',
                escapeshellarg($muxSocket),
                $commonOpts,
                escapeshellarg($serverData['username']),
                escapeshellarg($serverData['host']),
                $escapedCommand
            );
        } elseif (!empty($serverData['ssh_private_key'])) {
            // SSH key auth
            $keyPath = tempnam(sys_get_temp_dir(), 'nk_ssh_');
            file_put_contents($keyPath, $serverData['ssh_private_key']);
            chmod($keyPath, 0600);
            $sshCommand = sprintf(
                'ssh -i %s -o PubkeyAuthentication=yes %s %s@%s %s 2>&1',
                escapeshellarg($keyPath),
                $commonOpts,
                escapeshellarg($serverData['username']),
                escapeshellarg($serverData['host']),
                $escapedCommand
            );
        } else {
            // Fallback: password via sshpass
            $sshCommand = sprintf(
                "SSHPASS='%s' sshpass -e ssh %s -o PreferredAuthentications=password -o PubkeyAuthentication=no %s@%s %s 2>&1",
                str_replace("'", "'\\''", $serverData['password']),
                $commonOpts,
                escapeshellarg($serverData['username']),
                escapeshellarg($serverData['host']),
                $escapedCommand
            );
        }
        
        $result = shell_exec($sshCommand) ?? '';

        // Clean up temp key file
        if (isset($keyPath) && file_exists($keyPath)) {
            unlink($keyPath);
        }

        return $result;
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
        $server = new VpnServer($this->data['server_id']);
        $serverData = $server->getData();

        if ($serverData && $serverData['status'] === ServerStatus::ACTIVE->value) {
            try {
                self::removeClientFromServer($serverData, $this->data['public_key']);
            } catch (Exception $e) {
                // Log the failure but still mark as disabled in DB — admin can manually clean server
                \Logger::error('[WARN] revoke(): peer removal from server failed for client #' . $this->data['id']
                    . ' (key: ' . substr($this->data['public_key'], 0, 12) . '…): ' . $e->getMessage());
            }
        }

        // Mark as disabled using setStatus with validation
        return $this->setStatus(ClientStatus::DISABLED);
    }
    
    /**
     * Restore client access
     */
    public function restore(): bool {
        if (!$this->data) {
            throw new Exception('Client not loaded');
        }
        
        // Re-add to server
        $server = new VpnServer($this->data['server_id']);
        $serverData = $server->getData();
        
        if ($serverData && $serverData['status'] === ServerStatus::ACTIVE->value) {
            try {
                self::addClientToServer($serverData, $this->data['public_key'], $this->data['client_ip']);
            } catch (Exception $e) {
                throw new Exception('Failed to restore client on server: ' . $e->getMessage());
            }
        }
        
        // Mark as active using setStatus with validation
        return $this->setStatus(ClientStatus::ACTIVE);
    }
    
    /**
     * Delete client permanently
     */
    public function delete(): bool {
        if (!$this->data) {
            throw new Exception('Client not loaded');
        }
        
        // First revoke to remove from server
        if ($this->data['status'] === ClientStatus::ACTIVE) {
            $this->revoke();
        }
        
        // Mark as deleted in database instead of deleting row
        $pdo = DB::conn();
        $stmt = $pdo->prepare('UPDATE vpn_clients SET deleted_at = NOW(), status = ? WHERE id = ?');
        return $stmt->execute([ClientStatus::DELETED->value, $this->clientId]);
    }
    
    /**
     * Remove client from server WireGuard configuration
     */
    private static function removeClientFromServer(array $serverData, string $publicKey): void {
        $containerName = $serverData['container_name'];
        
        // First, remove using awg command (live removal)
        $removeCmd = sprintf(
            "docker exec -i %s /usr/local/bin/awg set wg0 peer %s remove",
            $containerName,
            escapeshellarg($publicKey)
        );
        
        self::executeServerCommand($serverData, $removeCmd, true);
        
        // Then remove from wg0.conf file to make it persistent
        // Use a more reliable method: read, filter, write
        $readCmd = sprintf("docker exec -i %s cat /opt/amnezia/awg/wg0.conf", $containerName);
        $config = self::executeServerCommand($serverData, $readCmd, true);
        
        // Parse and remove the peer section
        $newConfig = self::removePeerFromConfig($config, $publicKey);
        
        // Write back to file using base64 to avoid escaping issues
    $base64Config = base64_encode($newConfig);
    $writeCmd = sprintf(
        "echo '%s' | base64 -d | docker exec -i %s sh -c 'cat > /opt/amnezia/awg/wg0.conf'",
        $base64Config,
        $containerName
    );
    self::executeServerCommand($serverData, $writeCmd, true);
        
        // Apply via awg syncconf for surgical synchronization without drops
        $syncCmd = sprintf(
            "docker exec -i %s bash -c '/usr/local/bin/awg syncconf wg0 <(/usr/local/bin/awg-quick strip /opt/amnezia/awg/wg0.conf)'",
            $containerName
        );
        self::executeServerCommand($serverData, $syncCmd, true);
        
        // Remove from clientsTable
        self::removeFromClientsTable($serverData, $publicKey);
    }
    
    /**
     * Remove peer section from WireGuard config
     */
    private static function removePeerFromConfig(string $config, string $publicKey): string {
        $lines = explode("\n", $config);
        $newLines = [];
        $inPeerBlock = false;
        $skipBlock = false;
        
        foreach ($lines as $line) {
            $trimmed = trim($line);
            
            // Start of new section
            if (strpos($trimmed, '[') === 0) {
                $inPeerBlock = ($trimmed === '[Peer]');
                $skipBlock = false;
            }
            
            // Check if this peer block should be skipped
            if ($inPeerBlock && strpos($trimmed, 'PublicKey') === 0) {
                $parts = explode('=', $line, 2);
                if (count($parts) === 2 && trim($parts[1]) === $publicKey) {
                    $skipBlock = true;
                    // Remove the [Peer] line that was already added
                    array_pop($newLines);
                    continue;
                }
            }
            
            // Skip lines in the block to be removed
            if ($skipBlock && $inPeerBlock) {
                // Empty line ends the peer block
                if (empty($trimmed)) {
                    $skipBlock = false;
                    $inPeerBlock = false;
                }
                continue;
            }
            
            $newLines[] = $line;
        }
        
        return implode("\n", $newLines);
    }
    
    /**
     * Remove client from clientsTable
     */
    private static function removeFromClientsTable(array $serverData, string $publicKey): void {
        $containerName = $serverData['container_name'];
        
        // Read current table
        $cmd = sprintf("docker exec -i %s cat /opt/amnezia/awg/clientsTable 2>/dev/null", $containerName);
        $tableJson = self::executeServerCommand($serverData, $cmd, true);
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
        self::executeServerCommand($serverData, $updateCmd, true);
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
        // If config is old or doesn't have the new naming metadata, regenerate it once
        if ($config && strpos($config, '# Remark:') === false) {
            try {
                $this->regenerateConfig();
                return $this->data['config'];
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

        // Get ALL peer stats in one SSH call and find ours
        $allStats = self::getAllPeerStatsFromServer($serverData);
        $publicKey = $this->data['public_key'];

        if (empty($allStats)) {
            throw new Exception('Could not retrieve peer stats from server (SSH/Docker failed or no peers)');
        }
        if (!isset($allStats[$publicKey])) {
            throw new Exception('Client peer not found in server WireGuard dump (key: ' . substr($publicKey, 0, 12) . '…)');
        }

        $stats = $allStats[$publicKey];
        $newExternalIp = $stats['endpoint'];

        // Fetch geo data if the external IP changed OR if we don't have geo data yet
        $geo = null;
        if ($newExternalIp && ($newExternalIp !== ($this->data['external_ip'] ?? null) || empty($this->data['ip_country']))) {
            $geo = self::lookupIpGeo($newExternalIp);
        }

        $pdo = DB::conn();
        $lastHandshake = $stats['last_handshake'] > 0
            ? date('Y-m-d H:i:s', $stats['last_handshake'])
            : null;

        if ($geo) {
            $stmt = $pdo->prepare('
                UPDATE vpn_clients
                SET bytes_sent = ?, bytes_received = ?, last_handshake = ?, external_ip = ?,
                    ip_country = ?, ip_country_code = ?, ip_city = ?, ip_isp = ?, ip_org = ?,
                    ip_lat = ?, ip_lon = ?,
                    last_sync_at = NOW()
                WHERE id = ?
            ');
            return $stmt->execute([
                $stats['bytes_sent'], $stats['bytes_received'], $lastHandshake, $newExternalIp,
                $geo['country'], $geo['countryCode'], $geo['city'], $geo['isp'], $geo['org'],
                $geo['lat'] ?? null, $geo['lon'] ?? null,
                $this->clientId
            ]);
        } else {
            $stmt = $pdo->prepare('
                UPDATE vpn_clients
                SET bytes_sent = ?, bytes_received = ?, last_handshake = ?, external_ip = ?, last_sync_at = NOW()
                WHERE id = ?
            ');
            return $stmt->execute([
                $stats['bytes_sent'], $stats['bytes_received'], $lastHandshake,
                $newExternalIp, $this->clientId
            ]);
        }
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
            if (empty(trim($line))) continue;
            
            $parts = preg_split('/\t+/', trim($line));
            
            // Peer lines have the interface prefix + 8 peer fields = at least 9 columns
            // Format: iface  publicKey  presharedKey  endpoint  allowedIPs  latestHandshake  transferRx  transferTx  persistentKeepalive
            if (count($parts) >= 8) {
                // Find the public key (usually the first or second column)
                $keyIndex = (strpos($parts[0], 'wg') === 0 && strlen($parts[0]) <= 5) ? 1 : 0;
                $publicKey = $parts[$keyIndex];

                // Find handshake and traffic columns dynamically
                $handshakeIndex = -1;
                foreach ($parts as $i => $val) {
                    $v = (int)$val;
                    if ($v > 1600000000 && $v < 2000000000) {
                        $handshakeIndex = $i;
                        break;
                    }
                }

                if ($handshakeIndex !== -1 && isset($parts[$handshakeIndex + 2])) {
                    $handshake = (int)$parts[$handshakeIndex];
                    $bytesRx = (int)$parts[$handshakeIndex + 1];
                    $bytesTx = (int)$parts[$handshakeIndex + 2];
                    
                    // Endpoint is usually before handshake, let's try to find it
                    // In standard wg it's index 3 (or 4 with iface)
                    // In AWG it might be different, but let's assume it's before handshake
                    $endpoint = $parts[$handshakeIndex - 2] ?? '(none)';
                    
                    // Extract just the IP from the endpoint (strip port)
                    $externalIp = null;
                    if ($endpoint && $endpoint !== '(none)' && strpos($endpoint, ':') !== false) {
                        if (preg_match('/^(.+):(\d+)$/', $endpoint, $m)) {
                            $externalIp = $m[1];
                        } else {
                            $externalIp = $endpoint;
                        }
                    }
                    
                    $peers[$publicKey] = [
                        'bytes_sent' => $bytesRx,       // client sent (server received)
                        'bytes_received' => $bytesTx,   // client received (server sent)
                        'last_handshake' => $handshake,
                        'endpoint' => $externalIp,
                    ];
                }
            }
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

        // Get all active clients from DB (include current geo data to check for IP changes)
        $stmt = $pdo->prepare('SELECT id, public_key, external_ip, ip_country, ip_country_code, ip_city, ip_isp, ip_org, ip_lat, ip_lon FROM vpn_clients WHERE server_id = ? AND status = ?');
        $stmt->execute([$serverId, ClientStatus::ACTIVE->value]);
        $clients = $stmt->fetchAll();

        if (empty($clients)) {
            return 0;
        }

        // Single SSH call to get all peer stats
        $allStats = self::getAllPeerStatsFromServer($serverData);

        $synced = 0;
        $updateStmt = $pdo->prepare('
            UPDATE vpn_clients
            SET bytes_sent = ?, bytes_received = ?, last_handshake = ?, external_ip = ?,
                ip_country = ?, ip_country_code = ?, ip_city = ?, ip_isp = ?, ip_org = ?,
                ip_lat = ?, ip_lon = ?,
                last_sync_at = NOW()
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
                $lastHandshake = $stats['last_handshake'] > 0
                    ? date('Y-m-d H:i:s', $stats['last_handshake'])
                    : null;

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
                    $stats['bytes_sent'],
                    $stats['bytes_received'],
                    $lastHandshake,
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
            'is_online' => !empty($this->data['last_handshake']) && (time() - strtotime($this->data['last_handshake'])) < 300
        ];
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
        // We use fields parameter to get only what we need
        // fields=61439 is a generated numeric value for status,message,country,countryCode,region,regionName,city,zip,lat,lon,timezone,isp,org,as,query
        // But for clarity we'll use names
        $fields = 'status,message,country,countryCode,city,isp,org,lat,lon,query';
        $url = "http://ip-api.com/json/{$ip}?fields={$fields}";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, 'NK-Panel/1.0');
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode !== 200 || !$response) {
            return null;
        }
        
        $data = json_decode($response, true);
        if (($data['status'] ?? '') === 'success') {
            return $data;
        }
        
        return null;
    }
}
