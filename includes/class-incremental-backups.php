<?php
/** Incremental file backup planning and content inventories. */
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

class BackupPlus_Incremental_Backups {

	/** Identify the source, exclusions, encryption and destination of a chain. */
	public static function configuration( $entities, $services, $instances, $credentials ) {
		global $backupplus;
		$configuration = array();
		$paths         = $backupplus->get_backupable_file_entities( true );
		sort( $entities );
		sort( $services );
		foreach ( $instances as &$ids ) {
			sort( $ids );
		}
		unset( $ids );
		ksort( $instances );
		foreach ( $entities as $entity ) {
			$configuration[ $entity ] = array( $paths[ $entity ], $backupplus->get_exclude( $entity ), BackupPlus_Backup_Sources::custom( $entity ) ? ( is_file( $paths[ $entity ] ) ? 'file' : 'directory' ) : null );
		}
		$configuration['_protected_paths'] = array( $backupplus->backups_dir_location(), BackupPlus_Credential_Key_Store::directory() );
		if ( in_array( 'wpcore', $entities, true ) ) {
			$uploads                         = $backupplus->wp_upload_dir();
			$configuration['_content_paths'] = array( WP_CONTENT_DIR, WP_PLUGIN_DIR, WPMU_PLUGIN_DIR, $uploads['basedir'] );
		}
		return hash( 'sha256', serialize( array( site_url(), $configuration, $services, $instances, $credentials, BackupPlus_Options::get_backupplus_option( 'backupplus_database_encryption' ) ) ) );
	}

	/** Return a completed, compatible chain whose full backup is less than seven days old. */
	public static function find_base( $entities, $configuration, $now = null ) {
		global $backupplus;
		if ( empty( $entities ) ) {
			return false;
		}
		$now = null === $now ? time() : $now;
		foreach ( BackupPlus_Backup_History::get_history() as $timestamp => $backup ) {
			if ( ! empty( $backup['pre_update'] ) || $timestamp > $now || $timestamp <= $now - 7 * DAY_IN_SECONDS || empty( $backup['incremental']['complete'] ) || empty( $backup['native'] ) || $configuration !== $backup['incremental']['configuration'] ) {
				continue;
			}
			foreach ( $entities as $entity ) {
				if ( empty( $backup[ $entity ] ) || empty( $backup['incremental']['snapshots'][ $entity ] ) || false === self::read_snapshot( $backup['incremental']['snapshots'][ $entity ] ) ) {
					continue 2;
				}
				foreach ( (array) $backup[ $entity ] as $index => $file ) {
					$path     = $backupplus->backups_dir_location() . '/' . basename( $file );
					$size_key = $entity . ( $index ? $index : '' ) . '-size';
					if ( file_exists( $path ) ) {
						if ( ! is_file( $path ) || ! is_readable( $path ) || ! filesize( $path ) || ( isset( $backup[ $size_key ] ) && filesize( $path ) !== (int) $backup[ $size_key ] ) ) {
							continue 3;
						}
						foreach ( isset( $backup['checksums'] ) ? $backup['checksums'] : array() as $algorithm => $checksums ) {
							$key = $entity . $index . ( '.crypt' === substr( $file, -6 ) ? '.crypt' : '' );
							if ( isset( $checksums[ $key ] ) && in_array( $algorithm, hash_algos(), true ) && ! hash_equals( $checksums[ $key ], hash_file( $algorithm, $path ) ) ) {
								continue 4;
							}
						}
					} elseif ( empty( $backup['service'] ) || array( 'none' ) === (array) $backup['service'] ) {
						continue 3;
					}
				}
			}
			$backup['timestamp'] = $timestamp;
			return $backup;
		}
		return false;
	}

