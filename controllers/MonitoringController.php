<?php
/**
 * Monitoring Controller
 * Handles server health stats from external sources like Beszel
 */
class MonitoringController {
    /**
     * Get system data from Beszel for a given IP
     */
    public function getSystemData($args) {
        $ip = $args['ip'] ?? '';
        // Require authentication for API
        requireAuth();
        
        header('Content-Type: application/json');
        
        try {
            // Fetch server name by host IP to allow domain/name matching in Beszel
            require_once __DIR__ . '/../inc/DB.php';
            $db = DB::conn();
            $stmt = $db->prepare("SELECT name FROM vpn_servers WHERE host = ? LIMIT 1");
            $stmt->execute([$ip]);
            $serverRow = $stmt->fetch(PDO::FETCH_ASSOC);
            $name = $serverRow ? $serverRow['name'] : '';

            require_once __DIR__ . '/../inc/BeszelClient.php';
            $beszel = new BeszelClient();
            $data = $beszel->getSystemByIp($ip, $name);
            
            if ($data) {
                // Handle info field if it's a string
                if (isset($data['info']) && is_string($data['info'])) {
                    $data['info'] = json_decode($data['info'], true);
                }
                
                echo json_encode([
                    'success' => true,
                    'data' => $data
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'No monitoring data found for this IP'
                ]);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
        exit;
    }
}
