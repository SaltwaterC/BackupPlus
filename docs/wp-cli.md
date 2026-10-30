# WP-CLI

BackupPlus adds `wp backupplus` while the plugin is active and loaded. Native update protection also requires BackupPlus to be loaded; `--skip-plugins=backupplus` disables that integration.

```sh
wp backupplus backup
wp backupplus backup --type=database --local --porcelain
wp backupplus backup --files=plugins,wpcore,custom --tables=wp_posts,wp_postmeta
wp backupplus backup --type=files --incremental
wp backupplus backup --label="Before the migration" --services=s3
wp backupplus credential-keys rotate
wp backupplus list --format=json
wp backupplus sources --format=csv
wp backupplus delete 1792718455 --remote --yes
wp backupplus delete --older-than=30 --keep=4 --dry-run
```

`backup` waits through engine retries until a terminal result, without a CLI deadline. It uses saved file selections, database selections, encryption and destinations. `--local` skips uploads. `--type` accepts `all` (the default), `files` or `database`. `--incremental` uses the engine's compatible chain selection and full-backup fallback; it requires files. Ordinary completed backups with warnings exit successfully and display warnings on stderr. Failed or interrupted jobs exit with an error. Porcelain output contains only the saved history timestamp on stdout; an increment prints its chain's base timestamp.

`credential-keys rotate` uses the disk key store when no environment key is configured. It rotates the key, re-encrypts saved credentials and job snapshots, schedules retries if needed, and sends the configured key rotation email. When `BACKUPPLUS_CREDENTIAL_KEYS` is set, it instead prints a new Base64 encoded JSON key-ring value to stdout. That value has a new active key and updated `issued_at` and `rotated_at` timestamps, while retaining all previous keys. It does not change the environment or rewrite stored credentials; deploy the printed value through the service's secret configuration. Treat stdout as secret material.

Rotation does not remove old keys. Disk rotations and generated environment values retain them so credentials encrypted under earlier keys remain readable; there is currently no automatic key retirement command.

`--services` uploads this run only to the named services from `remote list`; it is exclusive with `--local`. `--label` sets the label shown in history.

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

`rescan` adds archives found in local storage to history; `--remote` also scans remote storage. `keep <timestamp>...` protects backups from retention, and `keep --off` releases them. `download <timestamp> --to=<directory>` copies a backup's archives, fetching missing ones from remote storage and verifying size and checksums. `--type`, `--components` and `--point` select what to copy as for `restore`, an existing file is only replaced with `--force`, and copies are owner-only. Archives stay encrypted.

`delete <timestamp>` deletes the complete incremental chain, local archives, recorded inventories and history. Remote deletion requires `--remote`; confirmation requires `--yes` to skip. Active chains and restore points authorizing pending updates are protected.

`delete` also selects chains by age and count instead of by timestamp: `--older-than=<days>` and `--keep=<count>` (all but the newest chains) select chains, and with both a chain must match both. Timestamps cannot be combined with the filters, and `--keep=0` is rejected. Chains protected with `keep`, active chains and restore points authorizing pending updates are skipped with a warning, never deleted by a filter. `--dry-run` lists what would be deleted; otherwise the command confirms once for the whole selection, continues past a chain that fails and exits with an error naming each one. Partial deletion retains metadata and successful remote deletion receipts so the command can be retried.

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

Retention counts must be positive integers. `retention set` requires at least one setting. `--mode=after_backup` prunes after backups; `--mode=daily` runs count-based cleanup at a valid supplied or saved `--time`. Daily cleanup retains protected backups, deletes whole chains, yields to backups and retries failed deletions. `retention run` runs that cleanup now, in this process and through all its resumptions, after confirmation. It needs `--mode=daily`; with `after_backup` the counts apply after each backup and the command fails. It fails as well while a backup is running, and the cleanup retries in five minutes. Scheduled jobs continue through WP-Cron, including `wp cron event run --due-now`.

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

`list`, `sources`, `schedule list`, `retention status`, `pre-update status` and every listing below support `--format=table|json|csv` and `--fields`.

## Restore

