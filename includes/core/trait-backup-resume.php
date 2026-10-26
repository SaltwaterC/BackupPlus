<?php

/** Resuming a backup job until each of its stages is complete. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Core_Backup_Resume {

	/**
	 * Proceed with a backup; before calling this, at least all the initial job data must be set up
	 *
	 * @param Integer $resumption_no - which resumption this is; from 0 upwards
	 * @param String  $bnonce        - the backup job identifier
	 */
	public function backup_resume( $resumption_no, $bnonce ) {

		// Theoretically (N.B. has been seen in the real world), the WP scheduler might call us more than once within the same context (e.g. an incremental run followed by a main backup resumption), leaving us with incorrect internal state if we don't reset.
		static $last_bnonce = null;
		if ( $last_bnonce ) {
			$this->jobdata_reset();
		}
		$last_bnonce = $bnonce;

		$error_levels = version_compare( PHP_VERSION, '8.4.0', '>=' ) ? E_ALL : E_ALL & ~E_STRICT;
		set_error_handler( array( $this, 'php_error' ), $error_levels );

		$this->current_resumption = $resumption_no;

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( BACKUPPLUS_SET_TIME_LIMIT );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}       if ( function_exists( 'ignore_user_abort' ) ) {
			@ignore_user_abort( true );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}
		$runs_started = array();
		$time_now     = microtime( true );

		BackupPlus_Backup_History::always_get_from_db();

		// Restore state
		$resumption_extralog        = '';
		$prev_resumption            = $resumption_no - 1;
		$last_successful_resumption = -1;
		$job_type                   = 'backup';

		if ( 0 == $resumption_no ) {
			$label = $this->jobdata_get( 'label' );
			if ( $label ) {
				$resumption_extralog = apply_filters( 'backupplus_autobackup_extralog', ", label=$label" );
			}
		} else {
			$this->nonce       = $bnonce;
			$file_nonce        = $this->jobdata_get( 'file_nonce' );
			$this->file_nonce  = $file_nonce ? $file_nonce : $bnonce;
			$this->backup_time = $this->jobdata_get( 'backup_time' );
			$this->job_time_ms = $this->jobdata_get( 'job_time_ms' );

			// Get the warnings before opening the log file, as opening the log file may generate new ones (which then leads to $this->errors having duplicate entries when they are copied over below)
			$warnings = $this->jobdata_get( 'warnings' );

			$this->logfile_open( $this->nonce );

			if ( ! $this->get_backup_job_semaphore_lock( $this->nonce, $resumption_no ) ) {
				$this->log( 'Failed to get backup job lock; possible overlapping resumptions - will abort this instance' );
				die;
			}

			// Import existing warnings. The purpose of this is so that when save_backup_to_history() is called, it has a complete set - because job data expires quickly, whilst the warnings of the last backup run need to persist
			if ( is_array( $warnings ) ) {
				foreach ( $warnings as $warning ) {
					$this->errors[] = array(
						'level'   => 'warning',
						'message' => $warning,
					);
				}
			}

			$runs_started = $this->jobdata_get( 'runs_started' );
			if ( ! is_array( $runs_started ) ) {
				$runs_started = array();
			}
			$time_passed = $this->jobdata_get( 'run_times' );
			if ( ! is_array( $time_passed ) ) {
				$time_passed = array();
			}

			foreach ( $time_passed as $run => $passed ) {
				if ( isset( $runs_started[ $run ] ) && $runs_started[ $run ] + $time_passed[ $run ] + 30 > $time_now ) {
					// We don't want to increase the resumption if WP has started two copies of the same resumption off
					if ( $run && $run == $resumption_no ) {
						$increase_resumption = false;
						$this->log( "It looks like WordPress's scheduler has started multiple instances of this resumption" );
					} else {
						$increase_resumption = true;
					}
					BackupPlus_Job_Scheduler::terminate_due_to_activity( 'check-in', round( $time_now, 1 ), round( $runs_started[ $run ] + $time_passed[ $run ], 1 ), $increase_resumption );
				}
			}

			$useful_checkins = $this->jobdata_get( 'useful_checkins', array() );
			if ( ! empty( $useful_checkins ) ) {
				$last_successful_resumption = min( max( $useful_checkins ), $prev_resumption );
			}

			if ( isset( $time_passed[ $prev_resumption ] ) ) {
				// N.B. A check-in occurred; we haven't yet tested if it was useful
				$resumption_extralog = ', previous check-in=' . round( $time_passed[ $prev_resumption ], 2 ) . 's';
			}

			// This is just a simple test to catch restorations of old backup sets where the backup includes a resumption of the backup job
			if ( $time_now - $this->backup_time > 172800 && true == apply_filters( 'backupplus_check_obsolete_backup', true, $time_now, $this ) ) {

				// We have seen cases where the get_site_option() call that self::get_jobdata() relies on returns nothing, even though the data was there in the database. This appears to be sometimes reproducible for the people who get it, but stops being reproducible if they change their backup times - which suggests that they're having failures at times of extreme load. We can attempt to detect this case, and reschedule, instead of aborting.
				if ( empty( $this->backup_time ) && empty( $this->backup_is_already_complete ) && ! empty( $this->logfile_name ) && is_readable( $this->logfile_name ) ) {
					$first_log_bit = file_get_contents( $this->logfile_name, false, null, 0, 250 );
					if ( preg_match( '/\(0\) Opened log file at time: (.*) on /', $first_log_bit, $matches ) ) {
						$first_opened = strtotime( $matches[1] );
						// The value of 1000 seconds here is somewhat arbitrary; but allows for the problem to occur in ~ the first 15 minutes. In practice, the problem is extremely rare; if this does not catch it, we can tweak the algorithm.
						if ( time() - $first_opened < 1000 ) {
							$this->log( 'This backup task (' . $this->nonce . ") failed to load its job data (possible database server malfunction), but has only recently started: scheduling a fresh resumption in order to try again, and then ending this resumption ($time_now, " . $this->backup_time . ') (existing jobdata keys: ' . implode( ', ', array_keys( $this->jobdata ) ) . ')' );
							BackupPlus_Job_Scheduler::reschedule( 120 );
							die;
						}
					}
				}

				// If we are doing a local upload then we do not want to abort the backup as it's possible they are uploading a backup that is older than two days
				if ( empty( $this->jobdata['local_upload'] ) ) {
					$this->log( 'This backup task (' . $this->nonce . ") is either complete or began over 2 days ago: ending ($time_now, " . $this->backup_time . ') (existing jobdata keys: ' . implode( ', ', array_keys( $this->jobdata ) ) . ')' );
					die;
				}
			}
		}

		$this->last_successful_resumption = $last_successful_resumption;

		$runs_started[ $resumption_no ] = $time_now;
		if ( ! empty( $this->backup_time ) ) {
			$this->jobdata_set( 'runs_started', $runs_started );
		}

		// Schedule again, to run in 5 minutes again, in case we again fail
		// The actual interval can be increased (for future resumptions) by other code, if it detects apparent overlapping
		$resume_interval = max( (int) $this->jobdata_get( 'resume_interval' ), 100 );

		$btime = $this->backup_time;

		$job_type = $this->jobdata_get( 'job_type' );

		do_action( 'backupplus_resume_backup_' . $job_type );

		$backupplus_dir = $this->backups_dir_location();

		$time_ago = time() - $btime;

		$this->log( "Backup run: resumption=$resumption_no, nonce=$bnonce, file_nonce=" . $this->file_nonce . " begun at=$btime ({$time_ago}s ago), job type=$job_type" . $resumption_extralog );

		// This works round a bizarre bug seen in one WP install, where delete_transient and wp_clear_scheduled_hook both took no effect, and upon 'resumption' the entire backup would repeat.
		// Argh. In fact, this has limited effect, as apparently (at least on another install seen), the saving of the updated transient via jobdata_set() also took no effect. Still, it does not hurt.
		if ( $resumption_no >= 1 && 'finished' == $this->jobdata_get( 'jobstatus' ) ) {
			$this->log( 'Terminate: This backup job is already finished (1).' );
			die;
		} elseif ( 'clouduploading' != $this->jobdata_get( 'jobstatus' ) && 'backup' == $job_type && ! empty( $this->backup_is_already_complete ) ) {
			$this->jobdata_set( 'jobstatus', 'finished' );
			$this->log( 'Terminate: This backup job is already finished (2).' );
			die;
		}

		if ( $resumption_no > 0 && isset( $runs_started[ $prev_resumption ] ) ) {
			$our_expected_start = $runs_started[ $prev_resumption ] + $resume_interval;
			// If the previous run increased the resumption time, then it is timed from the end of the previous run, not the start
			if ( isset( $time_passed[ $prev_resumption ] ) && $time_passed[ $prev_resumption ] > 0 ) {
				$our_expected_start += $time_passed[ $prev_resumption ];
			}
			$our_expected_start = apply_filters( 'backupplus_expected_start', $our_expected_start, $job_type );
			// More than 12 minutes late?
			if ( $time_now > $our_expected_start + 720 ) {
				$this->log( 'Long time past since expected resumption time: approx expected=' . round( $our_expected_start, 1 ) . ', now=' . round( $time_now, 1 ) . ', diff=' . round( $time_now - $our_expected_start, 1 ) );
				$this->log( __( 'A scheduled backup is running late. Check that WordPress cron or your server scheduler is running reliably.', 'backupplus' ), 'warning', 'infrequentvisits' );
			}
		}

		$this->jobdata_set( 'current_resumption', $resumption_no );

		$first_run = apply_filters( 'backupplus_filerun_firstrun', 0 );

		// April 2022: a similar situation is handled further down, but takes longer to kick in; so extra check has been added (a case where the first runtime under cli was > 4 hours was followed by running under cgi-fci with only 20 minute resumption times; it's better to detect this early)
		if ( $resumption_no == $first_run + 1 && $resume_interval >= 600 && '' != PHP_SAPI ) {

			$last_sapi = $this->jobdata_get( 'last_sapi' );

			if ( '' != $last_sapi && PHP_SAPI != $last_sapi ) {
				$resume_interval = $this->get_initial_resume_interval();
				$this->log( sprintf( 'Run environment has changed (%s -> %s) - resetting resumption interval to %d', $last_sapi, PHP_SAPI, $resume_interval ) );
				$this->jobdata_set( 'last_sapi', PHP_SAPI );
			}

			// We don't want to be in permanent conflict with the overlap detector
		} elseif ( $resumption_no >= $first_run + 8 && $resumption_no < $first_run + 15 && $resume_interval >= 300 ) {

			// $time_passed is set earlier
			list($max_time, $timings_string, $run_times_known) = BackupPlus_Manipulation_Functions::max_time_passed( $time_passed, $resumption_no - 1, $first_run );

			// Do this on resumption 8, or the first time that we have 6 data points. This is only done once to prevent any potential for back-and-forth.
			if ( ( $first_run + 8 == $resumption_no && $run_times_known >= 6 ) || ( 6 == $run_times_known && ! empty( $time_passed[ $prev_resumption ] ) ) ) {
				$this->log( "Time passed on previous resumptions: $timings_string (known: $run_times_known, max: $max_time)" );
				// Remember that 30 seconds is used as the 'perhaps something is still running' detection threshold, and that 45 seconds is used as the 'the next resumption is approaching - reschedule!' interval
				if ( $resume_interval > $max_time + 52 ) {
					$resume_interval = round( $max_time + 52 );
					$this->log( "Based on the available data, we are bringing the resumption interval down to: $resume_interval seconds" );
					$this->jobdata_set( 'resume_interval', $resume_interval );
				}
			} elseif ( isset( $time_passed[ $prev_resumption ] ) && $time_passed[ $prev_resumption ] > 50 && $resume_interval > 300 && $time_passed[ $prev_resumption ] < $resume_interval / 2 ) {
				// This next condition was added in response to HS#9174, a case where on one resumption, PHP was allowed to run for >3000 seconds - but other than that, up to 500 seconds. As a result, the resumption interval got stuck at a large value, whilst resumptions were only being allowed to run for a much smaller amount.
				// This detects whether our last run was less than half the resume interval,  but was non-trivial (at least 50 seconds - so, indicating it didn't just error out straight away), but with a resume interval of over 300 seconds. In this case, it is reduced.
				if ( 'clouduploading' == $this->jobdata_get( 'jobstatus' ) ) {
					$resume_interval = round( $time_passed[ $prev_resumption ] + 52 );
					$this->log( "Time passed on previous resumptions: $timings_string (known: $run_times_known, max: $max_time). Based on the available data, we are bringing the resumption interval down to: $resume_interval seconds" );
					$this->jobdata_set( 'resume_interval', $resume_interval );
				} elseif ( $run_times_known > 4 ) {
					// Added in response to the similar HS#66907 - in that case, resumption 0 ran for over an hour; nothing subsequently for more than ~3 minutes; and it didn't reach the uploading stage until resumption 19, so the previous fragment was not helping. The cause was that the backup initially started under WP-CLI, but then resumed through the web - different conditions led to different permitted run-times. (The user could also mitigate this by running WP-Cron in a CLI environment).
					$examined_values       = 0;
					$matching_values       = 0;
					$looking_at_resumption = $prev_resumption - 1;
					$largest_recent        = false;
					while ( $looking_at_resumption > 0 && $examined_values < 3 ) {
						if ( isset( $time_passed[ $looking_at_resumption ] ) ) {
							++$examined_values;
							if ( $time_passed[ $looking_at_resumption ] > 50 && $time_passed[ $looking_at_resumption ] < $resume_interval / 2 ) {
								++$matching_values;
								$largest_recent = max( $largest_recent, $time_passed[ $looking_at_resumption ] );
							}
						}
						--$looking_at_resumption;
					}
					// If the previous three found values were all less than half the resumption interval....
					if ( 3 == $examined_values && 3 == $matching_values ) {
						$resume_interval = round( $largest_recent + 52 );
						$this->log( "Time passed on previous resumptions: $timings_string (known: $run_times_known, max: $max_time). Based on the available data (most recent 3 resumptions compared to longest), we are bringing the resumption interval down to: $resume_interval seconds" );
						$this->jobdata_set( 'resume_interval', $resume_interval );
					}
				}
			}
		}

		// A different argument than before is needed otherwise the event is ignored
		$next_resumption = $resumption_no + 1;
		if ( $next_resumption < $first_run + 10 ) {
			if ( true === $this->jobdata_get( 'one_shot' ) ) {
				if ( true === $this->jobdata_get( 'reschedule_before_upload' ) && 1 == $next_resumption ) {
					$this->log( 'A resumption will be scheduled for the cloud backup stage' );
					$schedule_resumption = true;
				} else {
					$this->log( 'We are in "one shot" mode - no resumptions will be scheduled' );
				}
			} else {
				$schedule_resumption = true;
			}
		} else {

			// We're in over-time - we only reschedule if something useful happened last time (used to be that we waited for it to happen this time - but that meant that temporary errors, e.g. Google 400s on uploads, scuppered it all - we'd do better to have another chance
			// 'useful_checkin' is < 1.16.35 (Nov 2020). It is only supported here for resumptions that span upgrades. Later it can be removed.
			$useful_checkin = max( $this->jobdata_get( 'useful_checkin', 0 ), max( (array) $this->jobdata_get( 'useful_checkins', 0 ) ) );

			$last_resumption = $resumption_no - 1;
			$fail_on_resume  = $this->jobdata_get( 'fail_on_resume' );

			if ( empty( $useful_checkin ) || $useful_checkin < $last_resumption ) {
				if ( empty( $fail_on_resume ) ) {
					$this->log( sprintf( 'The current run is resumption number %d, and there was nothing useful done on the last run (last useful run: %s) - will not schedule a further attempt until we see something useful happening this time', $resumption_no, $useful_checkin ) );
					// Internally, we do actually schedule a resumption; but only in order to be able to nicely handle and log the failure, which otherwise may not be logged
					$this->jobdata_set( 'fail_on_resume', $next_resumption );
					$schedule_resumption = 1;
				}
			} else {
				// Something useful happened last time
				if ( ! empty( $fail_on_resume ) ) {
					$this->jobdata_delete( 'fail_on_resume' );
					$fail_on_resume = false;
				}
				$schedule_resumption = true;
			}

			if ( ! isset( $time_passed[ $prev_resumption ] ) ) {
				$this->no_checkin_last_time = true;
			}

			if ( ! empty( $fail_on_resume ) && $fail_on_resume == $this->current_resumption ) {
				if ( 'cleanup' === $job_type ) {
					BackupPlus_Retention_Cleanup::instance()->abandon();
					die;
				}
				$this->log( 'The backup is being aborted for a repeated failure to progress.', 'backupplus' );
				$this->log( __( 'The backup is being aborted for a repeated failure to progress.', 'backupplus' ), 'error' );
				$this->backup_finish( true, true );
				die;
			}
		}

		// Sanity check
		if ( empty( $this->backup_time ) ) {
			$this->log( 'The backup_time parameter is empty (usually caused by resuming an already-complete backup).' );
			return false;
		}

		if ( ! empty( $schedule_resumption ) ) {
			$schedule_for = time() + $resume_interval;
			if ( 1 === $schedule_resumption ) {
				$this->log( "Scheduling a resumption ($next_resumption) after $resume_interval seconds ($schedule_for); but the job will then be aborted unless something happens this time" );
				$this->resumption_scheduled_for_cleanup = true;
			} else {
				$this->log( "Scheduling a resumption ($next_resumption) after $resume_interval seconds ($schedule_for) in case this run gets aborted" );
			}
			wp_schedule_single_event( $schedule_for, 'backupplus_backup_resume', array( $next_resumption, $bnonce ) );
			$this->newresumption_scheduled = $schedule_for;
		}

		$backup_files = $this->jobdata_get( 'backup_files' );

		global $backupplus_backup;
		// Bring in all the backup routines
		backupplus_try_include_file( 'backup.php', 'include_once' );
		$backupplus_backup = new BackupPlus_Backup( $backup_files, apply_filters( 'backupplus_files_altered_since', -1, $job_type ) );

		// Retention cleanup reuses the resumption machinery above, but creates no archives, history or reports.
		if ( 'cleanup' === $job_type ) {
			BackupPlus_Retention_Cleanup::instance()->run_job( $backupplus_backup );
			restore_error_handler();
			return;
		}

		$undone_files = array();

		if ( 'no' == $backup_files ) {
			$this->log( 'This backup run is not intended for files - skipping' );
			$our_files = array();
		} else {
			try {
				// This should be always called; if there were no files in this run, it returns us an empty array
				$backup_array = $backupplus_backup->resumable_backup_of_files( $resumption_no );
				if ( false === $backup_array ) {
					$this->log( 'File archive encryption failed; backup dispatch has been stopped.', 'error' );
					return false;
				}
				// This save, if there was something, is then immediately picked up again
				if ( is_array( $backup_array ) ) {
					$this->log( 'Saving backup status to database (elements: ' . count( $backup_array ) . ')' );
					$this->save_backup_to_history( $backup_array );
				}

				// Switch of variable name is purely vestigial
				$our_files = $backup_array;
				if ( ! is_array( $our_files ) ) {
					$our_files = array();
				}
			} catch ( Exception $e ) {
				$log_message = 'Exception (' . get_class( $e ) . ') occurred during files backup: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
				BackupPlus_Manipulation_Functions::error_log( $log_message );
				// @codingStandardsIgnoreLine
				$log_message .= ' Backtrace: ' . str_replace( array( ABSPATH, "\n" ), array( '', ', ' ), $e->getTraceAsString() );
				$this->log( $log_message );
				/* translators: 1: Exception class, 2: Error message */
				$this->log( sprintf( __( 'A PHP exception (%1$s) has occurred: %2$s', 'backupplus' ), get_class( $e ), $e->getMessage() ), 'error' );
				die();
			} catch ( Error $e ) { // phpcs:ignore PHPCompatibility.Classes.NewClasses.errorFound -- The Error class does not exist in PHP below 5.6.
				$log_message = 'PHP Fatal error (' . get_class( $e ) . ') has occurred. Error Message: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
				BackupPlus_Manipulation_Functions::error_log( $log_message );
				// @codingStandardsIgnoreLine
				$log_message .= ' Backtrace: ' . str_replace( array( ABSPATH, "\n" ), array( '', ', ' ), $e->getTraceAsString() );
				$this->log( $log_message );
				/* translators: 1: Exception class, 2: Error message */
				$this->log( sprintf( __( 'A PHP fatal error (%1$s) has occurred: %2$s', 'backupplus' ), get_class( $e ), $e->getMessage() ), 'error' );
				die();
			}
		}

		do_action( 'pre_database_backup_setup' );

		$backup_databases = $this->jobdata_get( 'backup_database' );

		if ( ! is_array( $backup_databases ) ) {
			$backup_databases = array( 'wp' => $backup_databases );
		}

		foreach ( $backup_databases as $whichdb => $backup_database ) {

			if ( is_array( $backup_database ) ) {
				$dbinfo          = $backup_database['dbinfo'];
				$backup_database = $backup_database['status'];
			} else {
				$dbinfo = array();
			}

			$tindex = ( 'wp' == $whichdb ) ? 'db' : 'db' . $whichdb;

			if ( 'begun' == $backup_database || 'finished' == $backup_database || 'encrypted' == $backup_database ) {

				if ( 'wp' == $whichdb ) {
					$db_descrip = 'WordPress DB';
				} else {
					if ( ! empty( $dbinfo ) && is_array( $dbinfo ) && ! empty( $dbinfo['host'] ) ) {
						$db_descrip = "External DB $whichdb - " . $dbinfo['user'] . '@' . $dbinfo['host'] . '/' . $dbinfo['name'];
					} else {
						$db_descrip = "External DB $whichdb - details are missing";
					}
				}

				if ( 'begun' == $backup_database ) {
					if ( $resumption_no > 0 ) {
						$this->log( "Resuming creation of database dump ($db_descrip)" );
					} else {
						$this->log( "Beginning creation of database dump ($db_descrip)" );
					}
				} elseif ( 'encrypted' == $backup_database ) {
					$this->log( "Database dump ($db_descrip): Creation and encryption were completed already" );
				} else {
					$this->log( "Database dump ($db_descrip): Creation was completed already" );
				}

				if ( 'wp' != $whichdb && ( empty( $dbinfo ) || ! is_array( $dbinfo ) || empty( $dbinfo['host'] ) ) ) {
					unset( $backup_databases[ $whichdb ] );
					$this->jobdata_set( 'backup_database', $backup_databases );
					continue;
				}

				// Catch fatal errors through try/catch blocks around the database backup
				try {
					$db_backup = $backupplus_backup->backup_db( $backup_database, $whichdb, $dbinfo );
				} catch ( Exception $e ) {
					$log_message = 'Exception (' . get_class( $e ) . ') occurred during files backup: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
					$this->log( $log_message );
					BackupPlus_Manipulation_Functions::error_log( $log_message );
					/* translators: 1: Exception class, 2: Error message */
					$this->log( sprintf( __( 'A PHP exception (%1$s) has occurred: %2$s', 'backupplus' ), get_class( $e ), $e->getMessage() ), 'error' );
					die();
				} catch ( Error $e ) { // phpcs:ignore PHPCompatibility.Classes.NewClasses.errorFound -- The Error class does not exist in PHP below 5.6.
					$log_message = 'PHP Fatal error (' . get_class( $e ) . ') has occurred. Error Message: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
					$this->log( $log_message );
					BackupPlus_Manipulation_Functions::error_log( $log_message );
					/* translators: 1: Exception class, 2: Error message */
					$this->log( sprintf( __( 'A PHP fatal error (%1$s) has occurred: %2$s', 'backupplus' ), get_class( $e ), $e->getMessage() ), 'error' );
					die();
				}

				if ( false === $db_backup || is_wp_error( $db_backup ) ) {
					$this->log( 'Database export failed; backup dispatch has been stopped.', 'error' );
					return false;
				}
				if ( is_array( $our_files ) && is_string( $db_backup ) ) {
					$our_files[ $tindex ] = $db_backup;
				}

				if ( 'encrypted' != $backup_database ) {
					$backup_databases[ $whichdb ] = array(
						'status' => 'finished',
						'dbinfo' => $dbinfo,
					);
					$this->jobdata_set( 'backup_database', $backup_databases );
				}
			} elseif ( 'no' == $backup_database ) {
				$this->log( "No database backup ($whichdb) - not part of this run" );
			} else {
				$this->log( "Unrecognised data when trying to ascertain if the database ($whichdb) was backed up (" . serialize( $backup_database ) . ')' );
			}

			// This is done before cloud despatch, because we want a record of what *should* be in the backup. Whether it actually makes it there or not is not yet known.
			$this->save_backup_to_history( $our_files );

			// Potentially encrypt the database if it is not already
			if ( 'no' != $backup_database && isset( $our_files[ $tindex ] ) && ! preg_match( '/\.crypt$/', $our_files[ $tindex ] ) ) {
				$encrypted_file = $backupplus_backup->encrypt_file( $our_files[ $tindex ] );
				if ( false === $encrypted_file || is_wp_error( $encrypted_file ) ) {
					$this->log( 'Database encryption failed; backup dispatch has been stopped.', 'error' );
					return false;
				}
				$our_files[ $tindex ] = $encrypted_file;
				// No need to save backup history now, as it will happen in a few lines time
				if ( preg_match( '/\.crypt$/', $our_files[ $tindex ] ) ) {
					$backup_databases[ $whichdb ] = array(
						'status' => 'encrypted',
						'dbinfo' => $dbinfo,
					);
					$this->jobdata_set( 'backup_database', $backup_databases );
				}
			}

			if ( 'no' != $backup_database && isset( $our_files[ $tindex ] ) && file_exists( $backupplus_dir . '/' . $our_files[ $tindex ] ) ) {
				$our_files[ $tindex . '-size' ] = filesize( $backupplus_dir . '/' . $our_files[ $tindex ] );
				if ( BackupPlus_Encryption::is_file_encrypted( $our_files[ $tindex ] ) ) {
					foreach ( $this->which_checksums() as $checksum ) {
						$hash = hash_file( $checksum, $backupplus_dir . '/' . $our_files[ $tindex ] );
						if ( false === $hash ) {
							return false;
						}
						$this->jobdata_set( $checksum . '-' . $tindex . '0.crypt', $hash );
					}
				}
				$this->save_backup_to_history( $our_files );
			}
			if ( isset( $our_files[ $tindex ] ) && BackupPlus_Encryption::is_file_encrypted( $our_files[ $tindex ] ) ) {
				if ( ! is_file( $backupplus_dir . '/' . $our_files[ $tindex ] ) && ! $this->is_uploaded( $our_files[ $tindex ] ) ) {
					$this->log( 'The encrypted database archive is unavailable; backup dispatch has been stopped.', 'error' );
					return false;
				}
				$plaintext = $backupplus_dir . '/' . substr( $our_files[ $tindex ], 0, -6 );
				if ( ( is_file( $plaintext ) || is_link( $plaintext ) ) && ! unlink( $plaintext ) ) {
					$this->log( 'Could not remove the plaintext database archive; backup dispatch has been stopped.', 'error' );
					return false;
				}
			}
		}

		$backupable_entities = $this->get_backupable_file_entities( true );

		$checksum_list = $this->which_checksums();

		$checksums = array();

		foreach ( $checksum_list as $checksum ) {
			$checksums[ $checksum ] = array();
		}

		$total_size = 0;

		$service = $this->jobdata_get( 'pre_update_batch' ) ? $this->jobdata_get( 'service' ) : $this->just_one( $this->jobdata_get( 'service' ) );
		if ( empty( $service ) ) {
			$message = 'This file has already been successfully processed';
		} else {
			$message = 'This file has already been successfully uploaded';
		}

		// Queue files for upload
		foreach ( $our_files as $key => $files ) {
			// Only continue if the stored info was about a dump
			if ( ! isset( $backupable_entities[ $key ] ) && ( 'db' != substr( $key, 0, 2 ) || '-size' == substr( $key, -5, 5 ) ) ) {
				continue;
			}
			if ( is_string( $files ) ) {
				$files = array( $files );
			}
			foreach ( $files as $findex => $file ) {

				$size_key   = ( 0 == $findex ) ? $key . '-size' : $key . $findex . '-size';
				$total_size = ( false === $total_size || ! isset( $our_files[ $size_key ] ) || ! is_numeric( $our_files[ $size_key ] ) ) ? false : $total_size + $our_files[ $size_key ];

				foreach ( $checksum_list as $checksum ) {

					$cksum = $this->jobdata_get( $checksum . '-' . $key . $findex );
					if ( $cksum ) {
						$checksums[ $checksum ][ $key . $findex ] = $cksum;
					}
					$cksum = $this->jobdata_get( $checksum . '-' . $key . $findex . '.crypt' );
					if ( $cksum ) {
						$checksums[ $checksum ][ $key . $findex . '.crypt' ] = $cksum;
					}
				}

				if ( ! $this->is_ours_to_upload( $file, $key ) && 'db' !== $key ) {
					continue;
				}
				if ( $this->is_uploaded( $file ) ) {
					$this->log( "$file: $key: $message" );
				} elseif ( is_file( $backupplus_dir . '/' . $file ) ) {
					if ( ! in_array( $file, $undone_files ) ) {
						$this->log( "$file: $key: This file has not yet been successfully uploaded: will queue" );
						$undone_files[ $key . $findex ] = $file;
					} else {
						$this->log( "$file: $key: This file was already queued for upload (this condition should never be seen)" );
					}
				} elseif ( ! $this->is_ours_to_upload( $file, $key ) ) {
					$this->log( "$file: $key: This file is not ours to upload and has been/will be handled by another job." );
				} else {
					$this->log( "$file: $key: Note: This file was not marked as successfully uploaded, but does not exist on the local filesystem; now marking as uploaded ($backupplus_dir/$file)" );
					$this->uploaded_file( $file, true );
				}
			}
		}
		$our_files['checksums'] = $checksums;

		// Save again (now that we have checksums)
		$size_description = ( false === $total_size ) ? 'Unknown' : BackupPlus_Manipulation_Functions::convert_numeric_size_to_text( $total_size );
		$this->log( "Saving backup history. Total backup size: $size_description" );
		$this->save_backup_to_history( $our_files );
		do_action( 'backupplus_final_backup_history', $our_files );

		// We finished; so, low memory was not a problem
		$this->log_remove_warning( 'lowram' );

		if ( 0 == count( $undone_files ) ) {
			$this->log( "Resume backup ($bnonce, $resumption_no): finish run" );
			if ( is_array( $our_files ) ) {
				$this->save_last_backup( $our_files );
			}
			$this->log( 'There were no more files that needed uploading' );
			// No email, as the user probably already got one if something else completed the run
			$allow_email = false;
			if ( 'begun' == $this->jobdata_get( 'prune' ) ) {
				// Begun, but not finished
				$this->log( 'Restarting backup prune operation' );
				$backupplus_backup->do_prune_standalone();
				$allow_email = true;
			} elseif ( 'finished' != $this->jobdata_get( 'prune' ) ) {
				// If prune has not begun or finished but we have undone files then start it
				$backupplus_backup->do_prune_standalone();
				$allow_email = true;
			}

			$this->check_upload_completed();

			$this->backup_finish( true, $allow_email );
			restore_error_handler();
			return;
		}

		$this->error_count_before_cloud_backup = $this->error_count();

		// This is intended for one-shot backups, where we do want a resumption if it's only for uploading
		if ( empty( $this->newresumption_scheduled ) && 0 == $resumption_no && 0 == $this->error_count_before_cloud_backup && true === $this->jobdata_get( 'reschedule_before_upload' ) ) {
			$this->log( 'Cloud backup stage reached on one-shot backup: scheduling resumption for the cloud upload' );
			BackupPlus_Job_Scheduler::reschedule( 60 );
			BackupPlus_Job_Scheduler::record_still_alive();
		}

		$this->log( 'Requesting upload of the files that have not yet been successfully uploaded (' . count( $undone_files ) . ')' );
		// Catch fatal errors through try/catch blocks around the  upload to remote storage
		$backupplus_backup->cloud_backup( $undone_files );

		$this->log( "Resume backup ($bnonce, $resumption_no): finish run" );
		if ( is_array( $our_files ) ) {
			$this->save_last_backup( $our_files );
		}
		$this->backup_finish( true, true );

		restore_error_handler();
	}
}
