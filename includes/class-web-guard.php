<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}

/**
 * The .htaccess file that denies web access to a private BackupPlus folder (BPS-20).
 *
 * Apache 2.4 denies with mod_authz_core's Require. Apache 2.2 has no mod_authz_core and uses Order and Deny, which Apache 2.4
 * understands only when mod_access_compat is loaded: a bare "deny from all" is a configuration error there, so every request
 * gets a 500 response. That still denies access, but hides the real reason. Each branch below is read only by the server
 * version it is written for.
 */
class BackupPlus_Web_Guard {

	const HTACCESS = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n";

	/**
	 * What earlier versions wrote, compared after line endings are normalized and surrounding white space is trimmed: the backup
	 * folder (as UpdraftPlus also did), the PITR folder, and the PITR client folder and credential key folder.
	 */
	const PREVIOUS_HTACCESS = array(
		'deny from all',
		"Require all denied\nDeny from all",
		"<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>",
	);

	/**
	 * Whether a file's contents are what an earlier version of BackupPlus wrote.
	 *
	 * @param string $contents The file contents.
	 * @return bool
	 */
	public static function is_previous( $contents ) {
		$contents = trim( str_replace( array( "\r\n", "\r" ), "\n", (string) $contents ) );
		return in_array( $contents, self::PREVIOUS_HTACCESS, true );
	}

	/**
	 * Replace an .htaccess file that an earlier version wrote with the current one, keeping its permissions. A missing file, a
	 * link, or any other contents (a site's own rules) are left alone. The new file is written beside it and renamed over it,
	 * so the web server never reads a partial file.
	 *
	 * @param string $path The .htaccess file.
	 * @return bool Whether the file has the current contents afterwards.
	 */
	public static function upgrade_htaccess( $path ) {
		if ( is_link( $path ) || ! is_file( $path ) || filesize( $path ) > 1024 ) {
			return false;
		}
		$contents = @file_get_contents( $path );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- An unreadable file is left alone.
		if ( self::HTACCESS === $contents ) {
			return true;
		}
		if ( false === $contents || ! self::is_previous( $contents ) ) {
			return false;
		}
		$permissions = @fileperms( $path );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Checked below.
		$temporary   = dirname( $path ) . '/.htaccess-' . bin2hex( random_bytes( 8 ) ) . '.tmp';
		$handle      = @fopen( $temporary, 'xb' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A read-only folder keeps its file.
		if ( false === $handle ) {
			return false;
		}
		$written = fwrite( $handle, self::HTACCESS );// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Direct file system access in a private folder.
		fclose( $handle );// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Direct file system access in a private folder.
		if ( false !== $permissions ) {
			@chmod( $temporary, $permissions & 0777 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- The previous permissions are kept where possible.
		}
		if ( strlen( self::HTACCESS ) !== $written || ! @rename( $temporary, $path ) ) {// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- The previous file is kept on failure.
			@unlink( $temporary );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Cleanup.
			return false;
		}
		return true;
	}
}
