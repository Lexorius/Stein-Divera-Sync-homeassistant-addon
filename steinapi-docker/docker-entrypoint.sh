#!/usr/bin/env bash
###############################################################################
# docker-entrypoint.sh
#
# Erzeugt /data/options.json aus Environment-Variablen, damit das original
# Home-Assistant-Init-Script (steinapi-init.sh) unverändert weiterläuft.
# Danach: supervisord starten (nginx + php-fpm + mariadb + cron).
###############################################################################
set -euo pipefail

CONFIG_PATH=/data/options.json

echo "============================================"
echo "SteinAPI Standalone Container"
echo "Version: $(cat /etc/steinapi-version 2>/dev/null || echo unbekannt)"
echo "$(php -v 2>/dev/null | head -n1 || echo 'PHP: unbekannt')"
echo "============================================"
echo "[ENTRYPOINT] Erzeuge $CONFIG_PATH aus ENV-Variablen..."

# Defaults wie im Original-config.yaml
: "${PORT:=8099}"
: "${DIVERA_ACCESS_KEY:=}"
: "${DIVERA_BASE_URL:=https://app.divera247.com/api/v2}"
: "${STEIN_API_KEY:=}"
: "${STEIN_BUSINESS_UNIT_ID:=}"
: "${STEIN_BASE_URL:=https://www.stein.app/api}"
: "${SYNC_INTERVAL_MINUTES:=5}"
: "${SYNC_DIRECTION:=both}"
: "${AUTO_SYNC_ENABLED:=true}"
: "${TIMEZONE:=Europe/Berlin}"
: "${LOG_LEVEL:=info}"
: "${MAX_LOG_ENTRIES:=1000}"
: "${RATE_LIMIT_REQUESTS:=100}"
: "${RATE_LIMIT_WINDOW_SECONDS:=60}"
: "${DEBUG_ENDPOINTS:=false}"

# Hilfsfunktionen: ENV-Werte robust in JSON-Typen überführen
to_bool() {
    case "${1,,}" in
        true|1|yes|on)  printf 'true' ;;
        *)              printf 'false' ;;
    esac
}
to_int() {
    if [[ "$1" =~ ^[0-9]+$ ]]; then printf '%s' "$1"; else printf '%s' "$2"; fi
}

# Pflichtfelder prüfen (Warnung, aber kein Abbruch - evtl. über UI gesetzt)
if [ -z "$DIVERA_ACCESS_KEY" ]; then
    echo "[ENTRYPOINT] WARNUNG: DIVERA_ACCESS_KEY ist leer."
fi
if [ -z "$STEIN_API_KEY" ]; then
    echo "[ENTRYPOINT] WARNUNG: STEIN_API_KEY ist leer."
fi

# JSON via jq bauen (sauberer als String-Concat)
jq -n \
    --argjson port "$(to_int "$PORT" 8099)" \
    --arg divera_access_key "$DIVERA_ACCESS_KEY" \
    --arg divera_base_url "$DIVERA_BASE_URL" \
    --arg stein_api_key "$STEIN_API_KEY" \
    --arg stein_business_unit_id "$STEIN_BUSINESS_UNIT_ID" \
    --arg stein_base_url "$STEIN_BASE_URL" \
    --argjson sync_interval_minutes "$(to_int "$SYNC_INTERVAL_MINUTES" 5)" \
    --arg sync_direction "$SYNC_DIRECTION" \
    --argjson auto_sync_enabled "$(to_bool "$AUTO_SYNC_ENABLED")" \
    --arg timezone "$TIMEZONE" \
    --arg log_level "$LOG_LEVEL" \
    --argjson max_log_entries "$(to_int "$MAX_LOG_ENTRIES" 1000)" \
    --argjson rate_limit_requests "$(to_int "$RATE_LIMIT_REQUESTS" 100)" \
    --argjson rate_limit_window_seconds "$(to_int "$RATE_LIMIT_WINDOW_SECONDS" 60)" \
    --argjson debug_endpoints "$(to_bool "$DEBUG_ENDPOINTS")" \
    '{
        port: $port,
        divera_access_key: $divera_access_key,
        divera_base_url: $divera_base_url,
        stein_api_key: $stein_api_key,
        stein_business_unit_id: $stein_business_unit_id,
        stein_base_url: $stein_base_url,
        sync_interval_minutes: $sync_interval_minutes,
        sync_direction: $sync_direction,
        auto_sync_enabled: $auto_sync_enabled,
        timezone: $timezone,
        log_level: $log_level,
        max_log_entries: $max_log_entries,
        rate_limit_requests: $rate_limit_requests,
        rate_limit_window_seconds: $rate_limit_window_seconds,
        debug_endpoints: $debug_endpoints
    }' > "$CONFIG_PATH"
chmod 600 "$CONFIG_PATH"

echo "[ENTRYPOINT] options.json erstellt."

# API-Keys aus der Umgebung entfernen - sie stehen jetzt in options.json/config.php
# und sollen nicht an Kindprozesse (supervisord, php-fpm, nginx) vererbt werden.
unset DIVERA_ACCESS_KEY STEIN_API_KEY

# Sicherstellen dass /data-Unterverzeichnisse bei Named-Volume-Mount existieren
mkdir -p /data/steinapi /data/mysql

# Original-Init-Script vom HA-Addon aufrufen (baut DB auf, schreibt config.php)
/usr/local/bin/steinapi-init.sh

# Sauberer Shutdown bei SIGTERM von "docker stop"
_term() {
    echo "[ENTRYPOINT] SIGTERM empfangen - fahre supervisord herunter..."
    if [ -n "${SUPERVISOR_PID:-}" ]; then
        kill -TERM "$SUPERVISOR_PID" 2>/dev/null || true
        wait "$SUPERVISOR_PID" 2>/dev/null || true
    fi
    exit 0
}
trap _term TERM INT

echo "[ENTRYPOINT] Starte supervisord..."
/usr/bin/supervisord -c /etc/supervisord.conf &
SUPERVISOR_PID=$!
wait "$SUPERVISOR_PID"
