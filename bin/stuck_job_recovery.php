<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/Enums.php';
require_once __DIR__ . '/../inc/Queue.php';
require_once __DIR__ . '/../inc/Logger.php';

// Set timezone to Moscow (GMT+3)
date_default_timezone_set('Europe/Moscow');

echo "🧹 Starting Stuck Job Recovery...\n";

try {
    $pdo = DB::conn();

    // 1. Recover Stuck Server Deployments (> 15 mins)
    $stmt = $pdo->prepare("
        SELECT id, name FROM vpn_servers 
        WHERE status = 'deploying' 
        AND updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        AND deleted_at IS NULL
    ");
    $stmt->execute();
    $stuckServers = $stmt->fetchAll();

    foreach ($stuckServers as $server) {
        echo "⚠️ Server {$server['name']} (ID: {$server['id']}) stuck in deploying. Marking as ERROR.\n";
        $pdo->prepare("UPDATE vpn_servers SET status = 'error' WHERE id = ?")->execute([$server['id']]);
        Logger::channel('deployments')->warning("Server deployment timed out and marked as error", ['server_id' => $server['id']]);
    }

    // 2. Recover Stuck Client Provisioning (> 15 mins)
    $stmt = $pdo->prepare("
        SELECT id, name, server_id FROM vpn_clients 
        WHERE status = 'provisioning' 
        AND created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        AND deleted_at IS NULL
    ");
    $stmt->execute();
    $stuckClients = $stmt->fetchAll();

    foreach ($stuckClients as $client) {
        echo "⚠️ Client {$client['name']} (ID: {$client['id']}) stuck in provisioning. Re-queueing...\n";
        Queue::push('deployments', [
            'type' => 'provision_client',
            'client_id' => (int)$client['id'],
            'server_id' => (int)$client['server_id']
        ]);
        Logger::channel('deployments')->info("Stuck client provisioning re-queued", ['client_id' => $client['id']]);
    }

    echo "✅ Recovery completed.\n";

} catch (Throwable $e) {
    echo "❌ Recovery failed: " . $e->getMessage() . "\n";
    Logger::error("Stuck job recovery failed", ['error' => $e->getMessage()]);
    exit(1);
}
