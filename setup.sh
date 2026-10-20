#!/usr/bin/env bash
# Reusable Debian/Ubuntu installer for an isolated PHP, Composer, PostgreSQL,
# Nginx application. Edit Configuration and Project hooks, then run:
#   sudo ./setup   (default port 1279; press Enter at the prompt)
set -Eeuo pipefail

# Configuration
APP_NAME="SweepLine"
APP_SLUG="sweepline"
DEFAULT_PORT="1279"
MIN_PHP_VERSION="8.2.0"
DEFAULT_DB_NAME="sweepline"
DEFAULT_DB_USER="sweepline"
PHP_MEMORY_LIMIT="512M"
SESSION_COOKIE_NAME="SweepLineSession"
PUBLIC_DIR_RELATIVE="public"
NGINX_TEMPLATE_RELATIVE="config/nginx.conf.example"
SCHEMA_RELATIVE=""
WORKER_SCRIPT_RELATIVE="bin/worker.php"
SCHEDULED_SCRIPT_RELATIVE=""    # Example: bin/collect.php; empty disables it.
SCHEDULE="* * * * *"
SCHEDULE_LOG_RELATIVE="var/scheduled.log"
PRESERVED_PATHS=(.env vendor var .sweepline-deployment)
WRITABLE_PATHS=(var)

# The Nginx template must contain {{PORT}}, {{PROJECT_ROOT}}, and
# {{FPM_SOCKET}} placeholders. Application-specific work belongs in the hooks.

SOURCE_DIR="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
INSTALL_USER="${APP_INSTALL_USER:-${SUDO_USER:-$(id -un)}}"
APP_DIR="${APP_INSTALL_DIR:-/var/www/$APP_SLUG}"
PORT="${1:-${APP_LISTEN_PORT:-}}"
DB_NAME="${APP_DB_NAME:-$DEFAULT_DB_NAME}"
DB_USER="${APP_DB_USER:-$DEFAULT_DB_USER}"
SYSTEM_USER="$APP_SLUG"
DEPLOYED="${APP_DEPLOYED:-0}"
TEMP_FILES=()

die() { printf 'Error: %s\n' "$*" >&2; exit 1; }
info() { printf '\n==> %s\n' "$*"; }
cleanup() { local file; for file in "${TEMP_FILES[@]}"; do rm -f "$file"; done; }
new_temp() {
    local variable="$1" file
    file="$(mktemp)"
    TEMP_FILES+=("$file")
    printf -v "$variable" '%s' "$file"
}
as_user() {
    local user="$1"; shift
    if [[ "$(id -un)" == "$user" ]]; then "$@"
    elif [[ $EUID -eq 0 ]]; then runuser -u "$user" -- "$@"
    else sudo -u "$user" -- "$@"; fi
}
ensure_system_user() {
    if id -u "$SYSTEM_USER" >/dev/null 2>&1; then
        [[ "$(getent passwd "$SYSTEM_USER" | cut -d: -f5)" == "$APP_NAME" ]] || die "System account $SYSTEM_USER belongs to another application."
        local shell; shell="$(getent passwd "$SYSTEM_USER" | cut -d: -f7)"
        case "$shell" in */nologin|*/false) return ;; esac
        die "User '$SYSTEM_USER' is a login account. Choose another APP_SLUG."
    fi
    if getent group "$SYSTEM_USER" >/dev/null 2>&1; then
        die "Group $SYSTEM_USER already exists without the application user."
    else
        useradd --system --comment "$APP_NAME" --home-dir "$APP_DIR" --shell /usr/sbin/nologin --user-group "$SYSTEM_USER"
    fi
}

