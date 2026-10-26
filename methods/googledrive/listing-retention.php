<?php

/** Resolving folders, listing remote backups and deleting them for retention. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_GoogleDrive_Listing_Retention {

	/**
	 * Get the Google folder ID for the root of the drive
	 *
	 * @return String|Integer
	 */
	private function root_id() {
		return $this->get_storage()->about->get()->getRootFolderId();
	}

	/**
	 * Get folder id from path
	 *
	 * @param String  $path        folder path
	 * @param Boolean $one_only    if false, then will be returned as a list (Google Drive allows multiple entities with the same name)
	 * @param Integer $retry_count how many times to retry upon a network failure
	 *
	 * @return Array|String|Integer|Boolean internal id of the Google Drive folder (or list of them if $one_only was false), or false upon failure
	 */
	public function id_from_path( $path, $one_only = true, $retry_count = 3 ) {
		$storage = $this->get_storage();

		try {

			while ( '/' == substr( $path, 0, 1 ) ) {
				$path = substr( $path, 1 );
			}

			$cache_key = empty( $path ) ? '/' : ( $one_only ? $path : 'multi:' . $path );
			if ( isset( $this->ids_from_paths[ $this->get_instance_id() ][ $cache_key ] ) ) {
				return $this->ids_from_paths[ $this->get_instance_id() ][ $cache_key ];
			}

			$current_parent_id = $this->root_id();
			$current_path      = '/';

			if ( ! empty( $path ) ) {
				$nodes = explode( '/', $path );
				foreach ( $nodes as $element ) {
					$found     = array();
					$sub_items = $this->get_subitems( $current_parent_id, 'dir', $element );

					foreach ( $sub_items as $item ) {
						try {
							if ( $item->getTitle() == $element ) {
								$current_path               .= $element . '/';
								$current_parent_id           = $item->getId();
								$found[ $current_parent_id ] = strtotime( $item->getCreatedDate() );
							}
						} catch ( Exception $e ) {
							$this->log( 'id_from_path: exception: ' . $e->getMessage() . ' (line: ' . $e->getLine() . ', file: ' . $e->getFile() . ')' );
						}
					}

					if ( count( $found ) > 1 ) {
						asort( $found );
						reset( $found );
						$current_parent_id = key( $found );
					} elseif ( empty( $found ) ) {
						$ref = new UDP_Google_Service_Drive_ParentReference();
						$ref->setId( $current_parent_id );
						$dir = new UDP_Google_Service_Drive_DriveFile();
						$dir->setMimeType( 'application/vnd.google-apps.folder' );
						$dir->setParents( array( $ref ) );
						$dir->setTitle( $element );
						$this->log( 'creating path: ' . $current_path . $element );
						$dir               = $storage->files->insert(
							$dir,
							array( 'mimeType' => 'application/vnd.google-apps.folder' )
						);
						$current_path     .= $element . '/';
						$current_parent_id = $dir->getId();
					}
				}
			}

			if ( empty( $this->ids_from_paths[ $this->get_instance_id() ] ) ) {
				$this->ids_from_paths[ $this->get_instance_id() ] = array();
			}
			$this->ids_from_paths[ $this->get_instance_id() ][ $cache_key ] = ( $one_only || empty( $found ) || 1 == count( $found ) ) ? $current_parent_id : $found;

			return $this->ids_from_paths[ $this->get_instance_id() ][ $cache_key ];

		} catch ( Exception $e ) {
			$msg = $e->getMessage();
			$this->log( 'id_from_path failure: exception (' . get_class( $e ) . '): ' . $msg . ' (line: ' . $e->getLine() . ', file: ' . $e->getFile() . ')' );
			if ( is_a( $e, 'UDP_Google_Service_Exception' ) && false !== strpos( $msg, 'Invalid json in service response' ) && function_exists( 'mb_strpos' ) ) {
				// Aug 2015: saw a case where the gzip-encoding was not removed from the result
				// https://stackoverflow.com/questions/10975775/how-to-determine-if-a-string-was-compressed
				// @codingStandardsIgnoreLine
				$is_gzip = ( false !== mb_strpos( $msg, "\x1f\x8b\x08" ) ); // phpcs:ignore PHPCompatibility.ParameterValues.NewIconvMbstringCharsetDefault.NotSet -- using the default encoding for the mb_strpos() function.
				if ( $is_gzip ) {
					$this->log( 'Error: Response appears to be gzip-encoded still; something is broken in the client HTTP stack, and you should define BACKUPPLUS_GOOGLEDRIVE_DISABLEGZIP as true in your wp-config.php to overcome this.' );
				}
			}
			--$retry_count;
			$this->log( "id_from_path: retry ($retry_count)" );
			if ( $retry_count > 0 ) {
				$delay_in_seconds = defined( 'BACKUPPLUS_GOOGLE_DRIVE_GET_FOLDER_ID_SECOND_RETRY_DELAY' ) ? BACKUPPLUS_GOOGLE_DRIVE_GET_FOLDER_ID_SECOND_RETRY_DELAY : 5 - $retry_count;
				sleep( $delay_in_seconds );
				return $this->id_from_path( $path, $one_only, $retry_count );
			}
			return false;
		}
	}

	/**
	 * Runs upon the WP action backupplus_prune_retained_backups_finished
	 */
	public function prune_retained_backups_finished() {
		if ( empty( $this->multi_directories[ $this->get_instance_id() ] ) || count( $this->multi_directories[ $this->get_instance_id() ] ) < 2 ) {
			return;
		}
		$storage = $this->bootstrap();
		if ( false == $storage || is_wp_error( $storage ) ) {
			return;
		}
		foreach ( array_keys( $this->multi_directories[ $this->get_instance_id() ] ) as $drive_id ) {
			if ( ! isset( $oldest_reference ) ) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable -- The variable is defined below.
				$oldest_id        = $drive_id;
				$oldest_reference = new UDP_Google_Service_Drive_ParentReference();
				$oldest_reference->setId( $oldest_id );
				continue;
			}

			// All found files should be moved to the oldest folder

			try {
				$sub_items = $this->get_subitems( $drive_id, 'file' );
			} catch ( Exception $e ) {
				$this->log( 'list files: failed to access chosen folder:  ' . $e->getMessage() . ' (line: ' . $e->getLine() . ', file: ' . $e->getFile() . ')' );
			}

			$without_errors = true;

			foreach ( $sub_items as $item ) {
				$title = '(unknown)';
				try {
					$title = $item->getTitle();

					$this->log( "Moving: $title (" . $item->getId() . ") from duplicate folder $drive_id to $oldest_id" );

					$file = new UDP_Google_Service_Drive_DriveFile();
					$file->setParents( array( $oldest_reference ) );

					$storage->files->patch( $item->getId(), $file );

				} catch ( Exception $e ) {
					$this->log( 'move: exception: ' . $e->getMessage() . ' (line: ' . $e->getLine() . ', file: ' . $e->getFile() . ')' );
					$without_errors = false;
					continue;
				}
			}

			if ( $without_errors ) {
				if ( ! empty( $sub_items ) ) {
					try {
						$sub_items = $this->get_subitems( $drive_id, 'file' );
					} catch ( Exception $e ) {
						$this->log( 'list files: failed to access chosen folder:  ' . $e->getMessage() . ' (line: ' . $e->getLine() . ', file: ' . $e->getFile() . ')' );
					}
				}
				if ( empty( $sub_items ) ) {
					try {
						$storage->files->delete( $drive_id );
						$this->log( "removed empty duplicate folder ($drive_id)" );
					} catch ( Exception $e ) {
						$this->log( "delete empty duplicate folder ($drive_id): exception: " . $e->getMessage() . ' (line: ' . $e->getLine() . ', file: ' . $e->getFile() . ')' );
						continue;
					}
				}
			}
		}
	}

	/**
	 * Get the Google Drive internal ID
	 *
	 * @param Array   $opts     - storage instance options
	 * @param Boolean $one_only - whether to potentially return them all if there is more than one
	 *
	 * @return String|Array
	 */
	private function get_parent_id( $opts, $one_only = true ) {

		$storage = $this->get_storage();

		$filtered = apply_filters( 'backupplus_googledrive_parent_id', false, $opts, $storage, $this, $one_only );
		if ( ! empty( $filtered ) ) {
			return $filtered;
		}
		if ( isset( $opts['parentid'] ) ) {
			if ( empty( $opts['parentid'] ) ) {
				return $this->root_id();
			} else {
				$parent = is_array( $opts['parentid'] ) ? $opts['parentid']['id'] : $opts['parentid'];
			}
		} else {
			$folder = ! empty( $opts['folder'] ) ? $opts['folder'] : 'BackupPlus';
			$parent = $this->id_from_path( $folder, $one_only );
		}
		return empty( $parent ) ? $this->root_id() : $parent;
	}

	/**
	 * List files or folders on Google Drive.
	 *
	 * @param string $match      The file or folder name to search for (default: 'backup_').
	 * @param bool   $list_files Whether to search for files (true) or folders (false) (default: true).
	 *
	 * @return array|WP_Error  An array of results containing information about matching files or folders,
	 *                        or a WP_Error object if there are missing settings,
	 *                        or an error occurs while accessing Google Drive.
	 *                        Each result is represented as an associative array with the following keys:
	 *                        - 'name' (string): The name of the file or folder.
	 *                        - 'size' (int, optional): The size of the file in bytes (only for files).
	 *                        - 'id' (string, optional): The unique identifier of the folder (only for folders).
	 */
	public function list_files_or_folders( $match = 'backup_', $list_files = true ) {

		$opts = $this->get_options();

		$use_master = $this->use_master( $opts );

		if ( ! $use_master ) {
			if ( empty( $opts['secret'] ) || empty( $opts['clientid'] ) || empty( $opts['clientid'] ) ) {
				return new WP_Error(
					'no_settings',
					sprintf(
					/* translators: %s: Storage provider name */
						__( 'No %s settings were found', 'backupplus' ),
						__( 'Google Drive', 'backupplus' )
					)
				);
			}
		} else {
			if ( empty( $opts['user_id'] ) || empty( $opts['tmp_access_token'] ) ) {
				return new WP_Error(
					'no_settings',
					sprintf(
					/* translators: %s: Storage provider name */
						__( 'No %s settings were found', 'backupplus' ),
						__( 'Google Drive', 'backupplus' )
					)
				);
			}
		}

		$storage = $this->bootstrap();
		if ( is_wp_error( $storage ) || false == $storage ) {
			return $storage;
		}

		try {
			if ( $list_files ) {
				$parent_id = $this->get_parent_id( $opts );
				$sub_items = $this->get_subitems( $parent_id, 'file' );
			} else {
				$sub_items = $this->get_subitems( $match, 'dir', '' );
			}
		} catch ( Exception $e ) {
			return new WP_Error( __( 'Google Drive list files: failed to access parent folder', 'backupplus' ) . ':  ' . $e->getMessage() . ' (line: ' . $e->getLine() . ', file: ' . $e->getFile() . ')' );
		}

		$results = array();

		foreach ( $sub_items as $item ) {
			$title = '(unknown)';
			try {
				$title = $item->getTitle();
				if ( $list_files ) {
					if ( 0 === strpos( $title, $match ) ) {
						$results[] = array(
							'name' => $title,
							'size' => $item->getFileSize(),
						);
					}
				} else {
					$results[] = array(
						'name' => $title,
						'id'   => $item->getId(),
					);
				}
			} catch ( Exception $e ) {
				$this->log( 'list: exception: ' . $e->getMessage() . ' (line: ' . $e->getLine() . ', file: ' . $e->getFile() . ')' );
				continue;
			}
		}

		return $results;
	}

	/**
	 * List folders in Google Drive.
	 *
	 * @param string $search - The folder to search for (default: 'root').
	 * @return array $results - An array of folder information.
	 */
	public function list_folders( $search = 'root' ) {
		return $this->list_files_or_folders( $search, false );
	}

	/**
	 * List files in Google Drive.
	 *
	 * @param string $search - The files to search for (default: 'backup_').
	 * @return array $results - An array of file information.
	 */
	public function listfiles( $search = 'backup_' ) {
		return $this->list_files_or_folders( $search );
	}

	/**
	 * Returns array of UDP_Google_Service_Drive_DriveFile objects
	 *
	 * @param  String $parent_id This is the Parent ID
	 * @param  String $type      This is the type of file or directory but by default it is set to 'any' unless specified
	 * @param  String $match     This will specify which match is used for the SQL but by default it is set to 'backup_' unless specified
	 *
	 * @return Array - list of UDP_Google_Service_Drive_DriveFile items
	 */
	private function get_subitems( $parent_id, $type = 'any', $match = 'backup_' ) {

		$storage = $this->get_storage();

		$q = '"' . $parent_id . '" in parents and trashed = false';

		if ( 'dir' == $type ) {
			$q .= ' and mimeType = "application/vnd.google-apps.folder"';
		} elseif ( 'file' == $type ) {
			$q .= ' and mimeType != "application/vnd.google-apps.folder"';
		}
		// We used to use 'contains' in both cases, but this exposed some bug that might be in the SDK or at the Google end - a result that matched for = was not returned with contains
		if ( ! empty( $match ) ) {
			if ( 'backup_' == $match ) {
				$q .= " and title contains '$match'";
			} else {
				$q .= " and title = '$match'";
			}
		}

		$result     = array();
		$page_token = null;

		do {
			try {
				// Default for maxResults is 100
				$parameters = array(
					'q'          => $q,
					'maxResults' => 200,
				);
				if ( $page_token ) {
					$parameters['pageToken'] = $page_token;
				}
				$files = $storage->files->listFiles( $parameters );

				$result     = array_merge( $result, $files->getItems() );
				$page_token = $files->getNextPageToken();
			} catch ( Exception $e ) {
				$this->log( 'get_subitems: An error occurred (will not fetch further): ' . $e->getMessage() );
				$page_token = null;
			}
		} while ( $page_token );

		return $result;
	}

	/**
	 * Delete a single file from the service using GoogleDrive API
	 *
	 * @param Array|String $files    - array of file names to delete
	 * @param Array        $data     - unused here
	 * @param Array        $sizeinfo - unused here
	 * @return Boolean|String - either a boolean true or an error code string
	 */
	public function delete( $files, $data = null, $sizeinfo = array() ) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $data and $sizeinfo unused

		if ( is_string( $files ) ) {
			$files = array( $files );
		}

		$storage = $this->bootstrap();
		if ( is_wp_error( $storage ) ) {
			$this->log( 'delete: failed due to storage error: ' . $storage->get_error_code() . ' (' . $storage->get_error_message() . ')' );
			return 'service_unavailable';
		}

		if ( false == $storage ) {
			return $storage;
		}

		$opts = $this->get_options();

		try {
			$parent_id = $this->get_parent_id( $opts );
			$sub_items = $this->get_subitems( $parent_id, 'file' );
		} catch ( Exception $e ) {
			$this->log( 'delete: failed to access parent folder: ' . $e->getMessage() . ' (line: ' . $e->getLine() . ', file: ' . $e->getFile() . ')' );
			return 'container_access_error';
		}

		$ret = true;

		foreach ( $sub_items as $item ) {
			$title = '(unknown)';
			try {
				$title = $item->getTitle();
				if ( in_array( $title, $files ) ) {
					$storage->files->delete( $item->getId() );
					$this->log( "$title: Deletion successful" );
					if ( ( $key = array_search( $title, $files ) ) !== false ) {
						unset( $files[ $key ] );
					}
				}
			} catch ( Exception $e ) {
				$this->log( 'delete: exception: ' . $e->getMessage() . ' (line: ' . $e->getLine() . ', file: ' . $e->getFile() . ')' );
				$ret = 'file_delete_error';
				continue;
			}
		}

		foreach ( $files as $file ) {
			$this->log( "$file: Deletion failed: file was not found" );
		}

		return $ret;
	}
}
