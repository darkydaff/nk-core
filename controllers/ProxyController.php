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
                'redirect' => $redirect,
                'reload' => $success && !$redirect
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
        
        try {
            $proxies = $pdo->query('
                SELECT p.*, s.name as server_name, s.host as server_host, s.country_code as server_country_code 
                FROM http_proxies p 
                JOIN vpn_servers s ON p.server_id = s.id 
                WHERE p.deleted_at IS NULL
                ORDER BY p.created_at DESC
            ')->fetchAll();
        } catch (PDOException $e) {
            // Fallback for missing columns (migration 053 not run)
            $proxies = $pdo->query('
                SELECT p.*, s.name as server_name, s.host as server_host, NULL as server_country_code 
                FROM http_proxies p 
                JOIN vpn_servers s ON p.server_id = s.id 
                WHERE p.deleted_at IS NULL
                ORDER BY p.created_at DESC
            ')->fetchAll();
        }

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
                $password .= $chars[random_int(0, strlen($chars) - 1)];
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
            $stmt->execute([$user['id'], $serverId, $username, $password, $type, $port, ProxyStatus::ACTIVE->value]);
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
            $pdo->prepare('UPDATE http_proxies SET status = ? WHERE id = ?')->execute(['paused', $id]);
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
            $pdo->prepare('UPDATE http_proxies SET deleted_at = NOW(), status = ? WHERE id = ?')->execute(['deleted', $id]);
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

    public function syncAll()
    {
        requireAuth();
        unlockSession();
        try {
            ProxyServer::syncAllServers();
            relockSession();
            return $this->respond(true, "All proxy servers synchronized");
        } catch (Exception $e) {
            relockSession();
            return $this->respond(false, "Sync failed", $e->getMessage());
        }
    }

    public function check($params)
    {
        $user = requireAuth();
        $id   = (int)$params['id'];

        $pdo  = DB::conn();
        // Also filter deleted proxies and enforce ownership (admins can check any)
        if (Auth::isAdmin()) {
            $stmt = $pdo->prepare('SELECT p.*, s.host AS server_host FROM http_proxies p JOIN vpn_servers s ON p.server_id = s.id WHERE p.id = ? AND p.deleted_at IS NULL');
            $stmt->execute([$id]);
        } else {
            $stmt = $pdo->prepare('SELECT p.*, s.host AS server_host FROM http_proxies p JOIN vpn_servers s ON p.server_id = s.id WHERE p.id = ? AND p.user_id = ? AND p.deleted_at IS NULL');
            $stmt->execute([$id, $user['id']]);
        }
        $proxy = $stmt->fetch();

        if (!$proxy) return $this->respond(false, "Proxy not found");

        $isSocks5 = ($proxy['type'] === 'socks5');
        $curlType  = $isSocks5 ? CURLPROXY_SOCKS5_HOSTNAME : CURLPROXY_HTTP;

        // Try multiple IP-echo services so a single outage doesn't give a false negative
        $checkUrls = ['http://api.ipify.org', 'http://ipinfo.io/ip', 'http://ifconfig.me/ip'];
        $result = null;
        $httpCode = 0;
        $error = '';
        $errorNo = 0;

        foreach ($checkUrls as $url) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_PROXY          => $proxy['server_host'] . ':' . $proxy['port'],
                CURLOPT_PROXYTYPE      => $curlType,
                CURLOPT_PROXYUSERPWD   => $proxy['username'] . ':' . $proxy['password'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT      => 'curl/7.88',
            ]);
            // Only set HTTP proxy auth mode for HTTP proxies; SOCKS5 handles auth via PROXYUSERPWD
            if (!$isSocks5) {
                curl_setopt($ch, CURLOPT_PROXYAUTH, CURLAUTH_BASIC);
            }

            $result   = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);
            $errorNo  = curl_errno($ch);

            // Break as soon as we get a usable 200 response
            if ($httpCode === 200 && !empty($result)) {
                break;
            }
        }

        $ip = trim((string)$result);
        if ($httpCode === 200 && !empty($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
            $pdo->prepare('UPDATE http_proxies SET last_sync_at = NOW() WHERE id = ?')->execute([$id]);
            return $this->respond(true, "Proxy is working. External IP: " . $ip);
        } else {
            $errMsg = $error ?: "HTTP $httpCode";
            if ($httpCode === 407 || $errorNo === CURLE_LOGIN_DENIED)  $errMsg = "Proxy authentication failed";
            if ($errorNo === CURLE_COULDNT_CONNECT)    $errMsg = "Could not connect to proxy server";
            if ($errorNo === CURLE_OPERATION_TIMEDOUT) $errMsg = "Connection timed out";
            return $this->respond(false, "Connectivity check failed: $errMsg");
        }
    }
}

