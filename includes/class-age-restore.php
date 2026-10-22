<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}

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

	/** Called only by the existing authorized, nonce-protected preflight command. */
	public static function prepare( $backup, $timestamp, $identity, $passphrase, $ticket = '', $entities = array( 'db' ), $archive_passphrase = '' ) {
		global $backupplus;
		if ( ! BackupPlus_Options::user_can_manage() ) {
			throw new \RuntimeException( 'Not authorized.' );
		}
		if ( ! is_string( $identity ) || ! is_string( $passphrase ) || ! is_string( $ticket ) || strlen( $identity ) > 65536 || strlen( $passphrase ) > 4096 ) {
			throw new \RuntimeException( 'Invalid identity input.' );
		}
		$files         = array();
		$allowed       = $backupplus->get_backupable_file_entities( true );
		$allowed['db'] = true;
		foreach ( $entities as $entity ) {
			if ( ! is_string( $entity ) || ! isset( $allowed[ $entity ] ) || ! isset( $backup[ $entity ] ) ) {
				continue;
			}
			foreach ( (array) $backup[ $entity ] as $file ) {
				$files[] = array( $entity, $file );
			}
		}
		if ( count( $files ) > 10000 ) {
			throw new \RuntimeException( 'Too many backup archives.' );
		}
		$directory = $backupplus->backups_dir_location();
		$prepared  = array();
		$created   = array();
		$previous  = self::data( $ticket );
		$complete  = false;
		try {
			foreach ( $files as $entry ) {
				list($entity, $file) = $entry;
				if ( ! is_string( $file ) || $file !== basename( $file ) ) {
					throw new \RuntimeException( 'Invalid archive filename.' );
				}
				$path = $directory . '/' . $file;
				if ( ! BackupPlus_Encryption::is_file_encrypted( $file ) ) {
					continue;
				}
				if ( is_link( $path ) || ! is_file( $path ) ) {
					throw new \RuntimeException( 'Encrypted archive is unavailable.' );
				}
				$age = self::is_age( $path );
				if ( ! $age && 'db' === $entity ) {
					continue; // Existing passphrase database restoration.
				}
				if ( self::file( $path, $ticket, '', $timestamp ) ) {
					$prepared[ $file ] = $previous['files'][ $file ];
					continue;
				}
				if ( $age && '' === trim( $identity ) ) {
					throw new \RuntimeException( 'Supply the age identity for these archives.' );
				}
				$key = $age ? $identity : $archive_passphrase;
				if ( ! $age && '' === $key ) {
					$key = BackupPlus_Options::get_backupplus_option( 'backupplus_encryptionphrase', '' );
				}
				if ( ! is_string( $key ) || '' === $key ) {
					throw new \RuntimeException( 'Supply the archive passphrase.' );
				}
				$cipher_hash = hash_file( 'sha256', $path );
				$plain       = BackupPlus_Encryption::decrypt( $path, $key, true, $age ? $passphrase : '' );
				if ( false === $plain ) {
					throw new \RuntimeException( 'Could not decrypt the archive. Check the identity or passphrase.' );
				}
				$created[]         = $plain['fullpath'];
				$prepared[ $file ] = array(
					'path'        => basename( $plain['fullpath'] ),
					'cipher_hash' => $cipher_hash,
					'plain_hash'  => hash_file( 'sha256', $plain['fullpath'] ),
				);
				$current_hash      = hash_file( 'sha256', $path );
				if ( false === $cipher_hash || false === $current_hash || false === $prepared[ $file ]['plain_hash'] || ! hash_equals( $cipher_hash, $current_hash ) ) {
					throw new \RuntimeException( 'Archive changed during decryption.' );
				}
			}
			if ( ! $created ) {
				$complete = true;
				return $prepared ? $ticket : ''; }
			$ticket = bin2hex( \phpseclib3\Crypt\Random::string( 32 ) );
			$data   = array(
				'user'      => get_current_user_id(),
				'blog'      => get_current_blog_id(),
				'timestamp' => (int) $timestamp,
				'expires'   => time() + self::LIFETIME,
				'job'       => '',
				'files'     => $prepared,
			);
			if ( ! set_transient( 'backupplus_age_restore_' . $ticket, $data, self::LIFETIME ) ) {
				throw new \RuntimeException( 'Could not prepare the restore reference.' );
			}
			$complete = true;
			return $ticket;
		} finally {
			if ( ! $complete ) {
				foreach ( $created as $plain ) {
					if ( is_file( $plain ) ) {
										unlink( $plain );
					}
				}
			}
		}
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
		$data = self::data( $ticket, $job );
		if ( ! $data || ( null !== $timestamp && (int) $data['timestamp'] !== (int) $timestamp ) ) {
			return false;
		}
		if ( '' === $job ? (int) $data['user'] !== get_current_user_id() : $data['job'] !== $job ) {
			return false;
		}
		$name = basename( $encrypted );
		if ( ! isset( $data['files'][ $name ] ) ) {
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
