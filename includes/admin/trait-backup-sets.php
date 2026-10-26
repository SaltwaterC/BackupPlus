<?php

/** Backup set deletion, history status and completeness checks. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Admin_Backup_Sets {

	/**
	 * Delete a backup set, whilst respecting limits on how much to delete in one go
	 *
	 * @uses remove_backup_set_cleanup()
	 * @param Array $opts - deletion options; with keys backup_timestamp, delete_remote, [remote_delete_limit]
	 * @return Array - as from remove_backup_set_cleanup()
	 */
	public function delete_set( $opts ) {
		if ( ! empty( $opts['strict'] ) ) {
			require_once BACKUPPLUS_DIR . '/includes/class-backup-deletion.php';
			return BackupPlus_Backup_Deletion::delete( $opts );
		}

		global $backupplus;

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( BACKUPPLUS_SET_TIME_LIMIT );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}
		$backups    = BackupPlus_Backup_History::get_history();
		$timestamps = (string) $opts['backup_timestamp'];

		$remote_delete_limit    = ( isset( $opts['remote_delete_limit'] ) && $opts['remote_delete_limit'] > 0 ) ? (int) $opts['remote_delete_limit'] : PHP_INT_MAX;
		$processed_instance_ids = ! empty( $opts['processed_instance_ids'] ) && is_array( $opts['processed_instance_ids'] ) ? $opts['processed_instance_ids'] : array();
		$is_continuation        = $opts['is_continuation'];

		$timestamps = explode( ',', $timestamps );
		foreach ( $timestamps as $timestamp ) {
			if ( ! empty( $backups[ $timestamp ]['pitr'] ) ) {
				require_once BACKUPPLUS_DIR . '/includes/class-backup-deletion.php';
				return BackupPlus_Backup_Deletion::delete_selection( $opts );
			}
		}
		$deleted_timestamps = '';
		$delete_remote      = empty( $opts['delete_remote'] ) ? false : true;

		// You need a nonce before you can set job data. And we certainly don't yet have one.
		$backupplus->backup_time_nonce();
		// Set the job type before logging, as there can be different logging destinations
		$backupplus->jobdata_set( 'job_type', 'delete' );
		$backupplus->jobdata_set( 'job_time_ms', $backupplus->job_time_ms );

		if ( BackupPlus_Options::get_backupplus_option( 'backupplus_debug_mode' ) ) {
			$backupplus->logfile_open( $backupplus->nonce );
			$error_levels = version_compare( PHP_VERSION, '8.4.0', '>=' ) ? E_ALL : E_ALL & ~E_STRICT;
			set_error_handler( array( $backupplus, 'php_error' ), $error_levels );
		}

		$backupplus_dir      = $backupplus->backups_dir_location();
		$backupable_entities = $backupplus->get_backupable_file_entities( true, true );

		$local_deleted  = 0;
		$remote_deleted = 0;
		$sets_removed   = 0;

		$deletion_errors = array();
		$timestamps_list = implode( ',', $timestamps );

		if ( ! $is_continuation ) {
			foreach ( $timestamps as $i => $timestamp ) {
				unset( $backups[ $timestamp ]['instance_file_deletions_error'] );
			}
		}

		foreach ( $timestamps as $i => $timestamp ) {

			if ( ! isset( $backups[ $timestamp ] ) ) {
				return array(
					'result'  => 'error',
					'message' => __( 'Backup set not found', 'backupplus' ),
				);
			}

			$backupable_entities = BackupPlus_Backup_Sources::restore_entities( $backupable_entities, $backups[ $timestamp ] );
			$nonce               = isset( $backups[ $timestamp ]['nonce'] ) ? $backups[ $timestamp ]['nonce'] : '';

			$delete_from_service = array();

			if ( $delete_remote ) {
				// Locate backup set
				if ( isset( $backups[ $timestamp ]['service'] ) ) {
					// Convert to an array so that there is no uncertainty about how to process it
					$services = is_string( $backups[ $timestamp ]['service'] ) ? array( $backups[ $timestamp ]['service'] ) : $backups[ $timestamp ]['service'];
					if ( is_array( $services ) ) {
						foreach ( $services as $service ) {
							if ( is_string( $service ) && 'none' != $service && 'email' != $service ) {
								$delete_from_service[] = $service;
							}
						}
					}
				}
			}

			$delete_from_service = array_unique( $delete_from_service ); // it's possible that the same service was added more than one thus deleting already-done files over and over, we've seen a case when using SFTP service with two instance IDs and when rescanning remote storage will make two SFTP services appearing in the associated raw backup history

			$files_to_delete = array();
			foreach ( $backupable_entities as $key => $ent ) {
				if ( isset( $backups[ $timestamp ][ $key ] ) ) {
					$files_to_delete[ $key ] = $backups[ $timestamp ][ $key ];
				}
			}
			// Delete DB
			foreach ( $backups[ $timestamp ] as $key => $value ) {
				if ( 'db' == strtolower( substr( $key, 0, 2 ) ) && '-size' != substr( $key, -5, 5 ) ) {
					$files_to_delete[ $key ] = $backups[ $timestamp ][ $key ];
				}
			}

			// Also delete the log
			if ( $nonce && ! BackupPlus_Options::get_backupplus_option( 'backupplus_debug_mode' ) ) {
				$files_to_delete['log'] = "log.$nonce.txt";
			}

			$backupplus->register_wp_http_option_hooks();

			$start_time_for_file_deletions = microtime( true );

			foreach ( $files_to_delete as $key => $files ) {

				if ( is_string( $files ) ) {
					$files = array( $files );
				}

				foreach ( $files as $file ) {
					if ( is_file( $backupplus_dir . '/' . $file ) && @unlink( $backupplus_dir . '/' . $file ) ) {
						++$local_deleted;// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
					}
				}

				if ( 'log' != $key && count( $delete_from_service ) > 0 ) {

					$entity_total_files     = count( $files );
					$entity_total_instances = 0;
					$entity_remote_deleted  = 0;

					$storage_objects_and_ids = BackupPlus_Storage_Methods_Interface::get_storage_objects_and_ids( $delete_from_service );

					foreach ( $delete_from_service as $service ) {

						if ( 'email' == $service || 'none' == $service || ! $service ) {
							continue;
						}

						$deleted = -1;

						if ( empty( $storage_objects_and_ids[ $service ]['object'] ) ) {
							continue;
						}
						$remote_obj = $storage_objects_and_ids[ $service ]['object'];

						$instance_settings          = $storage_objects_and_ids[ $service ]['instance_settings'];
						$this->backups_instance_ids = empty( $backups[ $timestamp ]['service_instance_ids'][ $service ] ) ? array() : $backups[ $timestamp ]['service_instance_ids'][ $service ];

						if ( empty( $instance_settings ) ) {
							continue;
						}

						uksort( $instance_settings, array( $this, 'instance_ids_sort' ) );

						foreach ( $instance_settings as $instance_id => $options ) {

							if ( in_array( $timestamp . '-' . $instance_id . '-' . $key, $processed_instance_ids ) ) {
								continue; // see the unset($backups[$timestamp]['instance_file_deletions_error']); few lines below to see why we use this
							}

							++$entity_total_instances;

							$remote_obj->set_options( $options, false, $instance_id );

							foreach ( $files as $file ) {
								$hashed_file = md5( $instance_id . $file );
								if ( isset( $backups[ $timestamp ]['instance_file_deletions_done'] ) && in_array( $hashed_file, $backups[ $timestamp ]['instance_file_deletions_done'] ) ) {
									continue;
								}
								if ( $is_continuation && isset( $backups[ $timestamp ]['instance_file_deletions_error'] ) && in_array( $hashed_file, $backups[ $timestamp ]['instance_file_deletions_error'] ) ) {
									continue; // if it's a continuation then we don't want to retry deleting the same file over and over
								}
								$deleted = $remote_obj->delete( $file );

								if ( true === $deleted ) {
									$backups[ $timestamp ]['instance_file_deletions_done'][] = $hashed_file;
									++$remote_deleted;
									++$entity_remote_deleted;
								} else {
									// Handle abstracted error codes/return fail status. Including handle array/objects returned
									if ( is_object( $deleted ) || is_array( $deleted ) ) {
										$deleted = false;
									}

									if ( ! array_key_exists( $instance_id, $deletion_errors ) ) {
										$deletion_errors[ $instance_id ] = array(
											'error_code' => $deleted,
											'service'    => $service,
										);
									}
									$backups[ $timestamp ]['instance_file_deletions_error'][] = $hashed_file;
								}
								if ( floor( microtime( true ) - $start_time_for_file_deletions ) >= $remote_delete_limit ) {
									return $this->remove_backup_set_cleanup( false, $backups, $local_deleted, $remote_deleted, $sets_removed, $timestamps_list, $deleted_timestamps, $deletion_errors, $processed_instance_ids );
								}
							}
							$processed_instance_ids[] = $timestamp . '-' . $instance_id . '-' . $key;
						}
					}
					if ( $entity_total_files * $entity_total_instances === $entity_remote_deleted ) {
						unset( $backups[ $timestamp ][ $key ] );
						for ( $file_index = 1; $file_index < $entity_total_files; $file_index++ ) {
							unset( $backups[ $timestamp ][ $key . $file_index . '-size' ] );
							if ( isset( $backups[ $timestamp ]['checksums'] ) && is_array( $backups[ $timestamp ]['checksums'] ) ) {
								foreach ( array_keys( $backups[ $timestamp ]['checksums'] ) as $algo ) {
									unset( $backups[ $timestamp ]['checksums'][ $algo ][ $key . $file_index ] );
								}
							}
						}
						unset( $backups[ $timestamp ][ $key . '-size' ] );
						if ( isset( $backups[ $timestamp ]['checksums'] ) && is_array( $backups[ $timestamp ]['checksums'] ) ) {
							foreach ( array_keys( $backups[ $timestamp ]['checksums'] ) as $algo ) {
								unset( $backups[ $timestamp ]['checksums'][ $algo ][ $key . '0' ] );
							}
						}
					}
				}
			}

			if ( empty( $backups[ $timestamp ]['instance_file_deletions_error'] ) ) {
				unset( $backups[ $timestamp ] );
				unset( $timestamps[ $i ] );
				if ( '' != $deleted_timestamps ) {
					$deleted_timestamps .= ',';
				}
				$deleted_timestamps .= $timestamp;
				$timestamps_list     = implode( ',', $timestamps );
				++$sets_removed;
			} else {
				// we already have $processed_instance_ids variable that stores all already-done instances and it's going to be used during the continuation as long as it gets returned to the JS caller
				// so we unset the instance_file_deletions_error variable to prevent backup history from swelling
				unset( $backups[ $timestamp ]['instance_file_deletions_error'] );
			}
		}

		return $this->remove_backup_set_cleanup( true, $backups, $local_deleted, $remote_deleted, $sets_removed, $timestamps_list, $deleted_timestamps, $deletion_errors );
	}

	/**
	 * This function sorts the array of instance ids currently saved so that any instance id that is in both the saved settings and the backup history move to the top of the array, as these are likely to work. Then values that don't appear in the backup history move to the bottom.
	 *
	 * @param  String $a - the first instance id
	 * @param  String $b - the second instance id
	 * @return Integer   - returns an integer to indicate what position the $b value should be moved in
	 */
	public function instance_ids_sort( $a, $b ) {
		if ( in_array( $a, $this->backups_instance_ids ) ) {
			if ( in_array( $b, $this->backups_instance_ids ) ) {
				return 0;
			}
			return -1;
		}
		return in_array( $b, $this->backups_instance_ids ) ? 1 : 0;
	}

	/**
	 * Called by self::delete_set() to finish up before returning (whether the complete deletion is finished or not)
	 *
	 * @param Boolean $delete_complete        - whether the whole set is now gone (i.e. last round)
	 * @param Array   $backups                - the backup history
	 * @param Integer $local_deleted          - how many backup archives were deleted from local storage
	 * @param Integer $remote_deleted         - how many backup archives were deleted from remote storage
	 * @param Integer $sets_removed           - how many complete sets were removed
	 * @param String  $timestamps             - a csv of remaining timestamps
	 * @param String  $deleted_timestamps     - a csv of deleted timestamps
	 * @param Array   $deletion_errors        - an array of abstracted deletion errors, consisting of [error_code, service, instance]. For user notification purposes only, main error logging occurs at service.
	 * @param Array   $processed_instance_ids - a list of processed instance IDs prefixed with their corresponding timestamp
	 *
	 * @return Array - information on the status, suitable for returning to the UI
	 */
	public function remove_backup_set_cleanup( $delete_complete, $backups, $local_deleted, $remote_deleted, $sets_removed, $timestamps, $deleted_timestamps, $deletion_errors = array(), $processed_instance_ids = array() ) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $deletion_errors was used below but the code has been commented out.  Can both be removed?

		global $backupplus;

		$backupplus->register_wp_http_option_hooks( false );

		BackupPlus_Backup_History::save_history( $backups );

		$backupplus->log( "Local files deleted: $local_deleted. Remote files deleted: $remote_deleted" );

		/*
		Disable until next release
		$error_messages = array();
		$storage_details = array();

		foreach ($deletion_errors as $instance => $entry) {
			$service = $entry['service'];

			if (!array_key_exists($service, $storage_details)) {
				// As errors from multiple instances of a service can be present, store the service storage object for possible use later
				$new_service = BackupPlus_Storage_Methods_Interface::get_storage_objects_and_ids(array($service));
				$storage_details = array_merge($storage_details, $new_service);
			}

			$intance_label = !empty($storage_details[$service]['instance_settings'][$instance]['instance_label']) ? $storage_details[$service]['instance_settings'][$instance]['instance_label'] : $service;

			switch ($entry['error_code']) {
				case 'authentication_fail':
					$error_messages[] = sprintf(__("The authentication failed for '%s'.", 'backupplus').' '.__('Please check your credentials.', 'backupplus'), $intance_label);
					break;
				case 'service_unavailable':
					$error_messages[] = sprintf(__("We were unable to access '%s'.", 'backupplus').' '.__('Service unavailable.', 'backupplus'), $intance_label);
					break;
				case 'container_access_error':
					$error_messages[] = sprintf(__("We were unable to access the folder/container for '%s'.", 'backupplus').' '.__('Please check your permissions.', 'backupplus'), $intance_label);
					break;
				case 'file_access_error':
					$error_messages[] = sprintf(__("We were unable to access a file on '%s'.", 'backupplus').' '.__('Please check your permissions.', 'backupplus'), $intance_label);
					break;
				case 'file_delete_error':
					$error_messages[] = sprintf(__("We were unable to delete a file on '%s'.", 'backupplus').' '.__('The file may no longer exist or you may not have permission to delete.', 'backupplus'), $intance_label);
					break;
				default:
					$error_messages[] = sprintf(__("An error occurred while attempting to delete from '%s'.", 'backupplus'), $intance_label);
					break;
			}
		}
		*/

		// $error_message_string = implode("\n", $error_messages);
		$error_message_string = '';

		if ( $delete_complete ) {
			$set_message    = __( 'Backup sets removed:', 'backupplus' );
			$local_message  = __( 'Local files deleted:', 'backupplus' );
			$remote_message = __( 'Remote files deleted:', 'backupplus' );

			if ( BackupPlus_Options::get_backupplus_option( 'backupplus_debug_mode' ) ) {
				restore_error_handler();
			}

			return array(
				'result'             => 'success',
				'set_message'        => $set_message,
				'local_message'      => $local_message,
				'remote_message'     => $remote_message,
				'backup_sets'        => $sets_removed,
				'backup_local'       => $local_deleted,
				'backup_remote'      => $remote_deleted,
				'deleted_timestamps' => $deleted_timestamps,
				'error_messages'     => $error_message_string,
			);
		} else {

			return array(
				'result'                 => 'continue',
				'backup_local'           => $local_deleted,
				'backup_remote'          => $remote_deleted,
				'backup_sets'            => $sets_removed,
				'timestamps'             => $timestamps,
				'deleted_timestamps'     => $deleted_timestamps,
				'error_messages'         => $error_message_string,
				'processed_instance_ids' => $processed_instance_ids,
			);
		}
	}

	/**
	 * Get the history status HTML and other information
	 *
	 * @param Boolean $rescan       - whether to rescan local storage
	 * @param Boolean $remotescan   - whether to also rescan remote storage
	 * @param Boolean $debug        - whether to return debugging information also
	 * @param Integer $backup_count - a count of the total backups we want to display on the front end for use by BackupPlus_Backup_History::existing_backup_table()
	 *
	 * @return Array - the information requested
	 */
	public function get_history_status( $rescan, $remotescan, $debug = false, $backup_count = 0 ) {

		global $backupplus;

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( BACKUPPLUS_SET_TIME_LIMIT ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}
		if ( $rescan ) {
			$messages = BackupPlus_Backup_History::rebuild( $remotescan, false, $debug );
		}
		$backup_history = BackupPlus_Backup_History::get_history();
		$output         = BackupPlus_Backup_History::existing_backup_table( $backup_history, $backup_count );

		$data = array();

		if ( ! empty( $messages ) && is_array( $messages ) ) {
			$noutput = '';
			foreach ( $messages as $msg ) {
				if ( is_string( $msg ) ) {
					$noutput .= '<li><em>' . $msg . '</em></li>';
				} else {
					if ( empty( $msg['code'] ) || 'file-listing' != $msg['code'] ) {
						$noutput .= '<li>' . ( empty( $msg['desc'] ) ? '' : $msg['desc'] . ': ' ) . '<em>' . $msg['message'] . '</em></li>';
					}
					if ( ! empty( $msg['data'] ) ) {
						$key          = $msg['method'] . '-' . $msg['service_instance_id'];
						$data[ $key ] = $msg['data'];
					}
				}
			}
			if ( $noutput ) {
				$output = '<div style="margin-left: 100px; margin-top: 10px;"><ul style="list-style: disc inside;">' . $noutput . '</ul></div>' . $output;
			}
		}

		$logs_exist = ( false !== strpos( $output, 'downloadlog' ) );
		if ( ! $logs_exist ) {
			list($mod_time, $log_file, $nonce) = $backupplus->last_modified_log();// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Unused parameter is present because the method returns an array.
			if ( $mod_time ) {
				$logs_exist = true;
			}
		}

		return apply_filters(
			'backupplus_get_history_status_result',
			array(
				'n'                     => __( 'Existing backups', 'backupplus' ) . ' <span class="backupplus_existing_backups_count">' . count( $backup_history ) . '</span>',
				't'                     => $output,  // table
				'data'                  => $data,
				'cksum'                 => md5( $output ),
				'logs_exist'            => $logs_exist,
				'web_server_disk_space' => BackupPlus_Filesystem_Functions::web_server_disk_space( true ),
			)
		);
	}

	/**
	 * This function will check that a backup is complete depending on the parameters passed in.
	 * A backup is complete in the case of a "full backup" when it contains everything the user has set in their options to be backed up.
	 * It can also check if the backup is local on the filesystem.
	 *
	 * @param array   $backup      - the backup array we want to check
	 * @param boolean $full_backup - a boolean to indicate if the backup should also be a full backup
	 * @param boolean $local       - a boolean to indicate if the backup should be present on the local file system or not
	 *
	 * @return boolean - returns true if the backup is complete and if specified is found on the local system otherwise false
	 */
	public function check_backup_is_complete( $backup, $full_backup, $local ) {

		global $backupplus;

		if ( empty( $backup ) ) {
			return false;
		}

		$entities = $backupplus->get_backupable_file_entities( true, true );

		// Add the database to the entities array ready to loop over
		$entities['db'] = '';

		foreach ( $entities as $key => $info ) {
			$included = BackupPlus_Credential_Key_Store::BACKUP_ENTITY === $key
				? BackupPlus_Backup_Sources::included( $key )
				: BackupPlus_Options::get_backupplus_option( "backupplus_include_$key", false );
			if ( ! $included ) {
				unset( $entities[ $key ] );
			}
		}

		$backupplus_dir = trailingslashit( $backupplus->backups_dir_location() );

		foreach ( $entities as $type => $info ) {

			if ( $full_backup ) {
				$included = BackupPlus_Credential_Key_Store::BACKUP_ENTITY === $type
					? BackupPlus_Backup_Sources::included( $type )
					: BackupPlus_Options::get_backupplus_option( "backupplus_include_$type", false );
				if ( $included && ! isset( $backup[ $type ] ) ) {
					return false;
				}
			}

			if ( ! isset( $backup[ $type ] ) ) {
				return false;
			}

			if ( $local ) {
				// Cast this to an array so that a warning is not thrown when we encounter a Database.
				foreach ( (array) $backup[ $type ] as $value ) {
					if ( ! file_exists( $backupplus_dir . DIRECTORY_SEPARATOR . $value ) ) {
						return false;
					}
				}
			}
		}

		return true;
	}

	/**
	 * This function will set up the backup job data for when we are uploading a local backup to remote storage. It changes the initial jobdata so that BackupPlus knows about what files it's uploading and so that it skips directly to the upload stage.
	 *
	 * @param array $jobdata - the initial job data that we want to change
	 * @param array $options - options sent from the front end includes backup timestamp and nonce
	 *
	 * @return array         - the modified jobdata
	 */
	public function upload_local_backup_jobdata( $jobdata, $options ) {
		global $backupplus;

		if ( ! is_array( $jobdata ) ) {
			return $jobdata;
		}

		$backup_history = BackupPlus_Backup_History::get_history();
		$services       = ! empty( $options['services'] ) ? $options['services'] : array();
		$backup         = $backup_history[ $options['use_timestamp'] ];

		/*
			The initial job data is not set up in a key value array instead it is set up so key "x" is the name of the key and then key "y" is the value.
			e.g array[0] = 'backup_name' array[1] = 'my_backup'
		*/
		$jobstatus_key       = array_search( 'jobstatus', $jobdata ) + 1;
		$backup_time_key     = array_search( 'backup_time', $jobdata ) + 1;
		$backup_database_key = array_search( 'backup_database', $jobdata ) + 1;
		$backup_files_key    = array_search( 'backup_files', $jobdata ) + 1;
		$service_key         = array_search( 'service', $jobdata ) + 1;

		$db_backups     = $jobdata[ $backup_database_key ];
		$db_backup_info = $backupplus->update_database_jobdata( $db_backups, $backup );
		$file_backups   = $backupplus->update_files_jobdata( $backup );

		// Next we need to build the services array using the remote storage destinations the user has selected to upload this backup set to
		$selected_services = array();
		if ( is_array( $services ) ) {
			foreach ( $services as $storage_info ) {
				$selected_services[] = $storage_info['value'];
			}
		} else {
			$selected_services = array( $services );
		}

		$jobdata[ $jobstatus_key ]       = 'clouduploading';
		$jobdata[ $backup_time_key ]     = $options['use_timestamp'];
		$jobdata[ $backup_files_key ]    = 'finished';
		$jobdata[]                       = 'backup_files_array';
		$jobdata[]                       = $file_backups;
		$jobdata[]                       = 'blog_name';
		$jobdata[]                       = $db_backup_info['blog_name'];
		$jobdata[ $backup_database_key ] = $db_backup_info['db_backups'];
		$jobdata[]                       = 'local_upload';
		$jobdata[]                       = true;
		if ( ! empty( $selected_services ) ) {
			$jobdata[ $service_key ] = $selected_services;
		}

		return $jobdata;
	}

	/**
	 * This function allows us to change the backup name, this is needed when uploading a local database backup to remote storage when the backup has come from another site.
	 *
	 * @param string $backup_name - the current name of the backup file
	 * @param string $use_time    - the current timestamp we are using
	 * @param string $blog_name   - the blog name of the current site
	 *
	 * @return string             - the new filename or the original if the blog name from the job data is not set
	 */
	public function upload_local_backup_name( $backup_name, $use_time, $blog_name ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Filter use
		global $backupplus;

		$backup_blog_name = $backupplus->jobdata_get( 'blog_name', '' );

		if ( '' != $blog_name && '' != $backup_blog_name ) {
			return str_replace( $blog_name, $backup_blog_name, $backup_name );
		}

		return $backup_name;
	}
}
