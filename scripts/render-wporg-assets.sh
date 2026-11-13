#!/usr/bin/env bash
# Render the WordPress.org plugin directory banners and icons from assets-src/wordpress-org/ into .wordpress-org/, which
# scripts/stage-wporg-svn.sh mirrors into the SVN assets/ folder. Screenshots (screenshot-N.png) are added there by hand.
#
# Needs rsvg-convert (librsvg) and the Liberation Sans font, which has Arial's metrics like the plugin's own logo.
set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
src=$repo_root/assets-src/wordpress-org
out=$repo_root/.wordpress-org

command -v rsvg-convert >/dev/null || { echo 'rsvg-convert is missing: install librsvg.' >&2; exit 1; }
# A missing font would silently render the banner text in a fallback face.
fc-list 'Liberation Sans:style=Bold' family | grep -q . || { echo 'The Liberation Sans font is missing.' >&2; exit 1; }

mkdir -p "$out"
rsvg-convert --width 772 --height 250 "$src/banner.svg" --output "$out/banner-772x250.png"
rsvg-convert --width 1544 --height 500 "$src/banner.svg" --output "$out/banner-1544x500.png"
rsvg-convert --width 128 --height 128 "$src/icon.svg" --output "$out/icon-128x128.png"
rsvg-convert --width 256 --height 256 "$src/icon.svg" --output "$out/icon-256x256.png"
# The directory prefers the SVG icon where browsers support it; it has no text, so it renders the same everywhere.
cp "$src/icon.svg" "$out/icon.svg"

echo "Rendered the banners and icons into $out:"
ls -1 "$out"
