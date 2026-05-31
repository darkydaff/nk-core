-- Migration 065: Update active_nodes terminology to servers and update overview_subtitle/fleet_health in translation database
-- Date: 2026-05-31

-- active_nodes updates
UPDATE translations SET translation = 'Active Servers' WHERE key_name = 'active_nodes' AND category = 'dashboard' AND locale = 'en';
UPDATE translations SET translation = 'Активні сервери' WHERE key_name = 'active_nodes' AND category = 'dashboard' AND locale = 'uk';
UPDATE translations SET translation = 'Активные серверы' WHERE key_name = 'active_nodes' AND category = 'dashboard' AND locale = 'ru';

-- fleet_health updates
UPDATE translations SET translation = 'Server Status' WHERE key_name = 'fleet_health' AND category = 'dashboard' AND locale = 'en';
UPDATE translations SET translation = 'Стан серверів' WHERE key_name = 'fleet_health' AND category = 'dashboard' AND locale = 'uk';
UPDATE translations SET translation = 'Состояние серверов' WHERE key_name = 'fleet_health' AND category = 'dashboard' AND locale = 'ru';

-- overview_subtitle updates
UPDATE translations SET translation = 'Global servers and client overview' WHERE key_name = 'overview_subtitle' AND category = 'dashboard' AND locale = 'en';
UPDATE translations SET translation = 'Загальний огляд серверів та клієнтів' WHERE key_name = 'overview_subtitle' AND category = 'dashboard' AND locale = 'uk';
UPDATE translations SET translation = 'Общий обзор серверов и клиентов' WHERE key_name = 'overview_subtitle' AND category = 'dashboard' AND locale = 'ru';
