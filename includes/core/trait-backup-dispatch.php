<?php

/** Starting backups: entry points, scheduling, nonces and the job semaphore. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Core_Backup_Dispatch {

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

	/** Run a queued manual backup from a separate WP-Cron request. */
	public function dispatch_backupnow( $event, $options ) {
		$allowed_events = array(
			'backupplus_backupnow_backup',
			'backupplus_backupnow_backup_database',
			'backupplus_backupnow_backup_all',
		);

		if ( ! in_array( $event, $allowed_events, true ) || ! is_array( $options ) ) {
			return;
		}

		if ( ! empty( $options['pitr_capture'] ) ) {
			if ( empty( $options['incremental'] ) || 'backupplus_backupnow_backup' === $event ) {
				return;
			}
			$result = BackupPlus_PITR::manual_capture( $options );
			if ( false !== $result && 'backupplus_backupnow_backup_all' === $event ) {
				unset( $options['pitr_capture'] );
				do_action( 'backupplus_backupnow_backup', $options );
			}
			return $result;
		}

		do_action( $event, $options );
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
	 * If files + db are on different schedules but are scheduled for the same time,
	 * then combine them $event = (object) array('hook' => $hook, 'timestamp' => $timestamp, 'schedule' => $recurrence, 'args' => $args, 'interval' => $schedules[$recurrence]['interval']);
	 * See wp_schedule_single_event() and wp_schedule_event() in wp-includes/cron.php
	 *
	 * @param  Object|Boolean $event - the event being scheduled
	 * @return Object|Boolean - the filtered value
	 */
	public function schedule_event( $event ) {

		static $scheduled = array();

		// Incremental and cleanup events are never combined with other jobs.
		if ( is_object( $event ) && in_array( $event->hook, array( 'backupplus_backup_increments', 'backupplus_retention_cleanup' ), true ) ) {
			return apply_filters( 'backupplus_override_scheduled_backup_event', $event );
		}

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

			// Override the timestamp of the scheduled backup event based on the user's exact time setting.
			$event = apply_filters( 'backupplus_override_scheduled_backup_event', $event );

			// Exact times are chosen deliberately, so nearby schedules are not merged.
			if ( BackupPlus_Backup_Schedule::instance()->any_exact() ) {
				return $event;
			}

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
	 * Filter callback for saving the backupplus_interval option. The cron event is updated by BackupPlus_Backup_Schedule::reconcile() once all settings are saved, so that it also sees the new timing settings.
	 *
	 * @param  String $interval
	 * @return String - filtered value
	 */
	public function schedule_backup( $interval ) {
		if ( 'manual' == $interval ) {
			return 'manual';
		}
		$valid_schedules = wp_get_schedules();
		return empty( $valid_schedules[ $interval ] ) ? 'daily' : $interval;
	}

	/**
	 * Filter callback for saving the backupplus_interval_database option; see schedule_backup().
	 *
	 * @param  String $interval
	 * @return String - filtered value
	 */
	public function schedule_backup_database( $interval ) {
		return $this->schedule_backup( $interval );
	}

	/**
	 * Filter callback for saving the backupplus_interval_increments option: increments run from hourly to daily. The cron event is updated by BackupPlus_Backup_Schedule::reconcile(); see schedule_backup().
	 *
	 * @param  String $interval
	 * @return String - filtered value
	 */
	public function schedule_backup_increments( $interval ) {
		$interval = BackupPlus_Backup_Schedule::increments_interval( $interval );
		return $interval ? $interval : 'none';
	}

	/**
	 * This function will generate a random backup schedule timestamp between the hours of 9PM and 7AM and return it
	 *
	 * @return string - the random timestamp
	 */
	public function random_schedule_time() {

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
}
