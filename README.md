# BackupPlus

BackupPlus is a WordPress backup and restore plugin. To move a site, create a backup and restore it on the destination. This repository includes a Podman-based local WordPress environment that installs WordPress automatically, activates the checked-out plugin, and provides a local administrator auto-login link.

Start with `cp .env.example .env && ./dev/up.sh`. See [the development environment guide](dev/README.md) for configuration, WP-CLI commands, and cleanup.

## Incremental backups

Choose an **Incremental file backups** interval in Settings, or click **Incremental Backup** beside Backup Now. The first run creates a full file backup. Later runs archive only files whose SHA-256 content hashes have changed, including new files and edits with preserved timestamps. Database backups continue to use their existing full-backup schedule.

An incremental request automatically creates a full backup when the base is seven days old, incomplete, incompatible with the current file selection/exclusions/encryption/storage settings, or unavailable. Incremental schedules run at least daily, optionally at an exact time (see [backup times](docs/backup-scheduling.md)); the weekly limit uses the original full backup's time, so adding increments never extends it. Scheduled backups depend on WordPress cron running. The normal file schedule still creates full backups and can run more frequently.

Restoring a backup chain applies its full backup and increments in order, including recorded deletions. Keep every archive in the chain; scheduled retention counts and removes complete chains together. Encrypted increments use the saved age recipients. Local protected inventories support change detection; if an inventory is lost (for example after moving to a different installation), the next request creates a new full backup. Restore requires the archives and does not require those inventories.

`make test-wordpress` includes disposable end-to-end checks for incremental creation, same-size edits with preserved timestamps, unchanged files, deletions and restoration, encrypted increments, missing/corrupt bases and seven-day rollover. Run the focused checks with:

```sh
podman exec backupplus-dev-wordpress wp --skip-plugins=backupplus eval-file wp-content/plugins/backupplus/scripts/check-incremental-backups.php
podman exec backupplus-dev-wordpress wp --skip-plugins=backupplus eval-file wp-content/plugins/backupplus/scripts/check-incremental-restore.php
```

`check-incremental-restore.php` takes a real full backup and two increments of the content directory and WordPress core, then restores each to the latest, middle and full-backup restore points. It confirms changes, additions, cumulative deletions, `-old` copies, live-only files and `wp-config.php` handling. A dangling symlink in the source also confirms that harmless warnings do not stop a chain from being reused.

## Backup times and retention cleanup

File, database and incremental schedules can each run at an exact time in the site timezone. Hourly schedules choose the minute past the hour, and schedules of two to twelve hours choose which hours they run at and the minute. Weekly and fortnightly schedules also use a weekday, and monthly schedules a day from 1 to 28. Old backups can be deleted after each backup or by a separate daily cleanup that creates no backup. Existing schedules keep their timing until exact times are selected. Runs can be late without site traffic; running WordPress cron from the server's task scheduler is optional. See [backup times and retention cleanup](docs/backup-scheduling.md) for the scheduling rules, cleanup behaviour, server cron setup and checks.

## Building assets

The plugin's versioned minified assets are generated from the stable inputs in `assets-src/`. After changing the `Version:` header in `backupplus.php`, run `make build-assets` to produce the filenames used by the plugin and update CSS source-map references. Run `make check-assets` to verify the checked-in assets match the current plugin version. The browser decryptor is bundled from `assets-src/decryption/` using the pinned npm dependencies. Install build/test dependencies with `npm ci --ignore-scripts --omit=optional` (Node 22.4+), then run the same Make targets. The checked-in worker and license notices ship with the plugin; deployed WordPress sites require neither Node nor npm. `make test-client` checks independent crypto fixtures and archive boundaries. `make test-client-browser` checks the actual WordPress Decryption tab in Chromium; set `CHROMIUM` to your Chromium executable and optionally `BACKUPPLUS_TEST_URL` to the local development site.

## PHP formatting

Install the development tools with PHP and Composer 2:

