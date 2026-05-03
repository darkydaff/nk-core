-- Add latitude and longitude for VPN client IP geolocation (used by the Map page)
ALTER TABLE vpn_clients
    ADD COLUMN ip_lat DECIMAL(10, 7) NULL COMMENT 'Latitude from IP geolocation' AFTER ip_org,
    ADD COLUMN ip_lon DECIMAL(10, 7) NULL COMMENT 'Longitude from IP geolocation' AFTER ip_lat;
