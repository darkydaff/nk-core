-- Migration 045: Harden FK cascade behaviour to prevent accidental traffic data loss.
--
-- vpn_clients.server_id was ON DELETE CASCADE — a direct DELETE on vpn_servers
-- (bypassing the soft-delete path) would silently wipe all client traffic history.
-- Changing to RESTRICT prevents any hard-delete of a server row that still has
-- clients attached, making the soft-delete path the only viable option.
--
-- vpn_clients.user_id similarly: RESTRICT prevents losing traffic rows when a
-- user account is hard-deleted; the admin must clean up clients first.

DELIMITER //

CREATE PROCEDURE DropForeignKeyIfExists(IN tableName VARCHAR(255), IN fkName VARCHAR(255))
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.key_column_usage
        WHERE table_schema = DATABASE()
        AND table_name = tableName
        AND constraint_name = fkName
    ) THEN
        SET @s = CONCAT('ALTER TABLE ', tableName, ' DROP FOREIGN KEY ', fkName);
        PREPARE stmt FROM @s;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //

DELIMITER ;

CALL DropForeignKeyIfExists('vpn_clients', 'fk_vpn_clients_server_id');
CALL DropForeignKeyIfExists('vpn_clients', 'fk_vpn_clients_user_id');
CALL DropForeignKeyIfExists('vpn_clients', 'vpn_clients_ibfk_1');
CALL DropForeignKeyIfExists('vpn_clients', 'vpn_clients_ibfk_2');

CALL DropForeignKeyIfExists('http_proxies', 'fk_http_proxies_user_id');
CALL DropForeignKeyIfExists('http_proxies', 'fk_http_proxies_server_id');
CALL DropForeignKeyIfExists('http_proxies', 'http_proxies_ibfk_1');
CALL DropForeignKeyIfExists('http_proxies', 'http_proxies_ibfk_2');

DROP PROCEDURE DropForeignKeyIfExists;

-- Re-add with RESTRICT
ALTER TABLE vpn_clients
    ADD CONSTRAINT fk_vpn_clients_server_id
        FOREIGN KEY (server_id) REFERENCES vpn_servers(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_vpn_clients_user_id
        FOREIGN KEY (user_id)   REFERENCES users(id)       ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE http_proxies
    ADD CONSTRAINT fk_http_proxies_user_id
        FOREIGN KEY (user_id)   REFERENCES users(id)       ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_http_proxies_server_id
        FOREIGN KEY (server_id) REFERENCES vpn_servers(id) ON DELETE RESTRICT ON UPDATE CASCADE;
