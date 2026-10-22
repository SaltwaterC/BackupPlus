# Encryption and credential-storage findings

Reviewed: 2026-10-22. SEC-001 through SEC-004 are fixed in the working tree; SEC-005 remains open.

This records a source review of the current BackupPlus working tree, whose plugin header reports version 3.0.0. The reviewed tree includes the SFTP/SCP provider and the migration to phpseclib 3. Findings apply to this tree; affected published versions have not been established.

The review covered the plugin's encryption, credential persistence, and manual database-decryption paths. It was not a complete application or third-party dependency audit. No production credentials, production database contents, historical browser profiles or production server logs were inspected. Verification uses synthetic credentials and disposable local browser activity and logs. Impact statements describe what the code permits under the stated access conditions; exploitation against a deployed site was not attempted.

## Findings register

Priorities are implementation priorities, not CVSS scores. P1 means address promptly; P2 means address as part of the same hardening work.

| ID | Finding | Priority | Status |
| --- | --- | --- | --- |
| SEC-001 | Dropbox OAuth token encryption uses a shared hardcoded key and lacks ciphertext authentication | P1 | Fixed (working tree) |
| SEC-002 | Database-backup encryption uses a fixed IV, direct passphrase conversion, and unauthenticated CBC | P1 | Fixed (working tree) |
| SEC-003 | Manual decryption puts the key in a URL and displays it on the page | P1 | Fixed (working tree) |
| SEC-004 | Failed database decryption includes the supplied key in its response | P2 | Fixed (working tree) |
| SEC-005 | Provider credentials and backup encryption phrases lack plugin-level encryption in WordPress options | P1 | Open |

The only shared hardcoded data-encryption key found in the original reviewed first-party code was the Dropbox key in SEC-001. After remediation, it remains only in the one-time credential upgrade; normal token reads and writes cannot use it. This is a scoped search result, not proof that no other security issues exist. OAuth application identifiers and application secrets are a separate concern from the key used to encrypt stored user tokens.

## SEC-001: Dropbox tokens use a shared hardcoded encryption key

**Original evidence (before the fix).** `BackupPlus_BackupModule_dropbox::bootstrap()` constructed the encrypter with the literal 32-byte key:

```php
$encrypter = new Dropbox_Encrypter('ThisOneDoesNotMatterBeyondLength');
```

The original `Dropbox_WordPress::set()` serialized and encrypted access-token values before saving them in provider options. `Dropbox_Encrypter::encrypt()` used AES-256-CBC, PKCS#7 padding, and a fresh random 16-byte IV, storing `Base64(IV || ciphertext)`. It added no MAC or authentication tag. The decryptor checked encoding, length, and padding without cryptographically authenticating the stored value.

**Impact and conditions.** Anyone who obtains the encrypted token from a database copy, settings export, or another disclosure can recover it using the publicly available plugin source. No site-specific secret is needed. The random IV does not make a public shared key secret. Token permissions and validity determine the access a recovered token grants. An attacker able to change the stored value can also tamper with unauthenticated ciphertext; the resulting application effects were not demonstrated.

This concerns locally stored OAuth credentials. It does not describe Dropbox's own server-side encryption or add encryption to backup archives uploaded to Dropbox.

