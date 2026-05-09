-- Migration 045: Harden FK cascade behaviour to prevent accidental traffic data loss.
--
-- vpn_clients.server_id was ON DELETE CASCADE — a direct DELETE on vpn_servers
-- (bypassing the soft-delete path) would silently wipe all client traffic history.
-- Changing to RESTRICT prevents any hard-delete of a server row that still has
-- clients attached, making the soft-delete path the only viable option.
--
-- vpn_clients.user_id similarly: RESTRICT prevents losing traffic rows when a
-- user account is hard-deleted; the admin must clean up clients first.

ALTER TABLE vpn_clients
    DROP CONSTRAINT IF EXISTS fk_vpn_clients_server_id,
    DROP CONSTRAINT IF EXISTS fk_vpn_clients_user_id,
    DROP CONSTRAINT IF EXISTS vpn_clients_ibfk_1,
    DROP CONSTRAINT IF EXISTS vpn_clients_ibfk_2;

ALTER TABLE http_proxies
    DROP CONSTRAINT IF EXISTS fk_http_proxies_user_id,
    DROP CONSTRAINT IF EXISTS fk_http_proxies_server_id,
    DROP CONSTRAINT IF EXISTS http_proxies_ibfk_1,
    DROP CONSTRAINT IF EXISTS http_proxies_ibfk_2;

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
