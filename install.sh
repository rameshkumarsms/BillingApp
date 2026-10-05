#!/usr/bin/env bash
# Usage: ./install.sh my-billing-app   (needs PHP 8.2+, Composer, MySQL 8 or SQLite)
set -euo pipefail
APP=${1:-billing-app}
HERE="$(cd "$(dirname "$0")" && pwd)"
composer create-project laravel/laravel "$APP" "^11.0"
rsync -a --exclude install.sh --exclude README.md "$HERE"/ "$APP"/
cp "$HERE/README.md" "$APP/README.md"
cd "$APP"
php artisan install:api --no-interaction >/dev/null 2>&1 || true   # harmless if already present; we ship our own routes/api.php
rsync -a "$HERE"/routes/ routes/ && rsync -a "$HERE"/bootstrap/ bootstrap/
echo "Next: edit .env (DB_*, CACHE_STORE=redis, QUEUE_CONNECTION=redis), then:"
echo "  php artisan migrate --seed && php artisan test"
