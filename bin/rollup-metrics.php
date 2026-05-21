#!/usr/bin/env php
<?php

/**
 * Metric Downsampling & Rollup Cron Worker
 * 
 * Aggregates high-frequency client metrics into hourly and daily rollups,
 * then prunes historical raw entries to protect indices from bloat.
 * 
 * Recommended execution: Run hourly via crontab:
 * 0 * * * * php /home/darkydaff/github/nk-core/bin/rollup-metrics.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/Logger.php';

// Load environment configuration
Config::load(__DIR__ . '/../.env');

// Set timezone (align with panel-side aggregation targets)
date_default_timezone_set('Europe/Moscow');

// Enable CLI error output
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "[" . date('Y-m-d H:i:s') . "] Starting Metrics Rollup & Downsampling Task...\n";

try {
    $db = DB::conn();
    $db->beginTransaction();

    // 1. Rollup raw client_metrics older than 30 minutes into client_hourly_metrics
    echo " -> Compiling hourly client aggregates from raw telemetry...\n";
    $hourlyStmt = $db->exec("
        INSERT INTO client_hourly_metrics 
        (client_id, bytes_sent_delta, bytes_received_delta, peak_speed_up_kbps, peak_speed_down_kbps, recorded_hour)
        SELECT 
            client_id,
            CAST(GREATEST(0, MAX(bytes_sent) - MIN(bytes_sent)) AS UNSIGNED) as bytes_sent_delta,
            CAST(GREATEST(0, MAX(bytes_received) - MIN(bytes_received)) AS UNSIGNED) as bytes_received_delta,
            MAX(speed_up_kbps) as peak_speed_up_kbps,
            MAX(speed_down_kbps) as peak_speed_down_kbps,
            DATE_FORMAT(collected_at, '%Y-%m-%d %H:00:00') as recorded_hour
        FROM client_metrics
        WHERE collected_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        GROUP BY client_id, DATE_FORMAT(collected_at, '%Y-%m-%d %H:00:00')
        ON DUPLICATE KEY UPDATE 
            bytes_sent_delta = VALUES(bytes_sent_delta),
            bytes_received_delta = VALUES(bytes_received_delta),
            peak_speed_up_kbps = GREATEST(peak_speed_up_kbps, VALUES(peak_speed_up_kbps)),
            peak_speed_down_kbps = GREATEST(peak_speed_down_kbps, VALUES(peak_speed_down_kbps))
    ");
    echo "    Aggregated $hourlyStmt hourly client metrics.\n";

    // 2. Rollup client_hourly_metrics older than 24 hours into client_daily_metrics
    echo " -> Compiling daily analytics summaries...\n";
    $dailyStmt = $db->exec("
        INSERT INTO client_daily_metrics 
        (client_id, bytes_sent_delta, bytes_received_delta, peak_speed_up_kbps, peak_speed_down_kbps, recorded_day)
        SELECT 
            client_id,
            SUM(bytes_sent_delta) as bytes_sent_delta,
            SUM(bytes_received_delta) as bytes_received_delta,
            MAX(peak_speed_up_kbps) as peak_speed_up_kbps,
            MAX(peak_speed_down_kbps) as peak_speed_down_kbps,
            DATE(recorded_hour) as recorded_day
        FROM client_hourly_metrics
        WHERE recorded_hour < DATE_SUB(NOW(), INTERVAL 1 DAY)
        GROUP BY client_id, DATE(recorded_hour)
        ON DUPLICATE KEY UPDATE 
            bytes_sent_delta = VALUES(bytes_sent_delta),
            bytes_received_delta = VALUES(bytes_received_delta),
            peak_speed_up_kbps = GREATEST(peak_speed_up_kbps, VALUES(peak_speed_up_kbps)),
            peak_speed_down_kbps = GREATEST(peak_speed_down_kbps, VALUES(peak_speed_down_kbps))
    ");
    echo "    Aggregated $dailyStmt daily client metrics.\n";

    // 3. Prune old high-frequency raw data (older than 30 minutes)
    echo " -> Pruning high-frequency raw telemetry records older than 30 minutes...\n";
    $prunedRaw = $db->exec("DELETE FROM client_metrics WHERE collected_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
    echo "    Pruned $prunedRaw raw telemetry entries.\n";

    // 4. Prune hourly rollups older than 30 days (daily rollups are kept permanently)
    echo " -> Pruning hourly rollup tables older than 30 days...\n";
    $prunedHourly = $db->exec("DELETE FROM client_hourly_metrics WHERE recorded_hour < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    echo "    Pruned $prunedHourly hourly metric entries.\n";

    $db->commit();
    echo "[" . date('Y-m-d H:i:s') . "] Metrics rollup task completed successfully.\n";

} catch (\Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
    \Logger::error("Metrics Rollup Cron Job failed: " . $e->getMessage());
    exit(1);
}
