<?php
declare(strict_types=1);

/**
 * NK-Core Outbound Telemetry Ingest Script
 * Path: public/api/telemetry.php
 * 
 * Bypasses full MVC boots, translations, Twig, sessions, and CSRF chains for 
 * ultra-low boot latency, predictable throughput, and sub-millisecond execution times.
 */

// Disable sessions and output buffers completely for maximum speed
session_write_close();
ini_set('output_buffering', '0');

$startTime = microtime(true);

header('Content-Type: text/plain; charset=UTF-8');

// A. PRE-FLIGHT PROTECTION: Reject giant or abusive payloads immediately before parsing
if (!isset($_SERVER['CONTENT_LENGTH']) || (int)$_SERVER['CONTENT_LENGTH'] > 1024 * 1024) {
    http_response_code(413);
    echo "30"; // Force abusive node agents to back off
    exit;
}

// 1. Fast boot minimal dependencies
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../inc/Config.php';
require_once __DIR__ . '/../../inc/DB.php';
require_once __DIR__ . '/../../inc/Enums.php';
require_once __DIR__ . '/../../inc/VpnClient.php';
require_once __DIR__ . '/../../inc/EventBus.php';
require_once __DIR__ . '/../../inc/Logger.php';
require_once __DIR__ . '/../../inc/JWT.php';

// Load environment configuration
Config::load(__DIR__ . '/../../.env');

// Set default timezone to Moscow (GMT+3) to match the rest of the application and database connection
date_default_timezone_set('Europe/Moscow');

