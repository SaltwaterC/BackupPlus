#!/usr/bin/env bash
set -Eeuo pipefail

wp_path=/var/www/html
plugin_link="$wp_path/wp-content/plugins/backupplus"
mu_plugins="$wp_path/wp-content/mu-plugins"

export DB_HOST=${DB_HOST:-database}
export DB_NAME=${DB_NAME:-backupplus}
export DB_USER=${DB_USER:-backupplus}
export DB_PASSWORD=${DB_PASSWORD:-backupplus_dev}
export WP_URL=${WP_URL:-http://localhost:8080}
export WP_SITE_TITLE=${WP_SITE_TITLE:-BackupPlus Development}
export WP_ADMIN_USER=${WP_ADMIN_USER:-admin}
export WP_ADMIN_PASSWORD=${WP_ADMIN_PASSWORD:-backupplus_dev}
export WP_ADMIN_EMAIL=${WP_ADMIN_EMAIL:-admin@example.test}
export DEV_LOGIN_TOKEN=${DEV_LOGIN_TOKEN:-backupplus-local-dev-login}
export PHP_CLI_SERVER_WORKERS=${PHP_CLI_SERVER_WORKERS:-4}

host_ca_bundle=/etc/ssl/certs/host-ca-certificates.crt
if [[ ! -r "$host_ca_bundle" ]]; then
	echo "The mounted host CA bundle is missing or unreadable at $host_ca_bundle." >&2
	exit 1
fi

php_ini_configured=0
for php_ini_dir in /etc/php/*/cli/conf.d; do
	[[ -d "$php_ini_dir" ]] || continue
	cat > "$php_ini_dir/99-backupplus-host-ca.ini" <<PHPINI
curl.cainfo="$host_ca_bundle"
openssl.cafile="$host_ca_bundle"
PHPINI
	php_ini_configured=1
done

if [[ "$php_ini_configured" != 1 ]]; then
	echo "Could not find the PHP CLI configuration directory to set the host CA bundle." >&2
	exit 1
fi

mkdir -p "$wp_path" "$wp_path/wp-content/plugins" "$mu_plugins"

if [[ ! -f "$wp_path/wp-includes/version.php" ]]; then
	wp core download --path="$wp_path"
fi

ln -sfn /workspace/backupplus "$plugin_link"
ln -sfn /workspace/backupplus/dev/wordpress/mu-plugins/backupplus-dev-auto-login.php \
	"$mu_plugins/backupplus-dev-auto-login.php"

if [[ ! -f "$wp_path/wp-config.php" ]]; then
	wp config create \
		--path="$wp_path" \
		--dbname="$DB_NAME" \
		--dbuser="$DB_USER" \
		--dbpass="$DB_PASSWORD" \
		--dbhost="$DB_HOST" \
		--dbcharset=utf8mb4 \
		--skip-check
	wp config set WP_DEBUG true --raw --type=constant --path="$wp_path"
	wp config set WP_DEBUG_LOG true --raw --type=constant --path="$wp_path"
	wp config set WP_DEBUG_DISPLAY true --raw --type=constant --path="$wp_path"
	wp config set WP_HOME "$WP_URL" --type=constant --path="$wp_path"
	wp config set WP_SITEURL "$WP_URL" --type=constant --path="$wp_path"
fi

# Reconcile this for existing wp-config.php files in the persistent site volume too.
wp config set WP_ENVIRONMENT_TYPE local --type=constant --path="$wp_path"

ready=0
for ((attempt = 1; attempt <= 60; attempt++)); do
	if wp db check --path="$wp_path" --quiet >/dev/null 2>&1; then
		ready=1
		break
	fi
	sleep 1
done

if [[ "$ready" != 1 ]]; then
	echo "WordPress could not connect to MariaDB after 60 seconds." >&2
	echo "Configured database target: $(wp config get DB_USER --path="$wp_path" --type=constant)@$(wp config get DB_HOST --path="$wp_path" --type=constant)/$(wp config get DB_NAME --path="$wp_path" --type=constant)" >&2
	wp db check --path="$wp_path" >&2 || true
	configured_db_host=$(wp config get DB_HOST --path="$wp_path" --type=constant)
	configured_db_name=$(wp config get DB_NAME --path="$wp_path" --type=constant)
	configured_db_user=$(wp config get DB_USER --path="$wp_path" --type=constant)
	configured_db_password=$(wp config get DB_PASSWORD --path="$wp_path" --type=constant)
	# shellcheck disable=SC2016
	DB_HOST="$configured_db_host" DB_NAME="$configured_db_name" DB_USER="$configured_db_user" DB_PASSWORD="$configured_db_password" php -r '
mysqli_report(MYSQLI_REPORT_OFF);
$connection = mysqli_init();
mysqli_options($connection, MYSQLI_OPT_CONNECT_TIMEOUT, 3);
if (!@mysqli_real_connect($connection, getenv("DB_HOST"), getenv("DB_USER"), getenv("DB_PASSWORD"), getenv("DB_NAME"))) {
	fprintf(STDERR, "Detailed database connection error (%d): %s\n", mysqli_connect_errno(), mysqli_connect_error());
	exit(1);
}
$connection->close();
' >&2 || true
	exit 1
fi

if ! wp core is-installed --path="$wp_path" >/dev/null 2>&1; then
	wp core install \
		--path="$wp_path" \
		--url="$WP_URL" \
		--title="$WP_SITE_TITLE" \
		--admin_user="$WP_ADMIN_USER" \
		--admin_password="$WP_ADMIN_PASSWORD" \
		--admin_email="$WP_ADMIN_EMAIL" \
		--skip-email
fi

# Keep the front end available when an existing development volume has no working theme.
if ! wp theme list --status=active --field=name --path="$wp_path" | grep -q .; then
	if ! wp theme is-installed twentytwentyfive --path="$wp_path" >/dev/null 2>&1; then
		if wp_version=$(wp core version --path="$wp_path"); then
			if ! timeout 30 wp core download --path="$wp_path" --version="$wp_version" --force; then
				echo "Warning: Could not restore the bundled themes from the WordPress core package." >&2
			fi
		else
			echo "Warning: Could not restore the bundled themes from the WordPress core package." >&2
		fi
	fi
	if wp theme is-installed twentytwentyfive --path="$wp_path" >/dev/null 2>&1; then
		if ! wp theme activate twentytwentyfive --path="$wp_path"; then
			echo "Warning: Could not activate Twenty Twenty-Five; the WordPress front end may have no active theme." >&2
		fi
	else
		echo "Warning: Twenty Twenty-Five is unavailable; the WordPress front end may have no active theme." >&2
	fi
fi

wp plugin activate backupplus --path="$wp_path"

login_url="${WP_URL%/}/?backupplus_dev_login=${DEV_LOGIN_TOKEN}"
echo "BackupPlus WordPress: ${WP_URL}"
echo "Admin auto-login: ${login_url}"

exec php -S 0.0.0.0:8080 -t "$wp_path" /usr/local/share/backupplus-router.php
