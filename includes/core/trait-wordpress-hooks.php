<?php

/** Callbacks registered on WordPress hooks: menus, URL actions, plugin loading and third-party integrations. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Core_WordPress_Hooks {

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

	/**
	 * This function may get called multiple times, so write accordingly
	 */
	public function admin_menu() {
		// The other sites of a network have no BackupPlus screens: they are all in Network Admin
		if ( ! BackupPlus_Multisite::available() ) {
			return;
		}
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
	 * Initialize all settings required for the plugin to work properly
	 */
	public function initialize_required_settings() {
		// Tell WordPress where to find the translations
		$mofile = BACKUPPLUS_DIR . '/languages/backupplus-' . get_locale() . '.mo';
		if ( is_readable( $mofile ) ) {
			load_textdomain( 'backupplus', $mofile );
		} else {
			load_plugin_textdomain( 'backupplus', false, basename( BACKUPPLUS_DIR ) . '/languages/' );
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
		// The restore putting the site into maintenance carries on through WP-Cron and the super administrator's requests
		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) || ( is_admin() && BackupPlus_Options::user_can_manage() ) ) {
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
		// Set the global variable 'self' and update the parent file to the top-level BackupPlus menu entry
		$GLOBALS['self'] = $parent_file = BackupPlus_Options::parent_file();

		return $parent_file;
	}
}
