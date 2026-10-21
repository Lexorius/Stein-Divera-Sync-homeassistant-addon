#!/usr/bin/env bash
set -euo pipefail

CONFIG_PATH=/data/options.json
DATA_DIR=/data/steinapi
MYSQL_DATA=/data/mysql
WWW_DIR=/var/www/html
DB_PASS_FILE=$DATA_DIR/db_password
DB_VERSION_FILE=$DATA_DIR/mariadb_version
RATELIMIT_CONF=/etc/nginx/steinapi-ratelimit.conf

echo "============================================"
echo "SteinAPI Add-on Initialisierung"
echo "============================================"

# ---------------------------------------------------------------------------
# Hilfsfunktionen
# ---------------------------------------------------------------------------

# Escaped einen Wert für die Verwendung in einem einfach-quotierten PHP-String.
php_str() {
    local s="$1"
    s=${s//\\/\\\\}
    s=${s//\'/\\\'}
    printf '%s' "$s"
}

# Gibt $1 zurück, wenn es eine positive Ganzzahl ist, sonst $2 (Default).
int_or() {
    if [[ "$1" =~ ^[0-9]+$ ]]; then printf '%s' "$1"; else printf '%s' "$2"; fi
}

# Gibt "true"/"false" zurück (Default $2).
bool_or() {
    case "${1,,}" in
        true|1|yes|on)   printf 'true' ;;
        false|0|no|off)  printf 'false' ;;
        *)               printf '%s' "$2" ;;
    esac
}

# Gibt $1 zurück, wenn es in der Liste $2 (durch | getrennt) vorkommt, sonst $3.
enum_or() {
    case "|$2|" in
        *"|$1|"*) printf '%s' "$1" ;;
        *)        printf '%s' "$3" ;;
    esac
}

# Wählt das erste vorhandene Kommando aus der Liste.
first_cmd() {
    local c
    for c in "$@"; do
        if command -v "$c" >/dev/null 2>&1; then printf '%s' "$c"; return 0; fi
    done
    echo "[ERROR] Keines der Kommandos gefunden: $*" >&2
    return 1
}

# ---------------------------------------------------------------------------
# Konfiguration lesen und validieren
# ---------------------------------------------------------------------------
echo "[INFO] Lese Konfiguration..."

if [ ! -r "$CONFIG_PATH" ]; then
    echo "[ERROR] $CONFIG_PATH nicht gefunden oder nicht lesbar." >&2
    exit 1
fi

PORT=$(int_or "$(jq -r '.port // 8099' "$CONFIG_PATH")" 8099)
DIVERA_ACCESS_KEY=$(jq -r '.divera_access_key // ""' "$CONFIG_PATH")
DIVERA_BASE_URL=$(jq -r '.divera_base_url // "https://app.divera247.com/api/v2"' "$CONFIG_PATH")
STEIN_API_KEY=$(jq -r '.stein_api_key // ""' "$CONFIG_PATH")
STEIN_BUSINESS_UNIT_ID=$(jq -r '.stein_business_unit_id // ""' "$CONFIG_PATH")
STEIN_BASE_URL=$(jq -r '.stein_base_url // "https://www.stein.app/api"' "$CONFIG_PATH")
SYNC_INTERVAL=$(int_or "$(jq -r '.sync_interval_minutes // 5' "$CONFIG_PATH")" 5)
SYNC_DIRECTION=$(enum_or "$(jq -r '.sync_direction // "both"' "$CONFIG_PATH")" "both|divera_to_stein|stein_to_divera" "both")
AUTO_SYNC=$(bool_or "$(jq -r '.auto_sync_enabled // true' "$CONFIG_PATH")" true)
TIMEZONE=$(jq -r '.timezone // "Europe/Berlin"' "$CONFIG_PATH")
LOG_LEVEL=$(enum_or "$(jq -r '.log_level // "info"' "$CONFIG_PATH")" "debug|info|warning|error" "info")
MAX_LOG_ENTRIES=$(int_or "$(jq -r '.max_log_entries // 1000' "$CONFIG_PATH")" 1000)
RATE_LIMIT_REQUESTS=$(int_or "$(jq -r '.rate_limit_requests // 100' "$CONFIG_PATH")" 100)
RATE_LIMIT_WINDOW=$(int_or "$(jq -r '.rate_limit_window_seconds // 60' "$CONFIG_PATH")" 60)
DEBUG_ENDPOINTS=$(bool_or "$(jq -r '.debug_endpoints // false' "$CONFIG_PATH")" false)

