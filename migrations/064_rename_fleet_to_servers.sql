-- Migration 064: Rename fleet terminology to servers in translations
-- Date: 2026-05-24

UPDATE translations SET translation = 'Global servers and client overview' WHERE key_name = 'overview_subtitle' AND category = 'dashboard' AND locale = 'en';
UPDATE translations SET translation = 'Загальний огляд серверів та клієнтів' WHERE key_name = 'overview_subtitle' AND category = 'dashboard' AND locale = 'uk';
UPDATE translations SET translation = 'Общий обзор серверов и клиентов' WHERE key_name = 'overview_subtitle' AND category = 'dashboard' AND locale = 'ru';

UPDATE translations SET translation = 'Search' WHERE key_name = 'fleet_search' AND category = 'dashboard' AND locale = 'en';
UPDATE translations SET translation = 'Пошук' WHERE key_name = 'fleet_search' AND category = 'dashboard' AND locale = 'uk';
UPDATE translations SET translation = 'Поиск' WHERE key_name = 'fleet_search' AND category = 'dashboard' AND locale = 'ru';

UPDATE translations SET translation = 'Server Status' WHERE key_name = 'fleet_health' AND category = 'dashboard' AND locale = 'en';
UPDATE translations SET translation = 'Стан серверів' WHERE key_name = 'fleet_health' AND category = 'dashboard' AND locale = 'uk';
UPDATE translations SET translation = 'Состояние серверов' WHERE key_name = 'fleet_health' AND category = 'dashboard' AND locale = 'ru';

UPDATE translations SET translation = 'Client Distribution' WHERE key_name = 'fleet_distribution' AND category = 'map' AND locale = 'en';
UPDATE translations SET translation = 'Розподіл клієнтів' WHERE key_name = 'fleet_distribution' AND category = 'map' AND locale = 'uk';
UPDATE translations SET translation = 'Распределение клиентов' WHERE key_name = 'fleet_distribution' AND category = 'map' AND locale = 'ru';
