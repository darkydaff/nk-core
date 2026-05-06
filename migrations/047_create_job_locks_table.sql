CREATE TABLE IF NOT EXISTS job_locks (
    name VARCHAR(255) PRIMARY KEY,
    expires_at DATETIME NOT NULL,
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
