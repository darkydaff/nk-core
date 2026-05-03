-- Comprehensive Ukrainian translation update
-- Updates ALL existing translation values to Ukrainian

-- Menu
UPDATE translations SET translation = 'Адмін' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'menu.admin';
UPDATE translations SET translation = 'Панель керування' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'menu.dashboard';
UPDATE translations SET translation = 'Сервери' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'menu.servers';
UPDATE translations SET translation = 'Налаштування' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'menu.settings';
UPDATE translations SET translation = 'Вихід' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'menu.logout';

-- Dashboard
UPDATE translations SET translation = 'Панель керування' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'dashboard.title';
UPDATE translations SET translation = 'Ласкаво просимо до панелі керування VPN Amnezia' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'dashboard.welcome';
UPDATE translations SET translation = 'Всього серверів' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'dashboard.total_servers';
UPDATE translations SET translation = 'Всього клієнтів' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'dashboard.total_clients';
UPDATE translations SET translation = 'Активні клієнти' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'dashboard.active_clients';
UPDATE translations SET translation = 'Останні сервери' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'dashboard.recent_servers';
UPDATE translations SET translation = 'Переглянути всі' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'dashboard.view_all';
UPDATE translations SET translation = 'Серверів поки що немає' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'dashboard.no_servers';
UPDATE translations SET translation = 'Додати перший сервер' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'dashboard.add_first_server';
UPDATE translations SET translation = 'Початок роботи з додавання вашого першого VPN сервера' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'dashboard.get_started';
UPDATE translations SET translation = 'Швидкі дії' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'dashboard.quick_actions';
UPDATE translations SET translation = 'Загальний трафік' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'dashboard.total_traffic';

-- Servers
UPDATE translations SET translation = 'Сервери' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'servers.title';
UPDATE translations SET translation = 'Додати сервер' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'servers.add';
UPDATE translations SET translation = 'Додати новий сервер' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'servers.add_new';
UPDATE translations SET translation = 'Розгорнути новий екземпляр AmneziaWG VPN або імпортувати існуючу конфігурацію' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'servers.create_description';
UPDATE translations SET translation = 'Назва' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'servers.name';
UPDATE translations SET translation = 'Хост' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'servers.host';
UPDATE translations SET translation = 'Дії' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'servers.actions';
UPDATE translations SET translation = 'Переглянути' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'servers.view';
UPDATE translations SET translation = 'Редагувати' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'servers.edit';
UPDATE translations SET translation = 'Видалити' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'servers.delete';
UPDATE translations SET translation = 'Клієнти' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'servers.clients';
UPDATE translations SET translation = 'VPN порт' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'servers.vpn_port';
UPDATE translations SET translation = 'Підмережа' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'servers.subnet';
UPDATE translations SET translation = 'Протокол' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'servers.protocol';
UPDATE translations SET translation = 'Серверів поки що немає' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'servers.no_servers_yet';
UPDATE translations SET translation = 'Додати клієнта' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'servers.add_client';
UPDATE translations SET translation = 'Створити клієнта' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'servers.create_client';
UPDATE translations SET translation = 'Назад до сервера' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'servers.back_to_server';
UPDATE translations SET translation = 'Розгортання' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'servers.deploy';
UPDATE translations SET translation = 'Створити сервер' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'servers.create';

-- Import servers
UPDATE translations SET translation = 'Імпорт з існуючої панелі' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'servers.import_from_panel';
UPDATE translations SET translation = 'Обрати тип панелі' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'servers.select_panel_type';
UPDATE translations SET translation = 'wg-easy' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'servers.panel_type_wgeasy';
UPDATE translations SET translation = '3x-ui' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'servers.panel_type_3xui';
UPDATE translations SET translation = 'Завантажити файл резервної копії (JSON)' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'servers.upload_backup_file';
UPDATE translations SET translation = 'Імпорт триває...' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'servers.import_in_progress';
UPDATE translations SET translation = 'Успішно імпортовано {0} клієнтів' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'servers.import_success';
UPDATE translations SET translation = 'Імпорт не вдався' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'servers.import_failed';
UPDATE translations SET translation = 'Імпортовано {0} з {1} клієнтів' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'servers.import_partial';
UPDATE translations SET translation = 'Історія імпорту' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'servers.import_history';

