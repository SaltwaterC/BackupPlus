<?php

/** Table helpers: dump order, keys, LIKE escaping and the WP-Optimize links. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Database_Utility_Tables {

	/**
	 * The purpose of this function is to make sure that the options table is put in the database first, then the users table, then the site + blogs tables (if present - multisite), then the usermeta table; and after that the core WP tables - so that when restoring we restore the core tables first
	 *
	 * @param Array $a_arr the first array
	 * @param Array $b_arr the second array
	 *
	 * @return Integer - the sort result, according to the rules of PHP custom sorting functions
	 */
	public static function backup_db_sorttables( $a_arr, $b_arr ) {
		$a            = $a_arr['name'];
		$a_table_type = $a_arr['type'];
		$b            = $b_arr['name'];
		$b_table_type = $b_arr['type'];

		// Views must always go after tables (since they can depend upon them)
		if ( 'VIEW' == $a_table_type && 'VIEW' != $b_table_type ) {
			return 1;
		}
		if ( 'VIEW' == $b_table_type && 'VIEW' != $a_table_type ) {
			return -1;
		}

		if ( 'wp' != self::$whichdb ) {
			return strcmp( $a, $b );
		}

		global $backupplus;
		if ( $a == $b ) {
			return 0;
		}
		$our_table_prefix = self::$table_prefix_raw;
		if ( $a == $our_table_prefix . 'options' ) {
			return -1;
		}
		if ( $b == $our_table_prefix . 'options' ) {
			return 1;
		}
		if ( $a == $our_table_prefix . 'site' ) {
			return -1;
		}
		if ( $b == $our_table_prefix . 'site' ) {
			return 1;
		}
		if ( $a == $our_table_prefix . 'blogs' ) {
			return -1;
		}
		if ( $b == $our_table_prefix . 'blogs' ) {
			return 1;
		}
		if ( $a == $our_table_prefix . 'users' ) {
			return -1;
		}
		if ( $b == $our_table_prefix . 'users' ) {
			return 1;
		}
		if ( $a == $our_table_prefix . 'usermeta' ) {
			return -1;
		}
		if ( $b == $our_table_prefix . 'usermeta' ) {
			return 1;
		}

		if ( empty( $our_table_prefix ) ) {
			return strcmp( $a, $b );
		}

		try {
			$core_tables = array_merge( self::$dbhandle->tables, self::$dbhandle->global_tables, self::$dbhandle->ms_global_tables );
		} catch ( Exception $e ) {
			$backupplus->log( $e->getMessage() );
		}

		if ( empty( $core_tables ) ) {
			$core_tables = array( 'terms', 'term_taxonomy', 'termmeta', 'term_relationships', 'commentmeta', 'comments', 'links', 'postmeta', 'posts', 'site', 'sitemeta', 'blogs', 'blogversions', 'blogmeta' );
		}

		$na = BackupPlus_Manipulation_Functions::str_replace_once( $our_table_prefix, '', $a );
		$nb = BackupPlus_Manipulation_Functions::str_replace_once( $our_table_prefix, '', $b );
		if ( in_array( $na, $core_tables ) && ! in_array( $nb, $core_tables ) ) {
			return -1;
		}
		if ( ! in_array( $na, $core_tables ) && in_array( $nb, $core_tables ) ) {
			return 1;
		}
		return strcmp( $a, $b );
	}

	/**
	 * Detect if the table has a composite primary key (composed from multiple columns)
	 *
	 * @param String      $table    - table to examine
	 * @param Object|Null $wpdb_obj - WPDB-like object (requires the get_results() method), or null to use the global default
	 *
	 * @return Boolean
	 */
	public static function table_has_composite_private_key( $table, $wpdb_obj = null ) {

		$wpdb = ( null === $wpdb_obj ) ? $GLOBALS['wpdb'] : $wpdb_obj;

		$table_structure = ( new BackupPlus_SQL( $wpdb->dbh ) )->rows( 'DESCRIBE %i', array( $table ) );
		if ( ! $table_structure ) {
			return false;
		}

		$primary_key_columns_found = 0;

		foreach ( $table_structure as $struct ) {
			if ( isset( $struct->Key ) && 'PRI' == $struct->Key ) {
				++$primary_key_columns_found;
				if ( $primary_key_columns_found > 1 ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Escape the LIKE special characters % and _ in text that is matched literally.
	 *
	 * @param String $text The raw text to be escaped. The input typed by the user should have no extra or deleted slashes.
	 * @return String Text in the form of a LIKE phrase, to be bound as a value with BackupPlus_SQL.
	 */
	public static function esc_like( $text ) {
		return function_exists( 'esc_like' ) ? esc_like( $text ) : addcslashes( $text, '_%\\' );
	}

	/**
	 * Return installation or activation link of WP-Optimize plugin
	 *
	 * @return String
	 */
	public static function get_install_activate_link_of_wp_optimize_plugin() {
		// If WP-Optimize is activated, then return empty.
		if ( class_exists( 'WP_Optimize' ) ) {
			return '';
		}

		// Generally it is 'wp-optimize/wp-optimize.php',
		// but we can't assume that the user hasn't renamed the plugin folder - with 3 million UDP users and 1 million AIOWPS, there will be some who have.
		$wp_optimize_plugin_file_rel_to_plugins_dir = BackupPlus_Database_Utility::get_wp_optimize_plugin_file_rel_to_plugins_dir();

		// If BackupPlus is installed but not activated, then return activate link.
		if ( $wp_optimize_plugin_file_rel_to_plugins_dir ) {
			$activate_url = add_query_arg(
				array(
					'_wpnonce' => wp_create_nonce( 'activate-plugin_' . $wp_optimize_plugin_file_rel_to_plugins_dir ),
					'action'   => 'activate',
					'plugin'   => $wp_optimize_plugin_file_rel_to_plugins_dir,
				),
				network_admin_url( 'plugins.php' )
			);

			// If is network admin then add to link network activation.
			if ( is_network_admin() ) {
				$activate_url = add_query_arg( array( 'networkwide' => 1 ), $activate_url );
			}
			return sprintf( '%s <a href="%s">%s</a>', __( 'WP-Optimize is installed but currently inactive.', 'backupplus' ), $activate_url, __( 'Follow this link to activate the WP-Optimize plugin.', 'backupplus' ) );
		}

		// If WP-Optimize is neither activated nor installed then return the installation link
		return '<a href="' . wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&amp;backupplus_noautobackup=1&amp;plugin=wp-optimize' ), 'install-plugin_wp-optimize' ) . '">' . __( 'Follow this link to install the WP-Optimize plugin.', 'backupplus' ) . '</a>';
	}

	/**
	 * Get path to the WP-Optimize plugin file relative to the plugins directory.
	 *
	 * @return String|false path to the WP-Optimize plugin file relative to the plugins directory
	 */
	public static function get_wp_optimize_plugin_file_rel_to_plugins_dir() {
		if ( ! function_exists( 'get_plugins' ) ) {
			include_once ABSPATH . '/wp-admin/includes/plugin.php';
		}

		$installed_plugins      = get_plugins();
		$installed_plugins_keys = array_keys( $installed_plugins );
		foreach ( $installed_plugins_keys as $plugin_file_rel_to_plugins_dir ) {
			$temp_plugin_file_name = substr( $plugin_file_rel_to_plugins_dir, strpos( $plugin_file_rel_to_plugins_dir, '/' ) + 1 );
			if ( 'wp-optimize.php' == $temp_plugin_file_name ) {
				return $plugin_file_rel_to_plugins_dir;
			}
		}
		return false;
	}
}
