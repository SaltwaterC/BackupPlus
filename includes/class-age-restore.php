<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}

require_once __DIR__ . '/class-backup-sources.php';

/** Prepare verified archives for resumable restores without retaining identities. */
class BackupPlus_Age_Restore {
	const LIFETIME = 7200;

	public static function is_age( $path ) {
		if ( ! is_file( $path ) || is_link( $path ) ) {
			return false;
		}
		$input = fopen( $path, 'rb' );
		if ( false === $input ) {
			return false;
		}
		$prefix = fread( $input, strlen( 'age-encryption.org/' ) );
		fclose( $input );
		return 'age-encryption.org/' === $prefix;
	}

	/** Restorable archives of a backup set, as entity and filename pairs. */
	private static function archives( $backup, $entities ) {
		global $backupplus;
		$files         = array();
		$allowed       = $backupplus->get_backupable_file_entities( true );
		$allowed       = BackupPlus_Backup_Sources::restore_entities( $allowed, $backup );
		$allowed['db'] = true;
		foreach ( $entities as $entity ) {
			if ( ! is_string( $entity ) || ! isset( $allowed[ $entity ] ) || ! isset( $backup[ $entity ] ) ) {
				continue;
			}
			foreach ( (array) $backup[ $entity ] as $file ) {
				$files[] = array( $entity, $file );
			}
		}
		return $files;
	}

	/** Whether restoring any part of a backup set needs a private key. */
	public static function has_encrypted( $backup ) {
		foreach ( self::archives( $backup, array_map( 'strval', array_keys( $backup ) ) ) as $entry ) {
			if ( is_string( $entry[1] ) && BackupPlus_Encryption::is_file_encrypted( $entry[1] ) ) {
				return true;
			}
		}
		return false;
	}

	/** Require complete browser uploads for every selected encrypted archive. */
	public static function prepare( $backup, $timestamp, $ticket = '', $entities = array( 'db' ) ) {
		self::authorize();
		global $backupplus;
		$encrypted = false;
		foreach ( self::archives( $backup, $entities ) as $entry ) {
			$file = $entry[1];
			if ( ! is_string( $file ) || $file !== basename( $file ) ) {
				throw new \RuntimeException( 'Invalid archive filename.' );
			}
			if ( BackupPlus_Encryption::is_file_encrypted( $file ) ) {
				$encrypted = true;
				if ( ! self::file( $backupplus->backups_dir_location() . '/' . $file, $ticket, '', $timestamp ) ) {
					throw new \RuntimeException( 'Decrypt and upload the selected archives in your browser first.' );
				}
			}
		}
		return $encrypted ? $ticket : '';
	}

	private static function authorize() {
		if ( ! BackupPlus_Options::user_can_manage() ) {
			throw new \RuntimeException( 'Not authorized.' );
		}
	}

	/** Snapshot ciphertext bindings; private keys never enter this protocol. */
	public static function manifest( $backup, $timestamp, $entities, $ticket = '' ) {
		self::authorize();
		global $backupplus;
		if ( $ticket ) {
			try {
				return array(
					'ticket' => self::prepare( $backup, $timestamp, $ticket, $entities ),
					'files'  => array(),
				);
			} catch ( \RuntimeException $e ) {
				self::discard( $ticket );
			}
		}
		$files = array();
		foreach ( self::archives( $backup, $entities ) as $entry ) {
			$file = $entry[1];
			if ( ! is_string( $file ) || $file !== basename( $file ) ) {
				throw new \RuntimeException( 'Invalid archive filename.' );
			}
			if ( ! BackupPlus_Encryption::is_file_encrypted( $file ) ) { continue; }
			$path = $backupplus->backups_dir_location() . '/' . $file;
			if ( is_link( $path ) || ! is_file( $path ) ) {
				throw new \RuntimeException( 'Encrypted archive is unavailable.' );
			}
			$hash = hash_file( 'sha256', $path );
			if ( false === $hash ) { throw new \RuntimeException( 'Could not read archive.' ); }
			$files[ $file ] = array(
				'path'        => 'decrypt_' . bin2hex( random_bytes( 16 ) ) . '.tmp',
				'cipher_hash' => $hash,
				'cipher_size' => filesize( $path ),
				'plain_hash'  => '',
			);
		}
		if ( count( $files ) > 10000 ) { throw new \RuntimeException( 'Too many backup archives.' ); }
		if ( ! $files ) { return array(
			'ticket' => '',
			'files'  => array(),
		); }
		$ticket = bin2hex( random_bytes( 32 ) );
		$data   = array(
			'user'      => get_current_user_id(),
			'blog'      => get_current_blog_id(),
			'timestamp' => (int) $timestamp,
			'expires'   => time() + self::LIFETIME,
			'job'       => '',
			'files'     => $files,
		);
		if ( ! set_transient( 'backupplus_age_restore_' . $ticket, $data, self::LIFETIME ) ) {
			throw new \RuntimeException( 'Could not prepare the restore reference.' );
		}
		return array(
			'ticket' => $ticket,
			'files'  => array_keys( $files ),
		);
	}

