<?php
/**
 * Amnezia VPN Web Panel
 * Main entry point
 */

ob_start();

// Set default timezone to Moscow (GMT+3)
date_default_timezone_set('Europe/Moscow');

session_name(getenv('SESSION_NAME') ?: 'amnezia_panel_session');
session_start();

// Populate $_POST from JSON input if necessary
if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    if (is_array($data)) {
        $_POST = array_merge($_POST, $data);
    }
}

// Load dependencies
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/Auth.php';
require_once __DIR__ . '/../inc/Router.php';
require_once __DIR__ . '/../inc/View.php';
require_once __DIR__ . '/../inc/Enums.php';
require_once __DIR__ . '/../inc/VpnServer.php';
require_once __DIR__ . '/../inc/ProxyServer.php';
require_once __DIR__ . '/../inc/VpnClient.php';
require_once __DIR__ . '/../inc/Translator.php';
require_once __DIR__ . '/../inc/JWT.php';
require_once __DIR__ . '/../inc/ServerMonitoring.php';
require_once __DIR__ . '/../inc/Job.php';
require_once __DIR__ . '/../inc/EventBus.php';
require_once __DIR__ . '/../inc/CSRF.php';

// Load environment configuration
Config::load(__DIR__ . '/../.env');

// Test database connection
try {
    DB::conn();
} catch (Throwable $e) {
    die('Database connection error: ' . $e->getMessage());
}

// Seed admin user if not exists
try {
    $adminEmail = Config::get('ADMIN_EMAIL');
    $adminPass = Config::get('ADMIN_PASSWORD');
    if ($adminEmail && $adminPass) {
        Auth::seedAdmin($adminEmail, $adminPass);
    }
} catch (Throwable $e) {
    // Ignore errors
}

// Initialize translator
Translator::init();

// Initialize template engine
$user = Auth::user();
$appName = Config::get('APP_NAME', 'Nk-VPN Panel');

/**
 * Helper function to authenticate user from JWT or session
 * Returns user array or null if unauthorized
 */
function authenticateRequest(): ?array
{
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($authHeader && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $token = $matches[1];
        $user = JWT::verify($token);
        if ($user) {
            return $user;
        }
    }
    if (isset($_SESSION['user_id'])) {
        return Auth::user();
    }
    return null;
}

View::init(__DIR__ . '/../templates', [
    'app_name' => $appName,
    'user' => $user,
    'current_language' => Translator::getCurrentLanguage(),
    'languages' => Translator::getSupportedLanguages(),
    'current_uri' => $_SERVER['REQUEST_URI'] ?? '/dashboard',
    'csrf_token' => CSRF::getToken()
]);

// Helper function for redirects
function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function isJsonRequest(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $requestedWith = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    return stripos($accept, 'application/json') !== false || $requestedWith === 'xmlhttprequest';
}

// Helper function to require authentication
function requireAuth(): void
{
    if (!Auth::check()) {
        if (isJsonRequest()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Authentication required']);
            exit;
        }
        redirect('/login');
    }
}

// Helper function to require admin
function requireAdmin(): void
{
    requireAuth();
    if (!Auth::isAdmin()) {
        http_response_code(403);
        echo 'Forbidden: Admin access required';
        exit;
    }
}

// Helper function to get authenticated user (JWT or session)
function getAuthUser(): ?array
{
    $token = JWT::getTokenFromHeader();
    if ($token !== null) {
        $user = JWT::verify($token);
        if ($user !== null) {
            return $user;
        }
    }
    if (Auth::check()) {
        return Auth::user();
    }
    return null;
}

// Helper function to require authentication (JWT or session) for API
function requireApiAuth(): ?array
{
    $user = getAuthUser();
    if ($user === null) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Authentication required']);
        return null;
    }
    return $user;
}

// Helper function to unlock the session for async background tasks
function unlockSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

// Helper function to relock the session to write flash messages
function relockSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

// Pre-Dispatch CSRF verification
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && strpos($_SERVER['REQUEST_URI'], '/api/') !== 0) {
    if (!CSRF::verify()) {
        http_response_code(403);
        if (isJsonRequest()) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid CSRF token']);
        } else {
            echo Translator::t('message.forbidden') . ' (Invalid CSRF token)';
        }
        exit;
    }
}

/**
 * PUBLIC ROUTES
 */
