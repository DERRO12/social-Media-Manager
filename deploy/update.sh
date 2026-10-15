#!/usr/bin/env bash
# Deploy the latest version from GitHub. Run as root on the server:  bash /var/www/socialhub/deploy/update.sh
set -euo pipefail

APP_DIR=/var/www/socialhub
cd "$APP_DIR"
artisan() { sudo -u www-data php "$APP_DIR/artisan" "$@"; }

artisan down --retry=15 || true
git config --global --add safe.directory "$APP_DIR"
git pull --ff-only
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction
npm ci --no-audit --no-fund
npm run build
chown -R www-data:www-data "$APP_DIR"
artisan migrate --force
artisan optimize
artisan queue:restart
artisan up
systemctl reload php8.3-fpm
echo "Updated to: $(git log -1 --pretty='%h %s')"
