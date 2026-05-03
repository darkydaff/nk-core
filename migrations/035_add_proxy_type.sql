ALTER TABLE http_proxies ADD COLUMN type ENUM('http', 'socks5') DEFAULT 'http' AFTER password;

-- Update translations
INSERT INTO translations (locale, category, key_name, translation) VALUES
('en', 'proxies', 'title', 'Proxies'),
('en', 'proxies', 'type', 'Proxy Type'),
('en', 'proxies', 'type_http', 'HTTP'),
('en', 'proxies', 'type_socks5', 'SOCKS5'),
('ru', 'proxies', 'title', 'Прокси'),
('ru', 'proxies', 'type', 'Тип Прокси'),
('ru', 'proxies', 'type_http', 'HTTP'),
('ru', 'proxies', 'type_socks5', 'SOCKS5')
ON DUPLICATE KEY UPDATE translation=VALUES(translation);
