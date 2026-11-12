<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}

/**
 * Which programs BackupPlus runs, and the configured values that reach their command lines (BPS-20).
 *
 * A program is only taken from an absolute location that is not writable by every user, so neither a relative or empty PATH
 * entry (the current directory) nor a shared writable folder can supply it. Locations BackupPlus finds by itself, on PATH or in
 * the standard folders, must also not be writable by the PHP user; a location the site owner names explicitly in wp-config.php
 * or a filter may be, because shared hosting often runs PHP as the account that installed the programs. Root can write
 * anywhere, so when PHP runs as root (WP-CLI with --allow-root, containers) such a location must be owned by root instead.
 */
class BackupPlus_Executables {

	/** Folders that hold the system's programs; searched after PATH. */
	const STANDARD_DIRECTORIES = array( '/usr/bin', '/usr/local/bin', '/bin', '/usr/local/mysql/bin' );

	/**
	 * One option from BACKUPPLUS_BINZIP_OPTS: no quotes, white space, shell or control characters, so splitting the constant
	 * at spaces gives exactly the options its author meant, and each is passed to zip as one argument.
	 */
	const OPTION_PATTERN = '/\A[A-Za-z0-9@+=:,.\/_*?-]+\z/D';

	/** The mysqldump --max-allowed-packet value: a number with an optional K, M or G. */
	const PACKET_PATTERN = '/\A[0-9]{1,10}[KMGkmg]?\z/D';

	private static $logged = array();

	/**
	 * Whether the site owner set a program list constant, rather than BackupPlus defining its built-in list. Only a list the owner
	 * set may name programs the PHP user can write.
	 *
	 * @param string $constant BACKUPPLUS_ZIP_EXECUTABLE or BACKUPPLUS_MYSQLDUMP_EXECUTABLE.
	 * @return bool
	 */
	public static function configured( $constant ) {
		return defined( $constant ) && ! defined( $constant . '_BUILT_IN' );
	}

	private static function windows() {
		return 'win' === strtolower( substr( PHP_OS, 0, 3 ) );
	}

	private static function absolute( $path ) {
		return self::windows() ? (bool) preg_match( '#\A(?:[A-Za-z]:)?[\\\\/]#', $path ) : '/' === substr( $path, 0, 1 );
	}

	/** Windows reports every writable file as writable by everyone, so only the PHP user's access is checked there. */
	private static function world_writable( $path ) {
		if ( self::windows() ) {
			return false;
		}
		$permissions = @fileperms( $path );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- An unreadable mode counts as unsafe.
		return false === $permissions || 0 !== ( $permissions & 0002 );
	}

	/** Whether the PHP user could replace a file or folder; for root, whether someone else owns it. */
	private static function owner_writable( $path ) {
		if ( ! self::windows() && function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			return 0 !== @fileowner( $path );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- An unknown owner counts as unsafe.
		}
		return @is_writable( $path );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- open_basedir may hide the path.
	}

	/**
	 * A folder programs may come from, as its canonical path, or false.
	 *
	 * @param mixed $directory         The folder.
	 * @param bool  $owner_may_write   Whether the PHP user may write to it (an explicitly configured folder).
	 * @return string|false
	 */
	public static function trusted_directory( $directory, $owner_may_write = false ) {
		if ( ! is_string( $directory ) || '' === $directory || false !== strpbrk( $directory, "\0\r\n" ) || ! self::absolute( $directory ) ) {
			return false;
		}
		$real = @realpath( $directory );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- open_basedir may hide the folder.
		if ( false === $real || ! @is_dir( $real ) || self::world_writable( $real ) || ( ! $owner_may_write && self::owner_writable( $real ) ) ) {// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- open_basedir may hide the folder.
			return false;
		}
		return $real;
	}

	/**
	 * Whether a program may be run: an absolute path to an executable file which, after links are followed, neither it nor its
	 * folder may be replaced by someone else.
	 *
	 * @param mixed $path            The program.
	 * @param bool  $owner_may_write Whether the PHP user may write to it (an explicitly configured program).
	 * @return bool
	 */
	public static function trusted_binary( $path, $owner_may_write = false ) {
		if ( ! is_string( $path ) || '' === $path || false !== strpbrk( $path, "\0\r\n" ) || ! self::absolute( $path ) ) {
			return false;
		}
		$real = @realpath( $path );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- open_basedir may hide the program.
		if ( false === $real || ! @is_file( $real ) || ! @is_executable( $real ) || self::world_writable( $real ) || ( ! $owner_may_write && self::owner_writable( $real ) ) ) {// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- open_basedir may hide the program.
			return false;
		}
		return false !== self::trusted_directory( dirname( $real ), $owner_may_write );
	}