# Project hooks: customize these and keep them idempotent.
project_install() {
    [[ "$DB_HOST" == "127.0.0.1" && "$DB_PORT" == "5432" ]] || die "Automatic provisioning requires the local PostgreSQL cluster on 127.0.0.1:5432."
    [[ "$DB_NAME" =~ ^[a-zA-Z_][a-zA-Z0-9_]*$ && "$DB_USER" =~ ^[a-zA-Z_][a-zA-Z0-9_]*$ ]] || die "Invalid deployed database identifiers."
    owner="$(runuser -u postgres -- psql -tAc "SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname='$DB_NAME'")"
    [[ "$owner" == "$DB_USER" ]] || die "Application database is not owned by its dedicated role."
    marker="$(runuser -u postgres -- psql -tAc "SELECT shobj_description(oid,'pg_database') FROM pg_database WHERE datname='$DB_NAME'")"
    [[ "$marker" == "$APP_SLUG" ]] || die "Database is not marked as owned by this application."
    pg_major="$(runuser -u postgres -- psql -tAc 'SHOW server_version_num')"; pg_major="$((pg_major / 10000))"
    postgis_package="postgresql-$pg_major-postgis-3"
    if ! dpkg-query -W -f='${Status}' "$postgis_package" 2>/dev/null | grep -q 'install ok installed'; then
        apt-get update; DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "$postgis_package"
    fi
    runuser -u postgres -- psql -d "$DB_NAME" -v ON_ERROR_STOP=1 -c 'CREATE EXTENSION IF NOT EXISTS postgis'
    as_user "$SYSTEM_USER" "${PHP_CLI[@]}" bin/migrate.php
    install -d -o "$SYSTEM_USER" -g "$SYSTEM_USER" -m 0750 "$APP_DIR/var"
    as_user "$SYSTEM_USER" "${PHP_CLI[@]}" bin/bootstrap-app.php
}
project_cleanup() {
    :
    # Narrow legacy cleanup example requiring both project and runner markers:
    # new_temp old; new_temp filtered
    # if as_user "$INSTALL_USER" crontab -l > "$old" 2>/dev/null; then
    #   grep -Eiv 'my-app.*old_runner\.sh|old_runner\.sh.*my-app' "$old" > "$filtered" || true
    #   cmp -s "$old" "$filtered" || as_user "$INSTALL_USER" crontab - < "$filtered"
    # fi
}
project_verify() {
    curl --fail --silent --show-error --retry 3 --retry-delay 1 "http://127.0.0.1:$PORT/api/health" | php -r '$d=json_decode(stream_get_contents(STDIN),true); exit(($d["status"]??"")==="ok"?0:1);' || die "HTTP database/PostGIS health check failed."
}

trap cleanup EXIT
trap 'exit 130' HUP INT TERM

