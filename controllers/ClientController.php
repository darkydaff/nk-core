<?php
declare(strict_types=1);


class ClientController {
    private function respond($success, $message, $data = null, $redirect = null) {
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') || 
                  (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => $success,
                'message' => $message,
                'data' => $success ? $data : null,
                'error' => !$success ? $data : null,
                'redirect' => $redirect
            ]);
            exit;
        }

        if ($redirect) {
            header('Location: ' . $redirect . '?success=' . urlencode($message));
            exit;
        }

        if ($success) {
            $_SESSION['success_message'] = $message;
        } else {
            $_SESSION['error_message'] = (is_string($data) ? $data : $message);
        }
        
        $back = $_SERVER['HTTP_REFERER'] ?? '/servers';
        header('Location: ' . $back);
        exit;
    }
    public function create($params) {
        requireAuth();
        $serverId = (int)$params['id'];
        $clientName = trim($_POST['name'] ?? '');
        
        $expiresInDays = null;
        if (!empty($_POST['expires_in_seconds'])) {
            $expiresInDays = (int)ceil((int)$_POST['expires_in_seconds'] / 86400);
        } elseif (!empty($_POST['expires_in_days']) && $_POST['expires_in_days'] !== 'custom') {
            $expiresInDays = (int)$_POST['expires_in_days'];
        }
        
        $trafficLimitBytes = null;
        if (!empty($_POST['traffic_limit_mb'])) {
            $trafficLimitBytes = (int)((float)$_POST['traffic_limit_mb'] * 1048576);
        } elseif (!empty($_POST['traffic_limit_gb']) && $_POST['traffic_limit_gb'] !== 'custom') {
            $trafficLimitBytes = (int)((float)$_POST['traffic_limit_gb'] * 1073741824);
        }
        
        if (empty($clientName)) {
            return $this->respond(false, "Client name is required");
        }
        
        unlockSession();
        
        try {
            $server = new VpnServer($serverId);
            $serverData = $server->getData();
            
            $user = Auth::user();
            if ($serverData['user_id'] != $user['id'] && !Auth::isAdmin()) {
                http_response_code(403);
                echo Translator::t('message.forbidden');
                return;
            }
            
            $clientId = VpnClient::create($serverId, $user['id'], $clientName, $expiresInDays);
            
            if ($trafficLimitBytes !== null && $trafficLimitBytes > 0) {
                $client = new VpnClient($clientId);
                $client->setTrafficLimit($trafficLimitBytes);
            }
            
            return $this->respond(true, "Client created successfully", null, '/clients/' . $clientId);
        } catch (Exception $e) {
            return $this->respond(false, "Creation failed", $e->getMessage());
        }
    }

    public function view($params) {
        requireAuth();
        $clientId = (int)$params['id'];
        
        try {
            $client = new VpnClient($clientId);
            $clientData = $client->getData();
            
            $user = Auth::user();
            if ($clientData['user_id'] != $user['id'] && !Auth::isAdmin()) {
                http_response_code(403);
                echo 'Forbidden';
                return;
            }
            $stats = $client->getFormattedStats();
            
            View::render('clients/view.twig', [
                'client' => $clientData,
                'stats' => $stats
            ]);
        } catch (Exception $e) {
            http_response_code(404);
            echo Translator::t('clients.not_found');
        }
    }

    public function update($params) {
        requireAuth();
        $clientId = (int)$params['id'];
        
        unlockSession();
        
        try {
            $client = new VpnClient($clientId);
            $clientData = $client->getData();
            
            $user = Auth::user();
            if ($clientData['user_id'] != $user['id'] && !Auth::isAdmin()) {
                http_response_code(403);
                die('Forbidden');
            }

            $pdo = DB::conn();

            if (isset($_POST['name']) && trim($_POST['name']) !== '') {
                $stmt = $pdo->prepare('UPDATE vpn_clients SET name = ? WHERE id = ?');
                $stmt->execute([trim($_POST['name']), $clientId]);
            }
            
            if (!empty($_POST['add_days'])) {
                if ($_POST['add_days'] === 'remove') {
                    VpnClient::setExpiration($clientId, null);
                } else {
                    $days = $_POST['add_days'] === 'custom' ? max(1, (int)($_POST['custom_seconds'] / 86400)) : (int)$_POST['add_days'];
                    VpnClient::extendExpiration($clientId, $days);
                }
            }
            
            if (!empty($_POST['new_limit_gb'])) {
                if ($_POST['new_limit_gb'] === 'remove') {
                    $client->setTrafficLimit(null);
                } else {
                    $mb = $_POST['new_limit_gb'] === 'custom' ? (int)$_POST['custom_mb'] : (int)$_POST['new_limit_gb'] * 1024;
                    if ($mb > 0) {
                        $client->setTrafficLimit($mb * 1024 * 1024);
                    }
                }
            }

            return $this->respond(true, "Client updated successfully");
        } catch (Exception $e) {
            return $this->respond(false, "Update failed", $e->getMessage());
        }
    }

    public function status($params) {
        requireAuth();
        $clientId = (int)$params['id'];
        try {
            $client = new VpnClient($clientId);
            $clientData = $client->getData();
            
            return $this->respond(true, "Status fetched", [
                'db_status' => $clientData['status'],
                'connection_status' => $clientData['connection_status'] ?? 'offline',
                'is_active' => $clientData['status'] === ClientStatus::ACTIVE->value,
                'traffic' => [
                    'sent' => number_format($clientData['bytes_sent'] / 1024 / 1024, 2),
                    'received' => number_format($clientData['bytes_received'] / 1024 / 1024, 2),
                    'speed_up' => $clientData['speed_up'] ?? '0 B/s',
                    'speed_down' => $clientData['speed_down'] ?? '0 B/s'
                ]
            ]);
        } catch (Exception $e) {
            return $this->respond(false, $e->getMessage());
        }
    }

    public function downloadConfig($params) {
        requireAuth();
        $clientId = (int)$params['id'];
        
        try {
            $client = new VpnClient($clientId);
            $clientData = $client->getData();
            
            $user = Auth::user();
            if ($clientData['user_id'] != $user['id'] && !Auth::isAdmin()) {
                http_response_code(403);
                echo 'Forbidden';
                return;
            }
            
            $config = $client->getConfig();
            
            $hasNonLatin = preg_match('/[^a-zA-Z0-9_-]/', $clientData['name']);
            if ($hasNonLatin) {
                $filename = 'user_' . $clientData['id'] . '_s' . $clientData['server_id'] . '.conf';
            } else {
                $filename = $clientData['name'] . '.conf';
            }
            
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($config));
            echo $config;
        } catch (Exception $e) {
            http_response_code(404);
            echo 'Client not found';
        }
    }

    public function revoke($params) {
        requireAuth();
        $clientId = (int)$params['id'];
        
        unlockSession();
        
        try {
            $client = new VpnClient($clientId);
            $clientData = $client->getData();
            
            $user = Auth::user();
            if ($clientData['user_id'] != $user['id'] && !Auth::isAdmin()) {
                http_response_code(403);
                if (isJsonRequest()) echo json_encode(['error' => 'Forbidden']);
                else echo 'Forbidden';
                return;
            }
            
            if ($client->revoke()) {
                return $this->respond(true, "Client revoked successfully");
            } else {
                return $this->respond(false, "Failed to revoke client");
            }
        } catch (Exception $e) {
            return $this->respond(false, "Revoke failed", $e->getMessage());
        }
    }

    public function restore($params) {
        requireAuth();
        $clientId = (int)$params['id'];
        
        unlockSession();
        
        try {
            $client = new VpnClient($clientId);
            $clientData = $client->getData();
            
            $user = Auth::user();
            if ($clientData['user_id'] != $user['id'] && !Auth::isAdmin()) {
                http_response_code(403);
                if (isJsonRequest()) echo json_encode(['error' => 'Forbidden']);
                else echo 'Forbidden';
                return;
            }
            
            if ($client->restore()) {
                return $this->respond(true, "Client restored successfully");
            } else {
                return $this->respond(false, "Failed to restore client");
            }
        } catch (Exception $e) {
            return $this->respond(false, "Restore failed", $e->getMessage());
        }
    }

    public function delete($params) {
        requireAuth();
        $clientId = (int)$params['id'];
        
        unlockSession();
        
        try {
            $client = new VpnClient($clientId);
            $clientData = $client->getData();
            
            $user = Auth::user();
            if ($clientData['user_id'] != $user['id'] && !Auth::isAdmin()) {
                http_response_code(403);
                if (isJsonRequest()) echo json_encode(['error' => 'Forbidden']);
                else echo 'Forbidden';
                return;
            }
            
            $serverId = $clientData['server_id'];
            
            if ($client->delete()) {
                return $this->respond(true, "Client deleted successfully", null, '/servers/' . $serverId);
            } else {
                return $this->respond(false, "Failed to delete client");
            }
        } catch (Exception $e) {
            return $this->respond(false, "Delete failed", $e->getMessage());
        }
    }

    public function batchAction() {
        requireAuth();
        $ids = $_POST['ids'] ?? [];
        $action = $_POST['action'] ?? '';
        
        if (empty($ids) || !is_array($ids)) {
            return $this->respond(false, "No clients selected");
        }
        
        $successCount = 0;
        $errorCount = 0;
        $user = Auth::user();
        
        foreach ($ids as $id) {
            try {
                require_once __DIR__ . '/../inc/VpnClient.php';
                $client = new VpnClient((int)$id);
                $data = $client->getData();
                
                if ($data['user_id'] != $user['id'] && !Auth::isAdmin()) continue;
                
                switch ($action) {
                    case 'delete':
                        $client->delete();
                        $successCount++;
                        break;
                    case 'revoke':
                        $client->revoke();
                        $successCount++;
                        break;
                    case 'restore':
                        $client->restore();
                        $successCount++;
                        break;
                }
            } catch (Exception $e) {
                $errorCount++;
            }
        }
        
        return $this->respond(true, "Successfully processed $successCount clients" . ($errorCount > 0 ? " ($errorCount errors)" : ""));
    }

    public function syncStats($params) {
        requireAuth();
        $clientId = (int)$params['id'];
        
        header('Content-Type: application/json');
        
        unlockSession();
        
        try {
            $client = new VpnClient($clientId);
            $clientData = $client->getData();
            
            $user = Auth::user();
            if ($clientData['user_id'] != $user['id'] && !Auth::isAdmin()) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                return;
            }
            
            // syncStats() throws on any failure, so if we get here it succeeded
            $client->syncStats();
            $client = new VpnClient($clientId);
            $stats = $client->getFormattedStats();
            echo json_encode(['success' => true, 'stats' => $stats]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}