[ "$SYNC_INTERVAL" -ge 1 ] || SYNC_INTERVAL=1
[ "$RATE_LIMIT_REQUESTS" -ge 1 ] || RATE_LIMIT_REQUESTS=1
[ "$RATE_LIMIT_WINDOW" -ge 1 ] || RATE_LIMIT_WINDOW=1

# Nur https-URLs zulassen - API-Keys dürfen nicht im Klartext übertragen werden
for var in DIVERA_BASE_URL STEIN_BASE_URL; do
    if [[ ! "${!var}" =~ ^https://[A-Za-z0-9.-]+(:[0-9]+)?(/[A-Za-z0-9._~/-]*)?$ ]]; then
        echo "[ERROR] $var muss eine https-URL ohne Query-String sein: '${!var}'" >&2
        exit 1
    fi
done

echo "[INFO] Port: $PORT"
echo "[INFO] Sync Interval: $SYNC_INTERVAL Minuten"
echo "[INFO] Sync Direction: $SYNC_DIRECTION"
echo "[INFO] Auto Sync: $AUTO_SYNC"
echo "[INFO] Timezone: $TIMEZONE"
echo "[INFO] Log Level: $LOG_LEVEL"
echo "[INFO] Debug-Endpunkte: $DEBUG_ENDPOINTS"
if [ "$DEBUG_ENDPOINTS" = "true" ]; then
    echo "[WARN] Debug-Endpunkte sind aktiv - sie geben Rohdaten beider APIs ohne Authentifizierung aus. Nach der Fehlersuche wieder deaktivieren!"
fi
[ -n "$DIVERA_ACCESS_KEY" ] || echo "[WARN] divera_access_key ist leer."
[ -n "$STEIN_API_KEY" ] || echo "[WARN] stein_api_key ist leer."

# ---------------------------------------------------------------------------
# Zeitzone (Pfad validieren - kein ../ o.ä.)
# ---------------------------------------------------------------------------
if [[ "$TIMEZONE" =~ ^[A-Za-z0-9_+-]+(/[A-Za-z0-9_+-]+)*$ ]] && [ -f "/usr/share/zoneinfo/$TIMEZONE" ]; then
    echo "[INFO] Setze Zeitzone auf $TIMEZONE..."
    ln -snf "/usr/share/zoneinfo/$TIMEZONE" /etc/localtime
    echo "$TIMEZONE" > /etc/timezone
else
    echo "[WARN] Ungültige Zeitzone '$TIMEZONE' - verwende Europe/Berlin."
    TIMEZONE="Europe/Berlin"
    ln -snf "/usr/share/zoneinfo/$TIMEZONE" /etc/localtime
    echo "$TIMEZONE" > /etc/timezone
fi

# ---------------------------------------------------------------------------
# Datenverzeichnisse
# ---------------------------------------------------------------------------
echo "[INFO] Erstelle Verzeichnisse..."
mkdir -p "$DATA_DIR" "$MYSQL_DATA" /var/log/steinapi /var/log/php /run/mysqld
chmod 700 "$DATA_DIR"
chown nginx:nginx /var/log/steinapi /var/log/php
chown mysql:mysql /run/mysqld

# ---------------------------------------------------------------------------
# Datenbank-Passwort (zufällig, einmalig erzeugt, nur root-lesbar)
# ---------------------------------------------------------------------------
DB_PASSWORD_CHANGED=0
if [ ! -s "$DB_PASS_FILE" ]; then
    echo "[INFO] Erzeuge neues Datenbank-Passwort..."
    # head zuerst, damit tr kein SIGPIPE bekommt (set -o pipefail)
    NEW_PASSWORD=$(head -c 1024 /dev/urandom | tr -dc 'A-Za-z0-9' | head -c 32)
    (umask 077; printf '%s' "$NEW_PASSWORD" > "$DB_PASS_FILE")
    DB_PASSWORD_CHANGED=1
fi
chmod 600 "$DB_PASS_FILE"
DB_PASSWORD=$(cat "$DB_PASS_FILE")
if [[ ! "$DB_PASSWORD" =~ ^[A-Za-z0-9]{32}$ ]]; then
    echo "[ERROR] $DB_PASS_FILE hat ein unerwartetes Format." >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# MariaDB: Initialisierung, Upgrade, Benutzer
# ---------------------------------------------------------------------------
MARIADB_SAFE=$(first_cmd mariadbd-safe mysqld_safe)
MARIADB_CLI=$(first_cmd mariadb mysql)
MARIADB_ADMIN=$(first_cmd mariadb-admin mysqladmin)
MARIADB_INSTALL_DB=$(first_cmd mariadb-install-db mysql_install_db)
MARIADB_UPGRADE=$(first_cmd mariadb-upgrade mysql_upgrade || true)
MARIADB_VERSION=$("$MARIADB_CLI" --version 2>/dev/null | head -n1 || echo "unknown")

FIRST_INSTALL=0
NEED_MAINTENANCE=0

if [ ! -d "$MYSQL_DATA/mysql" ]; then
    echo "[INFO] Initialisiere MariaDB Datenbank..."
    "$MARIADB_INSTALL_DB" --user=mysql --datadir="$MYSQL_DATA" --skip-test-db --auth-root-authentication-method=socket >/dev/null
    FIRST_INSTALL=1
    NEED_MAINTENANCE=1
fi

if [ "$DB_PASSWORD_CHANGED" = "1" ]; then
    NEED_MAINTENANCE=1
fi

if [ "$(cat "$DB_VERSION_FILE" 2>/dev/null || true)" != "$MARIADB_VERSION" ]; then
    if [ "$FIRST_INSTALL" != "1" ]; then
        echo "[INFO] MariaDB-Version hat sich geändert -> Upgrade der Systemtabellen wird ausgeführt."
    fi
    NEED_MAINTENANCE=1
fi

chown -R mysql:mysql "$MYSQL_DATA"

if [ "$NEED_MAINTENANCE" = "1" ]; then
    echo "[INFO] Starte MariaDB temporär (nur Unix-Socket)..."
    "$MARIADB_SAFE" --user=mysql --datadir="$MYSQL_DATA" --skip-networking --skip-name-resolve >/dev/null 2>&1 &
    MARIADB_TMP_PID=$!

    READY=0
    for _ in $(seq 1 60); do
        if "$MARIADB_ADMIN" --protocol=socket -u root ping >/dev/null 2>&1; then
            READY=1
            break
        fi
        sleep 1
    done
    if [ "$READY" != "1" ]; then
        echo "[ERROR] MariaDB ist nicht gestartet." >&2
        exit 1
    fi

    if [ "$FIRST_INSTALL" != "1" ] && [ -n "$MARIADB_UPGRADE" ]; then
        echo "[INFO] Führe $MARIADB_UPGRADE aus..."
        "$MARIADB_UPGRADE" --protocol=socket -u root --silent || echo "[WARN] $MARIADB_UPGRADE meldete Fehler - Details siehe oben."
    fi

    echo "[INFO] Richte Datenbank und Benutzer ein..."
    "$MARIADB_CLI" --protocol=socket -u root <<EOF
CREATE DATABASE IF NOT EXISTS steinapi CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'steinapi'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER 'steinapi'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'steinapi'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER ON steinapi.* TO 'steinapi'@'localhost';
FLUSH PRIVILEGES;
EOF

    if [ "$FIRST_INSTALL" = "1" ]; then
        echo "[INFO] Importiere Datenbankschema..."
        "$MARIADB_CLI" --protocol=socket -u root steinapi < "$WWW_DIR/install.sql"
    fi

    echo "$MARIADB_VERSION" > "$DB_VERSION_FILE"

    echo "[INFO] Stoppe temporäre MariaDB-Instanz..."
    "$MARIADB_ADMIN" --protocol=socket -u root shutdown
    wait "$MARIADB_TMP_PID" 2>/dev/null || true
fi

# ---------------------------------------------------------------------------
# nginx Rate-Limit aus Konfiguration erzeugen
# ---------------------------------------------------------------------------
RATE_PER_MIN=$(( RATE_LIMIT_REQUESTS * 60 / RATE_LIMIT_WINDOW ))
[ "$RATE_PER_MIN" -ge 1 ] || RATE_PER_MIN=1
cat > "$RATELIMIT_CONF" <<EOF
# Automatisch erzeugt von steinapi-init.sh (rate_limit_requests=$RATE_LIMIT_REQUESTS, rate_limit_window_seconds=$RATE_LIMIT_WINDOW)
limit_req_zone \$binary_remote_addr zone=steinapi_api:1m rate=${RATE_PER_MIN}r/m;
EOF
echo "[INFO] HTTP Rate Limit: $RATE_LIMIT_REQUESTS Anfragen / $RATE_LIMIT_WINDOW s (= ${RATE_PER_MIN}r/m)"

# ---------------------------------------------------------------------------
# PHP Konfigurationsdatei
# ---------------------------------------------------------------------------
echo "[INFO] Erstelle PHP Konfiguration..."
(umask 027; cat > "$WWW_DIR/config/config.php" <<PHPCONFIG
<?php
/**
 * SteinAPI Konfiguration
 * Automatisch generiert durch steinapi-init.sh - nicht manuell bearbeiten.
 */

return [
    // Datenbank Konfiguration (nur Unix-Socket, kein TCP; Passwort wird beim ersten Start erzeugt)
    'database' => [
        'socket' => '/run/mysqld/mysqld.sock',
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'steinapi',
        'user' => 'steinapi',
        'password' => '$(php_str "$DB_PASSWORD")',
        'charset' => 'utf8mb4'
    ],

    // Divera Konfiguration
    'divera' => [
        'access_key' => '$(php_str "$DIVERA_ACCESS_KEY")',
        'base_url' => '$(php_str "$DIVERA_BASE_URL")',
        'enabled' => true
    ],

    // Stein.app Konfiguration
    'stein' => [
        'api_key' => '$(php_str "$STEIN_API_KEY")',
        'business_unit_id' => '$(php_str "$STEIN_BUSINESS_UNIT_ID")',
        'base_url' => '$(php_str "$STEIN_BASE_URL")',
        'enabled' => true
    ],

    // Sync Einstellungen
    'sync' => [
        'interval_minutes' => $SYNC_INTERVAL,
        'direction' => '$SYNC_DIRECTION',
        'auto_enabled' => $AUTO_SYNC,
        // Stein.app Asset-Gruppen, die synchronisiert werden (Fahrzeuge)
        'stein_group_ids' => [1, 5]
    ],

    // Logging
    'logging' => [
        'level' => '$LOG_LEVEL',
        'max_entries' => $MAX_LOG_ENTRIES,
        'path' => '/var/log/steinapi'
    ],

    // Rate Limiting (wird von nginx durchgesetzt, siehe steinapi-ratelimit.conf)
    'rate_limit' => [
        'requests' => $RATE_LIMIT_REQUESTS,
        'window_seconds' => $RATE_LIMIT_WINDOW
    ],

    // Debug-Endpunkte (debug, compareData, rawDivera, rawStein, testSteinRaw)
    'debug_endpoints' => $DEBUG_ENDPOINTS,

    // Zeitzone
    'timezone' => '$(php_str "$TIMEZONE")',

    // Home Assistant Integration
    'homeassistant' => [
        'ingress' => true,
        'addon_slug' => 'steinapi'
    ]
];
PHPCONFIG
)

# Syntaxprüfung der erzeugten Konfiguration
php -l "$WWW_DIR/config/config.php" >/dev/null

# Berechtigungen: Webroot gehört root, nginx/php-fpm dürfen nur lesen
chown -R root:nginx "$WWW_DIR"
find "$WWW_DIR" -type d -exec chmod 750 {} \;
find "$WWW_DIR" -type f -exec chmod 640 {} \;

# Speichere Sync-Intervall für Cron
echo "$SYNC_INTERVAL" > /data/sync_interval
echo "$AUTO_SYNC" > /data/auto_sync

echo "[INFO] Initialisierung abgeschlossen!"
echo "============================================"
