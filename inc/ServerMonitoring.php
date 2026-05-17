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
        if (!empty($this->serverData['telemetry_token'])) {
            // Server has push telemetry enabled. Avoid SSH polling to prevent DB locking,
            // baseline corruption, and redundant CPU overhead.
            return [
                'results' => [],
                'peer_stats' => [],
                'db_client_count' => 0,
                'active_peer_count' => 0,
                'using_push_telemetry' => true
            ];
        }

        $clients = VpnClient::listByServer($this->serverData['id']);
        if (empty($clients)) {
            return ['results' => [], 'peer_stats' => []];
        }

        // Single SSH call to get ALL peer stats at once
        $containerName = $this->serverData['container_name'];
        // Added -i flag for more consistent output streaming across different SSH/Docker environments
        $cmd = "docker exec -i {$containerName} /usr/local/bin/awg show all dump";
        $dumpOutput = $this->execSSH($cmd);

        if (!$dumpOutput || trim($dumpOutput) === "") {
            return ['results' => [], 'peer_stats' => []];
        }

        // Parse the full dump into a map of publicKey => stats
        $peerStats = $this->parseDump($dumpOutput);
        
        // Safety fallback: if 'all' failed or returned nothing but we expect clients, 
        // try specifically for wg0 which is the most common interface name
        if (empty($peerStats)) {
            $cmd = "docker exec -i {$containerName} /usr/local/bin/awg show wg0 dump";
            $dumpOutput = $this->execSSH($cmd);
            if ($dumpOutput) {
                $peerStats = $this->parseDump($dumpOutput);
            }
        }

        $db = DB::conn();
        $results = [];

        // Begin transaction for all database writes
        $db->beginTransaction();

        try {
            foreach ($clients as $client) {
                $status = $client['status'];
                if ($status instanceof ClientStatus) {
                    $status = $status->value;
                }
                
                if ($status !== ClientStatus::ACTIVE->value) continue;

                $publicKey = $client['public_key'];
                if (!isset($peerStats[$publicKey])) continue;

                $peer = $peerStats[$publicKey];
                
                // Always sync traffic totals and handshake, even if offline
                $stats = $this->calculateSpeed($client, $peer);

                if ($stats) {
                    $this->saveClientMetrics($client['id'], $stats);
                    
                    // Only add to real-time results if actually active (handshake < 3 mins)
                    $handshake = $peer['bytes_received'] > 0 ? ($peer['last_handshake'] ?? 0) : 0;
                    if ($handshake > 0 && abs(time() - $handshake) < 180) {
                        $results[] = [
                            'client_id' => $client['id'],
                            'client_name' => $client['name'],
                            'speed_up_kbps' => $stats['speed_up_kbps'],
                            'speed_down_kbps' => $stats['speed_down_kbps'],
                        ];
                    }
                }
            }

            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return [
            'results' => $results,
            'peer_stats' => $peerStats,
            'db_client_count' => count($clients),
            'active_peer_count' => count($results)
        ];
    }

    /**
     * Parse `awg show all dump` output into a map of publicKey => peer data.
     * 
     * Dump format per line (tab-separated):
     * Server line:  [iface] privateKey  publicKey  listenPort  fwmark
     * Peer line:    [iface] publicKey  presharedKey  endpoint  allowedIPs  latestHandshake  transferRx  transferTx  persistentKeepalive
     */
    private function parseDump(string $output): array
    {
        $peers = [];
        $lines = explode("\n", trim($output));

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Split by any whitespace (tabs or spaces) to be more resilient
            $parts = preg_split('/\s+/', $line);
            $count = count($parts);
            
            // AmneziaWG adds many columns for obfuscation. Standard WG is 8-9.
            // We search for the Public Key (44 chars ending in =) to identify the peer.
            $isKey0 = (strlen($parts[0]) === 44 && str_ends_with($parts[0], '='));
            $isKey1 = (isset($parts[1]) && strlen($parts[1]) === 44 && str_ends_with($parts[1], '='));

            if ($isKey0) {
                // Peer line, no interface prefix
                $offset = 0;
            } elseif ($isKey1) {
                // Peer line, with interface prefix
                $offset = 1;
            } else {
                continue; // Interface line or unrecognized format
            }

            // Standard WireGuard indices relative to the key:
            // 0: publicKey
            // 1: presharedKey
            // 2: endpoint
            // 3: allowedIPs
            // 4: latestHandshake
            // 5: transferRx
            // 6: transferTx
            // 7: persistentKeepalive
            
            if ($count < (5 + $offset)) continue; // Not enough data for stats

            $publicKey = $parts[0 + $offset];
            $peers[$publicKey] = [
                'preshared_key' => $parts[1 + $offset] ?? '(none)',
                'endpoint'      => $parts[2 + $offset] ?? '(none)',
                'allowed_ips'   => $parts[3 + $offset] ?? '(none)',
                'last_handshake'=> (int)($parts[4 + $offset] ?? 0),
                'bytes_sent'    => (float)($parts[6 + $offset] ?? 0), // tx = client received
                'bytes_received'=> (float)($parts[5 + $offset] ?? 0), // rx = client sent
            ];
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
            'endpoint' => $peer['endpoint'],
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
            external_ip = CASE WHEN :ext1 != '(none)' AND :ext2 != '' THEN :ext3 ELSE external_ip END,
            last_sync_at = NOW()
        WHERE id = :id
    ");

    // Clean endpoint IP (strip port, handle IPv6)
    $endpoint = $stats['endpoint'] ?? '(none)';
    $externalIp = $endpoint;
    if ($endpoint !== '(none)' && !empty($endpoint)) {
        if (strpos($endpoint, ']:') !== false) { // IPv6 [addr]:port
            $externalIp = substr($endpoint, 1, strpos($endpoint, ']:') - 1);
        } elseif (strpos($endpoint, ':') !== false) { // IPv4 addr:port
            $externalIp = explode(':', $endpoint)[0];
        }
    }

    $stmt->execute([
        'bs'   => $stats['diff_sent'],
        'br'   => $stats['diff_received'],
        'sup'  => (float)$stats['speed_up_kbps'],
        'sdn'  => (float)$stats['speed_down_kbps'],
        'lh'   => $lastHandshake,
        'ext1' => $externalIp,
        'ext2' => $externalIp,
        'ext3' => $externalIp,
        'id'   => $clientId
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
     * Get aggregate traffic history for all clients (used for dashboard chart)
     */
    public static function getGlobalTrafficHistory(int $minutes = 30): array
    {
        $db = DB::conn();
        
        // Group by 30-second or 1-minute buckets to aggregate staggered collection times
        $stmt = $db->prepare("
            SELECT 
                DATE_FORMAT(MIN(collected_at), '%H:%i:%s') as label,
                SUM(COALESCE(speed_up_kbps, 0)) / 1024 as speed_up_mb,
                SUM(COALESCE(speed_down_kbps, 0)) / 1024 as speed_down_mb
            FROM client_metrics
            WHERE collected_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
            GROUP BY FLOOR(UNIX_TIMESTAMP(collected_at) / 30)
            ORDER BY MIN(collected_at) ASC
        ");
        
        $stmt->execute([(int)$minutes]);
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
     * Execute SSH command on server via VpnServer delegation.
     */
    private function execSSH(string $cmd): ?string
    {
        try {
            // Use silent=true for monitoring tasks to avoid log spam
            return $this->server->executeCommand($cmd, true, false, true);
        } catch (Exception $e) {
            \Logger::error('ServerMonitoring::execSSH failed: ' . $e->getMessage());
            return null;
        }
    }
}

