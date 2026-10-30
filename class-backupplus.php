<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- we try to reduce overhead by bypassing WP APIs and other extra layers; Some custom complex queries tailored specifically to our needs, giving us full control over the SQL commands and data manipulation
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fgets, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.WP.AlternativeFunctions.file_system_operations_fputs, WordPress.WP.AlternativeFunctions.file_system_operations_is_writeable, WordPress.WP.AlternativeFunctions.file_system_operations_chown, WordPress.WP.AlternativeFunctions.file_system_operations_chgrp, WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Native PHP fileystem function is used for direct control and performance because it can bypass additional layers of abstraction so that no overhead from the WordPress filesystem API's internal handling
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt_array, WordPress.WP.AlternativeFunctions.curl_curl_setopt, WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.curl_curl_multi_init, WordPress.WP.AlternativeFunctions.curl_curl_multi_add_handle, WordPress.WP.AlternativeFunctions.curl_curl_multi_exec, WordPress.WP.AlternativeFunctions.curl_curl_multi_select, WordPress.WP.AlternativeFunctions.curl_curl_multi_getcontent, WordPress.WP.AlternativeFunctions.curl_curl_multi_remove_handle, WordPress.WP.AlternativeFunctions.curl_curl_multi_close, WordPress.WP.AlternativeFunctions.curl_curl_error, WordPress.WP.AlternativeFunctions.curl_curl_close -- Direct cURL usage is intentional to leverage specific low-level options not available via the WordPress HTTP API.
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_print_r -- print_r is intentionally used to convert an array into a readable string or for controlled logging purposes.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- some query operations need to always receive the most up-to-date or actual data directly from the database, reducing the risk of serving stale information.
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- we use the set_error_handler() function to provide a flexible way of handling PHP errors according to our needs; we centralises error handling in one place and customises certain errors based on their severity and context.
// phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged -- some functions, like set_time_limit() and ini_set(), are used to temporarily change PHP configuration values based on the script's needs (e.g., processing large datasets or performing long operations).
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

require_once __DIR__ . '/includes/core/trait-wordpress-hooks.php';
require_once __DIR__ . '/includes/core/trait-auto-updates.php';
require_once __DIR__ . '/includes/core/trait-http.php';
require_once __DIR__ . '/includes/core/trait-logging.php';
require_once __DIR__ . '/includes/core/trait-environment.php';
require_once __DIR__ . '/includes/core/trait-binaries.php';
require_once __DIR__ . '/includes/core/trait-remote-transfer.php';
require_once __DIR__ . '/includes/core/trait-jobdata.php';
require_once __DIR__ . '/includes/core/trait-backup-dispatch.php';
require_once __DIR__ . '/includes/core/trait-backup-boot.php';
require_once __DIR__ . '/includes/core/trait-backup-resume.php';
require_once __DIR__ . '/includes/core/trait-backup-completion.php';
require_once __DIR__ . '/includes/core/trait-notifications.php';
require_once __DIR__ . '/includes/core/trait-entities.php';
require_once __DIR__ . '/includes/core/trait-restore-support.php';
require_once __DIR__ . '/includes/core/trait-database-analysis.php';
require_once __DIR__ . '/includes/core/trait-settings-keys.php';
require_once __DIR__ . '/includes/core/trait-phpseclib.php';
require_once __DIR__ . '/includes/core/trait-utilities.php';

class BackupPlus {
	use BackupPlus_Core_WordPress_Hooks;
	use BackupPlus_Core_Auto_Updates;
	use BackupPlus_Core_Http;
	use BackupPlus_Core_Logging;
	use BackupPlus_Core_Environment;
	use BackupPlus_Core_Binaries;
	use BackupPlus_Core_Remote_Transfer;
	use BackupPlus_Core_Jobdata;
	use BackupPlus_Core_Backup_Dispatch;
	use BackupPlus_Core_Backup_Boot;
	use BackupPlus_Core_Backup_Resume;
	use BackupPlus_Core_Backup_Completion;
	use BackupPlus_Core_Notifications;
	use BackupPlus_Core_Entities;
	use BackupPlus_Core_Restore_Support;
	use BackupPlus_Core_Database_Analysis;
	use BackupPlus_Core_Settings_Keys;
	use BackupPlus_Core_Phpseclib;
	use BackupPlus_Core_Utilities;

