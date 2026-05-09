-- Migration 044: Soft-delete for http_proxies
-- Preserves bytes_sent/bytes_received for dashboard traffic totals after proxy deletion.

ALTER TABLE http_proxies
    ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL DEFAULT NULL AFTER status;

-- Index for fast filtering of non-deleted rows
-- MariaDB doesn't support IF NOT EXISTS for CREATE INDEX directly, but we can use ALTER TABLE
ALTER TABLE http_proxies ADD INDEX IF NOT EXISTS idx_http_proxies_deleted_at (deleted_at);
