<?php

/** The database handle the restore works through. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Restorer_Database_Connection {

	/**
	 * This function will check if we are using wpdb, if we are not then it will setup our wpdb-like objects
	 *
	 * @param boolean $reconnect_wpdb - if we should include and create a new instance of wpdb
	 *
	 * @return void
	 */
	private function setup_database_objects( $reconnect_wpdb = false ) {
		global $wpdb;

		if ( $reconnect_wpdb ) {
			$wpdb->db_connect( true );
		}

		// Line up a wpdb-like object
		if ( ! $this->use_wpdb() ) {
			// We have our own extension which drops lots of the overhead on the query
			$wpdb_obj = new BackupPlus_WPDB( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			// Was that successful?
			if ( ! $wpdb_obj->is_mysql || ! $wpdb_obj->ready ) {
				$this->use_wpdb = true;
			} else {
				$this->wpdb_obj   = $wpdb_obj;
				$this->mysql_dbh  = $wpdb_obj->backupplus_get_database_handle();
				$this->use_mysqli = $wpdb_obj->backupplus_use_mysqli();
			}
		}
	}

	/**
	 * This function will try to restore the database connection, if it succeeds then it returns true otherwise false
	 *
	 * @return boolean - true if the connection is restored, otherwise false
	 */
	private function restore_database_connection() {
		global $backupplus, $wpdb;

		$wpdb_connected = $backupplus->check_db_connection( $wpdb, false, false, true );

		if ( false === $wpdb_connected || -1 === $wpdb_connected ) {
			sleep( 10 );
			$this->setup_database_objects( true );
			return false;
		}

		return true;
	}

	/**
	 * Get the wpdb-like object that we are using, if we are using one
	 *
	 * @return BackupPlus_WPDB|Boolean
	 */
	/**
	 * The error of the last statement sql_exec() ran
	 *
	 * @return String
	 */
	public function last_sql_error() {
		return (string) $this->last_error;
	}

	public function get_db_object() {
		return $this->wpdb_obj;
	}

	/**
	 * Whether or not we must use the global $wpdb object for database queries.
	 * That is to say: we *can* always use it. But we prefer to avoid the overhead since we are potentially doing very many queries.
	 *
	 * This is the getter. We have no use-case for a setter outside of this class, so we just set it directly.
	 *
	 * @return Boolean
	 */
	public function use_wpdb() {
		if ( ! is_bool( $this->use_wpdb ) ) {
			global $wpdb;
			if ( defined( 'BACKUPPLUS_USE_WPDB' ) ) {
				$this->use_wpdb = (bool) BACKUPPLUS_USE_WPDB;
			} else {
				$this->use_wpdb = ( ! function_exists( 'mysqli_query' ) || ! $wpdb->is_mysql || ! $wpdb->ready ) ? true : false;
			}
		}
		return $this->use_wpdb;
	}

	/**
	 * The restore connection: WordPress's own when use_wpdb(), otherwise the direct handle. Built per use, because
	 * restore_database_connection() replaces the handle after the server drops it.
	 *
	 * @return BackupPlus_SQL
	 */
	private function sql() {
		return $this->use_wpdb() ? BackupPlus_SQL::wpdb() : new BackupPlus_SQL( $this->mysql_dbh );
	}

	/**
	 * Get the skin
	 *
	 * @return WP_Upgrader_Skin
	 */
	public function ud_get_skin() {
		return $this->skin;
	}
}
