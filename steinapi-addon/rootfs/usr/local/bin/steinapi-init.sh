#!/usr/bin/env bash
set -e

CONFIG_PATH=/data/options.json
DATA_DIR=/data/steinapi
MYSQL_DATA=/data/mysql
WWW_DIR=/var/www/html

echo "============================================"
echo "SteinAPI Add-on Initialisierung"
echo "============================================"

# Lese Konfiguration aus Home Assistant
echo "[INFO] Lese Konfiguration..."

PORT=$(jq -r '.port // 8099' $CONFIG_PATH)
DIVERA_ACCESS_KEY=$(jq -r '.divera_access_key // ""' $CONFIG_PATH)
DIVERA_BASE_URL=$(jq -r '.divera_base_url // "https://app.divera247.com/api/v2"' $CONFIG_PATH)
STEIN_API_KEY=$(jq -r '.stein_api_key // ""' $CONFIG_PATH)
STEIN_BUSINESS_UNIT_ID=$(jq -r '.stein_business_unit_id // ""' $CONFIG_PATH)
STEIN_BASE_URL=$(jq -r '.stein_base_url // "https://www.stein.app/api"' $CONFIG_PATH)
SYNC_INTERVAL=$(jq -r '.sync_interval_minutes // 5' $CONFIG_PATH)
SYNC_DIRECTION=$(jq -r '.sync_direction // "both"' $CONFIG_PATH)
AUTO_SYNC=$(jq -r '.auto_sync_enabled // true' $CONFIG_PATH)
TIMEZONE=$(jq -r '.timezone // "Europe/Berlin"' $CONFIG_PATH)
LOG_LEVEL=$(jq -r '.log_level // "info"' $CONFIG_PATH)
MAX_LOG_ENTRIES=$(jq -r '.max_log_entries // 1000' $CONFIG_PATH)
RATE_LIMIT_REQUESTS=$(jq -r '.rate_limit_requests // 100' $CONFIG_PATH)
RATE_LIMIT_WINDOW=$(jq -r '.rate_limit_window_seconds // 60' $CONFIG_PATH)

echo "[INFO] Port: $PORT"
echo "[INFO] Sync Interval: $SYNC_INTERVAL Minuten"
echo "[INFO] Sync Direction: $SYNC_DIRECTION"
echo "[INFO] Auto Sync: $AUTO_SYNC"
echo "[INFO] Timezone: $TIMEZONE"

# Setze Zeitzone
echo "[INFO] Setze Zeitzone auf $TIMEZONE..."
ln -snf /usr/share/zoneinfo/$TIMEZONE /etc/localtime
echo "$TIMEZONE" > /etc/timezone

# Erstelle Datenverzeichnisse
echo "[INFO] Erstelle Verzeichnisse..."
mkdir -p $DATA_DIR
mkdir -p $MYSQL_DATA
mkdir -p /var/log/steinapi

# MySQL Initialisierung
if [ ! -d "$MYSQL_DATA/mysql" ]; then
    echo "[INFO] Initialisiere MariaDB Datenbank..."
    mysql_install_db --user=mysql --datadir=$MYSQL_DATA --skip-test-db
    
    # Starte MySQL temporär
    /usr/bin/mysqld_safe --datadir=$MYSQL_DATA &
    sleep 5
    
    # Warte auf MySQL
    for i in {1..30}; do
        if mysqladmin ping &>/dev/null; then
            break
        fi
        sleep 1
    done
    
    # Erstelle Datenbank und Benutzer
    echo "[INFO] Erstelle Datenbank..."
    mysql -u root <<EOF
CREATE DATABASE IF NOT EXISTS steinapi CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'steinapi'@'localhost' IDENTIFIED BY 'steinapi_password';
GRANT ALL PRIVILEGES ON steinapi.* TO 'steinapi'@'localhost';
FLUSH PRIVILEGES;
EOF
    
    # Importiere Schema
    echo "[INFO] Importiere Datenbankschema..."
    mysql -u root steinapi < /var/www/html/install.sql
    
    # Stoppe temporäre MySQL Instanz
    mysqladmin shutdown
    sleep 2
fi

# Erstelle PHP Konfigurationsdatei
echo "[INFO] Erstelle PHP Konfiguration..."
cat > $WWW_DIR/config/config.php << PHPCONFIG
<?php
/**
 * SteinAPI Konfiguration
 * Automatisch generiert durch Home Assistant Add-on
 */

return [
    // Datenbank Konfiguration
    'database' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'steinapi',
        'user' => 'steinapi',
        'password' => 'steinapi_password',
        'charset' => 'utf8mb4'
    ],
    
    // Divera Konfiguration
    'divera' => [
        'access_key' => '$DIVERA_ACCESS_KEY',
        'base_url' => '$DIVERA_BASE_URL',
        'enabled' => true
    ],
    
    // Stein.app Konfiguration
    'stein' => [
        'api_key' => '$STEIN_API_KEY',
        'business_unit_id' => '$STEIN_BUSINESS_UNIT_ID',
        'base_url' => '$STEIN_BASE_URL',
        'enabled' => true
    ],
    
    // Sync Einstellungen
    'sync' => [
        'interval_minutes' => $SYNC_INTERVAL,
        'direction' => '$SYNC_DIRECTION',
        'auto_enabled' => $AUTO_SYNC,
        'fields' => [
            'name' => true,
            'email' => true,
            'phone' => true,
            'address' => true,
            'status' => true,
            'qualifications' => true
        ]
    ],
    
    // Logging
    'logging' => [
        'level' => '$LOG_LEVEL',
        'max_entries' => $MAX_LOG_ENTRIES,
        'path' => '/var/log/steinapi'
    ],
    
    // Rate Limiting
    'rate_limit' => [
        'requests' => $RATE_LIMIT_REQUESTS,
        'window_seconds' => $RATE_LIMIT_WINDOW
    ],
    
    // Home Assistant Integration
    'homeassistant' => [
        'ingress' => true,
        'addon_slug' => 'steinapi'
    ]
];
PHPCONFIG

# Setze Berechtigungen
chown -R nginx:nginx $WWW_DIR
chmod -R 755 $WWW_DIR
chmod 640 $WWW_DIR/config/config.php

# Speichere Sync-Intervall für Cron
echo "$SYNC_INTERVAL" > /data/sync_interval
echo "$AUTO_SYNC" > /data/auto_sync

echo "[INFO] Initialisierung abgeschlossen!"
echo "============================================"
