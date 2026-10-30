<?php
/**
 * Options handling
 */
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

require_once __DIR__ . '/includes/class-multisite.php';

class BackupPlus_Options {

	/**
	 * The url for UprdraftPlus page
	 */
	const PARENT_FILE = 'options-general.php?page=backupplus';

	/**
	 * Whether or not the current user has permission to manage BackupPlus
	 *
	 * @return Boolean
	 */
	public static function user_can_manage() {
		$user_can_manage = current_user_can( self::capability() ) && BackupPlus_Multisite::available();
		return apply_filters( 'backupplus_user_can_manage', $user_can_manage, is_multisite() );
	}

	/**
	 * The capability required to manage BackupPlus: on a network, that of a super administrator (see BackupPlus_Multisite)
	 *
	 * @return String
	 */
	public static function capability() {
		return apply_filters( 'option_page_capability_backupplus-options-group', 'manage_options' );
	}

	public static function options_table() {
		return 'options';
	}

	/**
	 * Get the URL to the dashboard admin page
	 *
	 * @return String
	 */
	public static function admin_page_url() {
		return is_multisite() ? network_admin_url( 'settings.php' ) : admin_url( 'options-general.php' );
	}

	/**
	 * Get the base-name for the dashboard admin page
	 *
	 * @return String
	 */
	public static function admin_page() {
		return is_multisite() ? 'settings.php' : 'options-general.php';
	}

	/**
	 * The menu slug of the top-level BackupPlus menu entry
	 *
	 * @return String
	 */
	public static function parent_file() {
		return self::admin_page() . '?page=backupplus';
	}

	/**
	 * Extracts the last logged message from backupplus last process
	 *
	 * @return Mixed - Value set for the option or the default message
	 */
	public static function get_backupplus_lastmessage() {
		return BackupPlus_Options::get_backupplus_option( 'backupplus_lastmessage', __( '(Nothing has been logged yet)', 'backupplus' ) );
	}

	/**
	 * Get the value for a specified option
	 *
	 * @param String $option  option name
	 * @param Mixed  $default default value of option
	 *
	 * @return Mixed
	 */
	public static function get_backupplus_option( $option, $default = null ) {
		// On a network, the configuration is the main site's, whichever site this request is for
		$ret = BackupPlus_Multisite::secondary() ? get_blog_option( BackupPlus_Multisite::main_site_id(), $option, $default ) : get_option( $option, $default );
		if ( class_exists( 'BackupPlus_Credential_Storage' ) ) {
			$ret = BackupPlus_Credential_Storage::reading( $ret, $option );
		}
		return apply_filters( 'backupplus_get_option', $ret, $option, $default );
	}

	/**
	 * Update an option of the BackupPlus configuration
	 *
	 * @param String  $option    specify option name
	 * @param String  $value     specify option value
	 * @param Boolean $use_cache whether or not to use the WP options cache
	 * @param String  $autoload  whether to autoload (only takes effect on a change of value)
	 *
	 * @return Boolean - as from update_option()
	 */
	public static function update_backupplus_option( $option, $value, $use_cache = true, $autoload = 'yes' ) {
		$value = apply_filters( 'backupplus_update_option', $value, $option, $use_cache );
		return BackupPlus_Multisite::on_main_site(
			function () use ( $option, $value, $autoload ) {
				return update_option( $option, $value, $autoload );
			}
		);
	}

	/**
	 * Delete an option
	 *
	 * @param String $option - the option name
	 */
	public static function delete_backupplus_option( $option ) {
		BackupPlus_Multisite::on_main_site(
			function () use ( $option ) {
				return delete_option( $option );
			}
		);
	}

	/**
	 * Register the BackupPlus admin menu entry: under Settings, or on a network under Network Admin's Settings
	 */
	public static function add_admin_pages() {
		// On a network, the page is in Network Admin only
		if ( is_multisite() !== is_network_admin() || ! BackupPlus_Multisite::available() ) {
			return;
		}

		$capability  = self::capability();
		$parent_menu = self::admin_page();

		if ( ! defined( 'BACKUPPLUS_DISABLE_TOP_LEVEL_MENU_ENTRY' ) || ! BACKUPPLUS_DISABLE_TOP_LEVEL_MENU_ENTRY ) {
			// Check user capability to manage options before proceeding
			if ( ! current_user_can( $capability ) ) {
				return;
			}

			global $submenu, $title, $plugin_page;

			if ( 'backupplus' == $plugin_page ) {
				$title = 'BackupPlus';
			}

			// Add "BackupPlus" as the main menu item
			add_menu_page(
				'BackupPlus',
				'BackupPlus',
				$capability,
				self::parent_file(),
				'', // Set the callback to empty string because it's unused
				trailingslashit( BACKUPPLUS_URL ) . 'images/backupplus-menu.svg'
			);

			BackupPlus_Options::add_submenu();

			foreach ( $submenu[ $parent_menu ] as $key => $item ) {
				if ( 'backupplus' != $item[2] ) {
					continue;
				}
				unset( $submenu[ $parent_menu ][ $key ] );
			}
		} else {
			BackupPlus_Options::add_submenu();
		}
	}

