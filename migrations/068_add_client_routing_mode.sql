-- Migration 068: Add routing_mode to vpn_clients
ALTER TABLE vpn_clients
    ADD COLUMN routing_mode ENUM('direct', 'warp') NOT NULL DEFAULT 'direct' AFTER status;
