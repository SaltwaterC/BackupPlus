<?php

/** Admin notices and warnings shown around the plugin pages. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Admin_Notices {

	/**
	 * Add actions for any needed dashboard notices for remote storage services
	 *
	 * @param String|Array $services - a list of services, or single service
	 */
	private function setup_all_admin_notices_global( $services ) {

		global $backupplus;

		$all_services       = BackupPlus_Storage_Methods_Interface::get_enabled_storage_objects_and_ids( $backupplus->get_canonical_service_list() );
		$available_services = (array) $backupplus->get_canonical_service_list();

		$this->storage_service_without_settings        = array();
		$this->storage_service_with_partial_settings   = array();
		$this->storage_service_without_addons_settings = array();
		$this->auth_instance_ids                       = array();

		foreach ( $all_services as $method => $sinfo ) {

			if ( empty( $sinfo['object'] ) || empty( $sinfo['instance_settings'] ) || ! is_callable( array( $sinfo['object'], 'options_exist' ) ) ) {
				continue;
			}
			foreach ( $sinfo['instance_settings'] as $opt ) {
				if ( ! $sinfo['object']->options_exist( $opt ) ) {
					if ( isset( $opt['auth_in_progress'] ) ) {
						if ( ! is_a( $sinfo['object'], 'BackupPlus_BackupModule_AddonNotYetPresent' ) && in_array( $method, $available_services ) ) {
							$this->storage_service_with_partial_settings[ $method ] = $backupplus->backup_methods[ $method ];
						} else {
							$this->storage_service_without_addons_settings[ $method ] = $backupplus->backup_methods[ $method ];
						}
					} else {
						if ( is_a( $sinfo['object'], 'BackupPlus_BackupModule_AddonNotYetPresent' ) ) {
							$this->storage_service_without_addons_settings[ $method ] = $backupplus->backup_methods[ $method ];
						} elseif ( in_array( $method, $available_services ) ) {
							$this->storage_service_without_settings[] = $backupplus->backup_methods[ $method ];
						}
					}
				}
			}
		}

		if ( ! empty( $this->storage_service_without_addons_settings ) ) {
			add_action( 'all_admin_notices', array( $this, 'show_admin_warning_if_remote_storage_without_addons' ) );
		}

		foreach ( array_intersect( (array) $services, $available_services ) as $method ) {
			$provider = BackupPlus_Storage_Methods_Interface::get_storage_object( $method );
			if ( is_wp_error( $provider ) || is_a( $provider, 'BackupPlus_BackupModule_AddonNotYetPresent' ) ) {
				continue;
			}
			if ( ! $provider->supports_feature( 'multi_options' ) ) {
				continue;
			}
			$settings = BackupPlus_Storage_Methods_Interface::update_remote_storage_options_format( $method );
			if ( is_wp_error( $settings ) ) {
				$this->storage_module_option_errors .= $backupplus->backup_methods[ $method ] . ' (' . $settings->get_error_code() . '): ' . $settings->get_error_message();
				add_action( 'all_admin_notices', array( $this, 'show_admin_warning_multiple_storage_options' ) );
				$backupplus->log_wp_error( $settings, true, true );
				continue;
			}
			foreach ( $settings['settings'] ?? array() as $instance_id => $storage_options ) {
				if ( $provider->authentication_required( $storage_options ) ) {
					if ( ! isset( $this->auth_instance_ids[ $method ] ) ) {
						$this->auth_instance_ids[ $method ] = array();
					}
					if ( ! in_array( $instance_id, $this->auth_instance_ids[ $method ], true ) ) {
						$this->auth_instance_ids[ $method ][] = $instance_id;
					}
					if ( ! isset( $this->auth_notice_callbacks[ $method ] ) ) {
						$this->auth_notice_callbacks[ $method ] = function () use ( $method ) {
							$this->get_method_auth_link( $method );
						};
					}
					if ( false === has_action( 'all_admin_notices', $this->auth_notice_callbacks[ $method ] ) ) {
						add_action( 'all_admin_notices', $this->auth_notice_callbacks[ $method ] );
					}
				}
				foreach ( $provider->get_admin_notices( $storage_options ) as $notice ) {
					$key = $method . ':' . md5( serialize( $notice ) );
					if ( ! isset( $this->provider_admin_notices[ $key ] ) ) {
						$this->provider_admin_notices[ $key ] = function () use ( $notice ) {
							$this->show_admin_warning( $notice['message'], $notice['class'] );
						};
					}
					if ( false === has_action( 'all_admin_notices', $this->provider_admin_notices[ $key ] ) ) {
						add_action( 'all_admin_notices', $this->provider_admin_notices[ $key ] );
					}
				}
			}
		}

		if ( $this->disk_space_check( 1048576 * 35 ) === false ) {
			add_action( 'all_admin_notices', array( $this, 'show_admin_warning_diskspace' ) );
		}

		if ( ! empty( $this->storage_service_with_partial_settings ) ) {
			add_action( 'all_admin_notices', array( $this, 'show_admin_warning_if_remote_storage_with_partial_settings' ) );
		}

		if ( ! empty( $this->storage_service_without_settings ) ) {
			add_action( 'all_admin_notices', array( $this, 'show_admin_warning_if_remote_storage_setting_are_empty' ) );
		}

		if ( $backupplus->is_restricted_hosting( 'only_one_backup_per_month' ) ) {
			add_action( 'all_admin_notices', array( $this, 'show_admin_warning_one_backup_per_month' ) );
		}

		if ( ! $backupplus->phpseclib_requirements_met() ) {
			add_action( 'all_admin_notices', array( $this, 'show_admin_warning_phpseclib' ) );
		}
	}

	private function setup_all_admin_notices_udonly( $service, $override = false ) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Filter use
		global $backupplus;

		if ( BackupPlus_Options::get_backupplus_option( 'backupplus_debug_mode' ) ) {
			@ini_set( 'display_errors', 1 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			if ( defined( 'E_DEPRECATED' ) ) {
				@error_reporting( E_ALL & ~E_NOTICE & ~E_DEPRECATED );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, PHPCompatibility.Constants.NewConstants.e_deprecatedFound, WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting -- Silenced to suppress errors that may arise because of the function. The error_reporting() function is used to display PHP errors when debug mode is enabled.
			} else {
				@error_reporting( E_ALL & ~E_NOTICE );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting -- Silenced to suppress errors that may arise because of the function. The error_reporting() function is used to display PHP errors when debug mode is enabled.
			}
			add_action( 'all_admin_notices', array( $this, 'show_admin_debug_warning' ) );
		}

		if ( null === BackupPlus_Options::get_backupplus_option( 'backupplus_interval' ) ) {
			add_action( 'all_admin_notices', array( $this, 'show_admin_nosettings_warning' ) );
			$this->no_settings_warning = true;
		}

		// Avoid false positives, by attempting to raise the limit (as happens when we actually do a backup)
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( BACKUPPLUS_SET_TIME_LIMIT );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}       $max_execution_time = (int) ini_get( 'max_execution_time' );
		if ( $max_execution_time > 0 && $max_execution_time < 20 ) {
			add_action( 'all_admin_notices', array( $this, 'show_admin_warning_execution_time' ) );
		}

		// LiteSpeed has a generic problem with terminating cron jobs
		$server_software = BackupPlus_Manipulation_Functions::fetch_superglobal( 'server', 'SERVER_SOFTWARE', '', false, 'string', null );
		if ( false == BackupPlus_Options::get_backupplus_option( 'backupplus_dismiss_admin_warning_litespeed' ) && isset( $server_software ) && strpos( $server_software, 'LiteSpeed' ) !== false ) {
			if ( ! is_file( ABSPATH . '.htaccess' ) || ! preg_match( '/noabort/i', file_get_contents( ABSPATH . '.htaccess' ) ) ) {
				add_action( 'all_admin_notices', array( $this, 'show_admin_warning_litespeed' ) );
			}
		}

		$binzip = $backupplus->find_working_bin_zip();

		if ( false == BackupPlus_Options::get_backupplus_option( 'backupplus_dismiss_admin_warning_pclzip' ) && empty( $binzip ) && ( ( defined( 'BACKUPPLUS_PREFERPCLZIP' ) && BACKUPPLUS_PREFERPCLZIP == true ) || ! class_exists( 'ZipArchive' ) || ( ! extension_loaded( 'zip' ) && ! method_exists( 'ZipArchive', 'AddFile' ) ) ) ) {
			add_action( 'all_admin_notices', array( $this, 'show_admin_warning_pclzip' ) );
		}

		if ( version_compare( $backupplus->get_wordpress_version(), '3.2', '<' ) ) {
			add_action( 'all_admin_notices', array( $this, 'show_admin_warning_wordpressversion' ) );
		}

		if ( ! $backupplus->phpseclib_requirements_met() ) {
			add_action( 'all_admin_notices', array( $this, 'show_admin_warning_phpseclib' ) );
		}
	}

	/**
	 * Show an administrative warning message, which can appear only on the BackupPlus plugin page
	 *
	 * @param String $message the HTML for the message (already escaped)
	 * @param String $class   CSS class to use for the div
	 */
	public function show_plugin_page_admin_warning( $message, $class = 'updated' ) {

		global $pagenow, $plugin_page;

		if ( BackupPlus_Options::admin_page() !== $pagenow || 'backupplus' !== $plugin_page ) {
			return;
		}

		$this->show_admin_warning( $message, $class );
	}

	/**
	 * Paint a div for a dashboard warning
	 *
	 * @param String $message - the HTML for the message (already escaped)
	 * @param String $class   - CSS class to use for the div
	 */
	public function show_admin_warning( $message, $class = 'updated' ) {
		echo '<div class="backupplusmessage ' . esc_attr( $class ) . '"><p>' . $message . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- As the docblock says, the $message param should be already escaped before passing it to this method
	}

	public function show_admin_warning_multiple_storage_options() {
		$this->show_admin_warning( '<strong>BackupPlus:</strong> ' . __( 'An error occurred when fetching storage module options: ', 'backupplus' ) . htmlspecialchars( $this->storage_module_option_errors ), 'error' );
	}

	public function show_admin_warning_unwritable() {
		// One of the translators has erroneously changed "Backup" into "Back up" (which means, "reverse" !)
		$unwritable_mess = htmlspecialchars( str_ireplace( 'Back Up', 'Backup', __( "The 'Backup Now' button is disabled as your backup directory is not writable (go to the 'Settings' tab and find the relevant option).", 'backupplus' ) ) );
		$this->show_admin_warning( $unwritable_mess, 'error' );
	}

	public function show_admin_nosettings_warning() {
		$this->show_admin_warning(
			'<strong>' . __( 'Welcome to BackupPlus!', 'backupplus' ) . '</strong> ' . __( 'To make a backup, press the Backup Now button. To change what is backed up, configure schedules, or set up remote storage, go to the Settings tab.', 'backupplus' ) . ' <a href="' . esc_url(
				add_query_arg(
					array(
						'page' => 'backupplus',
						'tab'  => 'settings',
					),
					BackupPlus_Options::admin_page_url()
				)
			) . '">' . __(
				'Open Settings',
				'backupplus'
			) . '</a>',
			'updated notice is-dismissible'
		);
	}

	public function show_admin_warning_execution_time() {
		$this->show_admin_warning(
			'<strong>' . __( 'Warning', 'backupplus' ) . ':</strong> ' .
			/* translators: 1: Max execution time, 2: Recommended value */
			sprintf( __( 'The amount of time allowed for WordPress plugins to run is very low (%1$s seconds) - you should increase it to avoid backup failures due to time-outs (consult your web hosting company for more help - it is the max_execution_time PHP setting; the recommended value is %2$s seconds or more)', 'backupplus' ), (int) @ini_get( 'max_execution_time' ), 90 )// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		);
	}

	public function show_admin_warning_disabledcron() {
		$ret  = '<div class="backupplusmessage updated"><p>';
		$ret .= '<strong>' . __( 'Warning', 'backupplus' ) . ':</strong> ' . __( 'The scheduler is disabled in your WordPress install, via the DISABLE_WP_CRON setting.', 'backupplus' ) . ' ' . __( 'No backups can run (even &quot;Backup Now&quot;) unless either you have set up a facility to call the scheduler manually, or until it is enabled.', 'backupplus' );
		$ret .= '</p></div>';
		return $ret;
	}

	public function show_admin_warning_diskspace() {
		$this->show_admin_warning(
			'<strong>' . __( 'Warning', 'backupplus' ) . ':</strong> ' .
			/* translators: %s: Free disk space */
			sprintf( __( 'You have less than %s of free disk space on the disk which BackupPlus is configured to use to create backups.', 'backupplus' ), '35 MB' ) . ' ' .
			__( 'BackupPlus could well run out of space.', 'backupplus' ) . ' ' .
			__( 'Contact the operator of your server (e.g. your web hosting company) to resolve this issue.', 'backupplus' )
		);
	}

	public function show_admin_warning_wordpressversion() {
		$this->show_admin_warning(
			'<strong>' . __( 'Warning', 'backupplus' ) . ':</strong> ' .
			/* translators: %s: Minimum supported WordPress version */
			sprintf( __( 'BackupPlus does not officially support versions of WordPress before %s.', 'backupplus' ), '3.2' ) . ' ' .
			__( 'It may work for you, but if it does not, then please be aware that no support is available until you upgrade WordPress.', 'backupplus' )
		);
	}

	public function show_admin_warning_litespeed() {
		$this->show_admin_warning(
			'<strong>' . __( 'Warning', 'backupplus' ) . ':</strong> ' .
			/* translators: %s: Web server name */
			sprintf( __( 'Your website is hosted using the %s web server.', 'backupplus' ), 'LiteSpeed' ) . ' ' .
			__( 'If backups fail, check the server error log and PHP execution limits.', 'backupplus' ),
			'updated admin-warning-litespeed notice is-dismissible'
		);
	}

	public function show_admin_warning_pclzip() {
		$this->show_admin_warning( '<strong>' . __( 'Warning', 'backupplus' ) . ':</strong> ' . __( 'Neither the PHP zip module nor a zip executable are available on your webserver.', 'backupplus' ) . ' ' . __( 'Consequently, BackupPlus will use a built-in zip module (PclZip); this is significantly slower.', 'backupplus' ) . ' ' . __( 'To get faster backups, ask your web hosting provider how to turn on the PHP zip module on your hosting.', 'backupplus' ), 'updated admin-warning-pclzip notice is-dismissible' );
	}

	public function show_admin_debug_warning() {
		$this->show_admin_warning( '<strong>' . __( 'Notice', 'backupplus' ) . ':</strong> ' . __( 'BackupPlus\'s debug mode is on.', 'backupplus' ) . ' ' . __( 'You may see debugging notices on this page not just from BackupPlus, but from any other plugin installed.', 'backupplus' ) . ' ' . __( 'Please try to make sure that the notice you are seeing is from BackupPlus before you raise a support request.', 'backupplus' ) . '</a>' );
	}

	/**
	 * Show dismissible PHPSecLib's admin notice on all admin pages, except BackupPlus plugin page
	 */
	public function show_admin_warning_phpseclib() {
		global $backupplus, $pagenow, $plugin_page;
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$dismissible = ( BackupPlus_Options::admin_page() !== $pagenow || 'backupplus' !== $plugin_page ) && ( ! isset( $_REQUEST['action'] ) || 'backupplus_savesettings' !== $_REQUEST['action'] );
		$class       = '';
		// Suppress only when dismissed for the minimum recommended version (previously was boolean `true`), so older dismissals re-show after a version bump.
		if ( $dismissible && BACKUPPLUS_PHPSECLIB_MIN_PHP_VERSION === BackupPlus_Options::get_backupplus_option( 'backupplus_dismiss_phpseclib_notice', false ) ) {
			return;
		}
		if ( $dismissible ) {
			$class = 'ud-phpseclib-notice is-dismissible';
		}
		$this->show_admin_warning( '<strong>' . __( 'Warning', 'backupplus' ) . ':</strong> ' . $backupplus->get_phpseclib_warning_msg(), "notice notice-warning $class" );
		$printed = true;
	}

	/**
	 * Return an admin warning notice
	 *
	 * @param Integer $howmany
	 *
	 * @return String
	 */
	public function show_admin_warning_overdue_crons( $howmany ) {
		$ret  = '<div class="backupplusmessage updated"><p>';
		$ret .= '<strong>' . __( 'Warning', 'backupplus' ) . ':</strong> ' .
		/* translators: %d: Number of overdue scheduled tasks */
		sprintf( __( 'WordPress has a number (%d) of scheduled tasks which are overdue.', 'backupplus' ), $howmany ) . ' ' .
		__( 'Unless this is a development site, this means that the scheduler in your WordPress install is not working properly.', 'backupplus' ) . ' ' .
		__( 'Check that WordPress cron is enabled and that your server can run scheduled tasks.', 'backupplus' );
		$ret .= '</p></div>';
		return $ret;
	}

	/**
	 * Get a count for the number of overdue cron jobs
	 *
	 * @return Integer - how many cron jobs are overdue
	 */
	public function howmany_overdue_crons() {
		$how_many_overdue = 0;
		if ( function_exists( '_get_cron_array' ) || ( is_file( ABSPATH . WPINC . '/cron.php' ) && include_once ABSPATH . WPINC . '/cron.php' && function_exists( '_get_cron_array' ) ) ) {
			$crons = _get_cron_array();
			if ( is_array( $crons ) ) {
				$timenow = time();
				foreach ( $crons as $jt => $job ) {
					if ( $jt < $timenow ) {
						++$how_many_overdue;
					}
				}
			}
		}
		return $how_many_overdue;
	}

	/**
	 * Detects byte-order mark at the start of common files and change waning message texts
	 *
	 * @return string|boolean BOM warning text or false if not bom characters detected
	 */
	public function get_bom_warning_text() {
		$files_to_check = array(
			ABSPATH . 'wp-config.php',
			get_template_directory() . DIRECTORY_SEPARATOR . 'functions.php',
		);
		if ( is_child_theme() ) {
			$files_to_check[] = get_stylesheet_directory() . DIRECTORY_SEPARATOR . 'functions.php';
		}
		$corrupted_files = array();
		foreach ( $files_to_check as $file ) {
			if ( ! file_exists( $file ) ) {
				continue;
			}
			if ( false === ( $fp = fopen( $file, 'r' ) ) ) {
				continue;
			}
			if ( false === ( $file_data = fread( $fp, 8192 ) ) ) {
			}
			fclose( $fp );
			$substr_file_data = array();
			for ( $substr_length = 2; $substr_length <= 5; $substr_length++ ) {
				$substr_file_data[ $substr_length ] = substr( $file_data, 0, $substr_length );
			}
			// Detect UTF-7, UTF-8, UTF-16 (BE), UTF-16 (LE), UTF-32 (BE) & UTF-32 (LE) Byte order marks (BOM)
			$bom_decimal_representations = array(
				array( 43, 47, 118, 56 ), // UTF-7 (Hexadecimal: 2B 2F 76 38)
				array( 43, 47, 118, 57 ), // UTF-7 (Hexadecimal: 2B 2F 76 39)
				array( 43, 47, 118, 43 ), // UTF-7 (Hexadecimal: 2B 2F 76 2B)
				array( 43, 47, 118, 47 ), // UTF-7 (Hexadecimal: 2B 2F 76 2F)
				array( 43, 47, 118, 56, 45 ), // UTF-7 (Hexadecimal: 2B 2F 76 38 2D)
				array( 239, 187, 191 ), // UTF-8 (Hexadecimal: 2B 2F 76 38 2D)
				array( 254, 255 ), // UTF-16 (BE) (Hexadecimal: FE FF)
				array( 255, 254 ), // UTF-16 (LE) (Hexadecimal: FF FE)
				array( 0, 0, 254, 255 ), // UTF-32 (BE) (Hexadecimal: 00 00 FE FF)
				array( 255, 254, 0, 0 ), // UTF-32 (LE) (Hexadecimal: FF FE 00 00)
			);
			foreach ( $bom_decimal_representations as $bom_decimal_representation ) {
				$no_of_chars = count( $bom_decimal_representation );
				array_unshift( $bom_decimal_representation, 'C*' );
				$binary = call_user_func_array( 'pack', $bom_decimal_representation );
				if ( $binary == $substr_file_data[ $no_of_chars ] ) {
					$corrupted_files[] = $file;
					break;
				}
			}
		}
		if ( empty( $corrupted_files ) ) {
			return false;
		} else {
			$corrupted_files_count = count( $corrupted_files );
			return '<strong>' . __( 'Warning', 'backupplus' ) . ':</strong> ' .
			/* translators: %s: List of corrupted files */
			sprintf( _n( 'The file %s has a "byte order mark" (BOM) at its beginning.', 'The files %s have a "byte order mark" (BOM) at their beginning.', $corrupted_files_count, 'backupplus' ), '<strong>' . implode( '</strong>, <strong>', $corrupted_files ) . '</strong>' ) .
			' ' . __( 'Remove the extra output from the listed files; even a blank line before the PHP opening tag can cause it.', 'backupplus' );
		}
	}

	/**
	 * Show which remote storage settings are partially setup error, or if manual auth is supported show the manual auth UI
	 */
	public function show_admin_warning_if_remote_storage_with_partial_settings() {
		if ( ( isset( $_REQUEST['page'] ) && 'backupplus' == $_REQUEST['page'] ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			$enabled_services = BackupPlus_Storage_Methods_Interface::get_enabled_storage_objects_and_ids( array_keys( $this->storage_service_with_partial_settings ) );
			foreach ( $this->storage_service_with_partial_settings as $method => $method_name ) {
				if ( empty( $enabled_services[ $method ]['object'] ) || empty( $enabled_services[ $method ]['instance_settings'] ) || ! $enabled_services[ $method ]['object']->supports_feature( 'manual_authentication' ) ) {
					/* translators: %s: Remote storage method */
					$this->show_admin_warning( sprintf( __( 'The following remote storage (%s) has only been partially configured, manual authorization is not supported with this remote storage, please try again and if the problem persists contact support.', 'backupplus' ), $method ), 'error' );
				} else {
					$this->show_admin_warning( $enabled_services[ $method ]['object']->get_manual_authorisation_template(), 'error' );
				}
			}
		} else {
			$this->show_admin_warning(
				/* translators: %s: List of storage services */
				'BackupPlus: ' . sprintf( __( 'The following remote storage (%s) has only been partially configured, if you are having problems you can try to manually authorise at the BackupPlus settings page.', 'backupplus' ), implode( ', ', $this->storage_service_with_partial_settings ) ) .
				' <a href="' . BackupPlus_Options::admin_page_url() . '?page=backupplus&amp;tab=settings">' . __( 'Return to BackupPlus configuration', 'backupplus' ) . '</a>',
				'error'
			);
		}
	}

	/**
	 * Show remote storage settings are empty warning
	 */
	public function show_admin_warning_if_remote_storage_setting_are_empty() {
		if ( ( isset( $_REQUEST['page'] ) && 'backupplus' == $_REQUEST['page'] ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			/* translators: %s: List of storage services */
			$this->show_admin_warning( sprintf( __( 'You have requested saving to remote storage (%s), but without entering any settings for that storage.', 'backupplus' ), implode( ', ', $this->storage_service_without_settings ) ), 'error' );
		} else {
			$this->show_admin_warning(
				/* translators: %s: List of storage services */
				'BackupPlus: ' . sprintf( __( 'You have requested saving to remote storage (%s), but without entering any settings for that storage.', 'backupplus' ), implode( ', ', $this->storage_service_without_settings ) ) .
				' <a href="' . BackupPlus_Options::admin_page_url() . '?page=backupplus&amp;tab=settings">' . __( 'Return to BackupPlus configuration', 'backupplus' ) . '</a>',
				'error'
			);
		}
	}

	/**
	 * Show remote storage warning when one or more cloud storage options are selected but the add-ons are not installed
	 */
	public function show_admin_warning_if_remote_storage_without_addons() {
		$storage_service_without_addons = $this->storage_service_without_addons_settings;
		$total_storage                  = count( $storage_service_without_addons );
		end( $storage_service_without_addons );
		if ( $total_storage > 1 ) {
			$storage_service_without_addons[ key( $storage_service_without_addons ) ] = __( 'and', 'backupplus' ) . ' ' . $storage_service_without_addons[ key( $storage_service_without_addons ) ];
		}
		$storage_service_without_addons = implode( ', ', $storage_service_without_addons );

		$notice = sprintf(
			/* translators: 1: List of remote storage services, 2: Link to storage settings */
			__( 'Some configured storage locations are unavailable in this installation, so backups to them will not run. This affects: %1$s. Review your destinations in the %2$s.', 'backupplus' ),
			$storage_service_without_addons,
			'<a href="' . esc_url( BackupPlus_Options::admin_page_url() ) . '?page=backupplus&amp;tab=settings">' . __( 'BackupPlus settings', 'backupplus' ) . '</a>'
		);

		$this->show_admin_warning( $notice, 'error' );
	}

	/**
	 * Show warning about restriction implied by the hosting company (can only perform a full backup once per month, incremental backup should not go above one per day)
	 */
	public function show_admin_warning_one_backup_per_month() {

		global $backupplus;

		$hosting_company = $backupplus->get_hosting_info();
		/* translators: 1: Hosting company name, 2: Website link */
		$txt1 = sprintf( __( 'Your website is hosted with %1$s (%2$s).', 'backupplus' ), $hosting_company['name'], $hosting_company['website'] );
		/* translators: %s: Hosting company name */
		$txt2 = sprintf( __( '%s permits BackupPlus to perform only one backup per month.', 'backupplus' ), $hosting_company['name'] ) . ' ' .
			__( 'Thus, we recommend you choose a full backup when performing a manual backup and to use that option when creating a scheduled backup.', 'backupplus' );
		$txt3 = __( 'Due to the restriction, some settings can be automatically adjusted, disabled or not available.', 'backupplus' );

		$this->show_plugin_page_admin_warning( '<strong>' . __( 'Warning', 'backupplus' ) . ':</strong> ' . $txt1 . ' ' . $txt2 . ' ' . $txt3, 'update-nag notice notice-warning', true );
	}

	/**
	 * Print the phpseclib-notice-related scripts
	 */
	public function print_phpseclib_notice_scripts() {
		static $printed = false;

		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
	<script>
		jQuery(function($) {
			$('div.ud-phpseclib-notice').on('click', 'button.notice-dismiss', function (event) {
				event.stopImmediatePropagation();
				$.ajax(ajaxurl, {
					type: 'POST',
					data: {
						action: 'backupplus_ajax',
						subaction: 'dismiss_phpseclib_notice',
						nonce: '<?php echo esc_js( wp_create_nonce( 'backupplus-credentialtest-nonce' ) ); ?>',
					},
					error: function(xhr, status, error_code) {
						alert(error_code+':'+status);
					}
				});
			});
		});
	</script>
		<?php
	}
}
