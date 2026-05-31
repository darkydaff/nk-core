<?php
declare(strict_types=1);


class MapController
{
    /**
     * Render the Map page
     */
    public function index()
    {
        requireAuth();
        View::render('map.twig');
    }

    /**
     * JSON API: return all clients with geo coordinates
     * Scoped to the current user (admins see all)
     */
    public function clientsGeo()
    {
        requireAuth();
        header('Content-Type: application/json');

        $user = Auth::user();
        $pdo  = DB::conn();

        $where = Auth::isAdmin()
            ? '1=1'
            : 'c.user_id = ' . (int) $user['id'];

        $sql = "
            SELECT
                c.id,
                c.name,
                c.status,
                c.external_ip,
                c.ip_country,
                c.ip_country_code,
                c.ip_city,
                c.ip_isp,
                c.ip_lat,
                c.ip_lon,
                c.bytes_sent,
                c.bytes_received,
                c.last_handshake,
                c.server_id,
                s.name AS server_name,
                CASE
                    WHEN c.status = '" . ClientStatus::DISABLED->value . "' THEN 'revoked'
                    WHEN c.last_handshake > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                         AND c.status = '" . ClientStatus::ACTIVE->value . "' THEN 'online'
                    ELSE 'offline'
                END AS computed_status
            FROM vpn_clients c
            LEFT JOIN vpn_servers s ON c.server_id = s.id
            WHERE ($where)
              AND c.deleted_at IS NULL
              AND c.ip_lat IS NOT NULL
              AND c.ip_lon IS NOT NULL
            ORDER BY computed_status ASC, c.name ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $clients = array_map(function ($row) {
            $totalBytes = (int) $row['bytes_sent'] + (int) $row['bytes_received'];
            $trafficMb  = round($totalBytes / 1048576, 2);

            // Human-readable last seen
            $lastSeen = 'Never';
            if ($row['last_handshake']) {
                $diff = time() - strtotime($row['last_handshake']);
                if ($diff < 60)         $lastSeen = $diff . 's ago';
                elseif ($diff < 3600)   $lastSeen = round($diff / 60) . 'm ago';
                elseif ($diff < 86400)  $lastSeen = round($diff / 3600) . 'h ago';
                else                     $lastSeen = round($diff / 86400) . 'd ago';
            }

            return [
                'id'           => (int) $row['id'],
                'name'         => $row['name'],
                'status'       => $row['computed_status'],
                'lat'          => (float) $row['ip_lat'],
                'lon'          => (float) $row['ip_lon'],
                'city'         => $row['ip_city'],
                'country'      => $row['ip_country'],
                'country_code' => $row['ip_country_code'],
                'isp'          => $row['ip_isp'],
                'server_id'    => (int) $row['server_id'],
                'server_name'  => $row['server_name'],
                'traffic_mb'   => $trafficMb,
                'last_seen'    => $lastSeen,
            ];
        }, $rows);

        // Fetch all active servers with resolved coordinates
        $servers = [];
        try {
            $serverSql = "
                SELECT 
                    s.id, s.name, s.host, s.status, s.country, s.country_code, s.city, s.isp, s.org, s.lat, s.lon,
                    (SELECT COUNT(*) FROM vpn_clients c WHERE c.server_id = s.id AND c.deleted_at IS NULL) as client_count,
                    (SELECT COUNT(*) FROM vpn_clients c 
                     WHERE c.server_id = s.id 
                       AND c.deleted_at IS NULL 
                       AND c.status = '" . ClientStatus::ACTIVE->value . "'
                       AND c.last_handshake > DATE_SUB(NOW(), INTERVAL 10 MINUTE)) as online_client_count
                FROM vpn_servers s
                WHERE s.deleted_at IS NULL
                  AND s.lat IS NOT NULL
                  AND s.lon IS NOT NULL
            ";
            $serverStmt = $pdo->prepare($serverSql);
            $serverStmt->execute();
            $serverRows = $serverStmt->fetchAll(PDO::FETCH_ASSOC);

            $servers = array_map(function ($row) {
                return [
                    'id'           => (int) $row['id'],
                    'name'         => $row['name'],
                    'host'         => $row['host'],
                    'status'       => $row['status'],
                    'lat'          => (float) $row['lat'],
                    'lon'          => (float) $row['lon'],
                    'city'         => $row['city'],
                    'country'      => $row['country'],
                    'country_code' => $row['country_code'],
                    'client_count' => (int) $row['client_count'],
                    'online_count' => (int) $row['online_client_count'],
                ];
            }, $serverRows);
        } catch (Throwable $e) {
            // Fallback for missing columns (migration 066 not run yet)
            $servers = [];
        }

        echo json_encode([
            'success' => true,
            'clients' => $clients,
            'servers' => $servers
        ]);
    }
}
