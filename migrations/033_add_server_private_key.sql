-- Migration 033: Add server_private_key to vpn_servers
ALTER TABLE vpn_servers ADD COLUMN server_private_key TEXT NULL AFTER preshared_key;
