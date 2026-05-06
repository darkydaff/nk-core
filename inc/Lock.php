<?php
declare(strict_types=1);

/**
 * DB-based distributed locking service for background jobs.
 */
class Lock
{
    /**
     * Try to acquire a lock
     * 
     * @param string $name Lock name (e.g. "server:123:sync")
     * @param int $ttl Seconds until lock automatically expires
     * @return bool True if lock acquired, false if already locked
     */
    public static function acquire(string $name, int $ttl = 300): bool
    {
        $pdo = DB::conn();
        
        // Clean up expired locks first
        $pdo->prepare("DELETE FROM job_locks WHERE expires_at < NOW()")->execute();
        
        try {
            $stmt = $pdo->prepare("INSERT INTO job_locks (name, expires_at) VALUES (?, DATE_ADD(NOW(), INTERVAL ? SECOND))");
            return $stmt->execute([$name, $ttl]);
        } catch (\PDOException $e) {
            // Integrity constraint violation (duplicate entry) means it's already locked
            return false;
        }
    }

    /**
     * Release a lock
     */
    public static function release(string $name): void
    {
        $pdo = DB::conn();
        $pdo->prepare("DELETE FROM job_locks WHERE name = ?")->execute([$name]);
    }
}
