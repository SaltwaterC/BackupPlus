# WordPress networks

On a WordPress network (multisite), BackupPlus backs up and restores the network as a whole, and can also restore one site of it or import a single-site backup as one of its sites.

## Activation and management

BackupPlus must be network-activated; activating it for one site of a network is refused, and a site where it was activated individually shows a notice pointing to Network Admin > Plugins. Its settings, backup history, jobs and scheduled events belong to the network: they are kept in the main site's options table and network options, and every backup, restore and event runs in the main site's context.

Only super administrators manage BackupPlus, from Network Admin > Settings > BackupPlus: the capability is `manage_network_options` instead of `manage_options`, so an administrator of a single site, the main site included, has no access. Network Admin saves the settings through `edit.php?action=backupplus`, with the same validation as `options.php` on a single site.

The network's other sites have no BackupPlus screens, AJAX actions or events. `BackupPlus_Options::get_backupplus_option()` reads the main site's configuration whichever site a request is for, BackupPlus events (`backupplus_*` hooks) cannot be scheduled on them and existing ones are removed, and the per-site upgrade routines (legacy option migration, credential encryption) only run on the main site. `wp backupplus` refuses to run with `--url` set to a site other than the main one.

A backup of a network covers every site: all tables with the network's table prefix, the uploads of every site (`uploads/sites/<blog-id>`) and the shared plugins, themes and other content. Its database header records the network's sites, which a restore uses to offer them.

## Restoring

When the database of a network backup is restored, the restore options offer:

- **The entire network**, the default: everything in the backup is restored, as on a single site.
- **Only site N**: the site's own tables and, if selected, its uploads are restored over the network's site with the same ID, which must exist. For the main site, its tables are those with the bare table prefix except the network's shared tables, and its uploads are the uploads directory except `sites/`. The network's users, sites and network options (`users`, `usermeta`, `blogs`, `blogmeta`, `site`, `sitemeta`, `signups`, `registration_log`, `blog_versions`), the other sites' tables and uploads, tables without the table prefix, and the plugins, themes and WordPress itself are left as they are, so only the database and uploads components can be selected.

A single-site backup restored into a network is imported into one site of it, chosen in the database restoration options: a **new site**, whose sub-directory or sub-domain is given there, or **an existing site** other than the main site, which it replaces. Only its database and uploads are imported; plugins, themes and other content are the network's.

- Its tables become the site's (`wp_<blog-id>_…`), replacing any that exist, through the usual atomic restore. The prefixed option and user metadata names (`user_roles`, `capabilities`, `user_level`) follow.
- Its addresses are replaced with the site's: its site and home URLs, its content URL with the site's (`<site URL>/wp-content`, unless `WP_CONTENT_URL` sets another) and its uploads URL with the site's (`…/uploads/sites/<blog-id>`). A new site's addresses are its sub-directory or sub-domain of the network; an existing site keeps its own site and home URLs, including a domain mapped to it in Network Admin > Sites > Edit Site.
- Its users are merged into the network's. A user whose login, or else email address, already exists in the network is that account: the account is unchanged, and gets its roles and other settings for this site from the backup. Other users are added, with their passwords, and the site as their primary site. Authors of posts, comments and links are changed to the network's user IDs; content of users not in the backup goes to the super administrator who ran the import.
- Its BackupPlus and UpdraftPlus settings and its `upload_path` and `upload_url_path` options are removed: the site uses the network's, which the import leaves as they were.
- Its uploads go into the site's uploads directory.

While one site is restored or imported, only that site is in maintenance (a `.maintenance` file in its uploads directory, which super administrators, WP-Cron and WP-CLI pass); the site's previous uploads are moved to `uploads-old/` (`uploads-old/sites/<blog-id>` for a site other than the main one), where "Delete old folders" removes them. As on a single site, a restore started from the dashboard is refused while previous uploads it would replace are still there: those of the same site, not those kept by restores of the whole network or of other sites, which a restore of one site, the main one included, leaves in place. WP-CLI replaces them. The database is restored before the uploads, so a backup that does not match the choice (a single-site backup restored as a site of a network, a network backup imported as a single site) is refused before anything changes; BackupPlus backups record which they are, and for others the database header is read.

From WP-CLI, `wp backupplus restore <timestamp> --site=<blog-id>` restores one site of a network backup, and `--import-into=new --slug=<slug>` or `--import-into=<blog-id>` imports a single-site backup. Without either, a restore on a network restores the entire network, and refuses a single-site backup.

Point-in-time recovery of a network replays the binary logs of the whole database, so it always restores the entire network; its full snapshot can be restored for one site like any network backup.

## Development

`dev/test-sites.sh` creates two networks next to the single sites (see `dev/README.md`): `backupplus-test-ms`, with sub-directories, on port 18190, and `backupplus-test-msd`, with sub-domains (`<site>.localhost`), on port 18191. `scripts/integration/check-multisite.php`, part of `make test-wordpress`, runs on both. It checks network management, a site's isolation from BackupPlus, restoring the whole network and one site, including a site with a mapped domain, starting a backup straight after a restore, and importing a backup taken on a leased single site into a new site, over an existing one and over one with a mapped domain, whose content and uploads it then fetches over HTTP. `scripts/test-multisite-restore-browser.mjs`, part of `make test-client-browser`, does the same restores from Network Admin's restore dialog in Chromium on the sub-directory network, and checks that an address already taken is refused before anything changes.
