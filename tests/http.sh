#!/usr/bin/env bash
set -Eeuo pipefail
# Deliberately restricted to the disposable test database and a loopback HTTP server.
php "$(dirname "$0")/http.php" "${1:-http://127.0.0.1:8099}"
