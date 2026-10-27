<?php

/** PITR artifacts use existing provider instances and age encryption. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

require_once __DIR__ . '/class-pitr-compression.php';

class BackupPlus_PITR_Delete_Pending extends RuntimeException {}

class BackupPlus_PITR_Storage {

	private $directory;
	private $destinations;
	private $encryption;
	private $work;
	private $identity   = '';
	private $passphrase = '';
	private $reader;
	private $providers = array();

	public function __construct( $work, $encryption = array(), $destinations = null, $reader = null ) {
		global $backupplus;
		$this->directory    = $backupplus->backups_dir_location();
		$this->work         = $work;
		$this->encryption   = $encryption;
		$this->reader       = $reader;
		$this->destinations = null === $destinations ? self::destinations() : $destinations;
		// Keep destination credentials and paths in memory for this operation. A settings
		// change during a long dump must not send its increment to another destination.
		foreach ( $this->destinations as $destination ) {
			$service = $destination['service'];
			$storage = BackupPlus_Storage_Methods_Interface::get_storage_objects_and_ids( array( $service ) );
			if ( empty( $storage[ $service ]['object'] ) ) {
				throw new RuntimeException( 'A PITR storage provider is unavailable.' );
			}
			$object  = $storage[ $service ]['object'];
			$id      = $destination['instance'];
			$options = null;
			if ( $object->supports_feature( 'multi_options' ) ) {
				if ( ! isset( $storage[ $service ]['instance_settings'][ $id ] ) ) {
					throw new RuntimeException( 'The remote storage this recovery chain was saved to no longer exists in the settings. Restore it, or delete the chain without also deleting from remote storage.' );
				}
				$options = $storage[ $service ]['instance_settings'][ $id ];
			} elseif ( is_callable( array( $object, 'get_options' ) ) ) {
				$options = $object->get_options();
			}
			if ( isset( $destination['settings_sha256'] ) && ! self::settings_match( $destination['settings_sha256'], $options ) ) {
				throw new RuntimeException( 'PITR storage settings changed. Restore the original settings or rescan and verify the complete chain at the current destination before recovery or deletion.' );
			}
			$this->providers[ $service ][ $id ] = array(
				'object'  => $object,
				'options' => $options,
			);
		}
	}

	/** Hash of the settings that decide where files go. The enabled flag appears and disappears as settings are saved, and the label is a display name that can be renamed at any time, so both are left out. */
	public static function settings_hash( $options ) {
		if ( is_array( $options ) ) {
			unset( $options['instance_enabled'], $options['instance_label'] );
		}
		return hash( 'sha256', serialize( $options ) );
	}

	/** Whether a destination's recorded hash still describes these settings, including hashes recorded before the flag and the label were left out. */
	public static function settings_match( $recorded, $options ) {
		if ( hash_equals( $recorded, self::settings_hash( $options ) ) ) {
			return true;
		}
		if ( ! is_array( $options ) ) {
			return false;
		}
		$plain = $options;
		unset( $plain['instance_enabled'] );
		foreach ( array( $options, $plain, $plain + array( 'instance_enabled' => 1 ), $plain + array( 'instance_enabled' => 0 ) ) as $recorded_as ) {
			if ( hash_equals( $recorded, hash( 'sha256', serialize( $recorded_as ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	public static function destinations() {
		$services = (array) BackupPlus_Options::get_backupplus_option( 'backupplus_service', array( 'none' ) );
		$result   = array();
		foreach ( $services as $service ) {
			if ( '' === $service || 'none' === $service ) {
				continue;
			}
			if ( 'email' === $service ) {
				throw new RuntimeException( 'Email cannot store a verifiable PITR chain.' );
			}
			$storage = BackupPlus_Storage_Methods_Interface::get_enabled_storage_objects_and_ids( array( $service ) );
			if ( empty( $storage[ $service ]['object'] ) ) {
				throw new RuntimeException( 'A configured PITR storage provider is unavailable.' );
			}
			$object = $storage[ $service ]['object'];
			$ids    = $object->supports_feature( 'multi_options' ) ? array_keys( $storage[ $service ]['instance_settings'] ) : array( '' );
			foreach ( $ids as $id ) {
				$options  = $object->supports_feature( 'multi_options' ) ? $storage[ $service ]['instance_settings'][ $id ] : ( is_callable( array( $object, 'get_options' ) ) ? $object->get_options() : null );
				$result[] = array(
					'service'         => $service,
					'instance'        => (string) $id,
					'settings_sha256' => self::settings_hash( $options ),
				);
			}
		}
		return $result;
	}

	private function provider( $destination ) {
		$service = $destination['service'];
		$id      = $destination['instance'];
		if ( ! isset( $this->providers[ $service ][ $id ] ) ) {
			throw new RuntimeException( 'A PITR storage instance is unavailable.' );
		}
		$provider = $this->providers[ $service ][ $id ];
		$object   = $provider['object'];
		if ( null !== $provider['options'] ) {
			$object->set_options( $provider['options'], false, $id );
		}
		return $object;
	}

	public function path( $name ) {
		if ( is_string( $name ) && preg_match( '/\Abackup_[0-9]{4}-[0-9]{2}-[0-9]{2}-[0-9]{4}_PITRRecovered_[a-f0-9]{12}-db\.(?:gz|sql\.zst)(?:\.crypt)?\z/D', $name ) ) {
			return $this->directory . '/' . $name;
		}
		return $this->directory . '/' . BackupPlus_PITR_Manifest::filename( $name );
	}

	public function artifact( $input, $name, $compress = true ) {
		$path = $this->path( $name );
		if ( file_exists( $path ) || is_link( $path ) || file_exists( $path . '.crypt' ) || is_link( $path . '.crypt' ) ) {
			throw new RuntimeException( 'PITR artifact already exists.' );
		}
		try {
			return $this->create_artifact( $input, $name, $compress );
		} catch ( Throwable $error ) {
			foreach ( array( $path, $path . '.crypt' ) as $file ) {
				if ( is_file( $file ) && ! is_link( $file ) ) {
					unlink( $file );
				}
			} throw $error;
		}
	}

	private function create_artifact( $input, $name, $compress ) {
		$path = $this->path( $name );
		$zstd = $compress && (bool) preg_match( '/\.zst$/D', $name );
		if ( $zstd ) {
			BackupPlus_PITR_Compression::compress_zstd( $input, $path, $this->work );
		} else {
			BackupPlus_PITR_Process::private_file( $path );
			$in  = fopen( $input, 'rb' );
			$out = $compress ? gzopen( $path, 'wb6' ) : fopen( $path, 'wb' );
			if ( ! $in || ! $out ) {
				throw new RuntimeException( 'Could not open PITR artifact.' );
			}
			try {
				while ( ! feof( $in ) ) {
					$data = fread( $in, 1048576 );
					if ( false === $data ) {
						throw new RuntimeException( 'Could not read PITR source artifact.' );
					}
					$offset = 0;
					while ( $offset < strlen( $data ) ) {
						$written = $compress ? gzwrite( $out, substr( $data, $offset ) ) : fwrite( $out, substr( $data, $offset ) );
						if ( ! $written ) {
							throw new RuntimeException( 'Could not write PITR artifact.' );
						} $offset += $written;
					}
				}
			} finally {
				fclose( $in );
				$compress ? gzclose( $out ) : fclose( $out );
			}
		}
		$plain_hash = hash_file( 'sha256', $path );
		$plain_size = filesize( $path );
		$mode       = isset( $this->encryption['mode'] ) ? $this->encryption['mode'] : 'none';
		if ( 'age' === $mode ) {
			$encrypted = BackupPlus_Encryption::encrypt( $path, $this->encryption['recipients'], 'age' );
			if ( false === $encrypted || ! chmod( $encrypted, 0600 ) ) {
				throw new RuntimeException( 'PITR age encryption failed.' );
			}
			unlink( $path );
			$path = $encrypted;
		} elseif ( 'none' !== $mode ) {
			unlink( $path );
			throw new RuntimeException( 'Unsupported PITR encryption configuration.' );
		}
		$artifact = array(
			'name'         => basename( $path ),
			'sha256'       => hash_file( 'sha256', $path ),
			'plain_sha256' => $plain_hash,
			'size'         => filesize( $path ),
			'plain_size'   => $plain_size,
			'raw_size'     => filesize( $input ),
			'raw_sha256'   => hash_file( 'sha256', $input ),
		);
		if ( $zstd ) { $artifact['compression'] = 'zstd'; }
		return $artifact;
	}

	public function verify_file( $artifact ) {
		$path = $this->path( $artifact['name'] );
		return ! is_link( $path ) && is_file( $path ) && filesize( $path ) === $artifact['size'] && hash_equals( $artifact['sha256'], hash_file( 'sha256', $path ) );
	}

	/** Read back the remote object. Provider callbacks alone are not a completion receipt. */
	private function read_back( $object, $artifact ) {
		$path = $this->path( $artifact['name'] );
		$copy = $this->work . '/verify-' . hash( 'sha256', $artifact['name'] );
		if ( ! copy( $path, $copy ) || ! chmod( $copy, 0600 ) ) {
			throw new RuntimeException( 'Could not protect PITR transfer verification copy.' );
		}
		try {
			unlink( $path );
			$result = $this->download( $object, $artifact['name'] );
			if ( false === $result || is_wp_error( $result ) || ! $this->verify_file( $artifact ) ) {
				throw new RuntimeException( 'PITR remote object verification failed.' );
			}
		} finally {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
			if ( ! rename( $copy, $path ) ) {
				throw new RuntimeException( 'Could not restore local PITR artifact after remote verification.' );
			}
		}
	}

	/** Payloads, then manifest, then locator: the locator is the publication marker. */
	public function publish( $pending, $checkpoint ) {
		global $backupplus_backup;
		foreach ( $pending['files'] as $file ) {
			if ( ! $this->verify_file( $file ) ) {
				// A hard-killed read-back may have left its original in an older private work directory.
				foreach ( glob( dirname( $this->work ) . '/work-*/verify-' . hash( 'sha256', $file['name'] ) ) as $copy ) {
					if ( ! is_link( $copy ) && filesize( $copy ) === $file['size'] && hash_equals( $file['sha256'], hash_file( 'sha256', $copy ) ) && ! is_link( $this->path( $file['name'] ) ) ) {
						copy( $copy, $this->path( $file['name'] ) );
						chmod( $this->path( $file['name'] ), 0600 );
						break;
					}
				}
			}
			if ( ! $this->verify_file( $file ) ) {
				$this->fetch( $file );
			}
		}
		foreach ( $this->destinations as $destination ) {
			$key                                      = $destination['service'] . ':' . $destination['instance'];
			$object                                   = $this->provider( $destination );
			$backupplus_backup->current_service       = $destination['service'];
			$backupplus_backup->current_instance      = $destination['instance'];
			$backupplus_backup->last_storage_instance = false;
			foreach ( $pending['files'] as $file ) {
				if ( ! empty( $pending['receipts'][ $key ][ $file['name'] ] ) ) {
					continue;
				}
				$result = $object->backup( array( $file['name'] ) );
				if ( false === $result || is_wp_error( $result ) ) {
					throw new RuntimeException( 'PITR remote upload failed.' );
				}
				$this->read_back( $object, $file );
				$pending['receipts'][ $key ][ $file['name'] ] = $file['sha256'];
				call_user_func( $checkpoint, $pending );
			}
		}
		return $pending;
	}

	public function fetch( $artifact ) {
		$path = $this->path( $artifact['name'] );
		if ( $this->verify_file( $artifact ) ) {
			return $path;
		}
		if ( is_link( $path ) ) {
			throw new RuntimeException( 'PITR artifacts cannot be symbolic links.' );
		}
		foreach ( $this->destinations as $destination ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
			$object = $this->provider( $destination );
			$result = $this->download( $object, $artifact['name'] );
			if ( false !== $result && ! is_wp_error( $result ) && $this->verify_file( $artifact ) ) {
				chmod( $path, 0600 );
				return $path;
			}
		}
		throw new RuntimeException( 'PITR artifact is missing or corrupt: ' . $artifact['name'] );
	}

	public function plain( $artifact ) {
		$path = $this->fetch( $artifact );
		if ( '.crypt' !== substr( $path, -6 ) ) {
			return $path;
		}
		if ( is_array( $this->reader ) && isset( $this->reader['prepared'] ) && is_callable( $this->reader['prepared'] ) ) {
			$prepared = call_user_func( $this->reader['prepared'], $path );
			if ( ! is_array( $prepared ) || empty( $prepared['fullpath'] ) || is_link( $prepared['fullpath'] ) || ! is_file( $prepared['fullpath'] ) ) { throw new RuntimeException( 'The browser-prepared PITR archive is missing, expired or changed. Prepare the chain again.' ); }
			$target = $this->work . '/plain-' . bin2hex( random_bytes( 8 ) );
			BackupPlus_PITR_Process::private_file( $target );
			if ( ! copy( $prepared['fullpath'], $target ) || ( isset( $artifact['plain_sha256'] ) && ! hash_equals( $artifact['plain_sha256'], hash_file( 'sha256', $target ) ) ) ) { throw new RuntimeException( 'Prepared PITR plaintext verification failed.' ); }
			return $target;
		}
		if ( '' === $this->identity ) {
			if ( ! is_callable( $this->reader ) ) {
				throw new RuntimeException( 'PITR verification needs an age identity through interactive CLI preparation.' );
			}
			$this->identity   = call_user_func( $this->reader, 'Private key (hidden; paste complete age, OpenSSH or PEM identity)', true );
			$this->passphrase = call_user_func( $this->reader, 'Private key passphrase (hidden; blank if none)', false );
		}
		$prepared = BackupPlus_Encryption::decrypt( $path, $this->identity, true, $this->passphrase );
		if ( ! is_array( $prepared ) ) {
			throw new RuntimeException( 'PITR archive authentication failed.' );
		}
		$target = $this->work . '/plain-' . bin2hex( random_bytes( 8 ) );
		if ( ! rename( $prepared['fullpath'], $target ) ) {
			throw new RuntimeException( 'Could not stage decrypted PITR artifact.' );
		}
		if ( isset( $artifact['plain_sha256'] ) && ! hash_equals( $artifact['plain_sha256'], hash_file( 'sha256', $target ) ) ) {
			throw new RuntimeException( 'PITR plaintext hash mismatch.' );
		}
		return $target;
	}

	public function discover() {
		$names = array();
		foreach ( $this->destinations as $destination ) {
			$result = $this->provider( $destination )->listfiles( 'backup_pitr_' );
			if ( is_wp_error( $result ) || ! is_array( $result ) ) {
				throw new RuntimeException( 'Could not list PITR remote artifacts.' );
			}
			foreach ( $result as $file ) {
				if ( isset( $file['name'] ) && preg_match( '/\Abackup_pitr_[a-f0-9]{32}_[a-f0-9]{32}_locator\.json\z/D', $file['name'] ) ) {
					$names[ $file['name'] ] = true;
				}
			}
		}
		foreach ( glob( $this->directory . '/backup_pitr_*_locator.json' ) as $path ) {
			$names[ basename( $path ) ] = true;
		}
		$result = array();
		foreach ( array_keys( $names ) as $name ) {
			$path = $this->path( $name );
			if ( ! is_file( $path ) ) {
				foreach ( $this->destinations as $destination ) {
					$object = $this->provider( $destination );
					if ( false !== $this->download( $object, $name ) && is_file( $path ) ) {
						break;
					}
				}
			}
			if ( is_link( $path ) || ! is_file( $path ) || filesize( $path ) > 16384 ) {
				throw new RuntimeException( 'Invalid PITR discovery record.' );
			}
			$locator = json_decode( file_get_contents( $path ), true );
			if ( ! is_array( $locator ) || ! isset( $locator['manifest_version'], $locator['chain_id'], $locator['backup_id'], $locator['manifest'] ) || 1 !== $locator['manifest_version'] ) {
				throw new RuntimeException( 'Invalid PITR discovery metadata.' );
			}
			BackupPlus_PITR_Manifest::id( $locator['chain_id'] );
			BackupPlus_PITR_Manifest::id( $locator['backup_id'] );
			if ( ! is_array( $locator['manifest'] ) || ! isset( $locator['manifest']['name'], $locator['manifest']['sha256'], $locator['manifest']['size'] ) || ! is_int( $locator['manifest']['size'] ) || $locator['manifest']['size'] < 1 || $locator['manifest']['size'] > 33554432 ) {
				throw new RuntimeException( 'Invalid PITR manifest discovery binding.' );
			}
			BackupPlus_PITR_Manifest::filename( $locator['manifest']['name'] );
			BackupPlus_PITR_Manifest::hash( $locator['manifest']['sha256'] );
			$base = 'backup_pitr_' . $locator['chain_id'] . '_' . $locator['backup_id'] . '_';
			if ( $name !== $base . 'locator.json' || ! in_array( $locator['manifest']['name'], array( $base . 'manifest.json', $base . 'manifest.json.crypt' ), true ) ) {
				throw new RuntimeException( 'PITR locator IDs do not match its artifact names.' );
			}
			$result[] = $locator;
		}
		return $result;
	}

	private function download( $object, $name ) {
		$mask = umask( 0077 );
		try {
			return $object->download( $name );
		} finally {
			umask( $mask );
			$path = $this->path( $name );
			if ( is_file( $path ) && ! is_link( $path ) ) {
				chmod( $path, 0600 );
			}
		}
	}

	public function delete( $files, $receipts, $checkpoint, $deadline = null, $delete_local = true ) {
		foreach ( $this->destinations as $destination ) {
			$key    = $destination['service'] . ':' . $destination['instance'];
			$object = $this->provider( $destination );
			foreach ( $files as $file ) {
				BackupPlus_PITR_Manifest::filename( $file );
				if ( ! empty( $receipts[ $key ][ $file ] ) ) {
					continue;
				}
				if ( null !== $deadline && microtime( true ) >= $deadline ) { throw new BackupPlus_PITR_Delete_Pending(); }
				if ( true !== $object->delete( $file ) ) {
					$listed = $object->listfiles( $file );
					$absent = is_array( $listed );
					if ( is_array( $listed ) ) {
						foreach ( $listed as $item ) {
							if ( isset( $item['name'] ) && $file === $item['name'] ) {
								$absent = false;
								break;
							}
						}
					}
					if ( ! $absent ) {
						throw new RuntimeException( 'PITR remote deletion failed; its inventory is retained for retry.' );
					}
				}
				$receipts[ $key ][ $file ] = true;
				call_user_func( $checkpoint, $receipts );
			}
		}
		foreach ( $delete_local ? $files : array() as $file ) {
			$path = $this->path( $file );
			if ( is_file( $path ) && null !== $deadline && microtime( true ) >= $deadline ) { throw new BackupPlus_PITR_Delete_Pending(); }
			if ( is_file( $path ) && ! unlink( $path ) ) {
				throw new RuntimeException( 'Could not delete local PITR artifact.' );
			}
		}
		return $receipts;
	}

	public function __destruct() {
		if ( class_exists( 'BackupPlus_CLI_Secret' ) ) {
			BackupPlus_CLI_Secret::forget( $this->identity );
			BackupPlus_CLI_Secret::forget( $this->passphrase );
		} else {
			$this->identity   = '';
			$this->passphrase = '';
		}
	}
}
