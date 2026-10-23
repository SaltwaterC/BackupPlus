<?php
/** Interactive CLI restores. Secret input lives only in this process. */
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

class BackupPlus_CLI_Secret {

	/** Run stty against the terminal, with no secret material in process arguments. */
	private static function terminal( $tty, $command ) {
		$process = proc_open(
			'stty ' . $command,
			array(
				0 => $tty,
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		if ( ! is_resource( $process ) ) {
			throw new RuntimeException( 'Cannot configure hidden terminal input.' );
		}
		$output = stream_get_contents( $pipes[1] );
		stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		if ( 0 !== proc_close( $process ) ) {
			throw new RuntimeException( 'Cannot configure hidden terminal input.' );
		}
		return trim( $output );
	}

	/** Hidden native age identity or complete multiline OpenSSH/PEM key. */
	public static function read( $message, $multiline = false ) {
		if ( ! function_exists( 'proc_open' ) || ! function_exists( 'stream_isatty' ) ) {
			throw new RuntimeException( 'Hidden input requires an interactive terminal and stty.' );
		}
		$tty = @fopen( '/dev/tty', 'r+' );
		if ( ! $tty || ! stream_isatty( $tty ) ) {
			if ( is_resource( $tty ) ) {
				fclose( $tty );
			}
			throw new RuntimeException( 'Encrypted restores require an interactive terminal for hidden key and passphrase prompts.' );
		}
		$state         = '';
		$hidden        = false;
		$signals       = array();
		$async_signals = null;
		try {
			$state = self::terminal( $tty, '-g' );
			if ( ! preg_match( '/^[a-fA-F0-9:;]+$/D', $state ) ) {
				throw new RuntimeException( 'Cannot preserve terminal settings.' );
			}
			self::terminal( $tty, '-echo -echonl' );
			$hidden = true;
			if ( function_exists( 'pcntl_async_signals' ) && function_exists( 'pcntl_signal_get_handler' ) ) {
				$async_signals = pcntl_async_signals( true );
				foreach ( array( SIGINT, SIGTERM, SIGHUP ) as $signal ) {
					$signals[ $signal ] = pcntl_signal_get_handler( $signal );
					pcntl_signal(
						$signal,
						function ( $received ) use ( $tty, $state, &$hidden ) {
							self::terminal( $tty, escapeshellarg( $state ) );
							$hidden = false;
							exit( 128 + $received );
						},
						false
					);
				}
			}
			// Restore echo on ordinary shutdown as well as exceptions and successful input.
			register_shutdown_function(
				function () use ( $tty, $state, &$hidden ) {
					if ( $hidden && is_resource( $tty ) ) {
						self::terminal( $tty, escapeshellarg( $state ) );
					}
				}
			);
			fwrite( $tty, $message . ': ' );
			$value = '';
			$end   = '';
			stream_set_blocking( $tty, false );
			while ( true ) {
				// Poll in PHP so signal callbacks can run while no complete input line is available.
				$read  = array( $tty );
				$write = $except = array();
				if ( 1 !== @stream_select( $read, $write, $except, 1 ) ) { continue; }
				$line = fgets( $tty, 4098 );
				if ( false === $line || false === strpos( $line, "\n" ) || strlen( $value ) + strlen( $line ) > 65536 ) {
					throw new RuntimeException( 'Secret input was interrupted or exceeds the supported size.' );
				}
				$line = rtrim( $line, "\r\n" );
				if ( $multiline && '' === $value && ( '' === trim( $line ) || '#' === substr( ltrim( $line ), 0, 1 ) ) ) {
					continue;
				}
				if ( $multiline && '' === $value && preg_match( '/^-----BEGIN ([A-Z0-9 ]+)-----$/D', $line, $match ) ) {
					$end = '-----END ' . $match[1] . '-----';
				}
				$value .= ( '' === $value ? '' : "\n" ) . $line;
				if ( ! $end || $line === $end ) {
					break;
				}
			}
			fwrite( $tty, "\n" );
			return $value;
		}
		finally {
			if ( $hidden ) {
				self::terminal( $tty, escapeshellarg( $state ) );
				$hidden = false;
			}
			foreach ( $signals as $signal => $handler ) {
				pcntl_signal( $signal, $handler );
			}
			if ( null !== $async_signals ) {
				pcntl_async_signals( $async_signals );
			}
			fclose( $tty );
		}
	}
	public static function forget( &$value ) {
		if ( is_string( $value ) && function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $value );
		}
		$value = '';
	}
}

class BackupPlus_CLI_Restore {
	private static $job           = '';
	private static $files         = array();
	private static $cipher_hashes = array();

