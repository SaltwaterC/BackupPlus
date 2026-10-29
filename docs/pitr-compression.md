# PITR binary-log compression

New binary-log archives prefer Zstandard level 6 with a 32 MiB history window and one compression thread. The frame is self-contained, with no external dictionary or dependency on another compressed archive. Restoring it reproduces the original log byte for byte, including event checksums, SQL annotations, source coordinates and ancestry evidence. No application or BackupPlus transactions are filtered out.

Gzip's short history window handles repeated small strings well, but repeated multi-hundred-kilobyte job records are too far apart to reuse effectively. Zstandard's larger window can reuse them. See the [Zstandard long-distance matching documentation](https://github.com/facebook/zstd/blob/dev/programs/README.md#long-distance-matching-mode).

## Measured example

A closed source binary log from a 59-second interval contained 180,400,828 bytes. Most of it came from repeated file-increment job-state and backup-history writes in `wp_options`.

The production codec benchmark produced:

| Representation | Stored bytes | Compression time |
| --- | ---: | ---: |
| Gzip, level 6 | 20,714,300 | 0.478 s |
| Zstandard, level 6, 32 MiB window | 1,098,937 | 0.236 s |

The Zstandard archive was 18.85 times smaller. Decoding took 0.102 seconds, and the reconstructed source's SHA-256 matched. Timings and compression ratios depend on workload, hardware and library versions; this is one measured log, not a universal reduction guarantee. Archive encryption adds its normal framing overhead after compression.

To reproduce on an unencrypted raw source binary log:

```sh
php scripts/benchmark-pitr-compression.php /path/to/binlog.000123
```

The benchmark reads the source, writes its outputs into a private temporary directory, verifies the lossless round trip, prints sizes/timings and removes those outputs. It does not publish archives or change database configuration.

## Compatibility and resource limits

- New log members use `.bin.zst`, or `.bin.zst.crypt` with age encryption. Each point's members, manifest and locator are stored together in one uncompressed `.tar` file. The manifest identifies Zstandard and binds the uncompressed size and SHA-256.
- New full SQL snapshots also use `.sql.zst` when Zstandard is available; the ordinary database importer supports both Zstandard and existing gzip snapshots. File and ordinary SQL archive behavior is documented in [archive compression](archive-compression.md).
- Existing `.bin.gz` archives remain readable. A chain may contain both codecs. Existing archives and manifest bindings are not rewritten.
- Without a `zstd` executable, capture falls back to gzip. Restoring `.zst` archives requires `zstd` on that host; a missing executable fails before replay.
- The development container installs `zstd`. Standard search locations are `/usr/bin`, `/usr/local/bin` and `/bin`. The `backupplus_pitr_zstd_binary` filter may supply an executable or return false to retain gzip.
- Decoding streams through PHP in bounded chunks. Output cannot exceed the manifest's declared raw size, and the native decoder has a 64 MiB memory limit. Truncated/corrupt frames and size/hash mismatches fail before recovery uses the decoded log.

This reduces archive storage and transfer volume. It does not reduce the source server's binary-log writes or the raw-log download volume. The separate [metadata persistence changes](binlog-write-volume.md) reduce unnecessary source-binlog writes from job-state and history updates.
