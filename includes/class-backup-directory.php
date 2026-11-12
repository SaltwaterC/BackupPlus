<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}
require_once __DIR__ . '/class-web-guard.php';

/**
 * Where the backup directory may be (BPS-07).
 *
 * The backupplus_dir setting accepts only a single folder name under WP_CONTENT_DIR that is not a WordPress, plugin, theme,
 * uploads or must-use plugin folder. Another location needs BACKUPPLUS_BACKUP_DIRECTORY in wp-config.php. Whatever the source,
 * the resolved directory is never one of those folders or an ancestor of them, and the protection files written into it never
 * replace existing files, other than an .htaccess file that an earlier BackupPlus version wrote.
 */
class BackupPlus_Backup_Directory {

	const OPTION = 'backupplus_dir';

	const CONSTANT = 'BACKUPPLUS_BACKUP_DIRECTORY';

	/** A single folder name; the first character keeps out ".", ".." and hidden folders. */
	const NAME_PATTERN = '/\A[A-Za-z0-9_][A-Za-z0-9_.\-]{0,99}\z/D';

	/** Folders WordPress, plugins and BackupPlus itself use directly inside wp-content. */
	const RESERVED_NAMES = array( 'plugins', 'mu-plugins', 'themes', 'uploads', 'languages', 'upgrade', 'upgrade-temp-backup', 'cache', 'fonts', 'blogs.dir' );

	/** The credential key folder (BackupPlus_Storage_Directory::name( 'backupplus-private' )) must never be backed up into. */
	const RESERVED_NAME_PATTERN = '/\Abackupplus-private(?:-|\z)/iD';

	/**
	 * Names WordPress loads from wp-content (drop-ins such as db.php and object-cache.php) or that a web server runs: a folder
	 * so named could be required on every request or shadow one.
	 */
	const EXECUTABLE_NAME_PATTERN = '/\.(?:php[0-9]*|phtml|phar|pht)\z/iD';

	const INDEX_HTML = '<html><body>WordPress backups by BackupPlus</body></html>';

	/** Denies access on Apache 2.4 and 2.2 alike (BPS-20). */
	const HTACCESS = BackupPlus_Web_Guard::HTACCESS;

	const WEB_CONFIG = "<configuration>\n<system.webServer>\n<authorization>\n<deny users=\"*\" />\n</authorization>\n</system.webServer>\n</configuration>\n";

	public static function register() {
		add_filter( 'sanitize_option_' . self::OPTION, array( __CLASS__, 'sanitize' ), 10, 2 );
	}

	/** The absolute location from BACKUPPLUS_BACKUP_DIRECTORY, or '' when it is not defined or not absolute. */
	public static function constant_path() {
		if ( ! defined( self::CONSTANT ) ) {
			return '';
		}
		$path = constant( self::CONSTANT );
		if ( ! is_string( $path ) || ! self::is_absolute( $path ) || false !== strpos( $path, "\0" ) ) {
			return '';
		}
		return self::untrailingslash( $path );
	}

	/**
	 * The sanitize_option_backupplus_dir filter. An invalid value keeps the saved one and reports why.
	 *
	 * @param mixed $value The submitted value.
	 * @return string The value to store.
	 */
	public static function sanitize( $value ) {
		$current = BackupPlus_Options::get_backupplus_option( self::OPTION, '' );
		$current = is_string( $current ) ? $current : '';
		if ( ! is_string( $value ) ) {
			return self::reject( $current, __( 'The backup directory must be a folder name.', 'backupplus' ) );
		}
		$name = self::normalize( $value );
		// The settings form shows where the saved value leads and sends that back on every save. A value that has not changed is
		// kept as it is, including one saved before these checks; the resolver still refuses unsafe locations.
		$unchanged = '' === $name || self::normalize( $current ) === $name || self::normalize( self::resolve( $current ) ) === $name;
		if ( '' !== self::constant_path() ) {
			if ( $unchanged || self::normalize( self::constant_path() ) === $name ) {
				return $current;
			}
			return self::reject( $current, __( 'The backup directory is set by BACKUPPLUS_BACKUP_DIRECTORY in wp-config.php.', 'backupplus' ) );
		}
		if ( $unchanged ) {
			return $name;
		}
		$error = self::invalid( $name );
		return null === $error ? $name : self::reject( $current, $error );
	}

