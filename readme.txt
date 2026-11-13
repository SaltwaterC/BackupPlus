=== BackupPlus - Backup/Restore ===
Contributors: saltwaterc
Tags: backup, database backup, wordpress backup, cloud backup, migration
Requires at least: 6.5
Requires PHP: 7.2.5
Tested up to: 7.1
Stable tag: 3.0.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Back up, restore and migrate your site on a schedule, locally or to cloud storage, with encryption and point-in-time database recovery.

== Description ==

BackupPlus backs up, restores, and migrates WordPress websites. Backups can be run manually or scheduled, with files and the database handled on separate schedules. Restore selected parts of a backup or move a site to a new host or domain.

BackupPlus is derived from UpdraftPlus and retains compatibility with its backup format and stored settings. Existing UpdraftPlus backup sets can be restored with BackupPlus.

BackupPlus is a modified GPLv3 fork of UpdraftPlus, with changes through 2026. Original copyright and license notices are retained in the source files.

== Installation ==

1. Upload the BackupPlus plugin directory to `/wp-content/plugins/`.
2. Activate BackupPlus from the WordPress Plugins screen.
3. Open BackupPlus in the dashboard to configure backup schedules and storage.

== Frequently Asked Questions ==

= Can I restore backups created by UpdraftPlus? =

Yes. BackupPlus retains the upstream backup format and can restore existing UpdraftPlus backup sets.

= Where are my backups stored? =

BackupPlus can store backups locally or send them to a configured remote storage destination. The backup directory and storage settings are configured in the plugin.

= How do I get help? =

Contact the maintainers of the repository or distribution from which you obtained BackupPlus. Include the BackupPlus version, PHP and WordPress versions, and the relevant backup log.

== Changelog ==

= 3.0.0 - 21/Oct/2026 =

* SECURITY: Storage provider secrets, external database passwords and backup job snapshots are encrypted (ChaCha20-Poly1305, per-installation key), with key rotation, recovery keys and the `BACKUPPLUS_CREDENTIAL_KEYS` environment variable.
* SECURITY: Hardening of restore, uploads, remote and backup file names, database dumps, SQL construction, transport defaults (FTPS, S3 over HTTPS) and admin tools; restore output is escaped against stored XSS.
* FEATURE: age encryption of every archive to X25519 or SSH recipients, with key generation, decryption and extraction in the browser; private keys never reach the server.
* FEATURE: Incremental file backups with selectable restore points.
* FEATURE: Database point-in-time recovery from binary logs for MySQL 8.4 and MariaDB 10.11, 11.4 and 11.8.
* FEATURE: Back up WordPress core, additional files and directories, and external MySQL/MariaDB databases.
* FEATURE: Pre-update backups before plugin, theme and core updates.
* FEATURE: Exact schedule times, separate retention cleanup, backup locks, storage quotas and disk alerts.
* FEATURE: Restore a single plugin or database table.
* FEATURE: Zstandard archives when a `zstd` executable is available.
* FEATURE: Several destinations per storage type; SFTP/SCP, WebDAV, FTPS, Azure, Backblaze B2, Google Cloud Storage, OneDrive and pCloud work, and Dropbox and Google Drive connect directly, without a relay service.
* FEATURE: ntfy notifications, multisite network management and restore, and a complete `wp backupplus` command.
* TWEAK: Backup Now queues a WP-Cron event, the Decryption tab becomes the Extract tab, the onboarding wizard and promotions are removed, and phpseclib 3 and a newer Guzzle are bundled. PHP 7.2.5 and WordPress 6.5 or newer are required.

The complete release notes are in changelog.txt, and older UpdraftPlus history in changelog-old.txt; both are included with the plugin.

== Screenshots ==

1. Configuring your backups

2. Take a backup

3. Choose which components to restore

4. Restoration shows as successful

5. To migrate, first click 'Database', 'Plugins' etc. to download these backups to your local machine.

6. On the destination site, 'upload backup files' from the source site to restore and migrate to the new destination.

== License ==

    Copyright 2011-26 David Anderson

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; version 3 of the License.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA

Non-English translators are provided by volunteers, and wordpress.org does not give plugin developers control over their translations; thus relying on them is at your own risk; BackupPlus cannot guarantee that translations from the original English are accurate.

We recognise and thank those mentioned at https://updraftplus.com/acknowledgements/ for code and/or libraries used and/or modified under the terms of their open source licences.

== Upgrade Notice ==
* 1.26.13: Important security updates (.9 to .13) - see the changelog for details. A recommended update for all; please update immediately.
