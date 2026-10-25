# Binary-log database backups

BackupPlus saves coordinated MySQL/MariaDB full snapshots and incremental binary-log stages using its existing storage providers, age encryption, scheduling and chain-aware retention. Full snapshots and increments retain binary-log filenames and positions. Complete transaction boundaries remain recoverable within archived coverage, including values overwritten between captures. Whole-database recovery runs from the restore screen on the source database; historical selected-table recovery uses the existing isolated reconstruction commands.

Ordinary logical full dumps and ordinary full-dump/table restores remain independent and need no PITR backup or restore account. Selecting the full snapshot in a PITR chain uses the standard database restore process and needs no restore account; encrypted snapshots still need archive decryption. Database restores preserve the current backup history, including newer PITR points and file backups, because the archives remain outside the database. See the [account design](database-scoped-pitr-design.md) for credential lifetimes and restore protection.

## Accounts

Keep WordPress's normal database-scoped account. If it already has the capture privileges below, **Backup account → Use WordPress account** avoids an additional capture login. Otherwise provide a separate backup account through **Settings → Database point-in-time recovery → Backup account**. BackupPlus verifies access and saves its credentials using the existing credential encryption and key-rotation mechanism. It does not provision users or grant privileges.

For recovery points requiring binary-log replay, provide the restore account username and password only in the PITR restore screen. Verification and final restoration receive them separately over HTTPS (local development is exempt). They remain in request memory; native tools receive them through inherited pipes. They are never written to options, jobs, logs, files, browser storage or recovery receipts. No saved restore account or `wp-config.php` recovery credential file is used. Retrying a restore requires supplying credentials again.

### Backup account grants

The following sets are what the native test matrix provisions for the operations BackupPlus performs. Replace the database and account names. Have the host create the account separately, using its normal password/TLS policy.

MySQL 8.4:

```sql
GRANT SELECT, SHOW VIEW, TRIGGER, EVENT ON `wordpress`.* TO 'bp_backup'@'localhost';
GRANT RELOAD, REPLICATION CLIENT, REPLICATION SLAVE ON *.* TO 'bp_backup'@'localhost';
GRANT BACKUP_ADMIN, SHOW_ROUTINE ON *.* TO 'bp_backup'@'localhost';
```

MariaDB 10.11/11.4/11.8:

```sql
GRANT SELECT, SHOW VIEW, TRIGGER, EVENT ON `wordpress`.* TO 'bp_backup'@'localhost';
GRANT RELOAD, REPLICATION SLAVE, BINLOG MONITOR ON *.* TO 'bp_backup'@'localhost';
GRANT SELECT ON mysql.proc TO 'bp_backup'@'localhost';
```

The backup account has no application INSERT, UPDATE, DELETE, account-management, server-variable administration or native-replay grants. TRIGGER and EVENT permit object changes as well as inspection, so these complete-object dumps cannot use a strictly read-only login. Routine metadata permissions also have server scope. A WordPress connection writes the ancestry marker in its own options table; the backup account only reads the resulting log proof.

