-- Add missing translation keys for en/uk/ru.

INSERT INTO translations (locale, category, key_name, translation) VALUES
('en', 'status', 'deleting', 'Deleting...'),
('uk', 'status', 'deleting', 'Видалення...'),
('ru', 'status', 'deleting', 'Удаление...'),

('en', 'settings', 'confirm_restore', 'Confirm Restore'),
('uk', 'settings', 'confirm_restore', 'Підтвердити відновлення'),
('ru', 'settings', 'confirm_restore', 'Подтвердить восстановление'),

('en', 'common', 'danger_zone', 'Danger Zone'),
('uk', 'common', 'danger_zone', 'Небезпечна зона'),
('ru', 'common', 'danger_zone', 'Опасная зона'),

('en', 'settings', 'restore_warning', 'Restoring from a backup will overwrite your current database and configurations. This action cannot be undone.'),
('uk', 'settings', 'restore_warning', 'Відновлення з резервної копії перезапише вашу поточну базу даних та конфігурації. Цю дію неможливо скасувати.'),
('ru', 'settings', 'restore_warning', 'Восстановление из резервной копии перезапишет вашу текущую базу данных и конфигурации. Это действие нельзя отменить.'),

('en', 'settings', 'confirm_with_password', 'To proceed, please enter your admin password'),
('uk', 'settings', 'confirm_with_password', 'Для продовження, будь ласка, введіть пароль адміністратора'),
('ru', 'settings', 'confirm_with_password', 'Для продолжения, пожалуйста, введите пароль администратора'),

('en', 'settings', 'enter_admin_password', 'Enter admin password'),
('uk', 'settings', 'enter_admin_password', 'Введіть пароль адміністратора'),
('ru', 'settings', 'enter_admin_password', 'Введите пароль администратора'),

('en', 'common', 'cancel', 'Cancel'),
('uk', 'common', 'cancel', 'Скасувати'),
('ru', 'common', 'cancel', 'Отмена'),

('en', 'settings', 'start_restore', 'Start Restore'),
('uk', 'settings', 'start_restore', 'Почати відновлення'),
('ru', 'settings', 'start_restore', 'Начать восстановление'),

('en', 'servers', 'view_logs', 'View Logs'),
('uk', 'servers', 'view_logs', 'Переглянути логи'),
('ru', 'servers', 'view_logs', 'Просмотреть логи'),

('en', 'servers', 'deployment_logs', 'Deployment Logs'),
('uk', 'servers', 'deployment_logs', 'Логи розгортання'),
('ru', 'servers', 'deployment_logs', 'Логи развертывания'),

('en', 'common', 'refresh', 'Refresh'),
('uk', 'common', 'refresh', 'Оновити'),
('ru', 'common', 'refresh', 'Обновить'),

('en', 'common', 'close', 'Close'),
('uk', 'common', 'close', 'Закрити'),
('ru', 'common', 'close', 'Закрыть'),

('en', 'servers', 'no_logs', 'No log entries found for this server yet.'),
('uk', 'servers', 'no_logs', 'Логів для цього сервера поки не знайдено.'),
('ru', 'servers', 'no_logs', 'Логов для этого сервера пока не найдено.'),

('en', 'servers', 'log_entries', 'entries'),
('uk', 'servers', 'log_entries', 'записів'),
('ru', 'servers', 'log_entries', 'записей'),

('en', 'servers', 'logs_failed', 'Failed to load logs'),
('uk', 'servers', 'logs_failed', 'Не вдалося завантажити логи'),
('ru', 'servers', 'logs_failed', 'Не удалось загрузить логи'),

('en', 'common', 'auto_scroll', 'Auto-scroll'),
('uk', 'common', 'auto_scroll', 'Автопрокрутка'),
('ru', 'common', 'auto_scroll', 'Автопрокрутка'),

('en', 'common', 'loading_logs', 'Loading logs...'),
('uk', 'common', 'loading_logs', 'Завантаження логів...'),
('ru', 'common', 'loading_logs', 'Загрузка логов...')
ON DUPLICATE KEY UPDATE translation = VALUES(translation);
