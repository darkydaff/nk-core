-- Migration 044: Soft-delete for http_proxies
-- Preserves bytes_sent/bytes_received for dashboard traffic totals after proxy deletion.

DELIMITER //

CREATE PROCEDURE AddDeletedAtColumn()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
        AND table_name = 'http_proxies'
        AND column_name = 'deleted_at'
    ) THEN
        ALTER TABLE http_proxies ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER status;
    END IF;
END //

DELIMITER ;

CALL AddDeletedAtColumn();
DROP PROCEDURE AddDeletedAtColumn;

-- Index for fast filtering of non-deleted rows
-- MariaDB doesn't support IF NOT EXISTS for CREATE INDEX directly, but we can use ALTER TABLE
-- Note: MySQL 8 doesn't support IF NOT EXISTS for ALTER TABLE ... ADD INDEX, we need another procedure

DELIMITER //

CREATE PROCEDURE AddDeletedAtIndex()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
        AND table_name = 'http_proxies'
        AND index_name = 'idx_http_proxies_deleted_at'
    ) THEN
        ALTER TABLE http_proxies ADD INDEX idx_http_proxies_deleted_at (deleted_at);
    END IF;
END //

DELIMITER ;

CALL AddDeletedAtIndex();
DROP PROCEDURE AddDeletedAtIndex;
