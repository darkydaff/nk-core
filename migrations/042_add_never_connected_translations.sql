-- Add "Never Connected" status translations
INSERT INTO translations (locale, category, key_name, translation) VALUES
('en', 'status', 'never', 'Never Connected'),
('uk', 'status', 'never', 'Не підключався'),
('ru', 'status', 'never', 'Не подключался')
ON DUPLICATE KEY UPDATE translation = VALUES(translation);
