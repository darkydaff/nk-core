<?php
require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/Enums.php';
require_once __DIR__ . '/../inc/VpnServer.php';

Config::load(__DIR__ . '/../.env');

try {
    $server = new VpnServer(16);
    echo "--- docker inspect nk-awg-v2 ---\n";
    echo $server->executeCommand("docker inspect nk-awg-v2", true) . "\n";
    echo "--- docker ps --filter name=nk-awg-v2 ---\n";
    echo $server->executeCommand("docker ps --filter name=nk-awg-v2", true) . "\n";
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