[[ -f /etc/debian_version ]] || die "This installer supports Debian-based systems only."
[[ "$APP_SLUG" =~ ^[a-z][a-z0-9-]*$ ]] || die "APP_SLUG must use lowercase letters, numbers, and hyphens."
[[ "$DB_NAME" =~ ^[a-zA-Z_][a-zA-Z0-9_]*$ ]] || die "APP_DB_NAME is not a valid PostgreSQL identifier."
[[ "$DB_USER" =~ ^[a-zA-Z_][a-zA-Z0-9_]*$ ]] || die "APP_DB_USER is not a valid PostgreSQL identifier."
case "$APP_DIR" in /*) ;; *) die "APP_INSTALL_DIR must be absolute." ;; esac
case "$APP_DIR" in *"'"*|*$'\n'*) die "APP_INSTALL_DIR cannot contain an apostrophe or newline." ;; esac
APP_DIR="$(realpath -m "$APP_DIR")"
case "$APP_DIR" in /|/var|/var/www) die "Refusing broad deployment directory $APP_DIR." ;; esac
[[ "$APP_DIR" == /var/www/* ]] || die "Application must be deployed beneath /var/www/."
[[ "$APP_DIR" =~ ^/[a-zA-Z0-9_./-]+$ ]] || die "Deployment path must not contain whitespace or shell/config metacharacters."

if [[ $EUID -ne 0 ]]; then
    command -v sudo >/dev/null 2>&1 || die "sudo or a root shell is required."
    info "Validating administrator access"
    exec sudo env APP_INSTALL_USER="$INSTALL_USER" APP_INSTALL_DIR="$APP_DIR" \
        APP_LISTEN_PORT="$PORT" APP_DB_NAME="$DB_NAME" APP_DB_USER="$DB_USER" \
        APP_DEPLOYED="$DEPLOYED" bash "$SOURCE_DIR/$(basename "$0")"
fi

if [[ "$DEPLOYED" != "1" ]]; then
    packages=(postgresql postgresql-contrib nginx cron curl unzip openssl rsync ca-certificates iproute2 osmium-tool)
    if command -v php >/dev/null 2>&1; then
        installed_php="$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')"
        packages+=("php$installed_php-cli" "php$installed_php-fpm" "php$installed_php-pgsql" "php$installed_php-mbstring" "php$installed_php-xml" "php$installed_php-curl" "php$installed_php-zip")
    else packages+=(php-cli php-fpm php-pgsql php-mbstring php-xml php-curl php-zip); fi
    command -v composer >/dev/null 2>&1 || packages+=(composer)
    missing=()
    for package in "${packages[@]}"; do
        dpkg-query -W -f='${Status}' "$package" 2>/dev/null | grep -q 'install ok installed' || missing+=("$package")
    done
    if ((${#missing[@]})); then
        info "Installing missing system packages: ${missing[*]}"
        apt-get update
        DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "${missing[@]}"
    else info "All required system packages are already installed"; fi

    ensure_system_user
    if [[ "$SOURCE_DIR" != "$APP_DIR" ]]; then
        case "$APP_DIR/" in "$SOURCE_DIR/"*) die "Deployment directory cannot be inside the source checkout." ;; esac
        if [[ -d "$APP_DIR" && -n "$(find "$APP_DIR" -mindepth 1 -maxdepth 1 -print -quit)" && ! -f "$APP_DIR/.sweepline-deployment" ]]; then
            die "Refusing to overwrite non-application directory $APP_DIR."
        fi
        [[ ! -f "$APP_DIR/.sweepline-deployment" || "$(cat "$APP_DIR/.sweepline-deployment")" == "$APP_SLUG" ]] || die "Deployment marker mismatch."
        if [[ -f "/etc/systemd/system/$APP_SLUG-worker.service" ]]; then
            grep -Fq "Managed by SweepLine installer" "/etc/systemd/system/$APP_SLUG-worker.service" || die "Refusing to stop an unowned worker."
            systemctl stop "$APP_SLUG-worker.service"
        fi
        info "Deploying application to $APP_DIR"
        install -d -m 0755 "$APP_DIR"
        printf '%s\n' "$APP_SLUG" > "$APP_DIR/.sweepline-deployment"
        rsync_args=(-a --delete --exclude='.git/')
        for path in "${PRESERVED_PATHS[@]}"; do rsync_args+=(--exclude="$path"); done
        rsync "${rsync_args[@]}" "$SOURCE_DIR/" "$APP_DIR/"
        [[ ! -f "$SOURCE_DIR/.env" || -f "$APP_DIR/.env" ]] || install -m 0640 "$SOURCE_DIR/.env" "$APP_DIR/.env"
        chown -R "$INSTALL_USER:$SYSTEM_USER" "$APP_DIR"
        info "Continuing installation from $APP_DIR"
        exec env APP_INSTALL_USER="$INSTALL_USER" APP_INSTALL_DIR="$APP_DIR" \
            APP_LISTEN_PORT="$PORT" APP_DB_NAME="$DB_NAME" APP_DB_USER="$DB_USER" \
            APP_DEPLOYED=1 bash "$APP_DIR/$(basename "$0")"
    fi
fi
ensure_system_user
if [[ ! -f "$APP_DIR/.sweepline-deployment" ]]; then
    [[ "$SOURCE_DIR" == "$APP_DIR" && -f "$APP_DIR/src/Importer.php" ]] || die "Missing deployment ownership marker."
    printf '%s\n' "$APP_SLUG" > "$APP_DIR/.sweepline-deployment"
fi
[[ "$(cat "$APP_DIR/.sweepline-deployment")" == "$APP_SLUG" ]] || die "Deployment marker mismatch."
for managed in "/etc/nginx/sites-available/$APP_SLUG" /etc/php/*/fpm/pool.d/"$APP_SLUG".conf "/etc/systemd/system/$APP_SLUG-worker.service"; do
    [[ ! -e "$managed" ]] || grep -Fq "Managed by SweepLine installer" "$managed" || die "Refusing to overwrite unowned configuration $managed."
