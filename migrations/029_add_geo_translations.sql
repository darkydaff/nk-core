-- Add translation keys for IP geolocation data
INSERT INTO translations (locale, category, key_name, translation) VALUES
-- English
('en', 'clients', 'external_ip_info', 'Connection Details'),
('en', 'clients', 'country', 'Country'),
('en', 'clients', 'city', 'City'),
('en', 'clients', 'isp', 'ISP'),
('en', 'clients', 'org', 'Organization'),

-- Russian
('ru', 'clients', 'external_ip_info', 'Детали подключения'),
('ru', 'clients', 'country', 'Страна'),
('ru', 'clients', 'city', 'Город'),
('ru', 'clients', 'isp', 'Провайдер'),
('ru', 'clients', 'org', 'Организация'),

-- Ukrainian
('uk', 'clients', 'external_ip_info', 'Деталі підключення'),
('uk', 'clients', 'country', 'Країна'),
('uk', 'clients', 'city', 'Місто'),
('uk', 'clients', 'isp', 'Провайдер'),
('uk', 'clients', 'org', 'Організація')
ON DUPLICATE KEY UPDATE translation = VALUES(translation);
