#!/usr/bin/env bash
set -Eeuo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$repo_root"

if [[ -f .env ]]; then
	set -a
	# .env.example uses shell-compatible assignments so values are shared with Compose.
	# shellcheck disable=SC1091
	. ./.env
	set +a
fi

export WP_PORT=${WP_PORT:-8080}
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
export HOST_CA_BUNDLE=${HOST_CA_BUNDLE:-/etc/ssl/certs/ca-certificates.crt}

if [[ ! -r "$HOST_CA_BUNDLE" ]]; then
	echo "HOST_CA_BUNDLE must point to a readable host CA bundle (default: /etc/ssl/certs/ca-certificates.crt)." >&2
	exit 1
fi

if ! command -v podman >/dev/null 2>&1; then
	echo "Podman is required. Install Podman, then run this script again." >&2
	exit 1
fi

podman build --file dev/mariadb/Containerfile --tag backupplus-dev-mariadb:local dev/mariadb
podman build --file dev/wordpress/Containerfile --tag backupplus-dev-wordpress:local dev/wordpress

if ! podman network exists backupplus-dev; then
	podman network create backupplus-dev >/dev/null
fi
podman volume exists backupplus-dev-mariadb >/dev/null 2>&1 || podman volume create backupplus-dev-mariadb >/dev/null
podman volume exists backupplus-dev-wordpress >/dev/null 2>&1 || podman volume create backupplus-dev-wordpress >/dev/null

podman rm --force backupplus-dev-wordpress backupplus-dev-database >/dev/null 2>&1 || true

podman run --detach \
	--name backupplus-dev-database \
	--network backupplus-dev \
	--network-alias database \
	--volume backupplus-dev-mariadb:/var/lib/mysql \
	--env MARIADB_DATABASE="$DB_NAME" \
	--env MARIADB_USER="$DB_USER" \
	--env MARIADB_PASSWORD="$DB_PASSWORD" \
	--env MARIADB_ROOT_PASSWORD="$DB_ROOT_PASSWORD" \
	--health-cmd='mariadb --protocol=tcp --host=127.0.0.1 --user="$MARIADB_USER" --password="$MARIADB_PASSWORD" "$MARIADB_DATABASE" --execute="SELECT 1" >/dev/null' \
	--health-interval=5s \
	--health-timeout=3s \
	--health-retries=30 \
	--health-start-period=10s \
	backupplus-dev-mariadb:local >/dev/null

printf 'Waiting for MariaDB'
database_ready=0
for attempt in $(seq 1 90); do
	health=$(podman inspect --format '{{.State.Health.Status}}' backupplus-dev-database 2>/dev/null || true)
	if [[ "$health" == healthy ]]; then
		database_ready=1
		break
	fi
	if [[ "$health" == unhealthy ]]; then
		printf '\nMariaDB failed its health check. Recent logs:\n' >&2
		podman logs --tail 80 backupplus-dev-database >&2
		exit 1
	fi
	if (( attempt % 5 == 0 )); then printf '.'; fi
	sleep 1
done
printf '\n'

if [[ "$database_ready" != 1 ]]; then
	echo "MariaDB did not become healthy within 90 seconds. Recent logs:" >&2
	podman logs --tail 80 backupplus-dev-database >&2
	exit 1
fi

podman run --detach \
	--name backupplus-dev-wordpress \
	--network backupplus-dev \
	--publish "127.0.0.1:${WP_PORT}:8080" \
	--volume backupplus-dev-wordpress:/var/www/html \
	--volume "$repo_root:/workspace/backupplus:ro,z" \
	--volume "$HOST_CA_BUNDLE:/etc/ssl/certs/host-ca-certificates.crt:ro" \
	--env DB_HOST=database \
	--env DB_NAME="$DB_NAME" \
	--env DB_USER="$DB_USER" \
	--env DB_PASSWORD="$DB_PASSWORD" \
	--env WP_ENVIRONMENT_TYPE=local \
	--env WP_URL="$WP_URL" \
	--env WP_SITE_TITLE="$WP_SITE_TITLE" \
	--env WP_ADMIN_USER="$WP_ADMIN_USER" \
	--env WP_ADMIN_PASSWORD="$WP_ADMIN_PASSWORD" \
	--env WP_ADMIN_EMAIL="$WP_ADMIN_EMAIL" \
	--env DEV_LOGIN_TOKEN="$DEV_LOGIN_TOKEN" \
	--health-cmd="php -r \"exit(@file_get_contents('http://127.0.0.1:8080/wp-login.php') === false ? 1 : 0);\"" \
	--health-interval=10s \
	--health-timeout=5s \
	--health-retries=10 \
	--health-start-period=20s \
	backupplus-dev-wordpress:local >/dev/null

printf 'Waiting for WordPress initialization'
wordpress_ready=0
for attempt in $(seq 1 120); do
	health=$(podman inspect --format '{{.State.Health.Status}}' backupplus-dev-wordpress 2>/dev/null || true)
	if [[ "$health" == healthy ]]; then
		wordpress_ready=1
		break
	fi
	if [[ "$health" == unhealthy ]]; then
		printf '\nWordPress failed its health check. Recent logs:\n' >&2
		podman logs --tail 120 backupplus-dev-wordpress >&2
		exit 1
	fi
	if (( attempt % 5 == 0 )); then printf '.'; fi
	sleep 1
done
printf '\n'

if [[ "$wordpress_ready" != 1 ]]; then
	echo "WordPress did not become healthy within 120 seconds. Recent logs:" >&2
	podman logs --tail 120 backupplus-dev-wordpress >&2
	exit 1
fi

podman logs backupplus-dev-wordpress
printf '\nBackupPlus development environment is ready at %s\n' "$WP_URL"
