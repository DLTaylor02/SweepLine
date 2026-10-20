#!/usr/bin/env bash
# Interactive regression using the real recovery prompt; no database or account changes.
set -Eeuo pipefail
[[ -t 0 ]] || { echo 'Run this optional prompt test in a terminal.' >&2; exit 1; }
project_dir="$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
marker="$(mktemp)"
trap 'rm -f "$marker"' EXIT
runuser() {
    [[ "$#" == 6 && "$1" == -u && "$2" == sweepline && "$3" == -- && "$4" == php &&
       "$5" == bin/superadmin.php && "$6" == --reset ]] ||
        { echo 'Unexpected arguments or password leaked into argv' >&2; return 2; }
    local secret
    secret="$(cat)"
    [[ "$secret" == 'recovery-test-password' ]] || { echo 'Test password rejected' >&2; return 1; }
    unset secret
    printf 'reset' > "$marker"
    echo 'Mock reset accepted via stdin'
}
# Enter recovery-test-password twice; mismatches or other values should retry.
eval "$(sed -n '/^trace_enabled=0/,$p' "$project_dir/reset-superadmin" | tr -d '\r')"
[[ "$(cat "$marker")" == reset ]] || exit 1
echo 'PASS: recovery confirmation, hidden input, reset mode, stdin-only password'
