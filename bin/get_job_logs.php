<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';

Config::load(__DIR__ . '/../.env');

$jobId = isset($argv[1]) ? (int)$argv[1] : null;

try {
    $pdo = DB::conn();
    if (!$jobId) {
        // Find latest job
        $stmt = $pdo->query("SELECT id, type, status FROM jobs ORDER BY id DESC LIMIT 1");
        $job = $stmt->fetch();
        if (!$job) {
            echo "No jobs found.\n";
            exit(0);
        }
        $jobId = (int)$job['id'];
        echo "Fetching logs for latest job ID: {$jobId} ({$job['type']}, status: {$job['status']})\n";
    } else {
        echo "Fetching logs for job ID: {$jobId}\n";
    }

    $stmt = $pdo->prepare("SELECT message, created_at FROM job_events WHERE job_id = ? AND type = 'log' ORDER BY id ASC");
    $stmt->execute([$jobId]);
    $events = $stmt->fetchAll();

    foreach ($events as $event) {
        echo "[{$event['created_at']}] {$event['message']}\n";
    }

} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