```sh
make install-tools
```

This installs locked versions of PHP_CodeSniffer, its automatic fixer (`phpcbf`), and WordPress Coding Standards under `.cache/php-tools/`. The plugin's bundled runtime libraries in `vendor/` are managed separately. If Composer is not on your PATH, pass `COMPOSER='php /path/to/composer.phar'`; a local `.cache/composer.phar` is also detected automatically. See [Composer installation](https://getcomposer.org/download/).

```sh
make format        # Automatically fix PHP coding style
make check-format  # Report violations without changing files
make format FORMAT_PATHS='includes/class-age-keys.php methods/ftp'
```

Both targets use the same explicit subset of WordPress formatting rules in `.phpcs.xml.dist`: indentation, whitespace, braces, argument spacing, array layout, and line endings. Bundled libraries, dependencies, and generated assets are excluded. Comparisons, naming conventions, SQL checks, and other code-quality rules are outside the formatter's scope. Heredoc/nowdoc contents and closing-marker indentation are preserved. `make check-format` exits nonzero for outstanding formatting violations. `make format` treats the fixer's successful "files changed" status as success and still fails if fixes could not be completed. Formatting is separate from `make test`.

The formatter uses a 512 MB PHP memory limit for the larger source files. Override it with `PHP_TOOLS_MEMORY_LIMIT`, for example `make check-format PHP_TOOLS_MEMORY_LIMIT=1G`.

## Tests

Run the complete suite with:

```sh
make test
```

This runs all standalone PHP tests, missing-extension checks, live FTP/FTPS, SFTP/SCP and WebDAV integration tests, asset checks, client cryptography and Chromium decryption checks, and WordPress provider checks with every provider removed individually and all providers removed together. Failures return a nonzero exit status; missing prerequisites fail instead of skipping a suite.

Prerequisites are PHP with cURL, DOM, OpenSSL and ctype (libcurl must support FTP and TLS), Python 3.8+ with `venv` and pip, the `openssl`, `scp`, and `age` commands, and the running Podman WordPress development environment (`./dev/up.sh`). The first run installs Python test dependencies into `.cache/test-venv`; subsequent runs reuse them. Live storage tests create disposable loopback servers and need no external storage accounts.

For a focused run, use `make test-unit`, `make test-integration`, `make test-wordpress`, or `make check-assets`. Override `PYTHON`, `TEST_VENV`, `PODMAN`, or `WP_TEST_CONTAINER` on the Make command line if needed, for example `make test PYTHON=python3.12`.

Provider behavior tests cover all 16 implemented backends. `scripts/test-s3.php` exercises Amazon S3, S3-compatible storage and DreamObjects; `scripts/test-swift.php` exercises OpenStack and Rackspace Cloud Files. Dropbox, Google Drive, Email, Azure, Backblaze B2, Google Cloud Storage, OneDrive and pCloud each have a `scripts/test-<provider>.php` suite. Run them directly with PHP or together with the FTP, SFTP/SCP and WebDAV checks through `make test-unit`. Cloud API responses and email delivery use fakes, so no external accounts or real emails are needed. Coverage includes binary/empty transfers, pagination, chunk boundaries, retention deletion, credential tests, failure handling and provider isolation. `scripts/test-cloud-http-integration.py` additionally exercises real streaming uploads against a disposable loopback HTTPS server, including TLS trust, hostname verification and redirect refusal.

## Storage providers

Azure, Backblaze B2, Google Cloud Storage, OneDrive and pCloud are built in and support uploads, listings, downloads, retention deletion and a connection test that writes, reads and deletes a temporary file. See [cloud provider setup](docs/cloud-providers.md) for credentials, permissions and configuration. Each provider owns its settings, authentication and REST implementation in `methods/<provider>/`; shared HTTP and backup-module utilities contain no provider-specific API logic.

