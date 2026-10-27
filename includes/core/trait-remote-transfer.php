<?php

/** Chunked transfers and the bookkeeping of what has been uploaded to remote storage. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Core_Remote_Transfer {

	/**
	 * This function is used by cloud methods to provide standardised logging, but more importantly to help us detect that meaningful activity took place during a resumption run, so that we can schedule further resumptions if it is worthwhile
	 *
	 * @param  Number  $percent   - the amount of the file uploaded
	 * @param  String  $extra     - anything extra to include in the log message
	 * @param  Boolean $file_path - the full path to the file being uploaded
	 * @param  Boolean $log_it    - whether to pass the message to BackupPlus::log()
	 * @return Void
	 */
	public function record_uploaded_chunk( $percent, $extra = '', $file_path = false, $log_it = true ) {

		// Touch the original file, which helps prevent overlapping runs
		if ( $file_path ) {
			touch( $file_path );
		}

		// What this means in effect is that at least one of the files touched during the run must reach this percentage (so lapping round from 100 is OK)
		if ( $percent > 0.7 * ( $this->current_resumption - max( $this->jobdata_get( 'uploaded_lastreset' ), 9 ) ) ) {
			BackupPlus_Job_Scheduler::something_useful_happened();
		}

		// Log it
		global $backupplus_backup;
		$log = empty( $backupplus_backup->current_service ) ? '' : ucfirst( $backupplus_backup->current_service ) . " chunked upload: $percent % uploaded";
		if ( $log && $log_it ) {
			$this->log( $log . ( $extra ? " ($extra)" : '' ) );
		}
		// If we are on an 'overtime' resumption run, and we are still meaningfully uploading, then schedule a new resumption
		// Our definition of meaningful is that we must maintain an overall average of at least 0.7% per run, after allowing 9 runs for everything else to get going
		// i.e. Max 100/.7 + 9 = 150 runs = 760 minutes = 12 hrs 40, if spaced at 5 minute intervals. However, our algorithm now decreases the intervals if it can, so this should not really come into play
		// If they get 2 minutes on each run, and the file is 1GB, then that equals 10.2MB/120s = minimum 59KB/s upload speed required

		$upload_status = $this->jobdata_get( 'uploading_substatus' );
		if ( is_array( $upload_status ) ) {
			$upload_status['p'] = $percent / 100;
			$this->jobdata_set( 'uploading_substatus', $upload_status );
		}
	}

	/**
	 * Method for helping remote storage methods to upload files in chunks without needing to duplicate all the overhead
	 *
	 * @param   Object  $caller        the object to call back to do the actual network API calls; needs to have a chunked_upload() method.
	 * @param   String  $file          the basename of the file
	 * @param   String  $cloudpath     this is passed back to the callback function; within this function, it is used only for logging
	 * @param   String  $logname       the prefix used on log lines. Also passed back to the callback function.
	 * @param   Integer $chunk_size    the size, in bytes, of each upload chunk
	 * @param   Integer $uploaded_size how many bytes have already been uploaded. This is passed back to the callback function; within this method, it is only used for logging.
	 * @param   Boolean $singletons    when the file, given the chunk size, would only have one chunk, should that be uploaded (true), or instead should 1 be returned (false) ?
	 * @return  Boolean
	 */
	public function chunked_upload( $caller, $file, $cloudpath, $logname, $chunk_size, $uploaded_size, $singletons = false ) {

		$fullpath       = $this->backups_dir_location() . '/' . $file;
		$orig_file_size = filesize( $fullpath );

		if ( $uploaded_size >= $orig_file_size && ! method_exists( $caller, 'chunked_upload_finish' ) ) {
			return true;
		}

		$chunks = floor( $orig_file_size / $chunk_size );
		// There will be a remnant unless the file size was exactly on a chunk boundary
		if ( $orig_file_size % $chunk_size > 0 ) {
			++$chunks;
		}

		$this->log( "$logname upload: $file (chunks: $chunks, of size: $chunk_size) -> $cloudpath ($uploaded_size)" );

		if ( 0 == $chunks ) {
			return 1;
		} elseif ( $chunks < 2 && ! $singletons ) {
			return 1;
		}

		// We have multiple chunks
		if ( $uploaded_size < $orig_file_size ) {

			if ( false == ( $fp = @fopen( $fullpath, 'rb' ) ) ) {// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
				$this->log( "$logname: failed to open file: $fullpath" );
				/* translators: %s: Log name */
				$this->log( "$file: " . sprintf( __( '%s Error: Failed to open local file', 'backupplus' ), $logname ), 'error' );
				return false;
			}

			$upload_start = 0;
			$upload_end   = -1;
			$chunk_index  = 1;
			// The file size minus one equals the byte offset of the final byte
			$upload_end           = min( $chunk_size - 1, $orig_file_size - 1 );
			$errors_on_this_chunk = 0;

			while ( $upload_start < $orig_file_size ) {

				// Don't forget the +1; otherwise the last byte is omitted
				$upload_size = $upload_end - $upload_start + 1;

				fseek( $fp, $upload_start );

				/*
				* Valid return values for $uploaded are many, as the possibilities have grown over time.
				* This could be cleaned up; but, it works, and it's not hugely complex.
				*
				* WP_Error : an error occured. The only permissible codes are: reduce_chunk_size (only on the first chunk), try_again
				* (bool)true : What was requested was done
				* (int)1 : What was requested was done, but do not log anything
				* (bool)false : There was an error
				* (Object) : Properties:
				*  (bool)log: (bool) - if absent, defaults to true
				*  (int)new_chunk_size: advisory amount for the chunk size for future chunks
				*  NOT IMPLEMENTED: (int)bytes_uploaded: Actual number of bytes uploaded (needs to be positive - o/w, should return an error instead)
				* N.B. Consumers should consult $fp and $upload_start to get data; they should not re-calculate from $chunk_index, which is not an indicator of file position.
				*/
				$uploaded = $caller->chunked_upload( $file, $fp, $chunk_index, $upload_size, $upload_start, $upload_end, $orig_file_size );

				// Try again? (Just once - added in 1.12.6 (can make more sophisticated if there is a need))
				if ( is_wp_error( $uploaded ) && 'try_again' == $uploaded->get_error_code() ) {
					// Arbitrary wait
					sleep( 3 );
					$this->log( 'Re-trying after wait (to allow apparent inconsistency to clear)' );
					$uploaded = $caller->chunked_upload( $file, $fp, $chunk_index, $upload_size, $upload_start, $upload_end, $orig_file_size );
				}

				// This is the only other supported case of a WP_Error - otherwise, a boolean must be returned
				// Note that this is only allowed on the first chunk. The caller is responsible to remember its chunk size if it uses this facility.
				if ( 1 == $chunk_index && is_wp_error( $uploaded ) && 'reduce_chunk_size' == $uploaded->get_error_code() && false != ( $new_chunk_size = $uploaded->get_error_data() ) && is_numeric( $new_chunk_size ) ) {
					$this->log( 'Re-trying with new chunk size: ' . $new_chunk_size );
					return $this->chunked_upload( $caller, $file, $cloudpath, $logname, $new_chunk_size, $uploaded_size, $singletons );
				}

				$uploaded_amount = $chunk_size;

				/*
				// Not using this approach for now. Instead, going to allow the consumers to increase the next chunk size
				if (is_object($uploaded) && isset($uploaded->bytes_uploaded)) {
					if (!$uploaded->bytes_uploaded) {
						$uploaded = false;
					} else {
						$uploaded_amount = $uploaded->bytes_uploaded;
						$uploaded = (!isset($uploaded->log) || $uploaded->log) ? true : 1;
					}
				}
				*/
				if ( is_object( $uploaded ) && isset( $uploaded->new_chunk_size ) ) {
					if ( $uploaded->new_chunk_size >= 1048576 ) {
						$new_chunk_size = $uploaded->new_chunk_size;
					}
					$uploaded = ( ! isset( $uploaded->log ) || $uploaded->log ) ? true : 1;
				}

				// The joys of WP/PHP: is_wp_error() is not false-y.
				if ( $uploaded && ! is_wp_error( $uploaded ) ) {
					$perc = round( 100 * ( $upload_end + 1 ) / max( $orig_file_size, 1 ), 1 );
					// Consumers use a return value of (int)1 (rather than (bool)true) to suppress logging
					$log_it = ( 1 === $uploaded ) ? false : true;
					$this->record_uploaded_chunk( $perc, $chunk_index, $fullpath, $log_it );

					// $uploaded_bytes = $upload_end + 1;

					// If there was an error, then we re-try the same chunk; we don't move on to the next one. Otherwise, we would need more code to handle potential 'intermediate' failed chunks (in case PHP dies before this method eventually returns false, and thus the intermediate chunk failure never gets detected)
					++$chunk_index;
					$errors_on_this_chunk = 0;
					$upload_start         = $upload_end + 1;
					$upload_end          += isset( $new_chunk_size ) ? $uploaded_amount + $new_chunk_size - $chunk_size : $uploaded_amount;
					$upload_end           = min( $upload_end, $orig_file_size - 1 );

				} else {

					++$errors_on_this_chunk;

					// Either $uploaded is false-y, or is a WP_Error
					if ( is_wp_error( $uploaded ) ) {
						$this->log( "$logname: Chunk upload ($chunk_index) failed (" . $uploaded->get_error_code() . '): ' . $uploaded->get_error_message() );
					} else {
						$this->log( "$logname: Chunk upload ($chunk_index) failed" );
					}

					if ( $errors_on_this_chunk >= 3 ) {
						@fclose( $fp );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
						return false;
					}
				}
			}

			@fclose( $fp );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.

		}

		// All chunks are uploaded - now combine the chunks
		$ret = true;

		// The action calls here exist to aid debugging
		if ( method_exists( $caller, 'chunked_upload_finish' ) ) {
			do_action( 'backupplus_pre_chunked_upload_finish', $file, $caller );
			$ret = $caller->chunked_upload_finish( $file );
			if ( ! $ret ) {
				$this->log( "$logname - failed to re-assemble chunks" );
				/* translators: %s: Log name */
				$this->log( sprintf( __( '%s error - failed to re-assemble chunks', 'backupplus' ), $logname ), 'error' );
			}
			do_action( 'backupplus_post_chunked_upload_finish', $file, $caller, $ret );
		}

		if ( $ret ) {
			// We allow chunked_upload_finish to return (int)1 to indicate that it took care of any logging.
			if ( true === $ret ) {
				$this->log( "$logname upload: success" );
			}
			$ret = true;
			// Remote storage integrations call this themselves.
			if ( ! is_a( $caller, 'BackupPlus_RemoteStorage_Addons_Base_v2' ) ) {
				$this->uploaded_file( $file );
			}
		}

		return $ret;
	}

	/**
	 * Provides a convenience function allowing remote storage methods to download a file in chunks, without duplicated overhead.
	 *
	 * @param String  $file              - The basename of the file being downloaded
	 * @param Object  $method            - This remote storage method object needs to have a chunked_download() method to call back
	 * @param Integer $remote_size       - The size, in bytes, of the object being downloaded
	 * @param Boolean $manually_break_up - Whether to break the download into multiple network operations (rather than just issuing a GET with a range beginning at the end of the already-downloaded data, and carrying on until it times out)
	 * @param Mixed   $passback          - A value to pass back to the callback function
	 * @param Integer $chunk_size        - Break up the download into chunks of this number of bytes. Should be set if and only if $manually_break_up is true.
	 */
	public function chunked_download( $file, $method, $remote_size, $manually_break_up = false, $passback = null, $chunk_size = 1048576 ) {

		try {

			$fullpath     = $this->backups_dir_location() . '/' . $file;
			$start_offset = file_exists( $fullpath ) ? filesize( $fullpath ) : 0;

			if ( $start_offset >= $remote_size ) {
				$this->log( "File is already completely downloaded ($start_offset/$remote_size)" );
				return true;
			}

			// Some more remains to download - so let's do it
			// N.B. We use ftell(), which precludes us from using open in append-only ('a') mode - see https://php.net/manual/en/function.fopen.php
			if ( ! ( $fh = fopen( $fullpath, 'c+' ) ) ) {
				$this->log( "Error opening local file: $fullpath" );
				$this->log( $file . ': ' . __( 'Error', 'backupplus' ) . ': ' . __( 'Error opening local file: Failed to download', 'backupplus' ), 'error' );
				return false;
			}

			$last_byte = ( $manually_break_up ) ? min( $remote_size, $start_offset + $chunk_size ) : $remote_size;

			// This only affects logging
			$expected_bytes_delivered_so_far = true;

			while ( $start_offset < $remote_size ) {
				$headers = array();
				// If resuming, then move to the end of the file

				$requested_bytes = $last_byte - $start_offset;

				if ( $expected_bytes_delivered_so_far ) {
					$this->log( "$file: local file is status: $start_offset/$remote_size bytes; requesting next $requested_bytes bytes" );
				} else {
					$this->log( "$file: local file is status: $start_offset/$remote_size bytes; requesting next chunk ({$start_offset}-)" );
				}

				if ( $start_offset > 0 || $last_byte < $remote_size ) {
					fseek( $fh, $start_offset );
					// N.B. Don't alter this format without checking what relies upon it
					$last_byte_start  = $last_byte - 1;
					$headers['Range'] = "bytes=$start_offset-$last_byte_start";
				}

				/*
				* The most common method is for the remote storage module to return a string with the results in it. In that case, the final $fh parameter is unused. However, since not all SDKs have that option conveniently, it is also possible to use the file handle and write directly to that; in that case, the method can either return the number of bytes written, or (boolean)true to infer it from the new file *pointer*.
				* The method is free to write/return as much data as it pleases.
				*/
				$ret = $method->chunked_download( $file, $headers, $passback, $fh );
				if ( true === $ret ) {
					clearstatcache();
					// Some SDKs (including AWS/S3) close the resource
					// N.B. We use ftell(), which precludes us from using open in append-only ('a') mode - see https://php.net/manual/en/function.fopen.php
					if ( is_resource( $fh ) ) {
						$ret = ftell( $fh );
					} else {
						$ret = filesize( $fullpath );
						// fseek returns - on success
						if ( false == ( $fh = fopen( $fullpath, 'c+' ) ) || 0 !== fseek( $fh, $ret ) ) {
							$this->log( "Error opening local file: $fullpath" );
							$this->log( $file . ': ' . __( 'Error', 'backupplus' ) . ': ' . __( 'Error opening local file: Failed to download', 'backupplus' ), 'error' );
							return false;
						}
					}
					if ( is_integer( $ret ) ) {
						$ret -= $start_offset;
					}
				}

				// Note that this covers a false code returned either by chunked_download() or by ftell.
				if ( false === $ret ) {
					return false;
				}

				$returned_bytes = is_integer( $ret ) ? $ret : strlen( $ret );

				if ( $returned_bytes > $requested_bytes || $returned_bytes < $requested_bytes - 1 ) {
					$expected_bytes_delivered_so_far = false;
				}

				if ( ! is_integer( $ret ) && ! fwrite( $fh, $ret ) ) {
					throw new Exception( 'Write failure (start offset: ' . $start_offset . ', bytes: ' . strlen( $ret ) . '; requested: ' . $requested_bytes . ')' );
				}

				clearstatcache();
				$start_offset = ftell( $fh );
				$last_byte    = ( $manually_break_up ) ? min( $remote_size, $start_offset + $chunk_size ) : $remote_size;

			}
		} catch ( Exception $e ) {
			$this->log( 'Error (' . get_class( $e ) . ') - failed to download the file (' . $e->getCode() . ', ' . $e->getMessage() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')' );
			$this->log( "$file: " . __( 'Error - failed to download the file', 'backupplus' ) . ' (' . $e->getCode() . ', ' . $e->getMessage() . ')', 'error' );
			return false;
		}

		$method->download_complete( $fh, $fullpath );

		fclose( $fh );

		return true;
	}

	/**
	 * The purpose of this function is to abstract away historical discrepancies in service lists, by returning in a single, logical form (in particular, no 'none' or '' entries, and always an array)
	 *
	 * @param Array|String|Boolean|Null $services - a list of services to canonicalize, or a string indicating a single service. If null is parsed, then the saved settings will be read.
	 *
	 * @return Array - an array of service names. All service names will be non-empty strings, and 'none' will not feature. If there are no services, then the array will be empty.
	 */
	public function get_canonical_service_list( $services = null ) {

		if ( null === $services ) {
			$services = BackupPlus_Options::get_backupplus_option( 'backupplus_service' );
		}

		$services = (array) $services;

		foreach ( $services as $key => $service ) {
			if ( '' === $service || 'none' === $service || false === $service ) {
				unset( $services[ $key ] );
			}
		}

		return $services;
	}

	/**
	 * This should be called whenever a file is successfully uploaded
	 *
	 * @param  String  $file  - full filepath
	 * @param  Boolean $force - mark as successfully uploaded even if not on the last service
	 * @return Void
	 */
	public function uploaded_file( $file, $force = false ) {

		global $backupplus_backup;

		$db_connected = $this->check_db_connection( false, true, true );

		$service     = empty( $backupplus_backup->current_service ) ? '' : $backupplus_backup->current_service;
		$instance_id = empty( $backupplus_backup->current_instance ) ? '' : $backupplus_backup->current_instance;
		$shash       = $service . ( ( '' == $service ) ? '' : '-' ) . $instance_id . ( ( '' == $instance_id ) ? '' : '-' ) . md5( $file );

		if ( $force || ! empty( $backupplus_backup->last_storage_instance ) ) {
			$this->log( "Recording as successfully uploaded: $file" );
			$new_jobdata = $this->get_uploaded_jobdata_items( $file, $service, $instance_id );
		} else {
			$new_jobdata = array( 'uploaded_' . $shash => 'yes' );
			$this->log( "Recording as successfully uploaded: $file (" . $backupplus_backup->current_service . ', more services to follow)' );
		}

		$upload_status = $this->jobdata_get( 'uploading_substatus' );
		if ( is_array( $upload_status ) && isset( $upload_status['i'] ) ) {
			++$upload_status['i'];
			$upload_status['p']                 = 0;
			$new_jobdata['uploading_substatus'] = $upload_status;
		}

		$this->jobdata_set_multi( $new_jobdata );

		// Really, we could do this immediately when we realise the DB has gone away. This is just for the probably-impossible case that a DB write really can still succeed. But, we must abort before calling delete_local(), as the removal of the local file can cause it to be recreated if the DB is out of sync with the fact that it really is already uploaded
		if ( false === $db_connected ) {
			BackupPlus_Job_Scheduler::record_still_alive();
			die;
		}

		if ( class_exists( 'BackupPlus_Storage_Limits' ) && BackupPlus_Storage_Limits::enabled() && ! $this->jobdata_get( 'storage_limits_measured_before_upload_deletion' ) ) {
			BackupPlus_Storage_Limits::run( null, false, true );
			$this->jobdata_set( 'storage_limits_measured_before_upload_deletion', true );
		}

		// Delete local files immediately if the option is set
		// Where we are only backing up locally, only the "prune" function should do deleting
		$service = $this->jobdata_get( 'service' );
		if ( ! empty( $backupplus_backup->last_storage_instance ) && ( '' !== $service && ( ( is_array( $service ) && count( $service ) > 0 && ( count( $service ) > 1 || ( array( '' ) !== $service && array( 'none' ) !== $service ) ) ) || ( is_string( $service ) && 'none' !== $service ) ) ) ) {
			$this->delete_local( $file );
		}
	}

	/**
	 * Gets the jobdata items to be added to mark a file as uploaded
	 *
	 * @param String $file        - the file (basename)
	 * @param String $service     - service identifier
	 * @param String $instance_id - instance identifier
	 *
	 * @return Array - jobdata items
	 */
	public function get_uploaded_jobdata_items( $file, $service = '', $instance_id = '' ) {
		$hash  = md5( $file );
		$shash = $service . ( ( '' == $service ) ? '' : '-' ) . $instance_id . ( ( '' == $instance_id ) ? '' : '-' ) . md5( $file );
		return array(
			'uploaded_lastreset' => $this->current_resumption,
			'uploaded_' . $hash  => 'yes',
			'uploaded_' . $shash => 'yes',
		);
	}

	/**
	 * Return whether a particular file has been uploaded to a particular remote service
	 *
	 * @param String $file        - the filename (basename)
	 * @param String $service     - the service identifier; or none, to indicate all services
	 * @param String $instance_id - the instance identifier
	 *
	 * @return Boolean - the result
	 */
	public function is_uploaded( $file, $service = '', $instance_id = '' ) {
		$hash = $service . ( ( '' == $service ) ? '' : '-' ) . $instance_id . ( ( '' == $instance_id ) ? '' : '-' ) . md5( $file );
		return ( 'yes' === $this->jobdata_get( "uploaded_$hash" ) ) ? true : false;
	}

	/**
	 * This function will mark the passed in service and instance id upload as complete
	 *
	 * @param String $service     - the service identifier
	 * @param String $instance_id - the instance identifier
	 *
	 * @return void
	 */
	public function mark_upload_complete( $service, $instance_id = '' ) {

		$upload_completed = $this->jobdata_get( 'upload_completed', array() );

		if ( empty( $instance_id ) ) {
			$upload_completed[ $service ] = 1;
		} else {
			if ( ! is_array( $upload_completed[ $service ] ) ) {
				$upload_completed[ $service ] = array();
			}
			$upload_completed[ $service ][ $instance_id ] = 1;
		}

		$this->jobdata_set( 'upload_completed', $upload_completed );
	}

	/**
	 * This function will check all the remote storage options for this job and ensure that each has completed the upload, if they have mark them as done if they have not completed then call upload_completed() for that service if it exists, otherwise mark as complete.
	 *
	 * @return boolean
	 */
	private function check_upload_completed() {

		$job_services  = $this->jobdata_get( 'service' );
		$services      = $this->get_canonical_service_list( $job_services );
		$sent_to_cloud = empty( $services ) ? false : true;

		if ( ! $sent_to_cloud ) {
			return;
		}

		$storage_objects_and_ids = BackupPlus_Storage_Methods_Interface::get_storage_objects_and_ids( $services );

		foreach ( $services as $service ) {

			if ( 'email' == $service || 'none' == $service || ! $service ) {
				continue;
			}

			if ( empty( $storage_objects_and_ids[ $service ]['object'] ) ) {
				continue;
			}
			$remote_obj       = $storage_objects_and_ids[ $service ]['object'];
			$upload_completed = $this->jobdata_get( 'upload_completed', array() );

			if ( isset( $upload_completed[ $service ] ) && ! is_array( $upload_completed[ $service ] ) ) {
				continue;
			}

			if ( ! empty( $remote_obj ) && ! $remote_obj->supports_feature( 'multi_options' ) ) {

				if ( is_callable( array( $remote_obj, 'upload_completed' ) ) ) {
					$result = $remote_obj->upload_completed();
					if ( $result ) {
						$this->mark_upload_complete( $service );
					}
				} else {
					$this->mark_upload_complete( $service );
				}
			} elseif ( ! empty( $storage_objects_and_ids[ $service ]['instance_settings'] ) ) {

				foreach ( $storage_objects_and_ids[ $service ]['instance_settings'] as $instance_id => $options ) {

					if ( isset( $upload_completed[ $service ][ $instance_id ] ) ) {
						continue;
					}

					$remote_obj->set_options( $options, true, $instance_id );
					if ( is_callable( array( $remote_obj, 'upload_completed' ) ) ) {
						$remote_obj->upload_completed();
					} else {
						$this->mark_upload_complete( $service, $instance_id );
					}
				}
			}
		}
	}

	/**
	 * This function will check if the passed-in file is this job's responsibility to upload. Potentially files can belong to a different job, when running an incremental backup run
	 *
	 * @param String $file - the name of the file
	 * @param String $type - the file entity type (db, plugins, themes etc)
	 *
	 * @return boolean - whether this is a file this job should upload (at some point)
	 */
	private function is_ours_to_upload( $file, $type ) {

		if ( 'db' == $type ) {
			return false;
		}

		$previous_backup_files_array = $this->jobdata_get( 'previous_backup_files_array', array() );

		if ( isset( $previous_backup_files_array[ $type ] ) && in_array( $file, $previous_backup_files_array[ $type ] ) ) {
			return false;
		}

		return true;
	}

	private function delete_local( $file ) {
		$log = "Deleting local file: $file: ";
		if ( class_exists( 'BackupPlus_Storage_Limits' ) && BackupPlus_Storage_Limits::quota() ) {
			$this->log( 'Retaining local archive for local disk quota policy: ' . $file );
			return;
		}
		if ( BackupPlus_Options::get_backupplus_option( 'backupplus_delete_local', 0 ) ) {
			$fullpath = $this->backups_dir_location() . '/' . $file;

			// check to make sure it exists before removing
			if ( realpath( $fullpath ) ) {
				$deleted = unlink( $fullpath );
				$this->log( $log . ( ( $deleted ) ? 'OK' : 'failed' ) );
				return $deleted;
			}
		} else {
			$this->log( $log . 'skipped: user has unchecked backupplus_delete_local option' );
		}
		return true;
	}

	/**
	 * Acts as a WordPress options filter
	 *
	 * @param Array  $options     - An array of options
	 * @param String $option_name - The option name
	 *
	 * @return Array - the returned array can either be the set of updated options or a WordPress error array
	 */
	public function storage_options_filter( $options, $option_name ) {
		if ( 'backupplus_' !== substr( $option_name, 0, 8 ) ) {
			return $options;
		}
		$method = substr( $option_name, 8 );

		$storage = BackupPlus_Storage_Methods_Interface::get_storage_object( $method );

		if ( ! is_a( $storage, 'BackupPlus_BackupModule' ) || ! is_callable( array( $storage, 'options_filter' ) ) ) {
			return $options;
		}

		return call_user_func( array( $storage, 'options_filter' ), $options );
	}

	public function spool_file( $fullpath, $encryption = '' ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 900 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}
		if ( ! file_exists( $fullpath ) || filesize( $fullpath ) < 1 ) {
			esc_html_e( 'File not found', 'backupplus' );
			return;
		}

		// Prevent any debug output
		// Don't enable this line - it causes 500 HTTP errors in some cases/hosts on some large files, for unknown reason
		// @ini_set('display_errors', '0');

		// Ordinary backup downloads preserve ciphertext. Decryption has its own
		// authorized POST handler and never takes a key from a download URL.
		$content_type = BackupPlus_Encryption::is_file_encrypted( $fullpath ) ? 'application/octet-stream' : BackupPlus_Manipulation_Functions::get_mime_type_from_filename( $fullpath, false );

		backupplus_try_include_file( 'includes/class-partialfileservlet.php', 'include_once' );

		// Prevent the file being read into memory
		if ( ob_get_level() ) {
			$flush_max = min( 5, (int) ob_get_level() );
			for ( $i = 1; $i <= $flush_max; $i++ ) {
				@ob_end_clean();// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			}
		}
		if ( ob_get_level() ) {
			@ob_end_clean(); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged --Twice - see HS#6673 - someone at least needed it
		}
		if ( isset( $_SERVER['HTTP_RANGE'] ) ) {
			$range_header = trim( BackupPlus_Manipulation_Functions::fetch_superglobal( 'server', 'HTTP_RANGE' ) );
		} elseif ( function_exists( 'apache_request_headers' ) ) {
			foreach ( apache_request_headers() as $name => $value ) {
				if ( strtoupper( $name ) === 'RANGE' ) {
					$range_header = trim( $value );
				}
			}
		}

		if ( empty( $range_header ) ) {
			header( 'Content-Length: ' . filesize( $fullpath ) );
			header( "Content-type: $content_type" );
			header( 'Content-Disposition: attachment; filename="' . basename( $fullpath ) . '";' );
			readfile( $fullpath );
			return;
		}

		try {
			$range_header = BackupPlus_RangeHeader::createFromHeaderString( $range_header );
			$servlet      = new BackupPlus_PartialFileServlet( $range_header );
			$servlet->sendFile( $fullpath, $content_type );
		} catch ( BackupPlus_InvalidRangeHeaderException $e ) {
			header( 'HTTP/1.1 400 Bad Request' );
			BackupPlus_Manipulation_Functions::error_log( 'BackupPlus: BackupPlus_InvalidRangeHeaderException: ' . $e->getMessage() );
		} catch ( BackupPlus_UnsatisfiableRangeException $e ) {
			header( 'HTTP/1.1 416 Range Not Satisfiable' );
		} catch ( BackupPlus_NonExistentFileException $e ) {
			header( 'HTTP/1.1 404 Not Found' );
		} catch ( BackupPlus_UnreadableFileException $e ) {
			header( 'HTTP/1.1 500 Internal Server Error' );
		}
	}
}
