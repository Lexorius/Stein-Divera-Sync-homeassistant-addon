-- SteinAPI Database Schema
-- Creates all necessary tables for the sync system

-- Sync History Table
CREATE TABLE IF NOT EXISTS sync_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    direction VARCHAR(50) NOT NULL DEFAULT 'both',
    source VARCHAR(50) NOT NULL DEFAULT 'manual',
    synced_count INT NOT NULL DEFAULT 0,
    created_count INT NOT NULL DEFAULT 0,
    updated_count INT NOT NULL DEFAULT 0,
    error_count INT NOT NULL DEFAULT 0,
    success TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_at (created_at),
    INDEX idx_source (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sync Logs Table
CREATE TABLE IF NOT EXISTS sync_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    level VARCHAR(20) NOT NULL DEFAULT 'info',
    message TEXT NOT NULL,
    context JSON,
    timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_timestamp (timestamp),
    INDEX idx_level (level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Member Cache Table (for tracking sync state)
CREATE TABLE IF NOT EXISTS member_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    divera_id VARCHAR(100),
    stein_id VARCHAR(100),
    data_hash VARCHAR(64) NOT NULL,
    last_synced DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_divera_id (divera_id),
    UNIQUE INDEX idx_stein_id (stein_id),
    INDEX idx_last_synced (last_synced)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Field Configuration Table
CREATE TABLE IF NOT EXISTS field_config (
    id INT PRIMARY KEY DEFAULT 1,
    config JSON NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default field configuration for VEHICLES
INSERT INTO field_config (id, config) VALUES (1, '{
    "status": true,
    "radio_name": true,
    "comment": false,
    "category": false,
    "name": false
}') ON DUPLICATE KEY UPDATE id = id;

-- Settings Table
CREATE TABLE IF NOT EXISTS settings (
    name VARCHAR(100) PRIMARY KEY,
    value TEXT,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default settings
INSERT INTO settings (name, value) VALUES 
    ('last_sync', NULL),
    ('auto_sync_enabled', 'true'),
    ('sync_direction', 'both')
ON DUPLICATE KEY UPDATE name = name;

-- Initial log entry
INSERT INTO sync_logs (level, message, context) VALUES 
    ('info', 'SteinAPI initialized', '{"version": "1.0.0"}');
