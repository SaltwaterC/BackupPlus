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

class BackupPlus {

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

		if ( ! wp_next_scheduled( 'backupplus_clean_temporary_files' ) ) {
			wp_schedule_event( time(), 'twicedaily', 'backupplus_clean_temporary_files' );
		}
	}

	/**
	 * Enables automatic updates for the plugin.
	 *
	 * @access public
	 * @see __construct
	 * @internal uses auto_update_plugin filter
	 *
	 * @param Bool   $update Whether the item has automatic updates enabled
	 * @param Object $item   Object holding the asset to be updated
	 * @return bool True of automatic updates enabled, false if not
	 */
	public function maybe_auto_update_plugin( $update, $item ) {
		if ( ! isset( $item->plugin ) || BACKUPPLUS_PLUGIN_SLUG !== $item->plugin ) {
			return $update;
		}
		$option_auto_update_settings = (array) get_site_option( 'auto_update_plugins', array() );
		return in_array( $item->plugin, $option_auto_update_settings, true );
	}

	/**
	 * Called by the WP action backupplus_report_remotestorage_extrainfo
	 *
	 * @param String $service
	 * @param String $info_html  - the HTML version of the extra info
	 * @param String $info_plain - the plain text version of the extra info
	 */
	public function report_remotestorage_extrainfo( $service, $info_html, $info_plain ) {
		$this->remotestorage_extrainfo[ $service ] = array(
			'pretty' => $info_html,
			'plain'  => $info_plain,
		);
	}

	/**
	 * WP filter upgrader_source_selection. We use it to tweak the error message shown when an install of a new version is prevented by the existence of an existing version (i.e. us!), to give the user some actual useful information instead of WP's default.
	 *
	 * @param String      $source          File source location.
	 * @param String      $remote_source   Remote file source location.
	 * @param WP_Upgrader $upgrader_object WP_Upgrader instance.
	 * @param Array       $hook_extra      Extra arguments passed to hooked filters.
	 *
	 * @return String - filtered value
	 */
	public function upgrader_source_selection( $source, $remote_source, $upgrader_object, $hook_extra = array() ) {// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Filter use

		static $been_here_already = false;

		if ( $been_here_already || ! is_array( $hook_extra ) || empty( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] || empty( $hook_extra['action'] ) || 'install' !== $hook_extra['action'] || empty( $source ) || 'backupplus' !== basename( untrailingslashit( $source ) ) || ! class_exists( 'ReflectionObject' ) ) {
			return $source;
		}

		$been_here_already = true;

		$reflect = new ReflectionObject( $upgrader_object );

		$properties = $reflect->getProperty( 'strings' );

		if ( ! $properties->isPublic() || ! is_array( $upgrader_object->strings ) || empty( $upgrader_object->strings['folder_exists'] ) ) {
			return $source;
		}

		$upgrader_object->strings['folder_exists'] .= ' ' . __( 'A version of BackupPlus is already installed.', 'backupplus' ) . ' ' . __( 'WordPress will only allow you to install your new version after first de-installing the existing one.', 'backupplus' ) . ' ' . __( 'That is safe - all your settings and backups will be retained.', 'backupplus' ) . ' ' . __( 'So, go to the "Plugins" page, de-activate and de-install BackupPlus, and then try again.', 'backupplus' );

		return $source;
	}

	/**
	 * WordPress filter itsec_scheduled_external_backup - from iThemes Security
	 *
	 * @return Boolean - filtered value
	 */
	public function itsec_scheduled_external_backup() {
		return wp_next_scheduled( 'backupplus_backup' ) ? true : false;
	}

	/**
	 * WordPress filter itsec_external_backup_link - from iThemes security
	 *
	 * @return String - filtered value
	 */
	public function itsec_external_backup_link() {
			return BackupPlus_Options::admin_page_url() . '?page=backupplus';
	}


	/** Load the single bundled phpseclib 3 installation. */
	public function ensure_phpseclib() {
		if ( version_compare( PHP_VERSION, BACKUPPLUS_PHPSECLIB_MIN_PHP_VERSION, '<' ) ) {
			return new WP_Error( 'phpseclib_php_version', sprintf( __( 'The bundled cryptography library requires PHP %s or newer.', 'backupplus' ), BACKUPPLUS_PHPSECLIB_MIN_PHP_VERSION ) );
		}
		backupplus_try_include_file( 'vendor/autoload.php', 'require_once' );
		backupplus_try_include_file( 'includes/class-backupplus-crypto.php', 'require_once' );
		return true;
	}

	/**
	 * Attempt to close the connection to the browser, optionally with some output sent first, whilst continuing execution
	 *
	 * @param String $txt - JSON-encoded output to send
	 */
	public function close_browser_connection( $txt = '' ) {
		// Close browser connection so that it can resume AJAX polling
		header( 'Content-Length: ' . ( empty( $txt ) ? '0' : 4 + strlen( $txt ) ) );
		header( 'Connection: close' );
		header( 'Content-Type: application/json' ); // Used to be 'none', but all inputs to this method are JSON-encoded
		if ( function_exists( 'session_id' ) && session_id() ) {
			session_write_close();
		}
		echo "\r\n\r\n";
		echo $txt; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- All inputs to this method are already JSON-encoded, and the output context is not HTML
		// These two added - 19-Feb-15 - started being required on local dev machine, for unknown reason (probably some plugin that started an output buffer).
		$ob_level = ob_get_level();
		while ( $ob_level > 0 ) {
			ob_end_flush();
			--$ob_level;
		}
		flush();
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		if ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
	}

	/**
	 * Returns the number of bytes free, if it can be detected; otherwise, false
	 * Presently, we only detect CPanel. If you know of others, then feel free to contribute!
	 */
	public function get_hosting_disk_quota_free() {
		if ( ( defined( 'BACKUPPLUS_SKIP_CPANEL_QUOTA_CHECK' ) && BACKUPPLUS_SKIP_CPANEL_QUOTA_CHECK ) || $this->detect_safe_mode() || ! @is_dir( '/usr/local/cpanel' ) || ! function_exists( 'popen' ) || ( ! @is_executable( '/usr/local/bin/perl' ) && ! @is_executable( '/usr/local/cpanel/3rdparty/bin/perl' ) ) ) {
			return false;// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}
		$perl = ( @is_executable( '/usr/local/cpanel/3rdparty/bin/perl' ) ) ? '/usr/local/cpanel/3rdparty/bin/perl' : '/usr/local/bin/perl';// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.

		$exec = "BACKUPPLUSKEY=backupplus $perl " . BACKUPPLUS_DIR . '/includes/get-cpanel-quota-usage.pl';

		// A case was seen of a web hosting company that disabled pclose() but not popen()
		$handle = ( function_exists( 'popen' ) && function_exists( 'pclose' ) ) ? @popen( $exec, 'r' ) : false; // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		if ( ! is_resource( $handle ) ) {
			return false;
		}

		$found = false;
		$lines = 0;
		while ( false === $found && ! feof( $handle ) && $lines < 100 ) {
			++$lines;
			$w = fgets( $handle );
			// Used, limit, remain
			if ( preg_match( '/RESULT: (\d+) (\d+) (\d+) /', $w, $matches ) ) {
				$found = true;
			}
		}
		$ret = pclose( $handle );
		// The manual page for pclose() claims that only -1 indicates an error, but this is untrue
		if ( false === $found || 0 != $ret ) {
			return false;
		}

		if ( (int) $matches[2] < 100 || ( $matches[1] + $matches[3] != $matches[2] ) ) {
			return false;
		}

		$this->cpanel_quota_readable = true;

		return $matches;
	}

	/**
	 * Fetch information about the most recently modified log file
	 *
	 * @return Array - lists the modification time, the full path to the log file, and the log's nonce (ID)
	 */
	public function last_modified_log() {
		$backupplus_dir = $this->backups_dir_location();

		$log_file = '';
		$mod_time = false;
		$nonce    = '';

		if ( $handle = @opendir( $backupplus_dir ) ) {// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			while ( false !== ( $entry = readdir( $handle ) ) ) {
				// The latter match is for files created internally by zipArchive::addFile
				if ( preg_match( '/^log\.([a-z0-9]+)\.txt$/i', $entry, $matches ) ) {
					$mtime = filemtime( $backupplus_dir . '/' . $entry );
					if ( $mtime > $mod_time ) {
						$mod_time = $mtime;
						$log_file = $backupplus_dir . '/' . $entry;
						$nonce    = $matches[1];
					}
				}
			}
			@closedir( $handle );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}

		return array( $mod_time, $log_file, $nonce );
	}

	/**
	 * This function may get called multiple times, so write accordingly
	 */
	public function admin_menu() {
		// We are in the admin area: now load all that code
		global $backupplus_admin;
		if ( empty( $backupplus_admin ) ) {
			backupplus_try_include_file( 'admin.php', 'include_once' );
		}

		list($page, $wpnonce, $action) = array_values(
			BackupPlus_Manipulation_Functions::fetch_superglobal_array(
				array( 'get', 'page' ),
				array( 'get', 'wpnonce' ),
				array( 'get', 'action' )
			)
		);
		if ( $wpnonce && $action && 'backupplus' == $page && 'downloadlatestmodlog' == $action && wp_verify_nonce( $wpnonce, 'backupplus_download' ) ) {

			list($mod_time, $log_file, $nonce) = $this->last_modified_log();// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Unused parameter is present because the method returns an array.

			if ( $mod_time > 0 ) {
				if ( is_readable( $log_file ) ) {
					header( 'Content-type: text/plain' );
					readfile( $log_file );
					exit;
				} else {
					add_action( 'all_admin_notices', array( $this, 'show_admin_warning_unreadablelog' ) );
				}
			} else {
				add_action( 'all_admin_notices', array( $this, 'show_admin_warning_nolog' ) );
			}
		}
	}

	/**
	 * WP action http_api_curl
	 *
	 * @param Resource $handle A curl handle returned by curl_init()
	 *
	 * @return the handle (having potentially had some options set upon it)
	 */
	public function http_api_curl( $handle ) {
		if ( defined( 'BACKUPPLUS_IPV4_ONLY' ) && BACKUPPLUS_IPV4_ONLY ) {
			curl_setopt( $handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
		}
		return $handle;
	}

	/**
	 * Used as a central location (to avoid repetition) to register or de-register hooks into the WP HTTP API
	 *
	 * @param Boolean $register - true to register, false to de-register
	 */
	public function register_wp_http_option_hooks( $register = true ) {
		if ( $register ) {
			add_filter( 'http_request_args', array( $this, 'modify_http_options' ) );
			add_action( 'http_api_curl', array( $this, 'http_api_curl' ) );
		} else {
			remove_filter( 'http_request_args', array( $this, 'modify_http_options' ) );
			remove_action( 'http_api_curl', array( $this, 'http_api_curl' ) );
		}
	}

	/**
	 * Used as a WordPress options filter (http_request_args)
	 *
	 * @param Array $opts - existing options
	 *
	 * @return Array - modified options
	 */
	public function modify_http_options( $opts ) {

		if ( ! is_array( $opts ) ) {
			return $opts;
		}

		if ( ! BackupPlus_Options::get_backupplus_option( 'backupplus_ssl_useservercerts' ) ) {
			$opts['sslcertificates'] = BACKUPPLUS_DIR . '/includes/cacert.pem';
		}

		$opts['sslverify'] = BackupPlus_Options::get_backupplus_option( 'backupplus_ssl_disableverify' ) ? false : true;

		return $opts;
	}

	/**
	 * Initialize all settings required for the plugin to work properly
	 */
	public function initialize_required_settings() {
		// Tell WordPress where to find the translations
		$mofile = BACKUPPLUS_DIR . '/languages/backupplus-' . get_locale() . '.mo';
		if ( is_readable( $mofile ) ) {
			load_textdomain( 'backupplus', $mofile );
		} else {
			load_plugin_textdomain( 'backupplus', false, basename( __DIR__ ) . '/languages/' );
		}
		$this->handle_url_actions();
		$this->backupplus_single_site_maintenance_init();
	}

	/**
	 * Handle actions passed on to method plugins; e.g. Google OAuth 2.0 - ?action=backupplusmethod-googledrive-auth&page=backupplus
	 * Nov 2013: Google's new cloud console, for reasons as yet unknown, only allows you to enter a redirect_uri with a single URL parameter... thus, we put page second, and re-add it if necessary. Apr 2014: Bitcasa already do this, so perhaps it is part of the OAuth2 standard or best practice somewhere.
	 * Also handle action=downloadlog
	 *
	 * @return Void - may not necessarily return at all, depending on the action
	 */
	private function handle_url_actions() {

		// First, basic security check: must be an admin page, with ability to manage options, with the right parameters
		// Also, only on GET because WordPress on the options page repeats parameters sometimes when POST-ing via the _wp_referer field
		list($request_method, $page, $action, $backupplus_backup_nonce, $backupplus_file, $what, $backup_timestamp, $findex) = array_values(
			BackupPlus_Manipulation_Functions::fetch_superglobal_array(
				array( 'server', 'REQUEST_METHOD' ),
				array( 'get', 'page' ),
				array( 'get', 'action' ),
				array( 'get', 'backupplus_backup_nonce' ),
				array( 'get', 'backupplus_file' ),
				array( 'get', 'what' ),
				array( 'get', 'backup_timestamp' ),
				array( 'get', 'findex', 0 )
			)
		);

		if ( $request_method && $action && ( 'GET' == $request_method || 'POST' == $request_method ) ) {
			if ( preg_match( '/^backupplusmethod-([a-z0-9]+)-([a-z]+)$/', $action, $matches ) && BackupPlus_Storage_Provider_Factory::has( $matches[1] ) && BackupPlus_Options::user_can_manage() ) {
				$_GET['page']            = 'backupplus';
				$_REQUEST['page']        = 'backupplus';
				$method                  = $matches[1];
				$call_method             = 'action_' . $matches[2];
				$storage_objects_and_ids = BackupPlus_Storage_Methods_Interface::get_storage_objects_and_ids( array( $method ) );

				$instance_id = BackupPlus_Manipulation_Functions::fetch_superglobal( 'get', 'backupplus_instance', '' );
				$state_post  = BackupPlus_Manipulation_Functions::fetch_superglobal( 'post', 'state' );
				$state_get   = BackupPlus_Manipulation_Functions::fetch_superglobal( 'get', 'state' );

				if ( 'POST' == $request_method && $state_post ) {
					$state = urldecode( $state_post );
				} elseif ( $state_get ) {
					$state = $state_get;
				}

				// If we don't have an instance_id but the state is set then we are coming back to finish the auth and should extract the instance_id from the state
				if ( '' == $instance_id && isset( $state ) && false !== strpos( $state, ':' ) ) {
					$parts       = explode( ':', $state );
					$instance_id = $parts[1];
				}

				if ( ! preg_match( '/^[-A-Z0-9]+$/i', $instance_id ) ) {
					die( 'Invalid input.' );
				}
				if ( empty( $storage_objects_and_ids[ $method ]['instance_settings'][ $instance_id ] ) ) {
					BackupPlus_Manipulation_Functions::error_log( 'BackupPlus::handle_url_actions(): no such instance ID found in settings.' );
					return;
				}
				$opts = $storage_objects_and_ids[ $method ]['instance_settings'][ $instance_id ];
				if ( ! isset( $storage_objects_and_ids[ $method ]['object'] ) || ! is_object( $storage_objects_and_ids[ $method ]['object'] ) ) {
					$backup_obj = BackupPlus_Storage_Provider_Factory::create( $method );
					if ( is_wp_error( $backup_obj ) ) {
						return;
					}
				} else {
					$backup_obj = $storage_objects_and_ids[ $method ]['object'];
				}
				$backup_obj->set_options( $opts, false, $instance_id );

				$this->register_wp_http_option_hooks();

				try {
					if ( method_exists( $backup_obj, $call_method ) ) {
						call_user_func( array( $backup_obj, $call_method ) );
					}
				} catch ( Exception $e ) {
					/* translators: 1: Method name, 2: Error message */
					$this->log( sprintf( __( '%1$s error: %2$s', 'backupplus' ), $method, $e->getMessage() . ' (' . $e->getCode() . ')', 'error' ) );
				}
				$this->register_wp_http_option_hooks( false );
			} elseif ( 'backupplus' === $page && 'downloadlog' === $action && $backupplus_backup_nonce && preg_match( '/^[0-9a-f]{12}$/', $backupplus_backup_nonce ) && BackupPlus_Options::user_can_manage() ) {
				// No WordPress nonce is needed here or for the next, since the backup is already nonce-based
				$backupplus_dir = $this->backups_dir_location();
				$log_file       = $backupplus_dir . '/log.' . $backupplus_backup_nonce . '.txt';
				if ( is_readable( $log_file ) ) {
					header( 'Content-type: text/plain' );
					$force_download = BackupPlus_Manipulation_Functions::fetch_superglobal( 'get', 'force_download' );
					if ( $force_download ) {
						header( 'Content-Disposition: attachment; filename="' . basename( $log_file ) . '"' );
					}
					readfile( $log_file );
					exit;
				} else {
					add_action( 'all_admin_notices', array( $this, 'show_admin_warning_unreadablelog' ) );
				}
			} elseif ( 'backupplus' === $page && 'downloadfile' == $action && $backupplus_file && preg_match( '/^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-db([0-9]+)?+\.(gz\.crypt)$/i', $backupplus_file ) && BackupPlus_Options::user_can_manage() ) {
				wp_die( 'Use the database decryption form.', '', array( 'response' => 405 ) );
			} elseif ( 'backupplus_spool_file' === $action && $what && $backup_timestamp && is_numeric( $backup_timestamp ) && BackupPlus_Options::user_can_manage() ) {
				// At some point, it may be worth merging this with the previous section
				$backupplus_dir = $this->backups_dir_location();

				$backup_set = BackupPlus_Backup_History::get_history( $backup_timestamp );

				$filename = null;
				if ( ! empty( $backup_set ) ) {
					if ( 'db' != substr( $what, 0, 2 ) ) {
						$backupable_entities = $this->get_backupable_file_entities();
						if ( ! isset( $backupable_entities[ $what ] ) ) {
							$filename = false;
						}
					}
					if ( false !== $filename && isset( $backup_set[ $what ] ) ) {
						if ( is_string( $backup_set[ $what ] ) && 0 == $findex ) {
							$filename = $backup_set[ $what ];
						} elseif ( isset( $backup_set[ $what ][ $findex ] ) ) {
							$filename = $backup_set[ $what ][ $findex ];
						}
					}
				}
				if ( empty( $filename ) || ! is_readable( $backupplus_dir . '/' . basename( $filename ) ) ) {
					echo json_encode( array( 'result' => __( 'BackupPlus notice:', 'backupplus' ) . ' ' . __( 'The given file was not found, or could not be read.', 'backupplus' ) ) );
					exit;
				}

				$this->spool_file( $backupplus_dir . '/' . basename( $filename ) );
				exit;

			}
		}
	}

	/**
	 * This function will check if this is a multisite and if our maintenance mode file is present if so return a service unavailable
	 *
	 * @return void
	 */
	private function backupplus_single_site_maintenance_init() {

		if ( ! is_multisite() ) {
			return;
		}

		$wp_upload_dir = wp_upload_dir();
		$subsite_dir   = $wp_upload_dir['basedir'] . '/';

		if ( ! file_exists( $subsite_dir . '.maintenance' ) ) {
			return;
		}

		$timestamp = file_get_contents( $subsite_dir . '.maintenance' );
		$time      = time();

		if ( $time - $timestamp > 3600 ) {
			unlink( $subsite_dir . '.maintenance' );
			return;
		}

		wp_die( '<h1>' . esc_html__( 'Under Maintenance', 'backupplus' ) . '</h1><p>' . esc_html( __( 'Briefly unavailable for scheduled maintenance.', 'backupplus' ) . ' ' . __( 'Check back in a minute.', 'backupplus' ) ) . '</p>' );
	}

	/**
	 * Get the installation's base table prefix, optionally allowing the result to be filtered
	 *
	 * @param Boolean $allow_override - allow the result to be filtered
	 *
	 * @return String
	 */
	public function get_table_prefix( $allow_override = false ) {
		global $wpdb;
		if ( is_multisite() && ! defined( 'MULTISITE' ) ) {
			// In this case (which should only be possible on installs upgraded from pre WP 3.0 WPMU), $wpdb->get_blog_prefix() cannot be made to return the right thing. $wpdb->base_prefix is not explicitly marked as public, so we prefer to use get_blog_prefix if we can, for future compatibility.
			$prefix = $wpdb->base_prefix;
		} else {
			$prefix = $wpdb->get_blog_prefix( 0 );
		}
		return $allow_override ? apply_filters( 'backupplus_get_table_prefix', $prefix ) : $prefix;
	}


	public function show_admin_warning_unreadablelog() {
		global $backupplus_admin;
		$backupplus_admin->show_admin_warning( '<strong>' . __( 'BackupPlus notice:', 'backupplus' ) . '</strong> ' . __( 'The log file could not be read.', 'backupplus' ) );
	}

	public function show_admin_warning_nolog() {
		global $backupplus_admin;
		$backupplus_admin->show_admin_warning( '<strong>' . __( 'BackupPlus notice:', 'backupplus' ) . '</strong> ' . __( 'No log files were found.', 'backupplus' ) );
	}

	public function show_admin_warning_unreadablefile() {
		global $backupplus_admin;
		$backupplus_admin->show_admin_warning( '<strong>' . __( 'BackupPlus notice:', 'backupplus' ) . '</strong> ' . __( 'The given file was not found, or could not be read.', 'backupplus' ) );
	}

	/**
	 * Runs upon the WP action plugins_loaded
	 */
	public function plugins_loaded() {

		// The Google Analyticator plugin does something horrible: loads an old version of the Google SDK on init, always - which breaks us
		list($subaction, $page) = array_values(
			BackupPlus_Manipulation_Functions::fetch_superglobal_array(
				array( 'request', 'subaction' ),
				array( 'get', 'page' )
			)
		);
		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX && 'backupnow' == $subaction || 'backupplus' == $page ) ) {
			remove_action( 'init', 'ganalyticator_stats_init' );
			// Appointments+ does the same; but provides a cleaner way to disable it
			@define( 'APP_GCAL_DISABLE', true );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}
	}


	/**
	 * Get the character set for the current database connection
	 *
	 * @uses WPDB::determine_charset() - exists on WP 4.6+
	 *
	 * @param Object|Null $wpdb - WPDB object; if none passed, then use the global one
	 *
	 * @return String
	 */
	public function get_connection_charset( $wpdb = null ) {
		if ( null === $wpdb ) {
			global $wpdb;
		}

		$charset = ( defined( 'DB_CHARSET' ) && DB_CHARSET ) ? DB_CHARSET : 'utf8mb4';

		if ( method_exists( $wpdb, 'determine_charset' ) ) {
			$charset_collate = $wpdb->determine_charset( $charset, '' );
			if ( ! empty( $charset_collate['charset'] ) ) {
				$charset = $charset_collate['charset'];
			}
		}

		return $charset;
	}


	/**
	 * This function allows you to manually set the nonce and timestamp for the current backup job. If none are provided then it will create new ones.
	 *
	 * @param Boolean|string $nonce     - the nonce you want to set
	 * @param Boolean|string $timestamp - the timestamp you want to set
	 *
	 * @return string                   - returns the backup nonce that has been set
	 */
	public function backup_time_nonce( $nonce = false, $timestamp = false ) {
		$this->job_time_ms = microtime( true );
		if ( false === $timestamp ) {
			$timestamp = time();
		}
		if ( false === $nonce ) {
			$nonce = substr( md5( time() . wp_rand() ), 20 );
		}
		$this->backup_time = $timestamp;
		$this->file_nonce  = apply_filters( 'backupplus_incremental_backup_file_nonce', $nonce );
		$this->nonce       = $nonce;
		return $nonce;
	}

	/**
	 * Get the WordPress version
	 *
	 * @return String - the version
	 */
	public function get_wordpress_version() {
		static $got_wp_version = false;
		if ( ! $got_wp_version ) {
			// This doesn't need to be global, but is kept just in case version.php changes its way of doing things in a future WP core release
			global $wp_version;
			include ABSPATH . WPINC . '/version.php';
			$got_wp_version = $wp_version;
		}
		return $got_wp_version;
	}

	/**
	 * Get the BackupPlus version and convert it to the correct format to be used in filenames
	 *
	 * @return String - the file version number
	 */
	public function get_backupplus_file_version() {

		if ( $this->use_unminified_scripts() ) {
			return '';
		}

		$version_parts = explode( '.', $this->version );
		$version_parts = array_slice( $version_parts, 0, 3 );
		$version       = implode( '.', $version_parts );

		return '-' . str_replace( '.', '-', $version ) . '.min';
	}

	/**
	 * Opens the log file, writes a standardised header, and stores the resulting name and handle in the class variables logfile_name/logfile_handle/opened_log_time (and possibly backup_is_already_complete)
	 *
	 * @param String $nonce - Used in the log file name to distinguish it from other log files. Should be the job nonce.
	 * @returns void
	 */
	public function logfile_open( $nonce ) {

		$this->logfile_name = $this->get_logfile_name( $nonce );

		$this->backup_is_already_complete = $this->found_backup_complete_in_logfile( $nonce, false );

		$this->logfile_handle = fopen( $this->logfile_name, 'a' );

		$this->opened_log_time = microtime( true );

		$this->write_log_header( array( $this, 'log' ) );
	}

	/**
	 * Opens the log file, and finds if backup_is_already_complete
	 *
	 * @param String  $nonce               - Used in the log file name to distinguish it from other log files. Should be the job nonce.
	 * @param Boolean $use_existing_result - Whether to use any existing result or not
	 *
	 * @return boolean - returns true if the backup is complete otherwise returns false
	 */
	public function found_backup_complete_in_logfile( $nonce, $use_existing_result = true ) {

		static $checked_files = array();

		if ( isset( $checked_files[ $nonce ] ) && $use_existing_result ) {
			return $checked_files[ $nonce ];
		}
		$logfile_name = $this->get_logfile_name( $nonce );

		if ( ! file_exists( $logfile_name ) ) {
			return false;
		}

		$backup_is_already_complete = false;

		$seek_to = max( ( filesize( $logfile_name ) - 340 ), 1 );
		$handle  = fopen( $logfile_name, 'r' );
		if ( is_resource( $handle ) ) {
			// Returns 0 on success
			if ( 0 === @fseek( $handle, $seek_to ) ) {// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
				$bytes_back = filesize( $logfile_name ) - $seek_to;
				// Return to the end of the file
				$read_recent = fread( $handle, $bytes_back );
				// Move to end of file - ought to be redundant
				if ( ( false !== strpos( $read_recent, ') The backup apparently succeeded' ) || false !== strpos( $read_recent, ') The backup succeeded' ) ) && false !== strpos( $read_recent, 'and is now complete' ) ) {
					$backup_is_already_complete = true;
				}
			}
			fclose( $handle );
		}

		$checked_files[ $nonce ] = $backup_is_already_complete;

		return $backup_is_already_complete;
	}

	/**
	 * Returns the logfile name for a given job
	 *
	 * @param String $nonce - Used in the log file name to distinguish it from other log files. Should be the job nonce.
	 * @return string
	 */
	public function get_logfile_name( $nonce ) {
		$backupplus_dir = $this->backups_dir_location();
		return $backupplus_dir . "/log.$nonce.txt";
	}

	/**
	 * Writes a standardised header to the log file, using the specified logging function, which needs to be compatible with (or to be) BackupPlus::log()
	 *
	 * @param callable $logging_function
	 */
	public function write_log_header( $logging_function ) {

		global $wpdb;

		$backupplus_dir = $this->backups_dir_location();

		call_user_func( $logging_function, 'Opened log file at time: ' . gmdate( 'r' ) . ' on ' . network_site_url() );

		$wp_version    = $this->get_wordpress_version();
		$mysql_version = $wpdb->get_var( 'SELECT VERSION()' );
		if ( '' == $mysql_version ) {
			$mysql_version = $wpdb->db_version();
		}
		$safe_mode = $this->detect_safe_mode();

		$memory_limit  = ini_get( 'memory_limit' );
		$memory_usage  = round( @memory_get_usage( false ) / 1048576, 1 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		$memory_usage2 = round( @memory_get_usage( true ) / 1048576, 1 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.

		// Attempt to raise limit to avoid false positives
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( BACKUPPLUS_SET_TIME_LIMIT );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}       $max_execution_time = (int) @ini_get( 'max_execution_time' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.

		$mp = (int) $wpdb->get_var( 'SELECT @@session.max_allowed_packet' );

		$proxy = new WP_HTTP_Proxy();

		$server_software = BackupPlus_Manipulation_Functions::fetch_superglobal( 'server', 'SERVER_SOFTWARE', '' );
		$logline         = 'BackupPlus, a GPL fork of the UpdraftPlus WordPress backup plugin: ' . $this->version . ' WP: ' . $wp_version . ' PHP: ' . phpversion() . ' (' . PHP_SAPI . ', ' . ( function_exists( 'php_uname' ) ? @php_uname() : PHP_OS ) . ") MySQL: $mysql_version (max packet size=$mp) WPLANG: " . get_locale() . ' Server: ' . $server_software . " safe_mode: $safe_mode max_execution_time: $max_execution_time memory_limit: $memory_limit (used: {$memory_usage}M | {$memory_usage2}M) multisite: " . ( is_multisite() ? ( is_subdomain_install() ? 'Y (sub-domain)' : 'Y (sub-folder)' ) : 'N' ) . ' openssl: ' . ( defined( 'OPENSSL_VERSION_TEXT' ) ? OPENSSL_VERSION_TEXT : 'N' ) . ' mcrypt: ' . ( function_exists( 'mcrypt_encrypt' ) ? 'Y' : 'N' ) . ' LANG: ' . getenv( 'LANG' ) . ' WP Proxy: ' . ( $proxy->is_enabled() ? 'enabled' : 'disabled' ) . ' ZipArchive::addFile: ';// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.

		// method_exists causes some faulty PHP installations to segfault, leading to support requests
		if ( version_compare( phpversion(), '5.2.0', '>=' ) && extension_loaded( 'zip' ) ) {
			$logline .= 'Y';
		} else {
			$logline .= ( class_exists( 'ZipArchive' ) && method_exists( 'ZipArchive', 'addFile' ) ) ? 'Y' : 'N';
		}

		if ( 0 === $this->current_resumption ) {
			$memlim = $this->memory_check_current();
			if ( $memlim < 65 && $memlim > 0 ) {
				/* translators: %s: Memory limit in MB */
				$this->log( sprintf( __( 'The amount of memory (RAM) allowed for PHP is very low (%s Mb) - you should increase it to avoid failures due to insufficient memory (consult your web hosting company for more help)', 'backupplus' ), round( $memlim, 1 ) ), 'warning', 'lowram' );
			}
			if ( $max_execution_time > 0 && $max_execution_time < 20 ) {
				/* translators: 1: Execution time in seconds, 2: Recommended execution time in seconds */
				call_user_func( $logging_function, sprintf( __( 'The amount of time allowed for WordPress plugins to run is very low (%1$s seconds) - you should increase it to avoid backup failures due to time-outs (consult your web hosting company for more help - it is the max_execution_time PHP setting; the recommended value is %2$s seconds or more)', 'backupplus' ), $max_execution_time, 90 ), 'warning', 'lowmaxexecutiontime' );
			}
		}

		call_user_func( $logging_function, $logline );

		$hosting_bytes_free = $this->get_hosting_disk_quota_free();
		if ( is_array( $hosting_bytes_free ) ) {
			$perc       = round( 100 * $hosting_bytes_free[1] / ( max( $hosting_bytes_free[2], 1 ) ), 1 );
			$quota_free = ' / ' . sprintf( 'Free disk space in account: %s (%s used)', round( $hosting_bytes_free[3] / 1048576, 1 ) . ' MB', "$perc %" );
			if ( $hosting_bytes_free[3] < 1048576 * 50 ) {
				$quota_free_mb = round( $hosting_bytes_free[3] / 1048576, 1 );
				/* translators: %s: Available disk space in MB */
				call_user_func( $logging_function, sprintf( __( 'Your free space in your hosting account is very low - only %s Mb remain', 'backupplus' ), $quota_free_mb ), 'warning', 'lowaccountspace' . $quota_free_mb );
			}
		} else {
			$quota_free = '';
		}

		$disk_free_space = function_exists( 'disk_free_space' ) ? @disk_free_space( $backupplus_dir ) : false;// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		// == rather than === here is deliberate; support experience shows that a result of (int)0 is not reliable. i.e. 0 can be returned when the real result should be false.
		if ( false == $disk_free_space ) {
			call_user_func( $logging_function, "Free space on disk containing BackupPlus's temporary directory: Unknown" . $quota_free );
		} else {
			call_user_func( $logging_function, "Free space on disk containing BackupPlus's temporary directory: " . round( $disk_free_space / 1048576, 1 ) . ' MB' . $quota_free );
			$disk_free_mb = round( $disk_free_space / 1048576, 1 );
			/* translators: %s: Available disk space in MB */
			if ( $disk_free_space < 50 * 1048576 ) {
				call_user_func( $logging_function, sprintf( __( 'Your free disk space is very low - only %s Mb remain', 'backupplus' ), round( $disk_free_space / 1048576, 1 ) ), 'warning', 'lowdiskspace' . $disk_free_mb );
			}
		}
	}

	/**
	 *
	 * Verifies that the indicated amount of memory is available
	 *
	 * @param Integer $how_many_bytes_needed - how many bytes need to be available
	 *
	 * @return Boolean - whether the needed number of bytes is available
	 */
	public function verify_free_memory( $how_many_bytes_needed ) {
		// This returns in MB
		$memory_limit = $this->memory_check_current();
		if ( ! is_numeric( $memory_limit ) ) {
			return false;
		}
		$memory_limit  = $memory_limit * 1048576;
		$memory_usage  = round( @memory_get_usage( false ), 1 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		$memory_usage2 = round( @memory_get_usage( true ), 1 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		if ( $memory_limit - $memory_usage > $how_many_bytes_needed && $memory_limit - $memory_usage2 > $how_many_bytes_needed ) {
			return true;
		}
		return false;
	}

	/**
	 * Logs the given line, adding (relative) time stamp and newline
	 * Note these subtleties of log handling:
	 * - Messages at level 'error' are not logged to file - it is assumed that a separate call to log() at another level will take place. This is because at level 'error', messages are translated; whereas the log file is for developers who may not know the translated language. Messages at level 'error' are for the user.
	 * - Messages at level 'error' do not persist through the job (they are only saved with save_backup_to_history(), and never restored from there - so only the final save_backup_to_history() errors
	 * persist); we presume that either a) they will be cleared on the next attempt, or b) they will occur again on the final attempt (at which point they will go to the user). But...
	 * - messages at level 'warning' persist. These are conditions that are unlikely to be cleared, not-fatal, but the user should be informed about. The $uniq_id field (which should not be numeric) can then be used for warnings that should only be logged once
	 * $skip_dblog = true is suitable when there's a risk of excessive logging, and the information is not important for the user to see in the browser on the settings page
	 * The uniq_id field is also used with PHP event detection - it is set then to 'php_event' - which is useful for anything hooking the action to detect
	 *
	 * @param  string  $line       the log line
	 * @param  string  $level      the log level: notice, warning, error. If suffixed with a hyphen and a destination, then the default destination is changed too.
	 * @param  boolean $uniq_id    each of these will only be logged once
	 * @param  boolean $skip_dblog if true, then do not write to the database
	 * @return null
	 */
	public function log( $line, $level = 'notice', $uniq_id = false, $skip_dblog = false ) {

		$destination = 'default';
		if ( preg_match( '/^([a-z]+)-([a-z]+)$/', $level, $matches ) ) {
			$level       = $matches[1];
			$destination = $matches[2];
		}

		if ( 'error' == $level || 'warning' == $level ) {
			if ( 'error' == $level && 0 == $this->error_count() ) {
				$this->log( 'An error condition has occurred for the first time during this job' );
			}
			if ( $uniq_id ) {
				$this->errors[ $uniq_id ] = array(
					'level'   => $level,
					'message' => $line,
				);
			} else {
				$this->errors[] = array(
					'level'   => $level,
					'message' => $line,
				);
			}
			// Errors are logged separately
			if ( 'error' == $level ) {
				return;
			}
			// It's a warning
			$warnings = $this->jobdata_get( 'warnings' );
			if ( ! is_array( $warnings ) ) {
				$warnings = array();
			}
			if ( $uniq_id ) {
				$warnings[ $uniq_id ] = $line;
			} else {
				$warnings[] = $line;
			}
			$this->jobdata_set( 'warnings', $warnings );
		}

		if ( false === ( $line = apply_filters( 'backupplus_logline', $line, $this->nonce, $level, $uniq_id, $destination ) ) ) {
			return;
		}

		if ( $this->logfile_handle ) {
			// Record log file times relative to the backup start, if possible
			$rtime = ( ! empty( $this->job_time_ms ) ) ? microtime( true ) - $this->job_time_ms : microtime( true ) - $this->opened_log_time;
			fwrite( $this->logfile_handle, sprintf( '%08.03f', round( $rtime, 3 ) ) . ' (' . $this->current_resumption . ') ' . ( ( 'notice' != $level ) ? '[' . ucfirst( $level ) . '] ' : '' ) . $line . "\n" );
		}

		switch ( $this->jobdata_get( 'job_type' ) ) {
			case 'download':
				// Download messages are keyed on the job (since they could be running several), and type
				// The values of the POST array were checked before
				list($findex, $timestamp, $type) = array_values(
					BackupPlus_Manipulation_Functions::fetch_superglobal_array(
						array( 'post', 'findex', 0 ),
						array( 'post', 'timestamp' ),
						array( 'post', 'type' )
					)
				);

				if ( $timestamp && $type ) {
					$this->jobdata_set( 'dlmessage_' . $timestamp . '_' . $type . '_' . $findex, $line );
				}
				break;

			case 'restore':
				// if ('debug' != $level) echo $line."\n";
				break;

			default:
				if ( ! $skip_dblog && 'debug' != $level ) {
					BackupPlus_Options::update_backupplus_option( 'backupplus_lastmessage', $line . ' (' . date_i18n( 'M d H:i:s' ) . ')', false );
				}
				break;
		}

		if ( defined( 'BACKUPPLUS_CONSOLELOG' ) && BACKUPPLUS_CONSOLELOG ) {
			echo $line . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- This constant can only be set deliberately by a user who is indicating he requires raw output on in a console (i.e. non-HTML) context
		}       if ( defined( 'BACKUPPLUS_BROWSERLOG' ) && BACKUPPLUS_BROWSERLOG ) {
			echo esc_html( $line ) . "<br>\n";
		}
	}

	/**
	 * Remove any logged warnings with the specified identifier. (The use case for this is that you can warn of something that may be about to happen (with a probably crash if it does), and then remove the warning if it did not happen).
	 *
	 * @see self::log()
	 *
	 * @param String $uniq_id - the identifier, previously passed to self::log()
	 */
	public function log_remove_warning( $uniq_id ) {
		$warnings = $this->jobdata_get( 'warnings' );
		if ( ! is_array( $warnings ) ) {
			$warnings = array();
		}
		// Avoid an unnecessary database write if nothing changed
		if ( isset( $warnings[ $uniq_id ] ) ) {
			unset( $warnings[ $uniq_id ] );
			$this->jobdata_set( 'warnings', $warnings );
		}
		unset( $this->errors[ $uniq_id ] );
	}

	/**
	 * Indicate whether or not a warning is logged with a specific identifier
	 *
	 * @see self::log()
	 *
	 * @param String $uniq_id - the identifier, previously passed to self::log()
	 *
	 * @return Boolean
	 */
	public function warning_exists( $uniq_id ) {
		$warnings = $this->jobdata_get( 'warnings' );
		return ! empty( $warnings[ $uniq_id ] );
	}

	/**
	 * For efficiency, you can also feed false or a string into this function
	 *
	 * @param  Boolean|String|WP_Error $err      - the errors
	 * @param  Boolean                 $echo     - whether to echo() the error(s)
	 * @param  Boolean                 $logerror - whether to pass errors to BackupPlus::log()
	 * @return Boolean - returns false for convenience
	 */
	public function log_wp_error( $err, $echo = false, $logerror = false ) {
		if ( false === $err ) {
			return false;
		}
		if ( is_string( $err ) ) {
			$this->log( "Error message: $err" );
			/* translators: %s: Error message */
			if ( $echo ) {
				$this->log( sprintf( __( 'Error: %s', 'backupplus' ), $err ), 'notice-warning' );
			}
			if ( $logerror ) {
				$this->log( $err, 'error' );
			}
			return false;
		}
		foreach ( $err->get_error_messages() as $msg ) {
			$this->log( "Error message: $msg" );
			/* translators: %s: Error message */
			if ( $echo ) {
				$this->log( sprintf( __( 'Error: %s', 'backupplus' ), $msg ), 'notice-warning' );
			}
			if ( $logerror ) {
				$this->log( $msg, 'error' );
			}
		}
		$codes = $err->get_error_codes();
		if ( is_array( $codes ) ) {
			foreach ( $codes as $code ) {
				$data = $err->get_error_data( $code );
				if ( ! empty( $data ) ) {
					$ll = ( is_string( $data ) ) ? $data : serialize( $data );
					$this->log( 'Error data (' . $code . '): ' . $ll );
				}
			}
		}
		// Returns false so that callers can return with false more efficiently if they wish
		return false;
	}

	/**
	 * This function will construct the restore information log line using the passed in parameters and then log the line using $this->log();
	 *
	 * @param array $restore_information - an array of restore information
	 *
	 * @return void
	 */
	public function log_restore_update( $restore_information ) {
		$this->log( 'RINFO:' . json_encode( $restore_information ), 'notice-progress' );
	}

	/**
	 * Outputs data to the browser.
	 * Will also fill the buffer on nginx systems after a specified amount of time.
	 *
	 * @param String $line The text to output. This may validly include HTML.
	 *
	 * @return void
	 */
	public function output_to_browser( $line ) {
		echo wp_kses_post( $line );
		$server_software = BackupPlus_Manipulation_Functions::fetch_superglobal( 'server', 'SERVER_SOFTWARE', '' );
		if ( false === stripos( $server_software, 'nginx' ) ) {
			return;
		}
		// Change it to what was actually output
		$line            = wp_kses_post( $line );
		static $strcount = 0;
		static $time     = 0;
		$buffer_size     = 65536; // The default NGINX config uses a buffer size of 32 or 64k, depending on the system. So we use 64K.
		if ( 0 == $time ) {
			$time = time();
		}
		$strcount += strlen( $line );
		if ( ( time() - $time ) >= 8 ) {
			// if the string count is > the buffer size, we reset, as it's likely the string was already sent.
			if ( $strcount > $buffer_size ) {
				$time     = time();
				$strcount = $strcount - $buffer_size;
				return;
			}
			echo str_repeat( ' ', $buffer_size - $strcount ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Absurd false positive
			// reset values
			$time     = time();
			$strcount = 0;
		}
	}
	/**
	 * Get the maximum packet size on the WPDB MySQL connection, in bytes, after (optionally) attempting to raise it to 32MB if it appeared to be lower.
	 * A default value equal to 1MB is returned if the true value could not be found - it has been found reasonable to assume that at least this is available.
	 *
	 * @param Boolean $first_raise
	 * @param Boolean $log_it
	 *
	 * @return Integer
	 */
	public function max_packet_size( $first_raise = true, $log_it = true ) {
		global $wpdb;
		$mp = (int) $wpdb->get_var( 'SELECT @@session.max_allowed_packet' );
		// Default to 1MB
		$mp = ( is_numeric( $mp ) && $mp > 0 ) ? $mp : 1048576;
		// 32MB
		if ( $first_raise && $mp < 33554432 ) {
			$save = $wpdb->show_errors( false );
			$req  = @$wpdb->query( 'SET GLOBAL max_allowed_packet=33554432' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.
			$wpdb->show_errors( $save );
			if ( ! $req ) {
				$this->log( 'Tried to raise max_allowed_packet from ' . round( $mp / 1048576, 1 ) . ' MB to 32 MB, but failed (' . $wpdb->last_error . ', ' . serialize( $req ) . ')' );
			}
			$mp = (int) $wpdb->get_var( 'SELECT @@session.max_allowed_packet' );
			// Default to 1MB
			$mp = ( is_numeric( $mp ) && $mp > 0 ) ? $mp : 1048576;
		}
		if ( $log_it ) {
			$this->log( 'Max packet size: ' . round( $mp / 1048576, 1 ) . ' MB' );
		}
		return $mp;
	}

	/**
	 * Q. Why is this abstracted into a separate function? A. To allow poedit and other parsers to pick up the need to translate strings passed to it (and not pick up all of those passed to log()).
	 * 1st argument = the line to be logged (obligatory)
	 * Further arguments = parameters for sprintf()
	 *
	 * @return null
	 */
	public function log_e() {
		$args = func_get_args();
		// Get first argument
		$pre_line = array_shift( $args );
		// Log it whilst still in English
		if ( is_wp_error( $pre_line ) ) {
			$this->log_wp_error( $pre_line );
		} else {
			// Now run (v)sprintf on it, using any remaining arguments. vsprintf = sprintf but takes an array instead of individual arguments
			$this->log( vsprintf( $pre_line, $args ) );
			// This is slightly hackish, in that we have no way to use a different level or destination. In that case, the caller should instead call log() twice with different parameters, instead of using this convenience function.
			$this->log( vsprintf( $pre_line, $args ), 'notice-restore' );
		}
	}

	/**
	 * This function is used by cloud methods to provide standardised logging, but more importantly to help us detect that meaningful activity took place during a resumption run, so that we can schedule further resumptions if it is worthwhile
	 *
	 * @param  Number  $percent   - the amount of the file uploaded
	 * @param  String  $extra     - anything extra to include in the log message
	 * @param  Boolean $file_path - the full path to the file being uploaded
	 * @param  Boolean $log_it    - whether to pass the message to BackupPlus::log()
	 * @return Void
	 */
	public function record_uploaded_chunk( $percent, $extra = '', $file_path = false, $log_it = true ) {

		// Touch the original file, which helps prevent overlapping runs
		if ( $file_path ) {
			touch( $file_path );
		}

		// What this means in effect is that at least one of the files touched during the run must reach this percentage (so lapping round from 100 is OK)
		if ( $percent > 0.7 * ( $this->current_resumption - max( $this->jobdata_get( 'uploaded_lastreset' ), 9 ) ) ) {
			BackupPlus_Job_Scheduler::something_useful_happened();
		}

		// Log it
		global $backupplus_backup;
		$log = empty( $backupplus_backup->current_service ) ? '' : ucfirst( $backupplus_backup->current_service ) . " chunked upload: $percent % uploaded";
		if ( $log && $log_it ) {
			$this->log( $log . ( $extra ? " ($extra)" : '' ) );
		}
		// If we are on an 'overtime' resumption run, and we are still meaningfully uploading, then schedule a new resumption
		// Our definition of meaningful is that we must maintain an overall average of at least 0.7% per run, after allowing 9 runs for everything else to get going
		// i.e. Max 100/.7 + 9 = 150 runs = 760 minutes = 12 hrs 40, if spaced at 5 minute intervals. However, our algorithm now decreases the intervals if it can, so this should not really come into play
		// If they get 2 minutes on each run, and the file is 1GB, then that equals 10.2MB/120s = minimum 59KB/s upload speed required

		$upload_status = $this->jobdata_get( 'uploading_substatus' );
		if ( is_array( $upload_status ) ) {
			$upload_status['p'] = $percent / 100;
			$this->jobdata_set( 'uploading_substatus', $upload_status );
		}
	}

	/**
	 * Method for helping remote storage methods to upload files in chunks without needing to duplicate all the overhead
	 *
	 * @param   Object  $caller        the object to call back to do the actual network API calls; needs to have a chunked_upload() method.
	 * @param   String  $file          the basename of the file
	 * @param   String  $cloudpath     this is passed back to the callback function; within this function, it is used only for logging
	 * @param   String  $logname       the prefix used on log lines. Also passed back to the callback function.
	 * @param   Integer $chunk_size    the size, in bytes, of each upload chunk
	 * @param   Integer $uploaded_size how many bytes have already been uploaded. This is passed back to the callback function; within this method, it is only used for logging.
	 * @param   Boolean $singletons    when the file, given the chunk size, would only have one chunk, should that be uploaded (true), or instead should 1 be returned (false) ?
	 * @return  Boolean
	 */
	public function chunked_upload( $caller, $file, $cloudpath, $logname, $chunk_size, $uploaded_size, $singletons = false ) {

		$fullpath       = $this->backups_dir_location() . '/' . $file;
		$orig_file_size = filesize( $fullpath );

		if ( $uploaded_size >= $orig_file_size && ! method_exists( $caller, 'chunked_upload_finish' ) ) {
			return true;
		}

		$chunks = floor( $orig_file_size / $chunk_size );
		// There will be a remnant unless the file size was exactly on a chunk boundary
		if ( $orig_file_size % $chunk_size > 0 ) {
			++$chunks;
		}

		$this->log( "$logname upload: $file (chunks: $chunks, of size: $chunk_size) -> $cloudpath ($uploaded_size)" );

		if ( 0 == $chunks ) {
			return 1;
		} elseif ( $chunks < 2 && ! $singletons ) {
			return 1;
		}

		// We have multiple chunks
		if ( $uploaded_size < $orig_file_size ) {

			if ( false == ( $fp = @fopen( $fullpath, 'rb' ) ) ) {// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
				$this->log( "$logname: failed to open file: $fullpath" );
				/* translators: %s: Log name */
				$this->log( "$file: " . sprintf( __( '%s Error: Failed to open local file', 'backupplus' ), $logname ), 'error' );
				return false;
			}

			$upload_start = 0;
			$upload_end   = -1;
			$chunk_index  = 1;
			// The file size minus one equals the byte offset of the final byte
			$upload_end           = min( $chunk_size - 1, $orig_file_size - 1 );
			$errors_on_this_chunk = 0;

			while ( $upload_start < $orig_file_size ) {

				// Don't forget the +1; otherwise the last byte is omitted
				$upload_size = $upload_end - $upload_start + 1;

				fseek( $fp, $upload_start );

				/*
				* Valid return values for $uploaded are many, as the possibilities have grown over time.
				* This could be cleaned up; but, it works, and it's not hugely complex.
				*
				* WP_Error : an error occured. The only permissible codes are: reduce_chunk_size (only on the first chunk), try_again
				* (bool)true : What was requested was done
				* (int)1 : What was requested was done, but do not log anything
				* (bool)false : There was an error
				* (Object) : Properties:
				*  (bool)log: (bool) - if absent, defaults to true
				*  (int)new_chunk_size: advisory amount for the chunk size for future chunks
				*  NOT IMPLEMENTED: (int)bytes_uploaded: Actual number of bytes uploaded (needs to be positive - o/w, should return an error instead)
				* N.B. Consumers should consult $fp and $upload_start to get data; they should not re-calculate from $chunk_index, which is not an indicator of file position.
				*/
				$uploaded = $caller->chunked_upload( $file, $fp, $chunk_index, $upload_size, $upload_start, $upload_end, $orig_file_size );

				// Try again? (Just once - added in 1.12.6 (can make more sophisticated if there is a need))
				if ( is_wp_error( $uploaded ) && 'try_again' == $uploaded->get_error_code() ) {
					// Arbitrary wait
					sleep( 3 );
					$this->log( 'Re-trying after wait (to allow apparent inconsistency to clear)' );
					$uploaded = $caller->chunked_upload( $file, $fp, $chunk_index, $upload_size, $upload_start, $upload_end, $orig_file_size );
				}

				// This is the only other supported case of a WP_Error - otherwise, a boolean must be returned
				// Note that this is only allowed on the first chunk. The caller is responsible to remember its chunk size if it uses this facility.
				if ( 1 == $chunk_index && is_wp_error( $uploaded ) && 'reduce_chunk_size' == $uploaded->get_error_code() && false != ( $new_chunk_size = $uploaded->get_error_data() ) && is_numeric( $new_chunk_size ) ) {
					$this->log( 'Re-trying with new chunk size: ' . $new_chunk_size );
					return $this->chunked_upload( $caller, $file, $cloudpath, $logname, $new_chunk_size, $uploaded_size, $singletons );
				}

				$uploaded_amount = $chunk_size;

				/*
				// Not using this approach for now. Instead, going to allow the consumers to increase the next chunk size
				if (is_object($uploaded) && isset($uploaded->bytes_uploaded)) {
					if (!$uploaded->bytes_uploaded) {
						$uploaded = false;
					} else {
						$uploaded_amount = $uploaded->bytes_uploaded;
						$uploaded = (!isset($uploaded->log) || $uploaded->log) ? true : 1;
					}
				}
				*/
				if ( is_object( $uploaded ) && isset( $uploaded->new_chunk_size ) ) {
					if ( $uploaded->new_chunk_size >= 1048576 ) {
						$new_chunk_size = $uploaded->new_chunk_size;
					}
					$uploaded = ( ! isset( $uploaded->log ) || $uploaded->log ) ? true : 1;
				}

				// The joys of WP/PHP: is_wp_error() is not false-y.
				if ( $uploaded && ! is_wp_error( $uploaded ) ) {
					$perc = round( 100 * ( $upload_end + 1 ) / max( $orig_file_size, 1 ), 1 );
					// Consumers use a return value of (int)1 (rather than (bool)true) to suppress logging
					$log_it = ( 1 === $uploaded ) ? false : true;
					$this->record_uploaded_chunk( $perc, $chunk_index, $fullpath, $log_it );

					// $uploaded_bytes = $upload_end + 1;

					// If there was an error, then we re-try the same chunk; we don't move on to the next one. Otherwise, we would need more code to handle potential 'intermediate' failed chunks (in case PHP dies before this method eventually returns false, and thus the intermediate chunk failure never gets detected)
					++$chunk_index;
					$errors_on_this_chunk = 0;
					$upload_start         = $upload_end + 1;
					$upload_end          += isset( $new_chunk_size ) ? $uploaded_amount + $new_chunk_size - $chunk_size : $uploaded_amount;
					$upload_end           = min( $upload_end, $orig_file_size - 1 );

				} else {

					++$errors_on_this_chunk;

					// Either $uploaded is false-y, or is a WP_Error
					if ( is_wp_error( $uploaded ) ) {
						$this->log( "$logname: Chunk upload ($chunk_index) failed (" . $uploaded->get_error_code() . '): ' . $uploaded->get_error_message() );
					} else {
						$this->log( "$logname: Chunk upload ($chunk_index) failed" );
					}

					if ( $errors_on_this_chunk >= 3 ) {
						@fclose( $fp );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
						return false;
					}
				}
			}

			@fclose( $fp );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.

		}

		// All chunks are uploaded - now combine the chunks
		$ret = true;

		// The action calls here exist to aid debugging
		if ( method_exists( $caller, 'chunked_upload_finish' ) ) {
			do_action( 'backupplus_pre_chunked_upload_finish', $file, $caller );
			$ret = $caller->chunked_upload_finish( $file );
			if ( ! $ret ) {
				$this->log( "$logname - failed to re-assemble chunks" );
				/* translators: %s: Log name */
				$this->log( sprintf( __( '%s error - failed to re-assemble chunks', 'backupplus' ), $logname ), 'error' );
			}
			do_action( 'backupplus_post_chunked_upload_finish', $file, $caller, $ret );
		}

		if ( $ret ) {
			// We allow chunked_upload_finish to return (int)1 to indicate that it took care of any logging.
			if ( true === $ret ) {
				$this->log( "$logname upload: success" );
			}
			$ret = true;
			// Remote storage integrations call this themselves.
			if ( ! is_a( $caller, 'BackupPlus_RemoteStorage_Addons_Base_v2' ) ) {
				$this->uploaded_file( $file );
			}
		}

		return $ret;
	}

	/**
	 * Provides a convenience function allowing remote storage methods to download a file in chunks, without duplicated overhead.
	 *
	 * @param String  $file              - The basename of the file being downloaded
	 * @param Object  $method            - This remote storage method object needs to have a chunked_download() method to call back
	 * @param Integer $remote_size       - The size, in bytes, of the object being downloaded
	 * @param Boolean $manually_break_up - Whether to break the download into multiple network operations (rather than just issuing a GET with a range beginning at the end of the already-downloaded data, and carrying on until it times out)
	 * @param Mixed   $passback          - A value to pass back to the callback function
	 * @param Integer $chunk_size        - Break up the download into chunks of this number of bytes. Should be set if and only if $manually_break_up is true.
	 */
	public function chunked_download( $file, $method, $remote_size, $manually_break_up = false, $passback = null, $chunk_size = 1048576 ) {

		try {

			$fullpath     = $this->backups_dir_location() . '/' . $file;
			$start_offset = file_exists( $fullpath ) ? filesize( $fullpath ) : 0;

			if ( $start_offset >= $remote_size ) {
				$this->log( "File is already completely downloaded ($start_offset/$remote_size)" );
				return true;
			}

			// Some more remains to download - so let's do it
			// N.B. We use ftell(), which precludes us from using open in append-only ('a') mode - see https://php.net/manual/en/function.fopen.php
			if ( ! ( $fh = fopen( $fullpath, 'c+' ) ) ) {
				$this->log( "Error opening local file: $fullpath" );
				$this->log( $file . ': ' . __( 'Error', 'backupplus' ) . ': ' . __( 'Error opening local file: Failed to download', 'backupplus' ), 'error' );
				return false;
			}

			$last_byte = ( $manually_break_up ) ? min( $remote_size, $start_offset + $chunk_size ) : $remote_size;

			// This only affects logging
			$expected_bytes_delivered_so_far = true;

			while ( $start_offset < $remote_size ) {
				$headers = array();
				// If resuming, then move to the end of the file

				$requested_bytes = $last_byte - $start_offset;

				if ( $expected_bytes_delivered_so_far ) {
					$this->log( "$file: local file is status: $start_offset/$remote_size bytes; requesting next $requested_bytes bytes" );
				} else {
					$this->log( "$file: local file is status: $start_offset/$remote_size bytes; requesting next chunk ({$start_offset}-)" );
				}

				if ( $start_offset > 0 || $last_byte < $remote_size ) {
					fseek( $fh, $start_offset );
					// N.B. Don't alter this format without checking what relies upon it
					$last_byte_start  = $last_byte - 1;
					$headers['Range'] = "bytes=$start_offset-$last_byte_start";
				}

				/*
				* The most common method is for the remote storage module to return a string with the results in it. In that case, the final $fh parameter is unused. However, since not all SDKs have that option conveniently, it is also possible to use the file handle and write directly to that; in that case, the method can either return the number of bytes written, or (boolean)true to infer it from the new file *pointer*.
				* The method is free to write/return as much data as it pleases.
				*/
				$ret = $method->chunked_download( $file, $headers, $passback, $fh );
				if ( true === $ret ) {
					clearstatcache();
					// Some SDKs (including AWS/S3) close the resource
					// N.B. We use ftell(), which precludes us from using open in append-only ('a') mode - see https://php.net/manual/en/function.fopen.php
					if ( is_resource( $fh ) ) {
						$ret = ftell( $fh );
					} else {
						$ret = filesize( $fullpath );
						// fseek returns - on success
						if ( false == ( $fh = fopen( $fullpath, 'c+' ) ) || 0 !== fseek( $fh, $ret ) ) {
							$this->log( "Error opening local file: $fullpath" );
							$this->log( $file . ': ' . __( 'Error', 'backupplus' ) . ': ' . __( 'Error opening local file: Failed to download', 'backupplus' ), 'error' );
							return false;
						}
					}
					if ( is_integer( $ret ) ) {
						$ret -= $start_offset;
					}
				}

				// Note that this covers a false code returned either by chunked_download() or by ftell.
				if ( false === $ret ) {
					return false;
				}

				$returned_bytes = is_integer( $ret ) ? $ret : strlen( $ret );

				if ( $returned_bytes > $requested_bytes || $returned_bytes < $requested_bytes - 1 ) {
					$expected_bytes_delivered_so_far = false;
				}

				if ( ! is_integer( $ret ) && ! fwrite( $fh, $ret ) ) {
					throw new Exception( 'Write failure (start offset: ' . $start_offset . ', bytes: ' . strlen( $ret ) . '; requested: ' . $requested_bytes . ')' );
				}

				clearstatcache();
				$start_offset = ftell( $fh );
				$last_byte    = ( $manually_break_up ) ? min( $remote_size, $start_offset + $chunk_size ) : $remote_size;

			}
		} catch ( Exception $e ) {
			$this->log( 'Error (' . get_class( $e ) . ') - failed to download the file (' . $e->getCode() . ', ' . $e->getMessage() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')' );
			$this->log( "$file: " . __( 'Error - failed to download the file', 'backupplus' ) . ' (' . $e->getCode() . ', ' . $e->getMessage() . ')', 'error' );
			return false;
		}

		$method->download_complete( $fh, $fullpath );

		fclose( $fh );

		return true;
	}

	/**
	 * Detect if safe_mode is on. N.B. This is abolished from PHP 7.0
	 *
	 * @return Integer - 1 or 0
	 */
	public function detect_safe_mode() {
		return ( @ini_get( 'safe_mode' ) && 'off' != strtolower( @ini_get( 'safe_mode' ) ) ) ? 1 : 0; // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, PHPCompatibility.IniDirectives.RemovedIniDirectives.safe_modeDeprecatedRemoved -- Silenced to suppress errors that may arise because of the function, ignore the removed ini directives compatibility.
	}

	/**
	 * Find, if possible, a working mysqldump executable
	 *
	 * @param Boolean $log_it  - whether to log the workings or not
	 * @param Boolean $cacheit - whether to cache the results for subsequent queries or not
	 *
	 * @return String|Boolean - either a path to an executable, or false for failure
	 */
	public function find_working_sqldump( $log_it = true, $cacheit = true ) {

		// The hosting provider may have explicitly disabled the popen or proc_open functions
		if ( $this->detect_safe_mode() || ! function_exists( 'popen' ) || ! function_exists( 'escapeshellarg' ) ) {
			if ( $cacheit ) {
				$this->jobdata_set( 'binsqldump', false );
			}
			return false;
		}
		$existing = $this->jobdata_get( 'binsqldump', null );
		// Theoretically, we could have moved machines, due to a migration
		if ( null !== $existing && ( ! is_string( $existing ) || @is_executable( $existing ) ) ) {
			return $existing;// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}
		$backupplus_dir = $this->backups_dir_location();
		global $wpdb;
		$table_name = $wpdb->get_blog_prefix() . 'options';
		$pfile      = md5( time() . wp_rand() ) . '.tmp';
		file_put_contents( $backupplus_dir . '/' . $pfile, "[mysqldump]\npassword=\"" . addslashes( DB_PASSWORD ) . "\"\n" );

		$result = false;
		foreach ( explode( ',', BACKUPPLUS_MYSQLDUMP_EXECUTABLE ) as $potsql ) {

			if ( ! @is_executable( $potsql ) ) {
				continue;// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			}
			if ( $log_it ) {
				$this->log( "Testing potential mysqldump binary: $potsql" );
			}

			if ( 'win' == strtolower( substr( PHP_OS, 0, 3 ) ) ) {
				$exec    = 'cd ' . escapeshellarg( str_replace( '/', '\\', $backupplus_dir ) ) . ' & ';
				$siteurl = "'siteurl'";
				if ( false !== strpos( $potsql, ' ' ) ) {
					$potsql = '"' . $potsql . '"';
				}
			} else {
				$exec    = 'cd ' . escapeshellarg( $backupplus_dir ) . '; ';
				$siteurl = "\\'siteurl\\'";
				if ( false !== strpos( $potsql, ' ' ) ) {
					$potsql = "'$potsql'";
				}
			}

			// Allow --max_allowed_packet to be configured via constant. Experience has shown some customers with complex CMS or pagebuilder setups can have extrememly large postmeta entries.
			$msqld_max_allowed_packet = ( defined( 'BACKUPPLUS_MYSQLDUMP_MAX_ALLOWED_PACKET' ) && ( is_int( BACKUPPLUS_MYSQLDUMP_MAX_ALLOWED_PACKET ) || is_string( BACKUPPLUS_MYSQLDUMP_MAX_ALLOWED_PACKET ) ) ) ? BACKUPPLUS_MYSQLDUMP_MAX_ALLOWED_PACKET : '1M';

			$exec .= "$potsql --defaults-file=$pfile --max_allowed_packet=$msqld_max_allowed_packet --quote-names --add-drop-table";

			static $mysql_version = null;
			if ( null === $mysql_version ) {
				$mysql_version = $wpdb->get_var( 'SELECT VERSION()' );
				if ( '' == $mysql_version ) {
					$mysql_version = $wpdb->db_version();
				}
			}
			if ( $mysql_version && version_compare( $mysql_version, '5.1', '>=' ) ) {
				$exec .= ' --no-tablespaces';
			}

			$exec .= " --skip-comments --skip-set-charset --allow-keywords --dump-date --extended-insert --where=option_name=$siteurl --user=" . escapeshellarg( DB_USER ) . ' ';

			if ( preg_match( '#^(.*):(\d+)$#', DB_HOST, $matches ) ) {
				// The escapeshellarg() on $matches[2] is only to avoid tripping static analysis tools
				$exec .= '--host=' . escapeshellarg( $matches[1] ) . ' --port=' . escapeshellarg( $matches[2] ) . ' ';
			} elseif ( preg_match( '#^(.*):(.*)$#', DB_HOST, $matches ) && file_exists( $matches[2] ) ) {
				$exec .= '--host=' . escapeshellarg( $matches[1] ) . ' --socket=' . escapeshellarg( $matches[2] ) . ' ';
			} else {
				$exec .= '--host=' . escapeshellarg( DB_HOST ) . ' ';
			}

			$exec .= DB_NAME . ' ' . escapeshellarg( $table_name );

			$handle = ( function_exists( 'popen' ) && function_exists( 'pclose' ) ) ? popen( $exec, 'r' ) : false;
			if ( $handle ) {
				$output = '';
				// We expect the INSERT statement in the first 100KB
				while ( ! feof( $handle ) && strlen( $output ) < 102400 ) {
					$output .= fgets( $handle, 102400 );
				}
				if ( $output && $log_it ) {
					$log_output = ( strlen( $output ) > 512 ) ? substr( $output, 0, 512 ) . ' (truncated - ' . strlen( $output ) . ' bytes total)' : $output;
					$this->log( 'Output: ' . str_replace( "\n", '\\n', trim( $log_output ) ) );
				}
				$ret = pclose( $handle );
				// The manual page for pclose() claims that only -1 indicates an error, but this is untrue
				if ( 0 != $ret ) {
					if ( $log_it ) {
						$this->log( "Binary mysqldump: error (code: $ret)" );
					}
				} else {
					if ( false !== stripos( $output, 'insert into' ) ) {
						if ( $log_it ) {
							$this->log( "Working binary mysqldump found: $potsql" );
						}
						$result = $potsql;
						break;
					}
				}
			} else {
				if ( $log_it ) {
					$this->log( 'Error: popen failed' );
				}
			}
		}

		if ( file_exists( $backupplus_dir . '/' . $pfile ) ) {
			unlink( $backupplus_dir . '/' . $pfile );
		}

		if ( $cacheit ) {
			$this->jobdata_set( 'binsqldump', $result );
		}

		return $result;
	}

	/**
	 * This function will work out which zip object we want to use and return it's name
	 *
	 * @return string - the name of the zip object we want to use
	 */
	public function get_zip_object_name() {

		if ( ! class_exists( 'BackupPlus_BinZip' ) ) {
			include_once BACKUPPLUS_DIR . '/includes/class-zip.php';
		}

		$zip_object = 'BackupPlus_ZipArchive';

		// In tests, PclZip was found to be 25% slower than ZipArchive
		if ( ( ( defined( 'BACKUPPLUS_PREFERPCLZIP' ) && BACKUPPLUS_PREFERPCLZIP == true ) || ! class_exists( 'ZipArchive' ) || ! class_exists( 'BackupPlus_ZipArchive' ) || ( ! extension_loaded( 'zip' ) && ! method_exists( 'ZipArchive', 'AddFile' ) ) ) ) {
			$zip_object = 'BackupPlus_PclZip';
		}

		return $zip_object;
	}

	/**
	 * We require -@ and -u -r to work - which is the usual Linux binzip
	 *
	 * @param  Boolean $log_it  - whether to record the results with BackupPlus::log()
	 * @param  Boolean $cacheit - whether to cache the results as job data
	 * @return String|Boolean   - the path to a working zip binary, or false
	 */
	public function find_working_bin_zip( $log_it = true, $cacheit = true ) {
		if ( $this->detect_safe_mode() ) {
			return false;
		}
		// The hosting provider may have explicitly disabled the popen or proc_open functions
		if ( ! function_exists( 'popen' ) || ! function_exists( 'proc_open' ) || ! function_exists( 'proc_close' ) || ! function_exists( 'escapeshellarg' ) ) {
			if ( $cacheit ) {
				$this->jobdata_set( 'binzip', false );
			}
			return false;
		}

		$existing = $this->jobdata_get( 'binzip', null );
		// Theoretically, we could have moved machines, due to a migration
		if ( null !== $existing && ( ! is_string( $existing ) || @is_executable( $existing ) ) ) {
			return $existing;// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}
		$backupplus_dir = $this->backups_dir_location();
		foreach ( explode( ',', BACKUPPLUS_ZIP_EXECUTABLE ) as $potzip ) {
			if ( ! @is_executable( $potzip ) ) {
				continue;// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			}           if ( $log_it ) {
				$this->log( "Testing: $potzip" );
			}

			// Test it, see if it is compatible with Info-ZIP
			// If you have another kind of zip, then feel free to tell me about it
			@mkdir( $backupplus_dir . '/binziptest/subdir1/subdir2', 0777, true );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.

			if ( ! file_exists( $backupplus_dir . '/binziptest/subdir1/subdir2' ) ) {
				return false;
			}

			file_put_contents( $backupplus_dir . '/binziptest/subdir1/subdir2/test.html', '<html><body>BackupPlus is a great backup and restoration plugin for WordPress.</body></html>' );

			if ( file_exists( $backupplus_dir . '/binziptest/test.zip' ) ) {
				unlink( $backupplus_dir . '/binziptest/test.zip' );
			}

			if ( is_file( $backupplus_dir . '/binziptest/subdir1/subdir2/test.html' ) ) {

				$exec = 'cd ' . escapeshellarg( $backupplus_dir ) . "; $potzip";
				if ( defined( 'BACKUPPLUS_BINZIP_OPTS' ) && BACKUPPLUS_BINZIP_OPTS ) {
					$exec .= ' ' . BACKUPPLUS_BINZIP_OPTS;
				}
				$exec .= ' -v -u -r binziptest/test.zip binziptest/subdir1';

				$all_ok = true;
				$handle = ( function_exists( 'popen' ) && function_exists( 'pclose' ) ) ? popen( $exec, 'r' ) : false;
				if ( $handle ) {
					while ( ! feof( $handle ) ) {
						$w = fgets( $handle );
						if ( $w && $log_it ) {
							$this->log( 'Output: ' . trim( $w ) );
						}
					}
					$ret = pclose( $handle );
					// The manual page for pclose() claims that only -1 indicates an error, but this is untrue
					if ( 0 != $ret ) {
						if ( $log_it ) {
							$this->log( "Binary zip: error (code: $ret)" );
						}
						$all_ok = false;
					}
				} else {
					if ( $log_it ) {
						$this->log( 'Error: popen failed' );
					}
					$all_ok = false;
				}

				// Now test -@
				if ( true == $all_ok ) {
					file_put_contents( $backupplus_dir . '/binziptest/subdir1/subdir2/test2.html', '<html><body>BackupPlus is a really great backup and restoration plugin for WordPress.</body></html>' );

					$exec = $potzip;
					if ( defined( 'BACKUPPLUS_BINZIP_OPTS' ) && BACKUPPLUS_BINZIP_OPTS ) {
						$exec .= ' ' . BACKUPPLUS_BINZIP_OPTS;
					}
					$exec .= ' -v -@ binziptest/test.zip';

					$all_ok = true;

					$descriptorspec = array(
						0 => array( 'pipe', 'r' ),
						1 => array( 'pipe', 'w' ),
						2 => array( 'pipe', 'w' ),
					);
					$handle         = proc_open( $exec, $descriptorspec, $pipes, $backupplus_dir );// phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- This function is intentionally used and reviewed for safety.
					if ( is_resource( $handle ) ) {
						if ( ! fwrite( $pipes[0], "binziptest/subdir1/subdir2/test2.html\n" ) ) {
							@fclose( $pipes[0] );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
							@fclose( $pipes[1] );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
							@fclose( $pipes[2] );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
							$all_ok = false;
						} else {
							fclose( $pipes[0] );
							while ( ! feof( $pipes[1] ) ) {
								$w = fgets( $pipes[1] );
								if ( $w && $log_it ) {
									$this->log( 'Output: ' . trim( $w ) );
								}
							}
							fclose( $pipes[1] );

							while ( ! feof( $pipes[2] ) ) {
								$last_error = fgets( $pipes[2] );
								if ( ! empty( $last_error ) && $log_it ) {
									$this->log( 'Stderr output: ' . trim( $w ) );
								}
							}
							fclose( $pipes[2] );

							$ret = function_exists( 'proc_close' ) ? proc_close( $handle ) : -1;
							if ( 0 != $ret ) {
								if ( $log_it ) {
									$this->log( "Binary zip: error (code: $ret)" );
								}
								$all_ok = false;
							}
						}
					} else {
						if ( $log_it ) {
							$this->log( 'Error: proc_open failed' );
						}
						$all_ok = false;
					}
				}

				// Do we now actually have a working zip? Need to test the created object using PclZip
				// If it passes, then remove dirs and then return $potzip;
				$found_first  = false;
				$found_second = false;
				if ( $all_ok && file_exists( $backupplus_dir . '/binziptest/test.zip' ) ) {
					if ( function_exists( 'gzopen' ) ) {
						if ( ! class_exists( 'PclZip' ) ) {
							include_once ABSPATH . '/wp-admin/includes/class-pclzip.php';
						}
						$zip = new PclZip( $backupplus_dir . '/binziptest/test.zip' );
						if ( ( $list = $zip->listContent() ) != 0 ) {
							foreach ( $list as $obj ) {
								if ( $obj['filename'] && ! empty( $obj['stored_filename'] ) && 'binziptest/subdir1/subdir2/test.html' == $obj['stored_filename'] && 131 == $obj['size'] ) {
									$found_first = true;
								}
								if ( $obj['filename'] && ! empty( $obj['stored_filename'] ) && 'binziptest/subdir1/subdir2/test2.html' == $obj['stored_filename'] && 138 == $obj['size'] ) {
									$found_second = true;
								}
							}
						}
					} else {
						// PclZip will die() if gzopen is not found
						// Obviously, this is a kludge - we assume it's working. We could, of course, just return false - but since we already know now that PclZip can't work, that only leaves ZipArchive
						$this->log( 'gzopen function not found; PclZip cannot be invoked; will assume that binary zip works if we have a non-zero file' );
						if ( filesize( $backupplus_dir . '/binziptest/test.zip' ) > 0 ) {
							$found_first  = true;
							$found_second = true;
						}
					}
				}
				$this->remove_binzip_test_files( $backupplus_dir );
				if ( $found_first && $found_second ) {
					if ( $log_it ) {
						$this->log( "Working binary zip found: $potzip" );
					}
					if ( $cacheit ) {
						$this->jobdata_set( 'binzip', $potzip );
					}
					return $potzip;
				}
			}
			$this->remove_binzip_test_files( $backupplus_dir );
		}
		if ( $cacheit ) {
			$this->jobdata_set( 'binzip', false );
		}
		return false;
	}

	/**
	 * Remove potentially existing test files after binzip testing
	 *
	 * @param String $backupplus_dir - directory to find the files in
	 */
	private function remove_binzip_test_files( $backupplus_dir ) {
		@unlink( $backupplus_dir . '/binziptest/subdir1/subdir2/test.html' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
		@unlink( $backupplus_dir . '/binziptest/subdir1/subdir2/test2.html' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
		@rmdir( $backupplus_dir . '/binziptest/subdir1/subdir2' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		@rmdir( $backupplus_dir . '/binziptest/subdir1' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		@unlink( $backupplus_dir . '/binziptest/test.zip' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
		@rmdir( $backupplus_dir . '/binziptest' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
	}

	public function option_filter_get( $which ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1", $which ) );
		// Has to be get_row instead of get_var because of funkiness with 0, false, null values
		return ( is_object( $row ) ) ? $row->option_value : false;
	}

	/**
	 * Indicate which checksums to take for backup files. Abstracted for extensibilty and future changes.
	 *
	 * @returns array - a list of hashing algorithms, as understood by PHP's hash() function
	 */
	public function which_checksums() {
		return apply_filters( 'backupplus_which_checksums', array( 'sha1', 'sha256' ) );
	}

	/**
	 * Pretty printing of the raw backup information
	 *
	 * @param String  $description
	 * @param Array   $history
	 * @param String  $entity
	 * @param Array   $checksums
	 * @param Array   $jobdata
	 * @param Boolean $smaller
	 * @return String
	 */
	public function printfile( $description, $history, $entity, $checksums, $jobdata, $smaller = false ) {

		if ( empty( $history[ $entity ] ) ) {
			return;
		}

		// PHP 7.2+ throws a warning if you try to count() a string
		$how_many = is_string( $history[ $entity ] ) ? 1 : count( $history[ $entity ] );

		if ( $smaller ) {
			/* translators: %s: Number of files */
			$pfiles = '<strong>' . $description . ' (' . sprintf( __( 'files: %s', 'backupplus' ), $how_many ) . ")</strong><br>\n";
		} else {
			/* translators: %s: Number of files */
			$pfiles = '<h3>' . $description . ' (' . sprintf( __( 'files: %s', 'backupplus' ), $how_many ) . ")</h3>\n\n";
		}

		$is_incremental = ( ! empty( $jobdata ) && ! empty( $jobdata['job_type'] ) && 'incremental' == $jobdata['job_type'] && 'db' != substr( $entity, 0, 2 ) ) ? true : false;

		if ( $is_incremental ) {
			$backup_timestamp = isset( $jobdata['incremental_base_time'] ) ? $jobdata['incremental_base_time'] : $jobdata['backup_time'];
			$backup_history   = BackupPlus_Backup_History::get_history( $backup_timestamp );
			$pfiles          .= '<dl>';
			foreach ( $backup_history['incremental_sets'] as $timestamp => $backup ) {
				if ( isset( $backup[ $entity ] ) ) {
					$pfiles .= '<dt>' . get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $timestamp ), 'M d, Y G:i' ) . "\n</dt>\n";
					foreach ( $backup[ $entity ] as $ind => $file ) {
						$pfiles .= '<dd>' . $this->get_entity_row( $file, $history, $entity, $checksums, $jobdata, $ind ) . "\n</dd>\n";
					}
				}
			}
			$pfiles .= "</dl>\n";
		} else {

			$pfiles .= '<ul>';
			$files   = $history[ $entity ];
			if ( is_string( $files ) ) {
				$files = array( $files );
			}

			foreach ( $files as $ind => $file ) {
				$pfiles .= '<li>' . $this->get_entity_row( $file, $history, $entity, $checksums, $jobdata, $ind ) . "\n</li>\n";
			}
			$pfiles .= "</ul>\n";
		}

		return $pfiles;
	}

	/**
	 * This function will use the passed in information to prepare a pretty string describing the backup from the raw backup history
	 *
	 * @param String  $file      - the backup file
	 * @param Array   $history   - the backup history
	 * @param String  $entity    - the backup entity
	 * @param Array   $checksums - checksums for the backup file
	 * @param Array   $jobdata   - the jobdata for this backup
	 * @param Integer $ind       - the index of the file
	 *
	 * @return String            - returns the entity output string
	 */
	public function get_entity_row( $file, $history, $entity, $checksums, $jobdata, $ind ) {
		$op   = htmlspecialchars( $file );
		$skey = $entity . ( ( 0 == $ind ) ? '' : $ind ) . '-size';

		$op = apply_filters( 'backupplus_report_downloadable_file_link', $op, $entity, $ind, $jobdata );

		$op .= "\n";

		$meta = '';
		if ( 'db' == substr( $entity, 0, 2 ) && 'db' != $entity ) {
			$dind = substr( $entity, 2 );
			if ( is_array( $jobdata ) && ! empty( $jobdata['backup_database'] ) && is_array( $jobdata['backup_database'] ) && ! empty( $jobdata['backup_database'][ $dind ] ) && is_array( $jobdata['backup_database'][ $dind ]['dbinfo'] ) && ! empty( $jobdata['backup_database'][ $dind ]['dbinfo']['host'] ) ) {
				$dbinfo = $jobdata['backup_database'][ $dind ]['dbinfo'];
				/* translators: %s: Database connection details */
				$meta .= sprintf( __( 'External database (%s)', 'backupplus' ), $dbinfo['user'] . '@' . $dbinfo['host'] . '/' . $dbinfo['name'] ) . '<br>';
			}
		}
		/* translators: %s: Database size in MB */
		if ( isset( $history[ $skey ] ) ) {
			$meta .= sprintf( __( 'Size: %s MB', 'backupplus' ), round( $history[ $skey ] / 1048576, 1 ) );
		}
		$ckey = $entity . $ind;
		foreach ( $checksums as $ck ) {
			$ck_plain = false;
			if ( isset( $history['checksums'][ $ck ][ $ckey ] ) ) {
				/* translators: 1: Checksum type, 2: Checksum value */
				$meta    .= ( ( $meta ) ? ', ' : '' ) . sprintf( __( '%1$s checksum: %2$s', 'backupplus' ), strtoupper( $ck ), $history['checksums'][ $ck ][ $ckey ] );
				$ck_plain = true;
			}
			if ( isset( $history['checksums'][ $ck ][ $ckey . '.crypt' ] ) ) {
				if ( $ck_plain ) {
					$meta .= ' ' . __( '(when decrypted)', 'backupplus' );
				}
				/* translators: 1: Checksum type, 2: Checksum value */
				$meta .= ( ( $meta ) ? ', ' : '' ) . sprintf( __( '%1$s checksum: %2$s', 'backupplus' ), strtoupper( $ck ), $history['checksums'][ $ck ][ $ckey . '.crypt' ] );
			}
		}

		$fileinfo = apply_filters( "backupplus_fileinfo_$entity", array(), $ind );
		if ( is_array( $fileinfo ) && ! empty( $fileinfo ) ) {
			if ( isset( $fileinfo['html'] ) ) {
				$meta .= $fileinfo['html'];
			}
		}

		// if ($meta) $meta = " ($meta)";
		if ( $meta ) {
			$meta = "<br><em>$meta</em>";
		}

		return $op . $meta;
	}

	/**
	 * This important function returns a list of file entities that can potentially be backed up (subject to users settings), and optionally further meta-data about them
	 *
	 * @param boolean $include_others Whether to include "Others" in the list of entities to backup.
	 * @param boolean $full_info      Whether to include additional metadata about each entity.
	 *
	 * @return array An associative array containing information about the backupable file entities.
	 */
	public function get_backupable_file_entities( $include_others = true, $full_info = false ) {

		$wp_upload_dir = $this->wp_upload_dir();

		if ( $full_info ) {
			$arr = array(
				'plugins'    => array(
					'path'                 => untrailingslashit( WP_PLUGIN_DIR ),
					'description'          => __( 'Plugins', 'backupplus' ),
					'singular_description' => __( 'Plugin', 'backupplus' ),
				),
				'themes'     => array(
					'path'                 => WP_CONTENT_DIR . '/themes',
					'description'          => __( 'Themes', 'backupplus' ),
					'singular_description' => __( 'Theme', 'backupplus' ),
				),
				'uploads'    => array(
					'path'        => untrailingslashit( $wp_upload_dir['basedir'] ),
					'description' => __( 'Uploads', 'backupplus' ),
				),
				'mu-plugins' => array(
					'path'        => WPMU_PLUGIN_DIR,
					'description' => __( 'Must-use plugins', 'backupplus' ),
				),
			);
		} else {
			$arr = array(
				'plugins'    => untrailingslashit( WP_PLUGIN_DIR ),
				'themes'     => WP_CONTENT_DIR . '/themes',
				'uploads'    => untrailingslashit( $wp_upload_dir['basedir'] ),
				'mu-plugins' => WPMU_PLUGIN_DIR,
			);
		}

		$arr = apply_filters( 'backupplus_backupable_file_entities', $arr, $full_info );

		// We then add 'others' on to the end
		if ( $include_others ) {
			if ( $full_info ) {
				$arr['others'] = array(
					'path'        => WP_CONTENT_DIR,
					'description' => __( 'Others', 'backupplus' ),
				);
			} else {
				$arr['others'] = WP_CONTENT_DIR;
			}
		}

		// Entries that should be added after 'others'
		$arr = apply_filters( 'backupplus_backupable_file_entities_final', $arr, $full_info );

		return $arr;
	}

	/**
	 * This function returns a list of specific php error messages and their action block
	 *
	 * @return array An associative array containing information about certain specific php errors and their error messages.
	 */
	private function php_specific_error_handler_data() {
		$handle_specific_messages = array(
			'open_basedir restriction.*/cpanel' => array(
				'action'      => 'replace',
				'action_data' => 'Could not ask cPanel about disk space (open_basedir) - this is not an error',
			),
			'ftp_nb_fput\(\): php_connect_nonb\(\) failed: Operation now in progress' => array(
				'action'      => 'append',
				'action_data' => "\nPHP logs this condition if a firewall blocked your FTP data channel from your webserver to your FTP server (but allowed your FTP control channel). You will need to speak to one or both of the administrators of those two servers to investigate (note that BackupPlus support cannot access any more information about the network than this PHP notice gives - talking to the administrators who can access that information is the next step).",
			),
		);
		return $handle_specific_messages;
	}

	public function php_error_to_logline( $errno, $errstr, $errfile, $errline ) {
		switch ( $errno ) {
			case 1:
				$e_type = 'E_ERROR';
				break;
			case 2:
				$e_type = 'E_WARNING';
				break;
			case 4:
				$e_type = 'E_PARSE';
				break;
			case 8:
				$e_type = 'E_NOTICE';
				break;
			case 16:
				$e_type = 'E_CORE_ERROR';
				break;
			case 32:
				$e_type = 'E_CORE_WARNING';
				break;
			case 64:
				$e_type = 'E_COMPILE_ERROR';
				break;
			case 128:
				$e_type = 'E_COMPILE_WARNING';
				break;
			case 256:
				$e_type = 'E_USER_ERROR';
				break;
			case 512:
				$e_type = 'E_USER_WARNING';
				break;
			case 1024:
				$e_type = 'E_USER_NOTICE';
				break;
			case 2048:
				$e_type = 'E_STRICT';
				break;
			case 4096:
				$e_type = 'E_RECOVERABLE_ERROR';
				break;
			case 8192:
				$e_type = 'E_DEPRECATED';
				break;
			case 16384:
				$e_type = 'E_USER_DEPRECATED';
				break;
			case 30719:
				$e_type = 'E_ALL';
				break;
			default:
				$e_type = "E_UNKNOWN ($errno)";
				break;
		}

		if ( false !== stripos( $errstr, 'table which is not valid in this version of Gravity Forms' ) ) {
			return false;
		}

		if ( ! is_string( $errstr ) ) {
			$errstr = serialize( $errstr );
		}

		if ( 0 === strpos( $errfile, ABSPATH ) ) {
			$errfile = substr( $errfile, strlen( ABSPATH ) );
		}

		if ( 'E_DEPRECATED' == $e_type && ! empty( $this->no_deprecation_warnings ) ) {
			return false;
		}

		$error_string = "PHP event: code $e_type: $errstr";

		foreach ( $this->php_specific_error_handler_data() as $pattern => $action_block ) {

			if ( preg_match( '#' . $pattern . '#i', $error_string ) ) {
				if ( 'replace' == $action_block['action'] ) {
					$error_string = 'PHP event: ' . $action_block['action_data'];
				}

				if ( 'append' == $action_block['action'] ) {
					$error_string = $error_string . ' ' . $action_block['action_data'];
				}
			}
		}

		$error_string .= " (line $errline, $errfile)";

		return $error_string;
	}

	/**
	 * Custom PHP error handler. This will print the error to the log file.
	 *
	 * @param integer $errno   - The level of the error raised
	 * @param string  $errstr  - The error message
	 * @param string  $errfile - The filename that the error was raised in
	 * @param integer $errline - The line number the error was raised at
	 *
	 * @return bool If the function returns false, the standard PHP error handler will continue to process the error
	 */
	public function php_error( $errno, $errstr, $errfile, $errline ) {
		// Unlike in PHP 7.4 and lower, the 'error_reporting()' function in PHP 8.0+ will return the value of this bitwise expression: 'E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR | E_PARSE' instead of '0' when we suppress the error using the '@' operator.
		$error_level = error_reporting(); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting -- The error_reporting() function is used to get the current PHP error level.
		$fatal_mask  = E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR | E_PARSE;
		if ( 0 === $error_level || 0 === ( $error_level & ~$fatal_mask ) ) {
			return true;
		}

		$logline = $this->php_error_to_logline( $errno, $errstr, $errfile, $errline );
		if ( false !== $logline ) {
			$this->log( $logline, 'notice', 'php_event' );
		}
		// Pass it up the chain
		return $this->error_reporting_stop_when_logged;
	}

	/**
	 * Proceed with a backup; before calling this, at least all the initial job data must be set up
	 *
	 * @param Integer $resumption_no - which resumption this is; from 0 upwards
	 * @param String  $bnonce        - the backup job identifier
	 */
	public function backup_resume( $resumption_no, $bnonce ) {

		// Theoretically (N.B. has been seen in the real world), the WP scheduler might call us more than once within the same context (e.g. an incremental run followed by a main backup resumption), leaving us with incorrect internal state if we don't reset.
		static $last_bnonce = null;
		if ( $last_bnonce ) {
			$this->jobdata_reset();
		}
		$last_bnonce = $bnonce;

		$error_levels = version_compare( PHP_VERSION, '8.4.0', '>=' ) ? E_ALL : E_ALL & ~E_STRICT;
		set_error_handler( array( $this, 'php_error' ), $error_levels );

		$this->current_resumption = $resumption_no;

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( BACKUPPLUS_SET_TIME_LIMIT );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}       if ( function_exists( 'ignore_user_abort' ) ) {
			@ignore_user_abort( true );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}
		$runs_started = array();
		$time_now     = microtime( true );

		BackupPlus_Backup_History::always_get_from_db();

		// Restore state
		$resumption_extralog        = '';
		$prev_resumption            = $resumption_no - 1;
		$last_successful_resumption = -1;
		$job_type                   = 'backup';

		if ( 0 == $resumption_no ) {
			$label = $this->jobdata_get( 'label' );
			if ( $label ) {
				$resumption_extralog = apply_filters( 'backupplus_autobackup_extralog', ", label=$label" );
			}
		} else {
			$this->nonce       = $bnonce;
			$file_nonce        = $this->jobdata_get( 'file_nonce' );
			$this->file_nonce  = $file_nonce ? $file_nonce : $bnonce;
			$this->backup_time = $this->jobdata_get( 'backup_time' );
			$this->job_time_ms = $this->jobdata_get( 'job_time_ms' );

			// Get the warnings before opening the log file, as opening the log file may generate new ones (which then leads to $this->errors having duplicate entries when they are copied over below)
			$warnings = $this->jobdata_get( 'warnings' );

			$this->logfile_open( $this->nonce );

			if ( ! $this->get_backup_job_semaphore_lock( $this->nonce, $resumption_no ) ) {
				$this->log( 'Failed to get backup job lock; possible overlapping resumptions - will abort this instance' );
				die;
			}

			// Import existing warnings. The purpose of this is so that when save_backup_to_history() is called, it has a complete set - because job data expires quickly, whilst the warnings of the last backup run need to persist
			if ( is_array( $warnings ) ) {
				foreach ( $warnings as $warning ) {
					$this->errors[] = array(
						'level'   => 'warning',
						'message' => $warning,
					);
				}
			}

			$runs_started = $this->jobdata_get( 'runs_started' );
			if ( ! is_array( $runs_started ) ) {
				$runs_started = array();
			}
			$time_passed = $this->jobdata_get( 'run_times' );
			if ( ! is_array( $time_passed ) ) {
				$time_passed = array();
			}

			foreach ( $time_passed as $run => $passed ) {
				if ( isset( $runs_started[ $run ] ) && $runs_started[ $run ] + $time_passed[ $run ] + 30 > $time_now ) {
					// We don't want to increase the resumption if WP has started two copies of the same resumption off
					if ( $run && $run == $resumption_no ) {
						$increase_resumption = false;
						$this->log( "It looks like WordPress's scheduler has started multiple instances of this resumption" );
					} else {
						$increase_resumption = true;
					}
					BackupPlus_Job_Scheduler::terminate_due_to_activity( 'check-in', round( $time_now, 1 ), round( $runs_started[ $run ] + $time_passed[ $run ], 1 ), $increase_resumption );
				}
			}

			$useful_checkins = $this->jobdata_get( 'useful_checkins', array() );
			if ( ! empty( $useful_checkins ) ) {
				$last_successful_resumption = min( max( $useful_checkins ), $prev_resumption );
			}

			if ( isset( $time_passed[ $prev_resumption ] ) ) {
				// N.B. A check-in occurred; we haven't yet tested if it was useful
				$resumption_extralog = ', previous check-in=' . round( $time_passed[ $prev_resumption ], 2 ) . 's';
			}

			// This is just a simple test to catch restorations of old backup sets where the backup includes a resumption of the backup job
			if ( $time_now - $this->backup_time > 172800 && true == apply_filters( 'backupplus_check_obsolete_backup', true, $time_now, $this ) ) {

				// We have seen cases where the get_site_option() call that self::get_jobdata() relies on returns nothing, even though the data was there in the database. This appears to be sometimes reproducible for the people who get it, but stops being reproducible if they change their backup times - which suggests that they're having failures at times of extreme load. We can attempt to detect this case, and reschedule, instead of aborting.
				if ( empty( $this->backup_time ) && empty( $this->backup_is_already_complete ) && ! empty( $this->logfile_name ) && is_readable( $this->logfile_name ) ) {
					$first_log_bit = file_get_contents( $this->logfile_name, false, null, 0, 250 );
					if ( preg_match( '/\(0\) Opened log file at time: (.*) on /', $first_log_bit, $matches ) ) {
						$first_opened = strtotime( $matches[1] );
						// The value of 1000 seconds here is somewhat arbitrary; but allows for the problem to occur in ~ the first 15 minutes. In practice, the problem is extremely rare; if this does not catch it, we can tweak the algorithm.
						if ( time() - $first_opened < 1000 ) {
							$this->log( 'This backup task (' . $this->nonce . ") failed to load its job data (possible database server malfunction), but has only recently started: scheduling a fresh resumption in order to try again, and then ending this resumption ($time_now, " . $this->backup_time . ') (existing jobdata keys: ' . implode( ', ', array_keys( $this->jobdata ) ) . ')' );
							BackupPlus_Job_Scheduler::reschedule( 120 );
							die;
						}
					}
				}

				// If we are doing a local upload then we do not want to abort the backup as it's possible they are uploading a backup that is older than two days
				if ( empty( $this->jobdata['local_upload'] ) ) {
					$this->log( 'This backup task (' . $this->nonce . ") is either complete or began over 2 days ago: ending ($time_now, " . $this->backup_time . ') (existing jobdata keys: ' . implode( ', ', array_keys( $this->jobdata ) ) . ')' );
					die;
				}
			}
		}

		$this->last_successful_resumption = $last_successful_resumption;

		$runs_started[ $resumption_no ] = $time_now;
		if ( ! empty( $this->backup_time ) ) {
			$this->jobdata_set( 'runs_started', $runs_started );
		}

		// Schedule again, to run in 5 minutes again, in case we again fail
		// The actual interval can be increased (for future resumptions) by other code, if it detects apparent overlapping
		$resume_interval = max( (int) $this->jobdata_get( 'resume_interval' ), 100 );

		$btime = $this->backup_time;

		$job_type = $this->jobdata_get( 'job_type' );

		do_action( 'backupplus_resume_backup_' . $job_type );

		$backupplus_dir = $this->backups_dir_location();

		$time_ago = time() - $btime;

		$this->log( "Backup run: resumption=$resumption_no, nonce=$bnonce, file_nonce=" . $this->file_nonce . " begun at=$btime ({$time_ago}s ago), job type=$job_type" . $resumption_extralog );

		// This works round a bizarre bug seen in one WP install, where delete_transient and wp_clear_scheduled_hook both took no effect, and upon 'resumption' the entire backup would repeat.
		// Argh. In fact, this has limited effect, as apparently (at least on another install seen), the saving of the updated transient via jobdata_set() also took no effect. Still, it does not hurt.
		if ( $resumption_no >= 1 && 'finished' == $this->jobdata_get( 'jobstatus' ) ) {
			$this->log( 'Terminate: This backup job is already finished (1).' );
			die;
		} elseif ( 'clouduploading' != $this->jobdata_get( 'jobstatus' ) && 'backup' == $job_type && ! empty( $this->backup_is_already_complete ) ) {
			$this->jobdata_set( 'jobstatus', 'finished' );
			$this->log( 'Terminate: This backup job is already finished (2).' );
			die;
		}

		if ( $resumption_no > 0 && isset( $runs_started[ $prev_resumption ] ) ) {
			$our_expected_start = $runs_started[ $prev_resumption ] + $resume_interval;
			// If the previous run increased the resumption time, then it is timed from the end of the previous run, not the start
			if ( isset( $time_passed[ $prev_resumption ] ) && $time_passed[ $prev_resumption ] > 0 ) {
				$our_expected_start += $time_passed[ $prev_resumption ];
			}
			$our_expected_start = apply_filters( 'backupplus_expected_start', $our_expected_start, $job_type );
			// More than 12 minutes late?
			if ( $time_now > $our_expected_start + 720 ) {
				$this->log( 'Long time past since expected resumption time: approx expected=' . round( $our_expected_start, 1 ) . ', now=' . round( $time_now, 1 ) . ', diff=' . round( $time_now - $our_expected_start, 1 ) );
				$this->log( __( 'A scheduled backup is running late. Check that WordPress cron or your server scheduler is running reliably.', 'backupplus' ), 'warning', 'infrequentvisits' );
			}
		}

		$this->jobdata_set( 'current_resumption', $resumption_no );

		$first_run = apply_filters( 'backupplus_filerun_firstrun', 0 );

		// April 2022: a similar situation is handled further down, but takes longer to kick in; so extra check has been added (a case where the first runtime under cli was > 4 hours was followed by running under cgi-fci with only 20 minute resumption times; it's better to detect this early)
		if ( $resumption_no == $first_run + 1 && $resume_interval >= 600 && '' != PHP_SAPI ) {

			$last_sapi = $this->jobdata_get( 'last_sapi' );

			if ( '' != $last_sapi && PHP_SAPI != $last_sapi ) {
				$resume_interval = $this->get_initial_resume_interval();
				$this->log( sprintf( 'Run environment has changed (%s -> %s) - resetting resumption interval to %d', $last_sapi, PHP_SAPI, $resume_interval ) );
				$this->jobdata_set( 'last_sapi', PHP_SAPI );
			}

			// We don't want to be in permanent conflict with the overlap detector
		} elseif ( $resumption_no >= $first_run + 8 && $resumption_no < $first_run + 15 && $resume_interval >= 300 ) {

			// $time_passed is set earlier
			list($max_time, $timings_string, $run_times_known) = BackupPlus_Manipulation_Functions::max_time_passed( $time_passed, $resumption_no - 1, $first_run );

			// Do this on resumption 8, or the first time that we have 6 data points. This is only done once to prevent any potential for back-and-forth.
			if ( ( $first_run + 8 == $resumption_no && $run_times_known >= 6 ) || ( 6 == $run_times_known && ! empty( $time_passed[ $prev_resumption ] ) ) ) {
				$this->log( "Time passed on previous resumptions: $timings_string (known: $run_times_known, max: $max_time)" );
				// Remember that 30 seconds is used as the 'perhaps something is still running' detection threshold, and that 45 seconds is used as the 'the next resumption is approaching - reschedule!' interval
				if ( $resume_interval > $max_time + 52 ) {
					$resume_interval = round( $max_time + 52 );
					$this->log( "Based on the available data, we are bringing the resumption interval down to: $resume_interval seconds" );
					$this->jobdata_set( 'resume_interval', $resume_interval );
				}
			} elseif ( isset( $time_passed[ $prev_resumption ] ) && $time_passed[ $prev_resumption ] > 50 && $resume_interval > 300 && $time_passed[ $prev_resumption ] < $resume_interval / 2 ) {
				// This next condition was added in response to HS#9174, a case where on one resumption, PHP was allowed to run for >3000 seconds - but other than that, up to 500 seconds. As a result, the resumption interval got stuck at a large value, whilst resumptions were only being allowed to run for a much smaller amount.
				// This detects whether our last run was less than half the resume interval,  but was non-trivial (at least 50 seconds - so, indicating it didn't just error out straight away), but with a resume interval of over 300 seconds. In this case, it is reduced.
				if ( 'clouduploading' == $this->jobdata_get( 'jobstatus' ) ) {
					$resume_interval = round( $time_passed[ $prev_resumption ] + 52 );
					$this->log( "Time passed on previous resumptions: $timings_string (known: $run_times_known, max: $max_time). Based on the available data, we are bringing the resumption interval down to: $resume_interval seconds" );
					$this->jobdata_set( 'resume_interval', $resume_interval );
				} elseif ( $run_times_known > 4 ) {
					// Added in response to the similar HS#66907 - in that case, resumption 0 ran for over an hour; nothing subsequently for more than ~3 minutes; and it didn't reach the uploading stage until resumption 19, so the previous fragment was not helping. The cause was that the backup initially started under WP-CLI, but then resumed through the web - different conditions led to different permitted run-times. (The user could also mitigate this by running WP-Cron in a CLI environment).
					$examined_values       = 0;
					$matching_values       = 0;
					$looking_at_resumption = $prev_resumption - 1;
					$largest_recent        = false;
					while ( $looking_at_resumption > 0 && $examined_values < 3 ) {
						if ( isset( $time_passed[ $looking_at_resumption ] ) ) {
							++$examined_values;
							if ( $time_passed[ $looking_at_resumption ] > 50 && $time_passed[ $looking_at_resumption ] < $resume_interval / 2 ) {
								++$matching_values;
								$largest_recent = max( $largest_recent, $time_passed[ $looking_at_resumption ] );
							}
						}
						--$looking_at_resumption;
					}
					// If the previous three found values were all less than half the resumption interval....
					if ( 3 == $examined_values && 3 == $matching_values ) {
						$resume_interval = round( $largest_recent + 52 );
						$this->log( "Time passed on previous resumptions: $timings_string (known: $run_times_known, max: $max_time). Based on the available data (most recent 3 resumptions compared to longest), we are bringing the resumption interval down to: $resume_interval seconds" );
						$this->jobdata_set( 'resume_interval', $resume_interval );
					}
				}
			}
		}

		// A different argument than before is needed otherwise the event is ignored
		$next_resumption = $resumption_no + 1;
		if ( $next_resumption < $first_run + 10 ) {
			if ( true === $this->jobdata_get( 'one_shot' ) ) {
				if ( true === $this->jobdata_get( 'reschedule_before_upload' ) && 1 == $next_resumption ) {
					$this->log( 'A resumption will be scheduled for the cloud backup stage' );
					$schedule_resumption = true;
				} else {
					$this->log( 'We are in "one shot" mode - no resumptions will be scheduled' );
				}
			} else {
				$schedule_resumption = true;
			}
		} else {

			// We're in over-time - we only reschedule if something useful happened last time (used to be that we waited for it to happen this time - but that meant that temporary errors, e.g. Google 400s on uploads, scuppered it all - we'd do better to have another chance
			// 'useful_checkin' is < 1.16.35 (Nov 2020). It is only supported here for resumptions that span upgrades. Later it can be removed.
			$useful_checkin = max( $this->jobdata_get( 'useful_checkin', 0 ), max( (array) $this->jobdata_get( 'useful_checkins', 0 ) ) );

			$last_resumption = $resumption_no - 1;
			$fail_on_resume  = $this->jobdata_get( 'fail_on_resume' );

			if ( empty( $useful_checkin ) || $useful_checkin < $last_resumption ) {
				if ( empty( $fail_on_resume ) ) {
					$this->log( sprintf( 'The current run is resumption number %d, and there was nothing useful done on the last run (last useful run: %s) - will not schedule a further attempt until we see something useful happening this time', $resumption_no, $useful_checkin ) );
					// Internally, we do actually schedule a resumption; but only in order to be able to nicely handle and log the failure, which otherwise may not be logged
					$this->jobdata_set( 'fail_on_resume', $next_resumption );
					$schedule_resumption = 1;
				}
			} else {
				// Something useful happened last time
				if ( ! empty( $fail_on_resume ) ) {
					$this->jobdata_delete( 'fail_on_resume' );
					$fail_on_resume = false;
				}
				$schedule_resumption = true;
			}

			if ( ! isset( $time_passed[ $prev_resumption ] ) ) {
				$this->no_checkin_last_time = true;
			}

			if ( ! empty( $fail_on_resume ) && $fail_on_resume == $this->current_resumption ) {
				$this->log( 'The backup is being aborted for a repeated failure to progress.', 'backupplus' );
				$this->log( __( 'The backup is being aborted for a repeated failure to progress.', 'backupplus' ), 'error' );
				$this->backup_finish( true, true );
				die;
			}
		}

		// Sanity check
		if ( empty( $this->backup_time ) ) {
			$this->log( 'The backup_time parameter is empty (usually caused by resuming an already-complete backup).' );
			return false;
		}

		if ( ! empty( $schedule_resumption ) ) {
			$schedule_for = time() + $resume_interval;
			if ( 1 === $schedule_resumption ) {
				$this->log( "Scheduling a resumption ($next_resumption) after $resume_interval seconds ($schedule_for); but the job will then be aborted unless something happens this time" );
				$this->resumption_scheduled_for_cleanup = true;
			} else {
				$this->log( "Scheduling a resumption ($next_resumption) after $resume_interval seconds ($schedule_for) in case this run gets aborted" );
			}
			wp_schedule_single_event( $schedule_for, 'backupplus_backup_resume', array( $next_resumption, $bnonce ) );
			$this->newresumption_scheduled = $schedule_for;
		}

		$backup_files = $this->jobdata_get( 'backup_files' );

		global $backupplus_backup;
		// Bring in all the backup routines
		backupplus_try_include_file( 'backup.php', 'include_once' );
		$backupplus_backup = new BackupPlus_Backup( $backup_files, apply_filters( 'backupplus_files_altered_since', -1, $job_type ) );

		$undone_files = array();

		if ( 'no' == $backup_files ) {
			$this->log( 'This backup run is not intended for files - skipping' );
			$our_files = array();
		} else {
			try {
				// This should be always called; if there were no files in this run, it returns us an empty array
				$backup_array = $backupplus_backup->resumable_backup_of_files( $resumption_no );
				if ( false === $backup_array ) {
					$this->log( 'File archive encryption failed; backup dispatch has been stopped.', 'error' );
					return false;
				}
				// This save, if there was something, is then immediately picked up again
				if ( is_array( $backup_array ) ) {
					$this->log( 'Saving backup status to database (elements: ' . count( $backup_array ) . ')' );
					$this->save_backup_to_history( $backup_array );
				}

				// Switch of variable name is purely vestigial
				$our_files = $backup_array;
				if ( ! is_array( $our_files ) ) {
					$our_files = array();
				}
			} catch ( Exception $e ) {
				$log_message = 'Exception (' . get_class( $e ) . ') occurred during files backup: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
				BackupPlus_Manipulation_Functions::error_log( $log_message );
				// @codingStandardsIgnoreLine
				$log_message .= ' Backtrace: ' . str_replace( array( ABSPATH, "\n" ), array( '', ', ' ), $e->getTraceAsString() );
				$this->log( $log_message );
				/* translators: 1: Exception class, 2: Error message */
				$this->log( sprintf( __( 'A PHP exception (%1$s) has occurred: %2$s', 'backupplus' ), get_class( $e ), $e->getMessage() ), 'error' );
				die();
			} catch ( Error $e ) { // phpcs:ignore PHPCompatibility.Classes.NewClasses.errorFound -- The Error class does not exist in PHP below 5.6.
				$log_message = 'PHP Fatal error (' . get_class( $e ) . ') has occurred. Error Message: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
				BackupPlus_Manipulation_Functions::error_log( $log_message );
				// @codingStandardsIgnoreLine
				$log_message .= ' Backtrace: ' . str_replace( array( ABSPATH, "\n" ), array( '', ', ' ), $e->getTraceAsString() );
				$this->log( $log_message );
				/* translators: 1: Exception class, 2: Error message */
				$this->log( sprintf( __( 'A PHP fatal error (%1$s) has occurred: %2$s', 'backupplus' ), get_class( $e ), $e->getMessage() ), 'error' );
				die();
			}
		}

		do_action( 'pre_database_backup_setup' );

		$backup_databases = $this->jobdata_get( 'backup_database' );

		if ( ! is_array( $backup_databases ) ) {
			$backup_databases = array( 'wp' => $backup_databases );
		}

		foreach ( $backup_databases as $whichdb => $backup_database ) {

			if ( is_array( $backup_database ) ) {
				$dbinfo          = $backup_database['dbinfo'];
				$backup_database = $backup_database['status'];
			} else {
				$dbinfo = array();
			}

			$tindex = ( 'wp' == $whichdb ) ? 'db' : 'db' . $whichdb;

			if ( 'begun' == $backup_database || 'finished' == $backup_database || 'encrypted' == $backup_database ) {

				if ( 'wp' == $whichdb ) {
					$db_descrip = 'WordPress DB';
				} else {
					if ( ! empty( $dbinfo ) && is_array( $dbinfo ) && ! empty( $dbinfo['host'] ) ) {
						$db_descrip = "External DB $whichdb - " . $dbinfo['user'] . '@' . $dbinfo['host'] . '/' . $dbinfo['name'];
					} else {
						$db_descrip = "External DB $whichdb - details are missing";
					}
				}

				if ( 'begun' == $backup_database ) {
					if ( $resumption_no > 0 ) {
						$this->log( "Resuming creation of database dump ($db_descrip)" );
					} else {
						$this->log( "Beginning creation of database dump ($db_descrip)" );
					}
				} elseif ( 'encrypted' == $backup_database ) {
					$this->log( "Database dump ($db_descrip): Creation and encryption were completed already" );
				} else {
					$this->log( "Database dump ($db_descrip): Creation was completed already" );
				}

				if ( 'wp' != $whichdb && ( empty( $dbinfo ) || ! is_array( $dbinfo ) || empty( $dbinfo['host'] ) ) ) {
					unset( $backup_databases[ $whichdb ] );
					$this->jobdata_set( 'backup_database', $backup_databases );
					continue;
				}

				// Catch fatal errors through try/catch blocks around the database backup
				try {
					$db_backup = $backupplus_backup->backup_db( $backup_database, $whichdb, $dbinfo );
				} catch ( Exception $e ) {
					$log_message = 'Exception (' . get_class( $e ) . ') occurred during files backup: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
					$this->log( $log_message );
					BackupPlus_Manipulation_Functions::error_log( $log_message );
					/* translators: 1: Exception class, 2: Error message */
					$this->log( sprintf( __( 'A PHP exception (%1$s) has occurred: %2$s', 'backupplus' ), get_class( $e ), $e->getMessage() ), 'error' );
					die();
				} catch ( Error $e ) { // phpcs:ignore PHPCompatibility.Classes.NewClasses.errorFound -- The Error class does not exist in PHP below 5.6.
					$log_message = 'PHP Fatal error (' . get_class( $e ) . ') has occurred. Error Message: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
					$this->log( $log_message );
					BackupPlus_Manipulation_Functions::error_log( $log_message );
					/* translators: 1: Exception class, 2: Error message */
					$this->log( sprintf( __( 'A PHP fatal error (%1$s) has occurred: %2$s', 'backupplus' ), get_class( $e ), $e->getMessage() ), 'error' );
					die();
				}

				if ( is_array( $our_files ) && is_string( $db_backup ) ) {
					$our_files[ $tindex ] = $db_backup;
				}

				if ( 'encrypted' != $backup_database ) {
					$backup_databases[ $whichdb ] = array(
						'status' => 'finished',
						'dbinfo' => $dbinfo,
					);
					$this->jobdata_set( 'backup_database', $backup_databases );
				}
			} elseif ( 'no' == $backup_database ) {
				$this->log( "No database backup ($whichdb) - not part of this run" );
			} else {
				$this->log( "Unrecognised data when trying to ascertain if the database ($whichdb) was backed up (" . serialize( $backup_database ) . ')' );
			}

			// This is done before cloud despatch, because we want a record of what *should* be in the backup. Whether it actually makes it there or not is not yet known.
			$this->save_backup_to_history( $our_files );

			// Potentially encrypt the database if it is not already
			if ( 'no' != $backup_database && isset( $our_files[ $tindex ] ) && ! preg_match( '/\.crypt$/', $our_files[ $tindex ] ) ) {
				$encrypted_file = $backupplus_backup->encrypt_file( $our_files[ $tindex ] );
				if ( false === $encrypted_file || is_wp_error( $encrypted_file ) ) {
					$this->log( 'Database encryption failed; backup dispatch has been stopped.', 'error' );
					return false;
				}
				$our_files[ $tindex ] = $encrypted_file;
				// No need to save backup history now, as it will happen in a few lines time
				if ( preg_match( '/\.crypt$/', $our_files[ $tindex ] ) ) {
					$backup_databases[ $whichdb ] = array(
						'status' => 'encrypted',
						'dbinfo' => $dbinfo,
					);
					$this->jobdata_set( 'backup_database', $backup_databases );
				}
			}

			if ( 'no' != $backup_database && isset( $our_files[ $tindex ] ) && file_exists( $backupplus_dir . '/' . $our_files[ $tindex ] ) ) {
				$our_files[ $tindex . '-size' ] = filesize( $backupplus_dir . '/' . $our_files[ $tindex ] );
				if ( BackupPlus_Encryption::is_file_encrypted( $our_files[ $tindex ] ) ) {
					foreach ( $this->which_checksums() as $checksum ) {
						$hash = hash_file( $checksum, $backupplus_dir . '/' . $our_files[ $tindex ] );
						if ( false === $hash ) {
							return false;
						}
						$this->jobdata_set( $checksum . '-' . $tindex . '0.crypt', $hash );
					}
				}
				$this->save_backup_to_history( $our_files );
			}
			if ( isset( $our_files[ $tindex ] ) && BackupPlus_Encryption::is_file_encrypted( $our_files[ $tindex ] ) ) {
				if ( ! is_file( $backupplus_dir . '/' . $our_files[ $tindex ] ) && ! $this->is_uploaded( $our_files[ $tindex ] ) ) {
					$this->log( 'The encrypted database archive is unavailable; backup dispatch has been stopped.', 'error' );
					return false;
				}
				$plaintext = $backupplus_dir . '/' . substr( $our_files[ $tindex ], 0, -6 );
				if ( ( is_file( $plaintext ) || is_link( $plaintext ) ) && ! unlink( $plaintext ) ) {
					$this->log( 'Could not remove the plaintext database archive; backup dispatch has been stopped.', 'error' );
					return false;
				}
			}
		}

		$backupable_entities = $this->get_backupable_file_entities( true );

		$checksum_list = $this->which_checksums();

		$checksums = array();

		foreach ( $checksum_list as $checksum ) {
			$checksums[ $checksum ] = array();
		}

		$total_size = 0;

		$service = $this->just_one( $this->jobdata_get( 'service' ) );
		if ( empty( $service ) ) {
			$message = 'This file has already been successfully processed';
		} else {
			$message = 'This file has already been successfully uploaded';
		}

		// Queue files for upload
		foreach ( $our_files as $key => $files ) {
			// Only continue if the stored info was about a dump
			if ( ! isset( $backupable_entities[ $key ] ) && ( 'db' != substr( $key, 0, 2 ) || '-size' == substr( $key, -5, 5 ) ) ) {
				continue;
			}
			if ( is_string( $files ) ) {
				$files = array( $files );
			}
			foreach ( $files as $findex => $file ) {

				$size_key   = ( 0 == $findex ) ? $key . '-size' : $key . $findex . '-size';
				$total_size = ( false === $total_size || ! isset( $our_files[ $size_key ] ) || ! is_numeric( $our_files[ $size_key ] ) ) ? false : $total_size + $our_files[ $size_key ];

				foreach ( $checksum_list as $checksum ) {

					$cksum = $this->jobdata_get( $checksum . '-' . $key . $findex );
					if ( $cksum ) {
						$checksums[ $checksum ][ $key . $findex ] = $cksum;
					}
					$cksum = $this->jobdata_get( $checksum . '-' . $key . $findex . '.crypt' );
					if ( $cksum ) {
						$checksums[ $checksum ][ $key . $findex . '.crypt' ] = $cksum;
					}
				}

				if ( ! $this->is_ours_to_upload( $file, $key ) && 'db' !== $key ) {
					continue;
				}
				if ( $this->is_uploaded( $file ) ) {
					$this->log( "$file: $key: $message" );
				} elseif ( is_file( $backupplus_dir . '/' . $file ) ) {
					if ( ! in_array( $file, $undone_files ) ) {
						$this->log( "$file: $key: This file has not yet been successfully uploaded: will queue" );
						$undone_files[ $key . $findex ] = $file;
					} else {
						$this->log( "$file: $key: This file was already queued for upload (this condition should never be seen)" );
					}
				} elseif ( ! $this->is_ours_to_upload( $file, $key ) ) {
					$this->log( "$file: $key: This file is not ours to upload and has been/will be handled by another job." );
				} else {
					$this->log( "$file: $key: Note: This file was not marked as successfully uploaded, but does not exist on the local filesystem; now marking as uploaded ($backupplus_dir/$file)" );
					$this->uploaded_file( $file, true );
				}
			}
		}
		$our_files['checksums'] = $checksums;

		// Save again (now that we have checksums)
		$size_description = ( false === $total_size ) ? 'Unknown' : BackupPlus_Manipulation_Functions::convert_numeric_size_to_text( $total_size );
		$this->log( "Saving backup history. Total backup size: $size_description" );
		$this->save_backup_to_history( $our_files );
		do_action( 'backupplus_final_backup_history', $our_files );

		// We finished; so, low memory was not a problem
		$this->log_remove_warning( 'lowram' );

		if ( 0 == count( $undone_files ) ) {
			$this->log( "Resume backup ($bnonce, $resumption_no): finish run" );
			if ( is_array( $our_files ) ) {
				$this->save_last_backup( $our_files );
			}
			$this->log( 'There were no more files that needed uploading' );
			// No email, as the user probably already got one if something else completed the run
			$allow_email = false;
			if ( 'begun' == $this->jobdata_get( 'prune' ) ) {
				// Begun, but not finished
				$this->log( 'Restarting backup prune operation' );
				$backupplus_backup->do_prune_standalone();
				$allow_email = true;
			} elseif ( 'finished' != $this->jobdata_get( 'prune' ) ) {
				// If prune has not begun or finished but we have undone files then start it
				$backupplus_backup->do_prune_standalone();
				$allow_email = true;
			}

			$this->check_upload_completed();

			$this->backup_finish( true, $allow_email );
			restore_error_handler();
			return;
		}

		$this->error_count_before_cloud_backup = $this->error_count();

		// This is intended for one-shot backups, where we do want a resumption if it's only for uploading
		if ( empty( $this->newresumption_scheduled ) && 0 == $resumption_no && 0 == $this->error_count_before_cloud_backup && true === $this->jobdata_get( 'reschedule_before_upload' ) ) {
			$this->log( 'Cloud backup stage reached on one-shot backup: scheduling resumption for the cloud upload' );
			BackupPlus_Job_Scheduler::reschedule( 60 );
			BackupPlus_Job_Scheduler::record_still_alive();
		}

		$this->log( 'Requesting upload of the files that have not yet been successfully uploaded (' . count( $undone_files ) . ')' );
		// Catch fatal errors through try/catch blocks around the  upload to remote storage
		$backupplus_backup->cloud_backup( $undone_files );

		$this->log( "Resume backup ($bnonce, $resumption_no): finish run" );
		if ( is_array( $our_files ) ) {
			$this->save_last_backup( $our_files );
		}
		$this->backup_finish( true, true );

		restore_error_handler();
	}

	/**
	 * Get all the job data in a single array
	 *
	 * @param String $job_id - the job identifier (nonce) for the job whose data is to be retrieved
	 *
	 * @return Array
	 */
	public function jobdata_getarray( $job_id ) {
		return $this->read_jobdata( $job_id );
	}

	private function read_jobdata( $job_id ) {
		$data = get_site_option( 'backupplus_jobdata_' . $job_id, array() );
		return class_exists( 'BackupPlus_Credential_Storage' ) ? BackupPlus_Credential_Storage::job( $data, $job_id, 'decrypt' ) : $data;
	}

	private function save_jobdata() {
		$data = class_exists( 'BackupPlus_Credential_Storage' ) ? BackupPlus_Credential_Storage::job( $this->jobdata, $this->nonce, 'encrypt' ) : $this->jobdata;
		return update_site_option( 'backupplus_jobdata_' . $this->nonce, $data );
	}

	public function jobdata_set_from_array( $array ) {
		$this->jobdata = $array;
		if ( ! empty( $this->nonce ) ) {
			$this->save_jobdata();
		}
	}

	/**
	 * This works with any amount of settings, but we provide also a jobdata_set for efficiency as normally there's only one setting
	 * You can list the keys/values (keys must be strings) as consecutive/alternating parameters, or send them all in as an array (with no other parameters)
	 *
	 * @return null
	 */
	public function jobdata_set_multi() {
		if ( ! is_array( $this->jobdata ) ) {
			$this->jobdata = empty( $this->nonce ) ? array() : $this->read_jobdata( $this->nonce );
		}
		if ( ! is_array( $this->jobdata ) ) {
			$this->jobdata = array();
		}

		$args = func_num_args();

		// func_get_arg() could not be used in parameter lists prior to PHP 5.3, so, we get it as a variable
		if ( 1 == $args && null !== ( $first_arg = func_get_arg( 0 ) ) && is_array( $first_arg ) ) {
			foreach ( $first_arg as $key => $value ) {
				$this->jobdata[ $key ] = $value;
			}
		} else {

			for ( $i = 1; $i <= $args / 2; $i++ ) {
				$key                   = func_get_arg( $i * 2 - 2 );
				$value                 = func_get_arg( $i * 2 - 1 );
				$this->jobdata[ $key ] = $value;
			}
		}
		if ( ! empty( $this->nonce ) ) {
			$this->save_jobdata();
		}
	}

	/**
	 * Set a job-data key/value pair for the current job
	 *
	 * @param String $key   - the key
	 * @param Mixed  $value - needs to be serializable
	 *
	 * @uses update_site_option()
	 */
	public function jobdata_set( $key, $value ) {
		if ( empty( $this->jobdata ) ) {
			$this->jobdata = empty( $this->nonce ) ? array() : $this->read_jobdata( $this->nonce );
			if ( ! is_array( $this->jobdata ) ) {
				$this->jobdata = array();
			}
		}
		$this->jobdata[ $key ] = $value;
		if ( $this->nonce ) {
			$this->save_jobdata();
		}
	}

	/**
	 * Delete a jobdata item, by key
	 *
	 * @param String $key
	 */
	public function jobdata_delete( $key ) {
		if ( ! is_array( $this->jobdata ) ) {
			$this->jobdata = empty( $this->nonce ) ? array() : $this->read_jobdata( $this->nonce );
			if ( ! is_array( $this->jobdata ) ) {
				$this->jobdata = array();
			}
		}
		unset( $this->jobdata[ $key ] );
		if ( $this->nonce ) {
			$this->save_jobdata();
		}
	}

	public function get_job_option( $opt ) {
		// These are meant to be read-only
		if ( empty( $this->jobdata['option_cache'] ) || ! is_array( $this->jobdata['option_cache'] ) ) {
			if ( ! is_array( $this->jobdata ) && $this->nonce ) {
				$this->jobdata = $this->read_jobdata( $this->nonce );
			}
			if ( empty( $this->jobdata['option_cache'] ) || ! is_array( $this->jobdata['option_cache'] ) ) {
				$this->jobdata['option_cache'] = array();
			}
		}
		return isset( $this->jobdata['option_cache'][ $opt ] ) ? $this->jobdata['option_cache'][ $opt ] : BackupPlus_Options::get_backupplus_option( $opt );
	}

	/**
	 * Get a job data item, or the specified default if it is not yet set
	 *
	 * @param String $key
	 * @param Mixed  $default
	 *
	 * @return Mixed
	 */
	public function jobdata_get( $key, $default = null ) {
		if ( empty( $this->jobdata ) ) {
			$this->jobdata = empty( $this->nonce ) ? array() : $this->read_jobdata( $this->nonce );
			if ( ! is_array( $this->jobdata ) ) {
				return $default;
			}
		}
		return isset( $this->jobdata[ $key ] ) ? $this->jobdata[ $key ] : $default;
	}

	/**
	 * Reset the job data for the currently active job (forcing a re-fetch from the database, if there is any)
	 */
	public function jobdata_reset() {
		$this->jobdata = null;
	}

	/**
	 * This function will update the database backup jobdata and set each entity to finished or encrypted to prevent that entity from being backed up again. This will also return the blog name that the database backup belongs to, just in case it's from another site.
	 *
	 * @param array $db_backups - the database backup jobdata
	 * @param array $backup     - the backup history for this backup
	 *
	 * @return array            - an array that contains the updated database backup jobdata and the blog name
	 */
	public function update_database_jobdata( $db_backups, $backup ) {

		$backup_database_info = array(
			'blog_name'  => '',
			'db_backups' => $db_backups,
		);

		if ( ! is_array( $db_backups ) ) {
			return $backup_database_info;
		}

		/*
			We need to tweak the database array here by setting each database entity to finished or encrypted if it's an encrypted archive.
			I also grab the backups blog name here ready to be used later, just in case this backup set is from another site.
		*/
		foreach ( $db_backups as $key => $db_info ) {
			$status   = 'finished';
			$db_index = ( 'wp' == $key ) ? '' : $key;

			if ( isset( $backup[ 'db' . $db_index ] ) ) {
				$db_backup_name = $backup[ 'db' . $db_index ];

				if ( preg_match( '/^backup_([\-0-9]{15})_(.*)_([0-9a-f]{12})-[\-a-z]+([0-9]+)?+(\.(zip|zip\.crypt|gz|gz\.crypt))?$/i', $db_backup_name, $matches ) ) {
					$backup_database_info['blog_name'] = $matches[2];
				}

				if ( BackupPlus_Encryption::is_file_encrypted( $db_backup_name ) ) {
					$status = 'encrypted';
				}

				if ( is_array( $db_info ) && isset( $db_info['status'] ) ) {
					$db_backups[ $key ]['status'] = $status;
				} else {
					$db_backups[ $key ] = $status;
				}
			} else {
				unset( $db_backups[ $key ] );
			}
		}

		$backup_database_info['db_backups'] = $db_backups;

		return $backup_database_info;
	}

	/**
	 * This function will update the files backup jobdata by constructing the backup entities and their sizes from the backup
	 *
	 * @param array $backup        - the backup array
	 * @param array $skip_entities - an array of entities to skip
	 *
	 * @return array - the files backup array
	 */
	public function update_files_jobdata( $backup, $skip_entities = array() ) {

		$file_backups        = array();
		$backupable_entities = $this->get_backupable_file_entities( true );

		// We need to construct the expected files array here, this gets added to the jobdata much later in the backup process but we need this before we start
		foreach ( $backupable_entities as $entity => $path ) {
			if ( in_array( $entity, $skip_entities ) ) {
				continue;
			}
			if ( isset( $backup[ $entity ] ) ) {
				$file_backups[ $entity ] = $backup[ $entity ];
			}
			if ( isset( $backup[ $entity . '-size' ] ) ) {
				$file_backups[ $entity . '-size' ] = $backup[ $entity . '-size' ];
			}
		}

		return $file_backups;
	}

	/**
	 * Start a files backup (used by WP cron)
	 */
	public function backup_files() {
		// Note that the "false" for database gets over-ridden automatically if they turn out to have the same schedules
		$this->boot_backup( true, false );
	}

	/** Run the selected incremental schedule, promoting to full whenever required. */
	public function backup_increments() {
		$interval = BackupPlus_Options::get_backupplus_option( 'backupplus_interval_increments', 'none' );
		if ( 'none' !== $interval && ! empty( $interval ) ) {
			$this->boot_backup(
				1,
				0,
				false,
				false,
				false,
				array(
					'incremental'           => true,
					'scheduled_incremental' => true,
				)
			);
		}
	}

	/**
	 * Start a database backup (used by WP cron)
	 */
	public function backup_database() {
		// Note that nothing will happen if the file backup had the same schedule
		$this->boot_backup( false, true );
	}

	/**
	 * Start a files + database backup (used by users manually in WP cron, and 'Backup Now')
	 *
	 * @param array $options
	 * @return Boolean|Void - as for BackupPlus::boot_backup()
	 */
	public function backup_all( $options = array() ) {
		if ( ! is_array( $options ) ) {
			$options = array();
		}
		$skip_cloud = empty( $options['nocloud'] ) ? false : true;
		return $this->boot_backup( 1, 1, false, false, $skip_cloud ? 'none' : false, $options );
	}

	/**
	 * Start a files backup
	 *
	 * @param array $options
	 * @return Boolean|Void - as for BackupPlus::boot_backup()
	 */
	public function backupnow_files( $options = array() ) {
		$skip_cloud = empty( $options['nocloud'] ) ? false : true;
		return $this->boot_backup( 1, 0, false, false, $skip_cloud ? 'none' : false, $options );
	}

	/**
	 * Start a files backup
	 *
	 * @param array $options
	 * @return Boolean|Void - as for BackupPlus::boot_backup()
	 */
	public function backupnow_database( $options = array() ) {
		$skip_cloud = empty( $options['nocloud'] ) ? false : true;
		return $this->boot_backup( 0, 1, false, false, ( $skip_cloud ) ? 'none' : false, $options );
	}

	/**
	 * This function will try and get a lock for the backup, it will return false if it fails to get a lock.
	 *
	 * @param Boolean $backup_files    - boolean to indicate if we want a lock for files
	 * @param Boolean $backup_database - boolean to indicate if we want a lock for the database
	 *
	 * @return boolean                 - boolean to indicate if we got a lock or not
	 */
	public function get_semaphore_lock( $backup_files, $backup_database ) {

		$semaphore = ( $backup_files ? 'f' : '' ) . ( $backup_database ? 'd' : '' );

		if ( ! class_exists( 'BackupPlus_Semaphore' ) ) {
			backupplus_try_include_file( 'includes/class-semaphore.php', 'include_once' );
		}

		BackupPlus_Semaphore::ensure_semaphore_exists( $semaphore );

		// Are we doing an action called by the WP scheduler? If so, we want to check when that last happened; the point being that the dodgy WP scheduler, when overloaded, can call the event multiple times - and sometimes, it evades the semaphore because it calls a second run after the first has finished, or > 3 minutes (our semaphore lock time) later
		// doing_action() was added in WP 3.9
		// wp_cron() can be called from the 'init' action

		if ( function_exists( 'doing_action' ) && ( doing_action( 'init' ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) && ( doing_action( 'backupplus_backup_database' ) || doing_action( 'backupplus_backup' ) ) ) {// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			$last_scheduled_action_called_at = get_option( "backupplus_last_scheduled_$semaphore" );
			// 11 minutes - so, we're assuming that they haven't custom-modified their schedules to run scheduled backups more often than that. If they have, they need also to use the filter to over-ride this check.
			$seconds_ago = time() - $last_scheduled_action_called_at;
			if ( $last_scheduled_action_called_at && $seconds_ago < 660 && apply_filters( 'backupplus_check_repeated_scheduled_backups', true ) ) {
				$this->log( sprintf( 'Scheduled backup aborted - another backup of this type was apparently invoked by the WordPress scheduler only %d seconds ago - the WordPress scheduler invoking events multiple times usually indicates a very overloaded server (or other plugins that mis-use the scheduler)', $seconds_ago ) );
				return false;
			}
		}

		update_option( "backupplus_last_scheduled_$semaphore", time() );

		$this->semaphore            = BackupPlus_Semaphore::factory();
		$this->semaphore->lock_name = $semaphore;

		$semaphore_log_message = 'Requesting semaphore lock (' . $semaphore . ')';
		if ( ! empty( $last_scheduled_action_called_at ) ) {
			$semaphore_log_message .= " (apparently via scheduler: last_scheduled_action_called_at=$last_scheduled_action_called_at, seconds_ago=$seconds_ago)";
		} else {
			$semaphore_log_message .= ' (apparently not via scheduler)';
		}

		$this->log( $semaphore_log_message );
		if ( ! $this->semaphore->lock() ) {
			$this->log( 'Failed to gain semaphore lock (' . $semaphore . ') - another backup of this type is apparently already active - aborting (if this is wrong - i.e. if the other backup crashed without removing the lock, then another can be started after 3 minutes)' );
			return false;
		}

		return true;
	}

	/**
	 * This function will try and get a lock for the backup job, it will return false if it fails to get a lock.
	 *
	 * @param String  $job_nonce     - the backup job nonce
	 * @param Integer $resumption_no - the current resumption
	 *
	 * @return boolean - boolean to indicate if we got a lock or not
	 */
	public function get_backup_job_semaphore_lock( $job_nonce, $resumption_no ) {

		$semaphore = 'udp_backupjob_' . $job_nonce;

		if ( ! class_exists( 'BackupPlus_Semaphore_3_0' ) ) {
			backupplus_try_include_file( 'includes/class-backupplus-semaphore.php', 'include_once' );
		}

		if ( empty( $this->backup_semaphore ) ) {
			$this->backup_semaphore = new BackupPlus_Semaphore_3_0( $semaphore, 30, array( $this ) );
		}

		if ( 1 <= $resumption_no ) {

			$this->log( 'Requesting backup semaphore lock (' . $semaphore . ')' );

			if ( ! $this->backup_semaphore->lock() ) {
				$this->log( 'Failed to gain semaphore lock (' . $semaphore . ') - another resumption for this job is apparently already active' );
				return false;
			}
		}

		return true;
	}

	/**
	 * This function will check to see if any of the known backups are still running and return true otherwise returns false.
	 *
	 * @return boolean|string - returns false if no backup is running or a error code if there is a backup running
	 */
	public function is_backup_running() {

		$backup_history = BackupPlus_Backup_History::get_history();

		foreach ( $backup_history as $backup ) {
			$nonce = $backup['nonce'];

			// Check the job is not still running.
			$jobdata = $this->jobdata_getarray( $nonce );

			if ( ! empty( $jobdata ) && 'finished' != $jobdata['jobstatus'] ) {

				// Check that there is not a resumption scheduled
				if ( wp_next_scheduled( 'backupplus_backup_resume' ) ) {
					return 'job_resumption_scheduled';
				}

				$time_passed = $jobdata['run_times'];

				// No runtime found so return
				if ( ! is_array( $time_passed ) ) {
					return "job_scheduled_{$nonce}_no_run_times";
				}

				// Runtime has been found so make sure last activity is over an hour
				$time_passed = end( $time_passed );
				if ( strtotime( $time_passed ) <= time() - ( 3600 ) ) {
					continue;
				}

				return "job_scheduled_{$nonce}_run_time_activity";
			}
		}

		return false;
	}

	/**
	 * This function is a filter function which will return the nonce for the incremental backup set we want to add to
	 *
	 * @param String $nonce - the backup nonce we want to filter
	 *
	 * @return string       - the backup nonce
	 */
	public function incremental_backup_file_nonce( $nonce ) {
		if ( apply_filters( 'backupplus_incremental_addon_installed', false ) && ! empty( $this->file_nonce ) ) {
			return $this->file_nonce;
		}
		return $nonce;
	}

	/**
	 * Get the initial resumption interval, in seconds
	 *
	 * @return Integer
	 */
	private function get_initial_resume_interval() {
		// Allow the resume interval to be more than 300 if last time we know we went beyond that - but never more than 600
		if ( defined( 'BACKUPPLUS_INITIAL_RESUME_INTERVAL' ) && is_numeric( BACKUPPLUS_INITIAL_RESUME_INTERVAL ) ) {
			$resume_interval = BACKUPPLUS_INITIAL_RESUME_INTERVAL;
		} else {
			$resume_interval = (int) min( max( 300, get_site_transient( 'backupplus_initial_resume_interval' ) ), 600 );
		}
		// We delete it because we only want to know about behaviour found during the very last backup run (so, if you move servers then old data is not retained)
		delete_site_transient( 'backupplus_initial_resume_interval' );
		return $resume_interval;
	}

	/**
	 * This procedure initiates a backup run
	 * $backup_files/$backup_database: true/false = yes/no (over-write allowed); 1/0 = yes/no (force)
	 *
	 * @param  Boolean|Integer      $backup_files
	 * @param  Boolean|Integer      $backup_database
	 * @param  Boolean|Array        $restrict_files_to_override
	 * @param  Boolean              $one_shot
	 * @param  Boolean|Array|String $service
	 * @param  Array                $options
	 *
	 * @return Boolean|Void - false indicates definite failure; true indicates a job was started and ran through as far as possible on this resumption. Note that you should not expect this method to return at all, depending on how long the backup takes, and available PHP run time, etc. In case of failure, currently there may or may not be information logged, and it may or may not be logged at the 'error' level. If more precise feedback is needed, then this can be improved. Void is currently used if no backup was started because none was needed.
	 */
	public function boot_backup( $backup_files, $backup_database, $restrict_files_to_override = false, $one_shot = false, $service = false, $options = array() ) {

		if ( function_exists( 'ignore_user_abort' ) ) {
			@ignore_user_abort( true );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}       if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( BACKUPPLUS_SET_TIME_LIMIT );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}
		$is_scheduled_backup = is_bool( $backup_files ) || is_bool( $backup_database ) || ! empty( $options['scheduled_incremental'] );

		$hosting_company = $this->get_hosting_info();
		if ( ! empty( $options['incremental'] ) && in_array( 'only_one_incremental_per_day', $this->is_hosting_backup_limit_reached() ) ) {
			$this->log(
				__( 'You have reached the daily limit for the number of incremental backups you can create at this time.', 'backupplus' ) . ' ' .
				__( ' Your hosting provider only allows you to take one incremental backup per day.', 'backupplus' ) . ' ' .
				sprintf(
					/* translators: %s: Hosting provider name */
					__( 'Please contact your hosting company (%s) if you require further support.', 'backupplus' ),
					$hosting_company['name']
				)
			);
			return false;
		} elseif ( empty( $options['incremental'] ) && in_array( 'only_one_backup_per_month', $this->is_hosting_backup_limit_reached() ) ) {
			$this->log(
				__( 'You have reached the monthly limit for the number of backups you can create at this time.', 'backupplus' ) . ' ' .
				__( 'Your hosting provider only allows you to take one backup per month.', 'backupplus' ) . ' ' .
				sprintf(
					/* translators: %s: Hosting provider name */
					__( 'Please contact your hosting company (%s) if you require further support.', 'backupplus' ),
					$hosting_company['name']
				)
			);
			return false;
		}

		if ( false === $restrict_files_to_override && isset( $options['restrict_files_to_override'] ) ) {
			$restrict_files_to_override = $options['restrict_files_to_override'];
		}
		// Generate backup information
		$use_nonce     = empty( $options['use_nonce'] ) ? false : $options['use_nonce'];
		$use_timestamp = empty( $options['use_timestamp'] ) ? false : $options['use_timestamp'];
		$this->backup_time_nonce( $use_nonce, $use_timestamp );
		// The current_resumption is consulted within logfile_open()
		$this->current_resumption = 0;
		$this->logfile_open( $this->nonce );

		if ( ! is_file( $this->logfile_name ) ) {
			$this->log( 'Failed to open log file (' . $this->logfile_name . ') - you need to check your BackupPlus settings (your chosen directory for creating files in is not writable, or you ran out of disk space). Backup aborted.' );
			$this->log( __( 'Could not create files in the backup directory.', 'backupplus' ) . ' ' . __( 'Backup aborted - check your BackupPlus settings.', 'backupplus' ), 'error' );

			if ( ! defined( 'BACKUPPLUS_SEND_UNWRITABLE_BACKUP_DIRECTORY_EMAIL' ) || BACKUPPLUS_SEND_UNWRITABLE_BACKUP_DIRECTORY_EMAIL ) {
				$final_message = __( 'BackupPlus is unable to perform backups as your backup directory is not writable or the disk space is full.', 'backupplus' ) . ' ' . __( 'Please check the backup directory and ensure it is writable so that backups may continue.', 'backupplus' );
				$this->send_results_email( $final_message, $this->jobdata );
			}

			return false;
		}

		// Some house-cleaning
		BackupPlus_Filesystem_Functions::clean_temporary_files();

		// Log some information that may be helpful
		$this->log( "Tasks: Backup files: $backup_files (schedule: " . BackupPlus_Options::get_backupplus_option( 'backupplus_interval', 'unset' ) . ") Backup DB: $backup_database (schedule: " . BackupPlus_Options::get_backupplus_option( 'backupplus_interval_database', 'unset' ) . ')' );

		// The is_bool() check here is confirming that we're allowed to adjust the parameters
		if ( false === $one_shot && is_bool( $backup_database ) ) {
			// If the files and database schedules are the same, and if this the file one, then we rope in database too.
			// On the other hand, if the schedules were the same and this was the database run, then there is nothing to do.

			$files_schedule = BackupPlus_Options::get_backupplus_option( 'backupplus_interval' );
			$db_schedule    = BackupPlus_Options::get_backupplus_option( 'backupplus_interval_database' );

			$sched_log_extra = '';

			if ( 'manual' != $files_schedule && false !== $files_schedule ) {
				if ( $files_schedule == $db_schedule || BackupPlus_Options::get_backupplus_option( 'backupplus_interval_database', 'xyz' ) == 'xyz' ) {
					$sched_log_extra = 'Combining jobs from identical schedules. ';
					$backup_database = ( true == $backup_files ) ? true : false;
				} elseif ( $files_schedule && $db_schedule && $files_schedule != $db_schedule ) {

					// This stored value is the earliest of the two apparently-close jobs
					$combine_around = empty( $this->combine_jobs_around ) ? false : $this->combine_jobs_around;

					if ( preg_match( '/^(cancel:)?(\d+)$/', $combine_around, $matches ) ) {

						$combine_around = $matches[2];

						// Re-save the option, since otherwise it will have been reset and not be accessible to the 'other' run
						BackupPlus_Options::update_backupplus_option( 'backupplus_combine_jobs_around', 'cancel:' . $this->combine_jobs_around );

						$margin = ( defined( 'BACKUPPLUS_COMBINE_MARGIN' ) && is_numeric( BACKUPPLUS_COMBINE_MARGIN ) ) ? BACKUPPLUS_COMBINE_MARGIN : 600;

						$time_now = time();

						// The margin is doubled, to cope with the lack of predictability in WP's cron system
						if ( $time_now >= $combine_around && $time_now <= $combine_around + 2 * $margin ) {

							$sched_log_extra = 'Combining jobs from co-inciding events. ';

							if ( 'cancel:' == $matches[1] ) {
								$backup_database = false;
								$backup_files    = false;
							} else {
								// We want them both to happen on whichever run is first (since, afterwards, the backupplus_combine_jobs_around option will have been removed when the event is rescheduled).
								$backup_database = true;
								$backup_files    = true;
							}
						}
					}
				}
			}
			$this->log( "Processed schedules. {$sched_log_extra}Tasks now: Backup files: $backup_files Backup DB: $backup_database" );
		}

		if ( false == apply_filters( 'backupplus_boot_backup', true, $backup_files, $backup_database, $one_shot ) ) {
			$this->log( 'Backup aborted (via filter)' );
			return false;
		}

		$enabled_storage_objects_and_ids = array();

		// All scheduled backups will go through this condition (and some others may too)
		// This section sets up default options, filters services/instances, and populates $options['remote_storage_instances']
		if ( ! is_string( $service ) && ! is_array( $service ) ) {
			$all_services = ! empty( $options['remote_storage_instances'] ) ? array_keys( $options['remote_storage_instances'] ) : BackupPlus_Options::get_backupplus_option( 'backupplus_service' );
			if ( is_string( $all_services ) ) {
				$all_services = (array) $all_services;
			}

			$enabled_storage_objects_and_ids = BackupPlus_Storage_Methods_Interface::get_enabled_storage_objects_and_ids( $all_services );
			$legacy_storage_instances        = array();

			if ( ! isset( $options['remote_storage_instances'] ) ) {

				$remote_storage_instances = array();

				foreach ( $enabled_storage_objects_and_ids as $method_id => $method_info ) {

					if ( $method_info['object']->supports_feature( 'multi_options' ) ) {
						foreach ( $method_info['instance_settings'] as $instance_id => $instance_settings ) {
							// We already know the instance is enabled, as we only selected those. We just want to give add-ons an opportunity to filter it.

							if ( ! apply_filters( 'backupplus_boot_backup_remote_storage_instance_include', true, $instance_settings, $method_id, $instance_id, $is_scheduled_backup ) ) {
								continue;
							}

							if ( ! isset( $remote_storage_instances[ $method_id ] ) ) {
								$remote_storage_instances[ $method_id ] = array();
							}

							$remote_storage_instances[ $method_id ][] = $instance_id;
						}
					} else {
						$legacy_storage_instances[] = $method_id;
					}
				}

				$options['remote_storage_instances'] = $remote_storage_instances;
			}

			$service = array_merge( array_keys( $options['remote_storage_instances'] ), $legacy_storage_instances );
		}

		$service = $this->just_one( $service );
		$service = $this->get_canonical_service_list( $service );

		$canonised_storage_objects_and_ids = array_merge( array_flip( $service ), $enabled_storage_objects_and_ids );
		$option_cache                      = array();

		foreach ( $canonised_storage_objects_and_ids as $method_id => $method_info ) {
			// Explained at backupplus/-/merge_requests/1302#note_206636
			if ( ! is_array( $method_info ) ) {
				$canonised_storage_objects_and_ids[ $method_id ] = $method_info = array();
			}
			if ( ! isset( $method_info['object'] ) || ! is_object( $method_info['object'] ) ) {
				$object = BackupPlus_Storage_Provider_Factory::create( $method_id, false );
				if ( ! is_wp_error( $object ) ) {
					$method_info['object'] = $canonised_storage_objects_and_ids[ $method_id ]['object'] = $object;
				} else {
					BackupPlus_Manipulation_Functions::error_log( "BackupPlus: storage provider unavailable: $method_id" );
				}
			}
			if ( isset( $method_info['object'] ) && is_object( $method_info['object'] ) && is_callable( array( $method_info['object'], 'get_credentials' ) ) ) {
				$opts = $method_info['object']->get_credentials();
				if ( is_array( $opts ) ) {
					foreach ( $opts as $opt ) {
						$option_cache[ $opt ] = BackupPlus_Options::get_backupplus_option( $opt );
					}
				}
			}
		}
		$option_cache = apply_filters( 'backupplus_job_option_cache', $option_cache );

		// If nothing to be done, then just finish
		if ( ! $backup_files && ! $backup_database ) {
			$ret = $this->backup_finish( false, false );
			// Don't keep useless log files
			if ( ! BackupPlus_Options::get_backupplus_option( 'backupplus_debug_mode' ) && ! empty( $this->logfile_name ) && file_exists( $this->logfile_name ) ) {
				unlink( $this->logfile_name );
			}
			// Currently backup_finish() appears to have a void return. We don't want to return false, as that indicates failure. But neither was it really a success. Void seems fine for now, given that nothing is currently using it.
			return $ret;
		}

		if ( ! $this->get_semaphore_lock( $backup_files, $backup_database ) ) {
			// get_semaphore_lock() already does some of its own logging (though not currently (Nov 2019) at 'error' level)
			return false;
		}

		$resume_interval = $this->get_initial_resume_interval();

		$job_file_entities = array();
		if ( $backup_files ) {
			$possible_backups = $this->get_backupable_file_entities( true );
			foreach ( $possible_backups as $youwhat => $whichdir ) {
				if ( ( false === $restrict_files_to_override && BackupPlus_Options::get_backupplus_option( "backupplus_include_$youwhat", apply_filters( "backupplus_defaultoption_include_$youwhat", true ) ) ) || ( is_array( $restrict_files_to_override ) && in_array( $youwhat, $restrict_files_to_override ) ) ) {
					// The 0 indicates the zip file index
					$job_file_entities[ $youwhat ] = array(
						'index' => 0,
					);
				}
			}
		}

		$incremental_configuration = BackupPlus_Incremental_Backups::configuration( array_keys( $job_file_entities ), $service, isset( $options['remote_storage_instances'] ) ? $options['remote_storage_instances'] : array(), $option_cache );
		$incremental_base          = ! empty( $options['incremental'] ) && ! empty( $job_file_entities ) ? BackupPlus_Incremental_Backups::find_base( array_keys( $job_file_entities ), $incremental_configuration ) : false;
		if ( $incremental_base ) {
			$this->file_nonce = $incremental_base['nonce'];
			foreach ( $job_file_entities as $entity => &$entity_info ) {
				$entity_info['index'] = max( array_keys( (array) $incremental_base[ $entity ] ) ) + 1;
			}
			unset( $entity_info );
			$this->log( 'Adding changed files to backup chain ' . $this->file_nonce );
		} elseif ( ! empty( $options['incremental'] ) ) {
			$this->log( 'Taking a full backup: no completed compatible base less than seven days old is available.' );
		}

		$followups_allowed = ( ( ( ! $one_shot && defined( 'DOING_CRON' ) && DOING_CRON ) ) || ( defined( 'BACKUPPLUS_FOLLOWUPS_ALLOWED' ) && BACKUPPLUS_FOLLOWUPS_ALLOWED ) );

		$split_every = max( (int) BackupPlus_Options::get_backupplus_option( 'backupplus_split_every', 400 ), BACKUPPLUS_SPLIT_MIN );

		$initial_jobdata = array(
			'resume_interval',
			$resume_interval,
			'job_type',
			'backup',
			'jobstatus',
			'begun',
			'backup_time',
			$this->backup_time,
			'job_time_ms',
			$this->job_time_ms,
			'service',
			$service,
			'split_every',
			$split_every,
			'maxzipbatch',
			26214400, // 25MB
			'job_file_entities',
			$job_file_entities,
			'option_cache',
			$option_cache,
			'uploaded_lastreset',
			9,
			'one_shot',
			$one_shot,
			'followsups_allowed',
			$followups_allowed,
			'last_sapi',
			PHP_SAPI,
		);

		array_push( $initial_jobdata, 'incremental_configuration', $incremental_configuration );
		if ( $incremental_base ) {
			$previous_files = array();
			foreach ( $job_file_entities as $entity => $unused ) {
				$previous_files[ $entity ] = (array) $incremental_base[ $entity ];
				foreach ( $previous_files[ $entity ] as $index => $file ) {
					$key = $entity . ( $index ? $index : '' ) . '-size';
					if ( isset( $incremental_base[ $key ] ) ) {
						$previous_files[ $key ] = $incremental_base[ $key ];
					}
				}
			}
			$initial_jobdata[ array_search( 'job_type', $initial_jobdata, true ) + 1 ] = 'incremental';
			array_push( $initial_jobdata, 'incremental_base_time', $incremental_base['timestamp'], 'incremental_previous_snapshots', $incremental_base['incremental']['snapshots'], 'previous_backup_files_array', $previous_files, 'backup_files_array', $previous_files );
		}

		if ( $one_shot ) {
			update_site_option( 'backupplus_oneshotnonce', $this->nonce );
		}

		if ( $this->file_nonce && $this->file_nonce != $this->nonce ) {
			array_push( $initial_jobdata, 'file_nonce', $this->file_nonce );
		}

		if ( $is_scheduled_backup ) {
			array_push( $initial_jobdata, 'is_scheduled_backup', true );
		}

		// 'autobackup' == $options['extradata'] might be set from another plugin so keeping here to keep support
		if ( ! empty( $options['extradata'] ) && ( ! empty( $options['extradata']['autobackup'] ) || 'autobackup' === $options['extradata'] ) ) {
			array_push( $initial_jobdata, 'is_autobackup', true );
		}
		// Save what *should* be done, to make it resumable from this point on
		if ( $backup_database ) {
			$dbs = apply_filters( 'backupplus_backup_databases', array( 'wp' => 'begun' ) );
			if ( is_array( $dbs ) ) {
				foreach ( $dbs as $key => $db ) {
					if ( 'wp' != $key && ( ! is_array( $db ) || empty( $db['dbinfo'] ) || ! is_array( $db['dbinfo'] ) || empty( $db['dbinfo']['host'] ) ) ) {
						unset( $dbs[ $key ] );
					}
				}
			}
		} else {
			$dbs = 'no';
		}

		array_push( $initial_jobdata, 'backup_database', $dbs );
		array_push( $initial_jobdata, 'backup_files', ( ( $backup_files ) ? 'begun' : 'no' ) );

		if ( is_array( $options ) && ! empty( $options['label'] ) ) {
			array_push( $initial_jobdata, 'label', $options['label'] );
		}

		if ( ! empty( $options['always_keep'] ) ) {
			array_push( $initial_jobdata, 'always_keep', true );
		}

		if ( ! empty( $options['remote_storage_instances'] ) ) {
			array_push( $initial_jobdata, 'remote_storage_instances', $options['remote_storage_instances'] );
		}

		try {
			// Use of jobdata_set_multi saves around 200ms
			call_user_func_array( array( $this, 'jobdata_set_multi' ), apply_filters( 'backupplus_initial_jobdata', $initial_jobdata, $options, $split_every ) );
		} catch ( Exception $e ) {
			$this->log( 'Exception when calling jobdata_set_multi: ' . $e->getMessage() . ' (' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')' );
			return false;
		}

		// Everything is set up; now go
		$this->backup_resume( 0, $this->nonce );

		if ( $one_shot ) {
			delete_site_option( 'backupplus_oneshotnonce' );
		}

		return true;
	}

	/**
	 * The purpose of this function is to abstract away historical discrepancies in service lists, by returning in a single, logical form (in particular, no 'none' or '' entries, and always an array)
	 *
	 * @param Array|String|Boolean|Null $services - a list of services to canonicalize, or a string indicating a single service. If null is parsed, then the saved settings will be read.
	 *
	 * @return Array - an array of service names. All service names will be non-empty strings, and 'none' will not feature. If there are no services, then the array will be empty.
	 */
	public function get_canonical_service_list( $services = null ) {

		if ( null === $services ) {
			$services = BackupPlus_Options::get_backupplus_option( 'backupplus_service' );
		}

		$services = (array) $services;

		foreach ( $services as $key => $service ) {
			if ( '' === $service || 'none' === $service || false === $service ) {
				unset( $services[ $key ] );
			}
		}

		return $services;
	}

	/**
	 * Perform the tasks necessary when a backup has run through all the available steps. N.B. This does not imply that the were all successful or that the backup is finished.
	 *
	 * @param Boolean $do_cleanup  - if (and only if) this is set will resumptions be unscheduled
	 * @param Boolean $allow_email - if this is false, then no email will be sent
	 * @param Boolean $force_abort - set to indicate that the user is manually aborting the backup
	 */
	public function backup_finish( $do_cleanup, $allow_email, $force_abort = false ) {

		if ( ! empty( $this->semaphore ) ) {
			$this->semaphore->unlock();
		}
		if ( ! empty( $this->backup_semaphore ) ) {
			$this->backup_semaphore->delete();
		}

		$this->restore_composer_autoloaders();

		$delete_jobdata = false;

		// The valid use of $do_cleanup is to indicate if in fact anything exists to clean up (if no job really started, then there may be nothing)

		// In fact, leaving the hook to run (if debug is set) is harmless, as the resume job should only do tasks that were left unfinished, which at this stage is none.
		if ( 0 == $this->error_count() || $force_abort ) {
			if ( $do_cleanup ) {
				$cancel_event = $this->current_resumption + 1;
				$this->log( "There were no errors in the uploads, so the 'resume' event ($cancel_event) is being unscheduled" );
				// This apparently-worthless setting of metadata before deleting it is for the benefit of a WP install seen where wp_clear_scheduled_hook() and delete_transient() apparently did nothing (probably a faulty cache)
				$this->jobdata_set( 'jobstatus', 'finished' );
				wp_clear_scheduled_hook( 'backupplus_backup_resume', array( $cancel_event, $this->nonce ) );
				// This should be unnecessary - even if it does resume, all should be detected as finished; but I saw one very strange case where it restarted, and repeated everything; so, this will help
				wp_clear_scheduled_hook( 'backupplus_backup_resume', array( $cancel_event + 1, $this->nonce ) );
				wp_clear_scheduled_hook( 'backupplus_backup_resume', array( $cancel_event + 2, $this->nonce ) );
				wp_clear_scheduled_hook( 'backupplus_backup_resume', array( $cancel_event + 3, $this->nonce ) );
				wp_clear_scheduled_hook( 'backupplus_backup_resume', array( $cancel_event + 4, $this->nonce ) );
				$delete_jobdata = true;
			}
		} else {
			if ( $this->newresumption_scheduled ) {
				if ( $this->current_resumption + 1 != $this->jobdata_get( 'fail_on_resume' ) ) {
					$this->log( "There were errors in the uploads, so the 'resume' event is remaining scheduled" );
					$this->jobdata_set( 'jobstatus', 'resumingforerrors' );
				}
			}
			// If there were no errors before moving to the upload stage, on the first run, then bring the resumption back very close. Since this is only attempted on the first run, it is really only an efficiency thing for a quicker finish if there was an unexpected networking event. We don't want to do it straight away every time, as it may be that the cloud service is down - and might be up in 5 minutes time. This was added after seeing a case where resumption 0 got to run for 10 hours... and the resumption 7 that should have picked up the uploading of 1 archive that failed never occurred.
			if ( isset( $this->error_count_before_cloud_backup ) && 0 === $this->error_count_before_cloud_backup ) {
				if ( 0 == $this->current_resumption ) {
					BackupPlus_Job_Scheduler::reschedule( 60 );
				} else {
					// Added 27/Feb/2016 - though the cloud service seems to be down, we still don't want to wait too long
					$resume_interval = $this->jobdata_get( 'resume_interval' );

					// 15 minutes + 2 for each resumption (a modest back-off)
					$max_interval = 900 + $this->current_resumption * 120;
					if ( $resume_interval > $max_interval ) {
						BackupPlus_Job_Scheduler::reschedule( $max_interval );
					}
				}
			}
		}

		// Send the results email if appropriate, which means:
		// - The caller allowed it (which is not the case in an 'empty' run)
		// - And: An email address was set (which must be so in email mode)
		// And one of:
		// - Debug mode
		// - There were no errors (which means we completed and so this is the final run - time for the final report)
		// - It was the tenth resumption; everything failed

		$send_an_email = false;
		// Save the jobdata's state for the reporting - because it might get changed (e.g. incremental backup is scheduled)
		$jobdata_as_was = $this->jobdata;

		// Make sure that the final status is shown
		if ( $force_abort ) {
			$send_an_email = true;
			$final_message = __( 'The backup was aborted by the user', 'backupplus' );
		} elseif ( 0 == $this->error_count() ) {
			$send_an_email = true;
			if ( 0 == $this->error_count( 'warning' ) ) {
				$final_message = __( 'The backup succeeded and is now complete', 'backupplus' );
				// Ensure it is logged in English. Not hugely important; but helps with a tiny number of really broken setups in which the options cacheing is broken
				if ( 'The backup succeeded and is now complete' != $final_message ) {
					$this->log( 'The backup succeeded and is now complete' );
				}
			} else {
				$final_message = __( 'The backup succeeded (with warnings) and is now complete', 'backupplus' );
				if ( 'The backup succeeded (with warnings) and is now complete' != $final_message ) {
					$this->log( 'The backup succeeded (with warnings) and is now complete' );
				}
			}
			if ( $do_cleanup ) {
				BackupPlus_Incremental_Backups::complete();
				$delete_jobdata = apply_filters( 'backupplus_backup_complete', $delete_jobdata );
			}
		} elseif ( false == $this->newresumption_scheduled || $this->current_resumption + 1 == $this->jobdata_get( 'fail_on_resume' ) ) {

			if ( $this->current_resumption + 1 == $this->jobdata_get( 'fail_on_resume' ) ) {
				$this->log( 'The resumption is being cancelled, as it was only scheduled to enable error reporting, which can be performed now' );
				wp_clear_scheduled_hook( 'backupplus_backup_resume', array( $this->current_resumption + 1, $this->nonce ) );
			}

			$send_an_email = true;
			$final_message = __( 'The backup attempt has finished, apparently unsuccessfully', 'backupplus' );
		} else {
			// There are errors, but a resumption will be attempted
			$final_message = __( 'The backup has not finished; a resumption is scheduled', 'backupplus' );
		}

		if ( 0 == $this->error_count() ) {
			// delete manifest files
			$backupplus_dir     = $this->backups_dir_location();
			$backup_files_array = $this->jobdata_get( 'backup_files_array', array() );

			if ( ! empty( $backup_files_array ) ) {
				foreach ( $backup_files_array as $entity => $files ) {
					if ( '-size' === substr( $entity, -5, 5 ) || ! is_array( $files ) ) {
						continue;
					}

					foreach ( $files as $file ) {
						$fullpath = $backupplus_dir . '/' . $file;
						if ( file_exists( $fullpath . '.list.tmp' ) ) {
							$this->log( "Deleting zip manifest ({$file}.list.tmp)" );
							unlink( $fullpath . '.list.tmp' );
						}
					}
				}
			}
		}

		// Now over-ride the decision to send an email, if needed
		if ( BackupPlus_Options::get_backupplus_option( 'backupplus_debug_mode' ) ) {
			$send_an_email = true;
			$this->log( 'An email has been scheduled for this job, because we are in debug mode' );
		}

		$email = BackupPlus_Options::get_backupplus_option( 'backupplus_email' );

		// If there's no email address, or the set was empty, that is the final over-ride: don't send
		if ( ! $allow_email ) {
			$send_an_email = false;
			$this->log( 'No email will be sent - this backup set was empty.' );
		} elseif ( empty( $email ) ) {
			$send_an_email = false;
			$this->log( 'No email will/can be sent - the user has not configured an email address.' );
		}

		if ( $force_abort ) {
			$jobdata_as_was['aborted'] = true;
		}
		if ( $send_an_email ) {
			$this->send_results_email( $final_message, $jobdata_as_was );
		}

		// Make sure this is the final message logged (so it remains on the dashboard)
		$this->log( $final_message );

		@fclose( $this->logfile_handle );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		$this->logfile_handle = null;

		// This is left until last for the benefit of the front-end UI, which then gets maximum chance to display the 'finished' status
		if ( $delete_jobdata ) {
			delete_site_option( 'backupplus_jobdata_' . $this->nonce );
		}
	}

	/**
	 * The jobdata is passed in instead of fetched, because the live jobdata may now differ from that which should be reported on (e.g. an incremental run was subsequently scheduled)
	 *
	 * @param String $final_message The final message to be sent
	 * @param Array  $jobdata       Full job data
	 */
	private function send_results_email( $final_message, $jobdata ) {

		$debug_mode = BackupPlus_Options::get_backupplus_option( 'backupplus_debug_mode' );

		$sendmail_to = $this->just_one_email( BackupPlus_Options::get_backupplus_option( 'backupplus_email' ) );
		if ( is_string( $sendmail_to ) ) {
			$sendmail_to = array( $sendmail_to );
		}

		$backup_files = $jobdata['backup_files'];
		$backup_db    = $jobdata['backup_database'];

		if ( is_array( $backup_db ) ) {
			$backup_db = $backup_db['wp'];
		}
		if ( is_array( $backup_db ) ) {
			$backup_db = $backup_db['status'];
		}

		$backup_type   = ( 'backup' == $jobdata['job_type'] ) ? __( 'Full backup', 'backupplus' ) : __( 'Incremental', 'backupplus' );
		$backup_status = __( 'Successful', 'backupplus' );

		$was_aborted = ! empty( $jobdata['aborted'] );

		if ( $was_aborted ) {
			$backup_contains = __( 'The backup was aborted by the user', 'backupplus' );
			$backup_status   = __( 'Aborted', 'backupplus' );
		} elseif ( 'finished' == $backup_files && ( 'finished' == $backup_db || 'encrypted' == $backup_db ) && 'incremental' !== $jobdata['job_type'] ) {
			$backup_contains = __( 'Files and database', 'backupplus' ) . " ($backup_type)";
		} elseif ( 'finished' == $backup_files ) {
			$backup_contains  = ( 'begun' == $backup_db ) ? __( 'Files (database backup has not completed)', 'backupplus' ) : __( 'Files only (database was not part of this particular schedule)', 'backupplus' );
			$backup_contains .= " ($backup_type)";
		} elseif ( 'finished' == $backup_db || 'encrypted' == $backup_db ) {
			$backup_contains = ( 'begun' == $backup_files ) ? __( 'Database (files backup has not completed)', 'backupplus' ) : __( 'Database only (files were not part of this particular schedule)', 'backupplus' );
		} elseif ( 'begun' == $backup_db || 'begun' == $backup_files ) {
			$backup_contains = __( 'Incomplete', 'backupplus' );
			$backup_status   = __( 'Incomplete', 'backupplus' );
		} else {
			$this->log( 'Unknown/unexpected status: ' . serialize( $backup_files ) . '/' . serialize( $backup_db ) );
			$backup_contains = __( 'Unknown/unexpected error - please raise a support request', 'backupplus' );
			$backup_status   = __( 'Unsuccessful', 'backupplus' );
		}

		$append_log  = '';
		$attachments = array();

		$error_count = 0;

		if ( $this->error_count() > 0 ) {
			$append_log    .= __( 'Errors encountered:', 'backupplus' ) . "\r\n";
			$attachments[0] = $this->logfile_name;
			foreach ( $this->errors as $err ) {
				if ( is_wp_error( $err ) ) {
					foreach ( $err->get_error_messages() as $msg ) {
						$append_log .= '* ' . rtrim( $msg ) . "\r\n";
					}
				} elseif ( is_array( $err ) && 'error' == $err['level'] ) {
					$append_log .= '* ' . rtrim( $err['message'] ) . "\r\n";
				} elseif ( is_string( $err ) ) {
					$append_log .= '* ' . rtrim( $err ) . "\r\n";
				}
				++$error_count;
			}
			$append_log   .= "\r\n";
			$backup_status = __( 'Unsuccessful due to number of errors', 'backupplus' );
		}
		$warnings = ( isset( $jobdata['warnings'] ) ) ? $jobdata['warnings'] : array();
		if ( is_array( $warnings ) && count( $warnings ) > 0 ) {
			if ( 'finished' == $jobdata['jobstatus'] && 0 == $this->error_count() ) {
				$append_log .= __( 'Warnings encountered (note: this is for information; the backup has completed successfully)', 'backupplus' ) . "\r\n";
			} else {
				$append_log .= __( 'Warnings encountered:', 'backupplus' ) . "\r\n";
			}
			$attachments[0] = $this->logfile_name;
			foreach ( $warnings as $err ) {
				$append_log .= '* ' . rtrim( $err ) . "\r\n";
			}
			$append_log .= "\r\n";
			if ( 0 == $this->error_count() ) {
				$backup_status = __( 'Successful with warnings', 'backupplus' );
			}
		}

		if ( $debug_mode && '' != $this->logfile_name && ! in_array( $this->logfile_name, $attachments ) ) {
			$append_log    .= "\r\n" . __( 'The log file has been attached to this email.', 'backupplus' );
			$attachments[0] = $this->logfile_name;
		}

		// We have to use the action in order to set the MIME type on the attachment - by default, WordPress just puts application/octet-stream

		/* translators: %s: Blog name */
		$subject = apply_filters( 'backupplus_report_subject', sprintf( __( 'Backed up: %s', 'backupplus' ), wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) ) . ' (BackupPlus ' . $this->version . ') ' . get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() ), 'Y-m-d H:i' ) . ' - ' . esc_html( $backup_status ), $error_count, count( $warnings ) );

		// The class_exists() check here is a micro-optimization to prevent a possible HTTP call whose results may be disregarded by the filter
		$extra_messages = apply_filters( 'backupplus_report_extramessages', array() );
		$extra_msg      = '';
		if ( is_array( $extra_messages ) ) {
			foreach ( $extra_messages as $msg ) {
				$extra_msg .= $msg['key'] . ': ' . $msg['val'] . "\r\n";
			}
		}

		foreach ( $this->remotestorage_extrainfo as $service => $message ) {
			if ( ! empty( $this->backup_methods[ $service ] ) ) {
				$extra_msg .= $this->backup_methods[ $service ] . ': ' . $message['plain'] . "\r\n";
			}
		}

		// Make it available to the filter
		$jobdata['remotestorage_extrainfo'] = $this->remotestorage_extrainfo;

		if ( ! class_exists( 'BackupPlus_Notices' ) ) {
			backupplus_try_include_file( 'includes/backupplus-notices.php', 'include_once' );
		}
		global $backupplus_notices;
		$ws_notice = $backupplus_notices->do_notice( false, 'report-plain', true );

		$body = apply_filters(
			'backupplus_report_body',
			__( 'Backup of:', 'backupplus' ) . ' ' . site_url() . "\r\n" .
			'BackupPlus ' . __( 'WordPress backup is complete', 'backupplus' ) . ".\r\n" .
			__( 'Backup contains:', 'backupplus' ) . " $backup_contains\r\n" .
			__( 'Latest status:', 'backupplus' ) . ' ' . $final_message . "\r\n" .
			$extra_msg .
			"\r\n" .
			$ws_notice . "\r\n" .
			$append_log,
			$final_message,
			$backup_contains,
			$this->errors,
			$warnings,
			$jobdata
		);

		$this->attachments = apply_filters( 'backupplus_report_attachments', $attachments );

		$attach_size  = 0;
		$unlink_files = array();

		foreach ( $this->attachments as $ind => $attach ) {
			if ( $attach == $this->logfile_name && filesize( $attach ) > 6 * 1048576 ) {

				$this->log( 'Log file is large (' . round( filesize( $attach ) / 1024, 1 ) . ' KB): will compress before e-mailing' );

				if ( ! $handle = fopen( $attach, 'r' ) ) {
					$this->log( 'Error: Failed to open log file for reading: ' . $attach );
				} else {
					if ( ! $whandle = gzopen( $attach . '.gz', 'w' ) ) {
						$this->log( 'Error: Failed to open log file for reading: ' . $attach . '.gz' );
					} else {
						while ( false !== ( $line = @stream_get_line( $handle, 131072, "\n" ) ) ) {// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
							@gzwrite( $whandle, $line . "\n" );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
						}
						fclose( $handle );
						gzclose( $whandle );
						$this->attachments[ $ind ] = $attach . '.gz';
						$unlink_files[]            = $attach . '.gz';
					}
				}
			}
			$attach_size += filesize( $this->attachments[ $ind ] );
		}

		foreach ( $sendmail_to as $ind => $mailto ) {

			if ( false === apply_filters( 'backupplus_report_sendto', true, $mailto, $error_count, count( $warnings ), $ind ) ) {
				continue;
			}

			foreach ( explode( ',', $mailto ) as $sendmail_addr ) {
				// if the address is a URL then instead of emailing it, POST it to slack
				if ( preg_match( '/^https?:\/\//i', $sendmail_addr ) ) {
					$this->log( "Sending to (URL) ('$backup_contains') report (attachments: " . count( $attachments ) . ', size: ' . round( $attach_size / 1024, 1 ) . ' KB) to: ' . substr( $sendmail_addr, 0, 5 ) . '...' );
					$this->post_results_slack( $subject, $body, trim( $sendmail_addr ), $this->file_nonce );
				} else {
					$this->log( "Sending email ('$backup_contains') report (attachments: " . count( $attachments ) . ', size: ' . round( $attach_size / 1024, 1 ) . ' KB) to: ' . substr( $sendmail_addr, 0, 5 ) . '...' );
					$headers = array();
					try {
						$headers[]               = 'X-BackupPlus-Backup-ID: ' . $this->nonce;
						$from_email              = apply_filters( 'backupplus_email_from_header', $this->get_email_from_header() );
						$from_name               = apply_filters( 'backupplus_email_from_name_header', $this->get_email_from_name_header() );
						$use_wp_from_name_filter = '' === $from_email;
						// Notice that we don't use the 'wp_mail_from' filter, but only the 'From:' header to set sender name and sender email address, the reason behind it is that some SMTP plugins override the "wp_mail()" function and they do anything they want inside their own "wp_mail()" function, including not to call the php_mailer filter nor the wp_mail_from and wp_mail_from_name filters, but since the function signature remain the same as the WP one, so they may evaluate and do something with the header parameter
						if ( ! $use_wp_from_name_filter ) {
							$headers[] = sprintf( 'From: %s <%s>', $from_name, $from_email );
						} else {
							add_filter( 'wp_mail_from_name', array( $this, 'get_email_from_name_header' ), 9 );
						}
						add_action( 'wp_mail_failed', array( $this, 'log_email_delivery_failure' ) );
						wp_mail( trim( $sendmail_addr ), $subject, $body, $headers, is_array( $this->attachments ) ? $this->attachments : array() );
						remove_action( 'wp_mail_failed', array( $this, 'log_email_delivery_failure' ) );
						if ( $use_wp_from_name_filter ) {
							remove_filter( 'wp_mail_from_name', array( $this, 'get_email_from_name_header' ), 9 );
						}
					} catch ( Exception $e ) {
						$this->log( 'Exception occurred when sending mail (' . get_class( $e ) . '): ' . $e->getMessage() );
					}
				}
			}
		}

		foreach ( $unlink_files as $file ) {
			@unlink( $file );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
		}
		do_action( 'backupplus_report_finished' );
	}

	/**
	 * Log the email delivery failure to the log file when a PHPMailer exception is caught
	 *
	 * @param WP_Error $error A WP_Error object with the PHPMailer\PHPMailer\Exception message, and an array containing the mail recipient, subject, message, headers, and attachments.
	 */
	public function log_email_delivery_failure( $error ) {
		$this->log( 'An error occurred when sending a backup report email and/or backup file(s) via email (' . $error->get_error_code() . '): ' . $error->get_error_message() );
	}

	/**
	 * Check whether the provided admin_email is under the same domain with the site, and use it as a sender email to increase the chance of an email being sent successfully (if appropriate)
	 *
	 * @return String The admin email address if it's found to be in same domain, an empty string otherwise
	 */
	public function get_email_from_header() {
		$sitename           = $this->get_site_name();
		$admin_email        = get_bloginfo( 'admin_email' );
		$admin_email_domain = preg_replace( '/^[^@]+@(.+)$/', '$1', $admin_email );
		if ( trim( strtolower( $sitename ) ) === trim( strtolower( $admin_email_domain ) ) ) {
			// assuming (non validating) that the email account of the admin email does exist, and the admin email is under the same domain as with the web domain and the domain exists and live as well
			return $admin_email;
		}
		return '';
	}

	/**
	 * Build sender name and use something authentic that represents the identity of the plugin and web domain
	 *
	 * @return String The sender name
	 */
	public function get_email_from_name_header() {
		/* translators: %s: Site name */
		return sprintf( __( 'BackupPlus on %s', 'backupplus' ), $this->get_site_name() );
	}

	/**
	 * Post backup report to slack instead of emailing if the address is a URL
	 *
	 * @param  string $header      report title
	 * @param  string $report_body report content
	 * @param  string $webhook_url url to post report
	 * @param  string $nval        backup log file nonce
	 * @return Void
	 */
	public function post_results_slack( $header, $report_body, $webhook_url, $nval ) {
		$findcontent = __( 'The log file has been attached to this email.', 'backupplus' );

		$report_body = str_replace( $findcontent, '', $report_body );
		$url         = admin_url( BackupPlus_Options::admin_page() . "?page=backupplus&action=downloadlog&backupplus_backup_nonce=$nval" );
		$response    = wp_remote_post(
			$webhook_url,
			array(
				'method'  => 'POST',
				'headers' => array(),
				'body'    => json_encode(
					array(
						'blocks' => array(
							array(
								'type' => 'header',
								'text' => array(
									'type'  => 'plain_text',
									'text'  => $header,
									'emoji' => true,
								),
							),
							array(
								'type' => 'section',
								'text' => array(
									'type' => 'mrkdwn',
									'text' => $report_body,
								),
							),
							array(
								'type'      => 'section',
								'text'      => array(
									'type' => 'mrkdwn',
									'text' => __( 'You can view the log by pressing the \'View log\' button.', 'backupplus' ),
								),
								'accessory' => array(
									'type'      => 'button',
									'text'      => array(
										'type'  => 'plain_text',
										'text'  => __( 'View log', 'backupplus' ),
										'emoji' => true,
									),
									'value'     => 'view_log_123',
									'url'       => $url,
									'action_id' => 'button-action',
								),
							),
						),
					)
				),
			)
		);
		if ( ! is_wp_error( $response ) ) {
			$response_code = wp_remote_retrieve_response_code( $response );
			if ( $response_code < 200 || $response_code >= 300 ) {
				$this->log( 'HTTP POST error : ' . $response_code . ' - ' . wp_remote_retrieve_response_message( $response ) );
			}
		} else {
			$this->log( 'HTTP POST error : ' . $response->get_error_code() . ' - ' . $response->get_error_message() );
		}
	}

	/**
	 * This function returns 'true' if mod_rewrite could be detected as unavailable; a 'false' result may mean it just couldn't find out the answer
	 *
	 * @param  boolean $check_if_in_use_first
	 * @return boolean
	 */
	public function mod_rewrite_unavailable( $check_if_in_use_first = true ) {
		if ( function_exists( 'apache_get_modules' ) ) {
			global $wp_rewrite;
			$mods = apache_get_modules();
			if ( ( ! $check_if_in_use_first || $wp_rewrite->using_mod_rewrite_permalinks() ) && ( ( in_array( 'core', $mods ) || in_array( 'http_core', $mods ) ) && ! in_array( 'mod_rewrite', $mods ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Count the number of alerts that have occurred at the specified level
	 *
	 * @param String $level - the level to count at
	 *
	 * @return Integer
	 */
	public function error_count( $level = 'error' ) {
		$count = 0;
		foreach ( $this->errors as $err ) {
			if ( ( 'error' == $level && ( is_string( $err ) || is_wp_error( $err ) ) ) || ( is_array( $err ) && $level == $err['level'] ) ) {
				++$count;
			}
		}
		return $count;
	}

	public function list_errors() {
		echo '<ul style="list-style: disc inside;">';
		foreach ( $this->errors as $err ) {
			if ( is_wp_error( $err ) ) {
				foreach ( $err->get_error_messages() as $msg ) {
					echo '<li>' . esc_html( $msg ) . '<li>';
				}
			} elseif ( is_array( $err ) && ( 'error' == $err['level'] || 'warning' == $err['level'] ) ) {
				echo '<li>' . esc_html( $err['message'] ) . '</li>';
			} elseif ( is_string( $err ) ) {
				echo '<li>' . esc_html( $err ) . '</li>';
			} else {
				print '<li>' . esc_html( print_r( $err, true ) ) . '</li>';
			}
		}
		echo '</ul>';
	}

	/**
	 * Save last successful backup information
	 *
	 * @param Array $backup_array An array of backup information
	 */
	private function save_last_backup( $backup_array ) {
		$success     = ( 0 == $this->error_count() ) ? 1 : 0;
		$last_backup = BackupPlus_Options::get_backupplus_option( 'backupplus_last_backup', array() );
		if ( empty( $last_backup ) ) {
			$last_backup = array();
		}
		if ( 'incremental' === $this->jobdata_get( 'job_type' ) ) {
			$last_backup['incremental_backup_time'] = $this->backup_time; // the incremental_backup_time index is used only for storing time of the incremental job type
		} else {
			$last_backup['nonincremental_backup_time'] = $this->backup_time; // otherwise the nonincremental_backup_time index is for the backup job type
		}
		$last_backup = wp_parse_args(
			array(
				'backup_time'  => $this->backup_time, // the backup_time index is used for storing either time of backup or incremental job type
				'backup_array' => $backup_array,
				'success'      => $success,
				'errors'       => $this->errors,
				'backup_nonce' => $this->nonce,
			),
			$last_backup
		);
		if ( 'incremental' === $this->jobdata_get( 'job_type' ) ) {
			$last_backup['backup_time_incremental'] = $this->backup_time;
			$last_backup['backup_time']             = $this->jobdata_get( 'incremental_base_time', $this->backup_time );
		} else {
			unset( $last_backup['backup_time_incremental'] );
		}
		$last_backup = apply_filters( 'backupplus_save_last_backup', $last_backup );
		BackupPlus_Options::update_backupplus_option( 'backupplus_last_backup', $last_backup, false );
	}

	/**
	 * $handle must be either false or a WPDB class (or extension thereof). Other options are not yet fully supported.
	 *
	 * @param  Resource|Boolean|Object $handle
	 * @param  Boolean                 $log_it     - whether to log information about the check
	 * @param  Boolean                 $reschedule - whether to schedule a resumption if checking fails
	 * @param  Boolean                 $allow_bail - whether to allow the connection to fail or throw an error
	 * @return Boolean|Integer - whether the check succeeded, or -1 for an unknown result
	 */
	public function check_db_connection( $handle = false, $log_it = false, $reschedule = false, $allow_bail = false ) {

		$type = false;
		if ( false === $handle || is_a( $handle, 'wpdb' ) ) {
			$type = 'wpdb';
		} elseif ( is_resource( $handle ) ) {
			// Expected: string(10) "mysql link"
			$type = get_resource_type( $handle );
		} elseif ( is_object( $handle ) && is_a( $handle, 'mysqli' ) ) {
			$type = 'mysqli';
		}

		if ( false === $type ) {
			return -1;
		}

		$db_connected = -1;

		if ( 'mysql link' == $type || 'mysqli' == $type ) {
			if ( 'mysql link' == $type && @mysql_ping( $handle ) ) {
				return true;// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, PHPCompatibility.Extensions.RemovedExtensions.mysql_DeprecatedRemoved, WordPress.DB.RestrictedFunctions.mysql_mysql_ping -- NoSilencedErrors: @ suppresses PHP warnings when connection is dead; return value is checked instead. DeprecatedRemoved: legacy mysql extension support retained for older PHP. mysql_mysql_ping: direct call on raw database handle; $wpdb->check_connection() cannot be used for non-wpdb connections.
			}           if ( 'mysqli' == $type && @mysqli_ping( $handle ) ) {
				return true;// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, WordPress.DB.RestrictedFunctions.mysql_mysqli_ping -- NoSilencedErrors: @ suppresses PHP warnings when connection is dead; return value is checked instead. mysql_mysqli_ping: direct call on raw mysqli handle; $wpdb->check_connection() cannot be used for non-wpdb connections.
			}
			for ( $tries = 1; $tries <= 5; $tries++ ) {
				// to do, if ever needed
				// if ($this->db_connect(false )) return true;
				// sleep(1);
			}
		} elseif ( 'wpdb' == $type ) {
			if ( false === $handle || ( is_object( $handle ) && 'wpdb' == get_class( $handle ) ) ) {
				global $wpdb;
				$handle = $wpdb;
			}
			if ( method_exists( $handle, 'check_connection' ) && ( ! defined( 'BACKUPPLUS_SUPPRESS_CONNECTION_CHECKS' ) || ! BACKUPPLUS_SUPPRESS_CONNECTION_CHECKS ) ) {
				if ( ! $handle->check_connection( $allow_bail ) ) {
					if ( $log_it ) {
						$this->log( 'The database went away, and could not be reconnected to' );
					}
					// Almost certainly a no-op
					if ( $reschedule ) {
						BackupPlus_Job_Scheduler::reschedule( 60 );
					}
					$db_connected = false;
				} else {
					$db_connected = true;
				}
			}
		}

		return $db_connected;
	}

	/**
	 * This should be called whenever a file is successfully uploaded
	 *
	 * @param  String  $file  - full filepath
	 * @param  Boolean $force - mark as successfully uploaded even if not on the last service
	 * @return Void
	 */
	public function uploaded_file( $file, $force = false ) {

		global $backupplus_backup;

		$db_connected = $this->check_db_connection( false, true, true );

		$service     = empty( $backupplus_backup->current_service ) ? '' : $backupplus_backup->current_service;
		$instance_id = empty( $backupplus_backup->current_instance ) ? '' : $backupplus_backup->current_instance;
		$shash       = $service . ( ( '' == $service ) ? '' : '-' ) . $instance_id . ( ( '' == $instance_id ) ? '' : '-' ) . md5( $file );

		if ( $force || ! empty( $backupplus_backup->last_storage_instance ) ) {
			$this->log( "Recording as successfully uploaded: $file" );
			$new_jobdata = $this->get_uploaded_jobdata_items( $file, $service, $instance_id );
		} else {
			$new_jobdata = array( 'uploaded_' . $shash => 'yes' );
			$this->log( "Recording as successfully uploaded: $file (" . $backupplus_backup->current_service . ', more services to follow)' );
		}

		$upload_status = $this->jobdata_get( 'uploading_substatus' );
		if ( is_array( $upload_status ) && isset( $upload_status['i'] ) ) {
			++$upload_status['i'];
			$upload_status['p']                 = 0;
			$new_jobdata['uploading_substatus'] = $upload_status;
		}

		$this->jobdata_set_multi( $new_jobdata );

		// Really, we could do this immediately when we realise the DB has gone away. This is just for the probably-impossible case that a DB write really can still succeed. But, we must abort before calling delete_local(), as the removal of the local file can cause it to be recreated if the DB is out of sync with the fact that it really is already uploaded
		if ( false === $db_connected ) {
			BackupPlus_Job_Scheduler::record_still_alive();
			die;
		}

		// Delete local files immediately if the option is set
		// Where we are only backing up locally, only the "prune" function should do deleting
		$service = $this->jobdata_get( 'service' );
		if ( ! empty( $backupplus_backup->last_storage_instance ) && ( '' !== $service && ( ( is_array( $service ) && count( $service ) > 0 && ( count( $service ) > 1 || ( array( '' ) !== $service && array( 'none' ) !== $service ) ) ) || ( is_string( $service ) && 'none' !== $service ) ) ) ) {
			$this->delete_local( $file );
		}
	}

	/**
	 * Gets the jobdata items to be added to mark a file as uploaded
	 *
	 * @param String $file        - the file (basename)
	 * @param String $service     - service identifier
	 * @param String $instance_id - instance identifier
	 *
	 * @return Array - jobdata items
	 */
	public function get_uploaded_jobdata_items( $file, $service = '', $instance_id = '' ) {
		$hash  = md5( $file );
		$shash = $service . ( ( '' == $service ) ? '' : '-' ) . $instance_id . ( ( '' == $instance_id ) ? '' : '-' ) . md5( $file );
		return array(
			'uploaded_lastreset' => $this->current_resumption,
			'uploaded_' . $hash  => 'yes',
			'uploaded_' . $shash => 'yes',
		);
	}

	/**
	 * Return whether a particular file has been uploaded to a particular remote service
	 *
	 * @param String $file        - the filename (basename)
	 * @param String $service     - the service identifier; or none, to indicate all services
	 * @param String $instance_id - the instance identifier
	 *
	 * @return Boolean - the result
	 */
	public function is_uploaded( $file, $service = '', $instance_id = '' ) {
		$hash = $service . ( ( '' == $service ) ? '' : '-' ) . $instance_id . ( ( '' == $instance_id ) ? '' : '-' ) . md5( $file );
		return ( 'yes' === $this->jobdata_get( "uploaded_$hash" ) ) ? true : false;
	}

	/**
	 * This function will mark the passed in service and instance id upload as complete
	 *
	 * @param String $service     - the service identifier
	 * @param String $instance_id - the instance identifier
	 *
	 * @return void
	 */
	public function mark_upload_complete( $service, $instance_id = '' ) {

		$upload_completed = $this->jobdata_get( 'upload_completed', array() );

		if ( empty( $instance_id ) ) {
			$upload_completed[ $service ] = 1;
		} else {
			if ( ! is_array( $upload_completed[ $service ] ) ) {
				$upload_completed[ $service ] = array();
			}
			$upload_completed[ $service ][ $instance_id ] = 1;
		}

		$this->jobdata_set( 'upload_completed', $upload_completed );
	}

	/**
	 * This function will check all the remote storage options for this job and ensure that each has completed the upload, if they have mark them as done if they have not completed then call upload_completed() for that service if it exists, otherwise mark as complete.
	 *
	 * @return boolean
	 */
	private function check_upload_completed() {

		$job_services  = $this->jobdata_get( 'service' );
		$services      = $this->get_canonical_service_list( $job_services );
		$sent_to_cloud = empty( $services ) ? false : true;

		if ( ! $sent_to_cloud ) {
			return;
		}

		$storage_objects_and_ids = BackupPlus_Storage_Methods_Interface::get_storage_objects_and_ids( $services );

		foreach ( $services as $service ) {

			if ( 'email' == $service || 'none' == $service || ! $service ) {
				continue;
			}

			if ( empty( $storage_objects_and_ids[ $service ]['object'] ) ) {
				continue;
			}
			$remote_obj       = $storage_objects_and_ids[ $service ]['object'];
			$upload_completed = $this->jobdata_get( 'upload_completed', array() );

			if ( isset( $upload_completed[ $service ] ) && ! is_array( $upload_completed[ $service ] ) ) {
				continue;
			}

			if ( ! empty( $remote_obj ) && ! $remote_obj->supports_feature( 'multi_options' ) ) {

				if ( is_callable( array( $remote_obj, 'upload_completed' ) ) ) {
					$result = $remote_obj->upload_completed();
					if ( $result ) {
						$this->mark_upload_complete( $service );
					}
				} else {
					$this->mark_upload_complete( $service );
				}
			} elseif ( ! empty( $storage_objects_and_ids[ $service ]['instance_settings'] ) ) {

				foreach ( $storage_objects_and_ids[ $service ]['instance_settings'] as $instance_id => $options ) {

					if ( isset( $upload_completed[ $service ][ $instance_id ] ) ) {
						continue;
					}

					$remote_obj->set_options( $options, true, $instance_id );
					if ( is_callable( array( $remote_obj, 'upload_completed' ) ) ) {
						$remote_obj->upload_completed();
					} else {
						$this->mark_upload_complete( $service, $instance_id );
					}
				}
			}
		}
	}
	/**
	 * This function will check if the passed-in file is this job's responsibility to upload. Potentially files can belong to a different job, when running an incremental backup run
	 *
	 * @param String $file - the name of the file
	 * @param String $type - the file entity type (db, plugins, themes etc)
	 *
	 * @return boolean - whether this is a file this job should upload (at some point)
	 */
	private function is_ours_to_upload( $file, $type ) {

		if ( 'db' == $type ) {
			return false;
		}

		$previous_backup_files_array = $this->jobdata_get( 'previous_backup_files_array', array() );

		if ( isset( $previous_backup_files_array[ $type ] ) && in_array( $file, $previous_backup_files_array[ $type ] ) ) {
			return false;
		}

		return true;
	}

	private function delete_local( $file ) {
		$log = "Deleting local file: $file: ";
		if ( BackupPlus_Options::get_backupplus_option( 'backupplus_delete_local', 1 ) ) {
			$fullpath = $this->backups_dir_location() . '/' . $file;

			// check to make sure it exists before removing
			if ( realpath( $fullpath ) ) {
				$deleted = unlink( $fullpath );
				$this->log( $log . ( ( $deleted ) ? 'OK' : 'failed' ) );
				return $deleted;
			}
		} else {
			$this->log( $log . 'skipped: user has unchecked backupplus_delete_local option' );
		}
		return true;
	}

	/**
	 * For detecting another run, and aborting if one was found
	 *
	 * @param String $file - full file path of the file to check
	 */
	public function check_recent_modification( $file ) {
		if ( file_exists( $file ) ) {
			$time_mod = (int) @filemtime( $file );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			$time_now = time();
			if ( $time_mod > 100 && ( $time_now - $time_mod ) < 30 ) {
				BackupPlus_Job_Scheduler::terminate_due_to_activity( $file, $time_now, $time_mod );
			}
		}
	}

	public function get_exclude( $whichone ) {
		if ( 'uploads' == $whichone ) {
			$exclude = explode( ',', BackupPlus_Options::get_backupplus_option( 'backupplus_include_uploads_exclude', BACKUPPLUS_DEFAULT_UPLOADS_EXCLUDE ) );
		} elseif ( 'others' == $whichone ) {
			$exclude = explode( ',', BackupPlus_Options::get_backupplus_option( 'backupplus_include_others_exclude', BACKUPPLUS_DEFAULT_OTHERS_EXCLUDE ) );
		} else {
			$exclude = apply_filters( 'backupplus_include_' . $whichone . '_exclude', array() );
		}
		return ( empty( $exclude ) || ! is_array( $exclude ) ) ? array() : $exclude;
	}

	/**
	 * Indicate the current blog's upload directory
	 *
	 * @return String
	 */
	public function wp_upload_dir() {
		if ( is_multisite() ) {
			global $current_site;
			switch_to_blog( $current_site->blog_id );
		}

		$wp_upload_dir = wp_upload_dir();

		if ( is_multisite() ) {
			restore_current_blog();
		}

		return $wp_upload_dir;
	}

	public function backup_uploads_dirlist( $log_it = false ) {
		// Create an array of directories to be skipped
		// Make the values into the keys
		$exclude = BackupPlus_Options::get_backupplus_option( 'backupplus_include_uploads_exclude', BACKUPPLUS_DEFAULT_UPLOADS_EXCLUDE );
		if ( $log_it ) {
			$this->log( 'Exclusion option setting (uploads): ' . $exclude );
		}
		$skip          = array_flip( preg_split( '/,/', $exclude ) );
		$wp_upload_dir = $this->wp_upload_dir();
		$uploads_dir   = $wp_upload_dir['basedir'];
		return $this->compile_folder_list_for_backup( $uploads_dir, array(), $skip );
	}

	public function backup_others_dirlist( $log_it = false ) {
		// Create an array of directories to be skipped
		// Make the values into the keys
		$exclude = BackupPlus_Options::get_backupplus_option( 'backupplus_include_others_exclude', BACKUPPLUS_DEFAULT_OTHERS_EXCLUDE );
		if ( $log_it ) {
			$this->log( 'Exclusion option setting (others): ' . $exclude );
		}
		$skip          = array_flip( preg_split( '/,/', $exclude ) );
		$file_entities = $this->get_backupable_file_entities( false );

		// Keys = directory names to avoid; values = the label for that directory (used only in log files)
		// $avoid_these_dirs = array_flip($file_entities);
		$avoid_these_dirs = array();
		foreach ( $file_entities as $type => $dirs ) {
			if ( is_string( $dirs ) ) {
				$avoid_these_dirs[ $dirs ] = $type;
			} elseif ( is_array( $dirs ) ) {
				foreach ( $dirs as $dir ) {
					$avoid_these_dirs[ $dir ] = $type;
				}
			}
		}
		return $this->compile_folder_list_for_backup( WP_CONTENT_DIR, $avoid_these_dirs, $skip );
	}

	/**
	 * avoid_these_dirs and skip_these_dirs ultimately do the same thing; but avoid_these_dirs takes full paths whereas skip_these_dirs takes basenames; and they are logged differently (dirs in avoid_these_dirs are potentially dangerous to include; skip is just a user-level preference). They are allowed to overlap.
	 *
	 * @param String $backup_from_inside_dir
	 * @param Array  $avoid_these_dirs
	 * @param Array  $skip_these_dirs
	 *
	 * @return Array
	 */
	public function compile_folder_list_for_backup( $backup_from_inside_dir, $avoid_these_dirs, $skip_these_dirs ) {

		// Entries in $skip_these_dirs are allowed to end in *, which means "and anything else as a suffix". It's not a full shell glob, but it covers what is needed to-date.

		$dirlist          = array();
		$added            = 0;
		$log_skipped      = 0;
		$log_skipped_last = '';

		$this->log( 'Looking for candidates to backup in: ' . $backup_from_inside_dir );
		$backupplus_dir = $this->backups_dir_location();

		if ( is_file( $backup_from_inside_dir ) ) {
			array_push( $dirlist, $backup_from_inside_dir );
			++$added;
			$this->log( "finding files: $backup_from_inside_dir: adding to list ($added)" );
		} elseif ( $handle = opendir( $backup_from_inside_dir ) ) {

			while ( false !== ( $entry = readdir( $handle ) ) ) {

				if ( '.' == $entry || '..' == $entry ) {
					continue;
				}

				// $candidate: full path; $entry = one-level
				$candidate = $backup_from_inside_dir . '/' . $entry;

				if ( isset( $avoid_these_dirs[ $candidate ] ) ) {
					$this->log( "finding files: $entry: skipping: this is the " . $avoid_these_dirs[ $candidate ] . ' directory' );
				} elseif ( $candidate == $backupplus_dir ) {
					$this->log( "finding files: $entry: skipping: this is the BackupPlus directory" );
				} elseif ( isset( $skip_these_dirs[ $entry ] ) ) {
					$this->log( "finding files: $entry: skipping: excluded by options" );
				} else {
					$add_to_list = true;
					// Now deal with entries in $skip_these_dirs ending in * or starting with *
					foreach ( $skip_these_dirs as $skip => $sind ) {
						if ( '*' == substr( $skip, -1, 1 ) && '*' == substr( $skip, 0, 1 ) && strlen( $skip ) > 2 ) {
							if ( strpos( $entry, substr( $skip, 1, strlen( $skip ) - 2 ) ) !== false ) {
								$this->log( "finding files: $entry: skipping: excluded by options (glob)" );
								$add_to_list = false;
							}
						} elseif ( '*' == substr( $skip, -1, 1 ) && strlen( $skip ) > 1 ) {
							if ( substr( $entry, 0, strlen( $skip ) - 1 ) == substr( $skip, 0, strlen( $skip ) - 1 ) ) {
								$this->log( "finding files: $entry: skipping: excluded by options (glob)" );
								$add_to_list = false;
							}
						} elseif ( '*' == substr( $skip, 0, 1 ) && strlen( $skip ) > 1 ) {
							if ( strlen( $entry ) >= strlen( $skip ) - 1 && substr( $entry, ( strlen( $skip ) - 1 ) * -1 ) == substr( $skip, 1 ) ) {
								$this->log( "finding files: $entry: skipping: excluded by options (glob)" );
								$add_to_list = false;
							}
						}
					}
					if ( $add_to_list ) {
						array_push( $dirlist, $candidate );
						++$added;
						if ( $added > 500 ) {
							if ( $log_skipped >= 500 ) {
								$this->log( "finding files: $entry: adding to list ($added, $log_skipped log lines skipped)" );
								$log_skipped      = 0;
								$log_skipped_last = '';
							} else {
								++$log_skipped;
								$log_skipped_last = $entry;
							}
						} else {
							$skip_dblog = ( ( $added > 50 && 0 != $added % 100 ) || ( $added > 2000 && 0 != $added % 500 ) );
							$this->log( "finding files: $entry: adding to list ($added)", 'notice', false, $skip_dblog );
						}
					}
				}
			}
			@closedir( $handle );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			if ( $log_skipped > 0 ) {
				$this->log( "finding files: $log_skipped_last: adding to list ($added, last; $log_skipped log lines skipped)" );
			}
		} else {
			$this->log( 'ERROR: Could not read the directory: ' . $backup_from_inside_dir );
			$this->log( __( 'Could not read the directory', 'backupplus' ) . ': ' . $backup_from_inside_dir, 'error' );
		}

		return $dirlist;
	}

	/**
	 * Save the backup information to the backup history during a running backup (adding information to the currently-running job)
	 *
	 * @param Array $backup_array - the backup history
	 */
	private function save_backup_to_history( $backup_array ) {

		if ( ! is_array( $backup_array ) ) {
			$this->log( 'Could not save backup history because we have no backup array. Backup probably failed.' );
			$this->log( __( 'Could not save backup history because we have no backup array.', 'backupplus' ) . ' ' . __( 'Backup probably failed.', 'backupplus' ), 'error' );
			return;
		}

		$job_type = $this->jobdata_get( 'job_type' );

		$backup_array['nonce']                = $this->file_nonce;
		$backup_array['native']               = true;
		$backup_array['service']              = $this->jobdata_get( 'service' );
		$backup_array['service_instance_ids'] = array();
		if ( 'incremental' != $job_type ) {
			$backup_array['always_keep'] = $this->jobdata_get( 'always_keep', false );
		}
		$backup_array['files_enumerated_at'] = $this->jobdata_get( 'files_enumerated_at' );
		$remote_storage_instances            = $this->jobdata_get( 'remote_storage_instances', array() );

		// N.B. Though the saved 'service' option can have various forms (especially if upgrading from (very) old versions), in the jobdata, it is always an array.
		$storage_objects_and_ids = BackupPlus_Storage_Methods_Interface::get_enabled_storage_objects_and_ids( $backup_array['service'], $remote_storage_instances );

		// N.B. On PHP 5.5+, we'd use array_column()
		foreach ( $storage_objects_and_ids as $method => $method_information ) {
			if ( 'none' == $method || ! $method || ! $method_information['object']->supports_feature( 'multi_options' ) ) {
				continue;
			}
			$backup_array['service_instance_ids'][ $method ] = array_keys( $method_information['instance_settings'] );
		}

		if ( 'incremental' != $job_type && '' != ( $label = $this->jobdata_get( 'label', '' ) ) ) {
			$backup_array['label'] = $label;
		}
		if ( ! isset( $backup_array['created_by_version'] ) ) {
			$backup_array['created_by_version'] = $this->version;
		}
		$backup_array['last_saved_by_version'] = $this->version;
		$backup_array['is_multisite']          = is_multisite() ? true : false;
		if ( false != $this->jobdata_get( 'is_autobackup', false ) ) {
			$backup_array['autobackup'] = true;
		}

		if ( false != ( $morefiles_linked_indexes = $this->jobdata_get( 'morefiles_linked_indexes', false ) ) ) {
			$backup_array['morefiles_linked_indexes'] = $morefiles_linked_indexes;
		}
		if ( false != ( $morefiles_more_locations = $this->jobdata_get( 'morefiles_more_locations', false ) ) ) {
			$backup_array['morefiles_more_locations'] = $morefiles_more_locations;
		}

		$history_time = $this->jobdata_get( 'incremental_base_time', $this->backup_time );
		$existing     = BackupPlus_Backup_History::get_history( $history_time );
		if ( 'incremental' === $job_type ) {
			// Array replacement preserves split indices, checksums, database and retention metadata.
			$backup_array = array_replace_recursive( $existing, $backup_array );
		}
		$backup_array['incremental'] = array(
			'configuration' => $this->jobdata_get( 'incremental_configuration' ),
			'complete'      => false,
			'snapshots'     => $this->jobdata_get( 'incremental_snapshots', array() ),
		);
		BackupPlus_Backup_History::save_backup( apply_filters( 'backupplus_save_backup_history_timestamp', $history_time ), $backup_array );
	}

	/**
	 * If files + db are on different schedules but are scheduled for the same time,
	 * then combine them $event = (object) array('hook' => $hook, 'timestamp' => $timestamp, 'schedule' => $recurrence, 'args' => $args, 'interval' => $schedules[$recurrence]['interval']);
	 * See wp_schedule_single_event() and wp_schedule_event() in wp-includes/cron.php
	 *
	 * @param  Object|Boolean $event - the event being scheduled
	 * @return Object|Boolean - the filtered value
	 */
	public function schedule_event( $event ) {

		static $scheduled = array();

		if ( is_object( $event ) && ( 'backupplus_backup' == $event->hook || 'backupplus_backup_database' == $event->hook ) ) {

			// Reset the option - but make sure it is saved first so that we can used it (since this hook may be called just before our actual cron task)
			$this->combine_jobs_around = BackupPlus_Options::get_backupplus_option( 'backupplus_combine_jobs_around' );

			BackupPlus_Options::delete_backupplus_option( 'backupplus_combine_jobs_around' );

			$scheduled[ $event->hook ] = true;

			// This next fragment is wrong: there's only a 'second call' when saving all settings; otherwise, the WP scheduler might just be updating one event. So, there's some inefficieny as the option is wiped and set uselessly at least once when saving settings.
			// We only want to take action on the second call (otherwise, our information is out-of-date already)
			// If there is no second call, then that's fine - nothing to do
			// if (count($scheduled) < 2) {
			// return $event;
			// }

			// Override the timestamp of the scheduled backup event based on the user's predefined schedule setting if there is a fixtime add-on.
			$event = apply_filters( 'backupplus_override_scheduled_backup_event', $event );

			$backup_scheduled_for = ( 'backupplus_backup' == $event->hook ) ? $event->timestamp : wp_next_scheduled( 'backupplus_backup' );
			$db_scheduled_for     = ( 'backupplus_backup_database' == $event->hook ) ? $event->timestamp : wp_next_scheduled( 'backupplus_backup_database' );

			$diff = absint( $backup_scheduled_for - $db_scheduled_for );

			$margin = ( defined( 'BACKUPPLUS_COMBINE_MARGIN' ) && is_numeric( BACKUPPLUS_COMBINE_MARGIN ) ) ? BACKUPPLUS_COMBINE_MARGIN : 600;

			if ( $backup_scheduled_for && $db_scheduled_for && $diff < $margin ) {
				// We could change the event parameters; however, this would complicate other code paths (because the WP cron system uses a hash of the parameters as a key, and you must supply the exact parameters to look up events). So, we just set a marker that boot_backup() can pick up on.
				BackupPlus_Options::update_backupplus_option( 'backupplus_combine_jobs_around', min( $backup_scheduled_for, $db_scheduled_for ) );
			}
		}

		return $event;
	}

	/**
	 * This function is both the backup scheduler and a filter callback for saving the option. It is called in the register_setting for the backupplus_interval, which means when the admin settings are saved it is called.
	 *
	 * @param  String $interval
	 * @return String - filtered value
	 */
	public function schedule_backup( $interval ) {
		$previous_time = wp_next_scheduled( 'backupplus_backup' );

		// Clear schedule so that we don't stack up scheduled backups
		wp_clear_scheduled_hook( 'backupplus_backup' );
		if ( 'manual' == $interval ) {
			return 'manual';
		}
		$previous_interval = BackupPlus_Options::get_backupplus_option( 'backupplus_interval' );

		$valid_schedules = wp_get_schedules();
		if ( empty( $valid_schedules[ $interval ] ) ) {
			$interval = 'daily';
		}

		// Try to avoid changing the time is one was already scheduled. This is fairly conservative - we could do more, e.g. check if a backup already happened today.
		$default_time = ( $interval == $previous_interval && $previous_time > 0 ) ? $previous_time : $this->random_schedule_time();
		$first_time   = apply_filters( 'backupplus_schedule_firsttime_files', $default_time );

		wp_schedule_event( $first_time, $interval, 'backupplus_backup' );

		return $interval;
	}

	/**
	 * This function is both the database backup scheduler and a filter callback for saving the option. It is called in the register_setting for the backupplus_interval_database, which means when the admin settings are saved it is called.
	 *
	 * @param  String $interval
	 * @return String - filtered value
	 */
	public function schedule_backup_database( $interval ) {
		$previous_time = wp_next_scheduled( 'backupplus_backup_database' );

		// Clear schedule so that we don't stack up scheduled backups
		wp_clear_scheduled_hook( 'backupplus_backup_database' );
		if ( 'manual' == $interval ) {
			return 'manual';
		}

		$previous_interval = BackupPlus_Options::get_backupplus_option( 'backupplus_interval_database' );

		$valid_schedules = wp_get_schedules();
		if ( empty( $valid_schedules[ $interval ] ) ) {
			$interval = 'daily';
		}

		// Try to avoid changing the time is one was already scheduled. This is fairly conservative - we could do more, e.g. check if a backup already happened today.
		$default_time = ( $interval == $previous_interval && $previous_time > 0 ) ? $previous_time : $this->random_schedule_time();

		$first_time = apply_filters( 'backupplus_schedule_firsttime_db', $default_time );
		wp_schedule_event( $first_time, $interval, 'backupplus_backup_database' );

		return $interval;
	}

	/**
	 * This function is both the increments backup scheduler and a filter callback for saving the option. It is called in the register_setting for the backupplus_interval_increments, which means when the admin settings are saved it is called.
	 *
	 * @param  String $interval
	 * @return String - filtered value
	 */
	public function schedule_backup_increments( $interval ) {
		$previous_time = wp_next_scheduled( 'backupplus_backup_increments' );

		// Clear schedule so that we don't stack up scheduled backups
		wp_clear_scheduled_hook( 'backupplus_backup_increments' );
		if ( 'none' == $interval || empty( $interval ) ) {
			return 'none';
		}
		$previous_interval = BackupPlus_Options::get_backupplus_option( 'backupplus_interval_increments' );

		$valid_schedules = wp_get_schedules();
		if ( empty( $valid_schedules[ $interval ] ) || $valid_schedules[ $interval ]['interval'] > DAY_IN_SECONDS || $valid_schedules[ $interval ]['interval'] < HOUR_IN_SECONDS ) {
			$interval = 'daily';
		}

		// Try to avoid changing the time is one was already scheduled. This is fairly conservative - we could do more, e.g. check if a backup already happened today.
		$default_time = ( $interval == $previous_interval && $previous_time > 0 ) ? $previous_time : time() + 120;
		$first_time   = apply_filters( 'backupplus_schedule_firsttime_increments', $default_time );

		wp_schedule_event( $first_time, $interval, 'backupplus_backup_increments' );

		return $interval;
	}

	/**
	 * This function will generate a random backup schedule timestamp between the hours of 9PM and 7AM and return it
	 *
	 * @return string - the random timestamp
	 */
	private function random_schedule_time() {

		static $scheduled_timestamp = false;

		if ( $scheduled_timestamp ) {
			return $scheduled_timestamp;
		}

		$valid_hours = array( 21, 22, 23, 0, 1, 2, 3, 4, 5, 6, 7 );

		$current_hour      = current_time( 'G' );
		$current_timestamp = current_time( 'timestamp' );

		if ( in_array( $current_hour, $valid_hours ) ) {
			$scheduled_timestamp = $current_timestamp;
		} else {
			$scheduled_timestamp = $current_timestamp + 43200;
		}

		return $scheduled_timestamp;
	}

	/**
	 * Acts as a WordPress options filter
	 *
	 * @param Array  $options     - An array of options
	 * @param String $option_name - The option name
	 *
	 * @return Array - the returned array can either be the set of updated options or a WordPress error array
	 */
	public function storage_options_filter( $options, $option_name ) {
		if ( 'backupplus_' !== substr( $option_name, 0, 8 ) ) {
			return $options;
		}
		$method = substr( $option_name, 8 );

		$storage = BackupPlus_Storage_Methods_Interface::get_storage_object( $method );

		if ( ! is_a( $storage, 'BackupPlus_BackupModule' ) || ! is_callable( array( $storage, 'options_filter' ) ) ) {
			return $options;
		}

		return call_user_func( array( $storage, 'options_filter' ), $options );
	}

	/**
	 * Get the location of BackupPlus's internal directory
	 *
	 * @param  Boolean $allow_cache
	 * @return String - the directory path. Returns without any trailing slash.
	 */
	public function backups_dir_location( $allow_cache = true ) {

		if ( $allow_cache && ! empty( $this->backup_dir ) ) {
			return $this->backup_dir;
		}
		$backupplus_dir = BackupPlus_Options::get_backupplus_option( 'backupplus_dir' );
		if ( ! is_string( $backupplus_dir ) ) {
			$backupplus_dir = '';
		}
		$backupplus_dir = untrailingslashit( $backupplus_dir );

		// Normalize historical settings that included the wp-content prefix before saving them.
		if ( preg_match( '/^wp-content\/(.*)$/', $backupplus_dir, $matches ) && ABSPATH . 'wp-content' === WP_CONTENT_DIR ) {
			BackupPlus_Options::update_backupplus_option( 'backupplus_dir', $matches[1] );
			$backupplus_dir = WP_CONTENT_DIR . '/' . $matches[1];
		}

		// Default
		if ( ! $backupplus_dir ) {
			$backupplus_dir = WP_CONTENT_DIR . '/backupplus';
		}

		// Do a test for a relative path
		if ( '/' != substr( $backupplus_dir, 0, 1 ) && '\\' != substr( $backupplus_dir, 0, 1 ) && ! preg_match( '/^[a-zA-Z]:/', $backupplus_dir ) ) {
			// Legacy - file paths stored related to ABSPATH
			if ( is_dir( ABSPATH . $backupplus_dir ) && is_file( ABSPATH . $backupplus_dir . '/index.html' ) && is_file( ABSPATH . $backupplus_dir . '/.htaccess' ) && ! is_file( ABSPATH . $backupplus_dir . '/index.php' ) && false !== strpos( file_get_contents( ABSPATH . $backupplus_dir . '/.htaccess', false, null, 0, 20 ), 'deny from all' ) ) {
				$backupplus_dir = ABSPATH . $backupplus_dir;
			} else {
				// File paths stored relative to WP_CONTENT_DIR
				$backupplus_dir = trailingslashit( WP_CONTENT_DIR ) . $backupplus_dir;
			}
		}

		// Check for the existence of the dir and prevent enumeration
		// index.php is for a sanity check - make sure that we're not somewhere unexpected
		if ( ( ! is_dir( $backupplus_dir ) || ! is_file( $backupplus_dir . '/index.html' ) || ! is_file( $backupplus_dir . '/.htaccess' ) ) && ! is_file( $backupplus_dir . '/index.php' ) || ! is_file( $backupplus_dir . '/web.config' ) ) {
			@mkdir( $backupplus_dir, 0775, true );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			@file_put_contents( $backupplus_dir . '/index.html', '<html><body>WordPress backups by BackupPlus</body></html>' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Silenced to suppress errors that may arise because of the function. False positive: the 'file_put_contents()' function doesn't put the file in the plugin directory, it puts it in the BackupPlus backup directory instead.
			if ( ! is_file( $backupplus_dir . '/.htaccess' ) ) {
				@file_put_contents( $backupplus_dir . '/.htaccess', 'deny from all' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Silenced to suppress errors that may arise because of the function. False positive: the 'file_put_contents()' function doesn't put the file in the plugin directory, it puts it in the BackupPlus backup directory instead.
			}           if ( ! is_file( $backupplus_dir . '/web.config' ) ) {
				@file_put_contents( $backupplus_dir . '/web.config', "<configuration>\n<system.webServer>\n<authorization>\n<deny users=\"*\" />\n</authorization>\n</system.webServer>\n</configuration>\n" );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Silenced to suppress errors that may arise because of the function. False positive: the 'file_put_contents()' function doesn't put the file in the plugin directory, it puts it in the BackupPlus backup directory instead.
			}
		}

		$this->backup_dir = $backupplus_dir;

		return $backupplus_dir;
	}

	/**
	 * This function will work out the total size of the passed in backup and return it.
	 *
	 * @param array $backup - an array of information about this backup set
	 *
	 * @return integer - the total size of the backup in bytes
	 */
	public function get_total_backup_size( $backup ) {

		$backupable_entities = $this->get_backupable_file_entities( true, true );

		// Add the database to the entities array ready to loop over
		$backupable_entities['db'] = '';

		$total_size = 0;
		foreach ( $backup as $ekey => $files ) {
			if ( ! isset( $backupable_entities[ $ekey ] ) ) {
				continue;
			}
			if ( is_string( $files ) ) {
				$files = array( $files );
			}
			foreach ( $files as $findex => $file ) {
				$size_key   = ( 0 == $findex ) ? $ekey . '-size' : $ekey . $findex . '-size';
				$total_size = ( false === $total_size || ! isset( $backup[ $size_key ] ) || ! is_numeric( $backup[ $size_key ] ) ) ? false : $total_size + $backup[ $size_key ];
			}
		}

		return $total_size;
	}

	public function spool_file( $fullpath, $encryption = '' ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 900 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}
		if ( ! file_exists( $fullpath ) || filesize( $fullpath ) < 1 ) {
			esc_html_e( 'File not found', 'backupplus' );
			return;
		}

		// Prevent any debug output
		// Don't enable this line - it causes 500 HTTP errors in some cases/hosts on some large files, for unknown reason
		// @ini_set('display_errors', '0');

		// Ordinary backup downloads preserve ciphertext. Decryption has its own
		// authorized POST handler and never takes a key from a download URL.
		$content_type = BackupPlus_Encryption::is_file_encrypted( $fullpath ) ? 'application/octet-stream' : BackupPlus_Manipulation_Functions::get_mime_type_from_filename( $fullpath, false );

		backupplus_try_include_file( 'includes/class-partialfileservlet.php', 'include_once' );

		// Prevent the file being read into memory
		if ( ob_get_level() ) {
			$flush_max = min( 5, (int) ob_get_level() );
			for ( $i = 1; $i <= $flush_max; $i++ ) {
				@ob_end_clean();// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			}
		}
		if ( ob_get_level() ) {
			@ob_end_clean(); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged --Twice - see HS#6673 - someone at least needed it
		}
		if ( isset( $_SERVER['HTTP_RANGE'] ) ) {
			$range_header = trim( BackupPlus_Manipulation_Functions::fetch_superglobal( 'server', 'HTTP_RANGE' ) );
		} elseif ( function_exists( 'apache_request_headers' ) ) {
			foreach ( apache_request_headers() as $name => $value ) {
				if ( strtoupper( $name ) === 'RANGE' ) {
					$range_header = trim( $value );
				}
			}
		}

		if ( empty( $range_header ) ) {
			header( 'Content-Length: ' . filesize( $fullpath ) );
			header( "Content-type: $content_type" );
			header( 'Content-Disposition: attachment; filename="' . basename( $fullpath ) . '";' );
			readfile( $fullpath );
			return;
		}

		try {
			$range_header = BackupPlus_RangeHeader::createFromHeaderString( $range_header );
			$servlet      = new BackupPlus_PartialFileServlet( $range_header );
			$servlet->sendFile( $fullpath, $content_type );
		} catch ( BackupPlus_InvalidRangeHeaderException $e ) {
			header( 'HTTP/1.1 400 Bad Request' );
			BackupPlus_Manipulation_Functions::error_log( 'BackupPlus: BackupPlus_InvalidRangeHeaderException: ' . $e->getMessage() );
		} catch ( BackupPlus_UnsatisfiableRangeException $e ) {
			header( 'HTTP/1.1 416 Range Not Satisfiable' );
		} catch ( BackupPlus_NonExistentFileException $e ) {
			header( 'HTTP/1.1 404 Not Found' );
		} catch ( BackupPlus_UnreadableFileException $e ) {
			header( 'HTTP/1.1 500 Internal Server Error' );
		}
	}

	public function just_one_email( $input, $required = false ) {
		$x = $this->just_one( $input, 'saveemails', ( empty( $input ) && false === $required ) ? '' : get_bloginfo( 'admin_email' ) );
		if ( is_array( $x ) ) {
			foreach ( $x as $ind => $val ) {
				if ( empty( $val ) ) {
					unset( $x[ $ind ] );
				}
			}
			if ( empty( $x ) ) {
				$x = '';
			}
		}
		return $x;
	}

	/**
	 * Filter the values down to just one (subject to being filtered)
	 *
	 * @param Array|String   $input  - input
	 * @param String         $filter - filter suffix to use
	 * @param Boolean|String $rinput - a 'preferred' value (unless false) if no filtering is done
	 *
	 * @return Array|String|Null - output, after filtering
	 */
	public function just_one( $input, $filter = 'savestorage', $rinput = false ) {
		$oinput = $input;
		if ( false === $rinput ) {
			$rinput = is_array( $input ) ? array_pop( $input ) : $input;
		}
		if ( is_string( $rinput ) && false !== strpos( $rinput, ',' ) ) {
			$rinput = substr( $rinput, 0, strpos( $rinput, ',' ) );
		}
		return apply_filters( 'BackupPlus_' . $filter, $rinput, $oinput );
	}

	/**
	 * Enqueue the JavaScript and CSS for the select2 library
	 */
	public function enqueue_select2() {
		// De-register to defeat any plugins that may have registered incompatible versions (e.g. WooCommerce 2.5 beta1 still has the Select 2 3.5 series)
		wp_deregister_script( 'select2' );
		wp_deregister_style( 'select2' );
		$select2_version = $this->use_unminified_scripts() ? '4.1.0-rc.0' . '.' . time() : '4.1.0-rc.0';
		$min_or_not      = $this->use_unminified_scripts() ? '' : '.min';
		wp_enqueue_script( 'select2', BACKUPPLUS_URL . '/includes/select2/select2' . $min_or_not . '.js', array( 'jquery' ), $select2_version );
		wp_enqueue_style( 'select2', BACKUPPLUS_URL . '/includes/select2/select2' . $min_or_not . '.css', array(), $select2_version );
	}

	public function memory_check_current( $memory_limit = false ) {
		// Returns in megabytes
		if ( false == $memory_limit ) {
			$memory_limit = ini_get( 'memory_limit' );
		}
		$memory_limit = rtrim( $memory_limit );
		$memory_unit  = $memory_limit[ strlen( $memory_limit ) - 1 ];
		if ( 0 == (int) $memory_unit && '0' !== $memory_unit ) {
			$memory_limit = substr( $memory_limit, 0, strlen( $memory_limit ) - 1 );
		} else {
			$memory_unit = '';
		}
		switch ( $memory_unit ) {
			case '':
				$memory_limit = floor( $memory_limit / 1048576 );
				break;
			case 'K':
			case 'k':
				$memory_limit = floor( $memory_limit / 1024 );
				break;
			case 'G':
				$memory_limit = $memory_limit * 1024;
				break;
			case 'M':
				// assumed size, no change needed
				break;
		}
		return $memory_limit;
	}

	public function memory_check( $memory, $check_using = false ) {
		$memory_limit = $this->memory_check_current( $check_using );
		return ( $memory_limit >= $memory ) ? true : false;
	}

	/**
	 * Sets up the nonce, basic job data, opens a log file for a new restore job, and makes sure that the BackupPlus_Restorer class is available
	 *
	 * @param Boolean|string $nonce - the job nonce we want to use or false for a new one
	 *
	 * @return void
	 */
	public function initiate_restore_job( $nonce = false ) {
		$this->backup_time_nonce( $nonce );
		// we reset here so that we ensure the correct jobdata gets loaded while we resume
		$this->jobdata_reset();
		$this->jobdata_set( 'job_type', 'restore' );
		$this->jobdata_set( 'job_time_ms', $this->job_time_ms );
		$this->logfile_open( $this->nonce );
		if ( ! class_exists( 'BackupPlus_Restorer' ) ) {
			backupplus_try_include_file( 'restorer.php', 'include_once' );
		}
	}

	/**
	 * Analyse a database file and return information about it
	 *
	 * @param Integer        $timestamp   - the database time in the backup history
	 * @param Array          $res         - accompanying data. The key 'backupplus_encryptionphrase' will be used for decryption if relevant.
	 * @param Boolean|String $db_file     - the path to the file to analyse; if not specified (false), then it will be obtained from the backup history
	 * @param Boolean        $header_only - whether or not to stop analysis once the header ends
	 *
	 * @return Array - containing arrays for the resulting messages, warnings, errors and meta information
	 */
	public function analyse_db_file( $timestamp, $res, $db_file = false, $header_only = false ) {

		$mess       = array();
		$warn       = array();
		$err        = array();
		$info       = array();
		$wp_version = $this->get_wordpress_version();
		global $wpdb;

		if ( ! class_exists( 'BackupPlus_Database_Utility' ) ) {
			backupplus_try_include_file( 'includes/class-database-utility.php', 'include_once' );
		}

		$backupplus_dir = $this->backups_dir_location();

		if ( false === $db_file ) {
			// This attempts to raise the maximum packet size. This can't be done within the session, only globally. Therefore, it has to be done before the session starts; in our case, during the pre-analysis.
			$this->max_packet_size();

			$backup = BackupPlus_Backup_History::get_history( $timestamp );
			if ( ! isset( $backup['nonce'] ) || ! isset( $backup['db'] ) ) {
				return array( $mess, $warn, $err, $info );
			}

			$db_file = is_string( $backup['db'] ) ? $backupplus_dir . '/' . $backup['db'] : $backupplus_dir . '/' . $backup['db'][0];
		}

		if ( ! is_readable( $db_file ) ) {
			return array( $mess, $warn, $err, $info );
		}

		// Encrypted - decrypt it
		if ( BackupPlus_Encryption::is_file_encrypted( $db_file ) ) {
			if ( BackupPlus_Age_Restore::is_age( $db_file ) ) {
				$decrypted_file = BackupPlus_Age_Restore::file( $db_file, isset( $res['backupplus_age_ticket'] ) ? $res['backupplus_age_ticket'] : '', '', $timestamp );
			} else {
				$encryption = empty( $res['backupplus_encryptionphrase'] ) ? BackupPlus_Options::get_backupplus_option( 'backupplus_encryptionphrase' ) : $res['backupplus_encryptionphrase'];

				if ( ! $encryption ) {
					$err[] = sprintf(
						/* translators: %s: Error message */
						__( 'Error: %s', 'backupplus' ),
						__( 'Decryption failed.', 'backupplus' ) . ' ' . __( 'The database file is encrypted, but no encryption key is configured.', 'backupplus' )
					);
					return array( $mess, $warn, $err, $info );
				}

				$decrypted_file = BackupPlus_Encryption::decrypt( $db_file, $encryption );
			}

			if ( is_array( $decrypted_file ) ) {
				$db_file = $decrypted_file['fullpath'];
			} else {
				$err[] = __( 'Decryption failed.', 'backupplus' ) . ' ' . __( 'The most likely cause is that you used the wrong key.', 'backupplus' );
				return array( $mess, $warn, $err, $info );
			}
		}

		// Even the empty schema when gzipped comes to 1565 bytes; a blank WP 3.6 install at 5158. But we go low, in case someone wants to share single tables.
		if ( filesize( $db_file ) < 1000 ) {
			/* translators: %s: Database size in KB */
			$err[] = sprintf( __( 'The database is too small to be a valid WordPress database (size: %s Kb).', 'backupplus' ), round( filesize( $db_file ) / 1024, 1 ) );
			return array( $mess, $warn, $err, $info );
		}

		// If the backup is not from BackupPlus or a compatible UpdraftPlus archive and it's not a simple SQL file then we don't want to scan
		if ( ! empty( $backup['meta_foreign'] ) && 'genericsql' != $backup['meta_foreign'] ) {
			$info['skipped_db_scan'] = 1;
			return array( $mess, $warn, $err, $info );
		}

		$is_plain = ( '.gz' == substr( isset( $decrypted_file['basename'] ) ? $decrypted_file['basename'] : $db_file, -3, 3 ) ) ? false : true;

		$dbhandle = $is_plain ? fopen( $db_file, 'r' ) : BackupPlus_Filesystem_Functions::gzopen_for_read( $db_file, $warn, $err );
		if ( ! is_resource( $dbhandle ) ) {
			$err[] = __( 'Failed to open database file.', 'backupplus' );
			return array( $mess, $warn, $err, $info );
		}

		$info['timestamp'] = $timestamp;

		// Analyse the file, print the results.

		$line               = 0;
		$old_siteurl        = '';
		$old_home           = '';
		$old_table_prefix   = null;
		$old_siteinfo       = array();
		$gathering_siteinfo = true;
		$old_wp_version     = '';
		$old_php_version    = '';

		$tables_found      = array();
		$db_charsets_found = array();

		$db_scan_timed_out           = false;
		$php_max_input_vars_exceeded = false;

		// TODO: If the backup is the right size/checksum, then we could restore the $line <= 100 in the 'while' condition and not bother scanning the whole thing? Or better: sort the core tables to be first so that this usually terminates early

		$wanted_tables = array( 'terms', 'term_taxonomy', 'term_relationships', 'commentmeta', 'comments', 'links', 'options', 'postmeta', 'posts', 'users', 'usermeta' );

		$migration_warning  = false;
		$processing_create  = false;
		$processing_routine = false;
		$db_version         = $wpdb->db_version();

		// Don't set too high - we want a timely response returned to the browser
		// Until April 2015, this was always 90. But we've seen a few people with ~1GB databases (uncompressed), and 90s is not enough. Note that we don't bother checking here if it's compressed - having a too-large timeout when unexpected is harmless, as it won't be hit. On very large dbs, they're expecting it to take a while.
		// "120 or 240" is a first attempt at something more useful than just fixed at 90 - but should be sufficient (as 90 was for everyone without ~1GB databases)
		$default_dbscan_timeout = ( filesize( $db_file ) < 31457280 ) ? 120 : 240;
		$dbscan_timeout         = ( defined( 'BACKUPPLUS_DBSCAN_TIMEOUT' ) && is_numeric( BACKUPPLUS_DBSCAN_TIMEOUT ) ) ? BACKUPPLUS_DBSCAN_TIMEOUT : $default_dbscan_timeout;
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $dbscan_timeout );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}
		// We limit the time that we spend scanning the file for character sets
		$db_charset_collate_scan_timeout                         = ( defined( 'BACKUPPLUS_DB_CHARSET_COLLATE_SCAN_TIMEOUT' ) && is_numeric( BACKUPPLUS_DB_CHARSET_COLLATE_SCAN_TIMEOUT ) ) ? BACKUPPLUS_DB_CHARSET_COLLATE_SCAN_TIMEOUT : 10;
		$charset_scan_start_time                                 = microtime( true );
		$db_supported_character_sets                             = (array) $GLOBALS['wpdb']->get_results( 'SHOW CHARACTER SET', OBJECT_K );
		$db_supported_collations                                 = (array) $GLOBALS['wpdb']->get_results( 'SHOW COLLATION', OBJECT_K );
		$db_charsets_found                                       = array();
		$db_collates_found                                       = array();
		$db_supported_charset_related_to_unsupported_collation   = false;
		$db_supported_charsets_related_to_unsupported_collations = array();
		while ( ( ( $is_plain && ! feof( $dbhandle ) ) || ( ! $is_plain && ! gzeof( $dbhandle ) ) ) && ( $line < 100 || ( ! $header_only && count( $wanted_tables ) > 0 ) || ( ( microtime( true ) - $charset_scan_start_time ) < $db_charset_collate_scan_timeout && ! empty( $db_supported_character_sets ) ) ) ) {
			++$line;
			// Up to 1MB
			$buffer = $is_plain ? rtrim( fgets( $dbhandle, 1048576 ) ) : rtrim( gzgets( $dbhandle, 1048576 ) );
			// Comments are what we are interested in
			if ( substr( $buffer, 0, 1 ) == '#' ) {
				$processing_create  = false;
				$processing_routine = false;
				if ( '' == $old_siteurl && preg_match( '/^\# Backup of: (http(.*))$/', $buffer, $matches ) ) {
					$old_siteurl = untrailingslashit( $matches[1] );
					$mess[]      = __( 'Backup of:', 'backupplus' ) . ' ' . htmlspecialchars( $old_siteurl ) .
								/* translators: %s: Old WP versions */
								( ( ! empty( $old_wp_version ) ) ? ' ' . sprintf( __( '(version: %s)', 'backupplus' ), $old_wp_version ) : '' );
					// Check for should-be migration
					if ( untrailingslashit( site_url() ) != $old_siteurl ) {
						if ( ! $migration_warning ) {
							$migration_warning = true;
							$info['migration'] = true;
							if ( BackupPlus_Manipulation_Functions::normalise_url( $old_siteurl ) == BackupPlus_Manipulation_Functions::normalise_url( site_url() ) ) {
								// Same site migration with only http/https difference
								$info['same_url']          = false;
								$info['url_scheme_change'] = true;
								$old_siteurl_parsed        = parse_url( $old_siteurl );
								$actual_siteurl_parsed     = parse_url( site_url() );
								if ( ( stripos( $old_siteurl_parsed['host'], 'www.' ) === 0 && stripos( $actual_siteurl_parsed['host'], 'www.' ) !== 0 ) || ( stripos( $old_siteurl_parsed['host'], 'www.' ) !== 0 && stripos( $actual_siteurl_parsed['host'], 'www.' ) === 0 ) ) {
									/* translators: 1: Old site URL, 2: Current site URL */
									$powarn = sprintf( __( 'The website address in the backup set (%1$s) is slightly different from that of the site now (%2$s).', 'backupplus' ), $old_siteurl, site_url() ) . ' ' .
											__( 'This is not expected to be a problem for restoring the site, as long as visits to the former address still reach the site.', 'backupplus' ) . ' ';
								} else {
									$powarn = '';
								}
								if ( ( 'https' == $old_siteurl_parsed['scheme'] && 'http' == $actual_siteurl_parsed['scheme'] ) || ( 'http' == $old_siteurl_parsed['scheme'] && 'https' == $actual_siteurl_parsed['scheme'] ) ) {
									/* translators: 1: Old scheme, 2: Current scheme */
									$powarn .= sprintf( __( 'This backup set is of this site, but at the time of the backup you were using %1$s, whereas the site now uses %2$s.', 'backupplus' ), $old_siteurl_parsed['scheme'], $actual_siteurl_parsed['scheme'] );
									if ( 'https' == $old_siteurl_parsed['scheme'] ) {
										$powarn .= ' ' . apply_filters( 'backupplus_https_to_http_additional_warning', '' );
									} else {
										$powarn .= ' ' . apply_filters( 'backupplus_http_to_https_additional_warning', '' );
									}
								} else {
									$powarn .= apply_filters( 'backupplus_dbscan_urlchange_www_append_warning', '' );
								}
								$warn[] = $powarn;
							} else {
								// For completely different site migration
								$info['same_url']          = false;
								$info['url_scheme_change'] = false;
								$warn[]                    = apply_filters(
									'backupplus_dbscan_urlchange',
									sprintf(
									/* translators: %s: Old and current site URLs */
										__( 'This backup set is from a different site (%s). After restoring, check whether any site URLs need to be updated.', 'backupplus' ),
										htmlspecialchars( $old_siteurl . ' / ' . untrailingslashit( site_url() ) )
									),
									$old_siteurl,
									$res
								);
							}
						}

						if ( $this->mod_rewrite_unavailable( false ) ) {
							/* translators: 1: Webserver type, 2: Missing module */
							$warn[] = sprintf( __( 'You are using the %1$s webserver, but do not seem to have the %2$s module loaded.', 'backupplus' ), 'Apache', 'mod_rewrite' ) . ' ' .
									/* translators: 1: Module name, 2: Sample url */
									sprintf( __( 'You should enable %1$s to make any pretty permalinks (e.g. %2$s) work', 'backupplus' ), 'mod_rewrite', 'http://example.com/my-page/' );
						}
					} else {
						// For exactly same URL site restoration
						$info['same_url']          = true;
						$info['url_scheme_change'] = false;
					}
				} elseif ( '' == $old_home && preg_match( '/^\# Home URL: (http(.*))$/', $buffer, $matches ) ) {
					$old_home = untrailingslashit( $matches[1] );
					// Check for should-be migration
					if ( ! $migration_warning && BackupPlus_Manipulation_Functions::normalise_url( home_url() ) != BackupPlus_Manipulation_Functions::normalise_url( $old_home ) ) {
						$migration_warning = true;
							$powarn        = apply_filters(
								'backupplus_dbscan_urlchange',
								sprintf(
								/* translators: %s: Old and current home URLs */
									__( 'This backup set is from a different site (%s). After restoring, check whether any site URLs need to be updated.', 'backupplus' ),
									htmlspecialchars( $old_home . ' / ' . home_url() )
								),
								$old_home,
								$res
							);
						if ( ! empty( $powarn ) ) {
							$warn[] = $powarn;
						}
					}
				} elseif ( ! isset( $info['created_by_version'] ) && preg_match( '/^\# Created by (?:BackupPlus|UpdraftPlus) version ([\d\.]+)/', $buffer, $matches ) ) {
					$info['created_by_version'] = trim( $matches[1] );
				} elseif ( '' == $old_wp_version && preg_match( '/^\# WordPress Version: ([0-9]+(\.[0-9]+)+)(-[-a-z0-9]+,)?(.*)$/', $buffer, $matches ) ) {
					$old_wp_version = $matches[1];
					if ( ! empty( $matches[3] ) ) {
						$old_wp_version .= substr( $matches[3], 0, strlen( $matches[3] ) - 1 );
					}
					if ( version_compare( $old_wp_version, $wp_version, '>' ) ) {
						// $mess[] = sprintf(__('%s version: %s', 'backupplus'), 'WordPress', $old_wp_version);
						$warn[] = sprintf(
							/* translators: 1: Old WP version, 2: Current WP version */
							__( 'You are importing from a newer version of WordPress (%1$s) into an older one (%2$s).', 'backupplus' ),
							$old_wp_version,
							$wp_version
						) . ' ' . __( 'There are no guarantees that WordPress can handle this.', 'backupplus' );
					}
					if ( preg_match( '/running on PHP ([0-9]+\.[0-9]+)(\s|\.)/', $matches[4], $nmatches ) && preg_match( '/^([0-9]+\.[0-9]+)(\s|\.)/', PHP_VERSION, $cmatches ) ) {
						$old_php_version     = $nmatches[1];
						$current_php_version = $cmatches[1];
						if ( version_compare( $old_php_version, $current_php_version, '>' ) ) {
							/* translators: 1: Old PHP version, 2: PHP */
							$warn[] = sprintf( __( 'The site in this backup was running on a webserver with version %1$s of %2$s.', 'backupplus' ), $old_php_version, 'PHP' ) . ' ' .
									/* translators: %s: Current PHP version */
									sprintf( __( 'This is significantly newer than the server which you are now restoring onto (version %s).', 'backupplus' ), PHP_VERSION ) . ' ' .
									/* translators: %s: PHP */
									sprintf( __( 'You should only proceed if you cannot update the current server and are confident (or willing to risk) that your plugins/themes/etc are compatible with the older %s version.', 'backupplus' ), 'PHP' ) . ' ' .
									/* translators: %s: PHP */
									sprintf( __( 'Any support requests to do with %s should be raised with your web hosting company.', 'backupplus' ), 'PHP' );
						} elseif ( version_compare( $old_php_version, $current_php_version, '<' ) ) {
							/* translators: 1: PHP version, 2: PHP */
							$warn[] = sprintf( __( 'The site in this backup was running on a webserver with version %1$s of %2$s.', 'backupplus' ), $old_php_version, 'PHP' ) . ' ' .
									/* translators: %s: Current PHP version */
									sprintf( __( 'This is older than the server which you are now restoring onto (version %s).', 'backupplus' ), PHP_VERSION ) . ' ' .
									/* translators: %s: PHP */
									sprintf( __( 'You should only proceed if you have checked and are confident (or willing to risk) that your plugins/themes/etc are compatible with the new %s version.', 'backupplus' ), 'PHP' ) . ' ' .
									/* translators: %s: PHP */
									sprintf( __( 'Any support requests to do with %s should be raised with your web hosting company.', 'backupplus' ), 'PHP' );
						}
					}
				} elseif ( null === $old_table_prefix && ( preg_match( '/^\# Table prefix: ?(\S*)$/', $buffer, $matches ) || preg_match( '/^-- Table prefix: ?(\S*)$/i', $buffer, $matches ) ) ) {
					$old_table_prefix = $matches[1];
					// echo '<strong>'.__('Old table prefix:', 'backupplus').'</strong> '.htmlspecialchars($old_table_prefix).'<br>';
				} elseif ( empty( $info['label'] ) && preg_match( '/^\# Label: (.*)$/', $buffer, $matches ) ) {
					$info['label'] = $matches[1];
					$mess[]        = __( 'Backup label:', 'backupplus' ) . ' ' . htmlspecialchars( $info['label'] );
				} elseif ( $gathering_siteinfo && preg_match( '/^\# Site info: (\S+)$/', $buffer, $matches ) ) {
					if ( 'end' == $matches[1] ) {
						$gathering_siteinfo = false;
						// Sanity checks
						if ( isset( $old_siteinfo['multisite'] ) && ! $old_siteinfo['multisite'] && is_multisite() ) {
							$err[] = __( 'Restoring a single-site backup into a WordPress multisite network is not supported.', 'backupplus' );
							return array( $mess, $warn, $err, $info );
						} elseif ( isset( $old_siteinfo['multisite'] ) && $old_siteinfo['multisite'] && ! is_multisite() ) {
							$warn[] = __( 'Warning:', 'backupplus' ) . ' ' . __( 'Your backup is of a WordPress multisite install; but this site is not.', 'backupplus' ) . ' ' . __( 'Only the first site of the network will be accessible.', 'backupplus' ) . ' <a href="https://codex.wordpress.org/Create_A_Network" target="_blank">' . __( 'If you want to restore a multisite backup, you should first set up your WordPress installation as a multisite.', 'backupplus' ) . '</a>';
						}
					} elseif ( preg_match( '/^([^=]+)=(.*)$/', $matches[1], $kvmatches ) ) {
						$key = $kvmatches[1];
						$val = $kvmatches[2];
						if ( 'multisite' == $key ) {
							$info['multisite'] = $val ? true : false;
							if ( $val ) {
								$mess[] = '<strong>' . __( 'Site information:', 'backupplus' ) . '</strong> ' . 'backup is of a WordPress Network';
							}
						}
						$old_siteinfo[ $key ] = $val;
					}
				} elseif ( preg_match( '/^\# Skipped tables: (.*)$/', $buffer, $matches ) ) {
					$skipped_tables = array_map( 'trim', explode( ',', $matches[1] ) );
				}
			} elseif ( preg_match( '#^\s*/\*\!40\d+ SET NAMES (.*)\*\/#i', $buffer, $smatches ) ) {
				$db_charsets_found[] = rtrim( $smatches[1] );
			} elseif ( ! $processing_routine && ! $processing_create && preg_match( "/^[^'\"]*create[^'\"]*(?:definer\s*=\s*(?:`.{1,17}`@`[^\s]+`|'.{1,17}'@'[^\s]+'))?.+?(?:function(?:\s\s*if\s\s*not\s\s*exists)?|procedure)\s*`([^\r\n]+)`/is", $buffer, $matches ) ) {
				// ^\s*create\s\s*(?:or\s\s*replace\s\s*)?.*?(?:aggregate\s\s*function|function|procedure)\s\s*`(.+)`(?:\s\s*if\s\s*not\s\s*exists\s*|\s*)?\(
				if ( ! preg_match( '/END\s*(?:\*\/)?;;\s*$/is', $buffer ) && ! preg_match( '/\;\s*;;\s*$/is', $buffer ) && ! preg_match( '/\s*(?:\*\/)?;;\s*$/is', $buffer ) ) {
					$processing_routine = true;
				}
			} elseif ( ! $processing_routine && preg_match( '/^\s*create table \`?([^\`\(]*)\`?\s*\(/i', $buffer, $matches ) ) {
				$table          = $matches[1];
				$tables_found[] = $table;
				if ( null !== $old_table_prefix ) {
					// Remove prefix
					$table = $old_table_prefix ? BackupPlus_Manipulation_Functions::str_replace_once( $old_table_prefix, '', $table ) : $table;
					if ( in_array( $table, $wanted_tables ) ) {
						$wanted_tables = array_diff( $wanted_tables, array( $table ) );
					}
				}
				if ( empty( $old_siteurl ) && ! empty( $backup['meta_foreign'] ) ) {
					$info['migration'] = true;
				}
				if ( ';' != substr( $buffer, -1, 1 ) ) {
					$processing_create                                     = true;
					$db_supported_charset_related_to_unsupported_collation = true;
				}
			} elseif ( $processing_create ) {
				if ( ! empty( $db_supported_collations ) ) {
					if ( preg_match( '/ COLLATE=([^\s;]+)/i', $buffer, $collate_match ) ) {
						$db_collates_found[] = $collate_match[1];
						if ( ! isset( $db_supported_collations[ $collate_match[1] ] ) ) {
							$db_supported_charset_related_to_unsupported_collation = true;
						}
					}
					if ( preg_match( '/ COLLATE ([a-zA-Z0-9._-]+),/i', $buffer, $collate_match ) ) {
						$db_collates_found[] = $collate_match[1];
						if ( ! isset( $db_supported_collations[ $collate_match[1] ] ) ) {
							$db_supported_charset_related_to_unsupported_collation = true;
						}
					}
					if ( preg_match( '/ COLLATE ([a-zA-Z0-9._-]+) /i', $buffer, $collate_match ) ) {
						$db_collates_found[] = $collate_match[1];
						if ( ! isset( $db_supported_collations[ $collate_match[1] ] ) ) {
							$db_supported_charset_related_to_unsupported_collation = true;
						}
					}
				}
				if ( ! empty( $db_supported_character_sets ) ) {
					if ( preg_match( '/\b(?:CHARSET|CHARACTER SET)\b\s*=?\s*([^\s;,]+)/i', $buffer, $charset_match ) ) {
						$db_charsets_found[] = $charset_match[1];
						if ( $db_supported_charset_related_to_unsupported_collation && ! in_array( $charset_match[1], $db_supported_charsets_related_to_unsupported_collations ) ) {
							$db_supported_charsets_related_to_unsupported_collations[] = $charset_match[1];
						}
					}
				}
				if ( ';' == substr( $buffer, -1, 1 ) ) {
					$processing_create                                     = false;
					$db_supported_charset_related_to_unsupported_collation = false;
				}
				static $mysql_version_warned = false;
				if ( ! $mysql_version_warned && version_compare( $db_version, '5.2.0', '<' ) && preg_match( '/(CHARSET|COLLATE)[= ]utf8mb4/', $buffer ) ) {
					$mysql_version_warned = true;
					$err[]                = sprintf(
						/* translators: %s: Error message */
						__( 'Error: %s', 'backupplus' ),
						/* translators: %s: MySQL version */
						sprintf( __( 'The database backup uses MySQL features not available in the old MySQL version (%s) that this site is running on.', 'backupplus' ), $db_version ) . ' ' .
						__( 'You must upgrade MySQL to be able to use this database.', 'backupplus' )
					);
				}
			} elseif ( $processing_routine ) {
				if ( ( preg_match( '/END\s*(?:\*\/)?;;\s*$/is', $buffer ) || preg_match( '/\;\s*;;\s*$/is', $buffer ) || preg_match( '/\s*(?:\*\/)?;;\s*$/is', $buffer ) ) && ! preg_match( '/(?:--|#).+?;;\s*$/i', $buffer ) ) {
					$processing_routine = false;
				}
			}
		}
		if ( $is_plain ) {
			if ( ! feof( $dbhandle ) ) {
				$db_scan_timed_out = true;
			}
			@fclose( $dbhandle );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		} else {
			if ( ! gzeof( $dbhandle ) ) {
				$db_scan_timed_out = true;
			}
			@gzclose( $dbhandle );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}
		if ( ! empty( $db_supported_character_sets ) ) {
			$db_charsets_found_unique = array_unique( $db_charsets_found );
			$db_unsupported_charset   = array();
			$db_charset_forbidden     = false;
			foreach ( $db_charsets_found_unique as $db_charset ) {
				if ( ! isset( $db_supported_character_sets[ $db_charset ] ) ) {
					$db_unsupported_charset[] = $db_charset;
					$db_charset_forbidden     = true;
				}
			}
			if ( $db_charset_forbidden ) {
				$db_unsupported_charset_unique = array_unique( $db_unsupported_charset );
				$warn[]                        = sprintf(
					/* translators: %s: Character sets */
					_n( "The database server that this WordPress site is running on doesn't support the character set (%s) which you are trying to import.", "The database server that this WordPress site is running on doesn't support the character sets (%s) which you are trying to import.", count( $db_unsupported_charset_unique ), 'backupplus' ),
					implode( ', ', $db_unsupported_charset_unique )
				) . ' ' .
				__( 'Choose a character set supported by the target database before continuing; changing it may alter how imported text is stored.', 'backupplus' );
				$db_supported_character_sets = array_keys( $db_supported_character_sets );
				$similar_type_charset        = BackupPlus_Manipulation_Functions::get_matching_str_from_array_elems( $db_unsupported_charset_unique, $db_supported_character_sets, true );
				if ( empty( $similar_type_charset ) ) {
					$row                  = $GLOBALS['wpdb']->get_row( 'show variables like "character_set_database"' );
					$similar_type_charset = ( null !== $row ) ? $row->Value : '';
				}
				if ( empty( $similar_type_charset ) && ! empty( $db_supported_character_sets[0] ) ) {
					$similar_type_charset = $db_supported_character_sets[0];
				}
				$charset_select_html  = '<label>' . __( 'Your chosen character set to use instead:', 'backupplus' ) . '</label> ';
				$charset_select_html .= '<select name="backupplus_restorer_charset" id="backupplus_restorer_charset">';
				if ( is_array( $db_supported_character_sets ) ) {
					foreach ( $db_supported_character_sets as $character_set ) {
						if ( $character_set == $similar_type_charset ) {
							$info['supported_charset'] = $character_set;
						}
						$charset_select_html .= '<option value="' . esc_attr( $character_set ) . '" ' . selected( $character_set, $similar_type_charset, false ) . '>' . esc_html( $character_set ) . '</option>';
					}
				}
				$charset_select_html .= '</select>';
				if ( empty( $info['addui'] ) ) {
					$info['addui'] = '';
				}
				$info['addui'] .= $charset_select_html;
			}
		}
		if ( ! empty( $db_supported_collations ) ) {
			$db_collates_found_unique = array_unique( $db_collates_found );
			$db_unsupported_collate   = array();
			$db_collate_forbidden     = false;
			foreach ( $db_collates_found_unique as $db_collate ) {
				if ( ! isset( $db_supported_collations[ $db_collate ] ) ) {
					$db_unsupported_collate[] = $db_collate;
					$db_collate_forbidden     = true;
				}
			}
			if ( $db_collate_forbidden ) {
				$db_unsupported_collate_unique = array_unique( $db_unsupported_collate );
				$warn[]                        = sprintf(
					/* translators: %s: Collations */
					_n( "The database server that this WordPress site is running on doesn't support the collation (%s) used in the database which you are trying to import.", "The database server that this WordPress site is running on doesn't support multiple collations (%s) used in the database which you are trying to import.", count( $db_unsupported_collate_unique ), 'backupplus' ),
					implode( ', ', $db_unsupported_collate_unique )
				) . ' ' .
				__( 'You can choose another suitable collation instead and continue with the restoration (at your own risk).', 'backupplus' );
				$similar_type_collate = '';
				if ( $db_charset_forbidden && ! empty( $similar_type_charset ) ) {
					$similar_type_collate = $this->get_similar_collate_related_to_charset( $db_supported_collations, $db_unsupported_collate_unique, $similar_type_charset );
				}
				if ( empty( $similar_type_collate ) && ! empty( $db_supported_charsets_related_to_unsupported_collations ) ) {
					$db_supported_collations_related_to_charset = array();
					foreach ( $db_supported_collations as $db_supported_collation => $db_supported_collations_info_obj ) {
						if ( isset( $db_supported_collations_info_obj->Charset ) && in_array( $db_supported_collations_info_obj->Charset, $db_supported_charsets_related_to_unsupported_collations ) ) {
							$db_supported_collations_related_to_charset[] = $db_supported_collation;
						}
					}
					if ( ! empty( $db_supported_collations_related_to_charset ) ) {
						$similar_type_collate = BackupPlus_Manipulation_Functions::get_matching_str_from_array_elems( $db_unsupported_collate_unique, $db_supported_collations_related_to_charset, false );
					}
				}
				if ( empty( $similar_type_collate ) ) {
					$similar_type_collate = $this->get_similar_collate_based_on_ocuurence_count( $db_collates_found, $db_supported_collations, $db_supported_charsets_related_to_unsupported_collations );
				}
				if ( empty( $similar_type_collate ) ) {
					$similar_type_collate = BackupPlus_Manipulation_Functions::get_matching_str_from_array_elems( $db_unsupported_collate_unique, array_keys( $db_supported_collations ), false );
				}

				$collate_select_html      = '<div class="udp-notice below-h2 backupplus-restore-option"><label>' . __( 'Your chosen replacement collation', 'backupplus' ) . ':</label>';
				$collate_select_html     .= '<select name="backupplus_restorer_collate" id="backupplus_restorer_collate">';
				$db_charsets_found_unique = array_unique( $db_charsets_found );
				foreach ( $db_supported_collations as $collate => $collate_info_obj ) {
					$option_other_attr = array();
					if ( $db_charset_forbidden && isset( $collate_info_obj->Charset ) ) {
						$option_other_attr[] = 'data-charset=' . esc_attr( $collate_info_obj->Charset );
						if ( $similar_type_charset != $collate_info_obj->Charset ) {
							$option_other_attr[] = 'style="display:none;"';
						}
					} else {
						if ( 1 == count( $db_charsets_found_unique ) ) {
							if ( ! in_array( $collate_info_obj->Charset, $db_charsets_found_unique ) ) {
								$option_other_attr[] = 'style="display:none;"';
							}
						} else {
							$option_other_attr[] = 'style="display:none;"';
						}
					}
					$collate_select_html .= '<option value="' . esc_attr( $collate ) . '" ' . selected( $collate, $similar_type_collate, false ) . ' ' . implode( ' ', $option_other_attr ) . '>' . esc_html( $collate ) . '</option>';
				}

				if ( count( $db_charsets_found_unique ) > 1 && ! $db_charset_forbidden ) {
					$collate_select_html .= '<option value="choose_a_default_for_each_table" selected="selected">' . __( 'Choose a default for each table', 'backupplus' ) . '</option>';
				}
				$collate_select_html .= '</select>';
				$collate_select_html .= '</div>';

				$info['addui'] = empty( $info['addui'] ) ? $collate_select_html : $info['addui'] . '<br>' . $collate_select_html;

				if ( $db_charset_forbidden ) {
					$collate_change_on_charset_selection_data = array(
						'db_supported_collations'       => $db_supported_collations,
						'db_unsupported_collate_unique' => $db_unsupported_collate_unique,
						'db_collates_found'             => $db_collates_found,
					);
					$info['addui']                           .= '<input type="hidden" name="collate_change_on_charset_selection_data" id="collate_change_on_charset_selection_data" value="' . esc_attr( json_encode( $collate_change_on_charset_selection_data ) ) . '">';
				}
			}
		}
		/*        $blog_tables = "CREATE TABLE $wpdb->terms (
		CREATE TABLE $wpdb->term_taxonomy (
		CREATE TABLE $wpdb->term_relationships (
		CREATE TABLE $wpdb->commentmeta (
		CREATE TABLE $wpdb->comments (
		CREATE TABLE $wpdb->links (
		CREATE TABLE $wpdb->options (
		CREATE TABLE $wpdb->postmeta (
		CREATE TABLE $wpdb->posts (
				$users_single_table = "CREATE TABLE $wpdb->users (
				$users_multi_table = "CREATE TABLE $wpdb->users (
				$usermeta_table = "CREATE TABLE $wpdb->usermeta (
				$ms_global_tables = "CREATE TABLE $wpdb->blogs (
		CREATE TABLE $wpdb->blog_versions (
		CREATE TABLE $wpdb->registration_log (
		CREATE TABLE $wpdb->site (
		CREATE TABLE $wpdb->sitemeta (
		CREATE TABLE $wpdb->signups (
		*/
		if ( ! isset( $skipped_tables ) ) {
			$skipped_tables = array();
		}
		$missing_tables = array();

		if ( null !== $old_table_prefix ) {

			if ( '' === $old_table_prefix ) {
				$warn[] = __( 'This backup is of a site with an empty table prefix, which WordPress does not officially support; the results may be unreliable.', 'backupplus' );
			}

			if ( ! $header_only ) {
				foreach ( $wanted_tables as $table ) {
					if ( ! in_array( $old_table_prefix . $table, $tables_found ) ) {
						$missing_tables[] = $table;
					}
				}

				foreach ( $missing_tables as $key => $value ) {
					if ( in_array( $old_table_prefix . $value, $skipped_tables ) ) {
						$key_to_unset_skipped_tables = array_search( $old_table_prefix . $value, $skipped_tables );
						unset( $skipped_tables[ $key_to_unset_skipped_tables ] );
					}
				}

				if ( count( $missing_tables ) > 0 ) {
					/* translators: %s: Missing WordPress tables */
					$warn[] = sprintf( __( 'This database backup is missing core WordPress tables: %s', 'backupplus' ), implode( ', ', $missing_tables ) );
				}
				if ( count( $skipped_tables ) > 0 ) {
					/* translators: %s: Excluded database tables */
					$warn[] = sprintf( __( 'This database backup has the following non-core WordPress database tables excluded: %s', 'backupplus' ), implode( ', ', $skipped_tables ) );
				}
			}
		} else {
			if ( empty( $backup['meta_foreign'] ) ) {
				$warn[] = __( 'BackupPlus was unable to find the table prefix when scanning the database backup.', 'backupplus' );
			}
		}

		$php_max_input_vars = ini_get( 'max_input_vars' ); // phpcs:ignore PHPCompatibility.IniDirectives.NewIniDirectives.max_input_varsFound -- does not exist in PHP 5.2

		if ( false == $php_max_input_vars ) {
			$php_max_input_vars_exceeded = true;
		} elseif ( count( $tables_found ) >= 0.90 * $php_max_input_vars ) {
			$php_max_input_vars_exceeded = true;
			// If the amount of tables exceed 90% of the php max input vars then truncate the list to 50% of the php max input vars value
			$tables_found = array_splice( $tables_found, 0, intval( $php_max_input_vars / 2 ) );
		}

		$php_max_input_vars_value   = false == $php_max_input_vars ? 0 : $php_max_input_vars;
		$info['php_max_input_vars'] = $php_max_input_vars_value;

		// On UD 1.16.30 - 1.16.34 there was a serious bug that did not backup all content in composite key tables, if this is not a migration and the backup was created on one of these versions do not restore this table.
		$skip_composite_tables = ( ! empty( $info['created_by_version'] ) && version_compare( '1' . substr( $info['created_by_version'], 1 ), '1.16.30', '>=' ) && version_compare( '1' . substr( $info['created_by_version'], 1 ), '1.16.34', '<=' ) ) ? true : false;

		// Check if this is a single site to multisite restore
		$skip_user_tables = ( isset( $old_siteinfo['multisite'] ) && ! $old_siteinfo['multisite'] && is_multisite() ) ? true : false;

		if ( $skip_composite_tables ) {
			if ( ! empty( $info['migration'] ) ) {
				$skip_composite_tables = false;
				$warn[]                = sprintf(
					/* translators: %s: BackupPlus or UpdraftPlus version */
					__( 'This backup was created on a previous BackupPlus or UpdraftPlus version (%s) which did not correctly backup tables with composite primary keys (such as the term_relationships table, which records tags and product attributes).', 'backupplus' ) . ' ' .
					__( 'Therefore it is advised that you take a fresh backup on the source site, using a later version.', 'backupplus' ),
					$info['created_by_version']
				);
			} else {
				$warn[] = sprintf(
					/* translators: %s: BackupPlus or UpdraftPlus version */
					__( 'This backup was created on a previous BackupPlus or UpdraftPlus version (%s) which did not correctly backup tables with composite primary keys (such as the term_relationships table, which records tags and product attributes).', 'backupplus' ) . ' ' .
					__( 'Therefore, affected tables on the current site which already exist will not be replaced by default, to avoid corrupting them (you can review this in the list of tables below).', 'backupplus' ),
					$info['created_by_version']
				);
			}
		}

		if ( $skip_user_tables ) {
			$warn[] = __( 'You are restoring a single site backup into a multisite installation.', 'backupplus' ) . ' ' . __( 'The users and usermeta tables will be excluded from the restore to prevent conflicts with the existing multisite user structure.', 'backupplus' );
		}

		if ( empty( $tables_found ) ) {
			$warn[] = __( 'BackupPlus was unable to find any tables when scanning the database backup; it maybe corrupt.', 'backupplus' );
		} else {
			$select_restore_tables  = '<div class="udp-notice below-h2 backupplus-restore-option">';
			$select_restore_tables .= '<p>' . __( 'If you do not want to restore all your database tables, then choose some to exclude here.', 'backupplus' ) . '(<a href="#" id="backupplus_restore_tables_showmoreoptions">...</a>)</p>';

			$select_restore_tables .= '<div class="backupplus_restore_tables_options_container" style="display:none;">';

			$select_table_button    = '<p><a href="#" class="backupplus-select-all-tables">' . __( 'Select All', 'backupplus' ) . '</a> | <a href="#" class="backupplus-deselect-all-tables">' . __( 'Deselect All', 'backupplus' ) . '</a></p>';
			$select_restore_tables .= $select_table_button;

			if ( $db_scan_timed_out || $php_max_input_vars_exceeded ) {
				if ( $db_scan_timed_out ) {
					$all_other_table_title = __( 'The database scan was taking too long and consequently the list of all tables in the database could not be completed.', 'backupplus' ) . ' ' . __( 'This option will ensure all tables not found will be backed up.', 'backupplus' );
				}
				if ( $php_max_input_vars_exceeded ) {
					$all_other_table_title = __( 'The amount of database tables scanned is near or over the php_max_input_vars value so some tables maybe truncated.', 'backupplus' ) . ' ' . __( 'This option will ensure all tables not found will be backed up.', 'backupplus' );
				}
				$select_restore_tables .= '<div style="margin-bottom:0.7rem;margin-top:0.7rem;"><input class="backupplus_restore_tables_options" id="backupplus_restore_table_udp_all_other_tables" checked="checked" type="checkbox" name="backupplus_restore_tables_options[]" value="udp_all_other_tables"> ';
				$select_restore_tables .= '<label for="backupplus_restore_table_udp_all_other_tables" title="' . $all_other_table_title . '">' . __( 'Include all tables not listed below', 'backupplus' ) . '</label></div>';
			}

			foreach ( $tables_found as $table ) {
				// Check if table should be skipped (composite key tables or user tables in single-to-multisite restore)
				$is_user_table = ( $skip_user_tables && isset( $old_table_prefix ) && '' !== $old_table_prefix && ( ( $table === $old_table_prefix . 'users' ) || ( $table === $old_table_prefix . 'usermeta' ) ) );
				$should_skip   = ( $skip_composite_tables && BackupPlus_Database_Utility::table_has_composite_private_key( $table ) ) || $is_user_table;

				$checked  = $should_skip ? '' : 'checked="checked"';
				$disabled = $is_user_table ? ' disabled="disabled"' : '';
				$style    = $is_user_table ? ' style="opacity: 0.5;"' : '';

				$select_restore_tables .= '<input class="backupplus_restore_tables_options" id="backupplus_restore_table_' . $table . ' ' . $checked . $disabled . ' type="checkbox" name="backupplus_restore_tables_options[]" value="' . $table . '"> ';
				$select_restore_tables .= '<label for="backupplus_restore_table_' . $table . '' . $style . '">' . $table . '</label><br>';
			}

			$select_restore_tables .= $select_table_button;

			$select_restore_tables .= '</div></div>';

			$info['addui'] = empty( $info['addui'] ) ? $select_restore_tables : $info['addui'] . '<br>' . $select_restore_tables;
		}

		// //need to make sure that we reset the file back to .crypt before clean temp files
		// $db_file = $decrypted_file['fullpath'].'.crypt';
		// unlink($decrypted_file['fullpath']);

		return array( $mess, $warn, $err, $info );
	}

	/**
	 * Get the current outgoing IP address. Use this wisely; of course, it's not guaranteed to always be the same.
	 *
	 * @param Boolean $use_ipv6_service True to check the IP address using the IPv6 service with IPv4 fallback, false to use the IPv4 service only
	 * @return String|Boolean - returns false upon failure
	 */
	public function get_outgoing_ip_address( $use_ipv6_service = false ) {
		$urls = array( 'https://ipvigilante.com/json' );
		if ( $use_ipv6_service ) {
			array_unshift( $urls, 'http://ip6.me/api' );
		}
		$urls = apply_filters( 'backupplus_get_outgoing_ip_address', $urls );
		foreach ( $urls as $url ) {
			$ip_lookup = wp_remote_get( $url, array( 'timeout' => 6 ) );
			if ( 200 === wp_remote_retrieve_response_code( $ip_lookup ) ) {
				$body = wp_remote_retrieve_body( $ip_lookup );
				$info = json_decode( $body, true );
				if ( is_array( $info ) ) {
					if ( ! empty( $info['status'] ) && ! empty( $info['data'] ) && 'success' === $info['status'] ) {
					}
					if ( ! empty( $info['data']['ipv4'] ) ) {
						return $info['data']['ipv4'];
					}
					if ( ! empty( $info['data']['ipv6'] ) ) {
						return $info['data']['ipv6'];
					}
				} elseif ( preg_match_all( '/([^"\',]+|"(?:[^"]|")*?"|\'(?:[^\']|\')*?\')?(?:,|$)/is', $body, $matches ) ) { // https://regex101.com/r/Q8XjT4/1/
					$matches[1][0] = strtolower( trim( $matches[1][0], ',\'" ' ) );
					if ( ( 'ipv4' === $matches[1][0] || 'ipv6' === $matches[1][0] ) && ! empty( $matches[1][1] ) ) {
						return trim( $matches[1][1], ',\'" ' );
					}
				}
			}
		}
		return false;
	}

	/**
	 * Get default substitute similar collate related to charset
	 *
	 * @param array  $db_supported_collations       Supported collations. It should contain result of 'SHOW COLLATION' query
	 * @param array  $db_unsupported_collate_unique Unsupported unique collates collection
	 * @param String $similar_type_charset          Charset for which need to get default collate substitution
	 * @return string $similar_type_collate default substitute collate which is best suitable or blank string
	 */
	public function get_similar_collate_related_to_charset( $db_supported_collations, $db_unsupported_collate_unique, $similar_type_charset ) {
		$similar_type_collate                       = '';
		$db_supported_collations_related_to_charset = array();
		foreach ( $db_supported_collations as $db_supported_collation => $db_supported_collations_info_obj ) {
			if ( isset( $db_supported_collations_info_obj->Charset ) && $db_supported_collations_info_obj->Charset == $similar_type_charset ) {
				$db_supported_collations_related_to_charset[] = $db_supported_collation;
			}
		}
		if ( ! empty( $db_supported_collations_related_to_charset ) ) {
			$similar_type_collate = BackupPlus_Manipulation_Functions::get_matching_str_from_array_elems( $db_unsupported_collate_unique, $db_supported_collations_related_to_charset, false );
		}
		return $similar_type_collate;
	}

	/**
	 * Get default substitute similar collate based on existing supported collates count in database backup file
	 *
	 * @param array $db_collates_found                                       All collates which have found in database backup file regardless whether they are supported or unsupported
	 * @param array $db_supported_collations                                 Supported collations. It should contain result of 'SHOW COLLATION' query
	 * @param array $db_supported_charsets_related_to_unsupported_collations All charset which are related to unsupported collation
	 *
	 * @return string $similar_type_collate default substitute collate which is best suitable or blank string
	 */
	public function get_similar_collate_based_on_ocuurence_count( $db_collates_found, $db_supported_collations, $db_supported_charsets_related_to_unsupported_collations ) {
		$similar_type_collate                        = '';
		$db_supported_collates_found_with_occurrence = array();
		foreach ( $db_collates_found as $db_collate_found ) {
			if ( isset( $db_supported_collations[ $db_collate_found ] ) ) {
				if ( isset( $db_supported_collates_found_with_occurrence[ $db_collate_found ] ) ) {
					$db_supported_collates_found_with_occurrence[ $db_collate_found ] = (int) $db_supported_collates_found_with_occurrence[ $db_collate_found ] + 1;
				} else {
					$db_supported_collates_found_with_occurrence[ $db_collate_found ] = 1;
				}
			}
		}
		if ( ! empty( $db_supported_collates_found_with_occurrence ) ) {
			arsort( $db_supported_collates_found_with_occurrence );
			if ( ! empty( $db_supported_charsets_related_to_unsupported_collations ) ) {
				foreach ( $db_supported_collates_found_with_occurrence as $db_supported_collate_with_occurrence => $occurrence_count ) {
					if ( isset( $db_supported_collations[ $db_supported_collate_with_occurrence ] ) && isset( $db_supported_collations[ $db_supported_collate_with_occurrence ]->Charset ) && in_array( $db_supported_collations[ $db_supported_collate_with_occurrence ]->Charset, $db_supported_charsets_related_to_unsupported_collations ) ) {
						$similar_type_collate = $db_supported_collate_with_occurrence;
						break;
					}
				}
			} else {
				$similar_type_collate = array_search( max( $db_supported_collates_found_with_occurrence ), $db_supported_collates_found_with_occurrence );
			}
		}
		return $similar_type_collate;
	}

	/**
	 * Retrieves current clean url for anchor link where href attribute value is not url (for ex. #div) or empty. Output is not escaped - caller should escape.
	 *
	 * @return String - current clean url
	 */
	public static function get_current_clean_url() {

		// CLI hooks can call this without a browser request.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return '';
		}

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$current_url = BackupPlus_Manipulation_Functions::fetch_superglobal( 'server', 'HTTP_REFERER' );
		} else {
			$url_prefix  = is_ssl() ? 'https' : 'http';
			$host        = BackupPlus_Manipulation_Functions::fetch_superglobal( 'server', 'HTTP_HOST', parse_url( network_site_url(), PHP_URL_HOST ) );
			$request_uri = BackupPlus_Manipulation_Functions::fetch_superglobal( 'server', 'REQUEST_URI', '' );
			$current_url = $url_prefix . '://' . $host . $request_uri;
		}
		$remove_query_args = array( 'state', 'action', 'oauth_verifier', 'nonce', 'backupplus_instance', 'access_token', 'user_id', 'backupplus_googledriveauth' );

		return BackupPlus_Manipulation_Functions::wp_unslash( remove_query_arg( $remove_query_args, $current_url ) );
	}

	/**
	 * TODO: Remove legacy storage setting keys from here
	 * These are used in 4 places (Feb 2016 - of course, you should re-scan the code to check if relying on this): showing current settings on the debug modal, wiping all current settings, getting a settings bundle to restore when migrating, and for relevant keys in POST-ed data when saving settings over AJAX
	 *
	 * @return Array - the list of keys
	 */
	public function get_settings_keys() {
		$general_keys = self::get_system_identifiers_list();
		$general_keys = array_merge(
			$general_keys['options'],
			array(
				'backupplus_dismiss_azure_legacy_storage_notice',
				'backupplus_tmp_googledrive_access_token',
				'backupplus_dismissedautobackup',
				'backupplus_dismissedexpiry',
				'backupplus_dismisseddashnotice',
				'backupplus_tour_cancelled_on',
				'backupplus_version',
			)
		);
		// N.B. backupplus_backup_history is not included here, as we don't want that wiped
		return array_diff( $general_keys, array( 'backupplus_restore_in_progress', 'backupplus_backup_history' ) );
	}

	/**
	 * Get a collection of unique names or keys used by the plugin to identify specific settings, options, scheduled tasks (crons), metadata, and transient data
	 *
	 * Note that the following list of setting keys are equivalent to the keys used in our code
	 * Adding a new one to the list below means there's already a key used somewhere else in the codebase, or it will be added later (i.e. it's reserved)
	 * Editing one of the keys below means that the corresponding key in the codebase should also be edited or updated for consistency
	 *
	 * @return Void
	 */
	public static function get_system_identifiers_list() {
		$list = array(
			'options'             => array(
				'backupplus_autobackup_default',
				'backupplus_dropbox',
				'backupplus_pcloud',
				'backupplus_googledrive',
				'dismissed_general_notices_until',
				'dismissed_review_notice',
				'dismissed_season_notices_until',
				'backupplus_interval',
				'backupplus_interval_increments',
				'backupplus_interval_database',
				'backupplus_retain',
				'backupplus_retain_db',
				'backupplus_database_encryption',
				'backupplus_service',
				'backupplus_googledrive_clientid',
				'backupplus_googledrive_secret',
				'backupplus_googledrive_remotepath',
				'backupplus_ftp',
				'backupplus_backblaze',
				'backupplus_server_address',
				'backupplus_dir',
				'backupplus_email',
				'backupplus_delete_local',
				'backupplus_debug_mode',
				'backupplus_include_plugins',
				'backupplus_include_themes',
				'backupplus_include_uploads',
				'backupplus_include_others',
				'backupplus_include_wpcore',
				'backupplus_include_wpcore_exclude',
				'backupplus_include_more',
				'backupplus_include_blogs',
				'backupplus_include_mu-plugins',
				'backupplus_auto_updates', // since WordPress 5.5, backupplus_auto_updates option is no longer used and has been removed from the code, but the HTML IDs which use the same name that represent the automatic update setting are still zealously preserved so this one cannot be removed
				'backupplus_include_others_exclude',
				'backupplus_include_uploads_exclude',
				'backupplus_lastmessage',
				'backupplus_googledrive_token',
				'backupplus_dropboxtk_request_token',
				'backupplus_dropboxtk_access_token',
				'backupplus_retain_extrarules',
				'backupplus_googlecloud',
				'backupplus_include_more_path',
				'backupplus_split_every',
				'backupplus_ssl_nossl',
				'backupplus_backupdb_nonwp',
				'backupplus_extradbs',
				'backupplus_combine_jobs_around',
				'backupplus_last_backup',
				'backupplus_starttime_files',
				'backupplus_starttime_db',
				'backupplus_startday_db',
				'backupplus_startday_files',
				'backupplus_sftp',
				'backupplus_s3',
				'backupplus_s3generic',
				'backupplus_dreamhost',
				'backupplus_s3generic_login',
				'backupplus_s3generic_pass',
				'backupplus_s3generic_remote_path',
				'backupplus_s3generic_endpoint',
				'backupplus_webdav',
				'backupplus_openstack',
				'backupplus_onedrive',
				'backupplus_azure',
				'backupplus_cloudfiles',
				'backupplus_cloudfiles_user',
				'backupplus_cloudfiles_apikey',
				'backupplus_cloudfiles_path',
				'backupplus_cloudfiles_authurl',
				'backupplus_ssl_useservercerts',
				'backupplus_ssl_disableverify',
				'backupplus_s3_login',
				'backupplus_s3_pass',
				'backupplus_s3_remote_path',
				'backupplus_dreamobjects_login',
				'backupplus_dreamobjects_pass',
				'backupplus_dreamobjects_remote_path',
				'backupplus_dreamobjects',
				'backupplus_report_warningsonly',
				'backupplus_report_wholebackup',
				'backupplus_report_dbbackup',
				'backupplus_log_syslog',
				'backupplus_extradatabases',
				'backupplus_dismiss_admin_warning_litespeed',
				'backupplus_dismiss_admin_warning_pclzip',
				'backupplus_dismiss_phpseclib_notice',
				'backupplus_restore_in_progress',
				'backupplus_backup_history',
			),
			'prefixed_options'    => array(
				'backupplus_jobdata_%',
				'backupplus_last_scheduled_%',
				'backupplus_lock_%',
				'backupplus_%',
			),
			'meta'                => array(
				'backupplus_oneshotnonce',
				'backupplus-addons_options',
				// leave the site ID until the x-spm-duplicate-of handler is implemented in our plugin
				// 'backupplus-addons_siteid',
				'external_updates-backupplus',
				'backupplus_lastmessage',
			),
			'transients'          => array(
				'backupplus_initial_resume_interval',
				'upaddons_remote',
				'backupplus_dashboard_news',
			),
			'prefixed_transients' => array(
				'ud_forgt_%',
				'ud_rsenddck_%',
				'backupplus_%',
			),
			'crons'               => array(
				'backupplus_backup',
				'backupplus_backup_database',
				'backupplus_backup_increments',
				'backupplus_clean_temporary_files',
				'puc_cron_check_updates-backupplus',
				'backupplus_backup_resume',
				'ud_wp_maybe_auto_update',
			),
		);

		// Provider settings use BackupPlus-prefixed option names.
		global $backupplus;
		if ( isset( $backupplus->backup_methods ) && is_array( $backupplus->backup_methods ) ) {
			foreach ( array_keys( $backupplus->backup_methods ) as $service ) {
				$list['options'][] = 'backupplus_' . $service;
			}
			$list['options'] = array_values( array_unique( $list['options'] ) );
		}

		$udaddons2_options = get_site_option( 'backupplus-addons_options' );
		if ( ! empty( $udaddons2_options['email'] ) ) {
			$list['transients'][] = 'udaddons_connect_' . substr( md5( $udaddons2_options['email'] ), 0, 23 );
		}
		return $list;
	}

	/**
	 * A function that works through the array passed to it and gets a list of all the tables from that database and puts the information in an array ready to be parsed and output to html.
	 *
	 * @param  Array $dbsinfo an array that contains information about each database, the default 'wp' array is just an empty array, but other entries can be added so that this method can get tables from other databases the array structure for this would be array('wp' => array(), 'TestDB' => array('host' => '', 'user' => '', 'pass' => '', 'name' => '', 'prefix' => ''))
	 *                          note that the extra tables array key must match the database name in the array note that the extra tables array key must match the database name in the array
	 * @return Array - databases and their table names
	 */
	public function get_database_tables( $dbsinfo = array( 'wp' => array() ) ) {

		global $wpdb;

		if ( ! class_exists( 'BackupPlus_Database_Utility' ) ) {
			backupplus_try_include_file( 'includes/class-database-utility.php', 'include_once' );
		}

		$dbhandle        = '';
		$db_tables_array = array();

		foreach ( $dbsinfo as $key => $value ) {
			if ( 'wp' == $key ) {
				// The unfiltered table prefix - i.e. the real prefix that things are relative to
				$table_prefix_raw = $this->get_table_prefix( false );
				$dbhandle         = $wpdb;
			} else {
				$dbhandle = new BackupPlus_WPDB_OtherDB_Utility( $dbsinfo[ $key ]['user'], $dbsinfo[ $key ]['pass'], $dbsinfo[ $key ]['name'], $dbsinfo[ $key ]['host'] );
				if ( ! empty( $dbhandle->error ) ) {
					return $this->log_wp_error( $dbhandle->error );
				}
				$table_prefix_raw = $dbsinfo[ $key ]['prefix'];
			}

			// SHOW FULL - so that we get to know whether it's a BASE TABLE or a VIEW
			$all_tables = $dbhandle->get_results( 'SHOW FULL TABLES', ARRAY_N );

			if ( empty( $all_tables ) && ! empty( $dbhandle->last_error ) ) {
				$all_tables = $dbhandle->get_results( 'SHOW TABLES', ARRAY_N );
				$all_tables = array_map( array( $this, 'cb_get_name_base_type' ), $all_tables );
			} else {
				$all_tables = array_map( array( $this, 'cb_get_name_type' ), $all_tables );
			}

			// If this is not the WP database, then we do not consider it a fatal error if there are no tables
			if ( 'wp' == $key && 0 == count( $all_tables ) ) {
				return $this->log_wp_error( 'No tables found in wp database.' );
				die;
			}

			// Put the options table first
			BackupPlus_Database_Utility::init( $key, $table_prefix_raw, $dbhandle );
			usort( $all_tables, array( 'BackupPlus_Database_Utility', 'backup_db_sorttables' ) );

			$all_table_names         = array_map( array( $this, 'cb_get_name' ), $all_tables );
			$db_tables_array[ $key ] = $all_table_names;
		}

		return $db_tables_array;
	}

	/**
	 * Returns the member of the array with key (int)0, as a new array. This function is used as a callback for array_map().
	 *
	 * @param Array $a - the array
	 *
	 * @return Array - with keys 'name' and 'type'
	 */
	private function cb_get_name_base_type( $a ) {
		return array(
			'name' => $a[0],
			'type' => 'BASE TABLE',
		);
	}

	/**
	 * Returns the members of the array with keys (int)0 and (int)1, as part of a new array.
	 *
	 * @param Array $a - the array
	 *
	 * @return Array - keys are 'name' and 'type'
	 */
	private function cb_get_name_type( $a ) {
		return array(
			'name' => $a[0],
			'type' => $a[1],
		);
	}

	/**
	 * Returns the member of the array with key (string)'name'. This function is used as a callback for array_map().
	 *
	 * @param Array $a - the array
	 *
	 * @return Mixed - the value with key (string)'name'
	 */
	private function cb_get_name( $a ) {
		return $a['name'];
	}

	/**
	 * Get log message for permission failure
	 *
	 * @param String $path                            full path of file or folder
	 * @param String $log_message_prefix              action which is performed to path
	 * @param String $directory_prefix_in_log_message Directory Prefix. It should be either "Parent" or "Destination"
	 * @return string|boolean log message (HTML). If posix function doesn't exist, It returns false
	 */
	public function log_permission_failure_message( $path, $log_message_prefix, $directory_prefix_in_log_message = 'Parent' ) {
		if ( $this->do_posix_functions_exist() ) {
			$stat_data    = stat( $path );
			$log_message  = $log_message_prefix . ': Failed. ';
			$log_message .= $directory_prefix_in_log_message . ' Directory UID=' . $stat_data['uid'] . ', GID=' . $stat_data['gid'] . '. ';
			$log_message .= $this->get_log_message_for_current_uid_and_gid();
			return $log_message;
		} else {
			return false;
		}
	}

	/**
	 * Get log message for current uid and gid
	 *
	 * @return String log message of current process (HTML)
	 */
	private function get_log_message_for_current_uid_and_gid() {
		$log_message  = 'Effective/real user IDs of the current process: ' . posix_geteuid() . '/' . posix_getuid() . '. ';
		$log_message .= 'Effective/real group IDs of the current process: ' . posix_getegid() . '/' . posix_getgid() . '. ';
		return $log_message;
	}

	/**
	 * Restore any previously-removed autoloaders
	 */
	public function restore_composer_autoloaders() {
		foreach ( $this->removed_autoloaders as $callable ) {
			if ( is_callable( $callable, false, $callable_name ) ) {
				$this->log( "Clean-up: re-registering composer autoloader: $callable_name" );
				spl_autoload_register( $callable, false );
			}
		}
		$this->removed_autoloaders = array();
	}

	/**
	 * Remove any potentially clashing composer PSR4 autoloaders. Only to be used inside a backup when no other plugins' libraries should be needed
	 *
	 * @param Array $prefixes
	 */
	public function potentially_remove_composer_autoloaders( $prefixes ) {

		if ( ! defined( 'BACKUPPLUS_REMOVE_COMPOSER_AUTOLOADERS' ) || ! BACKUPPLUS_REMOVE_COMPOSER_AUTOLOADERS ) {
			return;
		}

		$functions = spl_autoload_functions();
		foreach ( $functions as $callable ) {
			if ( ! is_array( $callable ) || ! isset( $callable[0] ) || ! is_object( $callable[0] ) ) {
				continue;
			}
			if ( ! is_a( $callable[0], 'Composer\Autoload\ClassLoader' ) || ! is_callable( array( $callable[0], 'getPrefixesPsr4' ) ) ) {
				continue;
			}
			$prefixes_psr4 = $callable[0]->getPrefixesPsr4();
			if ( ! is_array( $prefixes_psr4 ) ) {
				continue;
			}
			foreach ( $prefixes as $prefix ) {
				if ( ! isset( $prefixes_psr4[ $prefix ] ) ) {
					continue;
				}
				$is_ud = false;
				if ( is_array( $prefixes_psr4[ $prefix ] ) ) {
					foreach ( $prefixes_psr4[ $prefix ] as $path ) {
						if ( false !== strpos( BackupPlus_Manipulation_Functions::wp_normalize_path( $path ), '/' . basename( BackupPlus_Manipulation_Functions::wp_normalize_path( BACKUPPLUS_DIR ) ) . '/vendor/' ) ) {
							$is_ud = true;
						}
					}
				}
				if ( $is_ud ) {
					continue;
				}
				if ( is_callable( $callable, false, $callable_name ) ) {
					$this->log( "Conflict prevention: de-registering composer autoloader: $callable_name" );
				}
				$this->removed_autoloaders[] = $callable;
				spl_autoload_unregister( $callable );
				break;
			}
		}
	}

	/**
	 * Try to deal with other plugins with incompatible versions and bugs
	 */
	public function mitigate_guzzle_autoloader_conflicts() {
		// Work round bug in the JetPack autoloader which loads a file in a different namespace
		$potentially_include_in = array( 'guzzlehttp/guzzle', 'guzzlehttp/promises', 'guzzlehttp/psr7' );
		foreach ( $potentially_include_in as $package ) {
			$file = BACKUPPLUS_DIR . '/vendor/' . $package . '/src/functions_include.php';
			// Avoid conflicting with Google Ads and Listings which has already loaded this function
			if ( 'guzzlehttp/guzzle' == $package && function_exists( '\GuzzleHttp\choose_handler' ) ) {
				continue;
			}
			if ( file_exists( $file ) ) {
				include_once $file;
			}
		}
	}

	/**
	 * Checks whether POSIX functions exists or not
	 *
	 * @return boolean true if POSIX functions exists or not
	 */
	private function do_posix_functions_exist() {
		return function_exists( 'posix_geteuid' ) && function_exists( 'posix_getuid' ) && function_exists( 'posix_getegid' ) && function_exists( 'posix_getgid' );
	}

	/**
	 * Wipe state-related data (e.g. on wiping settings, or on a restore). Note that there is some internal knowledge within the method below of how it is being used (if not including locks, then check for an active job)
	 *
	 * @param Boolean $include_locks Whether to also wipe out data other than just backupplus_jobdata (e.g. BackupPlus semaphore, lock, schedule, etc.)
	 * @param String  $table         What table the data is in. It recognises only two tables ('options', 'sitemeta'), the default is 'options'
	 */
	public function wipe_state_data( $include_locks = false, $table = 'options' ) {
		// These aren't in get_settings_keys() because they are always in the options table, regardless of context
		global $wpdb;
		switch ( $table ) {
			case 'sitemeta':
				$table = $wpdb->sitemeta;
				$field = 'meta_key';
				break;
			default:
				$table = $wpdb->options;
				$field = 'option_name';
				// if multisite do we need site_id column in the where clause?
				break;
		}
		if ( ! class_exists( 'BackupPlus_Database_Utility' ) ) {
			backupplus_try_include_file( 'includes/class-database-utility.php', 'include_once' );
		}
		$escaped_table_name = BackupPlus_Database_Utility::escape_table_name( $table );
		if ( $include_locks ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM $escaped_table_name WHERE ($field LIKE %s OR $field LIKE %s OR $field LIKE %s OR $field LIKE %s OR $field LIKE %s OR $field LIKE %s)", BackupPlus_Database_Utility::esc_like( 'backupplus_unlocked_' ) . '%', BackupPlus_Database_Utility::esc_like( 'backupplus_locked_' ) . '%', BackupPlus_Database_Utility::esc_like( 'backupplus_last_lock_time_' ) . '%', BackupPlus_Database_Utility::esc_like( 'backupplus_semaphore_' ) . '%', BackupPlus_Database_Utility::esc_like( 'backupplus_jobdata_' ) . '%', BackupPlus_Database_Utility::esc_like( 'backupplus_last_scheduled_' ) . '%' ) );// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is safely escaped via escape_table_name().
		} else {
			if ( ! empty( $this->nonce ) ) {
				$sql = $wpdb->prepare( "DELETE FROM $escaped_table_name WHERE $field LIKE %s AND $field != %s", BackupPlus_Database_Utility::esc_like( 'backupplus_jobdata_' ) . '%', "backupplus_jobdata_{$this->nonce}" );// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely escaped via escape_table_name().
			} else {
				$sql = $wpdb->prepare( "DELETE FROM $escaped_table_name WHERE $field LIKE %s", BackupPlus_Database_Utility::esc_like( 'backupplus_jobdata_' ) . '%' );// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely escaped via escape_table_name().
			}
			$wpdb->query( $sql );// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- Table name is safely escaped via escape_table_name(), $wpdb->prepare() not recommended for SQL identifiers.
		}
	}

	/**
	 * Checks whether debug mode is on or not. If it is on then unminified script will be used.
	 *
	 * @return boolean true indicate use the unminified script
	 */
	public function use_unminified_scripts() {
		return BackupPlus_Options::get_backupplus_option( 'backupplus_debug_mode' ) || ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG );
	}

	/**
	 * This function has checks in place to see if a restore is still in progress
	 * Currently used in this->block_updates_during_restore_progress and admin->print_restore_in_progress_box_if_needed
	 *
	 * @uses $_REQUEST['action']
	 * @param Int $job_time_greater_than Specify the time in seconds.  Default is 120 seconds but function like block_updates_during_restore_progress has a 1 second time set as we want to check as soon as a restore is kicked off
	 * @return void|array There is a possibility if there is no restore in progress this can return a void.  However, in every other case, it will return an array.
	 */
	public function check_restore_progress( $job_time_greater_than = 120 ) {
		$restore_progress           = array();
		$restore_progress['status'] = false;
		$restore_in_progress        = get_site_option( 'backupplus_restore_in_progress' );
		if ( empty( $restore_in_progress ) ) {
			return;
		}

		$restore_jobdata = $this->jobdata_getarray( $restore_in_progress );
		if ( is_array( $restore_jobdata ) && ! empty( $restore_jobdata ) ) {
			// Only print if within the last 24 hours; and only after 2 minutes
			$action = BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'action' );
			if ( isset( $restore_jobdata['job_type'] ) && 'restore' == $restore_jobdata['job_type'] && isset( $restore_jobdata['second_loop_entities'] ) && ! empty( $restore_jobdata['second_loop_entities'] ) && isset( $restore_jobdata['job_time_ms'] ) && ( time() - $restore_jobdata['job_time_ms'] > $job_time_greater_than || ( defined( 'BACKUPPLUS_RESTORE_PROGRESS_ALWAYS_SHOW' ) && BACKUPPLUS_RESTORE_PROGRESS_ALWAYS_SHOW ) ) && time() - $restore_jobdata['job_time_ms'] < 86400 && ( ! $action || ( 'backupplus_restore' != $action && 'backupplus_restore_continue' != $action ) ) ) {

				$restore_progress['status']              = true;
				$restore_progress['restore_jobdata']     = $restore_jobdata;
				$restore_progress['restore_in_progress'] = $restore_in_progress;

				return $restore_progress;
			}
		}
	}

	/**
	 * Checking to see if a restore is in progress before
	 * Turning off WP updates while restoration is in progress
	 */
	public function block_updates_during_restore_progress() {
		$check_restore_progress = $this->check_restore_progress( 1 );
		// Check to see if the restore is still in progress
		if ( is_array( $check_restore_progress ) && true == $check_restore_progress['status'] ) {
			add_filter( 'pre_site_transient_update_core', '__return_false' ); // Disable WordPress core updates
			add_filter( 'pre_site_transient_update_plugins', '__return_false' ); // Disable WordPress plugin updates
			add_filter( 'pre_site_transient_update_themes', '__return_false' ); // Disable WordPress themes updates
		}
	}

	/**
	 * Retrieve the list of server (Apache, Nginx, PHP, etc..) configuration file names
	 */
	public function server_configuration_file_list() {
		$server_config_filenames = array(
			'.user.ini',
			'.htaccess',
		);
		// the default value for user_ini.filename setting in PHP.ini file is '.user.ini' but this could be set to use different file name
		$server_config_filenames[] = ini_get( 'user_ini.filename' ); // phpcs:ignore PHPCompatibility.IniDirectives.NewIniDirectives.user_ini_filenameFound -- Ignore ini_get function compatibility.
		$server_config_filenames   = array_unique( $server_config_filenames );
		return $server_config_filenames;
	}

	/**
	 * Check what hosting company that this plugin is installed onto and if there appear to be any restriction being applied to it
	 *
	 * @return Array An array of information regarding the hosting company or empty array if this method fails to recognise the hosting company
	 */
	public function get_hosting_info() {

		$hosting_company = array(
			'name'        => '',
			'website'     => '',
			'restriction' => array(),
		);

		if ( array_key_exists( 'KINSTA_CACHE_ZONE', $_SERVER ) ) {
			$hosting_company = array(
				'name'        => 'Kinsta',
				'website'     => 'kinsta.com',
				'restriction' => array(
					'only_one_backup_per_month',
					'only_one_incremental_per_day',
				),
			);
		}

		return apply_filters( 'backupplus_get_hosting_info', $hosting_company );
	}

	/**
	 * Check whether the hosting provider has some restriction
	 *
	 * @param String|Array $restriction An array or string of restriction
	 * @return Boolean True if the hosting provider has the given restriction, false otherwise
	 */
	public function is_restricted_hosting( $restriction ) {

		$restriction = (array) $restriction;

		$hosting_company = $this->get_hosting_info();

		if ( empty( $hosting_company ) ) {
			return false;
		}

		foreach ( $restriction as $rstc ) {
			if ( in_array( $rstc, $hosting_company['restriction'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether the hosting has a number of backups restrictions that can be created at a particular time and whether that number has reached the limit or the time elapsed has passed the limit
	 */
	public function is_hosting_backup_limit_reached() {
		$res         = array();
		$last_backup = BackupPlus_Options::get_backupplus_option( 'backupplus_last_backup', array() );
		if ( empty( $last_backup ) ) {
			$last_backup = array();
		}
		$current_time = time();
		if ( ! empty( $last_backup['incremental_backup_time'] ) ) {
			// $next_day_from_last_backup = strtotime(gmdate('Y-m-d', (int) $last_backup['backup_time'])) + 86400;
			$next_24hours_from_last_backup = strtotime( gmdate( 'Y-m-d H:i:s', (int) $last_backup['incremental_backup_time'] ) ) + 86400;
			// one incremental per day and the time has gone 24 hours past the last incremental backup time
			if ( $this->is_restricted_hosting( 'only_one_incremental_per_day' ) && $current_time < $next_24hours_from_last_backup ) {
				$res[] = 'only_one_incremental_per_day';
			}
		}
		if ( ! empty( $last_backup['nonincremental_backup_time'] ) ) {
			// $first_day_of_next_month_from_last_backup = strtotime(gmdate('Y-m-t', (int) $last_backup['backup_time']))+86400;
			$next_thirty_days_from_last_backup = strtotime( gmdate( 'Y-m-d H:i:s', (int) $last_backup['nonincremental_backup_time'] ) ) + ( 86400 * 30 );
			// Check whether the hosting provider permits only one backup per month and whether the time has gone 30 days past the last backup time
			if ( $this->is_restricted_hosting( 'only_one_backup_per_month' ) && $current_time < $next_thirty_days_from_last_backup ) {
				$res[] = 'only_one_backup_per_month';
			}
		}
		return $res;
	}

	/**
	 * Maintain compatibility on all versions between WordPress and BackupPlus, specifically since WordPress 5.5
	 */
	public function wordpress_55_updates_potential_migration() {
		// Due to the new WP's auto-updates interface in WordPress version 5.5, we need to maintain the auto update compatibility on all versions of WordPress and BackupPlus
		$udp_saved_version       = BackupPlus_Options::get_backupplus_option( 'backupplus_version' );
		$backupplus_auto_updates = BackupPlus_Options::get_backupplus_option( 'backupplus_auto_updates' );
		if ( ! $udp_saved_version || version_compare( $udp_saved_version, '1.16.34', '<=' ) || ( version_compare( $udp_saved_version, '2.0.0', '>=' ) && version_compare( $udp_saved_version, '2.16.34', '<=' ) ) || null !== $backupplus_auto_updates ) {
			$this->replace_auto_updates_option();
		}
	}

	/**
	 * Remove the use of backupplus_auto_updates option/meta (single & multisite) and replace it with auto_update_plugins site option that is used in WordPress's core since version 5.5
	 * This needs to be done in order to maintain auto-updates compatibility between WordPress and BackupPlus and to synchronise the auto-updates setting for both
	 */
	private function replace_auto_updates_option() {
		$old_setting_value = BackupPlus_Options::get_backupplus_option( 'backupplus_auto_updates' );
		BackupPlus_Options::delete_backupplus_option( 'backupplus_auto_updates' );
		$new_setting_value = (array) get_site_option( 'auto_update_plugins', array() );
		if ( ! empty( $old_setting_value ) ) {
			$new_setting_value[] = BACKUPPLUS_PLUGIN_SLUG;
		}
		$new_setting_value = array_unique( $new_setting_value );
		update_site_option( 'auto_update_plugins', $new_setting_value );
	}

	/**
	 * Set the plugin's automatic updates setting to either on or off by removing/adding plugin basename from/into the auto_update_plugins option
	 *
	 * @param Mixed $value The new value which auto_update_plugins option value is replaced with
	 */
	public function set_automatic_updates( $value ) {
		$auto_update_plugins = (array) get_site_option( 'auto_update_plugins', array() );
		if ( ! empty( $value ) ) {
			$auto_update_plugins[] = BACKUPPLUS_PLUGIN_SLUG;
			$auto_update_plugins   = array_unique( $auto_update_plugins );
		} else {
			$auto_update_plugins = array_diff( $auto_update_plugins, array( BACKUPPLUS_PLUGIN_SLUG ) );
		}
		update_site_option( 'auto_update_plugins', $auto_update_plugins );
	}

	/**
	 * Check whether the automatic-updates is set for BackupPlus
	 *
	 * @return Boolean True if set, false otherwise
	 */
	public function is_automatic_updating_enabled() {
		$auto_update_plugins = (array) get_site_option( 'auto_update_plugins', array() );
		return in_array( BACKUPPLUS_PLUGIN_SLUG, $auto_update_plugins, true );
	}

	/**
	 * Perform conditional checking of two values with the specified operator
	 *
	 * @param Mixed  $value1   the first value to compare
	 * @param String $operator the operator that is used for comparison of the two values
	 * @param Mixed  $value2   the second value to compare
	 *
	 * @return Boolean true if the first value matches against the second value, false otherwise
	 */
	public function if_cond( $value1, $operator, $value2 ) {
		switch ( strtolower( $operator ) ) {
			case 'is':
			case '==':
				return $value1 == $value2;
				break;
			case 'is_not':
			case '!=':
				return $value1 != $value2;
				break;
			default:
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error messages should be escaped when caught and printed.
				throw new Exception( __METHOD__ . ': Unsupported (' . $operator . ') operator', 1 );
				break;
		}
	}

	/**
	 * Return a listing of days of the week
	 *
	 * @param Boolean $respect_start_of_week whether to use the WordPress's start_of_week setting
	 * @return Array the days of the week
	 */
	public function list_days_of_the_week( $respect_start_of_week = true ) {
		global $wp_locale;
		$days_of_the_week = array();
		$i                = $j = $respect_start_of_week ? (int) get_option( 'start_of_week', 1 ) : 1;
		while ( $i < $j + 7 ) { // 7 days
			$days_of_the_week[] = array(
				'index' => $i % 7,
				'value' => $wp_locale->get_weekday( $i % 7 ),
			);
			++$i;
		}
		return $days_of_the_week;
	}

	/**
	 * Identify whether the minimum system requirements for PHPSecLib are met
	 *
	 * @return Boolean True if the minimum system requirements are met, false otherwise
	 */
	public function phpseclib_requirements_met() {
		if ( version_compare( PHP_VERSION, BACKUPPLUS_PHPSECLIB_MIN_PHP_VERSION, '>=' ) ) {
			return true;
		}
		$active_phpseclib_related_features = $this->list_active_features_requiring_phpseclib();
		if ( ! empty( $active_phpseclib_related_features ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Log warnings regarding phpseclib requirements that are not met
	 */
	public function maybe_log_phpseclib_warnings() {
		global $backupplus;
		static $logged = false;
		$job_type      = $backupplus->jobdata_get( 'job_type' );
		if ( ( 'backup' === $job_type || 'restore' === $job_type ) && ! $logged ) {
			$backupplus->log( $this->get_phpseclib_warning_msg(), 'warning' );
			$logged = true; // prevent multiple warnings being logged
		}
	}

	/**
	 * Retrieve phpseclib warning message that will be shown at the front-end and/or included in the backup/restoration logs
	 *
	 * @return String The warning message (filterable)
	 */
	public function get_phpseclib_warning_msg() {
		$phpseclib_related_features        = array_merge( $this->backup_methods, array( 'dbencryption' => 'Backup Encryption' ) );
		$active_phpseclib_related_features = array_intersect_key( $phpseclib_related_features, array_flip( $this->list_active_features_requiring_phpseclib() ) );
		return sprintf(
			/* translators: %s: PHP version */
			__( 'Your site is running PHP version %s.', 'backupplus' ),
			PHP_VERSION
		) . ' ' .
		__( 'Some functionality you have enabled is deprecated on this PHP version, and future releases of BackupPlus will require a newer version of PHP to keep it working.', 'backupplus' ) . ' ' .
		sprintf(
			/* translators: %s: Affected feature list */
			__( 'This affects: %s.', 'backupplus' ),
			implode( ', ', $active_phpseclib_related_features )
		) . ' ' .
		sprintf(
			/* translators: %s: Minimum recommended PHP version */
			__( 'We recommend asking your web host about updating PHP to version %s or higher.', 'backupplus' ),
			BACKUPPLUS_PHPSECLIB_MIN_PHP_VERSION
		);
	}

	/**
	 * Get plugin features requiring phpseclib which are active
	 *
	 * @return Array an array of active features
	 */
	protected function list_active_features_requiring_phpseclib() {
		$active_features     = array();
		$encryption_config   = BackupPlus_Options::get_backupplus_option( 'backupplus_database_encryption', array() );
		$backupplus_services = $this->get_canonical_service_list();
		foreach ( $backupplus_services as $service ) {
			$provider = BackupPlus_Storage_Methods_Interface::get_storage_object( $service );
			if ( ! is_wp_error( $provider ) && $provider->supports_feature( 'phpseclib' ) ) {
				$active_features[] = $service;
			}
		}
		if ( isset( $encryption_config['mode'] ) && 'age' === $encryption_config['mode'] ) {
			$active_features[] = 'dbencryption';
		}
		return $active_features;
	}

	/**
	 * Retrieve site name by outputing the hostname of the URL parsed
	 *
	 * @return String Site name
	 */
	private function get_site_name() {
		return preg_replace( '/^www\./i', '', strtolower( parse_url( network_site_url(), PHP_URL_HOST ) ) );
	}


	/**
	 * Displays a checkbox for selecting a remote storage service.
	 *
	 * @param string $backup_using   The label describing the backup method to be used.
	 * @param string $method         The method or service identifier (e.g., 'ftp', 's3').
	 * @param string $multi          Indicates whether multiple services can be selected (e.g., 'multi').
	 * @param mixed  $active_service The currently active service(s), can be a string or an array of active methods.
	 * @param string $description    A brief description of the service for display purposes.
	 * @param bool   $disabled       Whether the checkbox should be disabled (default: false).
	 */
	public function show_remote_storage( $backup_using, $method, $multi, $active_service, $description, $disabled = false ) {
		/* translators: %s: Backup method */
		$backup_using = esc_attr( sprintf( __( 'Backup using %s?', 'backupplus' ), $backup_using ) );
		$class        = esc_attr( "backupplus_servicecheckbox $method $multi" );
		$id           = esc_attr( "backupplus_servicecheckbox_$method" );
		$value        = esc_attr( $method );
		$description  = esc_attr( $description );

		echo "<input aria-label=\"$backup_using\" name=\"backupplus_service[]\" class=\"$class\" id=\"$id\" type=\"checkbox\" value=\"$value\""; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- All the variables are already escaped.
		if ( ! $disabled && ( $active_service === $method || ( is_array( $active_service ) && in_array( $method, $active_service ) ) ) ) {
			echo ' checked="checked"';
		}
		if ( $disabled ) {
			echo ' disabled="disabled"';
		}
		echo " data-labelauty=\"$description\">"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The '$description' variable is already escaped.
	}

	/**
	 * Modifies the parent file value.
	 *
	 * This modification is necessary to prevent the active menu selection from pointing to the
	 * old "Settings" menu and to ensure correct menu highlighting.
	 *
	 * @param string $parent_file The current parent file value.
	 *
	 * @return string The modified parent file value.
	 */
	public static function parent_file( $parent_file ) {
		// Set the global variable 'self' and update the parent file to BackupPlus_Options::PARENT_FILE
		$GLOBALS['self'] = $parent_file = BackupPlus_Options::PARENT_FILE;

		return $parent_file;
	}

	/**
	 * Unserialize data while maintaining compatibility across PHP versions due to different number of arguments required by PHP's "unserialize" function
	 *
	 * @param string        $serialized_data Data to be unserialized, should be one that is already serialized
	 * @param boolean|array $allowed_classes Either an array of class names which should be accepted, false to accept no classes, or true to accept all classes
	 * @param integer       $max_depth       The maximum depth of structures permitted during unserialization, and is intended to prevent stack overflows
	 * @return mixed Unserialized data can be any of types (integer, float, boolean, string, array or object)
	 */
	public static function unserialize( $serialized_data, $allowed_classes = false, $max_depth = 0 ) {
		static $polyfill_unserialize_loaded = false;
		if ( version_compare( PHP_VERSION, '5.2', '<=' ) ) {
			$result = unserialize( $serialized_data ); // For PHP 5.2 users, the search-replace feature has been removed, meaning that any input provided in this context will not undergo search-replace processing
		} else {
			if ( ! $polyfill_unserialize_loaded ) {
				if ( ! class_exists( 'Brumann\Polyfill\DisallowedClassesSubstitutor' ) ) {
					backupplus_try_include_file( 'vendor/brumann/polyfill-unserialize/src/DisallowedClassesSubstitutor.php', 'require_once' );
				}
				if ( ! class_exists( 'Brumann\Polyfill\Unserialize' ) ) {
					backupplus_try_include_file( 'vendor/brumann/polyfill-unserialize/src/Unserialize.php', 'require_once' );
				}
				$polyfill_unserialize_loaded = true;
			}
			$result = call_user_func(
				array( 'Brumann\Polyfill\Unserialize', 'unserialize' ),
				$serialized_data,
				array(
					'allowed_classes' => $allowed_classes,
					'max_depth'       => $max_depth,
				)
			);
		}
		return $result;
	}
}
