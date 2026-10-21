#!/usr/bin/env bash
set -Eeuo pipefail

data_dir=/var/lib/mysql
socket=/run/mysqld/mysqld.sock
pid_file=/run/mysqld/mysqld.pid

database=${MARIADB_DATABASE:-backupplus}
database_user=${MARIADB_USER:-backupplus}
database_password=${MARIADB_PASSWORD:-backupplus_dev}
root_password=${MARIADB_ROOT_PASSWORD:-backupplus_root}

for value in "$database" "$database_user"; do
	if [[ ! "$value" =~ ^[A-Za-z0-9_.-]+$ ]]; then
		echo "Database names and users may contain only letters, numbers, dot, underscore, and hyphen." >&2
		exit 1
	fi
done

if [[ -z "$database_password" || -z "$root_password" ]]; then
	echo "Database passwords must not be empty." >&2
	exit 1
fi

escape_sql_string() {
	local value=$1
	value=${value//\\/\\\\}
	value=${value//\'/\'\'}
	printf '%s' "$value"
}

database_password_sql=$(escape_sql_string "$database_password")
root_password_sql=$(escape_sql_string "$root_password")

mkdir -p /run/mysqld "$data_dir"
chown -R mysql:mysql /run/mysqld "$data_dir"

new_database=0
if [[ ! -d "$data_dir/mysql" ]]; then
	new_database=1
	mariadb-install-db \
		--user=mysql \
		--datadir="$data_dir" \
		--auth-root-authentication-method=normal \
		--skip-test-db
fi

mariadbd \
	--user=mysql \
	--datadir="$data_dir" \
	--socket="$socket" \
	--pid-file="$pid_file" \
	--skip-networking \
	--log-error=/dev/stderr &
bootstrap_pid=$!

root_client_args=(--protocol=socket --socket="$socket" --user=root)
if [[ "$new_database" != 1 ]]; then
	root_client_args+=(--password="$root_password")
fi

ready=0
for ((attempt = 1; attempt <= 60; attempt++)); do
	if mariadb "${root_client_args[@]}" --execute='SELECT 1' >/dev/null 2>&1; then
		ready=1
		break
	fi
	if ! kill -0 "$bootstrap_pid" 2>/dev/null; then
		wait "$bootstrap_pid" || true
		echo "MariaDB stopped during initialization." >&2
		exit 1
	fi
	sleep 1
done

if [[ "$ready" != 1 ]]; then
	mariadb "${root_client_args[@]}" --execute='SELECT 1' >&2 || true
	kill "$bootstrap_pid" 2>/dev/null || true
	wait "$bootstrap_pid" || true
	echo "MariaDB did not become ready during initialization. Check the database logs and, for an existing volume, verify DB_ROOT_PASSWORD matches the password used when it was created." >&2
	exit 1
fi

mariadb "${root_client_args[@]}" <<SQL
ALTER USER 'root'@'localhost' IDENTIFIED BY '${root_password_sql}';
CREATE DATABASE IF NOT EXISTS \`${database}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${database_user}'@'%' IDENTIFIED BY '${database_password_sql}';
ALTER USER '${database_user}'@'%' IDENTIFIED BY '${database_password_sql}';
GRANT ALL PRIVILEGES ON \`${database}\`.* TO '${database_user}'@'%';
FLUSH PRIVILEGES;
SQL

mariadb-admin --protocol=socket --socket="$socket" --user=root --password="$root_password" shutdown
wait "$bootstrap_pid" || true

exec mariadbd \
	--user=mysql \
	--datadir="$data_dir" \
	--socket="$socket" \
	--pid-file="$pid_file" \
	--bind-address=0.0.0.0 \
	--port=3306 \
	--log-error=/dev/stderr
