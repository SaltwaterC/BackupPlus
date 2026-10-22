# BackupPlus

BackupPlus is a WordPress backup and restore plugin. To move a site, create a backup and restore it on the destination. This repository includes a Podman-based local WordPress environment that installs WordPress automatically, activates the checked-out plugin, and provides a local administrator auto-login link.

Start with `cp .env.example .env && ./dev/up.sh`. See [the development environment guide](dev/README.md) for configuration, WP-CLI commands, and cleanup.

## Building assets

The plugin's versioned minified assets are generated from the stable inputs in `assets-src/`. After changing the `Version:` header in `backupplus.php`, run `make build-assets` to produce the filenames used by the plugin and update CSS source-map references. Run `make check-assets` to verify the checked-in assets match the current plugin version. The browser decryptor is bundled from `assets-src/decryption/` using the pinned npm dependencies. Install build/test dependencies with `npm ci --ignore-scripts --omit=optional` (Node 22.4+), then run the same Make targets. The checked-in worker and license notices ship with the plugin; deployed WordPress sites require neither Node nor npm. `make test-client` checks independent crypto fixtures and archive boundaries. `make test-client-browser` checks the actual WordPress Decryption tab in Chromium; set `CHROMIUM` to your Chromium executable and optionally `BACKUPPLUS_TEST_URL` to the local development site.

## Tests

Run the complete suite with:

```sh
make test
```

This runs all standalone PHP tests, missing-extension checks, live FTP/FTPS, SFTP/SCP and WebDAV integration tests, asset checks, client cryptography and Chromium decryption checks, and WordPress provider checks with every provider removed individually and all providers removed together. Failures return a nonzero exit status; missing prerequisites fail instead of skipping a suite.

Prerequisites are PHP with cURL, DOM, OpenSSL and ctype (libcurl must support FTP and TLS), Python 3.8+ with `venv` and pip, the `openssl`, `scp`, and `age` commands, and the running Podman WordPress development environment (`./dev/up.sh`). The first run installs Python test dependencies into `.cache/test-venv`; subsequent runs reuse them. Live storage tests create disposable loopback servers and need no external storage accounts.

For a focused run, use `make test-unit`, `make test-integration`, `make test-wordpress`, or `make check-assets`. Override `PYTHON`, `TEST_VENV`, `PODMAN`, or `WP_TEST_CONTAINER` on the Make command line if needed, for example `make test PYTHON=python3.12`.

## Storage providers

The **WebDAV** provider supports HTTP and HTTPS with Basic or Digest authentication. Enter the server hostname, optional port, username, password, and remote directory. HTTPS is the default; ports default to 443 for HTTPS and 80 for HTTP. The directory must already exist. Enter its path without URL encoding (for example, `remote.php/dav/files/username/backups` for Nextcloud); an empty path or `/` uses the server root. Legacy WebDAV settings are retained.

WebDAV supports backup uploads, restore downloads, backup discovery, retention deletion, and a settings test that lists the directory, uploads a disposable file, reads it back, and deletes it. Transfers stream through PHP cURL. Retried uploads restart in full; downloads replace local files only after a successful complete transfer. Configure the final WebDAV endpoint because redirects are rejected. PHP cURL and DOM XML are required. HTTPS verifies server certificates and hostnames by default, honoring the plugin's expert settings for certificate verification and system CA selection (`curl.cainfo`); disabling SSL rejects HTTPS connections.

Run `php scripts/test-webdav.php` and `php -d disable_functions=curl_init scripts/test-webdav.php` for settings and missing-support checks. Run `python3 scripts/test-webdav-integration.py` for disposable HTTP/HTTPS servers, Basic and Digest authentication, binary and empty files, special characters, malformed XML, failed transfers, and certificate verification. The integration checks require PHP cURL/DOM, Python's standard library, and `openssl`; no external WebDAV service is needed.

