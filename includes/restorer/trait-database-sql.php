<?php

/** Executing restore statements and handling the session settings around them. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Restorer_Database_Sql {

	/**
	 * Gets the table prefix to use, using the filter backupplus_restore_set_import_table_prefix
	 *
	 * @param String $import_table_prefix - table prefix to act upon
	 *
	 * @return String|WP_Error|Boolean - the modified table prefix, or an error or indication of an error
	 */
	private function pre_sql_actions( $import_table_prefix ) {

		global $backupplus;

		$import_table_prefix = apply_filters( 'backupplus_restore_set_table_prefix', $import_table_prefix, $this->ud_backup_is_multisite );

		if ( ! is_string( $import_table_prefix ) ) {
			$this->maintenance_mode( false );
			if ( false === $import_table_prefix ) {
				$backupplus->log( __( 'Please supply the requested information, and then continue.', 'backupplus' ), 'notice-restore' );
				return false;
			} elseif ( is_wp_error( $import_table_prefix ) ) {
				return $import_table_prefix;
			} else {
				return new WP_Error( 'invalid_table_prefix', __( 'Error:', 'backupplus' ) . ' ' . serialize( $import_table_prefix ) );
			}
		}

		$backupplus->log_e( 'New table prefix: %s', $import_table_prefix );

		return $import_table_prefix;
	}

	/**
	 * Assign a value to log_bin_trust_function_creators system variable and return its previous value
	 *
	 * @see https://mariadb.com/kb/en/library/binary-logging-of-stored-routines/
	 * @see https://dev.mysql.com/doc/refman/8.0/en/stored-programs-logging.html
	 *
	 * @param String $value It can only be set to ON or OFF
	 * @return String|WP_Error the variable value before it got assigned a new value, or WP_Error object on failure
	 */
	private function set_log_bin_trust_function_creators( $value ) {

		global $wpdb;
		static $saved_value   = null;
		static $initial_value = null;

		$old_val = $wpdb->suppress_errors();
		try {
			if ( is_null( $initial_value ) || is_wp_error( $initial_value ) ) {
				$creators_val = $wpdb->get_var( 'SELECT @@GLOBAL.log_bin_trust_function_creators' );
				/* translators: %s: Last error and last query information from $wpdb */
				if ( is_null( $creators_val ) ) {
					throw new Exception( sprintf( __( 'An error occurred while attempting to retrieve the MySQL global log_bin_trust_function_creators variable %s', 'backupplus' ), '(' . $wpdb->last_error . ' - ' . $wpdb->last_query . ')' ), 0 );
				}
				$initial_value = '1' === $creators_val || 'on' === strtolower( $creators_val ) ? 'ON' : 'OFF';
			}
			if ( ( is_null( $saved_value ) || ( $saved_value != $value ) ) ) {
				$sql = BackupPlus_SQL::wpdb();
				$res = $sql->execute( 'SET GLOBAL log_bin_trust_function_creators = ?', array(), array( $value ) );
				if ( false === $res ) {
					$saved_value = null;
					/* translators: %s: Last error information */
					throw new Exception( sprintf( __( 'An error occurred while attempting to set a new value to the MySQL global log_bin_trust_function_creators variable %s', 'backupplus' ), '(' . $sql->last_error() . ')' ), 0 );
				}
				if ( ! is_null( $saved_value ) ) {
					$initial_value = $saved_value;
				}
				$saved_value = $value;
			}
		} catch ( Exception $ex ) {
			$initial_value = new WP_Error( 'log_bin_trust_function_creators', $ex->getMessage() );
		}
		$wpdb->suppress_errors( $old_val );

		return $initial_value;
	}

	/**
	 * Save configuration bundle, ready to restore it once the options table has been restored
	 */
	private function save_configuration_bundle() {
		$this->configuration_bundle = array();
		// Some items must always be saved + restored; others only when restoring to a different site.
		// Remember, if modifying this, that a restoration can include restoring a destroyed site from a backup onto a fresh WP install on the same URL. So, it is not necessarily desirable to retain the current settings and drop the ones in the backup.
		// Backup history describes artifacts outside the database and must survive same-site restores too.
		$keys_to_save = array( 'backupplus_restore_in_progress', 'backupplus_backup_history' );

		if ( $this->old_siteurl != $this->our_siteurl || ( defined( 'BACKUPPLUS_RESTORE_ALL_SETTINGS' ) && BACKUPPLUS_RESTORE_ALL_SETTINGS ) ) {
			global $backupplus;
			$keys_to_save = array_merge( $keys_to_save, $backupplus->get_settings_keys() );
		}

		foreach ( $keys_to_save as $key ) {
			$this->configuration_bundle[ $key ] = BackupPlus_Options::get_backupplus_option( $key, '' );
		}
	}

	/**
	 * The table here is just for logging/info. The actual restoration itself is done via the standard options class.
	 *
	 * @param  string $table specific table
	 */
	private function restore_configuration_bundle( $table ) {

		if ( ! is_array( $this->configuration_bundle ) ) {
			return;
		}
		global $backupplus;
		$backupplus->log( "Restoring prior UD configuration (table: $table; keys: " . count( $this->configuration_bundle ) . ')' );
		foreach ( $this->configuration_bundle as $key => $value ) {
			BackupPlus_Options::delete_backupplus_option( $key );
			BackupPlus_Options::update_backupplus_option( $key, $value );
		}
		// Reset the LiteSpeed server warning message when replacing the site URL.
		if ( ! empty( $this->restore_options['backupplus_restorer_replacesiteurl'] ) ) {
			$server_software = BackupPlus_Manipulation_Functions::fetch_superglobal( 'server', 'SERVER_SOFTWARE' );
			if ( isset( $server_software ) && false !== strpos( $server_software, 'LiteSpeed' ) ) {
				if ( ! is_file( ABSPATH . '.htaccess' ) || ! preg_match( '/noabort/i', file_get_contents( ABSPATH . '.htaccess' ) ) ) {
					BackupPlus_Options::delete_backupplus_option( 'backupplus_dismiss_admin_warning_litespeed' );
				}
			}
		}
	}

	/**
	 * Log the information that a particular SQL commandment is too long
	 *
	 * @param String $sql_line - the SQL
	 */
	private function log_oversized_packet( $sql_line ) {
		global $backupplus;
		$logit = substr( $sql_line, 0, 100 );
		$backupplus->log( sprintf( 'An SQL line that is larger than the maximum packet size and cannot be split was found: %s', '(' . strlen( $sql_line ) . ', ' . $logit . ' ...)' ) );

		$backupplus->log(
			__( 'Warning:', 'backupplus' ) . ' ' .
			sprintf(
				/* translators: %s: SQL line */
				__( 'An SQL line that is larger than the maximum packet size and cannot be split was found; this line will not be processed, but will be dropped: %s', 'backupplus' ),
				'(' . strlen( $sql_line ) . ', ' . $this->max_allowed_packet . ', ' . $logit . ' ...)'
			),
			'notice-restore'
		);
	}

	private function restore_this_table( $table_name ) {

		global $backupplus;
		$unprefixed_table_name = substr( $table_name, strlen( $this->old_table_prefix ) );

		// First, check whether it's a multisite site which we're not restoring. This is stored in restore_this_site (once we know the site).
		if ( ! empty( $this->ud_multisite_selective_restore ) ) {
			if ( preg_match( '/^(\d+)_.*$/', $unprefixed_table_name, $matches ) ) {
				$site_id = $matches[1];

				if ( ! isset( $this->restore_this_site[ $site_id ] ) ) {
					$this->restore_this_site[ $site_id ] = apply_filters(
						'backupplus_restore_this_site',
						true,
						$site_id,
						$unprefixed_table_name,
						$this->restore_options
					);
				}

				if ( false === $this->restore_this_site[ $site_id ] ) {
					// The first time it's looked into, it gets logged
					$backupplus->log_e( 'Skipping site %s: this table (%s) and others from the site will not be restored', $site_id, $table_name );
					$this->restore_this_site[ $site_id ] = 0;
				}

				if ( ! $this->restore_this_site[ $site_id ] ) {
					return false;
				}
			}
		}

		// Secondly, if we're still intending to proceed, check the table specifically
		if ( ! isset( $this->restore_this_table[ $table_name ] ) ) {

			$this->restore_this_table[ $table_name ] = apply_filters(
				'backupplus_restore_this_table',
				true,
				$unprefixed_table_name,
				$this->restore_options
			);

			if ( false === $this->restore_this_table[ $table_name ] ) {
				// The first time it's looked into, it gets logged
				$backupplus->log_e( 'Skipping table %s: this table will not be restored', $table_name );
				$this->restore_this_table[ $table_name ] = 0;
			}
		}

		return $this->restore_this_table[ $table_name ];
	}

	/**
	 * UPDATE statements use sql_type=5 when passed to this method.
	 * $import_table_prefix is only use in one place in this function (long INSERTTs), and otherwise need/should not be supplied
	 *
	 * SQL Types:
	 * 1 DROP
	 * 2 CREATE
	 * 3 INSERT
	 * 4 LOCK
	 * 5 UPDATE
	 * 6 WPB2D CREATE/DROP
	 * 7 WPB2D USE
	 * 8 SET NAMES
	 * 9 TRIGGER
	 * 10 DELIMITER
	 * 11 CREATE VIEW
	 * 12 ROUTINE
	 * 13 DROP FUNCTION|PROCEDURE
	 * 14 ALTER
	 * 15 UNLOCK
	 * 16 DROP VIEW
	 * 17 SET GLOBAL.GTID_PURGED
	 * 18 SET SQL_MODE|@OLD_SQL_MODE
	 *
	 * @param  String  $sql_line            sql line to execute
	 * @param  Integer $sql_type            sql type
	 * @param  String  $import_table_prefix import type prefix
	 * @param  Boolean $check_skipping      if true, then check whether the table is on the list of tables to skip
	 *
	 * @return Boolean|WP_Error|Void
	 */
	public function sql_exec( $sql_line, $sql_type, $import_table_prefix = '', $check_skipping = true ) {
		global $wpdb, $backupplus;

		if ( $check_skipping && ! empty( $this->table_name ) && ! $this->restore_this_table( $this->table_name ) ) {
			return;
		}

		$ignore_errors = false;
		// Type 2 = CREATE TABLE
		if ( 2 == $sql_type && ! empty( $this->db_permissons_forbidden['create'] ) ) {
			$backupplus->log_e( 'Cannot create new tables, so skipping this command (%s)', htmlspecialchars( $sql_line ) );
			$req = true;
		} else {

			if ( 2 == $sql_type && empty( $this->db_permissons_forbidden['drop'] ) ) {
				// We choose, for now, to be very conservative - we only do the apparently-missing drop if we have never seen any drop - i.e. assume that in SQL dumps with missing DROPs, that it's because there are no DROPs at all
				if ( ! in_array( $this->new_table_name, $this->tables_been_dropped ) ) {
					$backupplus->log_e( 'Table to be implicitly dropped: %s', $this->new_table_name );
					$this->sql_exec( BackupPlus_SQL::render( 'DROP TABLE IF EXISTS %i', array( $this->new_table_name ) ), 1, '', false );
					$this->tables_been_dropped[] = $this->new_table_name;
				}
			}

			// Type 1 = DROP TABLE
			if ( 1 == $sql_type ) {
				if ( ! empty( $this->db_permissons_forbidden['drop'] ) ) {
					$sql_line = BackupPlus_SQL::render( 'DELETE FROM %i', array( $this->new_table_name ) );
					$backupplus->log_e( 'Cannot drop tables, so deleting instead (%s)', $sql_line );
					$ignore_errors = true;
				}
			}

			if ( 3 == $sql_type && $sql_line && strlen( $sql_line ) > $this->max_allowed_packet ) {
				$this->log_oversized_packet( $sql_line );
				// If this is the very first SQL line of the options table, we need to bail; it's essential
				++$this->errors;
				if ( 0 == $this->insert_statements_run && $this->new_table_name && $this->new_table_name == $import_table_prefix . 'options' ) {
					$backupplus->log( 'Leaving maintenance mode' );
					$this->maintenance_mode( false );
					/* translators: %s: String 'INSERT (options)' */
					return new WP_Error( 'initial_db_error', sprintf( __( 'An error occurred on the first %s command - aborting run', 'backupplus' ), 'INSERT (options)' ) );
				}
				return false;
			}

			static $first_trigger = true;
			if ( 9 == $sql_type && $first_trigger ) {
				$first_trigger = false;
				$backupplus->log( 'Restoring TRIGGERs...' );
			}

			static $first_stored_routine = true;
			if ( 12 == $sql_type && $first_stored_routine ) {
				$first_stored_routine = false;
				$backupplus->log( 'Restoring STORED ROUTINES...' );
			}

			$sql = $this->sql();
			// Fragments are BackupPlus statements with bound values; text is a statement read from the dump
			$req = false !== ( $sql_line instanceof BackupPlus_SQL_Fragment ? $sql->execute( '%f', array( $sql_line ) ) : $sql->script( $sql_line ) );
			if ( ! $req ) {
				$this->last_error = $sql->last_error();
			}
			if ( 3 == $sql_type ) {
				++$this->insert_statements_run;
			}
			if ( 1 == $sql_type ) {
				$this->tables_been_dropped[] = $this->new_table_name;
			}
			++$this->statements_run;
		}

		if ( ! $req ) {
			if ( ! $ignore_errors ) {
				++$this->errors;
			}
			$print_err = ( strlen( $sql_line ) > 100 ) ? substr( $sql_line, 0, 100 ) . ' ...' : $sql_line;
			$backupplus->log(
				sprintf(
				/* translators: %s: Error message */
					_x( 'An error (%s) occurred:', 'The user is being told the number of times an error has happened, e.g. An error (27) occurred', 'backupplus' ),
					$this->errors
				) . ' - ' . $this->last_error . ' - ' . __( 'the database query being run was:', 'backupplus' ) . ' ' . $print_err,
				'notice-restore'
			);
			$backupplus->log( 'An error (' . $this->errors . ') occurred: ' . $this->last_error . " - SQL query was (type=$sql_type): " . substr( $sql_line, 0, 65536 ) );

			if ( 'MySQL server has gone away' == $this->last_error || 'Connection was killed' == $this->last_error ) {

				$restored = false;

				for ( $i = 0; $i < 3; $i++ ) {
					if ( $this->restore_database_connection() ) {
						$restored = true;
						break;
					}
				}

				if ( ! $restored ) {
					$backupplus->log( 'The Database connection has been closed and cannot be reopened.' );
					$backupplus->log( 'Leaving maintenance mode' );
					$this->maintenance_mode( false );
					return new WP_Error( 'db_connection_closed', __( 'The Database connection has been closed and cannot be reopened.', 'backupplus' ) );
				}
				return $this->sql_exec( $sql_line, $sql_type, $import_table_prefix, $check_skipping );
			}

			// First command is expected to be DROP TABLE
			if ( 1 == $this->errors && 2 == $sql_type && 0 == $this->tables_created ) {
				if ( ! empty( $this->db_permissons_forbidden['drop'] ) ) {
					$backupplus->log_e( 'Create table failed - probably because there is no permission to drop tables and the table already exists; will continue' );
				} else {
					$backupplus->log( 'Leaving maintenance mode' );
					$this->maintenance_mode( false );
					/* translators: %s: String 'CREATE TABLE' */
					return new WP_Error( 'initial_db_error', sprintf( __( 'An error occurred on the first %s command - aborting run', 'backupplus' ), 'CREATE TABLE' ) );
				}
			} elseif ( 2 == $sql_type && 0 == $this->tables_created && ! empty( $this->db_permissons_forbidden['drop'] ) ) {
				// Decrease error counter again; otherwise, we'll cease if there are >=50 tables
				if ( ! $ignore_errors ) {
					--$this->errors;
				}
			} elseif ( 3 == $sql_type && false !== strpos( $this->last_error, 'Duplicate entry' ) && false !== strpos( $sql_line, 'INSERT' ) ) {
				$sql_line = BackupPlus_Manipulation_Functions::str_replace_once( 'INSERT', 'INSERT IGNORE', $sql_line );
				$backupplus->log( 'Retrying SQL query with INSERT IGNORE', 'notice-restore' );
				$this->sql_exec( $sql_line, $sql_type, $import_table_prefix, $check_skipping );
			} elseif ( 8 == $sql_type && 1 == $this->errors ) {
				$backupplus->log( 'Aborted: SET NAMES ' . $this->set_names . ' failed: leaving maintenance mode' );
				$this->maintenance_mode( false );
				$extra_msg = '';
				$dbv       = $wpdb->db_version();
				if ( 'utf8mb4' == strtolower( $this->set_names ) && $dbv && version_compare( $dbv, '5.2.0', '<=' ) ) {
					$extra_msg = ' ' . __( 'This problem is caused by trying to restore a database on a very old MySQL version that is incompatible with the source database.', 'backupplus' ) . ' ' .
					/* translators: %s: String '5.5' */
					sprintf( __( 'This database needs to be deployed on MySQL version %s or later.', 'backupplus' ), '5.5' );
				}
				return new WP_Error(
					'initial_db_error',
					/* translators: %s: String 'SET NAMES' */
					sprintf( __( 'An error occurred on the first %s command - aborting run', 'backupplus' ), 'SET NAMES' ) . '. ' .
					/* translators: %s: The character set */
					sprintf( __( 'To use this backup, your database server needs to support the %s character set.', 'backupplus' ), $this->set_names ) . $extra_msg
				);
			} elseif ( 12 == $sql_type ) {
				// sql_type 12 is stored routine creation
				// in case we dealt with an sql syntax error from the stored routine body, the restore operation should not be stopped
				$req = true;
				// Type 14 = ALTER TABLE
			} elseif ( 14 == $sql_type && 1 == $this->errors ) {
				return 1142;
			}

			if ( $this->errors >= ( defined( 'BACKUPPLUS_SQLEXEC_MAXIMUM_ERRORS' ) ? BACKUPPLUS_SQLEXEC_MAXIMUM_ERRORS : 50 ) ) {
				$this->maintenance_mode( false );
				return new WP_Error( 'too_many_db_errors', __( 'Too many database errors have occurred - aborting', 'backupplus' ) );
			}
		} elseif ( 2 == $sql_type ) {
			if ( empty( $this->db_permissons_forbidden['lock'] ) ) {
				$this->lock_table( $this->new_table_name );
			}
			++$this->tables_created;
			do_action( 'backupplus_creating_table', $this->new_table_name );
		}

		if ( $this->line > 0 && 0 == $this->line % 50 ) {
			if ( $this->line > $this->line_last_logged && ( 0 == $this->line % 250 || $this->line < 250 ) ) {
				$this->line_last_logged = $this->line;
				$time_taken             = microtime( true ) - $this->start_time;
				$backupplus->log_e( 'Database queries processed: %d in %.2f seconds', $this->line, $time_taken );
			}
		}
		return $req;
	}
}
