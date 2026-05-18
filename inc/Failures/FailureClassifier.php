<?php
declare(strict_types=1);

namespace Failures;

class FailureClassifier {

    public const CATEGORY_NETWORK_TIMEOUT = 'network_timeout';
    public const CATEGORY_AUTH_FAILURE = 'auth_failure';
    public const CATEGORY_NODE_OFFLINE = 'node_offline';
    public const CATEGORY_INVALID_CONFIG = 'invalid_config';
    public const CATEGORY_RESOURCE_MISSING = 'resource_missing';
    public const CATEGORY_DATA_CORRUPTION = 'data_corruption';
    public const CATEGORY_DISK_EXHAUSTED = 'disk_exhausted';
    public const CATEGORY_OOM = 'out_of_memory';
    public const CATEGORY_UNKNOWN = 'unknown';

    private const SEVERITY_WARNING = 'warning';
    private const SEVERITY_CRITICAL = 'critical';
    private const SEVERITY_FATAL = 'fatal';

    /**
     * Classifies a failure based on the exception message, stdout, stderr, and exit code.
     * 
     * @return array{category: string, retryable: bool, severity: string}
     */
    public static function classify(\Throwable $e, string $stdout, string $stderr, ?int $exitCode): array {
        
        $output = strtolower($e->getMessage() . "\n" . $stdout . "\n" . $stderr);

        // 1. DANGEROUS / HIGH SEVERITY (OOM, Disk, Corruption)
        if (str_contains($output, 'out of memory') || str_contains($output, 'oom-kill') || str_contains($output, 'killed')) {
            return self::buildResult(self::CATEGORY_OOM, false, self::SEVERITY_FATAL);
        }
        if (str_contains($output, 'no space left on device') || str_contains($output, 'disk full')) {
            return self::buildResult(self::CATEGORY_DISK_EXHAUSTED, false, self::SEVERITY_CRITICAL);
        }
        if (str_contains($output, 'database disk image malformed') || str_contains($output, 'corrupted')) {
            return self::buildResult(self::CATEGORY_DATA_CORRUPTION, false, self::SEVERITY_FATAL);
        }

        // 2. PERMANENT / AUTH / CONFIG
        if (str_contains($output, 'permission denied') || 
            str_contains($output, 'host key verification failed') || 
            str_contains($output, 'authentication failed') ||
            str_contains($output, 'publickey,password')) {
            return self::buildResult(self::CATEGORY_AUTH_FAILURE, false, self::SEVERITY_CRITICAL);
        }
        if (str_contains($output, 'invalid wireguard key') || 
            str_contains($output, 'syntax error') ||
            str_contains($output, 'invalid config')) {
            return self::buildResult(self::CATEGORY_INVALID_CONFIG, false, self::SEVERITY_WARNING);
        }
        if (str_contains($output, 'container not found') || 
            str_contains($output, 'no such file or directory') || 
            str_contains($output, 'does not exist')) {
            return self::buildResult(self::CATEGORY_RESOURCE_MISSING, false, self::SEVERITY_WARNING);
        }

        // 3. RETRYABLE / TRANSIENT
        if (str_contains($output, 'connection timed out') || 
            str_contains($output, 'no route to host') || 
            str_contains($output, 'connection reset by peer') ||
            str_contains($output, 'broken pipe') ||
            str_contains($output, 'timeout')) {
            return self::buildResult(self::CATEGORY_NETWORK_TIMEOUT, true, self::SEVERITY_WARNING);
        }
        if (str_contains($output, 'context deadline exceeded') || 
            str_contains($output, 'network unavailable') || 
            str_contains($output, 'docker daemon not responding') ||
            str_contains($output, 'tls handshake timeout') ||
            str_contains($output, 'connection refused')) {
            return self::buildResult(self::CATEGORY_NODE_OFFLINE, true, self::SEVERITY_WARNING);
        }

        // 4. FALLBACK BASED ON EXCEPTION/EXIT CODE
        if ($exitCode === 3 || $e instanceof \InvalidArgumentException) {
            // Inherit the exit_code=3 permanent marker from process_job
            return self::buildResult(self::CATEGORY_UNKNOWN, false, self::SEVERITY_WARNING);
        }

        return self::buildResult(self::CATEGORY_UNKNOWN, true, self::SEVERITY_WARNING);
    }

    private static function buildResult(string $category, bool $retryable, string $severity): array {
        return [
            'category' => $category,
            'retryable' => $retryable,
            'severity' => $severity,
        ];
    }
}
