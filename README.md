# SweepLine

SweepLine discovers and ranks continuous driving roads near a US ZIP code. It runs on PHP, PostgreSQL/PostGIS, NGINX, and PHP-FPM, with a responsive MapLibre interface.

**The operator installs the application and chooses the SuperAdmin password in the installer terminal. SweepLine handles ZIP coordinates, road downloads, analysis, caching, and data refreshes automatically. No manual geographic imports are required.**

## Install

On Debian 12+ with systemd, from a trusted checkout:

```bash
sudo ./setup.sh
```

`setup.sh` contains the complete installer. If an archive or checkout has lost executable permissions, restore them with `chmod +x setup.sh`, or run `sudo bash setup.sh`.

The installer prompts:

```text
Application port [1279]:
```

Press Enter to use **1279**, or enter another available port. Updates offer the existing deployed port. An optional argument or `APP_LISTEN_PORT` remains available for unattended installations, but no port argument is required.

Installation reuses existing services, installs only missing dependencies, deploys to `/var/www/sweepline`, creates the isolated database/PostGIS extension, FPM pool/socket and NGINX site, applies versioned migrations, enables the background worker, queues automatic ZIP preparation, and checks HTTP/database health. Existing `.env`, runtime files, users, and imported data are preserved on updates. Conflicting ports and unrelated resources are refused. NGINX and FPM configurations are validated before reload; invalid replacements are restored.

### SuperAdmin setup in the terminal

If no SuperAdmin exists, the installer prompts for its password and confirmation. Input is hidden. No email address is requested or required. Passwords must contain 12–72 bytes.

After installation, sign in to the web UI with username **superadmin** and the password you chose. There is no browser-based account claiming, setup code, or default password.

If a SuperAdmin already exists, the installer skips the password prompt and preserves its password. Upgrades assign an existing SuperAdmin the username `superadmin` without resetting credentials. Existing members receive usernames `user-<id>`; their previous email login continues to work. Web account email addresses are optional.

A first installation requires an interactive terminal to choose a password. Unattended upgrades work when the SuperAdmin already exists.

Sign in using that web account. Use **Manage** to create other users, enable/disable them, reset passwords, inspect data preparation, and retry failed jobs. Disabling a user or resetting their password invalidates existing sessions. Public registration is disabled. This implementation distinguishes only SuperAdmin and member; more detailed permission levels remain for later design.

### Recover a lost SuperAdmin password

From the deployed application directory, run:

```bash
sudo /var/www/sweepline/reset-superadmin
```

Enter and confirm a new password; input is hidden. The command requires server administrator access and never asks for the old web password or an email address. It changes only the existing SuperAdmin's password, re-enables that account if disabled, and invalidates its existing web sessions. Other users and application data are preserved. Passwords are hashed; no secret is passed in command-line arguments or saved in `.env`.

For a custom deployment directory, run its `reset-superadmin` file, or set `APP_INSTALL_DIR` explicitly when invoking the command from a checkout. If no SuperAdmin exists, run the installer instead. Normal `sudo ./setup.sh` reruns continue to preserve existing passwords; reset is an explicit recovery action.

### Linux service account versus web accounts

The non-login Linux account `sweepline` runs PHP and background jobs with isolated file permissions. It cannot log into SSH or the web UI and is **not** the SuperAdmin. PostgreSQL also has a separate dedicated application role. Web users live in the application database and authenticate through the web UI.

## Search and automatic data preparation

No city, ZIP code, or road region is preselected. The map opens with a global overview; your chosen ZIP code determines the search center and required road coverage.

1. Enter a US ZIP code, radius, and driving profile.
2. Optionally specify a road **longer than** a minimum and/or a **maximum road length**.
3. Submit the search. Cached coverage returns ranked roads immediately.
4. If data is missing, SweepLine queues the required areas and shows a spinner, completed-area progress bar, and current preparation message beside the search controls. A matching indicator appears over the map. Available roads are labeled as partial results while preparation continues; area progress is not a time estimate. The worker downloads and analyzes those areas; the page updates automatically when they are ready.

