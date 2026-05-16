<?php
/**
 * Beszel Server Monitoring Client
 * Pulls live stats from Beszel (PocketBase) hub
 */
class BeszelClient {
    private string $baseUrl;
    private string $email;
    private string $password;
    private string $tokenFile;
    private ?string $token = null;

    public function __construct() {
        $this->baseUrl = rtrim(Config::get('BESZEL_URL', ''), '/');
        $this->email = Config::get('BESZEL_EMAIL', '');
        $this->password = Config::get('BESZEL_PASSWORD', '');
        $this->tokenFile = __DIR__ . '/../storage/cache/beszel_token.json';
        
        // Ensure cache directory exists
        $cacheDir = dirname($this->tokenFile);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
    }

    /**
     * Authenticate and get/refresh token
     */
    private function getToken(): ?string {
        if ($this->token) return $this->token;

        // Try loading from cache
        if (file_exists($this->tokenFile)) {
            $cache = json_decode(file_get_contents($this->tokenFile), true);
            if ($cache && isset($cache['token']) && isset($cache['expires']) && $cache['expires'] > time()) {
                $this->token = $cache['token'];
                return $this->token;
            }
        }

        // Authenticate
        if (empty($this->baseUrl) || empty($this->email) || empty($this->password)) {
            return null;
        }

        $url = "{$this->baseUrl}/api/collections/users/auth-with-password";
        $data = json_encode([
            'identity' => $this->email,
            'password' => $this->password
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode === 200) {
            $result = json_decode($response, true);
            if (isset($result['token'])) {
                $this->token = $result['token'];
                // Cache for 2 days (PocketBase tokens usually last long, but we'll refresh earlier)
                file_put_contents($this->tokenFile, json_encode([
                    'token' => $this->token,
                    'expires' => time() + (2 * 24 * 3600) - 3600
                ]));
                return $this->token;
            }
        }
        
        return null;
    }

    /**
     * Get all systems from Beszel
     */
    public function getSystems(): array {
        $token = $this->getToken();
        if (!$token) return [];

        $url = "{$this->baseUrl}/api/collections/systems/records?perPage=100";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$token}"
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode === 200) {
            $result = json_decode($response, true);
            return $result['items'] ?? [];
        }
        
        return [];
    }

    /**
     * Get monitoring data for a specific IP
     */
    public function getSystemByIp(string $ip): ?array {
        $token = $this->getToken();
        if (!$token) return null;

        // Search by host field in PocketBase (should match IP)
        $url = "{$this->baseUrl}/api/collections/systems/records?filter=(host='{$ip}')";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}"]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);
        $result = json_decode($response, true);
        $items = $result['items'] ?? [];

        if (!empty($items)) {
            $system = $items[0];
            $stats = $this->getLatestStats($system['id']);
            if ($stats) $system['stats'] = $stats;
            return $system;
        }

        return null;
    }

    /**
     * Get latest stats for a system (contains m, d capacity)
     */
    public function getLatestStats(string $systemId): ?array {
        $token = $this->getToken();
        if (!$token) return null;

        // Fetch latest 1m stats for this system
        $url = "{$this->baseUrl}/api/collections/system_stats/records?filter=(system='{$systemId}')&sort=-created&perPage=1";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$token}"
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode === 200) {
            $result = json_decode($response, true);
            $items = $result['items'] ?? [];
            if (!empty($items)) {
                $stats = $items[0]['stats'] ?? [];
                if (is_string($stats)) {
                    return json_decode($stats, true);
                }
                return $stats;
            }
        }

        return null;
    }
}
