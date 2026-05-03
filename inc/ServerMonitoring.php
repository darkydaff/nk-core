<?php
declare(strict_types=1);


/**
 * ServerMonitoring - Collect and store server metrics
 * 
 * Collects:
 * - Client traffic speed (batched: single SSH call per server)
 */
class ServerMonitoring
{
    private VpnServer $server;
    private array $serverData;
    
    public function __construct(int $serverId)
    {
        $this->server = new VpnServer($serverId);
        $this->serverData = $this->server->getData();
    }
    
    /**
     * Collect client traffic metrics using a single batched SSH call.
     * Returns per-client speed results.
     */
    public function collectClientMetrics(): array
    {
        $clients = VpnClient::listByServer($this->serverData['id']);
        if (empty($clients)) {
            return [];
        }

        // Single SSH call to get ALL peer stats at once
        $containerName = $this->serverData['container_name'];
        $cmd = "docker exec {$containerName} /usr/local/bin/awg show all dump";
        $dumpOutput = $this->execSSH($cmd);

        if (!$dumpOutput) {
            return [];
        }

        // Parse the full dump into a map of publicKey => stats
        $peerStats = $this->parseDump($dumpOutput);

        $db = DB::conn();
        $results = [];

        // Begin transaction for all database writes
        $db->beginTransaction();

        try {
            foreach ($clients as $client) {
                if ($client['status'] !== ClientStatus::ACTIVE->value) continue;

                $publicKey = $client['public_key'];
                if (!isset($peerStats[$publicKey])) continue;

                $peer = $peerStats[$publicKey];
                $stats = $this->calculateSpeed($client, $peer);

                if ($stats) {
                    $this->saveClientMetrics($client['id'], $stats);
                    $results[] = [
                        'client_id' => $client['id'],
                        'client_name' => $client['name'],
                        'speed_up_kbps' => $stats['speed_up_kbps'],
                        'speed_down_kbps' => $stats['speed_down_kbps'],
                    ];
                }
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }

        return $results;
    }

    /**
     * Parse `awg show all dump` output into a map of publicKey => peer data.
     * 
     * Dump format per line (tab-separated):
     * Server line:  privateKey  publicKey  listenPort  fwmark
     * Peer line:    publicKey  presharedKey  endpoint  allowedIPs  latestHandshake  transferRx  transferTx  persistentKeepalive
     */
    private function parseDump(string $output): array
    {
        $peers = [];
        $lines = explode("\n", trim($output));

        foreach ($lines as $line) {
            if (empty(trim($line))) continue;

            $parts = preg_split('/\t+/', trim($line));
            if (count($parts) < 8) continue;

            // Find the public key (usually the first or second column)
            // If the first column is an interface name like 'wg0', the key is the second.
            $keyIndex = (strpos($parts[0], 'wg') === 0 && strlen($parts[0]) <= 5) ? 1 : 0;
            $publicKey = $parts[$keyIndex];

            // DYNAMICALLY find the traffic columns:
            // Latest handshake is always a large timestamp (e.g. 1714746033)
            // Transfer RX/TX are the two columns immediately following the handshake.
            $handshakeIndex = -1;
            foreach ($parts as $i => $val) {
                $val = (int)$val;
                if ($val > 1600000000 && $val < 2000000000) { // Valid timestamp range
                    $handshakeIndex = $i;
                    break;
                }
            }

            if ($handshakeIndex !== -1 && isset($parts[$handshakeIndex + 2])) {
                $peers[$publicKey] = [
                    'last_handshake' => (int)$parts[$handshakeIndex],
                    'bytes_sent'     => (int)$parts[$handshakeIndex + 1], // RX (Client Upload)
                    'bytes_received' => (int)$parts[$handshakeIndex + 2], // TX (Client Download)
                ];
            }
        }

        return $peers;
    }

    /**
     * Calculate upload/download speed from previous metrics.
     */
    private function calculateSpeed(array $client, array $peer): ?array
    {
        $db = DB::conn();

        // Get previous metrics
        $stmt = $db->prepare("
            SELECT bytes_sent, bytes_received, UNIX_TIMESTAMP(collected_at) as collected_ts
            FROM client_metrics
            WHERE client_id = ?
            ORDER BY collected_at DESC
            LIMIT 1
        ");
        $stmt->execute([$client['id']]);
        $previous = $stmt->fetch(PDO::FETCH_ASSOC);

        $bytesDiffSent = 0;
        $bytesDiffReceived = 0;
        $speedUp = 0;
        $speedDown = 0;

        if ($previous && !empty($previous['collected_ts'])) {
            // Compare pure UNIX timestamps to bypass any PHP/MySQL timezone mismatches
            $now = time();
            $prevTime = (int)$previous['collected_ts'];
            $timeDiff = $now - $prevTime;
            
            if ($timeDiff > 0 && $timeDiff < 3600) { // Safety: ignore gaps > 1 hour
                // Detect Server/Interface Restarts (counters reset to 0)
                if ($peer['bytes_sent'] < (int)$previous['bytes_sent'] || $peer['bytes_received'] < (int)$previous['bytes_received']) {
                    $bytesDiffSent = $peer['bytes_sent'];
                    $bytesDiffReceived = $peer['bytes_received'];
                } else {
                    $bytesDiffSent = $peer['bytes_sent'] - (int)$previous['bytes_sent'];
                    $bytesDiffReceived = $peer['bytes_received'] - (int)$previous['bytes_received'];
                }

                if ($bytesDiffSent < 0) $bytesDiffSent = 0;
                if ($bytesDiffReceived < 0) $bytesDiffReceived = 0;

                $speedUp = round(($bytesDiffSent * 8) / $timeDiff / 1000, 2);
                $speedDown = round(($bytesDiffReceived * 8) / $timeDiff / 1000, 2);
            }
        }

        return [
            'bytes_sent' => $peer['bytes_sent'], // Raw value for client_metrics
            'bytes_received' => $peer['bytes_received'],
            'diff_sent' => $bytesDiffSent, // Delta for vpn_clients totals
            'diff_received' => $bytesDiffReceived,
            'speed_up_kbps' => $speedUp,
            'speed_down_kbps' => $speedDown,
            'last_handshake' => $peer['last_handshake'],
        ];
    }
    
    /**
     * Save client metrics to database
     */
    private function saveClientMetrics(int $clientId, array $stats): void
    {
        $db = DB::conn();
        
        $stmt = $db->prepare("
            INSERT INTO client_metrics 
            (client_id, bytes_sent, bytes_received, speed_up_kbps, speed_down_kbps)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $clientId,
            $stats['bytes_sent'],
            $stats['bytes_received'],
            $stats['speed_up_kbps'],
            $stats['speed_down_kbps'],
        ]);
        
        // Update vpn_clients table with cumulative delta and handshake
        $lastHandshake = null;
        if (!empty($stats['last_handshake']) && $stats['last_handshake'] > 0) {
            $lastHandshake = date('Y-m-d H:i:s', $stats['last_handshake']);
        }

        $stmt = $db->prepare("
            UPDATE vpn_clients 
            SET bytes_sent = bytes_sent + :bs, 
                bytes_received = bytes_received + :br, 
                speed_up_kbps = :sup,
                speed_down_kbps = :sdn,
                last_handshake = :lh, 
                last_sync_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            'bs'  => $stats['diff_sent'],
            'br'  => $stats['diff_received'],
            'sup' => (float)$stats['speed_up_kbps'],
            'sdn' => (float)$stats['speed_down_kbps'],
            'lh'  => $lastHandshake,
            'id'  => $clientId
        ]);
    }
    
