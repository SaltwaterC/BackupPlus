<?php

/** Directory and zip listings for the file browser tree, and zip file downloads. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_WPAdmin_Commands_Jstree {

	/**
	 * This is an handler function that checks what entity has been specified in the $params and calls the required method
	 *
	 * @param  [array] $params this is an array of parameters sent via ajax it can include various things depending on what has called this method, this method only cares about the entity parameter which is used to call the correct method and return tree nodes based on that
	 * @return [array] returns an array of jstree nodes
	 */
	public function get_jstree_directory_nodes( $params ) {

		if ( 'filebrowser' == $params['entity'] ) {
			$node_array = $this->_backupplus_jstree_directory( $params );
		} elseif ( 'zipbrowser' == $params['entity'] ) {
			$node_array = $this->_backupplus_jstree_zip( $params );
		} else {
			$node_array = apply_filters( 'backupplus_jstree_' . $params['entity'], array(), $params );
		}
		return empty( $node_array['error'] ) ? array( 'nodes' => $node_array ) : $node_array;
	}

	/**
	 * This creates an array of nodes, built from either ABSPATH or the given directory ready to be returned to the jstree object.
	 *
	 * @param  [array] $params this is an array of parameters sent via ajax it can include the following:
	 * node - this is a jstree node object containing information about the selected node
	 * path - this is a path if provided this will be used to build the tree otherwise ABSPATH is used
	 * drop_directory - this is a boolean that if set to true will drop one directory level off the path this is used so that you can move above the current root directory
	 * @return [array] returns an array of jstree nodes
	 */
	private function _backupplus_jstree_directory( $params ) {
		$node_array = array();

		// # is the root node if it's the root node then this is the first call so create a parent node otherwise it's a child node and we should get the path from the node id
		if ( '#' == $params['node']['id'] ) {
			$path = ABSPATH;

			if ( ! empty( $params['path'] ) && is_dir( $params['path'] ) && is_readable( $params['path'] ) ) {
				$path = $params['path'];
			}
			$one_dir_up = dirname( $path );

			if ( ! empty( $params['drop_directory'] ) && true == $params['drop_directory'] && is_readable( $one_dir_up ) ) {
				$path = $one_dir_up;
			}
			if ( empty( $params['skip_root_node'] ) ) {
				$node_array[] = array(
					'text'     => basename( $path ),
					'children' => true,
					'id'       => $path,
					'icon'     => 'jstree-folder',
					'state'    => array(
						'opened' => true,
					),
				);
			}
		} else {
			$path = $params['node']['id'];
		}

		$page = empty( $params['page'] ) ? '' : $params['page'];

		if ( $dh = opendir( $path ) ) {
			$path = rtrim( $path, DIRECTORY_SEPARATOR );

			$skip_paths = array( '.', '..' );

			while ( ( $value = readdir( $dh ) ) !== false ) {
				if ( ! in_array( $value, $skip_paths ) ) {
					if ( is_dir( $path . DIRECTORY_SEPARATOR . $value ) ) {
						$node_array[] = array(
							'text'     => $value,
							'children' => true,
							'id'       => BackupPlus_Manipulation_Functions::wp_normalize_path( $path . DIRECTORY_SEPARATOR . $value ),
							'icon'     => 'jstree-folder',
						);
					} elseif ( empty( $params['directories_only'] ) && 'restore' != $page && is_file( $path . DIRECTORY_SEPARATOR . $value ) ) {
						$node_array[] = array(
							'text'     => $value,
							'children' => false,
							'id'       => BackupPlus_Manipulation_Functions::wp_normalize_path( $path . DIRECTORY_SEPARATOR . $value ),
							'type'     => 'file',
							'icon'     => 'jstree-file',
						);
					}
				}
			}
		} else {
			/* translators: %s: Directory path */
			$node_array['error'] = sprintf( __( 'Failed to open directory: %s.', 'backupplus' ), $path ) . ' ' .
			__( 'This is normally caused by file permissions.', 'backupplus' );
		}

		return $node_array;
	}

	/**
	 * This creates an array of nodes, built from a unzipped zip file structure.
	 *
	 * @param  [array] $params this is an array of parameters sent via ajax it can include the following:
	 * node - this is a jstree node object containing information about the selected node
	 * timestamp - this is the backup timestamp and is used to get the backup archive
	 * type - this is the type of backup and is used to get the backup archive
	 * findex - this is the index used to get the correct backup archive if theres more than one of a single archive type
	 * @return [array] returns an array of jstree nodes
	 */
	private function _backupplus_jstree_zip( $params ) {

		$backupplus = $this->_backupplus;

		$node_array = array();

		$zip_object = $backupplus->get_zip_object_name();

		// Retrieve the information from our backup history
		$backup_history = BackupPlus_Backup_History::get_history();

		if ( ! isset( $backup_history[ $params['timestamp'] ][ $params['type'] ] ) ) {
			return array( 'error' => __( 'Backup set not found', 'backupplus' ) );
		}

		// Base name
		$file = $backup_history[ $params['timestamp'] ][ $params['type'] ];

		// Get date in human readable form
		$pretty_date = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $params['timestamp'] ), 'M d, Y G:i' );

		$backupable_entities = $backupplus->get_backupable_file_entities( true, true );

		// Check the file type and set the name in a more friendly way
		$archive_name = isset( $backupable_entities[ $params['type'] ]['description'] ) ? $backupable_entities[ $params['type'] ]['description'] : $params['type'];

		if ( substr( $params['type'], 0, 2 ) === 'db' ) {
			$archive_name = __( 'Extra database', 'backupplus' ) . ' ' . substr( $params['type'], 3, 1 );
		}
		if ( 'db' == $params['type'] ) {
			$archive_name = __( 'Database', 'backupplus' );
		}
		if ( 'more' == $params['type'] ) {
			$archive_name = $backupable_entities[ $params['type'] ]['shortdescription'];
		}
		if ( 'wpcore' == $params['type'] ) {
			$archive_name = __( 'WordPress Core', 'backupplus' );
		}

		$archive_set = ( $params['findex'] + 1 ) . '/' . sizeof( $file );

		if ( '1/1' == $archive_set ) {
			$archive_set = '';
		}

		$parent_name = $archive_name . ' ' . __( 'archive', 'backupplus' ) . ' ' . $archive_set . ' ' . $pretty_date;

		// Deal with multi-archive sets
		if ( is_array( $file ) ) {
			$file = $file[ $params['findex'] ];
		}

		// Where it should end up being downloaded to
		$fullpath = $backupplus->backups_dir_location() . '/' . $file;

		if ( file_exists( $fullpath ) && is_readable( $fullpath ) && filesize( $fullpath ) > 0 ) {

			$node_array[] = array(
				'text'    => $parent_name,
				'parent'  => '#',
				'id'      => $parent_name,
				'icon'    => 'jstree-folder',
				'state'   => array( 'opened' => true ),
				'li_attr' => array( 'path' => $parent_name ),
			);

			$zip = new $zip_object();

			$zip_opened = $zip->open( $fullpath );

			if ( true !== $zip_opened ) {
				return array( 'error' => 'BackupPlus: opening zip (' . $fullpath . '): failed to open this zip file (object=' . $zip_object . ', code: ' . $zip_opened . ')' );
			} else {

				$numfiles = $zip->numFiles;

				if ( false === $numfiles ) {
					return array( 'error' => 'BackupPlus: reading zip: ' . $zip->last_error );
				}

				for ( $i = 0; $i < $numfiles; $i++ ) {
					$si = $zip->statIndex( $i );

					// Fix for windows being unable to build jstree due to different directory separators being used
					$si['name'] = str_replace( '/', DIRECTORY_SEPARATOR, $si['name'] );

					// if it's a dot then we don't want to append this as it will break the ids and the tree structure
					if ( '.' == dirname( $si['name'] ) ) {
						$node_id = $parent_name;
					} else {
						$node_id = $parent_name . DIRECTORY_SEPARATOR . dirname( $si['name'] ) . DIRECTORY_SEPARATOR;
					}

					$extension = substr( strrchr( $si['name'], '.' ), 1 );

					if ( 0 == $si['size'] && empty( $extension ) ) {
						$node_array[] = array(
							'text'    => basename( $si['name'] ),
							'parent'  => $node_id,
							'id'      => $parent_name . DIRECTORY_SEPARATOR . $si['name'],
							'icon'    => 'jstree-folder',
							'li_attr' => array(
								'path' => $parent_name . DIRECTORY_SEPARATOR . $si['name'],
							),
						);
					} else {
						$node_array[] = array(
							'text'    => basename( $si['name'] ),
							'parent'  => $node_id,
							'id'      => $parent_name . DIRECTORY_SEPARATOR . $si['name'],
							'type'    => 'file',
							'icon'    => 'jstree-file',
							'li_attr' => array(
								'path' => $parent_name . DIRECTORY_SEPARATOR . $si['name'],
								'size' => BackupPlus_Manipulation_Functions::convert_numeric_size_to_text( $si['size'] ),
							),
						);
					}
				}

				// check if this is an upload archive if it is add a 'uploads' folder so that the children can attach to it
				if ( 'uploads' == $params['type'] ) {
					$node_array[] = array(
						'text'    => 'uploads',
						'parent'  => $parent_name,
						'id'      => $parent_name . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR,
						'icon'    => 'jstree-folder',
						'li_attr' => array(
							'path' => $parent_name . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR,
						),
					);
				}

				@$zip->close();// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.
			}
		}

		return $node_array;
	}

	/**
	 * Return information on the zipfile download
	 *
	 * @param Array $params - details on the download; keys: type, findex, path, timestamp
	 *
	 * @return Array
	 */
	public function get_zipfile_download( $params ) {
		return apply_filters( 'backupplus_command_get_zipfile_download', array( 'error' => 'BackupPlus: command (get_zipfile_download) not installed (are you missing an add-on?)' ), $params );
	}
}
