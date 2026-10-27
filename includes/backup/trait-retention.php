<?php

/** Pruning backup sets that fall outside the retention settings. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Backup_Retention {

	/**
	 * This method is for calling outside of a cloud_backup() context. It constructs a list of services for which prune operations should be attempted, and then calls prune_retained_backups() if necessary upon them.
	 */
	public function do_prune_standalone() {
		global $backupplus;

		$services = (array) $backupplus->jobdata_get( 'service' );

		$prune_services = array();

		foreach ( $services as $service ) {
			if ( 'none' === $service || '' == $service ) {
				continue;
			}

			$remote_obj = BackupPlus_Storage_Provider_Factory::create( $service, false );
			if ( ! is_wp_error( $remote_obj ) ) {
				$prune_services[ $service ]['all'] = array( $remote_obj, null );
			} else {
				$backupplus->log( "Could not prune from service $service: remote method not found" );
			}
		}

		if ( ! empty( $prune_services ) ) {
			$this->prune_retained_backups( $prune_services );
		}
	}

	/**
	 * Group the backup history into sets for retention processing and indicate the retention rule to apply to each group. This is a 'default' function which just puts them all in together.
	 *
	 * @param Array $backup_history
	 *
	 * @return Array
	 */
	private function group_backups( $backup_history ) {
		return array(
			array(
				'sets'          => $backup_history,
				'process_order' => 'keep_newest',
			),
		);
	}

	/**
	 * Logs a message; with the message being logged to the database also only if that has not been done in the last 3 seconds. Useful for better overall performance on slow database servers with rapid logging.
	 *
	 * @uses BackupPlus::log()
	 *
	 * @param String $message - the message to log
	 * @param String $level   - the log level
	 */
	private function log_with_db_occasionally( $message, $level = 'notice' ) {
		global $backupplus;
		static $last_db = false;

		if ( time() > $last_db + 3 ) {
			$last_db    = time();
			$skip_dblog = false;
		} else {
			$skip_dblog = true;
		}

		return $backupplus->log( $message, $level, false, $skip_dblog );
	}

	/**
	 * Prunes historical backups, according to the user's settings
	 *
	 * @param Array $services - An associative array with list of services as key and remote object and boolean flag as values to prune on. This must be an array (i.e. it is not flexible like some other places)
	 *
	 * @return Boolean|void - false when a retention cleanup job stopped early to let a backup run
	 */
	public function prune_retained_backups( $services ) {

		global $backupplus, $wpdb;

		$is_cleanup = 'cleanup' === $backupplus->jobdata_get( 'job_type' );
		if ( class_exists( 'BackupPlus_Storage_Limits' ) && BackupPlus_Storage_Limits::managed() ) {
			BackupPlus_Storage_Limits::run( null, $is_cleanup || ! BackupPlus_Retention_Cleanup::deferred() );
			$backupplus->jobdata_set( 'prune', 'finished' );
			return true;
		}
		if ( ! $is_cleanup && BackupPlus_Retention_Cleanup::deferred() ) {
			$backupplus->log( 'Retain: old backups are deleted by the daily retention cleanup, so nothing will be pruned now' );
			$backupplus->jobdata_set( 'prune', 'finished' );
			return;
		}

		if ( method_exists( $wpdb, 'check_connection' ) && ( ! defined( 'BACKUPPLUS_SUPPRESS_CONNECTION_CHECKS' ) || ! BACKUPPLUS_SUPPRESS_CONNECTION_CHECKS ) ) {
			if ( ! $wpdb->check_connection( false ) ) {
				BackupPlus_Job_Scheduler::reschedule( 60 );
				$backupplus->log( 'It seems the database went away; scheduling a resumption and terminating for now' );
				BackupPlus_Job_Scheduler::record_still_alive();
				die;
			}
		}

		// If they turned off deletion on local backups, then there is nothing to do
		if ( ! BackupPlus_Options::get_backupplus_option( 'backupplus_delete_local', 0 ) && 1 == count( $services ) && array_key_exists( 'none', $services ) ) {
			$backupplus->log( 'Prune old backups from local store: nothing to do, since the user disabled local deletion and we are using local backups' );
			return;
		}

		$this->hash_list_of_complete_pruned_files = $backupplus->jobdata_get( 'hash_list_of_complete_pruned_files' );
		if ( ! is_array( $this->hash_list_of_complete_pruned_files ) ) {
			$this->hash_list_of_complete_pruned_files = array();
		}

		$backupplus->jobdata_set_multi(
			array(
				'jobstatus' => 'pruning',
				'prune'     => 'begun',
			)
		);

		// Number of backups to retain - files
		$backupplus_retain = BackupPlus_Options::get_backupplus_option( 'backupplus_retain', 2 );
		$backupplus_retain = is_numeric( $backupplus_retain ) ? $backupplus_retain : 1;

		// Number of backups to retain - db
		$backupplus_retain_db = BackupPlus_Options::get_backupplus_option( 'backupplus_retain_db', $backupplus_retain );
		$backupplus_retain_db = is_numeric( $backupplus_retain_db ) ? $backupplus_retain_db : 1;

		$backupplus->log( "Retain: beginning examination of existing backup sets; user setting: retain_files=$backupplus_retain, retain_db=$backupplus_retain_db" );

		// Returns an array, most recent first, of backup sets
		if ( $is_cleanup ) {
			BackupPlus_Backup_History::always_get_from_db();
		}
		$backup_history         = BackupPlus_Backup_History::get_history();
		$this->history_snapshot = $is_cleanup ? $backup_history : null;

		$ignored_because_imported = array();

		// Remove non-native (imported) backups, which are neither counted nor pruned. It's neater to do these in advance, and log only one line.
		$functional_backup_history = $backup_history;
		// Pre-update restore points are retained as complete sets by their coordinator.
		foreach ( $functional_backup_history as $time => $set ) {
			if ( ! empty( $set['pre_update'] ) || ! empty( $set['pitr'] ) ) {
				unset( $functional_backup_history[ $time ] );
			}
		}
		foreach ( $functional_backup_history as $backup_time => $backup_to_examine ) {
			if ( isset( $backup_to_examine['native'] ) && false == $backup_to_examine['native'] ) {
				$ignored_because_imported[] = $backup_time;
				unset( $functional_backup_history[ $backup_time ] );
			}
		}
		if ( ! empty( $ignored_because_imported ) ) {
			$backupplus->log( 'These backup set(s) were imported from a remote location, so will not be counted or pruned. Skipping: ' . implode( ', ', $ignored_because_imported ) );
		}

		$backupable_entities = $backupplus->get_backupable_file_entities( true );
		foreach ( $functional_backup_history as $set ) {
			foreach ( $set as $id => $unused ) { if ( BackupPlus_Backup_Sources::custom( $id ) ) { $backupable_entities[ $id ] = true; }
			}
		}

		$database_backups_found = array();

		$file_entities_backups_found = array();
		foreach ( $backupable_entities as $entity => $info ) {
			$file_entities_backups_found[ $entity ] = 0;
		}

		if ( false === ( $backup_db_groups = apply_filters( 'backupplus_group_backups_for_pruning', false, $functional_backup_history, 'db' ) ) ) {
			$backup_db_groups = $this->group_backups( $functional_backup_history );
		}
		$backupplus->log( 'Number of backup sets in history: ' . count( $backup_history ) . '; groups (db): ' . count( $backup_db_groups ) );

		foreach ( $backup_db_groups as $group_id => $group ) {

			// N.B. The array returned by BackupPlus_Backup_History::get_history() is already sorted, with most-recent first

			if ( empty( $group['sets'] ) || ! is_array( $group['sets'] ) ) {
				continue;
			}
			$sets = $group['sets'];

			// Sort the groups into the desired "keep this first" order
			$process_order = ( ! empty( $group['process_order'] ) && 'keep_oldest' == $group['process_order'] ) ? 'keep_oldest' : 'keep_newest';
			if ( 'keep_oldest' == $process_order ) {
				ksort( $sets );
			}

			$rule = ! empty( $group['rule'] ) ? $group['rule'] : array(
				'after-howmany' => 0,
				'after-period'  => 0,
				'every-period'  => 1,
				'every-howmany' => 1,
			);

			foreach ( $sets as $backup_datestamp => $backup_to_examine ) {

				$files_to_prune = array();
				$nonce          = empty( $backup_to_examine['nonce'] ) ? '???' : $backup_to_examine['nonce'];

				if ( $is_cleanup && BackupPlus_Retention_Cleanup::instance()->should_yield() ) {
					$backupplus->log( 'Retain: a backup has started; saving progress and stopping the retention cleanup for now' );
					$this->save_pruned_history( $backup_history );
					return false;
				}

				// $backup_to_examine is an array of file names, keyed on db/plugins/themes/uploads
				// The new backup_history array is saved afterwards, so remember to unset the ones that are to be deleted
				$this->log_with_db_occasionally( sprintf( "Examining (for databases) backup set with group_id=$group_id, nonce=%s, datestamp=%s (%s)", $nonce, $backup_datestamp, gmdate( 'M d Y H:i:s', $backup_datestamp ) ) );

				// "Always Keep" Backups should be counted in the count of how many have been retained for purposes of the "how many to retain" count... but if that count is already matched, it's not a problem
				$is_always_keep = ! empty( $backup_to_examine['always_keep'] );

				// Auto-backups are only counted or deleted once we have reached the retain limit - before that, they are skipped
				$is_autobackup = ! empty( $backup_to_examine['autobackup'] );

				$any_deleted_via_filter_yet = false;

				// Databases
				foreach ( $backup_to_examine as $key => $data ) {
					if ( 'db' != strtolower( substr( $key, 0, 2 ) ) || '-size' == substr( $key, -5, 5 ) ) {
						continue;
					}

					if ( empty( $database_backups_found[ $key ] ) ) {
						$database_backups_found[ $key ] = 0;
					}

					if ( $nonce == $backupplus->nonce || $nonce == $backupplus->file_nonce ) {
						$this->log_with_db_occasionally( 'This backup set is the backup set just made, so will not be deleted.' );
						++$database_backups_found[ $key ];
						continue;
					}

					if ( $is_always_keep ) {
						if ( $database_backups_found[ $key ] < $backupplus_retain ) {
							$this->log_with_db_occasionally( "This backup set ($backup_datestamp) was an 'Always Keep' backup, and we have not yet reached any retain limits, so it should be counted in the count of how many have been retained for purposes of the 'how many to retain' count. It will not be pruned. Skipping." );
							++$database_backups_found[ $key ];
						} else {
							$this->log_with_db_occasionally( "This backup set ($backup_datestamp) was an 'Always Keep' backup, so it will not be pruned. Skipping." );
						}
						continue;
					}

					if ( $is_autobackup ) {
						if ( $any_deleted_via_filter_yet ) {
							$this->log_with_db_occasionally( "This backup set ($backup_datestamp) was an automatic backup, but we have previously deleted a backup due to a limit, so it will be pruned (but not counted towards numerical limits)." );
							$prune_it = true;
						} elseif ( $database_backups_found[ $key ] < $backupplus_retain_db ) {
							$this->log_with_db_occasionally( "This backup set ($backup_datestamp) was an automatic backup, and we have not yet reached any retain limits, so it will not be counted or pruned. Skipping." );
							continue;
						} else {
							$this->log_with_db_occasionally( "This backup set ($backup_datestamp) was an automatic backup, and we have already reached retain limits, so it will be pruned." );
							$prune_it = true;
						}
					} else {
						$prune_it = false;
					}

					// All non-auto backups must be run through this filter (in date order) regardless of the current state of $prune_it - so that filters are able to track state.
					$prune_it_before_filter = $prune_it;

					if ( ! $is_autobackup ) {
						$prune_it = apply_filters( 'backupplus_prune_or_not', $prune_it, 'db', $backup_datestamp, $key, $database_backups_found[ $key ], $rule, $group_id );
					}

					// Apply the final retention limit list (do not increase the 'retained' counter before seeing if the backup is being pruned for some other reason)
					if ( ! $prune_it && ! $is_autobackup ) {

						if ( $database_backups_found[ $key ] + 1 > $backupplus_retain_db ) {
							$prune_it = true;

							$fname = is_string( $data ) ? $data : $data[0];
							$this->log_with_db_occasionally( "$backup_datestamp: $key: this set includes a database (" . $fname . '); db count is now ' . $database_backups_found[ $key ] );

							$this->log_with_db_occasionally( "$backup_datestamp: $key: over retain limit ($backupplus_retain_db); will delete this database" );
						}
					}

					if ( $prune_it ) {
						if ( ! $prune_it_before_filter ) {
							$any_deleted_via_filter_yet = true;
						}

						if ( ! empty( $data ) ) {
							$size_key = $key . '-size';
							$size     = isset( $backup_to_examine[ $size_key ] ) ? $backup_to_examine[ $size_key ] : null;
							foreach ( $services as $service => $instance_ids_to_prune ) {
								foreach ( $instance_ids_to_prune as $instance_id_to_prune => $sd ) {
									$this->current_pruning_instance_id = $instance_id_to_prune;
									if ( 'none' != $service && '' != $service && $sd[0]->supports_feature( 'multi_options' ) ) {
										$storage_objects_and_ids = BackupPlus_Storage_Methods_Interface::get_storage_objects_and_ids( array( $service ) );
										if ( 'all' == $instance_id_to_prune ) {
											foreach ( $storage_objects_and_ids[ $service ]['instance_settings'] as $saved_instance_id => $options ) {
												$this->current_pruning_instance_id = $saved_instance_id;
												$sd[0]->set_options( $options, false, $saved_instance_id );
												$this->prune_file( $service, $data, $sd[0], $sd[1], array( $size ) );
											}
										} else {
											$opts = $storage_objects_and_ids[ $service ]['instance_settings'][ $instance_id_to_prune ];
											$sd[0]->set_options( $opts, false, $instance_id_to_prune );
											$this->prune_file( $service, $data, $sd[0], $sd[1], array( $size ) );
										}
									} else {
										$this->current_pruning_instance_id = empty( $service ) ? 'none' : $service;
										$this->prune_file( $service, $data, $sd[0], $sd[1], array( $size ) );
									}
								}
							}
						}
						$this->current_pruning_instance_id = '';
						unset( $backup_to_examine[ $key ] );
						BackupPlus_Job_Scheduler::record_still_alive();
					} elseif ( ! $is_autobackup ) {
						++$database_backups_found[ $key ];
					}

					$backup_to_examine = $this->remove_backup_set_if_empty( $backup_to_examine, $backupable_entities );
					if ( empty( $backup_to_examine ) ) {
						unset( $functional_backup_history[ $backup_datestamp ] );
						unset( $backup_history[ $backup_datestamp ] );
						$this->maybe_save_backup_history_and_reschedule( $backup_history );
					} else {
						$functional_backup_history[ $backup_datestamp ] = $backup_to_examine;
						$backup_history[ $backup_datestamp ]            = $backup_to_examine;
					}
				}
			}
		}

		if ( false === ( $backup_files_groups = apply_filters( 'backupplus_group_backups_for_pruning', false, $functional_backup_history, 'files' ) ) ) {
			$backup_files_groups = $this->group_backups( $functional_backup_history );
		}

		$backupplus->log( 'Number of backup sets in history: ' . count( $backup_history ) . '; groups (files): ' . count( $backup_files_groups ) );

		// Now again - this time for the files
		foreach ( $backup_files_groups as $group_id => $group ) {

			// N.B. The array returned by BackupPlus_Backup_History::get_history() is already sorted, with most-recent first

			if ( empty( $group['sets'] ) || ! is_array( $group['sets'] ) ) {
				continue;
			}
			$sets = $group['sets'];

			// Sort the groups into the desired "keep this first" order
			$process_order = ( ! empty( $group['process_order'] ) && 'keep_oldest' == $group['process_order'] ) ? 'keep_oldest' : 'keep_newest';
			// Youngest - i.e. smallest epoch - first
			if ( 'keep_oldest' == $process_order ) {
				ksort( $sets );
			}

			$rule = ! empty( $group['rule'] ) ? $group['rule'] : array(
				'after-howmany' => 0,
				'after-period'  => 0,
				'every-period'  => 1,
				'every-howmany' => 1,
			);

			foreach ( $sets as $backup_datestamp => $backup_to_examine ) {

				$files_to_prune = array();
				$nonce          = empty( $backup_to_examine['nonce'] ) ? '???' : $backup_to_examine['nonce'];

				if ( $is_cleanup && BackupPlus_Retention_Cleanup::instance()->should_yield() ) {
					$backupplus->log( 'Retain: a backup has started; saving progress and stopping the retention cleanup for now' );
					$this->save_pruned_history( $backup_history );
					return false;
				}

				// $backup_to_examine is an array of file names, keyed on db/plugins/themes/uploads
				// The new backup_history array is saved afterwards, so remember to unset the ones that are to be deleted
				$this->log_with_db_occasionally( sprintf( 'Examining (for files) backup set with nonce=%s, datestamp=%s (%s)', $nonce, $backup_datestamp, gmdate( 'M d Y H:i:s', $backup_datestamp ) ) );

				// "Always Keep" Backups should be counted in the count of how many have been retained for purposes of the "how many to retain" count... but if that count is already matched, it's not a problem
				$is_always_keep = ! empty( $backup_to_examine['always_keep'] );

				// Auto-backups are only counted or deleted once we have reached the retain limit - before that, they are skipped
				$is_autobackup = ! empty( $backup_to_examine['autobackup'] );

				$any_deleted_via_filter_yet = false;

				$file_sizes = array();

				// Files
				foreach ( $backupable_entities as $entity => $info ) {
					if ( ! empty( $backup_to_examine[ $entity ] ) ) {

						// This should only be able to happen if you import backups with a future timestamp
						if ( $nonce == $backupplus->nonce || $nonce == $backupplus->file_nonce ) {
							$backupplus->log( 'This backup set is the backup set just made, so will not be deleted.' );
							++$file_entities_backups_found[ $entity ];
							continue;
						}

						if ( $is_always_keep ) {
							if ( $file_entities_backups_found[ $entity ] < $backupplus_retain ) {
								$this->log_with_db_occasionally( "This backup set ($backup_datestamp) was an 'Always Keep' backup, and we have not yet reached any retain limits, so it should be counted in the count of how many have been retained for purposes of the 'how many to retain' count. It will not be pruned. Skipping." );
								++$file_entities_backups_found[ $entity ];
							} else {
								$this->log_with_db_occasionally( "This backup set ($backup_datestamp) was an 'Always Keep' backup, so it will not be pruned. Skipping." );
							}
							continue;
						}

						if ( $is_autobackup ) {
							if ( $any_deleted_via_filter_yet ) {
								$this->log_with_db_occasionally( 'This backup set was an automatic backup, but we have previously deleted a backup due to a limit, so it will be pruned (but not counted towards numerical limits).' );
								$prune_it = true;
							} elseif ( $file_entities_backups_found[ $entity ] < $backupplus_retain ) {
								$this->log_with_db_occasionally( "This backup set ($backup_datestamp) was an automatic backup, and we have not yet reached any retain limits, so it will not be counted or pruned. Skipping." );
								continue;
							} else {
								$this->log_with_db_occasionally( "This backup set ($backup_datestamp) was an automatic backup, and we have already reached retain limits, so it will be pruned." );
								$prune_it = true;
							}
						} else {
							$prune_it = false;
						}

						// All non-auto backups must be run through this filter (in date order) regardless of the current state of $prune_it - so that filters are able to track state.
						$prune_it_before_filter = $prune_it;
						if ( ! $is_autobackup ) {
							$prune_it = apply_filters( 'backupplus_prune_or_not', $prune_it, 'files', $backup_datestamp, $entity, $file_entities_backups_found[ $entity ], $rule, $group_id );
						}

						// The "more than maximum to keep?" counter should not be increased until we actually know that the set is being kept. Before version 1.11.22, we checked this before running the filter, which resulted in the counter being increased for sets that got pruned via the filter (i.e. not kept) - and too many backups were thus deleted
						if ( ! $prune_it && ! $is_autobackup ) {
							if ( $file_entities_backups_found[ $entity ] >= $backupplus_retain ) {
								$this->log_with_db_occasionally( "$entity: over retain limit ($backupplus_retain); will delete this file entity" );
								$prune_it = true;
							}
						}

						if ( $prune_it ) {
							if ( ! $prune_it_before_filter ) {
								$any_deleted_via_filter_yet = true;
							}
							$prune_this = $backup_to_examine[ $entity ];
							if ( is_string( $prune_this ) ) {
								$prune_this = array( $prune_this );
							}

							foreach ( $prune_this as $k => $prune_file ) {
								$size_key         = ( 0 == $k ) ? $entity . '-size' : $entity . $k . '-size';
								$size             = ( isset( $backup_to_examine[ $size_key ] ) ) ? $backup_to_examine[ $size_key ] : null;
								$files_to_prune[] = $prune_file;
								$file_sizes[]     = $size;
							}
							unset( $backup_to_examine[ $entity ] );

						} elseif ( ! $is_autobackup ) {
							++$file_entities_backups_found[ $entity ];
						}
					}
				}

				// Sending an empty array is not itself a problem - except that the remote storage method may not check that before setting up a connection, which can waste time: especially if this is done every time around the loop.
				if ( ! empty( $files_to_prune ) ) {
					// Actually delete the files
					foreach ( $services as $service => $instance_ids_to_prune ) {
						foreach ( $instance_ids_to_prune as $instance_id_to_prune => $sd ) {
							$this->current_pruning_instance_id = $instance_id_to_prune;
							if ( 'none' != $service && '' != $service && $sd[0]->supports_feature( 'multi_options' ) ) {
								$storage_objects_and_ids = BackupPlus_Storage_Methods_Interface::get_storage_objects_and_ids( array( $service ) );
								if ( 'all' == $instance_id_to_prune ) {
									foreach ( $storage_objects_and_ids[ $service ]['instance_settings'] as $saved_instance_id => $options ) {
										$this->current_pruning_instance_id = $saved_instance_id;
										$sd[0]->set_options( $options, false, $saved_instance_id );
										$this->prune_file( $service, $files_to_prune, $sd[0], $sd[1], $file_sizes );
									}
								} else {
									$opts = $storage_objects_and_ids[ $service ]['instance_settings'][ $instance_id_to_prune ];
									$sd[0]->set_options( $opts, false, $instance_id_to_prune );
									$this->prune_file( $service, $files_to_prune, $sd[0], $sd[1], $file_sizes );
								}
							} else {
								$this->current_pruning_instance_id = empty( $service ) ? 'none' : $service;
								$this->prune_file( $service, $files_to_prune, $sd[0], $sd[1], $file_sizes );
							}
							BackupPlus_Job_Scheduler::record_still_alive();
						}
					}
				}
				$this->current_pruning_instance_id = '';

				$backup_to_examine = $this->remove_backup_set_if_empty( $backup_to_examine, $backupable_entities );
				if ( empty( $backup_to_examine ) ) {
					unset( $backup_history[ $backup_datestamp ] );
					$this->maybe_save_backup_history_and_reschedule( $backup_history );
				} else {
					$backup_history[ $backup_datestamp ] = $backup_to_examine;
				}

				// Loop over backup sets
			}

			// Look over backup groups
		}

		$backupplus->log( 'Retain: saving new backup history (sets now: ' . count( $backup_history ) . ') and finishing retain operation' );
		$this->save_pruned_history( $backup_history );

		do_action( 'backupplus_prune_retained_backups_finished' );

		$backupplus->jobdata_set( 'prune', 'finished' );
		return true;
	}

	/**
	 * Save the pruned backup history. A retention cleanup job can run alongside a backup that adds
	 * to the history, so it applies only its own removals to the latest saved history.
	 *
	 * @param Array $backup_history - the backup history after pruning
	 */
	private function save_pruned_history( $backup_history ) {
		if ( null === $this->history_snapshot ) {
			BackupPlus_Backup_History::save_history( $backup_history, false );
			return;
		}
		BackupPlus_Backup_History::always_get_from_db();
		$latest = BackupPlus_Backup_History::get_history();
		foreach ( $this->history_snapshot as $timestamp => $set ) {
			if ( ! isset( $latest[ $timestamp ] ) ) {
				continue;
			}
			if ( ! isset( $backup_history[ $timestamp ] ) ) {
				unset( $latest[ $timestamp ] );
				continue;
			}
			foreach ( array_diff_key( $set, $backup_history[ $timestamp ] ) as $key => $unused ) {
				unset( $latest[ $timestamp ][ $key ] );
			}
		}
		BackupPlus_Backup_History::save_history( $latest, false );
		// Later removals are relative to what has now been saved.
		$this->history_snapshot = $backup_history;
	}

	/**
	 * The purpose of this is to save the backup history periodically - for the benefit of setups where the pruning takes longer than the total allow run time (e.g. if the network communications to the remote storage have delays in, and there are a lot of sets to scan)
	 *
	 * @param Array $backup_history - the backup history to possible save
	 */
	private function maybe_save_backup_history_and_reschedule( $backup_history ) {
		static $last_saved_at = 0;
		if ( ! $last_saved_at ) {
			$last_saved_at = time();
		}
		if ( time() - $last_saved_at >= 10 ) {
			global $backupplus;
			$backupplus->log( 'Retain: saving new backup history, because at least 10 seconds have passed since the last save (sets now: ' . count( $backup_history ) . ')' );
			$this->save_pruned_history( $backup_history );
			BackupPlus_Job_Scheduler::something_useful_happened();
			$last_saved_at = time();
		}
	}

	/**
	 * Examine a backup set; if it is empty (no files or DB), then remove the associated log file
	 *
	 * @param Array $backup_to_examine   - backup set
	 * @param Array $backupable_entities - compare with this list of backup entities
	 *
	 * @return Array|Boolean - if it was empty, false is returned
	 */
	private function remove_backup_set_if_empty( $backup_to_examine, $backupable_entities ) {

		global $backupplus;

		// Get new result, post-deletion; anything left in this set?
		$contains_files = 0;
		foreach ( $backupable_entities as $entity => $info ) {
			if ( isset( $backup_to_examine[ $entity ] ) ) {
				$contains_files = 1;
				break;
			}
		}

		$contains_db = 0;
		foreach ( $backup_to_examine as $key => $data ) {
			if ( 'db' == strtolower( substr( $key, 0, 2 ) ) && '-size' != substr( $key, -5, 5 ) ) {
				$contains_db = 1;
				break;
			}
		}

		// Delete backup set completely if empty, o/w just remove DB
		// We search on the four keys which represent data, allowing other keys to be used to track other things
		if ( ! $contains_files && ! $contains_db ) {
			$backupplus->log( 'This backup set is now empty; will remove from history' );
			if ( isset( $backup_to_examine['nonce'] ) ) {
				$fullpath = $this->backupplus_dir . '/log.' . $backup_to_examine['nonce'] . '.txt';
				if ( is_file( $fullpath ) ) {
					$backupplus->log( 'Deleting log file (log.' . $backup_to_examine['nonce'] . '.txt)' );
					@unlink( $fullpath );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
				} else {
					$backupplus->log( 'Corresponding log file (log.' . $backup_to_examine['nonce'] . '.txt) not found - must have already been deleted' );
				}
			} else {
				$backupplus->log( 'No nonce record found in the backup set, so cannot delete any remaining log file' );
			}
			return false;
		} else {
			$backupplus->log( "This backup set remains non-empty (f=$contains_files/d=$contains_db); will retain in history" );
			return $backup_to_examine;
		}
	}

	/**
	 * Prune files from remote and local storage
	 *
	 * @param String       $service         Service to prune (one only)
	 * @param Array|String $dofiles         An array of files (or a single string for one file)
	 * @param Array        $method_object   specific method object
	 * @param Array        $object_passback specific passback object
	 * @param Array        $file_sizes      size of files
	 */
	private function prune_file( $service, $dofiles, $method_object = null, $object_passback = null, $file_sizes = array() ) {
		global $backupplus;
		if ( ! is_array( $dofiles ) ) {
			$dofiles = array( $dofiles );
		}

		if ( ! apply_filters( 'backupplus_prune_file', true, $dofiles, $service, $method_object, $object_passback, $file_sizes ) ) {
			$backupplus->log( "Prune: service=$service: skipped via filter" );
			return;
		}

		$is_cleanup = 'cleanup' === $backupplus->jobdata_get( 'job_type' );
		$cleanup    = $is_cleanup ? BackupPlus_Retention_Cleanup::instance() : null;

		foreach ( $dofiles as $dofile ) {
			$fullpath = $this->backupplus_dir . '/' . $dofile;
			if ( empty( $dofile ) || ! file_exists( $fullpath ) ) {
				continue;
			}
			$backupplus->log( "Delete file: $dofile, service=$service" );
			// delete it if it's locally available
			$backupplus->log( "Deleting local copy ($dofile)" );
			if ( $is_cleanup ) {
				// A failed local deletion is queued for the next cleanup.
				if ( @unlink( $fullpath ) ) { // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- The failure is recorded.
					$cleanup->record_deleted( array( $dofile ) );
				} else {
					$cleanup->record_failed( 'local', '', array( $dofile ), array( null ) );
				}
			} else {
				unlink( $fullpath );
			}
			if ( file_exists( $fullpath . '.list.tmp' ) ) {
				$backupplus->log( "Deleting zip manifest ({$dofile}.list.tmp)" );
				unlink( $fullpath . '.list.tmp' );
			}
		}
		// Despatch to the particular method's deletion routine
		if ( ! is_null( $method_object ) ) {
			$files                                      = $sizes = array();
			$pruning_start_time_for_current_instance_id = microtime( true );
			foreach ( $dofiles as $actual_index => $file ) {
				$hashed_file = md5( $this->current_pruning_instance_id . $file );
				if ( in_array( $hashed_file, $this->hash_list_of_complete_pruned_files ) ) {
					continue;
				}
				$files[]                                    = $file;
				$sizes[]                                    = $file_sizes[ $actual_index ];
				$this->hash_list_of_complete_pruned_files[] = $hashed_file;
				if ( count( $dofiles ) !== $actual_index + 1 && 0 != ( $actual_index + 1 ) % $this->pruning_number_of_files ) {
					continue; // only proceed with deletion at every multiples of ten (default) or if it's already at the end of files
				}
				$deleted = $method_object->delete( $files, $object_passback, $sizes );
				if ( $is_cleanup ) {
					if ( true === $deleted ) {
						$cleanup->record_deleted( $files );
					} else {
						$cleanup->record_failed( $service, $this->current_pruning_instance_id, $files, $sizes );
					}
				}
				// A cleanup job records each deletion, so that a resumed job does not repeat it.
				if ( $is_cleanup || count( $dofiles ) === $actual_index + 1 || ( floor( microtime( true ) - $pruning_start_time_for_current_instance_id ) ) >= $this->minimum_time_to_cache_pruned_files ) {
					$this->hash_list_of_complete_pruned_files = array_unique( $this->hash_list_of_complete_pruned_files );
					$backupplus->jobdata_set( 'hash_list_of_complete_pruned_files', $this->hash_list_of_complete_pruned_files ); // only cache already-done files when it reaches the minimum time, which is 25 seconds by default
					$pruning_start_time_for_current_instance_id = microtime( true );
				}
				$files = $sizes = array();
			}
		}
	}

	/**
	 * Log the amount account space free/used, if possible
	 */
	private function log_account_space() {
		global $backupplus;
		$hosting_bytes_free = $backupplus->get_hosting_disk_quota_free();
		if ( is_array( $hosting_bytes_free ) ) {
			$perc = round( 100 * $hosting_bytes_free[1] / ( max( $hosting_bytes_free[2], 1 ) ), 1 );
			$backupplus->log( sprintf( 'Free disk space in account: %s (%s used)', round( $hosting_bytes_free[3] / 1048576, 1 ) . ' MB', "$perc %" ) );
		}
	}
}