	/**
	 * Why a new backup directory name is refused.
	 *
	 * @param string $name A normalized setting value.
	 * @return string|null The reason, or null when the name is accepted.
	 */
	public static function invalid( $name ) {
		if ( ! is_string( $name ) || ! preg_match( self::NAME_PATTERN, $name ) ) {
			/* translators: %s: The content directory, normally wp-content */
			return sprintf( __( 'The backup directory must be a single folder name inside %s, such as my-backups. To keep backups elsewhere, define BACKUPPLUS_BACKUP_DIRECTORY in wp-config.php.', 'backupplus' ), basename( WP_CONTENT_DIR ) );
		}
		$path = WP_CONTENT_DIR . '/' . $name;
		if ( in_array( strtolower( $name ), self::RESERVED_NAMES, true ) || preg_match( self::RESERVED_NAME_PATTERN, $name ) || preg_match( self::EXECUTABLE_NAME_PATTERN, $name ) || self::unsafe( $path ) ) {
			return __( 'The backup directory cannot be a WordPress, plugin, theme, must-use plugin or uploads folder.', 'backupplus' );
		}
		$content  = self::canonical( WP_CONTENT_DIR );
		$resolved = self::canonical( $path );
		if ( false === $content || false === $resolved || dirname( $resolved ) !== $content ) {
			/* translators: %s: The content directory, normally wp-content */
			return sprintf( __( 'The backup directory must resolve to a folder directly inside %s.', 'backupplus' ), basename( WP_CONTENT_DIR ) );
		}
		if ( file_exists( $path ) && ! is_dir( $path ) ) {
			return __( 'The backup directory name is already used by a file.', 'backupplus' );
		}
		if ( is_dir( $path ) && ( ! self::own_file( $path, 'index.html' ) || ! self::own_file( $path, '.htaccess' ) ) ) {
			/* translators: %s: The folder name */
			return sprintf( __( 'The folder %s already has its own index.html or .htaccess file. BackupPlus does not replace them; choose a new folder name.', 'backupplus' ), $name );
		}
		// Another plugin's folder must not be taken over: its public files would be blocked and its temporary files removed. A
		// folder of backups whose protection files were removed is still accepted.
		$entries = is_dir( $path ) && ! file_exists( $path . '/index.html' ) ? array_diff( (array) @scandir( $path ), array( '.', '..' ) ) : array();// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- An unreadable folder lists nothing.
		if ( $entries && ! preg_grep( '/\A(?:backup_|log\.)/', $entries ) ) {
			/* translators: %s: The folder name */
			return sprintf( __( 'The folder %s already holds files that BackupPlus did not create; choose a new or empty folder.', 'backupplus' ), $name );
		}
		return null;
	}

