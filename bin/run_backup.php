<?php

require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/BackupManager.php';

// Load .env
Config::load(__DIR__ . '/../.env');

// Set timezone to Moscow (GMT+3)
date_default_timezone_set('Europe/Moscow');

// Parse arguments
$force = in_array('--force', $argv);
$isCron = in_array('--cron', $argv);

if ($isCron && !$force) {
    $enabled = Config::get('BACKUP_AUTO_ENABLED', 'false');
    if ($enabled !== 'true') {
        exit(0); // Disabled
    }

    $scheduleTime = Config::get('BACKUP_SCHEDULE_TIME', '00:00');
    $currentTime = date('H:i');
    
    if ($currentTime !== $scheduleTime) {
        exit(0); // Not the right time
    }
}

// Simple lock mechanism
$lockFile = sys_get_temp_dir() . '/nk_backup.lock';
$fp = fopen($lockFile, 'c+');
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    echo "⚠️ Another backup process is already running.\n";
    exit(1);
}

echo "🚀 Starting Triple-Vault Backup (Local + S3 + Telegram)...\n";

try {
    $result = BackupManager::createBackup();
    
    echo "✅ Backup Successful!\n";
    echo "📄 File: " . $result['filename'] . "\n";
    echo "⏰ Time: " . $result['timestamp'] . "\n";
    
    // Save last run status
    Config::updateEnv([
        'BACKUP_LAST_RUN' => $result['timestamp'],
        'BACKUP_LAST_STATUS' => 'success'
    ]);

    if ($result['s3_status']) {
        echo "☁️  S3: Uploaded to " . Config::get('S3_BUCKET') . "\n";
    } else {
        echo "⚠️  S3: Failed! (" . $result['s3_error'] . ")\n";
    }

    if ($result['tg_status']) {
        echo "📱 Telegram: Sent to Chat ID " . Config::get('TG_CHAT_ID') . "\n";
    } else {
        echo "⚠️  Telegram: Skipped or Failed! (" . ($result['tg_error'] ?? 'No config') . ")\n";
    }

    echo "📂 Local: " . $result['local_path'] . "\n";

} catch (Exception $e) {
    echo "❌ CRITICAL ERROR: " . $e->getMessage() . "\n";
    Config::updateEnv([
        'BACKUP_LAST_RUN' => date('Y-m-d H:i:s'),
        'BACKUP_LAST_STATUS' => 'failed: ' . substr($e->getMessage(), 0, 50)
    ]);
    flock($fp, LOCK_UN);
    fclose($fp);
    exit(1);
}

flock($fp, LOCK_UN);
fclose($fp);
