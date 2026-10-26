# WordPress development environment

This Podman setup starts WordPress and MariaDB from Ubuntu 26.04 containers. It downloads WordPress core into a persistent volume, creates the site on first launch, mounts this checkout as the `backupplus` plugin, and activates it automatically. PHP's built-in development server serves the site with four workers by default, so a long WP-Cron backup request does not monopolize the single-worker development server. Change `PHP_CLI_SERVER_WORKERS` in `.env` to adjust the worker count. WP-CLI is available as `wp` inside the `wordpress` container.

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

Copy `.env.example` to `.env` and change the local database and administrator credentials if desired. Changing `WP_PORT` also updates the site URL automatically; set `WP_URL` only if you want to use a custom host name. If an existing site volume has no active theme, startup installs and activates Twenty Twenty-Five. The default development login token is intentionally convenient and must only be used with the loopback-bound development server.

The database and application user are reconciled from `.env` whenever the MariaDB container starts. Keep `DB_ROOT_PASSWORD` unchanged for an existing database volume; changing it requires recreating that volume with `./dev/down.sh --volumes`.

WordPress and the native backup clients connect through a Unix socket shared between the containers at `/run/mysqld/mysqld.sock`. Startup updates existing `DB_HOST` settings and enabled PITR connection files to use this socket. Local PITR therefore needs no database TLS certificates. Remote database connections still require verified TLS.

MariaDB starts with ROW binary logging, CRC32 checksums, server ID 1 and two days of source-log retention. The WordPress account receives only privileges on its application database. Startup removes the previous development recovery account and file and resets the development application's old experimental grants. The database event scheduler is disabled. The shared socket volume is removed by `./dev/down.sh --volumes`.

Startup also provisions two socket-only PITR test logins from `.env`, with defaults for existing `.env` files that do not contain the new keys:

| Purpose | Username variable (default) | Password variable (default) |
| --- | --- | --- |
| Saved backup account | `DB_BACKUP_USER=backupplus_backup` | `DB_BACKUP_PASSWORD=backupplus_backup_dev` |
| Restore account | `DB_RESTORE_USER=backupplus_restore` | `DB_RESTORE_PASSWORD=backupplus_restore_dev` |

The backup login has the [capture grants](../docs/binary-log-backups.md) and no application data-write privileges. The restore login has application-schema restore and server-administration grants. Startup reconciles their passwords and grants on existing volumes too. All three application/backup/restore usernames must differ and cannot use system-account names. These credentials are passed only to MariaDB; they are not injected into the WordPress container, `wp-config.php`, plugin settings or restore jobs. A harmless file masks the checkout's private `.env` inside WordPress, and the development router refuses hidden-file URLs.

To test the full flow yourself:

1. Set the four PITR variables in `.env` if you want different credentials, then run `./dev/up.sh` (existing site data is preserved). With Compose, use `podman compose up --build -d`.
2. In **Settings → Database point-in-time recovery → Backup account**, enter the backup username/password and click **Verify and save backup account**. The plugin saves this login using credential encryption.
3. Select **Every minute** or another PITR interval and save Settings. Click **Incremental Backup** with only the database selected to create the initial full baseline immediately.
4. Make a recognizable content change, take another database incremental, then make another change and take a further incremental. The recovery points appear together under the full database chain in Existing backups.
5. Click **Restore** for that database chain, select an earlier incremental recovery point, and enter the restore username/password only in that screen. Selecting the full database backup instead uses the standard SQL dump restore and requires no restore account. Supply your archive decryption identity if encryption is enabled. Verify, restore and click **Done**, then check that the later content change is absent.
6. PITR keeps its previous setting after recovery and starts a new chain on its next run. Restore credentials are not saved by WordPress; enter them again for another recovery.

Ordinary full-dump backups/restores need neither PITR login. The disposable native test matrix provisions separate fixture accounts and does not restore into the development database.

The WordPress container mounts the host CA bundle read-only and configures PHP cURL, OpenSSL, and WordPress's HTTPS request API to use it. On Linux the default is `/etc/ssl/certs/ca-certificates.crt`; set `HOST_CA_BUNDLE` in `.env` if your host stores its bundle elsewhere. The startup script keeps `WP_ENVIRONMENT_TYPE=local` set on existing installations too, so the WordPress HTTP CA override remains active with persistent volumes. This lets PHP use certificates already trusted by the host, including a managed WARP CA, while keeping certificate verification enabled.

To stop the environment while preserving the WordPress installation and database:

```sh
./dev/down.sh
```

To discard the site, database, and all local settings:

```sh
./dev/down.sh --volumes
```

## WP-CLI

The container uses the WP-CLI nightly build, which includes the PHP 8.5 compatibility fixes not yet present in the stable release. Run WP-CLI commands in the WordPress container, for example:

```sh
podman exec backupplus-dev-wordpress wp plugin list --status=active
podman exec backupplus-dev-wordpress wp core version
```

An optional `compose.yaml` is also provided for users who already have a Compose provider installed. Podman's `compose` command delegates to an external provider such as `podman-compose` or `docker-compose`.

## Manual decryption security regression

With the local environment running, use `make test-client-browser` to exercise the Decryption tab in Chromium. Set `CHROMIUM` to the executable if it is not on PATH or in the Playwright cache. The test uses `http://localhost:8080` by default; set `BACKUPPLUS_TEST_URL` and `BACKUPPLUS_DEV_LOGIN_TOKEN` for a different local HTTP test site. `PODMAN` and `WP_TEST_CONTAINER` select the container runtime and WordPress container.

The SEC-003 checks generate a unique disposable passphrase, decrypt and download a synthetic archive, and check success/failure page content, links, request URLs/bodies/headers, redirect destinations and browser navigation history. A temporary loopback HTTP proxy captures page and worker requests, including access records with Referer and Location. The test reads the WordPress container's access/error logs for the test interval, verifies that both log sources contain a control request, and checks that neither contains the test credentials. Chromium's temporary profile, downloads and proxy are removed at exit. These checks inspect disposable test activity; they do not audit historical production logs or browser profiles.

## Test sites

`make test` does not use the development site. `./dev/test-sites.sh [count]` (also run by `make test-sites`) creates a pool of disposable WordPress sites next to it, named `backupplus-test-N`, on ports 18081 and up, built from the same images and with the first-run tour disabled and a seeded backup history. It only creates the sites that are missing, and `./dev/test-sites.sh --remove` deletes them. They are the same stack as the development one, started by `up.sh` with `DEV_NAME`, `WP_PORT`, `WP_URL` and `WP_INTERNAL_PORT` overridden, which you can also use to run a second development site.
