# SteinAPI Home Assistant Add-on

![Version](https://img.shields.io/badge/version-2.2.8-blue)
![Supports aarch64](https://img.shields.io/badge/aarch64-yes-green)
![Supports amd64](https://img.shields.io/badge/amd64-yes-green)
![PHP](https://img.shields.io/badge/PHP-8.4.28-777bb4)
![Alpine](https://img.shields.io/badge/Alpine-3.24-0d597f)

API + Dashboard für die Synchronisation des **Fahrzeugstatus** zwischen **Divera24-7** und **Stein.app**.

> ⚠️ **Version 2.1.0 ist ein Sicherheitsrelease.** PHP 8.4.27 schließt die kritischen
> RCE-Lücken CVE-2026-109618 / CVE-2026-109619; außerdem wurden Schwachstellen im Add-on behoben.
> Siehe [CHANGELOG](./CHANGELOG.md).

## 📋 Funktionen

- ✅ Synchronisation des **FMS-Status** und der **Statusnotiz** zwischen Divera24-7 und Stein.app
- ✅ Richtung wählbar: beide Richtungen (feldweiser Abgleich, siehe unten), nur Divera → Stein, nur Stein → Divera
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

**Abgleich in beide Richtungen (seit 2.2.0):**

Status und Notiz werden getrennt verglichen, jeweils gegen den zuletzt synchronisierten Stand des
Fahrzeugs (Tabelle `sync_state`):

- Hat sich ein Feld nur in Stein.app geändert, geht es nach Divera.
- Hat es sich nur in Divera geändert, geht es nach Stein.app.
- Wurde es auf beiden Seiten geändert, gewinnt die jüngere Änderung.

Besonderheiten:

- Gleichwertige Status werden nicht überschrieben: FMS 4 bleibt in Divera 4, `maint` bleibt in
  Stein.app `maint`.
- Divera-Status **0, 5, 7, 8 und 9** haben in Stein.app keine Entsprechung. Der Status wird dann
  nicht übertragen. Die Notiz geht weiterhin von Divera nach Stein.app; in Gegenrichtung wartet
  sie, bis der Status wieder zuordenbar ist, damit z. B. ein Sprechwunsch nicht erneut gesendet wird.
- Divera-Notizen sind einzeilig. Ein mehrzeiliger Stein-Kommentar steht in Divera mit Leerzeichen
  statt Zeilenumbrüchen und bleibt in Stein.app unverändert.
- Eine gelöschte Notiz wird auf der anderen Seite ebenfalls gelöscht.
- Es läuft immer nur eine Synchronisation gleichzeitig; ein paralleler Aufruf wird übersprungen.
- Im ersten Lauf nach Installation oder Update gibt es noch keinen gespeicherten Stand. Dort
  entscheidet einmalig der Zeitstempel für das jeweilige Feld.

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
| `stein_base_url` | API URL (Standard: `https://stein.app/api/api/ext`, nur `https`) |

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
| `log_level` | Log-Detail (`debug`, `info`, `warning`, `error`). Auf `info` schreibt ein Lauf ohne Änderung zwei Zeilen, auf `debug` über hundert | `info` |
| `max_log_entries` | Max. Anzahl Log-Einträge in der Datenbank. 1000 reichen auf `info` für knapp zwei Tage | `1000` |
| `rate_limit_requests` | HTTP-Anfragen an `/api.php` pro Zeitfenster und Client-IP | `100` |
| `rate_limit_window_seconds` | Zeitfenster für das HTTP-Rate-Limit | `60` |
| `debug_endpoints` | Debug-Endpunkte freischalten (siehe unten) | `false` |
| `allow_direct_access` | Zugriff auf Port 8099 außerhalb von Ingress erlauben (siehe Sicherheit) | `false` |

### Beispielkonfiguration

```yaml
divera_access_key: "your-divera-access-key-here"
divera_base_url: "https://app.divera247.com/api/v2"
stein_api_key: "your-stein-api-key-here"
stein_business_unit_id: "your-business-unit-id"
stein_base_url: "https://stein.app/api/api/ext"
sync_interval_minutes: 5
sync_direction: "both"
auto_sync_enabled: true
timezone: "Europe/Berlin"
log_level: "info"
max_log_entries: 1000
rate_limit_requests: 100
rate_limit_window_seconds: 60
debug_endpoints: false
allow_direct_access: false
```

## 📊 Dashboard

Nach dem Start ist das Dashboard über die Home Assistant Seitenleiste erreichbar:

**Einstellungen → Add-ons → SteinAPI → Im Seitenbereich öffnen**

Das Dashboard zeigt letzten Sync-Zeitpunkt, Anzahl synchronisierter Fahrzeuge, Fehlerzähler
(24 h), nächste geplante Synchronisation, manuelle Sync-Steuerung und das Aktivitätsprotokoll.

**Aktivitätsprotokoll:** Einträge mit ▸ lassen sich aufklappen und zeigen die Details. Bei
`log_level: debug` steht zu jedem Fahrzeug ein Eintrag **Sync decision** mit beiden Werten, dem
letzten gemeinsamen Stand, den Zeitstempeln und der gewählten Richtung. Die Kurzfassung in der
Zeile nennt Fahrzeug und Aktion. Über „Einträge" lassen sich bis zu 500 Zeilen laden, „Debug
ausblenden" reduziert die Liste auf Meldungen ab Stufe `info`.

## 🔄 API-Endpunkte

| Endpunkt | Methode | Beschreibung |
|----------|---------|--------------|
| `/api.php?action=health` | GET | Health Check |
| `/api.php?action=stats` | GET | Statistiken |
| `/api.php?action=logs&limit=50` | GET | Logs (`limit` 1-1000) |
| `/api.php?action=sync` | POST | Sync starten, Body: `{"direction":"both"}` |
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
  geschützt. Der Webserver nimmt deshalb nur Anfragen von Home Assistant Ingress (`172.30.32.2`)
  und aus dem Container selbst an. Andere Add-ons auf demselben Gerät und ein freigegebener Port
  erhalten HTTP 403.
- **Direktzugriff:** Wer das Dashboard über den Port `8099` oder die API aus einer Automation
  nutzen will, gibt den Port frei **und** setzt `allow_direct_access: true`. Dann kann jeder, der
  den Port erreicht, Syncs auslösen und das Protokoll lesen - nur in einem vertrauenswürdigen
  Netzwerk verwenden.
- **Backup:** Home Assistant hält das Add-on während einer Sicherung kurz an (`backup: cold`),
  damit die Datenbankdateien in sich stimmig gesichert werden.
- **Transport:** Verbindungen zu Divera und Stein.app erfolgen ausschließlich per `https` mit
  Zertifikatsprüfung, ohne Redirects.
- **PHP:** 8.4.28 (Alpine 3.24), der Build bricht bei älteren Versionen ab. Multipart-Parsing,
  Datei-Uploads, `session.upload_progress`, `allow_url_fopen` und `expose_php` sind deaktiviert.
- **Webserver:** Nur `/api.php` wird als PHP ausgeführt; `/config/`, `/src/` und `install.sql` sind
  gesperrt. Das Webroot ist für den Webserver-Prozess nur lesbar. Rate-Limit per nginx.
- **Datenbank:** Nur Unix-Socket, zufälliges Passwort, minimale Rechte.
- **Debug-Endpunkte:** standardmäßig aus.
- API-Keys werden in der Home-Assistant-Konfiguration gespeichert und in der Oberfläche maskiert.
  Hinweis: Die Divera-API verlangt den Access Key als URL-Parameter (`accesskey=`); das ist durch
  die Divera-API vorgegeben.

## 🐛 Fehlerbehebung

### Wo stehen welche Meldungen?
- **Aktivitätsprotokoll im Dashboard:** was der Sync getan hat, also Läufe, Schreibzugriffe und
  Fehler beim Abgleich.
- **Protokoll des Add-ons** (**Einstellungen → Add-ons → SteinAPI → Protokoll**): Start des
  Add-ons, eine Zeile je automatischem Lauf sowie Fehler von Webserver und PHP, zum Beispiel
  abgelehnte Zugriffe. Hier nachsehen, wenn das Dashboard selbst nicht lädt oder „API-Fehler" meldet.

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

### Status oder Notiz wird nicht übernommen
1. `log_level: debug` setzen und im Aktivitätsprotokoll den Eintrag **Sync decision** des
   Fahrzeugs aufklappen. Er zeigt beide Werte, den letzten gemeinsamen Stand und die gewählte
   Richtung. Danach `log_level` wieder auf `info` stellen
2. Steht das Fahrzeug in Divera auf Status 0, 5, 7, 8 oder 9, wird der Status nicht übertragen
   und eine Notiz aus Stein.app zurückgestellt (siehe „So funktioniert die Synchronisation")
3. Fehlgeschlagene Schreibzugriffe stehen mit Ursache im Aktivitätslog und werden im nächsten
   Lauf wiederholt

### HTTP 429 im Dashboard
Das Rate-Limit greift. `rate_limit_requests` erhöhen oder weniger Clients gleichzeitig nutzen.

## 🤝 Support

- Issues im [GitHub Repository](https://github.com/Lexorius/Stein-Divera-Sync-homeassistant-addon/issues)
- Sicherheitsprobleme bitte nicht öffentlich melden, sondern direkt an den Maintainer

## 📄 Lizenz

Dieses Projekt steht unter der MIT-Lizenz.
