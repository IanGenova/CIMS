#!/usr/bin/env bash
# Install or update CIMS on the server (after server-setup.sh; see deploy/README.md).
#
#   First install:  sudo bash install-release.sh cims-code-XXXX.tar.gz cims-data-XXXX.tar.gz
#   Update:         sudo bash install-release.sh cims-code-XXXX.tar.gz
#
# An update replaces the program only: .env, storage/ (logs, backups, keys)
# and uploaded images stay as they are, then new migrations run.
set -euo pipefail

CODE="${1:?Usage: sudo bash install-release.sh <cims-code-*.tar.gz> [cims-data-*.tar.gz]}"
DATA="${2:-}"
APP=/var/www/cims
HERE="$(cd "$(dirname "$0")" && pwd)"
as_web() { sudo -u www-data "$@"; }

[ "$(id -u)" = 0 ] || { echo "Run with sudo."; exit 1; }
[ -f "$CODE" ] || { echo "Not found: $CODE"; exit 1; }
[ -z "$DATA" ] || [ -f "$DATA" ] || { echo "Not found: $DATA"; exit 1; }
[ -f /etc/nginx/sites-available/cims ] || { echo "Run server-setup.sh first."; exit 1; }
DOMAIN="$(awk '/server_name/ {gsub(";","",$2); print $2; exit}' /etc/nginx/sites-available/cims)"

FIRST=0
[ -f "$APP/.env" ] || FIRST=1
if [ "$FIRST" = 1 ] && [ -z "$DATA" ]; then
  echo "First install needs the data bundle too (database, APP_KEY, sign-in keys, the 'installed' marker)."
  exit 1
fi

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/code"
tar -xzf "$CODE" -C "$WORK/code"
[ -f "$WORK/code/artisan" ] || { echo "$CODE does not look like a CIMS code bundle."; exit 1; }

if [ "$FIRST" = 0 ]; then
  echo "== Maintenance mode on"
  (cd "$APP" && as_web php artisan down) || true
fi

echo "== Program files"
# Keep what belongs to this server: settings, storage, uploads, installed packages.
rsync -a --delete \
  --exclude='/.env' --exclude='/storage/' --exclude='/vendor/' --exclude='/public/storage' \
  --exclude='/public/images/' \
  "$WORK/code/" "$APP/"
# Shipped images (icons, placeholders) are updated; uploaded ones are never deleted.
rsync -a "$WORK/code/public/images/" "$APP/public/images/"
mkdir -p "$APP"/storage/{app/public,app/backup,framework/cache/data,framework/sessions,framework/views,logs} "$APP/bootstrap/cache"

if [ "$FIRST" = 1 ]; then
  echo "== Data (first install)"
  mkdir -p "$WORK/data"
  tar -xzf "$DATA" -C "$WORK/data"
  TABLES="$(mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='cims'")"
  if [ "$TABLES" != 0 ]; then
    echo "The cims database already has $TABLES tables. Refusing to overwrite it."
    exit 1
  fi
  gunzip -c "$WORK/data/cims.sql.gz" | mysql cims
  echo "   database imported ($(mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='cims'") tables)"

  cp "$WORK/data/storage/"oauth-*.key "$APP/storage/"
  [ -f "$WORK/data/storage/app/webpush-vapid.key" ] && cp "$WORK/data/storage/app/webpush-vapid.key" "$APP/storage/app/"
  rsync -a "$WORK/data/storage/app/public/" "$APP/storage/app/public/"
  rsync -a "$WORK/data/public/images/" "$APP/public/images/"

  cp "$HERE/env.production.example" "$APP/.env"
  setenv() {
    local esc; esc="$(printf '%s' "$2" | sed 's/[&|\\]/\\&/g')"
    sed -i "s|^$1=.*|$1=$esc|" "$APP/.env"
  }
  setenv APP_KEY "$(cat "$WORK/data/app_key")"
  setenv APP_URL "https://$DOMAIN"
  setenv DB_PASSWORD "$(cat /root/cims-db-password)"

  if [ -s "$WORK/data/disable-test-accounts.sql" ]; then
    mysql cims < "$WORK/data/disable-test-accounts.sql"
    echo "   test accounts switched off"
  fi
fi

# Without this marker the setup wizard (which can wipe the database) is open to anyone.
if [ ! -f "$APP/storage/app/public/installed" ]; then
  echo "storage/app/public/installed is missing: refusing to go live with the setup wizard open."
  exit 1
fi

echo "== Ownership"
chown -R root:root "$APP"
chown -R www-data:www-data "$APP/storage" "$APP/bootstrap/cache" "$APP/public/images"
chown root:www-data "$APP/.env"
chmod 640 "$APP/.env"
chmod 600 "$APP"/storage/oauth-*.key

echo "== PHP packages"
(cd "$APP" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --no-progress)
chown -R www-data:www-data "$APP/bootstrap/cache"

echo "== Database changes"
(cd "$APP" && as_web php artisan migrate --force)
[ -L "$APP/public/storage" ] || (cd "$APP" && php artisan storage:link)
# config:cache is deliberately not used: some code reads .env directly.
(cd "$APP" && as_web php artisan config:clear && as_web php artisan view:clear)

systemctl reload php8.1-fpm
(cd "$APP" && as_web php artisan up) || true

echo "== Check"
CODE_HTTP="$(curl -s -o /dev/null -w '%{http_code}' "https://$DOMAIN/login" || true)"
echo "   https://$DOMAIN/login -> HTTP $CODE_HTTP"
[ "$CODE_HTTP" = 200 ] || { echo "   Not 200: see $APP/storage/logs/"; exit 1; }
[ "$FIRST" = 1 ] && echo "Done. Now delete the data bundle: rm -f $DATA"
echo "CIMS is live at https://$DOMAIN"
