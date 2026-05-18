<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/View.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/Queue.php';
require_once __DIR__ . '/../inc/Auth.php';

class JobController {
    
    public static function dlqIndex() {
        requireAuth();
        
        $pdo = DB::conn();
        
        // Fetch failed jobs (quarantined)
        $stmt = $pdo->query("
            SELECT j.*, 
                   s.name as server_name, s.ip_address 
            FROM jobs j
            LEFT JOIN vpn_servers s ON j.server_id = s.id
            WHERE j.status = 'error'
            ORDER BY j.completed_at DESC
            LIMIT 100
        ");
        $failedJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Compute aggregate stats for simple UI header
        $stats = $pdo->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN is_retryable = 1 THEN 1 ELSE 0 END) as retryable,
                SUM(CASE WHEN severity = 'fatal' THEN 1 ELSE 0 END) as fatal
            FROM jobs 
            WHERE status = 'error'
        ")->fetch(PDO::FETCH_ASSOC);
        
        View::render('jobs/dlq.twig', [
            'jobs' => $failedJobs,
            'stats' => $stats
        ]);
    }
    
    public static function retry($id) {
        requireAuth();
        
        $pdo = DB::conn();
        $stmt = $pdo->prepare("SELECT * FROM jobs WHERE id = ? AND status = 'error'");
        $stmt->execute([$id]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$job) {
            header("HTTP/1.1 404 Not Found");
            exit("Job not found in DLQ");
        }
        
        // Reset state and re-queue
        $pdo->prepare("
            UPDATE jobs 
            SET status = 'pending', attempts = 0, error_summary = NULL, 
                exit_code = NULL, duration_ms = NULL, failure_category = NULL, 
                is_retryable = NULL, severity = NULL, completed_at = NULL
            WHERE id = ?
        ")->execute([$id]);
        
        $payload = json_decode($job['payload'], true) ?: [];
        $payload['job_id'] = $job['id'];
        if ($job['server_id']) {
            $payload['server_id'] = $job['server_id'];
        }
        
        Queue::push('deployments', $payload);
        
        header('Location: /jobs/dlq');
        exit;
    }
}
