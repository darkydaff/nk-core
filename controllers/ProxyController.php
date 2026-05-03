<?php
declare(strict_types=1);

/**
 * Proxy Controller
 * Handles HTTP Proxy management UI
 */
class ProxyController
{
    private function respond($success, $message, $error = null, $redirect = null) {
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') || 
                  (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => $success,
                'message' => $message,
                'error' => $error,
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
            $_SESSION['error_message'] = $error ?: $message;
        }
        
        $back = $_SERVER['HTTP_REFERER'] ?? '/proxies';
        header('Location: ' . $back);
        exit;
    }
    public function index()
    {
        requireAuth();
        $pdo = DB::conn();
        
        $proxies = $pdo->query('
            SELECT p.*, s.name as server_name, s.host as server_host 
            FROM http_proxies p 
            JOIN vpn_servers s ON p.server_id = s.id 
            WHERE p.deleted_at IS NULL
            ORDER BY p.created_at DESC
        ')->fetchAll();

        $servers = $pdo->prepare('SELECT id, name FROM vpn_servers WHERE status = ?');
        $servers->execute([ServerStatus::ACTIVE->value]);
        $servers = $servers->fetchAll();

        echo View::render('proxies.twig', [
            'proxies' => $proxies,
            'servers' => $servers,
            'title' => Translator::t('proxies.title')
        ]);
    }

    public function create()
    {
        requireAuth();
        $user = Auth::user();
        
        $serverId = (int)($_POST['server_id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $type = trim($_POST['type'] ?? 'http');
        if (!in_array($type, ['http', 'socks5'])) {
            $type = 'http';
        }

        if (!$serverId) {
            return $this->respond(false, "Server is required");
        }

        if (empty($username)) {
            $username = 'user_' . substr(md5(uniqid()), 0, 8);
        }
        if (empty($password)) {
            $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            $password = '';
            for ($i = 0; $i < 16; $i++) {
                $password .= $chars[rand(0, strlen($chars) - 1)];
            }
        }

        unlockSession();

        try {
            $proxyServer = new ProxyServer($serverId);
            $proxyServer->install();
            $port = $proxyServer->findFreePort();

            $pdo = DB::conn();
            $stmt = $pdo->prepare('
                INSERT INTO http_proxies (user_id, server_id, username, password, type, port, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$user['id'], $serverId, $username, $password, $type, $port, ServerStatus::ACTIVE->value]);
            $proxyId = $pdo->lastInsertId();
            
            try {
                $proxyServer->syncUsers();
                return $this->respond(true, "Proxy created successfully");
            } catch (Exception $e) {
                // Rollback DB record if server sync fails
                $pdo->prepare('DELETE FROM http_proxies WHERE id = ?')->execute([$proxyId]);
                throw $e;
            }
        } catch (Exception $e) {
            \Logger::error("Failed to create proxy: " . $e->getMessage());
            relockSession();
            return $this->respond(false, "Failed to create proxy", $e->getMessage());
        }
    }

    public function pause($params)
    {
        requireAuth();
        $id = (int)$params['id'];
        
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT * FROM http_proxies WHERE id = ?');
        $stmt->execute([$id]);
        $proxy = $stmt->fetch();

        if ($proxy) {
            $pdo->prepare('UPDATE http_proxies SET status = ? WHERE id = ?')->execute([ServerStatus::STOPPED->value, $id]);
            unlockSession();
            try {
                $proxyServer = new ProxyServer($proxy['server_id']);
                $proxyServer->syncUsers();
                relockSession();
                return $this->respond(true, "Proxy paused");
            } catch (Exception $e) {
                relockSession();
                return $this->respond(false, "Pause failed", $e->getMessage());
            }
        }
        return $this->respond(false, "Proxy not found");
    }

    public function resume($params)
    {
        requireAuth();
        $id = (int)$params['id'];
        
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT * FROM http_proxies WHERE id = ?');
        $stmt->execute([$id]);
        $proxy = $stmt->fetch();

        if ($proxy) {
            $pdo->prepare('UPDATE http_proxies SET status = ? WHERE id = ?')->execute([ServerStatus::ACTIVE->value, $id]);
            unlockSession();
            try {
                $proxyServer = new ProxyServer($proxy['server_id']);
                $proxyServer->syncUsers();
                relockSession();
                return $this->respond(true, "Proxy resumed");
            } catch (Exception $e) {
                relockSession();
                return $this->respond(false, "Resume failed", $e->getMessage());
            }
        }
        return $this->respond(false, "Proxy not found");
    }

    public function delete($params)
    {
        requireAuth();
        $id = (int)$params['id'];
        
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT * FROM http_proxies WHERE id = ?');
        $stmt->execute([$id]);
        $proxy = $stmt->fetch();

        if ($proxy) {
            $pdo->prepare('UPDATE http_proxies SET deleted_at = NOW(), status = ? WHERE id = ?')->execute([ServerStatus::ERROR->value, $id]); // Marking as error/deleted context
            unlockSession();
            try {
                $proxyServer = new ProxyServer($proxy['server_id']);
                $proxyServer->syncUsers();
                relockSession();
                return $this->respond(true, "Proxy deleted");
            } catch (Exception $e) {
                relockSession();
                return $this->respond(false, "Delete failed", $e->getMessage());
            }
        }
        return $this->respond(false, "Proxy not found");
    }
}