	/**
	 * Folders to search for programs, in order and without duplicates: the configured folder, then PATH, then the standard
	 * folders. Empty, "." and other relative PATH entries are skipped, as are folders that fail trusted_directory().
	 *
	 * @param mixed       $configured A folder named by the site owner, or ''.
	 * @param string|null $path       The PATH value; null reads the PHP process environment.
	 * @return string[]
	 */
	public static function directories( $configured = '', $path = null ) {
		$candidates = array();
		if ( is_string( $configured ) && '' !== $configured ) {
			$candidates[] = array( $configured, true );
		}
		$path = null === $path ? getenv( 'PATH' ) : $path;
		if ( is_string( $path ) ) {
			foreach ( explode( PATH_SEPARATOR, $path ) as $entry ) {
				$candidates[] = array( $entry, false );
			}
		}
		foreach ( self::STANDARD_DIRECTORIES as $standard ) {
			$candidates[] = array( $standard, false );
		}
		$directories = array();
		foreach ( $candidates as $candidate ) {
			$directory = self::trusted_directory( $candidate[0], $candidate[1] );
			if ( false !== $directory && ! in_array( $directory, $directories, true ) ) {
				$directories[] = $directory;
			}
		}
		return $directories;
	}

	/**
	 * A program path for a shell command line.
	 *
	 * @param string $path An absolute program path.
	 * @return string
	 */
	public static function shell_program( $path ) {
		if ( self::windows() ) {
			// cmd.exe: escapeshellarg() would replace characters such as % with spaces.
			return false !== strpos( $path, ' ' ) ? '"' . $path . '"' : $path;
		}
		return escapeshellarg( $path );
	}

	/**
	 * BACKUPPLUS_BINZIP_OPTS for the zip command line, each option escaped as its own argument; '' when it is not set. A value
	 * with an option outside OPTION_PATTERN is ignored as a whole and logged, because no safe split of it is certain.
	 *
	 * @return string
	 */
	public static function binzip_options() {
		if ( ! defined( 'BACKUPPLUS_BINZIP_OPTS' ) || ! BACKUPPLUS_BINZIP_OPTS ) {
			return '';
		}
		$options = self::split_options( BACKUPPLUS_BINZIP_OPTS );
		if ( null === $options ) {
			self::log_once( 'BACKUPPLUS_BINZIP_OPTS is ignored: it may contain only options and values separated by spaces, without quotes or shell characters.' );
			return '';
		}
		return implode( ' ', array_map( 'escapeshellarg', $options ) );
	}

	/**
	 * Split a list of command-line options at spaces and tabs.
	 *
	 * @param mixed $value The options.
	 * @return string[]|null Null when an option does not match OPTION_PATTERN.
	 */
	public static function split_options( $value ) {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$options = preg_split( '/[ \t]+/', trim( $value, " \t" ), -1, PREG_SPLIT_NO_EMPTY );
		foreach ( $options as $option ) {
			if ( ! preg_match( self::OPTION_PATTERN, $option ) ) {
				return null;
			}
		}
		return $options;
	}

	/**
	 * BACKUPPLUS_MYSQLDUMP_MAX_ALLOWED_PACKET, or the default when it is not set or not a size.
	 *
	 * @param string $default The size to use otherwise.
	 * @return string
	 */
	public static function max_allowed_packet( $default ) {
		if ( ! defined( 'BACKUPPLUS_MYSQLDUMP_MAX_ALLOWED_PACKET' ) ) {
			return $default;
		}
		$value = BACKUPPLUS_MYSQLDUMP_MAX_ALLOWED_PACKET;
		if ( ( is_int( $value ) || is_string( $value ) ) && preg_match( self::PACKET_PATTERN, (string) $value ) ) {
			return (string) $value;
		}
		self::log_once( 'BACKUPPLUS_MYSQLDUMP_MAX_ALLOWED_PACKET is ignored: it must be a number with an optional K, M or G.' );
		return $default;
	}

	private static function log_once( $message ) {
		global $backupplus;
		if ( isset( self::$logged[ $message ] ) ) {
			return;
		}
		self::$logged[ $message ] = true;
		if ( is_object( $backupplus ) && method_exists( $backupplus, 'log' ) ) {
			$backupplus->log( $message );
		}
	}
}
