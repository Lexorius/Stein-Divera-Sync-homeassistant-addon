# Changelog

Alle wichtigen Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.0.0/),
und dieses Projekt hält sich an [Semantic Versioning](https://semver.org/lang/de/).

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