For optional coordinated full snapshots and MySQL/MariaDB binary-log recovery, use [`wp backupplus pitr`](binary-log-backups.md). It has separate setup, capture, chain verification, isolated historical reconstruction and replacement commands. Both full snapshots and increments retain portable file/position metadata. Every `pitr` command prints JSON by default; `--format=table` or `csv` prints a list of records as rows, and anything nested as one `key` and `value` row per value with dotted paths. Existing `backup` and `restore` commands continue to handle ordinary logical backups.

```sh
wp backupplus restore 1792718455
wp backupplus restore 1792718455 --type=database
wp backupplus restore 1792718455 --components=plugins,db --yes
wp backupplus restore 1792718455 --plugin=akismet
wp backupplus restore 1792718455 --table=wp_options
wp backupplus restore-points 1792718455 --format=json
wp backupplus restore 1792718455 --type=files --point=1792718400
wp backupplus restore 1792718455 --site=3
wp backupplus restore 1792718455 --import-into=new --slug=shop
```

`restore <timestamp>` restores all available components by default. `--type=files|database` limits the selection; `--components` selects exact history IDs, including `wpcore`, `custom-…` and external dumps such as `db1`. Additional sources use their recorded destinations. External dumps use the saved database connection with the corresponding ID; that connection must still exist. The command asks for overwrite confirmation unless `--yes` is supplied.

`--plugin` restores only the named top-level plugin directory or standalone PHP filename. `--table` restores only the exact source table name from a full WordPress database dump. These options select the plugins and WordPress database components respectively and cannot be combined with each other or with unrelated components. Single-table restores are not supported for binary-log recovery or external database dumps.

On a WordPress network, `--site=<blog-id>` restores only that site of a network backup, and `--import-into=new --slug=<slug>` or `--import-into=<blog-id>` imports a single-site backup into a new site or over an existing one; both select only the database and uploads. See [WordPress networks](multisite.md). BackupPlus commands run in the context of the network's main site, and refuse to run with `--url` set to another site.

File chains restore through their latest increment unless `--point` selects a timestamp returned by `restore-points`. These points use the engine's existing minute resolution. Database dumps come from the selected backup history entry. A restored options table keeps the backup locks held when the restore ran, not those recorded in the dump (the backup's own lock was held while it was taken), so a backup can start as soon as a restore finishes. When a database from another address is restored (a migration or a network import), the current BackupPlus settings are kept, and those the site does not have stay unset. Core restores preserve the current `wp-config.php` unless `--include-wp-config` is supplied.

Encrypted restores prompt in an interactive terminal for the private key and its passphrase. Paste a native age identity or the complete multiline OpenSSH/PEM private key, including its BEGIN and END lines. Leave the passphrase blank for an unencrypted key. Legacy passphrase-encrypted archives prompt separately for their archive passphrase. Input is hidden; `--yes` skips only overwrite confirmation.

Private keys and passphrases stay in the restore process's memory. They are never placed in settings, job data, logs, command-line arguments, environment variables or files. There are no command-line secret flags or piped-stdin fallback. Hidden input requires a terminal and `stty`.

All selected archives are fetched and verified, and every selected encrypted archive is decrypted before restoring site files or database tables. Decrypted staging files use owner-only permissions and are removed on success, decryption failure or ordinary process shutdown. The restore engine receives only process-local archive bindings. A new invocation prompts again; it cannot resume by retrieving a saved key or passphrase.

Restores take `--dry-run`, which lists the archives the selection would use, their size, whether they are encrypted and whether they are already local. It fetches, decrypts and changes nothing.

## Running jobs

```sh
wp backupplus jobs list
wp backupplus jobs log 0123456789ab --tail=50
wp backupplus jobs resume 0123456789ab
wp backupplus jobs cancel 0123456789ab --yes
```

`jobs list` shows every job with a scheduled resumption: its stage, start time, resumption number and next run, with an `overdue` flag when WP-Cron did not run it. `jobs log` prints a job log, the most recently modified one when no job is named; `--tail` keeps only the last lines. `jobs resume` runs the job's next resumption in this process, for sites where WP-Cron does not run; a resumption that is not yet due needs `--force`. `jobs cancel` stops a job and removes its resumption, the same as the Stop button.

