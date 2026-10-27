<?php

/** Downloading backup archives through the browser. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Admin_Downloads {

	/** Download archives are separate from the database entity used by restore. */
	public function download_files( $backup, $type ) {
		if ( 'db' === $type && ! empty( $backup['pitr']['downloads'] ) ) {
			return $backup['pitr']['downloads'];
		}
		$files = array();
		foreach ( isset( $backup[ $type ] ) ? (array) $backup[ $type ] : array() as $index => $name ) {
			$size_key        = $type . ( $index ? $index : '' ) . '-size';
			$files[ $index ] = array(
				'name' => $name,
				'size' => isset( $backup[ $size_key ] ) ? $backup[ $size_key ] : 0,
			);
		}
		return $files;
	}

	/**
	 * Start a download of a backup. This method is called via the AJAX action backupplus_download_backup. May die instead of returning depending upon the mode in which it is called.
	 */
	public function backupplus_download_backup() {

		if ( ! BackupPlus_Options::user_can_manage() ) {
			die( 'Unauthorised.' );
		}

		try {
			$global_wp_nonce = BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', '_wpnonce' );
			if ( empty( $global_wp_nonce ) || ! wp_verify_nonce( $global_wp_nonce, 'backupplus_download' ) ) {
				die( 'Unauthorised.' );
			}

			if ( empty( $_REQUEST['timestamp'] ) || ! is_numeric( $_REQUEST['timestamp'] ) || empty( $_REQUEST['type'] ) ) {
				die;
			}

			// One archive holding every file of the chosen recovery points; prepare_chain_download has already fetched them.
			if ( 'bundle' === BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'stage', '', false, 'string', 'sanitize_text_field' ) ) {
				backupplus_try_include_file( 'includes/class-chain-downloads.php', 'include_once' );
				backupplus_try_include_file( 'includes/class-stored-zip.php', 'include_once' );
				try {
					BackupPlus_Chain_Downloads::send( (int) $_REQUEST['timestamp'], BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'type', '', false, 'string', 'sanitize_text_field' ), BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'points', '', false, 'string', 'sanitize_text_field' ) );
				} catch ( RuntimeException $e ) {
					status_header( 409 );
					die( esc_html( $e->getMessage() ) );
				}
			}
			$findex    = BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'findex' );
			$findexes  = empty( $findex ) ? array( 0 ) : $findex;
			$stage     = BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'stage', '', false, 'string', 'sanitize_text_field' );
			$file_path = BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'filepath', '', false, 'string', null );

			$type = BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'type', '', false, 'string', null );
			// This call may not actually return, depending upon what mode it is called in
			$result = $this->do_backupplus_download_backup( $findexes, $type, (int) $_REQUEST['timestamp'], $stage, false, $file_path );

			// Some browsers ignore Connection: close and Content-Length after a response is sent, then read subsequent output as part of the response.
			if ( empty( $result['already_closed'] ) ) {
				echo json_encode( $result );
			}
		} catch ( Exception $e ) {
			$log_message = 'PHP Fatal Exception error (' . get_class( $e ) . ') has occurred during download backup. Error Message: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
			BackupPlus_Manipulation_Functions::error_log( $log_message );
			echo json_encode(
				array(
					'fatal_error'         => true,
					'fatal_error_message' => $log_message,
				)
			);
		} catch ( Error $e ) { // phpcs:ignore PHPCompatibility.Classes.NewClasses.errorFound -- The Error class does not exist in PHP below 5.6.
			$log_message = 'PHP Fatal error (' . get_class( $e ) . ') has occurred during download backup. Error Message: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
			BackupPlus_Manipulation_Functions::error_log( $log_message );
			echo json_encode(
				array(
					'fatal_error'         => true,
					'fatal_error_message' => $log_message,
				)
			);
		}
		die();
	}

	/**
	 * Ensure that a specified backup is present, downloading if necessary (or delete it, if the parameters so indicate). N.B. This function may die(), depending on the request being made in $stage
	 *
	 * @param Array            $findexes                  - the index number of the backup archive requested
	 * @param String           $type                      - the entity type (e.g. 'plugins') being requested
	 * @param Integer          $timestamp                 - identifier for the backup being requested (UNIX epoch time)
	 * @param Mixed            $stage                     - the stage; valid values include (have not audited for other possibilities) at least 'delete' and 2.
	 * @param Callable|Boolean $close_connection_callable - function used to close the connection to the caller; an array of data to return is passed. If false, then BackupPlus::close_browser_connection is called with a JSON version of the data.
	 * @param String           $file_path                 - an over-ride for where to download the file to (basename only)
	 *
	 * @return Array - summary of the results. May also just die.
	 */
	public function do_backupplus_download_backup( $findexes, $type, $timestamp, $stage, $close_connection_callable = false, $file_path = '' ) {

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( BACKUPPLUS_SET_TIME_LIMIT );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}
		global $backupplus;

		if ( ! is_array( $findexes ) ) {
			$findexes = array( $findexes );
		}

		$connection_closed = false;

		// Check that it is a known entity type; if not, die
		if ( 'db' != substr( $type, 0, 2 ) ) {
			$backupable_entities = $backupplus->get_backupable_file_entities( true );
			foreach ( $backupable_entities as $t => $info ) {
				if ( $type == $t ) {
					$type_match = true;
				}
			}
			if ( empty( $type_match ) ) {
				return array(
					'result' => 'error',
					'code'   => 'no_such_type',
				);
			}
		}

		$debug_mode = BackupPlus_Options::get_backupplus_option( 'backupplus_debug_mode' );

		// Retrieve the information from our backup history
		$backup_history = BackupPlus_Backup_History::get_history();
		$files          = $this->download_files( isset( $backup_history[ $timestamp ] ) ? $backup_history[ $timestamp ] : array(), $type );
		foreach ( $findexes as $findex ) {
			if ( ( ! is_int( $findex ) && ! is_string( $findex ) ) || ! ctype_digit( (string) $findex ) || ! isset( $files[ $findex ] ) ) {
				return array(
					'result' => 'error',
					'code'   => 'no_such_file',
				);
			}
		}

		foreach ( $findexes as $findex ) {
			// This is a bit ugly; these variables get placed back into $_POST (where they may possibly have come from), so that BackupPlus::log() can detect exactly where to log the download status.
			$_POST['findex']    = $findex;
			$_POST['type']      = $type;
			$_POST['timestamp'] = (int) $timestamp;

			// We already know that no possible entities have an MD5 clash (even after 2 characters)
			// Also, there's nothing enforcing a requirement that nonces are hexadecimal
			$job_nonce = dechex( $timestamp ) . $findex . substr( md5( $type ), 0, 3 );

			// You need a nonce before you can set job data. And we certainly don't yet have one.
			$backupplus->backup_time_nonce( $job_nonce );

			// Set the job type before logging, as there can be different logging destinations
			$backupplus->jobdata_set( 'job_type', 'download' );
			$backupplus->jobdata_set( 'job_time_ms', $backupplus->job_time_ms );

			// Base name
			$file = $files[ $findex ]['name'];

			if ( false !== strpos( $file_path, '..' ) ) {
				BackupPlus_Manipulation_Functions::error_log( "BackupPlus_Admin::do_backupplus_download_backup : invalid file_path: $file_path" );
				return array( 'result' => __( 'Error: invalid path', 'backupplus' ) );
			}

			if ( ! empty( $file_path ) ) {
				$file = $file_path;
			}

			// Where it should end up being downloaded to
			$fullpath = $backupplus->backups_dir_location() . '/' . $file;

			if ( ! empty( $file_path ) && strpos( realpath( $fullpath ), realpath( $backupplus->backups_dir_location() ) ) === false ) {
				BackupPlus_Manipulation_Functions::error_log( "BackupPlus_Admin::do_backupplus_download_backup : invalid fullpath: $fullpath" );
				return array( 'result' => __( 'Error: invalid path', 'backupplus' ) );
			}

			if ( 2 == $stage ) {
				$backupplus->spool_file( $fullpath );
				// We only want to remove if it was a temp file from the zip browser
				if ( ! empty( $file_path ) ) {
					@unlink( $fullpath );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
				}               // Do not return - we do not want the caller to add any output
				die;
			}

			if ( 'delete' == $stage ) {
				@unlink( $fullpath );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
				$backupplus->log( "The file has been deleted ($file)" );
				return array( 'result' => 'deleted' );
			}

			// TODO: FIXME: Failed downloads may leave log files forever (though they are small)
			if ( $debug_mode ) {
				$backupplus->logfile_open( $backupplus->nonce );
			}

			$error_levels = version_compare( PHP_VERSION, '8.4.0', '>=' ) ? E_ALL : E_ALL & ~E_STRICT;
			set_error_handler( array( $backupplus, 'php_error' ), $error_levels );

			$backupplus->log( "Requested to obtain file: timestamp=$timestamp, type=$type, index=$findex" );

			$known_size = $files[ $findex ]['size'];

			$services = isset( $backup_history[ $timestamp ]['service'] ) ? $backup_history[ $timestamp ]['service'] : false;

			$services = $backupplus->get_canonical_service_list( $services );

			$backupplus->jobdata_set( 'service', $services );

			// Fetch it from the cloud, if we have not already got it

			$needs_downloading = false;

			if ( ! file_exists( $fullpath ) && empty( $services ) ) {
				$backupplus->log( 'This file does not exist locally, and there is no remote storage for this file.' );
			} elseif ( ! file_exists( $fullpath ) ) {
				// If the file doesn't exist and they're using one of the cloud options, fetch it down from the cloud.
				$needs_downloading = true;
				$backupplus->log( 'File does not yet exist locally - needs downloading' );
			} elseif ( is_array( $services ) && 1 == count( $services ) && 'email' == $services[0] ) {
				$backupplus->log( __( 'The email protocol does not allow a remote backup to be retrieved from an email that has been sent.', 'backupplus' ) . ' ' . __( 'Therefore, please download the attachment from the original backup email and upload it using the "Upload backup files" facility in the "Existing Backups" tab.', 'backupplus' ), 'error' );
				$backupplus->jobdata_set( 'dlfile_' . $timestamp . '_' . $type . '_' . $findex, 'failed' );
				$backupplus->jobdata_set( 'dlerrors_' . $timestamp . '_' . $type . '_' . $findex, $backupplus->errors );
				return array(
					'result' => 'error',
					'code'   => 'no_email_download',
				);
			} elseif ( $known_size > 0 && filesize( $fullpath ) < $known_size ) {
				$backupplus->log( 'The file was found locally (' . filesize( $fullpath ) . ") but did not match the size in the backup history ($known_size) - will resume downloading" );
				$needs_downloading = true;
			} elseif ( $known_size > 0 && filesize( $fullpath ) > $known_size ) {
				$backupplus->log( 'The file was found locally (' . filesize( $fullpath ) . ") but the size is larger than what is recorded in the backup history ($known_size) - will try to continue but if errors are encountered then check that the backup is correct" );
			} elseif ( $known_size > 0 ) {
				$backupplus->log( 'The file was found locally and matched the recorded size from the backup history (' . round( $known_size / 1024, 1 ) . ' KB)' );
			} else {
				$backupplus->log( 'No file size was found recorded in the backup history. We will assume the local one is complete.' );
				$known_size = filesize( $fullpath );
			}

			// The AJAX responder that updates on progress wants to see this
			$backupplus->jobdata_set( 'dlfile_' . $timestamp . '_' . $type . '_' . $findex, "downloading:$known_size:$fullpath" );

			if ( $needs_downloading ) {

				// Update the "last modified" time to dissuade any other instances from thinking that no downloaders are active
				@touch( $fullpath );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.

				$msg = array(
					'result'  => 'needs_download',
					'request' => array(
						'type'      => $type,
						'timestamp' => $timestamp,
						'findex'    => $findex,
					),
				);

				if ( $close_connection_callable && is_callable( $close_connection_callable ) && ! $connection_closed ) {
					$connection_closed = true;
					call_user_func( $close_connection_callable, $msg );
				} elseif ( ! $connection_closed ) {
					$connection_closed = true;
					$backupplus->close_browser_connection( json_encode( $msg ) );
				}
				BackupPlus_Storage_Methods_Interface::get_remote_file( $services, $file, $timestamp );
				$needs_downloading = false;
			}

			// Now, be ready to spool the thing to the browser
			if ( is_file( $fullpath ) && is_readable( $fullpath ) && ! $needs_downloading ) {

				// That message is then picked up by the AJAX listener
				$backupplus->jobdata_set( 'dlfile_' . $timestamp . '_' . $type . '_' . $findex, 'downloaded:' . filesize( $fullpath ) . ":$fullpath" );

				$result = 'downloaded';

			} elseif ( $needs_downloading ) {

				$backupplus->jobdata_set( 'dlfile_' . $timestamp . '_' . $type . '_' . $findex, 'failed' );
				$backupplus->jobdata_set( 'dlerrors_' . $timestamp . '_' . $type . '_' . $findex, $backupplus->errors );
				$backupplus->log( 'Remote fetch failed. File ' . $fullpath . ' did not exist or was unreadable. If you delete local backups then remote retrieval may have failed.' );

				$result = 'download_failed';
			} else {
				$result = 'no_local_file';
			}

			restore_error_handler();

			if ( $debug_mode ) {
				@fclose( $backupplus->logfile_handle );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			}           if ( ! $debug_mode ) {
				@unlink( $backupplus->logfile_name );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
			}
		}

		// The browser connection was possibly already closed, but not necessarily
		return array(
			'result'         => $result,
			'already_closed' => $connection_closed,
		);
	}

	/**
	 * Input: an array of items
	 * Each item is in the format: <base>,<timestamp>,<type>(,<findex>)
	 * The 'base' is not for us: we just pass it straight back
	 *
	 * @param  array $downloaders Array of Items to download
	 * @return array
	 */
	public function get_download_statuses( $downloaders ) {
		global $backupplus;
		$download_status = array();
		foreach ( $downloaders as $downloader ) {
			// prefix, timestamp, entity, index
			if ( preg_match( '/^([^,]+),(\d+),([-a-z]+|db[0-9]+),(\d+)$/', $downloader, $matches ) ) {
				$findex            = ( empty( $matches[4] ) ) ? '0' : $matches[4];
				$backupplus->nonce = dechex( $matches[2] ) . $findex . substr( md5( $matches[3] ), 0, 3 );
				$backupplus->jobdata_reset();
				$status = $this->download_status( $matches[2], $matches[3], $matches[4] );
				if ( is_array( $status ) ) {
					$status['base']      = $matches[1];
					$status['timestamp'] = $matches[2];
					$status['what']      = $matches[3];
					$status['findex']    = $findex;
					$download_status[]   = $status;
				}
			}
		}
		return $download_status;
	}

	private function download_status( $timestamp, $type, $findex ) {
		global $backupplus;
		$response = array( 'm' => $backupplus->jobdata_get( 'dlmessage_' . $timestamp . '_' . $type . '_' . $findex ) . '<br>' );
		if ( $file = $backupplus->jobdata_get( 'dlfile_' . $timestamp . '_' . $type . '_' . $findex ) ) {
			if ( 'failed' == $file ) {
				$response['e']      = __( 'Download failed', 'backupplus' ) . '<br>';
				$response['failed'] = true;
				$errs               = $backupplus->jobdata_get( 'dlerrors_' . $timestamp . '_' . $type . '_' . $findex );
				if ( is_array( $errs ) && ! empty( $errs ) ) {
					$response['e'] .= '<ul class="disc">';
					foreach ( $errs as $err ) {
						if ( is_array( $err ) ) {
							$response['e'] .= '<li>' . htmlspecialchars( $err['message'] ) . '</li>';
						} else {
							$response['e'] .= '<li>' . htmlspecialchars( $err ) . '</li>';
						}
					}
					$response['e'] .= '</ul>';
				}
			} elseif ( preg_match( '/^downloaded:(\d+):(.*)$/', $file, $matches ) && file_exists( $matches[2] ) ) {
				$response['p'] = 100;
				$response['f'] = $matches[2];
				$response['s'] = (int) $matches[1];
				$response['t'] = (int) $matches[1];
				$response['m'] = __( 'File ready.', 'backupplus' );
				// The server can only list an archive it can read; encrypted archives are decrypted in the browser.
				if ( 'db' != substr( $type, 0, 2 ) && ! BackupPlus_Encryption::is_file_encrypted( $matches[2] ) ) {
					$response['can_show_contents'] = true;
				}
			} elseif ( preg_match( '/^downloading:(\d+):(.*)$/', $file, $matches ) && file_exists( $matches[2] ) ) {
				// Convert to bytes
				$response['f'] = $matches[2];
				$total_size    = (int) max( $matches[1], 1 );
				$cur_size      = filesize( $matches[2] );
				$response['s'] = $cur_size;
				$file_age      = time() - filemtime( $matches[2] );
				if ( $file_age > 20 ) {
					$response['a'] = time() - filemtime( $matches[2] );
				}
				$response['t']  = $total_size;
				$response['m'] .= __( 'Download in progress', 'backupplus' ) . ' (' . round( $cur_size / 1024 ) . ' / ' . round( ( $total_size / 1024 ) ) . ' KB)';
				$response['p']  = round( 100 * $cur_size / $total_size );
			} else {
				$response['m'] .= __( 'No local copy present.', 'backupplus' );
				$response['p']  = 0;
				$response['s']  = 0;
				$response['t']  = 1;
			}
		}
		return $response;
	}
}
