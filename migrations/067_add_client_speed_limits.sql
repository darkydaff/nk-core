-- Migration 067: Add upload and download speed limits to vpn_clients
ALTER TABLE vpn_clients
    ADD COLUMN speed_limit_up INT UNSIGNED DEFAULT NULL COMMENT 'Upload limit in Mbps (NULL = inherit default, 0 = unlimited)' AFTER speed_down_kbps,
    ADD COLUMN speed_limit_down INT UNSIGNED DEFAULT NULL COMMENT 'Download limit in Mbps (NULL = inherit default, 0 = unlimited)' AFTER speed_limit_up;
