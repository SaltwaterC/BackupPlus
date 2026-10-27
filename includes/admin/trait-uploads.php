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
	 * We do actually want to over-write
	 *
	 * @param  String $dir  Directory
	 * @param  String $name Name
	 * @param  String $ext  File extension
	 *
	 * @return String
	 */
	public function unique_filename_callback( $dir, $name, $ext ) {// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Filter use
		return $name . $ext;
	}

	public function sanitize_file_name( $filename ) {
		// WordPress 3.4.2 on multisite (at least) adds in an unwanted underscore
		return preg_replace( '/-db(.*)\.gz_\.crypt$/', '-db$1.gz.crypt', $filename );
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

		add_filter( 'upload_dir', array( $this, 'upload_dir' ) );
		add_filter( 'sanitize_file_name', array( $this, 'sanitize_file_name' ) );
		// handle file upload

		$farray = array(
			'test_form' => true,
			'action'    => 'plupload_action',
		);

		$farray['test_type'] = false;
		$farray['ext']       = 'x-gzip';
		$farray['type']      = 'application/octet-stream';

		if ( ! isset( $_POST['chunks'] ) ) {
			$farray['unique_filename_callback'] = array( $this, 'unique_filename_callback' );
		}
		$file   = BackupPlus_Manipulation_Functions::fetch_superglobal( 'files', 'async-upload', array(), false, 'array' );
		$status = wp_handle_upload( $file, $farray );
		remove_filter( 'upload_dir', array( $this, 'upload_dir' ) );
		remove_filter( 'sanitize_file_name', array( $this, 'sanitize_file_name' ) );
		$post_name  = BackupPlus_Manipulation_Functions::fetch_superglobal( 'post', 'name' );
		$final_file = isset( $post_name ) ? basename( $post_name ) : basename( $status['file'] );

		if ( isset( $status['error'] ) ) {
			echo json_encode( array( 'e' => $status['error'] ) );
			exit;
		}

		// If this was the chunk, then we should instead be concatenating onto the final file
		$chunk = BackupPlus_Manipulation_Functions::fetch_superglobal( 'post', 'chunk' );
		if ( isset( $_POST['chunks'] ) && isset( $chunk ) && preg_match( '/^[0-9]+$/', $chunk ) ) {

			if ( ! rename( $status['file'], $backupplus_dir . '/' . $final_file . '.' . $chunk . '.zip.tmp' ) ) {
				@unlink( $status['file'] );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
				echo json_encode(
					array(
						'e' => sprintf(
							/* translators: %s: Error message */
							__( 'Error: %s', 'backupplus' ),
							__( 'This file could not be uploaded', 'backupplus' )
						),
					)
				);
				exit;
			}

			$status['file'] = $backupplus_dir . '/' . $final_file . '.' . $chunk . '.zip.tmp';

		}

		$response = array();
		if ( ! isset( $_POST['chunks'] ) || ( isset( $chunk ) && preg_match( '/^[0-9]+$/', $chunk ) && $chunk == $_POST['chunks'] - 1 ) && isset( $final_file ) ) {
			if ( ! preg_match( '/^log\.[a-f0-9]{12}\.txt/i', $final_file ) && ! preg_match( '/^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-([\-a-z]+)([0-9]+)?(\.(zip|zip\.crypt|gz|gz\.crypt|tar\.zst|tar\.zst\.crypt|sql\.zst|sql\.zst\.crypt))?$/i', $final_file, $matches ) ) {
				$accept = apply_filters( 'backupplus_accept_archivename', array() );
				if ( is_array( $accept ) ) {
					foreach ( $accept as $acc ) {
						if ( preg_match( '/' . $acc['pattern'] . '/i', $final_file ) ) {
							/* translators: %s: Backup tool name */
							$response['dm'] = sprintf( __( 'This backup was created by %s, and can be imported.', 'backupplus' ), $acc['desc'] );
						}
					}
				}
				if ( empty( $response['dm'] ) ) {
					if ( isset( $status['file'] ) ) {
						@unlink( $status['file'] );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
					}                   echo json_encode(
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
			} else {
				$backupable_entities = $backupplus->get_backupable_file_entities( true );
				$type                = isset( $matches[3] ) ? $matches[3] : '';
				if ( ! preg_match( '/^log\.[a-f0-9]{12}\.txt/', $final_file ) && 'db' != $type && BackupPlus_Credential_Key_Store::BACKUP_ENTITY !== $type && ! BackupPlus_Backup_Sources::custom( $type ) && ! isset( $backupable_entities[ $type ] ) ) {
					if ( isset( $status['file'] ) ) {
						@unlink( $status['file'] );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
					}                   echo json_encode(
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

			// Final chunk? If so, then stich it all back together
			if ( isset( $_POST['chunk'] ) && $_POST['chunk'] == $_POST['chunks'] - 1 && ! empty( $final_file ) ) {
				if ( $wh = fopen( $backupplus_dir . '/' . $final_file, 'wb' ) ) {
					for ( $i = 0; $i < $_POST['chunks']; $i++ ) {
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
					$status['file'] = $backupplus_dir . '/' . $final_file;
					if ( '.tar' == substr( $final_file, -4, 4 ) ) {
						if ( file_exists( $status['file'] . '.gz' ) ) {
							unlink( $status['file'] . '.gz' );
						}
						if ( file_exists( $status['file'] . '.bz2' ) ) {
							unlink( $status['file'] . '.bz2' );
						}
					} elseif ( '.tar.gz' == substr( $final_file, -7, 7 ) ) {
						if ( file_exists( substr( $status['file'], 0, strlen( $status['file'] ) - 3 ) ) ) {
							unlink( substr( $status['file'], 0, strlen( $status['file'] ) - 3 ) );
						}
						if ( file_exists( substr( $status['file'], 0, strlen( $status['file'] ) - 3 ) . '.bz2' ) ) {
							unlink( substr( $status['file'], 0, strlen( $status['file'] ) - 3 ) . '.bz2' );
						}
					} elseif ( '.tar.bz2' == substr( $final_file, -8, 8 ) ) {
						if ( file_exists( substr( $status['file'], 0, strlen( $status['file'] ) - 4 ) ) ) {
							unlink( substr( $status['file'], 0, strlen( $status['file'] ) - 4 ) );
						}
						if ( file_exists( substr( $status['file'], 0, strlen( $status['file'] ) - 4 ) . '.gz' ) ) {
							unlink( substr( $status['file'], 0, strlen( $status['file'] ) - 3 ) . '.gz' );
						}
					}
				}
			}
		}

		// send the uploaded file url in response
		$response['m'] = $status['url'];
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

		add_filter( 'upload_dir', array( $this, 'upload_dir' ) );
		add_filter( 'sanitize_file_name', array( $this, 'sanitize_file_name' ) );
		// handle file upload

		$farray = array(
			'test_form' => true,
			'action'    => 'plupload_action2',
		);

		$farray['test_type'] = false;
		$farray['ext']       = 'crypt';
		$farray['type']      = 'application/octet-stream';

		if ( isset( $_POST['chunks'] ) ) {
			// $farray['ext'] = 'zip';
			// $farray['type'] = 'application/zip';
		} else {
			$farray['unique_filename_callback'] = array( $this, 'unique_filename_callback' );
		}
		$file   = BackupPlus_Manipulation_Functions::fetch_superglobal( 'files', 'async-upload', array(), false, 'array' );
		$status = wp_handle_upload( $file, $farray );
		remove_filter( 'upload_dir', array( $this, 'upload_dir' ) );
		remove_filter( 'sanitize_file_name', array( $this, 'sanitize_file_name' ) );

		if ( isset( $status['error'] ) ) {
			die( 'ERROR: ' . wp_kses_post( $status['error'] ) );
		}

		// If this was the chunk, then we should instead be concatenating onto the final file
		$chunk = BackupPlus_Manipulation_Functions::fetch_superglobal( 'post', 'chunk' );
		if ( isset( $_POST['chunks'] ) && isset( $chunk ) && preg_match( '/^[0-9]+$/', $chunk ) ) {
			$post_name  = BackupPlus_Manipulation_Functions::fetch_superglobal( 'post', 'name' );
			$final_file = basename( $post_name );
			rename( $status['file'], $backupplus_dir . '/' . $final_file . '.' . $chunk . '.zip.tmp' );
			$status['file'] = $backupplus_dir . '/' . $final_file . '.' . $chunk . '.zip.tmp';
		}

		if ( ! isset( $_POST['chunks'] ) || ( isset( $chunk ) && $chunk == $_POST['chunks'] - 1 ) ) {
			if ( ! preg_match( '/^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-(?:db[0-9]*\.(?:gz|sql\.zst)|[\-a-z]+[0-9]*\.(?:zip|tar\.zst))\.crypt$/i', $final_file ) ) {

				@unlink( $status['file'] );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
				echo 'ERROR:' . wp_kses_post( __( 'Bad filename format - this does not look like an encrypted backup archive created by BackupPlus or UpdraftPlus', 'backupplus' ) );
				exit;
			}

			// Final chunk? If so, then stich it all back together
			if ( isset( $chunk ) && $chunk == $_POST['chunks'] - 1 && isset( $final_file ) ) {
				if ( $wh = fopen( $backupplus_dir . '/' . $final_file, 'wb' ) ) {
					for ( $i = 0; $i < $_POST['chunks']; $i++ ) {
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
		}

		// send the uploaded file url in response
		if ( isset( $final_file ) ) {
			echo 'OK:' . wp_kses_post( $final_file );
		}
		exit;
	}
}
