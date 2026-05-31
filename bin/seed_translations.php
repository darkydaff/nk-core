<?php
/**
 * Seed missing dashboard translations for all languages
 */

require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/Translator.php';

Config::load(__DIR__ . '/../.env');
$pdo = DB::conn();

$data = [
    // English
    ['en', 'dashboard', 'overview_subtitle', 'Global servers and client overview'],
    ['en', 'dashboard', 'active_nodes', 'Active Servers'],
    ['en', 'dashboard', 'provisioned_access', 'Total configs'],
    ['en', 'dashboard', 'total_ingress', 'Total Inbound'],
    ['en', 'dashboard', 'total_egress', 'Total Outbound'],
    ['en', 'dashboard', 'traffic_analytics', 'Traffic Analytics'],
    ['en', 'dashboard', 'fleet_health', 'Server Status'],
    ['en', 'common', 'download', 'Download'],
    ['en', 'common', 'upload', 'Upload'],
    ['en', 'common', 'status_all', 'All Status'],
    ['en', 'dashboard', 'traffic_all', 'All Traffic'],
    ['en', 'dashboard', 'sort_recent', 'Newest First'],
    ['en', 'dashboard', 'sort_traffic', 'Most Traffic'],
    ['en', 'dashboard', 'sort_handshake', 'Last Activity'],
    
    // Ukrainian
    ['uk', 'dashboard', 'overview_subtitle', 'Загальний огляд серверів та клієнтів'],
    ['uk', 'dashboard', 'active_nodes', 'Активні сервери'],
    ['uk', 'dashboard', 'provisioned_access', 'Всього конфігурацій'],
    ['uk', 'dashboard', 'total_ingress', 'Сумарний вхідний трафік'],
    ['uk', 'dashboard', 'total_egress', 'Сумарний вихідний трафік'],
    ['uk', 'dashboard', 'traffic_analytics', 'Аналітика трафіку'],
    ['uk', 'dashboard', 'fleet_health', 'Стан серверів'],
    ['uk', 'common', 'download', 'Завантаження'],
    ['uk', 'common', 'upload', 'Віддача'],
    ['uk', 'common', 'status', 'Статус'],
    ['uk', 'common', 'status_all', 'Усі статуси'],
    ['uk', 'common', 'traffic', 'Трафік'],
    ['uk', 'dashboard', 'traffic_all', 'Будь-який трафік'],
    ['uk', 'dashboard', 'sort_recent', 'Спочатку нові'],
    ['uk', 'dashboard', 'sort_traffic', 'За трафіком'],
    ['uk', 'dashboard', 'sort_handshake', 'Остання активність'],
    ['uk', 'clients', 'list', 'Клієнт / Сервер'],
    ['uk', 'clients', 'last_seen', 'Активність'],

    // Russian
    ['ru', 'dashboard', 'overview_subtitle', 'Общий обзор серверов и клиентов'],
    ['ru', 'dashboard', 'active_nodes', 'Активные серверы'],
    ['ru', 'dashboard', 'provisioned_access', 'Всего конфигураций'],
    ['ru', 'dashboard', 'total_ingress', 'Суммарный входящий трафик'],
    ['ru', 'dashboard', 'total_egress', 'Суммарный исходящий трафик'],
    ['ru', 'dashboard', 'traffic_analytics', 'Аналитика трафика'],
    ['ru', 'dashboard', 'fleet_health', 'Состояние серверов'],
    ['ru', 'common', 'download', 'Загрузка'],
    ['ru', 'common', 'upload', 'Отдача'],
    ['ru', 'common', 'status', 'Статус'],
    ['ru', 'common', 'status_all', 'Все статусы'],
    ['ru', 'common', 'traffic', 'Трафик'],
    ['ru', 'dashboard', 'traffic_all', 'Любой трафик'],
    ['ru', 'dashboard', 'sort_recent', 'Сначала новые'],
    ['ru', 'dashboard', 'sort_traffic', 'По трафику'],
    ['ru', 'dashboard', 'sort_handshake', 'Последняя активность'],
    ['ru', 'clients', 'list', 'Клиент / Сервер'],
    ['ru', 'clients', 'last_seen', 'Активность'],
];

$stmt = $pdo->prepare('INSERT INTO translations (locale, category, key_name, translation) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE translation = VALUES(translation)');

echo "Seeding translations...\n";
foreach ($data as $row) {
    $stmt->execute($row);
}
echo "✅ Done! All dashboard translations seeded.\n";
