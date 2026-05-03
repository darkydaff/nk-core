<?php
/**
 * Background script to ping all VPN servers and update their status/latency
 * Run this via cron every hour: 0 * * * * php bin/ping_servers.php
 */
require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/VpnServer.php';

// Load environment configuration
Config::load(__DIR__ . '/../.env');

// Set timezone to Moscow (GMT+3)
date_default_timezone_set('Europe/Moscow');

echo "[" . date('Y-m-d H:i:s') . "] Starting background ping check...\n";

try {
    $pdo = DB::conn();
    // Get all active or offline servers (skip deploying)
    $stmt = $pdo->query("SELECT id FROM vpn_servers WHERE status != 'deploying'");
    $serverIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $total = count($serverIds);
    $active = 0;
    
    foreach ($serverIds as $id) {
        try {
            $server = new VpnServer((int)$id);
            if ($server->updatePingAndStatus()) {
                $active++;
            }
        } catch (Exception $e) {
            echo "Error pinging server $id: " . $e->getMessage() . "\n";
        }
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] Finished. Total: $total, Active: $active.\n";
    
} catch (Exception $e) {
    echo "Critical error: " . $e->getMessage() . "\n";
    exit(1);
}
