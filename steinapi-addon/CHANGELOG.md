# Changelog

Alle wichtigen Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.0.0/),
und dieses Projekt hält sich an [Semantic Versioning](https://semver.org/lang/de/).

## [2.1.0] - 2026-10-21

**Sicherheitsrelease - Update dringend empfohlen.**

### Sicherheit
- **PHP auf 8.4.27 aktualisiert** (Alpine 3.24). Behebt die kritischen Remote-Code-Execution-Lücken
  **CVE-2026-109618** (OOM-Korruption beim Multipart-Parsing) und **CVE-2026-109619**
  (Use-after-free über veralteten globalen Zustand), siehe
  [php.net Security Advisory vom 21.10.2026](https://www.php.net/archive/2026.php#2026-10-21-5).
  Die bisherige Basis Alpine 3.19 ist seit November 2025 ohne Sicherheitsupdates (PHP 8.3.14).
- Der Docker-Build bricht ab, wenn das Basis-Image eine PHP-Version unter 8.4.27 liefert (`PHP_MIN_VERSION`).
- PHP-Härtung (`99-steinapi.ini`): Multipart-Parsing und Datei-Uploads deaktiviert
  (`enable_post_data_reading = Off`, `file_uploads = Off`), `session.upload_progress` deaktiviert,
  `expose_php`/`display_errors` aus, `allow_url_fopen`/`allow_url_include` aus, `post_max_size = 1M`.
- php-fpm: `clear_env = yes` - Umgebungsvariablen (im Standalone-Container inkl. API-Keys) werden
  nicht mehr an PHP durchgereicht. Worker werden nach 500 Requests recycelt.
- **TLS-Zertifikatsprüfung aktiviert** für Divera- und Stein.app-Anfragen (`CURLOPT_SSL_VERIFYPEER`
  war deaktiviert - ein Angreifer im Netzwerk konnte API-Keys abgreifen). Nur noch `https`, keine Redirects.
- **CORS-Wildcard entfernt** (`Access-Control-Allow-Origin: *`): Fremde Webseiten konnten die API
  über den Browser des Nutzers abfragen.
- **Debug-Endpunkte** (`debug`, `compareData`, `rawDivera`, `rawStein`, `testSteinRaw`) sind
  standardmäßig **deaktiviert** (neue Option `debug_endpoints`). Sie gaben Rohdaten beider APIs ohne
  Authentifizierung aus. `testSteinRaw` akzeptiert nur noch relative Pfade.
- **MariaDB ausschließlich über Unix-Socket** (`--skip-networking` explizit gesetzt, PDO verbindet
  über `/run/mysqld/mysqld.sock`). Es gibt keinen TCP-Port, den andere Container im
  Home-Assistant-Add-on-Netz erreichen könnten - unabhängig von Distributions-Defaults.
- **Zufälliges Datenbank-Passwort** statt fest kodiertem `steinapi_password`; wird beim ersten Start
  erzeugt (`/data/steinapi/db_password`, 0600) und bei bestehenden Installationen automatisch migriert.
  Der DB-Benutzer hat nur noch die benötigten Rechte auf `steinapi.*`.
- SQL-Identifier (Tabellen-/Spaltennamen) in der Datenbankschicht werden geprüft und gequotet.
- nginx: Nur `/api.php` wird als PHP ausgeführt; `/config/`, `/src/`, `install.sql` und versteckte
  Dateien liefern 404. `server_tokens off`, `X-Content-Type-Options`, `Referrer-Policy`,
  `client_max_body_size 1m`.
- **HTTP-Rate-Limit** für `/api.php` wird jetzt tatsächlich durchgesetzt (nginx `limit_req`, aus den
  Optionen `rate_limit_requests`/`rate_limit_window_seconds`; bisher ohne Funktion).
- Webroot gehört `root` und ist für nginx/php-fpm nur lesbar (kein Schreibzugriff des Webservers).
- API-Eingaben werden validiert (`action`, `direction`, `source`, `field`, `enabled`, `limit` 1-1000).
  Unerwartete PHP-Fehler liefern eine generische Meldung statt interner Details.
- Werte aus der Add-on-Konfiguration werden beim Erzeugen von `config.php` escaped und validiert
  (Zeitzone, URLs nur `https`, Zahlen, Enums).
- Dashboard: Feld-Konfiguration und Log-Level werden ohne `innerHTML`-Injektion gerendert.
- Standalone Docker: Port standardmäßig nur auf `127.0.0.1` gebunden; API-Keys werden nach dem
  Erzeugen von `options.json` aus der Umgebung entfernt.
- `divera_access_key` und `stein_api_key` werden in der HA-Oberfläche als Passwort maskiert.

### Behoben
- `.gitattributes` erzwingt LF-Zeilenenden: Ein Windows-Checkout mit `core.autocrlf` erzeugte
  CRLF in den Shell-Skripten, wodurch der Cron-Dienst im Standalone-Container nicht startete
  (`env: can't execute 'bash'`).
- nginx-Warnung „duplicate MIME type text/html" beim Start.

### Geändert
- Basis-Image: `ghcr.io/home-assistant/*-base:3.24` bzw. `alpine:3.24` (vorher 3.19, EOL).
- MariaDB 11.8 (vorher 10.11). Beim ersten Start nach dem Update wird `mariadb-upgrade` automatisch ausgeführt.
- **Architekturen:** nur noch `amd64` und `aarch64`. Home Assistant stellt für `armhf`, `armv7` und
  `i386` keine Basis-Images mehr bereit.
- Konfigurationsdatei liest Zeitzone und `stein_group_ids` aus der generierten `config.php`.
- Sync-Toast im Dashboard zeigt Anzahl aktualisierter/unveränderter Fahrzeuge.

### Entfernt
- Nicht genutzte `.htaccess` (Apache-Regeln; der Container nutzt nginx).
- Nicht genutzte Mappings `addon_config` und `ssl` aus `config.yaml`.

## [2.0.0] - [2.0.2]

- Umstellung auf **Fahrzeugstatus-Synchronisation** (FMS-Status + Notiz, Zuordnung über das Kennzeichen).
- Standalone-Docker-Variante (`steinapi-docker/`), die dieselbe `rootfs/` nutzt.
- Keine detaillierten Einträge vorhanden.

## [1.0.0] - 2024-01-01

### Hinzugefügt
- Initiale Veröffentlichung
- Bidirektionale Synchronisation zwischen Divera24-7 und Stein.app
- Home Assistant Add-on mit Ingress-Support
- Webbasiertes Dashboard
  - Echtzeit-Statistiken
  - Manuelle Sync-Steuerung
  - Feld-Konfiguration
  - Aktivitätsprotokoll
- Automatische Synchronisation mit konfigurierbarem Intervall
- Konfigurierbare Feld-Synchronisation
- Integrierte MariaDB-Datenbank
- Rate Limiting zum Schutz der APIs
- Multi-Architektur-Support (amd64, aarch64, armhf, armv7, i386)
- Deutsche und englische Übersetzungen

### Sicherheit
- Geschützte Konfigurationsdateien
- Sichere API-Schlüssel-Speicherung
- Nur lokaler Datenbankzugriff
