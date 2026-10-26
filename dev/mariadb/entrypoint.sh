#!/usr/bin/env bash
set -Eeuo pipefail

data_dir=/var/lib/mysql
socket=/run/mysqld/mysqld.sock
pid_file=/run/mysqld/mysqld.pid

database=${MARIADB_DATABASE:-backupplus}
database_user=${MARIADB_USER:-backupplus}
database_password=${MARIADB_PASSWORD:-backupplus_dev}
root_password=${MARIADB_ROOT_PASSWORD:-backupplus_root}
backup_user=${MARIADB_BACKUP_USER:-backupplus_backup}
backup_password=${MARIADB_BACKUP_PASSWORD:-backupplus_backup_dev}
restore_user=${MARIADB_RESTORE_USER:-backupplus_restore}
restore_password=${MARIADB_RESTORE_PASSWORD:-backupplus_restore_dev}

for value in "$database" "$database_user" "$backup_user" "$restore_user"; do
	if [[ ! "$value" =~ ^[A-Za-z0-9_.-]+$ ]]; then
		echo "Database names and users may contain only letters, numbers, dot, underscore, and hyphen." >&2
		exit 1
	fi
done

if [[ "$database_user" == "$backup_user" || "$database_user" == "$restore_user" || "$backup_user" == "$restore_user" ]]; then
	echo "WordPress, PITR backup and PITR restore usernames must be different." >&2
	exit 1
fi
for value in "$database_user" "$backup_user" "$restore_user"; do
	if [[ "$value" == root || "$value" == mysql || "$value" == mariadb.sys ]]; then
		echo "Use application-specific usernames instead of database system accounts." >&2
		exit 1
	fi
done

if [[ -z "$database_password" || -z "$root_password" || -z "$backup_password" || -z "$restore_password" ]]; then
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
backup_password_sql=$(escape_sql_string "$backup_password")
restore_password_sql=$(escape_sql_string "$restore_password")

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
	--skip-networking &
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
DROP USER IF EXISTS 'backupplus_recovery'@'localhost';
CREATE DATABASE IF NOT EXISTS \`${database}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${database_user}'@'%' IDENTIFIED BY '${database_password_sql}';
ALTER USER '${database_user}'@'%' IDENTIFIED BY '${database_password_sql}';
CREATE USER IF NOT EXISTS '${database_user}'@'localhost' IDENTIFIED BY '${database_password_sql}';
ALTER USER '${database_user}'@'localhost' IDENTIFIED BY '${database_password_sql}';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${database_user}'@'%', '${database_user}'@'localhost';
GRANT ALL PRIVILEGES ON \`${database}\`.* TO '${database_user}'@'%';
GRANT ALL PRIVILEGES ON \`${database}\`.* TO '${database_user}'@'localhost';
CREATE USER IF NOT EXISTS '${backup_user}'@'localhost' IDENTIFIED BY '${backup_password_sql}';
ALTER USER '${backup_user}'@'localhost' IDENTIFIED BY '${backup_password_sql}';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${backup_user}'@'localhost';
GRANT SELECT, SHOW VIEW, TRIGGER, EVENT ON \`${database}\`.* TO '${backup_user}'@'localhost';
GRANT RELOAD, REPLICATION SLAVE, BINLOG MONITOR ON *.* TO '${backup_user}'@'localhost';
GRANT SELECT ON mysql.proc TO '${backup_user}'@'localhost';
CREATE USER IF NOT EXISTS '${restore_user}'@'localhost' IDENTIFIED BY '${restore_password_sql}';
ALTER USER '${restore_user}'@'localhost' IDENTIFIED BY '${restore_password_sql}';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${restore_user}'@'localhost';
GRANT ALL PRIVILEGES ON \`${database}\`.* TO '${restore_user}'@'localhost';
GRANT RELOAD, REPLICATION SLAVE, BINLOG MONITOR, SUPER, PROCESS, CREATE USER, CONNECTION ADMIN, SET USER, BINLOG ADMIN, BINLOG REPLAY, REPLICA MONITOR ON *.* TO '${restore_user}'@'localhost';
GRANT SELECT ON mysql.proc TO '${restore_user}'@'localhost';
GRANT SELECT ON mysql.global_priv TO '${restore_user}'@'localhost';
GRANT SELECT ON mysql.user TO '${restore_user}'@'localhost';
GRANT SELECT ON mysql.db TO '${restore_user}'@'localhost';
GRANT SELECT ON mysql.tables_priv TO '${restore_user}'@'localhost';
GRANT SELECT ON mysql.columns_priv TO '${restore_user}'@'localhost';
GRANT SELECT ON mysql.procs_priv TO '${restore_user}'@'localhost';
GRANT SELECT ON mysql.proxies_priv TO '${restore_user}'@'localhost';
GRANT SELECT ON mysql.roles_mapping TO '${restore_user}'@'localhost';
FLUSH PRIVILEGES;
SQL
rm -f /run/mysqld/backupplus-recovery.cnf

mariadb-admin --protocol=socket --socket="$socket" --user=root --password="$root_password" shutdown
wait "$bootstrap_pid" || true

# Disposable test sites keep their data in memory and need no durability: they skip the flushes that dominate write-heavy tests.
durability_args=()
if [[ "${MARIADB_RELAXED_DURABILITY:-0}" == 1 ]]; then
	durability_args=(--innodb-flush-log-at-trx-commit=0 --innodb-doublewrite=OFF --sync-binlog=0)
fi

exec mariadbd \
	--user=mysql \
	--datadir="$data_dir" \
	--socket="$socket" \
	--pid-file="$pid_file" \
	--bind-address=0.0.0.0 \
	--port=3306 \
	--server-id=1 \
	--event-scheduler=OFF \
	--log-bin="$data_dir/binlog" \
	--binlog-format=ROW \
	--binlog-checksum=CRC32 \
	--binlog-expire-logs-seconds=172800 \
	"${durability_args[@]}"
