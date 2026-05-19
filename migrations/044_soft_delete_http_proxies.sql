-- Migration 044: Soft-delete for http_proxies
-- Preserves bytes_sent/bytes_received for dashboard traffic totals after proxy deletion.

-- MySQL 8 and older MariaDB versions don't support IF NOT EXISTS in ALTER TABLE
-- We use a stored procedure trick to safely add column if not exists

DELIMITER //

CREATE PROCEDURE SafeAddColumn()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'http_proxies'
          AND COLUMN_NAME = 'deleted_at'
    ) THEN
        ALTER TABLE http_proxies ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER status;
    END IF;
END //

DELIMITER ;

CALL SafeAddColumn();
DROP PROCEDURE SafeAddColumn;

DELIMITER //

CREATE PROCEDURE SafeAddIndex()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'http_proxies'
          AND INDEX_NAME = 'idx_http_proxies_deleted_at'
    ) THEN
        ALTER TABLE http_proxies ADD INDEX idx_http_proxies_deleted_at (deleted_at);
    END IF;
END //

DELIMITER ;

CALL SafeAddIndex();
DROP PROCEDURE SafeAddIndex;
