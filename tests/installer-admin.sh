#!/usr/bin/env bash
# Actual installer provisioning function; PHP/database calls are stubbed.
set -Eeuo pipefail
project_dir="$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
eval "$(sed -n '/^project_provision_superadmin() {/,/^}/p' "$project_dir/setup.sh" | tr -d '\r')"
SYSTEM_USER=sweepline
PHP_CLI=(php)
mode=existing
marker="$(mktemp)"
trap 'rm -f "$marker"' EXIT
die() { printf 'Refused: %s\n' "$*" >&2; exit 1; }
info() { printf '%s\n' "$*"; }
as_user() {
    [[ "$#" == 4 && "$1" == sweepline && "$2" == php && "$3" == bin/superadmin.php ]] ||
        { echo 'Unexpected arguments or password leaked into argv' >&2; return 2; }
    case "$4" in
        --exists) case "$mode" in existing) return 0 ;; fresh) return 1 ;; *) return 2 ;; esac ;;
        --create)
            local secret
            secret="$(cat)"
            [[ "$secret" == 'installer-test-password' ]] || { echo 'Test password rejected' >&2; return 1; }
            unset secret
            printf 'created' > "$marker"
            echo 'Test SuperAdmin created without email'
            ;;
        *) return 2 ;;
    esac
}
if [[ "${1:-}" == --prompt ]]; then
    mode=fresh
    project_provision_superadmin
    [[ "$(cat "$marker")" == created ]] || exit 1
    echo 'PASS: hidden password prompt, confirmation and stdin-only provisioning'
    exit
fi
# Existing accounts must never reach read or --create, even without a terminal.
read() { echo 'Unexpected password prompt for existing account' >&2; return 1; }
project_provision_superadmin </dev/null
[[ ! -s "$marker" ]] || { echo 'Existing account was replaced' >&2; exit 1; }
mode=broken
if (project_provision_superadmin) >/dev/null 2>&1; then echo 'Database failure mistaken for absent account' >&2; exit 1; fi
mode=fresh
if (project_provision_superadmin </dev/null) >/dev/null 2>&1; then echo 'Missing account accepted without interactive input' >&2; exit 1; fi
echo 'PASS: existing SuperAdmin skips prompting; database failures and unattended missing credentials fail safely'
