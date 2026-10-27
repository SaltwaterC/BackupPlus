<?php

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No access.' );
}

/**
 * A class for scheduling-related code.
 * N.B. This class began life Nov 2018; it is not guaranteed to contain all scheduling-related code. The variables used have also generally remained in the BackupPlus class.
 */
class BackupPlus_Job_Scheduler {

	/** Seconds after which an unfinished backup job no longer holds back scheduled incremental backups. */
	const ABANDONED_AFTER = 43200;

	/**
	 * Backup jobs that have started but not finished: running now, or waiting for a resumption after an error or a timeout.
	 *
	 * A job that has been going for longer than the limit is treated as abandoned, so that one that can never finish does not stop scheduled backups for good.
	 *
	 * @return array Job data keyed by job nonce.
	 */
	public static function unfinished_backup_jobs() {
		$ids = array( BackupPlus_CLI::fresh( 'backupplus_oneshotnonce', true ) );
		BackupPlus_CLI::fresh( 'cron' );
		foreach ( (array) _get_cron_array() as $hooks ) {
			foreach ( isset( $hooks['backupplus_backup_resume'] ) ? $hooks['backupplus_backup_resume'] : array() as $event ) {
				if ( isset( $event['args'][1] ) ) {
					$ids[] = $event['args'][1];
				}
			}
		}
		$jobs = array();
		foreach ( array_unique( array_filter( $ids, 'is_string' ) ) as $id ) {
			$job = BackupPlus_CLI::fresh( 'backupplus_jobdata_' . $id, true );
			if ( ! is_array( $job ) || ! empty( $job['terminal_outcome'] ) || ! isset( $job['job_type'] ) || ! in_array( $job['job_type'], array( 'backup', 'incremental' ), true ) || ( isset( $job['jobstatus'] ) && 'finished' === $job['jobstatus'] ) ) {
				continue;
			}
			if ( isset( $job['backup_time'] ) && time() - (int) $job['backup_time'] > self::ABANDONED_AFTER ) {
				continue;
			}
			$jobs[ $id ] = $job;
		}
		return $jobs;
	}

	/**
	 * This function is purely for timing - we just want to know the maximum run-time; not whether we have achieved anything during it. It will also run a check on whether the resumption interval is being approached.
	 */
	public static function record_still_alive() {

		global $backupplus;

		// Update the record of maximum detected runtime on each run
		$time_passed = $backupplus->jobdata_get( 'run_times' );
		if ( ! is_array( $time_passed ) ) {
			$time_passed = array();
		}

		$time_this_run                                        = microtime( true ) - $backupplus->opened_log_time;
		$time_passed[ (int) $backupplus->current_resumption ] = $time_this_run;
		$backupplus->jobdata_set( 'run_times', $time_passed );

		$resume_interval = $backupplus->jobdata_get( 'resume_interval' );
		if ( $time_this_run + 30 > $resume_interval ) {
			$new_interval = ceil( $time_this_run + 30 );
			set_site_transient( 'backupplus_initial_resume_interval', (int) $new_interval, 8 * 86400 );
			$backupplus->log( 'The time we have been running (' . round( $time_this_run, 1 ) . ") is approaching the resumption interval ($resume_interval) - increasing resumption interval to $new_interval" );
			$backupplus->jobdata_set( 'resume_interval', $new_interval );
		}
	}

	/**
	 * This method helps with efficient scheduling
	 */
	public static function reschedule_if_needed() {

		global $backupplus;

		// If nothing is scheduled, then no re-scheduling is needed, so return
		if ( empty( $backupplus->newresumption_scheduled ) ) {
			return;
		}

		$time_away = $backupplus->newresumption_scheduled - time();

		// 45 is chosen because it is 15 seconds more than what is used to detect recent activity on files (file mod times). (If we use exactly the same, then it's more possible to slightly miss each other)
		if ( $time_away > 1 && $time_away <= 45 ) {
			$backupplus->log( 'The scheduled resumption is within 45 seconds - will reschedule' );
			// Increase interval generally by 45 seconds, on the assumption that our prior estimates were innaccurate (i.e. not just 45 seconds *this* time)
			self::increase_resume_and_reschedule( 45 );
		}
	}

