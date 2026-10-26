#!/usr/bin/env bash
# Make sure the isolated WordPress sites the test suite runs on exist and are healthy.
#
# Every site is a complete stack (WordPress and MariaDB containers, own volumes and network) named backupplus-test-<n>, with PHP
# listening on port 18080+<n>, so tests that change a site's options, cron schedule or files can run side by side without
# touching each other or the development site. Healthy sites are left alone; missing ones are created in parallel.
#
#   dev/test-sites.sh [count]       count defaults to $TEST_SITES, then 8
#   dev/test-sites.sh --remove      remove all test sites and their data
set -Eeuo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$repo_root"

if [[ "${1:-}" == --remove ]]; then
	for name in $(podman ps --all --format '{{.Names}}' | sed -n 's/^\(backupplus-test-[0-9]*\)-\(wordpress\|database\)$/\1/p' | sort -u); do
		DEV_NAME=$name ./dev/down.sh --volumes
	done
	exit 0
fi

count=${1:-${TEST_SITES:-8}}
logs=$(mktemp -d)
trap 'rm -rf "$logs"' EXIT

# Fresh sites show the new-install admin tour, whose overlay covers the screens the browser tests drive.
# The browser tests add rows to the table of existing backups, so every site needs one backup set, as the development site has.
configure_site() {
	podman exec "backupplus-test-$1-wordpress" sh -c 'wp config has BACKUPPLUS_ENABLE_TOUR --type=constant || wp config set BACKUPPLUS_ENABLE_TOUR false --raw --type=constant' >/dev/null
	podman exec "backupplus-test-$1-wordpress" wp eval '
		if (get_option("backupplus_backup_history")) { return; }
		$directory = WP_CONTENT_DIR . "/backupplus";
		wp_mkdir_p($directory);
		$file = "backup_2026-01-01-0000_Test_site_0123456789ab-db.gz";
		file_put_contents($directory . "/" . $file, gzencode("-- fixture\n"));
		update_option("backupplus_backup_history", array(1767225600 => array("db" => $file, "db-size" => filesize($directory . "/" . $file), "nonce" => "0123456789ab", "service" => array("none"), "service_instance_ids" => array())));
	' >/dev/null
}

# Inspect the sites side by side; this runs before every suite.
for ((n = 1; n <= count; n++)); do
	(podman inspect --format '{{.State.Health.Status}}' "backupplus-test-$n-wordpress" 2>/dev/null || true) >"$logs/health-$n" &
done
wait
missing=()
for ((n = 1; n <= count; n++)); do
	[[ "$(cat "$logs/health-$n")" == healthy ]] || missing+=("$n")
done
if ((${#missing[@]} == 0)); then
	for ((n = 1; n <= count; n++)); do
		(podman exec "backupplus-test-$n-wordpress" wp option get backupplus_backup_history >/dev/null 2>&1 || configure_site "$n") &
	done
	wait
	exit 0
fi

# Build once; the stacks then share the images.
podman build --quiet --file dev/mariadb/Containerfile --tag backupplus-dev-mariadb:local dev/mariadb >/dev/null
podman build --quiet --file dev/wordpress/Containerfile --tag backupplus-dev-wordpress:local dev/wordpress >/dev/null

echo "Creating test sites: ${missing[*]}"
pids=()
for n in "${missing[@]}"; do
	(
		export BACKUPPLUS_SKIP_IMAGE_BUILD=1 DB_IN_MEMORY=1 DEV_NAME="backupplus-test-$n" WP_PORT=$((18080 + n)) WP_INTERNAL_PORT=$((18080 + n))
		export WP_URL="http://localhost:$WP_PORT"
		./dev/up.sh >"$logs/$n.log" 2>&1
	) &
	pids+=("$!")
done

failed=0
for i in "${!pids[@]}"; do
	if ! wait "${pids[$i]}"; then
		echo "Test site ${missing[$i]} failed to start:" >&2
		tail -n 40 "$logs/${missing[$i]}.log" >&2
		failed=1
	fi
done
for n in "${missing[@]}"; do
	configure_site "$n" || failed=1
done
exit "$failed"
