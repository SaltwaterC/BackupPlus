<?php

/** A wpdb that logs connection failures to the job log instead of dying. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

class BackupPlus_WPDB_OtherDB_Utility extends wpdb {
	/**
	 * This adjusted bail() does two things: 1) Never dies and 2) logs in the UD log
	 *
	 * @param String $message    a string containing a message
	 * @param String $error_code a string containing an error code
	 * @return Boolean returns false
	 */
	public function bail( $message, $error_code = '500' ) {
		global $backupplus;
		if ( 'db_connect_fail' == $error_code ) {
			$message = 'Connection failed: check your access details, that the database server is up, and that the network connection is not firewalled.';
		}
		$backupplus->log( "WPDB_OtherDB error: $message ($error_code)" );
		// Now do the things that would have been done anyway
		$this->error = class_exists( 'WP_Error' ) ? new WP_Error( $error_code, $message ) : $message;
		return false;
	}
}