	/** Inventories stay local and cannot disclose paths through PHP HTTP requests. */
	public static function read_snapshot( $name ) {
		global $backupplus;
		if ( ! is_string( $name ) || ! preg_match( '/^incremental_[a-f0-9]{12}_[a-z-]+\.php$/D', $name ) ) {
			return false;
		}
		$path = $backupplus->backups_dir_location() . '/' . $name;
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}
		$raw = file_get_contents( $path );
		if ( ! is_string( $raw ) || 0 !== strpos( $raw, '<?php exit; ?>' ) ) {
			return false;
		}
		$data = json_decode( substr( $raw, strlen( '<?php exit; ?>' ) ), true );
		if ( ! is_array( $data ) || ! isset( $data['files'], $data['directories'], $data['deleted'] ) || ! is_array( $data['files'] ) || ! is_array( $data['directories'] ) || ! is_array( $data['deleted'] ) ) {
			return false;
		}
		foreach ( $data['files'] as $stored => $hash ) {
			if ( ( ! is_string( $stored ) && ! is_int( $stored ) ) || ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
				return false;
			}
		}
		foreach ( array_merge( $data['directories'], $data['deleted'] ) as $stored ) {
			if ( ! is_string( $stored ) ) {
				return false;
			}
		}
		return $data;
	}

	/**
	 * Hash the entire selected inventory, then remove unchanged files from this ZIP.
	 *
	 * $unchanged is set when this is an increment that adds no file, no directory and no deletion to the previous
	 * inventory: its archive would only repeat the previous manifest, so the caller may leave it out. A full backup
	 * never qualifies. The inventory is still written, because the next job compares against it.
	 */
	public static function scan( $entity, &$files, $directories, &$skipped, &$unchanged = null ) {
		global $backupplus;
		$previous_names = $backupplus->jobdata_get( 'incremental_previous_snapshots', array() );
		$previous       = isset( $previous_names[ $entity ] ) ? self::read_snapshot( $previous_names[ $entity ] ) : array(
			'files'       => array(),
			'directories' => array(),
			'deleted'     => array(),
		);
		if ( false === $previous ) {
			throw new RuntimeException( 'The previous incremental inventory is missing or invalid. Start a full backup.' );
		}
		$snapshot = array(
			'files'       => array(),
			'directories' => array_values( $directories ),
			'deleted'     => array(),
		);
		foreach ( $files as $path => $stored ) {
			$hash = hash_file( 'sha256', $path );
			if ( false === $hash ) {
				throw new RuntimeException( 'Could not fingerprint a file for incremental backup.' );
			}
			$snapshot['files'][ $stored ] = $hash;
			if ( isset( $previous['files'][ $stored ] ) && $previous['files'][ $stored ] === $hash ) {
				$skipped[ $path ] = $stored;
				unset( $files[ $path ] );
			}
		}
		// Keep cumulative deletions so restoring the final archive also removes files deleted in earlier increments.
		$old_paths           = array_unique( array_merge( array_map( 'strval', array_keys( $previous['files'] ) ), $previous['directories'], $previous['deleted'] ) );
		$paths               = $backupplus->get_backupable_file_entities( true );
		$root                = BackupPlus_Backup_Sources::custom( $entity ) ? $entity : basename( $paths[ $entity ] );
		$snapshot['deleted'] = array_values( array_diff( $old_paths, array_keys( $snapshot['files'] ), $snapshot['directories'], in_array( $entity, array( 'others', 'wpcore' ), true ) ? array() : array( $root, $root . '/' ) ) );
		$unchanged           = isset( $previous_names[ $entity ] ) && ! $files
			&& ! array_diff( $snapshot['directories'], $previous['directories'] )
			&& ! array_diff( $snapshot['deleted'], $previous['deleted'] )
			&& ! array_diff( $previous['deleted'], $snapshot['deleted'] );
		$name                = 'incremental_' . $backupplus->nonce . '_' . $entity . '.php';
		$path                = $backupplus->backups_dir_location() . '/' . $name;
		// Freeze the scan's inventory across resumptions. A file changed after it was archived
		// must be detected again by the next job, even when its size is unchanged.
		$checkpoint = self::read_snapshot( $name );
		if ( false !== $checkpoint ) {
			$snapshot = $checkpoint;
		}
		$data    = json_encode( $snapshot );
		$payload = '<?php exit; ?>' . $data;
		if ( false === $data || strlen( $payload ) !== file_put_contents( $path . '.tmp', $payload ) || ! rename( $path . '.tmp', $path ) ) {
			throw new RuntimeException( 'Could not save the incremental inventory.' );
		}
		$snapshots            = $backupplus->jobdata_get( 'incremental_snapshots', array() );
		$snapshots[ $entity ] = $name;
		$backupplus->jobdata_set( 'incremental_snapshots', $snapshots );
		return array(
			'source'  => isset( $backupplus->jobdata_get( 'file_sources', array() )[ $entity ] ) ? $backupplus->jobdata_get( 'file_sources' )[ $entity ] : null,
			'version' => 2,
			'type'    => $entity,
			'root'    => $root,
			'deleted' => $snapshot['deleted'],
		);
	}

	/** Count the warnings that mean the archives and the inventory might disagree. */
	private static function blocking_warnings() {
		global $backupplus;
		$count = 0;
		foreach ( $backupplus->errors as $key => $error ) {
			// A dangling symlink is neither archived nor inventoried, so it cannot make a chain inconsistent.
			if ( is_array( $error ) && 'warning' === $error['level'] && ! ( is_string( $key ) && 0 === strpos( $key, 'unrlink-' ) ) ) {
				++$count;
			}
		}
		return $count;
	}

	/** Only publish an inventory after all file creation and uploads completed without warnings. */
	public static function complete() {
		global $backupplus;
		if ( 'finished' !== $backupplus->jobdata_get( 'backup_files' ) || $backupplus->error_count() || self::blocking_warnings() ) {
			return;
		}
		$timestamp = $backupplus->jobdata_get( 'incremental_base_time', $backupplus->backup_time );
		$history   = BackupPlus_Backup_History::get_history();
		if ( empty( $history[ $timestamp ]['incremental'] ) ) {
			return;
		}
		$snapshots = $backupplus->jobdata_get( 'incremental_snapshots', array() );
		foreach ( $backupplus->jobdata_get( 'job_file_entities', array() ) as $entity => $unused ) {
			if ( empty( $snapshots[ $entity ] ) || false === self::read_snapshot( $snapshots[ $entity ] ) ) {
				return;
			}
		}
		$history[ $timestamp ]['incremental']['snapshots'] = $snapshots;
		$history[ $timestamp ]['incremental']['complete']  = true;
		BackupPlus_Backup_History::save_history( $history );
		$saved = BackupPlus_Backup_History::get_history( $timestamp );
		if ( isset( $saved['incremental']['snapshots'] ) && $saved['incremental']['snapshots'] === $snapshots ) {
			self::discard_snapshots( array_diff( $backupplus->jobdata_get( 'incremental_previous_snapshots', array() ), $snapshots ) );
		}
	}

	/** Remove inventories no longer needed by a completed or pruned chain. */
	public static function discard_snapshots( $snapshots ) {
		global $backupplus;
		foreach ( $snapshots as $name ) {
			if ( is_string( $name ) && preg_match( '/^incremental_[a-f0-9]{12}_[a-z-]+\\.php$/D', $name ) ) {
				$path = $backupplus->backups_dir_location() . '/' . $name;
				if ( is_file( $path ) ) {
					unlink( $path );
				}
			}
		}
	}
}
