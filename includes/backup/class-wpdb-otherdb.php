<?php

/** A wpdb that logs connection failures to the job log instead of dying. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

class BackupPlus_WPDB_OtherDB extends wpdb {
	/**
	 * This adjusted bail() does two things: 1) Never dies and 2) logs in the UD log
	 *
	 * @param  String $message    Error text
	 * @param  String $error_code Error code
	 *
	 * @return Boolean
	 */
	public function bail( $message, $error_code = '500' ) {
		global $backupplus;
		if ( 'db_connect_fail' == $error_code ) {
			$message = 'Connection failed: check your access details, that the database server is up, and that the network connection is not firewalled.';
		}
		$backupplus->log( "WPDB_OtherDB error: $message ($error_code)" );
		// Now do the things that would have been done anyway
		if ( class_exists( 'WP_Error' ) ) {
			$this->error = new WP_Error( $error_code, $message );
		} else {
			$this->error = $message;
		}
		return false;
	}
}
