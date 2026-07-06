# SeerrSyncerr

Subtitle issue bridge for **Seerr** and **Bazarr**. When a user reports a bad
subtitle in Seerr, SeerrSyncerr resolves the title to a Radarr/Sonarr id and
asks Bazarr to fix it — blacklist + re-search by default, or realign the
existing file in place if the report says it's just out of sync — then
comments back on the Seerr issue and resolves it.

Runs and configures just like Radarr/Sonarr/Bazarr: `PUID`/`PGID`/`TZ` env
vars, a single `/config` volume, its own web UI and port. No database, no
composer dependency, no YAML — everything the app needs lives in the web UI.

## Quick start

```yaml
services:
  seerr-syncerr:
    image: ghcr.io/bymem/seerr-syncerr:latest
    container_name: seerr-syncerr
    environment:
      - PUID=1000
      - PGID=1000
      - TZ=Europe/Copenhagen
      - PORT=8089
    volumes:
      - ./config:/config
    ports:
      - "8089:8089"
    restart: unless-stopped
```

1. `docker compose up -d`
2. Open `http://<host>:8089` and fill in the settings form: Seerr / Radarr /
   Sonarr / Bazarr URLs + API keys, your main languages, and any optional
   keyword shortcuts.
3. Copy the generated **Webhook URL** and **Webhook secret** from the bottom
   of the settings page into Seerr → Settings → Notifications → Webhook:
   - **Webhook URL:** as shown.
   - **Authorization Header:** the secret as shown.
   - **Notification types:** enable **Issue Reported**.
   - JSON payload template: leave as Seerr's default, no changes needed.

## Environment variables

| Variable | Purpose | Default |
|---|---|---|
| `PUID` | User id to run as inside the container | `1000` |
| `PGID` | Group id to run as inside the container | `1000` |
| `TZ` | Timezone | `UTC` |
| `PORT` | Port the web server listens on | `8089` |

All *application* configuration (service URLs/API keys, language and sync
keywords, auto-translate interop) lives in the web UI and is persisted to
`/config/config.json` — there's nothing else to set via environment
variables or docker-compose, unlike some other *arr-style tools.

## What it does

- **Movies and TV**, including whole-season and whole-series reports (Seerr
  has no explicit "all episodes" marker — the granularity is implicit in
  which fields the webhook payload includes).
- **Language detection** from the reporter's comment via optional keyword
  shortcuts, falling back to your configured main-languages list when the
  comment doesn't say anything specific.
- **Sync vs. replace**: a comment like "out of sync" realigns the existing
  file to audio instead of blacklisting and re-downloading it.
- **External auto-translate tool interop**: detects when a subtitle on disk
  was written by an external tool (like Bazarr-AI-Translate or
  ai-subtitle-translator) rather than Bazarr itself, and resets it —
  fetching a fresh source file and re-triggering translation where the tool
  supports it — instead of running the normal blacklist flow against a file
  Bazarr never downloaded.

See `SPEC.md` in this repo for the full design.

## Development

No composer install needed — `src/` is autoloaded directly. Run locally with:

```bash
CONFIG_PATH=./config/config.json php -S 0.0.0.0:8089 -t public public/index.php
```
