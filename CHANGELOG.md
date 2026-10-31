# SweepLine changelog

## Unreleased

- Fixed dense-city imports stalling at the subdivision cutoff: smaller bounded areas now use proportional overlap, and searches automatically requeue earlier size-limit failures while preserving active data.

- Changed the KMZ path style to #FF00D5 with line width 8.

- Added explicit extremely tight bend/hairpin callouts to rally-note PDFs and other road-note exports, based on local estimated radius rather than turn angle alone.

- Included very tight turns in curvature metrics, normalized curvature-quality filtering to 1–10 with an explanatory hint, and changed sustained-curve input to feet (default 150 ft, 45.72 m). Existing active metrics are recalculated locally during installation; saved filters retain compatible units.

- Normalized curve density to 1–10 in filters/results/Favorites, added curvature-quality and sustained-curve filters, and retained legacy filter compatibility.
- Added paved/unknown/unpaved classification with migration backfill and three surface policies; the default admits unknown surfaces and excludes known unpaved sections.

- Exposed Twisty minimum curve density as an advanced filter (default 7; fractional values 0–53.6448), shared by search, diagnosis, history, resumed searches and favorites.
- Clarified pavement diagnostics to distinguish missing surface tags from surface values outside the currently recognized paved list.

- Added a missing-road diagnostic that inspects local imported ways and continuous candidates, exposes exact filter/curvature rejection reasons and source tags, and reports incomplete coverage without changing search rules.

- Kept loading spinners animated when reduced motion is reported, including remote-desktop browser sessions, by removing the spinner-specific animation override.

- Added configurable minimum winding-road eligibility before Twisty result ranking, increased curvature weight to 70%, and refreshed cached scores during installation. Motorways remain eligible; Open Highways and missing-road/continuity behavior are unchanged.

- Added labeled geographic street basemaps to Favorites thumbnails, using authenticated lazy-loaded SVGs with saved/local OSM context, highlighted routes, endpoint markers and attribution.

- Redesigned printable PDFs with an overview page, metric cards, bold typography, direction icons, paginated bend-note tables, attribution/page numbers, and a labeled local OSM street basemap with north arrow and scale. New favorites preserve surrounding-map context.

- Removed “approximately” from individual bend notes and restored the coordinate formatter required by PDF exports, fixing a missing-method error that caused HTTP 503.

- Fixed inflated export instruction mileage caused by repeated full OSM-way geometries. GPX/KMZ/PDF now use the single track for total distance and rally-style bend notes with cumulative/interval mileage, left/right descriptions, and approximate angles; existing favorite exports benefit automatically.

- Added account-private Favorites with heart toggles, geometry thumbnails and road metrics, direct map reopening, and durable road/search snapshots that survive imports and support existing export formats.

- Added selected-road GPX tracks, KMZ with KML geometry, and downloadable printable PDFs with overview sketches and ordered road-section directions, preserving mapped one-way direction and OSM attribution.

- Added map picking beside the ZIP input, backed by indexed local geographic ZIP-center lookup, distance/approximation messaging, cancellation, and unavailable-data handling.

- Set the minimum/default mapped-stop plus uncertain-control limit to 1; reopening legacy zero-limit searches uses the new minimum.

- Consolidated installation on `sudo ./setup.sh`, removed the redundant `setup` wrapper, and updated documentation and CI checks.

- Restored the latest search and live preparation progress when returning to discovery or reloading, with account-scoped tab storage, fresh polling, history-link precedence, and sign-out cleanup.

- Defaulted the mapped-stop plus uncertain-control limit to zero in the search form and API.

- Added per-entry search history deletion with ownership and CSRF checks. Saved search links now automatically open current map results after sign-in without duplicating history.

- Corrected cancellation to remove only the current attempt: later searches can queue a fresh job. Removed prior suppression markers via migration 007 and freed area keys while cancelled work stops safely.

- Cancelled jobs now disappear from queue listings/counts and are physically removed once stopped. Added upgrade/runtime cleanup for previously cancelled tasks and inactive imports, with separate suppression keys to prevent automatic recreation.

- Added a responsive, accessible Jobs storage pie chart showing SweepLine database size, other used space, and free space across mounted local persistent filesystems, with explicit scope and unavailable-data handling.

- Styled Jobs/Manage/History links consistently, made Manage a dedicated users-only page, and consolidated preparation controls on Jobs.
- Defaulted radius to 5 miles and added private, persistent previous searches with filter restoration, bounded retention, and polling deduplication.

- Replaced the radius dropdown with a whole-mile numeric input (1–50 miles, default 50), enforced by the API.

- Added a SuperAdmin Jobs page with queue counts, progress, cancellation of queued/running automatic tasks and manual imports, and explicit retry of cancelled preparation tasks. Cancellation persists across searches and preserves active data.

- Added visible search/preparation spinners, completed-area progress, and partial-result messaging with automatic updates; versioned frontend assets to refresh cached files after deployment.

- Removed the login username hint and switched light/dark interface accents, tinted surfaces, and road/radius colors from green to blue.

- Added an operator-only `reset-superadmin` command with hidden password confirmation, account reactivation, and invalidation of existing SuperAdmin sessions.

- Moved SuperAdmin creation into the installer terminal: hidden password and confirmation only when no administrator exists, fixed username `superadmin`, and no required email.
- Removed browser account claiming/setup codes; added username login, optional account emails, and a non-destructive migration preserving existing passwords.
- Added terminal prompt, existing-account preservation, and username-only login regression coverage.

- Fixed installation failing at Linux service-account creation because `useradd` received two login arguments; added account-creation and ownership-safeguard regression checks.

- Removed the locality-specific download utility and documentation; replaced the initial regional map view and ZIP placeholder with a global overview and neutral input hint.

- Fixed maximum-length SQL quoting and deduplicated exact road geometry across overlapping coverage cells.

- Added the 1279 default-port prompt and installation-driven initialization.
- Added installer-provisioned SuperAdmin accounts, web sessions/CSRF, user management, and session invalidation.
- Added automatic ZIP initialization/refresh and cached, search-driven OSM preparation with progress polling, retries, dense-area subdivision, and resource guards.
- Added SuperAdmin worker/task monitoring and retries; updated automated tests and install-and-use documentation.

- Added an optional maximum road length, inclusive upper-bound filtering, range validation, and regression tests.

- Added the MIT license and updated Composer licensing metadata.
- Made the road-length threshold visible and optional; blank searches have no user-specified minimum and supplied thresholds use a strictly longer-than comparison.
- Added adaptive (default), light, and dark themes with saved preferences, live device-theme updates, and matching map styling.

## 0.1.0 — 2026-10-19

- Implemented local ZIP/radius discovery with two configurable driving profiles, cached explanations, and geographic coverage reporting.
- Added bounded OSM XML/PBF preparation, transactional dataset activation, connectivity, continuous road candidates, uniformly sampled curvature, conservative control analysis, and profile scoring.
- Added versioned PostGIS schema/migrations and a hardened PHP import worker.
- Built responsive MapLibre discovery UI with radius overlays, ranked roads, selection, score breakdowns, loading/errors, and coverage notices.
- Adapted the original Debian installer with dedicated database/PostGIS, FPM/NGINX/worker isolation, ownership safeguards, persistent-data preservation, and health verification.
- Added unit/integration/HTTP test suites and Linux CI; documented environment verification limits, data licensing, limitations, operations, and elevation extension path.

## Project naming

- Adopted SweepLine across the frontend, application namespace, deployment resources, documentation, and tests.

- Set the default application listening port to 1279 and aligned deployment examples.