**Remediation.** Use authenticated encryption with a random installation-specific key kept separately from the database and exported settings. Define key rotation, missing-key behavior, and migration before changing the writer. Keep the old decoder only for an explicit one-time credential migration, then remove it. Rotate or reauthorize tokens if exposure is suspected. This follows [OWASP's guidance on authenticated encryption and separating keys from encrypted data](https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html).

**Verification required before closure.** Using disposable tokens, migrate a value produced with the old key, verify subsequent writes use the new format, reject modified ciphertext, and demonstrate that a database copy plus public source cannot decrypt the new value without the installation key. Verify restart, restore, and rotation behavior.

**Resolution (2026-10-22; fixing commit pending).** The runtime now uses a versioned AES-256-GCM envelope with a random 96-bit nonce and full 128-bit authentication tag. It authenticates the version, key ID, WordPress blog ID and Dropbox instance ID before returning plaintext for unserialization. Access and request token writes require encryption; failed encryption preserves existing credentials.

BackupPlus automatically generates a random installation key file on activation and upgrades, defaulting to a protected folder under wp-content and falling back to writable uploads storage on restricted hosts. No SSH, manually generated keys or external server setup are required. The guarded PHP file uses owner-only permissions, HTTP access protections and archive exclusions. Keys never enter the database or settings exports. WordPress Settings provides authorized, nonce-protected controls to relocate the file (including outside wp-content/docroot), download/restore a recovery copy and rotate keys. Hosts can optionally override the directory; key generation remains automatic. Managed location metadata cannot be imported through settings.

The one-time automatic credential upgrade prepares/verifies every replacement before one options update and records a per-site completion marker in the protected key file, outside the database. The old decoder is private to that upgrade and is never used by normal reads; inserting legacy ciphertext or deleting a database marker after completion does not reopen it. Administrators can deliberately convert restored legacy settings through an authorized Settings action. Invalid inputs and failed saves preserve saved credentials. Existing tokens require no CLI migration or reauthorization on a successful upgrade. Lost managed keys are never silently replaced; administrators without a recovery copy can explicitly create a replacement in Settings and reconnect Dropbox without server access, while preserving old ciphertext for later recovery. Browser recovery verifies uploaded data against saved credentials before publishing it, activates the uploaded file's active key while retaining newer keys, and never executes the file. Relocation preserves keys and rotation retains historical keys for old backups/running jobs. An annual WordPress cron job rotates the shared key and re-encrypts stored credentials across the network, with a file-lock timestamp guard, per-site completion markers and daily retries that reuse the published key after failures. Multisite key maintenance is restricted to network administrators. See the deployment notes for [storage, upgrade, recovery and rotation behavior](dropbox-token-encryption.md).

**Verification evidence.** `make test` passes (asset checks, PHP unit tests, FTP/SFTP/SCP/WebDAV integration tests and WordPress checks). The Dropbox regression checks cover an independently produced disposable legacy CBC fixture, automatic one-time migration, protected installation file creation, shared-host uploads fallback, concurrent creation, relocation outside the document root, failure preservation, key rotation and verified browser recovery. Crypto checks cover OpenSSL interoperability, the portable phpseclib engine, fresh nonces, changes to every nonce/ciphertext/tag byte and the header, wrong installation keys, context binding and restart/database-only/full restore. The real WordPress check exercises activation, persisted token encryption, archive filters, unauthenticated HTTP denial of the key file, Settings controls, rotation, recovery, permissions and nonce rejection using disposable options/files. The recovery HTTP regression uses a disposable administrator and isolated key storage to verify the downloaded attachment, no-store headers, exact current file format, upload/restore roundtrip, restoration of an older active key after rotation without losing newer credentials, idempotence/conflict handling, and nonce/method/authentication/capability rejection. A fresh-process regression covers key metadata updates before crypto has loaded. Existing vendor/WP-CLI PHP deprecation notices remain.

**Remaining limitations.** The protected default file is inside the web filesystem where shared-host permissions require it; administrators can move it outside the document root through Settings when permitted. Other backup products require separate exclusion rules. Historical database copies encrypted under the old public key remain vulnerable; revoke/reauthorize Dropbox tokens if exposure is suspected. Normal key rotation retains old keys and does not revoke Dropbox tokens. Recovery copies must be kept separately from backups.

## SEC-002: Database-backup encryption preserves an unsafe legacy format

**Original evidence (before the fix).** `BackupPlus_Encryption::encrypt()` created its cipher without an IV argument. [`BackupPlus_Crypto::rijndael()`](../includes/class-backupplus-crypto.php#L15) then:

- Uses CBC with a 128-bit block and an all-zero initial IV.
- Rounds the raw passphrase length to 16, 20, 24, 28, or 32 bytes, zero-pads short values, and truncates values longer than 32 bytes.
- Uses those bytes directly as the key, without a salt or password-derivation work factor.

The original backup encryption writer added no MAC or authentication tag. Its decryptor inspected file signatures and trailing padding; these checks do not authenticate the archive. Resumption uses the previous ciphertext block to continue CBC, rather than generating a new initial IV for each archive.

**Impact and conditions.** For encrypted archives made with the same passphrase, identical plaintext prefixes produce identical ciphertext prefixes. An attacker holding an archive can try candidate passphrases without a password-derivation cost. Different long passphrases sharing the first 32 bytes produce the same effective key. An attacker able to alter an archive can change unauthenticated ciphertext; reliable malicious restoration or a padding-oracle exploit has not been established by this review.

The passphrase is user supplied, rather than a second shared hardcoded key. The finding concerns the format used when these encryption routines are invoked, not a claim that every backup is encrypted automatically.

**Remediation.** Introduce a versioned authenticated archive format with a random nonce and salted, costed password derivation. Keep old-format reading for existing backups while making new writes use the new format. Large-file and resumed encryption need a reviewed streaming construction that authenticates chunk order and completion and never reuses a nonce under the same key. OWASP recommends [authenticated encryption and secure random generation](https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html#cipher-modes).

**Verification required before closure.** Restore the saved pre-migration fixtures; ensure repeated new encryptions differ; reject wrong passwords, modified headers, modified ciphertext, reordered chunks, and truncation; exercise interrupted encryption and restoration. Test passphrases longer than 32 bytes to confirm their suffixes affect the new key derivation.

**Resolution (2026-10-22; fixing commit pending).** New encrypted backups use standard age v1 with native X25519 or SSH RSA/Ed25519 public recipients, an authenticated header and bounded ChaCha20-Poly1305 STREAM segments. Settings offer only Disabled or age public keys. Backup passphrase writing has been removed. Invalid or stale password-mode jobs fail closed; a saved legacy phrase cannot trigger a backup writer or enter new backup job snapshots. Restore and the local browser decryptor retain read-only compatibility with the original UpdraftPlus CBC passphrase format. See [the format and deployment notes](database-backup-encryption.md).

Every age encryption attempt starts with a fresh file key, payload nonce and ephemeral recipient keys. Retries restart unfinished parts with fresh randomness. Decryption stages output and publishes it only after the entire authenticated archive verifies. Wrong identities, corruption, ordering errors and incomplete archives remove staged plaintext and preserve existing destinations. Legacy CBC remains unauthenticated and is clearly identified as such in the browser decryptor.

**Verification evidence.** Regression checks restore saved pre-migration database vectors and the large legacy fixture, reject password writing and stale password configurations, and verify native X25519 and SSH RSA/Ed25519 age interoperability against the independent age CLI, including encrypted private keys, exact boundaries, corruption, ordering and truncation. File archive tests cover split-part retries, checkpoints, plaintext cleanup and file-only restoration. Browser checks confirm local decryption, legacy passphrase support, cancellation cleanup and no file/key uploads. Existing third-party PHP deprecation notices remain.

**File archive coverage.** The same configured encryption now covers each completed file ZIP part as well as database dumps. Encrypted jobs defer early ZIP uploads, checkpoint ciphertext sizes/checksums per part, and remove completed plaintext archives before dispatch. Restore preparation authenticates all selected encrypted file parts and carries only file bindings into the restore job; those bindings survive replacement of the options table by a database restore. Ordinary downloads preserve ciphertext, while the local browser decryptor supports both `.gz.crypt` and `.zip.crypt`. Regression tests cover split-part failure/retry, cleanup, rescan/incremental recognition, file-only preflight, changed-file rejection, and both ZIP extractors.

**Remaining limitations.** Interrupted encryption restarts from the beginning, so hosts with strict execution deadlines may require a longer execution window for large archives. Abrupt termination can leave owner-only temporary files for the existing reaper. Older plugin/decryptor versions cannot read the new format. Legacy CBC archives retain their original weaknesses; only recreation gives them authentication. Legacy reading now requires block alignment and a recognizable compressed-file or SQL-comment signature; arbitrary legacy binary inputs without those signatures are rejected. Format signatures do not authenticate legacy backups. This implementation follows a published construction and has interoperability checks; it has not received an independent cryptographic audit. SEC-005 remains separate outstanding work.

## SEC-003: Manual decryption exposes keys in URLs and page content

**Original evidence (before the fix).** The `FileUploaded` callback built a download link containing `&decrypt_key=` followed by the URL-encoded contents of the decryption-key input. The same statement appended the key visibly to the page. `BackupPlus::handle_url_actions()` read `decrypt_key` from the GET parameters and passed it to the download/decryption path.

**Impact and conditions.** Following the link places the key in the request URL. Depending on deployment and browser behavior, it can be retained in browser history, proxy/access logs, monitoring systems, and referrer data. The visible page text is another disclosure surface. Someone with access to one of these records may learn the key without access to the WordPress options table. URL encoding and HTTPS do not prevent endpoint-side URL retention. Referrer disclosure is policy dependent and is not asserted to occur on every deployment. [CWE-598 describes this exposure mechanism](https://cwe.mitre.org/data/definitions/598.html).

**Remediation.** Submit the key in the body of an authorized, nonce-protected POST over HTTPS. Do not include it in a query string or redisplay it. If a later download request is necessary, use an opaque, short-lived reference bound to the authorized user and file; it must not contain the key. Avoid persistent secret storage and apply no-store response handling and log redaction.

**Verification required before closure.** Complete manual decryption with a synthetic sentinel key and assert that it is absent from generated links, request URLs, visible page text, redirects, and error messages. Check representative access/proxy logs and browser history in a disposable environment. Verify that an expired or differently authenticated download reference fails.

**Resolution (2026-10-22; implementation commit `34956fba`, closure regression in the working tree).** The Decryption tab now processes archives and private identities entirely in a bundled browser worker. It creates local Blob download links only after successful decryption and complete authentication for modern formats. It has no upload, secret-bearing URL, POST decryption request, or server fallback. Key fields are cleared when processing starts, and cancellation/clear removes temporary browser output. Compatibility server downloads retain authorized nonce-protected POST bodies, generic errors and no-store responses. Older GET decryption routes reject the request. Age restore preparation uses a separate opaque, short-lived reference to already-verified plaintext, bound to the authorized user, blog, backup timestamp and restore job; no identity is retained with it.

**Verification evidence.** The real WordPress HTTP check exercises successful identity downloads and rejects GET, invalid nonce, invalid filename, anonymous and subscriber requests; it verifies no-store attachments and generic failure bodies. Restore reference tests cover different users/blogs/backups/jobs, expiration and file tampering. The JavaScript regression rejects the previous `decrypt_key` URL construction and key-display expression. The real Chromium regression decrypts native/SSH age and legacy fixtures, checks local Blob output and cleared input fields, and checks corrupted input and cancellation cleanup.

The closure regression generates a unique synthetic passphrase containing URL metacharacters, successfully decrypts an archive and clicks its Blob download link, verifying the downloaded plaintext exactly. It rejects raw, URL/form-encoded and JSON-escaped credentials in success/failure page content, generated links, download records, request URLs/bodies/headers, redirects and browser navigation history, including after navigating away and back. A temporary HTTP proxy captures page and worker traffic and access records including Referer and Location; no archive upload or server-decryption request is allowed. The regression reads the local WordPress container's access/error logs for the same interval. A control request must appear in both proxy and server logs, and a worker request must appear in the proxy records, preventing an empty capture from passing. All captured records omit the submitted test credentials. The browser profile, downloads and proxy are cleaned up after the run. See [the local verification instructions](../dev/README.md#manual-decryption-security-regression).

Verified on 2026-10-22: `node scripts/test-client-decryption-browser.mjs`, `node scripts/test-client-decryption.mjs` (69 decoder checks), `php scripts/test-age.php`, the real WordPress `scripts/check-age-encryption.php` check, and `node scripts/build-decryptor.mjs --check` all pass. These satisfy SEC-003's current-code closure criteria.

**Remaining limitations.** This evidence covers disposable local activity and representative HTTP access/proxy logs. Historical production browser records and logs have not been audited or removed; this fix cannot retract keys previously disclosed by vulnerable versions.

## SEC-004: Decryption failures echo the supplied key

**Original evidence (before the fix).** On failure, `BackupPlus_Encryption::spool_crypted_file()` appended the supplied key to a text response after the message `The decryption key used:`. This can be a manually supplied value or the configured phrase used as a fallback. HTML escaping does not redact it.

**Impact and conditions.** The attempted key is exposed in the response whenever this failure branch runs. It may subsequently be captured in screenshots, support attachments, browser tooling, or systems that record response bodies. No authentication bypass is established here; the issue is unnecessary disclosure within an otherwise authorized operation. A failed attempt may still disclose a valid key used with a corrupted or unrelated file.

**Remediation.** Return a generic failure message without the key. Keep secrets out of diagnostics and logs. If correlation is necessary, use an operation identifier unrelated to the secret.

**Verification required before closure.** Trigger the failure branch using synthetic keys and invalid or corrupted files. Assert that neither a directly supplied key nor a configured fallback phrase appears in the response or captured diagnostics.

**Resolution (2026-10-22; fixing commit pending).** Decryption failures return HTTP 400 and a generic message with no supplied key or key passphrase. Failed downloads do not return attachment headers. Crypto exceptions are logged using a generic message rather than exception arguments or traces.

**Verification evidence.** The WordPress HTTP regression attempts decryption with a synthetic directly supplied backup phrase, a configured fallback phrase, and an encrypted SSH identity with a wrong key passphrase. It asserts that failure bodies omit all supplied secrets and publish no attachment. Restore-start serialization checks also confirm that top-level and nested age identities/key passphrases are discarded before job persistence, while ordinary options remain available.

## SEC-005: Provider secrets are stored directly in WordPress options

**Evidence.** [`BackupPlus_BackupModule::save_options()`](../methods/backup-module.php#L75) copies the provider's options directly into its settings array. [`BackupPlus_Options::update_backupplus_option()`](../options.php#L79) passes the value to WordPress's `update_option()`. The corresponding getter returns it through `get_option()`. Hooks exist for external customization, but no built-in encryption handler for these hooks was found.

Examples include FTP and WebDAV passwords and the SFTP [`pass`, `key`, and `key_passphrase` fields](../methods/sftp.php#L24). The SFTP provider added in this working tree follows the same persistence design. Consequently, an encrypted private key and the passphrase needed to unlock it can be stored together. The former `backupplus_encryptionphrase` setting was also stored without an encryption wrapper. Passphrase backup writing and its settings field have now been removed; previously saved phrases can still be read as a restore-only fallback. Dropbox access tokens originally had the separate fixed-key wrapper described in SEC-001; the SEC-001 fix replaces it with authenticated encryption under an automatically managed installation key. Other credentials listed here remain outside that fix.

**Impact and conditions.** Someone who can read the relevant options through a database disclosure, a database backup, or a settings export that includes secrets can recover the provider credentials. For SFTP, the stored key and passphrase may permit remote authentication; the account's permissions determine the impact. A settings input rendered as a password field only changes browser presentation. Deployment-level database or disk encryption and third-party option hooks were not assessed.

**Remediation.** Centralize secret-field handling so provider options, job snapshots, imports, and exports follow one policy. Use an external secret source where available, or authenticated encryption with a master key outside the database and exported backups. Account for restore portability, key rotation, and fail-closed behavior when the key is missing. Avoid exporting secrets by default. Encryption does not protect against an attacker who can run arbitrary PHP in the application and access its decryption key. See [OWASP's key-storage guidance](https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html#separation-of-keys-and-data).

**Verification required before closure.** Save synthetic credentials through the settings form and programmatic APIs, inspect raw option values and job snapshots, and confirm that secrets are absent in plaintext. Verify migration, reconnect, import/export, restore, missing-key handling, and rotation for each provider. Include SFTP private keys and their passphrases in these checks.

## Migration and closure notes

The phpseclib 3 migration removed the obsolete library; it did not itself resolve these application-level designs. SEC-001 and SEC-002 now have separate authenticated formats and regression evidence described above. [`scripts/test-encryption.php`](../scripts/test-encryption.php) covers both the new archive format and read-only compatibility with the saved [legacy fixtures](../scripts/fixtures/phpseclib2-compat.json).

The URL/response disclosure code was remediated alongside the age restore controls; SEC-003 browser/history/log verification is complete as recorded above. Coordinate SEC-001 and SEC-005 around a common secret-storage policy. Treat SEC-002 as a versioned file-format change: preserve restore support for existing archives without continuing unsafe new writes.

Close a finding only after its implementation and regression tests satisfy the relevant verification criteria. Record the fixing commit, test evidence, migration behavior, and any remaining limitations here. Follow the repository's [security reporting policy](../SECURITY.md) for external disclosure.
