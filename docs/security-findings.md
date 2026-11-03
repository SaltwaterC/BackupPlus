# Security review findings

This document records the findings of a source security review of BackupPlus carried out on 2026-10-30 against commit `c1c11179`. Each finding has an identifier, a severity, a status and remediation criteria that describe what a fix must achieve. A status of **Open** means the issue is present in the reviewed code; documentation alone does not mean a fix has been implemented. Update the status (and the commit that fixed it) when a finding is resolved, and keep resolved findings in place so the identifiers stay stable.

Line numbers refer to the reviewed commit and will drift as the code changes; the function and file names are the stable reference.

## Scope and method

The review covered plugin code in the repository root, `includes/`, `methods/`, `templates/`, `js/`, `includes/admin-common/` and `assets-src/decryption/`. Bundled third-party SDKs (`methods/googledrive/Google`, `methods/dropbox/Dropbox2`, `methods/cloudfiles/sdk`, `vendor/`) were reviewed only for TLS configuration and for how the plugin calls them. WP-CLI commands were not reviewed as an attack surface, because a WP-CLI user already has shell access. `tests/`, `scripts/` and `dev/` were out of scope except where `scripts/check-sql.php` enforces a security rule.

The review was split into five areas: authentication, authorization and CSRF on every entry point; cryptography and secret handling; SQL injection and database privilege; filesystem, archive, upload/download and process execution; and remote storage, outbound HTTP and output escaping. Every finding of Low severity or above was traced through the code; findings marked *confirmed* were re-read at the cited lines after the initial report. No code was changed and no exploit was run against a live site. The only dynamic check was a `whois` lookup for BPS-01 and a local PHP check that `fopen()` resolves `missing/../` textually (BPS-02).

### Threat model

BackupPlus restricts its administration to `manage_options` on a single site and to `manage_network_options` on the main site of a network (`BackupPlus_Options::user_can_manage()`, `includes/class-multisite.php`). Those administrators are trusted. The attackers that matter are:

* unauthenticated visitors and low-privileged users (subscribers, authors, multisite sub-site administrators);
* a third party who can trick a logged-in administrator into opening a link or page (CSRF);
* anyone who can write to a configured remote storage destination without being a WordPress administrator: a bucket shared between sites, a leaked or narrowly-scoped storage credential, the storage provider, or a network attacker on a plaintext transport;
* anyone who obtains a copy of a backup or database dump, which is the threat that credential encryption is designed for;
* other local users on shared hosting;
* whoever controls a hostname the plugin trusts.

### Severity scale

| Severity | Meaning |
| --- | --- |
| Critical | Unauthenticated compromise of the site or its data with no user interaction. |
| High | Compromise of backups, credentials or the site by an outside party with little or no precondition. |
| Medium | Compromise that needs a realistic precondition, such as write access to remote storage or an administrator following a link. |
| Low | Requires an unusual configuration, an already-trusted actor, or local access; or a defence-in-depth gap that is not exploitable today. |
| Info | Hardening opportunity, documentation mismatch or tooling gap. |

## Summary

No Critical finding was identified, and nothing was found that an unauthenticated visitor can exploit without first obtaining a secret. The most serious issue was a hardcoded OAuth relay hostname that anyone can register; it is fixed. Three Medium findings share a root cause: data listed from remote storage (file names, archive contents, PITR chains) is trusted as if the site had produced it.

