# SteinAPI Home Assistant Add-on

![Version](https://img.shields.io/badge/version-1.0.0-blue)
![Supports aarch64](https://img.shields.io/badge/aarch64-yes-green)
![Supports amd64](https://img.shields.io/badge/amd64-yes-green)
![Supports armhf](https://img.shields.io/badge/armhf-yes-green)
![Supports armv7](https://img.shields.io/badge/armv7-yes-green)
![Supports i386](https://img.shields.io/badge/i386-yes-green)

API + Dashboard für die Synchronisation zwischen **Divera24-7** und **Stein.app**

## 📋 Funktionen

- ✅ Bidirektionale Synchronisation zwischen Divera24-7 und Stein.app
- ✅ Automatische Synchronisation in konfigurierbaren Intervallen
- ✅ Webbasiertes Dashboard mit Echtzeit-Status
- ✅ Konfigurierbare Feld-Synchronisation
- ✅ Detailliertes Logging und Statistiken
- ✅ Vollständige Home Assistant Ingress-Integration

## 🚀 Installation

### Methode 1: Als lokales Add-on

1. Kopiere den gesamten `steinapi-addon` Ordner in das Verzeichnis `/addons/` deiner Home Assistant Installation
2. Navigiere zu **Einstellungen → Add-ons → Add-on Store**
3. Klicke auf das 3-Punkte-Menü (oben rechts) und wähle **Repositories aktualisieren**
4. Das Add-on sollte nun unter **Lokale Add-ons** erscheinen
5. Klicke auf **SteinAPI** und dann auf **Installieren**

### Methode 2: Über Repository-URL

1. Navigiere zu **Einstellungen → Add-ons → Add-on Store**
2. Klicke auf das 3-Punkte-Menü und wähle **Repositories**
3. Füge die Repository-URL hinzu (falls verfügbar)
4. Suche nach "SteinAPI" und installiere es

## ⚙️ Konfiguration

Nach der Installation muss das Add-on konfiguriert werden:

### Divera24-7 Einstellungen

| Option | Beschreibung |
|--------|--------------|
| `divera_access_key` | API Access Key aus Divera (Verwaltung → Schnittstellen → API-Zugriff) |
| `divera_base_url` | API URL (Standard: `https://app.divera247.com/api/v2`) |

### Stein.app Einstellungen

| Option | Beschreibung |
|--------|--------------|
| `stein_api_key` | API Key aus Stein.app |
| `stein_business_unit_id` | Business Unit ID aus Stein.app |
| `stein_base_url` | API URL (Standard: `https://api.stein.app/v1`) |

### Sync-Einstellungen

| Option | Beschreibung | Standard |
|--------|--------------|----------|
| `sync_interval_minutes` | Intervall für Auto-Sync (1-60 Min) | `5` |
| `sync_direction` | `both`, `divera_to_stein`, `stein_to_divera` | `both` |
| `auto_sync_enabled` | Automatische Synchronisation | `true` |

### Erweiterte Einstellungen

| Option | Beschreibung | Standard |
|--------|--------------|----------|
| `log_level` | Log-Detail (`debug`, `info`, `warning`, `error`) | `info` |
| `max_log_entries` | Max. Anzahl Log-Einträge | `1000` |
| `rate_limit_requests` | API-Anfragen pro Zeitfenster | `100` |
| `rate_limit_window_seconds` | Zeitfenster für Rate Limit | `60` |

### Beispielkonfiguration

```yaml
port: 8099
divera_access_key: "your-divera-access-key-here"
divera_base_url: "https://app.divera247.com/api/v2"
stein_api_key: "your-stein-api-key-here"
stein_business_unit_id: "your-business-unit-id"
stein_base_url: "https://api.stein.app/v1"
sync_interval_minutes: 5
sync_direction: "both"
auto_sync_enabled: true
log_level: "info"
max_log_entries: 1000
rate_limit_requests: 100
rate_limit_window_seconds: 60
```

## 📊 Dashboard

Nach dem Start des Add-ons ist das Dashboard über die Home Assistant Seitenleiste erreichbar:

**Einstellungen → Add-ons → SteinAPI → Im Seitenbereich öffnen**

Das Dashboard zeigt:
- Letzten Sync-Zeitpunkt
- Anzahl synchronisierter Einträge
- Fehlerzähler
- Nächste geplante Synchronisation
- Manuelle Sync-Steuerung
- Feld-Konfiguration
- Aktivitätsprotokoll

## 🔄 API-Endpunkte

Das Add-on stellt folgende API-Endpunkte bereit:

| Endpunkt | Methode | Beschreibung |
|----------|---------|--------------|
| `/api.php?action=stats` | GET | Statistiken abrufen |
| `/api.php?action=logs&limit=50` | GET | Logs abrufen |
| `/api.php?action=sync` | POST | Sync starten |
| `/api.php?action=fieldConfig` | GET | Feld-Konfiguration |
| `/api.php?action=updateField` | POST | Feld aktualisieren |
| `/api.php?action=testConnection` | GET | Verbindung testen |
| `/api.php?action=health` | GET | Health Check |

## 🗃️ Datenbank

Das Add-on verwendet eine integrierte MariaDB-Datenbank. Die Daten werden persistent unter `/data/mysql` gespeichert.

## 🔒 Sicherheit

- Alle API-Schlüssel werden sicher in der Home Assistant Konfiguration gespeichert
- Die Datenbank ist nur lokal erreichbar
- Konfigurationsdateien sind durch Webserver-Regeln geschützt
- Rate Limiting schützt vor API-Überlastung

## 🐛 Fehlerbehebung

### Add-on startet nicht
1. Überprüfe die Add-on Logs unter **Einstellungen → Add-ons → SteinAPI → Protokoll**
2. Stelle sicher, dass alle Pflichtfelder ausgefüllt sind

### Keine Verbindung zu Divera/Stein
1. Überprüfe die API-Keys auf Gültigkeit
2. Teste die Verbindung über das Dashboard
3. Prüfe die Firewall-Einstellungen

### Sync funktioniert nicht
1. Prüfe die Logs im Dashboard
2. Stelle sicher, dass die API-Zugänge korrekt sind
3. Überprüfe die Feld-Konfiguration

## 📝 Changelog

### Version 1.0.0
- Initiale Version
- Bidirektionale Synchronisation
- Home Assistant Ingress-Integration
- Konfigurierbare Felder
- Auto-Sync mit konfigurierbarem Intervall

## 🤝 Support

Bei Problemen oder Fragen:
- Erstelle ein Issue im [GitHub Repository](https://github.com/Lexorius/steinapi)
- Prüfe die Add-on Logs auf Fehlermeldungen

## 📄 Lizenz

Dieses Projekt steht unter der MIT-Lizenz.
