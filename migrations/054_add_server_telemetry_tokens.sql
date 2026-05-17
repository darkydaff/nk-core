-- Migration: Add secure telemetry tokens and tiered metric rollups
-- Path: migrations/054_add_server_telemetry_tokens.sql

-- 1. Add secure telemetry token and heartbeat columns to vpn_servers
ALTER TABLE vpn_servers
    ADD COLUMN telemetry_token VARCHAR(64) NULL UNIQUE AFTER preshared_key,
    ADD COLUMN last_telemetry_at TIMESTAMP NULL DEFAULT NULL AFTER telemetry_token;

-- 2. Generate secure random starting tokens for any existing servers
UPDATE vpn_servers 
SET telemetry_token = SHA2(CONCAT(id, RAND(), NOW()), 256) 
WHERE telemetry_token IS NULL;

-- 3. Create client_hourly_metrics for 30-day retention tiering
CREATE TABLE client_hourly_metrics (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    bytes_sent_delta BIGINT NOT NULL COMMENT 'Accumulated sent in this hour',
    bytes_received_delta BIGINT NOT NULL COMMENT 'Accumulated received in this hour',
    peak_speed_up_kbps FLOAT NOT NULL DEFAULT 0.0,
    peak_speed_down_kbps FLOAT NOT NULL DEFAULT 0.0,
    recorded_hour TIMESTAMP NOT NULL,
    CONSTRAINT fk_hourly_client_id FOREIGN KEY (client_id) REFERENCES vpn_clients(id) ON DELETE CASCADE,
    UNIQUE KEY uq_client_hour (client_id, recorded_hour),
    INDEX idx_hourly_recorded (recorded_hour)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Create client_daily_metrics for long-term/indefinite history
CREATE TABLE client_daily_metrics (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    bytes_sent_delta BIGINT NOT NULL COMMENT 'Accumulated sent in this day',
    bytes_received_delta BIGINT NOT NULL COMMENT 'Accumulated received in this day',
    peak_speed_up_kbps FLOAT NOT NULL DEFAULT 0.0,
    peak_speed_down_kbps FLOAT NOT NULL DEFAULT 0.0,
    recorded_day DATE NOT NULL,
    CONSTRAINT fk_daily_client_id FOREIGN KEY (client_id) REFERENCES vpn_clients(id) ON DELETE CASCADE,
    UNIQUE KEY uq_client_day (client_id, recorded_day),
    INDEX idx_daily_recorded (recorded_day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
