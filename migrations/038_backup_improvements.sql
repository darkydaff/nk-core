-- Migration 038: Backup Improvements Translations
INSERT INTO translations (locale, category, key_name, translation) VALUES
('en', 'settings', 'confirm_delete_backup', 'Are you sure you want to delete this backup? This action cannot be undone.'),
('ru', 'settings', 'confirm_delete_backup', 'Вы уверены, что хотите удалить эту резервную копию? Это действие нельзя отменить.'),
('uk', 'settings', 'confirm_delete_backup', 'Ви впевнені, що хочете видалити цю резервну копію? Цю дію не можна скасувати.')
ON DUPLICATE KEY UPDATE translation = VALUES(translation);

INSERT INTO translations (locale, category, key_name, translation) VALUES
('en', 'settings', 'backup_integrity_checksum', 'SHA-256 Checksum'),
('ru', 'settings', 'backup_integrity_checksum', 'Контрольная сумма SHA-256'),
('uk', 'settings', 'backup_integrity_checksum', 'Контрольна сума SHA-256')
ON DUPLICATE KEY UPDATE translation = VALUES(translation);

INSERT INTO translations (locale, category, key_name, translation) VALUES
('en', 'settings', 'disk_space_error', 'Insufficient disk space for backup.'),
('ru', 'settings', 'disk_space_error', 'Недостаточно места на диске для резервного копирования.'),
('uk', 'settings', 'disk_space_error', 'Недостатньо місця на диску для резервного копіювання.')
ON DUPLICATE KEY UPDATE translation = VALUES(translation);
