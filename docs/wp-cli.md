# WP-CLI

BackupPlus adds `wp backupplus` while the plugin is active and loaded. Native update protection also requires BackupPlus to be loaded; `--skip-plugins=backupplus` disables that integration.

```sh
wp backupplus backup
wp backupplus backup --type=database --local --porcelain
wp backupplus backup --files=plugins,wpcore,custom --tables=wp_posts,wp_postmeta
wp backupplus backup --type=files --incremental
wp backupplus credential-keys rotate
wp backupplus list --format=json
wp backupplus sources --format=csv
wp backupplus delete 1792718455 --remote --yes
```

`backup` waits through engine retries until a terminal result, without a CLI deadline. It uses saved file selections, database selections, encryption and destinations. `--local` skips uploads. `--type` accepts `all` (the default), `files` or `database`. `--incremental` uses the engine's compatible chain selection and full-backup fallback; it requires files. Ordinary completed backups with warnings exit successfully and display warnings on stderr. Failed or interrupted jobs exit with an error. Porcelain output contains only the saved history timestamp on stdout; an increment prints its chain's base timestamp.

`credential-keys rotate` uses the disk key store when no environment key is configured. It rotates the key, re-encrypts saved credentials and job snapshots, schedules retries if needed, and sends the configured key rotation email. When `BACKUPPLUS_CREDENTIAL_KEYS` is set, it instead prints a new Base64 encoded JSON key-ring value to stdout. That value has a new active key and updated `issued_at` and `rotated_at` timestamps, while retaining all previous keys. It does not change the environment or rewrite stored credentials; deploy the printed value through the service's secret configuration. Treat stdout as secret material.

Rotation does not remove old keys. Disk rotations and generated environment values retain them so credentials encrypted under earlier keys remain readable; there is currently no automatic key retirement command.

`--files` replaces this run's file selection. Use entity IDs from `sources`, including `wpcore`; `custom` expands the saved additional paths. `--tables` selects exact WordPress tables and preserves external database selections. Neither option changes saved settings.

For more detailed per-run selection, use `--selection=/path/selection.json`, exclusive with `--files` and `--tables`:

```json
{
  "files": ["plugins", "wpcore"],
  "custom_paths": ["/srv/site-data"],
  "database_selection": {
    "wp": {
      "enabled": true,
      "mode": "selected",
      "tables": ["wp_posts", "wp_postmeta"]
    }
  }
}
```

Omitted selections keep their saved configuration. `custom_paths` replaces the additional-source list for this run and includes those paths in the job. Database IDs omitted from `database_selection` retain their saved selections. Paths, tables, exclusions and coverage are frozen for resumptions. Invalid sources, unreadable paths, missing tables and selectors incompatible with `--type` are rejected before starting a job.

`list` reads saved history, newest first. Default columns are `timestamp`, `date` (UTC), `nonce`, `label`, `components` and `services`. Optional fields include `pre_update` and `partial_database`. Components include historical custom sources even after removal from settings. `--fields=timestamp,components` limits the displayed fields.

`delete <timestamp>` deletes the complete incremental chain, local archives, recorded inventories and history. Remote deletion requires `--remote`; confirmation requires `--yes` to skip. Active chains and restore points authorizing pending updates are protected. Partial deletion retains metadata and successful remote deletion receipts so the command can be retried.

## Schedules and retention

```sh
wp backupplus schedule list --format=json
wp backupplus schedule set files --interval=weekly --time=02:30 --day=0
wp backupplus schedule set database --interval=daily --time=03:00
wp backupplus schedule set incremental --interval=every4hours --time=01:15
wp backupplus schedule set files --interval=daily --no-exact
wp backupplus retention status --format=json
wp backupplus retention set --files=7 --database=14 --mode=daily --time=04:00
```

Schedule types are `files`, `database` and `incremental`. `--interval` is required for `schedule set`; use registered WordPress intervals, `manual` for disabled file/database schedules, or `none` for disabled incremental schedules. Incremental intervals from one minute to one day are scheduled directly; shorter or longer intervals normalize to daily. `--time` enables exact scheduling and cannot be combined with `--no-exact`. Timing fields omitted from a submission keep saved values. Weekly and fortnightly days run from Sunday `0` to Saturday `6`; monthly days run from `1` to `28`.

