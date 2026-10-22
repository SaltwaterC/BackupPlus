# Pre-update backups

Pre-update backups are enabled by default. A previously saved disabled setting is preserved. Change **Pre-update backups** in BackupPlus settings to enable or disable the feature.

Before a selected batch of plugin, theme or WordPress core updates, BackupPlus creates a standalone restore point containing the database and all available WordPress content directories. Existing file exclusions and archive encryption settings apply. WordPress core binaries are outside the backup format. These restore points appear in backup history as **Pre-update backup** and never become incremental bases.

Updates wait for archive creation and every configured remote upload. An unavailable provider, incomplete archive, failed upload, encryption error, warning or cancellation prevents authorization. The update screen shows progress, a protected log link, and retry/cancel controls. Keep a manual update screen open for automatic continuation. Classic screens submit back through WordPress's normal update and nonce checks.

WordPress cron runs the backups and their resumptions. Scheduled automatic updates use WordPress's own eligibility rules and updater, with a second eligibility check after the backup. The backup runs before the updater enters maintenance mode. Failed or cancelled automatic batches remain paused until an administrator retries; their recovery controls appear in the admin area.

Authorization covers only the selected targets, versions and package offers, expires one hour after completion, and cannot be reused for an already attempted target. Update requests are serialized. WordPress's native rollback can still run within the authorized core update request. Installations and translation-only updates do not require a backup.

Complete pre-update restore points have separate retention. BackupPlus keeps the larger of the file and database retention counts (each defaults to two), plus pinned sets and sets backing pending updates. Old complete sets are pruned as a whole only after a replacement backup succeeds. Missing remote providers or unsuccessful remote deletions preserve the history entry for a later pruning attempt.

## Checks

- `php scripts/test-pre-update-backups.php` checks completion evidence and defaults without WordPress.
- `podman exec backupplus-dev-wordpress wp --skip-plugins=backupplus eval-file wp-content/plugins/backupplus/scripts/check-pre-update-backups.php` checks real encrypted archives, upload resumption, native automatic updating, authenticated endpoints, classic update forms, authorization, rollback, concurrency and retention using disposable data.
- `node scripts/test-pre-update-browser.mjs` checks the shipped admin script in Chromium against a local WordPress admin page, using deterministic backup/update transports to test waits, bulk requests, retry, cancellation, plugin information dialogs and classic continuation. It also checks real AJAX nonce rejection.

The checks are included in `make test-unit`, `make test-wordpress` and `make test-client-browser` respectively.
