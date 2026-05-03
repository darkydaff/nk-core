<?php
declare(strict_types=1);


class SettingsController {
    
    private function respond($success, $message, $error = null) {
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') || 
                  (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => $success,
                'message' => $message,
                'error' => $error
            ]);
            exit;
        }

        if ($success) {
            $_SESSION['settings_success'] = $message;
        } else {
            $_SESSION['settings_error'] = $error ?: $message;
        }
        
        $tab = 'profile';
        if (strpos($_SERVER['REQUEST_URI'], 'backup') !== false || strpos($_SERVER['REQUEST_URI'], 'restore') !== false) $tab = 'backups';
        if (strpos($_SERVER['REQUEST_URI'], 'telegram') !== false) $tab = 'telegram';
        if (strpos($_SERVER['REQUEST_URI'], 'beszel') !== false) $tab = 'beszel';
        
        header('Location: /settings#' . $tab);
        exit;
    }
    private $pdo;
    private $translator;
    
    public function __construct() {
        $this->pdo = DB::conn();
        $this->translator = new Translator();
    }
    
    public function index() {
        $stats = $this->getTranslationStats();
        $users = $this->getAllUsers();
        $apiKey = $this->getApiKey('openrouter');
        
        $s3Endpoint = Config::get('S3_ENDPOINT', '');
        $s3Key = Config::get('S3_KEY', '');
        $s3Bucket = Config::get('S3_BUCKET', '');
        
        $s3Configured = !empty($s3Endpoint) && !empty($s3Key) && !empty($s3Bucket);
        $s3Provider = 'S3 Storage';
        
        if ($s3Endpoint) {
            $host = parse_url($s3Endpoint, PHP_URL_HOST) ?: $s3Endpoint;
            if ($host) {
                if (str_contains($host, 'hostkey.com')) {
                    $s3Provider = 'Hostkey S3';
                } elseif (str_contains($host, 'digitaloceanspaces.com')) {
                    $s3Provider = 'DigitalOcean Spaces';
                } elseif (str_contains($host, 'amazonaws.com')) {
                    $s3Provider = 'AWS S3';
                } elseif (str_contains($host, 'backblazeb2.com')) {
                    $s3Provider = 'Backblaze B2';
                } else {
                    $parts = explode('.', $host);
                    if (count($parts) >= 2) {
                        $s3Provider = ucfirst($parts[count($parts)-2]) . ' S3';
                    } else {
                        $s3Provider = ucfirst($host) . ' S3';
                    }
                }
            }
        }

        $data = [
            'translation_stats' => $stats,
            'users' => $users,
            'openrouter_key' => $apiKey,
            's3_bucket' => $s3Bucket ?: 'Not configured',
            's3_provider' => $s3Provider,
            's3_configured' => $s3Configured,
            'cloud_backups' => $this->getCloudBackups(),
            'local_backups' => $this->getLocalBackups(),
            'tg_config' => $this->getTelegramConfig(),
            'beszel_config' => $this->getBeszelConfig(),
            'backup_auto_enabled' => Config::get('BACKUP_AUTO_ENABLED', 'false'),
            'backup_schedule_time' => Config::get('BACKUP_SCHEDULE_TIME', '00:00'),
            'backup_last_run' => Config::get('BACKUP_LAST_RUN'),
            'backup_last_status' => Config::get('BACKUP_LAST_STATUS'),
            'server_time' => date('H:i:s')
        ];
        
        // Check for session messages
        if (isset($_SESSION['settings_success'])) {
            $data['success'] = $_SESSION['settings_success'];
            unset($_SESSION['settings_success']);
        }
        if (isset($_SESSION['settings_error'])) {
            $data['error'] = $_SESSION['settings_error'];
            unset($_SESSION['settings_error']);
        }
        
        View::render('settings.twig', $data);
    }
    
    public function changePassword() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->respond(false, "Invalid request method.");
        }
        
        $user = Auth::user();
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            return $this->respond(false, $this->translator->translate('form.all_fields_required'));
        }
        
        if ($newPassword !== $confirmPassword) {
            return $this->respond(false, $this->translator->translate('settings.passwords_no_match'));
        }
        
        if (strlen($newPassword) < 6) {
            return $this->respond(false, $this->translator->translate('auth.password_min_length'));
        }
        
        // Verify current password
        if (!password_verify($currentPassword, $user['password_hash'])) {
            return $this->respond(false, $this->translator->translate('settings.current_password_invalid'));
        }
        
        // Update password
        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $this->pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->execute([$newHash, $user['id']]);
        
        return $this->respond(true, $this->translator->translate('settings.password_changed'));
    }
    
    
    public function deleteUser($params) {
        $id = (int)($params['id'] ?? 0);
        $currentUserId = $_SESSION['user_id'] ?? 0;

        if ($id == $currentUserId) {
            return $this->respond(false, "You cannot delete your own account.");
        }

        $stmt = $this->pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);

        return $this->respond(true, "User deleted successfully.");
    }

    public function createUser() {
        $username = trim($_POST['new_username'] ?? '');
        $password = $_POST['new_password'] ?? '';

        if (empty($username) || empty($password)) {
            return $this->respond(false, "Username and password are required.");
        }

        // Check if user exists
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            return $this->respond(false, "Username already exists.");
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        // Using email and name columns
        $stmt = $this->pdo->prepare("INSERT INTO users (email, name, password_hash, role, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$username, $username, $hash, UserRole::ADMIN->value, ClientStatus::ACTIVE->value]);

        return $this->respond(true, "New Global Admin '$username' created successfully.");
    }
    
    private function getAllUsers() {
        $stmt = $this->pdo->query("SELECT id, name, email, role, created_at FROM users ORDER BY created_at DESC");
        return $stmt->fetchAll();
    }
    
    private function getApiKey($service) {
        $stmt = $this->pdo->prepare("SELECT api_key FROM api_keys WHERE service_name = ? AND is_active = 1");
        $stmt->execute([$service]);
        $result = $stmt->fetch();
        return $result ? $result['api_key'] : null;
    }
    
    public function saveApiKey() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->respond(false, "Invalid request method.");
        }
        
        $service = $_POST['service'] ?? '';
        $apiKey = trim($_POST['api_key'] ?? '');
        $skipTest = isset($_POST['skip_test']); // Allow saving without testing
        
        if (empty($service) || empty($apiKey)) {
            return $this->respond(false, $this->translator->translate('settings.error_empty_key'));
        }
        
        // Test the API key (unless skip_test is set)
        if ($service === 'openrouter' && !$skipTest) {
            $testResult = $this->testOpenRouterKey($apiKey);
            if (!$testResult['success']) {
                $errorMsg = $this->translator->translate('settings.error_key_test') . ': ' . $testResult['error'];
                if (strpos($testResult['error'], '429') !== false || strpos($testResult['error'], 'Rate limit') !== false) {
                    $errorMsg .= ' - ' . $this->translator->translate('settings.skip_validation_hint');
                }
                return $this->respond(false, $errorMsg);
            }
        }
        
        // Save the key
        $saved = $this->translator->saveApiKey($service, $apiKey);
        
        if ($saved) {
            return $this->respond(true, $this->translator->translate('settings.key_saved'));
        } else {
            return $this->respond(false, $this->translator->translate('message.error'));
        }
    }
    
    private function testOpenRouterKey($apiKey) {
        // Test with a simple request to check API key validity
        $url = 'https://openrouter.ai/api/v1/chat/completions';
        $data = [
            'model' => 'openai/gpt-4o-mini',
            'messages' => [
                ['role' => 'user', 'content' => 'Reply with: OK']
            ],
            'max_tokens' => 5
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'HTTP-Referer: https://amnez.ia',
            'X-Title: Amnezia VPN Panel'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        // Handle cURL errors
        if ($curlError) {
            return [
                'success' => false,
                'error' => 'Network error: ' . $curlError
            ];
        }
        
        // Parse response
        $result = json_decode($response, true);
        
        // Success - got a valid response
        if ($httpCode === 200 && isset($result['choices'][0]['message'])) {
            return ['success' => true];
        }
        
        // Extract error message from various formats
        $errorMsg = 'Unknown error';
        
        if (isset($result['error'])) {
            if (is_string($result['error'])) {
                $errorMsg = $result['error'];
            } elseif (isset($result['error']['message'])) {
                $errorMsg = $result['error']['message'];
            } elseif (isset($result['error']['code'])) {
                $errorMsg = 'Error code: ' . $result['error']['code'];
            }
        }
        
        // Add HTTP code if not 200
        if ($httpCode !== 200) {
            $errorMsg .= ' (HTTP ' . $httpCode . ')';
        }
        
        // Common error messages user-friendly translations
        if (strpos($errorMsg, 'No auth credentials') !== false || $httpCode === 401) {
            $errorMsg = 'Invalid API key or authentication failed';
        } elseif (strpos($errorMsg, 'insufficient_quota') !== false || strpos($errorMsg, 'quota') !== false) {
            $errorMsg = 'API quota exceeded or no credits available';
        } elseif (strpos($errorMsg, 'rate_limit') !== false) {
            $errorMsg = 'Rate limit exceeded, try again later';
        }
        
        return [
            'success' => false,
            'error' => $errorMsg
        ];
    }
    
    
    public function runBackup() {
        require_once __DIR__ . '/../inc/BackupManager.php';
        
        unlockSession();
        
        try {
            $result = BackupManager::createBackup();
            $msg = "Snapshot created successfully: " . $result['filename'];
            relockSession();
            
            $status = $msg;
            if (!$result['s3_status']) {
                $status .= " (⚠️ Local only, S3 failed: " . $result['s3_error'] . ")";
                return $this->respond(true, $status);
            } else {
                $status .= " (✅ Local + S3 Cloud)";
                return $this->respond(true, $status);
            }
        } catch (Exception $e) {
            relockSession();
            return $this->respond(false, "Backup failed", $e->getMessage());
        }
    }

    public function restoreBackup() {
        require_once __DIR__ . '/../inc/BackupManager.php';
        
        $key = $_POST['key'] ?? '';
        $type = $_POST['type'] ?? 'cloud'; // 'cloud' or 'local'
        $password = $_POST['confirm_password'] ?? '';
        $user = Auth::user();

        if (empty($key) || empty($password)) {
            return $this->respond(false, "Missing backup data or password confirmation.");
        }

        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            return $this->respond(false, "Invalid password. Restore aborted.");
        }

        unlockSession();

        try {
            if ($type === 'local') {
                BackupManager::restoreFromLocal($key);
            } else {
                BackupManager::restoreFromCloud($key);
            }

            // Sync proxies after restore to ensure remote state matches DB
            require_once __DIR__ . '/../inc/ProxyServer.php';
            ProxyServer::syncAllServers();

            // Sync VPN servers after restore
            require_once __DIR__ . '/../inc/VpnServer.php';
            VpnServer::syncAllServers();

            relockSession();
            return $this->respond(true, "Database restored successfully from " . $type . ": " . basename($key) . " (All synced)");
        } catch (Exception $e) {
            relockSession();
            return $this->respond(false, "Restore failed", $e->getMessage());
        }
    }

    public function uploadRestore() {
        require_once __DIR__ . '/../inc/BackupManager.php';
        
        $password = $_POST['confirm_password'] ?? '';
        $file = $_FILES['backup_file'] ?? null;
        $user = Auth::user();

        if (empty($password) || !$file || $file['error'] !== UPLOAD_ERR_OK) {
            return $this->respond(false, "Missing file or password confirmation.");
        }

        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            return $this->respond(false, "Invalid password. Upload restore aborted.");
        }

        // Verify extension
        $filename = $file['name'];
        if (!str_ends_with($filename, '.sql.gz') && !str_ends_with($filename, '.tar.gz')) {
            return $this->respond(false, "Invalid file type. Only .sql.gz and .tar.gz files are allowed.");
        }

        unlockSession();

        try {
            // Move to a safe location before import
            $backupDir = __DIR__ . '/../storage/backups/tmp';
            if (!is_dir($backupDir)) {
                @mkdir($backupDir, 0755, true);
            }
            if (!is_dir($backupDir)) {
                throw new Exception("Failed to create temporary backup directory: $backupDir");
            }
            
            $tmpPath = $backupDir . '/uploaded_restore_' . time() . '.sql.gz';
            if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
                throw new Exception("Failed to move uploaded file to temporary storage.");
            }

            // Execute import via BackupManager helper
            BackupManager::restoreFromUpload($tmpPath);

            // Sync proxies after restore to ensure remote state matches DB
            require_once __DIR__ . '/../inc/ProxyServer.php';
            ProxyServer::syncAllServers();

            // Sync VPN servers after restore
            require_once __DIR__ . '/../inc/VpnServer.php';
            VpnServer::syncAllServers();
            
            relockSession();
            return $this->respond(true, "Database restored successfully from uploaded file: " . $file['name'] . " (All synced)");
        } catch (Exception $e) {
            relockSession();
            return $this->respond(false, "Upload restore failed", $e->getMessage());
        }
    }

    public function deleteBackup() {
        require_once __DIR__ . '/../inc/BackupManager.php';
        
        $key = $_POST['key'] ?? '';
        $type = $_POST['type'] ?? 'local'; // 'local' or 'cloud'

        if (empty($key)) {
            return $this->respond(false, "Missing backup identifier.");
        }

        try {
            if ($type === 'local') {
                $success = BackupManager::deleteLocalBackup($key);
            } else {
                $success = BackupManager::deleteCloudBackup($key);
            }

            if ($success) {
                return $this->respond(true, "Backup deleted successfully.");
            } else {
                return $this->respond(false, "Failed to delete backup.");
            }
        } catch (Exception $e) {
            return $this->respond(false, "Error deleting backup", $e->getMessage());
        }
    }

    public function saveTelegram() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->respond(false, "Invalid request method.");
        }

        $proxyUrl = trim($_POST['tg_proxy_url'] ?? '');
        $proxyHost = trim($_POST['tg_proxy_host'] ?? '');
        $proxyPort = trim($_POST['tg_proxy_port'] ?? '');
        $proxyAuth = trim($_POST['tg_proxy_auth'] ?? '');

        // Parse connection string if provided
        if (!empty($proxyUrl)) {
            $parsed = parse_url($proxyUrl);
            if ($parsed) {
                $proxyHost = $parsed['host'] ?? $proxyHost;
                $proxyPort = $parsed['port'] ?? $proxyPort;
                if (!empty($parsed['user']) && !empty($parsed['pass'])) {
                    $proxyAuth = $parsed['user'] . ':' . $parsed['pass'];
                } elseif (!empty($parsed['user'])) {
                    $proxyAuth = $parsed['user'];
                }
            }
        }

        $data = [
            'TG_BOT_TOKEN'      => trim($_POST['tg_bot_token'] ?? ''),
            'TG_CHAT_ID'        => trim($_POST['tg_chat_id'] ?? ''),
            'TG_PROXY_ENABLED'  => isset($_POST['tg_proxy_enabled']) ? 'true' : 'false',
            'TG_PROXY_TYPE'     => 'http', // Leave only HTTP
            'TG_PROXY_HOST'     => $proxyHost,
            'TG_PROXY_PORT'     => $proxyPort,
            'TG_PROXY_AUTH'     => $proxyAuth
        ];

        if (Config::updateEnv($data)) {
            return $this->respond(true, "Telegram configuration saved successfully.");
        } else {
            return $this->respond(false, "Failed to save configuration to .env file. Check permissions.");
        }
    }

    public function saveBeszel() {

        $data = [
            'BESZEL_URL'      => trim($_POST['beszel_url'] ?? ''),
            'BESZEL_EMAIL'    => trim($_POST['beszel_email'] ?? ''),
            'BESZEL_PASSWORD' => trim($_POST['beszel_password'] ?? '')
        ];

        if (Config::updateEnv($data)) {
            return $this->respond(true, "Beszel configuration saved successfully.");
        } else {
            return $this->respond(false, "Failed to save configuration to .env file. Check permissions.");
        }
    }

    public function saveBackupSchedule() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->respond(false, "Invalid request method.");
        }

        $enabled = isset($_POST['backup_auto_enabled']) ? 'true' : 'false';
        $time = trim($_POST['backup_schedule_time'] ?? '00:00');

        // Basic validation HH:MM
        if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
            return $this->respond(false, "Invalid time format. Please use HH:MM.");
        }

        $data = [
            'BACKUP_AUTO_ENABLED'  => $enabled,
            'BACKUP_SCHEDULE_TIME' => $time
        ];

        if (Config::updateEnv($data)) {
            return $this->respond(true, "Backup schedule updated successfully.");
        } else {
            return $this->respond(false, "Failed to save backup schedule.");
        }
    }

    private function getBeszelConfig() {
        return [
            'url'      => Config::get('BESZEL_URL'),
            'email'    => Config::get('BESZEL_EMAIL'),
            'password' => Config::get('BESZEL_PASSWORD')
        ];
    }

    private function getTelegramConfig() {
        return [
            'bot_token'     => Config::get('TG_BOT_TOKEN'),
            'chat_id'       => Config::get('TG_CHAT_ID'),
            'proxy_enabled' => Config::get('TG_PROXY_ENABLED', 'false'),
            'proxy_type'    => Config::get('TG_PROXY_TYPE', 'socks5'),
            'proxy_host'    => Config::get('TG_PROXY_HOST'),
            'proxy_port'    => Config::get('TG_PROXY_PORT'),
            'proxy_auth'    => Config::get('TG_PROXY_AUTH')
        ];
    }

    private function getLocalBackups() {
        require_once __DIR__ . '/../inc/BackupManager.php';
        return BackupManager::listLocalBackups();
    }

    private function getCloudBackups() {
        require_once __DIR__ . '/../inc/BackupManager.php';
        try {
            return BackupManager::listCloudBackups();
        } catch (Exception $e) {
            return [];
        }
    }
    
    private function getTranslationStats() {
        // Get all languages
        $stmt = $this->pdo->query("SELECT * FROM languages ORDER BY code");
        $languages = $stmt->fetchAll();
        
        // Get total translation keys count (distinct category + key_name combinations)
        $stmt = $this->pdo->query("SELECT COUNT(DISTINCT CONCAT(category, '.', key_name)) as count FROM translations WHERE locale = 'en'");
        $totalKeys = $stmt->fetch();
        $totalCount = $totalKeys['count'];
        
        $stats = [];
        foreach ($languages as $lang) {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) as count FROM translations WHERE locale = ? AND translation IS NOT NULL AND translation != ''"
            );
            $stmt->execute([$lang['code']]);
            $translated = $stmt->fetch();
            
            $stats[] = [
                'code' => $lang['code'],
                'name' => $lang['name'],
                'native_name' => $lang['native_name'],
                'total_count' => $totalCount,
                'translated_count' => $translated['count']
            ];
        }
        
        return $stats;
    }
    
}
