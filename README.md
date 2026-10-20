# SweepLine

SweepLine discovers and ranks continuous driving roads near a US ZIP code. It runs on PHP, PostgreSQL/PostGIS, NGINX, and PHP-FPM, with a responsive MapLibre interface.

**The operator installs the application and creates the SuperAdmin in the web UI. SweepLine handles ZIP coordinates, road downloads, analysis, caching, and data refreshes automatically. No manual geographic imports are required.**

## Install

On Debian 12+ with systemd, from a trusted checkout:

```bash
sudo ./setup
```

The executable `setup` entry point calls the established `setup.sh` installer. If an archive or checkout has lost executable permissions, use `sudo bash setup` (or restore permission with `chmod +x setup`).

The installer prompts:

```text
Application port [1279]:
```

Press Enter to use **1279**, or enter another available port. Updates offer the existing deployed port. An optional argument or `APP_LISTEN_PORT` remains available for unattended installations, but no port argument is required.

Installation reuses existing services, installs only missing dependencies, deploys to `/var/www/sweepline`, creates the isolated database/PostGIS extension, FPM pool/socket and NGINX site, applies versioned migrations, enables the background worker, queues automatic ZIP preparation, and checks HTTP/database health. Existing `.env`, runtime files, users, and imported data are preserved on updates. Conflicting ports and unrelated resources are refused. NGINX and FPM configurations are validated before reload; invalid replacements are restored.

### Complete first-run setup

The installer prints the application URL and a **one-time setup code**. Open the URL, enter that code, and choose your name, email, and password to create the **SuperAdmin**. Setup is locked after the first account is created; the setup code is consumed and its file removed. There are no default or shared passwords.

Sign in using that web account. Use **Manage** to create other users, enable/disable them, reset passwords, inspect data preparation, and retry failed jobs. Disabling a user or resetting their password invalidates existing sessions. Public registration is disabled. This implementation distinguishes only SuperAdmin and member; more detailed permission levels remain for later design.

### Linux service account versus web accounts

The non-login Linux account `sweepline` runs PHP and background jobs with isolated file permissions. It cannot log into SSH or the web UI and is **not** the SuperAdmin. PostgreSQL also has a separate dedicated application role. Web users live in the application database and authenticate through the web UI.

## Search and automatic data preparation

No city, ZIP code, or road region is preselected. The map opens with a global overview; your chosen ZIP code determines the search center and required road coverage.

1. Enter a US ZIP code, radius, and driving profile.
2. Optionally specify a road **longer than** a minimum and/or a **maximum road length**.
3. Submit the search. Cached coverage returns ranked roads immediately.
4. If data is missing, SweepLine queues the required areas and shows progress. The worker downloads and analyzes those areas; the page updates automatically when they are ready.

The worker initializes US ZIP coordinates from GeoNames automatically, including on a new installation. A search requiring unavailable ZIP data waits for preparation. A ZIP absent from a completed dataset produces a clear not-found message.

Missing road coverage is acquired from OpenStreetMap through bounded Overpass downloads. Half-degree areas are reused between searches; dense areas automatically split down to one-eighth degree when extract limits are exceeded. Downloads and analysis run in the dedicated worker, **never inside the HTTP search request**. Requests for the same area share one task rather than downloading again. Verified empty extracts are valid coverage and do not cause endless imports.

ZIP data refreshes approximately every 30 days. Road areas refresh when searched after 30 days. Existing active data remains in place until its replacement succeeds, and available results are shown during preparation. Temporary network/provider failures retry automatically with backoff, up to four attempts; persistent failures appear in the UI with a SuperAdmin retry action. No fabricated data is substituted. The operator does not need shell commands to import or retry routine data preparation.

