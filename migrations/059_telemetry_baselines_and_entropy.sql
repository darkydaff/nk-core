-- migration file: 059_telemetry_baselines_and_entropy.sql

CREATE TABLE telemetry_baselines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    server_id INT UNSIGNED NOT NULL,
    metric_name VARCHAR(50) NOT NULL COMMENT 'ingest_latency, db_time, centrifugo_time',
    baseline_value FLOAT NOT NULL DEFAULT 5.0 COMMENT '7-day rolling median baseline in ms',
    sample_count INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_baseline_server_id FOREIGN KEY (server_id) REFERENCES vpn_servers(id) ON DELETE CASCADE,
    UNIQUE KEY uq_server_metric (server_id, metric_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE vpn_servers
ADD COLUMN loop_entropy FLOAT NOT NULL DEFAULT 0.0 COMMENT 'Shannon entropy of transition history (0 = stable, >1 = volatile)',
ADD COLUMN baseline_drift_index FLOAT NOT NULL DEFAULT 0.0 COMMENT 'Average deviation percentage from metric baselines',
ADD COLUMN control_loop_damping FLOAT NOT NULL DEFAULT 1.0 COMMENT 'Measured recovery coefficient after simulated chaos';