	/** Select complete archives, optionally stopping a file chain at a known restore point. */
	public static function selection( $timestamp, $args ) {
		global $backupplus;
		if ( ! ctype_digit( (string) $timestamp ) ) {
			throw new InvalidArgumentException( 'Use a backup history timestamp.' );
		}
		$backup = BackupPlus_Backup_History::get_history( $timestamp );
		if ( ! $backup ) {
			throw new InvalidArgumentException( 'Backup timestamp not found.' );
		}
		$type = isset( $args['type'] ) ? $args['type'] : 'all';
		if ( ! in_array( $type, array( 'all', 'files', 'database' ), true ) ) {
			throw new InvalidArgumentException( 'Use --type=all, files or database.' );
		}
		$available       = BackupPlus_Backup_Sources::restore_entities( $backupplus->get_backupable_file_entities( true, true ), $backup );
		$available['db'] = array();
		$entities        = array();
		foreach ( $available as $id => $info ) {
			$db = (bool) preg_match( '/^db[0-9]*$/D', $id );
			if ( ! empty( $backup[ $id ] ) && ( 'all' === $type || ( 'database' === $type ? $db : ! $db ) ) && ( ! isset( $info['restorable'] ) || $info['restorable'] ) ) {
				$entities[] = $id;
			}
		}
		if ( isset( $args['components'] ) ) {
			$selected = BackupPlus_CLI::words( $args['components'] );
			if ( array_diff( $selected, $entities ) ) {
				throw new InvalidArgumentException( 'Unknown, unavailable or incompatible restore components.' );
			}
			$entities = $selected;
		}
		if ( ! $entities ) {
			throw new InvalidArgumentException( 'No components selected for restoration.' );
		}
		$options = array( 'backupplus_restorer_wpcore_includewpconfig' => isset( $args['include-wp-config'] ) );
		if ( isset( $args['point'] ) ) {
			if ( ! ctype_digit( (string) $args['point'] ) || ! isset( $backup['incremental_sets'][ $args['point'] ] ) ) {
				throw new InvalidArgumentException( 'The requested restore point does not belong to this backup chain.' );
			}
			$options['backupplus_incremental_restore_point'] = (int) $args['point'];
			foreach ( $backup['incremental_sets'] as $point => $components ) {
				if ( $point <= (int) $args['point'] ) {
					continue;
				}
				foreach ( $components as $id => $archives ) {
					foreach ( $archives as $index => $file ) {
						unset( $backup[ $id ][ $index ] );
					}
				}
			}
		}
		foreach ( $entities as $id ) {
			if ( empty( $backup[ $id ] ) ) {
				throw new InvalidArgumentException( 'A selected component has no archives at this restore point.' );
			}
			if ( preg_match( '/^db([1-9][0-9]*)$/D', $id, $match ) ) {
				$options['backupplus_external_targets'][ $match[1] ] = array( 'saved' => $match[1] );
			}
		}
		$entities = array_combine( $entities, $entities );
		BackupPlus_Source_Restore::preflight( $entities, $backup, $options );
		$backup['timestamp'] = (int) $timestamp;
		return array( $backup, $entities, $options );
	}

