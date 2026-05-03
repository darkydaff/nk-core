-- Fix unique constraint for soft-deleted clients
-- This allows reusing an IP address if the previous client was deleted

-- 1. Drop the old restrictive index
ALTER TABLE vpn_clients DROP INDEX unique_server_client_ip;

-- 2. Add a virtual column that is NULL when the client is deleted, but contains the IP when active
-- In MySQL, multiple NULL values are allowed in a UNIQUE index, so this perfectly handles soft deletes.
ALTER TABLE vpn_clients ADD COLUMN active_client_ip VARCHAR(50) 
    GENERATED ALWAYS AS (IF(deleted_at IS NULL, client_ip, NULL)) VIRTUAL;

-- 3. Create the new smart unique index
ALTER TABLE vpn_clients ADD UNIQUE KEY unique_active_server_client_ip (server_id, active_client_ip);
