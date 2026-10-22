# Stored credential encryption

BackupPlus uses one installation key store and AES-256-GCM implementation for storage credentials. Dropbox's existing authenticated tokens remain readable through a compatibility adapter; other providers use the same cipher and key lifecycle through `BackupPlus_Credential_Storage`.

Provider passwords, SFTP private identities and key passphrases, S3 secrets/session tokens, API keys, OAuth credentials and legacy credential-bearing URLs are encrypted before WordPress saves them. Structured secret values retain their types. Extra database passwords and saved legacy backup phrases are covered too. Legacy phrases remain available only for restoring old archives; new archives use age public recipients.

WordPress options contain `bpcred:v1:<key-id>:<base64(nonce || ciphertext || tag)>` envelopes. Each provider field authenticates its site, option name and full field path, including the instance ID. Moving ciphertext to another provider, field, instance or site fails authentication. Runtime callers continue to use `BackupPlus_Options` and module APIs with ordinary values; direct `get_option()` returns the encrypted storage representation. Settings forms, direct `update_option()`/`add_option()` calls and programmatic module saves share WordPress sanitization hooks. Provider sanitizers receive decrypted inputs, including when WordPress sanitizes a new option twice.

Job persistence encrypts the complete option snapshot and filesystem credentials, plus external database connection passwords and legacy restore phrases. Those envelopes authenticate the job ID and field. Ordinary progress metadata stays readable for the history and cleanup code. Job APIs decrypt only into process memory; old keys remain available for previously saved jobs and backups.

## Upgrade and recovery

Activation and the first request after upgrading automatically convert existing provider settings and job snapshots. A separate per-site completion marker lives in the protected key file. Normal reads never accept nonempty plaintext secret fields after upgrading. An authorized administrator can deliberately convert restored legacy settings with **Settings → Credential encryption → Upgrade restored credentials**. New settings imports pass through the ordinary encrypted writer.

Maintenance prepares provider replacements before saving them, checks for intervening changes, and verifies persisted ciphertext. Individual saves that fail retain their previous values; successfully converted options remain encrypted if a later save fails. Completion is recorded only after every option and job succeeds, so retrying finishes partial work. Concurrent jobs or settings changes can require a retry.

The existing automatic key generation, protected file, archive exclusions, browser recovery download/upload, relocation and annual network rotation now cover all these credentials. Recovery verifies both provider fields and job snapshots before publishing a key file. Rotation rewrites provider fields and saved job credentials while retaining historical keys. See the [key management guide](dropbox-token-encryption.md) for hosting and recovery controls.

Missing keys are never silently replaced when managed metadata or encrypted provider/job data exists. Failed reads leave saved ciphertext intact, show an error and give providers empty secret fields. Job reads fail rather than run with unauthenticated snapshots. Failed encryption cannot write plaintext. Saving a page whose secrets were redacted cannot silently clear unreadable credentials; restore the key, delete the destination explicitly, or enter replacement credentials. Without a recovery copy, explicitly replace the missing key in Settings and reconnect storage destinations. Previous ciphertext is retained until deliberately replaced or deleted.

Preserve WordPress site IDs and storage instance IDs when restoring encrypted settings. If these change, reconnect the destination. Keep a recovery copy separately from backups, and download a fresh copy after rotation. Historical plaintext database copies and settings exports are unchanged by this upgrade.

## Settings exports

Browser exports omit complete provider connection options, extra database configurations and legacy secret options, including unsaved credentials and hidden OAuth values. Ordinary backup settings and age public recipients remain exportable. Configure storage connections separately on a new site. No key material is included. Importing an older export containing plaintext credentials encrypts them before persistence; encrypted fields require the matching recovery key and original context.

## Adding a provider

Discovered provider options named `backupplus_<provider-id>` automatically receive the shared policy. Common secret field names are listed in `BackupPlus_Credential_Storage::secret_fields()` and are recognized recursively in instances and templates. A provider with additional secret fields must register them before options are read or saved:

```php
add_filter('backupplus_credential_secret_fields', function($fields, $option) {
    if ('backupplus_example' === $option) $fields[] = 'custom_api_secret';
    return $fields;
}, 10, 2);
```

Use `BackupPlus_Options`/module APIs for runtime access and job APIs for snapshots. Do not write credentials with SQL or persist separate credential caches. The shared cipher is also available for a caller with a separate authenticated context, but custom persistence must participate in migration, recovery and rotation. Optional add-on implementations absent from this repository must declare any extra secret fields they introduce.

## Verification and limits

`php scripts/test-credential-storage.php` covers provider fields, structured secrets, extension fields, WordPress double sanitization, job APIs, field/context binding, automatic and explicit migration, failed saves, missing keys, recovery verification and network rotation with partial failures/retries. `scripts/check-credential-storage.php`, run by `make test-wordpress`, checks real WordPress persistence, registered form sanitizers, module saves, legacy import, combined legacy Dropbox token/custom-app-secret migration, raw database values, job resumption, key rotation, recovery and reconnection after lost-key replacement using disposable options and files. `node scripts/test-credential-export.mjs` exercises the shipped export function with saved, unsaved and hidden synthetic credentials. Existing Dropbox checks continue to cover authenticated cryptography, legacy CBC conversion and the protected file/HTTP lifecycle.

This protects a database copy without the separate installation key. It cannot protect credentials from PHP code that can read both the database and key file. Authorized settings pages and provider requests necessarily use decrypted credentials. Other backup tools need their own key-file exclusions. This change does not revoke previously exposed credentials or erase historical exports.
