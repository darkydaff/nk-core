<?php

require_once __DIR__ . '/../inc/AwgConfigGenerator.php';

function runConfigTests() {
    $generator = new AwgConfigGenerator();
    $passed = 0;
    $failed = 0;

    $assert = function($condition, $message) use (&$passed, &$failed) {
        if ($condition) {
            echo "✅ PASS: $message\n";
            $passed++;
        } else {
            echo "❌ FAIL: $message\n";
            $failed++;
        }
    };

    echo "\n=== AwgConfigGenerator Tests ===\n\n";

    // Test 1: Dockerfile
    $dockerfile = $generator->getDockerfile();
    $assert(!empty($dockerfile), "Dockerfile is not empty");
    $assert(str_contains($dockerfile, 'FROM alpine:latest AS builder'), "Dockerfile contains builder stage");
    $assert(str_contains($dockerfile, 'ENTRYPOINT [ "dumb-init", "/opt/amnezia/start.sh" ]'), "Dockerfile contains entrypoint");

    // Test 2: Start Script
    $subnet = '10.9.0.0/24';
    $port = 51820;
    $startScript = $generator->getStartScript($subnet, $port);
    $assert(!empty($startScript), "Start script is not empty");
    $assert(str_contains($startScript, 'Using default interface'), "Start script contains interface detection");
    $assert(str_contains($startScript, $subnet), "Start script contains subnet");
    $assert(str_contains($startScript, (string)$port), "Start script contains VPN port");

    // Test 3: Mimicry Presets
    $quic = $generator->getMimicryPreset('quic');
    $assert(isset($quic['Jc']) && $quic['Jc'] === 4, "QUIC preset has correct Jc=4");
    
    $standard = $generator->getMimicryPreset('standard');
    $assert(isset($standard['Jc']) && $standard['Jc'] === 1, "Standard preset has correct Jc=1");

    // Test 4: Header Ranges
    $ranges = $generator->generateNonOverlappingHeaderRanges();
    $assert(count($ranges) === 4, "Generated 4 header ranges");
    $assert(isset($ranges['H1'], $ranges['H2'], $ranges['H3'], $ranges['H4']), "Ranges have H1-H4 keys");

    // Test 5: generateAwgParams
    $params = $generator->generateAwgParams(null);
    $assert(isset($params['Jc'], $params['Jmin'], $params['Jmax'], $params['H1']), "Generated AWG params contain required keys");
    $assert($params['mimicry_type'] === 'quic', "Default mimicry type is quic");

    // Test 6: generateWgConfig
    $privKey = 'GJWN...';
    $wgConfig = $generator->generateWgConfig('10.9.0', 51820, $privKey, $params);
    $assert(str_contains($wgConfig, "PrivateKey = $privKey"), "WG config contains PrivateKey");
    $assert(str_contains($wgConfig, "ListenPort = 51820"), "WG config contains ListenPort");
    $assert(str_contains($wgConfig, "Address = 10.9.0.1/24"), "WG config contains Address");
    $assert(str_contains($wgConfig, "Jc = " . $params['Jc']), "WG config contains AWG params");

    echo "\nTests completed: $passed passed, $failed failed.\n";
    exit($failed > 0 ? 1 : 0);
}

runConfigTests();
