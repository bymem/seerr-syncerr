# SeerrSyncerr — Subtitle Issue Bridge for Seerr + Bazarr

A small self-hosted service that installs and runs just like Radarr/Sonarr/Bazarr
(PUID/PGID/TZ env vars, `/config` volume, own web UI, own port) and closes the loop
between Seerr's "report an issue" feature and Bazarr's subtitle search.

**Flow:** user reports a bad subtitle in Seerr → Seerr fires a webhook → this app
resolves the TMDB/TVDB id to a Radarr/Sonarr internal id → asks Bazarr to blacklist
the current subtitle and search for a new one → comments back on the Seerr issue
and marks it resolved.

---

## 1. Why not just reuse overr-syncerr?

- It's archived (May 2026), PowerShell-based, and never targeted the unified Seerr
  webhook payload — treat it as reference only, not a dependency.
- Seerr's webhook JSON payload and REST API are the same shape Overseerr/Jellyseerr
  used, so the integration approach still holds; this project reimplements it in PHP.

### 1.1 Feature parity checklist

Since the goal is "equal replacement, improved where it makes sense," here's
every advertised overr-syncerr feature and what seerr-syncerr does with it:

| overr-syncerr feature | seerr-syncerr status |
|---|---|
| Full Sonarr/Radarr/Bazarr integration | **Kept** — §7 `RadarrClient`/`SonarrClient`/`BazarrClient` |
| Language mapping (keyword → language) | **Kept, improved** — §4.1: multiple synonyms per language (like the original), *plus* a default main-languages list so a report with no recognized keyword fixes everything you care about instead of doing nothing |
| Sync keywords (timing realignment) | **Kept** — §7.1 `ActionResolver`, config `sync_keywords` |
| Translate requests (Google/GPT) | **Dropped, deliberately** — §2 Non-goals: not this tool's job, hand off to a dedicated external tool instead (§8) |
| Manual adjustment requests | **Deferred** — see §9; the exact scope of "manual adjustment" in the original isn't something I could verify precisely, so it's an extension idea rather than a v1 promise |
| Auto-reply & resolve issue | **Kept, improved** — one summary comment per language/action attempted, not just a single generic reply |
| Sync whole season ("All Episodes") | **Kept, extended** — promoted into the core flow (§7 step 4); confirmed there's no magic value, granularity (episode/season/series) is implicit in `extra`'s shape, so whole-series is now handled too, not just whole-season |
| 4K Bazarr/Radarr/Sonarr instance support | **Deferred** — §9 extension idea |
| Config via docker-compose env vars (YAML, requires restart) | **Replaced** — everything's in the web UI, live, no restart (§4) |

---

## 2. Non-goals

