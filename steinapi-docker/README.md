# SteinAPI – Divera Sync (Standalone Docker)

Docker-Variante des Home-Assistant-Addons im selben Repo —
lauffähig auf **Docker Desktop for Windows** (WSL2), Linux und macOS,
ohne Home Assistant.

Nutzt die **gleiche `rootfs/`-Anwendung wie das HA-Addon** (keine
Duplikation): Bugfixes und Updates an der PHP-App wirken sich auf beide
Varianten gleichzeitig aus.

## Repo-Struktur

```
Stein-Divera-Sync-homeassistant-addon/
├── repository.json              ← HA-Supervisor-Metadaten
├── README.md
├── .dockerignore                ← (optional) schlanker Build-Context
├── steinapi-addon/              ← HA-Addon (Original, unverändert)
│   ├── config.yaml
│   ├── build.yaml
│   ├── Dockerfile               ← NUR für HA-Supervisor-Build
│   └── rootfs/                  ← PHP-App + Services (SHARED)
│       ├── etc/...
│       ├── usr/...
│       └── var/www/html/...
└── steinapi-docker/             ← DIESER Ordner (Standalone Docker)
    ├── Dockerfile               ← referenziert ../steinapi-addon/rootfs/
    ├── docker-compose.yml
    ├── docker-entrypoint.sh
    ├── .env.example
    └── README.md
```

**Wichtig:** HA-Supervisor liest nur `steinapi-addon/config.yaml` ein und
ignoriert `steinapi-docker/` komplett. Docker-Nutzer bauen nur aus
`steinapi-docker/` und ignorieren den HA-Teil.

## Voraussetzungen (Windows)