Binary-log download privileges authorize server-wide logs. Filtering to one database happens during replay; it does not restrict the server's log-download authorization. Raw archives can contain neighboring databases' events. The host must authorize this access; apply the plugin's age encryption and storage policy accordingly. See [MySQL's binlog tool](https://dev.mysql.com/doc/refman/8.4/en/mysqlbinlog.html), [MySQL dump privileges](https://dev.mysql.com/doc/refman/8.4/en/mysqldump.html) and [MariaDB dump behavior](https://mariadb.com/docs/server/clients-and-utilities/backup-restore-and-import-clients/mariadb-dump).

### Restore account grants

Restore needs application-schema writes, native binlog replay, session-binlog control, account-lock/session administration, replica-status inspection and definer handling. These administrative grants belong on the account entered for the restore, not the saved backup login. These examples include capture privileges because restoration first retains a pinned pre-restore snapshot while the application login is locked.

MySQL 8.4:

```sql
GRANT ALL PRIVILEGES ON `wordpress`.* TO 'bp_restore'@'localhost';
GRANT RELOAD, REPLICATION CLIENT, REPLICATION SLAVE, PROCESS, CREATE USER, SUPER ON *.* TO 'bp_restore'@'localhost';
GRANT BACKUP_ADMIN, SHOW_ROUTINE, REPLICATION_APPLIER, CONNECTION_ADMIN,
  SESSION_VARIABLES_ADMIN, SET_ANY_DEFINER ON *.* TO 'bp_restore'@'localhost';
GRANT SELECT ON mysql.user TO 'bp_restore'@'localhost';
GRANT SELECT ON mysql.db TO 'bp_restore'@'localhost';
GRANT SELECT ON mysql.tables_priv TO 'bp_restore'@'localhost';
GRANT SELECT ON mysql.columns_priv TO 'bp_restore'@'localhost';
GRANT SELECT ON mysql.procs_priv TO 'bp_restore'@'localhost';
GRANT SELECT ON mysql.proxies_priv TO 'bp_restore'@'localhost';
```

MariaDB 10.11/11.4/11.8:

```sql
GRANT ALL PRIVILEGES ON `wordpress`.* TO 'bp_restore'@'localhost';
GRANT RELOAD, REPLICATION SLAVE, BINLOG MONITOR, SUPER, PROCESS, CREATE USER,
  CONNECTION ADMIN, SET USER, BINLOG ADMIN, BINLOG REPLAY, REPLICA MONITOR ON *.* TO 'bp_restore'@'localhost';
GRANT SELECT ON mysql.proc TO 'bp_restore'@'localhost';
GRANT SELECT ON mysql.global_priv TO 'bp_restore'@'localhost';
GRANT SELECT ON mysql.user TO 'bp_restore'@'localhost';
GRANT SELECT ON mysql.db TO 'bp_restore'@'localhost';
GRANT SELECT ON mysql.tables_priv TO 'bp_restore'@'localhost';
GRANT SELECT ON mysql.columns_priv TO 'bp_restore'@'localhost';
GRANT SELECT ON mysql.procs_priv TO 'bp_restore'@'localhost';
GRANT SELECT ON mysql.proxies_priv TO 'bp_restore'@'localhost';
GRANT SELECT ON mysql.roles_mapping TO 'bp_restore'@'localhost';
```

The `mysql` table grants let restore protection read login lock state and find other accounts, roles and proxies that could write the application database. They are table-scoped on purpose: SELECT on the whole `mysql` schema would also expose replication-channel and federated-server credentials. Replicas and binary-log streaming clients are detected through the process list (`PROCESS`), so no replication-administration privilege is needed; any connected consumer stops restoration because unlogged replacement would not reach it. MariaDB servers running with `read_only=ON` are refused rather than requiring `READ_ONLY ADMIN`.

The global grants remain broad. `SUPER` is required by both vendors to recreate functions/triggers under the default `log_bin_trust_function_creators=0`, even with session binary logging disabled; the plugin does not change that global setting. See [stored program binary logging](https://dev.mysql.com/doc/refman/8.4/en/stored-programs-logging.html). Restored views, routines, triggers and events keep their original definer, normally the site's own database account. On MySQL, objects whose definer holds `SYSTEM_USER` (such as `root`) cannot be recreated with these grants; have the DBA redefine them under the site account rather than granting `SYSTEM_USER` to the restore login. Managed hosts may restrict these grants. Missing permissions stop preparation before replacement. Relevant references: [MySQL session logging](https://dev.mysql.com/doc/refman/8.4/en/set-sql-log-bin.html), [MySQL row-event replay](https://dev.mysql.com/doc/refman/8.4/en/binlog.html), [MySQL account locking](https://dev.mysql.com/doc/refman/8.4/en/account-locking.html) and [MariaDB grants](https://mariadb.com/docs/server/reference/sql-statements/account-management-sql-statements/grant).

### Recovery server account grants

Historical exports and staged replacement reconstruct the database on a separate, empty recovery server (see below). The account in the `--recovery-config` option file imports the baseline, replays the archived logs and exports the result; it never touches the source. The native matrix provisions exactly these grants:

MySQL 8.4:

```sql
GRANT ALL PRIVILEGES ON `wordpress`.* TO 'bp_reconstruct'@'localhost';
GRANT SHOW DATABASES, REPLICATION CLIENT ON *.* TO 'bp_reconstruct'@'localhost';
GRANT REPLICATION_APPLIER, SESSION_VARIABLES_ADMIN, SET_ANY_DEFINER, SHOW_ROUTINE ON *.* TO 'bp_reconstruct'@'localhost';
```

MariaDB 10.11/11.4/11.8:

```sql
GRANT ALL PRIVILEGES ON `wordpress`.* TO 'bp_reconstruct'@'localhost';
GRANT SHOW DATABASES, REPLICA MONITOR, BINLOG REPLAY, SET USER ON *.* TO 'bp_reconstruct'@'localhost';
GRANT SELECT ON mysql.proc TO 'bp_reconstruct'@'localhost';
```

`SHOW DATABASES` lets BackupPlus prove the server holds no other databases, and `REPLICATION CLIENT`/`REPLICA MONITOR` that it has no replication channels. Replaying row events needs `REPLICATION_APPLIER` and `SESSION_VARIABLES_ADMIN` on MySQL, or `BINLOG REPLAY` on MariaDB. Recreating views, routines, triggers and events under their original definer needs `SET_ANY_DEFINER` or `SET USER`; exporting routines needs `SHOW_ROUTINE` or `SELECT` on `mysql.proc`. Each of these was confirmed necessary by removing it and rerunning the matrix.

Create the site's database account on the recovery server too, locked (`ACCOUNT LOCK`) and with its usual grants on the application database, so that recovered objects keep a valid definer. MySQL refuses to create objects for a missing definer, and views and `SQL SECURITY DEFINER` routines run with their definer's privileges.

The ordinary importer runs as the WordPress account. On a binary-logging server, an account without `SUPER` can recreate triggers only when the DBA has set `log_bin_trust_function_creators=1`. Otherwise, the importer logs and skips triggers it is not permitted to create.

## Requirements and boundaries

- MySQL 8.4 or MariaDB 10.11, 11.4 or 11.8, with vendor-matching `mysql`, `mysqldump`, `mysqlbinlog`, or `mariadb`, `mariadb-dump`, `mariadb-binlog`. All three tools must exist in the configured binary directory. The native matrix exercises MySQL 8.4 GTID off/on and MariaDB 10.11/11.4. Other combinations require validation.
- InnoDB application tables, `lower_case_table_names=0`, binary logging enabled, nonzero server ID, ROW events, CRC32, no source-log filters, and at least two days of automatic retention (or automatic expiry disabled). Compressed and partial-JSON events are unsupported. BackupPlus never purges source logs.
- One application database is the recovery scope. Cross-schema dependencies, mixed-database transactions and unsupported DDL fail explicitly. Raw logs are validated before native database filtering; account-administration events are neutralized in private replay copies without changing source positions.
- Unix PHP with process support, private staging space and WordPress scheduled tasks. Long native operations need sufficient PHP/hosting request duration. One worker filesystem is required; the lock is not a distributed lease.
- Enabled storage destinations must support upload, download, listing and deletion. Every configured destination must publish successfully. Email cannot hold a verifiable chain. Without a remote destination, artifacts remain local.
- Direct recovery requires the original database name, prefix, vendor and server version. A source with replication channels or registered replicas requires administrator coordination and is refused by automatic restore.
- Direct source replay currently supports historical snapshots with disabled database events. Historical enabled events and event-state changes in the selected replay fail before replacement; use an isolated historical export. Ordinary full-dump restoration still supports its existing objects and options.

RDS PITR is unsupported by this snapshot strategy, and Percona compatibility has not been validated. Both remain deferred; see the [hosting gap register](binary-log-hosting-gaps.md).

## Snapshot locking and TLS

Full snapshots use `--single-transaction --flush-logs` and MySQL `--source-data=2` or MariaDB `--master-data=2`. The native tool briefly coordinates the snapshot and matching log position, then normal writes continue. There is no full-duration global read-lock fallback.

A persistent MySQL `LOCK INSTANCE FOR BACKUP` or MariaDB `BACKUP STAGE START; BACKUP STAGE BLOCK_DDL` guards table definitions. BackupPlus monitors that connection and checks the dump's log interval for schema changes. Losing the guard or finding an incompatible schema change invalidates the baseline.

Native connections inherit the WordPress endpoint and verified TLS settings. Local socket connections need no certificates. Remote connections require a trusted CA and hostname verification. TLS authentication, expired certificate, hostname mismatch, database authentication and missing-client failures produce distinct user-facing errors.

## Configure and capture

1. Configure the backup account when the WordPress account lacks capture grants.
2. Choose the Database point-in-time recovery interval: Disabled, every minute, 5, 10, 15 or 30 minutes, or 1, 2, 3, 4, 6, 8 or 12 hours.
3. Save Settings. Scheduled workers create an initial coordinated full snapshot, renew daily baselines and archive intervening transactions.
4. Click **Incremental Backup** to capture immediately. Uncheck files for a database-only capture. A new baseline is created when required.

WordPress cron depends on traffic; a host cron trigger improves scheduling reliability. Existing backups groups a complete database chain under its full snapshot with recovery points in the restore selector. Storage, encryption or source changes force a new compatible chain. Missing logs, ancestry changes and incompatible metadata break the chain rather than silently extending it.

The optional CLI supports `capabilities`, `enable`, `disable`, `run`, `full`, `capture`, `status --points`, `plan`, `verify`, `rescan`, `abandon-pending`, `delete` and `prune`. Run `wp help backupplus pitr COMMAND` for arguments. Legacy CLI capture configurations can still use an owner-only source `[client]` file. Archive decryption identities are read through hidden terminal prompts.

## Restore

Selecting the full snapshot restores its SQL dump through the ordinary database importer, without a restore account or binary-log replay. Current backup history, recovery points, labels and retention locks survive replacement of the options table. Ordinary local and remote rescans leave PITR chains to the chain index rather than pruning them as unrecognized filenames.

Select a PITR chain, recovery point and restore credentials. The browser authenticates and decrypts encrypted archives without sending private age keys or passphrases to the server, then uploads prepared plaintext. BackupPlus validates all dependencies, hashes and transaction boundaries. An incomplete chain never falls back to restoring only its baseline.

For final restoration, BackupPlus enters maintenance, locks the database-specific WordPress login variants, disconnects existing application sessions and pauses this database's scheduled events. It checks for additional database writers and unverified roles. It does not change global `read_only` or `event_scheduler`, and neighboring databases may remain online. Database administrators must not interfere during recovery or use the restore login for unrelated work.

The plugin retains a pinned pre-restore backup, replaces the application database with the baseline and applies the selected database's log ranges. Native restore sessions disable binary logging. On success, original application account locks are restored, maintenance ends and capture resumes with the setting it had before the restore, starting a new chain on its next run. Previously locked accounts stay locked. The finished restore screen offers **Done**.

```sh
wp backupplus pitr restore CHAIN_ID --direct --coordinate=binlog.000123:4567
```

CLI source restoration prompts for the restore username/password; they are not command-line arguments. Status exposes exact safe transaction coordinates. Wall-clock cutoffs are not implemented because event timestamps do not reliably identify commit times.

Historical selected-table exports use an empty isolated recovery server of the same version with binary logging and events disabled:

```sh
wp backupplus pitr recover CHAIN_ID --coordinate=binlog.000123:4567 \
  --tables=wp_posts,wp_postmeta --recovery-config=/private/reconstruction.cnf --isolated
```

Restore the resulting own-database logical export through the ordinary importer, including its selected-table support. The CLI also supports staged replacement with `restore --recovery-config=... --isolated --writers-stopped` and separately prompted source restore credentials.

A replacement failure leaves maintenance, application login locks and a non-secret protection receipt active. The error identifies the pinned pre-restore chain. An administrator must repair the database or recover that backup before releasing protection:

```sh
php scripts/pitr-release-isolation.php /absolute/path/to/backups/pitr/isolation.php \
  --confirm-database-recovered
```

This standalone emergency command prompts for credentials again and does not bootstrap WordPress. Direct replacement is not atomic across tables; interrupted replay is never reported as successful.

## Retention and verification

Retention keeps/deletes entire dependency chains and preserves active, pinned and in-use chains. The pre-restore backup is locked automatically; the lock icon in **Existing backups**, or **Lock**/**Unlock** on a selection, changes it manually. Unlocking releases the pin, so later retention may delete that chain. Existing backups deletion removes a complete selected chain through the UI, including its selected remote destinations. Rescan reconstructs history from portable locators/manifests after verifying required artifacts.

`make test` includes formatting, PHP/assets, unit, integration, WordPress, browser and native PITR suites. `make test-pitr-native` runs disposable MySQL GTID off/on and MariaDB 10.11/11.4 pairs. It checks coordinated snapshots, online writers, guard loss, coordinates, rotations, historical overwritten values, whole/selected-table recovery, age, corruption, rescan, encrypted backup credentials, request-only restore transport and application protection. It does not restore into the development site's database.
