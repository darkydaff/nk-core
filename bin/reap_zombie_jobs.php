<?php
declare(strict_types=1);

/**
 * Zombie Job Reaper
 * 
 * Detects and marks jobs stuck in 'running' state with stale heartbeats.
 * Should be run via cron every minute.
 * 
 * A job is considered a zombie if:
 * - Status is 'running'
 * - updated_at is older than 15 minutes (no heartbeat received)
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/Logger.php';
require_once __DIR__ . '/../inc/Enums.php';

Config::load(__DIR__ . '/../.env');
date_default_timezone_set('Europe/Moscow');

$staleness = 15 * 60; // 15 minutes without heartbeat = zombie

try {
    $pdo = DB::conn();
    
    // 1. Find zombie jobs
    $stmt = $pdo->prepare("
        SELECT id, type, server_id, started_at, updated_at
        FROM jobs
        WHERE status = 'running'
        AND updated_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
    ");
    $stmt->execute([$staleness]);
    $zombies = $stmt->fetchAll();
    
    if (empty($zombies)) {
        exit(0);
    }
    
    Logger::warning('Zombie job reaper found ' . count($zombies) . ' stale jobs');
    
    // 2. Mark them as error
    $updateStmt = $pdo->prepare("
        UPDATE jobs 
        SET status = 'error', 
            error_summary = CONCAT(COALESCE(error_summary, ''), '\n[REAPER] Job killed: no heartbeat for {$staleness}s'),
            completed_at = NOW()
        WHERE id = ?
    ");
    
    $serverStmt = $pdo->prepare("
        UPDATE vpn_servers 
        SET status = 'error', 
            error_message = 'Deployment timed out (zombie job reaped)',
            current_job_id = NULL
        WHERE current_job_id = ?
    ");
    
    foreach ($zombies as $zombie) {
        $updateStmt->execute([$zombie['id']]);
        $serverStmt->execute([$zombie['id']]);
        
        Logger::channel('control-plane')->warning('Reaped zombie job', [
            'job_id' => $zombie['id'],
            'type' => $zombie['type'],
            'server_id' => $zombie['server_id'],
            'started_at' => $zombie['started_at'],
            'last_heartbeat' => $zombie['updated_at']
        ]);
    }
    
    // 3. Clean expired locks too (belt and suspenders with Lock::acquire cleanup)
    $pdo->exec("DELETE FROM job_locks WHERE expires_at < NOW()");
    
    echo "Reaped " . count($zombies) . " zombie jobs\n";
    
} catch (Throwable $e) {
    Logger::error('Zombie reaper failed: ' . $e->getMessage());
    exit(1);
}
