<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}

require_once __DIR__ . '/class-storage-directory.php';

/** Installation keys live in protected files or a read-only environment source. */
class BackupPlus_Credential_Key_Store {
	const HEADER            = "<?php http_response_code(404); exit; __halt_compiler();\n";
	const FILE              = 'credentials.php';
	const OPTION            = 'backupplus_credential_key_directory';
	const ENVIRONMENT_KEY   = 'BACKUPPLUS_CREDENTIAL_KEYS';
	const BACKUP_ENTITY     = 'credential-keys';
	const ROTATION_INTERVAL = 365 * 24 * 60 * 60;

	/** Activation and upgrade share this path; failed initialization can be retried. */
	public static function initialize() {
		try {
			self::configuration();
			do_action( 'backupplus_credentials_upgrade' );
			if ( class_exists( 'BackupPlus_Credential_Storage' ) ) {
				BackupPlus_Credential_Storage::upgrade();
			}
			delete_option( 'backupplus_credential_setup_error' );
		} catch ( Exception $e ) {
			update_option( 'backupplus_credential_setup_error', $e->getMessage(), false );
		}
	}

	public static function directory() {
		return defined( 'BACKUPPLUS_CREDENTIAL_KEY_DIR' ) ? BACKUPPLUS_CREDENTIAL_KEY_DIR : get_site_option( self::OPTION, '' );
	}

	public static function configuration() {
		$environment_config = self::environment_configuration();
		if ( null !== $environment_config ) {
			return $environment_config;
		}

		$directory  = self::directory();
		$remembered = get_site_option( self::OPTION, '' );
		if ( $directory && $remembered === $directory ) {
			return self::read( $directory );
		}
		if ( $directory && $remembered ) {
			// A host override moves the managed file without changing its keys.
			return self::move_file( $remembered, $directory );
		}
		$uploads = wp_upload_dir( null, false );
		$roots   = array( WP_CONTENT_DIR );
		if ( ! $directory && empty( $uploads['error'] ) ) {
			$roots[] = $uploads['basedir'];
		}
		$candidates = $directory ? array( $directory ) : array();
		if ( ! $directory ) {
			foreach ( array_unique( $roots ) as $root ) {
				$candidates = array_merge( $candidates, BackupPlus_Storage_Directory::existing( $root, 'backupplus-private' ) );
			}
		}
		// A restored database may omit location metadata while the original file survives.
		foreach ( array_unique( $candidates ) as $candidate ) {
			if ( ! @file_exists( $candidate . '/' . self::FILE ) ) {
				continue;
			}
			$config = self::read( $candidate );
			update_site_option( self::OPTION, $candidate );
			if ( get_site_option( self::OPTION, '' ) !== $candidate ) {
				throw new RuntimeException( 'The encryption key location could not be saved. Please retry in BackupPlus Settings.' );
			}
			return $config;
		}
		if ( self::has_stored_ciphertext() ) {
			throw new RuntimeException( 'The encryption key is missing. Restore your recovery key in BackupPlus Settings.' );
		}
		if ( ! $directory ) {
			$name       = BackupPlus_Storage_Directory::name( 'backupplus-private' );
			$candidates = array();
			foreach ( array_unique( $roots ) as $root ) {
				$candidates[] = $root . '/' . $name;
			}
		}
		foreach ( array_unique( $candidates ) as $candidate ) {
			try {
				$config = self::with_lock(
					$candidate,
					function ( $path ) {
						if ( @file_exists( $path ) ) {
							return self::read_file( $path );
						}
						$config = self::new_configuration();
						self::write_file( $path, $config );
						return $config;
					}
				);
				update_site_option( self::OPTION, $candidate );
				if ( get_site_option( self::OPTION, '' ) !== $candidate ) {
					throw new RuntimeException( 'The encryption key location could not be saved. Please retry in BackupPlus Settings.' );
				}
				return $config;
			} catch ( Exception $e ) {
				// An existing key (even an unreadable one) must never trigger fallback.
				if ( @file_exists( $candidate . '/' . self::FILE ) ) {
					throw $e;
				}
			}
		}
		throw new RuntimeException( 'BackupPlus could not create its encryption key in the content or uploads folder. Choose a writable key folder in BackupPlus Settings, or ask your host to make the uploads folder writable.' );
	}

