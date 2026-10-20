#!/usr/bin/env bash
# Exercise the actual installer function with account tools stubbed; no host changes.
set -Eeuo pipefail
project_dir="$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
eval "$(sed -n '/^ensure_system_user() {/,/^}/p' "$project_dir/setup.sh" | tr -d '\r')"
SYSTEM_USER=sweepline
APP_NAME=SweepLine
APP_DIR=/var/www/sweepline
mode=fresh
created=0
die() { printf 'Refused: %s\n' "$*" >&2; exit 1; }
id() { [[ "$mode" != fresh && "$mode" != orphan_group ]]; }
getent() {
    case "$1" in
        passwd)
            case "$mode" in
                existing) printf 'sweepline:x:999:999:SweepLine:/var/www/sweepline:/usr/sbin/nologin\n' ;;
                login) printf 'sweepline:x:999:999:SweepLine:/var/www/sweepline:/bin/bash\n' ;;
                unrelated) printf 'sweepline:x:999:999:Another app:/other:/usr/sbin/nologin\n' ;;
                *) return 1 ;;
            esac ;;
        group) [[ "$mode" == orphan_group ]] ;;
        *) return 1 ;;
    esac
}
useradd() {
    local system=0 user_group=0 comment='' home_dir='' shell='' logins=()
    while (($#)); do
        case "$1" in
            --system) system=1; shift ;;
            --user-group) user_group=1; shift ;;
            --comment) comment="$2"; shift 2 ;;
            --home-dir) home_dir="$2"; shift 2 ;;
            --shell) shell="$2"; shift 2 ;;
            --*) printf 'Unexpected option: %s\n' "$1" >&2; return 1 ;;
            *) logins+=("$1"); shift ;;
        esac
    done
    [[ ${#logins[@]} == 1 ]] || { printf 'useradd needs one LOGIN, got %s\n' "${#logins[@]}" >&2; return 1; }
    [[ "${logins[0]}" == "$SYSTEM_USER" && "$system" == 1 && "$user_group" == 1 &&
       "$comment" == "$APP_NAME" && "$home_dir" == "$APP_DIR" && "$shell" == /usr/sbin/nologin ]] ||
        { echo 'Incorrect service account settings' >&2; return 1; }
    created=$((created+1))
}
ensure_system_user
[[ "$created" == 1 ]] || exit 1
mode=existing
ensure_system_user
[[ "$created" == 1 ]] || { echo 'Existing service user was recreated' >&2; exit 1; }
for mode in unrelated login orphan_group; do
    if (ensure_system_user) >/dev/null 2>&1; then
        printf 'Unsafe account reuse accepted: %s\n' "$mode" >&2; exit 1
    fi
done
echo 'PASS: service account arguments, idempotence, and unrelated/login/group safeguards'

