<?php
require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/VpnServer.php';

function assert_true(bool $cond, string $msg) {
    if (!$cond) { echo "❌ FAIL: $msg\n"; exit(1); }
    echo "✅ PASS: $msg\n";
}

// Find a server to test geo updates
$pdo = DB::conn();
$server = $pdo->query("SELECT id FROM vpn_servers WHERE deleted_at IS NULL LIMIT 1")->fetch();
if (!$server) {
    echo "No server available for testing.\n";
    exit(0);
}

$vs = new VpnServer((int)$server['id']);
$success = $vs->updateGeoIp();
assert_true($success, "Server updateGeoIp returns true");

$updated = $pdo->query("SELECT lat, lon FROM vpn_servers WHERE id = " . (int)$server['id'])->fetch();
assert_true($updated['lat'] !== null, "Latitude is resolved and not null");
assert_true($updated['lon'] !== null, "Longitude is resolved and not null");
echo "All GeoIP tests passed!\n";
