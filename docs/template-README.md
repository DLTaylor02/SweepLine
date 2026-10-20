# Debian PHP deployment template

Copy `debian-php-postgres-nginx-setup.sh` into a PHP project as `setup.sh` and edit its configuration block. The project should provide:

- `composer.json`
- `.env.example`
- An idempotent PostgreSQL schema or migration entry point
- An NGINX template using `{{PORT}}` and `{{PROJECT_ROOT}}`
- A `public/` web root

Review the project hook near the bottom and add any first-user or application-specific provisioning there. Also adjust the readable, writable, and `rsync` exclusion lists for the project’s directory layout.

The template intentionally deploys to `/var/www/<app-slug>`, preserves the deployed `.env` and runtime data on updates, reuses existing Debian services, installs only missing packages, and never resets an existing PostgreSQL role password or takes over an existing database.
