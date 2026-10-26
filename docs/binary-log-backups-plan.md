# Binary-log incremental database backups

Status: guarded snapshots, native capture, encrypted artifacts and source recovery are implemented. The [account design](database-scoped-pitr-design.md) defines the current deployment: an optional encrypted backup login and a request-only restore login, with the ordinary WordPress account retaining database-scoped permissions. The [operating guide](binary-log-backups.md) contains current grants and limitations. Earlier dedicated-server assumptions below are historical design notes; the account design supersedes them.

## Objective and release boundaries

Add optional MySQL and MariaDB binary-log backups between full database dumps, using BackupPlus storage providers, age encryption, job scheduling and retention. Require a new coordinated full backup at least every 24 hours. Make incremental capture configurable independently of full database backups and incremental file backups.

Preserve ordinary whole-database and selected-table restoration. Deliver whole-database PITR first, then selected-table historical recovery by reconstructing the database at the requested point and exporting the selected tables.

Initial PITR support requires tested native database clients, working WordPress scheduled tasks, InnoDB tables, complete database coverage, accessible binary logs and sufficient database privileges. Capture uses the configured backup account, or an existing WordPress account with sufficient capture grants, and does not require WP-CLI. Target MySQL 8.4 and MariaDB 10.11/11.4 as the initial integration-test matrix; publish exact tested server/client patch versions before release. Treat other combinations as unsupported until tested. No cross-vendor replay is promised.

Keep one application database as the recovery scope. Native replay filters that database, neutralizes server account-administration events and refuses cross-schema DDL and mixed-schema transactions. Ordinary logical backups remain independent.

AWS RDS and Percona support are deferred until needed. The [hosting and vendor gap register](binary-log-hosting-gaps.md) records the RDS snapshot/permission/retention blockers, Percona validation gaps and evidence required before either can be supported. These items are outside the initial release acceptance gates; no implementation or relaxed validation is included in this deferral.

## Repository findings

| Area | Existing behavior and integration point |
| --- | --- |
| Full database backups | `BackupPlus_Backup::backup_db()` (`includes/backup/trait-database.php`, composed into `backup.php`) iterates tables, resumes table fragments and stitches a final dump. `backup_table_bindump()` invokes a native dumper for one table. Neither path establishes one coordinated snapshot for the whole database. |
| Incremental backups | `includes/class-incremental-backups.php` implements file inventories and file chains. Its configuration, seven-day rollover and local inventories are specific to files. Database log chains need separate state and validation. |
| History and discovery | `includes/class-backup-history.php` builds incremental file sets and recognizes specific archive names/extensions. New artifacts require explicit discovery, import and indexing support. |
| Storage | `includes/class-storage-methods-interface.php` and `methods/backup-module.php` supply provider selection, instances and transfer integration. Keep database capture independent of provider implementations. |
| Encryption | `includes/class-backupplus-encryption.php`, `includes/class-database-encryption.php` and the backup dispatch pipeline support age and job configuration snapshots. Browser restore preparation is in `includes/class-age-restore.php`; interactive CLI preparation is in `includes/class-cli-restore.php`. |
| Scheduling | `includes/class-backup-schedule.php` handles exact local schedules; `includes/class-job-scheduler.php` handles job resumptions. Add a distinct database-log job and schedule. |
| Deletion | `includes/backup/trait-retention.php` (composed into `backup.php`) prunes database/file sets; `includes/class-retention-cleanup.php` coordinates cleanup and retries; `includes/class-backup-deletion.php` handles explicit deletion and active-set protection. All deletion paths must understand database chains. |
| Restore | `restorer.php` (methods in `includes/restorer/`) supports table selection and database import. Native dumps, schema objects and regenerated recovery exports need compatibility checks before entering that path. |

## Coordinated full-backup mode

Keep the existing resumable logical dumper. Introduce a separate PITR full-backup path that runs one native dump process over the complete supported database scope, with one snapshot and its matching coordinates.

