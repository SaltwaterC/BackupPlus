<?php

/** Versioned portable metadata; coordinates always include filename and byte offset. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

class BackupPlus_PITR_Manifest {

	const VERSION = 1;

	public static function coordinate( $coordinate ) {
		if ( ! is_array( $coordinate ) || array_diff( array_keys( $coordinate ), array( 'file', 'position' ) ) || ! isset( $coordinate['file'], $coordinate['position'] ) || ! is_string( $coordinate['file'] ) || ! preg_match( '/\A[A-Za-z0-9_][A-Za-z0-9_.-]*\.[0-9]{6,}\z/D', $coordinate['file'] ) || ! is_int( $coordinate['position'] ) || $coordinate['position'] < 4 ) {
			throw new RuntimeException( 'Invalid binary-log coordinate.' );
		}
		return $coordinate;
	}

	public static function snapshot_coordinate( $path ) {
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			throw new RuntimeException( 'Could not read full dump coordinates.' );
		}
		$found = array();
		try {
			$read = 0;
			while ( ! feof( $handle ) && $read < 1048576 ) {
				$line = fgets( $handle, 65536 );
				if ( false === $line ) {
					break;
				}
				$read += strlen( $line );
				if ( preg_match( "/^-- CHANGE (?:REPLICATION SOURCE|MASTER) TO (?:SOURCE|MASTER)_LOG_FILE='([A-Za-z0-9_.-]+\\.[0-9]{6,})', (?:SOURCE|MASTER)_LOG_POS=([0-9]+);/", $line, $m ) ) {
					$found[] = self::coordinate(
						array(
							'file'     => $m[1],
							'position' => (int) $m[2],
						)
					);
				}
			}
		} finally {
			fclose( $handle );
		}
		if ( 1 !== count( $found ) ) {
			throw new RuntimeException( 'The full dump must contain exactly one commented snapshot coordinate.' );
		}
		return $found[0];
	}

	public static function hash( $hash ) {
		if ( ! is_string( $hash ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $hash ) ) {
			throw new RuntimeException( 'Invalid PITR SHA-256 hash.' );
		}
		return $hash;
	}

	public static function id( $id ) {
		if ( ! is_string( $id ) || ! preg_match( '/\A[a-f0-9]{32}\z/D', $id ) ) {
			throw new RuntimeException( 'Invalid PITR backup identifier.' );
		}
		return $id;
	}

	public static function filename( $name ) {
		if ( ! is_string( $name ) || ! preg_match( '/\Abackup_pitr_[a-f0-9]{32}_[a-f0-9]{32}_(?:full\.sql\.gz|log[0-9]+\.bin\.gz|manifest\.json(?:\.crypt)?|locator\.json)(?:\.crypt)?\z/D', $name ) ) {
			throw new RuntimeException( 'Invalid PITR artifact name.' );
		}
		return $name;
	}

	public static function validate( $data ) {
		if ( ! is_array( $data ) || ! isset( $data['manifest_version'], $data['kind'], $data['backup_id'], $data['chain_id'], $data['source'], $data['database'], $data['created_at'], $data['artifacts'] ) || self::VERSION !== $data['manifest_version'] || ! in_array( $data['kind'], array( 'database-full', 'database-binlog' ), true ) ) {
			throw new RuntimeException( 'Unsupported or incomplete PITR manifest.' );
		}
		self::id( $data['backup_id'] );
		self::id( $data['chain_id'] );
		if ( ! is_int( $data['created_at'] ) || ! is_string( $data['database'] ) || ! preg_match( '/\A[A-Za-z0-9_$-]+\z/D', $data['database'] ) || ! is_array( $data['source'] ) || ! isset( $data['source']['engine'], $data['source']['identity'], $data['source']['log_epoch'], $data['source']['server_version'] ) || ! in_array( $data['source']['engine'], array( 'mysql', 'mariadb' ), true ) ) {
			throw new RuntimeException( 'Invalid PITR source metadata.' );
		}
		self::hash( $data['source']['identity'] );
		self::id( $data['source']['log_epoch'] );
		if ( ! is_array( $data['artifacts'] ) || ! $data['artifacts'] ) {
			throw new RuntimeException( 'PITR artifact inventory is missing.' );
		}
		$names = array();
		foreach ( $data['artifacts'] as $artifact ) {
			if ( ! is_array( $artifact ) || ! isset( $artifact['name'], $artifact['sha256'], $artifact['size'], $artifact['plain_sha256'] ) || ! is_int( $artifact['size'] ) || $artifact['size'] < 1 ) {
				throw new RuntimeException( 'Invalid PITR artifact inventory.' );
			}
			self::filename( $artifact['name'] );
			self::hash( $artifact['sha256'] );
			self::hash( $artifact['plain_sha256'] );
			if ( isset( $artifact['raw_size'] ) ) {
				if ( ! is_int( $artifact['raw_size'] ) || $artifact['raw_size'] < 1 || ! isset( $artifact['raw_sha256'] ) ) {
					throw new RuntimeException( 'Invalid PITR uncompressed size.' );
				} self::hash( $artifact['raw_sha256'] );
			}
			if ( isset( $artifact['plain_size'] ) && ( ! is_int( $artifact['plain_size'] ) || $artifact['plain_size'] < 1 ) ) {
				throw new RuntimeException( 'Invalid PITR plaintext size.' );
			}
			if ( isset( $names[ $artifact['name'] ] ) ) {
				throw new RuntimeException( 'Duplicate PITR artifact.' );
			}
			$names[ $artifact['name'] ] = true;
		}
		if ( 'database-full' === $data['kind'] ) {
			if ( isset( $data['database_events'] ) ) {
				if ( ! is_array( $data['database_events'] ) ) { throw new RuntimeException( 'Invalid snapshot event metadata.' ); }
				foreach ( $data['database_events'] as $event ) {
					if ( ! is_array( $event ) || ! isset( $event[0], $event[1] ) || ! is_string( $event[0] ) || ! in_array( $event[1], array( 'ENABLED', 'DISABLED', 'SLAVESIDE_DISABLED' ), true ) ) { throw new RuntimeException( 'Invalid snapshot event metadata.' ); }
				}
			}
			if ( ! isset( $data['snapshot_coordinate'], $data['anchor'] ) || 1 !== count( $data['artifacts'] ) ) {
				throw new RuntimeException( 'Incomplete PITR full metadata.' );
			}
			self::coordinate( $data['snapshot_coordinate'] );
			self::anchor( $data['anchor'] );
		} else {
			if ( ! isset( $data['baseline_id'], $data['previous_backup_id'], $data['previous_manifest_sha256'], $data['start_coordinate'], $data['end_coordinate'], $data['segments'], $data['anchor'] ) || ! is_array( $data['segments'] ) || ! $data['segments'] || count( $data['segments'] ) !== count( $data['artifacts'] ) ) {
				throw new RuntimeException( 'Incomplete PITR incremental metadata.' );
			}
			self::id( $data['baseline_id'] );
			self::id( $data['previous_backup_id'] );
			self::hash( $data['previous_manifest_sha256'] );
			self::coordinate( $data['start_coordinate'] );
			self::coordinate( $data['end_coordinate'] );
			self::anchor( $data['anchor'] );
			$next = $data['start_coordinate'];
			foreach ( $data['segments'] as $index => $segment ) {
				if ( ! isset( $segment['start'], $segment['end'], $segment['next'], $segment['artifact'], $segment['boundaries'] ) || $segment['artifact'] !== $data['artifacts'][ $index ]['name'] || ! is_int( $segment['end'] ) || $segment['end'] < $next['position'] || ! is_array( $segment['boundaries'] ) ) {
					throw new RuntimeException( 'Invalid PITR segment bounds.' );
				}
				self::coordinate( $segment['start'] );
				self::coordinate( $segment['next'] );
				if ( $next !== $segment['start'] || $next['file'] === $segment['next']['file'] ) {
					throw new RuntimeException( 'Discontinuous PITR log segments.' );
				}
				$position = $next['position'];
				foreach ( $segment['boundaries'] as $boundary ) {
					if ( ! isset( $boundary['position'], $boundary['timestamp'] ) || ! is_int( $boundary['position'] ) || ! is_int( $boundary['timestamp'] ) || $boundary['position'] <= $position || $boundary['position'] > $segment['end'] ) {
						throw new RuntimeException( 'Invalid PITR transaction boundary.' );
					}
					$position = $boundary['position'];
				}
				$next = $segment['next'];
			}
			if ( $next !== $data['end_coordinate'] ) {
				throw new RuntimeException( 'PITR increment end does not match its logs.' );
			}
		}
		return $data;
	}

	public static function anchor( $anchor ) {
		if ( ! is_array( $anchor ) || ! isset( $anchor['coordinate'], $anchor['sha256'] ) ) {
			throw new RuntimeException( 'Missing PITR source-log anchor.' );
		}
		self::coordinate( $anchor['coordinate'] );
		self::hash( $anchor['sha256'] );
	}

	/** Validate a complete prefix; a later intact increment cannot conceal an earlier gap. */
	public static function chain( $entries ) {
		if ( ! $entries ) {
			throw new RuntimeException( 'PITR chain is empty.' );
		}
		$full     = null;
		$previous = null;
		foreach ( $entries as $entry ) {
			if ( ! isset( $entry['manifest'], $entry['sha256'] ) ) {
				throw new RuntimeException( 'PITR manifest binding is missing.' );
			}
			$data = self::validate( $entry['manifest'] );
			self::hash( $entry['sha256'] );
			if ( null === $full ) {
				if ( 'database-full' !== $data['kind'] ) {
					throw new RuntimeException( 'PITR chain has no full baseline.' );
				}
				$full       = $data;
				$coordinate = $data['snapshot_coordinate'];
			} else {
				if ( 'database-binlog' !== $data['kind'] || $full['backup_id'] !== $data['baseline_id'] || $full['chain_id'] !== $data['chain_id'] || $full['database'] !== $data['database'] || $full['source'] !== $data['source'] || $coordinate !== $data['start_coordinate'] || $previous['manifest']['backup_id'] !== $data['previous_backup_id'] || ! hash_equals( $previous['sha256'], $data['previous_manifest_sha256'] ) ) {
					throw new RuntimeException( 'PITR chain is incomplete or belongs to a different source.' );
				}
				$coordinate = $data['end_coordinate'];
			}
			$previous = $entry;
		}
		return $coordinate;
	}
}
