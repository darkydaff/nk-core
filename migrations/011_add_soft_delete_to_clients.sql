-- Migration 011: Add soft-delete support to users, vpn_servers, and vpn_clients
-- Date: 2026-05-02

-- Users table
ALTER TABLE users ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE users ADD INDEX idx_deleted_at (deleted_at);
ALTER TABLE users MODIFY COLUMN status VARCHAR(20) DEFAULT 'active';

-- VPN Servers table
ALTER TABLE vpn_servers ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE vpn_servers ADD INDEX idx_deleted_at (deleted_at);
ALTER TABLE vpn_servers MODIFY COLUMN status VARCHAR(20) DEFAULT 'deploying';

-- VPN Clients table
ALTER TABLE vpn_clients ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE vpn_clients ADD INDEX idx_deleted_at (deleted_at);
ALTER TABLE vpn_clients MODIFY COLUMN status VARCHAR(20) DEFAULT 'active';
