<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, PHPCompatibility.Extensions.RemovedExtensions.mysql_DeprecatedRemoved -- Direct $wpdb query is required for this operation, legacy compatibility for very old PHP versions.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- some query operations need to always receive the most up-to-date or actual data directly from the database, reducing the risk of serving stale information.
// Static helpers for database work, grouped by concern in includes/database-utility/ (which also holds BackupPlus_WPDB_OtherDB_Utility).
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

require_once __DIR__ . '/database-utility/trait-tables.php';
require_once __DIR__ . '/database-utility/trait-session.php';
require_once __DIR__ . '/database-utility/trait-statements.php';
require_once __DIR__ . '/database-utility/trait-routines.php';
require_once __DIR__ . '/database-utility/class-wpdb-otherdb-utility.php';

class BackupPlus_Database_Utility {
	use BackupPlus_Database_Utility_Tables;
	use BackupPlus_Database_Utility_Session;
	use BackupPlus_Database_Utility_Statements;
	use BackupPlus_Database_Utility_Routines;

	/**
	 * Indicated which database is being used
	 *
	 * @var String
	 */
	private static $whichdb;

	/**
	 * The unfiltered table prefix - i.e. the real prefix that things are relative to
	 *
	 * @var String
	 */
	private static $table_prefix_raw;

	/**
	 * The object to perform database operations
	 *
	 * @var Object
	 */
	private static $dbhandle;

	/**
	 * The array of table status, used as a cache to reduce unnecessary DB reads for doing the same thing over and over
	 *
	 * @var Array
	 */
	private static $table_status = array();

	/**
	 * Initialize required variables
	 *
	 * @param String $whichdb          - which database is being backed up
	 * @param String $table_prefix_raw - the base table prefix
	 * @param Object $dbhandle         - WPDB object
	 */
	public static function init( $whichdb, $table_prefix_raw, $dbhandle ) {
		self::$whichdb          = $whichdb;
		self::$table_prefix_raw = $table_prefix_raw;
		self::$dbhandle         = $dbhandle;
	}
}