**seerr-syncerr does not do translation.** That's a deliberately separate, already-solved
problem (proactive, library-wide, scheduled/on-add) handled well by existing tools like
[Bazarr-AI-Translate](https://github.com/nirkons/Bazarr-AI-Translate),
[Bazarr_AutoTranslate](https://github.com/anast20sm/Bazarr_AutoTranslate), or
[ai-subtitle-translator](https://pypi.org/project/ai-subtitle-translator/). Bazarr's own
maintainers have explicitly said auto-translation isn't on their roadmap, which is exactly
why these third-party tools exist.

seerr-syncerr's job stays narrow: **a user reported a specific file is broken → fix that
file.** See §8 for how it cooperates with whichever auto-translate tool is already running,
without owning any translation logic itself.

## 3. Container packaging (match the *arr pattern)

This is the local-development shape (`build: .`); once published to GHCR,
`docker-compose.yml` switches to `image: ghcr.io/...` instead — see §10.

```yaml
# docker-compose.yml
services:
  seerr-syncerr:
    build: .
    container_name: seerr-syncerr
    environment:
      - PUID=1000
      - PGID=1000
      - TZ=Europe/Copenhagen
      - PORT=8070
      - WEBUI_USERNAME=admin
      - WEBUI_PASSWORD=changeme # required — see §4.2
    volumes:
      - ./config:/config
    ports:
      - "8070:8070"
    restart: unless-stopped
```

- **Base image:** `php:8.3-cli-alpine` + `curl`, `tzdata`, `shadow`, `su-exec`.
- **Entrypoint script** (`entrypoint.sh`) does the linuxserver.io-style dance:
  1. Read `PUID`/`PGID`/`PORT`/`TZ`/`WEBUI_PASSWORD`/`WEBUI_USERNAME` env
     vars (sensible defaults for all but `WEBUI_PASSWORD`, which has none —
     **refuse to start** if it's unset/empty; see §4.2).
  2. Create/modify a system user+group matching `PUID`/`PGID`.
  3. `chown -R` the `/config` volume so the app can write to it.
  4. `exec su-exec appuser php -S 0.0.0.0:$PORT -t /app/public /app/public/index.php`
- No database — one `config.json` file in `/config`, same idea as Bazarr's
  `settings.json`. First boot auto-generates the file plus a random webhook
  secret (`bin2hex(random_bytes(24))`).
- No composer/framework dependency — a ~15-line `spl_autoload_register` maps
  the `SeerrSyncerr\` namespace to `src/`. Keeps the image small and the build step
  trivial (`docker build .` with no `composer install`).
- Health check: `GET /healthz` → `200 {"status":"ok"}`, wired into
  `HEALTHCHECK` in the Dockerfile, same convention as Seerr's own image.

---

## 4. Web UI (the "settings page" every *arr app has)

**Every setting lives in the web UI — nothing requires editing `config.json` by
hand or passing extra environment variables.** `PUID`/`PGID`/`TZ`/`PORT` are
container-level concerns (user/permissions/timezone/port binding), decided at
`docker run` time before the app even starts — same split Radarr/Sonarr/Bazarr
use. `WEBUI_USERNAME`/`WEBUI_PASSWORD` join that list for the same reason:
the settings UI itself exposes every configured service's API key and the
webhook secret, so *access to the UI* has to be decided before the app is
reachable at all, not configured from inside a page that's the very thing
being protected — see §4.2. Everything else the app needs is editable at `/`,
POSTing to `/save`, persisted via `Config::save()`.

| Field | Purpose |
|---|---|
| Seerr URL + API key | to comment/resolve issues |
| Radarr URL + API key | tmdbId → radarr movie id |
| Sonarr URL + API key | tvdbId + season/ep → sonarr episode id |
| Bazarr URL + API key | trigger blacklist + resubtitle search |
| **Main languages** (ordered list, add/remove rows) | languages to fix when a report gives no usable keyword — see §4.1 |
| **Language keywords** (repeating key→value rows) | optional shortcut: map a word a reporter might type ("english", "eng") to a language code — see §4.1 |
| **Sync keywords** (repeating list) | optional shortcut: words like "out of sync"/"timing" that mean *realign this file*, not *replace it* — see §4.1 |
| **Auto-translate tool** (dropdown + conditional fields) | which adapter answers "is it callable" for §8's reset flow |
| **Externally-translated filename pattern** (single regex field) | always shown regardless of adapter — you fill this in from what your tool actually names its output |
| **Webhook URL** (read-only, shown to copy into Seerr) | `http://<host>:8070/webhook` |
| **Webhook secret** (read-only, shown to copy into Seerr) | goes in Seerr's Authorization Header field |

### 4.1 Main languages, keyword matching, and sync vs. replace

This replaces a single "default language" field with three UI-managed lists:

- **Main languages** — an ordered list of language codes (e.g. `da`, then `en`).
  *Every* entry gets fixed when a report doesn't match a language keyword
  below — it's not a fallback chain where only the first applies, the order
  only controls the sequence languages are processed/reported in. Managed as
  add/remove rows in the UI (with ↑/↓ reorder buttons, since order is
  meaningful), not a raw JSON edit. Codes must be whatever Bazarr itself
  uses (check Bazarr's own Settings → Languages page) — this app passes
  what's typed here straight to Bazarr's API, so it has to match Bazarr's
  codes, not Seerr's language names or a Radarr/Sonarr profile label.
- **Language keywords** — an optional lookup a reporter's comment can match
  against (e.g. `english` → `en`, `eng` → `en`). Entirely optional power-user
  shortcut, also managed as add/remove rows in the UI. Nobody needs to know
  this exists for the tool to work.
- **Sync keywords** — a separate list (e.g. `"out of sync"`, `"timing"`,
  `"messed up"`) that changes *what kind of fix* runs, independent of which
  language it applies to. overr-syncerr had this exact concept: a subtitle
  can be the right language and even the right file, just drifted out of
  time — re-downloading a "new" one doesn't help, only realigning it does.
  Prefilled on a fresh install (`"out of sync"`, `"desync"`, `"timing"`,
  `"off by"`) so this works out of the box rather than doing nothing until
  the user thinks to add entries themselves — still fully editable/removable.

These are genuinely two different questions the comment can answer — *which
language* and *what kind of problem* — so they're resolved by two separate
classes rather than one, see §7's `LanguageResolver` and `ActionResolver`.

See §7 for exactly how these lists get used together.

### 4.2 Web UI authentication

The settings UI is the one page in this app that's actually dangerous to
leave open on a network: it shows (and lets you resave) the Seerr/Radarr/
Sonarr/Bazarr API keys and the webhook secret. Credentials are **still just
two env vars** — no user table, no stored password hash, nothing that needs
to exist before the app can enforce it:

- `WEBUI_PASSWORD` — **required, no default.** `entrypoint.sh` checks this
  before doing anything else and `exit 1`s with a clear message if it's
  unset/empty, so the container simply never comes up unauthenticated.
- `WEBUI_USERNAME` — optional, defaults to `admin`.

Login itself is a proper **HTML form + session cookie**, not HTTP Basic
Auth — nicer UX (a real login page instead of the browser's native prompt,
an explicit sign-out) at the cost of needing CSRF protection, since a
cookie is sent automatically by the browser on any request whereas a Basic
Auth header is not. `Support\SessionAuth` covers both halves:

- `attempt(username, password)` — checks both via `hash_equals()` against
  the env vars; on success, `session_regenerate_id(true)` then flags the
  PHP session authenticated.
- `csrfToken()` / `verifyCsrf()` — a random token stored in the session,
  rendered as a hidden field on the settings form (`templates/settings.php`)
  and checked on every `POST /save`.

Routes in `public/index.php`:

- `GET /login` — the login page (`templates/login.php`); redirects to `/` if
  already authenticated.
- `POST /login` — checks credentials via `SessionAuth::attempt()`; redirects
  to `/` on success, back to `/login?error=1` on failure.
- `GET|POST /logout` — destroys the session, redirects to `/login`.
- `GET /` and `POST /save` — require `SessionAuth::isLoggedIn()`, redirect to
  `/login` otherwise; `/save` additionally requires a valid CSRF token,
  rejecting with `403` if it's missing or wrong.
- **`/webhook` is untouched** — Seerr authenticates to it with its own
  independent secret (§5), checked separately in `WebhookController`, not
  with a username/password/session.
- **`/healthz` is untouched** — needs to stay reachable for the Dockerfile's
  `HEALTHCHECK` without credentials.

If `WEBUI_PASSWORD` is somehow empty at request time (e.g. running
`public/index.php` directly with `php -S` outside the container, without
setting the env var), `SessionAuth::attempt()` fails closed — login can
never succeed, rather than silently allowing access.

### 4.3 Action Log tab

`docker logs` is the only place any of this was visible before — fine for
someone comfortable with the container, not for glancing at what happened
after a report came in. The settings page and the log page now share a small
tab bar (`Settings` / `Action Log`) so both live under the same login.

- **`Support\Logger`** writes every `info()`/`warning()`/`error()` call to
  stdout (unchanged, still picked up by `docker logs`) *and* appends the same
  line to a capped file (`ACTIVITY_LOG_PATH`, defaults to
  `/config/activity.log` — same non-user-facing default pattern as
  `CONFIG_PATH`, not exposed as a docker-compose env var). Capped at 500
  lines (oldest dropped first) — a debug aid, not an audit trail.
- **`Controllers\LogsController`** reads the most recent entries
  (`Logger::recentEntries()`, newest first) and renders `templates/logs.php`,
  gated behind the same `SessionAuth::isLoggedIn()` check as `/` and `/save`.
- **`Webhook\SubtitleIssueHandler`** now logs at each real decision point,
  not just failures: receipt of a report (issue id + subject + comment text),
  the resolved languages/action, one line per target/language outcome
  (`info` if it reached a definite result, `warning` otherwise), and the
  final resolve-vs-leave-open decision — enough to answer "what did it do
  with that report" from the UI alone.

---

## 5. Seerr-side setup (what the user configures in Seerr itself)

Settings → Notifications → Webhook:

- **Webhook URL:** the one shown in SeerrSyncerr's UI.
- **Authorization Header:** the secret from SeerrSyncerr's UI (checked with
  `hash_equals()` on our side — never a plain `===` on secrets).
- **Notification types:** enable only **Issue Reported** (and optionally
  **Issue Comment**, see §9 extension ideas).
- **JSON Payload** — Seerr's default template is fine as-is, no customization
  needed. Confirmed live (2026-07) against real reports — actual shape:

```json
{
  "notification_type": "ISSUE_CREATED",
  "event": "New Subtitle Issue Reported",
  "subject": "The Devil Wears Prada 2 (2026)",
  "message": "missing subtitles",
  "image": "https://image.tmdb.org/t/p/w600_and_h900_bestv2/....jpg",
  "media": {
    "media_type": "movie",
    "tmdbId": "1314481",
    "tvdbId": "",
    "status": "AVAILABLE",
    "status4k": "UNKNOWN"
  },
  "request": null,
  "issue": {
    "issue_id": "1",
    "issue_type": "SUBTITLES",
    "issue_status": "OPEN",
    "reportedBy_email": "...",
    "reportedBy_username": "...",
    "reportedBy_avatar": "...",
    "reportedBy_settings_discordId": "...",
    "reportedBy_settings_telegramChatId": ""
  },
  "comment": null,
  "extra": []
}
```

**Corrections vs. what I'd originally guessed, now confirmed against real
Seerr reports:**

- **The reporter's typed description is top-level `message`**, not
  `issue.issue_comment` (that field doesn't exist). There's also a top-level
  `comment`, but it came through `null` on every `ISSUE_CREATED` payload
  tested — it's presumably populated on a later `ISSUE_COMMENT` event, not
  the initial report. `LanguageResolver`/`ActionResolver` (§7) read `message`.
- **`extra` is top-level**, not nested under `issue`.
- **`tmdbId`/`tvdbId` are strings** (`"1314481"`), not numbers — cast
  explicitly before passing to Radarr/Sonarr's query params.
- **Both `tmdbId` and `tvdbId` keys are always present**, empty-string on
  whichever doesn't apply to the media type — don't treat presence/absence
  as the signal, use `media_type` instead.
- **No special "All Episodes" value exists.** Granularity is implicit in
  which keys `extra` contains instead — see the confirmed shapes below.
- One field is a known Seerr quirk, not something to rely on:
  `reportedBy_settings_discordId` came through as the **literal unrendered
  string** `"{{reportedBy_settings_discordId}}"` when the reporting user had
  no Discord ID linked — Seerr's template engine doesn't appear to fall back
  to an empty string here. Not used anywhere in this design, but worth
  remembering if a future feature ever reads it.

### Confirmed `extra` shapes for TV reports

| Report level | `extra` |
|---|---|
| Whole series (reported at the show level) | `[]` — identical shape to a movie report with nothing else to distinguish it, so this *is* the "no scope specified" case §7 needs to treat as "whole series" |
| Whole season | `[{"name":"Affected Season","value":"1"}]` |
| Single episode | `[{"name":"Affected Season","value":"2"},{"name":"Affected Episode","value":"3"}]` |

So the granularity check in §7 is simply: does `extra` contain an
`Affected Episode` entry? → single episode. Only `Affected Season`, no
episode? → whole season. Neither? → whole series. No magic string to detect,
just which keys are present.

---

## 6. File layout

```
seerr-syncerr/
├── Dockerfile
├── docker-compose.yml
├── entrypoint.sh
├── README.md
├── config/
│   └── config.sample.json
├── public/
│   └── index.php            # front controller / router (used by `php -S`)
├── src/
│   ├── Config.php           # load/save config.json, dot-notation get()
│   ├── Support/
│   │   ├── Logger.php        # stdout logger + capped activity log file, see §4.3
│   │   ├── HttpClient.php    # thin cURL wrapper (GET/POST/PUT/PATCH + JSON/multipart)
│   │   ├── LanguageResolver.php  # comment -> [language codes] to fix, see §4.1/§7
│   │   ├── ActionResolver.php    # comment -> "sync" or "replace", see §4.1/§7.1
│   │   ├── ExternalTranslationDetector.php  # was this file translated outside Bazarr?
│   │   └── SessionAuth.php       # login/session/CSRF for the settings UI, see §4.2
│   ├── TranslatorAdapters/
│   │   ├── ExternalTranslatorAdapter.php    # interface: isCallable()/triggerRetranslate(), see §8
│   │   ├── BazarrAiTranslateAdapter.php
│   │   ├── BazarrAutoTranslateAdapter.php
│   │   ├── AiSubtitleTranslatorAdapter.php
│   │   └── CustomAdapter.php                # user-supplied pattern/URL from the UI
│   ├── Clients/
│   │   ├── SeerrClient.php   # comment + resolve issue
│   │   ├── RadarrClient.php  # tmdbId -> radarr movie id
│   │   ├── SonarrClient.php  # tvdbId + S/E -> sonarr episode id
│   │   └── BazarrClient.php  # blacklist + search subtitle
│   ├── Webhook/
│   │   └── SubtitleIssueHandler.php  # orchestrates the whole flow
│   └── Controllers/
│       ├── WebhookController.php     # POST /webhook
│       ├── SettingsController.php    # GET/POST / (web UI)
│       └── LogsController.php        # GET /logs (Action Log tab), see §4.3
└── templates/
    ├── login.php             # login form, see §4.2
    ├── logs.php               # Action Log tab, see §4.3
    └── settings.php          # plain PHP+HTML view, no framework
```

---

## 7. Class contracts

### `Support\LanguageResolver`
- `resolve(string $comment, array $mainLanguages, array $keywordMap): array`
  — returns the list of language codes to fix, in priority order.
  1. Lowercase the comment, split on non-letters, check each word against
     `keywordMap` (both keys and values come from the UI — see §4.1).
  2. **Any match found** → return just that one language code. This is the
     opt-in shortcut path; a reporter who happens to type "english" gets
     exactly English fixed, nothing else.
  3. **No match found** (empty comment, or text that doesn't hit any
     configured keyword) → return the full `mainLanguages` list unchanged.
     This is the default, hands-off path — an empty or unhelpful comment
     means "fix everything I said I care about," not "fix nothing" or
     "guess one language."

### `Support\ActionResolver`
- `resolve(string $comment, array $syncKeywords): string` — returns `'sync'`
  or `'replace'`.
  1. Lowercase the comment, check for any configured `sync_keywords` phrase
     (substring match, since these are phrases like "out of sync" rather
     than single words — different matching approach than `LanguageResolver`,
     which splits on word boundaries).
  2. **Match found** → `'sync'`: realign the existing file to the audio
     track, don't touch which file it is.
  3. **No match** (the default, same as overr-syncerr's behavior when no
     sync keyword was present) → `'replace'`: the existing blacklist +
     research flow.

### `Config`
- `get(string $dotKey, $default = null)` — dot-notation read (`get('seerr.url')`)
- `save(array $data): void` — merges onto the current config, persists to `/config/config.json`
- `all(): array`
- Schema now includes `subtitles.main_languages` (ordered array of codes),
  `subtitles.language_keywords` (object, keyword → code), and
  `subtitles.sync_keywords` (array of phrases) alongside the service URLs/keys
  — all sections editable from the same settings form.
- **Merge is one level deep, not `array_replace_recursive()`.** Every section
  (`seerr`, `subtitles`, `translator`, ...) is exactly two levels deep, and
  every caller always submits a section's leaf fields as a complete set — so
  `mergeSections()` replaces each leaf value wholesale (`array_replace()` at
  depth 1) rather than recursing further. This matters specifically for list/
  map leaves like `main_languages` or `language_keywords`: with a *recursive*
  merge, submitting a shorter list after removing a row would merge by
  index/key against the previous (longer) value and the removed entry would
  silently survive, since there'd be nothing at that index/key to overwrite
  it with. Applies equally to filling in defaults for a config.json from an
  older version (`mergeDefaults()`) and to `save()` itself.

### `Support\HttpClient`
- `get(string $path, array $query = []): array{status:int, body:?array}`
- `post(string $path, array $body = []): array{status:int, body:?array}` —
  JSON body, used for Seerr/Radarr/Sonarr
- `postMultipart(string $path, array $formFields, array $query = []): array{status:int, body:?array}`
  — needed specifically for Bazarr: confirmed (§7 `BazarrClient`)
  that its blacklist endpoints expect `multipart/form-data`, not JSON,
  unlike every other API this project talks to. `CURLOPT_POSTFIELDS` with a
  plain array (not `json_encode`'d) makes cURL send it as multipart
  automatically — small implementation detail, but worth a code comment
  explaining *why* this one method exists, since it'd otherwise look like
  redundant duplication of `post()`.
- `patchMultipart(string $path, array $formFields, array $query = []): array{status:int, body:?array}`
  — same as `postMultipart()` but issues `PATCH`. **Added 2026-07-08**: every
  Bazarr "run an action" endpoint (sync, search-missing) turned out to
  actually be `PATCH`, not `POST` — confirmed by reading Bazarr's real
  source after a live 500 exposed the wrong-method version. See §7's
  `BazarrClient` for the full correction.
- `put(string $path, array $body = []): array{status:int, body:?array}`

### `Clients\SeerrClient`
- `addComment(int $issueId, string $message): bool` — `POST /api/v1/issue/{id}/comment`
- `resolveIssue(int $issueId): bool` — `POST /api/v1/issue/{id}/resolved`

> ✅ **Confirmed 2026-07-06** against a live Seerr instance (dev tools Network
> tab while manually resolving a test issue) — both routes are exactly as
> assumed from Overseerr's stable API. Bonus finding from the returned issue
> object: the REST API represents status numerically (`status: 2` for
> resolved, vs. the webhook's `"issue_status": "OPEN"` string) and
> `issueType: 3` corresponds to `SUBTITLES` — only relevant if seerr-syncerr
> ever needs to *read* an issue's state back, which it doesn't currently.

### `Clients\RadarrClient`
- `findRadarrIdByTmdbId(int $tmdbId): ?int`

### `Clients\SonarrClient`
- `findSeriesIdByTvdbId(int $tvdbId): ?int`
- `findEpisodeId(int $seriesId, int $season, int $episode): ?int`
- `findEpisodeIdsForSeason(int $seriesId, int $season): array` — every
  episode id in one season, for the "whole season reported" case (§7 step 4)
- `findAllEpisodeIds(int $seriesId): array` — every episode id across every
  season, for the "whole series reported" case (§7 step 4). All three methods
  can share one `GET /episode?seriesId=` call and just filter differently
  client-side — no reason to hit Sonarr three separate times.

### `Clients\BazarrClient`

**Rewritten 2026-07-08 against Bazarr's actual source** (fetched live from
`morpheus65535/bazarr` on GitHub — `bazarr/api/movies/*.py`,
`bazarr/api/episodes/*.py`, `bazarr/api/subtitles/subtitles.py`), after a
real production 500 showed the prior "confirmed live" claims for the
action-style endpoints were wrong. The earlier notes were apparently based
on a misremembered or different-version capture — this pass reads the
actual `flask_restx` `Resource` classes and their `reqparse.RequestParser`
argument lists directly, which is a stronger source than a UI capture:

- `findMovieByRadarrId(int $radarrId): ?array` — `GET /api/movies?radarrid[]=`
  — confirmed (`api/movies/movies.py`, `Movies.get()`).
- `findEpisodeBySonarrIds(int $seriesId, int $episodeId): ?array` —
  `GET /api/episodes?seriesid[]=&episodeid[]=` — confirmed
  (`api/episodes/episodes.py`, `Episodes.get()`).
- `findCurrentSubtitleRelease(int $mediaId, string $language, bool $isEpisode): ?array`
  — queries `GET /api/movies/history?radarrid=` or
  `GET /api/episodes/history?episodeid=` (plain int, not bracket-array —
  confirmed, `MoviesHistory.get()`) for the most recent matching-language
  download, returning `provider` + `subs_id` + on-disk `path`. Necessary
  because Bazarr's blacklist table is keyed by **provider + subs_id** (a
  specific release), not by movie/language — confirmed from
  `table_blacklist`'s schema and `blacklist_delete(provider, subs_id)` in
  `bazarr/utils.py`. You cannot blacklist "the Danish subtitle for movie X";
  only "this exact release from this exact provider."
- `blacklistAndResearchMovie(...)` / `blacklistAndResearchEpisode(...)` —
  **`POST /api/movies/blacklist`** / **`POST /api/movies/blacklist`**
  (genuinely `POST` — these resources *do* define `post()`), fields
  `radarrid`/`provider`/`subs_id`/`subtitles_path`/`language` (episode
  variant: `seriesid`+`episodeid` instead of `radarrid`) — confirmed still
  working live as of 2026-07-08. **Correction:** Bazarr's blacklist `post()`
  already calls `movies_download_subtitles()`/`episode_download_subtitles()`
  itself once the delete succeeds — a separate research call after a
  successful blacklist is pure redundant work, not extra resilience (the
  code only ever reached it on the success path anyway). Removed.
- `researchMovie(int $radarrId): bool` — **`PATCH /api/movies`**, fields
  `radarrid`+`action=search-missing` — confirmed (`api/movies/movies.py`,
  `Movies.patch()`, an action-dispatch handler with no separate `post()`
  logic for this). **Correction:** this was `POST` until 2026-07-08, which
  actually hit `Movies.post()` — a completely different, unrelated handler
  ("update movie's language profile") that crashed with a real live `500`
  the first time this path was exercised, since our request didn't match
  what that handler expects. Searches every currently-missing language on
  the movie's profile, not just the one being fixed — accepted, since movies
  have no single-language equivalent (episodes do, see below).
- `researchEpisode(int $seriesId, int $episodeId, string $language): bool`
  — **`PATCH /api/episodes/subtitles`**, fields
  `seriesid`+`episodeid`+`language`+`forced`+`hi`. **Correction:** SPEC.md
  previously assumed a bulk `POST /api/episodes?action=search-missing`
  mirroring the movie route — that route doesn't exist at all
  (`api/episodes/episodes.py`'s `Episodes` resource only defines `get()`).
  The real equivalent is `EpisodesSubtitles.patch()` (`api/episodes/
  episodes_subtitles.py`), which downloads one *specific* language rather
  than "everything missing" — arguably a better fit for this app's use case
  than the movie route's blanket search, not just a workaround.
- `syncMovieSubtitle(...)` / `syncEpisodeSubtitle(...)` — **`PATCH
  /api/subtitles`**, fields `action=sync`, `type` (`movie`/`episode`),
  `path`, `id`, `language`, `forced`, `hi`, `gss` (Golden Section Search —
  confirms overr-syncerr's old "using the 1st audio track + GSS"
  description) — confirmed (`api/subtitles/subtitles.py`, `Subtitles.patch()`,
  `action == 'sync'` branch calling `sync_subtitles()`). **Correction:**
  this was `POST` until 2026-07-08 — that resource has **no `post()` at all**,
  so every prior call here would have failed outright; it just hadn't been
  exercised yet in testing (only reached when a report's comment matches a
  sync keyword). Also: Bazarr compares `hi`/`forced`/`gss` with an
  **exact-case `== 'True'`**, no normalization — the previous lowercase
  `'true'`/`'false'` silently evaluated to `False` every time regardless of
  what was actually requested. Now sent capitalized (`'True'`/`'False'`).
  `reference`/`max_offset_seconds`/`no_fix_framerate` are left unset
  (all optional in `reqparse`) — Bazarr falls back to the video file itself
  as the sync reference when `reference` is omitted, which is exactly the
  "1st audio track" behavior this app wants.
- All of the above use `Support\HttpClient::patchMultipart()`
  (new — mirrors `postMultipart()` but issues `PATCH`) rather than
  `postMultipart()`, now that the HTTP verb itself is confirmed to matter:
  Bazarr's `flask_restx` resources dispatch strictly by verb, and a request
  arriving with the wrong one either 405s or — worse, as `researchMovie`'s
  live 500 showed — silently lands on a *different* handler that happens to
  share the same route.

> ⚠️ **One unknown left:** which numeric history `action` code means
> "downloaded via search" (only confirmed so far: `action == 4` means
> "manually uploaded," from a real traceback in
> `bazarr/api/movies/movies_subtitles.py` line 143). Needed for
> `findCurrentSubtitleRelease()` to correctly identify a real automatic
> download vs. any other history event type. Also
> worth a quick look, unrelated to any of this: `bazarr/utils.py` has an
> internal `translate_subtitles_file` function — meaning Bazarr does have
> *some* manual, on-demand translate capability internally, even though the
> earlier feature-request thread confirms *automatic/scheduled* translation
> isn't on their roadmap. Doesn't change anything in this spec (§2's non-goal
> stands either way), but worth knowing it exists if that Non-goals wording
> ever needs softening.

### `Webhook\SubtitleIssueHandler`
- `handle(array $payload): void`
  1. Bail unless `notification_type === 'ISSUE_CREATED'`.
  2. Bail unless `issue.issue_type === 'SUBTITLES'`.
  3. Branch on `media.media_type` (`movie` vs `tv`), casting `tmdbId`/`tvdbId`
     to int before resolving the Radarr/Sonarr id (§5: both fields are always
     present as strings, empty on whichever doesn't apply).
  4. **For TV:** check the top-level `extra` array (§5 confirmed shape) for
     `Affected Season`/`Affected Episode` entries:
     - Both present → single episode, resolve one episode id.
     - Only `Affected Season` → whole season, resolve every episode id in
       that season via `SonarrClient` (loop `findEpisodeId`).
     - Neither present → whole series, loop every season and every episode.
       This mirrors overr-syncerr's "sync whole season" feature and extends
       it one level further, now a core step rather than a stretch goal.
  5. **Run `LanguageResolver::resolve()`** against the top-level `message`
     field (§5 — *not* `issue.issue_comment`, that field doesn't exist) and
     the configured `main_languages`/`language_keywords` — gives back one
     language (keyword matched) or the full main-languages list (nothing
     matched/blank).
  6. **Run `ActionResolver::resolve()`** against the same `message` field and
     the configured `sync_keywords` — gives back `'sync'` or `'replace'`.
  7. **For each resolved language, for each resolved episode (movies: just one
     pass)**, branch on the action:
     - **`'sync'`:** call `BazarrClient::syncMovieSubtitle()` /
       `syncEpisodeSubtitle()` directly — no blacklist, no external-translation
       check, just realign the file that's already there.
     - **`'replace'` (default):**
       - Look up the current subtitle for that language via
         `BazarrClient::findCurrentSubtitleRelease()` — gives back the
         `provider`+`subs_id` blacklisting actually needs (§7's `BazarrClient`
         note: Bazarr blacklists specific releases, not "the Danish subtitle
         for this movie").
       - **Check if it came from an external auto-translate tool** (§8's
         `ExternalTranslationDetector`):
         - **Not externally translated (normal case):** blacklist that
           release + research.
         - **Externally translated:** delete the on-disk file → blacklist +
           re-force the source subtitle → hand off to the external tool per §8.
  8. Post **one** comment summarizing the outcome per language/episode/action
     combination attempted, and resolve the issue only if every attempted
     combination reached a definite outcome — leave it open if any ended in
     "waiting on external tool."
  9. Any failure at any step: log it, and make sure it's reflected in the
     summary rather than failing the whole comment silently.

### `Controllers\WebhookController`
- Verifies `Authorization` header via `hash_equals()` against the stored secret.
- Decodes JSON body, delegates to `SubtitleIssueHandler`.
- Always returns `200` (even on internal errors, which are logged) to avoid
  Seerr's webhook retry logic hammering the endpoint.

### `Controllers\SettingsController`
- `showForm()` — renders current config (mask API keys in the UI, values still
  submitted via HTTPS-only if you put this behind a reverse proxy).
- `save(array $formData)` — validates & persists.

---

## 8. Interop with external auto-translate tools

The problem you actually hit: a user reports "Danish subtitle is wrong," but
the Danish file on disk didn't come from Bazarr at all — an external
auto-translate tool wrote it there directly by translating a downloaded
English file. Bazarr has no record of it, so the normal "blacklist + research"
flow does nothing: Bazarr can't blacklist a subtitle it never downloaded, and
re-searching for Danish finds the same "nothing available" result that caused
the translation in the first place.

**seerr-syncerr's job here is narrow: detect that situation and reset it back
to a state the external tool will act on again — not perform any translation
itself.**

Rather than picking one external tool to support, this is built as a small
**adapter interface** with one concrete class per known tool. But the
filename pattern a tool produces isn't really *tool-specific code* — it
depends on how that tool's been configured on your particular system
(folder naming, language-code conventions), which varies install to install
even for the same tool. So that's a single **config field**, always present
regardless of which adapter's active — not something baked into each adapter
class. What *is* genuinely tool-specific is only "can I call this thing on
demand, and how" — that's the entire adapter contract.

### `Support\ExternalTranslatorAdapter` (interface)

- `isCallable(): bool` — does it expose something we can call on demand
- `triggerRetranslate(string $sourceSubtitlePath): bool` — only meaningful
  when `isCallable()` is true; no-op otherwise

### Concrete adapters

| Adapter | Callable? |
|---|---|
| `BazarrAiTranslateAdapter` | No — Tautulli/cron-script only, no HTTP server |
| `BazarrAutoTranslateAdapter` | No — same script-agent pattern |
| `AiSubtitleTranslatorAdapter` | **Yes** — runs its own FastAPI server; `triggerRetranslate()` POSTs to its `/process` endpoint |
| `CustomAdapter` | whatever "callable" toggle + URL you provide in the UI |

### Web UI addition

Two fields on the settings form, independent of each other:

- **"Auto-translate tool"** — a dropdown (`Bazarr-AI-Translate` /
  `Bazarr_AutoTranslate` / `ai-subtitle-translator` / `Custom` / `None`) that
  selects which adapter answers `isCallable()`/`triggerRetranslate()`.
  Picking `Custom` reveals a callable toggle + URL field. Picking `None`
  disables §8 entirely — normal blacklist+research runs for every subtitle,
  no external-translation detection at all, which is the right choice if
  you're not running any auto-translate tool.
- **"Externally-translated filename pattern"** — one regex field, always
  shown regardless of which adapter is selected above. You fill this in once
  by looking at what your tool actually named a real output file (or leave it
  blank if you're not using §8 at all).

The handler never needs to know which tool is active beyond asking the
adapter those two questions — adding a fifth tool later means adding one more
adapter class and one more dropdown option, nothing else changes. And since
the filename pattern is just config, there's no code-level "verify this tool's
output naming" step at all — you type in whatever you observe, no adapter
class to update if your setup names things differently than someone else's.

### `Support\ExternalTranslationDetector`

- `wasExternallyTranslated(string $subtitlePath, string $filenamePattern): bool`

Detection doesn't need Bazarr's cooperation, since the file exists on the
shared media volume regardless of which tool wrote it. Two independent signals,
either is sufficient:

1. **No matching entry in Bazarr's history for that language.** Query
   `/api/movies/history` (or `/api/episodes/history`) for the media — if the
   most recent Danish record isn't a real download (`action == 1`) matching
   the current file's path, Bazarr didn't put it there.
2. **Filename matches the configured `filenamePattern` regex.**

### Remediation — what seerr-syncerr does once detected

1. Delete the translated file directly (this needs a **shared media volume
   mount**, same path your Bazarr container sees — a new requirement, since
   until now seerr-syncerr only talked to APIs, never touched media files).
2. Identify the **source** subtitle it was translated from (English, in your
   case) and blacklist that in Bazarr, then force a fresh download — so the
   external tool has a genuinely new source file to translate, not the same
   one it already rejected.
3. Ask the active adapter `isCallable()`:
   - **Yes** (`AiSubtitleTranslatorAdapter`, or a `CustomAdapter` configured
     as callable) → call `triggerRetranslate()` immediately after step 2.
     Comment + resolve the issue right away.
   - **No** (`BazarrAiTranslateAdapter`, `BazarrAutoTranslateAdapter`, or a
     non-callable `CustomAdapter`) → comment explaining a new source file was
     fetched and translation will happen on the tool's next triggered pass
     (Tautulli event, cron, whatever fires it), and **leave the issue open**
     rather than falsely resolving it — an honest "in progress" beats a
     premature "fixed."

---

## 9. Extension ideas (not in v1, but designed for)

- Handle `ISSUE_COMMENT` notifications too, so a user typing "still broken,
  try again" on an already-resolved issue re-triggers the search — reopen +
  rerun the same handler.
- Multiple Bazarr instances (4K profile), same pattern overr-syncerr used
  (`BAZARR_4K_*` env vars) — add a second `BazarrClient` instance selected by
  the media's quality profile.
- "Manual adjustment" requests, whatever overr-syncerr specifically meant by
  that — worth digging into their docs/wiki once it's back up, since I
  couldn't verify the exact scope of this one.

---

## 10. Distribution & CI

**Goal:** `docker-compose.yml` should eventually look exactly like your Radarr
entry — pull a published image, no local build step:

```yaml
services:
  seerr-syncerr:
    image: ghcr.io/bymem/seerr-syncerr:latest
    container_name: seerr-syncerr
    environment:
      - PUID=1000
      - PGID=1000
      - TZ=Europe/Copenhagen
      - PORT=8070
      - WEBUI_USERNAME=admin
      - WEBUI_PASSWORD=changeme # required — see §4.2
    volumes:
      - ./config:/config
    ports:
      - "8070:8070"
    restart: unless-stopped
```

**Live as of 2026-07-06:** `ghcr.io/bymem/seerr-syncerr:latest` (amd64+arm64)
— first GitHub Actions run succeeded and the package pulls anonymously
(confirmed via a token-scoped manifest fetch), so visibility is already public.

### Where the image lives

`lscr.io` is linuxserver.io's *own* registry — not something available to a
personal project. The direct equivalent for a GitHub-hosted project is
**GitHub Container Registry (ghcr.io)**: free, no separate account needed
beyond GitHub itself, and it's what most indie self-hosted tools use precisely
because it sits right next to the source repo. Docker Hub is the other common
option, but GHCR avoids Docker Hub's pull-rate limits and keeps
image+source+CI in one place.

**One gotcha:** a package on GHCR is **private by default** the first time
it's pushed, even from a public repo — you have to flip it to public manually
once in the package settings (Settings → Packages → seerr-syncerr → Change
visibility), or `docker pull` will 404/401 for anyone but you.

### Build pipeline (GitHub Actions)

A single workflow (`.github/workflows/docker-publish.yml`) using
`docker/build-push-action` + QEMU/buildx for multi-arch:

- **Triggers:** push to `main` (→ `:latest`), and pushing a git tag like `v1.2.0`
  (→ `:1.2.0` and updates `:latest`).
- **Architectures:** build both `linux/amd64` and `linux/arm64` in one pass —
  relevant since your Pi cluster means you might genuinely want to run this
  somewhere ARM-based too, not just your main Docker host.
- **Auth:** uses the built-in `GITHUB_TOKEN` (no separate secret needed) to
  push to `ghcr.io` — GitHub Actions has permission to publish packages under
  your own account/org automatically.

Rough shape:

```yaml
name: Build and publish
on:
  push:
    branches: [main]
    tags: ['v*']

jobs:
  build:
    runs-on: ubuntu-latest
    permissions:
      contents: read
      packages: write
    steps:
      - uses: actions/checkout@v4
      - uses: docker/setup-qemu-action@v3
      - uses: docker/setup-buildx-action@v3
      - uses: docker/login-action@v3
        with:
          registry: ghcr.io
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}
      - uses: docker/metadata-action@v5
        id: meta
        with:
          images: ghcr.io/${{ github.repository_owner }}/seerr-syncerr
          tags: |
            type=raw,value=latest,enable={{is_default_branch}}
            type=semver,pattern={{version}}
      - name: Compute app version
        id: version
        run: |
          if [ "${{ github.ref_type }}" = "tag" ]; then
            echo "value=${{ github.ref_name }}" >> "$GITHUB_OUTPUT"
          else
            echo "value=main-$(echo ${{ github.sha }} | cut -c1-7)" >> "$GITHUB_OUTPUT"
          fi
      - uses: docker/build-push-action@v6
        with:
          context: .
          platforms: linux/amd64,linux/arm64
          push: true
          tags: ${{ steps.meta.outputs.tags }}
          build-args: |
            APP_VERSION=${{ steps.version.outputs.value }}
```

### Versioning

Plain semantic versioning (`v1.0.0`, `v1.1.0`, ...) via git tags — bump on any
change to the webhook payload contract, config schema, or Bazarr endpoint
assumptions, since those are the things most likely to break someone's setup
silently. A short `CHANGELOG.md` is worth it here specifically because so
much of this project's correctness depends on external APIs (§7's Bazarr
verify-notes, §5's Seerr payload verify-note) that may shift between your
versions and your future self's memory of why something was built a certain
way.

**Version shown in the UI:** the `Compute app version` step above resolves
to the git tag on a tagged release (e.g. `v1.2.0`), or `main-<shortsha>`
(e.g. `main-a1b2c3d`) on an untagged push to `main` — passed as the
`APP_VERSION` build arg into the Dockerfile (`ARG APP_VERSION=dev` /
`ENV APP_VERSION=$APP_VERSION`, `dev` being the fallback for a plain local
`docker build .` with no build arg). Every page (login, settings, Action
Log) reads `getenv('APP_VERSION')` and shows it next to the app name, with a
link to the GitHub releases page — deliberately just *shown*, not
auto-checked against the latest release over the network. A self-hosted
tool phoning home to check for updates is a design choice worth asking
about, not a default to reach for quietly; comparing what's displayed
against the releases page is a one-click manual check instead.

### README, mirroring how *arr apps document themselves

- Quick-start `docker-compose.yml` snippet (the one at the top of this section).
- Env var table: `PUID`, `PGID`, `TZ`, `PORT` — same as any linuxserver image.
- Note that all *application* config (Seerr/Radarr/Sonarr/Bazarr
  URLs+keys, language/sync keywords) lives in the web UI, not env vars — worth
  saying explicitly since every other *arr app trains people to expect a mix
  of both.
- First-run instructions: open `http://<host>:8070`, fill in the settings
  form, copy the generated webhook URL + secret into Seerr.

### Not building (deliberately, for now)

- **Unraid Community Apps template / Portainer stack file** — nice-to-have,
  but only worth the maintenance once the core flow is proven against your
  own setup for a while. Straightforward to add later since it's just a
  metadata file pointing at the same GHCR image.

---

## 11. Open items before this is "done"

- [x] ~~Confirm Seerr's actual webhook payload shape~~ — confirmed 2026-07-06
      against real reports (movie, series, season, episode); see §5.
- [x] ~~Confirm Bazarr's API routes~~ — **re-confirmed 2026-07-08** by
      reading Bazarr's real source directly (`bazarr/api/movies/*.py`,
      `bazarr/api/episodes/*.py`, `bazarr/api/subtitles/subtitles.py`) after
      a live 500 in production showed the prior "confirmed live" entry here
      (based on a 2026-07-06 UI capture) was wrong about HTTP methods: list
      endpoints use `radarrid[]`/`seriesid[]`/`episodeid[]` bracket-array
      params (still correct); blacklist is genuinely `POST` (still correct);
      but sync and search-missing are **`PATCH`, not `POST`** — those
      `flask_restx` resources have no `post()` handler at all, or (for
      movies' search-missing) `POST` silently lands on a *different*,
      unrelated handler. Also: episodes have no bulk search-missing route,
      only a targeted single-language one (`PATCH /api/episodes/subtitles`).
      Full detail and the fix in §7's `BazarrClient`. Still open: which
      numeric history `action` code means "downloaded via search" (only
      confirmed `4` = manual upload).
- [x] ~~Confirm Seerr's issue resolve endpoint~~ — confirmed 2026-07-06 live:
      `POST /api/v1/issue/{id}/resolved` exactly as assumed; see §7's
      `SeerrClient`.
- [x] ~~Confirm how Seerr's `extra` field represents "All Episodes"~~ —
      confirmed: no special value, granularity is implicit in which keys
      `extra` contains (`Affected Season`/`Affected Episode`); see §5.
- [ ] Decide your initial `main_languages` list and whether you want any
      `language_keywords`/`sync_keywords` shortcuts configured at all (fully
      optional — can ship with empty lists and add entries later from the UI).
- [x] ~~Determine each auto-translate tool's output filename~~ — no longer a
      code task: it's the one always-present "filename pattern" config field
      (§8), filled in from observation whenever you actually set the tool up,
      not something to hardcode per adapter ahead of time.
- [ ] Add the shared media volume mount to `docker-compose.yml` (read/write,
      same path Bazarr uses) — only needed once §8's file-deletion step is built;
      the core issue→resync flow (§1–§7) never touches media files directly.
- [ ] After the first push to GHCR, flip the package visibility to public
      (§10) — easy to forget since it's a one-time manual step outside the
      Actions workflow itself.