The **WebDAV** provider supports HTTP and HTTPS with Basic or Digest authentication. Enter the server hostname, optional port, username, password, and remote directory. HTTPS is the default; ports default to 443 for HTTPS and 80 for HTTP. The directory must already exist. Use the path after the hostname in your WebDAV folder URL: for `https://cloud.example.com/webdav/backups`, enter `cloud.example.com` as the server and `/webdav/backups` as the remote path. Enter spaces and other characters as written, without URL encoding. An empty path or `/` uses the server root. Legacy WebDAV settings are retained.

WebDAV supports backup uploads, restore downloads, backup discovery, retention deletion, and a settings test that lists the directory, uploads a disposable file, reads it back, and deletes it. Transfers stream through PHP cURL. Retried uploads restart in full; downloads replace local files only after a successful complete transfer. Configure the final WebDAV endpoint because redirects are rejected. PHP cURL and DOM XML are required. HTTPS verifies server certificates and hostnames by default, honoring the plugin's expert settings for certificate verification and system CA selection (`curl.cainfo`); disabling SSL rejects HTTPS connections.

Run `php scripts/test-webdav.php` and `php -d disable_functions=curl_init scripts/test-webdav.php` for settings and missing-support checks. Run `python3 scripts/test-webdav-integration.py` for disposable HTTP/HTTPS servers, Basic and Digest authentication, binary and empty files, special characters, malformed XML, failed transfers, and certificate verification. The integration checks require PHP cURL/DOM, Python's standard library, and `openssl`; no external WebDAV service is needed.

The **SFTP / SCP** provider uses the bundled phpseclib 3 library; PHP's SSH2 extension and a local SSH executable are not required. Select SFTP (the default) or SCP, enter the SSH server, port (default 22), username, and password or private key. Both RSA and Ed25519 keys are supported, including encrypted OpenSSH Ed25519 keys. Paste the complete private key into the private-key field and enter its passphrase separately when encrypted. A supplied private key takes precedence over the password. Hostnames with a port, bracketed IPv6 addresses, and `sftp://` or `scp://` host prefixes are accepted. A prefix also selects that protocol.

SSH host verification is required before authentication. Enter the server's SHA256 host key fingerprint, obtained from your server administrator. Testing with an empty fingerprint displays the server's fingerprint without sending credentials; verify it independently before saving it. A changed or missing fingerprint stops transfers. Existing SFTP settings are retained, including settings from the legacy `backupplus_sftp_settings` option; configure a fingerprint before using them.

The remote directory must already exist. An empty path uses the login directory; `/` starts an absolute path. SFTP supports upload and download resumption. SCP restarts interrupted transfers and requires a POSIX shell, `scp`, `wc`, and `rm` on the remote server. SCP works without an SFTP subsystem. Both protocols support backup uploads, restore downloads, backup discovery, retention deletion, and a settings test that uploads, reads back, and removes a disposable file.

Run `make test` for the complete suite. SSH integration checks cover password authentication, encrypted and unencrypted RSA and Ed25519 keys, Ed25519 server host keys, fingerprint rejection before login, transfer resumption, empty files, absolute and relative paths, shell metacharacters, symlinks, and read-only directories. No external SSH service is needed.

