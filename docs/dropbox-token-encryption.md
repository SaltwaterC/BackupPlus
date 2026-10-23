# Dropbox credential encryption

BackupPlus creates its installation encryption key automatically when activated. Existing installations get the same setup on the first request after upgrading. Dropbox requires no manual key generation, SSH or file uploads. Hosts that manage secrets centrally can use the optional environment key source described in [stored credential encryption](credential-encryption.md).

Existing Dropbox credentials are upgraded automatically once. Subsequent reads accept only authenticated ciphertext; there is no legacy or plaintext fallback. Other providers now share this cipher and key lifecycle through the [generic credential policy](credential-encryption.md). Database archive encryption uses its separate age format.

## Storage on shared hosts

The default key folder is `wp-content/backupplus-private`. If it cannot be created, BackupPlus automatically tries the WordPress uploads folder instead. By default, the key lives in `credentials.php`, outside the database, settings exports and plugin directory, so plugin updates do not replace it. When `BACKUPPLUS_CREDENTIAL_KEYS` is set, its Base64 encoded JSON key ring becomes the sole key source and the disk file is ignored.

The folder uses owner-only permissions, Apache/IIS access rules, a PHP guard that returns an empty 404 response, an index guard and a `.donotbackup` marker. BackupPlus excludes the key folder from unencrypted file archives. When age encryption is enabled, **BackupPlus key store** appears as a separate file source, selected by default. Its archive is age-encrypted and can be restored with the rest of the backup. A database copy and the public source do not contain the generated key. Other backup products need their own exclusion rules.

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

For disk-managed keys, BackupPlus registers an annual WordPress cron job automatically on activation and for existing installations. It runs every 365 days after key creation or the last rotation. Existing key files without rotation metadata start their first annual interval when this feature initializes. WordPress cron runs when triggered by site traffic or the site's existing system cron integration, so an inactive site may execute an overdue job later.

The job creates one new random key, makes it active, and re-encrypts all saved encrypted Dropbox access and request tokens for every stored instance. It also re-encrypts other provider secret fields and saved job snapshots. On multisite it re-encrypts credentials across the network sharing the installation key file. Each rewritten token is verified before saving; each completed site's active key ID is recorded so retries skip completed work. Previous keys stay in the file so historical backups and running jobs remain readable; rotation does not retire old keys.

The shared file's rotation timestamp is checked under its lock, preventing overlapping or duplicate callbacks from generating additional keys. A retry is scheduled before changes begin, so interrupted jobs can resume. Failed writes preserve the previous credential ciphertext, retain its keys, show an error in Settings and retry the rewrite after a day using the already published key. Successful completion clears the retry and error. Deactivation removes the annual and retry jobs; reactivation resumes scheduling.

You can still select **Rotate encryption key** to rotate immediately. It performs the same credential re-encryption across the network and resets the annual schedule. Download a fresh recovery copy after any rotation, including automatic rotation. On multisite, only a network administrator can trigger manual rotation.

When **Settings → Reporting → Email** is enabled, successful disk-key rotations send a notice to the configured report address. Backup reports warn when the active key reaches one year old and point to WordPress cron or manual rotation if it is disk-managed. For environment-managed keys, WordPress cron is disabled and the report asks the service operator to update the environment key ring. The key age comes from the active key's `issued_at` timestamp.

If exposure is suspected, disconnect/reconnect Dropbox and revoke the old tokens as well. Rotation does not revoke a token or make previously leaked database copies safe.

## Advanced hosting configuration

Hosts can optionally set an absolute directory before WordPress loads:

```php
define('BACKUPPLUS_CREDENTIAL_KEY_DIR', '/home/account/private/backupplus');
```

BackupPlus generates the file there on first installation. Changing this override on an existing installation moves the existing managed file, preserving its keys. The Settings location field becomes read-only while this override is present. This is optional; ordinary installations use automatic storage and browser controls.

## Format and upgrade details

The envelope is `bpcred:v1:<key-id>:<base64(nonce || ciphertext || tag)>`, using ChaCha20-Poly1305 through bundled phpseclib 3, a random 12-byte nonce and a full 16-byte tag. The version, key ID, WordPress blog ID and Dropbox storage instance ID are authenticated before unserialization.

An explicit UpdraftPlus configuration import decodes shared-key AES-CBC access tokens and old plaintext request tokens, then encrypts them with ChaCha20-Poly1305 before saving. Both structured exports and older flat Dropbox settings are supported. Invalid tokens abort the import before settings are saved.

The automatic one-time upgrade also prepares and verifies replacements for all saved Dropbox instances before one options update, only while the site's upgrade-completion marker is absent. The marker lives in the protected key file for disk-managed keys and in a local WordPress option for environment-managed keys. When restoring old settings directly from a database backup, an authorized administrator can select **Upgrade restored credentials** to deliberately run that conversion again; no CLI is required. Invalid input or a save failure retains original credentials and shows recovery instructions. The optional CLI maintenance script calls the same upgrade/rotation routines; it is not required for installation or recovery.

Key creation, relocation and rotation use file locks; writes publish a complete guarded temporary file with an atomic rename. Concurrent first installations converge on one key. Recovery uploads never execute PHP. Managed paths are private plugin metadata, not exported settings, and cannot be changed through settings imports.

## Verification

`make test` covers automatic activation/upgrade, protected file creation, shared-host uploads fallback, concurrent initialization, browser relocation outside the document root, failed moves, authorization/nonces, multisite permissions, HTTP denial, automatic legacy migration, recovery uploads, explicit lost-key replacement and rotation. Annual-cron regressions cover scheduling without duplicates, the due-time guard, re-encryption of all stored token fields and instances, retained old keys, retries after a failed save without another rotation, successful error cleanup, manual schedule resets, and deactivation/reactivation. Network checks cover partial site failures and resuming only unfinished rewrites. The HTTP regression downloads the recovery attachment with a real authenticated session, checks its exact contents and no-store headers, restores an older download after rotation while preserving newer credentials, checks repeated restores and conflicting uploads, restores those same bytes after a missing-file failure, and rejects bad nonces, GET requests, anonymous requests and insufficient permissions. Fresh-process checks cover key metadata writes before the crypto autoloader is available. It also checks OpenSSL interoperability, phpseclib's portable engine, nonce uniqueness, ciphertext/header tampering, key/context mismatch, write-failure preservation and restart/restore behavior.
