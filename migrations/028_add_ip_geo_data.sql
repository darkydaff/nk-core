-- Add IP geolocation data for VPN clients (populated via ip-api.com on sync)
ALTER TABLE vpn_clients
    ADD COLUMN ip_country VARCHAR(100) NULL COMMENT 'Country name from IP geolocation' AFTER external_ip,
    ADD COLUMN ip_country_code VARCHAR(2) NULL COMMENT 'ISO 3166-1 alpha-2 country code' AFTER ip_country,
    ADD COLUMN ip_city VARCHAR(100) NULL COMMENT 'City from IP geolocation' AFTER ip_country_code,
    ADD COLUMN ip_isp VARCHAR(200) NULL COMMENT 'ISP name from IP geolocation' AFTER ip_city,
    ADD COLUMN ip_org VARCHAR(200) NULL COMMENT 'Organization name from IP geolocation' AFTER ip_isp;