	/**
	 * Adds a submenu page under the "Settings" menu in the WordPress admin (Network Admin, on a network).
	 *
	 * The capability required to access this submenu is filtered through the
	 * 'option_page_capability_backupplus-options-group' filter, with a default value of 'manage_options'.
	 */
	public static function add_submenu() {
		global $backupplus_admin;

		add_submenu_page(
			self::admin_page(),
			'BackupPlus',
			__( 'BackupPlus Backups', 'backupplus' ),
			self::capability(),
			'backupplus',
			array( $backupplus_admin, 'settings_output' )
		);
	}

	public static function options_form_begin( $settings_fields = 'backupplus-options-group', $allow_autocomplete = true, $get_params = array(), $classes = '' ) {
		global $pagenow;
		echo '<form method="post"';

		if ( '' != $classes ) {
			echo ' class="' . esc_attr( $classes ) . '"';
		}

		$page = '';
		if ( is_network_admin() ) {
			// wp-admin/options.php saves a site's options; a network's settings are saved by BackupPlus_Options::network_save()
			$page       = 'edit.php';
			$get_params = array_merge( array( 'action' => 'backupplus' ), $get_params );
		} elseif ( 'options-general.php' == $pagenow ) {
			$page = 'options.php';
		}

		if ( ! empty( $get_params ) ) {
			$page     .= '?';
			$first_one = true;
			foreach ( $get_params as $k => $v ) {
				if ( $first_one ) {
					$first_one = false;
				} else {
					$page .= '&';
				}
				$page .= urlencode( $k ) . '=' . urlencode( $v );
			}
		}

		if ( $page ) {
			echo ' action="' . esc_url( $page ) . '"';
		}

		if ( ! $allow_autocomplete ) {
			echo ' autocomplete="off"';
		}
		echo '>';
		if ( $settings_fields ) {
			// This is settings_fields('backupplus-options-group'), but with the referer pruned
			echo "<input type='hidden' name='option_page' value='" . esc_attr( 'backupplus-options-group' ) . "' />";
			echo '<input type="hidden" name="action" value="update" />';
			wp_nonce_field( 'backupplus-options-group-options', '_wpnonce', false );

			$remove_query_args = array( 'state', 'action', 'oauth_verifier' );

			$referer = BackupPlus_Manipulation_Functions::wp_unslash( remove_query_arg( $remove_query_args, $_SERVER['REQUEST_URI'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- To be executed on server only, hence this superglobal not to be empty, sanitization and unslashing is done by custom function.

			// Add back the page parameter if it looks like we were on the settings page via an OAuth callback that has now had all parameters removed. This is likely unnecessarily conservative, but there's nothing requiring more than this at the current time.
			if ( substr( $referer, -strlen( self::admin_page() ) ) == self::admin_page() && false !== strpos( $_SERVER['REQUEST_URI'], '?' ) ) {
				$referer .= '?page=backupplus'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- To be executed on server only, hence this superglobal will not to be empty, sanitization and unslashing are not required as this superglobal is used directly.
			}
			echo '<input type="hidden" name="_wp_http_referer" value="' . esc_attr( $referer ) . '" />';
		}
	}

	/**
	 * Runs upon the WordPress action admin_init
	 */
	public static function admin_init() {

		static $already_inited = false;
		if ( $already_inited ) {
			return;
		}

		$already_inited = true;

		// The settings belong to the main site of a network
		if ( ! BackupPlus_Multisite::available() ) {
			return;
		}

		// If being called outside of the admin context, this may not be loaded yet
		if ( ! function_exists( 'register_setting' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		global $backupplus, $backupplus_admin;
		register_setting( 'backupplus-options-group', 'backupplus_interval', array( $backupplus, 'schedule_backup' ) );
		register_setting( 'backupplus-options-group', 'backupplus_interval_database', array( $backupplus, 'schedule_backup_database' ) );
		register_setting( 'backupplus-options-group', 'backupplus_interval_increments', array( $backupplus, 'schedule_backup_increments' ) );
		register_setting( 'backupplus-options-group', 'backupplus_retain', array( 'BackupPlus_Manipulation_Functions', 'retain_range' ) );
		register_setting( 'backupplus-options-group', 'backupplus_retain_db', array( 'BackupPlus_Manipulation_Functions', 'retain_range' ) );
		register_setting( 'backupplus-options-group', 'backupplus_retain_extrarules', array( $backupplus_admin, 'return_array' ) );

		register_setting( 'backupplus-options-group', 'backupplus_database_encryption', array( 'BackupPlus_Database_Encryption', 'sanitize' ) );
		register_setting( 'backupplus-options-group', 'backupplus_service', array( $backupplus, 'sanitize_services' ) );

		$services_to_register = array_keys( $backupplus->backup_methods );
		foreach ( $services_to_register as $service ) {
			register_setting( 'backupplus-options-group', 'backupplus_' . $service ); // phpcs:ignore PluginCheck.CodeAnalysis.SettingSanitization.register_settingMissing -- Sanitize callback added below to avail two params.
			// We have to add the filter manually in order to get the second parameter passed through (register_setting() only registers with one parameter)
			add_filter( 'sanitize_option_backupplus_' . $service, array( $backupplus, 'storage_options_filter' ), 10, 2 );
		}

		register_setting( 'backupplus-options-group', 'backupplus_auto_updates', 'absint' );
		register_setting( 'backupplus-options-group', 'backupplus_ssl_nossl', 'absint' );
		register_setting( 'backupplus-options-group', 'backupplus_log_syslog', 'absint' );
		register_setting( 'backupplus-options-group', 'backupplus_ssl_useservercerts', 'absint' );
		register_setting( 'backupplus-options-group', 'backupplus_ssl_disableverify', 'absint' );

		register_setting( 'backupplus-options-group', 'backupplus_split_every', array( $backupplus_admin, 'optionfilter_split_every' ) );

		register_setting( 'backupplus-options-group', 'backupplus_dir', array( 'BackupPlus_Manipulation_Functions', 'prune_backupplus_dir_prefix' ) );

		register_setting( 'backupplus-options-group', 'backupplus_report_warningsonly', array( $backupplus_admin, 'return_array' ) );
		register_setting( 'backupplus-options-group', 'backupplus_report_wholebackup', array( $backupplus_admin, 'return_array' ) );
		register_setting( 'backupplus-options-group', 'backupplus_report_dbbackup', array( $backupplus_admin, 'return_array' ) );

		register_setting( 'backupplus-options-group', BackupPlus_Ntfy::OPTION, array( 'BackupPlus_Ntfy', 'sanitize' ) );

		register_setting( 'backupplus-options-group', 'backupplus_autobackup_default', 'absint' );
		register_setting( 'backupplus-options-group', 'backupplus_delete_local', 'absint' );
		register_setting( 'backupplus-options-group', 'backupplus_storage_limits', array( 'BackupPlus_Storage_Limits', 'sanitize' ) );
		register_setting( 'backupplus-options-group', 'backupplus_debug_mode', 'absint' );
		register_setting( 'backupplus-options-group', 'backupplus_extradbs', array( 'BackupPlus_Backup_Sources', 'connections' ) );
		register_setting( 'backupplus-options-group', 'backupplus_database_selection', array( 'BackupPlus_Backup_Sources', 'sanitize_selection' ) );
		register_setting( 'backupplus-options-group', 'backupplus_backupdb_nonwp', 'absint' );
		register_setting( 'backupplus-options-group', 'backupplus_database_next_id', 'absint' );
		register_setting( 'backupplus-options-group', 'backupplus_include_more_exclude', array( 'BackupPlus_Manipulation_Functions', 'strip_dirslash' ) );

		register_setting( 'backupplus-options-group', 'backupplus_include_plugins', 'absint' );
		register_setting( 'backupplus-options-group', 'backupplus_include_themes', 'absint' );
		register_setting( 'backupplus-options-group', 'backupplus_include_uploads', 'absint' );
		register_setting( 'backupplus-options-group', 'backupplus_include_others', 'absint' );
		register_setting( 'backupplus-options-group', 'backupplus_include_credential-keys', 'absint' );
		register_setting( 'backupplus-options-group', 'backupplus_include_wpcore', 'absint' );
		register_setting( 'backupplus-options-group', 'backupplus_include_wpcore_exclude', array( 'BackupPlus_Manipulation_Functions', 'strip_dirslash' ) );
		register_setting( 'backupplus-options-group', 'backupplus_include_more', 'absint' );
		register_setting( 'backupplus-options-group', 'backupplus_include_more_path', array( 'BackupPlus_Backup_Sources', 'sanitize_paths' ) );
		register_setting( 'backupplus-options-group', 'backupplus_include_uploads_exclude', array( 'BackupPlus_Manipulation_Functions', 'strip_dirslash' ) );
		register_setting( 'backupplus-options-group', 'backupplus_include_others_exclude', array( 'BackupPlus_Manipulation_Functions', 'strip_dirslash' ) );

		register_setting( 'backupplus-options-group', 'backupplus_starttime_files', array( 'BackupPlus_Options', 'hourminute' ) );
		register_setting( 'backupplus-options-group', 'backupplus_starttime_db', array( 'BackupPlus_Options', 'hourminute' ) );

		register_setting( 'backupplus-options-group', 'backupplus_startday_files', array( 'BackupPlus_Options', 'week_or_month_day' ) );
		register_setting( 'backupplus-options-group', 'backupplus_startday_db', array( 'BackupPlus_Options', 'week_or_month_day' ) );

		register_setting( 'backupplus-options-group', 'backupplus_schedule_exact_files', array( 'BackupPlus_Backup_Schedule', 'sanitize_flag' ) );
		register_setting( 'backupplus-options-group', 'backupplus_schedule_exact_db', array( 'BackupPlus_Backup_Schedule', 'sanitize_flag' ) );
		register_setting( 'backupplus-options-group', 'backupplus_schedule_exact_increments', array( 'BackupPlus_Backup_Schedule', 'sanitize_flag' ) );
		register_setting( 'backupplus-options-group', 'backupplus_starttime_increments', array( 'BackupPlus_Backup_Schedule', 'sanitize_time' ) );
		register_setting( 'backupplus-options-group', 'backupplus_retention_cleanup_mode', array( 'BackupPlus_Backup_Schedule', 'sanitize_cleanup_mode' ) );
		register_setting( 'backupplus-options-group', 'backupplus_retention_cleanup_time', array( 'BackupPlus_Backup_Schedule', 'sanitize_time' ) );

		global $pagenow;
		$page = BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'page', '' );
		if ( self::admin_page() == $pagenow && 'backupplus' == substr( $page, 0, 11 ) ) {
			if ( ! defined( 'BACKUPPLUS_DISABLE_TOP_LEVEL_MENU_ENTRY' ) || ! BACKUPPLUS_DISABLE_TOP_LEVEL_MENU_ENTRY ) {
				add_filter( 'parent_file', array( 'BackupPlus', 'parent_file' ), 99 );
			}
		}
	}

	/**
	 * Save the settings form of Network Admin, which is submitted without JavaScript. This does for the network what
	 * wp-admin/options.php does for a site, for the settings registered in admin_init(): they are the main site's options.
	 */
	public static function network_save() {
		check_admin_referer( 'backupplus-options-group-options' );
		if ( ! self::user_can_manage() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage options for this site.', 'backupplus' ), 403 );
		}

		$submitted = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each option is sanitised by the callback registered for it, as in wp-admin/options.php.
		$valid     = BackupPlus_Backup_Schedule::instance()->validate( $submitted );
		if ( is_wp_error( $valid ) ) {
			wp_die(
				esc_html( $valid->get_error_message() ),
				'',
				array(
					'response'  => 400,
					'back_link' => true,
				)
			);
		}

		$allowed = apply_filters( 'allowed_options', array() );
		foreach ( isset( $allowed['backupplus-options-group'] ) ? $allowed['backupplus-options-group'] : array() as $option ) {
			$value = isset( $submitted[ $option ] ) ? $submitted[ $option ] : null;
			if ( ! is_array( $value ) && null !== $value ) {
				$value = trim( $value );
			}
			update_option( $option, $value );
		}

		wp_safe_redirect( add_query_arg( 'settings-updated', 'true', wp_get_referer() ? wp_get_referer() : self::admin_page_url() . '?page=backupplus' ) );
		exit;
	}

	public static function hourminute( $pot ) {
		if ( preg_match( '/^([0-2]?[0-9]):([0-5][0-9])$/', $pot, $matches ) ) {
			return sprintf( '%02d:%s', $matches[1], $matches[2] );
		}
		if ( '' == $pot ) {
			return gmdate( 'H:i', time() + 300 );
		}
		return '00:00';
	}

	public static function week_or_month_day( $pot ) {
		$pot = absint( $pot );
		return ( $pot > 28 ) ? 1 : $pot;
	}
}

add_action( 'admin_init', array( 'BackupPlus_Options', 'admin_init' ) );
add_action( 'admin_menu', array( 'BackupPlus_Options', 'add_admin_pages' ) );
