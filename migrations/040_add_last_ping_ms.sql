-- Add last_ping_ms column to vpn_servers
ALTER TABLE vpn_servers ADD COLUMN last_ping_ms INT UNSIGNED NULL DEFAULT NULL AFTER status;