done
[[ ! -e "/etc/cron.d/$APP_SLUG" ]] || die "An unexpected cron entry already uses the application name."
if [[ -e "/etc/nginx/sites-enabled/$APP_SLUG" || -L "/etc/nginx/sites-enabled/$APP_SLUG" ]]; then
    [[ -L "/etc/nginx/sites-enabled/$APP_SLUG" && "$(readlink -f "/etc/nginx/sites-enabled/$APP_SLUG")" == "/etc/nginx/sites-available/$APP_SLUG" ]] || die "Unrelated enabled site uses application name."
fi

existing_port=""
[[ ! -f "/etc/nginx/sites-available/$APP_SLUG" ]] || existing_port="$(sed -nE 's/^[[:space:]]*listen[[:space:]]+([0-9]+);.*/\1/p' "/etc/nginx/sites-available/$APP_SLUG" | head -n 1)"
if [[ -z "$PORT" ]]; then
    port_default="${existing_port:-$DEFAULT_PORT}"
    [[ ! -t 0 ]] || read -r -p "Application port [$port_default]: " PORT
    PORT="${PORT:-$port_default}"
fi
[[ "$PORT" =~ ^[0-9]+$ ]] && ((PORT >= 1 && PORT <= 65535)) || die "Port must be between 1 and 65535."
[[ "$SESSION_COOKIE_NAME" =~ ^[A-Za-z][A-Za-z0-9]*$ ]] || die "SESSION_COOKIE_NAME must contain only letters and digits and start with a letter."

command -v php >/dev/null 2>&1 || die "PHP was not installed."
command -v composer >/dev/null 2>&1 || die "Composer was not installed."
command -v psql >/dev/null 2>&1 || die "PostgreSQL client was not installed."
php -r 'exit(version_compare(PHP_VERSION, $argv[1], ">=") ? 0 : 1);' "$MIN_PHP_VERSION" || die "PHP $MIN_PHP_VERSION or newer is required."
php -r 'exit(extension_loaded("pdo_pgsql") ? 0 : 1);' || die "pdo_pgsql is not enabled."
systemctl enable --now postgresql nginx cron

php_version="$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')"
fpm_service="php${php_version}-fpm.service"
fpm_binary="$(command -v "php-fpm${php_version}" || true)"
[[ -n "$fpm_binary" ]] || die "PHP-FPM $php_version is not installed."
systemctl enable --now "$fpm_service"
PHP_CLI=(php -d memory_limit="$PHP_MEMORY_LIMIT" -d max_execution_time=0 -d max_input_time=0)
cd "$APP_DIR"

# A deployed .env is authoritative. The source .env is copied only once.
env_file="$APP_DIR/.env"
DB_HOST="${APP_DB_HOST:-127.0.0.1}"; DB_PORT="${APP_DB_PORT:-5432}"
DB_PASSWORD="${APP_DB_PASSWORD:-}"
if [[ ! -f "$env_file" ]]; then
    role_exists="$(runuser -u postgres -- psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='$DB_USER'")"
    database_exists="$(runuser -u postgres -- psql -tAc "SELECT 1 FROM pg_database WHERE datname='$DB_NAME'")"
    [[ "$database_exists" != "1" ]] || die "Database '$DB_NAME' already exists without an authoritative deployed .env. Refusing takeover."
    if [[ "$role_exists" == "1" ]]; then
        die "Role '$DB_USER' already exists without deployed credentials. Refusing takeover."
    else
        DB_PASSWORD="${DB_PASSWORD:-$(openssl rand -hex 24)}"
        runuser -u postgres -- psql -v ON_ERROR_STOP=1 -v role_name="$DB_USER" -v role_password="$DB_PASSWORD" <<'SQL'
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L', :'role_name', :'role_password') \gexec
SQL
    fi
    [[ "$database_exists" == "1" ]] || runuser -u postgres -- createdb --owner="$DB_USER" "$DB_NAME"
    runuser -u postgres -- psql -v ON_ERROR_STOP=1 -v db_name="$DB_NAME" -v app_marker="$APP_SLUG" <<'SQL'