	/**
	 * Indicate that something useful happened. Calling this at appropriate times is an important part of scheduling decisions.
	 */
	public static function something_useful_happened() {

		global $backupplus;

		self::record_still_alive();

		if ( ! $backupplus->something_useful_happened ) {

			// Update the record of when something useful happened
			$useful_checkins = $backupplus->jobdata_get( 'useful_checkins' );
			if ( ! is_array( $useful_checkins ) ) {
				$useful_checkins = array();
			}
			if ( ! in_array( $backupplus->current_resumption, $useful_checkins ) ) {
				$useful_checkins[] = $backupplus->current_resumption;
				$backupplus->jobdata_set( 'useful_checkins', $useful_checkins );
			}
		}

		$backupplus->something_useful_happened = true;

		$backupplus_dir = $backupplus->backups_dir_location();
		if ( file_exists( $backupplus_dir . '/deleteflag-' . $backupplus->nonce . '.txt' ) ) {
			$backupplus->log( 'User request for abort: backup job will be immediately halted' );
			@unlink( $backupplus_dir . '/deleteflag-' . $backupplus->nonce . '.txt' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.
			$backupplus->backup_finish( true, true, true );
			die;
		}

		if ( $backupplus->current_resumption >= 9 && false == $backupplus->newresumption_scheduled ) {
			$backupplus->log( 'This is resumption ' . $backupplus->current_resumption . ', but meaningful activity is still taking place; so a new one will be scheduled' );
			// We just use max here to make sure we get a number at all
			$resume_interval = max( $backupplus->jobdata_get( 'resume_interval' ), 75 );
			// Don't consult the minimum here
			// if (!is_numeric($resume_interval) || $resume_interval<300) { $resume_interval = 300; }
			$schedule_for                        = time() + $resume_interval;
			$backupplus->newresumption_scheduled = $schedule_for;
			wp_schedule_single_event( $schedule_for, 'backupplus_backup_resume', array( $backupplus->current_resumption + 1, $backupplus->nonce ) );
		} else {
			self::reschedule_if_needed();
		}
	}

	/**
	 * Reschedule the next resumption for the specified amount of time in the future
	 *
	 * @uses wp_schedule_single_event()
	 *
	 * @param Integer $how_far_ahead - a number of seconds
	 */
	public static function reschedule( $how_far_ahead ) {

		global $backupplus;

		// Reschedule - remove presently scheduled event
		$next_resumption = $backupplus->current_resumption + 1;
		wp_clear_scheduled_hook( 'backupplus_backup_resume', array( $next_resumption, $backupplus->nonce ) );
		// Add new event
		// This next line may be too cautious; but until 14-Aug-2014, it was 300.
		// Update 20-Mar-2015 - lowered from 180 to 120
		// Update 03-Aug-2018 - lowered from 120 to 100
		// Update 09-Oct-2020 - lowered from 100 to 60
		if ( $how_far_ahead < 60 ) {
			$how_far_ahead = 60;
		}
		$schedule_for = time() + $how_far_ahead;
		$backupplus->log( "Rescheduling resumption $next_resumption: moving to $how_far_ahead seconds from now ($schedule_for)" );
		wp_schedule_single_event( $schedule_for, 'backupplus_backup_resume', array( $next_resumption, $backupplus->nonce ) );
		$backupplus->newresumption_scheduled = $schedule_for;
	}

	/**
	 * Terminate a backup run because other activity on the backup has been detected
	 *
	 * @uses die()
	 *
	 * @param String  $file                - Indicate the file whose recent modification is indicative of activity
	 * @param Integer $time_now            - The epoch time at which the detection took place
	 * @param Integer $time_mod            - The epoch time at which the file was modified
	 * @param Boolean $increase_resumption - Whether or not to increase the resumption interval
	 */
	public static function terminate_due_to_activity( $file, $time_now, $time_mod, $increase_resumption = true ) {

		global $backupplus;

		// We check-in, to avoid 'no check in last time!' detectors firing.
		self::record_still_alive();

		// Log
		$file_size = file_exists( $file ) ? round( filesize( $file ) / 1024, 1 ) . 'KB' : 'n/a';
		$backupplus->log( 'Terminate: ' . basename( $file ) . " exists with activity within the last 30 seconds (time_mod=$time_mod, time_now=$time_now, diff=" . ( floor( $time_now - $time_mod ) ) . ", size=$file_size). This likely means that another BackupPlus run is at work; so we will exit." );

		$increase_by = $increase_resumption ? 120 : 0;
		self::increase_resume_and_reschedule( $increase_by, true );

		// Die, unless there was a deliberate over-ride (for development purposes)
		if ( ! defined( 'BACKUPPLUS_ALLOW_RECENT_ACTIVITY' ) || ! BACKUPPLUS_ALLOW_RECENT_ACTIVITY ) {
			die;
		}
	}

	/**
	 * Increase the resumption interval and reschedule the next resumption
	 *
	 * @uses self::reschedule()
	 *
	 * @param Integer $howmuch        - how much to add to the existing resumption interval
	 * @param Boolean $due_to_overlap - setting this changes the strategy for calculating the next resumption; it indicates that the reason for an increase is because of recent activity detection
	 */
	private static function increase_resume_and_reschedule( $howmuch = 120, $due_to_overlap = false ) {

		global $backupplus;

		$resume_interval = max( (int) $backupplus->jobdata_get( 'resume_interval' ), ( 0 === $howmuch ) ? 120 : 300 );

		if ( empty( $backupplus->newresumption_scheduled ) && $due_to_overlap ) {
			$backupplus->log( 'A new resumption will be scheduled to prevent the job ending' );
		}

		$new_resume = $resume_interval + $howmuch;
		// It may be that we're increasing for the second (or more) time during a run, and that we already know that the new value will be insufficient, and can be increased
		if ( $backupplus->opened_log_time > 100 && microtime( true ) - $backupplus->opened_log_time > $new_resume ) {
			$new_resume = ceil( microtime( true ) - $backupplus->opened_log_time ) + 45;
			$howmuch    = $new_resume - $resume_interval;
		}

		// This used to be always $new_resume, until 14-Aug-2014. However, people who have very long-running processes can end up with very long times between resumptions as a result.
		// Actually, let's not try this yet. I think it is safe, but think there is a more conservative solution available.
		// $how_far_ahead = min($new_resume, 600);
		// Nov 2018 - scheduling the next resumption unnecessarily-far-in-the-future after an overlap is still occurring, so, we're adjusting this to have a maximum value in that particular case
		$how_far_ahead = $due_to_overlap ? min( $new_resume, 900 ) : $new_resume;

		// If it is very long-running, then that would normally be known soon.
		// If the interval is already 12 minutes or more, then try the next resumption 10 minutes from now (i.e. sooner than it would have been). Thus, we are guaranteed to get at least 24 minutes of processing in the first 34.
		if ( $backupplus->current_resumption <= 1 && $new_resume > 720 ) {
			$how_far_ahead = 600;
		}

		if ( ! empty( $backupplus->newresumption_scheduled ) || $due_to_overlap ) {
			self::reschedule( $how_far_ahead );
		}

		$backupplus->jobdata_set( 'resume_interval', $new_resume );

		$backupplus->log( "To decrease the likelihood of overlaps, increasing resumption interval to: $resume_interval + $howmuch = $new_resume" );
	}
}
