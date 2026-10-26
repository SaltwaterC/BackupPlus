# Backup times and retention cleanup

## Exact backup times

File, database, incremental file and Database point-in-time recovery schedules can each run at an exact time. In Settings, choose a schedule, select **Run at an exact time**, then choose when it runs. Database timing stays editable when the database uses the same frequency as files.

- Daily, weekly, fortnightly and monthly schedules run once at the chosen local time. Weekly and fortnightly schedules also need a weekday; monthly schedules need a day from 1 to 28.
- Hourly schedules take only the minute past the hour, for example every hour at :15.
- Schedules of whole hours, from every two hours to every twelve hours, take which hours they run at and the minute. Every four hours offers 00, 04, 08, 12, 16 and 20 through 03, 07, 11, 15, 19 and 23; each choice lists its run times with the chosen minute, such as 01:30, 05:30, 09:30, 13:30, 17:30 and 21:30.
- Custom intervals shorter than an hour take the minutes past the hour from their choices, such as :05, :20, :35 and :50 every 15 minutes. Other custom intervals take a time of day, which sets the day's runs. Custom intervals qualify only if they divide a day exactly into whole minutes.
- Incremental file backups can run every minute, 5, 10, 15 or 30 minutes; every 1, 2, 3, 4, 6, 8 or 12 hours; or daily. **Incremental file backups** offers each schedule once: WordPress's own "Once Hourly" duplicates **Every hour**, and a saved "Once Hourly" choice runs as **Every hour** without moving its next run.
- The every-minute incremental schedule has no exact-time control because it already runs in every minute slot.
- Database point-in-time recovery uses the same exact-time controls for its selected interval. Its every-minute interval has no exact-time control because it already runs in every minute slot.

Schedules shorter than a day store their first run of the day as the start time. For example, choosing 13:30 every four hours is saved as 01:30, the same schedule.
- Fortnightly schedules keep their two-week phase when the time or weekday changes. The phase is set by the first run after fortnightly exact timing is chosen.

Times use the site timezone from **Settings → General** and minute precision. They are stored as UTC cron timestamps and recalculated when the timezone changes. During daylight-saving changes:

- A time skipped when clocks go forward runs at the first valid minute afterwards. For example, 01:30 runs at 02:00 in Europe/London on the spring change.
- A time that occurs twice when clocks go back runs once, at its first occurrence.

A late run does not catch up on missed runs. The next run is the next calendar occurrence of the chosen time.

Files and database backups run as one job only when their complete schedules match: frequency, exact-time setting, time and day. Different exact schedules run independently, even when they are only minutes apart. The ten-minute combining margin applies only to schedules without exact times.

Existing installations keep their current timing. Saved start times do not enable exact scheduling until **Run at an exact time** is selected. Without an exact time, a newly chosen incremental schedule still starts two minutes later. Incremental runs are never combined with full backups.

## Retention cleanup

**Delete old backups** controls when backups beyond the retention counts are removed:

- **After each backup** (default) keeps the existing behaviour.
- **Daily at a chosen time** runs a separate cleanup job at that local time. Backups then skip retention pruning. The cleanup creates no archive, backup history entry, or backup report.

Cleanup uses the same retention counts and rules as backups. It skips **Always Keep** backups (the lock icon in **Existing backups**, or **Lock**/**Unlock** on selected rows, toggles this), imported backups and pre-update restore points. Incremental chains are deleted as a whole. With local storage only, it respects the setting that disables local deletion. Deleting temporary local copies after a successful upload is unchanged.

The cleanup waits five minutes when a backup is running. If a backup starts while cleanup is running, the cleanup saves its progress, stops, and continues five minutes later. An interrupted cleanup resumes through the normal backup resumption mechanism.

When storage cannot be loaded, the cleanup deletes nothing. Failed deletions are queued and retried before the next cleanup. **Next scheduled backups** on the Backup / Restore tab shows the next cleanup and the latest result, including failed and queued deletions, with a link to its log.

One-off backups, age-based expiry and scheduled deletion of selected backups are not part of this feature.

## WordPress cron

**Backup Now** queues a one-off WP-Cron event and returns the admin request while a separate cron request starts the backup. The web server needs another PHP worker to serve admin and site requests during that job; a single-worker server serializes them. WordPress starts scheduled tasks when the site receives a request. On a quiet site, backups and cleanup can start late. For predictable starts, see [Hooking WP-Cron into the system task scheduler](https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/). It explains how to disable request-triggered cron and run `wp-cron.php` from the server's task scheduler. For example, use a crontab entry running every five minutes:

```sh
*/5 * * * * curl -fsS https://example.com/wp-cron.php?doing_wp_cron >/dev/null
```

or, with WP-CLI:

```sh
*/5 * * * * cd /path/to/wordpress && wp cron event run --due-now >/dev/null
```

## Checks

- `.cache/php-tools/vendor/bin/phpunit --testsuite unit --filter BackupScheduleTest` checks run-time calculation for daily, sub-daily, weekly, fortnightly and monthly schedules. It also covers timezone offsets, daylight-saving gaps and repeated times, delayed runs, validation and schedule combining, without WordPress.
- `podman exec backupplus-dev-wordpress wp --skip-plugins=backupplus eval-file wp-content/plugins/backupplus/scripts/check-backup-schedule.php` uses disposable data to check:
  - event reconciliation and repeated, unrelated and rejected saves;
  - overdue events, `options.php` validation, imports and reset;
  - combining, timezone changes and delayed cron;
  - the cleanup job, including protected sets, chains, waiting, pausing, resumption and failed local and remote deletions.

  It restores the settings it changes.
- `node scripts/test-backup-schedule-browser.mjs` checks the settings controls, saving, refreshed schedule displays, rejected input, export fields and narrow layouts in Chromium. It restores the site's settings and cron schedule afterwards.

The checks are included in `make test-unit`, `make test-wordpress` and `make test-client-browser` respectively.
