-- migration file: 056_add_system_telemetry_diagnostics.sql

ALTER TABLE vpn_servers
ADD COLUMN last_ingest_latency_ms FLOAT DEFAULT 0.0 COMMENT 'Last telemetry ingestion total duration in milliseconds',
ADD COLUMN last_db_time_ms FLOAT DEFAULT 0.0 COMMENT 'Last telemetry DB write duration in milliseconds',
ADD COLUMN last_centrifugo_time_ms FLOAT DEFAULT 0.0 COMMENT 'Last telemetry Centrifugo broadcast duration in milliseconds',
ADD COLUMN total_ingest_count INT UNSIGNED DEFAULT 0 COMMENT 'Accumulated successful telemetry pushes',
ADD COLUMN backpressure_count INT UNSIGNED DEFAULT 0 COMMENT 'Accumulated backpressure activations due to DB slow writes',
ADD COLUMN circuit_breaker_count INT UNSIGNED DEFAULT 0 COMMENT 'Accumulated circuit breaker event triggers (DB or WS outages)',
ADD COLUMN replayed_packets_count INT UNSIGNED DEFAULT 0 COMMENT 'Accumulated replayed or out-of-order packet drops',
ADD COLUMN server_health_score TINYINT UNSIGNED DEFAULT 100 COMMENT 'Server health score calculated dynamically (0-100)';
