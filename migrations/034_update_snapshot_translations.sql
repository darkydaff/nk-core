-- Update snapshot translations to be more generic and remove specific provider names
INSERT INTO translations (locale, category, key_name, translation) VALUES
('en', 'settings', 'create_snapshot', 'Create Snapshot'),
('uk', 'settings', 'create_snapshot', 'Створити знімок'),
('ru', 'settings', 'create_snapshot', 'Создать снимок')
ON DUPLICATE KEY UPDATE translation = VALUES(translation);

INSERT INTO translations (locale, category, key_name, translation) VALUES
('en', 'settings', 'manual_snapshot_desc', 'Instantly dump the database and upload it to the configured storage (if available).'),
('uk', 'settings', 'manual_snapshot_desc', 'Миттєво створіть дамп бази даних та завантажте його у налаштоване сховище (якщо доступно).'),
('ru', 'settings', 'manual_snapshot_desc', 'Мгновенно создайте дамп базы данных и загрузите его в настроенное хранилище (если доступно).')
ON DUPLICATE KEY UPDATE translation = VALUES(translation);

INSERT INTO translations (locale, category, key_name, translation) VALUES
('en', 'settings', 'cloud_snapshots', 'Cloud Snapshots'),
('uk', 'settings', 'cloud_snapshots', 'Хмарні знімки'),
('ru', 'settings', 'cloud_snapshots', 'Облачные снимки')
ON DUPLICATE KEY UPDATE translation = VALUES(translation);
