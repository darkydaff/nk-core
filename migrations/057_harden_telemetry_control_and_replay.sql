-- migration file: 057_harden_telemetry_control_and_replay.sql

ALTER TABLE vpn_servers
ADD COLUMN consecutive_active_ticks TINYINT UNSIGNED DEFAULT 0 COMMENT 'Consecutive ticks with active admin subscribers',
ADD COLUMN consecutive_idle_ticks TINYINT UNSIGNED DEFAULT 0 COMMENT 'Consecutive ticks with zero admin subscribers',
ADD COLUMN last_failure_reasons VARCHAR(512) DEFAULT NULL COMMENT 'JSON array of current SLO or connection failure reasons';

CREATE TABLE telemetry_replay_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    server_id INT UNSIGNED NOT NULL,
    payload LONGTEXT NOT NULL COMMENT 'Raw JSON input payload from server node',
    status VARCHAR(20) NOT NULL DEFAULT 'captured' COMMENT 'Status: captured, replayed, error',
    latency_ms FLOAT DEFAULT 0.0 COMMENT 'Execution latency measured during this run',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_replay_server_id FOREIGN KEY (server_id) REFERENCES vpn_servers(id) ON DELETE CASCADE,
    INDEX idx_server_created (server_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
