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
        
        $stmt = DB::conn()->prepare("SELECT id, name, token, expires_at, created_at, last_used_at FROM api_tokens WHERE user_id = ? AND revoked_at IS NULL ORDER BY created_at DESC");
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
                $clientData['status'] = ($clientData['status'] instanceof ClientStatus) ? $clientData['status']->value : $clientData['status'];
                $clientsData[] = array_merge($clientData, ['stats' => VpnClient::formatStatsForData($clientData)]);
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

    public function getJobEvents($params) {
        header('Content-Type: application/json');
        $user = $this->requireAnyAuth();
        if (!$user) { $this->respond(['error' => 'Unauthorized'], 401); }
        
        $jobId = (int)$params['id'];
        
        try {
            $job = new Job($jobId);
            $jobData = $job->getData();
            
            // Security: Ensure user owns the server associated with the job
            $pdo = DB::conn();
            $stmt = $pdo->prepare("SELECT user_id FROM vpn_servers WHERE id = ?");
            $stmt->execute([$jobData['server_id']]);
            $ownerId = $stmt->fetchColumn();
            
            if ($ownerId != $user['id'] && $user['role'] !== 'admin') {
                $this->respond(['error' => 'Forbidden'], 403);
            }
            
            $this->respond([
                'success' => true, 
                'job' => [
                    'status' => $jobData['status'],
                    'type' => $jobData['type']
                ],
                'events' => $job->getEvents(100)
            ]);
        } catch (Exception $e) {
            $this->respond(['error' => $e->getMessage()], 500);
        }
    }

    public function cancelJob($params) {
        $user = $this->requireAnyAuth();
        if (!$user) { $this->respond(['error' => 'Unauthorized'], 401); }
        
        $jobId = (int)$params['id'];
        
        try {
            $job = new Job($jobId);
            $jobData = $job->getData();
            
            // Authorization check
            $pdo = DB::conn();
            $stmt = $pdo->prepare("SELECT user_id FROM vpn_servers WHERE id = ?");
            $stmt->execute([$jobData['server_id']]);
            $ownerId = $stmt->fetchColumn();
            
            if ($ownerId != $user['id'] && $user['role'] !== 'admin') {
                $this->respond(['error' => 'Forbidden'], 403);
            }
            
            $job->requestCancel();
            $this->respond(['success' => true, 'message' => 'Cancellation requested']);
        } catch (Exception $e) {
            $this->respond(['error' => $e->getMessage()], 500);
        }
    }

    public function getDeploymentLogs($params) {
        $user = $this->requireAnyAuth();
        if (!$user) { $this->respond(['error' => 'Unauthorized'], 401); }
        
        $jobId = (int)$params['id'];
        try {
            $job = new Job($jobId);
            $this->respond([
                'logs' => $job->getEvents(1000)
            ]);
        } catch (Exception $e) {
            $this->respond(['error' => $e->getMessage()], 500);
        }
    }

    public function systemHealth() {
        header('Content-Type: application/json');
        $user = $this->requireAnyAuth();
        if (!$user || $user['role'] !== 'admin') {
            $this->respond(['error' => 'Forbidden'], 403);
        }

        try {
            $db = DB::conn();
            
            // 1. Basic counts
            $serversCount = (int)$db->query("SELECT COUNT(*) FROM vpn_servers WHERE deleted_at IS NULL")->fetchColumn();
            $clientsCount = (int)$db->query("SELECT COUNT(*) FROM vpn_clients WHERE deleted_at IS NULL")->fetchColumn();
            
            // 2. Telemetry metadata aggregation
            $stats = $db->query("
                SELECT 
                    SUM(total_ingest_count) as total_ingestion,
                    SUM(backpressure_count) as total_backpressure,
                    SUM(circuit_breaker_count) as total_cb,
                    SUM(replayed_packets_count) as total_replays,
                    AVG(server_health_score) as avg_health,
                    AVG(last_ingest_latency_ms) as avg_ingest_ms,
                    AVG(last_db_time_ms) as avg_db_ms,
                    AVG(last_centrifugo_time_ms) as avg_cent_ms
                FROM vpn_servers
                WHERE deleted_at IS NULL
            ")->fetch(PDO::FETCH_ASSOC);

            // 3. HARD SLO COMPLIANCE METRICS
            $sloStats = $db->query("
                SELECT 
                    COUNT(*) as total_active,
                    SUM(CASE WHEN last_ingest_latency_ms > 20.0 THEN 1 ELSE 0 END) as ingest_violations,
                    SUM(CASE WHEN last_db_time_ms > 15.0 THEN 1 ELSE 0 END) as db_violations,
                    SUM(CASE WHEN last_centrifugo_time_ms > 10.0 THEN 1 ELSE 0 END) as centrifugo_violations,
                    SUM(CASE WHEN last_telemetry_at IS NULL OR TIMESTAMPDIFF(SECOND, last_telemetry_at, NOW()) > 30 THEN 1 ELSE 0 END) as freshness_violations
                FROM vpn_servers
                WHERE deleted_at IS NULL AND telemetry_token IS NOT NULL
            ")->fetch(PDO::FETCH_ASSOC);

            $totalActive = (int)($sloStats['total_active'] ?? 0);
            $sloCompliance = [
                'ingest_latency_p95_compliance' => $totalActive > 0 ? round((1 - ($sloStats['ingest_violations'] ?? 0) / $totalActive) * 100, 2) : 100.0,
                'db_transaction_p95_compliance' => $totalActive > 0 ? round((1 - ($sloStats['db_violations'] ?? 0) / $totalActive) * 100, 2) : 100.0,
                'centrifugo_latency_compliance' => $totalActive > 0 ? round((1 - ($sloStats['centrifugo_violations'] ?? 0) / $totalActive) * 100, 2) : 100.0,
                'node_freshness_compliance' => $totalActive > 0 ? round((1 - ($sloStats['freshness_violations'] ?? 0) / $totalActive) * 100, 2) : 100.0
            ];

            // 4. Platform Health Status derived from SLO contracts
            $avgHealth = $stats['avg_health'] !== null ? round((float)$stats['avg_health'], 2) : 100.0;
            $status = 'healthy';
            if ($avgHealth < 75) $status = 'unstable';
            elseif ($avgHealth < 90) $status = 'degraded';

            // 5. Centrifugo socket status
            $centrifugoStatus = 'healthy';
            try {
                EventBus::hasActiveSubscribers("system:ping");
            } catch (\Throwable $e) {
                $centrifugoStatus = 'offline';
            }

            $this->respond([
                'success' => true,
                'status' => $status,
                'score' => $avgHealth,
                'counts' => [
                    'servers' => $serversCount,
                    'clients' => $clientsCount
                ],
                'slo_compliance' => $sloCompliance,
                'telemetry' => [
                    'total_ingests' => (int)($stats['total_ingestion'] ?? 0),
                    'total_backpressures' => (int)($stats['total_backpressure'] ?? 0),
                    'total_circuit_breakers' => (int)($stats['total_cb'] ?? 0),
                    'total_replays' => (int)($stats['total_replays'] ?? 0),
                    'latencies' => [
                        'avg_ingest_ms' => round((float)($stats['avg_ingest_ms'] ?? 0), 2),
                        'avg_db_ms' => round((float)($stats['avg_db_ms'] ?? 0), 2),
                        'avg_centrifugo_ms' => round((float)($stats['avg_cent_ms'] ?? 0), 2)
                    ]
                ],
                'dependencies' => [
                    'database' => 'healthy',
                    'centrifugo' => $centrifugoStatus
                ]
            ]);
        } catch (Exception $e) {
            $this->respond(['error' => $e->getMessage()], 500);
        }
    }

    public function systemNodes() {
        header('Content-Type: application/json');
        $user = $this->requireAnyAuth();
        if (!$user || $user['role'] !== 'admin') {
            $this->respond(['error' => 'Forbidden'], 403);
            return;
        }

        try {
            $db = DB::conn();
            $stmt = $db->query("
                SELECT id, name, host, last_telemetry_at, UNIX_TIMESTAMP(last_telemetry_at) as last_telemetry_ts,
                       last_ingest_latency_ms, last_db_time_ms, last_centrifugo_time_ms,
                       total_ingest_count, backpressure_count, circuit_breaker_count,
                       replayed_packets_count, server_health_score, last_failure_reasons,
                       telemetry_state, last_decision_path, loop_entropy, baseline_drift_index, control_loop_damping
                FROM vpn_servers
                WHERE deleted_at IS NULL
                ORDER BY server_health_score ASC, name ASC
            ");
            $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format health status labels
            foreach ($nodes as &$node) {
                $node['reasons'] = !empty($node['last_failure_reasons']) ? json_decode($node['last_failure_reasons'], true) : [];
                unset($node['last_failure_reasons']); // Strip raw JSON string to keep clean

                $node['decision_path'] = !empty($node['last_decision_path']) ? json_decode($node['last_decision_path'], true) : [];
                unset($node['last_decision_path']);

                $score = (int)$node['server_health_score'];
                $status = '🟢 Healthy';
                
                if (!empty($node['reasons'])) {
                    if (in_array('backpressure_active', $node['reasons']) || in_array('centrifugo_outage', $node['reasons'])) {
                        $status = '🔴 Unstable';
                    } else {
                        $status = '🟡 Degraded';
                    }
                }
                
                // Check if offline (no telemetry for > 60s)
                $lastTs = $node['last_telemetry_ts'] ? (int)$node['last_telemetry_ts'] : 0;
                if ($lastTs === 0 || (time() - $lastTs) > 60) {
                    $status = '⚫ Offline';
                    $node['server_health_score'] = 0;
                    $node['reasons'] = array_values(array_unique(array_merge($node['reasons'], ['heartbeat_timeout'])));
                }
                
                $node['status'] = $status;
            }

            $this->respond([
                'success' => true,
                'nodes' => $nodes
            ]);
        } catch (Exception $e) {
            $this->respond(['error' => $e->getMessage()], 500);
        }
    }

    public function systemTransitions() {
        header('Content-Type: application/json');
        $user = $this->requireAnyAuth();
        if (!$user || $user['role'] !== 'admin') {
            $this->respond(['error' => 'Forbidden'], 403);
            return;
        }

        $db = DB::conn();
        $serverId = (int)($_GET['server_id'] ?? 0);
        if ($serverId <= 0) {
            $this->respond(['error' => 'Server ID is required'], 400);
            return;
        }

        try {
            // Load current dynamics from server
            $serverStmt = $db->prepare("SELECT loop_entropy, baseline_drift_index, control_loop_damping FROM vpn_servers WHERE id = ?");
            $serverStmt->execute([$serverId]);
            $srv = $serverStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $transitionsStmt = $db->prepare("
                SELECT id, from_state, to_state, trigger_event, instability_weight, created_at
                FROM telemetry_state_transitions
                WHERE server_id = ?
                ORDER BY created_at DESC
                LIMIT 50
            ");
            $transitionsStmt->execute([$serverId]);
            $transitions = $transitionsStmt->fetchAll(PDO::FETCH_ASSOC);

            // Compute Oscillation & Thrash Metrics
            $totalTransitions = count($transitions);
            $instabilityTotal = 0.0;
            foreach ($transitions as $t) {
                $instabilityTotal += (float)$t['instability_weight'];
            }

            // Detect cycles in recent history (order chronologically)
            $chrono = array_reverse($transitions);
            $oscillationCount = 0;
            for ($i = 1; $i < count($chrono); $i++) {
                $prev = $chrono[$i - 1];
                $curr = $chrono[$i];
                // Check if they are reciprocal: A -> B followed by B -> A
                if ($prev['from_state'] === $curr['to_state'] && $prev['to_state'] === $curr['from_state']) {
                    $oscillationCount++;
                }
            }

            // Determine health classification of control loop
            $loopStatus = 'stable';
            $warnings = [];
            if ($instabilityTotal > 2.0) {
                $loopStatus = 'thrashing';
                $warnings[] = 'High frequency control loop oscillation detected (thrash chain active).';
            } elseif ($oscillationCount >= 3) {
                $loopStatus = 'oscillating';
                $warnings[] = 'Unstable state oscillation between active/idle/backpressure nodes detected.';
            }

            $this->respond([
                'success' => true,
                'server_id' => $serverId,
                'status' => $loopStatus,
                'warnings' => $warnings,
                'metrics' => [
                    'total_transitions' => $totalTransitions,
                    'cumulative_instability_index' => round($instabilityTotal, 2),
                    'reciprocal_cycle_count' => $oscillationCount,
                    'loop_entropy' => round((float)($srv['loop_entropy'] ?? 0.0), 2),
                    'baseline_drift_index' => round((float)($srv['baseline_drift_index'] ?? 0.0), 1),
                    'control_loop_damping' => round((float)($srv['control_loop_damping'] ?? 1.0), 2)
                ],
                'transitions' => $transitions
            ]);
        } catch (Exception $e) {
            $this->respond(['error' => $e->getMessage()], 500);
        }
    }

    public function systemReplay() {
        header('Content-Type: application/json');
        $user = $this->requireAnyAuth();
        if (!$user || $user['role'] !== 'admin') {
            $this->respond(['error' => 'Forbidden'], 403);
            return;
        }

        $db = DB::conn();
        $serverId = (int)($_GET['server_id'] ?? 0);
        $fromTime = $_GET['from'] ?? null;
        $toTime = $_GET['to'] ?? null;
        
        $scenario = $_GET['scenario'] ?? '';
        if (!empty($scenario)) {
            $safeScenario = escapeshellarg($scenario);
            exec("php " . dirname(__DIR__) . "/bin/load-test.php --nodes=1 --clients=2 --scenario={$safeScenario}", $output, $status);
            $this->respond([
                'success' => $status === 0,
                'message' => "Scenario {$scenario} simulation run complete",
                'logs' => $output
            ]);
            return;
        }

        try {
            // Programmatically execute a packet replay if payload_id is specified
            $payloadId = (int)($_GET['payload_id'] ?? 0);
            if ($payloadId > 0) {
                $logStmt = $db->prepare("SELECT * FROM telemetry_replay_logs WHERE id = ?");
                $logStmt->execute([$payloadId]);
                $log = $logStmt->fetch(PDO::FETCH_ASSOC);
                if (!$log) {
                    $this->respond(['error' => 'Replay log entry not found'], 404);
                    return;
                }
                
                $result = $this->runLocalReplay($log);
                $this->respond([
                    'success' => true,
                    'message' => 'Single packet replay simulation complete',
                    'replay' => $result
                ]);
                return;
            }

            // Expose captured telemetry logs query window
            $query = "SELECT id, server_id, status, latency_ms, created_at, SUBSTRING(payload, 1, 80) as payload_preview 
                      FROM telemetry_replay_logs 
                      WHERE 1=1";
            $binds = [];
            if ($serverId > 0) {
                $query .= " AND server_id = ?";
                $binds[] = $serverId;
            }
            if ($fromTime) {
                $query .= " AND created_at >= ?";
                $binds[] = $fromTime;
            }
            if ($toTime) {
                $query .= " AND created_at <= ?";
                $binds[] = $toTime;
            }
            $query .= " ORDER BY created_at DESC LIMIT 50";
            
            $stmt = $db->prepare($query);
            $stmt->execute($binds);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $this->respond([
                'success' => true,
                'logs' => $logs
            ]);
        } catch (Exception $e) {
            $this->respond(['error' => $e->getMessage()], 500);
        }
    }

    private function runLocalReplay($log) {
        $db = DB::conn();
        $serverId = (int)$log['server_id'];
        $payload = json_decode($log['payload'], true);
        
        $startTime = microtime(true);
        $dbStart = microtime(true);
        $dbDuration = 0.0;
        
        // Wrap query execution inside a Transaction that automatically rolls back!
        // This validates raw performance timings without modifying live bytes metrics.
        $db->beginTransaction();
        try {
            $peers = $payload['peers'] ?? [];
            $clientsStmt = $db->prepare("SELECT public_key, id FROM vpn_clients WHERE server_id = ? AND deleted_at IS NULL");
            $clientsStmt->execute([$serverId]);
            $clients = $clientsStmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            $updateStmt = $db->prepare("UPDATE vpn_clients SET bytes_sent = ?, bytes_received = ?, last_metric_at = NOW() WHERE id = ?");
            
            foreach ($peers as $peer) {
                $pubKey = $peer['public_key'] ?? '';
                if (isset($clients[$pubKey])) {
                    $clientId = $clients[$pubKey];
                    $updateStmt->execute([
                        (int)($peer['tx'] ?? 0),
                        (int)($peer['rx'] ?? 0),
                        $clientId
                    ]);
                }
            }
            $dbDuration = (microtime(true) - $dbStart) * 1000.0;
            $db->rollBack(); // Always roll back simulated packets
        } catch (\Throwable $ex) {
            $db->rollBack();
            return [
                'success' => false,
                'error' => 'DB Replay simulation error: ' . $ex->getMessage()
            ];
        }
        
        $totalDuration = (microtime(true) - $startTime) * 1000.0;
        
        // Mark replay logs state
        $db->prepare("UPDATE telemetry_replay_logs SET status = 'replayed', latency_ms = ? WHERE id = ?")
           ->execute([$totalDuration, $log['id']]);

        return [
            'success' => true,
            'simulated_db_latency_ms' => round($dbDuration, 2),
            'total_replay_latency_ms' => round($totalDuration, 2),
            'status' => 'success'
        ];
    }

    public function systemMetrics() {
        header('Content-Type: application/json');
        $user = $this->requireAnyAuth();
        if (!$user || $user['role'] !== 'admin') {
            $this->respond(['error' => 'Forbidden'], 403);
            return;
        }

        try {
            $db = DB::conn();
            // Fetch hourly aggregated metrics in the past 24 hours
            $stmt = $db->query("
                SELECT 
                    recorded_hour as time_label,
                    SUM(bytes_sent_delta) as bytes_sent,
                    SUM(bytes_received_delta) as bytes_received,
                    MAX(peak_speed_up_kbps) as peak_speed_up,
                    MAX(peak_speed_down_kbps) as peak_speed_down
                FROM client_hourly_metrics
                WHERE recorded_hour >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY recorded_hour
                ORDER BY recorded_hour ASC
            ");
            $hourly = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch daily aggregated metrics in the past 30 days
            $stmt = $db->query("
                SELECT 
                    recorded_day as time_label,
                    SUM(bytes_sent_delta) as bytes_sent,
                    SUM(bytes_received_delta) as bytes_received,
                    MAX(peak_speed_up_kbps) as peak_speed_up,
                    MAX(peak_speed_down_kbps) as peak_speed_down
                FROM client_daily_metrics
                WHERE recorded_day >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY recorded_day
                ORDER BY recorded_day ASC
            ");
            $daily = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $this->respond([
                'success' => true,
                'hourly_24h' => $hourly,
                'daily_30d' => $daily
            ]);
        } catch (Exception $e) {
            $this->respond(['error' => $e->getMessage()], 500);
        }
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
