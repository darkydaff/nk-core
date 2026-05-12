-- Migration: Add GeoIP data to vpn_servers
ALTER TABLE vpn_servers
    ADD COLUMN country VARCHAR(100) NULL AFTER host,
    ADD COLUMN country_code VARCHAR(10) NULL AFTER country,
    ADD COLUMN city VARCHAR(100) NULL AFTER country_code,
    ADD COLUMN isp VARCHAR(255) NULL AFTER city,
    ADD COLUMN org VARCHAR(255) NULL AFTER isp;
