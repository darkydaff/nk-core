-- Migration 063: Add missing deployment and map translations for en/uk/ru
-- Date: 2026-05-24

INSERT INTO translations (locale, category, key_name, translation) VALUES
-- Map Servers Distribution
('en', 'map', 'fleet_distribution', 'Servers Distribution'),
('uk', 'map', 'fleet_distribution', 'Розподіл серверів'),
('ru', 'map', 'fleet_distribution', 'Распределение серверов'),

-- Deployment Stepper & Console
('en', 'deployment', 'progress', 'Deployment Progress'),
('uk', 'deployment', 'progress', 'Прогрес розгортання'),
('ru', 'deployment', 'progress', 'Прогресс развертывания'),

('en', 'deployment', 'waiting_to_start', 'Waiting to start...'),
('uk', 'deployment', 'waiting_to_start', 'Очікування запуску...'),
('ru', 'deployment', 'waiting_to_start', 'Ожидание запуска...'),

('en', 'deployment', 'cancel', 'Cancel Deployment'),
('uk', 'deployment', 'cancel', 'Скасувати розгортання'),
('ru', 'deployment', 'cancel', 'Отменить развертывание'),

('en', 'servers', 'initiating', 'Initiating'),
('uk', 'servers', 'initiating', 'Ініціалізація'),
('ru', 'servers', 'initiating', 'Инициализация'),

('en', 'servers', 'deploying', 'Deploying'),
('uk', 'servers', 'deploying', 'Розгортання'),
('ru', 'servers', 'deploying', 'Развертывание'),

('en', 'servers', 'deploy_step_hint', 'Tailoring kernel parameters for high-performance VPN throughput...'),
('uk', 'servers', 'deploy_step_hint', 'Налаштування параметрів ядра для високої пропускної здатності VPN...'),
('ru', 'servers', 'deploy_step_hint', 'Настройка параметров ядра для высокой пропускной способности VPN...'),

('en', 'servers', 'deployment_in_progress', 'Deployment In Progress'),
('uk', 'servers', 'deployment_in_progress', 'Розгортання триває'),
('ru', 'servers', 'deployment_in_progress', 'Выполняется развертывание'),

('en', 'servers', 'deploying_to', 'Provisioning node:'),
('uk', 'servers', 'deploying_to', 'Налаштування вузла:'),
('ru', 'servers', 'deploying_to', 'Настройка узла:')
ON DUPLICATE KEY UPDATE translation = VALUES(translation);