SELECT format('COMMENT ON DATABASE %I IS %L', :'db_name', :'app_marker') \gexec
SQL
    umask 077
    printf 'DB_HOST=%s\nDB_PORT=%s\nDB_NAME=%s\nDB_USER=%s\nDB_PASSWORD=%s\nAPP_ENV=production\n' \
        "$DB_HOST" "$DB_PORT" "$DB_NAME" "$DB_USER" "$DB_PASSWORD" > "$env_file"
else
    info "Preserving existing deployed .env"
    read_env() { sed -nE "s/^$1=(.*)$/\\1/p" "$env_file" | head -n 1; }
    DB_HOST="$(read_env DB_HOST)"; DB_HOST="${DB_HOST:-127.0.0.1}"
    DB_PORT="$(read_env DB_PORT)"; DB_PORT="${DB_PORT:-5432}"
    DB_NAME="$(read_env DB_NAME)"; DB_NAME="${DB_NAME:-$DEFAULT_DB_NAME}"
    DB_USER="$(read_env DB_USER)"; DB_USER="${DB_USER:-$DEFAULT_DB_USER}"
    DB_PASSWORD="$(read_env DB_PASSWORD)"
fi
chown "$INSTALL_USER:$SYSTEM_USER" "$env_file"; chmod 0640 "$env_file"

info "Installing Composer dependencies and project data"
if [[ "$(id -un)" == "$INSTALL_USER" ]]; then COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction
else as_user "$INSTALL_USER" composer install --no-dev --optimize-autoloader --no-interaction; fi
"${PHP_CLI[@]}" -l "$APP_DIR/$PUBLIC_DIR_RELATIVE/index.php" >/dev/null
project_install

