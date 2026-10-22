# Encryption and credential-storage findings

Reviewed: 2026-10-22. SEC-001 is fixed in the working tree; SEC-002 through SEC-005 remain open.

This records a source review of the current BackupPlus working tree, whose plugin header reports version 3.0.0. The reviewed tree includes the SFTP/SCP provider and the migration to phpseclib 3. Findings apply to this tree; affected published versions have not been established.

The review covered the plugin's encryption, credential persistence, and manual database-decryption paths. It was not a complete application or third-party dependency audit. No production credentials, database contents, browser history, or server logs were inspected. Impact statements describe what the code permits under the stated access conditions; exploitation against a deployed site was not attempted.

## Findings register

Priorities are implementation priorities, not CVSS scores. P1 means address promptly; P2 means address as part of the same hardening work.

| ID | Finding | Priority | Status |
| --- | --- | --- | --- |
| SEC-001 | Dropbox OAuth token encryption uses a shared hardcoded key and lacks ciphertext authentication | P1 | Fixed (working tree) |
| SEC-002 | Database-backup encryption uses a fixed IV, direct passphrase conversion, and unauthenticated CBC | P1 | Open |
| SEC-003 | Manual decryption puts the key in a URL and displays it on the page | P1 | Open |
| SEC-004 | Failed database decryption includes the supplied key in its response | P2 | Open |
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

