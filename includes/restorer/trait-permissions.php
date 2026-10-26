<?php

/** File mode handling and permission failure reporting. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Restorer_Permissions {

	/**
	 * Returns an octal string (but not an octal number)
	 *
	 * @param  String                $file The file to get the permissions for
	 * @param  WP_Filesystem|Boolean $wpfs WP_Filesystem object, at least support the getchmod() method
	 *
	 * @return String
	 */
	private function get_current_chmod( $file, $wpfs = false ) {
		if ( false == $wpfs ) {
			global $wp_filesystem;
			$wpfs = $wp_filesystem;
		}
		// getchmod() is broken at least as recently as WP3.8 - see: https://core.trac.wordpress.org/ticket/26598
		return ( is_a( $wpfs, 'WP_Filesystem_Direct' ) ) ? substr( sprintf( '%06d', decoct( @fileperms( $file ) ) ), 3 ) : $wpfs->getchmod( $file );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
	}

	/**
	 * Returns a string in octal format
	 * $new_chmod should be an octal, i.e. what you'd pass to chmod()
	 *
	 * @param  string $old_chmod specify old chmod
	 * @param  string $new_chmod specify new chmod
	 * @return string
	 */
	private function calculate_additive_chmod_oct( $old_chmod, $new_chmod ) {
		// chmod() expects octal form, which means a preceding zero - see http://php.net/chmod
		$old_chmod = sprintf( '%04d', $old_chmod );
		$new_chmod = sprintf( '%04d', decoct( $new_chmod ) );

		for ( $i = 1; $i <= 3; $i++ ) {
			$oldbit = substr( $old_chmod, $i, 1 );
			$newbit = substr( $new_chmod, $i, 1 );
			for ( $j = 0; $j <= 2; $j++ ) {
				if ( ( $oldbit & ( 1 << $j ) ) && ! ( $newbit & ( 1 << $j ) ) ) {
					$newbit    = (string) ( $newbit | 1 << $j );
					$new_chmod = sprintf( '%04d', substr( $new_chmod, 0, $i ) . $newbit . substr( $new_chmod, $i + 1 ) );
				}
			}
		}

		return $new_chmod;
	}

	/**
	 * "If needed" means, "If the permissions are not already more permissive than this". i.e. This will not tighten permissions from what the user had before (we trust them)
	 * $chmod should be an octal - i.e. the same as you'd pass to chmod()
	 *
	 * @param  String  $dir       a WP_Filesystem path
	 * @param  String  $chmod     specific chmod
	 * @param  Boolean $recursive indicate if recursive chmod is needed
	 * @param  Boolean $wpfs      indicate whether to use wpfs access methods
	 * @param  Boolean $suppress  suppress PHP error/warning output
	 *
	 * @return Boolean - whether the operation was successfully carried out
	 */
	private function chmod_if_needed( $dir, $chmod, $recursive = false, $wpfs = false, $suppress = true ) {

		// Do nothing on Windows
		if ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
			return true;
		}

		if ( false == $wpfs ) {
			global $wp_filesystem;
			$wpfs = $wp_filesystem;
		}

		$old_chmod = $this->get_current_chmod( $dir, $wpfs );

		// Sanity check
		if ( strlen( $old_chmod ) < 3 ) {
			return false;
		}

		$new_chmod = $this->calculate_additive_chmod_oct( $old_chmod, $chmod );

		// Don't fix what isn't broken
		if ( ! $recursive && $new_chmod == $old_chmod ) {
			return true;
		}

		$new_chmod = octdec( $new_chmod );

		if ( $suppress ) {
			return @$wpfs->chmod( $dir, $new_chmod, $recursive );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.
		} else {
			return $wpfs->chmod( $dir, $new_chmod, $recursive );
		}
	}

	/**
	 * Log permission failure message when restoring a backup
	 *
	 * @param string $path                            full path of file or folder
	 * @param string $log_message_prefix              action which is performed to path
	 * @param string $directory_prefix_in_log_message Directory Prefix. It should be either "Parent" or "Destination"
	 */
	private function restore_log_permission_failure_message( $path, $log_message_prefix, $directory_prefix_in_log_message = 'Parent' ) {
		global $backupplus;
		$log_message = $backupplus->log_permission_failure_message( $path, $log_message_prefix, $directory_prefix_in_log_message );
		if ( $log_message ) {
			$backupplus->log( $log_message, 'warning-restore' );
		}
	}
}