	private static function new_configuration() {
		global $backupplus;
		if ( is_wp_error( $backupplus->ensure_phpseclib() ) ) {
			throw new RuntimeException( 'Encryption is unavailable on this host.' );
		}
		$id  = bin2hex( \phpseclib3\Crypt\Random::string( 8 ) );
		$now = time();
		return array(
			'active'                    => $id,
			'keys'                      => array( $id => base64_encode( \phpseclib3\Crypt\Random::string( 32 ) ) ),
			'upgraded_sites'            => array(),
			'credential_upgraded_sites' => array(),
			'rotated_at'                => $now,
			'issued_at'                 => $now,
		);
	}

	public static function validate( $config ) {
		if ( ! is_array( $config ) || ! isset( $config['keys'], $config['active'] ) ) {
			throw new RuntimeException( 'The recovery key file is invalid. Choose a recovery file downloaded from BackupPlus.' );
		}
		// Reuse the exact runtime key validation, without decrypting or executing files.
		new BackupPlus_Credential_Encrypter( $config['keys'], $config['active'], 'validation' );
		$sites = isset( $config['upgraded_sites'] ) ? $config['upgraded_sites'] : array();
		if ( ! is_array( $sites ) ) {
			throw new RuntimeException( 'The recovery key file is invalid.' );
		}
		foreach ( $sites as $site => $completed ) {
			if ( ! preg_match( '/^[0-9]+$/D', (string) $site ) || true !== $completed ) {
				throw new RuntimeException( 'The recovery key file is invalid.' );
			}
		}
		$credential_sites = isset( $config['credential_upgraded_sites'] ) ? $config['credential_upgraded_sites'] : array();
		if ( ! is_array( $credential_sites ) ) {
			throw new RuntimeException( 'The recovery key file is invalid.' );
		}
		foreach ( $credential_sites as $site => $completed ) {
			if ( ! preg_match( '/^[0-9]+$/D', (string) $site ) || true !== $completed ) {
				throw new RuntimeException( 'The recovery key file is invalid.' );
			}
		}
		$rotated_at = isset( $config['rotated_at'] ) ? $config['rotated_at'] : 0;
		if ( ! is_int( $rotated_at ) || $rotated_at < 0 ) {
			throw new RuntimeException( 'The recovery key file is invalid.' );
		}
		// Older key files only recorded the latest rotation. That date is the
		// best available estimate of when their active key was issued.
		$issued_at = array_key_exists( 'issued_at', $config ) ? $config['issued_at'] : $rotated_at;
		if ( ! is_int( $issued_at ) || $issued_at < 0 ) {
			throw new RuntimeException( 'The recovery key file is invalid.' );
		}
		return array(
			'active'                    => $config['active'],
			'keys'                      => $config['keys'],
			'upgraded_sites'            => $sites,
			'credential_upgraded_sites' => $credential_sites,
			'rotated_at'                => $rotated_at,
			'issued_at'                 => $issued_at,
		);
	}

	/** True when the key source is explicitly controlled by the PHP environment. */
	public static function environment_managed() {
		return false !== getenv( self::ENVIRONMENT_KEY );
	}

	/** Encode the same validated key structure used by the protected file. */
	public static function encode_environment( $config ) {
		$json = json_encode( self::validate( $config ) );
		if ( false === $json ) {
			throw new RuntimeException( 'The environment key configuration could not be encoded.' );
		}
		return base64_encode( $json );
	}

	/** Generate a new environment key-ring value without changing the process environment. */
	public static function environment_rotation_value() {
		if ( ! self::environment_managed() ) {
			throw new RuntimeException( 'Environment-managed credential keys are not configured.' );
		}
		$config = self::configuration();
		$new    = self::new_configuration();
		while ( isset( $config['keys'][ $new['active'] ] ) ) {
			$new = self::new_configuration();
		}
		$config['active']     = $new['active'];
		$config['keys']       = array_merge( $config['keys'], $new['keys'] );
		$config['rotated_at'] = $new['rotated_at'];
		$config['issued_at']  = $new['issued_at'];
		return self::encode_environment( $config );
	}

