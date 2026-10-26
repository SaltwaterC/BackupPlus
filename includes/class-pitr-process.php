<?php

/** Private native-client execution for database point-in-time backups. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

class BackupPlus_PITR_Process {
	private static $connections = array();

	/** Request-lifetime credentials. Native clients read an inherited pipe, never a disk file. */
	public static function memory_connection( $options ) {
		$raw = "[client]\n";
		foreach ( $options as $name => $value ) {
			if ( ! preg_match( '/\A[a-z-]+\z/D', $name ) || ! is_scalar( $value ) || false !== strpbrk( (string) $value, "\r\n\0" ) ) { throw new RuntimeException( 'Invalid database connection value.' ); }
			$raw .= $name . '="' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), (string) $value ) . "\"\n";
		}
		$id                       = 'memory:' . bin2hex( random_bytes( 16 ) );
		self::$connections[ $id ] = array(
			'raw'     => $raw,
			'options' => $options,
		);
		return $id;
	}

	public static function connection_options( $path ) {
		if ( isset( self::$connections[ $path ] ) ) { return self::$connections[ $path ]['options']; }
		$raw     = file_get_contents( $path );
		$options = parse_ini_string( $raw, true, INI_SCANNER_RAW );
		if ( ! is_array( $options ) || empty( $options['client']['user'] ) ) { throw new RuntimeException( 'Invalid database connection.' ); }
		$escapes = array(
			'\\\\' => '\\',
			'\\"'  => '"',
			"\\'"  => "'",
			'\\s'  => ' ',
			'\\n'  => "\n",
			'\\r'  => "\r",
			'\\t'  => "\t",
			'\\b'  => "\x08",
		);
		foreach ( $options['client'] as &$value ) {
			$value = strtr( $value, $escapes );
		}
		unset( $value );
		return $options['client'];
	}

	public static function forget_connection( $path ) {
		unset( self::$connections[ $path ] );
	}

	/** Bound parameters need the native protocol; the connection keeps the option file's verified TLS settings. */
	public static function mysqli( $options ) {
		mysqli_report( MYSQLI_REPORT_OFF );
		$link = mysqli_init();
		mysqli_options( $link, MYSQLI_OPT_CONNECT_TIMEOUT, 5 );
		$flags = 0;
		if ( ! empty( $options['ssl-ca'] ) || ! empty( $options['ssl-cert'] ) ) {
			mysqli_ssl_set( $link, isset( $options['ssl-key'] ) ? $options['ssl-key'] : null, isset( $options['ssl-cert'] ) ? $options['ssl-cert'] : null, isset( $options['ssl-ca'] ) ? $options['ssl-ca'] : null, null, null );
			mysqli_options( $link, MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, true );
			$flags = MYSQLI_CLIENT_SSL;
		}
		try {
			$connected = @mysqli_real_connect( $link, isset( $options['host'] ) ? $options['host'] : 'localhost', $options['user'], isset( $options['password'] ) ? $options['password'] : '', null, isset( $options['port'] ) ? (int) $options['port'] : 3306, isset( $options['socket'] ) ? $options['socket'] : null, $flags );
		} catch ( Throwable $error ) { $connected = false; }
		if ( ! $connected ) { throw new RuntimeException( 'Could not open the database connection. ' . self::failure_detail( (string) mysqli_connect_error() ) ); }
		mysqli_set_charset( $link, 'utf8mb4' );
		return $link;
	}

	/** Both one-shot commands and persistent lock sessions use the same credential transport. */
	public static function open( $arguments, $descriptors, &$pipes, $directory ) {
		$secret = null;
		foreach ( $arguments as &$argument ) {
			if ( 0 === strpos( $argument, '--defaults-file=memory:' ) ) {
				$id = substr( $argument, strlen( '--defaults-file=' ) );
				if ( ! isset( self::$connections[ $id ] ) ) { throw new RuntimeException( 'Restore credentials have expired. Enter them again.' ); }
				$secret         = self::$connections[ $id ]['raw'];
				$argument       = '--defaults-file=/dev/fd/3';
				$descriptors[3] = array( 'pipe', 'r' );
			}
		}
		unset( $argument );
		$process = proc_open(
			'exec ' . implode( ' ', array_map( 'escapeshellarg', $arguments ) ),
			$descriptors,
			$pipes,
			$directory,
			array(
				'PATH'   => '/usr/bin:/bin',
				'LC_ALL' => 'C',
				'TZ'     => 'UTC',
			)
		);
		if ( is_resource( $process ) && null !== $secret ) {
			$written = fwrite( $pipes[3], $secret );
			fclose( $pipes[3] );
			unset( $pipes[3] );
			if ( strlen( $secret ) !== $written ) { proc_terminate( $process );
				proc_close( $process );
				throw new RuntimeException( 'Could not deliver the database credentials to the native client.' ); }
		}
		return $process;
	}

	public static function private_file( $path, $contents = '' ) {
		$mask = umask( 0077 );
		try {
			$handle = fopen( $path, 'xb' );
		} finally {
			umask( $mask );
		}
		if ( ! $handle ) {
			throw new RuntimeException( 'Could not create private PITR file.' );
		}
		try {
			if ( ! chmod( $path, 0600 ) ) {
				throw new RuntimeException( 'Could not protect PITR file.' );
			}
			$offset = 0;
			while ( $offset < strlen( $contents ) ) {
				$written = fwrite( $handle, substr( $contents, $offset ) );
				if ( ! $written ) {
					throw new RuntimeException( 'Could not write PITR file.' );
				}
				$offset += $written;
			}
			if ( ! fflush( $handle ) || ( function_exists( 'fsync' ) && ! fsync( $handle ) ) ) {
				throw new RuntimeException( 'Could not flush private PITR file.' );
			}
		} finally {
			fclose( $handle );
		}
		return $path;
	}

	public static function directory( $path ) {
		if ( is_link( $path ) || ( ! is_dir( $path ) && ! mkdir( $path, 0700, true ) ) || ! chmod( $path, 0700 ) ) {
			throw new RuntimeException( 'Could not protect PITR working directory.' );
		}
		return $path;
	}

	/** Only a client connection group is accepted; tool behavior comes from our argv. */
	public static function connection_file( $path, $directory ) {
		if ( is_string( $path ) && isset( self::$connections[ $path ] ) ) { return $path; }
		if ( ! is_string( $path ) || is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) || filesize( $path ) > 65536 || ( fileperms( $path ) & 0077 ) ) {
			throw new RuntimeException( 'Use a readable, owner-only client option file (mode 0600).' );
		}
		$raw     = file_get_contents( $path );
		$allowed = array( 'host', 'port', 'socket', 'protocol', 'user', 'password', 'ssl', 'ssl-ca', 'ssl-cert', 'ssl-key', 'ssl-mode', 'ssl-verify-server-cert' );
		$group   = false;
		foreach ( preg_split( '/\r\n|\n|\r/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] || ';' === $line[0] ) {
				continue;
			}
			if ( '[client]' === $line && ! $group ) {
				$group = true;
				continue;
			}
			if ( ! $group || ! preg_match( '/^([a-z-]+)\s*(?:=|$)/D', $line, $m ) || ! in_array( $m[1], $allowed, true ) ) {
				throw new RuntimeException( 'PITR option files accept only connection options in one [client] group.' );
			}
		}
		$options = parse_ini_string( $raw, true, INI_SCANNER_RAW );
		if ( ! is_array( $options ) || empty( $options['client']['user'] ) ) {
			throw new RuntimeException( 'Invalid PITR client options.' );
		}
		$options  = $options['client'];
		$host     = isset( $options['host'] ) ? $options['host'] : 'localhost';
		$protocol = isset( $options['protocol'] ) ? strtolower( $options['protocol'] ) : '';
		$local    = ( 'socket' === $protocol ) || ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) && in_array( $protocol, array( '', 'socket', 'tcp' ), true ) );
		if ( ! $local && isset( $options['ssl'] ) && in_array( strtolower( $options['ssl'] ), array( '0', 'false', 'off', 'no' ), true ) ) {
			throw new RuntimeException( 'Remote PITR connections cannot disable TLS.' );
		}
		if ( ! $local && ( empty( $options['ssl-ca'] ) || ( ( ! isset( $options['ssl-mode'] ) || 'VERIFY_IDENTITY' !== $options['ssl-mode'] ) && ( ! isset( $options['ssl-verify-server-cert'] ) || ! in_array( strtolower( $options['ssl-verify-server-cert'] ), array( '1', 'true', 'on' ), true ) ) ) ) ) {
			throw new RuntimeException( 'Remote PITR connections require a CA and verified server identity.' );
		}
		return self::private_file( $directory . '/client-' . bin2hex( random_bytes( 12 ) ) . '.cnf', $raw );
	}

	public static function binary( $name, $directory = '/usr/bin' ) {
		$path = rtrim( $directory, '/' ) . '/' . $name;
		if ( ! is_file( $path ) || ! is_executable( $path ) ) {
			throw new RuntimeException( 'Required native database client is unavailable: ' . $name );
		}
		return $path;
	}

	/** Classify native diagnostics without exposing SQL, passwords or arbitrary stderr. */
	public static function failure_detail( $diagnostic ) {
		if ( preg_match( '/TLS\/SSL|SSL connection|SSL error|TLS error|certificate verify|certificate validation|certificate verification|ERROR\s+2026\b/i', $diagnostic ) ) {
			if ( preg_match( '/hostname|host name|does not match|doesn.t match|IP address mismatch/i', $diagnostic ) ) {
				return 'TLS certificate verification failed: the certificate does not match the database hostname. Use the hostname on the certificate or replace the server certificate.';
			}
			if ( preg_match( '/self.signed|unable to get.*issuer|unknown ca|not trusted|unable to verify.*certificate/i', $diagnostic ) ) {
				return 'TLS certificate verification failed: the database certificate is self-signed or its issuing CA is not trusted. Install the database CA in the system trust store or configure a trusted ssl-ca in the PITR client option file.';
			}
			if ( preg_match( '/expired|not yet valid/i', $diagnostic ) ) {
				return 'TLS certificate verification failed: the database certificate is expired or not yet valid. Check the server clock and renew the certificate.';
			}
			if ( preg_match( '/verify|verification|validation/i', $diagnostic ) ) {
				return 'TLS certificate verification failed. Check the trusted CA, certificate validity and database hostname in the PITR connection settings.';
			}
			return 'TLS connection failed. Check that the database supports TLS and that its protocol, cipher and client-certificate settings match the PITR client.';
		}
		if ( preg_match( '/ERROR\s+(1045|1698)\b/i', $diagnostic ) ) {
			return 'Database authentication failed. Check the WordPress database account and the saved PITR connection credentials.';
		}
		if ( preg_match( '/ERROR\s+(1044|1142|1227|1370)\b/i', $diagnostic ) ) {
			foreach ( array( 'BINLOG ADMIN', 'BINLOG REPLAY', 'BINLOG_ADMIN', 'BACKUP_ADMIN', 'CONNECTION_ADMIN', 'CONNECTION ADMIN', 'CREATE USER', 'RELOAD', 'REPLICATION CLIENT', 'REPLICATION SLAVE', 'BINLOG MONITOR', 'PROCESS', 'SELECT', 'SHOW VIEW', 'TRIGGER', 'EVENT' ) as $privilege ) {
				if ( preg_match( '/\b' . preg_quote( $privilege, '/' ) . '\b/i', $diagnostic ) ) { return 'The database account needs the ' . $privilege . ' privilege for this PITR operation. Check the backup or restore account grants.'; }
			}
			return 'The database account lacks a required PITR privilege. Check permissions for backup locks, metadata, log rotation and binary-log downloads.';
		}
		if ( preg_match( '/ERROR\s+(2002|2003|2005)\b/i', $diagnostic ) ) {
			return 'Could not reach the database. Check the hostname, port or Unix socket and that the database server is running.';
		}
		if ( preg_match( '/ERROR\s+(2006|2013)\b/i', $diagnostic ) ) {
			return 'The database connection was lost during the PITR operation. Check server availability and connection timeouts.';
		}
		if ( preg_match( '/ERROR\s+1290\b.*read.only|read.only.*prevent.*statement/i', $diagnostic ) ) {
			return 'Database writes are temporarily blocked by server read-only protection. Wait for the active recovery to finish or ask the database administrator to check the protection state.';
		}
		if ( preg_match( '/unknown (?:option|variable)/i', $diagnostic ) ) {
			return 'The native database client rejected a required option. Install supported client tools matching the database vendor and version.';
		}
		if ( preg_match( '/Flush logs or delete master logs failure/i', $diagnostic ) ) {
			return 'Database log rotation failed. Check the database server error log, log-file paths and permissions, and the account RELOAD privilege.';
		}
		if ( preg_match( '/ERROR\s+1419\b/i', $diagnostic ) ) { return 'The restore account needs SUPER to recreate stored functions or triggers while binary logging is enabled (log_bin_trust_function_creators=0). Ask your host for a restore-only account with that privilege.'; }
		if ( preg_match( '/ERROR\s+([0-9]{3,5})\b/i', $diagnostic, $error ) ) { return 'Database error ' . $error[1] . '. Check the database connection, privileges and client compatibility.'; }
		return 'Check the database connection, privileges and client compatibility.';
	}

	/** No shell pipeline: retain and check the exit code of each producer/consumer. */
	public static function run( $arguments, $directory, $input = null, $output = null, $timeout = 1800, $monitor = null ) {
		if ( '\\' === DIRECTORY_SEPARATOR || ! function_exists( 'proc_open' ) ) {
			throw new RuntimeException( 'PITR requires a Unix PHP runtime with process support.' );
		}
		@ignore_user_abort( true );
		@set_time_limit( 0 );
		$stdout = null === $output ? $directory . '/out-' . bin2hex( random_bytes( 8 ) ) : $output;
		$stderr = $directory . '/err-' . bin2hex( random_bytes( 8 ) );
		self::private_file( $stdout );
		self::private_file( $stderr );
		$process      = false;
		$exit         = -1;
		$signals      = array();
		$asynchronous = null;
		if ( function_exists( 'pcntl_async_signals' ) && function_exists( 'pcntl_signal_get_handler' ) ) {
			$asynchronous = pcntl_async_signals();
			pcntl_async_signals( true );
			foreach ( array( SIGINT, SIGTERM ) as $signal ) {
				$signals[ $signal ] = pcntl_signal_get_handler( $signal );
				pcntl_signal(
					$signal,
					function () {
						throw new RuntimeException( 'PITR worker was cancelled.' );
					}
				);
			}
		}
		try {
			$process = self::open(
				$arguments,
				array(
					0 => array( 'file', null === $input ? '/dev/null' : $input, 'r' ),
					1 => array( 'file', $stdout, 'w' ),
					2 => array( 'file', $stderr, 'w' ),
				),
				$pipes,
				$directory
			);
			if ( ! is_resource( $process ) ) {
				throw new RuntimeException( 'Could not start native PITR client.' );
			}
			$deadline = microtime( true ) + $timeout;
			// Most clients answer within milliseconds: poll quickly at first and back off to 100 ms for the long dumps and replays.
			$pause = 1000;
			do {
				$status = proc_get_status( $process );
				if ( ! $status['running'] ) {
					$exit = $status['exitcode'];
					break;
				}
				if ( null !== $monitor ) {
					call_user_func( $monitor, $status );
				}
				if ( microtime( true ) > $deadline ) {
					throw new RuntimeException( 'Native PITR operation timed out.' );
				}
				usleep( $pause );
				$pause = min( 100000, $pause * 2 );
			} while ( true );
			$closed  = proc_close( $process );
			$process = false;
			if ( 0 !== ( $exit < 0 ? $closed : $exit ) ) {
				$diagnostic = file_get_contents( $stderr, false, null, 0, 8192 );
				throw new RuntimeException( 'Native PITR client ' . basename( $arguments[0] ) . ' failed (exit ' . ( $exit < 0 ? $closed : $exit ) . '). ' . self::failure_detail( $diagnostic ) );
			}
			if ( null !== $output ) {
				return $output;
			}
			if ( filesize( $stdout ) > 8388608 ) {
				throw new RuntimeException( 'PITR client response exceeded its limit.' );
			}
			return file_get_contents( $stdout );
		} finally {
			if ( is_resource( $process ) ) {
				proc_terminate( $process );
				usleep( 100000 );
				proc_terminate( $process, 9 );
				proc_close( $process );
			}
			if ( is_file( $stderr ) ) {
				unlink( $stderr );
			}
			if ( null === $output && is_file( $stdout ) ) {
				unlink( $stdout );
			}
			foreach ( $signals as $signal => $handler ) {
				pcntl_signal( $signal, $handler );
			}
			if ( null !== $asynchronous ) {
				pcntl_async_signals( $asynchronous );
			}
		}
	}
}
