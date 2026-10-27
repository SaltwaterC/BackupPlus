<?php

/** Saving the backup history and keeping it across a restore. */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct $wpdb query is required for this operation.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- some query operations need to always receive the most up-to-date or actual data directly from the database, reducing the risk of serving stale information.
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Backup_History_Storage {

	/**
	 * Save the backup history. An abstraction function to make future changes easier.
	 *
	 * @param Array   $backup_history - the backup history
	 * @param Boolean $use_cache      - whether or not to use the WP options cache
	 */
	public static function save_history( $backup_history, $use_cache = true ) {

		global $backupplus, $wpdb;

		// This data is constructed at run-time from the other keys; we do not wish to save redundant data
		foreach ( $backup_history as $btime => $bdata ) {
			unset( $backup_history[ $btime ]['incremental_sets'] );
			// The atomic PITR index owns these growing lists. decorate_history()
			// reconstructs them for consumers, without copying them into every SQL update.
			if ( ! empty( $bdata['pitr']['chain_id'] ) ) {
				unset( $backup_history[ $btime ]['pitr']['points'], $backup_history[ $btime ]['pitr']['downloads'], $backup_history[ $btime ]['pitr']['snapshot_coordinate'], $backup_history[ $btime ]['pitr']['end_coordinate'] );
			}
		}

		$previous_history = self::get_history();

		$wpdb_previous_last_error = $wpdb->last_error;

		// Explicitly set autoload to 'no', as the backup history can get quite big.
		$changed = BackupPlus_Options::update_backupplus_option( 'backupplus_backup_history', $backup_history, $use_cache, 'no' );

		if ( ! $changed && '' !== $wpdb->last_error && $wpdb_previous_last_error != $wpdb->last_error ) {
			// if an error occurred, there is a possibility if this error is caused by invalid characters found in 'label'
			foreach ( $backup_history as $btime => $bdata ) {
				if ( isset( $bdata['label'] ) ) {
					// try removing invalid characters from 'label'
					if ( method_exists( $wpdb, 'strip_invalid_text_for_column' ) ) {
						$backup_history[ $btime ]['label'] = $wpdb->strip_invalid_text_for_column( $wpdb->options, 'option_value', $backup_history[ $btime ]['label'] );
					} else {

						// This replacement, may of course, drop parts of the label. This is judged to be better than dropping it all - and WPDB::strip_invalid_text_for_column() exists on WP 4.2+.
						$backup_history[ $btime ]['label'] = preg_replace( '/[^a-z0-9-_ ]/i', '', $backup_history[ $btime ]['label'] );
					}

					if ( '' === $backup_history[ $btime ]['label'] ) {
						unset( $backup_history[ $btime ]['label'] );
					}
				}
			}

			// try to save it again
			$changed = BackupPlus_Options::update_backupplus_option( 'backupplus_backup_history', $backup_history, $use_cache, 'no' );
		}

		if ( ! $changed ) {

			$max_packet_size    = $backupplus->max_packet_size( false, false );
			$serialization_size = strlen( addslashes( serialize( $backup_history ) ) );

			// Take off the *approximate* over-head of UPDATE wp_options SET option_value='' WHERE option_name='backupplus_backup_history'; (no need to be exact)
			if ( $max_packet_size < ( $serialization_size + 100 ) ) {

				$max_packet_size = $backupplus->max_packet_size();

				$changed = BackupPlus_Options::update_backupplus_option( 'backupplus_backup_history', $backup_history, $use_cache, 'no' );

				if ( ! $changed ) {

					$backupplus->log( 'The attempt to write the backup history to the WP database returned a negative code and the max packet size looked small. However, WP does not distinguish between a failure and no change from a previous update, so, this code is not conclusive and if no other symptoms are observed then there is no reason to infer any problem. Info: The updated database packet size is ' . $max_packet_size . '; the serialization size is ' . $serialization_size );

				}
			}
		}
		if ( $changed && class_exists( 'BackupPlus_Incremental_Backups' ) ) {
			foreach ( array_diff_key( $previous_history, $backup_history ) as $removed ) {
				if ( ! empty( $removed['incremental']['snapshots'] ) ) {
					BackupPlus_Incremental_Backups::discard_snapshots( $removed['incremental']['snapshots'] );
				}
			}
		}
	}

	/**
	 * Lock or unlock backups so that retention skips them. A recovery chain's automatic pre-restore pin only counts while its row here is locked (see BackupPlus_PITR::retention_locked()).
	 *
	 * @param Array   $timestamps - history keys to change.
	 * @param Boolean $keep       - true to lock, false to unlock.
	 *
	 * @return Array - 'locked' maps each changed timestamp to its new state; 'errors' maps each unchanged timestamp to a message.
	 */
	public static function set_always_keep( $timestamps, $keep ) {
		$history = self::get_history();
		$result  = array(
			'locked' => array(),
			'errors' => array(),
		);
		foreach ( array_unique( $timestamps ) as $timestamp ) {
			if ( ! isset( $history[ $timestamp ] ) ) {
				$result['errors'][ $timestamp ] = __( 'Backup set not found.', 'backupplus' );
				continue;
			}
			if ( $keep ) { $history[ $timestamp ]['always_keep'] = true; }
			else { unset( $history[ $timestamp ]['always_keep'] ); }
			$result['locked'][ $timestamp ] = (bool) $keep;
		}
		if ( $result['locked'] ) { self::save_history( $history ); }
		return $result;
	}

	/**
	 * Used by self::always_get_from_db()
	 *
	 * @return Mixed - the database option
	 */
	public static function filter_backupplus_backup_history() {
		global $wpdb, $backupplus;
		$row = BackupPlus_SQL::wpdb()->row( 'SELECT option_value FROM %i WHERE option_name = ? LIMIT 1', array( $wpdb->options ), array( 'backupplus_backup_history' ) );
		if ( is_object( $row ) && ! empty( $row->option_value ) ) {
			return $backupplus->unserialize( $row->option_value );
		}
		return false;
	}

	/**
	 * Make sure we get the value afresh from the db, instead of using the auto-loaded/cached value (which can be out of date, especially since backups are, by their nature, long-running)
	 */
	public static function always_get_from_db() {
		add_filter( 'pre_option_backupplus_backup_history', array( 'BackupPlus_Backup_History', 'filter_backupplus_backup_history' ) );
	}

	/**
	 * Save a backup into the history
	 *
	 * @param Integer $backup_time  - the time of the backup
	 * @param Array   $backup_array - the backup
	 */
	public static function save_backup( $backup_time, $backup_array ) {
		$backup_history = self::get_history();

		$backup_history[ $backup_time ] = isset( $backup_history[ $backup_time ] ) ? apply_filters( 'backupplus_merge_backup_history', $backup_array, $backup_history[ $backup_time ] ) : $backup_array;

		self::save_history( $backup_history, false );
	}

	/**
	 * Save backup history into a file
	 *
	 * @return void
	 */
	public static function preserve_backup_history() {
		self::$backup_history_on_restore = self::get_backup_history_option();
	}

	/**
	 * Update backup history label based on backup-history.txt
	 *
	 * @return void
	 */
	public static function restore_backup_history_label() {
		$backup_history            = self::get_backup_history_option();
		$saved_backup_history      = self::$backup_history_on_restore;
		$is_backup_history_changed = false;

		if ( empty( $saved_backup_history ) ) {
			return;
		}

		foreach ( $saved_backup_history as $backup ) {
			if ( ! isset( $backup['label'] ) ) {
				continue;
			}

			foreach ( $backup_history as $key => $value ) {
				if ( $backup['nonce'] === $value['nonce'] && ! empty( $backup_history[ $key ]['label'] ) && $backup_history[ $key ]['label'] !== $backup['label'] ) {
					$is_backup_history_changed       = true;
					$backup_history[ $key ]['label'] = $backup['label'];
				}
			}
		}

		if ( $is_backup_history_changed ) {
			self::save_history( $backup_history, false );
		}
	}

	/**
	 * Get backup history by checking for existence of the Multisite addon
	 *
	 * @return Array - the array of backup histories.
	 */
	private static function get_backup_history_option() {
		return BackupPlus_Options::get_backupplus_option( 'backupplus_backup_history' );
	}
}