| ID | Severity | Status | Area | Title |
| --- | --- | --- | --- | --- |
| [BPS-01](#bps-01-oauth-relay-hostname-is-unregistered) | High | Fixed | Remote storage | OAuth relay hostname `auth.backupplusplus.com` is unregistered |
| [BPS-02](#bps-02-remote-file-names-can-traverse-out-of-the-backup-directory) | Medium | Open | Filesystem | Remote file names can traverse out of the backup directory |
| [BPS-03](#bps-03-pitr-source-restore-runs-untrusted-sql-with-server-level-privileges) | Medium | Open | Database | PITR source restore runs untrusted SQL with server-level privileges |
| [BPS-04](#bps-04-stored-xss-from-remote-storage-and-archive-contents) | Medium | Open | XSS | Stored XSS from remote storage and archive contents |
| [BPS-05](#bps-05-dropbox-and-google-drive-oauth-callbacks-lack-state-validation) | Medium | Partly fixed | Auth / CSRF | Dropbox and Google Drive OAuth callbacks lack `state` validation |
| [BPS-06](#bps-06-backup-uploads-can-store-php-files) | Low | Open | Filesystem | Backup uploads can store PHP files |
| [BPS-07](#bps-07-backup-directory-setting-accepts-any-location) | Low | Open | Filesystem | Backup directory setting accepts any location |
| [BPS-08](#bps-08-mysqldump-password-file-is-world-readable-and-injectable) | Low | Open | Secrets / exec | mysqldump password file is world-readable and injectable |
| [BPS-09](#bps-09-pitr-writes-db_password-in-plaintext-under-the-backup-directory) | Low | Open | Secrets | PITR writes `DB_PASSWORD` in plaintext under the backup directory |
| [BPS-10](#bps-10-unsalted-hash-of-decrypted-credentials-is-stored-in-backup-history) | Low | Open | Crypto | Unsalted hash of decrypted credentials is stored in backup history |
| [BPS-11](#bps-11-unauthenticated-restore-continuation-relies-on-a-weak-job-id) | Low | Open | Auth | Unauthenticated restore continuation relies on a weak job ID |
| [BPS-12](#bps-12-php-database-dumper-escaping-ignores-the-connection-charset) | Low | Open | Database | PHP database dumper escaping ignores the connection charset |
| [BPS-13](#bps-13-sub-site-administrators-can-arm-the-network-wide-uninstall-wipe) | Low | Open | Auth | Sub-site administrators can arm the network-wide uninstall wipe |
| [BPS-14](#bps-14-decrypted-legacy-database-backups-can-remain-on-disk) | Low | Open | Crypto | Decrypted legacy database backups can remain on disk |
| [BPS-15](#bps-15-custom-source-restore-takes-its-destination-from-the-archive) | Low | Open | Filesystem | Custom source restore takes its destination from the archive |
| [BPS-16](#bps-16-amazon-s3-silently-falls-back-to-plain-http-without-curl) | Low | Open | Remote storage | Amazon S3 silently falls back to plain HTTP without cURL |
| [BPS-17](#bps-17-restore-charset-and-collation-are-concatenated-into-ddl) | Low | Open | Database | Restore charset and collation are concatenated into DDL |
| [BPS-18](#bps-18-external-database-import-guard-can-be-bypassed) | Low | Open | Database | External database import guard can be bypassed |
| [BPS-19](#bps-19-sql-lint-gaps) | Info | Open | Tooling | `scripts/check-sql.php` misses several concatenation patterns |
| [BPS-20](#bps-20-hardening-notes) | Info | Open | Various | Hardening notes |

## Findings

### BPS-01: OAuth relay hostname is unregistered

**Severity:** High. **Status:** Fixed. **Confirmed.** The locations below refer to the code as reviewed.

**Location:** `methods/googledrive.php:80` (`callback_url`), `methods/googledrive/client.php:36-100` (`bootstrap()`), `methods/googledrive/client.php:351` (`use_master()`), `methods/dropbox/client.php:148` (`$callback`), `methods/dropbox/Dropbox2/OAuth/Consumer/ConsumerAbstract.php:235,284-306`.

**Description.** Google Drive and Dropbox support a "master app" mode in which a relay service holds the OAuth client secret and hands tokens to the site. The relay URL defaults to `https://auth.backupplusplus.com/...` unless `BACKUPPLUS_GOOGLEDRIVE_CALLBACK_URL` or `BACKUPPLUS_DROPBOX_AUTH_RETURN_URL` is defined. The hostname is a rename artefact of `auth.updraftplus.com`. On 2026-10-30, `whois backupplusplus.com` returned "No match for domain" and the name did not resolve, so anyone can register it.

Google Drive uses master mode whenever no custom client ID and secret are saved and `BACKUPPLUS_CUSTOM_GOOGLEDRIVE_APP` is not defined. In that mode every `bootstrap()` posts the saved `user_id` to the relay, base64-decodes `$body[0]` of the response, and uses the `access_token` it contains for all uploads, listings and deletions. Dropbox with the default app key sends the site's `refresh_token` to the relay in `refreshAccessToken()` and stores whatever `access_token` and `expires_in` come back. The Dropbox `getAccessToken()` path also accepts a base64 JSON token blob supplied directly as the OAuth `code`.

Sites enter this mode by default when an administrator connects Google Drive without entering a custom app. The settings importer (`includes/class-settings-importer.php:141-155`) also maps `updraft_googledrive` and `updraft_dropbox` from UpdraftPlus exports, so a site imported from UpdraftPlus with its master apps lands in master mode with a live `user_id` or refresh token. BPS-05 can additionally write `user_id` without the administrator's knowledge.

The plugin also ships UpdraftPlus's Google client ID and its Dropbox app key and secret (base64-encoded in `methods/dropbox.php:131` and `methods/dropbox/client.php:142`). These are third-party credentials that BackupPlus does not control.

**Exploit scenario.** An attacker registers `backupplusplus.com` and serves `/auth/googledrive` and `/auth/dropbox/`. Every BackupPlus site in Google Drive master mode now asks the attacker for an access token on each run; the attacker returns a token for an attacker-owned Drive, and every subsequent backup (database including `wp_users`, `wp-config.php`, uploads, plugins) is uploaded to the attacker. Dropbox default-app sites send their refresh tokens to the attacker. New administrators who click "authenticate" are redirected to the attacker's site, which can complete the flow with attacker-owned credentials.

**Remediation criteria.**

* No code path sends tokens, refresh tokens, user IDs or authorization codes to a hostname that the project does not control.
* Either the master-app paths for Google Drive and Dropbox are removed and a custom app is required, or the relay hostname is one the maintainers own and operate, with its renewal tracked.
* Embedded UpdraftPlus client IDs, app keys and secrets are removed.
* Importing UpdraftPlus master-app settings does not silently produce a master-mode connection; the destination is marked as needing re-authentication with a custom app.
* A regression test asserts that no plugin file contains a relay hostname outside an allowlist.

**Resolution.** The relay and both master-app paths are removed, along with the embedded UpdraftPlus client ID, app key and secret. Every criterion above is met:

* Google Drive requires the site's own OAuth client. Its redirect URI is the site's own settings page (`methods/googledrive/oauth.php`), and the access token is obtained from the refresh token directly with Google. Connections made through the shared client are not used for transfers and raise a dashboard notice until they are signed in again with an own client (`methods/googledrive/admin.php`).
* Dropbox uses OAuth with PKCE and a pasted code (`methods/dropbox/pkce.php`): the authorization page displays the code, the administrator pastes it into the settings page (or passes it to `wp backupplus remote authorize dropbox`), and the site exchanges it with `api.dropboxapi.com` using the PKCE verifier. Neither a redirect URI nor an app secret is involved, so a built-in app key can be shipped safely; it is empty until a BackupPlus Dropbox app is registered, and `BACKUPPLUS_DROPBOX_CLIENT_ID` or a per-connection app key replaces it. The verifier is stored with the instance's encrypted tokens and expires after ten minutes. Tokens are renewed directly with Dropbox, without a secret for PKCE tokens. Tokens issued to the shared upstream app are refused and the connection is marked as needing to be connected again.
* The settings importer drops Dropbox tokens of instances without their own app key; imported Google Drive shared-client connections get the notice above.
* `tests/Unit/Providers/DropboxPkceTest.php` covers the PKCE request, exchange, renewal and the refusal of legacy tokens; `tests/Unit/Providers/GoogleDriveOwnClientTest.php` covers the own-client rules and scans `methods/`, `includes/` and the top-level plugin files for the relay hostname and the upstream client identifiers.

The pasted code is protected by PKCE: a code obtained by anyone else cannot be redeemed without the verifier held by the site. The old relay-based "manual authentication" that accepted tokens and user IDs directly is removed.

### BPS-02: Remote file names can traverse out of the backup directory

**Severity:** Medium. **Status:** Open. **Confirmed.**

**Location:** `includes/backup-history/trait-rebuild.php:165` and `:440` (`rebuild()` name pattern), `methods/s3/base.php:781-786` (and the identical code in `methods/s3generic/` and `methods/dreamobjects/`), `includes/class-storage-methods-interface.php:327` (`get_remote_file()`), `includes/core/trait-remote-transfer.php:242-252` (`chunked_download()`), `includes/admin/trait-downloads.php:165-194`, `includes/restorer/trait-run.php:254-267`.

**Description.** "Rescan remote storage" adds every listed object whose name matches `^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-([\-a-z]+)([0-9]+)?(\.(zip|...))?$` to backup history. The `.*` accepts `/`, `\` and `..`. When an S3-family destination has a bucket sub-path, `listfiles_with_path()` strips the prefix but does not drop keys that still contain `/`; only the no-path branch filters them. OpenStack and Cloud Files also appear not to filter `/`; Dropbox and FTP were not confirmed either way.

The history entry is then used verbatim. `get_remote_file()` and `chunked_download()` build `backups_dir_location() . '/' . $file` and open it with `fopen( $fullpath, 'c+' )`; the restore path and the download handler's `delete` stage build the same path. The docblocks describe `$file` as a basename, but nothing enforces it. PHP resolves `missing-dir/../` textually, so the intermediate directory in the name does not need to exist.

The newer providers built on `BackupPlus_Remote_Storage_Module` (Azure, Backblaze, Google Cloud, OneDrive, pCloud) and SFTP/WebDAV reject `/` through `valid_filename()` and are not affected. `class-chain-downloads.php`, `class-backup-deletion.php` and retention use basename checks and are not affected.

**Exploit scenario.** An attacker with write access to the configured bucket prefix creates an object named `prefix/backup_2024-01-01-0000_x/../../../../var/www/html/wp-content/plugins/x/y.php._abcdef012345-db.gz`. When an administrator rescans remote storage (database restore also calls `rebuild( true )`, `includes/restorer/trait-database-restore.php:1057`) and then clicks Download or Restore for that entry, the attacker's bytes are written to the traversed path. The final component must still match the pattern's suffix, so a plain `.php` or `.htaccess` name is not possible, but `name.php._<hex>-db` is executed as PHP on Apache configurations that use `AddHandler`, and any existing file matching the pattern can be overwritten or, through the `delete` stage, unlinked. Unlike planting a malicious archive, this needs only a download, not a restore.

**Remediation criteria.**

* `rebuild()` rejects names containing `/`, `\`, NUL or `..`, before they reach history.
* Every sink that joins a history file name to the backup directory (`get_remote_file()`, `chunked_download()`, the download handler including its `delete` stage, restore) asserts `basename( $file ) === $file` and the backup-name pattern, independently of `rebuild()`.
* S3, S3-generic, DreamObjects, OpenStack, Cloud Files, Dropbox and FTP listings drop names containing a path separator after removing the configured prefix.
* A test lists a remote name containing `../` and verifies it is neither added to history nor written outside the backup directory.

### BPS-03: PITR source restore runs untrusted SQL with server-level privileges

**Severity:** Medium. **Status:** Open. **Confirmed.**

**Location:** `includes/pitr/trait-history.php:199-270` (`rescan()`), `includes/class-pitr-storage.php:184` (default encryption `none`), `includes/pitr/trait-recovery-plan.php:135-174` (`authenticate()`), `includes/pitr/trait-recovery.php:44` and `:249-251`, `includes/class-pitr-database.php:338-348` (`import()`), `includes/class-pitr-isolation.php:73`.

**Description.** PITR rescan imports chains from remote storage and marks them `'imported' => true`. Their integrity check compares artifacts with the manifest that came from the same storage, so nothing held by the site anchors them. PITR archives are plaintext by default; with age encryption, anyone holding the recipient public key can still produce a valid archive.

A full source restore runs `DROP DATABASE`, then `import( $sql, null, $direct )`. `import()` pipes the baseline into the `mysql` client as the restore account with `--binary-mode`. Because `$database` is `null`, no `--database` is passed, and the only content check on the baseline is a regex in `trait-recovery.php:131` that rejects EVENT statements. DEFINER clauses are kept. In reconstruct mode, the export from the disposable server also keeps `DEFINER=` and is imported through the restore connection.

Per `docs/binary-log-backups.md`, the restore account holds CREATE USER, SUPER or CONNECTION_ADMIN, SET_ANY_DEFINER (SET USER on MariaDB), REPLICATION_APPLIER and related grants, and `class-pitr-isolation.php:73` accepts `ALL PRIVILEGES`. The ordinary restorer, by contrast, runs as the WordPress account, strips DEFINER from routines, views and triggers, and forces `SQL SECURITY INVOKER`.

**Exploit scenario.** An attacker with write access to remote storage plants a chain whose manifest names `DB_NAME`, the right table prefix and a plausible snapshot coordinate, with a baseline containing `CREATE USER ...`, `ALTER USER` on a DBA account without SYSTEM_USER, or `CREATE DEFINER=bp_restore@localhost PROCEDURE wordpress.p() SQL SECURITY DEFINER ...`. An administrator rescans (typically during disaster recovery on a fresh site) and restores that point. The SQL runs as the restore account; a SQL SECURITY DEFINER routine owned by it remains in the WordPress schema, and anyone who later controls the WordPress database account can call it with the restore account's server-level rights.

**Remediation criteria.**

* Source restore (restore onto the live database) accepts only chains captured by this site, or chains whose manifest is authenticated with a key held by the site and not stored with the backups. Imported chains remain available for isolated export.
* Baseline and reconstructed exports are imported with `--database=DB_NAME`.
* DEFINER clauses are removed or rewritten to the WordPress application account and `SQL SECURITY INVOKER` is forced, matching the ordinary restorer.
* Statements that change accounts, grants or global server state (`CREATE/ALTER/DROP USER`, `GRANT`, `REVOKE`, `SET GLOBAL`, `SET PASSWORD`, `INSTALL PLUGIN` and similar) cause the restore to fail before anything is dropped.
* A test restores a planted chain containing each of these statements and verifies that the restore is refused and the database is untouched.

### BPS-04: Stored XSS from remote storage and archive contents

**Severity:** Medium. **Status:** Open. **Confirmed** for sinks (a), (b), (f) and (g); traced for the others.

**Location and description.** File names accepted by `rebuild()` (see BPS-02) may contain `<`, `>`, quotes and spaces, and are written to history unescaped. Archive entry names, dump header comments and remote error messages are also attacker-influenced. They reach these sinks without escaping:

* (a) `includes/admin/trait-existing-backups.php:873`: the Advanced Tools "Known backups (raw)" output is `var_dump( $history )` inside `<pre>` with no escaping, served as HTML.
* (b) `includes/admin/trait-existing-backups.php:32` and `:103`: `$backup['label']` and `print_r( $backup )` in the raw backup info. `rawbackup_history` (`includes/commands/trait-backups.php:176`) applies `html_entity_decode()`, and `includes/admin-common/ready-tools.js:11-12` passes the result through `textarea.innerHTML` and `.value` into `backupplus_html_modal`, which uses `.html()` (`includes/admin-common/modals.js:65`). Triggered when the administrator double-clicks the backup date.
* (c) The log line `Requested file from remote service: $service: $file` (`includes/class-storage-methods-interface.php:412`) is stored as `dlmessage` (`includes/core/trait-logging.php:293`) and rendered with `.html()` in `restore-status.js:176,182,197`.
* (d) Restore preflight errors and warnings that include `$file` (`includes/wpadmin-commands/trait-restore.php:144,147,153`) and database-scan warnings that copy `# Skipped tables:`, `SET NAMES`, the old site URL and the foreign-dump `# Label:` line (`includes/core/trait-database-analysis.php:171,278,313,316,413`) are rendered with `.html()` in `restore-status.js:43-91`.
* (e) `js/backupplus-admin-restore.js:166,168` render `restore_data.data.table` and `.routine_name` from the dump with `.html()`.
* (f) The zip browser and exclusion picker use jstree without `force_text`, so `includes/jstree/jstree.js:2615` assigns entry names through `innerHTML`. Names come from archive entries and from `readdir()` (`includes/wpadmin-commands/trait-jstree.php:55,79,86,238`).
* (g) `methods/googledrive/oauth.php:392` concatenates the Google account display name into the success notice, and `:351` concatenates error messages; `show_admin_warning()` does not escape them. *Fixed with BPS-01: both are now escaped.*
* (h) Rescan error messages (`includes/admin/trait-backup-sets.php:405`) print `$msg['message']`, which carries OpenStack and Dropbox exception text.

**Exploit scenario.** A bucket writer creates an object named `backup_2024-01-01-0000_<img src=x onerror=...>_abcdef012345-db.gz`. After a rescan, script runs in a network administrator's session when they open raw history, double-click the backup, download it or run restore preflight. Archive-based variants fire when an administrator browses or preflights an archive, before confirming a restore. Combined with BPS-01 or BPS-05, sink (g) fires from an attacker-chosen Google display name. Because decrypted storage credentials are rendered into the settings forms (BPS-20), the script can read every remote credential or create an administrator.

**Remediation criteria.**

* Every listed sink escapes its data for the HTML context (`esc_html()` server-side; `.text()` or explicit escaping client-side). The raw history and raw backup views escape their output after `var_dump()` and `print_r()`, and `rawbackup_history` no longer decodes entities.
* jstree is configured with `core.force_text: true` everywhere it is instantiated.
* `show_admin_warning()` callers pass escaped text, or the function escapes and callers opt in to markup explicitly.
* BPS-02's name validation is in place, so history names cannot contain markup.
* A test renders each sink with a name containing `<img src=x onerror=...>` and asserts the markup is escaped.

### BPS-05: Dropbox and Google Drive OAuth callbacks lack `state` validation

**Severity:** Medium. **Status:** Partly fixed. **Confirmed.** Dropbox is fixed by the BPS-01 change: it has no OAuth callback any more, a pasted code is accepted only through the nonce-checked `backupplus_ajax` dispatcher or WP-CLI, and it is redeemable only with the PKCE verifier the site generated. The Google Drive `success` state and its token-accepting path are removed. The Google Drive `token` and `revoke` states remain open as described below.

**Location:** `methods/dropbox/oauth.php:36-48,76-102`, `methods/googledrive/oauth.php:11-52,192-218,244-306`, `includes/core/trait-wordpress-hooks.php:155-200`.

**Description.** OAuth callbacks are dispatched on `admin_init` for any user who passes `user_can_manage()`, with no nonce, by GET or POST.

Dropbox compares `strcmp( $csrf, $state ) == 0`, where `$state` is the text before the first `:` in the `state` parameter. After a completed authorization `$opts['CSRF']` is set to `''`, and before any authorization it is unset. A callback with `state=:<instance_id>` therefore passes, and the attacker's `code` is stored. With the default app, `getAccessToken()` decodes that code directly into an access and refresh token without an exchange. If the instance already has a token, the planted code stays in `$opts['code']` and is consumed on the next re-authorization. On mismatch, the expected CSRF value is written to the PHP error log.

Google Drive has no state check at all. `state=success:<id>&user_id=...&access_token=...` writes `user_id` and `tmp_access_token`, without requiring `auth_in_progress`; `state=token:<id>&code=...` exchanges an attacker-chosen authorization code; `state=revoke:<id>` revokes and wipes the token.

OneDrive and pCloud implement this correctly: a 256-bit random state, stored hashed, bound to the user, expiring after 10 minutes, single use, compared with `hash_equals()`, and the flow is started with a nonce.

**Exploit scenario.** An attacker who knows a storage instance ID gets a logged-in administrator to load `wp-admin/options-general.php?page=backupplus&action=backupplusmethod-dropbox-auth&state=:<instance_id>&code=<base64 JSON of an attacker token>`, for example through an image tag or auto-submitting form on another site. The destination is silently rebound to the attacker's Dropbox and every later backup is delivered to them. The Google Drive `revoke` variant silently stops Drive backups; the `success` variant combines with BPS-01 and BPS-04 (g).

The precondition is the instance ID (`s-` plus an MD5 of random values, `includes/class-storage-methods-interface.php:519`). It is not guessable, but it is not treated as a secret: it appears in admin HTML, settings exports and logs. If one leaks, this becomes High.

**Remediation criteria.**

* Dropbox and Google Drive use the OneDrive/pCloud state scheme: random state generated when the flow starts, stored hashed and bound to the user and instance, single use, short expiry, compared with `hash_equals()`. An empty or missing state is rejected.
* Google Drive `success`, `token` and `revoke` all require a valid pending state; `revoke` additionally requires a nonce because it is not an OAuth return.
* CSRF comparison failures do not log the expected value.
* Tests cover an empty state, a reused state, a state for another user and an expired state.

### BPS-06: Backup uploads can store PHP files

**Severity:** Low. **Status:** Open. **Confirmed.**

**Location:** `includes/admin/trait-uploads.php:89-94`, `:125`, `:174-175`.

**Description.** The upload handler checks `$_POST['name']` against `^log\.[a-f0-9]{12}\.txt` or the backup-name pattern. The log pattern has no end anchor, so `log.abcdef012345.txt.php` passes, and a chunked upload with that name is assembled into `<backup directory>/log.abcdef012345.txt.php`. For a non-chunked upload, the stored file name comes from `$_FILES['async-upload']['name']` through `wp_handle_upload()` with `test_type => false`, while only `$_POST['name']` is validated; a valid POST name with a `shell.php` file part stores `shell.php`. Both bypass WordPress's usual block on PHP uploads by users without `unfiltered_upload`.

**Exploit scenario.** Requires an administrator and a nonce, so the impact is executing PHP where the site's hardening (`DISALLOW_FILE_EDIT`, `DISALLOW_FILE_MODS`, no `unfiltered_upload`) intends to prevent it. The backup directory's `.htaccess` blocks access on Apache; on nginx, or Apache with `AllowOverride None`, the random-named directory is served and the file executes. With BPS-07 it works on any server.

**Remediation criteria.**

* Both patterns are anchored at the end, and the extension is checked against an allowlist.
* The stored file name is the validated name, not the client-supplied multipart file name.
* A test uploads `log.<hex>.txt.php` and a mismatched `$_FILES` name and verifies both are rejected.

### BPS-07: Backup directory setting accepts any location

**Severity:** Low. **Status:** Open.

**Location:** `includes/class-manipulation-functions.php:252`, `includes/core/trait-entities.php:362-381`, `includes/filesystem-functions/trait-files.php:122-170`.

**Description.** The `backupplus_dir` setting accepts absolute paths and relative paths containing `..`. Once set, the plugin writes `index.html` (overwriting an existing one), `.htaccess` with `deny from all` if none exists, and `web.config` into that directory, and the temporary-file cleanup deletes `*.tmp` and `zi??????` files older than 12 hours there. Uploads (BPS-06) also go there.

**Exploit scenario.** An administrator on a hardened site sets the directory to `wp-content/mu-plugins` and uploads a PHP file through BPS-06; it is loaded on every request. Setting it to the web root replaces `index.html` and, if no `.htaccess` exists, takes the site offline.

**Remediation criteria.**

* The setting accepts only a single directory name under `WP_CONTENT_DIR` (or a path resolved with `realpath()` that stays inside it and is not a WordPress, plugin, theme or mu-plugin directory), unless a constant explicitly allows another location.
* Protection files are never overwritten; an existing `index.html` or `.htaccess` in a non-empty directory causes the setting to be rejected.

### BPS-08: mysqldump password file is world-readable and injectable

**Severity:** Low. **Status:** Open. **Confirmed.**

**Location:** `includes/backup/trait-database-tables.php:49-50`, `includes/core/trait-binaries.php:36-37`.

**Description.** Before running `mysqldump`, the plugin writes `"[mysqldump]\npassword=\"" . addslashes( $pass ) . "\"\n"` to `<backup directory>/<md5>.tmp` with `file_put_contents()`. The file gets the process umask (typically 0644). `backup_table_bindump()` has no `finally`, so a timeout or fatal error during a long dump leaves the file until the 12-hour temporary cleanup. `addslashes()` does not escape newlines, so a password containing a newline adds arbitrary `[mysqldump]` options such as `result-file=` (write to a chosen path) or `plugin-dir=` with `default-auth=` (load a shared library). The external database password in `backupplus_extradbs` is not checked for control characters (`includes/class-backup-sources.php:130-149`).

The password is correctly kept off `argv`. PITR already handles this safely: credentials go over a file descriptor 3 pipe, option files are 0600, and `\r`, `\n` and NUL are rejected (`includes/class-pitr-process.php:16`).

**Exploit scenario.** On shared hosting, another local account that can list the backup directory reads `DB_PASSWORD` (or an external database's password) while the dump runs or after it is interrupted. The newline injection requires an administrator, so it is a hardening issue.

**Remediation criteria.**

* The option file is created 0600 (or replaced by the PITR file descriptor 3 pipe) and removed in a `finally` block.
* Passwords containing `\r`, `\n` or NUL are rejected when saved and before the file is written, matching `BackupPlus_PITR_Process`.

### BPS-09: PITR writes `DB_PASSWORD` in plaintext under the backup directory

**Severity:** Low. **Status:** Open. **Confirmed.**

**Location:** `includes/class-pitr-admin.php:109-160` (writes `source.cnf`), `:242-268` (`private_directory()`), `includes/class-pitr-setup.php:14,33,62`.

**Description.** When PITR is configured, the WordPress database host, user and password are written as a `[client]` option file to `<backup directory>/pitr/source.cnf`. The file is 0600 and the directory 0700, and `.htaccess`, `web.config` and `index.php` guards are added, but the file is a non-PHP copy of `DB_PASSWORD` inside the web root, at a predictable name relative to the backup directory, kept for as long as PITR is configured. `docs/credential-encryption.md` states that the saved PITR backup account is encrypted, which is true, but does not mention this file. The credential key file uses a stronger guard: it is a PHP file beginning with `http_response_code(404); exit; __halt_compiler();`, which returns 404 even on servers that ignore `.htaccess`.

**Exploit scenario.** On nginx, where `.htaccess` is ignored, with PHP-FPM and nginx running as the same user and a backup directory with a legacy or predictable name (`backupplus`, `updraft` or a custom name), `GET /wp-content/<dir>/pitr/source.cnf` returns the database password. New installations use a 128-bit random directory name, which prevents this.

**Remediation criteria.**

* `source.cnf` lives in the credential key directory (or another private directory outside the backup directory), or is written as a PHP-guarded file and passed to the client through the file descriptor 3 pipe.
* `docs/credential-encryption.md` documents where the WordPress database credentials are written for PITR.

### BPS-10: Unsalted hash of decrypted credentials is stored in backup history

**Severity:** Low. **Status:** Open. **Confirmed.**

**Location:** `includes/class-incremental-backups.php:29`, `includes/core/trait-backup-boot.php:193-217,307`, `includes/core/trait-backup-completion.php:382`. Related: `includes/class-pitr-storage.php:62-66,108`, `includes/pitr/trait-state.php:132`.

**Description.** The incremental chain configuration fingerprint is `hash( 'sha256', serialize( [ site_url(), $configuration, $services, $instances, $credentials, ... ] ) )`. `$credentials` is the job `option_cache`, built from `BackupPlus_Options::get_backupplus_option()`, which returns decrypted provider settings (for example the FTP `pass`). The fingerprint is stored in job data and in backup history under `incremental.configuration`, which is in `wp_options` and therefore in every database backup. PITR `settings_sha256` and the PITR fingerprint hash decrypted provider settings and connection options in the same way, but are stored only in the local PITR state file.

**Exploit scenario.** An attacker obtains an unencrypted database backup, which is the threat credential encryption is designed to address. Every other field in the serialized structure (host, user, path, instance IDs, site URL, exclusions, key directory) is readable in the same dump, so the attacker reconstructs it and runs an offline dictionary attack on the one unknown field. This is practical for a single destination with a human-chosen FTP, SFTP or WebDAV password and impractical for high-entropy API keys.

**Remediation criteria.**

* Fingerprints that need to change when credentials change are computed over the stored ciphertext envelopes, or with an HMAC keyed from the credential key ring, never over plaintext secrets.
* Existing history entries do not need rewriting, but the next full backup replaces the fingerprint.

### BPS-11: Unauthenticated restore continuation relies on a weak job ID

**Severity:** Low. **Status:** Open. **Confirmed.**

**Location:** `admin.php:102,104`, `includes/admin/trait-restore.php:140-153`, `includes/core/trait-backup-dispatch.php:25`, `includes/filesystem-functions/trait-files.php:26`.

**Description.** `wp_ajax_nopriv_backupplus_ajaxrestore_continue` is registered for unauthenticated users. It needs no nonce and no login; it checks only that the request's `job_id` equals the `backupplus_restore_in_progress` site option, using `!==` rather than `hash_equals()`. The job ID is `substr( md5( time() . wp_rand() ), 20 )`: 12 hex characters whose entropy is at most that of one `wp_rand()` call plus a guessable timestamp. The marker exists while a restore runs, and after a crashed restore until it is dismissed; the continuation path itself has no age limit.

A caller who knows the job ID can resume or replay the administrator's restore (including in parallel with the live one), receive the streamed restore log, and, with `backupplus_ajax_restore=continue_ajax_restore`, write arbitrary `hostname`, `username`, `password` and `connection_type` into the job's filesystem credentials, which are later loaded into `$_POST` for `WP_Filesystem`. On non-direct filesystems, the continuation page also prints a nonce for user ID 0, which is accepted by the unauthenticated `backupplus_ajaxrestore` path.

No unauthenticated leak of the job ID was found, and brute-forcing it online within a restore window is impractical, so this is Low. The same weak value names log files and backup archives.

The authenticated `backupplus_ajaxrestore` path relies on the nonce alone and has no `user_can_manage()` check, unlike the `backupplus_ajax` dispatcher. `downloadlatestmodlog` (`includes/core/trait-wordpress-hooks.php:97`) likewise checks only a nonce. Neither is exploitable while nonces are only issued to managers.

**Remediation criteria.**

* Restore continuation uses a separate `random_bytes( 32 )` token, stored hashed, compared with `hash_equals()`, and expiring after a fixed period.
* The continuation path does not accept filesystem credentials; they can only be supplied by an authenticated manager.
* `backupplus_ajaxrestore` and `downloadlatestmodlog` check `user_can_manage()` in addition to the nonce.
* Job and backup nonces are generated from `random_bytes()`.

### BPS-12: PHP database dumper escaping ignores the connection charset

**Severity:** Low. **Status:** Open. **Confirmed.**

**Location:** `includes/backup/trait-database-tables.php:674`, `includes/database-utility/trait-statements.php:18-140`.

**Description.** The PHP dumper escapes string values with `str_replace()` of `\` and `'`. This is correct for utf8, utf8mb4 and latin1, but in GBK, GB18030, Big5, SJIS and CP932 the byte 0x5C (`\`) can be the second byte of a two-byte character. A stored value ending in such a character is written as `...\x81\x5C\x5C'`; on restore the server reads `\x81\x5C` as one character and `\'` as an escaped quote, so the string does not terminate and the next attacker-controlled value is parsed as SQL. Stacked queries are not possible (`mysqli_query`), but extra rows can be injected into the same `INSERT`, for example into `usermeta` capabilities. A `SET NAMES gbk` header on a utf8mb4 dump triggers the same re-pairing. The restore-side statement splitter is byte-based in the same way. The same multibyte issue applies in principle to `%i` identifier quoting in `includes/class-sql.php:124-130`, but no path was found where a low-privileged user controls an identifier.

**Exploit scenario.** On a site whose `DB_CHARSET` is one of the affected charsets, a commenter or subscriber stores a crafted value; a later restore of that backup runs attacker-chosen SQL as the WordPress database user.

**Remediation criteria.**

* String values are escaped with `mysqli_real_escape_string()` on the dump connection, or values containing bytes ≥ 0x80 are written as hex literals.
* Alternatively, dump and restore refuse to run when the connection charset is one where 0x5C or 0x60 can be a trail byte.
* `%i` rejects identifiers containing bytes ≥ 0x80 unless the connection charset is utf8, utf8mb4, latin1 or ascii.

### BPS-13: Sub-site administrators can arm the network-wide uninstall wipe

**Severity:** Low (multisite only). **Status:** Open. **Confirmed.**

**Location:** `includes/class-backupplus-deactivation.php:34-42,116-124`, the bundled deinstall dialog's confirm handler, `uninstall.php:14-20`.

**Description.** The deactivation dialog object is created on any site's `plugins.php` and on admin-ajax without a BackupPlus capability check. `handle_user_choice()` states that "the capability check is handled by the dialog library", which checks only `activate_plugins`. On a network where the Plugins menu is enabled for site administrators, they have that capability. The handler then writes any sanitized string to the network-wide `backupplus_deinstall_option` site option.

**Exploit scenario.** A sub-site administrator sets the option to `yes`. When a super administrator later uninstalls the plugin, `uninstall.php` deletes all BackupPlus settings and data for the whole network.

**Remediation criteria.**

* `handle_user_choice()` requires `BackupPlus_Options::user_can_manage()` and accepts only `yes` or `no`.
* The dialog is only instantiated for users who pass `user_can_manage()`.

### BPS-14: Decrypted legacy database backups can remain on disk

**Severity:** Low. **Status:** Open.

**Location:** `includes/core/trait-database-analysis.php:56-68,655-657`, `includes/class-backupplus-encryption.php:40-44,219-241`.

**Description.** Restore preflight of a legacy (UpdraftPlus Rijndael) encrypted database calls `BackupPlus_Encryption::decrypt( $db_file, $encryption )` with `$to_temporary_file = false`, which renames the plaintext to `backup_...-db.gz` beside the `.crypt` file. The cleanup is commented out. If the restore reaches database unpack, the file is recreated and removed; if the administrator cancels after preflight or the restore fails earlier, a plaintext database dump remains permanently. It is not a temporary file and nothing in history references it. Separately, `spool_crypted_file()` calls `readfile()` then `unlink()` without `ignore_user_abort()` or `finally`, so an aborted manual decrypt download leaves `decrypt_<random>.tmp` until temporary cleanup runs.

**Exploit scenario.** An encrypted backup is silently left decrypted at rest, defeating the purpose of encrypting it for anyone who later reads the backup directory or its off-site copies. File names are random or nonce-based, so this is persistence of plaintext rather than direct disclosure.

**Remediation criteria.**

* Preflight decrypts to a private temporary file and removes it in a `finally` block.
* Decrypt downloads remove the temporary plaintext in a `finally` block, with `ignore_user_abort( true )`.

### BPS-15: Custom source restore takes its destination from the archive

**Severity:** Low. **Status:** Open. **Confirmed.**

**Location:** `includes/class-source-restore.php:64,88-94`.

**Description.** Preflight checks the destination recorded in history, but the restore writes to `$manifest['source']['path']` from the `backupplus-manifest.json` inside the archive, unless the administrator entered an override. Only the backup and credential key directories are refused.

**Exploit scenario.** An attacker who can replace an archive in remote storage with one of the same name makes an "Additional files" restore write to any absolute writable path, such as the web root or `mu-plugins`. Restoring a malicious archive is already a high-trust action, but here what the interface shows and what is written differ.

**Remediation criteria.**

* The restore uses the destination from history (or the administrator's override); a manifest path that differs from history aborts the restore.

### BPS-16: Amazon S3 silently falls back to plain HTTP without cURL

**Severity:** Low. **Status:** Open. **Confirmed.**

**Location:** `methods/s3/base.php:115-122,1423-1442`.

**Description.** When `curl_init` is missing, Amazon S3 uses the AWS SDK compatibility class. `get_bucket_access()` then finds no `curl_version()`, sets `$use_ssl = false`, and `S3compat::setSSL( false )` switches the endpoint scheme to `http`. Backups and request signatures are sent in plaintext; the only trace is a log line. The bundled Guzzle stack could have used TLS through its stream handler. Separately, `$curl_ssl_supported` tests the constant `CURL_VERSION_SSL` rather than masking `features` with it; that bug fails safe.

**Remediation criteria.**

* A missing cURL or missing cURL SSL support is an error unless the administrator has explicitly selected `nossl`; the SDK compatibility path uses TLS through Guzzle's stream handler.
* `$curl_ssl_supported` tests `$curl_version['features'] & CURL_VERSION_SSL`.

### BPS-17: Restore charset and collation are concatenated into DDL

**Severity:** Low. **Status:** Open.

**Location:** `includes/restorer/trait-database-tables.php:153-156,198,210,223`, `includes/restorer/trait-database-restore.php` (routine charset replacement, around line 887).

**Description.** `backupplus_restorer_charset` and `backupplus_restorer_collate` come from the restore request and are inserted into `CREATE TABLE` and routine DDL by string replacement, then executed through `sql_exec()`, which the SQL lint exempts. They are not checked against the server's character sets and collations. The `SET NAMES` path already quotes the charset with `%i`.

**Exploit scenario.** None today beyond an administrator who can already restore arbitrary SQL. It becomes SQL injection if any lower-privileged or CSRF path to starting a restore is introduced.

**Remediation criteria.**

* Both values are validated against `SHOW CHARACTER SET` and `SHOW COLLATION` before use, and rejected otherwise.

### BPS-18: External database import guard can be bypassed

**Severity:** Low. **Status:** Open. **Confirmed.**

**Location:** `includes/class-sql-stream.php:95`.

**Description.** External database restore blocks statements beginning with `CREATE|DROP|ALTER DATABASE` or `USE`. Statements with qualified names (`DROP TABLE otherdb.t`, `INSERT INTO otherdb.x`), a leading comment (`/* */ USE x`) or executable comments are not blocked and run on the target connection.

**Exploit scenario.** A tampered external-database dump modifies other schemas reachable by the target account.

**Remediation criteria.**

* Leading comments are stripped before the check, executable comments are rejected or evaluated, and qualified references to another schema are rejected; or the target account is documented as needing access to only the target schema, and the guard is described as a convenience rather than a boundary.

### BPS-19: SQL lint gaps

**Severity:** Info. **Status:** Open.

**Location:** `scripts/check-sql.php`.

**Description.** The repository passes the lint, and no SQL injection was found in current code: `BackupPlus_SQL` binds `?` values with real server-side `mysqli_prepare()`, and `%i`, `%a` and `%d` are validated. The lint, however, would not catch several ways of reintroducing concatenated SQL. Each of the following was confirmed to pass the lint with a sample file, except fragment forgery, which was noted from the code:

* Concatenation split across statements into an executor: `$q = 'DELETE FROM '; $q .= $t; $x->script( $q );`. The adjacency check (around line 124) only flags a keyword literal directly beside `.`, and any method named `script` or `sql_exec` on any object accepts any variable (around line 118).
* Lowercase keywords: the concatenation keyword check (around line 131) has no `/i` flag.
* Keywords missing from the list (around line 44): `WITH`, `EXPLAIN`, `DO`, `LOAD`, `HANDLER`, `PREPARE`, `EXECUTE`, `FLUSH`, `OPTIMIZE`, `ANALYZE`, `BEGIN`, `START`, `XA`.
* Literals that do not start with a keyword: `' WHERE id = ' . $t`, `'/*!40101 SET NAMES ' . ...` (`includes/backup/trait-database.php:930`), `'--execute=' . $sql`.
* Static calls: fully qualified `\BackupPlus_SQL::fragment( $t )` (PHP 8 `T_NAME_FULLY_QUALIFIED`), `self::`, `static::`, and static calls on other classes (around lines 99-100). Plain functions are checked only when their name starts with `mysql` (around line 113).
* Dynamic calls: `$wpdb->$m( ... )`, `call_user_func()`, `array( $wpdb, 'query' )`.
* Unlisted sinks: `mysqli::real_query()`, `mysqli::multi_query()`, `mysqli::send_query()`, `PDO::exec()`, and `$wpdb->insert/update/delete/replace` (which interpolate the table name; current uses in `includes/restorer/trait-database-tables.php:598,611` use the plugin's own prefix).
* Other builders: `implode()`, `strtr()`, `str_replace()`, `sprintf()` with a non-literal format.
* Fragment forgery: only `new BackupPlus_SQL_Fragment` is detected; `new $cls`, Reflection and unserialization are not, and the constructor is public.

If a site defines `MYSQL_CLIENT_FLAGS` with `MYSQLI_CLIENT_MULTI_STATEMENTS`, WordPress's connection would execute stacked statements; `BackupPlus_SQL` could detect this and refuse.

**Remediation criteria.**

* The lint tracks string variables assigned from SQL-keyword literals through `.=` and flags them when passed to an executor; matches keywords case-insensitively; flags any literal containing a SQL keyword at a token boundary that is concatenated with a variable; resolves `\`, `self::` and `static::` static calls; flags dynamic calls on `$wpdb` and `mysqli` objects; and includes the listed sinks.
* `BackupPlus_SQL_Fragment` can only be constructed by `BackupPlus_SQL` (private constructor and a named factory).
* Each pattern above has a negative fixture in the lint's tests.

### BPS-20: Hardening notes

**Severity:** Info. **Status:** Open.

These are not vulnerabilities on their own, but several make the findings above worse or could become findings if the surrounding code changes.

* **Decrypted secrets in admin HTML.** `includes/class-storage-methods-interface.php:55-70` sends decrypted instance settings to the browser, and templates render them as `value="{{secretkey}}"`, `{{pass}}` and so on (for example `methods/s3/base.php:1220`, `includes/class-remote-storage-module.php:181`). Only Dropbox and Google Drive override `filter_frontend_settings_keys()`. Advanced Tools "Options (raw)" (`includes/admin/trait-existing-backups.php:908-913`) prints every option decrypted except PITR credentials, and raw backup info prints decrypted job data. Any admin XSS (BPS-04) therefore yields every remote credential. Write-only placeholders would remove this.
* **`phpinfo` diagnostics** (`includes/wpadmin-commands/trait-diagnostics.php:78-105`) dump environment variables and every defined constant, including `DB_PASSWORD`, the salts and an environment-supplied `BACKUPPLUS_CREDENTIAL_KEYS`.
* **Administrator power tools.** `call_wordpress_action` runs an arbitrary `do_action_ref_array()` with JSON arguments (`includes/admin/trait-ajax.php:464-522`); `backupplus_broadcastaction` runs an arbitrary `do_action()` (`includes/admin/trait-settings-page.php:61-69`); `httpget` is an SSRF tool that returns the full response and skips `wp_http_validate_url()` whenever the plugins directory is writable (`includes/admin/trait-http.php:36-121`). All need a manager and a nonce; they matter on sites where administrators are deliberately restricted.
* **Unused server-side decrypt endpoint.** `admin_post_backupplus_decrypt_database` (`includes/class-database-encryption.php:178-205`) accepts an age private identity and passphrase over POST. It is capability-, nonce- and POST-protected, but no interface uses it (only `scripts/check-age-encryption.php`), and `docs/database-backup-encryption.md` says private keys never reach the server. Consider removing it.
* **Legacy Rijndael format.** The UpdraftPlus format (zero IV, no MAC, raw passphrase truncated at 32 bytes) is still decrypted server-side using the stored `backupplus_encryptionphrase` (`includes/class-backupplus-crypto.php:18-24`, `includes/class-backupplus-encryption.php:168-205`, `includes/restorer/trait-unpack.php:308-316`), contrary to `docs/database-backup-encryption.md`, which directs legacy archives to the Extract tab. There is no downgrade from age: `decrypt_stream` dispatches on the `age-encryption.org/` prefix and never falls back.
* **Credential key retirement.** Rotation keeps every historical key (`includes/class-credential-key-store.php:706`), so the current key file decrypts every credential ever written. This is documented; a retirement command would limit it.
* **age archives are not bound to their name or backup set.** A remote-storage attacker can swap or roll back archives; this is caught only where local history checksums exist.
* **Plaintext transports.** FTP defaults to `encryption => 'none'` (`methods/ftp.php:54`); WebDAV accepts `http://` with Basic or Digest authentication (`methods/webdav/client.php:120`); ntfy accepts an `http` server and sends its bearer token over it (`includes/class-ntfy.php:29`). S3-generic configurations created before `signature_version` existed default to SigV2 (`methods/s3generic.php:187`). ntfy reports go to public `ntfy.sh` by default, protected only by the topic name.
* **Incremental deletion and symlinks.** `includes/restorer/trait-entity-restore.php:446-460` checks parents for symlinks but not the target; a pre-existing symlink to a directory is followed by `WP_Filesystem_Direct::delete( $path, true )`.
* **Zip browser extraction** (`includes/wpadmin-commands/trait-jstree.php:323-324`) extracts to `extract-<hex>-<original name>`, keeping `.php`, and deletes it only when the second download stage runs.
* **Decompression.** The zip disk-space check only runs under cron (`includes/filesystem-functions/trait-unzip.php:398`); zstd is correctly capped.
* **Shell values from configuration.** `DB_NAME` (`includes/core/trait-binaries.php:90`), `BACKUPPLUS_DIR` (`includes/core/trait-environment.php:50`) and `BACKUPPLUS_BINZIP_OPTS` (`includes/class-zip.php:484`) are interpolated into shell commands unescaped. They come from server configuration, not users. `zip -@` reads newline-separated names on standard input (`includes/class-zip.php:533`), so a file name containing a newline could add another path to an archive.
* **Template includes.** `extract( $extract_these )` runs before `include $template_file` (`includes/admin/trait-templates.php:81-83`); a key named `template_file` would redirect the include. No caller passes one; `EXTR_SKIP` would prevent it.
* **Range handling.** For `bytes=-N` the partial file servlet sends N+1 bytes and the start position can go negative (`includes/class-partialfileservlet.php`, `getStartPosition()`); there is no out-of-bounds read.
* **Binary discovery.** `native_directory()` (`includes/class-pitr-admin.php:204-216`) takes candidates from the PHP process `PATH`, which could contain relative or writable entries.
* **`.htaccess` syntax.** Protection files use Apache 2.2 `deny from all`; on Apache 2.4 without `mod_access_compat` this yields a 500, which still denies access.
* **Minor defects that fail closed.** `includes/admin/trait-ajax.php:159` compares `'backupplus_'` with an 8-character substring, so the `doaction` branch is unreachable; do not "fix" it without adding a capability check. `includes/backup-module/trait-authentication.php:70,119` read `BackupPlus_<id>auth` while links send `backupplus_<id>auth`, so those nonce-checked authorization links never fire (fixed with BPS-01). `includes/admin/trait-restore.php:87-94` clears the restore marker on `action=backupplus_restore_abort&job_id=` without a nonce. `.tar` and `.tar.gz` restore requires `includes/PEAR/Archive/Tar.php`, which is missing. `get_outgoing_ip_address()` (`includes/core/trait-http.php:66`) and `methods/pcloud/BackupPlus_Pcloud_API.php` are unused.

## Reviewed and found sound

These areas were reviewed and no issue was found. They are listed so that future reviews know what was covered.

**Authentication and authorization.** The `backupplus_ajax` dispatcher enforces its nonce before anything runs and re-checks `user_can_manage()` (only three dismiss subactions use `update_plugins`); `call_user_func` reaches only public command methods. `user_can_manage()` uses `manage_options`, or `manage_network_options` on the main site of a network, and sub-site administrators cannot reach any BackupPlus administration surface. Downloads, uploads, settings save and import, the database decrypt download, credential key management and recovery download, source tables, settings wipe, old directory deletion and restore start all check both a capability and a nonce. Pre-update backups use a nonce, a per-batch capability check and a 128-bit batch ID. Heartbeat nonce refresh, log download, `backupplus_spool_file` and `get_log` check the capability and validate the job ID. Every place the credential-test, download and settings nonces are created is gated by `user_can_manage()`. No open redirects were found.

**Cryptography.** The PHP age implementation verifies the header MAC (HKDF of the file key with label `header`) with `hash_equals()`, parses headers strictly (canonical base64, 64-column lines, line, header and stanza limits), rejects an all-zero X25519 shared secret, implements the ssh-ed25519 tweak and RSA-OAEP-SHA256 (minimum 2048 bits) correctly, and implements STREAM with an 11-byte counter and final flag, rejecting truncation, trailing data, empty non-first final chunks and counter exhaustion. Output is staged to a 0600 temporary file and published only after full authentication. The ChaCha20-Poly1305 wrapper requires the full 16-byte tag and verifies it with `hash_equals()`. Credential envelopes use random 96-bit nonces and 256-bit keys, bind version, key ID, blog, option and field path (or job ID and field) as AAD, fail closed, and unserialize with only `stdClass` allowed. The key store uses a 128-bit random directory, 0700 and 0600 permissions, a PHP 404 guard, atomic writes and correct locking; environment-supplied keys never fall back to disk. Settings export omits all provider options. Age restore tickets are `random_bytes( 32 )`, bound to user, blog, time and job. The browser decryptor requires a secure context, bounds the header and scrypt work factor, and publishes plaintext only after authentication. CLI secrets are read from `/dev/tty` with echo disabled and never placed on `argv`. Security-relevant randomness uses `random_bytes()` or phpseclib's CSPRNG, except the job ID (BPS-11).

**SQL.** `BackupPlus_SQL` binds `?` values server-side with `mysqli_prepare()` and `bind_param()`, so binding is unaffected by charset or `NO_BACKSLASH_ESCAPES`. `%a` is a strict allowlist, `%d` is regex-checked, and `%f`, `%l` and `%w` accept only fragments compiled from templates. Every `unserialize()` uses `allowed_classes => false` (or `stdClass` for credentials); the job data store also checks a hash and re-serialization, and search-replace leaves objects as `__PHP_Incomplete_Class`. Restore table prefixes come from WordPress, the temporary prefix is random and checked unused, and dump-derived names reach only `%i`, `?` or the dump's own text. The ordinary restorer strips DEFINER and forces `SQL SECURITY INVOKER`, and skips `GTID_PURGED`, `SQL_MODE`, `USE` and `CREATE/DROP DATABASE`.

**PITR processes and replay.** Credentials reach native clients through a file descriptor 3 pipe, never `argv`, the environment or a file; option names and values reject CR, LF and NUL; files and directories are 0600 and 0700; `--defaults-file` allows only `[client]` connection keys and `--no-login-paths` is set; `PATH` is fixed and `proc_open()` uses no shell; `--binary-mode` disables client commands; remote connections require a CA and verify the server certificate. Binlog replay verifies CRCs, fails closed on unknown events, limits DDL, rejects executable comments and blanks account-control events. Isolation checks use bound values. The grants required by the code match `docs/binary-log-backups.md`.

**Archives and files.** The tar reader rejects absolute paths, `..`, backslashes, NUL and drive letters, accepts only files and directories, and verifies checksums. ZIP extraction validates every entry and writes symlink entries as regular files. PITR archive members are tied to the manifest and SHA-256 verified. Chain downloads, backup deletion, retention, the database decrypt download, `backupplus_spool_file`, log download, `fetch_log`, age plaintext upload, `plupload_action2`, the restore inventory, incremental v2 deletion paths and multisite uploads restore all validate names. Every dynamic include uses a constant or internally validated path. Temporary files use random names in private directories. mysqldump `--where`, user, host and table name are escaped. SFTP remote commands use `escapeshellarg()`.

**Remote storage.** TLS verification is on by default for every provider, using the bundled CA bundle; `disableverify` is off by default and clearly labelled. `BackupPlus_Remote_Storage_HTTP` (Azure, Backblaze, Google Cloud, OneDrive, pCloud) allows only HTTPS on port 443 to an allowlisted host checked on every URL including server-returned ones, follows no redirects, restricts cURL to HTTPS and bounds response sizes. OneDrive and pCloud OAuth state handling is correct. SFTP and SCP require a pinned SHA-256 host key fingerprint before sending credentials; there is no trust on first use. FTP over TLS uses `CURLUSESSL_ALL` with peer and host verification. WebDAV follows no redirects and keeps credentials out of URLs. ntfy follows no redirects. The GitHub recipient fetch uses HTTPS with verification, no redirects and a size cap. No log line was found that writes a password, secret or token, and restore options redact the passphrase.

**Output escaping.** The backup table template uses `esc_attr()` and `esc_html()` throughout; Handlebars triple braces are used only for developer-controlled strings; imported settings render through escaped double braces; `fetch_log`, the last log message, `httpget` output and settings-page `error`/`message` parameters are escaped; restore activity output goes through `wp_kses_post()`. There are no window `message` listeners and no `eval()` or `new Function()` in the admin sources.

## Suggested order of work

1. BPS-01: remove or replace the master-app relay. This is the only finding that an outside party can exploit at scale without any access to a site.
2. BPS-02 and the name validation part of BPS-04: one validation function for remote file names, applied in `rebuild()` and at every sink.
3. BPS-05: port the OneDrive/pCloud state scheme to Dropbox and Google Drive.
4. BPS-03: restrict PITR source restore to locally captured or authenticated chains and strip DEFINER.
5. The remaining BPS-04 sinks, then BPS-06 to BPS-11.
6. BPS-19, so that the SQL rule is enforced as strictly as it is stated.
