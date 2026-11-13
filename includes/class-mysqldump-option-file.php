<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Backup archives, logs, keys and temporary files are streamed, locked and given permissions directly, which WP_Filesystem cannot do.

/** The option file that gives mysqldump the database password without putting it on the command line (BPS-08). */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

class BackupPlus_Mysqldump_Option_File {

	/** Files not removed yet. A timeout or fatal error skips the caller's finally block, but not shutdown functions. */
	private static $pending = array();

	private static $registered = false;

	/** A line break or NUL would end the password line and start new options. BackupPlus_PITR_Process refuses the same characters. */
	public static function valid_password( $password ) {
		return is_string( $password ) && false === strpbrk( $password, "\r\n\0" );
	}

	/** The same quoting as BackupPlus_PITR_Process::memory_connection(). */
	public static function contents( $password ) {
		if ( ! self::valid_password( $password ) ) {
			throw new InvalidArgumentException( 'The database password contains a line break or NUL character, which a mysqldump option file cannot hold.' );
		}
		return "[mysqldump]\npassword=\"" . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $password ) . "\"\n";
	}

	/**
	 * Create an owner-only option file holding the password. Remove it with remove() in a finally block.
	 *
	 * @param String $directory - the folder for the file, normally the backup directory
	 * @param String $password  - the database password
	 *
	 * @return String - the path of the new file; its name is 32 hexadecimal characters and .tmp
	 *
	 * @throws RuntimeException - including InvalidArgumentException for a password the file cannot hold. Nothing is left on disk.
	 */
	public static function create( $directory, $password ) {
		$contents = self::contents( $password );
		$path     = rtrim( $directory, '/\\' ) . '/' . bin2hex( random_bytes( 16 ) ) . '.tmp';
		$mask     = umask( 0077 );
		try {
			$handle = @fopen( $path, 'xb' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- The failure is reported by the exception below.
		} finally {
			umask( $mask );
		}
		if ( ! $handle ) {
			throw new RuntimeException( 'Could not create the mysqldump option file.' );
		}
		if ( ! self::$registered ) {
			register_shutdown_function( array( __CLASS__, 'remove_pending' ) );
			self::$registered = true;
		}
		self::$pending[ $path ] = true;
		try {
			$written = chmod( $path, 0600 ) && strlen( $contents ) === fwrite( $handle, $contents ) && fflush( $handle );
		} finally {
			fclose( $handle );
		}
		if ( ! $written ) {
			self::remove( $path );
			throw new RuntimeException( 'Could not write the mysqldump option file.' );
		}
		return $path;
	}

	public static function remove( $path ) {
		unset( self::$pending[ $path ] );
		if ( file_exists( $path ) || is_link( $path ) ) {
			wp_delete_file( $path );
		}
	}

	public static function remove_pending() {
		foreach ( array_keys( self::$pending ) as $path ) {
			self::remove( $path );
		}
	}
}
