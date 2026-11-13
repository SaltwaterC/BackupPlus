# shellcheck shell=bash
# Throwaway WordPress sites that run the plugin package (an unzipped `make dist`), for dev/dist-check.sh and
# dev/screenshots.sh. Source this file; it needs PODMAN and the working directory at the repository root.
#
#   package_site_start NAME PORT PLUGIN_DIR TOKEN [podman run options for the WordPress container...]
#   package_site_remove NAME
#
# The site is a separate stack named NAME (containers NAME-wordpress and NAME-database, network NAME, volume NAME-db-socket)
# on http://localhost:PORT, next to the development and test sites. PLUGIN_DIR is mounted read-only as the backupplus plugin;
# TOKEN is the development login token: PORT/?backupplus_dev_login=TOKEN signs in as the administrator.

package_site_remove() {
	"$PODMAN" rm --force --ignore --time 2 "$1-wordpress" "$1-database" >/dev/null 2>&1 || true
	"$PODMAN" volume rm --force "$1-db-socket" >/dev/null 2>&1 || true
	"$PODMAN" network rm --force "$1" >/dev/null 2>&1 || true
}

package_site_start() {
	local name=$1 port=$2 plugin=$3 token=$4 url="http://localhost:$2" attempt log started=0
	shift 4

	"$PODMAN" image exists backupplus-dev-mariadb:local ||
		"$PODMAN" build --quiet --file dev/mariadb/Containerfile --tag backupplus-dev-mariadb:local dev/mariadb >/dev/null
	"$PODMAN" image exists backupplus-dev-wordpress:local ||
		"$PODMAN" build --quiet --file dev/wordpress/Containerfile --tag backupplus-dev-wordpress:local dev/wordpress >/dev/null

	# A previous run that was killed may have left the stack behind.
	package_site_remove "$name"
	"$PODMAN" network create "$name" >/dev/null
	"$PODMAN" volume create "$name-db-socket" >/dev/null

	# The health command is expanded in the container, as in dev/up.sh.
	# shellcheck disable=SC2016
	"$PODMAN" run --detach --name "$name-database" --network "$name" --network-alias database \
		--tmpfs /var/lib/mysql --env MARIADB_RELAXED_DURABILITY=1 --volume "$name-db-socket:/run/mysqld" \
		--env MARIADB_DATABASE=backupplus --env MARIADB_USER=backupplus --env MARIADB_PASSWORD=backupplus_dev \
		--env MARIADB_ROOT_PASSWORD=backupplus_root \
		--env MARIADB_BACKUP_USER=backupplus_backup --env MARIADB_BACKUP_PASSWORD=backupplus_backup_dev \
		--env MARIADB_RESTORE_USER=backupplus_restore --env MARIADB_RESTORE_PASSWORD=backupplus_restore_dev \
		--health-cmd='mariadb --protocol=tcp --host=127.0.0.1 --user="$MARIADB_USER" --password="$MARIADB_PASSWORD" "$MARIADB_DATABASE" --execute="SELECT 1" >/dev/null' \
		--health-interval=2s --health-retries=60 \
		backupplus-dev-mariadb:local >/dev/null
	for ((attempt = 1; attempt <= 90; attempt++)); do
		[[ "$("$PODMAN" inspect --format '{{.State.Health.Status}}' "$name-database")" == healthy ]] && break
		sleep 1
	done

	# The package is mounted read-only, as it was unzipped; the entrypoint links it in as the backupplus plugin and activates it.
	"$PODMAN" run --detach --name "$name-wordpress" --network "$name" \
		--publish "127.0.0.1:$port:$port" --tmpfs /var/www/html --volume "$name-db-socket:/run/mysqld" \
		--volume "$plugin:/workspace/backupplus:ro,z" \
		--volume "${HOST_CA_BUNDLE:-/etc/ssl/certs/ca-certificates.crt}:/etc/ssl/certs/host-ca-certificates.crt:ro" \
		--env DB_HOST=localhost:/run/mysqld/mysqld.sock --env WP_ENVIRONMENT_TYPE=local \
		--env WP_INTERNAL_PORT="$port" --env WP_URL="$url" --env DEV_LOGIN_TOKEN="$token" \
		"$@" backupplus-dev-wordpress:local >/dev/null
	for ((attempt = 1; attempt <= 150; attempt++)); do
		# Read the whole log first: with pipefail, grep -q stopping early would fail the pipeline.
		log=$("$PODMAN" logs "$name-wordpress" 2>&1)
		if grep -q 'Admin auto-login' <<<"$log"; then
			started=1
			break
		fi
		[[ "$("$PODMAN" inspect --format '{{.State.Running}}' "$name-wordpress")" == true ]] || break
		sleep 2
	done
	if [[ "$started" != 1 ]]; then
		echo "The site did not start:" >&2
		"$PODMAN" logs --tail 40 "$name-wordpress" >&2
		return 1
	fi

	# The entrypoint links the development login plugin from the checkout, which the package does not contain; copy it in.
	"$PODMAN" exec "$name-wordpress" rm -f wp-content/mu-plugins/backupplus-dev-auto-login.php
	"$PODMAN" cp dev/wordpress/mu-plugins/backupplus-dev-auto-login.php "$name-wordpress:/var/www/html/wp-content/mu-plugins/"
	for ((attempt = 1; attempt <= 30; attempt++)); do
		curl --silent --fail --output /dev/null "$url/wp-login.php" && return 0
		sleep 1
	done
	echo "The site at $url does not answer." >&2
	return 1
}