	public $version;

	public $plugin_title = 'BackupPlus';

	// Populated from the installed providers, in alphabetical display order.
	public $backup_methods = array();

	public $errors = array();

	public $nonce;

	public $file_nonce;

	public $logfile_name = '';

	public $logfile_handle = false;

	public $backup_time;

	public $job_time_ms;

	public $opened_log_time;

	private $backup_dir;

	private $jobdata;

	public $something_useful_happened = false;

	public $have_addons = false;

	// Used to schedule resumption attempts beyond the tenth, if needed
	public $current_resumption;

	public $last_successful_resumption;

	public $newresumption_scheduled = false;

	public $resumption_scheduled_for_cleanup = false;

	public $cpanel_quota_readable = false;

	public $error_reporting_stop_when_logged = false;

	private $combine_jobs_around;

	// Used for reporting
	private $attachments;

	private $remotestorage_extrainfo = array();

	public $no_checkin_last_time;

	private $removed_autoloaders = array();

	private $no_deprecation_warnings = false;

	private $backup_is_already_complete = false;

	private $error_count_before_cloud_backup = 0;

	private $semaphore;

	private $backup_semaphore;

	/**
	 * Class constructor
	 */
	public function __construct() {
		global $pagenow;
		// Initialisation actions - takes place on plugin load
		if ( $fp = fopen( BACKUPPLUS_DIR . '/backupplus.php', 'r' ) ) {
			$file_data = fread( $fp, 1024 );
			if ( preg_match( "/Version: ([\d\.]+)(\r|\n)/", $file_data, $matches ) ) {
				$this->version = $matches[1];
			}
			fclose( $fp );
		}

		$load_classes = array(
			'BackupPlus_Backup_History'            => 'includes/class-backup-history.php',
			'BackupPlus_Incremental_Backups'       => 'includes/class-incremental-backups.php',
			'BackupPlus_Encryption'                => 'includes/class-backupplus-encryption.php',
			'BackupPlus_Manipulation_Functions'    => 'includes/class-manipulation-functions.php',
			'BackupPlus_Filesystem_Functions'      => 'includes/class-filesystem-functions.php',
			'BackupPlus_Storage_Provider_Factory'  => 'includes/class-storage-provider-factory.php',
			'BackupPlus_Storage_Methods_Interface' => 'includes/class-storage-methods-interface.php',
			'BackupPlus_Job_Scheduler'             => 'includes/class-job-scheduler.php',
			'BackupPlus_HTTP_Error_Descriptions'   => 'includes/class-http-error-descriptions.php',
			'BackupPlus_Database_Utility'          => 'includes/class-database-utility.php',
			'BackupPlus_Restore_Search_Replace'    => 'includes/class-restore-search-replace.php',
			'BackupPlus_Deactivation'              => 'includes/class-backupplus-deactivation.php',
		);

		foreach ( $load_classes as $class => $relative_path ) {
			if ( ! class_exists( $class ) ) {
				backupplus_try_include_file( '' . $relative_path, 'include_once' );
			}
		}

		$this->backup_methods = BackupPlus_Storage_Provider_Factory::get_methods();

		new BackupPlus_Restore_Search_Replace();

		if ( class_exists( 'BackupPlus_Deactivation' ) ) {
			BackupPlus_Deactivation::get_instance();
		}

		// Create admin page
		add_action( 'init', array( $this, 'initialize_required_settings' ) );
		// Run earlier than default - hence earlier than other components
		// admin_menu runs earlier, and we need it because options.php wants to use $backupplus_admin before admin_init happens
		add_action( apply_filters( 'backupplus_admin_menu_hook', 'admin_menu' ), array( $this, 'admin_menu' ), 9 );
		add_action( 'network_admin_menu', array( $this, 'admin_menu' ), 9 );
		// Not a mistake: admin-ajax.php calls only admin_init and not admin_menu
		add_action( 'admin_init', array( $this, 'admin_menu' ), 9 );
		add_action( 'admin_init', array( $this, 'wordpress_55_updates_potential_migration' ) );

		// The two actions which we schedule upon
		add_action( 'backupplus_backup', array( $this, 'backup_files' ) );
		add_action( 'backupplus_backup_database', array( $this, 'backup_database' ) );
		add_action( 'backupplus_backup_increments', array( $this, 'backup_increments' ) );

		// The three actions that can be called from "Backup Now"
		add_action( 'backupplus_backupnow_backup', array( $this, 'backupnow_files' ) );
		add_action( 'backupplus_backupnow_backup_database', array( $this, 'backupnow_database' ) );
		add_action( 'backupplus_backupnow_backup_all', array( $this, 'backup_all' ) );
		add_action( 'backupplus_backupnow_start', array( $this, 'dispatch_backupnow' ), 10, 2 );

		// backup_all as an action is legacy (Oct 2013) - there may be some people who wrote cron scripts to use it
		add_action( 'backupplus_backup_all', array( $this, 'backup_all' ) );

		// This is our runs-after-backup event, whose purpose is to see if it succeeded or failed, and resume/mom-up etc.
		add_action( 'backupplus_backup_resume', array( $this, 'backup_resume' ), 10, 3 );

		// If files + db are on different schedules but are scheduled for the same time, then combine them
		add_filter( 'schedule_event', array( $this, 'schedule_event' ) );

		add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ) );

		// Since the WordPress version 5.5, we are no longer forcing an auto update by hooking the auto_update_plugin filter because WordPress does something different to its auto-update interface
		if ( version_compare( $this->get_wordpress_version(), '5.5', '<' ) ) {
			// Auto update plugin
			add_filter( 'auto_update_plugin', array( $this, 'maybe_auto_update_plugin' ), 20, 2 );
		}

		// Prevent iThemes Security from telling people that they have no backups (and advertising them another product on that basis!)
		add_filter( 'itsec_has_external_backup', '__return_true', 999 );
		add_filter( 'itsec_external_backup_link', array( $this, 'itsec_external_backup_link' ), 999 );
		add_filter( 'itsec_scheduled_external_backup', array( $this, 'itsec_scheduled_external_backup' ), 999 );

		add_action( 'backupplus_report_remotestorage_extrainfo', array( $this, 'report_remotestorage_extrainfo' ), 10, 3 );

		// Prevent people using WP < 5.5 upgrading from being baffled by WP's obscure error message. See: https://core.trac.wordpress.org/ticket/27196

		if ( version_compare( $this->get_wordpress_version(), '5.4.99999999', '<' ) ) {
			add_filter( 'upgrader_source_selection', array( $this, 'upgrader_source_selection' ), 10, 4 );
		}

		// register_deactivation_hook(__FILE__, array($this, 'deactivation'));
		// Remove the notice on the Updates page that confuses users who already have backups installed
		if ( 'update-core.php' == $pagenow ) {
			// added filter here instead of admin.php because the  jetpack_just_in_time_msgs filter applied in init hook
			add_filter( 'jetpack_just_in_time_msgs', '__return_false', 20 );
		}

		// Cron to clean temporary files even in the absence of a new backup job beginning
		add_action( 'backupplus_clean_temporary_files', 'BackupPlus_Filesystem_Functions::clean_temporary_files', 10 );

		if ( ! BackupPlus_Multisite::secondary() && ! wp_next_scheduled( 'backupplus_clean_temporary_files' ) ) {
			wp_schedule_event( time(), 'twicedaily', 'backupplus_clean_temporary_files' );
		}
	}
}
