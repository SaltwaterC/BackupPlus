# Dropbox credential encryption

BackupPlus creates its installation encryption key automatically when activated. Existing installations get the same setup on the first request after upgrading. Dropbox requires no manual key generation, SSH, file uploads or server configuration.

Existing Dropbox credentials are upgraded automatically once. Subsequent reads accept only authenticated ciphertext; there is no legacy or plaintext fallback. Other providers and database archive encryption are outside this change.

## Storage on shared hosts

The default key folder is `wp-content/backupplus-private`. If it cannot be created, BackupPlus automatically tries the WordPress uploads folder instead. The key lives in `credentials.php`, outside the database, settings exports and plugin directory, so plugin updates do not replace it.

The folder uses owner-only permissions, Apache/IIS access rules, a PHP guard that returns an empty 404 response, an index guard and a `.donotbackup` marker. BackupPlus also explicitly excludes the key folder and files from its archive traversal. A database copy and the public source do not contain the generated key. Other backup products need their own exclusion rules.

If hosting permissions prevent writing to both content and uploads, Settings explains the problem and lets an administrator choose a writable location and retry. BackupPlus cannot bypass hosting filesystem restrictions; it never falls back to a public key or plaintext credentials.

## Move the key without server access

In **BackupPlus → Settings → Credential encryption**, enter an absolute path to a dedicated empty folder and select **Move key file**. BackupPlus creates and protects the folder, copies and verifies the existing key, records the new location, and removes the old key file. Dropbox stays connected. A failed move preserves the original file and connection.

The folder may be outside `wp-content` and outside the website document root when the hosting account permits PHP to access it. No SSH or file manager is required. Choosing an existing website folder is rejected to avoid applying private-folder access rules to public content.

On multisite, the installation key file is shared across the network, and only a network administrator who can manage BackupPlus can download or modify it. Each site's saved Dropbox credentials have their own upgrade completion marker in the protected key file and remain bound to that site's ID and storage instance ID.

## Recovery and moving websites

Select **Download recovery key** in the same Settings section and keep the file somewhere safe and separate from your backups. Treat it as a password. The download is an authorized, nonce-protected POST with no-store response headers; no key appears in a URL or in the Settings page.

After moving a website or losing its key file, choose the downloaded file and select **Restore recovery key**. You can enter a different writable key folder before restoring if the old absolute path does not exist on the new host. BackupPlus parses the upload as data, never executes it, and verifies it against saved authenticated Dropbox credentials before publishing it. The uploaded file's active key becomes active for subsequent credential writes, including when restoring a copy downloaded before a rotation. Keys are merged so newer keys and the credentials encrypted with them remain readable; conflicting keys are rejected. Restoring does not delete newer keys or rewrite existing credential ciphertext. The success message explicitly confirms that the restored key is active.

If no recovery copy survives, select **Replace missing key**, then disconnect/reconnect Dropbox and download a new recovery copy. This explicit action creates a new file only when the old key file is absent; it preserves stored credentials so a recovery copy found later can still unlock them. It never replaces a working file.

The plugin reuses its key across restarts. It never silently creates a replacement for a missing or unreadable key once a managed location is recorded, or when authenticated credentials already exist. Preserve WordPress blog IDs and Dropbox storage instance IDs when restoring a database; changing them requires reconnecting Dropbox. Keep recovery copies for historical backups too.

## Rotation

Select **Rotate encryption key** to generate a new random key and re-encrypt the current site's saved Dropbox credentials. Download a fresh recovery copy afterwards. Previous keys stay in the file so historical backups, other sites and running jobs remain readable; normal rotation does not retire old keys. On multisite, new writes across the network use the new active key, while other sites' existing credentials remain readable with retained keys.

If exposure is suspected, disconnect/reconnect Dropbox and revoke the old tokens as well. Rotation does not revoke a token or make previously leaked database copies safe. A failed credential rewrite retains the previous keys, so saved credentials remain usable and the operation can be retried.

## Advanced hosting configuration

Hosts can optionally set an absolute directory before WordPress loads:

```php
define('BACKUPPLUS_CREDENTIAL_KEY_DIR', '/home/account/private/backupplus');
```

BackupPlus generates the file there on first installation. Changing this override on an existing installation moves the existing managed file, preserving its keys. The Settings location field becomes read-only while this override is present. This is optional; ordinary installations use automatic storage and browser controls.

## Format and upgrade details

The envelope is `bpcred:v1:<key-id>:<base64(nonce || ciphertext || tag)>`, using AES-256-GCM through bundled phpseclib 3, a random 12-byte nonce and a full 16-byte tag. The version, key ID, WordPress blog ID and Dropbox storage instance ID are authenticated before unserialization.

The automatic one-time upgrade prepares and verifies replacements for all saved Dropbox instances before one options update. It reads the former shared-key CBC access tokens and old plaintext request tokens only while the site's upgrade-completion marker in the protected key file is absent. Deleting a database marker or placing legacy ciphertext in settings cannot reopen that decoder after completion. When restoring settings from before this upgrade, an authorized administrator can select **Upgrade restored Dropbox credentials** to deliberately run that conversion again; no CLI is required. Invalid input or a save failure retains original credentials and shows recovery instructions. The optional CLI maintenance script calls the same upgrade/rotation routines; it is not required for installation or recovery.

Key creation, relocation and rotation use file locks; writes publish a complete guarded temporary file with an atomic rename. Concurrent first installations converge on one key. Recovery uploads never execute PHP. Managed paths are private plugin metadata, not exported settings, and cannot be changed through settings imports.

## Verification

`make test` covers automatic activation/upgrade, protected file creation, shared-host uploads fallback, concurrent initialization, browser relocation outside the document root, failed moves, authorization/nonces, multisite permissions, HTTP denial, automatic legacy migration, recovery uploads, explicit lost-key replacement and rotation. The HTTP regression downloads the recovery attachment with a real authenticated session, checks its exact contents and no-store headers, restores an older download after rotation while preserving newer credentials, checks repeated restores and conflicting uploads, restores those same bytes after a missing-file failure, and rejects bad nonces, GET requests, anonymous requests and insufficient permissions. Fresh-process checks cover key metadata writes before the crypto autoloader is available. It also checks OpenSSL interoperability, phpseclib's portable engine, nonce uniqueness, ciphertext/header tampering, key/context mismatch, write-failure preservation and restart/restore behavior.