A first search covering a large uncached radius can take many minutes or longer. Provider availability, rate limits, data density, bandwidth, CPU and storage determine preparation time. A self-hosted application cannot make an unavailable upstream source respond; errors are surfaced in the UI instead of reporting incomplete downloads as success. For a high-volume deployment, an administrator can configure a dedicated HTTPS Overpass endpoint rather than relying on the public service. See the [provider's resource policy](https://dev.overpass-api.de/overpass-doc/en/preface/commons.html).

### Profiles and filters

- **Twisty Roads:** sustained curves, meaningful length, and fewer intersections/controls.
- **Open Highways:** straightness, continuous stretches, suitable posted limits, and fewer interruptions, for lawful driving.
- Radius: 1–150 miles. Search distance uses indexed PostGIS geography, not a longitude/latitude bounding box.
- Optional minimum length: 0–30 miles; roads must be strictly longer.
- Optional maximum length: a positive value up to 150 miles; the upper bound is inclusive and must exceed the minimum.
- Blank bounds impose no user-specified length limit. Importer candidate-size limits still apply.
- Stops: 0–100 mapped mandatory plus uncertain controls.
- Paved-only: enabled by default; unknown surfaces do not qualify.

Candidates intersect the search radius; length and score describe the **whole continuous road stretch**, including portions outside the radius. SweepLine discovers roads rather than generating navigable routes.

## Themes

The header provides Adaptive, Light and Dark themes. **Adaptive is the default**, follows the device's color scheme, and responds to changes while the page is open. Preferences are saved in browser storage and shared across tabs. Map styling and controls follow the theme.

## Deployment configuration and dependencies

| Setting | Default / meaning |
| --- | --- |
| `APP_LISTEN_PORT` or optional installer argument | 1279 on fresh install; prompted if omitted |
| `APP_INSTALL_DIR` | `/var/www/sweepline`; safe path beneath `/var/www/` |
| `APP_DB_NAME`, `APP_DB_USER` | `sweepline`; fresh dedicated identifiers |
| `APP_DB_PASSWORD` | Randomly generated for a new role |
| `APP_INSTALL_USER` | Operator owning deployed code |
| `APP_COOKIE_SECURE` in deployed `.env` | Set to 1 when using HTTPS through a reverse proxy |
| `OSM_OVERPASS_URL` in deployed `.env` | `https://overpass-api.de/api/interpreter`; trusted server-side HTTPS provider |
| `DATA_DATABASE_BUDGET_MB` in deployed `.env` | 5120 MB application database guard |

The installer targets local PostgreSQL at 127.0.0.1:5432. It requires PHP >=8.2 with PDO/pdo_pgsql, XMLReader, SimpleXML, JSON, curl and ZipArchive. Composer supplies PSR-4 autoloading without third-party PHP libraries. It also provisions the original template's PHP modules and Debian tooling, matching PostGIS packages, NGINX, cron, and osmium-tool without adding a persistent application runtime. Existing PHP version packages are reused; global PHP settings and unrelated sites are not changed.

The deployed `.env` is authoritative. Do not create it from `.env.example` before a fresh installation; the installer generates credentials while provisioning the database. Code is operator-owned; only `var/` is writable by the application. Secrets are mode 0640. Only `public/` is web-accessible, and only its front controller executes PHP. Session cookies are HttpOnly and SameSite=Lax, login/setup attempts are rate limited, passwords are hashed, and mutations require a session CSRF token. Use HTTPS for remotely accessed credentials.

Downloads use HTTPS, bounded time/size limits and temporary files. ZIP extraction reads only `US.txt`, never arbitrary archive paths. The worker serializes jobs, checks for at least 512 MB free disk, observes the configured database budget, and caps the queue at 1024 pending areas. Automatic failed/superseded import attempts are pruned after a week and abandoned download files after a day; active datasets remain. Existing data is not deleted just because a refresh fails. These guards prevent unlimited background work on a shared server; persistent resource failures require capacity/configuration attention, which the UI reports.

## Architecture and data analysis

`public/` contains the interface and JSON front controller. `src/` contains authentication, download/queue orchestration, local spatial queries, road continuity, geometry analysis, and scoring. `bin/worker.php` processes data jobs under systemd. `database/migrations/` contains transactional versioned migrations. `config/profiles.php` centralizes scoring weights.

Imports stage OSM nodes, ways, connectivity, road candidates and scores per job. Ways are split at shared nodes and combined along unambiguous identity/class/direction/heading continuations. Residential/service roads contribute topology but are excluded from discoveries. Geographic geometry is resampled at 30 m for curvature and radius estimates; sharp grid turns, sampling artifacts and short fragments cannot arbitrarily inflate scores. Stops on side approaches do not automatically stop the main road, and unresolved controls remain uncertain.

Only a validated import atomically becomes active. Search requests read local indexed geography and cached 0–100 profile scores; 100 results and SQL execution limits bound web work. Source hashes and import dates are recorded. Completeness measures mapped attributes, not certainty every stop exists. Elevation gain/loss/grade fields exist but remain null until a real elevation source is integrated.

Original installer reference files remain in `docs/setup-template.sh` and `docs/template-README.md`. See [architecture decisions](docs/architecture.md) and [advanced development imports](docs/data-tools.md); the latter are optional diagnostic tools, not installation steps.

## API

JSON, same-origin sessions, no permissive CORS. Call `GET /api/session` to obtain session state and a CSRF token. Include `X-CSRF-Token` on POST/PATCH requests.

| Endpoint | Purpose |
| --- | --- |
| `GET /api/health` | Public database/PostGIS/schema health |
| `GET /api/profiles` | Public profile definitions |
| `GET /api/session` | Current user, CSRF token, setup state |
| `POST /api/setup` | One-time setup code, name/email/password → SuperAdmin |
| `POST /api/login`, `POST /api/logout` | Web session login/logout |
| `POST /api/search` | Authenticated road discovery and automatic preparation |
| `GET /api/zips/{zip}` | Authenticated local ZIP lookup; 202 during preparation |
| `GET /api/coverage` | Authenticated active coverage |
| `GET /api/roads/123?profile=twisty` | Authenticated road details and score dimensions |
| `GET/POST /api/admin/users` | SuperAdmin user listing/creation |
| `PATCH /api/admin/users/123` | SuperAdmin enable/disable/password reset |
| `GET /api/admin/data` | SuperAdmin worker/ZIP/task status |
| `POST /api/admin/tasks/123/retry` | SuperAdmin retry of a failed preparation task |

Search request template (replace `<US_ZIP>` with your chosen five-digit US ZIP code):

```json
{"zip":"<US_ZIP>","radius_miles":50,"profile":"twisty","min_length_miles":2,"max_length_miles":20,"max_stops":5,"paved_only":true}
```

200 means local data is ready. 202 includes `preparation` state/progress and any currently available results; poll the same search (the UI does this automatically). A terminal preparation failure is represented by `preparation.state="failed"` with a visible explanation; retry it through SuperAdmin management. Invalid inputs return 422; authentication 401; CSRF/authorization 403; absent resources 404; incorrect methods 405; setup/account conflicts 409; request/queue throttles 429; unavailable infrastructure 503. Passwords require 12–72 bytes. Initial setup locks against concurrent account creation. There is no email delivery, self-registration, forgotten-password email flow, or fine-grained role model yet.

## Operations and updates

Ordinary operation requires no import commands. SuperAdmin **Manage** shows worker heartbeat, ZIP refresh time, preparation tasks/errors, and retries. Logs are in `journalctl -u sweepline-worker` and `/var/log/sweepline/php-error.log`. The setup code is stored privately at `var/setup-code` until consumed; rerunning the installer preserves an unfinished setup code.

Back up PostgreSQL, `.env`, and runtime data according to your existing server backup policy. To upgrade, run the updated checkout's `sudo ./setup`. The installer stops its owned worker while deploying code, preserves configuration/data, runs migrations and restarts it. Interrupted background work is recovered for automatic retry. The installer is not a transactional release manager for packages/code; keep backups for application rollback.

## Development and verification

```bash
composer install
php tests/run.php
# Optional frontend checks with Node (development/CI only):
node tests/frontend.test.js
find src bin public tests -name '*.php' -print0 | xargs -0 -n1 php -l
bash -n setup
bash -n setup.sh
```

Integration tests require a **fresh disposable** PostGIS database with the extension already enabled; never use production credentials. Set database environment variables, then:

```bash
export SWEEPLINE_TEST_DATABASE=1
php tests/run.php --integration
php bin/bootstrap-app.php
php -S 127.0.0.1:8099 -t public public/index.php
# Another terminal, against the populated disposable database:
bash tests/http.sh http://127.0.0.1:8099
```

Tests cover geometry, continuity, controls, scores, length bounds, geographic planning, ZIP ingestion, migration idempotence, dataset activation, queue deduplication, authentication/CSRF and admin authorization. HTTP tests create fixture users only in the disposable test database. The Linux CI workflow supplies PHP/PostGIS checks. A real acceptance run should additionally verify automatic provider downloads, preparation progress, map interaction, first-run setup and user management on Debian.

This Windows workspace has no PHP/PostgreSQL runtime. JavaScript behavior and shell syntax can be checked here, but PHP/PostGIS execution, provider integration and actual Debian deployment are not claimed verified until run in the documented environment.

## Known limitations

OSM can omit stops, surfaces, speed limits and access restrictions. Check actual access, closures and conditions before driving. No turn-by-turn navigation, live traffic, elevation source or closure guarantees are implemented. Unnamed/name-changing roads and complex junctions can fragment candidates. Automatic cells have a halo to help continuity, but cross-cell stitching and overlapping fragments still need improvement. Large searches can require many downloads; public provider quotas prevent an instant nationwide first search. No whole-US road extract is downloaded during installation.

Road data is © [OpenStreetMap contributors](https://www.openstreetmap.org/copyright), under ODbL; ZIP coordinates are from [GeoNames](https://download.geonames.org/export/zip/) under its attribution license. Basemap requests follow the [OSM tile usage policy](https://operations.osmfoundation.org/policies/tiles/). Replace the raster source in `public/assets/app.js` for higher volume. MapLibre/fonts/tiles still require external network access; local road caching does not make the basemap offline.

## License

Application code is [MIT licensed](LICENSE), copyright 2026 SweepLine contributors. External datasets, map tiles, fonts, and MapLibre retain their respective licenses.
