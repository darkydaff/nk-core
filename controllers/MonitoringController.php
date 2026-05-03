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
            require_once __DIR__ . '/../inc/BeszelClient.php';
            $beszel = new BeszelClient();
            $data = $beszel->getSystemByIp($ip);
            
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
