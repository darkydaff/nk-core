-- Insert missing Ukrainian translations
-- Keys that don't exist yet in the database

-- Common
INSERT IGNORE INTO translations (locale, category, key_name, translation) VALUES
('uk', 'common', 'name', 'Ім`я'),
('uk', 'common', 'actions', 'Дії'),
('uk', 'common', 'search', 'Пошук'),
('uk', 'common', 'mb', 'МБ'),
('uk', 'common', 'gb', 'ГБ'),
('uk', 'common', 'kb', 'КБ'),
('uk', 'common', 'uploaded', 'Відправлено'),
('uk', 'common', 'downloaded', 'Отримано');

-- Clients
INSERT IGNORE INTO translations (locale, category, key_name, translation) VALUES
('uk', 'clients', 'title', 'Клієнт'),
('uk', 'clients', 'name', 'Ім`я'),
('uk', 'clients', 'client_name', 'Ім`я клієнта'),
('uk', 'clients', 'ip', 'IP адреса'),
('uk', 'clients', 'settings', 'Налаштування клієнта'),
('uk', 'clients', 'configuration', 'Конфігурація'),
('uk', 'clients', 'download_config', 'Завантажити конфіг'),
('uk', 'clients', 'copy_config', 'Копіювати конфіг'),
('uk', 'clients', 'copied', 'Скопійовано!'),
('uk', 'clients', 'copy_failed', 'Не вдалося копіювати'),
('uk', 'clients', 'sync_stats', 'Синхронізувати статистику'),
('uk', 'clients', 'syncing', 'Синхронізація...'),
('uk', 'clients', 'sync_failed', 'Не вдалося синхронізувати'),
('uk', 'clients', 'delete', 'Видалити клієнта'),
('uk', 'clients', 'delete_confirm', 'Видалити клієнта назавжди?'),
('uk', 'clients', 'restore', 'Відновити клієнта'),
('uk', 'clients', 'last_handshake', 'Останнє з`єднання'),
('uk', 'clients', 'never', 'Ніколи'),
('uk', 'clients', 'traffic', 'Трафік'),
('uk', 'clients', 'created', 'Створено'),
('uk', 'clients', 'created_at', 'Створено'),
('uk', 'clients', 'no_results', 'Клієнтів не знайдено'),
('uk', 'clients', 'no_clients', 'Клієнтів поки що немає'),
('uk', 'clients', 'no_clients_hint', 'Створіть першого клієнта'),
('uk', 'clients', 'name_hint', 'Пробіли стануть підкресленнями. Кирилиця підтримується.'),
('uk', 'clients', 'add', 'Додати клієнта'),
('uk', 'clients', 'create', 'Створити'),
('uk', 'clients', 'create_client', 'Створити клієнта'),
('uk', 'clients', 'view', 'Переглянути');

-- Servers
INSERT IGNORE INTO translations (locale, category, key_name, translation) VALUES
('uk', 'servers', 'vpn_port', 'VPN порт'),
('uk', 'servers', 'subnet', 'Підмережа'),
('uk', 'servers', 'protocol', 'Протокол'),
('uk', 'servers', 'new_client', 'Новий клієнт'),
('uk', 'servers', 'sync', 'Синхронізувати'),
('uk', 'servers', 'delete_confirm', 'Видалити цей сервер?'),
('uk', 'servers', 'delete', 'Видалити'),
('uk', 'servers', 'handshake', 'З`єднання'),
('uk', 'servers', 'never', 'Ніколи'),
('uk', 'servers', 'active', 'Активний'),
('uk', 'servers', 'offline', 'Офлайн'),
('uk', 'servers', 'deploying', 'Розгортання'),
('uk', 'servers', 'traffic', 'Трафік'),
('uk', 'servers', 'deployed', 'Розгорнуто');

-- Form
INSERT IGNORE INTO translations (locale, category, key_name, translation) VALUES
('uk', 'form', 'save', 'Зберегти'),
('uk', 'form', 'select', 'Обрати');

-- Message
INSERT IGNORE INTO translations (locale, category, key_name, translation) VALUES
('uk', 'message', 'connection_error', 'Помилка з`єднання'),
('uk', 'message', 'unknown_error', 'Невідома помилка'),
('uk', 'message', 'success', 'Успіх'),
('uk', 'message', 'error', 'Помилка');

-- Status
INSERT IGNORE INTO translations (locale, category, key_name, translation) VALUES
('uk', 'status', 'active', 'Активний'),
('uk', 'status', 'offline', 'Офлайн'),
('uk', 'status', 'deploying', 'Розгортання'),
('uk', 'status', 'online', 'Онлайн');

-- Users
INSERT IGNORE INTO translations (locale, category, key_name, translation) VALUES
('uk', 'users', 'you', 'Ви');