## Remote storage

```sh
wp backupplus remote list
wp backupplus remote set s3generic settings.json --label="Offsite" --select
wp backupplus remote test s3generic --instance=s-1a2b
wp backupplus remote remove s3generic s-1a2b --yes
```

`remote list` shows saved destinations without credentials; `--all` includes unconfigured services. `remote set <service> <file|->` reads a JSON object of the provider's saved setting names (`accesskey`, `secretkey`, `path`, `endpoint`, `host`, `user`, `pass` and so on) from a file or stdin, so credentials never appear in arguments or the process list. Keys already saved for the instance are kept, `--instance` updates one instance and a new one is created without it, and `--select` adds the service to the destinations backups use. Credentials are encrypted at rest as when saved from the settings screen. `remote test` runs the same upload, download and delete check as the settings screen from the saved settings. `remote remove` deletes an instance's settings; backups already stored there are kept.

OAuth services start in a browser. WP-CLI cannot start the flow: it sends the provider's address as a redirect header and the authorisation link carries a nonce of the logged-in administrator. On the settings screen follow the service's authorisation link, choose "Having problems authenticating?" at the end and give the displayed code to `remote authorize <service> <file|->` instead of pasting it into the page. Only Dropbox and Google Drive offer manual codes. `remote deauthorize <service>` removes an authorisation.

## Settings

```sh
wp backupplus settings get
wp backupplus settings get backupplus_retain
wp backupplus settings set backupplus_include_more_path '["/srv/site-data"]' --format=json
wp backupplus settings export --file=settings.json
wp backupplus settings import settings.json --yes
```

`settings` manages the options the settings screen saves, with the screen's validation, except the ones with their own commands: credentials, destinations (`remote`), external databases (`databases`), notifications (`notify`), recovery (`pitr`) and run state. Schedules and retention are validated and reconciled before any write. `settings export` writes the settings screen's export format without credentials; with `--file` it is owner-only and never replaces a file without `--force`. `settings import` applies an export with the screen's import rules, which replace report addresses, extra retention rules and additional file paths; saved destinations and external databases are kept.

```sh
wp backupplus databases list
wp backupplus databases set connection.json
wp backupplus databases test 1
wp backupplus databases remove 1 --yes
```

External databases take a JSON object with `host`, `name`, `user`, `pass` and optionally `prefix` and `enabled`, from a file or stdin. Use a dedicated account that can read the database, not an administrative one. `set` tests the connection first unless `--skip-test` is given, and `--id` updates a connection. `remove` also drops its saved table selection. `sources` lists the connections with their selections.

## Notifications and storage limits

```sh
wp backupplus notify status
wp backupplus notify set --email=1
echo '{"server":"https://ntfy.sh","topic":"my-backups"}' | wp backupplus notify set --ntfy=-
wp backupplus notify test
wp backupplus storage-limits set local --alert=8 --quota=10
wp backupplus storage-limits set s3 --instance=s-1a2b --quota=50
wp backupplus storage-limits status
wp backupplus storage-limits run --yes
```

`notify set --email` turns the report email to the site administrator address on or off. The ntfy server, topic and token come from a JSON file or stdin because the topic is a secret; `status` never prints them. `notify test` sends through every configured channel and fails if any one fails. See [notifications](notifications.md).

`storage-limits set` takes decimal gigabytes (`0` disables), per destination, with `local` for this server. The alert must not exceed the quota. `status` shows usage as of the last measurement; `run` measures now and enforces quotas, which deletes the oldest unprotected backups, so it asks for confirmation. See [storage limits](storage-limits.md).

## Diagnostics

```sh
wp backupplus doctor
wp backupplus cron
wp backupplus db-size --format=json
```

`doctor` checks the backup directory, free disk space, WP-Cron, overdue events, the credential key store, schedules and the latest backup. It exits with an error when a check fails and only warns otherwise. `cron` lists the plugin's scheduled events with the time they are late. `db-size` lists WordPress tables with their size in bytes.
