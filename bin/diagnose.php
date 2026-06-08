<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/VpnServer.php';

Config::load(__DIR__ . '/../.env');

try {
    $server = new VpnServer(16);
    $ssh = $server->getSshClient();
    
    echo "==================================================\n";
    echo "NK-Core Cloudflare WARP Routing Diagnostic Tool\n";
    echo "==================================================\n\n";
    
    echo "[1] Checking Docker Container Network Mode:\n";
    $netMode = trim($ssh->executeCommand("docker inspect nk-awg-v2 --format='{{.HostConfig.NetworkMode}}' 2>/dev/null || echo 'NOT FOUND'"));
    echo "Network Mode: $netMode\n\n";
    
    echo "[2] Checking interfaces on the host:\n";
    $interfaces = $ssh->executeCommand("ip -brief link show", true);
    echo $interfaces . "\n";
    
    echo "[3] Checking IP rules:\n";
    $rules = $ssh->executeCommand("ip rule show", true);
    echo $rules . "\n";
    
    echo "[4] Checking 'warp' routing table:\n";
    $warpRoutes = $ssh->executeCommand("ip route show table warp", true);
    echo $warpRoutes . "\n";
    
    echo "[5] Checking nftables inet nkcore ruleset:\n";
    $nftnkcore = $ssh->executeCommand("nft list table inet nkcore 2>/dev/null || echo 'table inet nkcore not found'", true);
    echo $nftnkcore . "\n";
    
    echo "[6] Checking nftables ip nat ruleset:\n";
    $nftnat = $ssh->executeCommand("nft list table ip nat 2>/dev/null || echo 'table ip nat not found'", true);
    echo $nftnat . "\n";
    
    echo "[7] Checking tail of nk-awg-v2 logs:\n";
    $logs = $ssh->executeCommand("docker logs --tail 15 nk-awg-v2 2>&1 || echo 'failed to get logs'", true);
    echo $logs . "\n";
    
} catch (\Throwable $e) {
    echo "❌ Error running diagnostics: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
