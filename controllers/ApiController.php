<?php
declare(strict_types=1);


class ApiController {

    private function respond($data, $status = 200) {
        http_response_code($status);
        echo json_encode($data);
        exit;
    }

    public function token() {
        header('Content-Type: application/json');
        
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        
        if (empty($email) || empty($password)) {
            $this->respond(['error' => 'Email and password are required'], 400);
        }
        
        $user = Auth::getUserByEmail($email);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            $this->respond(['error' => 'Invalid credentials'], 401);
        }
        
        try {
            $token = JWT::generate($user['id']);
            $this->respond([
                'success' => true,
                'token' => $token,
                'type' => 'Bearer',
                'expires_in' => 30 * 24 * 3600
            ]);
        } catch (Exception $e) {
            $this->respond(['error' => 'Token generation failed'], 500);
        }
    }

    public function createToken() {
        header('Content-Type: application/json');
        $user = JWT::requireAuth();
        if (!$user) return;
        
        $name = $_POST['name'] ?? 'API Token';
        $expiresIn = isset($_POST['expires_in']) ? (int)$_POST['expires_in'] : 2592000;
        
        try {
            $tokenData = JWT::createApiToken($user['id'], $name, $expiresIn);
            $this->respond(['success' => true, 'token' => $tokenData]);
        } catch (Exception $e) {
            $this->respond(['error' => $e->getMessage()], 500);
        }
    }

    public function listTokens() {
        header('Content-Type: application/json');
        $user = JWT::requireAuth();
        if (!$user) return;
        
        $stmt = DB::get()->prepare("SELECT id, name, token, expires_at, created_at, last_used_at FROM api_tokens WHERE user_id = ? AND revoked_at IS NULL ORDER BY created_at DESC");
        $stmt->execute([$user['id']]);
        $tokens = $stmt->fetchAll();
        
        foreach ($tokens as &$token) {
            $token['token'] = substr($token['token'], 0, 10) . '...';
        }
        $this->respond(['tokens' => $tokens]);
    }

    public function revokeToken($params) {
        header('Content-Type: application/json');
        $user = JWT::requireAuth();
        if (!$user) return;
        
        try {
            JWT::revokeApiToken($params['id'], $user['id']);
            $this->respond(['success' => true]);
        } catch (Exception $e) {
            $this->respond(['error' => $e->getMessage()], 404);
        }
    }

    public function listServers(): void {
        header('Content-Type: application/json');
        $user = JWT::requireAuth();
        if (!$user) return;
        
        $servers = VpnServer::listByUser($user['id']);
        $this->respond(['servers' => $servers]);
    }

    public function createServer() {
        header('Content-Type: application/json');
        $user = JWT::requireAuth();
        if (!$user) return;
        
        $input = json_decode(file_get_contents('php://input'), true);
        $name = trim($input['name'] ?? '');
        $host = trim($input['host'] ?? '');
        $port = (int)($input['port'] ?? 22);
        $username = trim($input['username'] ?? 'root');
        $password = $input['password'] ?? '';
        
        if (empty($name) || empty($host) || empty($password)) {
            $this->respond(['error' => 'Missing required fields: name, host, password'], 400);
        }

        unlockSession();

        try {
            $serverId = VpnServer::create(['user_id' => $user['id'],'name' => $name,'host' => $host,'port' => $port,'username' => $username,'password' => $password]);
            $this->respond(['success' => true,'server_id' => $serverId,'message' => 'Server created successfully'], 201);
        } catch (Exception $e) {
            $this->respond(['error' => $e->getMessage()], 500);
        }
    }

    public function deleteServer($params) {
        header('Content-Type: application/json');
        $user = JWT::requireAuth();
        if (!$user) return;
        
        try {
            $server = new VpnServer((int)$params['id']);
            $serverData = $server->getData();
            if ($serverData['user_id'] != $user['id'] && $user['role'] !== 'admin') {
                $this->respond(['error' => 'Forbidden'], 403);
            }
            unlockSession();
            $server->delete();
            $this->respond(['success' => true, 'message' => 'Server deleted successfully']);
        } catch (Exception $e) {
            $this->respond(['error' => $e->getMessage()], 500);
        }
    }

    public function createBackup($params) {
        header('Content-Type: application/json');
        $user = requireApiAuth();
        if (!$user) return;
        
        try {
            $server = new VpnServer((int)$params['id']);
            $serverData = $server->getData();
            if ($serverData['user_id'] != $user['id'] && $user['role'] !== 'admin') {
                $this->respond(['error' => 'Forbidden'], 403);
            }
            unlockSession();
            $backupId = $server->createBackup($user['id'], 'manual');
            $backup = VpnServer::getBackup($backupId);
            $this->respond(['success' => true, 'backup' => $backup]);
        } catch (Exception $e) {
            $this->respond(['error' => $e->getMessage()], 500);
        }
    }

    public function listBackups($params) {
        header('Content-Type: application/json');
        $user = requireApiAuth();
        if (!$user) return;
        
        try {
            $server = new VpnServer((int)$params['id']);
            $serverData = $server->getData();
            if ($serverData['user_id'] != $user['id'] && $user['role'] !== 'admin') {
                $this->respond(['error' => 'Forbidden'], 403);
            }
            $backups = $server->listBackups();
            $this->respond(['success' => true, 'backups' => $backups, 'count' => count($backups)]);
        } catch (Exception $e) {
            $this->respond(['error' => $e->getMessage()], 500);
        }
    }

    public function restoreBackup($params) {
        header('Content-Type: application/json');
        $user = requireApiAuth();
        if (!$user) return;
        
        $data = json_decode(file_get_contents('php://input'), true);
        $backupId = (int)($data['backup_id'] ?? 0);
        if ($backupId <= 0) {
            $this->respond(['error' => 'backup_id is required'], 400);
        }
        
        try {
            $server = new VpnServer((int)$params['id']);
            $serverData = $server->getData();
            if ($serverData['user_id'] != $user['id'] && $user['role'] !== 'admin') {
                $this->respond(['error' => 'Forbidden'], 403);
            }
            unlockSession();
            $this->respond($server->restoreBackup($backupId));
        } catch (Exception $e) {
            $this->respond(['error' => $e->getMessage(), 'success' => false], 500);
        }
    }

    public function deleteBackup($params) {
        header('Content-Type: application/json');
        $user = requireApiAuth();
        if (!$user) return;
        
        try {
            $backup = VpnServer::getBackup((int)$params['id']);
            if (!$backup) { $this->respond(['error' => 'Backup not found'], 404); }
            $server = new VpnServer($backup['server_id']);
            if ($server->getData()['user_id'] != $user['id'] && $user['role'] !== 'admin') {
                $this->respond(['error' => 'Forbidden'], 403);
            }
            VpnServer::deleteBackup((int)$params['id']);
            $this->respond(['success' => true, 'message' => 'Backup deleted successfully']);
        } catch (Exception $e) {
            $this->respond(['error' => $e->getMessage()], 500);
        }
    }

    public function listClients() {
        header('Content-Type: application/json');
        $user = JWT::requireAuth();
        if (!$user) return;
        $this->respond(['clients' => VpnClient::listByUser($user['id'])]);
    }

    public function clientDetails($params) {
        header('Content-Type: application/json');
        $user = JWT::requireAuth();
        if (!$user) return;
        
        try {
            $client = new VpnClient((int)$params['id']);
            if ($client->getData()['user_id'] != $user['id']) { $this->respond(['error' => 'Forbidden'], 403); }
            $client->syncStats();
            $client = new VpnClient((int)$params['id']);
            $clientData = $client->getData();
            $stats = $client->getFormattedStats();
            $this->respond([
                'success' => true,
                'client' => [
                    'id' => $clientData['id'],
                    'name' => $clientData['name'],
                    'server_id' => $clientData['server_id'],
                    'client_ip' => $clientData['client_ip'],
                    'external_ip' => $clientData['external_ip'] ?? null,
                    'status' => ($clientData['status'] instanceof ClientStatus) ? $clientData['status']->value : $clientData['status'],
                    'created_at' => $clientData['created_at'],
                    'stats' => $stats,
                    'bytes_sent' => $clientData['bytes_sent'],
                    'bytes_received' => $clientData['bytes_received'],
                    'last_handshake' => $clientData['last_handshake'],
                    'config' => $clientData['config']
                ]
            ]);
        } catch (Exception $e) {
            $this->respond(['error' => 'Client not found'], 404);
        }
    }

    public function createClient() {
        header('Content-Type: application/json');
        $user = JWT::requireAuth();
        if (!$user) return;
        
        $data = json_decode(file_get_contents('php://input'), true);
        $serverId = (int)($data['server_id'] ?? 0);
        $name = trim($data['name'] ?? '');
        $expiresInDays = isset($data['expires_in_days']) ? (int)$data['expires_in_days'] : null;
        
        if ($serverId <= 0 || empty($name)) { $this->respond(['error' => 'server_id and name are required'], 400); }
        
        unlockSession();
        
        try {
            $clientId = VpnClient::create($serverId, $user['id'], $name, $expiresInDays);
            $clientData = (new VpnClient($clientId))->getData();
            $this->respond([
                'success' => true,
                'client' => [
                    'id' => $clientData['id'], 'name' => $clientData['name'], 'server_id' => $clientData['server_id'],
                    'client_ip' => $clientData['client_ip'], 
                    'status' => ($clientData['status'] instanceof ClientStatus) ? $clientData['status']->value : $clientData['status'], 
                    'expires_at' => $clientData['expires_at'],
                    'created_at' => $clientData['created_at'], 'config' => $clientData['config']
                ]
            ]);
        } catch (Exception $e) { $this->respond(['error' => $e->getMessage()], 500); }
    }

    public function revokeClient($params) {
        header('Content-Type: application/json');
        $user = JWT::requireAuth();
        if (!$user) return;
        try {
            $client = new VpnClient((int)$params['id']);
            if ($client->getData()['user_id'] != $user['id']) { $this->respond(['error' => 'Forbidden'], 403); }
            unlockSession();
            if ($client->revoke()) { $this->respond(['success' => true, 'message' => 'Client revoked']); }
            else { $this->respond(['error' => 'Failed to revoke client'], 500); }
        } catch (Exception $e) { $this->respond(['error' => $e->getMessage()], 500); }
    }

    public function restoreClient($params) {
        header('Content-Type: application/json');
        $user = JWT::requireAuth();
        if (!$user) return;
        try {
            $client = new VpnClient((int)$params['id']);
            if ($client->getData()['user_id'] != $user['id']) { $this->respond(['error' => 'Forbidden'], 403); }
            unlockSession();
            if ($client->restore()) { $this->respond(['success' => true, 'message' => 'Client restored']); }
            else { $this->respond(['error' => 'Failed to restore client'], 500); }
        } catch (Exception $e) { $this->respond(['error' => $e->getMessage()], 500); }
    }

    public function setClientExpiration($params) {
        header('Content-Type: application/json');
        $user = JWT::requireAuth();
        if (!$user) return;
        $data = json_decode(file_get_contents('php://input'), true);
        try {
            $client = new VpnClient((int)$params['id']);
            if ($client->getData()['user_id'] != $user['id'] && $user['role'] !== 'admin') { $this->respond(['error' => 'Forbidden'], 403); }
            VpnClient::setExpiration((int)$params['id'], $data['expires_at'] ?? null);
            $this->respond(['success' => true, 'expires_at' => $data['expires_at'] ?? null]);
        } catch (Exception $e) { $this->respond(['error' => $e->getMessage()], 500); }
    }

    public function extendClientExpiration($params) {
        header('Content-Type: application/json');
        $user = JWT::requireAuth();
        if (!$user) return;
        $data = json_decode(file_get_contents('php://input'), true);
        $days = (int)($data['days'] ?? 30);
        if ($days <= 0) { $this->respond(['error' => 'days must be positive'], 400); }
        try {
            $client = new VpnClient((int)$params['id']);
            if ($client->getData()['user_id'] != $user['id'] && $user['role'] !== 'admin') { $this->respond(['error' => 'Forbidden'], 403); }
            VpnClient::extendExpiration((int)$params['id'], $days);
            $this->respond(['success' => true, 'expires_at' => (new VpnClient((int)$params['id']))->getData()['expires_at'], 'extended_days' => $days]);
        } catch (Exception $e) { $this->respond(['error' => $e->getMessage()], 500); }
    }

    public function getExpiringClients() {
        header('Content-Type: application/json');
        $user = JWT::requireAuth();
        if (!$user) return;
        $days = (int)($_GET['days'] ?? 7);
        try {
            $clients = VpnClient::getExpiringClients($days);
            if ($user['role'] !== 'admin') {
                $clients = array_filter($clients, function($c) use ($user) { return $c['user_id'] == $user['id']; });
            }
            $this->respond(['success' => true, 'clients' => array_values($clients), 'count' => count($clients)]);
        } catch (Exception $e) { $this->respond(['error' => $e->getMessage()], 500); }
    }

    public function setClientTrafficLimit($params) {
        header('Content-Type: application/json');
        $user = JWT::requireAuth();
        if (!$user) return;
        $data = json_decode(file_get_contents('php://input'), true);
        $limitBytes = isset($data['limit_bytes']) ? (int)$data['limit_bytes'] : null;
        if ($limitBytes !== null && $limitBytes < 0) { $this->respond(['error' => 'limit_bytes must be positive or null for unlimited'], 400); }
        try {
            $client = new VpnClient((int)$params['id']);
            if ($client->getData()['user_id'] != $user['id'] && $user['role'] !== 'admin') { $this->respond(['error' => 'Forbidden'], 403); }
            $client->setTrafficLimit($limitBytes);
            $this->respond(['success' => true, 'limit_bytes' => $limitBytes, 'limit_gb' => $limitBytes ? round($limitBytes / 1073741824, 2) : null]);
        } catch (Exception $e) { $this->respond(['error' => $e->getMessage()], 500); }
    }

    public function getClientTrafficLimitStatus($params) {
        header('Content-Type: application/json');
        $user = JWT::requireAuth();
        if (!$user) return;
        try {
            $client = new VpnClient((int)$params['id']);
            if ($client->getData()['user_id'] != $user['id'] && $user['role'] !== 'admin') { $this->respond(['error' => 'Forbidden'], 403); }
            $this->respond(['success' => true, 'status' => $client->getTrafficLimitStatus()]);
        } catch (Exception $e) { $this->respond(['error' => $e->getMessage()], 500); }
    }

    public function getClientsOverLimit() {
        header('Content-Type: application/json');
        $user = JWT::requireAuth();
        if (!$user) return;
        try {
            $clients = VpnClient::getClientsOverLimit();
            if ($user['role'] !== 'admin') {
                $clients = array_filter($clients, function($c) use ($user) { return $c['user_id'] == $user['id']; });
            }
            $this->respond(['success' => true, 'clients' => array_values($clients), 'count' => count($clients)]);
        } catch (Exception $e) { $this->respond(['error' => $e->getMessage()], 500); }
    }

    public function serverMetrics($params) {
        header('Content-Type: application/json');
        $user = $this->requireAnyAuth();
        if (!$user) { $this->respond(['error' => 'Unauthorized'], 401); }
        try {
            $server = new VpnServer((int)$params['id']);
            if ($server->getData()['user_id'] != $user['id'] && $user['role'] !== 'admin') { $this->respond(['error' => 'Forbidden'], 403); }
            $this->respond(['success' => true, 'metrics' => ServerMonitoring::getServerMetrics((int)$params['id'], isset($_GET['hours']) ? (float)$_GET['hours'] : 24)]);
        } catch (Exception $e) { $this->respond(['error' => $e->getMessage()], 500); }
    }

    public function clientMetrics($params) {
        header('Content-Type: application/json');
        $user = $this->requireAnyAuth();
        if (!$user) { $this->respond(['error' => 'Unauthorized'], 401); }
        try {
            $client = new VpnClient((int)$params['id']);
            $server = new VpnServer($client->getData()['server_id']);
            if ($server->getData()['user_id'] != $user['id'] && $user['role'] !== 'admin') { $this->respond(['error' => 'Forbidden'], 403); }
            $this->respond(['success' => true, 'metrics' => ServerMonitoring::getClientMetrics((int)$params['id'], isset($_GET['hours']) ? (float)$_GET['hours'] : 24)]);
        } catch (Exception $e) { $this->respond(['error' => $e->getMessage()], 500); }
    }

    public function serverClients($params) {
        header('Content-Type: application/json');
        $user = authenticateRequest();
        if (!$user) { $this->respond(['error' => 'Unauthorized'], 401); }
        try {
            $server = new VpnServer((int)$params['id']);
            if ($server->getData()['user_id'] != $user['id'] && $user['role'] !== 'admin') { $this->respond(['error' => 'Forbidden'], 403); }
            unlockSession();
            VpnClient::syncAllStatsForServer((int)$params['id']);
            $clients = VpnClient::listByServer((int)$params['id']);
            $clientsData = [];
            foreach ($clients as $clientData) {
                $client = new VpnClient($clientData['id']);
                $clientData['status'] = ($clientData['status'] instanceof ClientStatus) ? $clientData['status']->value : $clientData['status'];
                $clientsData[] = array_merge($clientData, ['stats' => $client->getFormattedStats()]);
            }
            $this->respond(['success' => true, 'clients' => $clientsData]);
        } catch (Exception $e) { $this->respond(['error' => $e->getMessage()], 500); }
    }

    public function translationStats() {
        header('Content-Type: application/json');
        $user = JWT::requireAuth();
        if (!$user) return;
        $this->respond(['stats' => Translator::getStatistics()]);
    }

    public function autoTranslate() {
        header('Content-Type: application/json');
        $user = JWT::requireAuth();
        if (!$user) return;
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['language'])) { $this->respond(['error' => 'Language is required'], 400); }
        try {
            $this->respond(['success' => true, 'stats' => Translator::translateMissingKeys($data['language'])]);
        } catch (Exception $e) { $this->respond(['error' => $e->getMessage()], 500); }
    }

    public function exportTranslations($params) {
        header('Content-Type: application/json');
        $user = JWT::requireAuth();
        if (!$user) return;
        try {
            $json = Translator::exportToJson($params['lang']);
            header('Content-Disposition: attachment; filename="translations_' . $params['lang'] . '.json"');
            echo $json;
            exit;
        } catch (Exception $e) { $this->respond(['error' => $e->getMessage()], 500); }
    }

    public function listProxies() {
        header('Content-Type: application/json');
        $user = JWT::requireAuth();
        if (!$user) return;

        $pdo = DB::conn();
        $proxies = $pdo->query('
            SELECT p.*, s.name as server_name 
            FROM http_proxies p 
            JOIN vpn_servers s ON p.server_id = s.id 
            ORDER BY p.created_at DESC
        ')->fetchAll();

        $this->respond(['success' => true, 'proxies' => $proxies]);
    }

    public function createProxy() {
        header('Content-Type: application/json');
        $user = JWT::requireAuth();
        if (!$user) return;

        $data = json_decode(file_get_contents('php://input'), true);
        $serverId = (int)($data['server_id'] ?? 0);
        $username = trim($data['username'] ?? '');
        $password = trim($data['password'] ?? '');
        $type = trim($data['type'] ?? 'http');
        if (!in_array($type, ['http', 'socks5'])) {
            $type = 'http';
        }

        if (!$serverId) { $this->respond(['error' => 'server_id is required'], 400); }
        if (empty($username)) { $username = 'user_' . substr(md5(uniqid()), 0, 8); }
        if (empty($password)) { $password = substr(md5(uniqid()), 0, 12); }

        unlockSession();

        try {
            $proxyServer = new ProxyServer($serverId);
            $proxyServer->install();
            $port = $proxyServer->findFreePort();

            $pdo = DB::conn();
            $stmt = $pdo->prepare('
                INSERT INTO http_proxies (user_id, server_id, username, password, type, port, status) 
                VALUES (?, ?, ?, ?, ?, ?, "active")
            ');
            $stmt->execute([$user['id'], $serverId, $username, $password, $type, $port]);
            $proxyId = $pdo->lastInsertId();
            
            try {
                $proxyServer->syncUsers();
                \Logger::error("API Proxy created: ID $proxyId, User $username on Server $serverId");
                $this->respond(['success' => true, 'message' => 'Proxy created', 'port' => $port, 'id' => $proxyId]);
            } catch (Exception $e) {
                // Rollback
                $pdo->prepare('DELETE FROM http_proxies WHERE id = ?')->execute([$proxyId]);
                throw $e;
            }
        } catch (Exception $e) { 
            \Logger::error("API Failed to create proxy: " . $e->getMessage());
            $this->respond(['error' => $e->getMessage()], 500); 
        }
    }

    public function pauseProxy($params) {
        header('Content-Type: application/json');
        $user = JWT::requireAuth();
        if (!$user) return;
        $id = (int)$params['id'];

        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT * FROM http_proxies WHERE id = ?');
        $stmt->execute([$id]);
        $proxy = $stmt->fetch();

        if ($proxy) {
            $pdo->prepare('UPDATE http_proxies SET status = "paused" WHERE id = ?')->execute([$id]);
            unlockSession();
            try {
                $proxyServer = new ProxyServer($proxy['server_id']);
                $proxyServer->syncUsers();
                $this->respond(['success' => true, 'message' => 'Proxy paused']);
            } catch (Exception $e) { $this->respond(['error' => $e->getMessage()], 500); }
        } else { $this->respond(['error' => 'Proxy not found'], 404); }
    }

    public function resumeProxy($params) {
        header('Content-Type: application/json');
        $user = JWT::requireAuth();
        if (!$user) return;
        $id = (int)$params['id'];

        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT * FROM http_proxies WHERE id = ?');
        $stmt->execute([$id]);
        $proxy = $stmt->fetch();

        if ($proxy) {
            $pdo->prepare('UPDATE http_proxies SET status = "active" WHERE id = ?')->execute([$id]);
            unlockSession();
            try {
                $proxyServer = new ProxyServer($proxy['server_id']);
                $proxyServer->syncUsers();
                $this->respond(['success' => true, 'message' => 'Proxy resumed']);
            } catch (Exception $e) { $this->respond(['error' => $e->getMessage()], 500); }
        } else { $this->respond(['error' => 'Proxy not found'], 404); }
    }

    public function deleteProxy($params) {
        header('Content-Type: application/json');
        $user = JWT::requireAuth();
        if (!$user) return;
        $id = (int)$params['id'];

        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT * FROM http_proxies WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$id]);
        $proxy = $stmt->fetch();

        if ($proxy) {
            // Soft-delete: preserve bytes_sent/bytes_received for dashboard traffic totals
            $pdo->prepare('UPDATE http_proxies SET deleted_at = NOW(), status = ? WHERE id = ?')
                ->execute(['deleted', $id]);
            unlockSession();
            try {
                $proxyServer = new ProxyServer($proxy['server_id']);
                $proxyServer->syncUsers();
                $this->respond(['success' => true, 'message' => 'Proxy deleted']);
            } catch (Exception $e) { $this->respond(['error' => $e->getMessage()], 500); }
        } else { $this->respond(['error' => 'Proxy not found'], 404); }
    }

    private function requireAnyAuth() {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($authHeader && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return JWT::verify($matches[1]);
        } else if (isset($_SESSION['user_id'])) {
            return Auth::user();
        }
        return null;
    }
}
