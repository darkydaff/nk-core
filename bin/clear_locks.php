<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/DB.php';

try {
    $pdo = DB::conn();
    $count = $pdo->exec("DELETE FROM job_locks");
    echo "🧹 Cleared {$count} job locks successfully.\n";
} catch (\Throwable $e) {
    echo "❌ Failed to clear locks: " . $e->getMessage() . "\n";
    exit(1);
}
