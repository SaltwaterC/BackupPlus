# Archive compression

When the `zstd` executable is available, new backups use one Zstandard compression layer:

| Content | Archive | With age encryption |
| --- | --- | --- |
| Files, including full, incremental and pre-update backups | `-plugins.tar.zst`, `-uploads2.tar.zst`, etc. | `.tar.zst.crypt` |
| WordPress and external database SQL dumps | `-db.sql.zst`, `-db7.sql.zst` | `.sql.zst.crypt` |
| PITR full snapshots | `_point.tar` containing `_full.sql.zst` | `_point.tar` containing `.sql.zst.crypt` |
| PITR binary logs | `_point.tar` containing `_log0.bin.zst` and any additional logs | `_point.tar` containing `.bin.zst.crypt` members |

File archives contain ordinary POSIX tar entries, with PAX records for long paths and large files. Their contents are stored without ZIP or Deflate. Each split part is an independent tar/Zstandard archive containing its own incremental manifest. Compression can reuse repeated content across files within that part. SQL assembly feeds uncompressed SQL directly into Zstandard; there is no gzip layer inside the published `.sql.zst` file. Internal resumable per-table staging fragments may still use gzip and are decoded before final assembly. PITR point tar files hold independently compressed or encrypted members together with their manifest and locator; the outer tar itself is not compressed.

Zstandard uses level 6, a 32 MiB history window, a frame checksum and one thread. Encryption follows compression. Every completed part has its published size and checksum recorded, and uploads wait until compression/encryption finish. An interrupted compression leaves its source intact; completed names are checkpointed before staging cleanup. Previously published backups are not converted.

## Restore and interoperability

BackupPlus supports the new formats in local/remote rescans, uploads, downloads, quota accounting, archive browsing, additional-source metadata, file selection, incremental restoration and database preflight/import. Restoring an encrypted archive uses the same browser-side age preparation; private keys stay in the browser. The prepared compressed archive is decoded on the restore server.

Frames are fully decoded and checked before SQL import or extraction begins. Decoding uses bounded chunks, a 64 MiB native memory cap and an output budget based on available disk space. PITR additionally requires the exact uncompressed size and SHA-256 from its manifest. The tar reader checks header checksums and bounds, and rejects parent paths, absolute paths, symbolic links, hard links and special files.

Standard command-line tools can extract unencrypted file archives:

```sh
tar --zstd -xf backup_2026-10-26-1200_Site_0123456789ab-uploads.tar.zst
zstd -d backup_2026-10-26-1200_Site_0123456789ab-db.sql.zst
```

For ordinary age archives, decrypt with `age` first, retaining the `.tar.zst` or `.sql.zst` suffix. For PITR points, first extract the `.tar`, then decrypt an encrypted member with `age`.

## Compatibility and staging space

Existing ZIP and gzip backups remain supported. Hosts without a usable native process API or `zstd` retain the existing ZIP/gzip writers. The ordinary-archive preference can be disabled with `add_filter('backupplus_archive_zstd', '__return_false')`. The executable lookup and custom absolute-path filter are described in [PITR compression](pitr-compression.md). A job freezes its representation for retries; jobs already in progress when this feature is installed retain their old representation.

Restoring Zstandard archives requires `zstd` on the restore host. The development image includes it. Allow space for the uncompressed tar staging parts plus the compressed/encrypted part being finalized; early part uploads are deferred for these jobs. Restoring requires space for a decoded staging archive and extracted contents. Staging files are removed after successful finalization/restoration; interrupted work can retain them until resumption or temporary-file cleanup.

The twice-daily temporary-file sweep also removes abandoned `backup_…-entity.tar` and partial `.tar.tmp` staging parts older than 12 hours, including split and additional-source parts. It defers tar deletion while a backup is running or awaiting resumption, or while a restore is active. Tar files referenced by backup history, unrelated tar archives and symbolic links are preserved. Published `.tar.zst` and `.tar.zst.crypt` archives remain governed by backup retention and quotas.

The byte-for-byte PITR compression benchmark is in [PITR compression](pitr-compression.md). Compression ratios for ordinary files and databases depend on their contents.
