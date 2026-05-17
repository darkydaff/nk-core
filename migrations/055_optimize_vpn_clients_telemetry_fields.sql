-- migration file: 055_optimize_vpn_clients_telemetry_fields.sql

ALTER TABLE vpn_clients
ADD COLUMN last_bytes_sent BIGINT UNSIGNED DEFAULT 0 COMMENT 'Cache of the last raw bytes_sent from telemetry',
ADD COLUMN last_bytes_received BIGINT UNSIGNED DEFAULT 0 COMMENT 'Cache of the last raw bytes_received from telemetry',
ADD COLUMN last_metric_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Timestamp of the last telemetry metric collection';

-- Seed existing records with the latest values from client_metrics to prevent delta skew
UPDATE vpn_clients vc
INNER JOIN (
    SELECT m.client_id, m.bytes_sent, m.bytes_received, m.collected_at
    FROM client_metrics m
    INNER JOIN (
        SELECT client_id, MAX(id) as max_id
        FROM client_metrics
        GROUP BY client_id
    ) latest ON m.id = latest.max_id
) m_data ON vc.id = m_data.client_id
SET vc.last_bytes_sent = m_data.bytes_sent,
    vc.last_bytes_received = m_data.bytes_received,
    vc.last_metric_at = m_data.collected_at;
