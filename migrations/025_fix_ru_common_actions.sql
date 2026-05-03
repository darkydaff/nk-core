-- Fix Russian translations for common.actions
INSERT INTO translations (locale, category, key_name, translation) VALUES ('ru', 'common', 'actions', 'Действия') ON DUPLICATE KEY UPDATE translation = 'Действия';
