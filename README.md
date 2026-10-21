# BackupPlus

BackupPlus is a WordPress backup and restore plugin. To move a site, create a backup and restore it on the destination. This repository includes a Podman-based local WordPress environment that installs WordPress automatically, activates the checked-out plugin, and provides a local administrator auto-login link.

Start with `cp .env.example .env && ./dev/up.sh`. See [the development environment guide](dev/README.md) for configuration, WP-CLI commands, and cleanup.

## Building assets

The plugin's versioned minified assets are generated from the stable inputs in `assets-src/`. After changing the `Version:` header in `backupplus.php`, run `make build-assets` to produce the filenames used by the plugin and update CSS source-map references. Run `make check-assets` to verify the checked-in assets match the current plugin version. This uses PHP CLI and Make; it does not require Node or npm.

## Storage providers

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