-- Clients
UPDATE translations SET translation = 'Клієнт' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'clients.title';
UPDATE translations SET translation = 'Налаштування клієнта' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'clients.settings';
UPDATE translations SET translation = 'IP адреса' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'clients.ip';
UPDATE translations SET translation = 'Ім`я клієнта' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'clients.client_name';
UPDATE translations SET translation = 'Конфігурація' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'clients.configuration';
UPDATE translations SET translation = 'Завантажити конфіг' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'clients.download_config';
UPDATE translations SET translation = 'Копіювати конфіг' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'clients.copy_config';
UPDATE translations SET translation = 'Скопійовано!' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'clients.copied';
UPDATE translations SET translation = 'Не вдалося копіювати' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'clients.copy_failed';
UPDATE translations SET translation = 'Синхронізувати статистику' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'clients.sync_stats';
UPDATE translations SET translation = 'Синхронізація...' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'clients.syncing';
UPDATE translations SET translation = 'Не вдалося синхронізувати' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'clients.sync_failed';
UPDATE translations SET translation = 'Видалити клієнта' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'clients.delete';
UPDATE translations SET translation = 'Видалити клієнта назавжди?' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'clients.delete_confirm';
UPDATE translations SET translation = 'Відновити клієнта' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'clients.restore';
UPDATE translations SET translation = 'Останнє з`єднання' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'clients.last_handshake';
UPDATE translations SET translation = 'Ніколи' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'clients.never';
UPDATE translations SET translation = 'Відправлено' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'clients.uploaded';
UPDATE translations SET translation = 'Отримано' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'clients.downloaded';
UPDATE translations SET translation = 'Трафік' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'clients.traffic';
UPDATE translations SET translation = 'Створено' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'clients.created';
UPDATE translations SET translation = 'Клієнтів не знайдено' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'clients.no_results';
UPDATE translations SET translation = 'Клієнтів поки що немає' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'clients.no_clients';
UPDATE translations SET translation = 'Створіть першого клієнта' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'clients.no_clients_hint';
UPDATE translations SET translation = 'Підказка: імя клієнта' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'clients.name_hint';

-- Users
UPDATE translations SET translation = 'Додати користувача' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'users.add_user';
UPDATE translations SET translation = 'Всі користувачі' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'users.all_users';
UPDATE translations SET translation = 'Ім`я' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'users.name';
UPDATE translations SET translation = 'Електронна пошта' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'users.email';
UPDATE translations SET translation = 'Роль' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'users.role';
UPDATE translations SET translation = 'Створено' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'users.created';
UPDATE translations SET translation = 'Адміністратор' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'users.role_admin';
UPDATE translations SET translation = 'Користувач' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'users.role_user';
UPDATE translations SET translation = 'Ви' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'users.you';
UPDATE translations SET translation = 'Видалити користувача {0}?' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'users.delete_confirm';

-- Auth
UPDATE translations SET translation = 'Авторизація' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'auth.login';
UPDATE translations SET translation = 'Реєстрація' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'auth.register';
UPDATE translations SET translation = 'Електронна пошта' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'auth.email';
UPDATE translations SET translation = 'Пароль' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'auth.password';
UPDATE translations SET translation = 'Ім`я' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'auth.name';
UPDATE translations SET translation = 'Повне ім`я' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'auth.full_name';
UPDATE translations SET translation = 'Створити акаунт' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'auth.create_account';
UPDATE translations SET translation = 'Налаштувати ваш акаунт' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'auth.setup_account';
UPDATE translations SET translation = 'Вже маєте акаунт?' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'auth.already_have_account';
UPDATE translations SET translation = 'Іван Петренко' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'auth.placeholder_name';
UPDATE translations SET translation = 'ivan@example.com' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'auth.placeholder_email';

-- Settings
UPDATE translations SET translation = 'Налаштування' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'settings.title';
UPDATE translations SET translation = 'Профіль' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'settings.profile';
UPDATE translations SET translation = 'Користувачі' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'settings.users';
UPDATE translations SET translation = 'Управління налаштуваннями панелі та API' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'settings.description';
UPDATE translations SET translation = 'Змінити пароль' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'settings.change_password';
UPDATE translations SET translation = 'Поточний пароль' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'settings.current_password';
UPDATE translations SET translation = 'Новий пароль' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'settings.new_password';
UPDATE translations SET translation = 'Підтвердження пароля' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'settings.confirm_password';
UPDATE translations SET translation = 'Мінімум 6 символів' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'settings.min_6_chars';
UPDATE translations SET translation = 'Дії' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'settings.actions';

-- Common
UPDATE translations SET translation = 'МБ' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'common.mb';
UPDATE translations SET translation = 'ГБ' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'common.gb';
UPDATE translations SET translation = 'КБ' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'common.kb';
UPDATE translations SET translation = 'Зберегти' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'form.save';
UPDATE translations SET translation = 'Обрати' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'form.select';
UPDATE translations SET translation = 'Назва' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'common.name';
UPDATE translations SET translation = 'Дії' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'common.actions';
UPDATE translations SET translation = 'Пошук' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'common.search';
UPDATE translations SET translation = 'Помилка з`єднання' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'message.connection_error';
UPDATE translations SET translation = 'Невідома помилка' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'message.unknown_error';

-- Status
UPDATE translations SET translation = 'Активний' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'status.active';
UPDATE translations SET translation = 'Офлайн' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'status.offline';
UPDATE translations SET translation = 'Розгортання' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'status.deploying';

-- Client traffic
UPDATE translations SET translation = 'Відправлено' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'common.uploaded';
UPDATE translations SET translation = 'Отримано' WHERE locale = 'uk' AND CONCAT(category, '.', key_name) = 'common.downloaded';