    /**
     * Get server metrics for last 24 hours
     */
    public static function getServerMetrics(int $serverId, int $hours = 24): array
    {
        $db = DB::conn();
        
        $stmt = $db->prepare("
            SELECT *
            FROM server_metrics
            WHERE server_id = ?
            AND collected_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            ORDER BY collected_at ASC
        ");
        
        $stmt->execute([$serverId, $hours]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get client metrics for last 24 hours
     */
    public static function getClientMetrics(int $clientId, int $hours = 24): array
    {
        $db = DB::conn();
        
        $stmt = $db->prepare("
            SELECT *
            FROM client_metrics
            WHERE client_id = ?
            AND collected_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            ORDER BY collected_at ASC
        ");
        
        $stmt->execute([$clientId, $hours]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Clean old metrics (older than 24 hours)
     */
    public static function cleanOldMetrics(): void
    {
        $db = DB::conn();
        
        // Clean server metrics
        $db->exec("DELETE FROM server_metrics WHERE collected_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        
        // Clean client metrics
        $db->exec("DELETE FROM client_metrics WHERE collected_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    }
    
    /**
     * Execute SSH command on server.
     * Tries to reuse an existing ControlMaster socket, falls back to sshpass.
     */
    private function execSSH(string $cmd): ?string
    {
        $host = $this->serverData['host'];
        $port = $this->serverData['port'];
        $username = $this->serverData['username'];
        $password = $this->serverData['password'];

        // Check for an existing ControlMaster socket
        $muxDir = '/tmp/ssh_mux';
        $muxSocket = $muxDir . '/nk_' . md5($host . ':' . $port) . '_' . getmypid();

        $commonOpts = sprintf(
            '-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR -q -p %d',
            $port
        );

        if (file_exists($muxSocket)) {
            $sshCmd = sprintf(
                'ssh -o ControlPath=%s %s %s@%s %s 2>/dev/null',
                escapeshellarg($muxSocket),
                $commonOpts,
                escapeshellarg($username),
                escapeshellarg($host),
                escapeshellarg($cmd)
            );
        } elseif (!empty($this->serverData['ssh_private_key'])) {
            $keyPath = tempnam(sys_get_temp_dir(), 'nk_ssh_');
            file_put_contents($keyPath, $this->serverData['ssh_private_key']);
            chmod($keyPath, 0600);
            $sshCmd = sprintf(
                'ssh -i %s -o PubkeyAuthentication=yes %s %s@%s %s 2>/dev/null',
                escapeshellarg($keyPath),
                $commonOpts,
                escapeshellarg($username),
                escapeshellarg($host),
                escapeshellarg($cmd)
            );
        } else {
            $sshCmd = sprintf(
                'SSHPASS=%s sshpass -e ssh -o PreferredAuthentications=password -o PubkeyAuthentication=no %s %s@%s %s 2>/dev/null',
                escapeshellarg($password),
                $commonOpts,
                escapeshellarg($username),
                escapeshellarg($host),
                escapeshellarg($cmd)
            );
        }
        
        $output = shell_exec($sshCmd);

        // Clean up temp key file
        if (isset($keyPath) && file_exists($keyPath)) {
            unlink($keyPath);
        }
        
        return $output ?: null;
    }
}

