#!/usr/bin/env bash
# Install the plugin package that `make dist` builds on a fresh, throwaway WordPress site and check it as a release needs:
#
# - the plugin activates from the zip's contents alone;
# - the settings page loads without PHP errors, and every plugin script, stylesheet, image and module it refers to is in the
#   package, both minified and in debug mode (which loads the readable files);
# - a database backup runs;
# - the WordPress.org Plugin Check finds no packaging errors (its file_type check: archives, hidden, VCS or application
#   files). Its full report, code-level findings included, is written next to the zip as plugin-check-VERSION.csv.
#
#   dev/dist-check.sh [zip]    default: build/backupplus-VERSION.zip for the Version in backupplus.php
#
# The site is a separate stack (backupplus-distcheck, port DIST_CHECK_PORT, default 18198) next to the development and test
# sites, removed on exit unless KEEP=1. PLUGIN_CHECK_VERSION pins the Plugin Check release (downloaded once into .cache/).
set -Eeuo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$repo_root"
# shellcheck source=dev/package-site.sh
source dev/package-site.sh

PODMAN=${PODMAN:-podman}
PLUGIN_CHECK_VERSION=${PLUGIN_CHECK_VERSION:-2.2.0}
port=${DIST_CHECK_PORT:-18198}
name=backupplus-distcheck
url="http://localhost:$port"
token=backupplus-dist-check

version=$(sed -n 's/^Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' backupplus.php)
zip=${1:-build/backupplus-$version.zip}
[[ -f "$zip" ]] || { echo "No package at $zip; run make dist first." >&2; exit 1; }
zip=$(cd "$(dirname "$zip")" && pwd)/$(basename "$zip")
report="${zip%.zip}"
report="$(dirname "$zip")/plugin-check-${report##*/backupplus-}.csv"

work=$(mktemp -d "${TMPDIR:-/tmp}/backupplus-dist-check.XXXXXX")
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

failed=0
fail() {
	echo "FAIL: $*" >&2
	failed=1
}
wp() {
	"$PODMAN" exec "$name-wordpress" wp "$@"
}

unzip -q "$zip" -d "$work"
[[ -f "$work/backupplus/backupplus.php" ]] || { echo "$zip has no backupplus/backupplus.php" >&2; exit 1; }

plugin_check_zip=.cache/plugin-check-$PLUGIN_CHECK_VERSION.zip
if [[ ! -f "$plugin_check_zip" ]]; then
	mkdir -p .cache
	curl --fail --silent --show-error --location --output "$plugin_check_zip.part" \
		"https://downloads.wordpress.org/plugin/plugin-check.$PLUGIN_CHECK_VERSION.zip"
	mv "$plugin_check_zip.part" "$plugin_check_zip"
fi

echo "Starting a throwaway WordPress site at $url with $(basename "$zip")"
package_site_start "$name" "$port" "$work/backupplus" "$token"
if wp plugin is-active backupplus 2>/dev/null; then
	echo "ok: the plugin activates"
else
	fail "the plugin did not activate"
fi


# The settings page, and every plugin file it refers to.
check_page() {
	local mode=$1 jar=$work/cookies page=$work/page.html assets status count=0 missing=0
	rm -f "$jar"
	curl --silent --location --cookie "$jar" --cookie-jar "$jar" --output /dev/null "$url/?backupplus_dev_login=$token"
	status=$(curl --silent --cookie "$jar" --output "$page" --write-out '%{http_code}' "$url/wp-admin/options-general.php?page=backupplus")
	[[ "$status" == 200 ]] || { fail "$mode: the settings page answered HTTP $status"; return; }
	grep -q 'id="backupplus-navtab-backups"' "$page" || fail "$mode: the settings page did not render"
	if grep -qiE '<b>(Fatal error|Parse error|Warning|Notice|Deprecated)</b>' "$page"; then
		fail "$mode: PHP errors on the settings page:"
		grep -oiE '<b>(Fatal error|Parse error|Warning|Notice|Deprecated)</b>[^<]*<b>[^<]*</b>[^<]*<b>[^<]*</b>' "$page" | sed 's/<[^>]*>//g' | sort -u | head -20 >&2
	fi
	assets=$(grep -oE "$url/wp-content/plugins/backupplus/[^'\" )<>]+" "$page" | sed 's/&#038;/\&/g; s/&amp;/\&/g' | sort -u)
	while read -r asset; do
		[[ -n "$asset" ]] || continue
		count=$((count + 1))
		status=$(curl --silent --output /dev/null --write-out '%{http_code}' "$asset")
		if [[ "$status" != 200 ]]; then
			fail "$mode: HTTP $status for ${asset#"$url"/wp-content/plugins/backupplus/}"
			missing=1
		fi
	done <<<"$assets"
	((count > 0)) || fail "$mode: the settings page refers to no plugin files"
	((missing == 0 && count > 0)) && echo "ok: $mode settings page and its $count plugin files load"
}
check_page minified
wp option update backupplus_debug_mode 1 >/dev/null
check_page "debug mode"
wp option update backupplus_debug_mode 0 >/dev/null

if backup=$(wp backupplus backup --type=database --local --porcelain 2>&1) && wp backupplus list 2>/dev/null | grep -qw "$(tail -n 1 <<<"$backup")"; then
	echo "ok: a database backup runs"
else
	fail "the database backup failed: $(tail -n 5 <<<"$backup")"
fi

if "$PODMAN" exec "$name-wordpress" test -f wp-content/debug.log; then
	errors=$("$PODMAN" exec "$name-wordpress" grep -E 'PHP (Fatal|Parse|Warning|Notice|Deprecated).*/(workspace|plugins)/backupplus/' wp-content/debug.log | grep -v '/dev/wordpress/mu-plugins/' || true)
	[[ -z "$errors" ]] || { fail "PHP errors from the plugin in debug.log:"; sort -u <<<"$errors" | head -20 >&2; }
fi

# Plugin Check, which WordPress.org runs on submissions. The site cannot reach WordPress.org through every proxy, so install it from the file.
"$PODMAN" cp "$plugin_check_zip" "$name-wordpress:/tmp/plugin-check.zip"
wp plugin install /tmp/plugin-check.zip --activate >/dev/null 2>&1 || { fail "could not install Plugin Check"; exit 1; }
# Keep only result rows: with nothing to report, Plugin Check prints a "Success:" line instead.
wp plugin check backupplus --format=csv --fields=file,line,type,code,message 2>/dev/null |
	grep -E '^[^,]*,[0-9]+,(ERROR|WARNING),' | sed 's#/workspace/backupplus/##' >"$report" || true
packaging=$(wp plugin check backupplus --checks=file_type --ignore-warnings --format=csv --fields=file,type,code,message 2>/dev/null |
	grep -E '^[^,]*,ERROR,' | sed 's#/workspace/backupplus/##; s#,ERROR,#,#' || true)
echo "Plugin Check: $(grep -c ',ERROR,' "$report" || true) errors and $(grep -c ',WARNING,' "$report" || true) warnings in total; the report is $report"
cut -d, -f3,4 "$report" | sort | uniq -c | sort -rn | head -12 | sed 's/^/  /'
if [[ -n "$packaging" ]]; then
	fail "Plugin Check rejects files in the package:"
	echo "  ${packaging//$'\n'/$'\n'  }" >&2
else
	echo "ok: Plugin Check finds no packaging errors"
fi

((failed == 0)) && echo "The package passed." || echo "The package FAILED." >&2
exit "$failed"
