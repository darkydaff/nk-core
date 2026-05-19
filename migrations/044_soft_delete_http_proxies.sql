-- Migration 044: Soft-delete for http_proxies
-- Preserves bytes_sent/bytes_received for dashboard traffic totals after proxy deletion.

-- Create a procedure to safely add the column if it doesn't exist
DELIMITER $$
CREATE PROCEDURE add_deleted_at_to_http_proxies()
BEGIN
    DECLARE col_exists INT;
    SELECT COUNT(*) INTO col_exists
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'http_proxies'
      AND column_name = 'deleted_at';

    IF col_exists = 0 THEN
        ALTER TABLE http_proxies ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER status;
    END IF;
END$$
DELIMITER ;

CALL add_deleted_at_to_http_proxies();
DROP PROCEDURE add_deleted_at_to_http_proxies;

-- Index for fast filtering of non-deleted rows
-- Create a procedure to safely add the index if it doesn't exist
DELIMITER $$
CREATE PROCEDURE add_idx_http_proxies_deleted_at()
BEGIN
    DECLARE idx_exists INT;
    SELECT COUNT(*) INTO idx_exists
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'http_proxies'
      AND index_name = 'idx_http_proxies_deleted_at';

    IF idx_exists = 0 THEN
        ALTER TABLE http_proxies ADD INDEX idx_http_proxies_deleted_at (deleted_at);
    END IF;
END$$
DELIMITER ;

CALL add_idx_http_proxies_deleted_at();
DROP PROCEDURE add_idx_http_proxies_deleted_at;
