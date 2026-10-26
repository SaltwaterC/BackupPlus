<?php

/** Uploading backup files to and downloading them from Google Drive. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_GoogleDrive_Transfers {

	/**
	 * This function just does the formalities, and off-loads the main work to upload_file
	 *
	 * @param  array $backup_array
	 */
	public function backup( $backup_array ) {

		global $backupplus;

		$storage = $this->bootstrap();
		if ( false == $storage || is_wp_error( $storage ) ) {
			return $storage;
		}

		$backupplus_dir = trailingslashit( $backupplus->backups_dir_location() );

		$opts = $this->get_options();

		try {
			$parent_ids = $this->get_parent_id( $opts, false );
			if ( is_array( $parent_ids ) ) {
				reset( $parent_ids );
				$parent_id = key( $parent_ids );
				if ( count( $parent_ids ) > 1 ) {
					$this->log( 'there appears to be more than one folder: ' . implode( ', ', array_keys( $parent_ids ) ) );
					if ( empty( $this->registered_prune[ $this->get_instance_id() ] ) ) {
						$this->registered_prune[ $this->get_instance_id() ]  = true;
						$this->multi_directories[ $this->get_instance_id() ] = $parent_ids;
						add_action( 'backupplus_prune_retained_backups_finished', array( $this, 'prune_retained_backups_finished' ) );
					}
				}
			} else {
				$parent_id = $parent_ids;
			}
		} catch ( Exception $e ) {
			$this->log( 'upload: failed to access parent folder: ' . $e->getMessage() . ' (line: ' . $e->getLine() . ', file: ' . $e->getFile() . ')' );
			$this->log(
				sprintf(
				/* translators: %s: Storage provider name */
					__( 'Failed to upload to %s', 'backupplus' ),
					__( 'Google Drive', 'backupplus' )
				) . ': ' . __( 'failed to access parent folder', 'backupplus' ) . ' (' . $e->getMessage() . ')',
				'error'
			);
			return false;
		}

		foreach ( $backup_array as $file ) {

			$available_quota = -1;

			do {
				try {
					$try_again       = false;
					$about           = $storage->about->get();
					$quota_total     = max( $about->getQuotaBytesTotal(), 1 );
					$quota_used      = $about->getQuotaBytesUsed();
					$available_quota = $quota_total - $quota_used;
					$message         = 'quota usage: used=' . round( $quota_used / 1048576, 1 ) . ' MB, total=' . round( $quota_total / 1048576, 1 ) . ' MB, available=' . round( $available_quota / 1048576, 1 ) . ' MB';
					$this->log( $message );
				} catch ( Exception $e ) {
					$msg = $e->getMessage();
					$this->log( 'quota usage: failed to obtain this information: ' . $msg );

					// If the issue was a problem refreshing the OAuth2 token, bootstrap again and try again
					if ( false !== strpos( $msg, 'Error refreshing the OAuth2 token' ) ) {
						$this->log( 'quota usage: will attempt to refresh OAuth2 token and fetch this information again' );
						$this->set_storage( null );
						$storage = $this->bootstrap();
						if ( false == $storage || is_wp_error( $storage ) ) {
							return $storage;
						}

						$try_again = true;
					}
				}
			} while ( $try_again );

			$file_path = $backupplus_dir . $file;
			$file_name = basename( $file_path );
			$this->log( "$file_name: Attempting to upload to Google Drive (into folder id: $parent_id)" );

			$filesize       = filesize( $file_path );
			$already_failed = false;
			if ( -1 != $available_quota ) {
				if ( $filesize > $available_quota ) {
					$already_failed = true;
					$this->log( "File upload expected to fail: file ($file_name) size is $filesize b, whereas available quota is only $available_quota b" );
					$this->log(
						sprintf(
						/* translators: 1: Storage provider name, 2: Available bytes, 3: File size */
							__( 'Account full: your %1$s account has only %2$d bytes left, but the file to be uploaded is %3$d bytes', 'backupplus' ),
							__( 'Google Drive', 'backupplus' ),
							$available_quota,
							$filesize
						),
						'error'
					);
				}
			}

			if ( ! $already_failed && $filesize > 10737418240 ) {
				// 10GB
				$this->log( "File upload expected to fail: file ($file_name) size is $filesize b (" . round( $filesize / 1073741824, 4 ) . " GB), whereas Google Drive's limit is 10GB (1073741824 bytes)" );
				$this->log(
					sprintf(
					/* translators: 1: Storage provider name, 2: File size limit, 3: File size in GB, 4: File size in bytes */
						__( 'Upload expected to fail: the %1$s limit for any single file is %2$s, whereas this file is %3$s GB (%4$d bytes)', 'backupplus' ),
						__( 'Google Drive', 'backupplus' ),
						'10GB (1073741824)',
						round( $filesize / 1073741824, 4 ),
						$filesize
					),
					'warning'
				);
			}

			do {
				try {
					$try_again   = false;
					$timer_start = microtime( true );
					if ( $this->upload_file( $file_path, $parent_id ) ) {
						$this->log( 'OK: Archive ' . $file_name . ' uploaded in ' . ( round( microtime( true ) - $timer_start, 2 ) ) . ' seconds' );
						$backupplus->uploaded_file( $file );
					} else {
						$this->log( "ERROR: $file_name: Failed to upload" );
						$this->log(
							"$file_name: " . sprintf(
							/* translators: %s: Storage provider name */
								__( 'Failed to upload to %s', 'backupplus' ),
								__( 'Google Drive', 'backupplus' )
							),
							'error'
						);
					}
				} catch ( Exception $e ) {
					$msg = $e->getMessage();
					$this->log( 'Upload exception (' . get_class( $e ) . "): $msg (line: " . $e->getLine() . ', file: ' . $e->getFile() . ')' );

					// If the issue was a problem refreshing the OAuth2 token, bootstrap again and try again
					if ( false !== ( $p = strpos( $msg, 'Error refreshing the OAuth2 token' ) ) ) {
						$this->log( "$file_name: will attempt to refresh OAuth2 token and upload again" );
						$this->set_storage( null );
						$storage = $this->bootstrap();
						if ( false == $storage || is_wp_error( $storage ) ) {
							return $storage;
						}

						$try_again = true;
					} else {
						if ( false !== ( $p = strpos( $msg, 'The user has exceeded their Drive storage quota' ) ) ) {
							$this->log(
								"$file_name: " . sprintf(
								/* translators: %s: Storage provider name */
									__( 'Failed to upload to %s', 'backupplus' ),
									__( 'Google Drive', 'backupplus' )
								) . ': ' . substr( $msg, $p ),
								'error'
							);
						} else {
							$this->log(
								"$file_name: " . sprintf(
								/* translators: %s: Storage provider name */
									__( 'Failed to upload to %s', 'backupplus' ),
									__( 'Google Drive', 'backupplus' )
								),
								'error'
							);
						}
						$this->client->setDefer( false );
					}
				}
			} while ( $try_again );
		}

		return null;
	}

	/**
	 * Used internally to upload files
	 *
	 * @param String  $file      - the full path to the file to upload
	 * @param String  $parent_id - the internal Google Drive folder identifier
	 * @param Boolean $try_again - whether to retry in the event of a problem
	 *
	 * @return Boolean - success or failure state
	 */
	private function upload_file( $file, $parent_id, $try_again = true ) {

		global $backupplus;
		$basename = basename( $file );

		$storage = $this->get_storage();
		$client  = $this->client;

		// See: https://github.com/google/google-api-php-client/blob/master/examples/fileupload.php (at time of writing, only shows how to upload in chunks, not how to resume)

		$client->setDefer( true );

		$local_size = filesize( $file );

		$gdfile        = new UDP_Google_Service_Drive_DriveFile();
		$gdfile->title = $basename;

		$ref = new UDP_Google_Service_Drive_ParentReference();
		$ref->setId( $parent_id );
		$gdfile->setParents( array( $ref ) );

		$size    = 0;
		$request = $storage->files->insert( $gdfile );

		$chunk_size = 1048576;

		$transkey = 'resume_' . md5( $file );
		// This is unset upon completion, so if it is set then we are resuming
		$possible_location = $this->jobdata_get( $transkey, null, 'gd' . $transkey );

		if ( is_array( $possible_location ) ) {

			$headers = array( 'content-range' => 'bytes */' . $local_size );

			$http_request = new UDP_Google_Http_Request(
				$possible_location[0],
				'PUT',
				$headers,
				''
			);
			$response     = $this->client->getIo()->makeRequest( $http_request );
			$can_resume   = false;

			$response_http_code = $response->getResponseHttpCode();

			if ( 200 == $response_http_code || 201 == $response_http_code ) {
				$client->setDefer( false );
				$this->jobdata_delete( $transkey, 'gd' . $transkey );
				$this->log( "$basename: upload appears to be already complete (HTTP code: $response_http_code)" );
				return true;
			}

			if ( 308 == $response_http_code ) {
				$range = $response->getResponseHeader( 'range' );
				if ( ! empty( $range ) && preg_match( '/bytes=0-(\d+)$/', $range, $matches ) ) {
					$can_resume           = true;
					$possible_location[1] = $matches[1] + 1;
					$this->log( "$basename: upload already began; attempting to resume from byte " . $matches[1] );
				}
			}
			if ( ! $can_resume ) {
				$this->log( "$basename: upload already began; attempt to resume did not succeed (HTTP code: " . $response_http_code . ')' );
			}
		}

		// BackupPlus_Google_Http_MediaFileUpload extends Google_Http_MediaFileUpload, with a few extra methods to change private properties to public ones
		$media = new BackupPlus_Google_Http_MediaFileUpload(
			$client,
			$request,
			( ( '.zip' == substr( $basename, -4, 4 ) ) ? 'application/zip' : 'application/octet-stream' ),
			null,
			true,
			$chunk_size
		);
		$media->setFileSize( $local_size );

		if ( ! empty( $possible_location ) ) {
			// $media->resumeUri = $possible_location[0];
			// $media->progress = $possible_location[1];
			$media->backupplus_setResumeUri( $possible_location[0] );
			$media->backupplus_setProgress( $possible_location[1] );
			$size = $possible_location[1];
		}
		if ( $size >= $local_size ) {
			return true;
		}

		$status = false;
		if ( false == ( $handle = fopen( $file, 'rb' ) ) ) {
			$this->log( "failed to open file: $basename" );
			$this->log( "$basename: " . __( 'Error: Failed to open local file', 'backupplus' ), 'error' );
			return false;
		}

		if ( $size > 0 && 0 != fseek( $handle, $size ) ) {
			$this->log( "failed to fseek file: $basename, $size" );
			$this->log( "$basename (fseek): " . __( 'Error: Failed to open local file', 'backupplus' ), 'error' );
			return false;
		}

		$pointer = $size;

		try {
			while ( ! $status && ! feof( $handle ) ) {
				$chunk = '';
				// Google requires chunks of the previous indicated size. Short reads are thus problematic. (Or does it? Was this just because the content-length header was hard-coded to the chunk size? Should be investigated, to see if we can change chunk size dynamically).
				while ( strlen( $chunk ) < $chunk_size && ! feof( $handle ) ) {
					$chunk .= fread( $handle, $chunk_size - strlen( $chunk ) );
				}
				// Do we need any further error handling??
				$pointer += strlen( $chunk );

				$start_time = microtime( true );
				$status     = $media->nextChunk( $chunk );

				unset( $chunk );

				$extra_log = $media->getProgress();

				if ( ! $status && $chunk_size < 67108864 && microtime( true ) - $start_time < 2.5 && ! feof( $handle ) && $backupplus->verify_free_memory( $chunk_size * 4 ) ) {

					$memory_usage  = round( @memory_get_usage( false ) / 1048576, 1 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
					$memory_usage2 = round( @memory_get_usage( true ) / 1048576, 1 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.

					$chunk_size = $chunk_size * 2;
					$extra_log .= ' - increasing chunk size to ' . round( $chunk_size / 1024 ) . ' KB';
					$extra_log .= " - memory usage: $memory_usage / $memory_usage2";
				}

				$this->jobdata_set( $transkey, array( $media->backupplus_getResumeUri(), $media->getProgress() ) );
				$backupplus->record_uploaded_chunk( round( 100 * $pointer / $local_size, 1 ), $extra_log, $file );
			}
		} catch ( UDP_Google_Service_Exception $e ) {
			return $this->catch_upload_engine_exceptions( $e, $handle, $try_again, $file, $parent_id );
		} catch ( UDP_Google_IO_Exception $e ) {
			return $this->catch_upload_engine_exceptions( $e, $handle, $try_again, $file, $parent_id );
		}

		// The final value of $status will be the data from the API for the object
		// that has been uploaded.
		$result = ( false != $status ) ? $status : false;// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- We don't use this at this time.

		fclose( $handle );
		$client->setDefer( false );
		$this->jobdata_delete( $transkey, 'gd' . $transkey );

		return true;
	}

	/**
	 * This function is used to handle certain exceptions that can rise from uploading files to Google Drive when a retry is possibly desirable.
	 *
	 * @param Exception $e         - the Google exception we caught
	 * @param Resource  $handle    - a file handler object that needs closing
	 * @param Boolean   $try_again - indicates if we should try again
	 * @param String    $file      - the full file path
	 * @param String    $parent_id - the Google Drive ID for the parent folder
	 *
	 * @return Boolean
	 */
	private function catch_upload_engine_exceptions( $e, $handle, $try_again, $file, $parent_id ) {
		$this->log( 'ERROR: upload exception (' . get_class( $e ) . '): ' . $e->getMessage() . ' (line: ' . $e->getLine() . ', file: ' . $e->getFile() . ')' );
		$this->client->setDefer( false );
		fclose( $handle );
		if ( false == $try_again ) {
			throw $e;
		}
		// Reset this counter to prevent the something_useful_happened condition's possibility being sent into the far future and potentially missed
		global $backupplus;
		if ( $backupplus->current_resumption > 9 ) {
			$backupplus->jobdata_set( 'uploaded_lastreset', $backupplus->current_resumption );
		}
		return $this->upload_file( $file, $parent_id, false );
	}

	/**
	 * Download method: takes a base name, and brings it back from the cloud storage into the internal directory.
	 *
	 * @param String $file The specific file to be downloaded from the Cloud Storage
	 *
	 * @return Boolean - success or failure state
	 */
	public function download( $file ) {

		global $backupplus;

		$storage = $this->bootstrap();
		if ( false == $storage || is_wp_error( $storage ) ) {
			return false;
		}

		global $backupplus;
		$opts = $this->get_options();

		try {
			$parent_id = $this->get_parent_id( $opts );
			// $gdparent = $storage->files->get($parent_id);
			$sub_items = $this->get_subitems( $parent_id, 'file' );
		} catch ( Exception $e ) {
			$this->log( 'delete: failed to access parent folder: ' . $e->getMessage() . ' (line: ' . $e->getLine() . ', file: ' . $e->getFile() . ')' );
			return false;
		}

		$found = false;
		foreach ( $sub_items as $item ) {
			if ( $found ) {
				continue;
			}
			$title = '(unknown)';
			try {
				$title = $item->getTitle();
				if ( $title == $file ) {
					$gdfile = $item;
					$found  = $item->getId();
					$size   = $item->getFileSize();
				}
			} catch ( Exception $e ) {
				$this->log( 'download: exception: ' . $e->getMessage() . ' (line: ' . $e->getLine() . ', file: ' . $e->getFile() . ')' );
			}
		}

		if ( false === $found ) {
			$this->log( 'download: failed: file not found' );
			$this->log( $file . ': ' . __( 'Error', 'backupplus' ) . ': ' . __( 'File not found', 'backupplus' ), 'error' );
			return false;
		}

		$download_to = $backupplus->backups_dir_location() . '/' . $file;

		$existing_size = file_exists( $download_to ) ? filesize( $download_to ) : 0;

		if ( $existing_size >= $size ) {
			$this->log( 'download: was already downloaded (' . filesize( $download_to ) . "/$size bytes)" );
			return true;
		}

		// We only need a chunk size because the API library won't accept a file handle - otherwise, we could download the whole range. But testing (150Mb/s connection) shows that after 32MB almost all the gains have been realised.
		$chunk_size = 2097152;
		while ( $backupplus->verify_free_memory( $chunk_size * 3 ) && $chunk_size <= 20971520 ) {
			$chunk_size = $chunk_size * 2;
		}

		try {
			while ( $existing_size < $size ) {

				$end = min( $existing_size + $chunk_size, $size );

				if ( $existing_size > 0 ) {
					$put_flag = FILE_APPEND;
					$headers  = array( 'Range' => 'bytes=' . $existing_size . '-' . $end );
				} else {
					$put_flag = 0;
					$headers  = ( $end < $size ) ? array( 'Range' => 'bytes=0-' . $end ) : array();
				}

				$pstart = round( 100 * $existing_size / $size, 1 );
				$pend   = round( 100 * $end / $size, 1 );
				$this->log( "Requesting byte range: $existing_size - $end ($pstart - $pend %)" );

				$request       = $this->client->getAuth()->sign( new UDP_Google_Http_Request( $gdfile->getDownloadUrl(), 'GET', $headers, null ) );
				$http_request  = $this->client->getIo()->makeRequest( $request );
				$http_response = $http_request->getResponseHttpCode();
				if ( 200 == $http_response || 206 == $http_response ) {
					file_put_contents( $download_to, $http_request->getResponseBody(), $put_flag );
				} else {
					$this->log( 'download: failed: unexpected HTTP response code: ' . $http_response );
					$this->log( __( 'download: failed: file not found', 'backupplus' ), 'error' );
					return false;
				}

				clearstatcache();
				$new_size = filesize( $download_to );
				if ( $new_size > $existing_size ) {
					$existing_size = $new_size;
				} else {
					throw new Exception( 'Failed to obtain any new data at size: ' . $existing_size );
				}
			}
		} catch ( Exception $e ) {
			$this->log( 'download: exception: ' . $e->getMessage() . ' (line: ' . $e->getLine() . ', file: ' . $e->getFile() . ')' );
		}

		return true;
	}
}
