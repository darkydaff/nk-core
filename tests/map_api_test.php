<?php
require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';

function assert_equals($expected, $actual, string $msg) {
    if ($expected !== $actual) { echo "❌ FAIL: $msg (Expected: " . var_export($expected, true) . ", Got: " . var_export($actual, true) . ")\n"; exit(1); }
    echo "✅ PASS: $msg\n";
}

$pdo = DB::conn();
$clients = $pdo->query("SELECT id, ip_lat, ip_lon FROM vpn_clients WHERE deleted_at IS NULL AND ip_lat IS NOT NULL")->fetchAll();
$servers = $pdo->query("SELECT id, lat, lon FROM vpn_servers WHERE deleted_at IS NULL AND lat IS NOT NULL")->fetchAll();

echo "Map API DB check:\n";
echo "Found " . count($clients) . " clients with coordinates.\n";
echo "Found " . count($servers) . " servers with coordinates.\n";
echo "All Map API checks completed.\n";
