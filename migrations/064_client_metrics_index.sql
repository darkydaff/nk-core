-- Migration 064: Performance index for client_metrics speed calculation query
-- The calculateSpeed() method in ServerMonitoring queries:
--   SELECT ... FROM client_metrics WHERE client_id = ? ORDER BY collected_at DESC LIMIT 1
-- Without this index, every speed calculation does a full table scan on a table
-- that grows ~2880 rows/client/day.

CREATE INDEX IF NOT EXISTS idx_client_metrics_speed_lookup 
    ON client_metrics (client_id, collected_at DESC);
