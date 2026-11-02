#!/usr/bin/env bash
# Test actual installer helpers without modifying system cron.
set -Eeuo pipefail
project_dir="$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
eval "$(sed -n '/^cron_entry() {/,/^}/p; /^validate_cron_entry() {/,/^}/p' "$project_dir/setup.sh" | tr -d '\r')"
die() { printf 'Refused: %s\n' "$*" >&2; exit 1; }
SCHEDULE="* * * * *"
SYSTEM_USER=sweepline
APP_DIR=/var/www/sweepline
PHP_MEMORY_LIMIT=512M
SCHEDULED_SCRIPT_RELATIVE=bin/cleanup-beamng.php
SCHEDULE_LOG_RELATIVE=var/scheduled.log
command() { printf '/usr/bin/php\n'; }
scratch="$(mktemp -d)"
trap 'rm -f "$scratch/entry" "$scratch/link"; rmdir "$scratch"' EXIT
validate_cron_entry "$scratch/entry"
cron_entry > "$scratch/entry"
validate_cron_entry "$scratch/entry"
{ printf '%s\n' '# Managed by SweepLine installer'; cron_entry; } > "$scratch/entry"
SCHEDULE="*/2 * * * *"
validate_cron_entry "$scratch/entry"
printf '* * * * * root unrelated-task\n' > "$scratch/entry"
if (validate_cron_entry "$scratch/entry") >/dev/null 2>&1; then echo 'FAIL unrelated cron accepted'; exit 1; fi
ln -s "$scratch/entry" "$scratch/link"
if (validate_cron_entry "$scratch/link") >/dev/null 2>&1; then echo 'FAIL symlink accepted'; exit 1; fi
echo 'PASS: absent, legacy and marked cron entries; reject unrelated files and symlinks'
