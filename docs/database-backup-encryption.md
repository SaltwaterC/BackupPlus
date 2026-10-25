# Backup archive encryption

In **Settings → Backup encryption**, choose **Disabled** or **age public keys**. New encrypted backups use age only; passphrase-based backup writing has been removed. The setting covers database dumps and every file archive, including each split ZIP part, file-only backups, and new incremental file archives. A failed configured encryption stops backup dispatch. Existing unencrypted file archives, password archives, and legacy CBC database backups remain readable. Existing backups are not retroactively encrypted. A saved configuration or resumed job still requesting the removed passphrase mode fails with an error. Choose Disabled or age public keys, save, and start a new backup; a resumed job retains its original encryption configuration. A previously saved backup passphrase does not enable encryption and is no longer copied into new backup jobs.

Encrypted jobs finish creating their file archives before encrypting them and starting uploads. The early-upload optimization is disabled for these jobs. Each completed encrypted part is checkpointed separately, with ciphertext sizes and checksums, before its plaintext ZIP and local manifest are removed. Retries retain completed parts and restart only unfinished encryption with fresh randomness. Database backup dispatch also removes the completed plaintext dump after recording the encrypted archive. Allow disk space for all completed ZIP parts plus the encrypted output being written; an interrupted process can leave plaintext source archives until the job resumes. Live WordPress files are unchanged. Ordinary backup downloads return the encrypted archive; use the separate manual decryptor or the age executable for plaintext.

## age recipients and restoration

For age, enter one recipient per line:

- A native X25519 public recipient (`age1…`).
- An SSH RSA public key (`ssh-rsa …`, at least 2048 bits).
- An SSH Ed25519 public key (`ssh-ed25519 …`).
- `github:USERNAME`, to import the account’s supported public SSH keys.

To create a native recipient, click **Generate age key in browser**. The bundled upstream `age-encryption` library generates an explicit X25519 identity using Web Crypto randomness and derives its public recipient. Generation makes no server request. Download the private identity file, confirm you have saved it, and click **Add public recipient**. The public `age1…` recipient is appended to the form and age mode is selected. Click **Save Changes** to persist the public recipient; generation and adding it to the form do not save settings. Existing recipients are preserved. Cancelling discards the generated key without changing recipients.

The downloaded identity is an unencrypted standard age text file. Store it safely, separately from the backups. It is never included in the settings form, uploaded, logged, or saved in browser storage by the generator. Its temporary browser download link is revoked when the prompt closes. BackupPlus cannot recover a lost private identity.

Every saved recipient can independently decrypt the archive. Only public recipients are saved and copied into backup-job snapshots. A GitHub entry is validated and fetched from `https://github.com/USERNAME.keys` over verified HTTPS when saving age settings. Redirects, oversized responses, invalid keys, and accounts without supported keys fail without replacing the previous configuration. Unsupported SSH key types are skipped. The resolved public keys are pinned; jobs never fetch them again. Saving age settings again refreshes GitHub entries. Disabling encryption does not require GitHub access. This follows Zetta’s `github:USER` convention while resolving at settings-save time.

During restore, select the components to restore and provide the matching credential in the browser: a native age identity, an SSH/PEM private key and its key passphrase, or an `age -p` archive passphrase. Native X25519 and hybrid ML-KEM-768/X25519 identities are supported. The controls are also available for file-only restores. For legacy passphrase-encrypted archives, use the Decryption tab and upload the decrypted archives before restoring. Encrypted OpenSSH and encrypted PEM keys are supported, including RSA, Ed25519, and X25519 PKCS#8. Native age-keygen identity files with comment lines are accepted. Hardware plugins and encrypted native identity files are unsupported.

Restore downloads the selected encrypted archives into the browser and decrypts them with the same bundled Web Worker as the Decryption tab. Private identities and their key passphrases stay in the browser; the credential controls have no form names and are cleared on start. The worker authenticates each complete age archive before returning plaintext. The browser uploads completed plaintext in chunks of at most 512 KiB. The server checks the uploaded size and SHA-256 hash and confirms that the original ciphertext has not changed before making a staged file available to restore preflight. Failed decryption uploads no plaintext for that archive.