// 2. Validate token header (allow X-Telemetry-Token or Authorization Bearer fallback)
$token = $_SERVER['HTTP_X_TELEMETRY_TOKEN'] ?? '';
if (empty($token) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    if (preg_match('/Bearer\s+(.+)/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
        $token = trim($matches[1]);
    }
}

if (empty($token) || strlen($token) !== 64) {
    http_response_code(400);
    echo "15";
    exit;
}

$json = file_get_contents('php://input');
$payload = json_decode($json, true);

if (!is_array($payload) || !isset($payload['peers']) || !is_array($payload['peers'])) {
    http_response_code(400);
    echo "15";
    exit;
}

// B. DATABASE CIRCUIT BREAKER: Gracefully handle DB outages without propagating container errors
try {
    $db = DB::conn();
} catch (\Throwable $dbException) {
    if (class_exists('Logger')) {
        \Logger::error("Telemetry Ingestion DB Circuit Breaker active: " . $dbException->getMessage());
    }
    http_response_code(503);
    echo "30"; // Back-off node telemetry pushing until DB is responsive
    exit;
}

try {
    // 3. Authenticate server node token via indexed search
    $serverStmt = $db->prepare("SELECT id, telemetry_mode, UNIX_TIMESTAMP(last_telemetry_at) as last_telemetry_ts, server_health_score, consecutive_active_ticks, consecutive_idle_ticks, telemetry_state, replayed_packets_count, control_loop_damping FROM vpn_servers WHERE telemetry_token = ? AND deleted_at IS NULL LIMIT 1");
    $serverStmt->execute([$token]);
    $server = $serverStmt->fetch(PDO::FETCH_ASSOC);

    if (!$server) {
        http_response_code(401);
        echo "15";
        exit;
    }

    if ($server['telemetry_mode'] !== 'push') {
        // Strict Telemetry Authority Lock: Push is disabled/locked for this server
        http_response_code(403);
        echo "15"; // Back off pushing until mode is promoted to push
        exit;
    }

    $serverId = (int)$server['id'];
    $peers = $payload['peers'];
    $timestamp = (int)($payload['timestamp'] ?? time());
    $currentHealth = (int)($server['server_health_score'] ?? 100);

    // Load dynamic baselines for rolling drift detection
    $baselineStmt = $db->prepare("SELECT metric_name, baseline_value FROM telemetry_baselines WHERE server_id = ?");
    $baselineStmt->execute([$serverId]);
    $baselines = $baselineStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $baseIngest = (float)($baselines['ingest_latency'] ?? 10.0);
    $baseDb = (float)($baselines['db_time'] ?? 8.0);
    $baseCent = (float)($baselines['centrifugo_time'] ?? 2.0);

    // Calculate dynamic SLO threshold limits (guarded by minimum absolute thresholds)
    $sloIngestLimit = max(20.0, $baseIngest * 2.0);
    $sloDbLimit = max(15.0, $baseDb * 2.5);
    $sloCentLimit = max(10.0, $baseCent * 2.0);

    // C. REPLAY & MONOTONIC TIMESTAMP PROTECTION: Guard against out-of-order/replayed packages
    if (!empty($server['last_telemetry_ts'])) {
        $lastTelemetryTime = (int)$server['last_telemetry_ts'];
        if ($timestamp <= $lastTelemetryTime) {
            $failureReasons = ["replay_detected"];
            $decisionPath = [
                "base_health=100 initialized",
                "timestamp_replay_detected=true (timestamp={$timestamp} <= last_telemetry_at={$lastTelemetryTime}), penalty=-10 applied, request discarded"
            ];
            $db->prepare("
                UPDATE vpn_servers 
                SET replayed_packets_count = replayed_packets_count + 1,
                    server_health_score = GREATEST(0, CAST(server_health_score AS SIGNED) - 10),
                    last_failure_reasons = ?,
                    last_decision_path = ?
                WHERE id = ?
            ")->execute([json_encode($failureReasons), json_encode($decisionPath), $serverId]);
            
            // Log deterministic replay event
            try {
                $db->prepare("
                    INSERT INTO telemetry_replay_logs (server_id, payload, status, latency_ms)
                    VALUES (?, ?, 'replayed', ?)
                ")->execute([$serverId, $json, (microtime(true) - $startTime) * 1000.0]);
            } catch (\Throwable $e) {}

            header("X-Telemetry-Decision-Path: " . json_encode($decisionPath));
            echo "15";
            exit;
        }
    }

    // 4. ADAPTIVE telemetry interval: Query Centrifugo Presence API
    // D. CENTRIFUGO CIRCUIT BREAKER: Isolate WebSocket network failures from rolling back metric transactions
    $channelName = "server:telemetry:{$serverId}";
    $isActivelyViewed = false;
    $centrifugoCBActive = false;
    $centrifugoDuration = 0.0;
    
    $decisionPath = ["base_health=100 initialized"];

    $centStart = microtime(true);
    try {
        $mockCentDelay = (int)($_SERVER['HTTP_X_MOCK_CENTRIFUGO_DELAY'] ?? 0);
        if ($mockCentDelay > 0) { usleep($mockCentDelay * 1000); }
        $isActivelyViewed = EventBus::hasActiveSubscribers($channelName);
        $centrifugoDuration += (microtime(true) - $centStart) * 1000.0;
        $decisionPath[] = "centrifugo_presence_latency=" . round($centrifugoDuration, 2) . "ms, active_subscribers=" . ($isActivelyViewed ? 'true' : 'false');
    } catch (\Throwable $centrifugeEx) {
        $isActivelyViewed = false; // Fallback gracefully to idle mode
        $centrifugoCBActive = true;
        $centrifugoDuration += (microtime(true) - $centStart) * 1000.0;
        $decisionPath[] = "centrifugo_cb_active=true, presence API check failed, fallback to idle, penalty=-20 applied";
        if (class_exists('Logger')) {
            \Logger::error("Centrifugo Presence Circuit Breaker active: " . $centrifugeEx->getMessage());
        }
    }
    
    // Apply state hysteresis to eliminate interval thrashing
    $consecutiveActive = (int)($server['consecutive_active_ticks'] ?? 0);
    $consecutiveIdle = (int)($server['consecutive_idle_ticks'] ?? 0);

    if ($isActivelyViewed) {
        $consecutiveIdle = 0;
        $consecutiveActive++;
        $nextInterval = ($consecutiveActive >= 3) ? 5 : 15;
        $decisionPath[] = "hysteresis_active_ticks={$consecutiveActive}, consecutive active ticks required >= 3 for ACTIVE_5S state, current_interval={$nextInterval}";
    } else {
        $consecutiveActive = 0;
        $consecutiveIdle++;
        $nextInterval = ($consecutiveIdle >= 3) ? 15 : 5;
        $decisionPath[] = "hysteresis_idle_ticks={$consecutiveIdle}, consecutive idle ticks required >= 3 for IDLE_15S state, current_interval={$nextInterval}";
    }

    // 5. Batch SELECT active clients on this server in one query (incorporating cached previous metrics columns)
    $clientsStmt = $db->prepare("
        SELECT id, public_key, bytes_sent, bytes_received, 
               last_bytes_sent, last_bytes_received, UNIX_TIMESTAMP(last_metric_at) as last_ts,
               external_ip, ip_country
        FROM vpn_clients 
        WHERE server_id = ? AND deleted_at IS NULL
    ");
    $clientsStmt->execute([$serverId]);
    
    $clients = [];
    $clientIds = [];
    while ($row = $clientsStmt->fetch(PDO::FETCH_ASSOC)) {
        $clients[$row['public_key']] = $row;
        $clientIds[] = (int)$row['id'];
    }

    if (empty($clientIds)) {
        // Deferred Heartbeat update for empty servers
        $totalIngestTime = (microtime(true) - $startTime) * 1000.0;
        $db->prepare("
            UPDATE vpn_servers 
            SET last_telemetry_at = NOW(),
                last_ingest_latency_ms = ?,
                total_ingest_count = total_ingest_count + 1
            WHERE id = ?
        ")->execute([$totalIngestTime, $serverId]);

        echo (string)$nextInterval;
        exit;
    }

    // E. DYNAMIC SELF-HEALING BACKPRESSURE DETECTION
    $elapsed = microtime(true) - $startTime;
    $backpressureActive = ($elapsed > 0.100); 
    
    if ($backpressureActive) {
        $nextInterval = 30; // Push remote nodes into back-off mode
        $decisionPath[] = "db_ingest_time=" . round($elapsed * 1000, 2) . "ms > 100ms threshold, db_backpressure_active=true, penalty=-25 applied, next_state=BACKPRESSURE_30S";
        if (class_exists('Logger')) {
            \Logger::warning("Telemetry Backpressure Active (Latency: " . round($elapsed * 1000, 2) . "ms). Metric history writes suspended for server #{$serverId}.");
        }
    } else {
        $decisionPath[] = "db_ingest_time=" . round($elapsed * 1000, 2) . "ms <= 100ms threshold, backpressure system cleared";
    }

    // 7. Compute Deltas, Speeds, and Execute Batch Updates inside a single Transaction
    $dbStart = microtime(true);
    $db->beginTransaction();
    $mockDbDelay = (int)($_SERVER['HTTP_X_MOCK_DB_DELAY'] ?? 0);
    if ($mockDbDelay > 0) { usleep($mockDbDelay * 1000); }

    $updateClientStmt = $db->prepare("
        UPDATE vpn_clients 
        SET bytes_sent = bytes_sent + :diff_sent,
            bytes_received = bytes_received + :diff_received,
            speed_up_kbps = :speed_up,
            speed_down_kbps = :speed_down,
            last_handshake = :handshake,
            external_ip = :ext_ip,
            last_sync_at = NOW(),
            last_bytes_sent = :sent,
            last_bytes_received = :received,
            last_metric_at = FROM_UNIXTIME(:ts)
        WHERE id = :id
    ");

    $insertMetricStmt = $db->prepare("
        INSERT INTO client_metrics 
        (client_id, bytes_sent, bytes_received, speed_up_kbps, speed_down_kbps)
        VALUES (?, ?, ?, ?, ?)
    ");

    $updateGeoStmt = $db->prepare("
        UPDATE vpn_clients 
        SET ip_country = ?, 
            ip_country_code = ?, 
            ip_city = ?, 
            ip_isp = ?, 
            ip_org = ?,
            ip_lat = ?, 
            ip_lon = ?
        WHERE id = ?
    ");

    $realtimeEvents = [];

    foreach ($peers as $peer) {
        $pubKey = $peer['public_key'];
        if (!isset($clients[$pubKey])) continue;

        $client = $clients[$pubKey];
        $clientId = (int)$client['id'];

        $sent = (float)($peer['bytes_sent'] ?? 0);
        $received = (float)($peer['bytes_received'] ?? 0);
        
        $diffSent = 0.0;
        $diffReceived = 0.0;
        $speedUp = 0.0;
        $speedDown = 0.0;

        // O(1) Prev Stats Lookup directly from the primary client row (no subquery or self-joins!)
        if ($client['last_ts'] > 0) {
            $timeDiff = $timestamp - (int)$client['last_ts'];
            
            if ($timeDiff > 0 && $timeDiff < 3600) {
                // Detect Counter Resets
                if ($sent < (float)$client['last_bytes_sent'] || $received < (float)$client['last_bytes_received']) {
                    $diffSent = $sent;
                    $diffReceived = $received;
                } else {
                    $diffSent = $sent - (float)$client['last_bytes_sent'];
                    $diffReceived = $received - (float)$client['last_bytes_received'];
                }

                if ($diffSent < 0) $diffSent = 0;
                if ($diffReceived < 0) $diffReceived = 0;

                $speedUp = round(($diffSent * 8) / $timeDiff / 1000, 2);
                $speedDown = round(($diffReceived * 8) / $timeDiff / 1000, 2);
            }
        }

        $handshakeStr = $peer['latest_handshake'] > 0 ? date('Y-m-d H:i:s', $peer['latest_handshake']) : null;

        // Execute batch updates on hot clients table and update the telemetry cache fields
        $updateClientStmt->execute([
            'diff_sent' => $diffSent,
            'diff_received' => $diffReceived,
            'speed_up' => $speedUp,
            'speed_down' => $speedDown,
            'handshake' => $handshakeStr,
            'ext_ip' => $peer['endpoint_ip'] ?? null,
            'sent' => $sent,
            'received' => $received,
            'ts' => $timestamp,
            'id' => $clientId
        ]);

        // Automatically fetch and update GeoIP metadata when external IP changes or is missing
        $newExternalIp = $peer['endpoint_ip'] ?? null;
        if (!empty($newExternalIp) && $newExternalIp !== '(none)') {
            if ($newExternalIp !== ($client['external_ip'] ?? null) || empty($client['ip_country'])) {
                try {
                    $geoData = VpnClient::lookupIpGeo($newExternalIp);
                    if ($geoData) {
                        $updateGeoStmt->execute([
                            $geoData['country'] ?? null,
                            $geoData['countryCode'] ?? null,
                            $geoData['city'] ?? null,
                            $geoData['isp'] ?? null,
                            $geoData['org'] ?? null,
                            $geoData['lat'] ?? null,
                            $geoData['lon'] ?? null,
                            $clientId
                        ]);
                    }
                } catch (\Throwable $geoE) {
                    if (class_exists('Logger')) {
                        \Logger::warning("Fast-path GeoIP failed for client {$clientId}: " . $geoE->getMessage());
                    }
                }
            }
        }

        // Guard: Skip metrics history writes when under DB backpressure to save disk I/O
        if (!$backpressureActive) {
            $insertMetricStmt->execute([$clientId, $sent, $received, $speedUp, $speedDown]);
        }

        // Only compile real-time event array if Centrifugo has active subscribers
        if ($isActivelyViewed) {
            $realtimeEvents[] = [
                'id' => $clientId,
                'up' => VpnClient::formatSpeed($speedUp),
                'down' => VpnClient::formatSpeed($speedDown),
                'traffic' => VpnClient::formatBytes((float)$client['bytes_sent'] + $diffSent + (float)$client['bytes_received'] + $diffReceived),
                'online' => ($peer['latest_handshake'] > 0 && ($timestamp - $peer['latest_handshake']) < 300),
                'seen' => $handshakeStr ? 'Active' : 'Never'
            ];
        }
    }

    $db->commit();
    $dbDuration = (microtime(true) - $dbStart) * 1000.0;

    // 8. Broadcast real-time delta updates to browser UI via Centrifugo
    // Protected by separate catch block to ensure Centrifugo outages do not affect metrics
    if ($isActivelyViewed && !empty($realtimeEvents)) {
        $centPublishStart = microtime(true);
        try {
            EventBus::publish($channelName, [
                'server_id' => $serverId,
                'timestamp' => $timestamp,
                'clients' => $realtimeEvents
            ]);
            $centrifugoDuration += (microtime(true) - $centPublishStart) * 1000.0;
        } catch (\Throwable $centrifugePublishEx) {
            $centrifugoCBActive = true;
            $centrifugoDuration += (microtime(true) - $centPublishStart) * 1000.0;
            if (class_exists('Logger')) {
                \Logger::error("Centrifugo Publish Circuit Breaker active: " . $centrifugePublishEx->getMessage());
            }
        }
    }

    // 9. DYNAMIC SLO CONTRACTS & FAILURE CLASSIFICATION
    $totalIngestTime = (microtime(true) - $startTime) * 1000.0;
    
    $failureReasons = [];
    $healthScore = 100;

    // A. Dynamic Ingest Latency SLO: dynamic threshold (rolling baseline * 2.0 or 20ms guard)
    if ($totalIngestTime > $sloIngestLimit) {
        $failureReasons[] = 'ingest_latency_high';
        $healthScore -= 10;
        $decisionPath[] = "total_ingest_time=" . round($totalIngestTime, 2) . "ms > SLO threshold=" . round($sloIngestLimit, 2) . "ms (baseline=" . round($baseIngest, 2) . "ms), ingest_latency_high penalty=-10 applied";
    } else {
        $decisionPath[] = "total_ingest_time=" . round($totalIngestTime, 2) . "ms <= SLO threshold=" . round($sloIngestLimit, 2) . "ms, ingest_latency compliant";
    }

    // B. Dynamic DB Ingest Transaction SLO: dynamic threshold (rolling baseline * 2.5 or 15ms guard)
    if ($dbDuration > $sloDbLimit) {
        $failureReasons[] = 'db_latency_high';
        $healthScore -= 15;
        $decisionPath[] = "db_write_latency=" . round($dbDuration, 2) . "ms > SLO threshold=" . round($sloDbLimit, 2) . "ms (baseline=" . round($baseDb, 2) . "ms), db_latency_high penalty=-15 applied";
    } else {
        $decisionPath[] = "db_write_latency=" . round($dbDuration, 2) . "ms <= SLO threshold=" . round($sloDbLimit, 2) . "ms, db_write compliant";
    }

    // C. Dynamic WebSocket Publish SLO: dynamic threshold (rolling baseline * 2.0 or 10ms guard)
    if ($centrifugoDuration > $sloCentLimit) {
        $failureReasons[] = 'centrifugo_latency_high';
        $healthScore -= 10;
        $decisionPath[] = "centrifugo_publish_latency=" . round($centrifugoDuration, 2) . "ms > SLO threshold=" . round($sloCentLimit, 2) . "ms (baseline=" . round($baseCent, 2) . "ms), centrifugo_latency_high penalty=-10 applied";
    } else {
        $decisionPath[] = "centrifugo_publish_latency=" . round($centrifugoDuration, 2) . "ms <= SLO threshold=" . round($sloCentLimit, 2) . "ms, centrifugo compliant";
    }

    // D. Dependency Outages (Circuit Breakers)
    if ($centrifugoCBActive) {
        $failureReasons[] = 'centrifugo_outage';
        $healthScore -= 20;
    }
    if ($backpressureActive) {
        $failureReasons[] = 'backpressure_active';
        $healthScore -= 25;
    }

    $healthScore = max(0, $healthScore);
    $decisionPath[] = "final_health_score={$healthScore}";

    // Control Loop Damping Ratio Coefficient
    $damping = $backpressureActive ? 0.2 : ($consecutiveIdle >= 3 ? 1.0 : 0.6);
    
    // Loop Gain Bound calculation
    $gainPresence = $isActivelyViewed ? 0.3 : 0.1;
    $gainBackpressure = $backpressureActive ? 0.5 : 0.0;
    $replayedPacketsCount = (int)($server['replayed_packets_count'] ?? 0);
    $gainReplays = ($replayedPacketsCount > 0) ? 0.15 : 0.0;
    $loopGain = $gainPresence + $gainBackpressure + $gainReplays;
    
    $decisionPath[] = "loop_gain_bound=" . round($loopGain, 2) . " (< 1.0 stable target), loop_damping_ratio=" . round($damping, 2) . " (1.0 = stable oscillator, <0.4 = volatile underdamped)";

    // Control Loop State Machine Transition Tracking
    $currentTelemetryState = $server['telemetry_state'] ?? 'IDLE_15S';
    $targetTelemetryState = 'IDLE_15S';
    if ($nextInterval === 30) {
        $targetTelemetryState = 'BACKPRESSURE_30S';
    } elseif ($nextInterval === 5) {
        $targetTelemetryState = 'ACTIVE_5S';
    }

    if ($currentTelemetryState !== $targetTelemetryState) {
        $triggerEvent = 'presence_idle';
        $instabilityWeight = 0.25;
        
        if ($targetTelemetryState === 'BACKPRESSURE_30S') {
            $triggerEvent = 'db_latency_high';
            $instabilityWeight = 0.75;
        } elseif ($currentTelemetryState === 'BACKPRESSURE_30S') {
            $triggerEvent = 'db_latency_resolved';
            $instabilityWeight = 0.50;
        } elseif ($targetTelemetryState === 'ACTIVE_5S') {
            $triggerEvent = 'presence_active';
            $instabilityWeight = 0.25;
        }
        
        try {
            $db->prepare("
                INSERT INTO telemetry_state_transitions (server_id, from_state, to_state, trigger_event, instability_weight)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([$serverId, $currentTelemetryState, $targetTelemetryState, $triggerEvent, $instabilityWeight]);
        } catch (\Throwable $e) {}
    }

    // Fetch last 10 transitions to calculate Shannon loop entropy
    $historyStmt = $db->prepare("
        SELECT from_state, to_state 
        FROM telemetry_state_transitions 
        WHERE server_id = ? 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $historyStmt->execute([$serverId]);
    $history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

    $states = [];
    foreach ($history as $h) {
        $states[$h['to_state']] = ($states[$h['to_state']] ?? 0) + 1;
        $states[$h['from_state']] = ($states[$h['from_state']] ?? 0) + 1;
    }
    
    $loopEntropy = 0.0;
    $totalStatesCount = array_sum($states);
    if ($totalStatesCount > 0) {
        foreach ($states as $sName => $count) {
            $p = $count / $totalStatesCount;
            $loopEntropy -= $p * log($p, 2);
        }
    }
    $decisionPath[] = "loop_entropy_score=" . round($loopEntropy, 2) . " (0.0 = completely stable, >1.0 = highly thrashed/volatile)";

    // Calculate Baseline Drift Index
    $driftIngest = $baseIngest > 0 ? abs($totalIngestTime - $baseIngest) / $baseIngest : 0.0;
    $driftDb = $baseDb > 0 ? abs($dbDuration - $baseDb) / $baseDb : 0.0;
    $driftCent = $baseCent > 0 ? abs($centrifugoDuration - $baseCent) / $baseCent : 0.0;
    $driftIndex = (($driftIngest + $driftDb + $driftCent) / 3.0) * 100.0; // percentage

    // Defer baseline updates using exponential moving average (EMA)
    $newBaseIngest = $baseIngest * 0.95 + $totalIngestTime * 0.05;
    $newBaseDb = $baseDb * 0.95 + $dbDuration * 0.05;
    $newBaseCent = $baseCent * 0.95 + $centrifugoDuration * 0.05;

    // Save baseline updates
    try {
        $saveBaselineStmt = $db->prepare("
            INSERT INTO telemetry_baselines (server_id, metric_name, baseline_value, sample_count)
            VALUES (?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE 
                baseline_value = VALUES(baseline_value),
                sample_count = sample_count + 1
        ");
        $saveBaselineStmt->execute([$serverId, 'ingest_latency', $newBaseIngest]);
        $saveBaselineStmt->execute([$serverId, 'db_time', $newBaseDb]);
        $saveBaselineStmt->execute([$serverId, 'centrifugo_time', $newBaseCent]);
    } catch (\Throwable $e) {}

    // 10. DEFERRED DIAGNOSTICS & SYSTEM METRICS REGISTRY WRITE (Single row write update!)
    $db->prepare("
        UPDATE vpn_servers 
        SET last_telemetry_at = NOW(),
            last_ingest_latency_ms = ?,
            last_db_time_ms = ?,
            last_centrifugo_time_ms = ?,
            total_ingest_count = total_ingest_count + 1,
            backpressure_count = backpressure_count + ?,
            circuit_breaker_count = circuit_breaker_count + ?,
            server_health_score = ?,
            consecutive_active_ticks = ?,
            consecutive_idle_ticks = ?,
            last_failure_reasons = ?,
            telemetry_state = ?,
            last_decision_path = ?,
            loop_entropy = ?,
            baseline_drift_index = ?,
            control_loop_damping = ?
        WHERE id = ?
    ")->execute([
        $totalIngestTime,
        $dbDuration,
        $centrifugoDuration,
        $backpressureActive ? 1 : 0,
        $centrifugoCBActive ? 1 : 0,
        $healthScore,
        $consecutiveActive,
        $consecutiveIdle,
        empty($failureReasons) ? null : json_encode($failureReasons),
        $targetTelemetryState,
        json_encode($decisionPath),
        $loopEntropy,
        $driftIndex,
        $damping,
        $serverId
    ]);

    // 11. RECORD IN REPLAY LOG (Only when actively monitored or on sampling / failure to prevent DB bloat)
    $shouldLogReplay = $isActivelyViewed || !empty($failureReasons) || (mt_rand(1, 10) === 1);
    if ($shouldLogReplay) {
        try {
            $db->prepare("
                INSERT INTO telemetry_replay_logs (server_id, payload, status, latency_ms)
                VALUES (?, ?, 'captured', ?)
            ")->execute([$serverId, $json, $totalIngestTime]);
        } catch (\Throwable $e) {}
    }

    // Output decision path in header
    header("X-Telemetry-Decision-Path: " . json_encode($decisionPath));

    // Output plain text next interval directly for zero-parsing remote node consumption
    echo (string)$nextInterval;

} catch (\Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    // Fail gracefully back to default interval
    http_response_code(500);
    echo "15";
}
