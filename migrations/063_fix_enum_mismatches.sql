-- Migration 063: Fix ENUM mismatches between PHP code and database schema
-- These mismatches cause PDOException when PHP writes a status value the DB doesn't accept.

-- vpn_servers: PHP ServerStatus enum has 'deploying','deleting','active','stopped','error','deleted'
-- DB only had: 'deploying','active','stopped','error'
-- Missing: 'deleting', 'deleted'
ALTER TABLE vpn_servers
    MODIFY COLUMN status ENUM('deploying','deleting','active','stopped','error','deleted') DEFAULT 'deploying';

-- vpn_clients: PHP ClientStatus enum has 'provisioning','verifying','active','disabled','deleting','error','deleted'
-- DB only had: 'active','disabled'
-- Missing: 'provisioning', 'verifying', 'deleting', 'error', 'deleted'
ALTER TABLE vpn_clients
    MODIFY COLUMN status ENUM('provisioning','verifying','active','disabled','deleting','error','deleted') DEFAULT 'active';

-- users: Add 'deleted_at' column if missing (used by Auth::deleteUser soft-delete)
ALTER TABLE users ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL DEFAULT NULL;
