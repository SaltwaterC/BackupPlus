<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct $wpdb query is required for this operation.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- some query operations need to always receive the most up-to-date or actual data directly from the database, reducing the risk of serving stale information.
// phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged -- some functions, like set_time_limit() and ini_set(), are used to temporarily change PHP configuration values based on the script's needs (e.g., processing large datasets or performing long operations).
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}


require_once __DIR__ . '/restore-search-replace/trait-restore-hooks.php';
require_once __DIR__ . '/restore-search-replace/trait-admin-notices.php';
require_once __DIR__ . '/restore-search-replace/trait-table-rewrite.php';
require_once __DIR__ . '/restore-search-replace/trait-search-replace.php';

// Search/replace code adapted in according with the licence from https://github.com/interconnectit/Search-Replace-DB
// Methods are grouped by concern in includes/restore-search-replace/.

// phpcs:ignore
class BackupPlus_Restore_Search_Replace {
	use BackupPlus_Restore_Search_Replace_Restore_Hooks;
	use BackupPlus_Restore_Search_Replace_Admin_Notices;
	use BackupPlus_Restore_Search_Replace_Table_Rewrite;
	use BackupPlus_Restore_Search_Replace_Replace;

	private $is_migration;

	private $restored_blogs = false;

	private $restored_sites = false;

	private $wpdb_obj = false;

	protected $restore_options = array();

	private $page_size = 5000;

	private $old_abspath = '';

	/**
	 * Whether to use $wpdb or not
	 *
	 * @var Bool
	 */
	private $use_wpdb;

	/**
	 * Table prefix
	 *
	 * @var String
	 */
	private $base_prefix;

	/**
	 * The database handle object or false boolean
	 *
	 * @var Mixed
	 */
	private $mysql_dbh;

	/**
	 * Whether the object is using mysqli or not
	 *
	 * @var Boolean
	 */
	private $use_mysqli;

	/**
	 * Variable to store migration progress
	 *
	 * @var Array
	 */
	private $report;

	/**
	 * The site url
	 *
	 * @var String
	 */
	protected $siteurl;

	/**
	 * The old site url
	 *
	 * @var String
	 */
	private $old_siteurl;

	/**
	 * The home url
	 *
	 * @var String
	 */
	protected $home;

	/**
	 * The old home url
	 *
	 * @var String
	 */
	private $old_home;

	/**
	 * The wp-content url
	 *
	 * @var String
	 */
	protected $content;

	/**
	 * The old wp-content url
	 *
	 * @var String
	 */
	protected $old_content;

	/**
	 * The uploads url
	 *
	 * @var String
	 */
	protected $uploads;

	/**
	 * The old uploads url
	 *
	 * @var String
	 */
	protected $old_uploads;

	/**
	 * Tables for search and replace operation
	 *
	 * @var Array
	 */
	private $which_tables;

	/**
	 * Tables that have been searched and replaced
	 *
	 * @var Array
	 */
	private $tables_replaced;

	/**
	 * Sites array of multisite
	 *
	 * @var Array
	 */
	private $original_sites;

	/**
	 * Constructor, called during BackupPlus initialization.
	 */
	public function __construct() {
		add_action( 'backupplus_restored_db', array( $this, 'backupplus_restored_db' ), 10, 2 );
		add_action( 'backupplus_restored_db_table', array( $this, 'backupplus_restored_db_table' ), 10, 3 );
		add_action( 'backupplus_restore_db_pre', array( $this, 'backupplus_restore_db_pre' ) );
		add_action( 'backupplus_restore_db_record_old_siteurl', array( $this, 'backupplus_restore_db_record_old_siteurl' ) );
		add_action( 'backupplus_restore_db_record_old_home', array( $this, 'backupplus_restore_db_record_old_home' ) );
		add_action( 'backupplus_restore_db_record_old_content', array( $this, 'backupplus_restore_db_record_old_content' ) );
		add_action( 'backupplus_restore_db_record_old_uploads', array( $this, 'backupplus_restore_db_record_old_uploads' ) );
		add_action( 'backupplus_restore_db_record_old_abspath', array( $this, 'backupplus_restore_db_record_old_abspath' ) );
		add_action( 'backupplus_restored_plugins_one', array( $this, 'restored_plugins_one' ) );
		add_action( 'backupplus_debugtools_dashboard', array( $this, 'debugtools_dashboard' ), 30 );
		add_action( 'backupplus_adminaction_searchreplace', array( $this, 'adminaction_searchreplace' ) );
		add_action( 'backupplus_creating_table', array( $this, 'backupplus_creating_table' ), 10, 1 );
		// Displaying notices after migration if migrated url exists in .htaccess file
		add_action( 'all_admin_notices', array( $this, 'migration_admin_notices' ) );

		add_filter( 'backupplus_dbscan_urlchange', array( $this, 'dbscan_urlchange' ), 10, 3 );
		add_filter( 'backupplus_https_to_http_additional_warning', array( $this, 'https_to_http_additional_warning' ), 10, 1 );
		add_filter( 'backupplus_http_to_https_additional_warning', array( $this, 'http_to_https_additional_warning' ), 10, 1 );
		add_filter( 'backupplus_dbscan_urlchange_www_append_warning', array( $this, 'dbscan_urlchange_www_append_warning' ), 10, 1 );

		add_filter( 'backupplus_restorecachefiles', array( $this, 'restorecachefiles' ), 10, 2 );
		add_filter( 'backupplus_restored_plugins', array( $this, 'restored_plugins' ) );

		// Both MU and normal site
		// Actions/filters that need UD to be fully loaded before we can consider adding them
		add_action( 'plugins_loaded', array( $this, 'lite_plugins_loaded' ) );
	}
}
