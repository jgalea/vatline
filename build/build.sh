#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
STAGE="$ROOT/dist/vatright"

rm -rf "$ROOT/dist"
mkdir -p "$STAGE"

rsync -a --exclude-from="$ROOT/.distignore" --exclude='/vendor/' "$ROOT/" "$STAGE/"

if [ -f "$ROOT/package.json" ] && grep -q '"build"' "$ROOT/package.json"; then
	(cd "$ROOT" && npm run build)
	mkdir -p "$STAGE/build"
	cp -R "$ROOT"/build/*.js "$ROOT"/build/*.asset.php "$STAGE/build/" 2>/dev/null || true
fi

(cd "$STAGE" && composer install --no-dev --classmap-authoritative --no-interaction --quiet && rm -f composer.lock)

for required in templates build/checkout-block.js build/checkout-block.asset.php data/rates.json languages vendor/autoload.php; do
	[ -e "$STAGE/$required" ] || { echo "missing from package: $required" >&2; exit 1; }
done
for excluded in tests e2e node_modules bin package.json playwright.config.ts phpcs.xml.dist phpstan.neon.dist phpunit.xml.dist .wp-env.json .distignore .gitignore composer.lock; do
	[ ! -e "$STAGE/$excluded" ] || { echo "dev file in package: $excluded" >&2; exit 1; }
done

(cd "$ROOT/dist" && zip -qr vatright.zip vatright)
echo "$ROOT/dist/vatright.zip"
