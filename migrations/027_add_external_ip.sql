-- Add external IP tracking for VPN clients
-- Stores the real-world IP:port endpoint that the client connects from (from awg show)
ALTER TABLE vpn_clients
    ADD COLUMN external_ip VARCHAR(100) NULL COMMENT 'External IP:port endpoint seen by WireGuard' AFTER client_ip;
