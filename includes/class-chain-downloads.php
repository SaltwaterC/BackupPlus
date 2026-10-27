<?php

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

/**
 * Recovery points of an incremental backup chain as a downloadable list.
 *
 * A chain stores several files per point: the snapshot or log of a database PITR chain with its manifest and locator, or
 * one archive per incremental backup of a file entity. Browsers get one row per point, listed a page at a time, and one
 * archive for whichever points were chosen, instead of one transfer per file.
 */
class BackupPlus_Chain_Downloads {

	const MAX_PAGE = 200;

	/** Label of a database PITR recovery point, matching the restore dialog. */
	public static function label( $point, $latest ) {
		$label  = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $point['timestamp'] ), 'M d, Y G:i:s' ) . ' - ';
		$label .= $point['increment'] ? sprintf( __( 'database incremental %d', 'backupplus' ), $point['increment'] ) : __( 'full database backup', 'backupplus' );
		if ( $latest ) {
			$label .= ' (' . __( 'latest', 'backupplus' ) . ')';
		}
		return $label;
	}

	private static function file_label( $timestamp, $position, $latest ) {
		$label  = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $timestamp ), 'M d, Y G:i:s' ) . ' - ';
		$label .= $position ? sprintf( __( 'incremental backup %d', 'backupplus' ), $position ) : __( 'full backup', 'backupplus' );
		if ( $latest ) {
			$label .= ' (' . __( 'latest', 'backupplus' ) . ')';
		}
		return $label;
	}

	/** Whether the entity of this history row is listed by recovery point rather than as a flat list of files. */
	public static function supported( $backup, $type ) {
		if ( 'db' === $type ) {
			return ! empty( $backup['pitr']['points'] ) && ! empty( $backup['pitr']['downloads'] );
		}
		return is_string( $type ) && preg_match( '/^[a-z][a-z0-9_-]*$/D', $type )
			&& isset( $backup[ $type ] ) && is_array( $backup[ $type ] ) && count( $backup[ $type ] ) > 1
			&& ! empty( $backup['incremental'] ) && ! empty( $backup['incremental_sets'] ) && is_array( $backup['incremental_sets'] ) && count( $backup['incremental_sets'] ) > 1;
	}

	/** Every stored file of the entity by its index in the backup history: name and recorded size. */
	public static function files( $backup, $type ) {
		if ( 'db' === $type ) {
			return isset( $backup['pitr']['downloads'] ) ? $backup['pitr']['downloads'] : array();
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

	/** Every recovery point of a history row, newest first, with the files that belong to it. */
	public static function points( $backup, $type ) {
		if ( ! self::supported( $backup, $type ) ) {
			return array();
		}
		$files  = self::files( $backup, $type );
		$points = array();
		if ( 'db' === $type ) {
			$grouped = array();
			foreach ( $files as $findex => $file ) {
				$increment = isset( $file['increment'] ) ? (int) $file['increment'] : 0;
				if ( ! isset( $grouped[ $increment ] ) ) {
					$grouped[ $increment ] = array();
				}
				$grouped[ $increment ][] = (int) $findex;
			}
			$stored = array_values( $backup['pitr']['points'] );
			foreach ( $stored as $position => $point ) {
				$increment = (int) $point['increment'];
				if ( isset( $grouped[ $increment ] ) ) {
					$points[] = self::point( $files, $increment, (int) $point['timestamp'], self::label( $point, count( $stored ) - 1 === $position ), $grouped[ $increment ] );
				}
			}
		} else {
			$indexes = array();
			foreach ( $backup[ $type ] as $index => $name ) {
				$indexes[ $name ] = (int) $index;
			}
			$sets = $backup['incremental_sets'];
			ksort( $sets );
			$timestamps = array_keys( $sets );
			foreach ( $timestamps as $position => $timestamp ) {
				$findexes = array();
				foreach ( isset( $sets[ $timestamp ][ $type ] ) ? (array) $sets[ $timestamp ][ $type ] : array() as $name ) {
					if ( isset( $indexes[ $name ] ) ) {
						$findexes[] = $indexes[ $name ];
					}
				}
				if ( $findexes ) {
					$points[] = self::point( $files, $position, (int) $timestamp, self::file_label( $timestamp, $position, count( $timestamps ) - 1 === $position ), $findexes );
				}
			}
		}
		return array_reverse( $points );
	}

	private static function point( $files, $id, $timestamp, $label, $findexes ) {
		$size      = 0;
		$encrypted = false;
		foreach ( $findexes as $findex ) {
			$size     += isset( $files[ $findex ]['size'] ) && is_numeric( $files[ $findex ]['size'] ) ? (int) $files[ $findex ]['size'] : 0;
			$encrypted = $encrypted || BackupPlus_Encryption::is_file_encrypted( $files[ $findex ]['name'] );
		}
		return array(
			'id'        => $id,
			'timestamp' => $timestamp,
			'label'     => $label,
			'iso'       => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ), 'Y-m-d H:i:s' ),
			'size'      => $size,
			'files'     => $findexes,
			'encrypted' => $encrypted,
		);
	}

	/**
	 * One page of the list, optionally narrowed by a date or label fragment.
	 *
	 * @return array total, ids of every matching point, all ids of the chain, the rows of the requested page and whether any archive is encrypted.
	 */
	public static function page( $backup, $type, $search, $offset, $limit ) {
		$points = self::points( $backup, $type );
		$all    = array();
		$locked = false;
		foreach ( $points as $point ) {
			$all[]  = $point['id'];
			$locked = $locked || $point['encrypted'];
		}
		$search = strtolower( trim( (string) $search ) );
		if ( '' !== $search ) {
			$points = array_values(
				array_filter(
					$points,
					function ( $point ) use ( $search ) {
						return false !== strpos( strtolower( $point['label'] . ' ' . $point['iso'] ), $search );
					}
				)
			);
		}
		$ids  = array();
		$rows = array();
		foreach ( $points as $position => $point ) {
			$ids[] = $point['id'];
			if ( $position >= $offset && $position < $offset + $limit ) {
				$rows[] = array(
					'id'     => $point['id'],
					'label'  => $point['label'],
					'size'   => BackupPlus_Manipulation_Functions::convert_numeric_size_to_text( $point['size'] ),
					'files'  => count( $point['files'] ),
					// The server can only read an unencrypted archive, so only a single plain archive can be browsed.
					'browse' => 'db' !== $type && ! $point['encrypted'] && 1 === count( $point['files'] ) ? $point['files'][0] : null,
				);
			}
		}
		return array(
			'total'     => count( $ids ),
			'ids'       => $ids,
			'all'       => $all,
			'rows'      => $rows,
			'encrypted' => $locked,
		);
	}

	/** The history row and the chosen points, validated, oldest first. */
	private static function select( $timestamp, $type, $selection ) {
		$history = BackupPlus_Backup_History::get_history();
		if ( ! isset( $history[ $timestamp ] ) || ! self::supported( $history[ $timestamp ], $type ) ) {
			throw new RuntimeException( __( 'This backup has no recovery points to download.', 'backupplus' ) );
		}
		$backup = $history[ $timestamp ];
		$wanted = array_unique( array_map( 'intval', is_array( $selection ) ? $selection : preg_split( '/\D+/', (string) $selection, -1, PREG_SPLIT_NO_EMPTY ) ) );
		if ( ! $wanted ) {
			throw new RuntimeException( __( 'Select at least one recovery point.', 'backupplus' ) );
		}
		$chosen = array();
		foreach ( self::points( $backup, $type ) as $point ) {
			if ( in_array( $point['id'], $wanted, true ) ) {
				$chosen[ $point['id'] ] = $point;
			}
		}
		if ( count( $chosen ) !== count( $wanted ) ) {
			throw new RuntimeException( __( 'Some of the selected recovery points no longer exist.', 'backupplus' ) );
		}
		ksort( $chosen );
		return array( $backup, $chosen );
	}

	/** Whether the backup was sent to remote storage, so that deleting a local copy leaves another. */
	public static function has_remote( $backup ) {
		global $backupplus;
		return (bool) array_diff( (array) $backupplus->get_canonical_service_list( isset( $backup['service'] ) ? $backup['service'] : false ), array( 'email', 'none', '' ) );
	}

	/** Remote destinations of a backup: service, instance and the key under which history records deletions. */
	private static function destinations( $backup ) {
		global $backupplus;
		$destinations = array();
		foreach ( array_diff( (array) $backupplus->get_canonical_service_list( isset( $backup['service'] ) ? $backup['service'] : false ), array( 'email', 'none', '' ) ) as $service ) {
			$instances = ! empty( $backup['service_instance_ids'][ $service ] ) ? (array) $backup['service_instance_ids'][ $service ] : array( '' );
			foreach ( $instances as $instance ) {
				$destinations[] = array(
					'service'  => $service,
					'instance' => (string) $instance,
					'key'      => $service . ':' . $instance,
				);
			}
		}
		return $destinations;
	}

	/** Destinations still holding a copy of the file, according to the deletions recorded in the history. */
	private static function remote_copies( $backup, $name, $destinations ) {
		return array_values(
			array_filter(
				$destinations,
				function ( $destination ) use ( $backup, $name ) {
					$key = $destination['key'];
					return empty( $backup['storage_removed'][ $key ] ) && empty( $backup['storage_deleted_files'][ $key ][ $name ] ) && empty( $backup['storage_delete_queue'][ $key ][ $name ] );
				}
			)
		);
	}

	private static function provider( $destination ) {
		$storage = BackupPlus_Storage_Methods_Interface::get_storage_objects_and_ids( array( $destination['service'] ) );
		if ( empty( $storage[ $destination['service'] ]['object'] ) ) {
			/* translators: %s: Storage service. */
			throw new RuntimeException( sprintf( __( 'The remote storage %s is not available.', 'backupplus' ), $destination['service'] ) );
		}
		$provider = $storage[ $destination['service'] ]['object'];
		if ( '' !== $destination['instance'] && $provider->supports_feature( 'multi_options' ) ) {
			if ( ! isset( $storage[ $destination['service'] ]['instance_settings'][ $destination['instance'] ] ) ) {
				/* translators: %s: Storage service. */
				throw new RuntimeException( sprintf( __( 'The remote storage %s is not available.', 'backupplus' ), $destination['service'] ) );
			}
			$provider->set_options( $storage[ $destination['service'] ]['instance_settings'][ $destination['instance'] ], false, $destination['instance'] );
		}
		return $provider;
	}

	/**
	 * Work out what deleting recovery points from this server or from remote storage involves.
	 *
	 * Each action only touches the location it names. Remote deletion also takes every later point, which cannot be restored without
	 * the deleted one. A point left without any copy has the same effect on the points after it, so those are removed entirely.
	 *
	 * @param string $where Either local, for the copies on this server, or remote.
	 * @return array The backup, files to delete from the server and from remote storage by history index, points removed entirely, and counts for confirmation.
	 * @throws RuntimeException When the selection is invalid or the backup is not in remote storage for a remote deletion.
	 */
	public static function plan( $timestamp, $type, $selection, $where ) {
		global $backupplus;
		if ( 'db' === $type ) {
			throw new RuntimeException( __( 'Database recovery chains are removed by deleting the whole backup.', 'backupplus' ) );
		}
		list( $backup, $chosen ) = self::select( $timestamp, $type, $selection );
		$destinations            = self::destinations( $backup );
		if ( 'remote' === $where && ! $destinations ) {
			throw new RuntimeException( __( 'This backup is not in remote storage.', 'backupplus' ) );
		}
		$known  = self::files( $backup, $type );
		$dir    = $backupplus->backups_dir_location();
		$points = array();
		foreach ( array_reverse( self::points( $backup, $type ) ) as $point ) {
			$points[ $point['id'] ] = $point;
		}
		$first = min( array_keys( $chosen ) );
		$local = array();
		$far   = array();
		foreach ( $points as $id => $point ) {
			foreach ( $point['files'] as $findex ) {
				$name = $known[ $findex ]['name'];
				if ( basename( $name ) !== $name || '' === $name ) {
					throw new RuntimeException( __( 'A recovery point has an invalid file name.', 'backupplus' ) );
				}
				$local[ $findex ] = is_file( $dir . '/' . $name ) && ! is_link( $dir . '/' . $name );
				$far[ $findex ]   = (bool) self::remote_copies( $backup, $name, $destinations );
			}
		}
		$touch = array(
			'local'  => array(),
			'remote' => array(),
		);
		$lost  = array();
		foreach ( $points as $id => $point ) {
			if ( ! isset( $chosen[ $id ] ) && ! ( 'remote' === $where && $id >= $first ) ) {
				continue;
			}
			foreach ( $point['files'] as $findex ) {
				$had = $local[ $findex ] || $far[ $findex ];
				if ( 'local' === $where && $local[ $findex ] ) {
					$touch['local'][ $findex ] = $id;
					$local[ $findex ]          = false;
				} elseif ( 'remote' === $where && $far[ $findex ] ) {
					$touch['remote'][ $findex ] = $id;
					$far[ $findex ]             = false;
				}
				if ( $had && ! $local[ $findex ] && ! $far[ $findex ] ) {
					$lost[] = $id;
				}
			}
		}
		$purge = array();
		if ( $lost ) {
			foreach ( $points as $id => $point ) {
				if ( $id >= min( $lost ) ) {
					$purge[ $id ] = $point;
					foreach ( $point['files'] as $findex ) {
						if ( $local[ $findex ] ) {
							$touch['local'][ $findex ] = $id;
						}
						if ( $far[ $findex ] ) {
							$touch['remote'][ $findex ] = $id;
						}
					}
				}
			}
		}
		return array(
			'backup'       => $backup,
			'files'        => $known,
			'touch'        => $touch,
			'purge'        => $purge,
			'destinations' => $destinations,
			'summary'      => array(
				'local'  => count( array_unique( $touch['local'] ) ),
				'remote' => count( array_unique( $touch['remote'] ) ),
				'lost'   => count( $purge ),
			),
		);
	}

	/**
	 * Delete recovery points of a file entity from this server or from remote storage, as two separate actions.
	 *
	 * @param bool $dry Only report what would be deleted.
	 * @return array Counts of the points affected, and unless dry, the files deleted, the size freed and the ids of points removed from the history.
	 * @throws RuntimeException When the selection is invalid, the backup is in use or a deletion fails; remote deletions already made are kept and recorded.
	 */
	public static function delete( $timestamp, $type, $selection, $where, $dry = false ) {
		global $backupplus;
		backupplus_try_include_file( 'includes/class-backup-deletion.php', 'include_once' );
		$plan = self::plan( $timestamp, $type, $selection, $where );
		if ( $dry ) {
			return $plan['summary'];
		}
		if ( BackupPlus_Backup_Deletion::protected_set( $timestamp, $plan['backup'] ) ) {
			throw new RuntimeException( __( 'This backup chain is active or authorizes pending updates.', 'backupplus' ) );
		}
		$dir     = $backupplus->backups_dir_location();
		$known   = $plan['files'];
		$deleted = 0;
		$bytes   = 0;
		$remote  = 0;
		$clients = array();
		foreach ( array_keys( $plan['touch']['remote'] ) as $findex ) {
			$name = $known[ $findex ]['name'];
			foreach ( self::remote_copies( $plan['backup'], $name, $plan['destinations'] ) as $destination ) {
				if ( ! isset( $clients[ $destination['key'] ] ) ) {
					$clients[ $destination['key'] ] = self::provider( $destination );
				}
				if ( true !== $clients[ $destination['key'] ]->delete( $name ) ) {
					/* translators: %s: File name. */
					throw new RuntimeException( sprintf( __( 'The file %s could not be deleted from remote storage. Try again; files already deleted are skipped.', 'backupplus' ), $name ) );
				}
				$history = BackupPlus_Backup_History::get_history();
				if ( isset( $history[ $timestamp ] ) ) {
					$history[ $timestamp ]['storage_deleted_files'][ $destination['key'] ][ $name ] = true;
					BackupPlus_Backup_History::save_history( $history );
				}
				$plan['backup']['storage_deleted_files'][ $destination['key'] ][ $name ] = true;
				$backupplus->log( "The file has been deleted from remote storage ($name)" );
			}
			++$remote;
		}
		foreach ( array_keys( $plan['touch']['local'] ) as $findex ) {
			$name = $known[ $findex ]['name'];
			$path = $dir . '/' . $name;
			if ( is_file( $path ) && ! is_link( $path ) ) {
				$size = filesize( $path );
				if ( ! unlink( $path ) ) {
					/* translators: %s: File name. */
					throw new RuntimeException( sprintf( __( 'The file %s could not be deleted.', 'backupplus' ), $name ) );
				}
				++$deleted;
				$bytes += (int) $size;
				$backupplus->log( "The file has been deleted ($name)" );
			}
		}
		if ( $plan['purge'] ) {
			self::forget( $timestamp, $type, $plan['purge'], $known );
		}
		return array(
			'deleted'        => $deleted,
			'size'           => BackupPlus_Manipulation_Functions::convert_numeric_size_to_text( $bytes ),
			'remote_deleted' => $remote,
			'removed'        => array_keys( $plan['purge'] ),
		);
	}

	/** Drop removed points from the history row and retire the entity's inventory so no later increment builds on them. */
	private static function forget( $timestamp, $type, $points, $known ) {
		$history = BackupPlus_Backup_History::get_history();
		if ( ! isset( $history[ $timestamp ] ) ) {
			return;
		}
		$backup = $history[ $timestamp ];
		foreach ( $points as $point ) {
			foreach ( $point['files'] as $findex ) {
				unset( $backup[ $type ][ $findex ], $backup[ $type . ( $findex ? $findex : '' ) . '-size' ] );
				foreach ( isset( $backup['checksums'] ) ? array_keys( (array) $backup['checksums'] ) : array() as $algorithm ) {
					unset( $backup['checksums'][ $algorithm ][ $type . $findex ], $backup['checksums'][ $algorithm ][ $type . $findex . '.crypt' ] );
				}
				foreach ( isset( $backup['storage_deleted_files'] ) ? array_keys( (array) $backup['storage_deleted_files'] ) : array() as $key ) {
					unset( $backup['storage_deleted_files'][ $key ][ $known[ $findex ]['name'] ] );
				}
			}
		}
		if ( empty( $backup[ $type ] ) ) {
			unset( $backup[ $type ] );
		}
		unset( $backup['incremental_sets'] );
		if ( ! empty( $backup['incremental']['snapshots'][ $type ] ) ) {
			BackupPlus_Incremental_Backups::discard_snapshots( array( $backup['incremental']['snapshots'][ $type ] ) );
			unset( $backup['incremental']['snapshots'][ $type ] );
		}
		$history[ $timestamp ] = $backup;
		BackupPlus_Backup_History::save_history( $history );
	}

	/**
	 * Resolve the files of the chosen points, fetching any that are only held in remote storage.
	 *
	 * @param int          $timestamp Backup history key.
	 * @param string       $type      Entity, e.g. db or plugins.
	 * @param string|array $selection Point ids, as an array or a comma separated string.
	 * @param bool         $fetch     Whether to retrieve missing files from remote storage.
	 * @return array files (name and path pairs for the archive) and the archive name.
	 * @throws RuntimeException With a message fit for the admin when the selection cannot be served.
	 */
	public static function resolve( $timestamp, $type, $selection, $fetch ) {
		global $backupplus;
		list( $backup, $chosen ) = self::select( $timestamp, $type, $selection );

		$known    = self::files( $backup, $type );
		$dir      = $backupplus->backups_dir_location();
		$services = array_values( array_diff( (array) $backupplus->get_canonical_service_list( isset( $backup['service'] ) ? $backup['service'] : false ), array( 'email', 'none', '' ) ) );
		$files    = array();
		$prepared = false;
		foreach ( $chosen as $point ) {
			foreach ( $point['files'] as $findex ) {
				$file = $known[ $findex ];
				$name = $file['name'];
				if ( basename( $name ) !== $name || '' === $name ) {
					throw new RuntimeException( __( 'A recovery point has an invalid file name.', 'backupplus' ) );
				}
				$path = $dir . '/' . $name;
				if ( $fetch && $services && ! is_file( $path ) ) {
					if ( ! $prepared ) {
						$prepared = true;
						$backupplus->backup_time_nonce( dechex( $timestamp ) . 'b' . substr( md5( $type ), 0, 3 ) );
						$backupplus->jobdata_set( 'job_type', 'download' );
						$backupplus->jobdata_set( 'service', $services );
					}
					BackupPlus_Storage_Methods_Interface::get_remote_file( $services, $name, $timestamp );
				}
				if ( ! is_file( $path ) || ! is_readable( $path ) || ( ! empty( $file['size'] ) && is_numeric( $file['size'] ) && filesize( $path ) < (int) $file['size'] ) ) {
					/* translators: %s: File name. */
					throw new RuntimeException( sprintf( __( 'The file %s is not available on this server and could not be retrieved from remote storage.', 'backupplus' ), $name ) );
				}
				$files[] = array(
					'name' => $name,
					'path' => $path,
				);
			}
		}
		$first = reset( $chosen );
		$last  = end( $chosen );
		return array(
			'files' => $files,
			'name'  => 'backupplus-' . ( 'db' === $type ? 'database-recovery' : $type ) . '-' . gmdate( 'Ymd-His', $first['timestamp'] ) . ( $first['id'] === $last['id'] ? '' : '-to-' . gmdate( 'Ymd-His', $last['timestamp'] ) ) . '.zip',
		);
	}

	/** Check that the selection can be archived and make sure every file is on this server. */
	public static function prepare( $timestamp, $type, $selection, $archive = true ) {
		$resolved = self::resolve( $timestamp, $type, $selection, true );
		if ( ! $archive ) {
			return array( 'files' => count( $resolved['files'] ) );
		}
		$zip = new BackupPlus_Stored_Zip( $resolved['files'] );
		return array(
			'files' => count( $resolved['files'] ),
			'size'  => BackupPlus_Manipulation_Functions::convert_numeric_size_to_text( $zip->length() ),
			'name'  => $resolved['name'],
		);
	}

	/** Stream the chosen points as one archive. Does not return on success. */
	public static function send( $timestamp, $type, $selection ) {
		$resolved = self::resolve( $timestamp, $type, $selection, false );
		$zip      = new BackupPlus_Stored_Zip( $resolved['files'] );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 900 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}
		$zip->send( $resolved['name'] );
		die;
	}
}