	/** Only the preparing administrator can download/upload/discard unclaimed files. */
	private static function pending( $ticket ) {
		self::authorize();
		$data = self::data( $ticket );
		if ( ! $data || '' !== $data['job'] || (int) $data['user'] !== get_current_user_id() ) {
			throw new \RuntimeException( 'The prepared restore expired or belongs to another restore.' );
		}
		return $data;
	}

	public static function download( $ticket, $file ) {
		$data = self::pending( $ticket );
		global $backupplus;
		if ( ! is_string( $file ) || ! isset( $data['files'][ $file ] ) ) {
			throw new \RuntimeException( 'Invalid archive.' );
		}
		$path = $backupplus->backups_dir_location() . '/' . $file;
		if ( is_link( $path ) || ! is_file( $path ) || ! hash_equals( $data['files'][ $file ]['cipher_hash'], hash_file( 'sha256', $path ) ) ) {
			throw new \RuntimeException( 'Encrypted archive changed.' );
		}
		return $path;
	}

	/** Append bounded plaintext chunks, publishing only after size/hash confirmation. */
	public static function upload( $ticket, $file, $offset, $bytes, $size = null, $hash = '' ) {
		$data = self::pending( $ticket );
		if ( ! is_string( $file ) || ! isset( $data['files'][ $file ] ) || ! is_string( $bytes ) || strlen( $bytes ) > 524288 || ! is_numeric( $offset ) || (int) $offset < 0 ) {
			throw new \RuntimeException( 'Invalid archive upload.' );
		}
		$entry = $data['files'][ $file ];
		if ( '' !== $entry['plain_hash'] || ! preg_match( '/\Adecrypt_[a-f0-9]{32}\.tmp\z/D', $entry['path'] ) ) {
			throw new \RuntimeException( 'Archive already completed.' );
		}
		global $backupplus;
		$path = $backupplus->backups_dir_location() . '/' . $entry['path'];
		if ( is_link( $path ) ) { throw new \RuntimeException( 'Invalid upload path.' ); }
		$output = fopen( $path, 0 === (int) $offset ? 'x+b' : 'r+b' );
		if ( ! $output ) { throw new \RuntimeException( 'Could not open upload.' ); }
		try {
			if ( ! flock( $output, LOCK_EX ) ) { throw new \RuntimeException( 'Could not lock upload.' ); }
			$stat = fstat( $output );
			if ( $stat['size'] !== (int) $offset || (int) $offset + strlen( $bytes ) > $entry['cipher_size'] ) {
				throw new \RuntimeException( 'Invalid upload offset or size.' );
			}
			if ( 0 === (int) $offset && ! chmod( $path, 0600 ) ) { throw new \RuntimeException( 'Could not protect upload.' ); }
			fseek( $output, 0, SEEK_END );
			if ( strlen( $bytes ) && strlen( $bytes ) !== fwrite( $output, $bytes ) ) {
				throw new \RuntimeException( 'Could not write upload.' );
			}
			fflush( $output );
			$end = (int) $offset + strlen( $bytes );
			if ( null !== $size ) {
				if ( $end !== (int) $size || ! is_string( $hash ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $hash ) || ! hash_equals( $hash, hash_file( 'sha256', $path ) ) ) {
					throw new \RuntimeException( 'Incomplete plaintext upload.' );
				}
				self::download( $ticket, $file ); // Recheck the ciphertext before publishing.
				$data['files'][ $file ]['plain_hash'] = $hash;
				if ( ! set_transient( 'backupplus_age_restore_' . $ticket, $data, max( 1, $data['expires'] - time() ) ) ) {
					throw new \RuntimeException( 'Could not complete upload.' );
				}
			}
			return array( 'offset' => $end );
		} finally {
			fclose( $output );
		}
	}

