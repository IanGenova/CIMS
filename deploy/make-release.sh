#!/usr/bin/env bash
# Build the files to upload to the server (see deploy/README.md).
# Run on the computer CIMS runs on now, in Git Bash, from the project folder:
#
#   bash deploy/make-release.sh               code only (for updates)
#   bash deploy/make-release.sh --with-data   code + the data bundle (first install)
#
# The data bundle holds the whole database, the encryption key and the sign-in
# keys. Treat it like a password: copy it straight to the server, then delete it.
set -euo pipefail
cd "$(dirname "$0")/.."

STAMP="$(date +%Y%m%d-%H%M)"
MYSQL_BIN="${MYSQL_BIN:-/c/wamp64/bin/mysql/mysql8.4.7/bin}"
mkdir -p dist

envval() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

# ---------------------------------------------------------------- code
CODE="dist/cims-code-$STAMP.tar.gz"
# Anchored: './*.csv' means top-level files only, not public/import's examples.
tar -czf "$CODE" --anchored --no-wildcards-match-slash \
  --exclude=./vendor --exclude=./node_modules --exclude=./tests --exclude=./dist \
  --exclude=./backup-beta2 --exclude=./brand-assets --exclude=./docs --exclude=./scripts \
  --exclude=./.claude --exclude=./.flow --exclude=./.vscode --exclude=./storage \
  --exclude=./.env --exclude='./.env.*' --exclude='./*.orig' --exclude=./.phpunit.result.cache \
  --exclude='./*.xlsx' --exclude='./*.pdf' --exclude='./*.csv' --exclude='./*.md' --exclude='./*.txt' \
  --exclude=./public/storage --exclude=./public/images/products --exclude=./public/images/avatar \
  --exclude=./public/images/brands --exclude='./bootstrap/cache/*.php' \
  .
echo "code: $CODE ($(du -h "$CODE" | cut -f1))"

[ "${1:-}" = "--with-data" ] || exit 0

# ---------------------------------------------------------------- data
DATA_DIR="$(mktemp -d)"
trap 'rm -rf "$DATA_DIR"' EXIT
DB="$(envval DB_DATABASE)"
DB_USER="$(envval DB_USERNAME)"
DB_PASS="$(envval DB_PASSWORD)"

echo "dumping database '$DB'..."
"$MYSQL_BIN/mysqldump.exe" -u"$DB_USER" ${DB_PASS:+-p"$DB_PASS"} --single-transaction --routines --triggers \
  --hex-blob --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 "$DB" | gzip > "$DATA_DIR/cims.sql.gz"
gunzip -c "$DATA_DIR/cims.sql.gz" | tail -1 | grep -q "Dump completed" || { echo "database dump did not finish"; exit 1; }

# SMTP and Xendit secrets in the database are encrypted with this key.
envval APP_KEY > "$DATA_DIR/app_key"
[ -s "$DATA_DIR/app_key" ] || { echo "APP_KEY not found in .env"; exit 1; }

mkdir -p "$DATA_DIR/storage/app" "$DATA_DIR/public"
cp storage/oauth-private.key storage/oauth-public.key "$DATA_DIR/storage/"       # sign-in tokens
[ -f storage/app/webpush-vapid.key ] && cp storage/app/webpush-vapid.key "$DATA_DIR/storage/app/"  # phone notifications
cp -r storage/app/public "$DATA_DIR/storage/app/public"                         # includes the 'installed' marker
[ -f "$DATA_DIR/storage/app/public/installed" ] || { echo "storage/app/public/installed is missing: the setup wizard would be open to the internet"; exit 1; }
cp -r public/images "$DATA_DIR/public/images"                                   # logo, product photos, avatars

# The test accounts' passwords sit in tests/fixtures: switch those accounts off.
if [ -f tests/fixtures/test-accounts.json ]; then
  node -e '
    const a = require("./tests/fixtures/test-accounts.json").accounts || [];
    const emails = a.map(x => x.email).filter(Boolean).map(e => "\x27" + e.replace(/\x27/g, "") + "\x27");
    if (emails.length) console.log("UPDATE users SET statut = 0 WHERE email IN (" + emails.join(", ") + ");");
  ' > "$DATA_DIR/disable-test-accounts.sql"
fi

DATA="dist/cims-data-$STAMP.tar.gz"
tar -czf "$DATA" -C "$DATA_DIR" .
echo "data: $DATA ($(du -h "$DATA" | cut -f1))  <- database + keys: copy to the server, then delete it here"
