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

	/**
	 * Whether every archive a backup set names is a backup archive basename. History saved before BPS-02 was fixed may name
	 * paths outside the backup directory, so a feature that reads or deletes a set's local files checks it first.
	 *
	 * @param Array $backup   One backup set from the history
	 * @param Array $entities The file entities, as keys
	 *
	 * @return Boolean
	 */
	public static function archive_names_are_safe( $backup, $entities ) {
		foreach ( $backup as $key => $names ) {
			if ( ! is_string( $key ) || ( ! isset( $entities[ $key ] ) && ! preg_match( '/\Adb[0-9]*\z/D', $key ) ) ) {
				continue;
			}
			foreach ( (array) $names as $name ) {
				if ( ! is_string( $name ) || ! BackupPlus_Manipulation_Functions::is_backup_basename( $name ) ) {
					return false;
				}
			}
		}
		return true;
	}
}
