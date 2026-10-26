<?php

/** Stored routine support and listing. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Database_Utility_Routines {

	/**
	 * Check whether the currently running database server supports stored routines
	 *
	 * @return Array|WP_Error an array of booleans indicating whether or not some of syntax variations are supported, or WP_Error object if stored routine isn't supported
	 *
	 * Return format example:
	 *
	 *     [
	 *         "is_create_or_replace_supported" => true, // true on MariaDB, false on MySQL
	 *         "is_if_not_exists_function_supported" => true, // true on MariaDB, false on MySQL
	 *         "is_aggregate_function_supported" => true, // true on MariaDB, false on MySQL
	 *         "is_binary_logging_enabled" => true, // true if --bin-log is specified for both MariaDB and MySQL
	 *         "is_function_creators_trusted" => false // the default value is false (MariaDB/MySQL)
	 *     ]
	 *
	 *     OR a database error message, e.g. "Access denied for user 'root'@'localhost' to database 'WordPress'"
	 */
	public static function is_stored_routine_supported() {

		global $wpdb;

		$sql = new BackupPlus_SQL( $wpdb->dbh );
		$sql->execute( 'DROP FUNCTION IF EXISTS backupplus_test_stored_routine' );
		// sql to check whether stored routines is supported
		$is_stored_routine_supported = false !== $sql->execute( 'CREATE FUNCTION backupplus_test_stored_routine() RETURNS tinyint(1) DETERMINISTIC READS SQL DATA RETURN true' );
		if ( $is_stored_routine_supported ) {
			$is_binary_logging_enabled = 1 == $sql->value( 'SELECT @@GLOBAL.log_bin' );
			// not sure why the log_bin variable cant be retrieved on mysql 5.0, seems like there's a bug on that version, so we use another alternative to check whether or not binary logging is enabled
			$is_binary_logging_enabled   = false === $is_binary_logging_enabled ? $sql->rows( "SHOW GLOBAL VARIABLES LIKE 'log_bin'", array(), array(), ARRAY_A ) : $is_binary_logging_enabled;
			$is_binary_logging_enabled   = is_array( $is_binary_logging_enabled ) && isset( $is_binary_logging_enabled[0]['Value'] ) && '' != $is_binary_logging_enabled[0]['Value'] ? $is_binary_logging_enabled[0]['Value'] : $is_binary_logging_enabled;
			$is_binary_logging_enabled   = is_string( $is_binary_logging_enabled ) && ( 'ON' === strtoupper( $is_binary_logging_enabled ) || '1' === $is_binary_logging_enabled ) ? true : $is_binary_logging_enabled;
			$is_binary_logging_enabled   = is_string( $is_binary_logging_enabled ) && ( 'OFF' === strtoupper( $is_binary_logging_enabled ) || '0' === $is_binary_logging_enabled ) ? false : $is_binary_logging_enabled;
			$is_stored_routine_supported = array(
				// sql to check whether create or replace syntax is supported
				'is_create_or_replace_supported'      => false !== $sql->execute( 'CREATE OR REPLACE FUNCTION backupplus_test_stored_routine() RETURNS tinyint(1) DETERMINISTIC READS SQL DATA RETURN true' ),
				// sql to check whether if not exists syntax is supported (mariadb starting with 10.1.3)
				'is_if_not_exists_function_supported' => false !== $sql->execute( 'CREATE FUNCTION IF NOT EXISTS backupplus_test_stored_routine() RETURNS tinyint(1) DETERMINISTIC READS SQL DATA RETURN true' ),
				// sql to check whether aggregate function is supported (mariadb starting with 10.3.3)
				'is_aggregate_function_supported'     => false !== $sql->execute( 'CREATE OR REPLACE AGGREGATE FUNCTION backupplus_test_stored_routine() RETURNS tinyint(1) DETERMINISTIC READS SQL DATA BEGIN RETURN true; FETCH GROUP NEXT ROW; END;' ),
				'is_binary_logging_enabled'           => $is_binary_logging_enabled,
				'is_function_creators_trusted'        => 1 == $sql->value( 'SELECT @@GLOBAL.log_bin_trust_function_creators' ),
			);
			$sql->execute( 'DROP FUNCTION IF EXISTS backupplus_test_stored_routine' );
		} else {
			/* translators: 1: Last database error, 2: SQL create function statement. */
			$is_stored_routine_supported = new WP_Error( 'routine_creation_error', sprintf( __( 'An error occurred while attempting to check the support of stored routines creation (%1$s %2$s)', 'backupplus' ), $sql->last_error() . ' -', 'CREATE FUNCTION backupplus_test_stored_routine() RETURNS tinyint(1) DETERMINISTIC READS SQL DATA RETURN true' ) );
		}

		return $is_stored_routine_supported;
	}

	/**
	 * Retrieve all the stored routines (functions and procedures) in the currently running database
	 *
	 * @return Array|WP_Error an array of routine statuses, or an empty array if there is no stored routine in the database, or WP_Error object on failure
	 *
	 * Output example:
	 *
	 *     [
	 *         [
	 *              "Db" => "WordPress",
	 *              "Name" => "_NextVal",
	 *              "Type" => "FUNCTION",
	 *              "Definer" => "root@localhost",
	 *              "Modified" => "2019-11-22 15:11:15",
	 *              "Created" => "2019-11-22 14:20:29",
	 *              "Security_type" => "DEFINER",
	 *              "Comment" => "",
	 *              "Function" => "_NextVal",
	 *              "sql_mode" => "",
	 *              "Create Function" => "
	 *                  CREATE DEFINER=`root`@`localhost` FUNCTION `_NextVal`(vname VARCHAR(30)) RETURNS int(11)
	 *                  BEGIN
	 *                      -- Retrieve and update in single statement
	 *                      UPDATE _sequences
	 *                          SET next = next + 1
	 *                          WHERE name = vname;
	 *                      RETURN (SELECT next FROM _sequences LIMIT 1);
	 *                  END"
	 *         ],
	 *         [
	 *              "Db" => "WordPress",
	 *              "Name" => "CreateSequence",
	 *              "Type" => "Procedure",
	 *              "Definer" => "root@localhost",
	 *              "Modified" => "2019-11-22 15:11:15",
	 *              "Created" => "2019-11-22 14:20:29",
	 *              "Security_type" => "DEFINER",
	 *              "Comment" => "",
	 *              "Procedure" => "CreateSequence",
	 *              "sql_mode" => "",
	 *              "Create Procedure" => "
	 *                   CREATE DEFINER=`root`@`localhost` PROCEDURE `CreateSequence`(name VARCHAR(30), start INT, inc INT)
	 *                   BEGIN
	 *                       -- Create a table to store sequences
	 *                       CREATE TABLE  _sequences (
	 *                           name VARCHAR(70) NOT NULL UNIQUE,
	 *                           next INT NOT NULL,
	 *                           inc INT NOT NULL,
	 *                       );
	 *                       -- Add the new sequence
	 *                       INSERT INTO _sequences VALUES (name, start, inc);
	 *                   END"
	 *         ]
	 *     ]
	 */
	public static function get_stored_routines( $connection = null, $database = null ) {

		$wpdb     = null === $connection ? $GLOBALS['wpdb'] : $connection;
		$database = null === $database ? DB_NAME : $database;

		$sql = new BackupPlus_SQL( $wpdb->dbh );
		try {
			/* translators: 1: Last database error, 2: Additional error details. */
			$err_msg         = __( 'An error occurred while attempting to retrieve routine status (%1$s %2$s)', 'backupplus' );
			$function_status = $sql->rows( 'SHOW FUNCTION STATUS', array(), array(), ARRAY_A );
			if ( false === $function_status ) {
				throw new Exception( sprintf( $err_msg, $sql->last_error() . ' -', 'SHOW FUNCTION STATUS' ), 0 );
			}
			$procedure_status = $sql->rows( 'SHOW PROCEDURE STATUS', array(), array(), ARRAY_A );
			if ( false === $procedure_status ) {
				throw new Exception( sprintf( $err_msg, $sql->last_error() . ' -', 'SHOW PROCEDURE STATUS' ), 0 );
			}
			// The Db column compares case-insensitively, as SHOW ... STATUS WHERE Db = '...' did.
			$stored_routines = array_values(
				array_filter(
					array_merge( $function_status, $procedure_status ),
					function ( $routine ) use ( $database ) {
						return isset( $routine['Db'] ) && 0 === strcasecmp( $routine['Db'], $database );
					}
				)
			);
			foreach ( $stored_routines as $key => $routine ) {
				if ( empty( $routine['Name'] ) || empty( $routine['Type'] ) ) {
					continue;
				}
				if ( 'FUNCTION' === strtoupper( $routine['Type'] ) ) {
					$routine = $sql->rows( 'SHOW CREATE FUNCTION %i', array( $routine['Name'] ), array(), ARRAY_A );
				} elseif ( 'PROCEDURE' === strtoupper( $routine['Type'] ) ) {
					$routine = $sql->rows( 'SHOW CREATE PROCEDURE %i', array( $routine['Name'] ), array(), ARRAY_A );
				} else {
					continue;
				}
				if ( false === $routine ) {
					/* translators: 1: Last database error, 2: Name of the routine. */
					throw new Exception( sprintf( __( 'An error occurred while attempting to retrieve the routine SQL/DDL statement (%1$s %2$s)', 'backupplus' ), $sql->last_error() . ' -', $stored_routines[ $key ]['Name'] ), 1 );
				}
				$stored_routines[ $key ] = array_merge( $stored_routines[ $key ], $routine ? $routine[0] : array() );
			}
		} catch ( Exception $ex ) {
			$stored_routines = new WP_Error( 1 === $ex->getCode() ? 'routine_sql_error' : 'routine_status_error', $ex->getMessage() );
		}

		return $stored_routines;
	}
}