Router::get('/', ['AuthController', 'index']);
Router::get('/login', ['AuthController', 'showLogin']);
Router::post('/login', ['AuthController', 'login']);
Router::get('/logout', ['AuthController', 'logout']);

/**
 * AUTHENTICATED ROUTES
 */
Router::get('/dashboard', ['ServerController', 'dashboard']);
Router::get('/map', ['MapController', 'index']);
Router::get('/api/map/clients', ['MapController', 'clientsGeo']);

// Server Routes
Router::get('/servers', ['ServerController', 'index']);
Router::get('/servers/create', ['ServerController', 'create']);
Router::post('/servers/create', ['ServerController', 'store']);
Router::post('/servers/{id}/delete', ['ServerController', 'delete']);
Router::get('/servers/{id}/deploy', ['ServerController', 'showDeploy']);
Router::post('/servers/{id}/deploy', ['ServerController', 'deploy']);
Router::get('/servers/{id}/status', ['ServerController', 'getStatus']);
Router::get('/api/servers/health-batch', ['ServerController', 'getHealthBatch']);
Router::get('/servers/{id}/logs', ['ServerController', 'getLogs']);
Router::get('/api/servers/{id}/deployment-logs', ['ApiController', 'getDeploymentLogs']);
Router::get('/api/jobs/{id}/events', ['ApiController', 'getJobEvents']);
Router::post('/api/jobs/{id}/cancel', ['ApiController', 'cancelJob']);
Router::get('/servers/diagnostics', ['ServerController', 'diagnostics']);
Router::get('/servers/{id}', ['ServerController', 'view']);
Router::post('/servers/{id}/sync-stats', ['ServerController', 'syncStats']);
Router::post('/servers/{id}/toggle-telemetry', ['ServerController', 'toggleTelemetry']);
Router::post('/servers/{id}/warp/install', ['ServerController', 'installWarp']);
Router::post('/servers/{id}/warp/uninstall', ['ServerController', 'uninstallWarp']);
Router::post('/servers/{id}/warp/repair', ['ServerController', 'repairWarp']);
Router::post('/servers/{id}/warp/reinstall', ['ServerController', 'reinstallWarp']);
Router::post('/servers/{id}/warp/health-check', ['ServerController', 'runWarpHealthCheck']);
Router::post('/servers/sync-all', ['ServerController', 'syncAll']);
Router::get('/api/search-clients', ['ServerController', 'searchClients']);
Router::get('/api/monitoring/traffic-history', ['ServerController', 'getTrafficHistory']);

// Client Routes
Router::post('/servers/{id}/clients/create', ['ClientController', 'create']);
Router::get('/clients/{id}', ['ClientController', 'view']);
Router::get('/clients/{id}/status', ['ClientController', 'status']);
Router::post('/clients/{id}/update', ['ClientController', 'update']);
Router::get('/clients/{id}/download', ['ClientController', 'downloadConfig']);
Router::post('/clients/{id}/revoke', ['ClientController', 'revoke']);
Router::post('/clients/{id}/restore', ['ClientController', 'restore']);
Router::post('/clients/{id}/delete', ['ClientController', 'delete']);
Router::post('/clients/{id}/sync-stats', ['ClientController', 'syncStats']);
Router::post('/clients/batch', ['ClientController', 'batchAction']);

// Proxy Routes
Router::get('/proxies', ['ProxyController', 'index']);
Router::post('/proxies/create', ['ProxyController', 'create']);
Router::post('/proxies/{id}/pause', ['ProxyController', 'pause']);
Router::post('/proxies/{id}/resume', ['ProxyController', 'resume']);
Router::post('/proxies/{id}/delete', ['ProxyController', 'delete']);
Router::post('/proxies/{id}/check', ['ProxyController', 'check']);
Router::post('/proxies/sync-all', ['ProxyController', 'syncAll']);

// Jobs / DLQ
require_once __DIR__ . '/../controllers/JobController.php';
Router::get('/jobs/dlq', ['JobController', 'dlqIndex']);
Router::post('/jobs/{id}/retry', ['JobController', 'retry']);

/**
 * SETTINGS ROUTES
 */
