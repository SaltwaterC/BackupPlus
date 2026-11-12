<?php

/** Uploading backup archives through plupload. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Admin_Uploads {

	/**
	 * Used with the WP filter upload_dir to adjust where uploads go to when uploading a backup
	 *
	 * @param Array $uploads - pre-filter array
	 *
	 * @return Array - filtered array
	 */
	public function upload_dir( $uploads ) {
		global $backupplus;
		$backupplus_dir = $backupplus->backups_dir_location();
		if ( is_writable( $backupplus_dir ) ) {
			$uploads['path'] = $backupplus_dir;
		}
		return $uploads;
	}

	/**
	 * The name and chunk of an upload request. The name is the 'name' field that plupload sends, or the multipart file name when
	 * that is absent. It is returned as sent, so the caller must validate it; the multipart file name is never used to store the
	 * file (BPS-06).
	 *
	 * @return Array|String - 'name', 'chunk' and 'chunks' (both null for an upload in one part), or an error message
	 */
	private function upload_request() {
		$name = BackupPlus_Manipulation_Functions::fetch_superglobal( 'post', 'name', null, true );
		if ( null === $name ) {
			$file = BackupPlus_Manipulation_Functions::fetch_superglobal( 'files', 'async-upload' );
			$name = is_array( $file ) && isset( $file['name'] ) ? $file['name'] : null;
		}
		if ( ! isset( $_POST['chunks'] ) ) {
			return array(
				'name'   => $name,
				'chunk'  => null,
				'chunks' => null,
			);
		}
		$chunk  = BackupPlus_Manipulation_Functions::fetch_superglobal( 'post', 'chunk' );
		$chunks = BackupPlus_Manipulation_Functions::fetch_superglobal( 'post', 'chunks' );
		if ( ! is_string( $chunk ) || ! is_string( $chunks ) || ! preg_match( '/\A[0-9]{1,9}\z/', $chunk ) || ! preg_match( '/\A[0-9]{1,9}\z/', $chunks ) || (int) $chunk >= (int) $chunks ) {
			return __( 'The upload chunk numbers are not valid.', 'backupplus' );
		}
		return array(
			'name'   => $name,
			'chunk'  => (int) $chunk,
			'chunks' => (int) $chunks,
		);
	}

	/**
	 * Store the uploaded file, or one chunk of it, in the backup directory under a name that the caller has validated. WordPress
	 * first stores it under a random temporary name, so the client's multipart file name never reaches the file system (BPS-06).
	 *
	 * @param String $action         - the AJAX action that wp_handle_upload() checks the request against
	 * @param String $backupplus_dir - the backup directory
	 * @param String $target         - the validated basename to store the file as
	 *
	 * @return Array - 'file' (the stored path) or 'error'
	 */
	private function store_upload( $action, $backupplus_dir, $target ) {
		$file         = BackupPlus_Manipulation_Functions::fetch_superglobal( 'files', 'async-upload', array(), false, 'array' );
		$file['name'] = 'backupplus-upload-' . bin2hex( random_bytes( 8 ) ) . '.tmp';
		add_filter( 'upload_dir', array( $this, 'upload_dir' ) );
		$status = wp_handle_upload(
			$file,
			array(
				'test_form' => true,
				'action'    => $action,
				'test_type' => false,
				'ext'       => 'tmp',
				'type'      => 'application/octet-stream',
			)
		);
		remove_filter( 'upload_dir', array( $this, 'upload_dir' ) );
		if ( isset( $status['error'] ) ) {
			return array( 'error' => $status['error'] );
		}
		$path = $backupplus_dir . '/' . $target;
		if ( empty( $status['file'] ) || ! rename( $status['file'], $path ) ) {
			if ( ! empty( $status['file'] ) ) {
				@unlink( $status['file'] );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
			}
			return array(
				'error' => sprintf(
					/* translators: %s: Error message */
					__( 'Error: %s', 'backupplus' ),
					__( 'This file could not be uploaded', 'backupplus' )
				),
			);
		}
		return array( 'file' => $path );
	}

	/**
	 * Runs upon the WordPress action plupload_action
	 */
	public function plupload_action() {

		global $backupplus;
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( BACKUPPLUS_SET_TIME_LIMIT );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}
		if ( ! BackupPlus_Options::user_can_manage() ) {
			return;
		}
			check_ajax_referer( 'backupplus-uploader' );

		$backupplus_dir = $backupplus->backups_dir_location();
		if ( ! @BackupPlus_Filesystem_Functions::really_is_writable( $backupplus_dir ) ) {// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.
			echo json_encode(
				array(
					'e' => sprintf(
						/* translators: %s: Backup directory path */
						__( 'Backup directory (%s) is not writable, or does not exist.', 'backupplus' ),
						$backupplus_dir
					) . ' ' . __( 'You will find more information about this in the Settings section.', 'backupplus' ),
				)
			);
			exit;
		}

		// Every part is checked before anything is stored, and stored only under the checked name (BPS-06).
		$upload = $this->upload_request();
		if ( is_string( $upload ) ) {
			echo json_encode(
				array(
					'e' => sprintf(
						/* translators: %s: Error message */
						__( 'Error: %s', 'backupplus' ),
						$upload
					),
				)
			);
			exit;
		}
		$final_file = $upload['name'];
		$chunk      = $upload['chunk'];
		$chunks     = $upload['chunks'];
		$name       = BackupPlus_Manipulation_Functions::upload_name( $final_file, apply_filters( 'backupplus_accept_archivename', array() ) );
		if ( false === $name ) {
			echo json_encode(
				array(
					'e' => sprintf(
						/* translators: %s: Error message */
						__( 'Error: %s', 'backupplus' ),
						__( 'Bad filename format - this does not look like a file created by BackupPlus or UpdraftPlus', 'backupplus' )
					),
				)
			);
			exit;
		}
		if ( 'backup' === $name['kind'] ) {
			$backupable_entities = $backupplus->get_backupable_file_entities( true );
			$type                = $name['entity'];
			if ( 'db' != $type && BackupPlus_Credential_Key_Store::BACKUP_ENTITY !== $type && ! BackupPlus_Backup_Sources::custom( $type ) && ! isset( $backupable_entities[ $type ] ) ) {
				echo json_encode(
					array(
						'e' => sprintf(
							/* translators: %s: Error message */
							__( 'Error: %s', 'backupplus' ),
							sprintf(
								/* translators: %s: Object type */
								__( 'This looks like a file created by BackupPlus or UpdraftPlus, but this install does not know about this type of object: %s.', 'backupplus' ),
								htmlspecialchars( $type )
							) . ' ' .
							__( 'This backup component was created by an extension that is not installed here.', 'backupplus' )
						),
					)
				);
				exit;
			}
		}

		// A chunk is stored on its own, and the final chunk then concatenates them all onto the final file
		$stored = $this->store_upload( 'plupload_action', $backupplus_dir, null === $chunk ? $final_file : $final_file . '.' . $chunk . '.zip.tmp' );
		if ( isset( $stored['error'] ) ) {
			echo json_encode( array( 'e' => $stored['error'] ) );
			exit;
		}

		$response = array( 'm' => $final_file );
		if ( null === $chunk || $chunk === $chunks - 1 ) {
			if ( 'foreign' === $name['kind'] ) {
				/* translators: %s: Backup tool name */
				$response['dm'] = sprintf( __( 'This backup was created by %s, and can be imported.', 'backupplus' ), $name['desc'] );
			}

			// Final chunk? If so, then stich it all back together
			if ( null !== $chunk ) {
				if ( $wh = fopen( $backupplus_dir . '/' . $final_file, 'wb' ) ) {
					for ( $i = 0; $i < $chunks; $i++ ) {
						$rf = $backupplus_dir . '/' . $final_file . '.' . $i . '.zip.tmp';
						if ( $rh = fopen( $rf, 'rb+' ) ) {

							// April 1st 2020 - Due to a bug during uploads to Dropbox some backups had string "null" appended to the end which caused warnings, this removes the string "null" from these backups
							fseek( $rh, -4, SEEK_END );
							$data = fgets( $rh, 5 );

							// Authenticated archives must survive upload byte-for-byte.
							if ( ! BackupPlus_Encryption::is_file_encrypted( $final_file ) && 'null' === $data ) {
								ftruncate( $rh, filesize( $rf ) - 4 );
							}

							fseek( $rh, 0, SEEK_SET );

							while ( $line = fread( $rh, 524288 ) ) {
								fwrite( $wh, $line );
							}
							fclose( $rh );
							@unlink( $rf );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
						}
					}
					fclose( $wh );
					$path = $backupplus_dir . '/' . $final_file;
					if ( '.tar' == substr( $final_file, -4, 4 ) ) {
						if ( file_exists( $path . '.gz' ) ) {
							unlink( $path . '.gz' );
						}
						if ( file_exists( $path . '.bz2' ) ) {
							unlink( $path . '.bz2' );
						}
					} elseif ( '.tar.gz' == substr( $final_file, -7, 7 ) ) {
						if ( file_exists( substr( $path, 0, strlen( $path ) - 3 ) ) ) {
							unlink( substr( $path, 0, strlen( $path ) - 3 ) );
						}
						if ( file_exists( substr( $path, 0, strlen( $path ) - 3 ) . '.bz2' ) ) {
							unlink( substr( $path, 0, strlen( $path ) - 3 ) . '.bz2' );
						}
					} elseif ( '.tar.bz2' == substr( $final_file, -8, 8 ) ) {
						if ( file_exists( substr( $path, 0, strlen( $path ) - 4 ) ) ) {
							unlink( substr( $path, 0, strlen( $path ) - 4 ) );
						}
						if ( file_exists( substr( $path, 0, strlen( $path ) - 4 ) . '.gz' ) ) {
							unlink( substr( $path, 0, strlen( $path ) - 4 ) . '.gz' );
						}
					}
				}
			}
		}

		echo json_encode( $response );
		exit;
	}

	/**
	 * Archive decrypter - runs upon the WP action plupload_action2
	 */
	public function plupload_action2() {

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( BACKUPPLUS_SET_TIME_LIMIT );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}       global $backupplus;

		if ( ! BackupPlus_Options::user_can_manage() ) {
			return;
		}
			check_ajax_referer( 'backupplus-uploader' );

		$backupplus_dir = $backupplus->backups_dir_location();
		if ( ! is_writable( $backupplus_dir ) ) {
			exit;
		}

		// Checked before anything is stored, and stored only under the checked name (BPS-06)
		$upload = $this->upload_request();
		if ( is_string( $upload ) ) {
			echo 'ERROR:' . wp_kses_post( $upload );
			exit;
		}
		$final_file = $upload['name'];
		$chunk      = $upload['chunk'];
		$chunks     = $upload['chunks'];
		if ( ! BackupPlus_Manipulation_Functions::is_safe_basename( $final_file ) || ! preg_match( '/\Abackup_([\-0-9]{15})_.*_([0-9a-f]{12})-(?:db[0-9]*\.(?:gz|sql\.zst)|[\-a-z]+[0-9]*\.(?:zip|tar\.zst))\.crypt\z/iD', $final_file ) || preg_match( BackupPlus_Manipulation_Functions::EXECUTABLE_NAME_PATTERN, $final_file ) ) {
			echo 'ERROR:' . wp_kses_post( __( 'Bad filename format - this does not look like an encrypted backup archive created by BackupPlus or UpdraftPlus', 'backupplus' ) );
			exit;
		}

		$stored = $this->store_upload( 'plupload_action2', $backupplus_dir, null === $chunk ? $final_file : $final_file . '.' . $chunk . '.zip.tmp' );
		if ( isset( $stored['error'] ) ) {
			die( 'ERROR: ' . wp_kses_post( $stored['error'] ) );
		}

		// Final chunk? If so, then stich it all back together
		if ( null !== $chunk && $chunk === $chunks - 1 ) {
			if ( $wh = fopen( $backupplus_dir . '/' . $final_file, 'wb' ) ) {
				for ( $i = 0; $i < $chunks; $i++ ) {
					$rf = $backupplus_dir . '/' . $final_file . '.' . $i . '.zip.tmp';
					if ( $rh = fopen( $rf, 'rb' ) ) {
						while ( $line = fread( $rh, 524288 ) ) {
							fwrite( $wh, $line );
						}
						fclose( $rh );
						@unlink( $rf );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
					}
				}
				fclose( $wh );
			}
		}

		echo 'OK:' . wp_kses_post( $final_file );
		exit;
	}
}
