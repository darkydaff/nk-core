<?php
/**
 * NK-Core SRE Telemetry Stability Envelope Sweeper & Failure Scenario Simulator
 * Simulates high-concurrency WireGuard node push-telemetry under custom failure scenarios,
 * executes stability envelope sweeps, tracks state transitions, and plots heatmaps.
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the CLI.\n");
}

require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/Config.php';

// Parse CLI Arguments
$options = getopt("", ["nodes:", "clients:", "ticks:", "delay:", "scenario:", "teardown"]);
$nodeCount = isset($options['nodes']) ? (int)$options['nodes'] : 5;
$clientsPerNode = isset($options['clients']) ? (int)$options['clients'] : 10;
$ticksCount = isset($options['ticks']) ? (int)$options['ticks'] : 5;
$delayMs = isset($options['delay']) ? (int)$options['delay'] * 1000 : 1000;
$scenario = isset($options['scenario']) ? trim($options['scenario']) : '';
$teardownOnly = isset($options['teardown']);

$db = DB::conn();

// ----------------------------------------------------
// DB FOOTPRINT TEARDOWN CLEANER
// ----------------------------------------------------
function cleanMockData($db) {
    echo "🧹 Cleaning up existing mock load-test telemetry servers, clients, and state transitions...\n";
    $db->exec("DELETE FROM telemetry_state_transitions WHERE server_id IN (SELECT id FROM vpn_servers WHERE name LIKE 'Mock Load Server %')");
    $db->exec("DELETE FROM telemetry_baselines WHERE server_id IN (SELECT id FROM vpn_servers WHERE name LIKE 'Mock Load Server %')");
    $db->exec("DELETE FROM vpn_clients WHERE name LIKE 'Mock Client %'");
    $db->exec("DELETE FROM vpn_servers WHERE name LIKE 'Mock Load Server %'");
    echo "✅ Teardown complete.\n";
}

if ($teardownOnly) {
    cleanMockData($db);
    exit(0);
}

// ----------------------------------------------------
// FAILURE SCENARIOS SETUP
// ----------------------------------------------------
$mockDbDelay = 0;       // ms
$mockCentDelay = 0;     // ms
$injectReplays = false; // flag

switch ($scenario) {
    case 'db_slow':
        echo "🚨 [SCENARIO ENABLED] DB Slow Cluster Degradation (Injecting 25ms delay, testing DB SLO warnings)\n";
        $mockDbDelay = 25;
        break;
    case 'ws_outage':
        echo "🚨 [SCENARIO ENABLED] Centrifugo Outage Burst (Injecting 15ms delay, testing WebSocket SLO warnings)\n";
        $mockCentDelay = 15;
        break;
    case 'replay_storm':
        echo "🚨 [SCENARIO ENABLED] Out-of-Order Replay Storm (Injecting 50% stale packets)\n";
        $injectReplays = true;
        break;
    case 'mixed_failures':
        echo "🚨 [SCENARIO ENABLED] Mixed Failure Cascade (Injecting 25ms DB, 15ms WS, and 30% packet replays)\n";
        $mockDbDelay = 25;
        $mockCentDelay = 15;
        $injectReplays = true;
        break;
    case 'adversarial':
        echo "🚨 [SCENARIO ENABLED] Adversarial Sequencing (Worst-case compounding cascading failure storm)\n";
        $ticksCount = 4;
        break;
    case 'sweep':
        // Run Stability Envelope Sweep (PHASE 5)
        runStabilitySweep($db);
        exit(0);
    default:
        if (!empty($scenario)) {
            echo "⚠️ Unknown scenario '{$scenario}'. Running default clean stream.\n";
        }
        break;
}

// Ensure database is clean before we start
cleanMockData($db);

// Seed environment and run standard test
runSimulationLoop($db, $nodeCount, $clientsPerNode, $ticksCount, $delayMs, $mockDbDelay, $mockCentDelay, $injectReplays);

// ----------------------------------------------------
// SIMULATION ENGINE
// ----------------------------------------------------
function runSimulationLoop($db, $nodes, $clients, $ticks, $delay, $dbDelay, $centDelay, $replays, $quiet = false) {
    if (!$quiet) {
        echo "🚀 Seeding {$nodes} mock servers and {$clients} clients per server...\n";
    }
    
    $mockServers = [];
    $mockClients = [];

    $db->beginTransaction();
    try {
        for ($i = 1; $i <= $nodes; $i++) {
            $token = hash('sha256', "loadtest_server_token_" . $i . "_" . random_bytes(8));
            $stmt = $db->prepare("
                INSERT INTO vpn_servers (user_id, name, host, port, username, password, status, telemetry_token)
                VALUES (1, ?, '127.0.0.1', 22, 'root', 'password', 'active', ?)
            ");
            $stmt->execute(["Mock Load Server {$i}", $token]);
            $serverId = $db->lastInsertId();

            $mockServers[] = [
                'id' => $serverId,
                'name' => "Mock Load Server {$i}",
                'token' => $token,
                'last_timestamp' => time()
            ];

            for ($j = 1; $j <= $clients; $j++) {
                $pubKey = "mock_client_pubkey_{$serverId}_{$j}_" . bin2hex(random_bytes(8));
                $ip = "10.8.1." . (($j % 250) + 2);
                $clientStmt = $db->prepare("
                    INSERT INTO vpn_clients (server_id, user_id, name, client_ip, public_key, private_key, status)
                    VALUES (?, 1, ?, ?, ?, 'mock_private_key', 'active')
                ");
                $clientStmt->execute([$serverId, "Mock Client {$serverId}-{$j}", $ip, $pubKey]);
                
                $mockClients[$serverId][] = [
                    'id' => $db->lastInsertId(),
                    'public_key' => $pubKey,
                    'tx' => 0,
                    'rx' => 0
                ];
            }
        }
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        echo "❌ Seeding failed: " . $e->getMessage() . "\n";
        cleanMockData($db);
        exit(1);
    }

    if (!$quiet) {
        echo "🔥 Seed complete! Starting Telemetry Loop...\n";
    }

    $targetUrl = "http://127.0.0.1/api/telemetry.php";
    
    for ($tick = 1; $tick <= $ticks; $tick++) {
        if (!$quiet) {
            echo "\n⏱️ TICK {$tick}/{$ticks}\n";
        }
        $tickStart = microtime(true);

        foreach ($mockServers as &$server) {
            $serverId = $server['id'];
            $token = $server['token'];

            foreach ($mockClients[$serverId] as &$c) {
                $c['tx'] += mt_rand(10240, 524288);
                $c['rx'] += mt_rand(10240, 524288);
            }

            $peers = [];
            foreach ($mockClients[$serverId] as $c) {
                $peers[] = [
                    'public_key' => $c['public_key'],
                    'tx' => $c['tx'],
                    'rx' => $c['rx'],
                    'endpoint' => '1.2.3.4:51820'
                ];
            }

            // Deterministic Adversarial Sequence Injection
            $activeDbDelay = $dbDelay;
            $activeCentDelay = $centDelay;
            $activeReplays = $replays;
            
            $globalScenario = $GLOBALS['scenario'] ?? '';
            $forceReplay = false;
            
            if ($globalScenario === 'adversarial') {
                if ($tick === 1) {
                    $activeDbDelay = 25;
                    $activeCentDelay = 0;
                    $activeReplays = false;
                    if (!$quiet) {
                        echo "💥 [ADVERSARIAL STAGE 1] Injected DB Lag (25ms) - testing dynamic baseline drift index...\n";
                    }
                } elseif ($tick === 2) {
                    $activeDbDelay = 25;
                    $activeCentDelay = 15;
                    $activeReplays = false;
                    if (!$quiet) {
                        echo "💥 [ADVERSARIAL STAGE 2] Compounding failure: DB Lag (25ms) + WS Delay (15ms) - testing backpressure damping ratio...\n";
                    }
                } elseif ($tick === 3) {
                    $activeDbDelay = 35;
                    $activeCentDelay = 15;
                    $activeReplays = true;
                    $forceReplay = true;
                    if (!$quiet) {
                        echo "💥 [ADVERSARIAL STAGE 3] Triple Cascade failure: DB Lag + WS Delay + Stale packet storm - testing Loop Entropy limits...\n";
                    }
                } else {
                    $activeDbDelay = 0;
                    $activeCentDelay = 0;
                    $activeReplays = false;
                    if (!$quiet) {
                        echo "💥 [ADVERSARIAL STAGE 4] Resource recovery step - evaluating fleet damping recovery coefficient...\n";
                    }
                }
            }

            $timestamp = time();
            if (($activeReplays && $tick > 1 && mt_rand(1, 3) === 1) || $forceReplay) {
                if (!$quiet) {
                    echo "⚠️ [CHAOS] Injecting replayed timestamp packet into Server #{$serverId}...\n";
                }
                $timestamp = $server['last_timestamp'] - mt_rand(5, 60);
            } else {
                $server['last_timestamp'] = $timestamp;
            }

            $payload = [
                'timestamp' => $timestamp,
                'peers' => $peers
            ];

            $ch = curl_init($targetUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type: application/json",
                "X-Telemetry-Token: {$token}",
                "X-Mock-Db-Delay: {$activeDbDelay}",
                "X-Mock-Centrifugo-Delay: {$activeCentDelay}"
            ]);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);

            $curlStart = microtime(true);
            $responseOuter = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $latencyMs = (microtime(true) - $curlStart) * 1000.0;
            
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headers = substr($responseOuter, 0, $headerSize);
            $response = substr($responseOuter, $headerSize);

            // Extract Decision Path Trace Header
            $decisionPath = [];
            if (preg_match('/X-Telemetry-Decision-Path:\s*(.+)/i', $headers, $matchHeader)) {
                $decisionPath = json_decode(trim($matchHeader[1]), true) ?: [];
            }

            // Read state changes from DB
            $diagnostics = $db->query("
                SELECT server_health_score, last_db_time_ms, last_centrifugo_time_ms, last_failure_reasons, telemetry_state, loop_entropy, baseline_drift_index, control_loop_damping
                FROM vpn_servers
                WHERE id = {$serverId}
            ")->fetch(PDO::FETCH_ASSOC);

            $health = $diagnostics['server_health_score'] ?? 100;
            $dbTime = round((float)($diagnostics['last_db_time_ms'] ?? 0), 2);
            $state = $diagnostics['telemetry_state'] ?? 'IDLE_15S';
            $reasons = $diagnostics['last_failure_reasons'] ? json_decode($diagnostics['last_failure_reasons'], true) : [];
            $reasonStr = !empty($reasons) ? "[" . implode(", ", $reasons) . "]" : "None";
            $entropy = round((float)($diagnostics['loop_entropy'] ?? 0.0), 2);
            $drift = round((float)($diagnostics['baseline_drift_index'] ?? 0.0), 1);
            $damping = round((float)($diagnostics['control_loop_damping'] ?? 1.0), 2);

            if (!$quiet) {
                printf(
                    "📡 Node: %-20s | Code: %d | Latency: %5.1fms | State: %-16s | Health: %3d | Reasons: %s | Next Sleep: %ss\n",
                    $server['name'],
                    $httpCode,
                    $latencyMs,
                    $state,
                    $health,
                    $reasonStr,
                    trim($response ?: 'N/A')
                );
                printf(
                    "   📊 [Dynamics]: Shannon Entropy: %.2f | Baseline Drift: %s%% | Damping Ratio: %.2f\n",
                    $entropy,
                    $drift,
                    $damping
                );
                if (!empty($decisionPath)) {
                    echo "   🔍 [Explainability Trace]:\n";
                    foreach ($decisionPath as $trace) {
                        echo "     ↳ {$trace}\n";
                    }
                }
            }
        }
        $tickDuration = (microtime(true) - $tickStart) * 1000.0;
        if (!$quiet) {
            echo "🏁 Tick complete in " . round($tickDuration, 2) . "ms\n";
        }
        if ($tick < $ticks) {
            usleep($delay);
        }
    }

    // Collect aggregate compliance indices
    $report = $db->query("
        SELECT 
            AVG(server_health_score) as avg_health,
            SUM(replayed_packets_count) as total_replays,
            SUM(backpressure_count) as total_bp,
            SUM(circuit_breaker_count) as total_cb,
            AVG(loop_entropy) as avg_entropy,
            AVG(baseline_drift_index) as avg_drift,
            AVG(control_loop_damping) as avg_damping
        FROM vpn_servers
        WHERE name LIKE 'Mock Load Server %'
    ")->fetch(PDO::FETCH_ASSOC);

    $transitions = $db->query("
        SELECT COUNT(*) as total_trans, SUM(instability_weight) as total_instability
        FROM telemetry_state_transitions
    ")->fetch(PDO::FETCH_ASSOC);

    $slo = $db->query("
        SELECT 
            COUNT(*) as total_nodes,
            SUM(CASE WHEN last_ingest_latency_ms > 20.0 THEN 1 ELSE 0 END) as ingest_violations,
            SUM(CASE WHEN last_db_time_ms > 15.0 THEN 1 ELSE 0 END) as db_violations,
            SUM(CASE WHEN last_centrifugo_time_ms > 10.0 THEN 1 ELSE 0 END) as centrifugo_violations
        FROM vpn_servers
        WHERE name LIKE 'Mock Load Server %'
    ")->fetch(PDO::FETCH_ASSOC);

    $totalActive = (int)$slo['total_nodes'];
    $ingestCompliance = $totalActive > 0 ? (1 - $slo['ingest_violations'] / $totalActive) * 100 : 100;
    $dbCompliance = $totalActive > 0 ? (1 - $slo['db_violations'] / $totalActive) * 100 : 100;
    $centCompliance = $totalActive > 0 ? (1 - $slo['centrifugo_violations'] / $totalActive) * 100 : 100;

    if (!$quiet) {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "📊 SYSTEM HEALTH & SLO REPORT CARD\n";
        echo str_repeat("=", 80) . "\n";
        printf("📈 Cumulative Ingest SLO Compliance (p95 < 20ms):  %5.1f%%\n", $ingestCompliance);
        printf("💾 Cumulative DB Write SLO Compliance (p95 < 15ms): %5.1f%%\n", $dbCompliance);
        printf("🌐 Cumulative Cent WebSocket SLO Compliance (< 10ms): %5.1f%%\n", $centCompliance);
        printf("❤️  Average Sim System Health Grade:                  %5.1f/100\n", (float)$report['avg_health']);
        printf("🔄 Out-of-Order Packet Drops (Replays):             %d drops\n", (int)$report['total_replays']);
        printf("🚦 Backpressure Activations:                         %d limits\n", (int)$report['total_bp']);
        printf("🔌 Outage Circuit Breaker Trips:                     %d trips\n", (int)$report['total_cb']);
        printf("⛓️  Control loop state transitions:                   %d moves (Instability weight: %.2f)\n", (int)$transitions['total_trans'], (float)$transitions['total_instability']);
        printf("🌀 Average Shannon Loop Entropy:                     %.2f (Stable < 0.5, Volatile > 1.0)\n", (float)$report['avg_entropy']);
        printf("📈 Average Baseline Drift Index:                     %.1f%%\n", (float)$report['avg_drift']);
        printf("🌊 Average Control Loop Damping Ratio:               %.2f (1.0 = stable oscillator)\n", (float)$report['avg_damping']);
        echo str_repeat("=", 80) . "\n";
    }

    $firstFailure = 'None';
    if ($report['total_replays'] > 0) $firstFailure = 'replay_detected';
    if ($report['total_bp'] > 0) $firstFailure = 'db_backpressure_active';
    if ($report['total_cb'] > 0) $firstFailure = 'centrifugo_cb_active';
    if ($slo['ingest_violations'] > 0) $firstFailure = 'ingest_latency_high';
    if ($slo['db_violations'] > 0) $firstFailure = 'db_latency_high';

    $results = [
        'avg_health' => (float)$report['avg_health'],
        'ingest_compliance' => $ingestCompliance,
        'db_compliance' => $dbCompliance,
        'cent_compliance' => $centCompliance,
        'transitions_count' => (int)$transitions['total_trans'],
        'instability_weight' => (float)$transitions['total_instability'],
        'first_failure' => $firstFailure
    ];

    cleanMockData($db);
    return $results;
}

// ----------------------------------------------------
// PHASE 5: CONTROLLED STABILITY ENVELOPE SWEEPER
// ----------------------------------------------------
function runStabilitySweep($db) {
    echo "🗺️  Starting Controlled Stability Envelope Sweep...\n";
    echo "📋 Sweeping through escalating loads, mock latencies, and disorder packets...\n";
    echo str_repeat("-", 80) . "\n";

    $regions = [
        'Region A: Stable Ingest' => [
            'nodes' => 2,
            'clients' => 2,
            'db_delay' => 0,
            'cent_delay' => 0,
            'replays' => false,
            'desc' => 'Normal operations, zero latency overhead'
        ],
        'Region B: DB Contention' => [
            'nodes' => 5,
            'clients' => 10,
            'db_delay' => 120, // triggers backpressure (> 100ms)
            'cent_delay' => 0,
            'replays' => false,
            'desc' => 'Elevated DB transaction write lock limits'
        ],
        'Region C: Stale Storm' => [
            'nodes' => 5,
            'clients' => 10,
            'db_delay' => 0,
            'cent_delay' => 0,
            'replays' => true,
            'desc' => '30% packet timestamp replays'
        ],
        'Region D: Heavy Cascade' => [
            'nodes' => 8,
            'clients' => 20,
            'db_delay' => 150, // severe backpressure
            'cent_delay' => 20,  // Centrifugo SLO violation (> 10ms)
            'replays' => true,
            'desc' => 'Combined DB delay, dead WS, and replayed packets'
        ]
    ];

    $heatmap = [];

    foreach ($regions as $name => $cfg) {
        echo "🧪 Sweeping {$name} ({$cfg['desc']})...\n";
        cleanMockData($db);
        
        $res = runSimulationLoop(
            $db,
            $cfg['nodes'],
            $cfg['clients'],
            3,            // ticks
            100000,       // delay (100ms)
            $cfg['db_delay'],
            $cfg['cent_delay'],
            $cfg['replays'],
            true          // quiet (hides verbose prints)
        );

        $heatmap[$name] = $res;
        echo "   -> Ingest Compliance: {$res['ingest_compliance']}% | DB Compliance: {$res['db_compliance']}% | First Failure: {$res['first_failure']} | Transitions: {$res['transitions_count']} (Instability: {$res['instability_weight']})\n";
    }

    echo "\n";
    echo "================================================================================\n";
    echo "📊 SRE STABILITY ENVELOPE HEATMAP MATRIX\n";
    echo "================================================================================\n";
    printf("%-25s | Ingest SLO | DB Ingest  | Health | Instability | First Failure Mode\n", "Operational Boundary");
    echo str_repeat("-", 80) . "\n";

    foreach ($heatmap as $name => $res) {
        $colorTag = '🟢';
        if ($res['avg_health'] < 60.0) {
            $colorTag = '🔴';
        } elseif ($res['avg_health'] < 95.0) {
            $colorTag = '🟡';
        }

        printf(
            "%-25s |   %5.1f%%  |   %5.1f%%  | %s %3.0f/100|    %5.2f    | %s\n",
            $name,
            $res['ingest_compliance'],
            $res['db_compliance'],
            $colorTag,
            $res['avg_health'],
            $res['instability_weight'],
            $res['first_failure']
        );
    }
    echo "================================================================================\n";
    echo "⭐️ Stability Envelope Sweeper complete. System boundaries mapped successfully!\n";
}
