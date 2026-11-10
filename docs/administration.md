# Administration and operations

[Documentation index](README.md) | [Project overview](../README.md)

## Operations and updates

Ordinary operation requires no import commands. SuperAdmin **Manage** shows worker heartbeat, ZIP refresh time, preparation tasks/errors, and retries. Logs are in `journalctl -u sweepline-worker` and `/var/log/sweepline/php-error.log`. SuperAdmin provisioning is handled by the installer; rerunning it preserves an existing administrator and password.

Back up PostgreSQL, `.env`, and runtime data according to your existing server backup policy. To upgrade, run the updated checkout's `sudo ./setup.sh`. The installer stops its owned worker while deploying code, preserves configuration/data, runs migrations and restarts it. Interrupted background work is recovered for automatic retry. The installer is not a transactional release manager for packages/code; keep backups for application rollback.

## Managing background jobs

SuperAdmins can open **Manage** (`/manage`) to review preparation tasks and manual imports, worker heartbeat, progress, errors, and queue counts. Cancel queued/retrying jobs immediately or request cancellation of running downloads and analysis. Cancellation is cooperative: it is checked during downloads and processing, with a final check before activating a road dataset. A database statement already executing must finish before its next checkpoint. Existing active geographic data remains available. These jobs are shared across searches, so cancelling an area can affect other users.

Cancelled preparation tasks are removed from the queue and counts. Running tasks disappear immediately and are physically removed after worker acknowledgement. Cancellation applies only to that job attempt. A later search or an active search still requesting that area can create a fresh job with a new ID. Running cancelled attempts release their area key immediately, so replacements can queue while they stop; the single worker processes the replacement afterward. There is no Retry action for the deleted attempt. Cancellation preserves cached active data. Existing cancelled records are cleaned during upgrade and routine queue maintenance. Completed jobs cannot be cancelled. Manual imports can be cancelled here; to rerun one, submit a new import using the documented CLI workflow. The list prioritizes active work and shows up to 100 tasks and 100 manual imports. Job controls require SuperAdmin authorization and CSRF protection.

Additional endpoints: `POST /api/admin/tasks/{id}/cancel`, `POST /api/admin/tasks/{id}/retry` (failed tasks only), and `POST /api/admin/imports/{id}/cancel`.

## Manage storage chart

The SuperAdmin Manage page shows a live pie chart of **SweepLine database**, **other used space**, and **free space**, refreshing with job status. The database slice uses PostgreSQL's actual database size. Disk totals combine mounted local persistent filesystems reported by Debian's `df`; repeated mounts are counted once. Memory filesystems and network mounts are excluded. Other used space includes other databases, SweepLine downloads/source/logs, unrelated files, metadata, and reserved filesystem space. Free space means space available to ordinary users. The mounted volumes and combined total appear below the chart; this is not a report of unmounted disks. A database hosted remotely cannot be combined with local disk totals, so the page shows database size and an explanation instead of a misleading pie chart. No extra database or filesystem privileges are granted.

`DELETE /api/search-history/{id}` deletes only a search owned by the signed-in user and requires CSRF protection. Missing entries and entries belonging to another account return 404. Opening history queries current cached data and prepares missing coverage automatically; history does not store old result snapshots.

The minimum and default maximum is **1 mapped stop plus uncertain control**, counted together. Road endpoints are tracked separately and do not count as mandatory stops. Missing OSM controls can still exist in reality. Reopening older searches with a zero limit uses the new minimum of 1.

The discovery page remembers your latest search in this browser tab, scoped to your signed-in account. Returning from Users, Manage, or History (or reloading) restores its filters, requests current results/progress, and resumes automatic updates without adding another history entry. Explicit history links take precedence. Signing out clears the remembered search. This tab-level restoration requires browser session storage; server jobs continue independently when you leave the page.

## Administration pages and storage budget

**Manage** (`/manage`) contains background jobs, the storage chart, and a SuperAdmin-only database budget setting (512–1,048,576 MiB). **Users** (`/users`) manages web accounts. Old `/jobs` links redirect to Manage. The saved database budget overrides `DATA_DATABASE_BUDGET_MB`; the environment value remains the initial fallback. Saving takes effect on the worker's next task without a restart. Increasing the budget requeues retry/failed tasks blocked specifically by the database budget. Lowering it preserves data and prevents new imports while over budget. Minimum free disk checks still apply. The authenticated, CSRF-protected settings API is `GET/PATCH /api/admin/storage` with integer `database_budget_mb`.

## User roles

| Role | Discovery, History, Favorites | Users | Manage (jobs/storage) |
|---|---|---|---|
| SuperAdmin | Yes | Yes, including Admin role assignment | Yes |
| Admin | Yes | Create, enable/disable and reset User accounts | No |
| User | Yes | No | No |

Only SuperAdmin can assign/revoke Admin roles or manage privileged accounts. The installer-managed SuperAdmin cannot be disabled or demoted in the web UI. Role changes invalidate the affected account's sessions. Existing `member` records are displayed as Users and retain their accounts and data. Favorites and history remain private to their owner, including thumbnails and exports; neither administrative role bypasses ownership. API authorization enforces the same permissions as the page controls.



