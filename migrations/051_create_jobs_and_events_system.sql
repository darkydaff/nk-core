-- Migration: Create Job and Event System for real-time tracking
-- Created: 2026-05-09
-- Purpose: Evolve architecture to an event-driven control plane

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS job_events;
DROP TABLE IF EXISTS jobs;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE jobs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    server_id INT UNSIGNED DEFAULT NULL,
    type VARCHAR(50) NOT NULL, -- e.g., 'provision_server', 'sync_all', 'backup'
    status ENUM('pending', 'running', 'success', 'error', 'cancelled') DEFAULT 'pending',
    payload JSON DEFAULT NULL, -- Input parameters
    result JSON DEFAULT NULL,  -- Final output/error data
    started_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_user_type (user_id, type),
    INDEX idx_server (server_id),
    INDEX idx_status (status),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (server_id) REFERENCES vpn_servers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE job_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    job_id INT UNSIGNED NOT NULL,
    type VARCHAR(50) NOT NULL, -- e.g., 'step_start', 'log', 'step_end', 'error', 'progress'
    level ENUM('debug', 'info', 'warning', 'error') DEFAULT 'info',
    message TEXT,               -- Human readable message
    payload JSON DEFAULT NULL,  -- Structured data (e.g., {"step": "docker_install", "percent": 25})
    created_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6), -- Microsecond precision for log ordering
    
    INDEX idx_job_type (job_id, type),
    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link servers to their active jobs
ALTER TABLE vpn_servers ADD COLUMN IF NOT EXISTS current_job_id INT UNSIGNED DEFAULT NULL;
ALTER TABLE vpn_servers ADD CONSTRAINT fk_server_current_job FOREIGN KEY (current_job_id) REFERENCES jobs(id) ON DELETE SET NULL;
