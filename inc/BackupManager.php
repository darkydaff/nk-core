<?php

require_once __DIR__ . '/S3Lite.php';
require_once __DIR__ . '/TelegramClient.php';
require_once __DIR__ . '/Config.php';

class BackupManager {
    
    private static function getBackupDir($create = true) {
        $baseDir = dirname(__DIR__);
        $storageDir = $baseDir . '/storage';
        $dir = $storageDir . '/backups';
        
        if (!$create) {
            return $dir;
        }

        // Ensure storage directory exists first
        if (!is_dir($storageDir)) {
            if (!@mkdir($storageDir, 0755, true) && !is_dir($storageDir)) {
                // If 0755 fails, try 0775 as a fallback
                if (!@mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
                    throw new Exception("Could not create storage directory: $storageDir. Permission denied.");
                }
            }
        }

        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                    throw new Exception("Could not create backup directory: $dir. Permission denied.");
                }
            }
        }

        if (!is_writable($dir)) {
            @chmod($dir, 0775);
            if (!is_writable($dir)) {
                throw new Exception("Backup directory is not writable: $dir. Please run: chown -R www-data:www-data $storageDir");
            }
        }
        
        return realpath($dir) ?: $dir;
    }

    public static function createBackup() {
        $backupDir = self::getBackupDir();

        $dbHost = Config::get('DB_HOST', 'db');
        $dbUser = Config::get('DB_USERNAME', 'amnezia');
        $dbPass = Config::get('DB_PASSWORD', 'amnezia');
        $dbName = Config::get('DB_DATABASE', 'amnezia_panel');

        // 0. Pre-Backup Health Checks (Disk Space)
        $freeSpace = @disk_free_space($backupDir) ?: @disk_free_space('/');
        if ($freeSpace !== false && $freeSpace < 100 * 1024 * 1024) { // 100MB minimum
            throw new Exception("Insufficient disk space to create backup. Free space: " . round($freeSpace / 1024 / 1024, 2) . " MB");
        }

        $filename = 'nk_backup_' . date('Y-m-d_H-i-s') . '.tar.gz';
        $localPath = $backupDir . '/' . $filename;
        $sqlPath = $backupDir . '/db_dump.sql';
        $envPath = __DIR__ . '/../.env';

        // 1. Create Local Backup
        // Added --skip-ssl because internal Docker connections don't have trusted certs
        // Added --single-transaction and --no-tablespaces for consistency and to avoid PROCESS privilege errors
        $command = sprintf(
            'mysqldump --skip-ssl --single-transaction --no-tablespaces -h %s -u %s --password=%s %s --result-file=%s 2>&1',
            escapeshellarg($dbHost),
            escapeshellarg($dbUser),
            escapeshellarg($dbPass),
            escapeshellarg($dbName),
            escapeshellarg($sqlPath)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($sqlPath) || filesize($sqlPath) < 100) {
            $errorMsg = implode("\n", $output);
            if (file_exists($sqlPath) && filesize($sqlPath) < 100) $errorMsg .= "\nError: SQL dump is empty or too small.";
            if (file_exists($sqlPath)) unlink($sqlPath);
            throw new Exception("Database dump failed with code $returnCode: $errorMsg");
        }

        // 2. Create Tar Archive including .env
        $tarFiles = [basename($sqlPath)];
        if (file_exists($envPath)) {
            copy($envPath, $backupDir . '/.env.backup');
            $tarFiles[] = '.env.backup';
        }

        $tarCmd = sprintf(
            'cd %s && tar -czf %s %s',
            escapeshellarg($backupDir),
            escapeshellarg($filename),
            implode(' ', array_map('escapeshellarg', $tarFiles))
        );

        exec($tarCmd, $tarOutput, $tarReturn);

        // Cleanup temporary SQL and .env backup
        if (file_exists($sqlPath)) unlink($sqlPath);
        if (file_exists($backupDir . '/.env.backup')) unlink($backupDir . '/.env.backup');

        if ($tarReturn !== 0 || !file_exists($localPath)) {
            throw new Exception("Failed to create tar archive: " . implode("\n", $tarOutput));
        }

        // 2.5 Generate SHA-256 Checksum
        $checksum = hash_file('sha256', $localPath);
        file_put_contents($localPath . '.sha256', $checksum);

        // 3. Upload to Cloud (Optional but recommended)
        $s3Uploaded = false;
        $s3Error = null;
        try {
            $s3 = self::getS3Client();
            $s3->putObject($localPath, 'backups/' . $filename);
            if (file_exists($localPath . '.sha256')) {
                $s3->putObject($localPath . '.sha256', 'backups/' . $filename . '.sha256');
            }
            $s3Uploaded = true;
        } catch (Exception $e) {
            $s3Error = $e->getMessage();
        }

        // 3. Upload to Telegram (Optional but recommended)
        $tgUploaded = false;
        $tgError = null;
        try {
            if (Config::get('TG_BOT_TOKEN') && Config::get('TG_CHAT_ID')) {
                $tg = self::getTelegramClient();
                $tg->sendDocument($localPath, "Vault Snapshot: $filename");
                $tgUploaded = true;
            }
        } catch (Exception $e) {
            $tgError = $e->getMessage();
        }

        // 4. Cleanup old backups (Retention Policy)
        self::cleanupOldBackups();

        return [
            'success' => true,
            'filename' => $filename,
            'timestamp' => date('Y-m-d H:i:s'),
            's3_status' => $s3Uploaded,
            's3_error' => $s3Error,
            'tg_status' => $tgUploaded,
            'tg_error' => $tgError,
            'local_path' => $localPath
        ];
    }

    public static function cleanupOldBackups() {
        $retentionCount = (int)Config::get('BACKUP_RETENTION_COUNT', 10);
        if ($retentionCount <= 0) return;

        // 1. Cleanup Local
        $localBackups = self::listLocalBackups();
        if (count($localBackups) > $retentionCount) {
            $toDelete = array_slice($localBackups, $retentionCount);
            foreach ($toDelete as $backup) {
                self::deleteLocalBackup($backup['key']);
            }
        }

        // 2. Cleanup Cloud (S3)
        try {
            $s3Backups = self::listCloudBackups();
            if (count($s3Backups) > $retentionCount) {
                $toDelete = array_slice($s3Backups, $retentionCount);
                foreach ($toDelete as $backup) {
                    self::deleteCloudBackup($backup['key']);
                }
            }
        } catch (Exception $e) {
            // Ignore S3 cleanup errors
        }
    }

    public static function deleteLocalBackup(string $filename): bool {
        $backupDir = self::getBackupDir(false);
        $path = $backupDir . '/' . $filename;
        if (!file_exists($path)) return false;
        
        @unlink($path);
        @unlink($path . '.sha256');
        return true;
    }

    public static function deleteCloudBackup(string $key): bool {
        try {
            $s3 = self::getS3Client();
            $s3->deleteObject($key);
            $s3->deleteObject($key . '.sha256');
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public static function listLocalBackups() {
        $backupDir = self::getBackupDir(false);
        if (!is_dir($backupDir)) return [];
        
        $files = scandir($backupDir);
        $backups = [];
        foreach ($files as $file) {
            if (str_ends_with($file, '.tar.gz') || str_ends_with($file, '.sql.gz')) {
                $path = $backupDir . '/' . $file;
                if (file_exists($path)) {
                    $backups[] = [
                        'key' => $file,
                        'size' => @filesize($path) ?: 0,
                        'last_modified' => date('Y-m-d H:i:s', filemtime($path))
                    ];
                }
            }
        }

        usort($backups, function($a, $b) {
            return strcmp($b['key'], $a['key']);
        });

        return $backups;
    }

    public static function listCloudBackups() {
        try {
            $s3 = self::getS3Client();
            $files = $s3->listObjects('backups/');
            
            $backups = array_filter($files, function($f) {
                return str_ends_with($f['key'], '.tar.gz') || str_ends_with($f['key'], '.sql.gz');
            });

            usort($backups, function($a, $b) {
                return strcmp($b['key'], $a['key']);
            });

            return array_values($backups);
        } catch (Exception $e) {
            return [];
        }
    }

    public static function restoreFromLocal($filename) {
        $path = self::getBackupDir(false) . '/' . $filename;
        if (!file_exists($path)) {
            throw new Exception("Local backup file not found: $filename");
        }
        if (str_ends_with($filename, '.tar.gz')) {
            return self::restoreFromTar($path);
        }
        return self::executeSqlImport($path);
    }

    public static function restoreFromCloud($remoteKey) {
        $tmpPath = sys_get_temp_dir() . '/restore_' . time() . '.sql.gz';
        
        $s3 = self::getS3Client();
        $s3->getObject($remoteKey, $tmpPath);

        try {
            if (str_ends_with($remoteKey, '.tar.gz')) {
                $result = self::restoreFromTar($tmpPath);
            } else {
                $result = self::executeSqlImport($tmpPath);
            }
            unlink($tmpPath);
            return $result;
        } catch (Exception $e) {
            if (file_exists($tmpPath)) unlink($tmpPath);
            throw $e;
        }
    }

    public static function restoreFromUpload($filePath) {
        try {
            if (!file_exists($filePath) || filesize($filePath) < 10) {
                throw new Exception("Uploaded file is missing or invalid.");
            }
            if (str_ends_with($filePath, '.tar.gz')) {
                return self::restoreFromTar($filePath);
            }
            return self::executeSqlImport($filePath);
        } finally {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
    }

    private static function restoreFromTar($tarPath) {
        $backupDir = self::getBackupDir();
        $extractDir = $backupDir . '/extract_' . time();
        
        if (!@mkdir($extractDir, 0755, true)) {
            if (!@mkdir($extractDir, 0775, true)) {
                throw new Exception("Failed to create temporary extraction directory: $extractDir");
            }
        }
        @chmod($extractDir, 0775);

        $tarCmd = sprintf(
            'tar -xzf %s -C %s 2>&1',
            escapeshellarg($tarPath),
            escapeshellarg($extractDir)
        );

        exec($tarCmd, $output, $return);

        if ($return !== 0) {
            self::recursiveRemoveDir($extractDir);
            throw new Exception("Failed to extract tar archive: " . implode("\n", $output));
        }

        // Import SQL
        $sqlFile = $extractDir . '/db_dump.sql';
        if (file_exists($sqlFile)) {
            self::executeSqlImport($sqlFile);
        }

        // Restore .env if present
        $envFile = $extractDir . '/.env.backup';
        if (file_exists($envFile)) {
            copy($envFile, __DIR__ . '/../.env');
        }

        // Cleanup
        self::recursiveRemoveDir($extractDir);

        return true;
    }

    private static function recursiveRemoveDir($dir) {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? self::recursiveRemoveDir("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }

    private static function executeSqlImport($filePath) {
        $dbHost = Config::get('DB_HOST', 'db');
        $dbUser = Config::get('DB_USERNAME', 'amnezia');
        $dbPass = Config::get('DB_PASSWORD', 'amnezia');
        $dbName = Config::get('DB_DATABASE', 'amnezia_panel');

        $isGzipped = str_ends_with($filePath, '.gz');
        $catCmd = $isGzipped ? 'zcat' : 'cat';
        
        // Filter out generated columns and all variations of DEFINER statements (including versioned comments)
        // to prevent ERROR 3105/1419 during restore.
        $sedCmd = "sed -E 's/\\/\\*!5001[37] DEFINER=[^*]+\\*\\///g; s/DEFINER=[^ ]+ //g; s/GENERATED ALWAYS AS .* VIRTUAL/DEFAULT NULL/gi'";

        // Added --skip-ssl for internal container connections
        $command = sprintf(
            '%s %s | %s | mysql --skip-ssl -h %s -u %s -p%s %s 2>&1',
            $catCmd,
            escapeshellarg($filePath),
            $sedCmd,
            escapeshellarg($dbHost),
            escapeshellarg($dbUser),
            escapeshellarg($dbPass),
            escapeshellarg($dbName)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new Exception("Database import failed with code $returnCode: " . implode("\n", $output));
        }

        // Apply triggers to ensure active_client_ip is updated for restored old backups
        try {
            require_once __DIR__ . '/DB.php';
            $pdo = DB::conn();
            $pdo->exec("DROP TRIGGER IF EXISTS vpn_clients_before_insert;");
            $pdo->exec("
                CREATE TRIGGER vpn_clients_before_insert
                BEFORE INSERT ON vpn_clients
                FOR EACH ROW
                BEGIN
                    IF NEW.deleted_at IS NULL THEN
                        SET NEW.active_client_ip = NEW.client_ip;
                    ELSE
                        SET NEW.active_client_ip = NULL;
                    END IF;
                END;
            ");
            $pdo->exec("DROP TRIGGER IF EXISTS vpn_clients_before_update;");
            $pdo->exec("
                CREATE TRIGGER vpn_clients_before_update
                BEFORE UPDATE ON vpn_clients
                FOR EACH ROW
                BEGIN
                    IF NEW.deleted_at IS NULL THEN
                        SET NEW.active_client_ip = NEW.client_ip;
                    ELSE
                        SET NEW.active_client_ip = NULL;
                    END IF;
                END;
            ");
            
            // Fix any inconsistent active_client_ip data
            $pdo->exec("UPDATE vpn_clients SET active_client_ip = IF(deleted_at IS NULL, client_ip, NULL)");
        } catch (\Throwable $e) {
            // Ignore trigger creation errors
        }

        return true;
    }

    private static function getTelegramClient() {
        return new TelegramClient(
            Config::get('TG_BOT_TOKEN'),
            Config::get('TG_CHAT_ID'),
            [
                'enabled' => Config::get('TG_PROXY_ENABLED'),
                'type'    => Config::get('TG_PROXY_TYPE'),
                'host'    => Config::get('TG_PROXY_HOST'),
                'port'    => Config::get('TG_PROXY_PORT'),
                'auth'    => Config::get('TG_PROXY_AUTH')
            ]
        );
    }

    private static function getS3Client() {
        return new S3Lite(
            Config::get('S3_KEY'),
            Config::get('S3_SECRET'),
            Config::get('S3_REGION', 'us-east-1'),
            Config::get('S3_ENDPOINT'),
            Config::get('S3_BUCKET')
        );
    }
}