There is exactly one SuperAdmin account, provisioned by the installer. No web account can be created or promoted to SuperAdmin. A database unique index prevents duplicates; installer reruns and credential recovery preserve the same account.

## Password policy and account password changes

SuperAdmin controls **Manage → Password policy**, persisted in `app_settings.password_policy`. Defaults are **12 characters**, **Standard**, and compromised-password screening **enabled**. Minimum length is configurable from 1–72 characters; all inputs also have a 72 UTF-8 byte maximum to avoid bcrypt truncation. Unicode characters count as characters, while composition uses uppercase A–Z, lowercase a–z, digits 0–9, and symbols (including whitespace and other Unicode characters).

- **Basic:** minimum length only.
- **Standard:** length plus any three of the four character types.
- **Strong:** length plus all four types.

Policy changes affect newly created/changed passwords only. Existing hashes continue to authenticate and are rehashed with PHP's `PASSWORD_DEFAULT` when needed. Password creation and changes use `password_hash`/`password_verify`, never reversible storage.

**SuperAdmin exemption:** the single installer-created account bypasses configured length, composition, and breach screening in both self-service and administrative resets. It retains only safe input formatting (nonempty valid UTF-8, no null bytes, at most 72 bytes), current-password verification for self-service, and confirmation. Installer provisioning and `sudo ./reset-superadmin` continue to operate locally without an external service.

**Change password** (`/password`) is available to all signed-in roles. Enter the current password, a different new password, and matching confirmation. Successful changes clear any required-change flag, rotate the session ID and CSRF token, invalidate other sessions, and return to Discovery with a one-time success/informational message.

Users displays a **Password change required** badge and explicit require/clear controls. SuperAdmin can mark Users or Admins; Admins can mark the regular Users they are authorized to manage. SuperAdmin cannot be flagged (also enforced in PostgreSQL). Administrative resets do not automatically mark an account. A flag-only update keeps the user signed in. Flagged users can access only password change/sign-out plus the session and policy metadata needed for that page. Other page requests redirect to `/password`; other authenticated APIs return 403 with `redirect: "/password"`. Background jobs continue independently.

All new-password fields show themed live requirements and a strength bar. The meter is a UX aid; the shared server-side `PasswordPolicy` service enforces the actual current settings.

