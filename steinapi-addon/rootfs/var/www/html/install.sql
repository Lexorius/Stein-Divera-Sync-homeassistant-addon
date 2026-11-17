-- SteinAPI Database Schema
-- Creates all necessary tables for the sync system.
-- Läuft nur bei der Erstinstallation. Bestehende Installationen behalten die
-- früheren, ungenutzten Tabellen member_cache, field_config und settings;
-- sie stören nicht und werden nicht mehr gelesen oder geschrieben.

-- Sync History Table: eine Zeile je Sync-Lauf, älter als 90 Tage wird gelöscht
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

-- Sync Logs Table: Aktivitätsprotokoll, begrenzt durch die Option max_log_entries
CREATE TABLE IF NOT EXISTS sync_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    level VARCHAR(20) NOT NULL DEFAULT 'info',
    message TEXT NOT NULL,
    context JSON,
    timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_timestamp (timestamp),
    INDEX idx_level (level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Zuletzt synchronisierter Stand je Fahrzeug (Schlüssel = Kennzeichen).
-- Grundlage für den feldweisen Abgleich: nur wer vom letzten gemeinsamen Stand
-- abweicht, hat geändert. Bei bestehenden Installationen legt der Sync die
-- Tabelle beim ersten Lauf selbst an.
CREATE TABLE IF NOT EXISTS sync_state (
    vehicle_key VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
    last_status VARCHAR(20) NULL,
    last_comment TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initial log entry
INSERT INTO sync_logs (level, message, context) VALUES
    ('info', 'SteinAPI initialized', '{"version": "2.2.6"}');