MySQL documents `--single-transaction --source-data=2` for capturing a snapshot and commented binary-log coordinates. This still briefly takes a global read lock and does not make nontransactional tables consistent. Concurrent table DDL can invalidate the dump. These are capability requirements, not guarantees provided by the current dumper. [MySQL mysqldump documentation](https://dev.mysql.com/doc/refman/8.4/en/mysqldump.html)

Use separate engine adapters:

| Engine | Snapshot and coordinate options | Capture/replay tools |
| --- | --- | --- |
| MySQL | `mysqldump --single-transaction --source-data=2` | Matching `mysqlbinlog` and `mysql` |
| MariaDB | `mariadb-dump --single-transaction --master-data=2` | Matching `mariadb-binlog` and `mariadb` |

MariaDB's option writes the file and position into the dump; `=2` comments out the replication statement. MariaDB dump headers also have client compatibility requirements. Probe actual binaries and versions rather than assuming a `mysqldump` alias exists. [MariaDB mariadb-dump documentation](https://mariadb.com/docs/server/clients-and-utilities/backup-restore-and-import-clients/mariadb-dump)

The full-backup procedure must:

1. Check logging, source identity, scope, engines, client versions, privileges, disk space and source-log retention. Require ROW logging for the initial implementation and reject unsupported event types or logging gaps. Neither ROW format nor a current setting proves earlier events were compatible: inspect captured events too.
2. Obtain a tested schema-change guard before enumerating objects and starting the dump. Keep it alive until dumping finishes; losing it invalidates the attempt. Evaluate native backup locks in the capability spike and prove coverage for all supported table/schema objects. Verify the covering binary-log interval as well, rejecting any schema changes which the guard did not exclude. Missing privileges or lost guards must reject the attempt; a full-duration global read lock is not an allowed fallback. Maintenance mode in WordPress alone cannot exclude external DDL. Native lock mechanisms are version-specific: see [MySQL backup locking](https://dev.mysql.com/doc/refman/8.4/en/lock-instance-for-backup.html) and [MariaDB backup stages](https://mariadb.com/docs/server/reference/sql-statements/administrative-sql-statements/backup-commands/backup-stage).
3. Dump every table and supported schema object in scope, including views, triggers, routines and events. Disable anonymisation, partial table coverage and filters that transform data for this mode. Validate required command arguments after extension filters run. Keep replication configuration commented and avoid importing global GTID state; for MySQL use the tested `--set-gtid-purged=OFF` policy.
4. Parse the coordinate emitted by this exact dump. A status query before or after the process is not a substitute. Record snapshot coordinates immediately in job state, then bind them to the completed dump in its persistent manifest.
5. Verify process success, dump completeness, object coverage and the availability/ancestry of the source logs beginning at that coordinate. Preserve a source-log anchor sufficient to detect filename reuse and resets. Source expiration must allow the entire dump, capture and upload retry window; BackupPlus does not purge source logs.
6. Finalize compression, optional age encryption and upload; publish the full backup and manifest only after the required destinations have acknowledged the artifacts.

A terminated dump restarts the entire snapshot with a new attempt and new coordinates. Never stitch fragments from different attempts. Completed compression, encryption and uploads may resume from validated artifacts; the database transaction cannot resume across PHP requests. The scheduled PHP request owns the dump process and lock connection, with deadlines, cancellation and child-process cleanup. Host request timeouts can interrupt long snapshots and must be accounted for.

Pass database credentials to native clients through owner-only temporary option files, with correct escaping and cleanup, rather than command-line arguments or logs. Preserve verified TLS for remote connections and use argument-safe process execution.

Baseline snapshot imports must continue to support selecting tables. Add and test a metadata/header adapter for the new dump format, including MariaDB headers and SQL delimiters. Historical table selection between snapshots is a later milestone. Existing backups never acquire PITR eligibility merely because they contain an ordinary dump.

## Persistent metadata and coordinates

**Both full and incremental backups must save binary-log coordinates as backup metadata.** Store versioned portable manifests beside the archives in every required destination. Index them in backup history, but do not make WordPress options, transient job state or local file inventories the only copy.

For a full backup, save the snapshot's file and byte position: this is the first source event to replay after importing that snapshot. For an incremental, save the inclusive start and exclusive end coordinates of the replay interval, covering only complete committed transactions. A coordinate is a pair; a position without its log filename is insufficient.

Illustrative fields for the two manifest types:

```json
{
  "manifest_version": 1,
  "kind": "database-full",
  "backup_id": "full-A",
  "chain_id": "chain-A",
  "source": {
    "engine": "mysql",
    "server_version": "record-exact-version",
    "identity": "validated-source-identity",
    "log_epoch": "epoch-A"
  },
  "database_scope": ["wordpress"],
  "snapshot_coordinate": {"file": "binlog.000123", "position": 4567},
  "snapshot_gtid_state": null,
  "artifact": {"name": "record-archive-name", "sha256": "record-ciphertext-hash"}
}
```

```json
{
  "manifest_version": 1,
  "kind": "database-binlog",
  "backup_id": "increment-A1",
  "chain_id": "chain-A",
  "baseline_id": "full-A",
  "previous_backup_id": "full-A",
  "previous_manifest_sha256": "record-predecessor-manifest-hash",
  "source_identity": "validated-source-identity",
  "log_epoch": "epoch-A",
  "start_coordinate": {"file": "binlog.000123", "position": 4567},
  "end_coordinate": {"file": "binlog.000124", "position": 4},
  "segments": [
    {
      "source_file": "binlog.000123",
      "replay_start_position": 4567,
      "replay_end_position": 9876,
      "artifact": "record-archive-name",
      "sha256": "record-ciphertext-hash"
    }
  ]
}
```

The example's terminal rotate event links the final file offset to the successor file; its metadata header is not a transaction. The adapter must validate this transition. Do not infer continuity from consecutive filenames or compare positions numerically across files.

The complete schema also records creation and snapshot timing separately, source server identifiers, database options, schema-object inventory, client versions, binlog format/checksum settings, compression, plaintext/ciphertext sizes and hashes, encryption recipient fingerprint, per-destination completion receipts, event boundaries, commit-time coverage and engine-specific GTID information where available. GTID state is supplementary and vendor-specific; do not synthesize snapshot GTIDs from a later global status query.

Predecessor manifest hashes refer to the exact persisted manifest object, including ciphertext when encrypted. Distinguish content hashes from stored-object hashes explicitly in the schema.

MySQL UUIDs and MariaDB server IDs have different identity properties. A server ID alone is not a unique log history. Bind the source epoch to validated log anchors/ancestry and available GTID history; resets, failover, identity changes, reused filenames or ambiguous ancestry require a new baseline. Define these proofs in the adapter contract before claiming continuity.

With encryption enabled, encrypt manifests containing database details as well as payloads. A minimal discovery record may expose only opaque IDs, version and encrypted-manifest filename/hash. Remote rescan can discover such a set without a private key; full verification requires the existing browser or interactive CLI decryption flow. Archive integrity checks and authenticated decryption must precede replay.

## Incremental capture and publication

Use scheduled capture of **closed, rotated raw binary-log files** for the first release. Each run rotates the source log through the tested engine adapter, lists the logs, freezes its target range and downloads all closed logs needed from the last durable coordinate. Rotation affects the database server and requires the appropriate privilege; report this during capability setup. A host that cannot rotate logs does not qualify for this initial mode.

MySQL supports remote raw binlog backup. MariaDB documents that `--stop-position` is ignored in raw mode. Therefore, do not use that option to claim a bounded active-log capture; a future streaming/partial-file mode needs its own protocol and tests. [MySQL raw backup documentation](https://dev.mysql.com/doc/refman/8.4/en/mysqlbinlog-backup.html), [MariaDB binlog options](https://mariadb.com/docs/server/clients-and-utilities/logging-tools/mariadb-binlog/mariadb-binlog-options)

Capture rules:

- Retrieve entire closed files, retaining format-description events and checksums. The first file may contain bytes before the baseline coordinate; record replay bounds separately from physical artifact bytes. Retain server-wide raw contents without filtering during capture and disclose that their data scope can exceed the selected database.
- Verify event checksums, complete transactions, rotation transitions, source ancestry and every required intervening log. Reject unsupported compressed/encrypted event handling rather than omitting events. Never convert verbose row-event comments into replay SQL.
- Stage artifacts privately; compress, age-encrypt when configured and use existing providers. Configured encryption failure blocks publication. Checkpoint each immutable encrypted artifact before removing plaintext. Retries do not append to age ciphertext.
- Publish artifacts first and their immutable completion manifest last, separately for each provider instance. Make retries idempotent and retain incomplete uploads as uncommitted artifacts. Providers need no cross-object atomic rename.
- Advance the durable capture cursor only after artifacts and manifests are complete at every destination required by the job's saved configuration. Track optional destination lag separately. A provider's readable, complete dependency set determines whether it can supply a restore; a successful upload elsewhere does not make it complete.
- Serialize cursor updates with a source-scoped lease and fencing token so expired workers cannot overwrite a newer cursor. Backup scheduling, new baselines and retention must respect these leases.
- When a required source log is missing, mark the chain broken at the last verified point, alert and schedule a new full backup. Preserve earlier recoverable coverage. A successful new baseline cannot repair the historical gap.

During daily rollover, continue capture for the old baseline while the new full snapshot is created and uploaded. Activate the new baseline only after its artifacts and the logs needed from its snapshot coordinate are durable. It is acceptable to store overlapping source files in separate chains initially; this avoids cross-chain deletion dependencies. Deduplication is deferred.

## Scheduling and health

Use a single dropdown with Disabled, every minute, 5, 10, 15 or 30 minutes, and 1, 2, 3, 4, 6, 8 or 12 hours. Require full snapshots no less often than every 24 hours; check renewal at least hourly independently of long capture intervals. Validate this independently of saved ordinary backup schedules. Calendar timing may stay in the site timezone, but use UTC instants for coverage and enforce the 24-hour age limit across DST changes and delays.

The Incremental Backup dialog includes PITR capture when enabled, independently of incremental files. Queue manual capture immediately, creating a full baseline if needed. Preserve the queued progress identifier and use the chain's complete database scope, saved encryption and storage destinations. A capture failure must be visible and stop a combined request before file backup begins.

Integrate triggers, resumptions and cleanup coordination with existing classes, while WordPress scheduled tasks perform native operations in PHP requests. Low-traffic sites should trigger `wp-cron.php` from their host scheduler to keep capture cadence. On activation, take a baseline before claiming coverage. On deactivation, unschedule capture without deleting retained chains.

Show the last successful full snapshot, latest verified durable transaction/coordinate, configured capture interval, measured capture/upload lag, destination status and any gap. The interval is a target: actual potential data loss includes scheduler delay, long transactions, capture duration and upload retries. Display coverage only through proven committed boundaries. Warn when the latest full snapshot becomes overdue; keep collecting a still-valid older chain while retrying a new baseline.

Changes to source, scope, encryption recipients or destination requirements start a new chain. Continue protecting the old chain until replacement is complete. Credential renewal for the same validated endpoint need not invalidate ancestry. Unavailable capabilities must never silently turn a PITR job into an ordinary dump reported as PITR success.

## Whole-database recovery

Deliver an inspectable recovery plan before production mutation. Proposed CLI operations are capability inspection, chain verification, recovery-plan generation and restore-to-point; finalize command names with the existing CLI conventions during implementation.

1. Select a baseline preceding the requested point and resolve every required manifest and segment. Report coverage, source scope, engine/client requirements and required disk space. Reject missing, corrupt, incompatible or ambiguous chains.
2. Fetch and authenticate the entire required set. Preserve current identity handling: browser private keys stay in the browser; interactive CLI keys remain process-local. Extend archive selection/preparation to binary logs and manifests rather than persisting new restore secrets in job state.
3. Create an isolated recovery **server instance**, with original schema names, compatible engine/version, no production credentials, event scheduler disabled and no external application traffic. A second database on the production server is insufficient isolation for arbitrary log statements.
4. Import the baseline and replay logs from its snapshot coordinate to the selected terminal transaction boundary. Use matching native tools, executable row events and one ordered replay connection to preserve session state. Check producer and database-client exit statuses independently; never use force/ignore-error behavior. Source GTIDs and replication configuration must not turn the recovery instance into a replica.
5. Validate the recovered schema and data, then export an ordinary logical dump suitable for BackupPlus import. The export adapter must preserve supported schema objects and table-selection metadata.
6. For production replacement, quiesce all writers, produce a separate current restore point and preflight the existing importer. Apply any supported URL/prefix migration only after historical replay. Do not advertise cross-table atomic replacement where the current importer cannot provide it; preserve maintenance state and report partial import on failure.

Timestamp selection must resolve to an explicit file/position and complete transaction boundary. Define the UI as the last supported committed boundary at or before the selected UTC time, with a preview of the actual boundary. Provide exact-coordinate selection for unambiguous recovery. Do not simply pass a wall-clock cutoff that can split a transaction. Detect unsupported/non-monotonic timing cases and request a coordinate instead of asserting exact wall-clock reconstruction. [MySQL recovery using event positions](https://dev.mysql.com/doc/refman/8.4/en/point-in-time-recovery-positions.html)

Isolation is a design safeguard: database filtering and name rewriting have limitations for statement events and qualified names. Restrict the first release to the proven database scope and reject events requiring absent schemas or unsupported server-wide effects before production import. [MySQL binlog filtering and rewriting rules](https://dev.mysql.com/doc/refman/8.4/en/mysqlbinlog.html)

If replay is interrupted, rebuild the isolated instance from its baseline unless a separately tested transactional checkpoint can prove the exact committed position. Never restart a partly applied stream against production. Log-verification failure leaves production untouched; final-import failure must be reported as such, not as successful PITR.

## Selected-table historical recovery

After whole-database recovery is proven, reconstruct the complete supported database scope at the historical point in isolation. Enumerate tables from that recovered state, export the requested subset and pass it through BackupPlus's selected-table restore path.

Do not recover historical tables by grepping SQL or applying only matching row events. Test renamed/dropped/recreated tables, DDL and foreign-key dependencies. Explain when the selection omits related tables; block structurally invalid imports. Choosing a historical subset does not promise whole-database application consistency. Ordinary selected-table restores remain available throughout rollout.

## Retention and deletion

Count a PITR full backup plus its incremental coverage as one database chain. Apply existing count/age and Always Keep policies to whole chains; selected-table restore does not change their dependencies. Preserve imported-backup and pre-update exemptions.

Protect active capture chains, baseline attempts, in-flight uploads and restore leases. Never delete the only usable baseline because a replacement was started or partially uploaded. Route scheduled cleanup, after-backup pruning, browser deletion, CLI deletion and local temporary-file cleanup through the same database dependency checks. Reject deleting an individual required segment; an explicit whole-chain delete must show the recovery coverage being removed.

Use retryable deletion state per destination. Mark a chain as deleting before removal, keep its inventory and failure details until required deletions complete, and hide it from normal recovery selection once deletion starts. Avoid orphan payloads by deleting payloads before their final discovery metadata. Recheck protection under a lock when committing a deletion plan. Initially use chain-owned artifacts so a deleted chain cannot remove a newer chain's overlapping log files.

## Delivery sequence and acceptance gates

| Milestone | Work | Required evidence |
| --- | --- | --- |
| 0. Capability proof | Engine/client adapters, privilege matrix, snapshot lock strategy, raw closed-file retrieval, source ancestry and supported scope. Extend disposable database fixtures with binary logging. | On each target engine, prove snapshot/coordinate agreement under concurrent writes, prevent concurrent DDL from invalidating it, retrieve and replay real raw logs. Reject missing privileges and unsupported scope cleanly. |
| 1. PITR full backup | Separate whole-scope dumper, worker lifecycle, metadata schema, compression/encryption and provider publication. | Interrupted snapshots restart cleanly; complete manifests bind actual dump coordinates. Ordinary full/selected-table restores still pass, including a new baseline restored at its own snapshot. |
| 2. Incremental durability | Closed-log capture, cursors, leases, gap detection, independent schedule, remote discovery and chain-aware retention. | Survive rotation, concurrent jobs, upload failure and process death without gaps or duplicate cursor advancement. Reconstruct and verify chains on a fresh installation using remote artifacts. |
| 3. Whole-database PITR | Plan/verify CLI, isolated reconstruction, transaction-boundary selection, ordinary export/import and recovery status. | Recover known data at multiple boundaries on both engines from encrypted off-host artifacts. Missing/corrupt segments fail before production mutation. |
| 4. Administration | Capability setup, scheduling controls, coverage display and browser preparation of new encrypted artifacts. | Settings cannot enable unsupported promises; key handling matches existing restore behavior; lag and broken coverage are visible. |
| 5. Historical table selection | Export selected tables from the reconstructed historical state. | Restore selected historical tables while preserving unselected live tables; exercise rename/drop/recreate and dependency cases. |

The regression matrix must cover GTID on/off; multiple rotations; empty captures; a transaction spanning the requested time; large transactions; ROW events with binary data and minimal row images where supported; schema changes between snapshots; changed recipients/destinations; purged source logs; server restart/reset/failover; corrupted/truncated/reordered artifacts; missing or wrong identities; disk exhaustion; failed provider writes/deletes; rescan with encrypted manifests; DST and overdue snapshots; cancellation and worker/lock loss.

Extend the existing standalone checks and disposable WordPress/Podman integration scripts. Run focused existing incremental-file, scheduling, encryption, CLI restore, retention and selected-table checks alongside the new database matrix. Do not mark MySQL and MariaDB support complete based only on mocked commands or generated SQL. Measure snapshot lock duration, capture lag, recovery time and temporary disk usage before publishing operating limits.