- **Docker Desktop for Windows** ([Download](https://www.docker.com/products/docker-desktop/))
- Aktiviertes **WSL2-Backend** (Standard bei aktuellen Versionen)
- PowerShell oder Windows Terminal

```powershell
docker version
docker compose version
```

## Installation

### 1. Repo klonen (falls nicht schon vorhanden)

```powershell
git clone https://github.com/Lexorius/Stein-Divera-Sync-homeassistant-addon.git
cd Stein-Divera-Sync-homeassistant-addon\steinapi-docker
```

### 2. Konfiguration anlegen

```powershell
Copy-Item .env.example .env
notepad .env
```

Trage in `.env` mindestens ein:

- `DIVERA_ACCESS_KEY` — dein Divera24/7 API-Key
- `STEIN_API_KEY` — dein Stein.app API-Key
- `STEIN_BUSINESS_UNIT_ID` — ID deiner Einheit

### 3. Container bauen & starten

```powershell
docker compose up -d --build
```

Docker Compose nutzt automatisch **Build-Context `..`** (Repo-Root), damit
das Dockerfile auf `steinapi-addon/rootfs/` zugreifen kann. Der erste
Build dauert 2–5 Minuten.

```powershell
# Logs live mitlesen
docker compose logs -f steinapi

# Status prüfen
docker compose ps
```

### 4. Öffnen

Browser → <http://localhost:8099>

## Bedienung

Alle Kommandos aus dem Ordner `steinapi-docker/` heraus:

| Aktion                     | Kommando                                        |
| -------------------------- | ----------------------------------------------- |
| Starten                    | `docker compose up -d`                          |
| Stoppen                    | `docker compose stop`                           |
| Logs ansehen               | `docker compose logs -f steinapi`               |
| In den Container einloggen | `docker compose exec steinapi bash`             |
| Update / Rebuild           | `docker compose up -d --build --force-recreate` |
| Komplett entfernen         | `docker compose down`                           |
| Entfernen **inkl. Daten**  | `docker compose down -v`                        |

⚠️ `down -v` löscht das Volume `steinapi-data` mit MariaDB und allen
Sync-Logs.

## Manueller Build (ohne Compose)

Falls du pur mit `docker build` arbeiten willst — Kommando muss aus dem
**Repo-Root** laufen, nicht aus `steinapi-docker/`:

```powershell
cd <Repo-Root>
docker build -f steinapi-docker/Dockerfile -t steinapi-divera-sync:local .
docker run -d --name steinapi -p 8099:8099 `
    -v steinapi-data:/data `
    --env-file steinapi-docker/.env `
    steinapi-divera-sync:local
```

## Daten-Persistenz

Zustandsdaten liegen im Docker Named Volume **`steinapi-data`**,
gemountet unter `/data`:

- `/data/mysql/` — MariaDB-Datenverzeichnis
- `/data/steinapi/` — App-interne Ablage
- `/data/options.json` — wird bei jedem Start aus `.env` neu generiert

Backup des Volumes (PowerShell):

```powershell
docker run --rm -v steinapi-data:/data -v ${PWD}:/backup `
  alpine tar czf /backup/steinapi-backup.tar.gz -C /data .
```

Restore:

```powershell
docker run --rm -v steinapi-data:/data -v ${PWD}:/backup `
  alpine sh -c "cd /data && tar xzf /backup/steinapi-backup.tar.gz"
```

## Konfiguration ändern

`.env` bearbeiten, dann:

```powershell
docker compose up -d
```

Der Entrypoint schreibt `/data/options.json` bei jedem Containerstart
neu, die PHP-App liest daraus.

## Port ändern

Standard ist `8099`. Bei Konflikten in `docker-compose.yml` die linke
Seite ändern:

```yaml
ports:
  - "9000:8099"   # Host-Port 9000 -> Container-Port 8099
```

## Updates einspielen

```powershell
cd <Repo-Root>
git pull
cd steinapi-docker
docker compose up -d --build
```

Da das Docker-Image beim Build die aktuelle `steinapi-addon/rootfs/`
einbettet, kommen Änderungen an der PHP-App automatisch mit.

## Troubleshooting

### „port is already allocated“ beim Start

Ein anderer Dienst nutzt Port 8099. Host-Port in `docker-compose.yml`
umstellen (siehe oben).

### Healthcheck zeigt „unhealthy“

Bis MariaDB initialisiert ist, braucht der Container ~30–60 s.
`start_period` im Compose-File gibt 60 s Toleranz. Bei dauerhaftem Fehler:

```powershell
docker compose logs steinapi --tail 200
```

Typische Ursachen: leere API-Keys, Schreibrechte auf Volume, Port-Konflikt.

### „COPY failed: file not found“ beim Build

Passiert, wenn man `docker build` aus dem falschen Verzeichnis startet
oder `context: ..` in `docker-compose.yml` manuell geändert hat. Der
Build-Context muss der **Repo-Root** sein, nicht `steinapi-docker/`.

### Container läuft, Webseite lädt aber nicht

```powershell
docker compose exec steinapi curl -v http://127.0.0.1:8099/api.php?action=health
```

Antwortet das mit HTTP 200, liegt das Problem zwischen Host und
Container — meist Windows-Firewall oder VPN.

### Von Grund auf neu aufsetzen

```powershell
docker compose down -v
docker compose up -d --build
```

## Unterschiede zum Home-Assistant-Addon

| Aspekt          | HA-Addon                        | Standalone-Container                 |
| --------------- | ------------------------------- | ------------------------------------ |
| Basis-Image     | `ghcr.io/home-assistant/*-base` | `alpine:3.19`                        |
| Service-Init    | s6-overlay                      | Entrypoint + supervisord             |
| Konfiguration   | `/data/options.json` von HA     | ENV-Vars → wird zu `options.json`    |
| UI-Zugriff      | HA Ingress                      | Direktzugriff `http://host:8099`     |
| Auto-Update     | HA Supervisor                   | `docker compose up --build`          |
| PHP-Anwendung   | `steinapi-addon/rootfs/`        | **identisch — shared**               |

## Lizenz

Wie Upstream-Repo: MIT.
