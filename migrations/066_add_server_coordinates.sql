-- Migration 066: Add latitude and longitude to vpn_servers
ALTER TABLE vpn_servers
    ADD COLUMN lat DECIMAL(10, 7) NULL COMMENT 'Latitude from IP geolocation' AFTER org,
    ADD COLUMN lon DECIMAL(10, 7) NULL COMMENT 'Longitude from IP geolocation' AFTER lat;
