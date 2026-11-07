# Installation and deployment

[Documentation index](README.md) | [Project overview](../README.md)

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

## SuperAdmin setup in the terminal

If no SuperAdmin exists, the installer prompts for its password and confirmation. Input is hidden. No email address is requested or required. The SuperAdmin is exempt from configurable policy; passwords retain a 1–72 UTF-8 byte input limit.

After installation, sign in to the web UI with username **superadmin** and the password you chose. There is no browser-based account claiming, setup code, or default password.

If a SuperAdmin already exists, the installer skips the password prompt and preserves its password. Upgrades assign an existing SuperAdmin the username `superadmin` without resetting credentials. Existing members receive usernames `user-<id>`; their previous email login continues to work. Web account email addresses are optional.

A first installation requires an interactive terminal to choose a password. Unattended upgrades work when the SuperAdmin already exists.

Sign in using that web account. Use **Users** to create other users, enable/disable them, and reset passwords. Use **Manage** to inspect data preparation, adjust the storage budget, and retry failed jobs. Disabling a user or resetting their password invalidates existing sessions. Public registration is disabled. Roles are SuperAdmin, Admin, and User. Existing member accounts remain Users.

## Recover a lost SuperAdmin password

From the deployed application directory, run:

```bash
sudo /var/www/sweepline/reset-superadmin
```

Enter and confirm a new password; input is hidden. The command requires server administrator access and never asks for the old web password or an email address. It changes only the existing SuperAdmin's password, re-enables that account if disabled, and invalidates its existing web sessions. Other users and application data are preserved. Passwords are hashed; no secret is passed in command-line arguments or saved in `.env`.

For a custom deployment directory, run its `reset-superadmin` file, or set `APP_INSTALL_DIR` explicitly when invoking the command from a checkout. If no SuperAdmin exists, run the installer instead. Normal `sudo ./setup.sh` reruns continue to preserve existing passwords; reset is an explicit recovery action.

## Linux service account versus web accounts

The non-login Linux account `sweepline` runs PHP and background jobs with isolated file permissions. It cannot log into SSH or the web UI and is **not** the SuperAdmin. PostgreSQL also has a separate dedicated application role. Web users live in the application database and authenticate through the web UI.

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

