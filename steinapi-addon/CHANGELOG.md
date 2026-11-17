# Changelog

Alle wichtigen Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.0.0/),
und dieses Projekt hält sich an [Semantic Versioning](https://semver.org/lang/de/).

## [2.2.6] - 2026-11-17

### Behoben
- **Voreingestellte Adresse der Stein-API**: Die Voreinstellung von `stein_base_url` lautete
  `https://www.stein.app/api`. Die funktionierende Adresse ist `https://stein.app/api/api/ext`,
  mit ihr arbeitet auch das Vorgängerprojekt steinapi. Die Voreinstellung ist in der
  Add-on-Konfiguration, im Standalone-Container, in der Dokumentation und in den Übersetzungen
  korrigiert.

### Hinweis zum Update
- Eine bereits gespeicherte Adresse wird **nicht** geändert. Wer in der Add-on-Konfiguration noch
  `https://www.stein.app/api` stehen hat und keine Verbindung zu Stein.app bekommt, trägt dort
  `https://stein.app/api/api/ext` ein. Im Standalone-Container gilt dasselbe für `STEIN_BASE_URL`
  in der `.env`.

### Geändert
- Die automatischen Tests prüfen, dass an allen Stellen dieselbe Stein-Adresse steht.

## [2.2.5] - 2026-11-17

Drei Schönheitsfehler im Protokoll des Add-ons. Am Verhalten ändert sich nichts.

### Behoben
- **Version im Startprotokoll**: Dort stand wörtlich `Version: ${BUILD_VERSION}`. `build.yaml`
  übergab den Platzhalter als festen Text und überschrieb damit den Wert des Supervisors. Das
  betraf auch das Versions-Label des Images.
- **CRIT-Zeile von supervisord** bei jedem Start. Sie war harmlos, sah aber nach einem Problem aus.
- **Warnung des Webservers bei großen Antworten** („an upstream response is buffered to a
  temporary file"), etwa beim Laden von 500 Protokolleinträgen.

### Geändert
- Die automatischen Tests prüfen Versionsdatei und Label des Images, das Fehlen der CRIT-Zeile
  und dass `build.yaml` keine Platzhalter enthält.

## [2.2.4] - 2026-11-17

Protokoll und Dashboard-Anzeigen korrigiert. Am Verhalten der Synchronisation ändert sich nichts.

### Behoben
- **Fehler verschwanden nach rund zehn Stunden aus dem Protokoll**: Jeder Lauf schrieb acht
  Routinezeilen auf Stufe `info`, rund 2300 am Tag bei 1000 gespeicherten Einträgen. Sechs davon
  stehen jetzt auf `debug`. Auf `info` bleiben Start und Ergebnis eines Laufs sowie alle
  Schreibzugriffe und Fehler. 1000 Einträge reichen damit für knapp zwei Tage.
- **Kachel „Fehler" zählte Logzeilen statt Fehler**: Ein fehlgeschlagener Lauf erschien als 2,
  und Fehler, die älter als das Protokoll waren, fehlten ganz. Die Zahl kommt jetzt aus dem
  Verlauf: jedes fehlgeschlagene Fahrzeug und jeder abgebrochene Lauf zählt einmal.
- **„Nächste Auto-Sync" stimmte nach einem manuellen Lauf nicht**: Die Anzeige rechnete vom
  letzten Lauf jeder Art. Der automatische Takt läuft unabhängig davon, deshalb zählt jetzt nur
  der letzte automatische Lauf.
- **Meldungen von Webserver, PHP und Cron stehen im Protokoll des Add-ons**: Bisher landeten sie
  in Dateien im Container, die in Home Assistant niemand sieht und die beim Neustart verloren
  gehen. Der automatische Lauf schreibt dort eine kurze Zeile je Lauf.
- Der automatische Lauf erkennt HTTP-Fehler des eigenen Aufrufs und bricht nach 330 Sekunden ab.

### Geändert
- **Ein Dockerfile für Add-on und Standalone-Container** (Stufen `addon` und `standalone`). Bisher
  gab es zwei Dateien mit gleichem Inhalt, getestet wurde nur eine. `docker-compose.yml` baut
  jetzt `../steinapi-addon` mit `target: standalone`. Wer von Hand baut:
  `docker build --target standalone -t steinapi steinapi-addon`.
- **Automatische Tests** bauen zusätzlich das Add-on-Image auf dem Basis-Image von Home Assistant
  und starten es. Die Sync-Simulation läuft ein zweites Mal gegen die echte MariaDB und prüft die
  Abfragen für Sync-Stand, Sperre und Verlauf.
- Kein Zugriffsprotokoll des Webservers mehr. Abgelehnte Zugriffe stehen weiterhin im Protokoll
  des Add-ons.

### Entfernt
- **Option `port`**: Sie hatte keine Wirkung, der Webserver lauscht fest auf 8099. Ein noch
  gespeicherter Wert wird ignoriert. Im Standalone-Container entfällt entsprechend `PORT`.
- Ungenutzter Code: drei Methoden der Datenbank- und Stein-Schicht, eine Hilfsfunktion im
  Dashboard, drei nie gelesene Blöcke der erzeugten Konfiguration und die Felder
  `divera_enabled` und `stein_enabled` der API-Aktion `config`.

## [2.2.3] - 2026-11-17

Aufräumen nach dem Code-Audit. Am Verhalten der Synchronisation ändert sich nichts.

### Behoben
- **Verlaufstabelle wuchs ohne Grenze**: Jeder Lauf schrieb eine Zeile, gelöscht wurde nie. Zeilen,
  die älter als 90 Tage sind, werden jetzt nach jedem Lauf entfernt. Die Tagesabfrage des
  Dashboards nutzt einen Datumsbereich und damit den Index.
- **Zeitzone**: Der Sync-Dienst setzte bei jeder Anfrage fest `Europe/Berlin` und überschrieb damit
  die Option `timezone`. Für Installationen mit `Europe/Berlin` ändert sich nichts.
- **Protokoll**: Alte Einträge werden nur noch einmal je Anfrage gelöscht statt nach jeder
  geschriebenen Zeile.

### Hinzugefügt
- **Automatische Tests** (GitHub Actions) bei jedem Push: Syntax von PHP und Shell, die
  Sync-Simulation, Bau und Start des Images, nginx- und php-fpm-Konfiguration, die Endpunkte,
  ein Sync-Lauf gegen die echte MariaDB und die Zugriffsbeschränkung aus 2.2.2.

### Entfernt
- Neun ungenutzte PHP-Erweiterungen aus beiden Images (`pdo_sqlite`, `sqlite3`, `mbstring`,
  `session`, `ctype`, `dom`, `xml`, `simplexml`, `tokenizer`). Ohne Session-Erweiterung gibt es
  `session.upload_progress` nicht mehr, einen der Angriffswege aus CVE-2026-109619.
- Ungenutzte Tabellen `member_cache`, `field_config` und `settings` aus dem Schema für
  Neuinstallationen sowie der Schreibzugriff auf `settings`. Bestehende Installationen behalten
  die Tabellen, sie werden nicht mehr verwendet.
- nginx-Regeln für eingebettete Dateien und Pfadumschreibung. Das Dashboard ist eine einzelne
  Datei ohne externe Verweise, die Regeln griffen nie.

## [2.2.2] - 2026-11-17

**Wichtig vor dem Update:** Wer das Dashboard nicht über die Home-Assistant-Seitenleiste, sondern
direkt über den freigegebenen Port 8099 aufruft oder die API aus einer Automation abfragt, muss
nach dem Update die neue Option `allow_direct_access` einschalten. Sonst antwortet das Add-on dort
mit HTTP 403.

### Sicherheit
- **Zugriff auf Ingress beschränkt**: Der Webserver nimmt nur noch Anfragen von Home Assistant
  Ingress (Supervisor, `172.30.32.2`) und aus dem Container selbst an. Bisher konnte jedes andere
  Add-on auf demselben Gerät die API im internen Netz ohne Anmeldung aufrufen, also einen Sync
  erzwingen oder das Protokoll lesen. Die neue Option `allow_direct_access` (Standard `false`)
  hebt die Beschränkung auf. Im Standalone-Container ist sie standardmäßig an
  (`ALLOW_DIRECT_ACCESS=true`), dort ändert sich nichts.

### Behoben
- **Kachel „Synchronisiert" zählte doppelt**: Jedes synchronisierte Fahrzeug wurde einmal als
  eigene Zeile und einmal in der Summe des Laufs gezählt. Es gibt jetzt nur noch eine Zeile je
  Lauf. Die Zahl des Update-Tages kann noch zu hoch sein, ab dem Folgetag stimmt sie.
- **Backup**: `backup: cold`. Home Assistant hält das Add-on für die Dauer einer Sicherung an.
  Vorher wurden die Dateien der laufenden MariaDB kopiert, was nach einer Wiederherstellung zu
  einer nicht startenden Datenbank führen kann.

### Entfernt
- **Bereich „Fahrzeug-Feld-Konfiguration" im Dashboard** samt den Endpunkten `fieldConfig` und
  `updateField`. Die Häkchen hatten keine Wirkung: Der Server schrieb beim Umschalten nur eine
  Logzeile, Status und Notiz wurden immer synchronisiert. Daran ändert sich nichts.

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
