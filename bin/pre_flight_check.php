#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/SchemaContractValidator.php';
require_once __DIR__ . '/../inc/Config.php';

// Load environment configuration
if (file_exists(__DIR__ . '/../.env')) {
    Config::load(__DIR__ . '/../.env');
}

echo "Running Pre-flight Schema Validation...\n";

try {
    // Attempt to connect to DB to ensure it's available before validating
    DB::conn();
    
    $errors = SchemaContractValidator::validate();
    if (!empty($errors)) {
        echo "🚨 FAILED: Schema consistency violations found:\n";
        foreach ($errors as $error) {
            echo " - $error\n";
        }
        exit(1);
    }
    echo "✅ PASSED: No schema drift detected.\n";
    exit(0);
} catch (\PDOException $e) {
    echo "🚨 ERROR: Database connection failed. Validation requires an active database. " . $e->getMessage() . "\n";
    exit(1);
} catch (\Throwable $e) {
    echo "🚨 ERROR: Exception during validation: " . $e->getMessage() . "\n";
    exit(1);
}
