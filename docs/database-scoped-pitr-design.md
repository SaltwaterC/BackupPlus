# PITR database accounts

BackupPlus runs coordinated snapshots, binary-log capture and recovery inside the plugin, using its existing scheduling, storage, encryption and retention infrastructure.

The WordPress application account keeps permissions on its own database. PITR uses up to two additional accounts:

| Account | When used | Credential lifetime |
| --- | --- | --- |
| Backup account | Coordinated PITR full snapshots and binary-log capture | Saved through the existing BackupPlus credential encryption, migration and key-rotation mechanism |
| Restore account | Verification and execution of a recovery point requiring binary-log replay | Entered in the restore screen; held in request memory and supplied to native clients through an inherited pipe |

If the existing WordPress account already has capture privileges, **Backup account → Use WordPress account** avoids an extra backup login. BackupPlus checks the account; it does not grant privileges or create accounts. A dedicated backup account is the usual choice when the site account has only database-scoped grants.

Ordinary full logical dumps and full-dump/table restores continue to use their existing WordPress connection and require neither account. Selecting the full snapshot in a PITR chain also uses the standard database restore process, with optional table selection and no restore account. Archive decryption is still required for encrypted snapshots.

## Capture

The backup account needs SELECT on application data, metadata access for dumped objects, native-log download/monitoring privileges and the privileges needed for a coordinated snapshot and DDL guard. It receives no application INSERT, UPDATE, DELETE, account administration, native replay or server-variable administration privileges. MySQL/MariaDB TRIGGER and EVENT privileges permit object changes as well as object inspection; therefore the account is data-read-only but cannot be described as strictly read-only. The [operating guide](binary-log-backups.md) gives tested grant sets.

A WordPress connection writes the ancestry marker in its own options table. The backup account reads the resulting source-log evidence. This keeps application writes out of the backup account's permissions.

Snapshots use a guarded InnoDB transaction and matching binary-log coordinates. Normal writes continue after the brief coordinated startup lock. Full backups and incremental stages retain filename/position metadata. Every committed change within verified archived coverage remains available, including overwritten or deleted values. Snapshot intervals determine archival timing, not which transactions are retained.

Binary-log permissions are server-wide. Raw archives can contain other databases' events even though the backup account cannot SELECT their tables. The host must authorize that access. The account boundary cannot turn MySQL/MariaDB binlog reads into schema-scoped authorization. Existing age encryption applies to those archives.

## Restore

For an incremental recovery point, the restore screen requests a username and password for the same database endpoint. HTTPS is required outside local development. Both verification and final execution receive those credentials explicitly; a verified request does not create a reusable server-side credential ticket. Native clients read credentials from an inherited pipe, and PHP database connections use request memory. Restore credentials are excluded from options, jobs, history, logs, files, browser storage and protection receipts. Previous experimental saved recovery options are removed on initialization.

Before replacement, the plugin validates the entire required chain, checks the target server and permissions, enters maintenance, locks application login variants and disconnects existing application sessions. It pauses events belonging to this database and monitors the locks. Other databases' clients and global read-only/scheduler settings are left alone. Additional accounts or roles with write access to this application database cause an explicit refusal; privileged database administrators must not interfere during restoration. The application username must be specific to this database.

The plugin retains a pinned pre-restore backup, imports the baseline, then applies only the selected database's verified log ranges. Account-administration events are removed from private replay copies while preserving original byte positions and checksums. Native database filtering and cross-schema transaction/DDL checks prevent unrelated schema replay. Unsupported statements and mixed-database transactions fail explicitly.

On success, application access resumes and PITR capture returns to the setting it had before the restore and starts a fresh chain on its next run. If replacement fails, maintenance and login locks remain active. The protection receipt contains the account lock states and non-secret connection metadata, allowing an administrator to repair the database and run the emergency release command with newly entered credentials. No privileged sign-in is retained for retries or emergency release.

## Supported boundaries

MySQL 8.4 and MariaDB 10.11/11.4/11.8 use vendor-matching tools. Database names currently require `lower_case_table_names=0`. Incremental recovery requires the original database name, table prefix and server version. Replicated source recovery requires administrator coordination and is refused by the automatic path.

Direct replay currently supports snapshots whose database events are disabled. Historical enabled events or event-state changes in the selected replay need an isolated historical export; they fail before replacement, rather than allowing events to run during a partial import. Historical selected-table recovery also uses the existing isolated reconstruction workflow. Ordinary selected-table restore remains available.

Tests use disposable source/recovery servers, database-only application accounts, minimum-grant backup accounts, temporary restore credentials and a neighboring database. They exercise overwritten intermediate values, complete log ranges, native filtering, encryption, credential persistence/expiry, live-writer exclusion and ordinary dump restoration. RDS and Percona gaps remain documented separately.
