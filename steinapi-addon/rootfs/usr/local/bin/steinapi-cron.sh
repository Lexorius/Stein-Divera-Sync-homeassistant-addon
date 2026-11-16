#!/usr/bin/env bash
# Cron-ähnliches Skript für automatische Synchronisation.
# Die Ausgabe landet im Protokoll des Add-ons: eine Zeile je Lauf.

echo "[CRON] SteinAPI Cron Service gestartet"

while true; do
    # Lese Intervall aus Konfiguration
    INTERVAL=$(cat /data/sync_interval 2>/dev/null || echo "5")
    AUTO_SYNC=$(cat /data/auto_sync 2>/dev/null || echo "true")

    # Warte das konfigurierte Intervall
    sleep $((INTERVAL * 60))

    # Führe Sync nur aus wenn aktiviert
    if [ "$AUTO_SYNC" = "true" ]; then
        NOW=$(date '+%Y-%m-%d %H:%M:%S')

        # 127.0.0.1 statt localhost: nginx lauscht nur auf IPv4 und lässt genau
        # diese Adresse zu. --fail meldet HTTP-Fehler, --max-time liegt knapp
        # über dem Limit von 300 s für einen Lauf.
        if RESPONSE=$(curl -sS --fail --max-time 330 -X POST "http://127.0.0.1:8099/api.php?action=sync" \
            -H "Content-Type: application/json" \
            -d '{"source": "cron"}' 2>&1); then
            SUMMARY=$(printf '%s' "$RESPONSE" | jq -r '
                if .data.busy then "übersprungen, ein anderer Lauf ist aktiv"
                elif .data.success == false then "fehlgeschlagen: \(.data.error // "Ursache unbekannt")"
                else "\(.data.updated) aktualisiert, \(.data.skipped) unverändert, \(.data.errors) Fehler"
                end' 2>/dev/null)
            echo "[CRON] $NOW - Sync: ${SUMMARY:-$RESPONSE}"
        else
            echo "[CRON] $NOW - Sync-Aufruf fehlgeschlagen: $RESPONSE"
        fi
    fi
done
