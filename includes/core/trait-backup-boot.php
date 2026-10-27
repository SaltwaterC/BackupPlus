<?php

/** Booting a backup job: creating its state, checking prerequisites and scheduling the first resumption. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Core_Backup_Boot {

	/**
	 * This procedure initiates a backup run
	 * $backup_files/$backup_database: true/false = yes/no (over-write allowed); 1/0 = yes/no (force)
	 *
	 * @param  Boolean|Integer      $backup_files
	 * @param  Boolean|Integer      $backup_database
	 * @param  Boolean|Array        $restrict_files_to_override
	 * @param  Boolean              $one_shot
	 * @param  Boolean|Array|String $service
	 * @param  Array                $options
	 *
	 * @return Boolean|Void - false indicates definite failure; true indicates a job was started and ran through as far as possible on this resumption. Note that you should not expect this method to return at all, depending on how long the backup takes, and available PHP run time, etc. In case of failure, currently there may or may not be information logged, and it may or may not be logged at the 'error' level. If more precise feedback is needed, then this can be improved. Void is currently used if no backup was started because none was needed.
	 */
	public function boot_backup( $backup_files, $backup_database, $restrict_files_to_override = false, $one_shot = false, $service = false, $options = array() ) {

		if ( function_exists( 'ignore_user_abort' ) ) {
			@ignore_user_abort( true );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}       if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( BACKUPPLUS_SET_TIME_LIMIT );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}
		$is_scheduled_backup = is_bool( $backup_files ) || is_bool( $backup_database ) || ! empty( $options['scheduled_incremental'] );

		$hosting_company = $this->get_hosting_info();
		if ( ! empty( $options['incremental'] ) && in_array( 'only_one_incremental_per_day', $this->is_hosting_backup_limit_reached() ) ) {
			$this->log(
				__( 'You have reached the daily limit for the number of incremental backups you can create at this time.', 'backupplus' ) . ' ' .
				__( ' Your hosting provider only allows you to take one incremental backup per day.', 'backupplus' ) . ' ' .
				sprintf(
					/* translators: %s: Hosting provider name */
					__( 'Please contact your hosting company (%s) if you require further support.', 'backupplus' ),
					$hosting_company['name']
				)
			);
			return false;
		} elseif ( empty( $options['incremental'] ) && in_array( 'only_one_backup_per_month', $this->is_hosting_backup_limit_reached() ) ) {
			$this->log(
				__( 'You have reached the monthly limit for the number of backups you can create at this time.', 'backupplus' ) . ' ' .
				__( 'Your hosting provider only allows you to take one backup per month.', 'backupplus' ) . ' ' .
				sprintf(
					/* translators: %s: Hosting provider name */
					__( 'Please contact your hosting company (%s) if you require further support.', 'backupplus' ),
					$hosting_company['name']
				)
			);
			return false;
		}

		if ( false === $restrict_files_to_override && isset( $options['restrict_files_to_override'] ) ) {
			$restrict_files_to_override = $options['restrict_files_to_override'];
		}
		BackupPlus_Backup_Sources::set_run_sources( isset( $options['file_sources'] ) ? $options['file_sources'] : null, isset( $options['file_entity_paths'] ) ? $options['file_entity_paths'] : null );
		// Generate backup information
		$use_nonce     = empty( $options['use_nonce'] ) ? false : $options['use_nonce'];
		$use_timestamp = empty( $options['use_timestamp'] ) ? false : $options['use_timestamp'];
		$this->backup_time_nonce( $use_nonce, $use_timestamp );
		$this->jobdata_reset();
		// Errors left by whatever ran earlier in this request, such as a database capture, belong to that work and must not count against this job.
		$this->errors = array();
		// The current_resumption is consulted within logfile_open()
		$this->current_resumption = 0;
		$this->logfile_open( $this->nonce );

		if ( ! is_file( $this->logfile_name ) ) {
			$this->log( 'Failed to open log file (' . $this->logfile_name . ') - you need to check your BackupPlus settings (your chosen directory for creating files in is not writable, or you ran out of disk space). Backup aborted.' );
			$this->log( __( 'Could not create files in the backup directory.', 'backupplus' ) . ' ' . __( 'Backup aborted - check your BackupPlus settings.', 'backupplus' ), 'error' );

			if ( ! defined( 'BACKUPPLUS_SEND_UNWRITABLE_BACKUP_DIRECTORY_EMAIL' ) || BACKUPPLUS_SEND_UNWRITABLE_BACKUP_DIRECTORY_EMAIL ) {
				$final_message = __( 'BackupPlus is unable to perform backups as your backup directory is not writable or the disk space is full.', 'backupplus' ) . ' ' . __( 'Please check the backup directory and ensure it is writable so that backups may continue.', 'backupplus' );
				$this->send_results_email( $final_message, $this->jobdata );
			}

			return false;
		}

		// Some house-cleaning
		BackupPlus_Filesystem_Functions::clean_temporary_files();

		// Log some information that may be helpful
		$this->log( "Tasks: Backup files: $backup_files (schedule: " . BackupPlus_Options::get_backupplus_option( 'backupplus_interval', 'unset' ) . ") Backup DB: $backup_database (schedule: " . BackupPlus_Options::get_backupplus_option( 'backupplus_interval_database', 'unset' ) . ')' );

		// The is_bool() check here is confirming that we're allowed to adjust the parameters
		if ( false === $one_shot && is_bool( $backup_database ) ) {
			// If the files and database schedules are the same, and if this the file one, then we rope in database too.
			// On the other hand, if the schedules were the same and this was the database run, then there is nothing to do.

			$files_schedule = BackupPlus_Options::get_backupplus_option( 'backupplus_interval' );
			$db_schedule    = BackupPlus_Options::get_backupplus_option( 'backupplus_interval_database' );

			$sched_log_extra = '';

			if ( 'manual' != $files_schedule && false !== $files_schedule ) {
				if ( BackupPlus_Backup_Schedule::instance()->same_schedule() ) {
					$sched_log_extra = 'Combining jobs from identical schedules. ';
					$backup_database = ( true == $backup_files ) ? true : false;
				} elseif ( $files_schedule && $db_schedule && ! BackupPlus_Backup_Schedule::instance()->any_exact() ) {

					// This stored value is the earliest of the two apparently-close jobs
					$combine_around = empty( $this->combine_jobs_around ) ? false : $this->combine_jobs_around;

					if ( preg_match( '/^(cancel:)?(\d+)$/', $combine_around, $matches ) ) {

						$combine_around = $matches[2];

						// Re-save the option, since otherwise it will have been reset and not be accessible to the 'other' run
						BackupPlus_Options::update_backupplus_option( 'backupplus_combine_jobs_around', 'cancel:' . $this->combine_jobs_around );

						$margin = ( defined( 'BACKUPPLUS_COMBINE_MARGIN' ) && is_numeric( BACKUPPLUS_COMBINE_MARGIN ) ) ? BACKUPPLUS_COMBINE_MARGIN : 600;

						$time_now = time();

						// The margin is doubled, to cope with the lack of predictability in WP's cron system
						if ( $time_now >= $combine_around && $time_now <= $combine_around + 2 * $margin ) {

							$sched_log_extra = 'Combining jobs from co-inciding events. ';

							if ( 'cancel:' == $matches[1] ) {
								$backup_database = false;
								$backup_files    = false;
							} else {
								// We want them both to happen on whichever run is first (since, afterwards, the backupplus_combine_jobs_around option will have been removed when the event is rescheduled).
								$backup_database = true;
								$backup_files    = true;
							}
						}
					}
				}
			}
			$this->log( "Processed schedules. {$sched_log_extra}Tasks now: Backup files: $backup_files Backup DB: $backup_database" );
		}

		if ( false == apply_filters( 'backupplus_boot_backup', true, $backup_files, $backup_database, $one_shot ) ) {
			$this->log( 'Backup aborted (via filter)' );
			return false;
		}

		$enabled_storage_objects_and_ids = array();

		// All scheduled backups will go through this condition (and some others may too)
		// This section sets up default options, filters services/instances, and populates $options['remote_storage_instances']
		if ( ! is_string( $service ) && ! is_array( $service ) ) {
			$all_services = ! empty( $options['remote_storage_instances'] ) ? array_keys( $options['remote_storage_instances'] ) : BackupPlus_Options::get_backupplus_option( 'backupplus_service' );
			if ( is_string( $all_services ) ) {
				$all_services = (array) $all_services;
			}

			$enabled_storage_objects_and_ids = BackupPlus_Storage_Methods_Interface::get_enabled_storage_objects_and_ids( $all_services );
			$legacy_storage_instances        = array();

			if ( ! isset( $options['remote_storage_instances'] ) ) {

				$remote_storage_instances = array();

				foreach ( $enabled_storage_objects_and_ids as $method_id => $method_info ) {

					if ( $method_info['object']->supports_feature( 'multi_options' ) ) {
						foreach ( $method_info['instance_settings'] as $instance_id => $instance_settings ) {
							// We already know the instance is enabled, as we only selected those. We just want to give add-ons an opportunity to filter it.

							if ( ! apply_filters( 'backupplus_boot_backup_remote_storage_instance_include', true, $instance_settings, $method_id, $instance_id, $is_scheduled_backup ) ) {
								continue;
							}

							if ( ! isset( $remote_storage_instances[ $method_id ] ) ) {
								$remote_storage_instances[ $method_id ] = array();
							}

							$remote_storage_instances[ $method_id ][] = $instance_id;
						}
					} else {
						$legacy_storage_instances[] = $method_id;
					}
				}

				$options['remote_storage_instances'] = $remote_storage_instances;
			}

			$service = array_merge( array_keys( $options['remote_storage_instances'] ), $legacy_storage_instances );
		}

		$service = $this->get_canonical_service_list( $service );

		$canonised_storage_objects_and_ids = array_merge( array_flip( $service ), $enabled_storage_objects_and_ids );
		$option_cache                      = array();

		foreach ( $canonised_storage_objects_and_ids as $method_id => $method_info ) {
			// Explained at backupplus/-/merge_requests/1302#note_206636
			if ( ! is_array( $method_info ) ) {
				$canonised_storage_objects_and_ids[ $method_id ] = $method_info = array();
			}
			if ( ! isset( $method_info['object'] ) || ! is_object( $method_info['object'] ) ) {
				$object = BackupPlus_Storage_Provider_Factory::create( $method_id, false );
				if ( ! is_wp_error( $object ) ) {
					$method_info['object'] = $canonised_storage_objects_and_ids[ $method_id ]['object'] = $object;
				} else {
					BackupPlus_Manipulation_Functions::error_log( "BackupPlus: storage provider unavailable: $method_id" );
				}
			}
			if ( isset( $method_info['object'] ) && is_object( $method_info['object'] ) && is_callable( array( $method_info['object'], 'get_credentials' ) ) ) {
				$opts = $method_info['object']->get_credentials();
				if ( is_array( $opts ) ) {
					foreach ( $opts as $opt ) {
						$option_cache[ $opt ] = BackupPlus_Options::get_backupplus_option( $opt );
					}
				}
			}
		}
		$option_cache = apply_filters( 'backupplus_job_option_cache', $option_cache );

		// If nothing to be done, then just finish
		if ( ! $backup_files && ! $backup_database ) {
			$ret = $this->backup_finish( false, false );
			// Don't keep useless log files
			if ( ! BackupPlus_Options::get_backupplus_option( 'backupplus_debug_mode' ) && ! empty( $this->logfile_name ) && file_exists( $this->logfile_name ) ) {
				unlink( $this->logfile_name );
			}
			// Currently backup_finish() appears to have a void return. We don't want to return false, as that indicates failure. But neither was it really a success. Void seems fine for now, given that nothing is currently using it.
			return $ret;
		}

		if ( ! $this->get_semaphore_lock( $backup_files, $backup_database ) ) {
			// get_semaphore_lock() already does some of its own logging (though not currently (Nov 2019) at 'error' level)
			return false;
		}

		$resume_interval = $this->get_initial_resume_interval();

		$job_file_entities = array();
		if ( $backup_files ) {
			$possible_backups = $this->get_backupable_file_entities( true );
			foreach ( $possible_backups as $youwhat => $whichdir ) {
				if ( ( false === $restrict_files_to_override && BackupPlus_Backup_Sources::included( $youwhat ) ) || ( is_array( $restrict_files_to_override ) && in_array( $youwhat, $restrict_files_to_override ) ) ) {
					// The 0 indicates the zip file index
					$job_file_entities[ $youwhat ] = array(
						'index' => 0,
					);
				}
			}
		}

		$file_sources = BackupPlus_Backup_Sources::sources();
		try {
			BackupPlus_Backup_Sources::validate_sources( $job_file_entities, $file_sources );
		} catch ( Exception $e ) {
			$this->log( $e->getMessage(), 'error' );
			if ( $this->semaphore ) { $this->semaphore->unlock(); }
			return false;
		}

		$incremental_configuration = BackupPlus_Incremental_Backups::configuration( array_keys( $job_file_entities ), $service, isset( $options['remote_storage_instances'] ) ? $options['remote_storage_instances'] : array(), $option_cache );
		$incremental_base          = ! empty( $options['incremental'] ) && ! empty( $job_file_entities ) ? BackupPlus_Incremental_Backups::find_base( array_keys( $job_file_entities ), $incremental_configuration ) : false;
		if ( $incremental_base ) {
			$this->file_nonce = $incremental_base['nonce'];
			foreach ( $job_file_entities as $entity => &$entity_info ) {
				$entity_info['index'] = max( array_keys( (array) $incremental_base[ $entity ] ) ) + 1;
			}
			unset( $entity_info );
			$this->log( 'Adding changed files to backup chain ' . $this->file_nonce );
		} elseif ( ! empty( $options['incremental'] ) ) {
			$this->log( 'Taking a full backup: no completed compatible base less than seven days old is available.' );
		}

		$followups_allowed = ( ( ( ! $one_shot && defined( 'DOING_CRON' ) && DOING_CRON ) ) || ( defined( 'BACKUPPLUS_FOLLOWUPS_ALLOWED' ) && BACKUPPLUS_FOLLOWUPS_ALLOWED ) );

		$split_every = max( (int) BackupPlus_Options::get_backupplus_option( 'backupplus_split_every', 400 ), BACKUPPLUS_SPLIT_MIN );

		$initial_jobdata = array(
			'resume_interval',
			$resume_interval,
			'job_type',
			'backup',
			'jobstatus',
			'begun',
			'backup_time',
			$this->backup_time,
			'job_time_ms',
			$this->job_time_ms,
			'service',
			$service,
			'split_every',
			$split_every,
			'maxzipbatch',
			26214400, // 25MB
			'job_file_entities',
			$job_file_entities,
			'option_cache',
			$option_cache,
			'uploaded_lastreset',
			9,
			'one_shot',
			$one_shot,
			'followsups_allowed',
			$followups_allowed,
			'last_sapi',
			PHP_SAPI,
		);

		array_push( $initial_jobdata, 'incremental_configuration', $incremental_configuration );
		if ( ! empty( $options['pre_update_batch'] ) ) {
			array_push( $initial_jobdata, 'pre_update_batch', $options['pre_update_batch'] );
		}
		if ( $incremental_base ) {
			$previous_files = array();
			foreach ( $job_file_entities as $entity => $unused ) {
				$previous_files[ $entity ] = (array) $incremental_base[ $entity ];
				foreach ( $previous_files[ $entity ] as $index => $file ) {
					$key = $entity . ( $index ? $index : '' ) . '-size';
					if ( isset( $incremental_base[ $key ] ) ) {
						$previous_files[ $key ] = $incremental_base[ $key ];
					}
				}
			}
			$initial_jobdata[ array_search( 'job_type', $initial_jobdata, true ) + 1 ] = 'incremental';
			array_push( $initial_jobdata, 'incremental_base_time', $incremental_base['timestamp'], 'incremental_previous_snapshots', $incremental_base['incremental']['snapshots'], 'previous_backup_files_array', $previous_files, 'backup_files_array', $previous_files );
		}

		if ( $one_shot ) {
			update_site_option( 'backupplus_oneshotnonce', $this->nonce );
		}

		if ( $this->file_nonce && $this->file_nonce != $this->nonce ) {
			array_push( $initial_jobdata, 'file_nonce', $this->file_nonce );
		}

		if ( $is_scheduled_backup ) {
			array_push( $initial_jobdata, 'is_scheduled_backup', true );
		}

		// 'autobackup' == $options['extradata'] might be set from another plugin so keeping here to keep support
		if ( ! empty( $options['extradata'] ) && ( ! empty( $options['extradata']['autobackup'] ) || 'autobackup' === $options['extradata'] ) ) {
			array_push( $initial_jobdata, 'is_autobackup', true );
		}
		// Save what *should* be done, to make it resumable from this point on
		if ( $backup_database ) {
			try {
				list( $dbs, $coverage ) = isset( $options['database_plan'] ) ? $options['database_plan'] : BackupPlus_Backup_Sources::plan_databases( $options );
				array_push( $initial_jobdata, 'database_coverage', $coverage );
			} catch ( Exception $e ) {
				$this->log( $e->getMessage(), 'error' );
				if ( $this->semaphore ) { $this->semaphore->unlock(); }
				return false;
			}
		} else {
			$dbs = 'no';
		}

		$file_exclusions = array();
		foreach ( $job_file_entities as $entity => $unused ) { $file_exclusions[ $entity ] = $this->get_exclude( $entity ); }
		array_push( $initial_jobdata, 'file_sources', $file_sources, 'file_exclusions', $file_exclusions, 'file_entity_paths', $this->get_backupable_file_entities( true ) );
		BackupPlus_Backup_Sources::set_run_sources( null );
		array_push( $initial_jobdata, 'backup_database', $dbs );
		array_push( $initial_jobdata, 'backup_files', ( ( $backup_files ) ? 'begun' : 'no' ) );

		if ( is_array( $options ) && ! empty( $options['label'] ) ) {
			array_push( $initial_jobdata, 'label', $options['label'] );
		}

		if ( ! empty( $options['always_keep'] ) ) {
			array_push( $initial_jobdata, 'always_keep', true );
		}

		if ( ! empty( $options['remote_storage_instances'] ) ) {
			array_push( $initial_jobdata, 'remote_storage_instances', $options['remote_storage_instances'] );
		}

		try {
			// Use of jobdata_set_multi saves around 200ms
			call_user_func_array( array( $this, 'jobdata_set_multi' ), apply_filters( 'backupplus_initial_jobdata', $initial_jobdata, $options, $split_every ) );
		} catch ( Exception $e ) {
			$this->log( 'Exception when calling jobdata_set_multi: ' . $e->getMessage() . ' (' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')' );
			return false;
		}

		// Everything is set up; now go
		$this->backup_resume( 0, $this->nonce );

		if ( $one_shot ) {
			delete_site_option( 'backupplus_oneshotnonce' );
		}

		return true;
	}

	/**
	 * $handle must be either false or a WPDB class (or extension thereof). Other options are not yet fully supported.
	 *
	 * @param  Resource|Boolean|Object $handle
	 * @param  Boolean                 $log_it     - whether to log information about the check
	 * @param  Boolean                 $reschedule - whether to schedule a resumption if checking fails
	 * @param  Boolean                 $allow_bail - whether to allow the connection to fail or throw an error
	 * @return Boolean|Integer - whether the check succeeded, or -1 for an unknown result
	 */
	public function check_db_connection( $handle = false, $log_it = false, $reschedule = false, $allow_bail = false ) {

		$type = false;
		if ( false === $handle || is_a( $handle, 'wpdb' ) ) {
			$type = 'wpdb';
		} elseif ( is_resource( $handle ) ) {
			// Expected: string(10) "mysql link"
			$type = get_resource_type( $handle );
		} elseif ( is_object( $handle ) && is_a( $handle, 'mysqli' ) ) {
			$type = 'mysqli';
		}

		if ( false === $type ) {
			return -1;
		}

		$db_connected = -1;

		if ( 'mysql link' == $type || 'mysqli' == $type ) {
			if ( 'mysql link' == $type && @mysql_ping( $handle ) ) {
				return true;// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, PHPCompatibility.Extensions.RemovedExtensions.mysql_DeprecatedRemoved, WordPress.DB.RestrictedFunctions.mysql_mysql_ping -- NoSilencedErrors: @ suppresses PHP warnings when connection is dead; return value is checked instead. DeprecatedRemoved: legacy mysql extension support retained for older PHP. mysql_mysql_ping: direct call on raw database handle; $wpdb->check_connection() cannot be used for non-wpdb connections.
			}           if ( 'mysqli' == $type && @mysqli_ping( $handle ) ) {
				return true;// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, WordPress.DB.RestrictedFunctions.mysql_mysqli_ping -- NoSilencedErrors: @ suppresses PHP warnings when connection is dead; return value is checked instead. mysql_mysqli_ping: direct call on raw mysqli handle; $wpdb->check_connection() cannot be used for non-wpdb connections.
			}
			for ( $tries = 1; $tries <= 5; $tries++ ) {
				// to do, if ever needed
				// if ($this->db_connect(false )) return true;
				// sleep(1);
			}
		} elseif ( 'wpdb' == $type ) {
			if ( false === $handle || ( is_object( $handle ) && 'wpdb' == get_class( $handle ) ) ) {
				global $wpdb;
				$handle = $wpdb;
			}
			if ( method_exists( $handle, 'check_connection' ) && ( ! defined( 'BACKUPPLUS_SUPPRESS_CONNECTION_CHECKS' ) || ! BACKUPPLUS_SUPPRESS_CONNECTION_CHECKS ) ) {
				if ( ! $handle->check_connection( $allow_bail ) ) {
					if ( $log_it ) {
						$this->log( 'The database went away, and could not be reconnected to' );
					}
					// Almost certainly a no-op
					if ( $reschedule ) {
						BackupPlus_Job_Scheduler::reschedule( 60 );
					}
					$db_connected = false;
				} else {
					$db_connected = true;
				}
			}
		}

		return $db_connected;
	}
}