Subsequent restore requests use an opaque reference bound to the user, WordPress site, backup timestamp, and restore job. The reference expires after two hours and binds both the source ciphertext and prepared plaintext by SHA-256 hashes. When claimed, the job also retains these file bindings so database restoration can replace the transient table without breaking later file restoration. A background job can continue without the private key. If the reference expires or a prepared file changes, prepare the restore again.

Plaintext is staged in owner-only temporary server files. File restoration opens these files through the existing ZIP extractor, including selective extraction and extraction resumption, and removes staged plaintext after successful extraction. The encrypted originals remain unless archive deletion was requested. Database restoration copies and removes its staged file. Cancellation and transfer failures attempt to remove staged browser and server files. Abrupt termination can leave server files for the existing temporary-file reaper (normally twelve hours). Browser staging is removed after each upload. Restoration requires HTTPS (or localhost) and the same browser crypto and storage capabilities as the Decryption tab; there is no server-decryption fallback in the restore screen.

## Local browser decryption

The **Decryption** tab, next to **Advanced Tools**, accepts local `.gz.crypt`, `.zip.crypt`, or binary `.age` archives. Choose files, enter the backup passphrase or identity and its key passphrase, and click **Decrypt locally**. Each verified result gets a local download link. Files, passphrases and private keys go only to a bundled same-origin Web Worker; this tab makes no upload or server-decryption request and has no server fallback. HTTPS (or localhost), Web Crypto, Web Workers and a modern browser are required. The scripts are served by the WordPress installation, so its JavaScript remains part of the trust boundary.

The worker uses the pinned upstream JavaScript `age-encryption` library for native identities, header authentication, and payload streaming. SSH and PEM private keys load the official Go `agessh` implementation and Go private-key parsers in WebAssembly, with an upstream PKCS#8 library for PBES2 keys. The bridge receives private-key input and header stanzas; archive payloads stay in the JavaScript stream. X25519 PEM imports become native identities, preserving the library's Noble fallback when browser Web Crypto lacks X25519. CryptoJS remains only for read-only legacy Rijndael-CBC compatibility. No Node APIs, native modules, Go server, or CDN scripts are required by deployed sites.

The Go runtime and module load on demand for SSH/PEM keys. The plugin ships a precompressed module and matching runtime with versioned URLs. Browser decompression uses that copy where available; the raw module remains available without requiring host compression settings. SSH/PEM decryption requires browser policy to allow WebAssembly. Native age text identities and legacy archives do not load it. See the [implementation and build notes](agessh-wasm-prototype.md).

Supported private identities are:

- Native age X25519 and hybrid ML-KEM-768/X25519 identities, including age-keygen comments and multiple native identity lines.
- OpenSSH RSA (2048–8192 bits) and Ed25519 keys, unencrypted or protected with bcrypt and AES-256-CTR.
- PKCS#8 RSA, Ed25519 and X25519 PEM, unencrypted or PBES2/PBKDF2 with AES-128/192/256-CBC. PBKDF2 PRFs include HMAC-SHA1/256/384/512.
- Traditional PKCS#1 RSA PEM, unencrypted or protected with AES-128/192/256-CBC.

Other private-key ciphers and encrypted native identity files are unsupported. Age `-p` archive passphrases use the scrypt stanza and are supported separately from private-key passphrases. Key parsing is bounded to 64 KiB; scrypt work factors above 20 are rejected. The restore screen supports the same age identities and private-key formats as the Decryption tab.

Archive reads use bounded slices. Authenticated formats verify the header, every segment, segment ordering and the final flag before publishing a download. Legacy archives show an explicit integrity warning because that format has no authentication. Successful results are staged in the browser’s Origin Private File System. When that storage is unavailable, an in-memory fallback handles files up to 128 MiB per file; larger files fail locally with a storage explanation. The browser needs space for staged plaintext. Use **Clear files and keys** after downloading to revoke links and remove temporary browser copies. Cancellation terminates the worker and removes its staged output. Key input fields are cleared on start; credentials remain in memory only while processing the selected files and are discarded on completion or cancellation. Closing the tab also attempts cleanup; files abandoned by abrupt browser termination are reaped after 24 hours when the tool next opens.