	/** Fetch and verify every selected archive before asking for secrets or modifying the site. */
	public static function archives( $backup, $entities ) {
		global $backupplus;
		$result   = array();
		$services = isset( $backup['service'] ) ? $backupplus->get_canonical_service_list( $backup['service'] ) : array();
		foreach ( $entities as $id => $unused ) {
			foreach ( (array) $backup[ $id ] as $index => $file ) {
				if ( ! is_string( $file ) || basename( $file ) !== $file || '' === $file || '.' === $file || '..' === $file ) {
					throw new RuntimeException( 'Invalid archive filename.' );
				}
				$path = $backupplus->backups_dir_location() . '/' . $file;
				if ( is_link( $path ) ) {
					throw new RuntimeException( 'An archive must not be a symbolic link.' );
				}
				if ( ! is_file( $path ) || ! is_readable( $path ) ) {
					BackupPlus_Storage_Methods_Interface::get_remote_file( $services, $file, $backup['timestamp'], true );
				}
				clearstatcache( true, $path );
				if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) || ! filesize( $path ) ) {
					throw new RuntimeException( 'A selected archive is unavailable: ' . $file );
				}
				$size = $id . ( $index ? $index : '' ) . '-size';
				if ( isset( $backup[ $size ] ) && filesize( $path ) !== (int) $backup[ $size ] ) {
					throw new RuntimeException( 'Archive size verification failed: ' . $file );
				}
				foreach ( isset( $backup['checksums'] ) ? $backup['checksums'] : array() as $algorithm => $checksums ) {
					$key = $id . $index . ( BackupPlus_Encryption::is_file_encrypted( $file ) ? '.crypt' : '' );
					if ( isset( $checksums[ $key ] ) && ( ! in_array( $algorithm, hash_algos(), true ) || ! hash_equals( $checksums[ $key ], hash_file( $algorithm, $path ) ) ) ) {
						throw new RuntimeException( 'Archive checksum verification failed: ' . $file );
					}
				}
				self::$cipher_hashes[ $path ] = hash_file( 'sha256', $path );
				$result[]                     = $path;
			}
		}
		return array_values( array_unique( $result ) );
	}

	/** Decrypt before site writes. The reader returns secrets directly into local variables. */
	public static function decrypt( $archives, $reader ) {
		$identity = $passphrase = $legacy = '';
		try {
			foreach ( $archives as $path ) {
				if ( ! BackupPlus_Encryption::is_file_encrypted( $path ) ) {
					continue;
				}
				$age = BackupPlus_Age_Restore::is_age( $path );
				if ( $age && '' === $identity ) {
					$identity   = call_user_func( $reader, 'Private key (hidden; paste the complete age, OpenSSH or PEM key)', true );
					$passphrase = call_user_func( $reader, 'Private key passphrase (hidden; leave blank if none)', false );
				}
				if ( ! $age && '' === $legacy ) {
					$legacy = call_user_func( $reader, 'Legacy archive passphrase (hidden)', false );
				}
				if ( ! isset( self::$cipher_hashes[ $path ] ) || ! hash_equals( self::$cipher_hashes[ $path ], hash_file( 'sha256', $path ) ) ) {
					throw new RuntimeException( 'A selected archive changed after verification.' );
				}
				$prepared = BackupPlus_Encryption::decrypt( $path, $age ? $identity : $legacy, true, $age ? $passphrase : '' );
				if ( ! is_array( $prepared ) ) {
					throw new RuntimeException( 'Archive decryption failed. Check the private key and passphrase. No restore has started.' );
				}
				self::$files[ $path ]                = $prepared;
				self::$files[ $path ]['cipher_hash'] = self::$cipher_hashes[ $path ];
				self::$files[ $path ]['plain_hash']  = hash_file( 'sha256', $prepared['fullpath'] );
			}
		}
		finally {
			BackupPlus_CLI_Secret::forget( $identity );
			BackupPlus_CLI_Secret::forget( $passphrase );
			BackupPlus_CLI_Secret::forget( $legacy );
		}
	}

	/** Process-local, job-bound plaintext lookup; no secret enters restore options or job data. */
	public static function prepared( $path, $job ) {
		global $backupplus;
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '' === self::$job || $job !== self::$job || $job !== $backupplus->nonce || ! isset( self::$files[ $path ] ) ) {
			return false;
		}
		$entry = self::$files[ $path ];
		if ( is_link( $path ) || is_link( $entry['fullpath'] ) || ! is_file( $path ) || ! is_file( $entry['fullpath'] ) || ! hash_equals( $entry['cipher_hash'], hash_file( 'sha256', $path ) ) || ! hash_equals( $entry['plain_hash'], hash_file( 'sha256', $entry['fullpath'] ) ) ) {
			return false;
		}
		return array_intersect_key( $entry, array_flip( array( 'fullpath', 'basename' ) ) );
	}
	public static function cleanup() {
		foreach ( self::$files as $entry ) {
			if ( is_file( $entry['fullpath'] ) && ! is_link( $entry['fullpath'] ) ) {
				unlink( $entry['fullpath'] );
			}
		}
		self::$files         = array();
		self::$cipher_hashes = array();
		self::$job           = '';
	}
	public static function run( $timestamp, $args, $reader = null ) {
		global $backupplus, $backupplus_restorer;
		list( $backup, $entities, $options ) = self::selection( $timestamp, $args );
		WP_CLI::confirm( 'Restore backup ' . $timestamp . ' (' . implode( ', ', array_keys( $entities ) ) . ')? This overwrites the selected current files and database tables.', $args );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		if ( $backupplus->check_restore_progress( 1 ) || BackupPlus_Retention_Cleanup::backup_active() || ! WP_Upgrader::create_lock( 'backupplus_cli_restore', HOUR_IN_SECONDS ) ) {
			throw new RuntimeException( 'Another backup or restore is active.' );
		}
		$complete  = false;
		$succeeded = false;
		$job       = '';
		register_shutdown_function(
			function () use ( &$complete, &$job ) {
				self::cleanup();
				if ( $job ) {
					global $backupplus;
					if ( ! $complete ) { $backupplus->jobdata_set( 'jobstatus', 'failed' ); }
					if ( get_site_option( 'backupplus_restore_in_progress' ) === $job ) { delete_site_option( 'backupplus_restore_in_progress' ); }
					WP_Upgrader::release_lock( 'backupplus_cli_restore' );
				}
				if ( ! $complete ) {
					register_shutdown_function(
						function () {
							exit( 1 );
						}
					);
				}
			}
		);
		try {
			$backupplus->initiate_restore_job();
			$job      = self::$job = $backupplus->nonce;
			$archives = self::archives( $backup, $entities );
			self::decrypt( $archives, null === $reader ? array( 'BackupPlus_CLI_Secret', 'read' ) : $reader );
			foreach ( self::$cipher_hashes as $path => $hash ) {
				if ( ! is_file( $path ) || is_link( $path ) || ! hash_equals( $hash, hash_file( 'sha256', $path ) ) ) {
					throw new RuntimeException( 'A selected archive changed after verification.' );
				}
			}
			if ( self::$files ) {
				$options['backupplus_age_ticket'] = 'cli';
			}
			if ( ! WP_Filesystem() ) {
				throw new RuntimeException( 'Could not initialize the WordPress filesystem for restore.' );
			}
			$backupplus_restorer = new BackupPlus_Restorer( new BackupPlus_Restorer_Skin(), $backup, false, $options );
			$error_levels        = version_compare( PHP_VERSION, '8.4.0', '>=' ) ? E_ALL : E_ALL & ~E_STRICT;
			set_error_handler( array( $backupplus, 'php_error' ), $error_levels );
			$handler_active = true;
			try {
				$result = $backupplus_restorer->perform_restore( $entities, $options );
				$backupplus_restorer->post_restore_clean_up( $result, false );
				$handler_active = false;
			}
			finally {
				if ( $handler_active ) {
					restore_error_handler();
				}
			}
			if ( true !== $result ) {
				throw new RuntimeException( is_wp_error( $result ) ? $result->get_error_message() : 'Restore failed. Review log.' . $job . '.txt.' );
			}
			$succeeded = true;
			WP_CLI::success( 'Restore completed: ' . $timestamp );
		}
		finally {
			$complete = true;
			if ( $job ) {
				$backupplus->jobdata_set( 'jobstatus', $succeeded ? 'finished' : 'failed' );
				if ( get_site_option( 'backupplus_restore_in_progress' ) === $job ) { delete_site_option( 'backupplus_restore_in_progress' ); }
			}
			self::cleanup();
			WP_Upgrader::release_lock( 'backupplus_cli_restore' );
			$job = '';
			if ( isset( $backupplus_restorer ) ) {
				remove_filter( 'backupplus_logline', array( $backupplus_restorer, 'backupplus_logline' ), 10 );
			}
		}
	}
}
