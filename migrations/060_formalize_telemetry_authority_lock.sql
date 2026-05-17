-- Migration 060: Formalize Telemetry Authority Lock & Mutual Exclusion
ALTER TABLE vpn_servers 
ADD COLUMN telemetry_mode VARCHAR(20) NOT NULL DEFAULT 'ssh' AFTER status;

-- Create an index to optimize routing and validation
CREATE INDEX idx_vpn_servers_telemetry_mode ON vpn_servers(telemetry_mode);

-- Backfill mode from current token state:
-- If server has a telemetry token configured, it is promoted to push mode.
UPDATE vpn_servers 
SET telemetry_mode = 'push' 
WHERE telemetry_token IS NOT NULL;
