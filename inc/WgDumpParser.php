<?php
declare(strict_types=1);

/**
 * WgDumpParser - Parses `awg show all dump` output into structured peer data.
 * 
 * Extracted from VpnClient and ServerMonitoring to eliminate duplication.
 * Single source of truth for WireGuard/AmneziaWG dump parsing.
 */
class WgDumpParser
{
    /**
     * Parse `awg show [all|wg0] dump` output into a map of publicKey => peer data.
     * 
     * Dump format per line (tab-separated):
     * Server line:  [iface] privateKey  publicKey  listenPort  fwmark
     * Peer line:    [iface] publicKey  presharedKey  endpoint  allowedIPs  latestHandshake  transferRx  transferTx  persistentKeepalive
     * 
     * @return array<string, array{preshared_key: string, endpoint: string, allowed_ips: string, last_handshake: int, bytes_sent: float, bytes_received: float}>
     */
    public static function parse(string $output): array
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
     * Clean endpoint string to extract just the IP address.
     * Handles IPv4 (addr:port), IPv6 ([addr]:port), and (none).
     */
    public static function cleanEndpoint(string $endpoint): string
    {
        if ($endpoint === '(none)' || empty($endpoint)) {
            return $endpoint;
        }
        
        if (strpos($endpoint, ']:') !== false) {
            // IPv6 [addr]:port
            return substr($endpoint, 1, strpos($endpoint, ']:') - 1);
        }
        
        if (strpos($endpoint, ':') !== false) {
            // IPv4 addr:port
            return explode(':', $endpoint)[0];
        }
        
        return $endpoint;
    }
}
