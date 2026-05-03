-- Fix common.actions for Ukrainian
INSERT INTO translations (locale, category, key_name, translation) VALUES ('uk', 'common', 'actions', 'Дії') ON DUPLICATE KEY UPDATE translation = 'Дії';
