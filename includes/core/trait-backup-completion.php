<?php

/** Finishing a backup job: history, last-backup state and error reporting. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Core_Backup_Completion {

	/**
	 * Perform the tasks necessary when a backup has run through all the available steps. N.B. This does not imply that the were all successful or that the backup is finished.
	 *
	 * @param Boolean $do_cleanup  - if (and only if) this is set will resumptions be unscheduled
	 * @param Boolean $allow_email - if this is false, then no email will be sent
	 * @param Boolean $force_abort - set to indicate that the user is manually aborting the backup
	 */
	public function backup_finish( $do_cleanup, $allow_email, $force_abort = false ) {

		if ( ! empty( $this->semaphore ) ) {
			$this->semaphore->unlock();
		}
		if ( ! empty( $this->backup_semaphore ) ) {
			$this->backup_semaphore->delete();
		}

		$this->restore_composer_autoloaders();

		$delete_jobdata = false;

		// The valid use of $do_cleanup is to indicate if in fact anything exists to clean up (if no job really started, then there may be nothing)

		// In fact, leaving the hook to run (if debug is set) is harmless, as the resume job should only do tasks that were left unfinished, which at this stage is none.
		if ( 0 == $this->error_count() || $force_abort ) {
			if ( $do_cleanup ) {
				$cancel_event = $this->current_resumption + 1;
				$this->log( "There were no errors in the uploads, so the 'resume' event ($cancel_event) is being unscheduled" );
				// This apparently-worthless setting of metadata before deleting it is for the benefit of a WP install seen where wp_clear_scheduled_hook() and delete_transient() apparently did nothing (probably a faulty cache)
				$this->jobdata_set( 'jobstatus', 'finished' );
				wp_clear_scheduled_hook( 'backupplus_backup_resume', array( $cancel_event, $this->nonce ) );
				// This should be unnecessary - even if it does resume, all should be detected as finished; but I saw one very strange case where it restarted, and repeated everything; so, this will help
				wp_clear_scheduled_hook( 'backupplus_backup_resume', array( $cancel_event + 1, $this->nonce ) );
				wp_clear_scheduled_hook( 'backupplus_backup_resume', array( $cancel_event + 2, $this->nonce ) );
				wp_clear_scheduled_hook( 'backupplus_backup_resume', array( $cancel_event + 3, $this->nonce ) );
				wp_clear_scheduled_hook( 'backupplus_backup_resume', array( $cancel_event + 4, $this->nonce ) );
				$delete_jobdata = true;
			}
		} else {
			if ( $this->newresumption_scheduled ) {
				if ( $this->current_resumption + 1 != $this->jobdata_get( 'fail_on_resume' ) ) {
					$this->log( "There were errors in the uploads, so the 'resume' event is remaining scheduled" );
					$this->jobdata_set( 'jobstatus', 'resumingforerrors' );
				}
			}
			// If there were no errors before moving to the upload stage, on the first run, then bring the resumption back very close. Since this is only attempted on the first run, it is really only an efficiency thing for a quicker finish if there was an unexpected networking event. We don't want to do it straight away every time, as it may be that the cloud service is down - and might be up in 5 minutes time. This was added after seeing a case where resumption 0 got to run for 10 hours... and the resumption 7 that should have picked up the uploading of 1 archive that failed never occurred.
			if ( isset( $this->error_count_before_cloud_backup ) && 0 === $this->error_count_before_cloud_backup ) {
				if ( 0 == $this->current_resumption ) {
					BackupPlus_Job_Scheduler::reschedule( 60 );
				} else {
					// Added 27/Feb/2016 - though the cloud service seems to be down, we still don't want to wait too long
					$resume_interval = $this->jobdata_get( 'resume_interval' );

					// 15 minutes + 2 for each resumption (a modest back-off)
					$max_interval = 900 + $this->current_resumption * 120;
					if ( $resume_interval > $max_interval ) {
						BackupPlus_Job_Scheduler::reschedule( $max_interval );
					}
				}
			}
		}

		// Send the results email if appropriate, which means:
		// - The caller allowed it (which is not the case in an 'empty' run)
		// - And: An email address was set (which must be so in email mode)
		// And one of:
		// - Debug mode
		// - There were no errors (which means we completed and so this is the final run - time for the final report)
		// - It was the tenth resumption; everything failed

		$send_an_email = false;
		// Save the jobdata's state for the reporting - because it might get changed (e.g. incremental backup is scheduled)
		$jobdata_as_was = $this->jobdata;

		// Make sure that the final status is shown
		if ( $force_abort ) {
			$send_an_email = true;
			$final_message = __( 'The backup was aborted by the user', 'backupplus' );
		} elseif ( 0 == $this->error_count() ) {
			$send_an_email = true;
			if ( 0 == $this->error_count( 'warning' ) ) {
				$final_message = __( 'The backup succeeded and is now complete', 'backupplus' );
				// Ensure it is logged in English. Not hugely important; but helps with a tiny number of really broken setups in which the options cacheing is broken
				if ( 'The backup succeeded and is now complete' != $final_message ) {
					$this->log( 'The backup succeeded and is now complete' );
				}
			} else {
				$final_message = __( 'The backup succeeded (with warnings) and is now complete', 'backupplus' );
				if ( 'The backup succeeded (with warnings) and is now complete' != $final_message ) {
					$this->log( 'The backup succeeded (with warnings) and is now complete' );
				}
			}
			if ( $do_cleanup ) {
				BackupPlus_Incremental_Backups::complete();
				$delete_jobdata = apply_filters( 'backupplus_backup_complete', $delete_jobdata );
			}
		} elseif ( false == $this->newresumption_scheduled || $this->current_resumption + 1 == $this->jobdata_get( 'fail_on_resume' ) ) {

			if ( $this->current_resumption + 1 == $this->jobdata_get( 'fail_on_resume' ) ) {
				$this->log( 'The resumption is being cancelled, as it was only scheduled to enable error reporting, which can be performed now' );
				wp_clear_scheduled_hook( 'backupplus_backup_resume', array( $this->current_resumption + 1, $this->nonce ) );
			}

			$send_an_email = true;
			$final_message = __( 'The backup attempt has finished, apparently unsuccessfully', 'backupplus' );
		} else {
			// There are errors, but a resumption will be attempted
			$final_message = __( 'The backup has not finished; a resumption is scheduled', 'backupplus' );
		}

		if ( 0 == $this->error_count() ) {
			// delete manifest files
			$backupplus_dir     = $this->backups_dir_location();
			$backup_files_array = $this->jobdata_get( 'backup_files_array', array() );

			if ( ! empty( $backup_files_array ) ) {
				foreach ( $backup_files_array as $entity => $files ) {
					if ( '-size' === substr( $entity, -5, 5 ) || ! is_array( $files ) ) {
						continue;
					}

					foreach ( $files as $file ) {
						$fullpath = $backupplus_dir . '/' . $file;
						if ( file_exists( $fullpath . '.list.tmp' ) ) {
							$this->log( "Deleting zip manifest ({$file}.list.tmp)" );
							unlink( $fullpath . '.list.tmp' );
						}
					}
				}
			}
		}

		// Now over-ride the decision to send an email, if needed
		if ( BackupPlus_Options::get_backupplus_option( 'backupplus_debug_mode' ) ) {
			$send_an_email = true;
			$this->log( 'An email has been scheduled for this job, because we are in debug mode' );
		}

		$email = BackupPlus_Options::get_backupplus_option( 'backupplus_email' );

		// If there's no email address, or the set was empty, that is the final over-ride: don't send
		if ( ! $allow_email ) {
			$send_an_email = false;
			$this->log( 'No email will be sent - this backup set was empty.' );
		} elseif ( empty( $email ) ) {
			$send_an_email = false;
			$this->log( 'No email will/can be sent - the user has not configured an email address.' );
		}

		if ( $force_abort ) {
			$jobdata_as_was['aborted'] = true;
		}
		if ( $send_an_email ) {
			$this->send_results_email( $final_message, $jobdata_as_was );
		}

		// Make sure this is the final message logged (so it remains on the dashboard)
		$this->log( $final_message );

		@fclose( $this->logfile_handle );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		$this->logfile_handle = null;

		// Emit a terminal result while archive and upload metadata are still available.
		if ( $do_cleanup && ( $force_abort || 0 === $this->error_count() || ! $this->newresumption_scheduled || $this->current_resumption + 1 == $this->jobdata_get( 'fail_on_resume' ) ) ) {
			$outcome = $force_abort ? 'aborted' : ( 0 === $this->error_count() && 0 === $this->error_count( 'warning' ) ? 'success' : 'failed' );
			$this->jobdata_set( 'terminal_outcome', $outcome );
			$diagnostics = array(
				'errors'   => $this->error_count(),
				'warnings' => $this->error_count( 'warning' ),
				'messages' => array(),
			);
			foreach ( $this->errors as $error ) {
				if ( is_array( $error ) ) {
					$diagnostics['messages'][] = $error;
				} elseif ( is_wp_error( $error ) ) {
					foreach ( $error->get_error_messages() as $message ) {
						$diagnostics['messages'][] = array(
							'level'   => 'error',
							'message' => $message,
						);
					}
				} elseif ( is_string( $error ) ) {
					$diagnostics['messages'][] = array(
						'level'   => 'error',
						'message' => $error,
					);
				}
			}
			do_action( 'backupplus_backup_finished', $this->nonce, $outcome, $this->jobdata, $diagnostics );
		}

		// This is left until last for the benefit of the front-end UI, which then gets maximum chance to display the 'finished' status
		if ( $delete_jobdata ) {
			delete_site_option( 'backupplus_jobdata_' . $this->nonce );
		}
	}

	/**
	 * End a job that creates no backup, such as retention cleanup. Unlike backup_finish(), this saves no backup history, sends no report and does not fire backupplus_backup_finished.
	 */
	public function end_cleanup_job() {
		if ( ! empty( $this->backup_semaphore ) ) {
			$this->backup_semaphore->delete();
			$this->backup_semaphore = null;
		}
		$this->restore_composer_autoloaders();
		$this->jobdata_set( 'jobstatus', 'finished' );
		for ( $resumption = $this->current_resumption + 1; $resumption <= $this->current_resumption + 5; $resumption++ ) {
			wp_clear_scheduled_hook( 'backupplus_backup_resume', array( $resumption, $this->nonce ) );
		}
		$this->newresumption_scheduled = false;
		if ( $this->logfile_handle ) {
			fclose( $this->logfile_handle );
			$this->logfile_handle = null;
		}
		delete_site_option( 'backupplus_jobdata_' . $this->nonce );
		// A later cron event in this request must not inherit this job's data.
		$this->jobdata_reset();
	}

	/**
	 * This function returns 'true' if mod_rewrite could be detected as unavailable; a 'false' result may mean it just couldn't find out the answer
	 *
	 * @param  boolean $check_if_in_use_first
	 * @return boolean
	 */
	public function mod_rewrite_unavailable( $check_if_in_use_first = true ) {
		if ( function_exists( 'apache_get_modules' ) ) {
			global $wp_rewrite;
			$mods = apache_get_modules();
			if ( ( ! $check_if_in_use_first || $wp_rewrite->using_mod_rewrite_permalinks() ) && ( ( in_array( 'core', $mods ) || in_array( 'http_core', $mods ) ) && ! in_array( 'mod_rewrite', $mods ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Count the number of alerts that have occurred at the specified level
	 *
	 * @param String $level - the level to count at
	 *
	 * @return Integer
	 */
	public function error_count( $level = 'error' ) {
		$count = 0;
		foreach ( $this->errors as $err ) {
			if ( ( 'error' == $level && ( is_string( $err ) || is_wp_error( $err ) ) ) || ( is_array( $err ) && $level == $err['level'] ) ) {
				++$count;
			}
		}
		return $count;
	}

	public function list_errors() {
		echo '<ul style="list-style: disc inside;">';
		foreach ( $this->errors as $err ) {
			if ( is_wp_error( $err ) ) {
				foreach ( $err->get_error_messages() as $msg ) {
					echo '<li>' . esc_html( $msg ) . '<li>';
				}
			} elseif ( is_array( $err ) && ( 'error' == $err['level'] || 'warning' == $err['level'] ) ) {
				echo '<li>' . esc_html( $err['message'] ) . '</li>';
			} elseif ( is_string( $err ) ) {
				echo '<li>' . esc_html( $err ) . '</li>';
			} else {
				print '<li>' . esc_html( print_r( $err, true ) ) . '</li>';
			}
		}
		echo '</ul>';
	}

	/**
	 * Save last successful backup information
	 *
	 * @param Array $backup_array An array of backup information
	 */
	private function save_last_backup( $backup_array ) {
		$success     = ( 0 == $this->error_count() ) ? 1 : 0;
		$last_backup = BackupPlus_Options::get_backupplus_option( 'backupplus_last_backup', array() );
		if ( empty( $last_backup ) ) {
			$last_backup = array();
		}
		if ( 'incremental' === $this->jobdata_get( 'job_type' ) ) {
			$last_backup['incremental_backup_time'] = $this->backup_time; // the incremental_backup_time index is used only for storing time of the incremental job type
		} else {
			$last_backup['nonincremental_backup_time'] = $this->backup_time; // otherwise the nonincremental_backup_time index is for the backup job type
		}
		$last_backup = wp_parse_args(
			array(
				'backup_time'  => $this->backup_time, // the backup_time index is used for storing either time of backup or incremental job type
				'backup_array' => $backup_array,
				'success'      => $success,
				'errors'       => $this->errors,
				'backup_nonce' => $this->nonce,
			),
			$last_backup
		);
		if ( 'incremental' === $this->jobdata_get( 'job_type' ) ) {
			$last_backup['backup_time_incremental'] = $this->backup_time;
			$last_backup['backup_time']             = $this->jobdata_get( 'incremental_base_time', $this->backup_time );
		} else {
			unset( $last_backup['backup_time_incremental'] );
		}
		$last_backup = apply_filters( 'backupplus_save_last_backup', $last_backup );
		BackupPlus_Options::update_backupplus_option( 'backupplus_last_backup', $last_backup, false );
	}

	/**
	 * Save the backup information to the backup history during a running backup (adding information to the currently-running job)
	 *
	 * @param Array $backup_array - the backup history
	 */
	private function save_backup_to_history( $backup_array ) {

		if ( ! is_array( $backup_array ) ) {
			$this->log( 'Could not save backup history because we have no backup array. Backup probably failed.' );
			$this->log( __( 'Could not save backup history because we have no backup array.', 'backupplus' ) . ' ' . __( 'Backup probably failed.', 'backupplus' ), 'error' );
			return;
		}

		$job_type = $this->jobdata_get( 'job_type' );

		$backup_array['file_sources']         = $this->jobdata_get( 'file_sources', array() );
		$backup_array['database_coverage']    = $this->jobdata_get( 'database_coverage', array() );
		$backup_array['nonce']                = $this->file_nonce;
		$backup_array['native']               = true;
		$backup_array['service']              = $this->jobdata_get( 'service' );
		$backup_array['service_instance_ids'] = array();
		if ( 'incremental' != $job_type ) {
			$backup_array['always_keep'] = $this->jobdata_get( 'always_keep', false );
		}
		$backup_array['files_enumerated_at'] = $this->jobdata_get( 'files_enumerated_at' );
		$remote_storage_instances            = $this->jobdata_get( 'remote_storage_instances', array() );

		// N.B. Though the saved 'service' option can have various forms (especially if upgrading from (very) old versions), in the jobdata, it is always an array.
		$storage_objects_and_ids = BackupPlus_Storage_Methods_Interface::get_enabled_storage_objects_and_ids( $backup_array['service'], $remote_storage_instances );

		// N.B. On PHP 5.5+, we'd use array_column()
		foreach ( $storage_objects_and_ids as $method => $method_information ) {
			if ( 'none' == $method || ! $method || ! $method_information['object']->supports_feature( 'multi_options' ) ) {
				continue;
			}
			$backup_array['service_instance_ids'][ $method ] = array_keys( $method_information['instance_settings'] );
		}

		if ( 'incremental' != $job_type && '' != ( $label = $this->jobdata_get( 'label', '' ) ) ) {
			$backup_array['label'] = $label;
		}
		if ( ! isset( $backup_array['created_by_version'] ) ) {
			$backup_array['created_by_version'] = $this->version;
		}
		$backup_array['last_saved_by_version'] = $this->version;
		$backup_array['is_multisite']          = is_multisite() ? true : false;
		if ( false != $this->jobdata_get( 'is_autobackup', false ) ) {
			$backup_array['autobackup'] = true;
		}

		if ( false != ( $morefiles_linked_indexes = $this->jobdata_get( 'morefiles_linked_indexes', false ) ) ) {
			$backup_array['morefiles_linked_indexes'] = $morefiles_linked_indexes;
		}
		if ( false != ( $morefiles_more_locations = $this->jobdata_get( 'morefiles_more_locations', false ) ) ) {
			$backup_array['morefiles_more_locations'] = $morefiles_more_locations;
		}

		$history_time = $this->jobdata_get( 'incremental_base_time', $this->backup_time );
		$existing     = BackupPlus_Backup_History::get_history( $history_time );
		if ( 'incremental' === $job_type ) {
			// Array replacement preserves split indices, checksums, database and retention metadata.
			$backup_array = array_replace_recursive( $existing, $backup_array );
		}
		$backup_array['incremental'] = array(
			'configuration' => $this->jobdata_get( 'incremental_configuration' ),
			'complete'      => false,
			'snapshots'     => $this->jobdata_get( 'incremental_snapshots', array() ),
		);
		if ( $this->jobdata_get( 'pre_update_batch' ) ) {
			$backup_array['pre_update'] = array(
				'batch'     => $this->jobdata_get( 'pre_update_batch' ),
				'complete'  => false,
				'snapshots' => $this->jobdata_get( 'incremental_snapshots', array() ),
			);
			unset( $backup_array['incremental'] );
		}
		BackupPlus_Backup_History::save_backup( apply_filters( 'backupplus_save_backup_history_timestamp', $history_time ), $backup_array );
	}
}
