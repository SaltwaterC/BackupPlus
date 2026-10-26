<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct $wpdb query is required for this operation.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- some query operations need to always receive the most up-to-date or actual data directly from the database, reducing the risk of serving stale information.
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

require_once __DIR__ . '/backup-history/trait-query.php';
require_once __DIR__ . '/backup-history/trait-storage.php';
require_once __DIR__ . '/backup-history/trait-rebuild.php';

/**
 * A class to deal with management of backup history.
 * N.B. All database access should come through here. However, this class is not the only place with knowledge of the data structure.
 *
 * The methods live in traits under includes/backup-history/ (reading, saving, rebuilding); the static property stays here.
 */
class BackupPlus_Backup_History {
	use BackupPlus_Backup_History_Query;
	use BackupPlus_Backup_History_Storage;
	use BackupPlus_Backup_History_Rebuild;

	private static $backup_history_on_restore = null;
}
