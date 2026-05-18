<?php
declare(strict_types=1);

/**
 * SchemaContractValidator
 * 
 * Verifies consistency between Database Schema, Templates, and API representations.
 * Run this before deployment to catch "works by coincidence" defects.
 */
class SchemaContractValidator {
    public static function validate(): array {
        $errors = [];
        
        // 1. Verify SQL schema fields match models/templates
        $pdo = DB::conn();
        $stmt = $pdo->query("DESCRIBE vpn_clients");
        $clientCols = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!in_array('client_ip', $clientCols)) {
            $errors[] = "CRITICAL: vpn_clients missing 'client_ip' column";
        }
        
        $stmt = $pdo->query("DESCRIBE vpn_servers");
        $serverCols = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!in_array('host', $serverCols)) {
            $errors[] = "CRITICAL: vpn_servers missing 'host' column";
        }
        
        // 2. Scan Twig templates for deprecated or invalid fields
        $twigFiles = glob(__DIR__ . '/../templates/**/*.twig');
        foreach ($twigFiles as $file) {
            $content = file_get_contents($file);
            if (strpos($content, 'client.ip_address') !== false) {
                $errors[] = "TEMPLATE ERROR: 'client.ip_address' found in " . basename($file) . " (should be client_ip)";
            }
            if (strpos($content, 'server.ip_address') !== false) {
                $errors[] = "TEMPLATE ERROR: 'server.ip_address' found in " . basename($file) . " (should be host)";
            }
        }
        
        return $errors;
    }
}
