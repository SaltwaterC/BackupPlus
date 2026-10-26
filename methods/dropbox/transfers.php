<?php

/** Uploading backup files to and downloading them from Dropbox, including chunk size tuning. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Dropbox_Transfers {

	/**
	 * This callback is called as upload progress is made
	 *
	 * @param Integer        $offset   - the byte offset
	 * @param String         $uploadid - identifier for the upload in progress
	 * @param Boolean|String $fullpath - optional full path to the file being uploaded
	 */
	public function chunked_callback( $offset, $uploadid, $fullpath = false ) {

		global $backupplus;

		$storage = $this->get_storage();

		// Update upload ID
		$this->jobdata_set( 'upload_id_' . $this->current_file_hash, $uploadid );
		$this->jobdata_set( 'upload_offset_' . $this->current_file_hash, $offset );

		$time_now = microtime( true );

		$time_since_last_tick = $time_now - $this->upload_tick;
		$data_since_last_tick = $offset - $this->uploaded_offset;

		$this->upload_tick     = $time_now;
		$this->uploaded_offset = $offset;

		// Here we use job-wide data, because we don't expect wildly different performance for different Dropbox accounts
		$chunk_size = $backupplus->jobdata_get( 'dropbox_chunk_size', 1048576 );
		// Don't go beyond 10MB, or change the chunk size after the last segment
		if ( $chunk_size < 10485760 && $this->current_file_size > 0 && $offset < $this->current_file_size ) {
			$job_run_time = $time_now - $backupplus->job_time_ms;
			if ( $time_since_last_tick < 10 ) {
				$upload_rate = $data_since_last_tick / max( $time_since_last_tick, 1 );
				$upload_secs = min( floor( $job_run_time ), 10 );
				if ( $job_run_time < 15 ) {
					$upload_secs = max( 6, $job_run_time * 0.6 );
				}
				$new_chunk  = (int) max( min( $upload_secs * $upload_rate * 0.9, 10485760 ), 1048576 );
				$new_chunk  = $new_chunk - ( $new_chunk % 524288 );
				$chunk_size = $new_chunk;
				$storage->setChunkSize( $chunk_size );
				$backupplus->jobdata_set( 'dropbox_chunk_size', $chunk_size );
			}
		}

		if ( $this->current_file_size > 0 ) {
			$percent = round( 100 * ( $offset / $this->current_file_size ), 1 );
			$backupplus->record_uploaded_chunk( $percent, "$uploadid, $offset, " . round( $chunk_size / 1024, 1 ) . ' KB', $fullpath );
		} else {
			$this->log( "Chunked Upload: $offset bytes uploaded" );
			// This act is done by record_uploaded_chunk, and helps prevent overlapping runs
			if ( $fullpath ) {
				touch( $fullpath );
			}
		}
	}

	public function backup( $backup_array ) {

		global $backupplus;

		$opts = $this->get_options();

		if ( empty( $opts['tk_access_token'] ) ) {
			$this->log( 'You are not authenticated with Dropbox (1)' );
			$this->log( __( 'You are not authenticated with Dropbox', 'backupplus' ), 'error' );
			return false;
		}

		// 28 September 2017: APIv1 is gone. We'll keep the variable to make life easier if there's ever an APIv3.
		$use_api_ver = 2;

		if ( empty( $opts['tk_request_token'] ) ) {
			$this->log( "begin cloud upload (using API version $use_api_ver with OAuth v2 token)" );
		} else {
			$this->log( "begin cloud upload (using API version $use_api_ver with OAuth v1 token)" );
		}

		$chunk_size = $backupplus->jobdata_get( 'dropbox_chunk_size', 1048576 );

		try {
			$dropbox = $this->bootstrap();
			if ( false === $dropbox ) {
				throw new Exception( __( 'You are not authenticated with Dropbox', 'backupplus' ) );
			}
			$this->log( 'access gained; setting chunk size to: ' . round( $chunk_size / 1024, 1 ) . ' KB' );
			$dropbox->setChunkSize( $chunk_size );
		} catch ( Exception $e ) {
			$this->log( 'error when trying to gain access: ' . $e->getMessage() . ' (line: ' . $e->getLine() . ', file: ' . $e->getFile() . ')' );
			/* translators: %s: Error message */
			$this->log( sprintf( __( 'error: %s (see log file for more)', 'backupplus' ), $e->getMessage() ), 'error' );
			return false;
		}

		$backupplus_dir = $backupplus->backups_dir_location();

		foreach ( $backup_array as $file ) {

			$available_quota = -1;

			// If we experience any failures collecting account info, then carry on anyway
			try {

				/*
					Quota information is no longer provided with account information a new call to quotaInfo must be made to get this information.
				 */
				$quota_info = $dropbox->quotaInfo();

				// Access token expired try to refresh and then call quota info again
				if ( '401' == $quota_info['code'] ) {
					$this->log( 'HTTP code 401 (unauthorized) code returned from Dropbox; attempting to refresh access token' );
					$dropbox->refreshAccessToken();
					$quota_info = $dropbox->quotaInfo();
				}

				if ( '200' != $quota_info['code'] ) {
					$message = 'account/info did not return HTTP 200; returned: ' . $quota_info['code'];
				} elseif ( ! isset( $quota_info['body'] ) ) {
					$message = 'account/info did not return the expected data';
				} else {
					$body = $quota_info['body'];
					if ( isset( $body->quota_info ) ) {
						$quota_info      = $body->quota_info;
						$total_quota     = $quota_info->quota;
						$normal_quota    = $quota_info->normal;
						$shared_quota    = $quota_info->shared;
						$available_quota = $total_quota - ( $normal_quota + $shared_quota );
						$message         = 'quota usage: normal=' . round( $normal_quota / 1048576, 1 ) . ' MB, shared=' . round( $shared_quota / 1048576, 1 ) . ' MB, total=' . round( $total_quota / 1048576, 1 ) . ' MB, available=' . round( $available_quota / 1048576, 1 ) . ' MB';
					} else {
						$total_quota = max( $body->allocation->allocated, 1 );
						$used        = $body->used;
						/* check here to see if the account is a team account and if so use the other used value
						This will give us their total usage including their individual account and team account */
						if ( isset( $body->allocation->used ) ) {
							$used = $body->allocation->used;
						}
						$available_quota = $total_quota - $used;
						$message         = 'quota usage: used=' . round( $used / 1048576, 1 ) . ' MB, total=' . round( $total_quota / 1048576, 1 ) . ' MB, available=' . round( $available_quota / 1048576, 1 ) . ' MB';
					}
				}
				$this->log( $message );
			} catch ( Exception $e ) {
				$this->log( 'exception (' . get_class( $e ) . ') occurred whilst getting account info: ' . $e->getMessage() );
				// $this->log(sprintf(__("%s error: %s", 'backupplus'), 'Dropbox', $e->getMessage()).' ('.$e->getCode().')', 'warning', md5($e->getMessage()));
			}

			$file_success = 1;

			$hash                    = md5( $file );
			$this->current_file_hash = $hash;

			$filesize                = filesize( $backupplus_dir . '/' . $file );
			$this->current_file_size = $filesize;

			// Into KB
			$filesize  = $filesize / 1024;
			$microtime = microtime( true );

			if ( 'None' != ( $upload_id = $this->jobdata_get( 'upload_id_' . $hash, 'None', 'updraf_dbid_' . $hash ) ) ) {
				// Resume
				$offset = $this->jobdata_get( 'upload_offset_' . $hash, 0, 'updraf_dbof_' . $hash );
				if ( $offset ) {
					$this->log( "This is a resumption: $offset bytes had already been uploaded" );
				}
			} else {
				$offset    = 0;
				$upload_id = 'None';
			}

			// We don't actually abort now - there's no harm in letting it try and then fail
			if ( -1 != $available_quota && $available_quota < ( $filesize - $offset ) ) {
				$this->log( "File upload expected to fail: file data remaining to upload ($file) size is " . ( $filesize - $offset ) . ' b (overall file size; .' . ( $filesize * 1024 ) . " b), whereas available quota is only $available_quota b" );
				// $this->log(sprintf(__("Account full: your %s account has only %d bytes left, but the file to be uploaded has %d bytes remaining (total size: %d bytes)",'backupplus'),'Dropbox', $available_quota, $filesize-$offset, $filesize), 'warning');
			}

			$ufile = apply_filters( 'backupplus_dropbox_modpath', $file, $this );

			$this->log( "Attempt to upload: $file to: $ufile" );

			$this->upload_tick     = microtime( true );
			$this->uploaded_offset = $offset;

			try {
				$response = $dropbox->chunkedUpload( $backupplus_dir . '/' . $file, '', $ufile, true, $offset, $upload_id, array( $this, 'chunked_callback' ) );
				if ( empty( $response['code'] ) || '200' != $response['code'] ) {
					$this->log( 'Unexpected HTTP code returned from Dropbox: ' . $response['code'] . ' (' . serialize( $response ) . ')' );
					if ( $response['code'] >= 400 ) {
						if ( 401 == $response['code'] ) {
							$this->log( 'HTTP code 401 returned from Dropbox, refreshing access token' );
							$dropbox->refreshAccessToken();
						}
						/* translators: %s: Destination path */
						$this->log( sprintf( __( 'error: failed to upload file to %s (see log file for more)', 'backupplus' ), $file ), 'error' );
						$file_success = 0;
					} else {
						$this->log( __( 'did not return the expected response - check your log file for more details', 'backupplus' ), 'warning' );
					}
				}
			} catch ( Exception $e ) {
				$this->log( 'chunked upload exception (' . get_class( $e ) . '): ' . $e->getMessage() . ' (line: ' . $e->getLine() . ', file: ' . $e->getFile() . ')' );
				if ( preg_match( '/Submitted input out of alignment: got \[(\d+)\] expected \[(\d+)\]/i', $e->getMessage(), $matches ) ) {
					// Try the indicated offset
					$we_tried       = $matches[1];
					$dropbox_wanted = (int) $matches[2];
					$this->log( "not yet aligned: tried=$we_tried, wanted=$dropbox_wanted; will attempt recovery" );
					$this->uploaded_offset = $dropbox_wanted;
					$upload_id             = $this->jobdata_get( 'upload_id_' . $hash, 'None', 'updraf_dbid_' . $hash );
					try {
						$dropbox->chunkedUpload( $backupplus_dir . '/' . $file, '', $ufile, true, $dropbox_wanted, $upload_id, array( $this, 'chunked_callback' ) );
					} catch ( Exception $e ) {
						$msg = $e->getMessage();
						if ( preg_match( '/Upload with upload_id .* already completed/', $msg ) ) {
							$this->log( 'returned an error, but apparently indicating previous success: ' . $msg );
						} else {
							$this->log( $msg . ' (line: ' . $e->getLine() . ', file: ' . $e->getFile() . ')' );
							/* translators: %s: Destination path */
							$this->log( sprintf( __( 'failed to upload file to %s (see log file for more)', 'backupplus' ), $ufile ), 'error' );
							$file_success = 0;
							if ( strpos( $msg, 'select/poll returned error' ) !== false && $this->upload_tick > 0 && time() - $this->upload_tick > 800 ) {
								BackupPlus_Job_Scheduler::reschedule( 60 );
								$this->log( 'Select/poll returned after a long time: scheduling a resumption and terminating for now' );
								BackupPlus_Job_Scheduler::record_still_alive();
								die;
							}
						}
					}
				} else {
					$msg = $e->getMessage();
					if ( preg_match( '/Upload with upload_id .* already completed/', $msg ) ) {
						$this->log( 'returned an error, but apparently indicating previous success: ' . $msg );
					} else {
						/* translators: %s: Destination path */
						$this->log( sprintf( __( 'failed to upload file to %s (see log file for more)', 'backupplus' ), $ufile ), 'error' );
						$file_success = 0;
						if ( strpos( $msg, 'select/poll returned error' ) !== false && $this->upload_tick > 0 && time() - $this->upload_tick > 800 ) {
							BackupPlus_Job_Scheduler::reschedule( 60 );
							$this->log( 'Select/poll returned after a long time: scheduling a resumption and terminating for now' );
							BackupPlus_Job_Scheduler::record_still_alive();
							die;
						}
					}
				}
			}
			if ( $file_success ) {
				$backupplus->uploaded_file( $file );
				$microtime_elapsed = microtime( true ) - $microtime;
				$speedps           = ( $microtime_elapsed > 0 ) ? $filesize / $microtime_elapsed : 0;
				$speed             = sprintf( '%.2d', $filesize ) . ' KB in ' . sprintf( '%.2d', $microtime_elapsed ) . 's (' . sprintf( '%.2d', $speedps ) . ' KB/s)';
				$this->log( 'File upload success (' . $file . "): $speed" );
				$this->jobdata_delete( 'upload_id_' . $hash, 'updraf_dbid_' . $hash );
				$this->jobdata_delete( 'upload_offset_' . $hash, 'updraf_dbof_' . $hash );
			}
		}

		return null;
	}

	public function download( $file ) {

		global $backupplus;

		$opts = $this->get_options();

		if ( empty( $opts['tk_access_token'] ) ) {
			$this->log( 'You are not authenticated with Dropbox (4)' );
			/* translators: %s: Service name */
			$this->log( sprintf( __( 'You are not authenticated with %s', 'backupplus' ), 'Dropbox' ), 'error' );
			return false;
		}

		try {
			$dropbox = $this->bootstrap();
		} catch ( Exception $e ) {
			$this->log( $e->getMessage() . ' (line: ' . $e->getLine() . ', file: ' . $e->getFile() . ')' );
			$this->log( $e->getMessage() . ' (line: ' . $e->getLine() . ', file: ' . $e->getFile() . ')', 'error' );
			return false;
		}
		if ( false === $dropbox ) {
			return false;
		}

		$remote_files = $this->listfiles( $file );

		foreach ( $remote_files as $file_info ) {
			if ( $file_info['name'] == $file ) {
				return $backupplus->chunked_download( $file, $this, $file_info['size'], apply_filters( 'backupplus_dropbox_downloads_manually_break_up', false ), null, 2 * 1048576 );
			}
		}

		$this->log( "$file: file not found in listing of remote directory" );

		return false;
	}

	/**
	 * Callback used by by chunked downloading API
	 *
	 * @param String   $file    - the file (basename) to be downloaded
	 * @param Array    $headers - supplied headers
	 * @param Mixed    $data    - pass-back from our call to the API (which we don't use)
	 * @param resource $fh      - the local file handle
	 *
	 * @return String - the data downloaded
	 */
	public function chunked_download( $file, $headers, $data, $fh ) {// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Unused parameter is present because the caller from BackupPlus class uses 4 arguments.

		$opts    = $this->get_options();// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- filter use
		$storage = $this->get_storage();

		$try_the_other_one = false;// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- filter use

		$ufile = apply_filters( 'backupplus_dropbox_modpath', $file, $this );

		$options = array();

		if ( ! empty( $headers ) ) {
			$options['headers'] = $headers;
		}

		try {
			$get = $storage->download( $ufile, $fh, $options );
		} catch ( Exception $e ) {
			$this->log( $e );
			$this->log( $e->getMessage(), 'error' );
			$get = false;
		}

		return $get;
	}
}