info "Configuring isolated PHP-FPM pool"
session_dir="/var/lib/$APP_SLUG/sessions"; log_dir="/var/log/$APP_SLUG"
fpm_socket="/run/php/$APP_SLUG.sock"
install -d -o "$SYSTEM_USER" -g "$SYSTEM_USER" -m 0700 "$session_dir"
install -d -o "$SYSTEM_USER" -g "$SYSTEM_USER" -m 0750 "$log_dir"
touch "$log_dir/php-error.log"; chown "$SYSTEM_USER:$SYSTEM_USER" "$log_dir/php-error.log"; chmod 0640 "$log_dir/php-error.log"
new_temp pool_tmp
cat > "$pool_tmp" <<FPM
; Managed by SweepLine installer
[$APP_SLUG]
user = $SYSTEM_USER
group = $SYSTEM_USER
listen = $fpm_socket
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 4
pm.process_idle_timeout = 15s
pm.max_requests = 250
clear_env = yes
security.limit_extensions = .php
php_admin_value[memory_limit] = $PHP_MEMORY_LIMIT
request_terminate_timeout = 25s
php_admin_value[max_execution_time] = 20
php_admin_value[max_input_time] = 0
php_admin_value[session.save_path] = $session_dir
php_admin_value[session.name] = $SESSION_COOKIE_NAME
php_admin_value[session.use_strict_mode] = 1
php_admin_value[session.gc_probability] = 1
php_admin_value[session.gc_divisor] = 100
php_admin_value[session.gc_maxlifetime] = 43200
php_admin_value[session.cookie_httponly] = 1
php_admin_value[error_log] = $log_dir/php-error.log
php_admin_flag[log_errors] = on
FPM
pool_path="/etc/php/$php_version/fpm/pool.d/$APP_SLUG.conf"
for old_pool in /etc/php/*/fpm/pool.d/"$APP_SLUG".conf; do
    [[ ! -e "$old_pool" || "$old_pool" == "$pool_path" ]] || die "Existing pool uses another PHP version; migrate that application pool explicitly before proceeding."
done
new_temp pool_backup; had_pool=0
if [[ -f "$pool_path" ]]; then cp -p "$pool_path" "$pool_backup"; had_pool=1; fi
install -m 0644 "$pool_tmp" "$pool_path"
if ! "$fpm_binary" -t; then
    if [[ "$had_pool" == 1 ]]; then install -m 0644 "$pool_backup" "$pool_path"
    else rm -f "$pool_path"; fi
    die "PHP-FPM validation failed; previous application pool restored."
fi

chown -R "$INSTALL_USER:$SYSTEM_USER" "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 0750 {} +; find "$APP_DIR" -type f -exec chmod 0640 {} +
chmod 0751 "$APP_DIR"
chmod 0750 "$APP_DIR/$(basename "$0")" "$APP_DIR/setup"
find "$APP_DIR/$PUBLIC_DIR_RELATIVE" -type d -exec chmod 0755 {} +
find "$APP_DIR/$PUBLIC_DIR_RELATIVE" -type f -exec chmod 0644 {} +
for path in "${WRITABLE_PATHS[@]}"; do
    install -d -o "$SYSTEM_USER" -g "$SYSTEM_USER" -m 0750 "$APP_DIR/$path"
    chown -R "$SYSTEM_USER:$SYSTEM_USER" "$APP_DIR/$path"
done
"$fpm_binary" -t; systemctl reload "$fpm_service"
for _ in $(seq 1 20); do [[ -S "$fpm_socket" ]] && break; sleep 0.25; done
[[ -S "$fpm_socket" ]] || die "FPM socket was not created at $fpm_socket."

info "Configuring isolated Nginx site"
for site in /etc/nginx/sites-enabled/* /etc/nginx/conf.d/*.conf; do
    [[ -e "$site" ]] || continue
    [[ "$(realpath -m "$site")" == "$(realpath -m "/etc/nginx/sites-available/$APP_SLUG")" ]] && continue
    grep -Eq "^[[:space:]]*listen[^;]*([[:space:]:])${PORT}([[:space:]]|;)" "$site" && die "Port $PORT is configured by $site."
done
if ss -H -ltn | awk -v port=":$PORT" '$4 ~ (port "$") {found=1} END {exit !found}'; then
    [[ -L "/etc/nginx/sites-enabled/$APP_SLUG" ]] && grep -Eq "^[[:space:]]*listen[^;]*([[:space:]:])${PORT}([[:space:]]|;)" "/etc/nginx/sites-available/$APP_SLUG" && ss -H -ltnp | grep -E ":${PORT}[[:space:]]" | grep -q '"nginx"' || die "Port $PORT is already in use."
fi
nginx_template="$APP_DIR/$NGINX_TEMPLATE_RELATIVE"
[[ -f "$nginx_template" ]] || die "Missing Nginx template $nginx_template."
new_temp nginx_tmp; escaped_root="$(printf '%s' "$APP_DIR" | sed 's/[&|\\]/\\&/g')"
sed -e "s|{{PORT}}|$PORT|g" -e "s|{{PROJECT_ROOT}}|$escaped_root|g" -e "s|{{FPM_SOCKET}}|$fpm_socket|g" "$nginx_template" > "$nginx_tmp"
new_temp nginx_backup; had_site=0; had_link=0
if [[ -f "/etc/nginx/sites-available/$APP_SLUG" ]]; then
    cp -p "/etc/nginx/sites-available/$APP_SLUG" "$nginx_backup"; had_site=1
fi
[[ ! -L "/etc/nginx/sites-enabled/$APP_SLUG" ]] || had_link=1
install -m 0644 "$nginx_tmp" "/etc/nginx/sites-available/$APP_SLUG"
ln -sfn "/etc/nginx/sites-available/$APP_SLUG" "/etc/nginx/sites-enabled/$APP_SLUG"
if ! nginx -t; then
    if [[ "$had_site" == 1 ]]; then install -m 0644 "$nginx_backup" "/etc/nginx/sites-available/$APP_SLUG"
    else rm -f "/etc/nginx/sites-available/$APP_SLUG"; fi
    [[ "$had_link" == 1 ]] || rm -f "/etc/nginx/sites-enabled/$APP_SLUG"
    die "NGINX validation failed; previous application site restored without reload."
fi
systemctl reload nginx

if [[ -n "$WORKER_SCRIPT_RELATIVE" ]]; then
    info "Installing background worker"
    [[ -f "$APP_DIR/$WORKER_SCRIPT_RELATIVE" ]] || die "Missing worker script $APP_DIR/$WORKER_SCRIPT_RELATIVE."
    "${PHP_CLI[@]}" -l "$APP_DIR/$WORKER_SCRIPT_RELATIVE" >/dev/null
    new_temp worker_tmp
    cat > "$worker_tmp" <<SERVICE
# Managed by SweepLine installer
[Unit]
Description=$APP_NAME background worker
After=network.target postgresql.service
[Service]
Type=simple
User=$SYSTEM_USER
Group=$SYSTEM_USER
WorkingDirectory=$APP_DIR
ExecStart=$(command -v php) -d memory_limit=$PHP_MEMORY_LIMIT -d max_execution_time=0 -d max_input_time=0 "$APP_DIR/$WORKER_SCRIPT_RELATIVE"
Restart=always
RestartSec=3
UMask=0027
NoNewPrivileges=true
PrivateTmp=true
ProtectHome=read-only
ProtectSystem=strict
SERVICE
    for path in "${WRITABLE_PATHS[@]}"; do printf 'ReadWritePaths=%s\n' "$APP_DIR/$path" >> "$worker_tmp"; done
    cat >> "$worker_tmp" <<SERVICE
[Install]
WantedBy=multi-user.target
SERVICE
    install -m 0644 "$worker_tmp" "/etc/systemd/system/$APP_SLUG-worker.service"
    systemd-analyze verify "/etc/systemd/system/$APP_SLUG-worker.service"
    systemctl daemon-reload; systemctl enable "$APP_SLUG-worker.service"; systemctl restart "$APP_SLUG-worker.service"
fi

if [[ -n "$SCHEDULED_SCRIPT_RELATIVE" ]]; then
    info "Installing scheduled task"
    [[ -f "$APP_DIR/$SCHEDULED_SCRIPT_RELATIVE" ]] || die "Missing scheduled script $APP_DIR/$SCHEDULED_SCRIPT_RELATIVE."
    "${PHP_CLI[@]}" -l "$APP_DIR/$SCHEDULED_SCRIPT_RELATIVE" >/dev/null
    schedule_log="$APP_DIR/$SCHEDULE_LOG_RELATIVE"
    install -d -o "$SYSTEM_USER" -g "$SYSTEM_USER" -m 0750 "$(dirname "$schedule_log")"
    new_temp cron_tmp
    printf '%s\n' "$SCHEDULE $SYSTEM_USER cd '$APP_DIR' && $(command -v php) -d memory_limit=$PHP_MEMORY_LIMIT -d max_execution_time=0 -d max_input_time=0 '$SCHEDULED_SCRIPT_RELATIVE' >> '$schedule_log' 2>&1" > "$cron_tmp"
    install -m 0644 "$cron_tmp" "/etc/cron.d/$APP_SLUG"
fi

info "Running cleanup and final checks"
project_cleanup
as_user "$INSTALL_USER" composer check-platform-reqs --no-dev
systemctl is-active --quiet postgresql || die "PostgreSQL is not running."
systemctl is-active --quiet "$fpm_service" || die "PHP-FPM is not running."
systemctl is-active --quiet nginx || die "Nginx is not running."
[[ -z "$SCHEDULED_SCRIPT_RELATIVE" ]] || systemctl is-active --quiet cron || die "cron is not running."
[[ -z "$WORKER_SCRIPT_RELATIVE" ]] || systemctl is-active --quiet "$APP_SLUG-worker.service" || die "Worker is not running."
project_verify

server_ip="$(hostname -I 2>/dev/null | awk '{print $1}')"; server_ip="${server_ip:-localhost}"
printf '\n%s is deployed and ready.\n' "$APP_NAME"
printf 'Application: http://%s:%s/\nDeployment: %s\nConfiguration: %s\n' "$server_ip" "$PORT" "$APP_DIR" "$env_file"

if [[ -f "$APP_DIR/var/setup-code" ]]; then
    printf '\nOpen the web UI to create your SuperAdmin. One-time setup code: %s\n' "$(cat "$APP_DIR/var/setup-code")"
fi
printf 'ZIP coordinates are prepared automatically. Searches prepare missing road coverage in the background.\n'
