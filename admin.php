<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- we try to reduce overhead by bypassing WP APIs and other extra layers; Some custom complex queries tailored specifically to our needs, giving us full control over the SQL commands and data manipulation
// phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename -- rename() usage is intentional and safe within this context
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fgets, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.WP.AlternativeFunctions.file_system_operations_fputs, WordPress.WP.AlternativeFunctions.file_system_operations_is_writable, WordPress.WP.AlternativeFunctions.file_system_operations_chown, WordPress.WP.AlternativeFunctions.file_system_operations_chgrp, WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Native PHP fileystem function is used for direct control and performance because it can bypass additional layers of abstraction so that no overhead from the WordPress filesystem API's internal handling
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_print_r -- print_r is intentionally used to convert an array into a readable string for controlled logging/debug purposes
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt_array, WordPress.WP.AlternativeFunctions.curl_curl_setopt, WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.curl_curl_multi_init, WordPress.WP.AlternativeFunctions.curl_curl_multi_add_handle, WordPress.WP.AlternativeFunctions.curl_curl_multi_exec, WordPress.WP.AlternativeFunctions.curl_curl_multi_select, WordPress.WP.AlternativeFunctions.curl_curl_multi_getcontent, WordPress.WP.AlternativeFunctions.curl_curl_multi_remove_handle, WordPress.WP.AlternativeFunctions.curl_curl_multi_close, WordPress.WP.AlternativeFunctions.curl_curl_error, WordPress.WP.AlternativeFunctions.curl_curl_close -- Direct cURL usage is intentional to leverage specific low-level options not available via the WordPress HTTP API.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- some query operations need to always receive the most up-to-date or actual data directly from the database, reducing the risk of serving stale information.
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- we use the set_error_handler() function to provide a flexible way of handling PHP errors according to our needs; we centralises error handling in one place and customises certain errors based on their severity and context.
// phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged -- some functions, like set_time_limit() and ini_set(), are used to temporarily change PHP configuration values based on the script's needs (e.g., processing large datasets or performing long operations).
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

if ( ! class_exists( 'BackupPlus_Settings_Importer' ) ) {
	backupplus_try_include_file( 'includes/class-settings-importer.php', 'require_once' );
}

// Admin-area code lives here. This gets called in admin_menu, earlier than admin_init
// The class is composed from the traits in includes/admin/, one per area of the admin UI.

require_once __DIR__ . '/includes/admin/trait-templates.php';
require_once __DIR__ . '/includes/admin/trait-notices.php';
require_once __DIR__ . '/includes/admin/trait-schedule-display.php';
require_once __DIR__ . '/includes/admin/trait-assets.php';
require_once __DIR__ . '/includes/admin/trait-wordpress-integration.php';
require_once __DIR__ . '/includes/admin/trait-ajax.php';
require_once __DIR__ . '/includes/admin/trait-downloads.php';
require_once __DIR__ . '/includes/admin/trait-uploads.php';
require_once __DIR__ . '/includes/admin/trait-backup-sets.php';
require_once __DIR__ . '/includes/admin/trait-active-jobs.php';
require_once __DIR__ . '/includes/admin/trait-settings-page.php';
require_once __DIR__ . '/includes/admin/trait-settings-fields.php';
require_once __DIR__ . '/includes/admin/trait-settings-storage.php';
require_once __DIR__ . '/includes/admin/trait-existing-backups.php';
require_once __DIR__ . '/includes/admin/trait-restore.php';
require_once __DIR__ . '/includes/admin/trait-old-directories.php';
require_once __DIR__ . '/includes/admin/trait-http.php';

class BackupPlus_Admin {
	use BackupPlus_Admin_Templates;
	use BackupPlus_Admin_Notices;
	use BackupPlus_Admin_Schedule_Display;
	use BackupPlus_Admin_Assets;
	use BackupPlus_Admin_WordPress_Integration;
	use BackupPlus_Admin_Ajax;
	use BackupPlus_Admin_Downloads;
	use BackupPlus_Admin_Uploads;
	use BackupPlus_Admin_Backup_Sets;
	use BackupPlus_Admin_Active_Jobs;
	use BackupPlus_Admin_Settings_Page;
	use BackupPlus_Admin_Settings_Fields;
	use BackupPlus_Admin_Settings_Storage;
	use BackupPlus_Admin_Existing_Backups;
	use BackupPlus_Admin_Restore;
	use BackupPlus_Admin_Old_Directories;
	use BackupPlus_Admin_Http;

	public $logged = array();

	private $template_directories;

	private $backups_instance_ids;

	private $auth_instance_ids      = array();
	private $auth_notice_callbacks  = array();
	private $provider_admin_notices = array();


	private $storage_service_without_settings;

	private $storage_service_with_partial_settings;

	private $storage_service_without_addons_settings;

	private $storage_module_option_errors = '';

	private $no_settings_warning = false;

