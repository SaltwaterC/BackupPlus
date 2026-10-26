<?php

/** Hooks into the restore run: recording the old site locations, cache and plugin handling. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Restore_Search_Replace_Restore_Hooks {

	public function lite_plugins_loaded() {
		global $backupplus;

		// Register restore search-and-replace hooks on supported WordPress versions.
		if ( is_a( $backupplus, 'BackupPlus' ) && method_exists( $backupplus, 'get_wordpress_version' ) && version_compare( $backupplus->get_wordpress_version(), '3.5', '>=' ) ) {
			if ( version_compare( PHP_VERSION, '5.3', '>=' ) ) {
				add_filter( 'backupplus_restore_all_downloaded_postscan', array( $this, 'lite_restore_all_downloaded_postscan' ), 20, 7 );
			}
			// Both MU and normal site
			add_action( 'backupplus_restorer_restore_options', array( $this, 'restorer_restore_options' ) );
		}
	}

	public function restorer_restore_options( $restore_options ) {
		$this->restore_options = $restore_options;
	}

	/**
	 * Disable W3TC and WP Super Cache, etc.
	 */
	public function restored_plugins() {
		if ( true !== $this->is_migration ) {
			return;
		}
		global $backupplus;
		$active_plugins = $backupplus->unserialize( $backupplus->option_filter_get( 'active_plugins' ) );
		if ( ! is_array( $active_plugins ) ) {
			return;
		}
		$disable_plugins = array(
			'w3-total-cache/w3-total-cache.php'   => 'W3 Total Cache',
			'wp-super-cache/wp-cache.php'         => 'W3 Super Cache',
			'quick-cache/quick-cache.php'         => 'Quick Cache',
			'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
		);
		foreach ( $disable_plugins as $slug => $desc ) {
			// in_array is case sensitive
			// if (in_array($slug, $active_plugins)) {
			if ( preg_grep( '#' . $slug . '#i', $active_plugins ) ) {
				unset( $active_plugins[ $slug ] );

				$backupplus->log( 'Disabled this plugin: %s: re-activate it manually when you are ready.', $desc );
				/* translators: %s: Plugin name or description */
				$backupplus->log( sprintf( __( 'Disabled this plugin: %s: re-activate it manually when you are ready.', 'backupplus' ), $desc ), 'notice-restore' );

			}
		}
		update_option( 'active_plugins', $active_plugins );
	}

	public function restorecachefiles( $val, $file ) {
		// On a migration, we don't want to add cache files if they do not already exist (because usually they won't work until re-installed)
		if ( true !== $this->is_migration || false == $val ) {
			return $val;
		}
		$val = is_file( WP_CONTENT_DIR . '/' . $file ) ? $val : false;
		if ( false == $val ) {
			global $backupplus;
			$backupplus->log_e( '%s: Skipping cache file (does not already exist)', $file );
		}
		return $val;
	}

	/**
	 * This method will check if the newly created table has already been created before, if it has then we should mark it to be search and replaced again.
	 *
	 * @param  String $table - the name of the newly created table
	 */
	public function backupplus_creating_table( $table ) {
		global $backupplus;

		if ( ! empty( $this->tables_replaced[ $table ] ) && $this->tables_replaced[ $table ] ) {
			$this->tables_replaced[ $table ] = false;
			$backupplus->log( 'Warning: This database table has already been created once, now marking it to be search and replaced again - will try to continue but if errors are encountered then check that the backup is correct.', 'notice-restore' );
		}
	}

	public function restored_plugins_one( $plugin ) {
		global $backupplus;
		$backupplus->log( __( 'Processed plugin:', 'backupplus' ) . ' ' . $plugin, 'notice-restore' );
		$backupplus->log( "Processed plugin: $plugin" );
	}

	/**
	 * WordPress action backupplus_restore_all_downloaded_postscan called during the restore process.
	 *
	 * The last four parameters can be edited in-place.
	 *
	 * @param Array   $backups   - list of backups
	 * @param Integer $timestamp - the timestamp (epoch time) of the backup being restored
	 * @param Array   $elements  - elements being restored (as the keys of the array)
	 * @param Array   $info      - information about the backup being restored
	 * @param Array   $mess      - array of informational-level messages
	 * @param Array   $warn      - array of warning-level messages
	 * @param Array   $err       - array of error-level messages
	 */
	public function lite_restore_all_downloaded_postscan( $backups, $timestamp, $elements, &$info, &$mess, &$warn, &$err ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Filter use
		if ( is_array( $info ) && isset( $info['migration'] ) && true === $info['migration'] ) {
			if ( empty( $info['addui'] ) ) {
				$info['addui'] = '';
			}
			$info['addui'] .= '<div id="backupplus_restorer_dboptions" class="udp-notice before-h2 backupplus-restore-option backupplus-hidden">';
			$info['addui'] .= '<h4>' . __( 'Database restoration options:', 'backupplus' ) . '</h4>';
			$info['addui'] .= '<input name="backupplus_restorer_replacesiteurl" id="backupplus_restorer_replacesiteurl" type="checkbox" value="1" checked><label for="backupplus_restorer_replacesiteurl" title="' .
			/* translators: %s: Current site URL */
			sprintf( __( 'All references to the site location in the database will be replaced with your current site URL, which is: %s', 'backupplus' ), htmlspecialchars( untrailingslashit( site_url() ) ) ) . '"> ' .
			__( 'Search and replace site location in the database', 'backupplus' ) . '</label>';
			$info['addui'] .= '</div>';
		}
	}

	public function backupplus_restore_db_record_old_siteurl( $old_siteurl ) {
		// Only record once
		if ( ! empty( $this->old_siteurl ) ) {
			return;
		}
		$this->old_siteurl = $old_siteurl;
	}

	public function backupplus_restore_db_record_old_home( $old_home ) {
		// Only record once
		if ( ! empty( $this->old_home ) ) {
			return;
		}
		$this->old_home = $old_home;
	}

	public function backupplus_restore_db_record_old_content( $old_content ) {
		// Only record once
		if ( ! empty( $this->old_content ) ) {
			return;
		}
		$this->old_content = $old_content;
	}

	public function backupplus_restore_db_record_old_uploads( $old_uploads ) {
		// Only record once
		if ( ! empty( $this->old_uploads ) ) {
			return;
		}
		$this->old_uploads = $old_uploads;
	}

	/**
	 * This function is called via a filter it saves the passed in old abspath value from restorer.php to a class variable for later use
	 *
	 * @param String $old_abspath - the old abspath
	 *
	 * @return void
	 */
	public function backupplus_restore_db_record_old_abspath( $old_abspath ) {
		if ( '' !== $this->old_abspath ) {
			return;
		}
		$this->old_abspath = $old_abspath;
	}

	public function backupplus_restore_db_pre() {

		global $wpdb, $backupplus, $backupplus_restorer;

		$this->siteurl  = untrailingslashit( site_url() );
		$this->home     = untrailingslashit( home_url() );
		$this->content  = untrailingslashit( content_url() );
		$this->use_wpdb = $backupplus_restorer->use_wpdb();

		$this->base_prefix = $backupplus->get_table_prefix( false );

		$mysql_dbh  = false;
		$use_mysqli = false;

		if ( ! $this->use_wpdb ) {
			// We have our own extension which drops lots of the overhead on the query
			$wpdb_obj = $backupplus_restorer->get_db_object();
			// Was that successful?
			if ( ! $wpdb_obj->is_mysql || ! $wpdb_obj->ready ) {
				$this->use_wpdb = true;
			} else {
				$this->wpdb_obj = $wpdb_obj;
				$mysql_dbh      = $wpdb_obj->backupplus_get_database_handle();
				$use_mysqli     = $wpdb_obj->backupplus_use_mysqli();
			}
		}

		$this->mysql_dbh  = $mysql_dbh;
		$this->use_mysqli = $use_mysqli;

		if ( true == $this->use_wpdb ) {
			$backupplus->log_e( 'Database access: Direct MySQL access is not available, so we are falling back to wpdb (this will be considerably slower)' );
		}

		if ( is_multisite() ) {
			$sites = BackupPlus_SQL::wpdb()->rows( 'SELECT id, domain, path FROM %i', array( $this->base_prefix . 'site' ), array(), ARRAY_N );
			if ( is_array( $sites ) ) {
				$nsites = array();
				foreach ( $sites as $site ) {
					$nsites[ $site[0] ] = array( $site[1], $site[2] );
				}
				$this->original_sites = $nsites;
			}
		}

		$this->report = array(
			'tables'    => 0,
			'rows'      => 0,
			'change'    => 0,
			'updates'   => 0,
			'timetaken' => 0,
			'errors'    => array(),
		);
	}
}