All proposed settings are validated before any writes, then reconciled once. Unchanged schedules retain queued times. Exact times use the site's timezone and the existing DST and fortnightly phase rules. Equal file/database schedules run together. The effective schedule listing includes timezone and next runs.

Retention counts must be positive integers. `retention set` requires at least one setting. `--mode=after_backup` prunes after backups; `--mode=daily` runs count-based cleanup at a valid supplied or saved `--time`. Daily cleanup retains protected backups, deletes whole chains, yields to backups and retries failed deletions. Scheduled jobs continue through WP-Cron, including `wp cron event run --due-now`.

## Native updates

```sh
wp backupplus pre-update status --format=json
wp backupplus pre-update set --enabled=1
wp plugin update --all --exclude=example
wp theme update --all
wp core update
```

When protection is enabled, native `plugin update`, `theme update`, `core update` and their `upgrade` aliases wait for one standalone full restore point per invocation. The guard uses native resolved targets and download offers, including explicit-version plugin installation paths, bulk exclusions, forced updates and core package arguments. Local packages are bound by SHA-256 content hashes. CLI authorization does not require `--user`; browser capability and ownership checks continue to apply.

The restore point includes all eligible WordPress tables even if saved WordPress coverage is partial or disabled, together with saved external database selections and site files, including standalone additional files. Required destination uploads must be verified before updates proceed. Warnings, failures, cancellation, expiry and unavailable restore points block updates. Dry runs and invocations with no updates create no backup. Pre-update sets use the coordinator's separate retention policy.

`list`, `sources`, `schedule list`, `retention status` and `pre-update status` support `--format=table|json|csv` and `--fields`.

## Restore

For optional coordinated full snapshots and MySQL/MariaDB binary-log recovery, use [`wp backupplus pitr`](binary-log-backups.md). It has separate setup, capture, chain verification, isolated historical reconstruction and replacement commands. Both full snapshots and increments retain portable file/position metadata. Existing `backup` and `restore` commands continue to handle ordinary logical backups.

```sh
wp backupplus restore 1792718455
wp backupplus restore 1792718455 --type=database
wp backupplus restore 1792718455 --components=plugins,db --yes
wp backupplus restore-points 1792718455 --format=json
wp backupplus restore 1792718455 --type=files --point=1792718400
```

`restore <timestamp>` restores all available components by default. `--type=files|database` limits the selection; `--components` selects exact history IDs, including `wpcore`, `custom-…` and external dumps such as `db1`. Additional sources use their recorded destinations. External dumps use the saved database connection with the corresponding ID; that connection must still exist. The command asks for overwrite confirmation unless `--yes` is supplied.

File chains restore through their latest increment unless `--point` selects a timestamp returned by `restore-points`. These points use the engine's existing minute resolution. Database dumps come from the selected backup history entry. Core restores preserve the current `wp-config.php` unless `--include-wp-config` is supplied.

Encrypted restores prompt in an interactive terminal for the private key and its passphrase. Paste a native age identity or the complete multiline OpenSSH/PEM private key, including its BEGIN and END lines. Leave the passphrase blank for an unencrypted key. Legacy passphrase-encrypted archives prompt separately for their archive passphrase. Input is hidden; `--yes` skips only overwrite confirmation.

Private keys and passphrases stay in the restore process's memory. They are never placed in settings, job data, logs, command-line arguments, environment variables or files. There are no command-line secret flags or piped-stdin fallback. Hidden input requires a terminal and `stty`.

All selected archives are fetched and verified, and every selected encrypted archive is decrypted before restoring site files or database tables. Decrypted staging files use owner-only permissions and are removed on success, decryption failure or ordinary process shutdown. The restore engine receives only process-local archive bindings. A new invocation prompts again; it cannot resume by retrieving a saved key or passphrase.
