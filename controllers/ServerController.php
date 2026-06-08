<?php
declare(strict_types=1);


class ServerController
{
    private function respond($success, $message, $error = null, $redirect = null) {
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') || 
                  (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => $success,
                'message' => $message,
                'data' => $success ? null : null,
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
        
        $back = $_SERVER['HTTP_REFERER'] ?? '/servers';
        header('Location: ' . $back);
        exit;
    }
    public function dashboard()
    {
        requireAuth();
        $user = Auth::user();
        $pdo = DB::conn();

        // One-time translation migration trigger via URL param
        if (isset($_GET['migrate_db']) && Auth::isAdmin()) {
            try {
                $translations = [
                    ['en', 'common', 'batch_actions', 'Batch Actions'],
                    ['en', 'common', 'select_all', 'Select All'],
                    ['en', 'common', 'delete_selected', 'Delete Selected'],
                    ['en', 'common', 'revoke_selected', 'Revoke Selected'],
                    ['en', 'common', 'restore_selected', 'Restore Selected'],
                    ['en', 'common', 'selected_count', '%s items selected'],
                    ['en', 'common', 'sort_handshake', 'Last Activity'],
                    ['en', 'common', 'sort_oldest', 'Oldest First'],
                    ['en', 'common', 'filter_status', 'Status Filter'],
                    ['en', 'common', 'filter_traffic', 'Traffic Filter'],
                    ['en', 'common', 'apply_filters', 'Apply Filters'],
                    ['en', 'common', 'sort_newest', 'Newest First'],
                    ['en', 'common', 'sort_traffic', 'Most Traffic'],
                    ['uk', 'common', 'batch_actions', 'Групові дії'],
                    ['uk', 'common', 'select_all', 'Вибрати все'],
                    ['uk', 'common', 'delete_selected', 'Видалити вибрані'],
                    ['uk', 'common', 'revoke_selected', 'Відкликати вибрані'],
                    ['uk', 'common', 'restore_selected', 'Відновити вибрані'],
                    ['uk', 'common', 'selected_count', 'Вибрано: %s'],
                    ['uk', 'common', 'sort_handshake', 'Остання активність'],
                    ['uk', 'common', 'sort_oldest', 'Спочатку старі'],
                    ['uk', 'common', 'filter_status', 'Фільтр статусу'],
                    ['uk', 'common', 'filter_traffic', 'Фільтр трафіку'],
                    ['uk', 'common', 'apply_filters', 'Застосувати'],
                    ['uk', 'common', 'sort_newest', 'Найновіші'],
                    ['uk', 'common', 'sort_traffic', 'Найбільше трафіку'],
                    ['ru', 'common', 'batch_actions', 'Групповые действия'],
                    ['ru', 'common', 'select_all', 'Выбрать все'],
                    ['ru', 'common', 'delete_selected', 'Удалить выбранные'],
                    ['ru', 'common', 'revoke_selected', 'Отозвать выбранные'],
                    ['ru', 'common', 'restore_selected', 'Восстановить выбранные'],
                    ['ru', 'common', 'selected_count', 'Выбрано: %s'],
                    ['ru', 'common', 'sort_handshake', 'Последняя активность'],
                    ['ru', 'common', 'sort_oldest', 'Сначала старые'],
                    ['ru', 'common', 'filter_status', 'Фильтр статуса'],
                    ['ru', 'common', 'filter_traffic', 'Фильтр трафика'],
                    ['ru', 'common', 'apply_filters', 'Применить'],
                    ['ru', 'common', 'sort_newest', 'Сначала новые'],
                    ['ru', 'common', 'sort_traffic', 'Больше всего трафика'],
                    ['en', 'status', 'never', 'Never Connected'],
                    ['uk', 'status', 'never', 'Ніколи не підключався'],
                    ['ru', 'status', 'never', 'Никогда не подключался']
                ];
                $stmt = $pdo->prepare("INSERT INTO translations (locale, category, key_name, translation) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE translation = VALUES(translation)");
                foreach ($translations as $row) { $stmt->execute($row); }
                $_SESSION['success_message'] = "Translations migrated successfully!";
                header('Location: /dashboard');
                exit;
            } catch (Exception $e) {
                $_SESSION['error_message'] = "Migration error: " . $e->getMessage();
            }
        }

        $servers = Auth::isAdmin() ? VpnServer::listAll() : VpnServer::listByUser($user['id']);

        // Use parameterized queries for safety
        $vpnStmt = Auth::isAdmin()
            ? $pdo->query("SELECT COUNT(CASE WHEN deleted_at IS NULL THEN 1 END) as total_clients, SUM(bytes_sent) as vpn_upload, SUM(bytes_received) as vpn_download, SUM(CASE WHEN last_handshake > DATE_SUB(NOW(), INTERVAL 5 MINUTE) AND deleted_at IS NULL THEN 1 ELSE 0 END) as active_clients FROM vpn_clients")
            : $pdo->prepare("SELECT COUNT(CASE WHEN deleted_at IS NULL THEN 1 END) as total_clients, SUM(bytes_sent) as vpn_upload, SUM(bytes_received) as vpn_download, SUM(CASE WHEN last_handshake > DATE_SUB(NOW(), INTERVAL 5 MINUTE) AND deleted_at IS NULL THEN 1 ELSE 0 END) as active_clients FROM vpn_clients WHERE user_id = ?");
        if (!Auth::isAdmin()) { $vpnStmt->execute([$user['id']]); }
        $vpnStats = $vpnStmt->fetch(PDO::FETCH_ASSOC);

        $proxyStmt = Auth::isAdmin()
            ? $pdo->query("SELECT SUM(bytes_sent) as proxy_upload, SUM(bytes_received) as proxy_download FROM http_proxies")
            : $pdo->prepare("SELECT SUM(bytes_sent) as proxy_upload, SUM(bytes_received) as proxy_download FROM http_proxies WHERE user_id = ?");
        if (!Auth::isAdmin()) { $proxyStmt->execute([$user['id']]); }
        $proxyStats = $proxyStmt->fetch(PDO::FETCH_ASSOC);

        $summary = [
            'total_clients' => $vpnStats['total_clients'],
            'total_upload' => ($vpnStats['vpn_upload'] ?? 0) + ($proxyStats['proxy_upload'] ?? 0),
            'total_download' => ($vpnStats['vpn_download'] ?? 0) + ($proxyStats['proxy_download'] ?? 0),
            'active_clients' => $vpnStats['active_clients']
        ];

        View::render('dashboard.twig', [
            'servers' => $servers,
            'summary' => $summary,
            'beszel_config' => [
                'url' => Config::get('BESZEL_URL')
            ]
        ]);
    }

    public function index()
    {
        requireAuth();
        $user = Auth::user();

        $servers = Auth::isAdmin()
            ? VpnServer::listAll()
            : VpnServer::listByUser($user['id']);

        View::render('servers/index.twig', ['servers' => $servers]);
    }

    public function create()
    {
        requireAuth();
        $randomPort = rand(30000, 65000);
        $allowedSubnets = [
            '10.8.1.0/24',
            '10.9.1.0/24',
            '10.10.1.0/24',
            '10.20.1.0/24',
            '10.30.1.0/24',
            '10.50.1.0/24',
            '10.100.1.0/24',
            '192.168.100.0/24',
            '192.168.150.0/24',
            '192.168.200.0/24',
        ];
        View::render('servers/create.twig', [
            'random_port' => $randomPort,
            'allowed_subnets' => $allowedSubnets
        ]);
    }

    public function store()
    {
        requireAuth();
        $user = Auth::user();

        $name = trim($_POST['name'] ?? '');
        $host = trim($_POST['host'] ?? '');
        $port = (int) ($_POST['port'] ?? 22);
        $username = trim($_POST['username'] ?? 'root');
        $password = $_POST['password'] ?? '';
        $vpnPort = !empty($_POST['vpn_port']) ? (int) $_POST['vpn_port'] : NULL;
        $vpnSubnet = trim($_POST['vpn_subnet'] ?? '10.8.1.0/24');
        $mimicryType = $_POST['mimicry_type'] ?? 'quic';

        $allowedSubnets = [
            '10.8.1.0/24',
            '10.9.1.0/24',
            '10.10.1.0/24',
            '10.20.1.0/24',
            '10.30.1.0/24',
            '10.50.1.0/24',
            '10.100.1.0/24',
            '192.168.100.0/24',
            '192.168.150.0/24',
            '192.168.200.0/24',
        ];

        if (!in_array($vpnSubnet, $allowedSubnets)) {
            $randomPort = $vpnPort ?: rand(30000, 65000);
            View::render('servers/create.twig', [
                'error' => 'Invalid VPN subnet selected',
                'random_port' => $randomPort,
                'allowed_subnets' => $allowedSubnets
            ]);
            return;
        }

        if (empty($name) || empty($host) || empty($password)) {
            $randomPort = $vpnPort ?: rand(30000, 65000);
            View::render('servers/create.twig', [
                'error' => 'All fields are required',
                'random_port' => $randomPort,
                'allowed_subnets' => $allowedSubnets
            ]);
            return;
        }

        try {
            $serverId = VpnServer::create([
                'user_id' => $user['id'],
                'name' => $name,
                'host' => $host,
                'port' => $port,
                'username' => $username,
                'password' => $password,
                'vpn_port' => $vpnPort,
                'vpn_subnet' => $vpnSubnet,
                'mimicry_type' => $mimicryType
            ]);

            redirect('/servers/' . $serverId . '/deploy');
        } catch (Exception $e) {
            $randomPort = $vpnPort ?: rand(30000, 65000);
            View::render('servers/create.twig', [
                'error' => $e->getMessage(),
                'random_port' => $randomPort,
                'allowed_subnets' => $allowedSubnets
            ]);
        }
    }

    public function delete($params)
    {
        requireAuth();
        $user = Auth::user();
        $serverId = (int) $params['id'];

        try {
            $server = new VpnServer($serverId);
            $serverData = $server->getData();

            if ($serverData['user_id'] != $user['id'] && !Auth::isAdmin()) {
                http_response_code(403);
                echo Translator::t('message.forbidden');
                return;
            }

            // Safety Check: Verify server name from POST (trim both for robustness)
            $confirmName = trim($_POST['confirm_name'] ?? '');
            $actualName  = trim($serverData['name']);
            
            if (empty($confirmName) || $confirmName !== $actualName) {
                return $this->respond(false, 'Deletion failed', 'Confirmation name did not match or was empty.');
            }

            unlockSession();
            $server->delete();
            relockSession();
            
            return $this->respond(true, 'Server deleted successfully', null, '/servers');
        } catch (Exception $e) {
            relockSession();
            return $this->respond(false, 'Delete failed', $e->getMessage());
        }
    }

    public function showDeploy($params)
    {
        requireAuth();
        $serverId = (int) $params['id'];

        try {
            $server = new VpnServer($serverId);
            $serverData = $server->getData();

            $user = Auth::user();
            if ($serverData['user_id'] != $user['id'] && !Auth::isAdmin()) {
                http_response_code(403);
                echo Translator::t('message.forbidden');
                return;
            }

            $user = Auth::user();
            $connToken = EventBus::generateConnectionToken((string)$user['id']);
            
            $subToken = '';
            if ($serverData['status'] === ServerStatus::DEPLOYING->value && !empty($serverData['current_job_id'])) {
                $subToken = EventBus::generateSubscriptionToken((string)$user['id'], "job:{$serverData['current_job_id']}");
            }

            View::render('servers/deploy.twig', [
                'server' => $serverData,
                'centrifugo_url' => getenv('CENTRIFUGO_WS_URL') ?: 'ws://localhost:8000/connection/websocket',
                'connection_token' => $connToken,
                'subscription_token' => $subToken
            ]);
        } catch (Exception $e) {
            http_response_code(404);
            echo Translator::t('servers.not_found');
        }
    }

    public function deploy($params)
    {
        requireAuth();
        header('Content-Type: application/json');

        $serverId = (int) $params['id'];
        ob_start();

        unlockSession();

        try {
            $server = new VpnServer($serverId);
            $serverData = $server->getData();

            $user = Auth::user();
            if ($serverData['user_id'] != $user['id'] && !Auth::isAdmin()) {
                http_response_code(403);
                echo json_encode(['error' => Translator::t('message.forbidden')]);
                return;
            }

            // Ensure Queue class is available
            if (!class_exists('Queue')) {
                require_once __DIR__ . '/../inc/Queue.php';
            }

            // Create Job for tracking with a snapshot of server metadata
            $job = Job::create((int)$user['id'], 'provision_server', $serverId, [
                'server_name' => $serverData['name'],
                'host' => $serverData['host'],
                'snapshot' => [
                    'os' => $serverData['os'] ?? 'linux',
                    'provider' => $serverData['provider'] ?? 'custom'
                ]
            ]);
            
            if (!$job) {
                throw new Exception("Failed to create orchestration job. Check database connectivity.");
            }

            // Set DB status to deploying and link current job
            $pdo = DB::conn();
            $pdo->prepare("UPDATE vpn_servers SET status = 'deploying', current_job_id = ? WHERE id = ?")->execute([$job->getId(), $serverId]);

            Queue::push('deployments', [
                'type' => 'provision_server',
                'server_id' => $serverId,
                'job_id' => $job->getId()
            ]);

            $jobId = $job->getId();
            $subToken = EventBus::generateSubscriptionToken((string)$user['id'], "job:{$jobId}");

            ob_get_clean();
            echo json_encode([
                'success' => true, 
                'message' => 'Deployment queued',
                'job_id' => $jobId,
                'subscription_token' => $subToken
            ]);
        } catch (Throwable $e) {
            $unexpectedOutput = trim((string) ob_get_clean());
            http_response_code(500);
            
            if (class_exists('Logger')) {
                Logger::error('Deploy queueing failed', ['error' => $e->getMessage()]);
            } else {
                \Logger::error('Deploy queueing failed: ' . $e->getMessage());
            }

            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
                'details' => $unexpectedOutput !== '' ? substr(strip_tags($unexpectedOutput), 0, 500) : null
            ]);
        }
    }

    public function diagnostics()
    {
        requireAuth();
        if (!Auth::isAdmin()) {
            http_response_code(403);
            echo "Forbidden";
            return;
        }

        View::render('servers/diagnostics.twig');
    }

    public function view($params)
    {
        requireAuth();
        $serverId = (int) $params['id'];

        try {
            $server = new VpnServer($serverId);
            $serverData = $server->getData();

            $user = Auth::user();
            if ($serverData['user_id'] != $user['id'] && !Auth::isAdmin()) {
                http_response_code(403);
                echo Translator::t('message.forbidden');
                return;
            }

            $clients = VpnClient::listByServer($serverId);

            $summary = $this->getServerSummaryStats($serverId, $clients);

            $user = Auth::user();
            $connToken = EventBus::generateConnectionToken((string)$user['id']);
            
            $subToken = '';
            if ($serverData['status'] === ServerStatus::DEPLOYING->value && !empty($serverData['current_job_id'])) {
                $subToken = EventBus::generateSubscriptionToken((string)$user['id'], "job:{$serverData['current_job_id']}");
            }

            View::render('servers/view.twig', [
                'server' => $serverData,
                'clients' => $clients,
                'stats_summary' => $summary,
                'beszel_config' => [
                    'url' => Config::get('BESZEL_URL')
                ],
                'centrifugo_url' => getenv('CENTRIFUGO_WS_URL') ?: 'ws://localhost:8000/connection/websocket',
                'connection_token' => $connToken,
                'subscription_token' => $subToken
            ]);
        } catch (Exception $e) {
            \Logger::error('Server view error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            http_response_code(404);
            echo 'Server not found: ' . htmlspecialchars($e->getMessage());
        }
    }

    public function syncStats($params)
    {
        requireAuth();
        $serverId = (int) $params['id'];

        header('Content-Type: application/json');

        unlockSession();

        try {
            $server = new VpnServer($serverId);
            $serverData = $server->getData();

            $user = Auth::user();
            if ($serverData['user_id'] != $user['id'] && !Auth::isAdmin()) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                return;
            }

            $server->updatePingAndStatus();
            $server->updateGeoIp();
            $synced = VpnClient::syncAllStatsForServer($serverId);

            // Sync proxy traffic as well if proxies are configured on this server
            $db = DB::conn();
            $pxCount = $db->prepare('SELECT COUNT(*) FROM http_proxies WHERE server_id = ? AND deleted_at IS NULL');
            $pxCount->execute([$serverId]);
            if ((int)$pxCount->fetchColumn() > 0) {
                require_once __DIR__ . '/../inc/ProxyServer.php';
                $proxy = new ProxyServer($serverId);
                $proxy->updateTrafficStats();
            }

            echo json_encode(['success' => true, 'synced' => $synced]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function toggleTelemetry($params)
    {
        requireAuth();
        $serverId = (int) $params['id'];

        header('Content-Type: application/json');

        unlockSession();

        try {
            $server = new VpnServer($serverId);
            $serverData = $server->getData();

            $user = Auth::user();
            if ($serverData['user_id'] != $user['id'] && !Auth::isAdmin()) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                return;
            }

            $currentMode = $serverData['telemetry_mode'] ?? 'ssh';
            $targetMode = ($currentMode === 'push') ? 'ssh' : 'push';

            $db = DB::conn();
            
            if ($targetMode === 'push') {
                // Perform setup magic!
                require_once __DIR__ . '/../inc/LinuxProvisioner.php';
                require_once __DIR__ . '/../inc/AwgConfigGenerator.php';
                require_once __DIR__ . '/../inc/VpnProvisioner.php';
                
                $provisioner = new VpnProvisioner(
                    new LinuxProvisioner($server->getSshClient(), $serverId),
                    new AwgConfigGenerator()
                );
                $provisioner->setServer($server);
                $provisioner->installTelemetryAgent();
                
                $message = 'Push telemetry successfully deployed and activated!';
            } else {
                // Switch back to legacy SSH
                $db->prepare("UPDATE vpn_servers SET telemetry_mode = 'ssh' WHERE id = ?")
                   ->execute([$serverId]);
                   
                // Safe remote agent uninstallation/cleanup
                try {
                    $server->executeCommand("systemctl disable --now nk-telemetry.service && rm -f /etc/systemd/system/nk-telemetry.service /usr/local/bin/nk-telemetry-agent.py && systemctl daemon-reload", true, true);
                } catch (\Throwable $th) {
                    // Ignore transient SSH errors during removal, legacy database mode is successfully locked in
                }
                
                $message = 'Successfully reverted to legacy SSH telemetry mode.';
            }

            echo json_encode([
                'success' => true, 
                'mode' => $targetMode,
                'message' => $message
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function syncAll()
    {
        requireAuth();
        $user = Auth::user();

        try {
            // Push to queue for async processing
            require_once __DIR__ . '/../inc/Queue.php';
            
            // Optional: Cooldown check to prevent spamming
            $lastSync = $_SESSION['last_sync_all'] ?? 0;
            if (time() - $lastSync < 60) {
                throw new Exception("Please wait 60 seconds between global syncs.");
            }
            $_SESSION['last_sync_all'] = time();

            Queue::push('deployments', [
                'type' => 'sync_all_servers',
                'requested_by' => $user['id']
            ]);

            if (isJsonRequest() || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest')) {
                echo json_encode(['success' => true, 'message' => 'Synchronization started in background']);
                exit;
            }

            $_SESSION['flash_success'] = 'Synchronization started in background.';
            header('Location: /servers');
            exit;

        } catch (Exception $e) {
            if (isJsonRequest() || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest')) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            } else {
                $_SESSION['flash_error'] = "Sync failed: " . $e->getMessage();
                header('Location: /servers');
            }
        }
    }

    public function searchClients()
    {
        try {
            requireAuth();
            header('Content-Type: application/json');

            $user = Auth::user();
            $query = trim($_GET['q'] ?? '');
            $serverId = (int) ($_GET['server_id'] ?? 0);
            $statusFilter = $_GET['status'] ?? 'all';
            $trafficFilter = $_GET['traffic'] ?? 'all';
            $sortBy = $_GET['sort'] ?? 'recent';
            $pdo = DB::conn();

            $where = Auth::isAdmin() ? "c.deleted_at IS NULL" : "c.user_id = " . (int) $user['id'] . " AND c.deleted_at IS NULL";
            $where .= " AND s.deleted_at IS NULL"; // Hide clients of deleted servers
            
            if ($serverId > 0) {
                $where .= " AND c.server_id = :sid";
            }

            // Apply Status Filter
            if ($statusFilter === 'online') {
                $where .= " AND c.last_handshake > DATE_SUB(NOW(), INTERVAL 5 MINUTE) AND c.status = '" . ClientStatus::ACTIVE->value . "'";
            } elseif ($statusFilter === 'revoked') {
                $where .= " AND c.status = '" . ClientStatus::DISABLED->value . "'";
            } elseif ($statusFilter === 'offline') {
                $where .= " AND (c.last_handshake IS NULL OR c.last_handshake <= DATE_SUB(NOW(), INTERVAL 5 MINUTE)) AND c.status = '" . ClientStatus::ACTIVE->value . "'";
            }

            // Apply Traffic Filter
            if ($trafficFilter === 'high') {
                $where .= " AND (c.bytes_sent + c.bytes_received) > 1073741824";
            } elseif ($trafficFilter === 'medium') {
                $where .= " AND (c.bytes_sent + c.bytes_received) > 104857600";
            }

            $params = [];
            if ($serverId > 0) {
                $params['sid'] = $serverId;
            }

            $sql = "
                SELECT c.*, s.name as server_name, s.host as server_host, s.warp_status as server_warp_status
                FROM vpn_clients c
                LEFT JOIN vpn_servers s ON c.server_id = s.id
                WHERE ($where)
            ";

            if ($query !== '') {
                $sql .= " AND (c.name LIKE :q1 OR c.external_ip LIKE :q2 OR s.name LIKE :q3)";
                $params['q1'] = "%$query%";
                $params['q2'] = "%$query%";
                $params['q3'] = "%$query%";
            }

            $orderBy = "c.created_at DESC";
            if ($sortBy === 'traffic') {
                $orderBy = "(COALESCE(c.bytes_sent,0) + COALESCE(c.bytes_received,0)) DESC";
            } elseif ($sortBy === 'handshake') {
                $orderBy = "c.last_handshake DESC";
            } elseif ($sortBy === 'oldest') {
                $orderBy = "c.created_at ASC";
            }
            $sql .= " ORDER BY $orderBy LIMIT 500";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $truncated = count($results) === 500;

            // Format for JSON
            foreach ($results as &$r) {
                $bytes = (float) ($r['bytes_sent'] ?? 0) + (float) ($r['bytes_received'] ?? 0);
                if ($bytes > 1073741824) {
                    $r['total_traffic'] = number_format($bytes / 1073741824, 2) . ' GB';
                } else {
                    $r['total_traffic'] = number_format($bytes / 1048576, 2) . ' MB';
                }

                $r['db_status'] = $r['status']; // Active, Disabled, etc.
                
                $r['speed_up'] = VpnClient::formatSpeed((float)($r['speed_up_kbps'] ?? 0));
                $r['speed_down'] = VpnClient::formatSpeed((float)($r['speed_down_kbps'] ?? 0));

                $r['connection_status'] = 'offline';
                if (!empty($r['last_handshake'])) {
                    $lastHandshake = strtotime($r['last_handshake']);
                    $diff = time() - $lastHandshake;
                    if ($diff < 300) {
                        $r['connection_status'] = 'online';
                    }
                    if ($diff < 60)
                        $r['last_seen'] = 'Just now';
                    elseif ($diff < 3600)
                        $r['last_seen'] = floor($diff / 60) . 'm ago';
                    elseif ($diff < 86400)
                        $r['last_seen'] = floor($diff / 3600) . 'h ago';
                    else
                        $r['last_seen'] = floor($diff / 86400) . 'd ago';
                } else {
                    $r['connection_status'] = 'never';
                    $r['last_seen'] = 'Never';
                }
                $r['flag'] = View::getFlag($r['ip_country_code'] ?? '');

                // Effective routing mode logic
                $r['effective_routing'] = 'direct';
                if (($r['routing_mode'] ?? 'direct') === 'warp') {
                    if (($r['server_warp_status'] ?? 'not_installed') === 'connected') {
                        $r['effective_routing'] = 'warp';
                    } else {
                        $r['effective_routing'] = 'fallback';
                    }
                }
            }

            $response = ['results' => $results, 'truncated' => $truncated ?? false];
            
            // Return summary stats for AJAX update
            if ($serverId > 0) {
                $response['summary'] = $this->getServerSummaryStats($serverId, $results);
            } else {
                // Global summary for dashboard
                $pdo = DB::conn();
                $vpnStmt = $pdo->query("SELECT COUNT(CASE WHEN deleted_at IS NULL THEN 1 END) as total_clients, SUM(bytes_sent) as vpn_upload, SUM(bytes_received) as vpn_download, SUM(CASE WHEN last_handshake > DATE_SUB(NOW(), INTERVAL 5 MINUTE) AND deleted_at IS NULL THEN 1 ELSE 0 END) as active_clients FROM vpn_clients");
                $vpnStats = $vpnStmt->fetch(PDO::FETCH_ASSOC);

                $proxyStmt = $pdo->query("SELECT SUM(bytes_sent) as proxy_upload, SUM(bytes_received) as proxy_download FROM http_proxies");
                $proxyStats = $proxyStmt->fetch(PDO::FETCH_ASSOC);

                $response['summary'] = [
                    'active_clients' => (int)($vpnStats['active_clients'] ?? 0),
                    'total_clients' => (int)($vpnStats['total_clients'] ?? 0),
                    'traffic' => [
                        'sent' => (float)($vpnStats['vpn_upload'] ?? 0) + (float)($proxyStats['proxy_upload'] ?? 0),
                        'received' => (float)($vpnStats['vpn_download'] ?? 0) + (float)($proxyStats['proxy_download'] ?? 0),
                    ]
                ];
            }

            echo json_encode($response);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage(), 'results' => []]);
        }
    }

    /**
     * GET /servers/{id}/status
     * Ultra-fast single-column query: returns only {status} for smart polling.
     */
    public function getStatus($params)
    {
        requireAuth();
        header('Content-Type: application/json');

        $serverId = (int) $params['id'];

        // Release session lock immediately — this endpoint is polled every 2.5s
        // and must not block concurrent browser tabs waiting for the same lock.
        $user = Auth::user();
        unlockSession();

        try {
            $pdo  = DB::conn();

            $stmt = $pdo->prepare("SELECT status, user_id FROM vpn_servers WHERE id = ? LIMIT 1");
            $stmt->execute([$serverId]);
            $row  = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                http_response_code(404);
                echo json_encode(['error' => 'Server not found']);
                return;
            }

            if ($row['user_id'] != $user['id'] && !Auth::isAdmin()) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                return;
            }

            echo json_encode(['status' => $row['status']]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * GET /api/servers/health-batch
     * Returns status and basic metrics for all active servers.
     */
    public function getHealthBatch()
    {
        requireAuth();
        header('Content-Type: application/json');

        $user = Auth::user();
        unlockSession();

        try {
            $pdo = DB::conn();
            $sql = Auth::isAdmin() 
                ? "SELECT id, status FROM vpn_servers WHERE deleted_at IS NULL"
                : "SELECT id, status FROM vpn_servers WHERE deleted_at IS NULL AND user_id = ?";
            
            $stmt = $pdo->prepare($sql);
            Auth::isAdmin() ? $stmt->execute() : $stmt->execute([$user['id']]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $results = [];
            foreach ($rows as $row) {
                $results[$row['id']] = $row['status'];
            }

            echo json_encode(['servers' => $results]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * GET /servers/{id}/logs
     * Reads the latest rotated Monolog JSON log files (ssh + deployments) and
     * returns only entries whose context contains server_id == $serverId.
     * Sensitive fields (password, private_key) are stripped before output.
     */
    public function getLogs($params)
    {
        requireAuth();
        header('Content-Type: application/json');

        $serverId = (int) $params['id'];

        // Release session lock immediately — log reading can be slow on large files.
        $user = Auth::user();
        unlockSession();

        try {
            $pdo  = DB::conn();

            // Ownership check (only user_id needed, cheap query)
            $stmt = $pdo->prepare("SELECT user_id FROM vpn_servers WHERE id = ? LIMIT 1");
            $stmt->execute([$serverId]);
            $row  = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                http_response_code(404);
                echo json_encode(['error' => 'Server not found']);
                return;
            }

            if ($row['user_id'] != $user['id'] && !Auth::isAdmin()) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                return;
            }

            $logDir   = '/var/log/nk-panel';
            $channels = ['deployments', 'ssh'];
            $entries  = [];

            // Sensitive context keys to scrub before sending to browser
            $sensitiveKeys = ['password', 'passwd', 'private_key', 'secret', 'token'];

            foreach ($channels as $channel) {
                // Monolog RotatingFileHandler rotates as: {base}-YYYY-MM-DD.log
                // e.g. ssh.log → ssh-2026-05-06.log (dash before date, same extension)
                // The current active file keeps the plain name: ssh.log
                $pattern = $logDir . '/' . preg_replace('/\.log$/', '', $channel . '.log') . '-*.log';
                $files   = glob($pattern) ?: []; // glob() returns false on error; coerce to array

                // The plain file is the current day's active write target — always include it
                $plain = $logDir . '/' . $channel . '.log';
                if (is_file($plain)) {
                    $files[] = $plain;
                }

                if (empty($files)) {
                    continue;
                }

                // Read all files for this channel (current + rotated) to find relevant entries
                foreach ($files as $file) {
                    if (!is_readable($file)) {
                        continue;
                    }

                    $handle = fopen($file, 'r');
                    if (!$handle) {
                        continue;
                    }

                    while (($line = fgets($handle)) !== false) {
                        $line = trim($line);
                        if ($line === '') {
                            continue;
                        }

                        $decoded = json_decode($line, true);
                        if (!is_array($decoded)) {
                            continue;
                        }

                        // Filter by server_id in context
                        $ctx = $decoded['context'] ?? [];
                        $ctxServerId = (int) ($ctx['server_id'] ?? -1);

                        if ($ctxServerId !== $serverId) {
                            continue;
                        }

                        // Scrub sensitive context fields
                        foreach ($sensitiveKeys as $sk) {
                            if (isset($ctx[$sk])) {
                                $ctx[$sk] = '[REDACTED]';
                            }
                        }
                        $decoded['context'] = $ctx;
                        $decoded['channel_source'] = $channel;

                        $entries[] = $decoded;
                    }

                    fclose($handle);
                }
            }

            // Sort all entries by datetime ascending
            usort($entries, fn($a, $b) => strcmp($a['datetime'] ?? '', $b['datetime'] ?? ''));

            // Cap output to last 500 entries to avoid huge payloads
            if (count($entries) > 500) {
                $entries = array_slice($entries, -500);
            }

            echo json_encode(['logs' => $entries, 'server_id' => $serverId]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Helper to calculate summary stats for a server
     */
    private function getServerSummaryStats(int $serverId, array $clients): array
    {
        $onlineClients = 0;
        
        foreach ($clients as $c) {
            if (!empty($c['last_handshake'])) {
                $lastHandshake = is_string($c['last_handshake']) ? strtotime($c['last_handshake']) : $c['last_handshake'];
                $diff = time() - $lastHandshake;
                if ($diff < 300) $onlineClients++;
            }
        }

        $pdo = DB::conn();
        $trafficStmt = $pdo->prepare("SELECT SUM(bytes_sent) as sent, SUM(bytes_received) as received FROM vpn_clients WHERE server_id = ?");
        $trafficStmt->execute([$serverId]);
        $vpnTraffic = $trafficStmt->fetch();

        $proxyTrafficStmt = $pdo->prepare("SELECT SUM(bytes_sent) as sent, SUM(bytes_received) as received FROM http_proxies WHERE server_id = ?");
        $proxyTrafficStmt->execute([$serverId]);
        $proxyTraffic = $proxyTrafficStmt->fetch();
        
        $totalSent = (float)($vpnTraffic['sent'] ?? 0) + (float)($proxyTraffic['sent'] ?? 0);
        $totalReceived = (float)($vpnTraffic['received'] ?? 0) + (float)($proxyTraffic['received'] ?? 0);

        return [
            'total' => count($clients),
            'online' => $onlineClients,
            'traffic' => [
                'sent' => $totalSent,
                'received' => $totalReceived,
                'total' => $totalSent + $totalReceived
            ]
        ];
    }

    /**
     * GET /api/monitoring/traffic-history
     */
    public function getTrafficHistory()
    {
        requireAuth();
        header('Content-Type: application/json');
        
        try {
            $minutes = isset($_GET['minutes']) ? (int)$_GET['minutes'] : 30;
            $history = \ServerMonitoring::getGlobalTrafficHistory($minutes);
            echo json_encode(['history' => $history]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function installWarp($params)
    {
        requireAuth();
        header('Content-Type: application/json');
        $serverId = (int) $params['id'];

        unlockSession();

        try {
            $server = new VpnServer($serverId);
            $serverData = $server->getData();

            $user = Auth::user();
            if ($serverData['user_id'] != $user['id'] && !Auth::isAdmin()) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                return;
            }

            $status = $serverData['warp_status'] ?? 'not_installed';
            if ($status === 'installing' || $status === 'initializing') {
                throw new Exception("Another operation is already in progress on this server. Please wait.");
            }
            if ($status === 'connected' || $status === 'degraded' || $status === 'error' || $status === 'installed') {
                throw new Exception("WARP is already installed. Use Reinstall or Repair instead.");
            }

            if (!class_exists('Queue')) {
                require_once __DIR__ . '/../inc/Queue.php';
            }

            $job = Job::create((int)$user['id'], 'warp_install', $serverId, [
                'server_name' => $serverData['name'],
                'host' => $serverData['host']
            ]);

            if (!$job) {
                throw new Exception("Failed to create orchestration job.");
            }

            $pdo = DB::conn();
            $pdo->prepare("UPDATE vpn_servers SET warp_status = 'installing', current_job_id = ? WHERE id = ?")
                ->execute([$job->getId(), $serverId]);

            Queue::push('deployments', [
                'type' => 'warp_install',
                'server_id' => $serverId,
                'job_id' => $job->getId()
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Cloudflare WARP installation queued',
                'job_id' => $job->getId(),
                'subscription_token' => EventBus::generateSubscriptionToken((string)$user['id'], "job:{$job->getId()}")
            ]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function uninstallWarp($params)
    {
        requireAuth();
        header('Content-Type: application/json');
        $serverId = (int) $params['id'];

        unlockSession();

        try {
            $server = new VpnServer($serverId);
            $serverData = $server->getData();

            $user = Auth::user();
            if ($serverData['user_id'] != $user['id'] && !Auth::isAdmin()) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                return;
            }

            $status = $serverData['warp_status'] ?? 'not_installed';
            if ($status === 'installing' || $status === 'initializing') {
                throw new Exception("Another operation is already in progress on this server. Please wait.");
            }
            if ($status === 'not_installed') {
                throw new Exception("WARP is not installed on this server.");
            }

            if (!class_exists('Queue')) {
                require_once __DIR__ . '/../inc/Queue.php';
            }

            $job = Job::create((int)$user['id'], 'warp_uninstall', $serverId, [
                'server_name' => $serverData['name'],
                'host' => $serverData['host']
            ]);

            if (!$job) {
                throw new Exception("Failed to create orchestration job.");
            }

            $pdo = DB::conn();
            $pdo->prepare("UPDATE vpn_servers SET warp_status = 'initializing', current_job_id = ? WHERE id = ?")
                ->execute([$job->getId(), $serverId]);

            Queue::push('deployments', [
                'type' => 'warp_uninstall',
                'server_id' => $serverId,
                'job_id' => $job->getId()
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Cloudflare WARP uninstallation queued',
                'job_id' => $job->getId(),
                'subscription_token' => EventBus::generateSubscriptionToken((string)$user['id'], "job:{$job->getId()}")
            ]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function repairWarp($params)
    {
        requireAuth();
        header('Content-Type: application/json');
        $serverId = (int) $params['id'];

        unlockSession();

        try {
            $server = new VpnServer($serverId);
            $serverData = $server->getData();

            $user = Auth::user();
            if ($serverData['user_id'] != $user['id'] && !Auth::isAdmin()) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                return;
            }

            $status = $serverData['warp_status'] ?? 'not_installed';
            if ($status === 'installing' || $status === 'initializing') {
                throw new Exception("Another operation is already in progress on this server. Please wait.");
            }
            if ($status === 'not_installed') {
                throw new Exception("WARP is not installed. Please install it first.");
            }

            if (!class_exists('Queue')) {
                require_once __DIR__ . '/../inc/Queue.php';
            }

            $job = Job::create((int)$user['id'], 'warp_repair', $serverId, [
                'server_name' => $serverData['name'],
                'host' => $serverData['host']
            ]);

            if (!$job) {
                throw new Exception("Failed to create orchestration job.");
            }

            $pdo = DB::conn();
            $pdo->prepare("UPDATE vpn_servers SET warp_status = 'initializing', current_job_id = ? WHERE id = ?")
                ->execute([$job->getId(), $serverId]);

            Queue::push('deployments', [
                'type' => 'warp_repair',
                'server_id' => $serverId,
                'job_id' => $job->getId()
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Cloudflare WARP repair queued',
                'job_id' => $job->getId(),
                'subscription_token' => EventBus::generateSubscriptionToken((string)$user['id'], "job:{$job->getId()}")
            ]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function reinstallWarp($params)
    {
        requireAuth();
        header('Content-Type: application/json');
        $serverId = (int) $params['id'];

        unlockSession();

        try {
            $server = new VpnServer($serverId);
            $serverData = $server->getData();

            $user = Auth::user();
            if ($serverData['user_id'] != $user['id'] && !Auth::isAdmin()) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                return;
            }

            $status = $serverData['warp_status'] ?? 'not_installed';
            if ($status === 'installing' || $status === 'initializing') {
                throw new Exception("Another operation is already in progress on this server. Please wait.");
            }
            if ($status === 'not_installed') {
                throw new Exception("WARP is not installed. Please install it first.");
            }

            if (!class_exists('Queue')) {
                require_once __DIR__ . '/../inc/Queue.php';
            }

            $job = Job::create((int)$user['id'], 'warp_reinstall', $serverId, [
                'server_name' => $serverData['name'],
                'host' => $serverData['host']
            ]);

            if (!$job) {
                throw new Exception("Failed to create orchestration job.");
            }

            $pdo = DB::conn();
            $pdo->prepare("UPDATE vpn_servers SET warp_status = 'installing', current_job_id = ? WHERE id = ?")
                ->execute([$job->getId(), $serverId]);

            Queue::push('deployments', [
                'type' => 'warp_reinstall',
                'server_id' => $serverId,
                'job_id' => $job->getId()
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Cloudflare WARP reinstallation queued',
                'job_id' => $job->getId(),
                'subscription_token' => EventBus::generateSubscriptionToken((string)$user['id'], "job:{$job->getId()}")
            ]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function runWarpHealthCheck($params)
    {
        requireAuth();
        header('Content-Type: application/json');
        $serverId = (int) $params['id'];

        unlockSession();

        try {
            $server = new VpnServer($serverId);
            $serverData = $server->getData();

            $user = Auth::user();
            if ($serverData['user_id'] != $user['id'] && !Auth::isAdmin()) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                return;
            }

            $status = $serverData['warp_status'] ?? 'not_installed';
            if ($status === 'installing' || $status === 'initializing') {
                throw new Exception("Operation in progress. Cannot run health check.");
            }
            if ($status === 'not_installed') {
                throw new Exception("WARP is not installed on this server.");
            }

            require_once __DIR__ . '/../inc/LinuxProvisioner.php';
            $linux = new LinuxProvisioner($server->getSshClient(), $serverId);
            
            $diagnostics = $linux->runWarpDiagnostics();
            
            $status = $diagnostics['status'] ?? 'error';
            if (!in_array($status, ['connected', 'degraded', 'error'], true)) {
                $status = 'error';
            }

            $db = DB::conn();
            $db->prepare("
                UPDATE vpn_servers 
                SET warp_status = ?,
                    warp_connected = ?,
                    warp_cloudflare_ip = ?,
                    warp_last_check_status = ?,
                    warp_last_check_at = NOW(),
                    warp_last_repair_at = CASE WHEN ? IS NOT NULL THEN NOW() ELSE warp_last_repair_at END,
                    warp_last_repair_result = CASE WHEN ? IS NOT NULL THEN ? ELSE warp_last_repair_result END
                WHERE id = ?
            ")->execute([
                $status,
                ($status === 'connected' ? 1 : 0),
                $diagnostics['cloudflare_ip'] ?? null,
                $diagnostics['last_check_status'] ?? 'Completed',
                !empty($diagnostics['last_repair_at']) ? $diagnostics['last_repair_at'] : null,
                !empty($diagnostics['last_repair_result']) ? $diagnostics['last_repair_result'] : null,
                !empty($diagnostics['last_repair_result']) ? $diagnostics['last_repair_result'] : null,
                $serverId
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'WARP health check completed',
                'warp_status' => $status,
                'cloudflare_ip' => $diagnostics['cloudflare_ip'] ?? null,
                'last_check_status' => $diagnostics['last_check_status'] ?? 'Completed'
            ]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }
}
