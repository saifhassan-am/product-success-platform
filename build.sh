#!/usr/bin/env bash
# Rebuilds the GitHub Pages site in docs/ from SPEC.md, screenshots/ and demo/.
# Needs PHP 8.1 or newer (with pdo_sqlite) and pandoc 3.
#
#   ./build.sh
#
# The demo data is re-seeded into a throwaway database each time, so dates stay
# relative to the day you build. Commit docs/ afterwards; Pages serves it as is.
set -euo pipefail
cd "$(dirname "$0")"

OUT=docs
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

rm -rf "$OUT"
mkdir -p "$OUT/spec" "$OUT/demo/data"
touch "$OUT/.nojekyll"

# Overview page and shared files
cp site/index.html site/site.css site/favicon.svg site/console.jpg "$OUT/"

# Spec
php site/prepare-spec.php SPEC.md > "$TMP/spec.md"
pandoc "$TMP/spec.md" \
  --from gfm+implicit_figures --to html5 --standalone \
  --toc --toc-depth=3 \
  --template site/spec-template.html \
  --lua-filter site/spec.lua \
  --output "$OUT/spec/index.html"
# Success criteria are a checklist to read, not a form to fill in.
php -r '$f = $argv[1]; file_put_contents($f, str_replace("<input type=\"checkbox\" />", "<input type=\"checkbox\" disabled />", file_get_contents($f)));' "$OUT/spec/index.html"
cp -R screenshots "$OUT/spec/"

# Demo: seed, export to JSON, and the browser-only console
PSP_DB="$TMP/demo.sqlite" php demo/bin/seed.php > /dev/null
PSP_DB="$TMP/demo.sqlite" php demo/bin/export-static.php "$OUT/demo/data"
cp demo/public/console/app.css demo/public/console/app.js demo/public/console/static-api.js "$OUT/demo/"
php site/demo-index.php demo/public/console/index.html > "$OUT/demo/index.html"

echo "Built $OUT/. Preview it with: php -S 127.0.0.1:8788 -t $OUT"
