#!/usr/bin/env bash
set -Eeuo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$repo_root"

# Values passed by the caller win over .env: the test sites are started with their own name, port and URL.
caller_overrides=()
for name in DEV_NAME WP_PORT WP_URL WP_INTERNAL_PORT; do
	if [[ -n "${!name+x}" ]]; then
		caller_overrides+=("$name=${!name}")
	fi
done

if [[ -f .env ]]; then
	set -a
	# .env.example uses shell-compatible assignments so values are shared with Compose.
	# shellcheck disable=SC1091
	. ./.env
	set +a
fi
for override in "${caller_overrides[@]}"; do
	export "$override"
done

# DEV_NAME names every container, volume and network, so that further isolated stacks (the test sites, see
# dev/test-sites.sh) can run next to the development one. WP_INTERNAL_PORT is the port PHP listens on inside the container: it
# must equal the port of WP_URL, or WordPress cannot request its own URL.
export DEV_NAME=${DEV_NAME:-backupplus-dev}
export WP_PORT=${WP_PORT:-8080}
export WP_INTERNAL_PORT=${WP_INTERNAL_PORT:-8080}
export PHP_CLI_SERVER_WORKERS=${PHP_CLI_SERVER_WORKERS:-4}
export WP_URL=${WP_URL:-http://localhost:${WP_PORT}}
export WP_SITE_TITLE=${WP_SITE_TITLE:-BackupPlus Development}
export WP_ADMIN_USER=${WP_ADMIN_USER:-admin}
export WP_ADMIN_PASSWORD=${WP_ADMIN_PASSWORD:-backupplus_dev}
export WP_ADMIN_EMAIL=${WP_ADMIN_EMAIL:-admin@example.test}
export DEV_LOGIN_TOKEN=${DEV_LOGIN_TOKEN:-backupplus-local-dev-login}
export DB_NAME=${DB_NAME:-backupplus}
export DB_USER=${DB_USER:-backupplus}
export DB_PASSWORD=${DB_PASSWORD:-backupplus_dev}
export DB_ROOT_PASSWORD=${DB_ROOT_PASSWORD:-backupplus_root}
export DB_BACKUP_USER=${DB_BACKUP_USER:-backupplus_backup}
export DB_BACKUP_PASSWORD=${DB_BACKUP_PASSWORD:-backupplus_backup_dev}
export DB_RESTORE_USER=${DB_RESTORE_USER:-backupplus_restore}
export DB_RESTORE_PASSWORD=${DB_RESTORE_PASSWORD:-backupplus_restore_dev}
export HOST_CA_BUNDLE=${HOST_CA_BUNDLE:-/etc/ssl/certs/ca-certificates.crt}

if [[ ! -r "$HOST_CA_BUNDLE" ]]; then
	echo "HOST_CA_BUNDLE must point to a readable host CA bundle (default: /etc/ssl/certs/ca-certificates.crt)." >&2
	exit 1
fi

if ! command -v podman >/dev/null 2>&1; then
	echo "Podman is required. Install Podman, then run this script again." >&2
	exit 1
fi

if [[ -z "${BACKUPPLUS_SKIP_IMAGE_BUILD:-}" ]]; then
	podman build --file dev/mariadb/Containerfile --tag backupplus-dev-mariadb:local dev/mariadb
	podman build --file dev/wordpress/Containerfile --tag backupplus-dev-wordpress:local dev/wordpress
fi

if ! podman network exists ${DEV_NAME}; then
	podman network create ${DEV_NAME} >/dev/null
fi
podman volume exists ${DEV_NAME}-mariadb >/dev/null 2>&1 || podman volume create ${DEV_NAME}-mariadb >/dev/null
podman volume exists ${DEV_NAME}-db-socket >/dev/null 2>&1 || podman volume create ${DEV_NAME}-db-socket >/dev/null
podman volume exists ${DEV_NAME}-wordpress >/dev/null 2>&1 || podman volume create ${DEV_NAME}-wordpress >/dev/null

# --ignore covers containers that do not exist yet; any other failure (such as an active `podman exec`) must stop here.
podman rm --force --ignore ${DEV_NAME}-wordpress ${DEV_NAME}-database >/dev/null

# DB_IN_MEMORY=1 (the test sites) keeps the database in memory and skips its flushes; the data is gone with the container.
db_storage=(--volume ${DEV_NAME}-mariadb:/var/lib/mysql)
if [[ "${DB_IN_MEMORY:-0}" == 1 ]]; then
	db_storage=(--tmpfs /var/lib/mysql --env MARIADB_RELAXED_DURABILITY=1)
fi

podman run --detach \
	--name ${DEV_NAME}-database \
	--network ${DEV_NAME} \
	--network-alias database \
	"${db_storage[@]}" \
	--volume ${DEV_NAME}-db-socket:/run/mysqld \
	--env MARIADB_DATABASE="$DB_NAME" \
	--env MARIADB_USER="$DB_USER" \
	--env MARIADB_PASSWORD="$DB_PASSWORD" \
	--env MARIADB_ROOT_PASSWORD="$DB_ROOT_PASSWORD" \
	--env MARIADB_BACKUP_USER="$DB_BACKUP_USER" \
	--env MARIADB_BACKUP_PASSWORD="$DB_BACKUP_PASSWORD" \
	--env MARIADB_RESTORE_USER="$DB_RESTORE_USER" \
	--env MARIADB_RESTORE_PASSWORD="$DB_RESTORE_PASSWORD" \
	--health-cmd='mariadb --protocol=tcp --host=127.0.0.1 --user="$MARIADB_USER" --password="$MARIADB_PASSWORD" "$MARIADB_DATABASE" --execute="SELECT 1" >/dev/null' \
	--health-interval=5s \
	--health-timeout=3s \
	--health-retries=30 \
	--health-start-period=10s \
	backupplus-dev-mariadb:local >/dev/null

printf 'Waiting for MariaDB'
database_ready=0
for attempt in $(seq 1 90); do
	health=$(podman inspect --format '{{.State.Health.Status}}' ${DEV_NAME}-database 2>/dev/null || true)
	if [[ "$health" == healthy ]]; then
		database_ready=1
		break
	fi
	if [[ "$health" == unhealthy ]]; then
		printf '\nMariaDB failed its health check. Recent logs:\n' >&2
		podman logs --tail 80 ${DEV_NAME}-database >&2
		exit 1
	fi
	if (( attempt % 5 == 0 )); then printf '.'; fi
	sleep 1
done
printf '\n'

if [[ "$database_ready" != 1 ]]; then
	echo "MariaDB did not become healthy within 90 seconds. Recent logs:" >&2
	podman logs --tail 80 ${DEV_NAME}-database >&2
	exit 1
fi

podman run --detach \
	--name ${DEV_NAME}-wordpress \
	--network ${DEV_NAME} \
	--publish "127.0.0.1:${WP_PORT}:${WP_INTERNAL_PORT}" \
	--volume ${DEV_NAME}-wordpress:/var/www/html \
	--volume ${DEV_NAME}-db-socket:/run/mysqld \
	--volume "$repo_root:/workspace/backupplus:ro,z" \
	--volume "$repo_root/dev/wordpress/env-mask:/workspace/backupplus/.env:ro" \
	--volume "$HOST_CA_BUNDLE:/etc/ssl/certs/host-ca-certificates.crt:ro" \
	--env DB_HOST=localhost:/run/mysqld/mysqld.sock \
	--env DB_NAME="$DB_NAME" \
	--env DB_USER="$DB_USER" \
	--env DB_PASSWORD="$DB_PASSWORD" \
	--env WP_ENVIRONMENT_TYPE=local \
	--env PHP_CLI_SERVER_WORKERS="$PHP_CLI_SERVER_WORKERS" \
	--env WP_INTERNAL_PORT="$WP_INTERNAL_PORT" \
	--env WP_URL="$WP_URL" \
	--env WP_SITE_TITLE="$WP_SITE_TITLE" \
	--env WP_ADMIN_USER="$WP_ADMIN_USER" \
	--env WP_ADMIN_PASSWORD="$WP_ADMIN_PASSWORD" \
	--env WP_ADMIN_EMAIL="$WP_ADMIN_EMAIL" \
	--env DEV_LOGIN_TOKEN="$DEV_LOGIN_TOKEN" \
	--health-cmd="php -r \"exit(@file_get_contents('http://127.0.0.1:${WP_INTERNAL_PORT}/wp-login.php') === false ? 1 : 0);\"" \
	--health-interval=10s \
	--health-timeout=5s \
	--health-retries=10 \
	--health-start-period=20s \
	backupplus-dev-wordpress:local >/dev/null

printf 'Waiting for WordPress initialization'
wordpress_ready=0
for attempt in $(seq 1 120); do
	health=$(podman inspect --format '{{.State.Health.Status}}' ${DEV_NAME}-wordpress 2>/dev/null || true)
	if [[ "$health" == healthy ]]; then
		wordpress_ready=1
		break
	fi
	if [[ "$health" == unhealthy ]]; then
		printf '\nWordPress failed its health check. Recent logs:\n' >&2
		podman logs --tail 120 ${DEV_NAME}-wordpress >&2
		exit 1
	fi
	if (( attempt % 5 == 0 )); then printf '.'; fi
	sleep 1
done
printf '\n'

if [[ "$wordpress_ready" != 1 ]]; then
	echo "WordPress did not become healthy within 120 seconds. Recent logs:" >&2
	podman logs --tail 120 ${DEV_NAME}-wordpress >&2
	exit 1
fi

podman logs ${DEV_NAME}-wordpress
printf '\nBackupPlus development environment is ready at %s\n' "$WP_URL"