	/** Read a read-only Base64(JSON) key ring. Never fall back to the key file. */
	private static function environment_configuration() {
		$encoded = getenv( self::ENVIRONMENT_KEY );
		if ( false === $encoded ) {
			return null;
		}
		$encoded = trim( $encoded );
		$json    = '' !== $encoded ? base64_decode( $encoded, true ) : false;
		if ( false === $json || base64_encode( $json ) !== $encoded || strlen( $json ) > 1048576 ) {
			throw new RuntimeException( 'The ' . self::ENVIRONMENT_KEY . ' environment variable must contain a Base64 encoded JSON credential key configuration.' );
		}
		$decoded  = json_decode( $json, true );
		$required = array( 'active', 'keys', 'upgraded_sites', 'credential_upgraded_sites', 'rotated_at', 'issued_at' );
		if ( ! is_array( $decoded ) || array_diff( $required, array_keys( $decoded ) ) ) {
			throw new RuntimeException( 'The ' . self::ENVIRONMENT_KEY . ' environment variable must contain the complete credential key structure, including issued_at.' );
		}
		$config = self::validate( $decoded );
		if ( 0 === $config['issued_at'] ) {
			throw new RuntimeException( 'The ' . self::ENVIRONMENT_KEY . ' environment variable must contain a valid issued_at timestamp.' );
		}
		return $config;
	}

	private static function require_file_managed() {
		if ( self::environment_managed() ) {
			throw new RuntimeException( 'Credential keys are managed by the ' . self::ENVIRONMENT_KEY . ' environment variable. Update the service configuration to rotate them.' );
		}
	}

	/** Whether the current backup job writes every archive with age encryption. */
	public static function age_encryption_enabled() {
		global $backupplus;
		$config = is_object( $backupplus ) && is_callable( array( $backupplus, 'get_job_option' ) )
			? $backupplus->get_job_option( 'backupplus_database_encryption' )
			: BackupPlus_Options::get_backupplus_option( 'backupplus_database_encryption', array() );
		return is_array( $config ) && isset( $config['mode'] ) && 'age' === $config['mode'];
	}

	/** Resolve the existing protected folder that should be offered as a backup source. */
	public static function backup_directory() {
		foreach ( self::private_directories() as $directory ) {
			if ( is_dir( $directory ) ) {
				return $directory;
			}
		}
		return '';
	}

	/** Add the key folder as its own source only when age encryption can protect it. */
	public static function add_backup_entity( $entities, $full_info ) {
		if ( ! self::age_encryption_enabled() || ! ( $directory = self::backup_directory() ) ) {
			return $entities;
		}
		$entities[ self::BACKUP_ENTITY ] = $full_info ? array(
			'path'        => $directory,
			'description' => __( 'BackupPlus key store', 'backupplus' ),
			'htmltitle'   => $directory,
		) : $directory;
		return $entities;
	}