	private $restore_in_progress_jobdata = array();

	private $entities_to_restore = array();

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->admin_init();
	}

	/**
	 * Run upon the WP admin_init action
	 */
	private function admin_init() {
		add_action( 'admin_init', array( $this, 'maybe_download_backup_from_email' ) );

		add_action( 'admin_head', array( $this, 'admin_head' ) );
		add_filter( ( is_multisite() ? 'network_admin_' : '' ) . 'plugin_action_links', array( $this, 'plugin_action_links' ), 10, 2 );
		add_filter( 'plugin_row_meta', array( $this, 'change_plugin_author_link' ), 10, 3 );
		add_action( 'wp_ajax_backupplus_download_backup', array( $this, 'backupplus_download_backup' ) );
		add_action( 'wp_ajax_backupplus_ajax', array( $this, 'backupplus_ajax_handler' ) );
		add_action( 'wp_ajax_backupplus_ajaxrestore', array( $this, 'backupplus_ajaxrestore' ) );
		add_action( 'wp_ajax_nopriv_backupplus_ajaxrestore', array( $this, 'backupplus_ajaxrestore' ) );
		add_action( 'wp_ajax_backupplus_ajaxrestore_continue', array( $this, 'backupplus_ajaxrestore' ) );
		add_action( 'wp_ajax_nopriv_backupplus_ajaxrestore_continue', array( $this, 'backupplus_ajaxrestore' ) );

		add_action( 'wp_ajax_plupload_action', array( $this, 'plupload_action' ) );
		add_action( 'wp_ajax_plupload_action2', array( $this, 'plupload_action2' ) );

		add_action( 'wp_before_admin_bar_render', array( $this, 'wp_before_admin_bar_render' ) );

		// Add a new Ajax action for saving settings
		add_action( 'wp_ajax_backupplus_savesettings', array( $this, 'backupplus_ajax_savesettings' ) );

		// Ajax for settings import and export
		add_action( 'wp_ajax_backupplus_importsettings', array( $this, 'backupplus_ajax_importsettings' ) );

		add_filter( 'heartbeat_received', array( $this, 'process_status_in_heartbeat' ), 10, 2 );

		// BackupPlus templates
		$this->register_template_directories();

		global $backupplus, $pagenow;
		add_filter( 'backupplus_dirlist_others', array( $backupplus, 'backup_others_dirlist' ) );
		add_filter( 'backupplus_dirlist_uploads', array( $backupplus, 'backup_uploads_dirlist' ) );

		// First, the checks that are on all (admin) pages:

		$service = BackupPlus_Options::get_backupplus_option( 'backupplus_service' );

		if ( BackupPlus_Options::user_can_manage() ) {

			$this->print_restore_in_progress_box_if_needed();

			// Moved out for use with Ajax saving
			$this->setup_all_admin_notices_global( $service );
		}

		// New-install admin tour
		if ( ! defined( 'BACKUPPLUS_ENABLE_TOUR' ) || BACKUPPLUS_ENABLE_TOUR ) {
			backupplus_try_include_file( 'includes/backupplus-tour.php', 'include_once' );
		}

		$udp_saved_version = BackupPlus_Options::get_backupplus_option( 'backupplus_version' );
		if ( ! $udp_saved_version || $udp_saved_version != $backupplus->version ) {
			if ( ! $udp_saved_version ) {
				// udp was newly installed, or upgraded from an older version
				do_action( 'backupplus_newly_installed', $backupplus->version );
			} else {
				// udp was updated or downgraded
				do_action( 'backupplus_version_changed', $udp_saved_version, $backupplus->version );
			}
			BackupPlus_Options::update_backupplus_option( 'backupplus_version', $backupplus->version );
		}

		if ( BackupPlus_Options::admin_page() != $pagenow || empty( $_REQUEST['page'] ) || 'backupplus' != $_REQUEST['page'] ) {
			if ( BackupPlus_Options::user_can_manage() ) {
				add_action( 'admin_print_footer_scripts', array( $this, 'print_phpseclib_notice_scripts' ) );
			}
			return;
		}

		// Next, the actions that only come on the BackupPlus page
		$this->setup_all_admin_notices_udonly( $service );

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ), 99999 );
		$post_nonce = BackupPlus_Manipulation_Functions::fetch_superglobal( 'post', 'nonce' );
		if ( isset( $_POST['action'] ) && 'backupplus_wipesettings' === $_POST['action'] && isset( $post_nonce ) && BackupPlus_Options::user_can_manage() ) {
			if ( wp_verify_nonce( $post_nonce, 'backupplus-wipe-setting-nonce' ) ) {
				$this->wipe_settings();
			}
		}
	}
}

global $backupplus_admin;
if ( ! is_a( $backupplus_admin, 'BackupPlus_Admin' ) ) {
	$backupplus_admin = new BackupPlus_Admin();
}
$GLOBALS['backupplus_admin'] = $backupplus_admin;
