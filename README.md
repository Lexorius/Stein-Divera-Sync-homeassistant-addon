# Stein-Divera-Sync Home Assistant Add-ons

[![Open your Home Assistant instance and show the add add-on repository dialog with a specific repository URL pre-filled.](https://my.home-assistant.io/badges/supervisor_add_addon_repository.svg)](https://my.home-assistant.io/redirect/supervisor_add_addon_repository/?repository_url=https%3A%2F%2Fgithub.com%2FLexorius%2FStein-Divera-Sync-homeassistant-addon)

Home Assistant Add-on Repository für die Synchronisation des **Fahrzeugstatus** zwischen
**Divera24-7** und **Stein.app**.

> ⚠️ **Sicherheitsupdate 2.1.0 (21.10.2026):** PHP wurde auf 8.4.27 aktualisiert und schließt die
> kritischen Remote-Code-Execution-Lücken CVE-2026-109618 und CVE-2026-109619
> ([php.net Advisory](https://www.php.net/archive/2026.php#2026-10-21-5)). Zusätzlich wurden mehrere
> Schwachstellen im Add-on selbst behoben (TLS-Prüfung, offene Debug-Endpunkte, CORS, Datenbank).
> Bitte zeitnah aktualisieren - Details im [CHANGELOG](./steinapi-addon/CHANGELOG.md).

## 🚀 Installation

### Methode 1: Klick-Installation (Empfohlen)

Klicke auf den Button oben oder nutze diesen Link:

[![Add Repository](https://my.home-assistant.io/badges/supervisor_add_addon_repository.svg)](https://my.home-assistant.io/redirect/supervisor_add_addon_repository/?repository_url=https%3A%2F%2Fgithub.com%2FLexorius%2FStein-Divera-Sync-homeassistant-addon)

### Methode 2: Manuelle Installation

1. Öffne Home Assistant
2. Gehe zu **Einstellungen** → **Add-ons** → **Add-on Store**
3. Klicke auf das **⋮** Menü (oben rechts) → **Repositories**
4. Füge diese URL hinzu:
   ```
   https://github.com/Lexorius/Stein-Divera-Sync-homeassistant-addon
   ```
5. Klicke auf **Hinzufügen**
6. Das Add-on "SteinAPI - Divera Sync" erscheint nun im Add-on Store

### Ohne Home Assistant (Standalone Docker)

Dieselbe Anwendung läuft auch als eigenständiger Container (Docker Desktop, Linux, macOS),
siehe [steinapi-docker/README.md](./steinapi-docker/README.md).

## 📦 Verfügbare Add-ons

### [SteinAPI - Divera Sync](./steinapi-addon)

![Version](https://img.shields.io/badge/version-2.1.0-blue)
![Supports aarch64](https://img.shields.io/badge/aarch64-yes-green)
![Supports amd64](https://img.shields.io/badge/amd64-yes-green)
![PHP](https://img.shields.io/badge/PHP-8.4.27-777bb4)
![Alpine](https://img.shields.io/badge/Alpine-3.24-0d597f)

API + Dashboard für die bidirektionale Synchronisation des Fahrzeugstatus zwischen **Divera24-7**
und **Stein.app**. Die Zuordnung der Fahrzeuge erfolgt über das **Kennzeichen**.

**Features:**
- ✅ Synchronisation von FMS-Status und Statusnotiz in beide Richtungen (oder nur eine)
- ✅ Automatische Synchronisation in konfigurierbarem Intervall (1-60 Minuten)
- ✅ Webbasiertes Dashboard mit Statistiken und Aktivitätslog
- ✅ Home Assistant Ingress-Integration (Zugriff durch HA authentifiziert)
- ✅ Integrierte MariaDB, ausschließlich lokal (Unix-Socket) erreichbar
- ✅ HTTP-Rate-Limit, TLS-Zertifikatsprüfung, abschaltbare Debug-Endpunkte

**Architekturen:** `amd64`, `aarch64`. Home Assistant stellt für `armhf`, `armv7` und `i386`
keine Basis-Images mehr bereit; diese Plattformen werden seit 2.1.0 nicht mehr unterstützt.

## 📁 Repo-Struktur

```
Stein-Divera-Sync-homeassistant-addon/
├── repository.json        ← HA-Supervisor-Metadaten
├── steinapi-addon/        ← Home Assistant Add-on
│   ├── config.yaml        ← Add-on-Optionen und Schema
│   ├── build.yaml         ← Basis-Images (Alpine 3.24)
│   ├── Dockerfile         ← HA-Supervisor-Build
│   ├── CHANGELOG.md
│   └── rootfs/            ← PHP-App, nginx, php-fpm, MariaDB, Init-Skripte (SHARED)
└── steinapi-docker/       ← Standalone-Docker-Variante (nutzt dieselbe rootfs/)
```

## 🔒 Sicherheit

- Die API hat **keine eigene Authentifizierung**. Im Add-on erfolgt der Zugriff über HA Ingress
  (durch Home Assistant abgesichert); der Port `8099` ist standardmäßig **nicht** freigegeben und
  sollte es auch bleiben. Im Standalone-Container ist der Port standardmäßig nur auf `127.0.0.1`
  gebunden.
- Debug-Endpunkte sind standardmäßig deaktiviert (`debug_endpoints: false`).
- Sicherheitsprobleme bitte **nicht** als öffentliches Issue melden, sondern per E-Mail an den
  Maintainer (siehe GitHub-Profil [@Lexorius](https://github.com/Lexorius)).

## 📝 Support

Bei Problemen oder Fragen erstelle bitte ein [Issue](https://github.com/Lexorius/Stein-Divera-Sync-homeassistant-addon/issues).

## 📄 Lizenz

MIT License