	/** Private key folders used by this installation, including default locations without metadata. */
	private static function private_directories() {
		static $cache = array();
		$managed      = self::directory();
		$blog_id      = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0;
		$key          = $blog_id . '|' . $managed . '|' . ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '' );
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		$directories = array();
		if ( $managed ) {
			$directories[] = $managed;
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$directories = array_merge( $directories, BackupPlus_Storage_Directory::existing( WP_CONTENT_DIR, 'backupplus-private' ) );
		}
		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir( null, false );
			if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
				$directories = array_merge( $directories, BackupPlus_Storage_Directory::existing( $uploads['basedir'], 'backupplus-private' ) );
			}
		}
		$normalized_directories = array();
		foreach ( array_unique( $directories ) as $directory ) {
			$normalized_directories[] = self::normalized_path( $directory );
		}
		$cache[ $key ] = array_unique( $normalized_directories );
		return $cache[ $key ];
	}

	private static function normalized_path( $path ) {
		return untrailingslashit( str_replace( '\\', '/', @realpath( $path ) ?: $path ) );
	}

	/** Match the managed key folder and all of its contents, even without saved location metadata. */
	public static function is_private_path( $path ) {
		$path = self::normalized_path( $path );
		foreach ( self::private_directories() as $directory ) {
			if ( $path === $directory || 0 === strpos( $path, $directory . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/** The archive walker may ignore this directory's .donotbackup marker only for age-encrypted jobs. */
	public static function include_private_directory_in_age_backup( $path, $require_backup_entity = true ) {
		if ( ! self::age_encryption_enabled() ) {
			return false;
		}
		if ( $require_backup_entity ) {
			global $backupplus;
			$status = is_object( $backupplus ) && is_callable( array( $backupplus, 'jobdata_get' ) )
				? $backupplus->jobdata_get( 'filecreating_substatus', array() )
				: array();
			if ( ! isset( $status['e'] ) || self::BACKUP_ENTITY !== $status['e'] ) {
				return false;
			}
		}
		$path = self::normalized_path( $path );
		foreach ( self::private_directories() as $directory ) {
			if ( $path === $directory ) {
				return true;
			}
		}
		return false;
	}

	/** Keep the private folder out of other sources and permit its dedicated age-encrypted source. */
	public static function exclude_from_backup( $excluded, $path ) {
		if ( $excluded ) {
			return true;
		}
		if ( self::age_encryption_enabled() ) {
			if ( self::is_private_path( $path ) ) {
				global $backupplus;
				$status = is_object( $backupplus ) && is_callable( array( $backupplus, 'jobdata_get' ) )
					? $backupplus->jobdata_get( 'filecreating_substatus', array() )
					: array();
				return ! isset( $status['e'] ) || self::BACKUP_ENTITY !== $status['e'];
			}
			return false;
		}
		return self::exclude( false, $path );
	}

	public static function decode( $contents ) {
		if ( ! is_string( $contents ) || strlen( $contents ) > 1048576 || 0 !== strpos( $contents, self::HEADER ) ) {
			throw new RuntimeException( 'The recovery key file is invalid. Choose a recovery file downloaded from BackupPlus.' );
		}
		return self::validate( json_decode( substr( $contents, strlen( self::HEADER ) ), true ) );
	}

	public static function encode( $config ) {
		return self::HEADER . json_encode( self::validate( $config ) ) . "\n";
	}

	private static function read_file( $path ) {
		$contents = @file_get_contents( $path, false, null, 0, 1048577 );
		if ( false === $contents ) {
			throw new RuntimeException( 'The encryption key file cannot be read. Restore your recovery key in BackupPlus Settings.' );
		}
		$config = self::decode( $contents );
		$stored = json_decode( substr( $contents, strlen( self::HEADER ) ), true );
		if ( is_array( $stored ) && ! array_key_exists( 'issued_at', $stored ) ) {
			// Older key rings approximate issuance from their last rotation date.
			// Persist the added field when possible without making legacy reads fail
			// on a host where the existing key file is read-only.
			try {
				self::write_file( $path, $config );
			} catch ( Exception $e ) {
				// Keep using the validated legacy configuration if only this rewrite failed.
			}
		}
		return $config;
	}

	public static function read( $directory ) {
		$environment_config = self::environment_configuration();
		if ( null !== $environment_config ) {
			return $environment_config;
		}
		if ( ! @is_file( $directory . '/' . self::FILE ) ) {
			throw new RuntimeException( 'The encryption key file is missing. Restore your recovery key in BackupPlus Settings.' );
		}
		return self::with_lock(
			$directory,
			function ( $path ) {
				return self::read_file( $path );
			},
			false
		);
	}

	/** All modifications are serialized, including simultaneous first installations. */
	private static function with_lock( $directory, $callback, $create = true ) {
		if ( ! is_string( $directory ) || ! preg_match( '#^(?:/|[A-Za-z]:[\\\\/])#', $directory ) || false !== strpos( $directory, "\0" ) || preg_match( '#(^|[\\\\/])\.\.([\\\\/]|$)#', $directory ) ) {
			throw new RuntimeException( 'Enter an absolute path to a dedicated, writable key folder.' );
		}
		$directory = rtrim( $directory, '/\\' );
		if ( '' === $directory || preg_match( '/^[A-Za-z]:$/D', $directory ) ) {
			throw new RuntimeException( 'Choose a dedicated key folder rather than a filesystem root.' );
		}
		if ( $create ) {
			self::protect_directory( $directory );
		}
		$lock = @fopen( $directory . '/.lock', 'c+b' );
		if ( ! $lock ) {
			throw new RuntimeException( 'The key folder is not writable. Choose another folder in BackupPlus Settings.' );
		}
		@chmod( $directory . '/.lock', 0600 );
		try {
			if ( ! @flock( $lock, LOCK_EX ) ) {
				throw new RuntimeException( 'The encryption key is busy. Please retry.' );
			}
			return call_user_func( $callback, $directory . '/' . self::FILE );
		} finally {
			@flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	/** Never put protection files into an arbitrary existing website folder. */
	private static function protect_directory( $directory ) {
		if ( ! @is_dir( $directory ) && ! @mkdir( $directory, 0700, true ) && ! @is_dir( $directory ) ) {
			throw new RuntimeException( 'The key folder could not be created. Choose a writable folder in BackupPlus Settings.' );
		}
		$entries = @scandir( $directory );
		if ( false === $entries ) {
			throw new RuntimeException( 'The key folder cannot be read. Choose another folder.' );
		}
		foreach ( $entries as $entry ) {
			if ( in_array( $entry, array( '.', '..', self::FILE, '.lock', '.htaccess', 'web.config', 'index.php', '.donotbackup' ), true ) || preg_match( '/^\.credentials-[a-f0-9]+\.php$/D', $entry ) ) {
				continue;
			}
			throw new RuntimeException( 'Choose an empty, dedicated folder for the encryption key.' );
		}
		@chmod( $directory, 0700 );
		$guards = array(
			'index.php'    => "<?php http_response_code(404); exit;\n",
			'.htaccess'    => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'web.config'   => '<?xml version="1.0"?><configuration><system.webServer><security><authorization><remove users="*" roles="" verbs=""/><add accessType="Deny" users="*"/></authorization></security></system.webServer></configuration>',
			'.donotbackup' => "Private credential keys: keep out of backup archives.\n",
		);
		foreach ( $guards as $name => $contents ) {
			if ( ! @file_exists( $directory . '/' . $name ) && false === @file_put_contents( $directory . '/' . $name, $contents, LOCK_EX ) ) {
				throw new RuntimeException( 'The key folder could not be protected. Choose another writable folder.' );
			}
		}
	}

	/** Write a guarded temporary file and atomically publish it under the lock. */
	private static function write_file( $path, $config ) {
		$contents = self::encode( $config );
		// Metadata updates can write a key file before the crypto library is loaded.
		if ( function_exists( 'random_bytes' ) ) {
			$suffix = random_bytes( 8 );
		} else {
			global $backupplus;
			if ( is_wp_error( $backupplus->ensure_phpseclib() ) ) {
				throw new RuntimeException( 'The encryption key file could not be saved.' );
			}
			$suffix = \phpseclib3\Crypt\Random::string( 8 );
		}
		$temp = dirname( $path ) . '/.credentials-' . bin2hex( $suffix ) . '.php';
		$file = @fopen( $temp, 'x+b' );
		if ( ! $file ) {
			throw new RuntimeException( 'The encryption key file could not be saved. Check the key folder permissions in BackupPlus Settings.' );
		}
		@chmod( $temp, 0600 );
		try {
			if ( strlen( $contents ) !== @fwrite( $file, $contents ) || ! @fflush( $file ) ) {
				throw new RuntimeException( 'The encryption key file could not be saved. The previous key is unchanged.' );
			}
			if ( function_exists( 'fsync' ) && ! @fsync( $file ) ) {
				throw new RuntimeException( 'The encryption key file could not be saved. The previous key is unchanged.' );
			}
			fclose( $file );
			$file = null;
			if ( ! @rename( $temp, $path ) ) {
				throw new RuntimeException( 'The encryption key file could not be replaced. The previous key is unchanged.' );
			}
			if ( self::read_file( $path ) !== self::validate( $config ) ) {
				throw new RuntimeException( 'The saved encryption key could not be verified.' );
			}
		} finally {
			if ( is_resource( $file ) ) {
				fclose( $file );
			}
			if ( @file_exists( $temp ) ) {
				@unlink( $temp );
			}
		}
	}

	public static function relocate( $directory ) {
		self::require_file_managed();
		if ( defined( 'BACKUPPLUS_CREDENTIAL_KEY_DIR' ) ) {
			throw new RuntimeException( 'The key location is controlled by your hosting configuration.' );
		}
		$old = self::directory();
		if ( $old ) {
			return self::move_file( $old, $directory );
		}
		if ( self::has_stored_ciphertext() ) {
			throw new RuntimeException( 'Restore your recovery key before moving its location.' );
		}
		$config = self::with_lock(
			$directory,
			function ( $path ) {
				if ( @file_exists( $path ) ) {
					return self::read_file( $path );
				}
				$config = self::new_configuration();
				self::write_file( $path, $config );
				return $config;
			}
		);
		update_site_option( self::OPTION, rtrim( $directory, '/\\' ) );
		if ( self::directory() !== rtrim( $directory, '/\\' ) ) {
			throw new RuntimeException( 'The key location could not be saved. Please retry.' );
		}
		return $config;
	}

	private static function move_file( $old, $directory ) {
		$directory = rtrim( $directory, '/\\' );
		if ( @realpath( $old ) && @realpath( $old ) === @realpath( $directory ) ) {
			return self::read( $old );
		}
		return self::with_lock(
			$old,
			function ( $old_path ) use ( $old, $directory ) {
				if ( get_site_option( self::OPTION, '' ) !== $old ) {
					throw new RuntimeException( 'The key location changed. Please reload Settings and retry.' );
				}
				$config = self::read_file( $old_path );
				self::with_lock(
					$directory,
					function ( $path ) use ( $config ) {
						if ( @file_exists( $path ) ) {
							if ( self::read_file( $path ) !== $config ) {
								throw new RuntimeException( 'The destination contains a different encryption key. Choose an empty folder.' );
							}
						} else {
							self::write_file( $path, $config );
						}
					}
				);
				update_site_option( self::OPTION, $directory );
				if ( get_site_option( self::OPTION, '' ) !== $directory ) {
					throw new RuntimeException( 'The key location could not be saved. Your previous key remains available.' );
				}
				// Retain .donotbackup and HTTP protection even if unlink fails.
				@unlink( $old_path );
				return $config;
			},
			false
		);
	}

	public static function rotation_time() {
		$config = self::configuration();
		if ( self::environment_managed() ) {
			return $config['rotated_at'];
		}
		if ( $config['rotated_at'] ) {
			return $config['rotated_at'];
		}
		return self::with_lock(
			self::directory(),
			function ( $path ) {
				$config = self::read_file( $path );
				if ( ! $config['rotated_at'] ) {
					$config['rotated_at'] = time();
					$config['issued_at']  = $config['issued_at'] ?: $config['rotated_at'];
					self::write_file( $path, $config );
				}
				return $config['rotated_at'];
			}
		);
	}

	public static function rotate( $only_if_due = false ) {
		self::require_file_managed();
		self::configuration();
		return self::with_lock(
			self::directory(),
			function ( $path ) use ( $only_if_due ) {
				if ( @realpath( dirname( $path ) ) !== @realpath( self::directory() ) ) {
					throw new RuntimeException( 'The key location changed. Please retry.' );
				}
				$config = self::read_file( $path );
				if ( $only_if_due && ! $config['rotated_at'] ) {
					$config['rotated_at'] = time();
					$config['issued_at']  = $config['issued_at'] ?: $config['rotated_at'];
					self::write_file( $path, $config );
					return $config;
				}
				if ( $only_if_due && time() < $config['rotated_at'] + self::ROTATION_INTERVAL ) {
					return $config;
				}
				$new              = self::new_configuration();
				$config['active'] = $new['active'];
				// Assign by ID: array_merge() renumbers numeric-string keys, which can
				// make an all-decimal hexadecimal ID disappear from the key ring.
				$config['keys'][ $new['active'] ] = $new['keys'][ $new['active'] ]; // Keep historical and in-flight jobs readable.
				$config['rotated_at']             = $new['rotated_at'];
				$config['issued_at']              = $new['issued_at'];
				self::write_file( $path, $config );
				return $config;
			}
		);
	}

	/** Explicit recovery when no key copy survives; never overwrite an existing file. */
	public static function replace_missing( $target_directory = '' ) {
		self::require_file_managed();
		$old = self::directory();
		if ( $old && @file_exists( $old . '/' . self::FILE ) ) {
			throw new RuntimeException( 'An encryption key file already exists. Restore a recovery copy or rotate the existing key instead.' );
		}
		$directory = trim( $target_directory ) ?: $old;
		if ( defined( 'BACKUPPLUS_CREDENTIAL_KEY_DIR' ) && $directory !== BACKUPPLUS_CREDENTIAL_KEY_DIR ) {
			throw new RuntimeException( 'The key location is controlled by your hosting configuration.' );
		}
		if ( ! $directory ) {
			$uploads   = wp_upload_dir( null, false );
			$directory = ( empty( $uploads['error'] ) ? $uploads['basedir'] : WP_CONTENT_DIR ) . '/' . BackupPlus_Storage_Directory::name( 'backupplus-private' );
		}
		$config = self::with_lock(
			$directory,
			function ( $path ) {
				if ( @file_exists( $path ) ) {
					throw new RuntimeException( 'The selected folder already contains an encryption key. No key was replaced.' );
				}
				$config = self::new_configuration();
				$config['upgraded_sites'][ get_current_blog_id() ]            = true;
				$config['credential_upgraded_sites'][ get_current_blog_id() ] = true;
				self::write_file( $path, $config );
				return $config;
			}
		);
		update_site_option( self::OPTION, rtrim( $directory, '/\\' ) );
		if ( get_site_option( self::OPTION, '' ) !== rtrim( $directory, '/\\' ) ) {
			throw new RuntimeException( 'The replacement key location could not be saved. Please retry.' );
		}
		return $config;
	}

	public static function restore( $contents, $target_directory = '' ) {
		self::require_file_managed();
		$imported         = self::decode( $contents );
		$directory        = self::directory();
		$target_directory = trim( $target_directory );
		if ( defined( 'BACKUPPLUS_CREDENTIAL_KEY_DIR' ) && $target_directory && $target_directory !== $directory ) {
			throw new RuntimeException( 'The key location is controlled by your hosting configuration.' );
		}
		// On a restored site the old absolute path may no longer exist or be allowed.
		if ( $target_directory && $target_directory !== $directory ) {
			if ( $directory && @is_file( $directory . '/' . self::FILE ) ) {
				self::relocate( $target_directory ); // Lock and preserve any existing keys.
				$directory = self::directory();
			} else {
				$directory = $target_directory;
			}
		}
		if ( ! $directory ) {
			$uploads   = wp_upload_dir( null, false );
			$directory = ( empty( $uploads['error'] ) ? $uploads['basedir'] : WP_CONTENT_DIR ) . '/' . BackupPlus_Storage_Directory::name( 'backupplus-private' );
		}
		$config = self::with_lock(
			$directory,
			function ( $path ) use ( $imported ) {
				try {
					$config = @file_exists( $path ) ? self::read_file( $path ) : $imported;
				} catch ( Exception $e ) {
					$config = $imported; }
				foreach ( $imported['keys'] as $id => $key ) {
					if ( isset( $config['keys'][ $id ] ) && $config['keys'][ $id ] !== $key ) {
						throw new RuntimeException( 'The recovery file conflicts with an existing key. No keys were changed.' );
					}
					$config['keys'][ $id ] = $key;
				}
				// Restore the selected recovery state while retaining keys needed by
				// credentials written since that recovery copy was downloaded.
				$config['active']                    = $imported['active'];
				$config['rotated_at']                = $imported['rotated_at'];
				$config['issued_at']                 = $imported['issued_at'];
				$config['upgraded_sites']            = $config['upgraded_sites'] + $imported['upgraded_sites'];
				$config['credential_upgraded_sites'] = $config['credential_upgraded_sites'] + $imported['credential_upgraded_sites'];
				self::verify_credentials( $config );
				self::write_file( $path, $config );
				return $config;
			}
		);
		update_site_option( self::OPTION, rtrim( $directory, '/\\' ) );
		if ( get_site_option( self::OPTION, '' ) !== rtrim( $directory, '/\\' ) ) {
			throw new RuntimeException( 'The recovery key was saved but its location could not be recorded. Please retry.' );
		}
		return $config;
	}

	/** A shared recovery file must unlock every site's credentials before publication. */
	private static function verify_credentials( $config ) {
		$sites = is_multisite() ? get_sites(
			array(
				'fields'     => 'ids',
				'number'     => 0,
				'network_id' => get_current_network_id(),
			)
		) : array( get_current_blog_id() );
		foreach ( $sites as $site ) {
			if ( is_multisite() ) {
				switch_to_blog( $site );
			}
			try {
				do_action( 'backupplus_credentials_verify', $config );
				if ( class_exists( 'BackupPlus_Credential_Storage' ) ) {
					BackupPlus_Credential_Storage::verify( $config );
				}
			} finally {
				if ( is_multisite() ) {
					restore_current_blog();
				}
			}
		}
	}

	/** Completion lives beside the keys, so deleting a DB marker cannot downgrade tokens. */
	public static function upgrade_complete( $scope = 'legacy_tokens' ) {
		$config = self::configuration();
		$field  = 'legacy_tokens' === $scope ? 'upgraded_sites' : 'credential_upgraded_sites';
		if ( self::environment_managed() ) {
			$local = get_option( 'backupplus_environment_credential_upgrades', array() );
			return ! empty( $config[ $field ][ get_current_blog_id() ] ) || ( isset( $local[ $scope ] ) && ! empty( $local[ $scope ][ get_current_blog_id() ] ) );
		}
		return ! empty( $config[ $field ][ get_current_blog_id() ] );
	}

	public static function mark_upgraded( $scope = 'legacy_tokens' ) {
		if ( self::environment_managed() ) {
			$upgrades = get_option( 'backupplus_environment_credential_upgrades', array() );
			if ( ! is_array( $upgrades ) ) {
				$upgrades = array();
			}
			if ( ! isset( $upgrades[ $scope ] ) || ! is_array( $upgrades[ $scope ] ) ) {
				$upgrades[ $scope ] = array();
			}
			$upgrades[ $scope ][ get_current_blog_id() ] = true;
			update_option( 'backupplus_environment_credential_upgrades', $upgrades, false );
			if ( get_option( 'backupplus_environment_credential_upgrades' ) !== $upgrades ) {
				throw new RuntimeException( 'The credential upgrade completion could not be saved.' );
			}
			return;
		}
		self::with_lock(
			self::directory(),
			function ( $path ) use ( $scope ) {
				$config                                    = self::read_file( $path );
				$field                                     = 'legacy_tokens' === $scope ? 'upgraded_sites' : 'credential_upgraded_sites';
				$config[ $field ][ get_current_blog_id() ] = true;
				self::write_file( $path, $config );
			}
		);
	}

	/** Raw reads avoid re-entering the option decryptor while locating its key. */
	private static function has_stored_ciphertext() {
		if ( class_exists( 'BackupPlus_Credential_Storage' ) ) {
			return BackupPlus_Credential_Storage::has_ciphertext();
		}
		return apply_filters( 'backupplus_credentials_has_ciphertext', false );
	}

	/** Exclude the managed directory even when custom backup selections include it. */
	public static function exclude( $excluded, $path ) {
		$directory = self::directory();
		if ( ! $directory ) {
			return $excluded;
		}
		$directory = str_replace( '\\', '/', @realpath( $directory ) ?: $directory );
		$path      = str_replace( '\\', '/', @realpath( $path ) ?: $path );
		return $excluded || $path === $directory || 0 === strpos( $path, $directory . '/' );
	}
}
