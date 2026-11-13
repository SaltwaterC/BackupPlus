#!/usr/bin/env bash
# Take the WordPress.org plugin directory screenshots (.wordpress-org/screenshot-N.png, numbered as the captions under
# `== Screenshots ==` in readme.txt) from the plugin package on a fresh, throwaway WordPress site:
#
#   dev/screenshots.sh [zip]    default: build/backupplus-VERSION.zip for the Version in backupplus.php
#
# The site (backupplus-screenshots, port SCREENSHOTS_PORT, default 18197) gets a backup schedule and real backups, then
# scripts/capture-wporg-screenshots.mjs drives headless Chromium through the screens. The site is removed on exit unless
# KEEP=1. CHROMIUM selects the browser, as for the browser tests.
set -Eeuo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$repo_root"
# shellcheck source=dev/package-site.sh
source dev/package-site.sh

PODMAN=${PODMAN:-podman}
port=${SCREENSHOTS_PORT:-18197}
name=backupplus-screenshots
url="http://localhost:$port"
token=backupplus-screenshots
out=$repo_root/.wordpress-org

version=$(sed -n 's/^Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' backupplus.php)
zip=${1:-build/backupplus-$version.zip}
[[ -f "$zip" ]] || { echo "No package at $zip; run make dist first." >&2; exit 1; }

work=$(mktemp -d "${TMPDIR:-/tmp}/backupplus-screenshots.XXXXXX")
# shellcheck disable=SC2317 # Invoked by the EXIT trap.
cleanup() {
	if [[ "${KEEP:-0}" == 1 ]]; then
		echo "Kept the site at $url (log in with $url/?backupplus_dev_login=$token) and the package in $work/backupplus."
		echo "Remove them with: $PODMAN rm -f $name-wordpress $name-database; $PODMAN volume rm $name-db-socket; $PODMAN network rm $name; rm -rf $work"
		return
	fi
	package_site_remove "$name"
	rm -rf "$work"
}
trap cleanup EXIT

wp() {
	"$PODMAN" exec "$name-wordpress" wp "$@"
}

unzip -q "$zip" -d "$work"
echo "Starting a throwaway WordPress site at $url with $(basename "$zip")"
package_site_start "$name" "$port" "$work/backupplus" "$token" --env WP_SITE_TITLE="Example Site"

# What a configured site looks like: no first-run tour, a schedule, retention, and a few backups to restore and download.
wp config set BACKUPPLUS_ENABLE_TOUR false --raw --type=constant >/dev/null
# The scheduled backups start at night, so that none runs while the screens are captured.
wp eval '
	update_option( "backupplus_interval", "weekly" );
	update_option( "backupplus_schedule_exact_files", 1 );
	update_option( "backupplus_starttime_files", "02:00" );
	update_option( "backupplus_startday_files", 0 );
	update_option( "backupplus_interval_database", "daily" );
	update_option( "backupplus_schedule_exact_db", 1 );
	update_option( "backupplus_starttime_db", "01:30" );
	update_option( "backupplus_retain", 4 );
	update_option( "backupplus_retain_db", 14 );
	BackupPlus_Backup_Schedule::instance()->reconcile();
' >/dev/null
for ((n = 1; n <= 3; n++)); do
	wp backupplus backup --type=all --local --porcelain >/dev/null
	sleep 1
done

mkdir -p "$out"
node scripts/capture-wporg-screenshots.mjs "$url" "$token" "$out"
