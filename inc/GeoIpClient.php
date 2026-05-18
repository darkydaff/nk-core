<?php
declare(strict_types=1);

/**
 * GeoIP Circuit Breaker
 * 
 * Prevents cascading failures when GeoIP APIs are rate-limited or offline.
 * ip-api.com has a 45 requests/minute free-tier limit.
 * 
 * States:
 * - CLOSED: Normal operation, requests pass through
 * - OPEN: All requests short-circuit (return null) for cooldown period
 * - HALF_OPEN: Allow one probe request to test if service recovered
 */
class GeoIpClient
{
    private static int $failureCount = 0;
    private static int $lastFailureAt = 0;
    private static int $circuitOpenUntil = 0;
    
    /** Number of consecutive failures before opening circuit */
    private const FAILURE_THRESHOLD = 3;
    
    /** Cooldown period in seconds when circuit is open */
    private const COOLDOWN_SECONDS = 120;
    
    /**
     * Lookup GeoIP data for an IP address with circuit breaker protection.
     * 
     * @return array|null Geo data or null if unavailable/circuit open
     */
    public static function lookup(string $ip): ?array
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        
        // Check circuit breaker state
        if (self::$circuitOpenUntil > time()) {
            return null; // Circuit OPEN — skip request
        }
        
        // Try primary: ip-api.com
        $result = self::queryIpApi($ip);
        if ($result !== null) {
            self::recordSuccess();
            return $result;
        }
        
        // Try fallback: freeipapi.com
        $result = self::queryFreeIpApi($ip);
        if ($result !== null) {
            self::recordSuccess();
            return $result;
        }
        
        // Both failed
        self::recordFailure();
        return null;
    }
    
    private static function queryIpApi(string $ip): ?array
    {
        try {
            $url = "http://ip-api.com/json/{$ip}?fields=status,message,country,countryCode,city,isp,org,lat,lon,query";
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if (!$response || $httpCode === 429) {
                // Rate limited — trigger circuit breaker immediately
                if ($httpCode === 429) {
                    self::$failureCount = self::FAILURE_THRESHOLD;
                    self::recordFailure();
                }
                return null;
            }
            
            $geo = json_decode($response, true);
            if (($geo['status'] ?? '') !== 'success') return null;
            
            return [
                'country' => $geo['country'] ?? null,
                'country_code' => $geo['countryCode'] ?? null,
                'city' => $geo['city'] ?? null,
                'isp' => $geo['isp'] ?? null,
                'org' => $geo['org'] ?? null,
                'lat' => $geo['lat'] ?? null,
                'lon' => $geo['lon'] ?? null,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    private static function queryFreeIpApi(string $ip): ?array
    {
        try {
            $url = "https://freeipapi.com/api/json/{$ip}";
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
            
            if (!$response) return null;
            
            $geo = json_decode($response, true);
            if (empty($geo['countryName'])) return null;
            
            return [
                'country' => $geo['countryName'] ?? null,
                'country_code' => $geo['countryCode'] ?? null,
                'city' => $geo['cityName'] ?? null,
                'isp' => $geo['isp'] ?? null,
                'org' => null,
                'lat' => $geo['latitude'] ?? null,
                'lon' => $geo['longitude'] ?? null,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    private static function recordSuccess(): void
    {
        self::$failureCount = 0;
        self::$circuitOpenUntil = 0;
    }
    
    private static function recordFailure(): void
    {
        self::$failureCount++;
        self::$lastFailureAt = time();
        
        if (self::$failureCount >= self::FAILURE_THRESHOLD) {
            self::$circuitOpenUntil = time() + self::COOLDOWN_SECONDS;
            if (class_exists('Logger')) {
                \Logger::warning('GeoIP circuit breaker OPEN — cooling down for ' . self::COOLDOWN_SECONDS . 's', [
                    'failures' => self::$failureCount
                ]);
            }
        }
    }
    
    /**
     * Check if circuit breaker is currently open (for diagnostics)
     */
    public static function isCircuitOpen(): bool
    {
        return self::$circuitOpenUntil > time();
    }
}
