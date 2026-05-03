-- Rename S3 Cloud Backups to just Backups
-- Updates en, uk, ru translations for settings.s3_backups

INSERT INTO translations (locale, category, key_name, translation) VALUES
('en', 'settings', 's3_backups', 'Backups'),
('uk', 'settings', 's3_backups', 'Бекапи'),
('ru', 'settings', 's3_backups', 'Резервні копії')
ON DUPLICATE KEY UPDATE translation = VALUES(translation);

INSERT INTO translations (locale, category, key_name, translation) VALUES
('en', 'settings', 'upload_restore', 'Restore from file'),
('uk', 'settings', 'upload_restore', 'Відновити з файлу'),
('ru', 'settings', 'upload_restore', 'Восстановить из файла')
ON DUPLICATE KEY UPDATE translation = VALUES(translation);

INSERT INTO translations (locale, category, key_name, translation) VALUES
('en', 'settings', 'upload_restore_desc', 'Upload a .sql.gz backup file to restore the database.'),
('uk', 'settings', 'upload_restore_desc', 'Завантажте файл резервної копії .sql.gz для відновлення бази даних.'),
('ru', 'settings', 'upload_restore_desc', 'Загрузите файл резервной копии .sql.gz для восстановления базы данных.')
ON DUPLICATE KEY UPDATE translation = VALUES(translation);
