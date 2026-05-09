-- Replace generated column with a regular column + triggers to support backups natively
-- This avoids the ERROR 3105 (HY000) when restoring from backups

-- Temporarily allow trigger creation
SET GLOBAL log_bin_trust_function_creators = 1;

-- 1. We must safely drop and recreate the column because altering a generated column is not supported in all MySQL versions
ALTER TABLE vpn_clients DROP INDEX IF EXISTS unique_active_server_client_ip;
ALTER TABLE vpn_clients DROP COLUMN IF EXISTS active_client_ip;

-- 2. Add as a regular column
ALTER TABLE vpn_clients ADD COLUMN IF NOT EXISTS active_client_ip VARCHAR(50) DEFAULT NULL;

-- 3. Re-add the unique index
ALTER TABLE vpn_clients ADD UNIQUE KEY IF NOT EXISTS unique_active_server_client_ip (server_id, active_client_ip);

-- 4. Fill the column with initial data
UPDATE vpn_clients SET active_client_ip = IF(deleted_at IS NULL, client_ip, NULL);

-- 5. Create triggers to keep it updated, essentially mimicking GENERATED ALWAYS AS
DROP TRIGGER IF EXISTS vpn_clients_before_insert;
DROP TRIGGER IF EXISTS vpn_clients_before_update;

DELIMITER //

CREATE TRIGGER vpn_clients_before_insert
BEFORE INSERT ON vpn_clients
FOR EACH ROW
BEGIN
    IF NEW.deleted_at IS NULL THEN
        SET NEW.active_client_ip = NEW.client_ip;
    ELSE
        SET NEW.active_client_ip = NULL;
    END IF;
END;
//

CREATE TRIGGER vpn_clients_before_update
BEFORE UPDATE ON vpn_clients
FOR EACH ROW
BEGIN
    IF NEW.deleted_at IS NULL THEN
        SET NEW.active_client_ip = NEW.client_ip;
    ELSE
        SET NEW.active_client_ip = NULL;
    END IF;
END;
//

DELIMITER ;

-- Restore original setting
SET GLOBAL log_bin_trust_function_creators = 0;