	/**
	 * Whether a resolved backup directory is, contains or lies inside a folder that must not hold backups.
	 *
	 * @param string $path An absolute path.
	 * @return bool
	 */
	public static function unsafe( $path ) {
		$resolved = self::canonical( $path );
		if ( false === $resolved || '/' === $resolved ) {
			return true;
		}
		// Folders that must not be the backup directory or below it.
		$containers = array( ABSPATH, WP_CONTENT_DIR );
		// Folders whose contents WordPress loads or serves: the backup directory must not be below them either.
		$guarded = array( ABSPATH . 'wp-admin', ABSPATH . 'wp-includes', WP_CONTENT_DIR . '/plugins', WP_CONTENT_DIR . '/mu-plugins', WP_CONTENT_DIR . '/themes', WP_CONTENT_DIR . '/uploads' );
		foreach ( array( 'WP_PLUGIN_DIR', 'WPMU_PLUGIN_DIR', 'BACKUPPLUS_DIR' ) as $constant ) {
			if ( defined( $constant ) && is_string( constant( $constant ) ) ) {
				$guarded[] = constant( $constant );
			}
		}
		if ( ! empty( $GLOBALS['wp_theme_directories'] ) && is_array( $GLOBALS['wp_theme_directories'] ) ) {
			$guarded = array_merge( $guarded, array_filter( $GLOBALS['wp_theme_directories'], 'is_string' ) );
		}
		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir( null, false );
			if ( is_array( $uploads ) && ! empty( $uploads['basedir'] ) && is_string( $uploads['basedir'] ) ) {
				$guarded[] = $uploads['basedir'];
			}
		}
		foreach ( array_merge( $containers, $guarded ) as $folder ) {
			$folder = self::canonical( $folder );
			if ( false !== $folder && ( $folder === $resolved || self::inside( $resolved, $folder ) ) ) {
				return true;
			}
		}
		foreach ( $guarded as $folder ) {
			$folder = self::canonical( $folder );
			if ( false !== $folder && self::inside( $folder, $resolved ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Create the protection files that are missing. Existing files, including links, are left alone, except that an .htaccess
	 * file with exactly what an earlier version wrote is brought up to date (BPS-20).
	 *
	 * @param string $directory The backup directory.
	 */
	public static function protect( $directory ) {
		BackupPlus_Web_Guard::upgrade_htaccess( $directory . '/.htaccess' );
		foreach ( array(
			'index.html' => self::INDEX_HTML,
			'.htaccess'  => self::HTACCESS,
			'web.config' => self::WEB_CONFIG,
		) as $file => $contents ) {
			$path = $directory . '/' . $file;
			if ( file_exists( $path ) || is_link( $path ) ) {
				continue;
			}
			// Exclusive creation fails on any existing file or link, so a file that appears meanwhile is not replaced.
			$handle = @fopen( $path, 'xb' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A concurrent request may create the file first.
			if ( false !== $handle ) {
				fwrite( $handle, $contents );// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Direct file system access in the backup directory.
				fclose( $handle );// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Direct file system access in the backup directory.
			}
		}
	}

	/**
	 * The absolute path a saved setting value leads to, before the resolver checks it; '' for the default.
	 *
	 * @param string $value The saved setting value.
	 * @return string
	 */
	public static function resolve( $value ) {
		$value = self::untrailingslash( trim( (string) $value, " \t\r\n" ) );
		if ( '' === $value || self::is_absolute( $value ) ) {
			return $value;
		}
		if ( 0 === strpos( $value, 'wp-content/' ) && ABSPATH . 'wp-content' === WP_CONTENT_DIR ) {
			return WP_CONTENT_DIR . '/' . substr( $value, strlen( 'wp-content/' ) );
		}
		// Very old installations stored the folder relative to ABSPATH; such a folder was always protected and had no index.php.
		$legacy = ABSPATH . $value;
		if ( is_dir( $legacy ) && is_file( $legacy . '/index.html' ) && is_file( $legacy . '/.htaccess' ) && ! is_file( $legacy . '/index.php' ) && self::own_file( $legacy, '.htaccess' ) ) {
			return $legacy;
		}
		return self::untrailingslash( WP_CONTENT_DIR ) . '/' . $value;
	}

	/**
	 * The setting value as a name relative to WP_CONTENT_DIR when it is inside it, otherwise as given.
	 *
	 * @param string $value The setting value.
	 * @return string
	 */
	public static function normalize( $value ) {
		// Only spaces and line ends are trimmed: a NUL or other control character fails the name pattern.
		$value = self::untrailingslash( trim( (string) $value, " \t\r\n" ) );
		if ( self::is_absolute( $value ) ) {
			$content = self::untrailingslash( WP_CONTENT_DIR ) . '/';
			if ( 0 === strpos( $value, $content ) ) {
				$value = substr( $value, strlen( $content ) );
			}
		} elseif ( 0 === strpos( $value, 'wp-content/' ) && ABSPATH . 'wp-content' === WP_CONTENT_DIR ) {
			$value = substr( $value, strlen( 'wp-content/' ) );
		}
		return $value;
	}

	/** Whether a file in a directory is one that BackupPlus (or UpdraftPlus, for imported settings) writes, or absent. */
	private static function own_file( $directory, $file ) {
		$path = $directory . '/' . $file;
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			return true;
		}
		if ( is_link( $path ) || ! is_file( $path ) || filesize( $path ) > 1024 ) {
			return false;
		}
		$contents = (string) file_get_contents( $path );
		if ( 'index.html' === $file ) {
			return (bool) preg_match( '/\A\s*<html><body>(?:<a href="[^"<>]*">)?WordPress backups by (?:BackupPlus|UpdraftPlus)(?:<\/a>)?<\/body><\/html>\s*\z/D', $contents );
		}
		$denies = 0;
		foreach ( preg_split( '/\r\n|\r|\n/', $contents ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			if ( preg_match( '/\A(?:deny from all|require all denied)\z/iD', $line ) ) {
				++$denies;
			} elseif ( ! preg_match( '/\A(?:order (?:deny,allow|allow,deny)|<IfModule !?mod_authz_core\.c>|<\/IfModule>)\z/iD', $line ) ) {
				return false;
			}
		}
		return $denies > 0;
	}

	/**
	 * An absolute path with links resolved. A missing tail is appended to its nearest existing ancestor, so a folder that does
	 * not exist yet is judged by where it would be created.
	 *
	 * @param string $path An absolute path.
	 * @return string|false The canonical path without a trailing slash ("/" for the root), or false.
	 */
	private static function canonical( $path ) {
		if ( ! is_string( $path ) || '' === $path || false !== strpos( $path, "\0" ) || ! self::is_absolute( $path ) ) {
			return false;
		}
		$tail  = array();
		$probe = self::untrailingslash( $path );
		while ( ! file_exists( $probe ) ) {
			$base = basename( $probe );
			// A dangling link, or a "." or ".." below a missing folder, has no answer that is safe to assume.
			if ( is_link( $probe ) || '.' === $base || '..' === $base ) {
				return false;
			}
			array_unshift( $tail, $base );
			$parent = dirname( $probe );
			if ( $parent === $probe ) {
				return false;
			}
			$probe = $parent;
		}
		$real = realpath( $probe );
		if ( false === $real ) {
			return false;
		}
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$real = str_replace( '\\', '/', $real );
		}
		$real = rtrim( $real, '/' );
		if ( $tail ) {
			$real .= '/' . implode( '/', $tail );
		}
		return '' === $real ? '/' : $real;
	}

	/** Whether $child is strictly below $parent; both canonical. */
	private static function inside( $parent, $child ) {
		return '/' === $parent ? '/' !== $child : 0 === strpos( $child, $parent . '/' );
	}

	private static function is_absolute( $path ) {
		return '' !== $path && ( '/' === $path[0] || '\\' === $path[0] || preg_match( '/\A[a-zA-Z]:/', $path ) );
	}

	private static function untrailingslash( $path ) {
		$trimmed = rtrim( $path, '/\\' );
		return '' === $trimmed && '' !== $path ? '/' : $trimmed;
	}

	private static function reject( $current, $message ) {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::OPTION, 'invalid_backup_directory', $message, 'error' );
		} elseif ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			// wp option update has no settings errors to show.
			WP_CLI::warning( $message );
		}
		return $current;
	}
}
