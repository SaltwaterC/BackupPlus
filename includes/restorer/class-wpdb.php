<?php

/** A wpdb that exposes its database handle. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

/**
 * Get a protected property
 */
class BackupPlus_WPDB extends wpdb {

	/**
	 * Get the database handle
	 *
	 * @return Mixed - the database handle
	 */
	public function backupplus_get_database_handle() {
		return $this->dbh;
	}

	/**
	 * Return whether the object is using mysqli or not.
	 *
	 * @return Boolean
	 */
	public function backupplus_use_mysqli() {
		return $this->dbh instanceof mysqli;
	}
}
