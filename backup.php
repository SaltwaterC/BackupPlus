<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- we try to reduce overhead by bypassing WP APIs and other extra layers; Some custom complex queries tailored specifically to our needs, giving us full control over the SQL commands and data manipulation
// phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename -- rename() usage is intentional and safe within this context
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fgets, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.WP.AlternativeFunctions.file_system_operations_fputs, WordPress.WP.AlternativeFunctions.file_system_operations_is_writeable, WordPress.WP.AlternativeFunctions.file_system_operations_chown, WordPress.WP.AlternativeFunctions.file_system_operations_chgrp, WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Native PHP fileystem function is used for direct control and performance because it can bypass additional layers of abstraction so that no overhead from the WordPress filesystem API's internal handling
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- some query operations need to always receive the most up-to-date or actual data directly from the database, reducing the risk of serving stale information.
// phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged -- some functions, like set_time_limit() and ini_set(), are used to temporarily change PHP configuration values based on the script's needs (e.g., processing large datasets or performing long operations).
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

if ( ! class_exists( 'BackupPlus_PclZip' ) ) {
	backupplus_try_include_file( 'includes/class-zip.php', 'require_once' );
}

require_once __DIR__ . '/includes/backup/trait-retention.php';
require_once __DIR__ . '/includes/backup/trait-cloud.php';
require_once __DIR__ . '/includes/backup/trait-files.php';
require_once __DIR__ . '/includes/backup/trait-exclusions.php';
require_once __DIR__ . '/includes/backup/trait-zip-writer.php';
require_once __DIR__ . '/includes/backup/trait-zip-entries.php';
require_once __DIR__ . '/includes/backup/trait-zip-manifest.php';
require_once __DIR__ . '/includes/backup/trait-database.php';
require_once __DIR__ . '/includes/backup/trait-database-tables.php';

/**
 * This file contains code that is only needed/loaded when a backup is running
 */
class BackupPlus_Backup {
	use BackupPlus_Backup_Retention;
	use BackupPlus_Backup_Cloud;
	use BackupPlus_Backup_Files;
	use BackupPlus_Backup_Exclusions;
	use BackupPlus_Backup_Zip_Writer;
	use BackupPlus_Backup_Zip_Entries;
	use BackupPlus_Backup_Zip_Manifest;
	use BackupPlus_Backup_Database;
	use BackupPlus_Backup_Database_Tables;

	private $index = 0;

	private $manifest_path;

	private $incremental_manifest;

	private $zipfiles_added;

	private $zipfiles_added_thisrun = 0;

	public $zipfiles_dirbatched;

	public $zipfiles_batched;

	public $zipfiles_skipped_notaltered;

	private $symlink_reversals = array();

	private $makezip_recursive_batchedbytes;

	private $zip_split_every = 419430400; // 400MB

	private $zip_last_ratio = 1;

	private $whichone;

	private $zip_basename = '';

	private $zipfiles_lastwritetime;

	// 0 = unknown; false = failed
	public $binzip = 0;

	private $dbhandle;

	private $dbhandle_isgz;

	private $whichdb;

	private $whichdb_suffix;

	// Array of entities => times
	private $altered_since = -1;

	// Time for the current entity
	private $makezip_if_altered_since = -1;

	private $excluded_extensions = false;

	private $excluded_wildcards = false;

	private $excluded_prefixes = false;

	private $use_zip_object = 'BackupPlus_ZipArchive';

	public $debug = false;

	public $backupplus_dir;

	private $site_name;

	private $wpdb_obj;

	private $job_file_entities = array();

	private $first_run = 0;

	// Record of zip files created
	private $backup_files_array = array();

	// Used when deciding to use the 'store' or 'deflate' zip storage method
	private $extensions_to_not_compress = array();

	// Append to this any skipped tables
	private $skipped_tables;

	// When initialised, a boolean
	public $last_storage_instance;

	// The absolute upper limit that will be considered for a zip batch (in bytes)
	private $zip_batch_ceiling;

	private $backup_excluded_patterns = array();

	// Bytes of uncompressed data written since last open
	private $db_current_raw_bytes = 0;

	private $table_prefix;

	private $table_prefix_raw;

	private $many_rows_warning = false;

	private $expected_rows = false;

	// @var Boolean
	private $try_split = false;

