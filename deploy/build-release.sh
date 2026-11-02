#!/usr/bin/env bash
# Builds a production release zip in dist/ without touching the local dev setup.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PHP="${PHP_BIN:-/Applications/MAMP/bin/php/php8.3.30/bin/php}"
COMPOSER="${COMPOSER_BIN:-/Applications/MAMP/bin/php/composer}"
STAMP="$(date +%Y%m%d-%H%M)"
WORK="$(mktemp -d)"
OUT="$ROOT/dist/waveteam-portal-$STAMP.zip"

echo "→ Building front-end assets"
(cd "$ROOT" && npm ci --silent && npm run build --silent)

echo "→ Copying application (excluding dev/secret files)"
rsync -a "$ROOT/" "$WORK/portal/" \
  --exclude .git --exclude node_modules --exclude vendor --exclude tests --exclude dist \
  --exclude .env --exclude '.env.*' --exclude phpunit.xml --exclude '.phpunit*' \
  --exclude 'storage/logs/*' --exclude 'storage/framework/cache/data/*' \
  --exclude 'storage/framework/sessions/*' --exclude 'storage/framework/views/*' \
  --exclude 'storage/app/documents/*' --exclude 'storage/app/private/*' --exclude 'storage/app/public/*' \
  --exclude 'bootstrap/cache/*.php' --exclude .DS_Store

echo "→ Installing production PHP dependencies"
(cd "$WORK/portal" && "$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress --quiet --no-scripts)

# Keep the empty storage skeleton so Laravel can write to it.
for d in storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views storage/app/private bootstrap/cache; do
  mkdir -p "$WORK/portal/$d"
done

mkdir -p "$ROOT/dist"
(cd "$WORK/portal" && zip -qr "$OUT" . -x '*.DS_Store')
rm -rf "$WORK"

echo "✓ Release: $OUT ($(du -h "$OUT" | cut -f1))"
echo "  Next: follow docs/DEPLOYMENT.md"
