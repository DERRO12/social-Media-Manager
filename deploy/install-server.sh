#!/usr/bin/env bash
# One-time setup of a fresh Ubuntu 22.04 / 24.04 server for SocialHub.
#
# Run as root on the server:
#   bash install-server.sh yourdomain.com
#
# Installs: Nginx, PHP 8.3, MySQL, Composer, Node.js, Supervisor (queue worker), cron (scheduler),
# downloads the app from GitHub, creates the database and .env, and starts everything.
# Safe to run again: it skips what is already done.
set -euo pipefail

DOMAIN="${1:?Usage: bash install-server.sh yourdomain.com [git-repo-url]}"
REPO="${2:-https://github.com/DERRO12/social-Media-Manager.git}"
APP_DIR=/var/www/socialhub
PHP=8.3

[ "$(id -u)" -eq 0 ] || { echo "Please run as root: type 'sudo -i' first."; exit 1; }
. /etc/os-release
[ "$ID" = "ubuntu" ] || { echo "This script supports Ubuntu only (found: $PRETTY_NAME)."; exit 1; }
export DEBIAN_FRONTEND=noninteractive

step() { echo; echo "==> $*"; }
artisan() { sudo -u www-data php "$APP_DIR/artisan" "$@"; }
set_env() { # set_env KEY VALUE  (replaces the line, or adds it)
    if grep -qE "^#?[[:space:]]*$1=" "$APP_DIR/.env"; then
        sed -i -E "s|^#?[[:space:]]*$1=.*|$1=$2|" "$APP_DIR/.env"
    else
        echo "$1=$2" >> "$APP_DIR/.env"
    fi
}

step "1/9 Updating the system and installing Nginx, MySQL, Supervisor, Git"
apt-get update
apt-get -y upgrade
apt-get install -y software-properties-common ca-certificates curl git unzip openssl nginx mysql-server supervisor cron

step "2/9 Installing PHP $PHP"
add-apt-repository -y ppa:ondrej/php
apt-get update
apt-get install -y php$PHP-fpm php$PHP-cli php$PHP-mysql php$PHP-mbstring php$PHP-xml php$PHP-curl \
    php$PHP-zip php$PHP-intl php$PHP-gd php$PHP-bcmath

step "3/9 Installing Composer and Node.js"
if ! command -v composer >/dev/null; then
    curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
    EXPECTED="$(curl -sS https://composer.github.io/installer.sig)"
    ACTUAL="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
    [ "$EXPECTED" = "$ACTUAL" ] || { echo "Composer installer checksum mismatch, stopping."; exit 1; }
    php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
    rm /tmp/composer-setup.php
fi
if ! command -v node >/dev/null || [ "$(node -v | cut -c2- | cut -d. -f1)" -lt 20 ]; then
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
    apt-get install -y nodejs
fi

step "4/9 Downloading SocialHub from GitHub"
mkdir -p /var/www
git config --global --add safe.directory "$APP_DIR"
if [ ! -d "$APP_DIR/.git" ]; then
    git clone "$REPO" "$APP_DIR"
else
    git -C "$APP_DIR" pull --ff-only
fi
cd "$APP_DIR"

step "5/9 Creating the database and .env (first run only)"
if [ ! -f .env ]; then
    DB_PASS="$(openssl rand -hex 16)"
    mysql <<SQL
CREATE DATABASE IF NOT EXISTS socialhub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'socialhub'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER 'socialhub'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON socialhub.* TO 'socialhub'@'localhost';
FLUSH PRIVILEGES;
SQL
    cp .env.example .env
    set_env APP_NAME SocialHub
    set_env APP_ENV production
    set_env APP_DEBUG false
    set_env APP_URL "https://$DOMAIN"
    set_env TRUSTED_PROXIES '*'
    set_env LOG_LEVEL error
    set_env DB_CONNECTION mysql
    set_env DB_HOST 127.0.0.1
    set_env DB_PORT 3306
    set_env DB_DATABASE socialhub
    set_env DB_USERNAME socialhub
    set_env DB_PASSWORD "$DB_PASS"
    set_env DB_QUEUE_RETRY_AFTER 3700
    set_env SESSION_SECURE_COOKIE true
    chmod 640 .env
    echo "Database created; its password is stored only in $APP_DIR/.env"
fi

step "6/9 Installing the app's code libraries and building its design files"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction
npm ci --no-audit --no-fund
npm run build
chown -R www-data:www-data "$APP_DIR"

step "7/9 Setting up the app (encryption key, database tables, default plans)"
grep -qE '^APP_KEY=base64:' .env || artisan key:generate --force
artisan migrate --force
artisan db:seed --force
artisan storage:link || true
artisan optimize

step "8/9 Configuring PHP, Nginx, the background worker and the scheduler"
cp deploy/php-socialhub.ini /etc/php/$PHP/fpm/conf.d/99-socialhub.ini
sed -e "s|__DOMAIN__|$DOMAIN|g" -e "s|__APP_DIR__|$APP_DIR|g" deploy/nginx-socialhub.conf > /etc/nginx/sites-available/socialhub
ln -sf /etc/nginx/sites-available/socialhub /etc/nginx/sites-enabled/socialhub
rm -f /etc/nginx/sites-enabled/default
sed -e "s|__APP_DIR__|$APP_DIR|g" deploy/supervisor-socialhub.conf > /etc/supervisor/conf.d/socialhub.conf
echo "* * * * * www-data cd $APP_DIR && php artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/socialhub
chmod 644 /etc/cron.d/socialhub

step "9/9 Starting everything"
systemctl enable --now php$PHP-fpm nginx mysql supervisor cron
nginx -t
systemctl restart php$PHP-fpm
systemctl reload nginx
supervisorctl reread
supervisorctl update
supervisorctl restart 'socialhub-worker:*' || true

echo
echo "Done. Check the app locally on the server with:  curl -sI http://127.0.0.1 | head -n 1   (expect HTTP/1.1 200 OK)"
echo "Next: create your admin account:  sudo -u www-data php $APP_DIR/artisan app:make-admin you@example.com"
echo "Then connect https://$DOMAIN with a Cloudflare Tunnel (see deploy/README.md)."