Router::get('/settings', ['SettingsController', 'index']);
Router::post('/settings/api-key', ['SettingsController', 'saveApiKey']);
Router::post('/settings/change-password', ['SettingsController', 'changePassword']);
Router::post('/settings/delete-user/{id}', ['SettingsController', 'deleteUser']);
Router::post('/settings/create-user', ['SettingsController', 'createUser']);
Router::post('/settings/run-backup', ['SettingsController', 'runBackup']);
Router::post('/settings/restore-backup', ['SettingsController', 'restoreBackup']);
Router::post('/settings/delete-backup', ['SettingsController', 'deleteBackup']);
Router::post('/settings/save-telegram', ['SettingsController', 'saveTelegram']);
Router::post('/settings/save-backup-schedule', ['SettingsController', 'saveBackupSchedule']);
Router::post('/settings/save-beszel', ['SettingsController', 'saveBeszel']);
Router::post('/settings/save-speed-limit', ['SettingsController', 'saveSpeedLimit']);
Router::post('/settings/upload-restore', ['SettingsController', 'uploadRestore']);

/**
 * LANGUAGE ROUTES
 */
Router::get('/language/change', ['LanguageController', 'change']);
Router::post('/language/change', ['LanguageController', 'change']);

// Removed temporary migration route

/**
 * API ROUTES
 */
Router::post('/api/auth/token', ['ApiController', 'token']);
Router::post('/api/tokens', ['ApiController', 'createToken']);
Router::get('/api/tokens', ['ApiController', 'listTokens']);
Router::delete('/api/tokens/{id}', ['ApiController', 'revokeToken']);
Router::get('/api/servers', ['ApiController', 'listServers']);
Router::post('/api/servers/create', ['ApiController', 'createServer']);
Router::delete('/api/servers/{id}/delete', ['ApiController', 'deleteServer']);
Router::post('/api/servers/{id}/backup', ['ApiController', 'createBackup']);
Router::get('/api/servers/{id}/backups', ['ApiController', 'listBackups']);
Router::post('/api/servers/{id}/restore', ['ApiController', 'restoreBackup']);
Router::delete('/api/backups/{id}', ['ApiController', 'deleteBackup']);
Router::get('/api/clients', ['ApiController', 'listClients']);
Router::get('/api/clients/{id}/details', ['ApiController', 'clientDetails']);
Router::post('/api/clients/create', ['ApiController', 'createClient']);
Router::post('/api/clients/{id}/revoke', ['ApiController', 'revokeClient']);
Router::post('/api/clients/{id}/restore', ['ApiController', 'restoreClient']);
Router::post('/api/clients/{id}/set-expiration', ['ApiController', 'setClientExpiration']);
Router::post('/api/clients/{id}/extend', ['ApiController', 'extendClientExpiration']);
Router::get('/api/clients/expiring', ['ApiController', 'getExpiringClients']);
Router::post('/api/clients/{id}/set-traffic-limit', ['ApiController', 'setClientTrafficLimit']);
Router::get('/api/clients/{id}/traffic-limit-status', ['ApiController', 'getClientTrafficLimitStatus']);
Router::get('/api/clients/overlimit', ['ApiController', 'getClientsOverLimit']);
Router::get('/api/servers/{id}/metrics', ['ApiController', 'serverMetrics']);
Router::get('/api/clients/{id}/metrics', ['ApiController', 'clientMetrics']);
Router::get('/api/servers/{id}/clients', ['ApiController', 'serverClients']);
Router::post('/api/translations/auto-translate', ['ApiController', 'autoTranslate']);
Router::get('/api/translations/export/{lang}', ['ApiController', 'exportTranslations']);

// Proxy API Routes
Router::get('/api/proxies', ['ApiController', 'listProxies']);
Router::post('/api/proxies', ['ApiController', 'createProxy']);
Router::post('/api/proxies/{id}/pause', ['ApiController', 'pauseProxy']);
Router::post('/api/proxies/{id}/resume', ['ApiController', 'resumeProxy']);
Router::delete('/api/proxies/{id}', ['ApiController', 'deleteProxy']);

// System Telemetry & Self-Diagnostics API Routes
Router::get('/api/system/health', ['ApiController', 'systemHealth']);
Router::get('/api/system/nodes', ['ApiController', 'systemNodes']);
Router::get('/api/system/metrics', ['ApiController', 'systemMetrics']);
Router::get('/api/system/replay', ['ApiController', 'systemReplay']);
Router::get('/api/system/transitions', ['ApiController', 'systemTransitions']);

// Beszel Monitoring API
Router::get('/api/monitoring/beszel/{ip}', ['MonitoringController', 'getSystemData']);

// Dispatch router
Router::dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
