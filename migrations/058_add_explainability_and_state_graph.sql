-- migration file: 058_add_explainability_and_state_graph.sql

ALTER TABLE vpn_servers
ADD COLUMN telemetry_state VARCHAR(50) NOT NULL DEFAULT 'IDLE_15S' COMMENT 'Current state machine node: ACTIVE_5S, IDLE_15S, BACKPRESSURE_30S',
ADD COLUMN last_decision_path TEXT DEFAULT NULL COMMENT 'JSON-encoded explainability trace of current SLO penalties';

CREATE TABLE telemetry_state_transitions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    server_id INT UNSIGNED NOT NULL,
    from_state VARCHAR(50) NOT NULL,
    to_state VARCHAR(50) NOT NULL,
    trigger_event VARCHAR(100) NOT NULL COMMENT 'Triggering event, e.g. db_latency_high, presence_idle',
    instability_weight FLOAT NOT NULL DEFAULT 0.0 COMMENT 'Contribution to system oscillation metric',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_transition_server_id FOREIGN KEY (server_id) REFERENCES vpn_servers(id) ON DELETE CASCADE,
    INDEX idx_server_transition (server_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
