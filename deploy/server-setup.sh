#!/usr/bin/env bash
# One-time setup of a fresh Ubuntu 22.04 server for CIMS (see deploy/README.md).
#
#   sudo bash server-setup.sh cims.yourshop.com you@yourshop.com
#
# Installs Nginx, MySQL 8, PHP 8.1 and Composer; creates the database and its
# user; opens only SSH and web ports; gets a free Let's Encrypt certificate
# (renewed automatically) and runs the CIMS scheduler every minute.
# Running it agrees to the Let's Encrypt subscriber agreement for that email.
# Safe to run again: steps already done are skipped.
set -euo pipefail

DOMAIN="${1:?Usage: sudo bash server-setup.sh <domain> <email-for-certificate-notices>}"
EMAIL="${2:?Usage: sudo bash server-setup.sh <domain> <email-for-certificate-notices>}"
APP=/var/www/cims
HERE="$(cd "$(dirname "$0")" && pwd)"

[ "$(id -u)" = 0 ] || { echo "Run with sudo."; exit 1; }
. /etc/os-release
[ "${VERSION_ID:-}" = "22.04" ] || { echo "Expected Ubuntu 22.04 (it ships PHP 8.1, which CIMS is tested on). Found: $PRETTY_NAME"; exit 1; }
[ -f "$HERE/nginx-cims.conf" ] || { echo "nginx-cims.conf must be next to this script."; exit 1; }

echo "== 1/7 Packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get -y -q upgrade
apt-get install -y -q nginx mysql-server composer unzip ufw certbot python3-certbot-nginx rsync \
  php8.1-fpm php8.1-cli php8.1-mysql php8.1-mbstring php8.1-xml php8.1-gd php8.1-zip \
  php8.1-bcmath php8.1-intl php8.1-curl
timedatectl set-timezone Asia/Manila

echo "== 2/7 PHP limits"
cat > /etc/php/8.1/fpm/conf.d/99-cims.ini <<'INI'
upload_max_filesize = 20M
post_max_size = 25M
memory_limit = 256M
max_execution_time = 120
expose_php = Off
INI
cp /etc/php/8.1/fpm/conf.d/99-cims.ini /etc/php/8.1/cli/conf.d/99-cims.ini
systemctl restart php8.1-fpm

echo "== 3/7 Database"
if [ ! -f /root/cims-db-password ]; then
  DBPASS="$(openssl rand -base64 32 | tr -dc 'A-Za-z0-9' | cut -c1-28)"
  mysql -e "CREATE DATABASE IF NOT EXISTS cims CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
            CREATE USER IF NOT EXISTS 'cims'@'localhost' IDENTIFIED BY '$DBPASS';
            ALTER USER 'cims'@'localhost' IDENTIFIED BY '$DBPASS';
            GRANT ALL PRIVILEGES ON cims.* TO 'cims'@'localhost';
            FLUSH PRIVILEGES;"
  umask 077
  printf '%s\n' "$DBPASS" > /root/cims-db-password
  echo "   database 'cims' and user 'cims' created; password saved in /root/cims-db-password"
else
  echo "   already set up (/root/cims-db-password exists)"
fi
# MySQL listens on this machine only (the Ubuntu default); make sure.
grep -q "^bind-address\s*=\s*127.0.0.1" /etc/mysql/mysql.conf.d/mysqld.cnf || echo "   NOTE: check bind-address in /etc/mysql/mysql.conf.d/mysqld.cnf is 127.0.0.1"

echo "== 4/7 Web server"
mkdir -p "$APP"
sed "s/__DOMAIN__/$DOMAIN/g" "$HERE/nginx-cims.conf" > /etc/nginx/sites-available/cims
ln -sf /etc/nginx/sites-available/cims /etc/nginx/sites-enabled/cims
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

echo "== 5/7 Firewall (SSH and web only)"
ufw allow OpenSSH >/dev/null
ufw allow 'Nginx Full' >/dev/null
ufw --force enable >/dev/null
ufw status | sed 's/^/   /'

echo "== 6/7 HTTPS certificate"
SERVER_IP="$(curl -s -4 https://ifconfig.me || true)"
DNS_IP="$(getent ahostsv4 "$DOMAIN" | awk 'NR==1{print $1}')"
if [ -n "$SERVER_IP" ] && [ "$SERVER_IP" != "$DNS_IP" ]; then
  echo "   $DOMAIN points to '${DNS_IP:-nothing}', but this server is $SERVER_IP."
  echo "   Fix the DNS A record, wait a few minutes, then run this script again."
  exit 1
fi
certbot --nginx -d "$DOMAIN" -m "$EMAIL" --agree-tos --redirect --non-interactive
systemctl list-timers | grep -q certbot && echo "   automatic renewal is scheduled"

echo "== 7/7 Scheduler (backups, POS-hold release, ledger posting)"
cat > /etc/cron.d/cims <<CRON
* * * * * www-data cd $APP && php artisan schedule:run >> /dev/null 2>&1
CRON
chmod 644 /etc/cron.d/cims

echo
echo "Server ready. Next: sudo bash install-release.sh <cims-code-*.tar.gz> <cims-data-*.tar.gz>"