phpseclib 3 handles PHP cryptography for backup archives and Dropbox tokens. New encrypted backups use age public recipients; passphrase encryption is no longer available for new backups. Legacy passphrase-encrypted archives remain readable. **Settings → Backup encryption** covers database dumps and every file archive (including split ZIPs), and offers only Disabled or standard age encryption to native X25519 or SSH RSA/Ed25519 recipients, including `github:USERNAME` public-key import. **Generate age key in browser** creates a native key using Web Crypto randomness and prompts you to download the private identity. Only its public recipient is saved when you click **Save Changes**. Restore decrypts selected archives in your browser, then uploads a temporary plaintext copy for restoration. Private keys and passphrases never leave the browser; encrypted OpenSSH/PEM keys are supported. Neither libsodium nor an `age` executable is required by the plugin. The **Decryption** tab decrypts archives entirely in the browser without uploading files, keys or passphrases. It supports both database and file archives, including legacy backups. See [backup encryption and compatibility](docs/database-backup-encryption.md). Dropbox credentials use authenticated ChaCha20-Poly1305 encryption. BackupPlus automatically creates a protected installation key file and upgrades existing credentials; no SSH or manual key generation is needed. Hosts can instead use the Base64 encoded JSON `BACKUPPLUS_CREDENTIAL_KEYS` environment variable, with service-managed rotation and key-age alerts in backup reports. In **Settings → Expert settings → Credential encryption**, you can move the key folder outside `wp-content` or the document root, download/restore a recovery key, and rotate disk-managed keys. Restricted hosts automatically use writable uploads storage if the content folder is unavailable. With age encryption enabled, the key folder appears as its own file backup source and is selected by default; unencrypted BackupPlus archives omit it. Settings exports omit the key folder. `make test` covers archive compatibility, authenticated token storage, automatic key management and recovery.

The FTP provider supports plain FTP and FTPS with explicit TLS. Select **FTPS (explicit TLS)** under **Encryption** to encrypt the login and file transfers. Both modes default to port 21; enter `hostname:port` to use a custom port. The `ftps://` and `ftpes://` host prefixes also select explicit TLS, including in connection tests. Existing FTP settings continue to use plain FTP.

Both modes use PHP's cURL extension with libcurl FTP support; FTPS also requires libcurl SSL/TLS support and SSL enabled in the plugin's expert settings. PHP's native FTP extension is not required. FTPS requires encryption on both control and data connections and never retries using plain FTP. Server certificate trust and hostname verification are enabled by default, honoring the existing expert settings for disabling verification and choosing the system CA store instead of the bundled CA file. System CA selection respects PHP's `curl.cainfo` setting. Implicit FTPS (typically port 990) is not supported. An empty remote path uses the login directory; paths beginning with `/` use the server root.

Run `php scripts/test-ftp.php` and `php -d disable_functions=curl_init scripts/test-ftp.php` for FTP settings and failure handling checks. To test live transfers and certificate verification against disposable loopback servers:

```sh
python3 -m venv /tmp/backupplus-ftp-tests
/tmp/backupplus-ftp-tests/bin/pip install pyftpdlib pyOpenSSL
/tmp/backupplus-ftp-tests/bin/python scripts/test-ftp-integration.py
```

The integration checks disable the native PHP FTP functions and exercise both active and passive modes, transfer resumption, relative and absolute paths, filenames with URL metacharacters, and rejection of untrusted, expired and mismatched certificates. No external FTP service is needed.

Each provider entry file in `methods/` declares its own registration headers:

```php
/**
 * BackupPlus Provider: Example Storage
 * Provider ID: example
 * Provider Class: BackupPlus_BackupModule_example
 */
```

The factory discovers these headers automatically and sorts providers by display name, ignoring helper files without headers. The class must extend `BackupPlus_BackupModule`; its `get_id()` must match the registered ID (lowercase letters and digits). Implement the storage operations and configuration methods illustrated in `methods/template.php`.

Use `BackupPlus_Storage_Methods_Interface::get_storage_object('example')` for a shared object, or `BackupPlus_Storage_Provider_Factory::create('example', false)` for an independent object. Both return `WP_Error` for an unavailable provider.

Keep the main provider class in `methods/example.php` and its helpers, transports and bundled provider libraries in `methods/example/`. A provider must not load another provider's entry point or helpers. S3, S3-compatible and DreamObjects each own their implementation and transport classes; OpenStack and Cloud Files likewise own separate implementations. Use distinct class names when duplicating code so providers can run together. General services such as credential encryption, the key store, CA certificates, and Composer libraries remain reusable.

