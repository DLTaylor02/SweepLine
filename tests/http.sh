#!/usr/bin/env bash
set -Eeuo pipefail
# Deliberately restricted to the disposable test database and a loopback HTTP server.
php -d display_errors=stderr -d log_errors=0 "$(dirname "$0")/http.php" "${1:-http://127.0.0.1:8099}"
