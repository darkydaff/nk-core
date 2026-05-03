-- Migration: Add user roles and permissions (No LDAP)
-- Date: 2026-05-02

-- User roles table
CREATE TABLE IF NOT EXISTS user_roles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    description TEXT,
    permissions JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Change role column in users table from ENUM to VARCHAR
ALTER TABLE users 
MODIFY COLUMN role VARCHAR(50) DEFAULT 'viewer';

-- Ensure idx_role index exists
SET @index_exists = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_name = 'users' AND index_name = 'idx_role' AND table_schema = DATABASE());
SET @query = IF(@index_exists = 0, 'ALTER TABLE users ADD INDEX idx_role (role)', 'SELECT 1');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Insert default roles
INSERT IGNORE INTO user_roles (name, display_name, description, permissions) VALUES
('admin', 'Administrator', 'Full access to all features', JSON_ARRAY('*')),
('manager', 'Manager', 'Can manage servers and clients', JSON_ARRAY('servers.view', 'servers.create', 'servers.edit', 'clients.view', 'clients.create', 'clients.edit', 'clients.delete')),
('viewer', 'Viewer', 'Can only view own clients', JSON_ARRAY('clients.view_own', 'clients.download_own'));

-- Update existing users to admin role (backward compatibility)
UPDATE users SET role = 'admin' WHERE role IS NULL OR role = '' OR role = 'user';
