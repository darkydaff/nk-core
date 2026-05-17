<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/BeszelClient.php';

Config::load(__DIR__ . '/../.env');

$beszel = new BeszelClient();
$systems = $beszel->getSystems();

echo "=== BESZEL SYSTEMS INVENTORY ===\n";
if (empty($systems)) {
    echo "❌ No systems returned from Beszel (empty array or auth failure).\n";
} else {
    foreach ($systems as $sys) {
        echo "ID: " . ($sys['id'] ?? 'N/A') . "\n";
        echo "Name: " . ($sys['name'] ?? 'N/A') . "\n";
        echo "Host: " . ($sys['host'] ?? 'N/A') . "\n";
        echo "Status: " . ($sys['status'] ?? 'N/A') . "\n";
        echo "---------------------------\n";
    }
}
