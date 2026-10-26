<?php

/** Restore bookkeeping: job start, progress, state cleanup and autoloader conflicts. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Core_Restore_Support {

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
		$sql = BackupPlus_SQL::wpdb();
		if ( $include_locks ) {
			$sql->execute(
				'DELETE FROM %i WHERE (%i LIKE ? OR %i LIKE ? OR %i LIKE ? OR %i LIKE ? OR %i LIKE ? OR %i LIKE ?)',
				array( $table, $field, $field, $field, $field, $field, $field ),
				array(
					BackupPlus_Database_Utility::esc_like( 'backupplus_unlocked_' ) . '%',
					BackupPlus_Database_Utility::esc_like( 'backupplus_locked_' ) . '%',
					BackupPlus_Database_Utility::esc_like( 'backupplus_last_lock_time_' ) . '%',
					BackupPlus_Database_Utility::esc_like( 'backupplus_semaphore_' ) . '%',
					BackupPlus_Database_Utility::esc_like( 'backupplus_jobdata_' ) . '%',
					BackupPlus_Database_Utility::esc_like( 'backupplus_last_scheduled_' ) . '%',
				)
			);
		} elseif ( ! empty( $this->nonce ) ) {
			$sql->execute( 'DELETE FROM %i WHERE %i LIKE ? AND %i != ?', array( $table, $field, $field ), array( BackupPlus_Database_Utility::esc_like( 'backupplus_jobdata_' ) . '%', "backupplus_jobdata_{$this->nonce}" ) );
		} else {
			$sql->execute( 'DELETE FROM %i WHERE %i LIKE ?', array( $table, $field ), array( BackupPlus_Database_Utility::esc_like( 'backupplus_jobdata_' ) . '%' ) );
		}
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
}
