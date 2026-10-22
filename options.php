<?php
/**
 * Options handling
 */
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

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
		$user_can_manage = current_user_can( apply_filters( 'option_page_capability_backupplus-options-group', 'manage_options' ) );
		// false: allows the filter to know that the request is not coming from the multisite add-on
		return apply_filters( 'backupplus_user_can_manage', $user_can_manage, false );
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
		return admin_url( 'options-general.php' );
	}

	/**
	 * Get the base-name for the dashboard admin page
	 *
	 * @return String
	 */
	public static function admin_page() {
		return 'options-general.php';
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
		$ret = get_option( $option, $default );
		if ( class_exists( 'BackupPlus_Credential_Storage' ) ) {
			$ret = BackupPlus_Credential_Storage::reading( $ret, $option );
		}
		return apply_filters( 'backupplus_get_option', $ret, $option, $default );
	}

	/**
	 * The apparently unused parameter is used in the alternative class in the Multisite add-on
	 *
	 * @param String  $option    specify option name
	 * @param String  $value     specify option value
	 * @param Boolean $use_cache whether or not to use the WP options cache
	 * @param String  $autoload  whether to autoload (only takes effect on a change of value)
	 *
	 * @return Boolean - as from update_option()
	 */
	public static function update_backupplus_option( $option, $value, $use_cache = true, $autoload = 'yes' ) {
		return update_option( $option, apply_filters( 'backupplus_update_option', $value, $option, $use_cache ), $autoload );
	}

	/**
	 * Delete an option
	 *
	 * @param String $option - the option name
	 */
	public static function delete_backupplus_option( $option ) {
		delete_option( $option );
	}

	/**
	 * Register the BackupPlus admin menu entry
	 */
	public static function add_admin_pages() {
		$capability = apply_filters( 'option_page_capability_backupplus-options-group', 'manage_options' );

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
				'options-general.php?page=backupplus',
				'', // Set the callback to empty string because it's unused
				trailingslashit( BACKUPPLUS_URL ) . 'images/backupplus-menu.svg'
			);

			BackupPlus_Options::add_submenu();

			foreach ( $submenu['options-general.php'] as $key => $item ) {
				if ( 'backupplus' != $item[2] ) {
					continue;
				}
				unset( $submenu['options-general.php'][ $key ] );
			}
		} else {
			BackupPlus_Options::add_submenu();
		}
	}

	/**
	 * Adds a submenu page under the "Settings" menu in the WordPress admin.
	 *
	 * The capability required to access this submenu is filtered through the
	 * 'option_page_capability_backupplus-options-group' filter, with a default value of 'manage_options'.
	 */
	public static function add_submenu() {
		global $backupplus_admin;

		$capability = apply_filters( 'option_page_capability_backupplus-options-group', 'manage_options' );

		add_submenu_page(
			'options-general.php',
			'BackupPlus',
			__( 'BackupPlus Backups', 'backupplus' ),
			$capability,
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
		if ( 'options-general.php' == $pagenow ) {
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
			if ( substr( $referer, -19, 19 ) == 'options-general.php' && false !== strpos( $_SERVER['REQUEST_URI'], '?' ) ) {
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
		register_setting( 'backupplus-options-group', 'backupplus_service', array( $backupplus, 'just_one' ) );

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

		register_setting( 'backupplus-options-group', 'backupplus_autobackup_default', 'absint' );
		register_setting( 'backupplus-options-group', 'backupplus_delete_local', 'absint' );
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

		global $pagenow;
		$page = BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'page', '' );
		if ( 'options-general.php' == $pagenow && 'backupplus' == substr( $page, 0, 11 ) ) {
			if ( ! defined( 'BACKUPPLUS_DISABLE_TOP_LEVEL_MENU_ENTRY' ) || ! BACKUPPLUS_DISABLE_TOP_LEVEL_MENU_ENTRY ) {
				add_filter( 'parent_file', array( 'BackupPlus', 'parent_file' ), 99 );
			}
			if ( is_multisite() ) {
				add_action( 'all_admin_notices', array( 'BackupPlus_Options', 'show_admin_warning_multisite' ) );
			}
		}
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

	/**
	 * Output information about the multisite add-on when relevant
	 */
	public static function show_admin_warning_multisite() {
		global $backupplus_admin;
		$backupplus_admin->show_admin_warning( '<strong>' . __( 'BackupPlus warning:', 'backupplus' ) . '</strong> ' . __( 'This is a WordPress multi-site (a.k.a. network) installation.', 'backupplus' ) . ' ' . __( 'Every blog admin who can modify plugin settings can back up and restore the entire network, including its data and passwords. Restrict access to these settings to trusted network administrators.', 'backupplus' ), 'error' );
	}
}

add_action( 'admin_init', array( 'BackupPlus_Options', 'admin_init' ) );
add_action( 'admin_menu', array( 'BackupPlus_Options', 'add_admin_pages' ) );
