<?php

/** System variables and SQL modes of a database connection. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Database_Utility_Session {

	/**
	 * Set MySQL server system variable
	 *
	 * Only sql_mode is supported: variable names cannot be bound as parameters, so each one needs its own template.
	 *
	 * @param String $variable  The name of the system variable
	 * @param String $value     The variable value
	 * @param Object $db_handle The mysqli link
	 * @return Boolean Returns true on success, false otherwise
	 */
	public static function set_system_variable( $variable, $value, $db_handle ) {

		if ( 'sql_mode' !== $variable || ! is_a( $db_handle, 'mysqli' ) ) {
			return false;
		}

		return false !== ( new BackupPlus_SQL( $db_handle ) )->execute( 'SET SESSION sql_mode = ?', array(), array( $value ) );
	}

	/**
	 * Get MySQL server system variable.
	 *
	 * Only sql_mode is supported: variable names cannot be bound as parameters, so each one needs its own template.
	 *
	 * @param String $variable  The name of the system variable
	 * @param Object $db_handle The mysqli link
	 * @return String|Boolean|Null Returns value of the system variable, false on query failure or null if there is no result for the corresponding variable
	 */
	public static function get_system_variable( $variable, $db_handle ) {

		if ( 'sql_mode' !== $variable || ! is_a( $db_handle, 'mysqli' ) ) {
			return false;
		}

		return ( new BackupPlus_SQL( $db_handle ) )->value( 'SELECT @@SESSION.sql_mode' );
	}

	/**
	 * Get a list of current SQL modes set, in upper case
	 *
	 * @param Resource|Object|NULL $db_handle - Optional. If specified, it should either the valid database link identifier(resource) given by mysql(i) or null to instead use the global WPDB object, or a WPDB-compatible object.
	 *
	 * @uses $GLOBALS['wpdb']
	 *
	 * @return Array|Boolean
	 */
	public static function get_sql_modes( $db_handle = null ) {

		if ( is_null( $db_handle ) ) {
			$modes = $GLOBALS['wpdb']->get_var( 'SELECT @@SESSION.sql_mode' );
		} elseif ( is_a( $db_handle, 'WPDB' ) ) {
			$modes = $db_handle->get_var( 'SELECT @@SESSION.sql_mode' );
		} else {
			$modes = call_user_func_array( array( __CLASS__, 'get_system_variable' ), array( 'sql_mode', $db_handle ) );
		}

		if ( is_scalar( $modes ) && ! is_bool( $modes ) ) {
			return array_map( 'strtoupper', explode( ',', $modes ) );
		}

		return false;
	}

	/**
	 *
	 * This function is adapted from the set_sql_mode() method in WordPress wpdb class but with a few modifications applied, this can be used to switch between different sets of SQL modes.
	 *
	 * @see https://developer.wordpress.org/reference/classes/wpdb/set_sql_mode/
	 * @see https://dev.mysql.com/doc/refman/5.6/en/sql-mode.html
	 * @see https://dev.mysql.com/doc/refman/5.7/en/sql-mode.html
	 * @see https://dev.mysql.com/doc/refman/8.0/en/sql-mode.html
	 * @see https://dev.mysql.com/doc/refman/5.6/en/server-system-variables.html#sysvar_sql_mode
	 * @see https://dev.mysql.com/doc/refman/5.7/en/server-system-variables.html#sysvar_sql_mode
	 * @see https://dev.mysql.com/doc/refman/8.0/en/server-system-variables.html#sysvar_sql_mode
	 * @see https://mariadb.com/kb/en/library/sql-mode/#strict-mode
	 * @see https://mariadb.com/kb/en/library/sql-mode/#setting-sql_mode
	 *
	 * @param Array                $modes        - Optional. A list of SQL modes to set.
	 * @param Array                $remove_modes - modes to remove if they are currently active
	 * @param Resource|Object|NULL $db_handle    - Optional. If specified, it should either the valid database link identifier(resource) given by mysql(i) or null to instead use the global WPDB object, or a WPDB-compatible object.
	 *
	 * @return Boolean|Null - true if no unwanted modes remain, false if some do, null if indeterminate.
	 */
	public static function set_sql_mode( $modes = array(), $remove_modes = array(), $db_handle = null ) {

		global $backupplus, $wpdb;

		$wpdb_handle_if_used = ( null !== $db_handle && is_a( $db_handle, 'WPDB' ) ) ? $db_handle : $wpdb;

		// If any of these are set, they will be unset
		$unwanted_modes = array_merge(
			array_map( 'strtoupper', $remove_modes ),
			array(
				'NO_ZERO_DATE',
				'ONLY_FULL_GROUP_BY',
				'TRADITIONAL',
				'NO_BACKSLASH_ESCAPES',
				// according to mariadb and mysql docs, strict mode can be one of these or both
				'STRICT_TRANS_TABLES',
				'STRICT_ALL_TABLES',
			)
		);

		$initial_modes = self::get_sql_modes();

		if ( ! is_array( $initial_modes ) ) {
			$backupplus->log( "Couldn't get the sql_mode value (" . serialize( $initial_modes ) . '); will not attempt any adjustment' );
			return null;
		}

		$modes = array_unique( array_merge( $initial_modes, array_map( 'strtoupper', $modes ) ) );

		foreach ( $modes as $i => $mode ) {
			if ( in_array( $mode, $unwanted_modes ) ) {
				unset( $modes[ $i ] );
			}
		}

		$modes_str = implode( ',', $modes );

		// We don't examine the return code, because we re-check status afterwards
		if ( is_null( $db_handle ) || is_a( $db_handle, 'WPDB' ) ) {
			( new BackupPlus_SQL( $wpdb_handle_if_used->dbh ) )->execute( 'SET SESSION sql_mode = ?', array(), array( $modes_str ) );
		} else {
			call_user_func_array( array( __CLASS__, 'set_system_variable' ), array( 'sql_mode', $modes_str, $db_handle ) );
		}

		$after_modes = self::get_sql_modes();

		if ( is_array( $after_modes ) ) {
			$remaining_unwanted_modes = array_intersect( $after_modes, $unwanted_modes );
			if ( empty( $remaining_unwanted_modes ) ) {
				$backupplus->log( 'SQL compatibility mode is now: ' . implode( ',', $after_modes ) );
				return true;
			}

			$backupplus->log( 'SQL compatibility mode has unwanted modes remaining: ' . implode( ', ', $unwanted_modes ) . ' members present in: ' . implode( ', ', $after_modes ) );
			return false;
		}

		return null;
	}
}
