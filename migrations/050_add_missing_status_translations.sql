-- Add missing status translations for provisioning, revoked, and verifying
INSERT INTO translations (locale, category, key_name, translation) VALUES
('en', 'status', 'provisioning', 'Provisioning'),
('en', 'status', 'revoked', 'Revoked'),
('en', 'status', 'verifying', 'Verifying'),
('uk', 'status', 'provisioning', 'Налаштування'),
('uk', 'status', 'revoked', 'Відкликано'),
('uk', 'status', 'verifying', 'Перевірка'),
('ru', 'status', 'provisioning', 'Настройка'),
('ru', 'status', 'revoked', 'Отозван'),
('ru', 'status', 'verifying', 'Проверка')
ON DUPLICATE KEY UPDATE translation = VALUES(translation);

-- Add common.revoked as an alias for backward compatibility
INSERT INTO translations (locale, category, key_name, translation) VALUES
('en', 'common', 'revoked', 'Revoked'),
('uk', 'common', 'revoked', 'Відкликано'),
('ru', 'common', 'revoked', 'Отозван')
ON DUPLICATE KEY UPDATE translation = VALUES(translation);
