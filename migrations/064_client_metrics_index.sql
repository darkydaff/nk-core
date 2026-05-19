-- Migration 064: Performance index for client_metrics speed calculation query
-- The calculateSpeed() method in ServerMonitoring queries:
--   SELECT ... FROM client_metrics WHERE client_id = ? ORDER BY collected_at DESC LIMIT 1
-- Without this index, every speed calculation does a full table scan on a table
-- that grows ~2880 rows/client/day.

DELIMITER //

CREATE PROCEDURE SafeAddClientMetricsIndex()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'client_metrics'
          AND INDEX_NAME = 'idx_client_metrics_speed_lookup'
    ) THEN
        CREATE INDEX idx_client_metrics_speed_lookup
            ON client_metrics (client_id, collected_at DESC);
    END IF;
END //

DELIMITER ;

CALL SafeAddClientMetricsIndex();
DROP PROCEDURE SafeAddClientMetricsIndex;
