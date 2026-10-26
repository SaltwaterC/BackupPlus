<?php

/** Filesystem access that moves by rename only, never by copy and delete. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

// The purpose of this is that, in a certain case, we want to forbid the "move" operation from doing a copy/delete if a direct move fails... because we have our own method for retrying (and don't want to risk copying a tonne of data if we can avoid it)
if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
	if ( ! class_exists( 'WP_Filesystem_Base' ) ) {
		include_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
	}
	include_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
}
class BackupPlus_WP_Filesystem_Direct extends WP_Filesystem_Direct {

	/**
	 * Moves a file
	 *
	 * @param String  $source      Path to the source file.
	 * @param String  $destination Path to the destination file.
	 * @param Boolean $overwrite   Whether to overwrite the destination file if it exists.
	 *
	 * @return Boolean Success status
	 */
	public function move( $source, $destination, $overwrite = false ) {
		if ( ! $overwrite && $this->exists( $destination ) ) {
			return false;
		}

		// try using rename first. if that fails (for example, source is read only) try copy
		if ( @rename( $source, $destination ) ) { // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- errors are handled via a different strategy
			return true;
		}

		return false;
	}
}