**Evidence.** [`BackupPlus_Encryption::encrypt()`](../includes/class-backupplus-encryption.php#L178) creates its cipher without an IV argument. [`BackupPlus_Crypto::rijndael()`](../includes/class-backupplus-crypto.php#L15) then:

- Uses CBC with a 128-bit block and an all-zero initial IV.
- Rounds the raw passphrase length to 16, 20, 24, 28, or 32 bytes, zero-pads short values, and truncates values longer than 32 bytes.
- Uses those bytes directly as the key, without a salt or password-derivation work factor.

The backup encryption writer adds no MAC or authentication tag. The [`decryptor`](../includes/class-backupplus-encryption.php#L64) inspects file signatures and trailing padding; these checks do not authenticate the archive. Resumption uses the previous ciphertext block to continue CBC, rather than generating a new initial IV for each archive.

**Impact and conditions.** For encrypted archives made with the same passphrase, identical plaintext prefixes produce identical ciphertext prefixes. An attacker holding an archive can try candidate passphrases without a password-derivation cost. Different long passphrases sharing the first 32 bytes produce the same effective key. An attacker able to alter an archive can change unauthenticated ciphertext; reliable malicious restoration or a padding-oracle exploit has not been established by this review.

The passphrase is user supplied, rather than a second shared hardcoded key. The finding concerns the format used when these encryption routines are invoked, not a claim that every backup is encrypted automatically.

**Remediation.** Introduce a versioned authenticated archive format with a random nonce and salted, costed password derivation. Keep old-format reading for existing backups while making new writes use the new format. Large-file and resumed encryption need a reviewed streaming construction that authenticates chunk order and completion and never reuses a nonce under the same key. OWASP recommends [authenticated encryption and secure random generation](https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html#cipher-modes).

**Verification required before closure.** Restore the saved pre-migration fixtures; ensure repeated new encryptions differ; reject wrong passwords, modified headers, modified ciphertext, reordered chunks, and truncation; exercise interrupted encryption and restoration. Test passphrases longer than 32 bytes to confirm their suffixes affect the new key derivation.

## SEC-003: Manual decryption exposes keys in URLs and page content

**Evidence.** The [`FileUploaded` callback](../includes/backupplus-admin-common.js#L3995) builds a download link containing `&decrypt_key=` followed by the URL-encoded contents of the decryption-key input. The same statement appends the key visibly to the page. [`BackupPlus::handle_url_actions()`](../class-backupplus.php#L462) reads `decrypt_key` from the GET parameters and passes it to the download/decryption path.

**Impact and conditions.** Following the link places the key in the request URL. Depending on deployment and browser behavior, it can be retained in browser history, proxy/access logs, monitoring systems, and referrer data. The visible page text is another disclosure surface. Someone with access to one of these records may learn the key without access to the WordPress options table. URL encoding and HTTPS do not prevent endpoint-side URL retention. Referrer disclosure is policy dependent and is not asserted to occur on every deployment. [CWE-598 describes this exposure mechanism](https://cwe.mitre.org/data/definitions/598.html).

**Remediation.** Submit the key in the body of an authorized, nonce-protected POST over HTTPS. Do not include it in a query string or redisplay it. If a later download request is necessary, use an opaque, short-lived reference bound to the authorized user and file; it must not contain the key. Avoid persistent secret storage and apply no-store response handling and log redaction.

**Verification required before closure.** Complete manual decryption with a synthetic sentinel key and assert that it is absent from generated links, request URLs, visible page text, redirects, and error messages. Check representative access/proxy logs and browser history in a disposable environment. Verify that an expired or differently authenticated download reference fails.

## SEC-004: Decryption failures echo the supplied key

**Evidence.** On failure, [`BackupPlus_Encryption::spool_crypted_file()`](../includes/class-backupplus-encryption.php#L351) appends the supplied key to a text response after the message `The decryption key used:`. This can be a manually supplied value or the configured phrase used as a fallback. HTML escaping does not redact it.

**Impact and conditions.** The attempted key is exposed in the response whenever this failure branch runs. It may subsequently be captured in screenshots, support attachments, browser tooling, or systems that record response bodies. No authentication bypass is established here; the issue is unnecessary disclosure within an otherwise authorized operation. A failed attempt may still disclose a valid key used with a corrupted or unrelated file.

**Remediation.** Return a generic failure message without the key. Keep secrets out of diagnostics and logs. If correlation is necessary, use an operation identifier unrelated to the secret.

**Verification required before closure.** Trigger the failure branch using synthetic keys and invalid or corrupted files. Assert that neither a directly supplied key nor a configured fallback phrase appears in the response or captured diagnostics.

## SEC-005: Provider secrets are stored directly in WordPress options

**Evidence.** [`BackupPlus_BackupModule::save_options()`](../methods/backup-module.php#L75) copies the provider's options directly into its settings array. [`BackupPlus_Options::update_backupplus_option()`](../options.php#L79) passes the value to WordPress's `update_option()`. The corresponding getter returns it through `get_option()`. Hooks exist for external customization, but no built-in encryption handler for these hooks was found.

Examples include FTP and WebDAV passwords and the SFTP [`pass`, `key`, and `key_passphrase` fields](../methods/sftp.php#L24). The SFTP provider added in this working tree follows the same persistence design. Consequently, an encrypted private key and the passphrase needed to unlock it can be stored together. The [`backupplus_encryptionphrase` setting](../options.php#L212) is also registered as a string without an encryption wrapper. Dropbox access tokens originally had the separate fixed-key wrapper described in SEC-001; the SEC-001 fix replaces it with authenticated encryption under an automatically managed installation key. Other credentials listed here remain outside that fix.

**Impact and conditions.** Someone who can read the relevant options through a database disclosure, a database backup, or a settings export that includes secrets can recover the provider credentials. For SFTP, the stored key and passphrase may permit remote authentication; the account's permissions determine the impact. A settings input rendered as a password field only changes browser presentation. Deployment-level database or disk encryption and third-party option hooks were not assessed.

**Remediation.** Centralize secret-field handling so provider options, job snapshots, imports, and exports follow one policy. Use an external secret source where available, or authenticated encryption with a master key outside the database and exported backups. Account for restore portability, key rotation, and fail-closed behavior when the key is missing. Avoid exporting secrets by default. Encryption does not protect against an attacker who can run arbitrary PHP in the application and access its decryption key. See [OWASP's key-storage guidance](https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html#separation-of-keys-and-data).

**Verification required before closure.** Save synthetic credentials through the settings form and programmatic APIs, inspect raw option values and job snapshots, and confirm that secrets are absent in plaintext. Verify migration, reconnect, import/export, restore, missing-key handling, and rotation for each provider. Include SFTP private keys and their passphrases in these checks.

## Migration and closure notes

The phpseclib 3 migration removed the obsolete library; it did not resolve these application-level designs. Existing [`scripts/test-encryption.php`](../scripts/test-encryption.php) and [`fixtures`](../scripts/fixtures/phpseclib2-compat.json) test legacy database ciphertext compatibility. Passing those tests is not evidence that SEC-002 is fixed. SEC-001 has separate authenticated-storage and migration checks described above.

Address the URL and response disclosures without waiting for a ciphertext-format migration. Coordinate SEC-001 and SEC-005 around a common secret-storage policy. Treat SEC-002 as a versioned file-format change: preserve restore support for existing archives without continuing unsafe new writes.

Close a finding only after its implementation and regression tests satisfy the relevant verification criteria. Record the fixing commit, test evidence, migration behavior, and any remaining limitations here. Follow the repository's [security reporting policy](../SECURITY.md) for external disclosure.