The worker initializes US ZIP coordinates from GeoNames automatically, including on a new installation. A search requiring unavailable ZIP data waits for preparation. A ZIP absent from a completed dataset produces a clear not-found message.

Missing road coverage is acquired from OpenStreetMap through bounded Overpass downloads. Half-degree areas are reused between searches; dense areas automatically split down to one-eighth degree when extract limits are exceeded. Downloads and analysis run in the dedicated worker, **never inside the HTTP search request**. Requests for the same area share one task rather than downloading again. Verified empty extracts are valid coverage and do not cause endless imports.

ZIP data refreshes approximately every 30 days. Road areas refresh when searched after 30 days. Existing active data remains in place until its replacement succeeds, and available results are shown during preparation. Temporary network/provider failures retry automatically with backoff, up to four attempts; persistent failures appear in the UI with a SuperAdmin retry action. No fabricated data is substituted. The operator does not need shell commands to import or retry routine data preparation.

A first search covering a large uncached radius can take many minutes or longer. Provider availability, rate limits, data density, bandwidth, CPU and storage determine preparation time. A self-hosted application cannot make an unavailable upstream source respond; errors are surfaced in the UI instead of reporting incomplete downloads as success. For a high-volume deployment, an administrator can configure a dedicated HTTPS Overpass endpoint rather than relying on the public service. See the [provider's resource policy](https://dev.overpass-api.de/overpass-doc/en/preface/commons.html).

### Profiles and filters

- **Twisty Roads:** sustained curves, meaningful length, and fewer intersections/controls.
- **Open Highways:** straightness, continuous stretches, suitable posted limits, and fewer interruptions, for lawful driving.
- Radius: whole miles from 1–50, entered directly (default 5). Search distance uses indexed PostGIS geography, not a longitude/latitude bounding box.
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

The deployed `.env` is authoritative. Do not create it from `.env.example` before a fresh installation; the installer generates credentials while provisioning the database. Code is operator-owned; only `var/` is writable by the application. Secrets are mode 0640. Only `public/` is web-accessible, and only its front controller executes PHP. Session cookies are HttpOnly and SameSite=Lax, login attempts are rate limited, passwords are hashed, and mutations require a session CSRF token. Use HTTPS for remotely accessed credentials.

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
| `GET /api/session` | Current user, CSRF token, administrator readiness |
| `POST /api/login`, `POST /api/logout` | Web session login/logout |
| `POST /api/search` | Authenticated road discovery and automatic preparation |
| `GET /api/zips/{zip}` | Authenticated local ZIP lookup; 202 during preparation |
| `GET /api/coverage` | Authenticated active coverage |
| `GET /api/roads/123?profile=twisty` | Authenticated road details and score dimensions |
| `GET/POST /api/admin/users` | SuperAdmin user listing/creation |
| `PATCH /api/admin/users/123` | SuperAdmin enable/disable/password reset |
| `GET /api/admin/data` | SuperAdmin worker/ZIP/task status |
| `POST /api/admin/tasks/123/retry` | SuperAdmin retry of a failed preparation task |

Login uses `{"username":"superadmin","password":"<YOUR_PASSWORD>"}`. SuperAdmin user creation requires a username, name and initial password; email may be omitted. The installer never passes the password in command-line arguments, writes it to `.env`, or prints it; only its hash is stored in the database.

Search request template (replace `<US_ZIP>` with your chosen five-digit US ZIP code):

```json
{"zip":"<US_ZIP>","radius_miles":50,"profile":"twisty","min_length_miles":2,"max_length_miles":20,"max_stops":1,"paved_only":true}
```

200 means local data is ready. 202 includes `preparation` state/progress and any currently available results; poll the same search (the UI does this automatically). A terminal preparation failure is represented by `preparation.state="failed"` with a visible explanation; retry it through SuperAdmin management. Invalid inputs return 422; authentication 401; CSRF/authorization 403; absent resources 404; incorrect methods 405; account conflicts 409; request/queue throttles 429; unavailable infrastructure 503. Passwords require 12–72 bytes. Terminal provisioning locks against concurrent account creation and never resets an existing administrator. There is no email delivery, self-registration, forgotten-password email flow, or fine-grained role model yet.

## Operations and updates

Ordinary operation requires no import commands. SuperAdmin **Manage** shows worker heartbeat, ZIP refresh time, preparation tasks/errors, and retries. Logs are in `journalctl -u sweepline-worker` and `/var/log/sweepline/php-error.log`. SuperAdmin provisioning is handled by the installer; rerunning it preserves an existing administrator and password.

Back up PostgreSQL, `.env`, and runtime data according to your existing server backup policy. To upgrade, run the updated checkout's `sudo ./setup.sh`. The installer stops its owned worker while deploying code, preserves configuration/data, runs migrations and restarts it. Interrupted background work is recovered for automatic retry. The installer is not a transactional release manager for packages/code; keep backups for application rollback.

## Development and verification

```bash
composer install
php tests/run.php
# Optional frontend checks with Node (development/CI only):
node tests/frontend.test.js
find src bin public tests -name '*.php' -print0 | xargs -0 -n1 php -l
bash -n setup.sh
bash tests/installer-user.sh
bash tests/installer-admin.sh
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

Tests cover geometry, continuity, controls, scores, length bounds, geographic planning, ZIP ingestion, migration idempotence, dataset activation, queue deduplication, authentication/CSRF and admin authorization. HTTP tests create fixture users only in the disposable test database. The Linux CI workflow supplies PHP/PostGIS checks. A real acceptance run should additionally verify automatic provider downloads, preparation progress, map interaction, terminal SuperAdmin provisioning and user management on Debian.

This Windows workspace has no PHP/PostgreSQL runtime. JavaScript behavior and shell syntax can be checked here, but PHP/PostGIS execution, provider integration and actual Debian deployment are not claimed verified until run in the documented environment.

## Known limitations

OSM can omit stops, surfaces, speed limits and access restrictions. Check actual access, closures and conditions before driving. No turn-by-turn navigation, live traffic, elevation source or closure guarantees are implemented. Unnamed/name-changing roads and complex junctions can fragment candidates. Automatic cells have a halo to help continuity, but cross-cell stitching and overlapping fragments still need improvement. Large searches can require many downloads; public provider quotas prevent an instant nationwide first search. No whole-US road extract is downloaded during installation.

Road data is © [OpenStreetMap contributors](https://www.openstreetmap.org/copyright), under ODbL; ZIP coordinates are from [GeoNames](https://download.geonames.org/export/zip/) under its attribution license. Basemap requests follow the [OSM tile usage policy](https://operations.osmfoundation.org/policies/tiles/). Replace the raster source in `public/assets/app.js` for higher volume. MapLibre/fonts/tiles still require external network access; local road caching does not make the basemap offline.

## License

Application code is [MIT licensed](LICENSE), copyright 2026 SweepLine contributors. External datasets, map tiles, fonts, and MapLibre retain their respective licenses.

### Managing background jobs

SuperAdmins can open **Jobs** (`/jobs`) to review preparation tasks and manual imports, worker heartbeat, progress, errors, and queue counts. Cancel queued/retrying jobs immediately or request cancellation of running downloads and analysis. Cancellation is cooperative: it is checked during downloads and processing, with a final check before activating a road dataset. A database statement already executing must finish before its next checkpoint. Existing active geographic data remains available. These jobs are shared across searches, so cancelling an area can affect other users.

Cancelled preparation tasks are removed from the queue and counts. Running tasks disappear immediately and are physically removed after worker acknowledgement. Cancellation applies only to that job attempt. A later search or an active search still requesting that area can create a fresh job with a new ID. Running cancelled attempts release their area key immediately, so replacements can queue while they stop; the single worker processes the replacement afterward. There is no Retry action for the deleted attempt. Cancellation preserves cached active data. Existing cancelled records are cleaned during upgrade and routine queue maintenance. Completed jobs cannot be cancelled. Manual imports can be cancelled here; to rerun one, submit a new import using the documented CLI workflow. The list prioritizes active work and shows up to 100 tasks and 100 manual imports. Job controls require SuperAdmin authorization and CSRF protection.

Additional endpoints: `POST /api/admin/tasks/{id}/cancel`, `POST /api/admin/tasks/{id}/retry` (failed tasks only), and `POST /api/admin/imports/{id}/cancel`.

### Pages and previous searches

The header links share the same button styling. **Manage** (`/manage`) is a dedicated SuperAdmin user-management page; all worker status, ZIP sync status, preparation progress, errors, counts, cancellation and retry controls live on **Jobs** (`/jobs`). **History** (`/history`) is available to every signed-in user and shows only their latest 100 searches across browsers. Searches made before this feature was installed cannot be recovered. Click a search title or **View results** to open the map and automatically retrieve current results with the saved filters. Reopening does not add a history entry. **Delete search** permanently removes that entry from your account; it does not cancel jobs or remove road data. History stores search parameters and timestamps, not road-result snapshots. The radius defaults to 5 whole miles (maximum 50).

`GET /api/search-history` returns the signed-in user's recent searches. The browser sends `record_history: true` for an initial `POST /api/search`, and false for background progress polling. Identical submissions within ten seconds are deduplicated. Search parameters include location information and remain in the application database, scoped to their account; older entries are removed automatically as the 100-entry limit is reached.

### Jobs storage chart

The SuperAdmin Jobs page shows a live pie chart of **SweepLine database**, **other used space**, and **free space**, refreshing with job status. The database slice uses PostgreSQL's actual database size. Disk totals combine mounted local persistent filesystems reported by Debian's `df`; repeated mounts are counted once. Memory filesystems and network mounts are excluded. Other used space includes other databases, SweepLine downloads/source/logs, unrelated files, metadata, and reserved filesystem space. Free space means space available to ordinary users. The mounted volumes and combined total appear below the chart; this is not a report of unmounted disks. A database hosted remotely cannot be combined with local disk totals, so the page shows database size and an explanation instead of a misleading pie chart. No extra database or filesystem privileges are granted.

`DELETE /api/search-history/{id}` deletes only a search owned by the signed-in user and requires CSRF protection. Missing entries and entries belonging to another account return 404. Opening history queries current cached data and prepares missing coverage automatically; history does not store old result snapshots.

The minimum and default maximum is **1 mapped stop plus uncertain control**, counted together. Road endpoints are tracked separately and do not count as mandatory stops. Missing OSM controls can still exist in reality. Reopening older searches with a zero limit uses the new minimum of 1.

The discovery page remembers your latest search in this browser tab, scoped to your signed-in account. Returning from Jobs, Manage, or History (or reloading) restores its filters, requests current results/progress, and resumes automatic updates without adding another history entry. Explicit history links take precedence. Signing out clears the remembered search. This tab-level restoration requires browser session storage; server jobs continue independently when you leave the page.

### Pick a ZIP on the map

Use **Pick on map** beside the ZIP input, then click a location. SweepLine uses the local ZIP dataset to find the nearest US ZIP center within 25 miles and fills the input. The UI shows its place/state and distance from the click. This is a nearest-center approximation, not a ZIP boundary lookup: the search still centers on the selected ZIP coordinate. Outside coverage, or while ZIP initialization is incomplete, a clear message appears and manual ZIP entry remains available. This feature makes no external reverse-geocoding requests. Authenticated endpoint: `GET /api/zips/nearest?lon=-100&lat=40` (422 invalid coordinates, 404 no nearby ZIP, 202 initial dataset preparing).

### Export a selected road

Select a road result, then choose **GPX**, **KMZ**, or **Printable PDF** in its detail panel. Exports use the full stored candidate geometry, not the simplified search-map line. GPX is a GPX 1.1 track with start/end waypoints. KMZ contains a KML road line, start/end points and road-shape notes. PDF is a downloadable, printable US Letter document with a labeled local OSM street map, start/end coordinates, distance/score/control metric cards, and geometry-based bend notes. Long instruction lists paginate automatically. Exports require a signed-in session and currently active road data.

Notes follow the exported track geometry, independently of OSM way splits: cumulative mileage, distance since the previous note, left/right bend descriptions, and estimated heading change rounded to 5 degrees. Geometry is sampled every 15 m and headings smoothed over roughly 60 m; bends below 15 degrees are omitted. Consecutive bends in opposite directions remain separate. Total mileage is calculated once from the exported track, avoiding repeated full-way lengths. These are approximate road-shape notes, not surveyed rally pace notes or junction turn instructions. They are not turn-by-turn navigation, a route from your current location, or a guarantee of access or complete mapped controls. One-way chains are oriented to the mapped legal direction; inconsistent mapped directions are refused. PDFs use locally imported OSM roads as a vector street basemap, with street labels, a north arrow and scale bar. They do not fetch external map tiles or require an image/PDF service. The overview page is followed by paginated bend-note tables with vector direction icons, bold mileage/angles and alternating rows. Helvetica regular, bold and italic fonts provide hierarchy; text uses basic Latin transliteration where available. GPX/KML preserve UTF-8 names. Elevation is not fabricated. Track imports into other apps may be displayed without navigational instructions. Road data attribution is included.

Download API: `GET /api/roads/{id}/export?format=gpx|kmz|pdf&profile=twisty|open`. Responses are authenticated attachments; unsupported formats return 422 and inactive/missing roads return 404. No additional packages or persistent services are required.

### Favorite roads

Open a road result and select **♡ Add to favorites**. The heart changes to **♥ Favorited** and can be clicked again to remove it. The dedicated **Favorites** page (`/favorites`) shows saved roads with local SVG geometry thumbnails, profile score, length, curve density, mapped/uncertain controls, speed where known, and data completeness. Each account has its own favorites (up to 200); identical geometry/profile pairs are deduplicated.

Click a card to open that selected road directly in the discovery map, highlighted and zoomed, with the original profile, saved metrics, score breakdown, search filters and export controls. Favorites store server-generated snapshots independent of transient import/candidate IDs, so reimports and removal of old data do not erase saved roads. The view clearly labels saved metrics; it is not a live road-condition report. Saved GPX/KMZ/PDF exports continue to work from the snapshot. Removing a favorite removes its saved snapshot only, not road data or background work. Account deletion cascades to its favorites.

API: `GET /api/favorites`, `POST /api/favorites` with `road_id`, `profile`, and original `search` filters; `GET /api/favorites/{id}`; `DELETE /api/favorites/{id}`; `GET /api/favorites/{id}/export?format=gpx|kmz|pdf`. All require authentication, mutations require CSRF, and every lookup/deletion is scoped to the current account. Migration 008 adds persistent favorites; rerun the installer when upgrading.

PDF map context is bounded to 1,200 nearby active OSM road segments and labeled if that limit is reached. It includes the roads already imported locally, not terrain, buildings, water polygons or live traffic. Missing surrounding data is explicitly labeled rather than replaced with a fictitious map. New favorites save map context with their road snapshot; older favorites use currently available local roads. Coordinates and the selected route are always projected together in Web Mercator. Both overview and notes include attribution and page numbers.

Favorite thumbnails use the saved local OSM street basemap with surrounding roads/labels, a blue route overlay, start/end markers and attribution. Images load lazily through the account-protected SVG endpoint `GET /api/favorites/{id}/thumbnail`. Older favorites lacking saved map context use currently imported surrounding roads. Missing local context is labeled explicitly; no external tile requests are made.

### Twisty Roads qualification

Twisty Roads now requires a curvature metric of at least 0.12, a user-selected minimum curve density (default 7 qualifying 30 m samples per mile), and a sustained curve run of at least 120 m. The other initial thresholds live in `config/profiles.php`; they are not counts of individual corners. Candidates missing these metrics are excluded. Qualification runs before result ranking/limits, so length, pavement and low interruptions cannot admit an otherwise straight road. Curvature supplies 70% of the Twisty score. Motorways remain eligible if their geometry qualifies. Open Highways retains its existing rules and weights. The road connectivity/import/surface/stop filters are unchanged.

The installer refreshes existing cached scores automatically using the centralized profile configuration; no OSM reimport is needed. After manually editing profile weights, run `sudo -u sweepline php /var/www/sweepline/bin/rescore.php` (adjust the deployment directory/account if customized). Saved Favorites keep their historical scores. The initial thresholds require field validation against actual searches; they are not calibrated specifically to any named road.

### Investigating a missing road

Open **Missing a road? Check why** below the search form, enter a road name or number, and select **Check this road**. It uses the ZIP, radius and filters currently shown in the form. The report shows imported OSM source tags, candidate lengths and mapped controls, and every failing length/surface/stop/curvature requirement. It can find a candidate by names/references on its member ways even when its displayed name differs. Unknown pavement is distinguished from confirmed pavement; missing local source ways are not automatically declared missing from OSM. Try a road name as well as a number when OSM route references may differ.

This is a read-only check of active local data. It does not relax filters, fetch external data, change scores, or start imports. Results are capped at 100 source ways and 100 candidates, and coverage/truncation are labeled. API: authenticated, CSRF-protected `POST /api/search-diagnostics` with the normal search parameters plus a `road` string (2–100 characters). Use the report to distinguish short fragments, classification/tag differences, strict filters and incomplete imports before tuning the algorithm.

Advanced filters expose **Minimum curve density**, default 7, accepting any finite number from 0 through 53.6448 (the theoretical maximum with 30 m sampling). This measures qualifying sampled curve points per mile, not the number of separate bends. It overrides the Twisty curve-density gate only; curvature quality and sustained-curve requirements still apply. Open Highways ignores this field. API parameter: `min_curve_density`. History, resumed searches and favorites preserve the chosen value; older saved searches use 7. Diagnostics use exactly the selected threshold.

Known-paved filtering requires every candidate piece to have one of the currently recognized explicit surface tags: `asphalt`, `paved`, `concrete` or `concrete:plates`. A single missing tag excludes the whole candidate. Missing tags mean unknown data, not proof of an unpaved road. Other surface values (including some physically paved finishes not yet recognized by the importer) also fail this narrow list. The missing-road report now distinguishes missing tags from values outside the accepted list. Disabling Known paved includes unknown surfaces **and** explicitly unpaved roads; the policy has not otherwise changed.

### Normalized advanced filters and surface policy

Curve density is displayed and filtered on a linear 1–10 scale: `1 + 9 × raw_samples_per_mile / 53.6448`. A score of 1 is zero qualifying samples and 10 the theoretical maximum. The new default is 2.2/10, approximately the previous raw threshold of 7; it is not a default of 7/10. Decimal thresholds are accepted. API field: `min_curve_density_score` (1–10). Legacy `min_curve_density` still accepts raw units and is converted when loading old searches. Results and Favorites display normalized values.

Advanced Twisty filters also include `min_curvature_quality` (0–1, default 0.12) and `min_sustained_curve_m` (0–50,000 meters, default 120). Open Highways ignores all three geometry gates. History, resumed searches and Favorites retain them.

`surface_filter` supports `exclude_unpaved` (default: allow unknown, reject any explicitly unpaved piece), `known_paved` (all pieces must have recognized paved tags), or `any`. Missing/unrecognized tags remain unknown; any explicitly gravel/dirt/unpaved section makes the full candidate unpaved. Paved recognition now includes chipseal, paving stones and stone paving. Legacy `paved_only: true/false` maps to known_paved/any. Migration 009 backfills existing candidates without downloading OSM again. Surface confidence remains separate from the inclusion policy.
