<?php

/** Reading the backup history: sets, incremental chains, the existing backups table and the latest backups. */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct $wpdb query is required for this operation.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- some query operations need to always receive the most up-to-date or actual data directly from the database, reducing the risk of serving stale information.
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Backup_History_Query {

	/**
	 * Get the backup history for an indicated timestamp, or the complete set of all backup histories
	 *
	 * @param Integer|Boolean $timestamp - Indicate a particular timestamp to get a particular backup job, or false to get a list of them all (sorted by most recent first).
	 *
	 * @return Array - either the particular backup indicated, or the full list.
	 */
	public static function get_history( $timestamp = false ) {

		$backup_history = BackupPlus_Options::get_backupplus_option( 'backupplus_backup_history' );
		// N.B. Doing a direct wpdb->get_var() here actually *introduces* a race condition

		if ( ! is_array( $backup_history ) ) {
			$backup_history = array();
		}
		if ( class_exists( 'BackupPlus_PITR' ) ) {
			$backup_history = BackupPlus_PITR::decorate_history( $backup_history );
		}

		$backup_history = self::build_incremental_sets( $backup_history );
		if ( class_exists( 'BackupPlus_Storage_Limits' ) ) { $backup_history = BackupPlus_Storage_Limits::discovery( $backup_history ); }

		if ( $timestamp ) {
			return isset( $backup_history[ $timestamp ] ) ? $backup_history[ $timestamp ] : array();
		}

		// The most recent backup will be first. Then we can array_pop().
		krsort( $backup_history );

		return $backup_history;
	}

	/**
	 * Add jobdata to all entries in an array of history items which do not already have it (key: 'jobdata'). If none is found, it will still be set, but empty.
	 *
	 * @param Array $backup_history - the list of history items
	 *
	 * @return Array
	 */
	public static function add_jobdata( $backup_history ) {

		global $wpdb, $backupplus;
		$table        = is_multisite() ? $wpdb->sitemeta : $wpdb->options;
		$key_column   = is_multisite() ? 'meta_key' : 'option_name';
		$value_column = is_multisite() ? 'meta_value' : 'option_value';

		$any_more = true;

		while ( $any_more ) {

			$any_more   = false;
			$columns    = array();
			$nonces_map = array();

			foreach ( $backup_history as $timestamp => $backup ) {
				if ( isset( $backup['jobdata'] ) ) {
					continue;
				}
				$nonce                = $backup['nonce'];
				$nonces_map[ $nonce ] = $timestamp;
				$columns[]            = $nonce;
				// Approx. 2.5MB of data would be expected if they all had 5KB each (though in reality we expect very few of them to have any)
				if ( count( $columns ) >= 500 ) {
					$any_more = true;
					break;
				}
			}

			if ( empty( $columns ) ) {
				break;
			}

			$option_names = array();
			foreach ( $columns as $nonce ) {
				$option_names[] = 'backupplus_jobdata_' . $nonce;
			}

			$all_jobdata = BackupPlus_SQL::wpdb()->rows( 'SELECT %i, %i FROM %i WHERE %i IN (?)', array( $key_column, $value_column, $table, $key_column ), array( $option_names ) );

			foreach ( (array) $all_jobdata as $values ) {
				$nonce = substr( $values->$key_column, strlen( 'backupplus_jobdata_' ) );
				if ( empty( $nonces_map[ $nonce ] ) || empty( $values->$value_column ) ) {
					continue;
				}
				$jobdata = $backupplus->unserialize( $values->$value_column );
				$backup_history[ $nonces_map[ $nonce ] ]['jobdata'] = empty( $jobdata ) ? array() : $jobdata;
			}
			foreach ( $columns as $nonce ) {
				if ( ! empty( $nonces_map[ $nonce ] ) && ! isset( $backup_history[ $nonces_map[ $nonce ] ]['jobdata'] ) ) {
					$backup_history[ $nonces_map[ $nonce ] ]['jobdata'] = array();
				}
			}
		}

		return $backup_history;
	}

	/**
	 * Get the backup history for an indicated nonce
	 *
	 * @param String $nonce - Backup nonce to get a particular backup job
	 *
	 * @return Array|Boolean - either the particular backup indicated, or false
	 */
	public static function get_backup_set_by_nonce( $nonce ) {
		if ( empty( $nonce ) ) {
			return false;
		}
		$backup_history = self::get_history();
		foreach ( $backup_history as $timestamp => $backup_info ) {
			if ( $nonce == $backup_info['nonce'] ) {
				$backup_info['timestamp'] = $timestamp;
				return $backup_info;
			}
		}
		return false;
	}

	/**
	 * Get the HTML for the table of existing backups
	 *
	 * @param Array|Boolean $backup_history - a list of backups to use, or false to get the current list from the database
	 * @param Boolean       $backup_count   - the amount of backups currently displayed in the existing backups table
	 *
	 * @uses BackupPlus_Admin::include_template()
	 *
	 * @return String - HTML for the table
	 */
	public static function existing_backup_table( $backup_history = false, $backup_count = 0 ) {

		global $backupplus, $backupplus_admin;

		if ( false === $backup_history ) {
			$backup_history = self::get_history();
		}

		if ( class_exists( 'BackupPlus_Storage_Limits' ) && is_array( $backup_history ) ) { $backup_history = BackupPlus_Storage_Limits::visible_history( $backup_history ); }
		if ( ! is_array( $backup_history ) || empty( $backup_history ) ) {
			return '<div class="postbox"><p class="backupplus-no-backups-msg">' . __( 'You have not yet made any backups.', 'backupplus' ) . '</p> <p class="backupplus-no-backups-msg">' . __( 'If you have an existing backup that you wish to upload and restore from, then please use the "Upload backup files" link above.', 'backupplus' ) . ' ' . __( 'Or, if they are in remote storage, you can connect that remote storage (in the "Settings" tab), save your settings, and use the "Rescan remote storage" link.', 'backupplus' ) . '</p></div>';
		}

		if ( empty( $backup_count ) ) {
			$backup_count = defined( 'BACKUPPLUS_EXISTING_BACKUPS_LIMIT' ) ? BACKUPPLUS_EXISTING_BACKUPS_LIMIT : 100;
		}

		// Reverse date sort - i.e. most recent first
		krsort( $backup_history );

		$pass_values = array(
			'backup_history'      => self::add_jobdata( $backup_history ),
			'backupplus_dir'      => $backupplus->backups_dir_location(),
			'backupable_entities' => $backupplus->get_backupable_file_entities( true, true ),
			'backup_count'        => $backup_count,
			'show_paging_actions' => false,
		);

		return $backupplus_admin->include_template( 'wp-admin/settings/existing-backups-table.php', true, $pass_values );
	}

	/**
	 * This function will scan the backup history and split the files up in to incremental sets, foreign backup sets will only have one incremental set.
	 *
	 * @param Array $backup_history - the saved backup history
	 *
	 * @return Array - returns the backup history but also includes the incremental sets
	 */
	public static function build_incremental_sets( $backup_history ) {

		global $backupplus;

		$backupable_entities = array_keys( $backupplus->get_backupable_file_entities( true, false ) );

		$accept = apply_filters( 'backupplus_accept_archivename', array() );

		foreach ( $backup_history as $btime => $bdata ) {

			$incremental_sets    = array();
			$backupable_entities = array_keys( BackupPlus_Backup_Sources::restore_entities( array_fill_keys( $backupable_entities, true ), $bdata ) );

			foreach ( $backupable_entities as $entity ) {

				if ( empty( $bdata[ $entity ] ) || ! is_array( $bdata[ $entity ] ) ) {
					continue;
				}

				foreach ( $bdata[ $entity ] as $key => $filename ) {

					if ( preg_match( '/^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-[\-a-z]+([0-9]+)?+(\.(zip|zip\.crypt|gz|gz\.crypt|tar\.zst|tar\.zst\.crypt|sql\.zst|sql\.zst\.crypt))?$/i', $filename, $matches ) ) {

						$timestamp = strtotime( get_gmt_from_date( date_format( DateTime::createFromFormat( 'Y-m-d-Hi', $matches[1] ), 'Y-m-d H:i' ) ) );

						if ( ! isset( $incremental_sets[ $timestamp ] ) ) {
							$incremental_sets[ $timestamp ] = array();
						}

						if ( ! isset( $incremental_sets[ $timestamp ][ $entity ] ) ) {
							$incremental_sets[ $timestamp ][ $entity ] = array();
						}

						$incremental_sets[ $timestamp ][ $entity ][ $key ] = $filename;
					} else {
						$accepted = false;

						foreach ( $accept as $fkey => $acc ) {
							if ( preg_match( '/' . $acc['pattern'] . '/i', $filename ) ) {
								$accepted = $fkey;
							}
						}

						if ( ! empty( $accepted ) && ( false != ( $btime = apply_filters( 'backupplus_foreign_gettime', false, $accepted, $filename ) ) ) && $btime > 0 ) {

							$timestamp = $btime;

							if ( ! isset( $incremental_sets[ $timestamp ] ) ) {
								$incremental_sets[ $timestamp ] = array();
							}

							if ( ! isset( $incremental_sets[ $timestamp ][ $entity ] ) ) {
								$incremental_sets[ $timestamp ][ $entity ] = array();
							}

							$incremental_sets[ $timestamp ][ $entity ][] = $filename;
						}
					}
				}
			}
			ksort( $incremental_sets );
			$backup_history[ $btime ]['incremental_sets'] = $incremental_sets;
		}

		return $backup_history;
	}

	/**
	 * This function will look through backup history and return the latest full backup with all configured entities.
	 *
	 * @return String - the backup nonce of a full backup or an empty string if none are found
	 */
	public static function get_latest_full_backup() {

		$backup_history = self::get_history();

		global $backupplus;

		$backupable_entities = $backupplus->get_backupable_file_entities( true, true );

		foreach ( $backupable_entities as $key => $info ) {
			$included = BackupPlus_Credential_Key_Store::BACKUP_ENTITY === $key
				? BackupPlus_Backup_Sources::included( $key )
				: BackupPlus_Options::get_backupplus_option( "backupplus_include_$key", false );
			if ( ! $included ) {
				unset( $backupable_entities[ $key ] );
			}
		}

		foreach ( $backup_history as $key => $backup ) {

			foreach ( $backupable_entities as $key => $info ) {
				if ( ! isset( $backup[ $key ] ) ) {
					continue 2;
				}
			}

			return $backup['nonce'];

		}

		return '';
	}

	/**
	 * This function will look through backup history and return the latest backup usable for an incremental backup.
	 *
	 * @param array $entities - an array of file entities that are included in this job
	 *
	 * @return String         - the backup nonce of a full backup or an empty string if none are found
	 */
	public static function get_latest_backup( $entities ) {

		if ( empty( $entities ) ) {
			return '';
		}

		$backup_history = self::get_history();

		foreach ( $backup_history as $backup ) {

			// We don't want to add an increment to a backup of another site
			if ( isset( $backup['native'] ) && false == $backup['native'] ) {
				continue;
			}

			foreach ( $entities as $type ) {
				if ( ! isset( $backup[ $type ] ) ) {
					continue 2;
				}
			}

			return $backup['nonce'];

		}

		return '';
	}

	/**
	 * This function will look through the backup history and return an array of entity types found in the history
	 *
	 * @return array - an array of backup entities found in the history or an empty array if there are none
	 */
	public static function get_existing_backup_entities() {

		$backup_history = self::get_history();

		global $backupplus;

		$backupable_entities = $backupplus->get_backupable_file_entities( true, true );

		$entities = array();

		foreach ( $backup_history as $key => $backup ) {

			foreach ( $backupable_entities as $key => $info ) {
				if ( isset( $backup[ $key ] ) ) {
					$entities[] = $key;
				}
			}
		}

		return $entities;
	}
}