Provider methods own authentication checks (`authentication_required()`), dashboard messages (`get_admin_notices()`), imported settings conversion (`normalize_imported_settings()`) and any download repair (`download_complete()`). Declare the `phpseclib` supported feature when a provider needs that library. An optional `methods/example/bootstrap.php` registers hooks before credential options are registered, without constructing the provider. Credential lifecycle actions are `backupplus_credentials_upgrade`, `backupplus_credentials_upgrade_restored`, `backupplus_credentials_rotate` and `backupplus_credentials_verify` (which receives the candidate key configuration). Failures must throw so maintenance cannot report success with unreadable credentials. Dropbox uses these hooks for its historical token format; the cipher and key lifecycle stay in core.

Remove a provider by deleting `methods/example.php` and `methods/example/`. BackupPlus and the other providers continue to work; saved settings are retained for reinstatement.

Run the WordPress integration checks with the plugin skipped, so the test can load an isolated copy:

```sh
podman exec backupplus-dev-wordpress wp --skip-plugins=backupplus eval-file wp-content/plugins/backupplus/scripts/test-storage-providers.php s3
```

Omit `s3` to test the complete installation, pass another provider ID to test removal of its entry file and helper directory, or pass `all` to test with every provider removed. Pass `only:s3` to test S3 with every other provider removed. The checks exercise discovery, object creation, credential upgrade/rotation/recovery, the Settings form, and stale credentials and authentication requests without changing source providers or saved options. `make test-wordpress` runs every removal and standalone-provider combination.


## Core files, additional sources, and database tables

WordPress core and **Additional files** are opt-in in Settings. Core includes the installation root and non-WordPress root files, excluding separately backed-up content directories. Additional sources accept absolute paths to readable local files and directories, one per line. Each source has a stable archive namespace and stores its original path in its archive manifest. An additional source cannot be the backup output or credential key directory. When age encryption is enabled, the credential key folder is listed separately as its own source. Missing or unreadable selected sources fail the backup.

Database Options provides saved external MySQL/MariaDB connections, connection testing and table discovery. Connections use stable numeric IDs (`db1`, `db2`, etc. in archive names). For each database, choose **All eligible tables** or **Only selected tables**. WordPress eligibility defaults to its table prefix; the non-WordPress checkbox expands it to the whole database. New external connections default to all tables; an imported prefix restriction still applies to eligible mode. Selected mode uses exact names regardless of prefix, permits omitting WordPress core tables, and adds no related tables automatically. Empty selected lists and missing selected tables fail without falling back to a broader backup.

Scheduled backups use saved selections. **Backup Now** has independent temporary choices. Each job snapshots its resolved table list, source paths and exclusions for resumptions. Databases are full dumps of the chosen tables, even during incremental file backups. Dump headers and backup history record partial coverage, and unencrypted local rescans or encrypted restore verification recover that metadata.

The existing restore wizard offers core, individual additional sources and each external dump. Directory destinations merge archived contents; standalone files accept a destination filename. The current `wp-config.php` is preserved unless replacement is explicitly selected. External dumps require an explicit saved or newly entered connection to an existing database. Verification displays destinations and replacement details. External imports run before the WordPress database, preserve table names and values without WordPress transformations, and leave tables absent from the dump untouched. Interrupted imports restart the current table. Restore connection passwords use the existing credential encryption, recovery and rotation mechanisms; archive metadata and history contain no passwords.

Programmatic backup options accept the same structure as `backupplus_database_selection`:

```php
array(
    'database_selection' => array(
        'wp' => array( 'enabled' => false, 'mode' => 'all', 'tables' => array() ),
        1 => array( 'enabled' => true, 'mode' => 'selected', 'tables' => array( 'orders', 'order_items' ) ),
    ),
)
```

Focused checks: `php scripts/test-backup-sources.php`, `wp --skip-plugins=backupplus eval-file wp-content/plugins/backupplus/scripts/check-backup-sources.php`, and `node scripts/test-backup-sources-browser.mjs`. These also run through the existing `make test` targets.

WP-CLI commands for backups, restores, source selection, schedules, retention and native update protection are documented in [docs/wp-cli.md](docs/wp-cli.md).
