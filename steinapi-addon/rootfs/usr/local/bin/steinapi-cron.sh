#!/usr/bin/env bash
# Cron-ähnliches Skript für automatische Synchronisation

echo "[CRON] SteinAPI Cron Service gestartet"

while true; do
    # Lese Intervall aus Konfiguration
    INTERVAL=$(cat /data/sync_interval 2>/dev/null || echo "5")
    AUTO_SYNC=$(cat /data/auto_sync 2>/dev/null || echo "true")
    
    # Berechne Sekunden
    SLEEP_SECONDS=$((INTERVAL * 60))
    
    # Warte das konfigurierte Intervall
    sleep $SLEEP_SECONDS
    
    # Führe Sync nur aus wenn aktiviert
    if [ "$AUTO_SYNC" = "true" ]; then
        echo "[CRON] $(date '+%Y-%m-%d %H:%M:%S') - Starte automatische Synchronisation..."
        
        # Rufe den Sync-Endpunkt auf
        RESPONSE=$(curl -s -X POST "http://localhost:8099/api.php?action=sync" \
            -H "Content-Type: application/json" \
            -d '{"source": "cron"}')
        
        if [ $? -eq 0 ]; then
            echo "[CRON] Sync abgeschlossen: $RESPONSE"
        else
            echo "[CRON] Sync fehlgeschlagen!"
        fi
    fi
done
