-- Migration 044: Soft-delete for http_proxies
-- Preserves bytes_sent/bytes_received for dashboard traffic totals after proxy deletion.

ALTER TABLE http_proxies
    ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER status;

-- Index for fast filtering of non-deleted rows
CREATE INDEX idx_http_proxies_deleted_at ON http_proxies (deleted_at);