	/** Cancelled/failed uploads cannot be claimed; old .tmp files also use routine cleanup. */
	public static function discard( $ticket ) {
		try { $data = self::pending( $ticket );
		} catch ( \RuntimeException $e ) { return; }
		global $backupplus;
		foreach ( $data['files'] as $entry ) {
			if ( ! preg_match( '/\Adecrypt_[a-f0-9]{32}\.tmp\z/D', $entry['path'] ) ) { continue; }
			$path = $backupplus->backups_dir_location() . '/' . $entry['path'];
			if ( is_file( $path ) && ! is_link( $path ) ) { unlink( $path ); }
		}
		delete_transient( 'backupplus_age_restore_' . $ticket );
	}

	private static function data( $ticket, $job = '' ) {
		if ( ! is_string( $ticket ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $ticket ) ) {
			return false;
		}
		$data = get_transient( 'backupplus_age_restore_' . $ticket );
		// Database restoration runs first and can replace the transient's table.
		// The current restore job preserves only non-secret file bindings.
		if ( ! is_array( $data ) && '' !== $job ) {
			global $backupplus;
			if ( $backupplus->nonce !== $job ) {
				return false;
			}
			$cached = $backupplus->jobdata_get( 'backupplus_prepared_archives', array() );
			if ( ! isset( $cached['ticket'], $cached['data'] ) || $cached['ticket'] !== $ticket ) {
				return false;
			}
			$data = $cached['data'];
		}
		if ( ! is_array( $data ) || $data['expires'] < time() || (int) $data['blog'] !== get_current_blog_id() ) {
			return false;
		}
		return $data;
	}

	/** Bind the opaque reference to the already authorized restore job. */
	public static function claim( $ticket, $timestamp, $job ) {
		global $backupplus;
		$data = self::data( $ticket, $job );
		if ( ! $data || (int) $data['timestamp'] !== (int) $timestamp || ( '' !== $data['job'] ? $data['job'] !== $job : (int) $data['user'] !== get_current_user_id() ) ) {
			throw new \RuntimeException( 'The prepared age restore expired or belongs to another restore. Prepare it again.' );
		}
		foreach ( $data['files'] as $entry ) {
			if ( empty( $entry['plain_hash'] ) ) { throw new \RuntimeException( 'The plaintext upload is incomplete.' ); }
		}
		$data['job'] = $job;
		set_transient( 'backupplus_age_restore_' . $ticket, $data, max( 1, $data['expires'] - time() ) );
		$backupplus->jobdata_set(
			'backupplus_prepared_archives',
			array(
				'ticket' => $ticket,
				'data'   => $data,
			)
		);
	}

	/** Resolve only server-generated paths, bound to both ciphertext and plaintext. */
	public static function file( $encrypted, $ticket, $job = '', $timestamp = null ) {
		if ( 'cli' === $ticket && defined( 'WP_CLI' ) && WP_CLI && class_exists( 'BackupPlus_CLI_Restore', false ) ) {
			return BackupPlus_CLI_Restore::prepared( $encrypted, $job );
		}
		$data = self::data( $ticket, $job );
		if ( ! $data || ( null !== $timestamp && (int) $data['timestamp'] !== (int) $timestamp ) ) {
			return false;
		}
		if ( '' === $job ? (int) $data['user'] !== get_current_user_id() : $data['job'] !== $job ) {
			return false;
		}
		$name = basename( $encrypted );
		if ( ! isset( $data['files'][ $name ] ) || empty( $data['files'][ $name ]['plain_hash'] ) ) {
			return false;
		}
		$entry = $data['files'][ $name ];
		if ( ! preg_match( '/\Adecrypt_[a-f0-9]{32}\.tmp\z/D', $entry['path'] ) ) {
			return false;
		}
		$plain = dirname( $encrypted ) . '/' . $entry['path'];
		if ( is_link( $encrypted ) || is_link( $plain ) || ! is_file( $encrypted ) || ! is_file( $plain ) ) {
			return false;
		}
		$cipher_hash = hash_file( 'sha256', $encrypted );
		$plain_hash  = hash_file( 'sha256', $plain );
		if ( ! is_string( $entry['cipher_hash'] ) || ! is_string( $entry['plain_hash'] ) || false === $cipher_hash || false === $plain_hash || ! hash_equals( $entry['cipher_hash'], $cipher_hash ) || ! hash_equals( $entry['plain_hash'], $plain_hash ) ) {
			return false;
		}
		return array(
			'fullpath' => $plain,
			'basename' => preg_replace( '/\.crypt$/i', '', $name ),
		);
	}
}