	private $zip_microtime_start;

	public $current_service;

	private $existing_files;

	private $existing_files_rawsize;

	private $existing_zipfiles_size;

	private $dbinfo;

	private $duplicate_tables_exist = false;

	private $first_linked_index;

	private $current_pruning_instance_id = '';

	private $hash_list_of_complete_pruned_files = array();

	// The backup history as it was read when a retention cleanup job began pruning
	private $history_snapshot = null;

	private $pruning_number_of_files = 10;

	private $minimum_time_to_cache_pruned_files = 25; // seconds

	/**
	 * Current processing remote storage instance id
	 *
	 * @var String
	 */
	public $current_instance;

	/**
	 * Backup entity name for which a zip backup file is currently making
	 *
	 * @var String
	 */
	public $make_zipfile_source;

	// private $source;

	/**
	 * Class constructor
	 *
	 * @param Array|String $backup_files  - files to backup, or (string)'no'
	 * @param Integer      $altered_since - only backup files altered since this time (UNIX epoch time)
	 */
	public function __construct( $backup_files, $altered_since = -1 ) {

		global $backupplus;

		$this->site_name = $this->get_site_name();

		$this->debug          = BackupPlus_Options::get_backupplus_option( 'backupplus_debug_mode' );
		$this->backupplus_dir = $backupplus->backups_dir_location();

		backupplus_try_include_file( 'includes/class-database-utility.php', 'require_once' );

		if ( 'no' === $backup_files ) {
			$this->use_zip_object = 'BackupPlus_PclZip';
			return;
		}

		$this->extensions_to_not_compress = array_unique( array_map( 'strtolower', array_map( 'trim', explode( ',', BACKUPPLUS_ZIP_NOCOMPRESS ) ) ) );

		$this->backup_excluded_patterns = array(
			array(
				// all in one wp migration pattern: WP_PLUGIN_DIR/all-in-one-wp-migration/storage/*/*.wpress, `ai1wm-backups` folder in wp-content is already implicitly handled on the UDP settings with a `*backups` predefined exclusion rule for `others` directory
				'directory' => realpath( WP_PLUGIN_DIR ) . DIRECTORY_SEPARATOR . 'all-in-one-wp-migration' . DIRECTORY_SEPARATOR . 'storage',
				'regex'     => '/.+\.wpress$/is',
			),
		);

		$this->altered_since = $altered_since;

		$resumptions_since_last_successful = $backupplus->current_resumption - $backupplus->last_successful_resumption;

		// false means 'tried + failed'; whereas 0 means 'not yet tried'
		// Disallow binzip on OpenVZ when we're not sure there's plenty of memory
		if ( 0 === $this->binzip && ( ! defined( 'BACKUPPLUS_PREFERPCLZIP' ) || ! BACKUPPLUS_PREFERPCLZIP ) && ( ! defined( 'BACKUPPLUS_NO_BINZIP' ) || ! BACKUPPLUS_NO_BINZIP ) && ( $backupplus->current_resumption < 9 || $resumptions_since_last_successful < 2 ) ) {

			if ( @file_exists( '/proc/user_beancounters' ) && @file_exists( '/proc/meminfo' ) && @is_readable( '/proc/meminfo' ) ) {// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
				$meminfo = @file_get_contents( '/proc/meminfo', false, null, 0, 200 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
				if ( is_string( $meminfo ) && preg_match( '/MemTotal:\s+(\d+) kB/', $meminfo, $matches ) ) {
					$memory_mb = $matches[1] / 1024;
					// If the report is of a large amount, then we're probably getting the total memory on the hypervisor (this has been observed), and don't really know the VPS's memory
					$vz_log = 'OpenVZ; reported memory: ' . round( $memory_mb, 1 ) . ' MB';
					if ( $memory_mb < 1024 || $memory_mb > 8192 ) {
						$openvz_lowmem = true;
						$vz_log       .= ' (will not use BinZip)';
					}
					$backupplus->log( $vz_log );
				}
			}
			if ( empty( $openvz_lowmem ) ) {
				$backupplus->log( 'Checking if we have a zip executable available' );
				$binzip = $backupplus->find_working_bin_zip();
				if ( is_string( $binzip ) ) {
					$backupplus->log( "Zip engine: found/will use a binary zip: $binzip" );
					$this->binzip         = $binzip;
					$this->use_zip_object = 'BackupPlus_BinZip';
				}
			}
		}

		// In tests, PclZip was found to be 25% slower than ZipArchive
		if ( 'BackupPlus_PclZip' != $this->use_zip_object && empty( $this->binzip ) && ( ( defined( 'BACKUPPLUS_PREFERPCLZIP' ) && BACKUPPLUS_PREFERPCLZIP == true ) || ! class_exists( 'ZipArchive' ) || ! class_exists( 'BackupPlus_ZipArchive' ) || ( ! extension_loaded( 'zip' ) && ! method_exists( 'ZipArchive', 'AddFile' ) ) ) ) {
			global $backupplus;
			$backupplus->log( 'Zip engine: ZipArchive (a.k.a. php-zip) is not available or is disabled (will use PclZip (much slower) if needed)' );
			$this->use_zip_object = 'BackupPlus_PclZip';
		}

		$this->zip_batch_ceiling = ( defined( 'BACKUPPLUS_ZIP_BATCH_CEILING' ) && BACKUPPLUS_ZIP_BATCH_CEILING > 104857600 ) ? BACKUPPLUS_ZIP_BATCH_CEILING : 200 * 1048576;

		if ( defined( 'BACKUPPLUS_PRUNING_NUMBER_OF_FILES' ) && (int) BACKUPPLUS_PRUNING_NUMBER_OF_FILES > $this->pruning_number_of_files ) {
			$this->pruning_number_of_files = BACKUPPLUS_PRUNING_NUMBER_OF_FILES;
		}
		if ( defined( 'BACKUPPLUS_MIN_TIME_TO_CACHE_PRUNED_FILES' ) && (int) BACKUPPLUS_MIN_TIME_TO_CACHE_PRUNED_FILES > $this->minimum_time_to_cache_pruned_files ) {
			$this->minimum_time_to_cache_pruned_files = BACKUPPLUS_MIN_TIME_TO_CACHE_PRUNED_FILES;
		}

		add_filter( 'backupplus_exclude_file', array( $this, 'backup_exclude_file' ), 10, 2 );
	}

	/**
	 * A name as written in a dump comment line: quoted, with line breaks escaped so that it cannot end the comment
	 *
	 * @param String $name - the table or database name
	 *
	 * @return String
	 */
	private function comment_name( $name ) {
		return str_replace( array( "\r", "\n" ), array( '\r', '\n' ), BackupPlus_SQL::identifier( $name ) );
	}

	/**
	 * Statements on the connection of the database currently being dumped
	 *
	 * @return BackupPlus_SQL
	 */
	private function sql() {
		return new BackupPlus_SQL( $this->wpdb_obj->dbh );
	}

	/**
	 * Get a site name suitable for use in the backup filename
	 *
	 * @return String
	 */
	private function get_site_name() {
		// Get the blog name and rip out known-problematic characters. Remember that we may need to be able to upload this to any FTP server or cloud storage, where filename support may be unknown
		$site_name = str_replace( '__', '_', preg_replace( '/[^A-Za-z0-9_]/', '', str_replace( ' ', '_', substr( get_bloginfo(), 0, 32 ) ) ) );
		if ( ! $site_name || preg_match( '#^_+$#', $site_name ) ) {
			// Try again...
			$parsed_url    = parse_url( home_url(), PHP_URL_HOST );
			$parsed_subdir = untrailingslashit( parse_url( home_url(), PHP_URL_PATH ) );
			if ( $parsed_subdir && '/' != $parsed_subdir ) {
				$parsed_url .= str_replace( array( '/', '\\' ), '_', $parsed_subdir );
			}
			$site_name = str_replace( '__', '_', preg_replace( '/[^A-Za-z0-9_]/', '', str_replace( ' ', '_', substr( $parsed_url, 0, 32 ) ) ) );
			if ( ! $site_name || preg_match( '#^_+$#', $site_name ) ) {
				$site_name = 'WordPress_Backup';
			}
		}

		// Allow an over-ride. Careful about introducing characters not supported by your filesystem or cloud storage.
		return apply_filters( 'backupplus_blog_name', $site_name );
	}
}

require_once __DIR__ . '/includes/backup/class-wpdb-otherdb.php';
