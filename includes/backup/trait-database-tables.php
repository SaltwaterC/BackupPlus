<?php

/** Dumping one table, either natively or row by row. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Backup_Database_Tables {

	/**
	 * Row conditions for a table's dump, from the backupplus_backup_table_sql_where filter
	 *
	 * @param String $table - the table being dumped
	 *
	 * @return Array|WP_Error - BackupPlus_SQL fragments without bound values (both the SELECT and mysqldump --where use text), or an error
	 */
	private function table_conditions( $table ) {
		$conditions = apply_filters( 'backupplus_backup_table_sql_where', array(), $table, $this );
		if ( ! is_array( $conditions ) ) {
			return array();
		}
		foreach ( $conditions as $condition ) {
			if ( ! $condition instanceof BackupPlus_SQL_Fragment || $condition->values() ) {
				return new WP_Error( 'table_conditions_error', "Table $table: backupplus_backup_table_sql_where must return BackupPlus_SQL::fragment() conditions without bound values" );
			}
		}
		return array_values( $conditions );
	}

	/**
	 * Produce a dump of the table using a mysqldump binary
	 *
	 * @param String $potsql     - the path to the mysqldump binary
	 * @param String $table_name - the name of the table being dumped
	 *
	 * @return Boolean - success status
	 */
	private function backup_table_bindump( $potsql, $table_name ) {

		$microtime = microtime( true );

		global $backupplus, $wpdb;

		// Deal with Windows/old MySQL setups with erroneous table prefixes differing in case
		// Can't get binary mysqldump to make this transformation
		// $dump_as_table = ($this->duplicate_tables_exist == false && stripos($table, $this->table_prefix) === 0 && strpos($table, $this->table_prefix) !== 0) ? $this->table_prefix.substr($table, strlen($this->table_prefix)) : $table;

		$pfile = md5( time() . wp_rand() ) . '.tmp';
		file_put_contents( $this->backupplus_dir . '/' . $pfile, "[mysqldump]\npassword=\"" . addslashes( $this->dbinfo['pass'] ) . "\"\n" );

		$conditions = $this->table_conditions( $table_name );
		if ( is_wp_error( $conditions ) ) {
			$backupplus->log( $conditions->get_error_message(), 'error' );
			return false;
		}
		// N.B. Don't add a WHERE prefix here; most versions of mysqldump silently strip it out, but one was encountered that didn't.
		$where = $conditions ? BackupPlus_SQL::render( '%w', array( $conditions ) ) : '';
		if ( 'win' === strtolower( substr( PHP_OS, 0, 3 ) ) ) {
			// On Windows, the PHP escapeshellarg() replaces % char with white space, so we change the % char to [percent_sign] but change it back later after escapeshellarg finish processing it
			$where = str_replace( '%', '[percent_sign]', $where );
		}

		// Note: escapeshellarg() adds quotes around the string
		if ( $where ) {
			$where = '--where=' . escapeshellarg( $where );
		}
		if ( '' !== $where && 'win' === strtolower( substr( PHP_OS, 0, 3 ) ) ) {
			// change the [percent_sign] back to % char
			$where = str_replace( '[percent_sign]', '%', $where );
		}

		if ( strtolower( substr( PHP_OS, 0, 3 ) ) == 'win' ) {
			$exec = 'cd ' . escapeshellarg( str_replace( '/', '\\', $this->backupplus_dir ) ) . ' & ';
		} else {
			$exec = 'cd ' . escapeshellarg( $this->backupplus_dir ) . '; ';
		}

		// Allow --max_allowed_packet to be configured via constant. Experience has shown some customers with complex CMS or pagebuilder setups can have very large postmeta entries.
		$msqld_max_allowed_packet = ( defined( 'BACKUPPLUS_MYSQLDUMP_MAX_ALLOWED_PACKET' ) && ( is_int( BACKUPPLUS_MYSQLDUMP_MAX_ALLOWED_PACKET ) || is_string( BACKUPPLUS_MYSQLDUMP_MAX_ALLOWED_PACKET ) ) ) ? BACKUPPLUS_MYSQLDUMP_MAX_ALLOWED_PACKET : '12M';

		$exec .= "$potsql --defaults-file=$pfile $where --max-allowed-packet=$msqld_max_allowed_packet --quote-names --add-drop-table";

		static $mysql_version = null;
		if ( null === $mysql_version ) {
			$mysql_version = $wpdb->get_var( 'SELECT VERSION()' );
			if ( '' == $mysql_version ) {
				$mysql_version = $wpdb->db_version();
			}
		}
		if ( $mysql_version && version_compare( $mysql_version, '5.1', '>=' ) ) {
			$exec .= ' --no-tablespaces';
		}

		$exec .= ' --skip-comments --skip-set-charset --allow-keywords --dump-date --extended-insert --user=' . escapeshellarg( $this->dbinfo['user'] ) . ' ';

		$host = $this->dbinfo['host'];

		if ( preg_match( '#^(.*):(\d+)$#', $host, $matches ) ) {
			// The escapeshellarg() on $matches[2] is only to avoid tripping static analysis tools
			$exec .= '--host=' . escapeshellarg( $matches[1] ) . ' --port=' . escapeshellarg( $matches[2] ) . ' ';
		} elseif ( preg_match( '#^(.*):(.*)$#', $host, $matches ) && file_exists( $matches[2] ) ) {
			$exec .= '--host=' . escapeshellarg( $matches[1] ) . ' --socket=' . escapeshellarg( $matches[2] ) . ' ';
		} else {
			$exec .= '--host=' . escapeshellarg( $host ) . ' ';
		}

		$exec = apply_filters( 'backupplus_mysqldump_arguments', $exec, $table_name, $potsql );

		$exec .= escapeshellarg( $this->dbinfo['name'] ) . ' ' . escapeshellarg( $table_name );

		$ret         = false;
		$any_output  = false;
		$gtid_found  = false;
		$writes      = 0;
		$write_bytes = 0;
		$handle      = ( function_exists( 'popen' ) && function_exists( 'pclose' ) ) ? popen( $exec, 'r' ) : false;
		if ( $handle ) {
			while ( ! feof( $handle ) ) {
				$w = fgets( $handle, 1048576 );
				if ( is_string( $w ) && $w ) {

					if ( preg_match( '/^SET @@GLOBAL.GTID_PURGED/i', $w ) ) {
						$gtid_found = true;
					}

					if ( $gtid_found ) {
						if ( false !== strpos( $w, ';' ) ) {
							$gtid_found = false;
						}
						continue;
					}

					$this->stow( $w );
					++$writes;
					$write_bytes += strlen( $w );
					$any_output   = true;
				}
			}
			$ret = pclose( $handle );
			// The manual page for pclose() claims that only -1 indicates an error, but this is untrue
			if ( 0 != $ret ) {
				$backupplus->log( "Binary mysqldump: error (code: $ret)" );
				// Keep counter of failures? Change value of binsqldump?
				$ret = false;
			} else {
				if ( $any_output ) {
					$backupplus->log( "Table $table_name: binary mysqldump finished (writes: $writes, bytes $write_bytes, return code $ret) in " . sprintf( '%.02f', max( microtime( true ) - $microtime, 0.00001 ) ) . ' seconds' );
					$ret = true;
				}
			}
		} else {
			$backupplus->log( 'Binary mysqldump error: bindump popen failed' );
		}

		// Clean temporary files
		@unlink( $this->backupplus_dir . '/' . $pfile );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.

		return $ret;
	}

	/**
	 * Write out the initial backup information for a table to the currently open file
	 *
	 * @param String $table           - Full name of database table to backup
	 * @param String $dump_as_table   - Table name to use when writing out
	 * @param String $table_type      - Table type - 'VIEW' is supported; otherwise it is treated as an ordinary table
	 * @param Array  $table_structure - Table structure as returned by a DESCRIBE command
	 */
	private function write_table_backup_beginning( $table, $dump_as_table, $table_type, $table_structure ) {

		$this->stow( "\n# Delete any existing table " . $this->comment_name( $table ) . "\n\n" . BackupPlus_SQL::render( 'DROP TABLE IF EXISTS %i;', array( $dump_as_table ) ) . "\n" );

		if ( 'VIEW' == $table_type ) {
			$this->stow( BackupPlus_SQL::render( 'DROP VIEW IF EXISTS %i;', array( $dump_as_table ) ) . "\n" );
		}

		$description = ( 'VIEW' == $table_type ) ? 'view' : 'table';

		$this->stow( "\n# Table structure of $description " . $this->comment_name( $table ) . "\n\n" );

		$create_table = $this->sql()->rows( 'SHOW CREATE TABLE %i', array( $table ), array(), ARRAY_N );
		if ( false === $create_table ) {
			$this->stow( "#\n# Error with SHOW CREATE TABLE for " . $this->comment_name( $table ) . "\n#\n" );
		}
		$create_line = BackupPlus_Manipulation_Functions::str_lreplace( 'TYPE=', 'ENGINE=', $create_table[0][1] );

		// Remove PAGE_CHECKSUM parameter from MyISAM - was internal, undocumented, later removed (so causes errors on import)
		if ( preg_match( '/ENGINE=([^\s;]+)/', $create_line, $eng_match ) ) {
			$engine = $eng_match[1];
			if ( 'myisam' == strtolower( $engine ) ) {
				$create_line = preg_replace( '/PAGE_CHECKSUM=\d\s?/', '', $create_line, 1 );
			}
		}

		if ( $dump_as_table !== $table ) {
			$create_line = BackupPlus_Manipulation_Functions::str_replace_once( $table, $dump_as_table, $create_line );
		}

		$this->stow( $create_line . ' ;' );

		if ( false === $table_structure ) {
			$this->stow( "#\n# Error getting $description structure of $table\n#\n" );
		}

		// Add a comment preceding the beginning of the data
		$this->stow( "\n\n# " . sprintf( "Data contents of $description %s", $this->comment_name( $table ) ) . "\n\n" );
	}

	/**
	 * Suggest a beginning value for how many rows to fetch in each SELECT statement (before taking into account resumptions)
	 *
	 * @param String $table - the full table name
	 *
	 * @return Integer
	 */
	private function get_rows_on_first_fetch( $table ) {

		// In future, we could run over the table definition; if it is all non-massive defined lengths, we could base a calculation on that.

		if ( $this->table_prefix_raw . 'term_relationships' == $table ) {
			// This table is known to have very small data lengths
			$rows = 100000;
		} elseif ( preg_match( '/meta$/i', $table ) ) {
			// Meta-data rows tend to be short *on average*. 10MB / 4000 rows = 2.6KB/row, so this is still quite conservative.
			$rows = 4000;
		} else {
			// The very conservative default
			$rows = 1000;
		}

		return $rows;
	}

	/**
	 * Suggest how many rows to fetch in each SELECT statement
	 *
	 * @param String          $table                    - the table being fetched
	 * @param Boolean         $allow_further_reductions - whether to enable a second level of reductions (i.e. even less rows)
	 * @param Boolean         $is_first_fetch_for_table - whether this is the first fetch on this table
	 * @param Integer|Boolean $expected_rows            - if an integer, an estimate of the number of rows
	 * @param Boolean         $expected_via_count       - if $expected_rows is an integer, then this indicates whether the estimate was made via a SELECT COUNT() statement
	 *
	 * @return Integer
	 */
	private function number_of_rows_to_fetch( $table, $allow_further_reductions, $is_first_fetch_for_table, $expected_rows = false, $expected_via_count = false ) {

		global $backupplus;

		// This used to be fixed at 500; but we (after a long time) saw a case that looked like an out-of-memory even at this level. Now that we have implemented resumptions, the risk of timeouts is much lower (we just need to process enough rows).
		// October 2020: added further reductions
		// Listed in increasing order due to the handling below. At the end it gets quite drastic. Note, though, that currently we don't store this in the job-data.
		// A future improvement could, when things get drastic, grab and log data on the size of what is required, so that we can respond more dynamically. The strategy currently here will run out of road if memory falls short multiple times. See: https://stackoverflow.com/questions/4524019/how-to-get-the-byte-size-of-resultset-in-an-sql-query
		$fetch_rows_reductions = array( 500, 250, 200, 100 );

		$default_on_first_fetch = $this->get_rows_on_first_fetch( $table );

		$known_bigger_than_table = ( ! is_bool( $expected_rows ) && $expected_rows && $expected_via_count && $default_on_first_fetch > 2 * $expected_rows );

		if ( $known_bigger_than_table ) {
			$allow_further_reductions = true;
		}

		if ( $allow_further_reductions ) {
			// If we're relying on LIMIT with offsets, then we have to be mindful of how that performs
			$fetch_rows_reductions = array_merge( $fetch_rows_reductions, array( 50, 20, 5 ) );
		}

		// Remove any that are far out of range
		if ( $known_bigger_than_table ) {
			foreach ( $fetch_rows_reductions as $k => $reduce_to ) {
				if ( $reduce_to > $expected_rows * 2 && count( $fetch_rows_reductions ) > 2 ) {
					unset( $fetch_rows_reductions[ $k ] );
				}
			}
		}

		// If this is not the first fetch on a table, then get what was stored last time we set it (if we ever did). On the first fetch, reset back to the starting value (we presume problems are table-specific).
		// This means that the same value will persist whilst the table is being backed up, both during the current resumption, and subsequent ones
		$fetch_rows = $is_first_fetch_for_table ? $default_on_first_fetch : $backupplus->jobdata_get( 'fetch_rows', $default_on_first_fetch );

		$fetch_rows_at_start = $fetch_rows;

		$resumptions_since_last_successful = $backupplus->current_resumption - $backupplus->last_successful_resumption;

		// Do we need to reduce the number of rows we attempt to fetch?
		// If something useful has happened on this run, then we don't try any reductions (we save them for a resumption after one on which nothing useful happened)
		if ( $known_bigger_than_table || ( ! $backupplus->something_useful_happened && ! empty( $backupplus->current_resumption ) && $resumptions_since_last_successful > 1 ) ) {

			$break_after = $is_first_fetch_for_table ? max( $resumptions_since_last_successful - 1, 1 ) : 1;

			foreach ( $fetch_rows_reductions as $reduce_to ) {
				if ( $fetch_rows > $reduce_to ) {
					// Go down one level
					$fetch_rows = $reduce_to;
					--$break_after;
					if ( $break_after < 1 ) {
						break;
					}
				}
			}

			$log_start = $backupplus->current_resumption ? "Last successful resumption was $resumptions_since_last_successful runs ago" : 'Table is relatively small';
			$backupplus->log( "$log_start; fetch_rows will thus be: $fetch_rows (allow_further_reductions=$allow_further_reductions, is_first_fetch=$is_first_fetch_for_table, known_bigger_than_table=$known_bigger_than_table)" );
		}

		// If it has changed, then preserve it in the job for the next resumption (of this table)
		if ( $fetch_rows_at_start !== $fetch_rows || $is_first_fetch_for_table ) {
			$backupplus->jobdata_set( 'fetch_rows', $fetch_rows );
		}

		return apply_filters( 'backupplus_number_of_rows_to_fetch', $fetch_rows, $table, $allow_further_reductions, $is_first_fetch_for_table, $expected_rows, $expected_via_count );
	}

	/**
	 * Return a list of primary keys (N.B. the method should not be called unless the caller already knows that the table has a single/simple primary key) for rows that have "over-sized" data.
	 * Currently this only examines the "posts" table and any other table with a longtext type, which are the primary causes of problems. If others are revealed in future, it can be generalised (e.g. examine the whole definition/all cells).
	 *
	 * @param String $table       - the full table name
	 * @param Array  $structure   - the table structure, as from WPDB::get_results("DESCRIBE ...");
	 * @param String $primary_key - the primary key to use; required if $structure is set (slightly redundant, since it can be derived from structure)
	 *
	 * @return Array - list of IDs
	 */
	private function get_oversized_rows( $table, $structure = array(), $primary_key = '' ) {

		if ( $this->table_prefix_raw . 'posts' != $table ) {
			if ( empty( $structure ) || '' === $primary_key ) {
				return array();
			}
			foreach ( $structure as $item ) {
				if ( '' !== $item->Field && 'longtext' === $item->Type ) {
					$use_field = $item->Field;
				}
			}
		} else {
			$primary_key = 'id';
			$use_field   = 'post_content';
		}

		if ( ! isset( $use_field ) ) {
			return array();
		}

		global $backupplus;

		// Look for the jobdata_delete() call elsewhere in this class - the key name needs to match
		$jobdata_key = 'oversized_rows_' . $table;

		$oversized_list = $backupplus->jobdata_get( $jobdata_key );

		if ( is_array( $oversized_list ) ) {
			return $oversized_list;
		}

		$oversized_list = array();

		// Allow over-ride via a constant
		$oversized_row_size = defined( 'BACKUPPLUS_OVERSIZED_ROW_SIZE' ) ? BACKUPPLUS_OVERSIZED_ROW_SIZE : 2048576;

		$oversized_rows = $this->sql()->column( 'SELECT %i FROM %i WHERE LENGTH(%i) > ? ORDER BY %i ASC', array( $primary_key, $table, $use_field, $primary_key ), array( (int) $oversized_row_size ) );

		// Upon an error, just return an empty list
		if ( ! is_array( $oversized_rows ) ) {
			return array();
		}

		$backupplus->jobdata_set( $jobdata_key, $oversized_rows );

		return $oversized_rows;
	}

	/**
	 * Original version taken partially from phpMyAdmin and partially from Alain Wolf, Zurich - Switzerland to use the WordPress $wpdb object
	 * Website: http://restkultur.ch/personal/wolf/scripts/db_backup/
	 * Modified by Scott Merrill (http://www.skippy.net/)
	 * Subsequently heavily improved and modified
	 *
	 * This method should be called in a loop for a complete table backup (see the information for the returned parameter). The method may implement whatever strategy it likes for deciding when to return (the assumption is that when it does return with some results, the caller should register that something useful happened).
	 *
	 * @param String          $table               - Full name of database table to backup
	 * @param String          $table_type          - Table type - 'VIEW' is supported; otherwise it is treated as an ordinary table
	 * @param Integer|Boolean $start_record        - Specify the starting record, or true to start at the beginning. Our internal page size is fixed at 1000 (though within that we might actually query in smaller batches).
	 * @param Boolean         $can_use_primary_key - Whether it is allowed to perform quicker SELECTS based on the primary key. The intended use case for false is to support backups running during a version upgrade. N.B. This "can" is not absolute; there may be other constraints dealt with within this method.
	 *
	 * @return Integer|Array|WP_Error - a WP_Error to indicate an error; an array indicates that it finished (if it includes 'next_record' that means it finished via producing something); an integer to indicate the next page the case that there are more to do.
	 */
	private function backup_table( $table, $table_type = 'BASE TABLE', $start_record = true, $can_use_primary_key = true ) {
		$process_pages = 100;

		// Preserve the passed-in value
		$original_start_record = $start_record;

		global $backupplus;

		$microtime  = microtime( true );
		$total_rows = 0;

		// Deal with Windows/old MySQL setups with erroneous table prefixes differing in case
		$dump_as_table = ( false == $this->duplicate_tables_exist && 0 === stripos( $table, $this->table_prefix ) && 0 !== strpos( $table, $this->table_prefix ) ) ? $this->table_prefix . substr( $table, strlen( $this->table_prefix ) ) : $table;

		$sql             = $this->sql();
		$table_structure = $sql->rows( 'DESCRIBE %i', array( $table ) );
		if ( ! $table_structure ) {
			// $backupplus->log(__('Error getting table details', 'backupplus') . ": $table", 'error');
			$error_message = '';
			if ( $sql->last_error() ) {
				$error_message .= ' (' . $sql->last_error() . ')';
			}
			return new WP_Error( 'table_details_error', $error_message );
		}

		// If at the beginning of the dump for a table, then add the DROP and CREATE statements
		if ( true === $start_record ) {
			$this->write_table_backup_beginning( $table, $dump_as_table, $table_type, $table_structure );
		}

		// Some tables have optional data, and should be skipped if they do not work
		$table_sans_prefix    = substr( $table, strlen( $this->table_prefix_raw ) );
		$data_optional_tables = ( 'wp' == $this->whichdb ) ? apply_filters( 'BACKUPPLUS_DATA_OPTIONAL_TABLES', explode( ',', BACKUPPLUS_DATA_OPTIONAL_TABLES ) ) : array();
		if ( in_array( $table_sans_prefix, $data_optional_tables ) ) {
			if ( ! $backupplus->something_useful_happened && ! empty( $backupplus->current_resumption ) && ( $backupplus->current_resumption - $backupplus->last_successful_resumption > 2 ) ) {
				$backupplus->log( "Table $table: Data skipped (previous attempts failed, and table is marked as non-essential)" );
				return array();
			}
		}

		$table_data = array();
		if ( 'VIEW' != $table_type ) {
			$select_selected_fields = $insert_selected_fields = array();
			$defs                   = array();
			$integer_fields         = array();
			$binary_fields          = array();
			$bit_fields             = array();
			$bit_field_exists       = false;
			$invisible_field_exists = false;

			// false means "not yet set"; a string means what it was set to; null means that there are multiple (and so not useful to us). If it is not a string, then $primary_key_type is invalid and should not be used.
			$primary_key      = false;
			$primary_key_type = false;

			// $table_structure was from "DESCRIBE $table"
			foreach ( $table_structure as $struct ) {

				if ( isset( $struct->Key ) && 'PRI' == $struct->Key && '' != $struct->Field ) {
					$primary_key      = ( false === $primary_key ) ? $struct->Field : null;
					$primary_key_type = $struct->Type;
				}

				if ( ( 0 === strpos( $struct->Type, 'tinyint' ) ) || ( 0 === strpos( strtolower( $struct->Type ), 'smallint' ) )
					|| ( 0 === strpos( strtolower( $struct->Type ), 'mediumint' ) ) || ( 0 === strpos( strtolower( $struct->Type ), 'int' ) ) || ( 0 === strpos( strtolower( $struct->Type ), 'bigint' ) )
				) {
					$defs[ strtolower( $struct->Field ) ]           = ( null === $struct->Default ) ? 'NULL' : $struct->Default;
					$integer_fields[ strtolower( $struct->Field ) ] = true;
				}

				if ( ( 0 === strpos( strtolower( $struct->Type ), 'binary' ) ) || ( 0 === strpos( strtolower( $struct->Type ), 'varbinary' ) ) || ( 0 === strpos( strtolower( $struct->Type ), 'tinyblob' ) ) || ( 0 === strpos( strtolower( $struct->Type ), 'mediumblob' ) ) || ( 0 === strpos( strtolower( $struct->Type ), 'blob' ) ) || ( 0 === strpos( strtolower( $struct->Type ), 'longblob' ) ) ) {
					$binary_fields[ strtolower( $struct->Field ) ] = true;
				}

				$insert_selected_fields[] = BackupPlus_SQL::fragment( '%i', array( $struct->Field ) );

				if ( preg_match( '/^bit(?:\(([0-9]+)\))?$/i', trim( $struct->Type ), $matches ) ) {
					if ( ! $bit_field_exists ) {
						$bit_field_exists = true;
					}
					$bit_fields[ strtolower( $struct->Field ) ] = ! empty( $matches[1] ) ? max( 1, (int) $matches[1] ) : 1;
					// the reason why if bit fields are found then the fields need to be cast into binary type is that if mysqli_query function is being used, mysql will convert the bit field value to a decimal number and represent it in a string format whereas, if mysql_query function is being used, mysql will not convert it to a decimal number but instead will keep it retained as it is
					$select_selected_fields[] = BackupPlus_SQL::fragment( 'CAST(%i AS BINARY) AS %i', array( $struct->Field, $struct->Field ) );
				} else {
					$select_selected_fields[] = BackupPlus_SQL::fragment( '%i', array( $struct->Field ) );
				}

				if ( isset( $struct->Extra ) && preg_match( '/\binvisible\b/i', $struct->Extra ) ) {
					$invisible_field_exists = true;
				}
			}

			$expected_via_count = false;

			// N.B. At this stage this is for optimisation, mainly targets what is used on the core WP tables (bigint(20)); a value can be relied upon, but false is not definitive. N.B. https://docs.oracle.com/cd/E17952_01/mysql-8.0-en/numeric-type-syntax.html (retrieved Aug 2021): "As of MySQL 8.0.17, the display width attribute is deprecated for integer data types; you should expect support for it to be removed in a future version of MySQL." MySQL 8.0.20 is not returning it.
			$use_primary_key = false;
			if ( $can_use_primary_key && is_string( $primary_key ) && preg_match( '#^(small|medium|big)?int(\(| |$)#i', $primary_key_type ) ) {
				$use_primary_key = true;

				// We don't bother re-counting if it's likely to be so large that we're not going to do anything with the result
				if ( is_bool( $this->expected_rows ) || $this->expected_rows < 1000 ) {
					$expected_rows = $sql->value( 'SELECT COUNT(%i) FROM %i', array( $primary_key, $table ) );
					if ( ! is_bool( $expected_rows ) ) {
						$this->expected_rows = $expected_rows;
						$expected_via_count  = true;
					}
				}

				$oversized_rows = $this->get_oversized_rows( $table, $table_structure, $primary_key );

				if ( preg_match( '# unsigned$#i', $primary_key_type ) ) {
					if ( true === $start_record ) {
						$start_record = -1;
					}
				} else {
					if ( true === $start_record ) {
						$min_value    = $sql->value( 'SELECT MIN(%i) FROM %i', array( $primary_key, $table ) );
						$start_record = ( is_numeric( $min_value ) && $min_value ) ? (int) $min_value - 1 : -1;
					}
				}
			}

			if ( ! is_bool( $this->expected_rows ) ) {
				$log_expected_records = ( true === $original_start_record );
				$this->log_expected_rows( $table, $this->expected_rows, $expected_via_count, $log_expected_records );
			}

			$search  = array( "\x00", "\x0a", "\x0d", "\x1a" );
			$replace = array( '\0', '\n', '\r', '\Z' );

			$conditions = $this->table_conditions( $table );
			if ( is_wp_error( $conditions ) ) {
				return $conditions;
			}

			// Experimentation here shows that on large tables (we tested with 180,000 rows) on MyISAM, 1000 makes the table dump out 3x faster than the previous value of 100. After that, the benefit diminishes (increasing to 4000 only saved another 12%)

			$fetch_rows = $this->number_of_rows_to_fetch( $table, $use_primary_key || $start_record < 500000, true === $original_start_record, $this->expected_rows, $expected_via_count );

			if ( ! is_bool( $this->expected_rows ) ) {
				$this->expected_rows = true;
			}

			$original_fetch_rows = $fetch_rows;

			$select_selected_fields = $bit_field_exists || $invisible_field_exists ? $select_selected_fields : array( BackupPlus_SQL::fragment( '*' ) );

			$enough_for_now = false;

			$began_writing_at = time();

			$enough_data_after = 104857600;
			$enough_time_after = ( $fetch_rows > 250 ) ? 15 : 9;

			// Loop which retrieves data
			do {

				if ( function_exists( 'set_time_limit' ) ) {
					@set_time_limit( BACKUPPLUS_SET_TIME_LIMIT );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
				}
				// Reset back to that which has constructed before the loop began
				$final_where = $conditions;

				if ( $use_primary_key ) {

					// The point of this is to leverage the indexing on the private key to make the SELECT much faster than index-less paging
					// If it's -1, then we avoid mentioning a negative value, as the value may be unsigned
					$final_where[] = ( -1 === $start_record ) ? BackupPlus_SQL::fragment( '%i >= 0', array( $primary_key ) ) : BackupPlus_SQL::fragment( '%i > %d', array( $primary_key, $start_record ) );

					$oversized_last_row_id = false;
					// Remove ones we've gone past
					foreach ( $oversized_rows as $k => $row_id ) {
						if ( $start_record >= $row_id ) {
							unset( $oversized_rows[ $k ] );
						} else {
							$oversized_last_row_id = $row_id;
							// At this point we are only willing to fetch a single over-sized row. If this ever changes, we'll need to also keep track of their length.
							break;
						}
					}
					// Number the keys again from zero
					$oversized_rows = array_values( $oversized_rows );

					if ( $oversized_last_row_id ) {
						$final_where[] = BackupPlus_SQL::fragment( '%i <= %d', array( $primary_key, $oversized_last_row_id ) );
					}

					$order_and_limit = BackupPlus_SQL::fragment( 'ORDER BY %i ASC LIMIT %d', array( $primary_key, (int) $fetch_rows ) );

				} else {
					if ( true === $start_record ) {
						$start_record = 0;
					}
					$order_and_limit = BackupPlus_SQL::fragment( 'LIMIT %d, %d', array( (int) $start_record, (int) $fetch_rows ) );
				}

				// Text protocol (no bound values), so that values reach the dump exactly as the server formats them
				$select_sql = $final_where ? BackupPlus_SQL::fragment( 'SELECT %l FROM %i WHERE %w %f', array( $select_selected_fields, $table, $final_where, $order_and_limit ) ) : BackupPlus_SQL::fragment( 'SELECT %l FROM %i %f', array( $select_selected_fields, $table, $order_and_limit ) );

				if ( defined( 'BACKUPPLUS_LOG_BACKUP_SELECTS' ) && BACKUPPLUS_LOG_BACKUP_SELECTS ) {
					$backupplus->log( (string) $select_sql );
				}

				$sql        = $this->sql();
				$table_data = $sql->rows( '%f', array( $select_sql ), array(), ARRAY_A );
				if ( false === $table_data ) {
					$backupplus->log( 'Database fetch error (' . $sql->last_error() . ") when running: $select_sql" );
					// As $wpdb->get_results() did, a failed fetch is passed on as no rows
					$table_data = array();
				}

				// Allow the data to be filtered (e.g. anonymisation)
				$table_data = apply_filters( 'backupplus_backup_table_results', $table_data, $table, $this->table_prefix, $this->whichdb );

				$oversized_changes = false;

				if ( ! $table_data ) {
					// Nothing was found - not even the expected over-sized row; this means it was deleted - so don't try to use a limitation based on it again, or we may get an infinite loop.
					if ( isset( $oversized_last_row_id ) && false !== $oversized_last_row_id ) {
						if ( false !== ( $key = array_search( $oversized_last_row_id, $oversized_rows ) ) ) {
							unset( $oversized_rows[ $key ] );
							$oversized_changes = true;
						}
					}
					if ( $oversized_changes ) {
						$backupplus->jobdata_set( 'oversized_rows_' . $table, $oversized_rows );
					}
					continue;
				}
				$entries = $invisible_field_exists ? BackupPlus_SQL::render( 'INSERT INTO %i (%l) VALUES ', array( $dump_as_table, $insert_selected_fields ) ) : BackupPlus_SQL::render( 'INSERT INTO %i VALUES ', array( $dump_as_table ) );

				// \x08\\x09, not required

				$this_entry = '';
				foreach ( $table_data as $row ) {
					++$total_rows;
					if ( $this_entry ) {
						$this_entry .= ",\n ";
					}
					$this_entry .= '(';
					$key_count   = 0;
					foreach ( $row as $key => $value ) {

						if ( $key_count ) {
							$this_entry .= ', ';
						}
						++$key_count;

						if ( $use_primary_key && strtolower( $primary_key ) == strtolower( $key ) && $value > $start_record ) {
							$start_record = $value;
							foreach ( $oversized_rows as $k => $row_id ) {
								if ( $start_record >= $row_id ) {
									unset( $oversized_rows[ $k ] );
								} else {
									break;
								}
							}
						}

						if ( isset( $integer_fields[ strtolower( $key ) ] ) ) {
							// make sure there are no blank spots in the insert syntax,
							// yet try to avoid quotation marks around integers
							$value       = ( null === $value || '' === $value ) ? $defs[ strtolower( $key ) ] : $value;
							$value       = ( '' === $value ) ? "''" : $value;
							$this_entry .= $value;
						} elseif ( isset( $binary_fields[ strtolower( $key ) ] ) ) {
							if ( null === $value ) {
								$this_entry .= 'NULL';
							} elseif ( '' === $value ) {
								$this_entry .= "''";
							} else {
								$this_entry .= '0x' . bin2hex( str_repeat( '0', floor( strspn( $value, '0' ) / 4 ) ) . $value );
							}
						} elseif ( isset( $bit_fields[ strtolower( $key ) ] ) ) {
							if ( null === $value ) {
								$this_entry .= 'NULL';
							} else {
								mbstring_binary_safe_encoding();
								$val_len = is_string( $value ) ? strlen( $value ) : 0;
								reset_mbstring_encoding();
								$hex = '';
								for ( $i = 0; $i < $val_len; $i++ ) {
									$hex .= sprintf( '%02X', ord( $value[ $i ] ) );
								}
								$this_entry .= "b'" . str_pad( $this->hex2bin( $hex ), $bit_fields[ strtolower( $key ) ], '0', STR_PAD_LEFT ) . "'";
							}
						} else {
							$this_entry .= ( null === $value ) ? 'NULL' : "'" . str_replace( $search, $replace, str_replace( '\'', '\\\'', str_replace( '\\', '\\\\', $value ) ) ) . "'";
						}
					}
					$this_entry .= ')';

					// Flush every 512KB
					if ( strlen( $this_entry ) > 524288 ) {
						$this_entry .= ';';
						if ( strlen( $this_entry ) > 10485760 ) {
							// This is an attempt to prevent avoidable duplication of long strings in-memory, at the cost of one extra write
							$this->stow( " \n" . $entries );
							$this->stow( $this_entry );
						} else {
							$this->stow( " \n" . $entries . $this_entry );
						}
						$this_entry = '';
						// Potentially indicate that enough has been done to loop
						if ( $this->db_current_raw_bytes > $enough_data_after || time() - $began_writing_at > $enough_time_after ) {
							$enough_for_now = true;
						}
					}
				}

				if ( $this_entry ) {
					$this_entry .= ';';
					if ( strlen( $this_entry ) > 10485760 ) {
						// This is an attempt to prevent avoidable duplication of long strings in-memory, at the cost of one extra write
						$this->stow( " \n" . $entries );
						$this->stow( $this_entry );
					} else {
						$this->stow( " \n" . $entries . $this_entry );
					}
				}

				// Increment this before any potential changes to $fetch_rows
				if ( ! $use_primary_key ) {
					$start_record += $fetch_rows;
				}

				// Potentially fetch more rows at once, if performance has been good on a sufficient number of rows
				// However - testing indicates that this makes very little difference to overall performance; MySQL's performance scales linearly with the number of rows requested. So optimisations here are unlikely to be worthwhile. (Probably better to remove LIMIT and ORDER BY entirely on tables that look small enough to fit into memory in one go)
				if ( ! $enough_for_now && $total_rows > 0 && $fetch_rows >= $original_fetch_rows && $fetch_rows < $original_fetch_rows * 8 && $this->db_current_raw_bytes > 10000 && $total_rows > 5000 ) {
					$bytes_per_row = $this->db_current_raw_bytes / $total_rows;
					// Increase the numbers of rows fetched if we still expect it to be less than 5MB, and the rate is acceptable
					if ( 2 * $fetch_rows * $bytes_per_row < 5242880 ) {
						// N.B. This does not persist across resumptions
						$fetch_rows    = $fetch_rows * 2;
						$process_pages = $process_pages / 2;
					}
				}

				if ( $process_pages > 0 ) {
					--$process_pages;
				}

				// The condition involving count($oversized_rows) is for when rows that were in oversized_rows got deleted before being fetched; the "ID < (row)" condition could result in no data being returned, even though the table isn't finished
			} while ( ! $enough_for_now && ( count( $table_data ) > 0 || ( isset( $oversized_rows ) && count( $oversized_rows ) > 0 ) ) && ( -1 == $process_pages || $process_pages > 0 ) );
		}

		$fetch_time = max( microtime( true ) - $microtime, 0.00001 );

		$backupplus->log( "Table $table: Rows added in this batch (next record: $start_record): $total_rows (uncompressed bytes in this segment=" . $this->db_current_raw_bytes . ') in ' . sprintf( '%.02f', $fetch_time ) . ' seconds' );

		// If all data has been fetched, then write out the closing comment
		if ( -1 == $process_pages || 0 == count( $table_data ) ) {
			$this->stow( "\n# End of data contents of table " . $this->comment_name( $table ) . "\n\n" );
			// Keep the keyname here in sync with what is in self::get_oversized_rows()
			$backupplus->jobdata_delete( 'oversized_rows_' . $table );
			return is_numeric( $start_record ) ? array( 'next_record' => (int) $start_record ) : array();
		}

		return is_numeric( $start_record ) ? (int) $start_record : $start_record;
	}
}
