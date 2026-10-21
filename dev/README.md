# WordPress development environment

This Podman setup starts WordPress and MariaDB from Ubuntu 26.04 containers. It downloads WordPress core into a persistent volume, creates the site on first launch, mounts this checkout as the `backupplus` plugin, and activates it automatically. PHP's built-in development server serves the site, and WP-CLI is available as `wp` inside the `wordpress` container.

## Start

Start the environment with Podman's built-in commands:

```sh
cp .env.example .env
./dev/up.sh
```

Open the printed **Admin auto-login** URL from the container logs. To view it again:

```sh
podman logs backupplus-dev-wordpress
```

The default site URL is <http://localhost:8080>. The login link signs into the pre-created `admin` account and redirects to the WordPress dashboard. The web port is bound to loopback only.

The checkout is mounted read-only as the plugin source, so edits to BackupPlus files on the host are immediately visible in WordPress. WordPress core, its database, and generated site content live in named volumes.

## Configuration

Copy `.env.example` to `.env` and change the local database and administrator credentials if desired. Changing `WP_PORT` also updates the site URL automatically; set `WP_URL` only if you want to use a custom host name. The default development login token is intentionally convenient and must only be used with the loopback-bound development server.

The database and application user are reconciled from `.env` whenever the MariaDB container starts. Keep `DB_ROOT_PASSWORD` unchanged for an existing database volume; changing it requires recreating that volume with `./dev/down.sh --volumes`.

The WordPress container mounts the host CA bundle read-only and configures PHP cURL, OpenSSL, and WordPress's HTTPS request API to use it. On Linux the default is `/etc/ssl/certs/ca-certificates.crt`; set `HOST_CA_BUNDLE` in `.env` if your host stores its bundle elsewhere. This lets PHP use certificates already trusted by the host, including a managed WARP CA, while keeping certificate verification enabled.

To stop the environment while preserving the WordPress installation and database:

```sh
./dev/down.sh
```

To discard the site, database, and all local settings:

```sh
./dev/down.sh --volumes
```

## WP-CLI

Run WP-CLI commands in the WordPress container, for example:

```sh
podman exec backupplus-dev-wordpress wp plugin list --status=active
podman exec backupplus-dev-wordpress wp core version
```

An optional `compose.yaml` is also provided for users who already have a Compose provider installed. Podman's `compose` command delegates to an external provider such as `podman-compose` or `docker-compose`.
