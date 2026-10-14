# CIMS on Hostinger shared hosting

The live site is `https://pos.naspintech.com`. It uses Hostinger Web hosting,
not a VPS. The private Laravel application is in
`~/domains/pos.naspintech.com/cims`; only its `public` files are served from
`~/domains/pos.naspintech.com/public_html`. The `cims/public` directory is a
symlink to `../public_html`, so uploads through `public_path()` reach the web
directory. `public_html/storage` links to `../cims/storage/app/public`.

The web directory's `index.php` loads Composer and Laravel from `../cims`.
Its `.htaccess` starts with `php_value newrelic.enabled 0`; this domain-level
setting is needed because Hostinger's New Relic extension rejects some PHP
code used by CIMS dependencies. For command-line Artisan, run
`php -d newrelic.enabled=0 artisan ...`. The site's `.env` and keys stay
outside `public_html`. Do not use `config:cache`: CIMS reads some `.env`
values directly.

Hostinger hPanel has a custom Cron Job with schedule `* * * * *`:

```text
/usr/bin/php -d newrelic.enabled=0 /home/u610581005/domains/pos.naspintech.com/cims/artisan schedule:run --no-ansi >> /home/u610581005/domains/pos.naspintech.com/cims/storage/logs/cron.log 2>&1
```

The scheduler heartbeat is at `cims/storage/framework/scheduler-heartbeat`.
Database backups are enabled hourly in CIMS; inspect backup status in the app
and the files in `cims/storage/app/backups`. Hostinger's own backups should
also remain enabled.

## Updating the application

1. Build a code bundle with `bash deploy/make-release.sh` on the source PC.
   It excludes the database, keys, uploads, `.env`, and `.git`.
2. Upload the bundle to a private directory on Hostinger. Extract it in a
   staging directory outside `public_html`. Supply Composer dependencies from
   a trusted local build if Composer fails on Hostinger.
3. Preserve the live `.env`, `storage`, uploaded images, and public storage
   link. Apply new code and static assets, then run
   `php -d newrelic.enabled=0 artisan migrate --force` and clear Laravel's
   configuration and view caches. Do not run `config:cache`.
4. Keep only web assets in `public_html`, patch its `index.php` to use the
   private app, and retain the New Relic setting in `.htaccess`. Check
   `https://pos.naspintech.com/login`, static assets, login, and the scheduler
   heartbeat. Make public assets readable (`644` files, `755` directories).

The VPS-only scripts in this folder require root, Nginx, and systemd and do
not apply to Hostinger shared hosting.
