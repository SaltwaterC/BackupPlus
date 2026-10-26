<?php

/** Sending finished archives to remote storage. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Backup_Cloud {

	/**
	 * This function will check if backup archives exist and have a usable manifest if so it will attempt to send them for upload
	 *
	 * @param String  $file     - the name of the zip file
	 * @param String  $whichone - the entity type
	 * @param Integer $index    - the entity index
	 *
	 * @return void
	 */
	private function maybe_cloud_backup( $file, $whichone, $index ) {

		global $backupplus;
		// Encrypted jobs publish all ZIP parts only after encryption is complete.
		if ( $this->archive_encryption_enabled() ) {
			return;
		}

		// Check if the feature is enabled
		if ( ! defined( 'BACKUPPLUS_UPLOAD_AFTER_CREATE' ) || BACKUPPLUS_UPLOAD_AFTER_CREATE ) {

			if ( $backupplus->is_uploaded( $file ) || ! is_file( $this->backupplus_dir . '/' . $file ) ) {
				return;
			}

			$backupplus->jobdata_set( 'filesize-' . $whichone . $index, filesize( $this->backupplus_dir . '/' . $file ) );

			$backupable_entities = $backupplus->get_backupable_file_entities( true );
			$undone_files        = array();

			if ( ! isset( $backupable_entities[ $whichone ] ) && ( 'db' != substr( $whichone, 0, 2 ) ) ) {
				return;
			}

			$manifest = $this->backupplus_dir . '/' . $file . '.list.tmp';

			if ( ! file_exists( $manifest ) ) {
				return;
			}

			$manifest_contents = json_decode( file_get_contents( $manifest ), true );

			if ( empty( $manifest_contents ) || empty( $manifest_contents['files'] ) ) {
				return;
			}

			$undone_files[ $whichone . $index ] = $file;

			$this->cloud_backup( $undone_files, 'partialclouduploading' );

			// reset the jobstatus as we may have just uploaded a backup set
			$backupplus->jobdata_set( 'jobstatus', 'filescreating' );
		}
	}

	/**
	 * Dispatch to the relevant function
	 *
	 * @param Array  $backup_array - List of archives for the backup
	 * @param String $stage        - The stage we are uploading at (clouduploading, partialclouduploading)
	 */
	public function cloud_backup( $backup_array, $stage = 'clouduploading' ) {

		global $backupplus;
		if ( $this->archive_encryption_enabled() ) {
			foreach ( $backup_array as $file ) {
				if ( is_string( $file ) && preg_match( '/\.zip$/i', $file ) ) {
					throw new RuntimeException( 'Unencrypted file archive refused by backup dispatch.' );
				}
			}
		}

		$services                 = (array) ( $backupplus->jobdata_get( 'pre_update_batch' ) ? $backupplus->jobdata_get( 'service' ) : $backupplus->just_one( $backupplus->jobdata_get( 'service' ) ) );
		$remote_storage_instances = $backupplus->jobdata_get( 'remote_storage_instances', array() );

		// We need to make sure that the loop below actually runs
		if ( empty( $services ) ) {
			$services = array( 'none' );
		}

		$storage_objects_and_ids = BackupPlus_Storage_Methods_Interface::get_enabled_storage_objects_and_ids( $services, $remote_storage_instances );

		$total_instances_count = 0;

		foreach ( $storage_objects_and_ids as $service ) {
			if ( $service['object']->supports_feature( 'multi_options' ) ) {
				$total_instances_count += count( $service['instance_settings'] );
			}
		}

		$backupplus->jobdata_set( 'jobstatus', $stage );

		$backupplus->register_wp_http_option_hooks();

		$upload_status = $backupplus->jobdata_get( 'uploading_substatus' );
		if ( ! is_array( $upload_status ) || ! isset( $upload_status['t'] ) ) {
			$upload_status = array(
				'i' => 0,
				'p' => 0,
				't' => max( 1, $total_instances_count ) * count( $backup_array ),
			);
			$backupplus->jobdata_set( 'uploading_substatus', $upload_status );
		} elseif ( is_array( $upload_status ) && isset( $upload_status['t'] ) ) {
			$upload_status['t'] = $upload_status['i'] + max( 1, $total_instances_count ) * count( $backup_array );
			$backupplus->jobdata_set( 'uploading_substatus', $upload_status );
		}

		$do_prune = array();

		// If there was no check-in last time, then attempt a different service first - in case a time-out on the attempted service leads to no activity and everything stopping
		if ( count( $services ) > 1 && $backupplus->no_checkin_last_time ) {
			$backupplus->log( 'No check-in last time: will try a different remote service first' );
			array_push( $services, array_shift( $services ) );
			// Make sure that the 'no worthwhile activity' detector isn't flumoxed by the starting of a new upload at 0%
			if ( $backupplus->current_resumption > 9 ) {
				$backupplus->jobdata_set( 'uploaded_lastreset', $backupplus->current_resumption );
			}
			if ( 1 == ( $backupplus->current_resumption % 2 ) && count( $services ) > 2 ) {
				array_push( $services, array_shift( $services ) );
			}
		}

		$errors_before_uploads = $backupplus->error_count();

		foreach ( $services as $ind => $service ) {
			if ( 'none' !== $service && '' !== $service && empty( $storage_objects_and_ids[ $service ]['object'] ) ) {
				$backupplus->log( sprintf( __( 'Remote storage provider unavailable: %s', 'backupplus' ), $service ), 'error' );
				continue;
			}
			try {
				$instance_id_count  = 0;
				$total_instance_ids = ( 'none' !== $service && '' !== $service && $storage_objects_and_ids[ $service ]['object']->supports_feature( 'multi_options' ) ) ? count( $storage_objects_and_ids[ $service ]['instance_settings'] ) : 1;

				// Used for logging by record_upload_chunk()
				$this->current_service  = $service;
				$this->current_instance = ''; // Legacy providers must not inherit the preceding provider's instance ID.

				// Used when deciding whether to delete the local file
				$this->last_storage_instance = ( $ind + 1 >= count( $services ) && $instance_id_count + 1 >= $total_instance_ids && $errors_before_uploads == $backupplus->error_count() ) ? true : false;
				$log_extra                   = $this->last_storage_instance ? ' (last)' : '';
				$backupplus->log( 'Cloud backup selection (' . ( $ind + 1 ) . '/' . count( $services ) . '): ' . $service . ' with instance (' . ( $instance_id_count + 1 ) . '/' . $total_instance_ids . ')' . $log_extra );
				if ( function_exists( 'set_time_limit' ) ) {
					@set_time_limit( BACKUPPLUS_SET_TIME_LIMIT );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
				}
				if ( 'none' == $service || '' == $service ) {
					$backupplus->log( 'No remote despatch: user chose no remote backup service' );
					// Still want to mark as "uploaded", to signal that nothing more needs doing. (Important on incremental runs with no cloud storage).
					foreach ( $backup_array as $file ) {
						if ( $backupplus->is_uploaded( $file ) ) {
							$backupplus->log( "Already uploaded: $file" );
						} else {
							$backupplus->uploaded_file( $file, true );
						}
						$fullpath = $this->backupplus_dir . '/' . $file;
						if ( file_exists( $fullpath . '.list.tmp' ) ) {
							$backupplus->log( "Deleting zip manifest ({$file}.list.tmp)" );
							unlink( $fullpath . '.list.tmp' );
						}
					}
					$this->prune_retained_backups( array( 'none' => array( 'all' => array( null, null ) ) ) );
				} elseif ( ! empty( $storage_objects_and_ids[ $service ]['object'] ) && ! $storage_objects_and_ids[ $service ]['object']->supports_feature( 'multi_options' ) ) {
					$remote_obj = $storage_objects_and_ids[ $service ]['object'];

					$do_prune = array_merge_recursive( $do_prune, $this->upload_cloud( $remote_obj, $service, $backup_array, '' ) );
				} elseif ( ! empty( $storage_objects_and_ids[ $service ]['instance_settings'] ) ) {
					foreach ( $storage_objects_and_ids[ $service ]['instance_settings'] as $instance_id => $options ) {

						if ( $instance_id_count > 0 ) {
							$this->last_storage_instance = ( $ind + 1 >= count( $services ) && $instance_id_count + 1 >= $total_instance_ids && $errors_before_uploads == $backupplus->error_count() ) ? true : false;
							$log_extra                   = $this->last_storage_instance ? ' (last)' : '';
							$backupplus->log( 'Cloud backup selection (' . ( $ind + 1 ) . '/' . count( $services ) . '): ' . $service . ' with instance (' . ( $instance_id_count + 1 ) . '/' . $total_instance_ids . ')' . $log_extra );
						}

						// Used for logging by record_upload_chunk()
						$this->current_instance = $instance_id;

						if ( ! isset( $options['instance_enabled'] ) ) {
							$options['instance_enabled'] = 1;
						}

						// if $remote_storage_instances is not empty then we are looping over a list of instances the user wants to backup to so we want to ignore if the instance is enabled or not
						if ( 1 == $options['instance_enabled'] || ! empty( $remote_storage_instances ) ) {
							$remote_obj = $storage_objects_and_ids[ $service ]['object'];
							$remote_obj->set_options( $options, true, $instance_id );
							$do_prune = array_merge_recursive( $do_prune, $this->upload_cloud( $remote_obj, $service, $backup_array, $instance_id ) );
						} else {
							$backupplus->log( "This instance id ($instance_id) is set as inactive." );
						}

						++$instance_id_count;
					}
				}
			} catch ( Exception $e ) {
				$log_message = 'Exception (' . get_class( $e ) . ') occurred during backup uploads to the ' . $service . '. Exception Message: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
				$backupplus->log( $log_message );
				BackupPlus_Manipulation_Functions::error_log( $log_message );
				/* translators: 1: Exception class, 2: Exception message */
				$backupplus->log( sprintf( __( 'A PHP exception (%1$s) has occurred: %2$s', 'backupplus' ), get_class( $e ), $e->getMessage() ), 'error' );
			} catch ( Error $e ) {// phpcs:ignore PHPCompatibility.Classes.NewClasses.errorFound -- This Error class will only get triggered during runtime but we don't explicitly throw this class in our code; so we only catch it when PHP throws it.
				$log_message = 'PHP Fatal error (' . get_class( $e ) . ') has occurred during backup uploads to the ' . $service . '. Error Message: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
				$backupplus->log( $log_message );
				BackupPlus_Manipulation_Functions::error_log( $log_message );
				/* translators: 1: Exception class, 2: Exception message */
				$backupplus->log( sprintf( __( 'A PHP fatal error (%1$s) has occurred: %2$s', 'backupplus' ), get_class( $e ), $e->getMessage() ), 'error' );
			}
		}

		if ( ! empty( $do_prune ) && 'clouduploading' == $stage ) {
			$this->prune_retained_backups( $do_prune );
		}

		$backupplus->register_wp_http_option_hooks( false );
	}

	/**
	 * This method will start the upload of the backups to the chosen remote storage method and return an array of files to be pruned and their location.
	 *
	 * @param  Object $remote_obj   - the remote storage object
	 * @param  String $service      - the name of the service we are uploading to
	 * @param  Array  $backup_array - an array that contains the backup files we want to upload
	 * @param  String $instance_id  - the instance id we are using
	 * @return Array                - an array with information about what files to prune and where they are located
	 */
	private function upload_cloud( $remote_obj, $service, $backup_array, $instance_id ) {

		global $backupplus;

		$do_prune = array();

		if ( '' == $instance_id ) {
			$backupplus->log( "Beginning dispatch of backup to remote ($service)" );
		} else {
			$backupplus->log( "Beginning dispatch of backup to remote ($service) (instance identifier $instance_id)" );
		}

		$errors_before_uploads = $backupplus->error_count();

		$sarray = array();
		foreach ( $backup_array as $bind => $file ) {
			if ( $backupplus->is_uploaded( $file, $service, $instance_id ) ) {
				if ( '' == $instance_id ) {
					$backupplus->log( "Already uploaded to $service: $file", 'notice', false, true );
				} else {
					$backupplus->log( "Already uploaded to $service / $instance_id: $file", 'notice', false, true );
				}
				// If this is the last instance and this file has already been uploaded then we need to mark it as complete in order to get the local file cleaned up.
				if ( ! empty( $this->last_storage_instance ) ) {
					$backupplus->uploaded_file( $file );
				}
			} else {
				$sarray[ $bind ] = $file;
			}
		}

		if ( count( $sarray ) > 0 ) {
			$pass_to_prune                        = $remote_obj->backup( $sarray );
			$do_prune[ $service ][ $instance_id ] = array( $remote_obj, $pass_to_prune );

			// Check there are no errors in the uploads, if none then call upload_completed() if it exists otherwise mark as complete
			if ( $errors_before_uploads == $backupplus->error_count() ) {
				if ( is_callable( array( $remote_obj, 'upload_completed' ) ) ) {
					$result = $remote_obj->upload_completed();
					if ( $result ) {
						$backupplus->mark_upload_complete( $service );
					}
				} else {
					$backupplus->mark_upload_complete( $service );
				}
			}
		} else {
			// We still need to make sure that prune is run on this remote storage method, even if all entities were previously uploaded
			$do_prune[ $service ]['all'] = array( $remote_obj, null );
		}

		return $do_prune;
	}
}