The **SFTP / SCP** provider uses the bundled phpseclib 3 library; PHP's SSH2 extension and a local SSH executable are not required. Select SFTP (the default) or SCP, enter the SSH server, port (default 22), username, and password or private key. Both RSA and Ed25519 keys are supported, including encrypted OpenSSH Ed25519 keys. Paste the complete private key into the private-key field and enter its passphrase separately when encrypted. A supplied private key takes precedence over the password. Hostnames with a port, bracketed IPv6 addresses, and `sftp://` or `scp://` host prefixes are accepted. A prefix also selects that protocol.

SSH host verification is required before authentication. Enter the server's SHA256 host key fingerprint, obtained from your server administrator. Testing with an empty fingerprint displays the server's fingerprint without sending credentials; verify it independently before saving it. A changed or missing fingerprint stops transfers. Existing SFTP settings are retained, including settings from the legacy `backupplus_sftp_settings` option; configure a fingerprint before using them.

The remote directory must already exist. An empty path uses the login directory; `/` starts an absolute path. SFTP supports upload and download resumption. SCP restarts interrupted transfers and requires a POSIX shell, `scp`, `wc`, and `rm` on the remote server. SCP works without an SFTP subsystem. Both protocols support backup uploads, restore downloads, backup discovery, retention deletion, and a settings test that uploads, reads back, and removes a disposable file.

Run `make test` for the complete suite. SSH integration checks cover password authentication, encrypted and unencrypted RSA and Ed25519 keys, Ed25519 server host keys, fingerprint rejection before login, transfer resumption, empty files, absolute and relative paths, shell metacharacters, symlinks, and read-only directories. No external SSH service is needed.

phpseclib 3 handles PHP cryptography for backup archives and Dropbox tokens. New encrypted backups use age public recipients; passphrase encryption is no longer available for new backups. Legacy passphrase-encrypted archives remain readable. **Settings → Backup encryption** covers database dumps and every file archive (including split ZIPs), and offers only Disabled or standard age encryption to native X25519 or SSH RSA/Ed25519 recipients, including `github:USERNAME` public-key import. **Generate age key in browser** creates a native key using Web Crypto randomness and prompts you to download the private identity. Only its public recipient is saved when you click **Save Changes**. Restore accepts encrypted OpenSSH/PEM keys without saving the private key or its passphrase. Neither libsodium nor an `age` executable is required by the plugin. The **Decryption** tab decrypts archives entirely in the browser without uploading files, keys or passphrases. It supports both database and file archives, including legacy backups. See [backup encryption and compatibility](docs/database-backup-encryption.md). Dropbox credentials use authenticated AES-256-GCM encryption. BackupPlus automatically creates a protected installation key file and upgrades existing credentials; no SSH or manual key generation is needed. In **Settings → Credential encryption**, you can move the key folder outside `wp-content` or the document root, download/restore a recovery key, and rotate keys. Restricted hosts automatically use writable uploads storage if the content folder is unavailable. The key folder is excluded from BackupPlus archives and settings exports. `make test` covers archive compatibility, authenticated token storage, automatic key management and recovery.

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

Use `BackupPlus_Storage_Methods_Interface::get_storage_object('example')` for a shared object, or `BackupPlus_Storage_Provider_Factory::create('example', false)` for an independent object. Both return `WP_Error` for an unavailable provider. Keep shared implementation in helper files rather than another provider's entry file, so removing an entry file removes only that destination. Saved settings are retained when a provider is absent.

Run the WordPress integration checks with the plugin skipped, so the test can load an isolated copy:

```sh
podman exec backupplus-dev-wordpress wp --skip-plugins=backupplus eval-file wp-content/plugins/backupplus/scripts/test-storage-providers.php s3
```

Omit `s3` to test the complete installation, pass another provider ID to test its removal, or pass `all` to test with every provider entry file removed. The checks exercise discovery, object creation, the Settings form, and stale credentials and authentication requests without changing source providers or saved options.
