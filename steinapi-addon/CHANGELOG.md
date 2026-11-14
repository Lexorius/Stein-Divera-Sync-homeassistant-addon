# Changelog

Alle wichtigen Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.0.0/),
und dieses Projekt hält sich an [Semantic Versioning](https://semver.org/lang/de/).

## [2.2.1] - 2026-11-15

### Hinzugefügt
- **Aktivitätsprotokoll zeigt Details**: Einträge mit Kontext lassen sich aufklappen. Bei
  „Sync decision" stehen dort beide Werte, der letzte gemeinsame Stand, die Zeitstempel und die
  gewählte Richtung. Bisher zeigte das Dashboard nur die Überschrift.
- Kurzfassung direkt in der Zeile (Fahrzeug und Aktion, Fehlerursache, Zählerstände).
- Auswahl der Anzahl (50, 200, 500 Einträge) und Schalter „Debug ausblenden".
- Aufgeklappte Einträge bleiben beim automatischen Neuladen offen.

### Behoben
- Einträge derselben Sekunde erscheinen in stabiler Reihenfolge (Sortierung zusätzlich nach ID).
- Stufe `debug` hatte im Protokoll keine eigene Farbe.

### Geändert
- Das Protokoll wird per DOM-API aufgebaut statt über `innerHTML`.

## [2.2.0] - 2026-11-15

**Überarbeitung der Synchronisationslogik.** Status und Notiz wurden in mehreren Situationen
nicht sauber übertragen oder gegenseitig überschrieben.

### Behoben
- **Feldweiser Abgleich statt „ganzes Fahrzeug, jüngerer Zeitstempel gewinnt"**: Status und Notiz
  werden getrennt gegen den zuletzt synchronisierten Stand verglichen (neue Tabelle `sync_state`).
  Eine Statusänderung in Divera überschreibt nicht mehr eine kurz zuvor in Stein.app geänderte
  Notiz - und umgekehrt. Der Zeitstempel entscheidet nur noch bei echten Konflikten.
- Kein Rück-Sync mehr durch Zeitstempel, die der Sync selbst verursacht hat. Bisher wurde jeder
  Wert, der sich nicht verlustfrei abbilden lässt, im nächsten Lauf in Gegenrichtung zurückgeschrieben.
- **Sprechwunsch setzte das Fahrzeug auf „nicht einsatzbereit"**: Ein Divera-Status ohne
  Stein-Entsprechung (0, 5, 7, 8, 9) wurde als `notready` nach Stein.app geschrieben und im
  nächsten Lauf als FMS 6 zurück nach Divera. Solche Status werden jetzt nicht mehr übertragen;
  die Notiz kommt trotzdem in Stein.app an.
- **FMS 4 wurde auf 3 zurückgesetzt**, weil beide in Stein.app `inuse` sind. Der Statusvergleich
  erfolgt jetzt in Stein-Begriffen, gleichwertige Status werden nicht überschrieben.
- Stein-Status `maint` kippte bei einer Notizänderung aus Divera auf `notready`.
- Eine in Stein.app gelöschte Notiz wird jetzt auch in Divera gelöscht. Bisher wurde die leere
  Notiz nicht gesendet und die alte kam im nächsten Lauf nach Stein.app zurück.
- Mehrzeilige Stein-Kommentare: Zeilenumbrüche (`\r\n`, `\r`, `\n`) werden für Divera einheitlich zu
  Leerzeichen. Der Kommentar gilt als gleich zu seiner einzeiligen Divera-Fassung und wird in
  Stein.app nicht mehr umgeschrieben.
- Notizvergleich ist jetzt exakt (`"007"` und `"7"` galten als gleich).
- **Sperre gegen parallele Läufe**: Cron, Dashboard und externe Aufrufe konnten gleichzeitig
  synchronisieren. Ein zweiter Lauf wird jetzt übersprungen (`busy: true` in der Antwort).
- Fehlermeldungen einzelner Fahrzeuge enthalten jetzt die Ursache (`Divera: …` / `Stein: …`).

### Hinzugefügt
- `tests/sync_simulation.php`: spielt 16 Sync-Fälle ohne echte APIs und ohne Datenbank durch
  (`php tests/sync_simulation.php`).
- Debug-Log-Eintrag „Sync decision" je Fahrzeug mit beiden Werten, letztem Stand und Zeitstempeln
  (nur bei `log_level: debug`).

### Hinweise zum Update
- Die Tabelle `sync_state` wird beim ersten Sync automatisch angelegt.
- Im ersten Lauf nach dem Update gibt es noch keinen gespeicherten Stand; dort entscheidet
  einmalig der Zeitstempel wie bisher.
- Steht ein Fahrzeug in Divera auf einem Status ohne Stein-Entsprechung, wird eine Notiz aus
  Stein.app erst übertragen, wenn der Status wieder zuordenbar ist. So wird z. B. ein Sprechwunsch
  nicht erneut ausgelöst.

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
