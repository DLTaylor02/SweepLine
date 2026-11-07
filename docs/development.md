# Development and verification

[Documentation index](README.md) | [Project overview](../README.md)

## Architecture and data analysis

`public/` contains the interface and JSON front controller. `src/` contains authentication, download/queue orchestration, local spatial queries, road continuity, geometry analysis, and scoring. `bin/worker.php` processes data jobs under systemd. `database/migrations/` contains transactional versioned migrations. `config/profiles.php` centralizes scoring weights.

Imports stage OSM nodes, ways, connectivity, road candidates and scores per job. Ways are split at shared nodes and combined along unambiguous identity/class/direction/heading continuations. Residential/service roads contribute topology but are excluded from discoveries. Geographic geometry is resampled at 30 m for curvature and radius estimates; sharp grid turns, sampling artifacts and short fragments cannot arbitrarily inflate scores. Stops on side approaches do not automatically stop the main road, and unresolved controls remain uncertain.

Only a validated import atomically becomes active. Search requests read local indexed geography and cached 0–100 profile scores; 100 results and SQL execution limits bound web work. Source hashes and import dates are recorded. Completeness measures mapped attributes, not certainty every stop exists. Elevation gain/loss/grade fields exist but remain null until a real elevation source is integrated.

Original installer reference files remain in `docs/setup-template.sh` and `docs/template-README.md`. See [architecture decisions](architecture.md) and [advanced development imports](data-tools.md); the latter are optional diagnostic tools, not installation steps.

## Development and verification

```bash
composer install
composer test
# Full archive scenarios: composer test:full
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

## Fast validation

`composer test` runs quick algorithm/security/cache checks, including generated tunnel-clearance geometry, without rebuilding every complete map archive. `composer test:full` retains all archive scenarios; `composer test:integration` adds the disposable-PostGIS tests. CI still runs the complete set. Output is condensed by suite with timings and an explicit failure summary; add `--verbose` to `php tests/run.php` for every assertion. Quick mode is also available as `php tests/run.php --quick`. On the development machine the quick path takes about eight seconds; timings depend on the host.

Validated BeamNG inputs are shared for seven days in the owner-only `var/beam-resources/` cache. Keys cover exact provider URLs/query bodies and resource types; stored size/hash and format checks reject corrupt or incomplete data. Publication is atomic and per-key locked. The existing scheduled BeamNG cleanup expires data without new services, packages or migrations. Upgrades preserve this directory under `var/`; small lock files contain no source data. Resource caching does not change completed ZIP download expiry or regional road-import refresh rules.

