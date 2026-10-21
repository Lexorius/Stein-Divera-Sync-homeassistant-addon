# SteinAPI Home Assistant Add-on

![Version](https://img.shields.io/badge/version-2.1.0-blue)
![Supports aarch64](https://img.shields.io/badge/aarch64-yes-green)
![Supports amd64](https://img.shields.io/badge/amd64-yes-green)
![PHP](https://img.shields.io/badge/PHP-8.4.27-777bb4)
![Alpine](https://img.shields.io/badge/Alpine-3.24-0d597f)

API + Dashboard für die Synchronisation des **Fahrzeugstatus** zwischen **Divera24-7** und **Stein.app**.

> ⚠️ **Version 2.1.0 ist ein Sicherheitsrelease.** PHP 8.4.27 schließt die kritischen
> RCE-Lücken CVE-2026-109618 / CVE-2026-109619; außerdem wurden Schwachstellen im Add-on behoben.
> Siehe [CHANGELOG](./CHANGELOG.md).

## 📋 Funktionen

- ✅ Synchronisation des **FMS-Status** und der **Statusnotiz** zwischen Divera24-7 und Stein.app
- ✅ Richtung wählbar: beide Richtungen (neuerer Zeitstempel gewinnt), nur Divera → Stein, nur Stein → Divera
- ✅ Automatische Synchronisation in konfigurierbaren Intervallen (1-60 Minuten)
- ✅ Webbasiertes Dashboard mit Statistiken, manueller Sync-Steuerung und Aktivitätslog
- ✅ Vollständige Home Assistant Ingress-Integration
- ✅ Integrierte MariaDB (nur lokal über Unix-Socket erreichbar)
- ✅ Gehärtet: TLS-Zertifikatsprüfung, HTTP-Rate-Limit, abschaltbare Debug-Endpunkte

## 🔄 So funktioniert die Synchronisation

| | Divera24-7 | Stein.app |
|---|---|---|
| Datenquelle | `pull/vehicle-status` | `/assets/?buIds=<Business Unit>` |
| Zuordnungsschlüssel (Kennzeichen) | Feld `number` | Feld `name` |
| Status | `fmsstatus` (FMS 1-6) | `status` (`ready`, `semiready`, `inuse`, `notready`, `maint`) |
| Notiz | `fmsstatus_note` | `comment` |

Es werden nur Stein.app-Assets der **Gruppen 1 und 5** (Fahrzeuge) berücksichtigt. Ein Fahrzeug
wird synchronisiert, wenn das Kennzeichen in beiden Systemen **identisch** geschrieben ist.

**FMS-Mapping:**

| Divera FMS | Stein.app Status | | Stein.app Status | Divera FMS |
|---|---|---|---|---|
| 1 | `semiready` | | `ready` | 2 |
| 2 | `ready` | | `semiready` | 1 |
| 3, 4 | `inuse` | | `inuse` | 3 |
| 6 | `notready` | | `notready`, `maint` | 6 |

## 🚀 Installation

### Methode 1: Über Repository-URL (empfohlen)

1. Navigiere zu **Einstellungen → Add-ons → Add-on Store**
2. Klicke auf das 3-Punkte-Menü und wähle **Repositories**
3. Füge `https://github.com/Lexorius/Stein-Divera-Sync-homeassistant-addon` hinzu
4. Suche nach "SteinAPI" und installiere es

### Methode 2: Als lokales Add-on

1. Kopiere den Ordner `steinapi-addon` nach `/addons/` deiner Home Assistant Installation
2. Navigiere zu **Einstellungen → Add-ons → Add-on Store**
3. 3-Punkte-Menü → **Repositories aktualisieren**
4. Das Add-on erscheint unter **Lokale Add-ons** → **Installieren**

**Unterstützte Architekturen:** `amd64`, `aarch64`. (`armhf`, `armv7`, `i386` werden von Home
Assistant nicht mehr mit Basis-Images versorgt und seit 2.1.0 nicht mehr unterstützt.)

## ⚙️ Konfiguration

### Divera24-7

| Option | Beschreibung |
|--------|--------------|
| `divera_access_key` | API Access Key aus Divera (Verwaltung → Schnittstellen → API-Zugriff) |
| `divera_base_url` | API URL (Standard: `https://app.divera247.com/api/v2`, nur `https`) |

### Stein.app

| Option | Beschreibung |
|--------|--------------|
| `stein_api_key` | API Key aus Stein.app |
| `stein_business_unit_id` | Business Unit ID aus Stein.app |
| `stein_base_url` | API URL (Standard: `https://www.stein.app/api`, nur `https`) |

### Sync-Einstellungen

| Option | Beschreibung | Standard |
|--------|--------------|----------|
| `sync_interval_minutes` | Intervall für Auto-Sync (1-60 Min) | `5` |
| `sync_direction` | `both`, `divera_to_stein`, `stein_to_divera` | `both` |
| `auto_sync_enabled` | Automatische Synchronisation | `true` |

### Erweiterte Einstellungen

| Option | Beschreibung | Standard |
|--------|--------------|----------|
| `timezone` | Zeitzone des Containers (z.B. `Europe/Berlin`) | `Europe/Berlin` |
| `log_level` | Log-Detail (`debug`, `info`, `warning`, `error`) | `info` |
| `max_log_entries` | Max. Anzahl Log-Einträge in der Datenbank | `1000` |
| `rate_limit_requests` | HTTP-Anfragen an `/api.php` pro Zeitfenster und Client-IP | `100` |
| `rate_limit_window_seconds` | Zeitfenster für das HTTP-Rate-Limit | `60` |
| `debug_endpoints` | Debug-Endpunkte freischalten (siehe unten) | `false` |

### Beispielkonfiguration

```yaml
port: 8099
divera_access_key: "your-divera-access-key-here"
divera_base_url: "https://app.divera247.com/api/v2"
stein_api_key: "your-stein-api-key-here"
stein_business_unit_id: "your-business-unit-id"
stein_base_url: "https://www.stein.app/api"
sync_interval_minutes: 5
sync_direction: "both"
auto_sync_enabled: true
timezone: "Europe/Berlin"
log_level: "info"
max_log_entries: 1000
rate_limit_requests: 100
rate_limit_window_seconds: 60
debug_endpoints: false
```

## 📊 Dashboard

Nach dem Start ist das Dashboard über die Home Assistant Seitenleiste erreichbar:

**Einstellungen → Add-ons → SteinAPI → Im Seitenbereich öffnen**

Das Dashboard zeigt letzten Sync-Zeitpunkt, Anzahl synchronisierter Fahrzeuge, Fehlerzähler
(24 h), nächste geplante Synchronisation, manuelle Sync-Steuerung und das Aktivitätsprotokoll.

## 🔄 API-Endpunkte

| Endpunkt | Methode | Beschreibung |
|----------|---------|--------------|
| `/api.php?action=health` | GET | Health Check |
| `/api.php?action=stats` | GET | Statistiken |
| `/api.php?action=logs&limit=50` | GET | Logs (`limit` 1-1000) |
| `/api.php?action=sync` | POST | Sync starten, Body: `{"direction":"both"}` |
| `/api.php?action=fieldConfig` | GET | Feld-Konfiguration |
| `/api.php?action=updateField` | POST | Feld aktualisieren, Body: `{"field":"status","enabled":true}` |
| `/api.php?action=testConnection` | GET | Verbindung zu beiden APIs testen |
| `/api.php?action=config` | GET | Aktive Konfiguration (ohne Secrets) |

POST-Bodies müssen JSON sein (`Content-Type: application/json`). Multipart-/Formular-Daten
werden nicht verarbeitet.

### Debug-Endpunkte

Nur verfügbar, wenn `debug_endpoints: true` gesetzt ist (sonst HTTP 403). Sie geben **Rohdaten
beider APIs** aus - nur vorübergehend zur Fehlersuche aktivieren und danach wieder abschalten.

| Endpunkt | Beschreibung |
|----------|--------------|
| `/api.php?action=debug` | Konfiguration (ohne Secrets) und Verbindungstests |
| `/api.php?action=compareData` | Fahrzeuge beider Systeme und gefundene Kennzeichen-Matches |
| `/api.php?action=rawDivera` | Rohdaten `pull/vehicle-status` |
| `/api.php?action=rawStein` | Rohdaten `/assets/` |
| `/api.php?action=testSteinRaw&endpoint=/pfad` | Beliebigen Pfad unterhalb der Stein.app-Base-URL abfragen |

**Tipp:** `log_level: "debug"` liefert detaillierte API-Logs im Aktivitätsprotokoll.

## 🗃️ Datenbank

Das Add-on verwendet eine integrierte MariaDB (11.8). Die Daten liegen persistent unter
`/data/mysql`. Die Datenbank ist **nur über den Unix-Socket** im Container erreichbar (kein
TCP-Port). Das Datenbank-Passwort wird beim ersten Start zufällig erzeugt
(`/data/steinapi/db_password`). Beim Update von einer älteren Version werden Passwort und
Systemtabellen automatisch migriert.

## 🔒 Sicherheit

- **Zugriff:** Die API selbst hat keine Authentifizierung. Über Ingress ist sie durch Home Assistant
  geschützt. Der Port `8099` ist standardmäßig **nicht** freigegeben - so belassen, sofern das
  Netzwerk nicht vertrauenswürdig ist.
- **Transport:** Verbindungen zu Divera und Stein.app erfolgen ausschließlich per `https` mit
  Zertifikatsprüfung, ohne Redirects.
- **PHP:** 8.4.27 (Alpine 3.24), der Build bricht bei älteren Versionen ab. Multipart-Parsing,
  Datei-Uploads, `session.upload_progress`, `allow_url_fopen` und `expose_php` sind deaktiviert.
- **Webserver:** Nur `/api.php` wird als PHP ausgeführt; `/config/`, `/src/` und `install.sql` sind
  gesperrt. Das Webroot ist für den Webserver-Prozess nur lesbar. Rate-Limit per nginx.
- **Datenbank:** Nur Unix-Socket, zufälliges Passwort, minimale Rechte.
- **Debug-Endpunkte:** standardmäßig aus.
- API-Keys werden in der Home-Assistant-Konfiguration gespeichert und in der Oberfläche maskiert.
  Hinweis: Die Divera-API verlangt den Access Key als URL-Parameter (`accesskey=`); das ist durch
  die Divera-API vorgegeben.

## 🐛 Fehlerbehebung

### Add-on startet nicht
1. Add-on-Logs prüfen: **Einstellungen → Add-ons → SteinAPI → Protokoll**
2. Pflichtfelder (`divera_access_key`, `stein_api_key`, `stein_business_unit_id`) ausgefüllt?
3. Base-URLs müssen mit `https://` beginnen.

### Keine Verbindung zu Divera/Stein
1. API-Keys auf Gültigkeit prüfen (Divera: Token muss 64 Zeichen lang sein)
2. `/api.php?action=testConnection` aufrufen bzw. Logs im Dashboard prüfen
3. Firewall/Proxy: ausgehende `https`-Verbindungen zu `app.divera247.com` und `stein.app` nötig

### Fahrzeuge werden nicht synchronisiert
1. Kennzeichen in Divera (`number`) und Stein.app (`name`) müssen identisch sein
2. Stein.app-Asset muss in Gruppe 1 oder 5 liegen
3. Vorübergehend `debug_endpoints: true` setzen und `/api.php?action=compareData` aufrufen

### HTTP 429 im Dashboard
Das Rate-Limit greift. `rate_limit_requests` erhöhen oder weniger Clients gleichzeitig nutzen.

## 🤝 Support

- Issues im [GitHub Repository](https://github.com/Lexorius/Stein-Divera-Sync-homeassistant-addon/issues)
- Sicherheitsprobleme bitte nicht öffentlich melden, sondern direkt an den Maintainer

## 📄 Lizenz

Dieses Projekt steht unter der MIT-Lizenz.