**Compromised passwords:** the backend checks [Have I Been Pwned Pwned Passwords](https://haveibeenpwned.com/API/v3#PwnedPasswords) only on password submission. SHA-1 is used solely for the range lookup, not storage: only the first five hex characters leave the server, and suffixes are compared locally. Requests use HTTPS verification, response padding, a 2-second connection timeout, 5-second total timeout and 1 MiB response bound. No API key is required. Nonzero breach matches are rejected. Outages, timeouts, malformed responses, or unavailable cURL fail open with: “Password saved. Compromised-password screening was temporarily unavailable, so this password could not be checked.” An unchecked password is not described as unsafe. Passwords and complete hashes are never sent, persisted in caches, or logged.

Migration `011_password_policy.sql` preserves existing accounts, adds `must_change_password=false`, protects SuperAdmin, and seeds settings without overwriting configured values. Run `sudo ./setup.sh` to upgrade. No new persistent runtime or PHP dependency is required: PHP sessions, password APIs, JSON, PCRE and the existing cURL extension are used. Outbound HTTPS to `api.pwnedpasswords.com:443` enables screening; blocked egress produces the documented warning rather than locking out user management.

Password-related API actions use authenticated, CSRF-protected **POST**:
- `POST /api/password/change`: `current_password`, `new_password`, `confirmation`.
- `POST /api/admin/password-policy` (SuperAdmin only): `minimum_length`, `strength`, `screen_compromised`.
- `POST /api/admin/users`: existing creation fields plus optional `must_change_password`.
- `POST /api/admin/users/{id}`: authorized password/role/active/required-change updates. This replaces PATCH for account updates.
- `GET /api/password-policy`: authenticated policy metadata, also included in `/api/session`.

Run `php tests/run.php`, `node tests/frontend.test.js`, and `node tests/passwords.test.js`. Disposable PostGIS integration/HTTP tests cover persisted policies, exemptions, private-account authorization, required-change restrictions and password session rotation. Unit breach checks inject synthetic range responses and never send test passwords to HIBP.

History displays one search per row with Location, Searched, Search, Filters, and Actions columns. The main location label uses the ZIP dataset's city/place and state, with the numeric ZIP beneath it. This is the locality associated with the searched ZIP, not a separate live nearest-city lookup. Existing history is enriched from local ZIP data without downloading maps or rewriting search records. A missing ZIP locality falls back to the numeric ZIP. Rows retain automatic map opening and owner-only deletion; the table scrolls horizontally on narrow screens.

## Session lifetime

SuperAdmin controls **Manage → Session lifetime**, from 1–30 whole days, default **7 days**. The database setting `session_timeout_days` is seeded without overwriting existing configuration by migration `012_session_lifetime.sql`. Sessions use a persistent HttpOnly/SameSite cookie, retaining the existing Secure behavior under HTTPS. The deadline is measured from sign-in (or a successful own-password change), not from the last mouse click or background poll. There is no separate one-hour idle logout.

Changes apply to existing sessions on their next authenticated request: shortening may expire them; increasing updates the browser cookie deadline while the session is still valid. An already expired session cannot be restored by increasing the setting. Disabling accounts, role changes, password resets and explicit logout still invalidate access earlier. Raising the setting is SuperAdmin-only via authenticated, CSRF-protected `POST /api/admin/session-settings`; `GET` returns its current value.

Run `sudo ./setup.sh` after upgrading: SweepLine's isolated PHP-FPM pool must retain session files for the supported 30-day maximum (2,592,000 seconds), preventing the former 12-hour garbage-collection limit from deleting otherwise valid sessions. This changes only the application pool, not global PHP or other applications. Existing browser-session cookies become persistent on the next valid authenticated request; sessions already deleted or expired require one fresh sign-in.



PDF exports open in a new tab using the browser PDF viewer, where users can preview, print, or save the road-named PDF. Browsers configured to always download PDFs may honor that local preference. PDFs are generated in memory per request and are not stored as server files, so no retention cleanup job is needed. GPX and KMZ exports remain direct downloads.

## Queued exports and obsolete-import cleanup

The worker claims queued BeamNG exports, due data-preparation tasks and manual imports before pruning obsolete geographic data. Large import deletion runs only in idle time, as short resumable batches. Each statement has a transaction-local two-second deadline and a 250 ms lock timeout; busy batches roll back and defer rather than blocking queue processing. Old active datasets remain protected. Nonactive automatic imports retain the existing seven-day grace period; stopped cancelled imports can be pruned sooner once queue references are gone. Cancelled queue entries still disappear immediately and later searches can enqueue fresh attempts. Their large geographic payloads are removed gradually during idle time.

Migration 018 adds supporting indexes for candidate_segments.segment_id, data_tasks.import_job_id, regions.active_job_id and import_jobs.region_id, plus obsolete-import age lookup. PostgreSQL does not automatically create indexes on referencing foreign-key columns; repeated cascading deletion without them can require expensive scans ([PostgreSQL documentation](https://www.postgresql.org/docs/17/ddl-constraints.html#DDL-CONSTRAINTS-FK)). The installer applies these indexes automatically. The first index build can take time on a large database.

Manage shows worker activity as well as the heartbeat. Cleanup batches and deferred attempts are logged by the CLI service:
`sudo journalctl -u sweepline-worker.service -n 100 --no-pager`
The application PHP error log is for PHP-FPM web requests; CLI worker errors normally appear in the service journal.

If an older release is already executing its bulk startup DELETE, stop only the application worker, cancel that specific application cleanup, then install the updated checkout:

```bash
sudo systemctl stop sweepline-worker.service
sudo -u postgres psql -d sweepline -c "SELECT pg_cancel_backend(pid) FROM pg_stat_activity WHERE datname=current_database() AND usename='sweepline' AND state='active' AND pid<>pg_backend_pid() AND query LIKE 'DELETE FROM import_jobs j USING regions r%';"
sudo ./setup.sh
```

Use the deployment's configured database/service names if customized. Cancelling this statement rolls back that in-progress cleanup; queued jobs and active coverage remain. The installer then applies the indexes and starts the updated worker. No PostgreSQL restart or unrelated service changes are required.


## Manage Region data (0.140.0)

SuperAdmins can choose **Manage Region data** on Manage, opening `/regions`. This map shows imported coverage and queued/in-progress road areas without road-result overlays. Green means downloaded, red means queued/retrying, yellow pulses while processing; gray indicates discard work. Failed work is labeled. Select a box or filter the sidebar list by name/key/status.

**Refresh region** queues a new provider download immediately, with priority when the worker is next free. It does not interrupt an existing job. Current validated coverage stays available until the replacement validates. A region already processing cannot start a duplicate refresh. Dense-area child downloads inherit the explicit refresh request.

**Discard region** requires confirmation. Coverage is removed from discovery immediately; the existing worker cancels that region's preparation and removes its import rows in bounded transactions. Cleanup resumes after worker restarts and yields between batches so other jobs can proceed. The map reports discard progress/failure. The operation affects only the selected region: overlapping or subdivided regions remain separate. Active searches may request the discarded area again.

Storage details distinguish **estimated database row bytes** from actual **managed download file bytes**. Temporary OSM extracts normally disappear after successful imports, so file bytes can be zero while database usage is substantial. Estimates exclude shared indexes/free pages/metadata and are bounded under database load; busy estimates can be unavailable. PostgreSQL normally reuses deleted space instead of shrinking database files immediately.

Discard does not remove ZIP coordinates, user accounts, History/Favorite snapshots, BeamNG resources/exports, unrelated regions, or external/manual source files. Managed sources still referenced by another import are retained. Existing saved History results remain viewable, although actions requiring discarded live source data may become unavailable.

Migration `021_region_management.sql` adds the region-operation queue, explicit task priority and supporting indexes. Run the normal installer update to apply it and restart the worker. No new service, persistent runtime, global server setting or external API dependency is introduced.