Authorized nonce-protected server POST download endpoints remain for compatibility; the Decryption tab and restore screen do not invoke them. Older GET decryption URLs are rejected.

## age file format

Age mode writes standard binary [age v1](https://age-encryption.org/v1): native X25519 or [SSH RSA/Ed25519 recipient stanzas](https://github.com/FiloSottile/age/blob/v1.3.2/agessh/agessh.go), an authenticated header, and 64 KiB ChaCha20-Poly1305 STREAM chunks with authenticated counters and a final-chunk flag. Each encryption attempt starts with a fresh random file key, payload nonce, and ephemeral X25519 keys. Header parsing is limited to 64 KiB and 100 recipients. SSH recipients include the standard short public-key identifier.

The plugin uses `.gz.crypt` for database dumps and `.zip.crypt` for file archives. Backup history, rescans, uploads, downloads, providers, incremental sets, and retention recognize both. Each split ZIP is independently encrypted and authenticated. The contents are standard age and can be decrypted with the `age` executable regardless of the extension, for example:

```sh
age --decrypt --identity key.txt --output database.sql.gz backup_database.gz.crypt
```

The plugin uses the bundled phpseclib primitives and does not execute `age`, `ssh-keygen`, or another external process. The standalone `example-decrypt.php` shares the format dispatcher; its optional third argument supplies a private-key passphrase. Tests use the independent `age` executable and saved reference fixtures, including encrypted OpenSSH/PEM keys, multiple recipients, exact chunk boundaries, portable PHP engines, corruption, ordering and truncation.

## Original UpdraftPlus passphrase backups

Passphrase decryption supports only the original UpdraftPlus Rijndael-CBC format. Backup writing accepts age public recipients only. The legacy format has no authentication; signature checks can detect many wrong keys or damaged inputs but cannot prove integrity. The local decryptor labels these downloads accordingly.

## Failure and interruption behavior

Encryption reads and writes bounded segments and publishes the completed archive with a rename. The low-level encryption API preserves its source file; the backup pipeline removes completed plaintext archives after checkpointing the encrypted result. Every age attempt creates a fresh file key, payload nonce and ephemeral recipient keys, including retries after interruption. Staging files from an earlier attempt are never appended to or used as cipher state. Age encryption restarts the unfinished archive: a sufficiently large archive on a host with a strict execution deadline may need a longer execution window. Progress across terminated encryption processes is not preserved.

Age decryption uses a unique, owner-only staging file and authenticates the complete archive before returning a usable path or replacing a destination. Wrong identities, incorrect private-key passphrases, unsupported age versions, header changes, modified/reordered/duplicated/removed segments, truncation, and appended bytes fail. An error removes staged plaintext and preserves any existing restored file. An interrupted restore starts again and verifies the entire archive. Abrupt process termination may leave owner-only `.tmp` files for the existing temporary-file reaper; no such file is treated as a completed restore.

For age archives, no plaintext download or stdout output is published before complete authentication. The browser worker handles both restore preparation and local decryption; `example-decrypt.php` uses the PHP decoder. Both implementations use temporary staging and require sufficient local space.

## Compatibility

For legacy passphrase archives, no passphrase migration or installation key is needed. Age archives written by BackupPlus require a matching private identity; externally created `age -p` archives require their scrypt passphrase. Keep a compatible version of the plugin or the standalone decryptor: older versions cannot read these formats.

The old zero-IV CBC format is accepted only for reading. Its historical key rounding/truncation and partial-block padding remain compatible with the saved pre-migration fixtures. Legacy inputs must be block aligned and begin, after decryption, with a gzip/ZIP signature or a SQL comment header (`#` followed by whitespace, `-- `, or `/*` with a comment marker and at least twelve comment bytes). Arbitrary legacy encrypted binary files without those database/archive signatures are rejected. Signature checks reduce accidental format downgrade; they do not authenticate legacy backups. Recognized age headers never fall back to CBC after a decryption failure. Old backups retain their original weaknesses and must be recreated to gain authenticated encryption.

Stored provider credentials and any previously saved restore passphrases use the separate [credential encryption policy](credential-encryption.md) (SEC-005). The age mode stores only public recipients; restore identities remain in the browser.
