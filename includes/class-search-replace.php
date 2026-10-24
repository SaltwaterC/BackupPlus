<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct $wpdb query is required for this operation.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- some query operations need to always receive the most up-to-date or actual data directly from the database, reducing the risk of serving stale information.
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

class BackupPlus_Search_Replace {

	private $columns = array();

	private $current_row = 0;

	private $use_wpdb = false;

	private $wpdb_obj = null;

	private $mysql_dbh = null;

	private $fetch_error = '';

	// The searched columns, named explicitly: SELECT * leaves out INVISIBLE columns
	private $select_columns = array();

	protected $max_recursion = 0;

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'backupplus_restore_db_pre', array( $this, 'backupplus_restore_db_pre' ) );
		$this->max_recursion = apply_filters( 'backupplus_search_replace_max_recursion', 20 );
	}

	/**
	 * This function is called via the filter backupplus_restore_db_pre it sets up the search and replace database objects
	 *
	 * @return void
	 */
	public function backupplus_restore_db_pre() {
		global $wpdb, $backupplus_restorer;

		$this->use_wpdb = $backupplus_restorer->use_wpdb();
		$this->wpdb_obj = $wpdb;

		$mysql_dbh = false;

		if ( ! $this->use_wpdb ) {
			// We have our own extension which drops lots of the overhead on the query
			$wpdb_obj = $backupplus_restorer->get_db_object();
			// Was that successful?
			if ( ! $wpdb_obj->is_mysql || ! $wpdb_obj->ready || ! $wpdb_obj->backupplus_use_mysqli() ) {
				$this->use_wpdb = true;
			} else {
				$this->wpdb_obj = $wpdb_obj;
				$mysql_dbh      = $wpdb_obj->backupplus_get_database_handle();
			}
		}

		$this->mysql_dbh = $mysql_dbh;
	}

	/**
	 * The engine
	 *
	 * @param string|array $search    - a string or array of things to search for
	 * @param string|array $replace   - a string or array of things to replace the search terms with
	 * @param array        $tables    - an array of tables
	 * @param integer      $page_size - the page size
	 */
	public function icit_srdb_replacer( $search, $replace, $tables, $page_size ) {

		if ( ! is_array( $tables ) ) {
			return false;
		}

		global $backupplus;

		$report = array(
			'tables'  => 0,
			'rows'    => 0,
			'change'  => 0,
			'updates' => 0,
			'start'   => microtime( true ),
			'end'     => microtime( true ),
			'errors'  => array(),
		);

		$page_size = ( empty( $page_size ) || ! is_numeric( $page_size ) ) ? 5000 : $page_size;

		foreach ( $tables as $table => $stripped_table ) {

			++$report['tables'];

			if ( $search === $replace ) {
				$backupplus->log( 'No search/replace required: would-be search and replacement are identical' );
				continue;
			}

			$this->columns = array();

			$print_line = __( 'Search and replacing table:', 'backupplus' ) . ' ' . $table;

			$backupplus->check_db_connection( $this->wpdb_obj, true );

			$sql = BackupPlus_SQL::wpdb();

			// Get a list of columns in this table
			$fields = $sql->rows( 'DESCRIBE %i', array( $table ), array(), ARRAY_A );

			$prikey_field = false;
			foreach ( is_array( $fields ) ? $fields : array() as $column ) {
				$primary_key = ( 'PRI' == $column['Key'] ) ? true : false;
				if ( $primary_key ) {
					$prikey_field = $column['Field'];
				}
				if ( 'posts' == $stripped_table && 'guid' == $column['Field'] ) {
					$backupplus->log( 'Skipping search/replace on GUID column in posts table' );
					continue;
				}
				$this->columns[ $column['Field'] ] = $primary_key;
			}
			$this->select_columns = array();
			foreach ( array_keys( $this->columns ) as $column ) {
				$this->select_columns[] = BackupPlus_SQL::fragment( '%i', array( $column ) );
			}
			if ( ! $this->select_columns ) {
				$this->select_columns[] = BackupPlus_SQL::fragment( '*' );
			}

			// Count the number of rows we have in the table if large we'll split into blocks, This is a mod from Simon Wheatley

			// InnoDB does not do count(*) quickly. You can use an index for more speed - see: http://www.cloudspace.com/blog/2009/08/06/fast-mysql-innodb-count-really-fast/

			// Opportunity to use internal knowledge on tables which may be huge: only rows matching meta_value LIKE '%http%' are searched
			$http_only = 'postmeta' == $stripped_table && ( ( is_array( $search ) && 0 === strpos( $search[0], 'http' ) ) || ( is_string( $search ) && 0 === strpos( $search, 'http' ) ) );

			$row_count = $this->count_rows( $sql, $table, $prikey_field ? 'PRIMARY' : null, $http_only );

			// If that failed, try this
			if ( false !== $prikey_field && false === $row_count ) {
				$row_count = $this->count_rows( $sql, $table, $prikey_field, $http_only );
				if ( false === $row_count ) {
					$row_count = $this->count_rows( $sql, $table, null, false );
				}
			}

			/* translators: %d: Number of rows. */
			$print_line .= ': ' . sprintf( __( 'rows: %d', 'backupplus' ), $row_count );
			$backupplus->log( $print_line, 'notice-restore', 'restoring-table-' . $table );
			$backupplus->log( 'Search and replacing table: ' . $table . ': rows: ' . $row_count );

			if ( 0 == $row_count ) {
				continue;
			}

			for ( $on_row = 0; $on_row <= $row_count; $on_row = $on_row + $page_size ) {

				$this->current_row = 0;

				if ( $on_row > 0 ) {
					$backupplus->log_e( 'Searching and replacing reached row: %d', $on_row );
				}

				// Grab the contents of the table
				list($data, $page_size) = $this->fetch_sql_result( $table, $on_row, $page_size, $http_only );
				// $sql_line is calculated here only for the purpose of logging errors
				$sql_line = $this->page_sql( $table, $on_row, $on_row + $page_size, $http_only );

				// Our strategy here is to minimise memory usage if possible; to process one row at a time if we can, rather than reading everything into memory
				if ( $this->use_wpdb ) {

					if ( false === $data ) {
						$report['errors'][] = $this->print_error( $sql_line, $this->fetch_error );
					} else {
						foreach ( $data as $row ) {
							$rowrep = $this->process_row( $table, $row, $search, $replace, $stripped_table );
							++$report['rows'];
							$report['updates'] += $rowrep['updates'];
							$report['change']  += $rowrep['change'];
							foreach ( $rowrep['errors'] as $err ) {
								$report['errors'][] = $err;
							}
						}
					}
				} else {
					if ( false === $data ) {
						$report['errors'][] = $this->print_error( $sql_line );
					} elseif ( true !== $data && null !== $data ) {
						while ( $row = mysqli_fetch_array( $data ) ) { // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_fetch_array -- Using mysqli directly for streaming large result sets during restore to avoid high memory usage with $wpdb
							$rowrep = $this->process_row( $table, $row, $search, $replace, $stripped_table );
							++$report['rows'];
							$report['updates'] += $rowrep['updates'];
							$report['change']  += $rowrep['change'];
							foreach ( $rowrep['errors'] as $err ) {
								$report['errors'][] = $err;
							}
						}
						mysqli_free_result( $data ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_free_result -- Using mysqli directly for streaming large result sets during restore to avoid high memory usage with $wpdb
					}
				}
			}
		}

		$report['end'] = microtime( true );

		return $report;
	}

	/**
	 * Count the rows of a table, as the search and replace will page through them
	 *
	 * @param BackupPlus_SQL $sql       - the connection to count on
	 * @param string         $table     - the table name
	 * @param string|null    $index     - the index to count with, or null to let the server choose
	 * @param boolean        $http_only - whether to count only postmeta rows whose meta_value contains http
	 *
	 * @return string|false|null - the number of rows, or false on error
	 */
	private function count_rows( $sql, $table, $index, $http_only ) {
		if ( null === $index ) {
			return $http_only ? $sql->value( "SELECT COUNT(*) FROM %i WHERE meta_value LIKE '%http%'", array( $table ) ) : $sql->value( 'SELECT COUNT(*) FROM %i', array( $table ) );
		}
		return $http_only ? $sql->value( "SELECT COUNT(*) FROM %i USE INDEX (%i) WHERE meta_value LIKE '%http%'", array( $table, $index ) ) : $sql->value( 'SELECT COUNT(*) FROM %i USE INDEX (%i)', array( $table, $index ) );
	}

	/**
	 * The text of the statement fetching a page of rows, for logging
	 *
	 * @param string  $table     - the table name
	 * @param integer $on_row    - the row to start from
	 * @param integer $page_size - the page size
	 * @param boolean $http_only - whether only postmeta rows whose meta_value contains http are fetched
	 *
	 * @return string
	 */
	private function page_sql( $table, $on_row, $page_size, $http_only ) {
		return $http_only ? BackupPlus_SQL::render( "SELECT %l FROM %i WHERE meta_value LIKE '%http%' LIMIT %d, %d", array( $this->select_columns, $table, (int) $on_row, (int) $page_size ) ) : BackupPlus_SQL::render( 'SELECT %l FROM %i LIMIT %d, %d', array( $this->select_columns, $table, (int) $on_row, (int) $page_size ) );
	}

	/**
	 * This function will get data from the passed in table ready to be search and replaced
	 *
	 * @param string  $table     - the table name
	 * @param integer $on_row    - the row to start from
	 * @param integer $page_size - the page size
	 * @param boolean $http_only - whether to fetch only postmeta rows whose meta_value contains http
	 *
	 * @return array - an array of data or an array with a false value
	 */
	private function fetch_sql_result( $table, $on_row, $page_size, $http_only = false ) {

		global $backupplus;
		$backupplus->check_db_connection( $this->wpdb_obj, true );

		if ( $this->use_wpdb ) {
			$sql  = BackupPlus_SQL::wpdb();
			$data = $http_only ? $sql->rows( "SELECT %l FROM %i WHERE meta_value LIKE '%http%' LIMIT %d, %d", array( $this->select_columns, $table, (int) $on_row, (int) $page_size ), array(), ARRAY_A ) : $sql->rows( 'SELECT %l FROM %i LIMIT %d, %d', array( $this->select_columns, $table, (int) $on_row, (int) $page_size ), array(), ARRAY_A );
			if ( false !== $data ) {
				return array( $data, $page_size );
			}
			$this->fetch_error = $sql->last_error();
		} else {
			// Streamed from the result set rather than copied into PHP arrays; the statement holds only identifiers and integers.
			if ( $http_only ) {
				$data = mysqli_query( $this->mysql_dbh, BackupPlus_SQL::render( "SELECT %l FROM %i WHERE meta_value LIKE '%http%' LIMIT %d, %d", array( $this->select_columns, $table, (int) $on_row, (int) $page_size ) ) ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- Direct mysqli call required; this uses a dedicated low-level database handle ($this->mysql_dbh) outside $wpdb for search-replace operations.
			} else {
				$data = mysqli_query( $this->mysql_dbh, BackupPlus_SQL::render( 'SELECT %l FROM %i LIMIT %d, %d', array( $this->select_columns, $table, (int) $on_row, (int) $page_size ) ) ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- Direct mysqli call required; this uses a dedicated low-level database handle ($this->mysql_dbh) outside $wpdb for search-replace operations.
			}
			if ( false !== $data ) {
				return array( $data, $page_size );
			}
		}

		if ( 5000 <= $page_size ) {
			return $this->fetch_sql_result( $table, $on_row, 2000, $http_only );
		}
		if ( 2000 <= $page_size ) {
			return $this->fetch_sql_result( $table, $on_row, 500, $http_only );
		}

		// At this point, $page_size should be 500; and that failed
		return array( false, $page_size );
	}

	/**
	 * This function will process a single row from the database calling recursive_unserialize_replace to search and replace the data found in the search and replace arrays
	 *
	 * @param string $table          - the current table we are working on
	 * @param array  $row            - the current row we are working on
	 * @param array  $search         - an array of things to search for
	 * @param array  $replace        - an array of things to replace the search terms with
	 * @param string $stripped_table - the stripped table
	 *
	 * @return array - returns an array report which includes changes made and any errors
	 */
	private function process_row( $table, $row, $search, $replace, $stripped_table ) {

		global $backupplus, $backupplus_restorer;

		$report = array(
			'change'  => 0,
			'errors'  => array(),
			'updates' => 0,
		);

		++$this->current_row;

		$update_sql = array();
		$where_sql  = array();
		$upd        = false;

		foreach ( $this->columns as $column => $primary_key ) {

			// Don't search/replace these
			if ( 'sitemeta' == $stripped_table && 'meta_value' == $column && ! empty( $row['meta_key'] ) && 'BackupPlus_Options' == $row['meta_key'] ) {
				continue;
			}

			$edited_data = $data_to_fix = $row[ $column ];
			$successful  = false;

			// We catch errors/exceptions so that they're not fatal. Once saw a fatal ("Cannot access empty property") on "if (is_a($value, '__PHP_Incomplete_Class')) {" (not clear what $value has to be to cause that).
			try {
				// Run a search replace on the data that'll respect the serialisation.
				$edited_data = $this->recursive_unserialize_replace( $search, $replace, $data_to_fix );
				$successful  = true;
			} catch ( Exception $e ) {
				$log_message        = 'An Exception (' . get_class( $e ) . ') occurred during the recursive search/replace. Exception message: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
				$report['errors'][] = $log_message;
				BackupPlus_Manipulation_Functions::error_log( $log_message );
				$backupplus->log( $log_message );
				/* translators: 1: Exception class, 2: Exception message. */
				$backupplus->log( sprintf( __( 'A PHP exception (%1$s) has occurred: %2$s', 'backupplus' ), get_class( $e ), $e->getMessage() ), 'warning-restore' );
			} catch ( Error $e ) {// phpcs:ignore PHPCompatibility.Classes.NewClasses.errorFound -- The Error class does not exist in PHP below 5.6.
				$log_message        = 'A PHP Fatal error (recoverable, ' . get_class( $e ) . ') occurred during the recursive search/replace. Exception message: Error message: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
				$report['errors'][] = $log_message;
				BackupPlus_Manipulation_Functions::error_log( $log_message );
				$backupplus->log( $log_message );
				/* translators: 1: Fatal error class, 2: Error message. */
				$backupplus->log( sprintf( __( 'A PHP fatal error (%1$s) has occurred: %2$s', 'backupplus' ), get_class( $e ), $e->getMessage() ), 'warning-restore' );
			}

			// Something was changed
			if ( $successful && $edited_data != $data_to_fix ) {
				++$report['change'];
				$update_sql[] = BackupPlus_SQL::fragment( '%i = ?', array( $column ), array( (string) $edited_data ) );
				$upd          = true;
			}

			if ( $primary_key ) {
				$where_sql[] = BackupPlus_SQL::fragment( '%i = ?', array( $column ), array( (string) $data_to_fix ) );
			}
		}

		if ( $upd && ! empty( $where_sql ) ) {
			$sql    = BackupPlus_SQL::fragment( 'UPDATE %i SET %l WHERE %w', array( $table, $update_sql, $where_sql ) );
			$result = $backupplus_restorer->sql_exec( $sql, 5, '', false );
			if ( false === $result || is_wp_error( $result ) ) {
				$last_error         = $this->print_error( (string) $sql, $backupplus_restorer->last_sql_error() );
				$report['errors'][] = $last_error;
			} else {
				++$report['updates'];
			}
		} elseif ( $upd ) {
			$report['errors'][] = sprintf( '"%s" has no primary key, manual change needed on row %s.', $table, $this->current_row );
			$backupplus->log(
				__( 'Error:', 'backupplus' ) . ' ' .
				/* translators: 1: Table name, 2: Row number requiring manual change. */
				sprintf( __( '"%1$s" has no primary key, manual change needed on row %2$s.', 'backupplus' ), $table, $this->current_row ),
				'warning-restore'
			);
		}

		return $report;
	}

	/**
	 * Take a serialised array and unserialise it replacing elements as needed and
	 * unserialising any subordinate arrays and performing the replace on those too.
	 * N.B. $from and $to can be arrays - they get passed only to str_replace(), which can take an array
	 *
	 * @param string $from            String we're looking to replace.
	 * @param string $to              What we want it to be replaced with
	 * @param array  $data            Used to pass any subordinate arrays back to in.
	 * @param bool   $serialised      Does the array passed via $data need serialising.
	 * @param int    $recursion_level Current recursion depth within the original data.
	 * @param array  $visited_data    Data that has been seen in previous recursion iterations.
	 *
	 * @return array    The original array with all elements replaced as needed.
	 */
	private function recursive_unserialize_replace( $from = '', $to = '', $data = '', $serialised = false, $recursion_level = 0, $visited_data = array() ) {

		global $backupplus;

		static $error_count = 0;

		// some unserialised data cannot be re-serialised eg. SimpleXMLElements
		try {
			$case_insensitive = false;

			// If we've reached the maximum recursion level, short circuit
			if ( 0 !== $this->max_recursion && $recursion_level >= $this->max_recursion ) {
				return $data;
			}

			if ( is_array( $data ) || is_object( $data ) ) {
				// If we've seen this exact object or array before, short circuit
				if ( in_array( $data, $visited_data, true ) ) {
					return $data; // Avoid infinite recursions when there's a circular reference
				}
				// Add this data to the list of
				$visited_data[] = $data;
			}

			if ( is_array( $from ) && is_array( $to ) ) {
				$case_insensitive = preg_match( '#^https?:#i', implode( $from ) ) && preg_match( '#^https?:#i', implode( $to ) ) ? true : false;
			} else {
				$case_insensitive = preg_match( '#^https?:#i', $from ) && preg_match( '#^https?:#i', $to ) ? true : false;
			}

			// O:8:"DateTime":0:{} : see https://bugs.php.net/bug.php?id=62852
			if ( is_serialized( $data ) && false === strpos( $data, 'O:8:"DateTime":0:{}' ) && false !== ( $unserialized = BackupPlus::unserialize( $data ) ) ) {
				$data = $this->recursive_unserialize_replace( $from, $to, $unserialized, true, $recursion_level + 1 );
			} elseif ( is_array( $data ) ) {
				$_tmp = array();
				foreach ( $data as $key => $value ) {
					$_tmp[ $key ] = $this->recursive_unserialize_replace( $from, $to, $value, false, $recursion_level + 1, $visited_data );
				}

				$data = $_tmp;
				unset( $_tmp );
			} elseif ( is_object( $data ) ) {
				$_tmp  = clone $data;
				$props = get_object_vars( $data );

				foreach ( $props as $key => $value ) {
					// Skip any representation of a protected property or integer property
					if ( ! preg_match( '/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $key ) ) {
						continue;
					}

					$_tmp->$key = $this->recursive_unserialize_replace( $from, $to, $value, false, $recursion_level + 1, $visited_data );
				}

				$data = $_tmp;
				unset( $_tmp );
			} elseif ( is_string( $data ) && ( null !== ( $_tmp = json_decode( $data, true ) ) ) ) {

				if ( is_array( $_tmp ) ) {
					foreach ( $_tmp as $key => $value ) {
						$_tmp[ $key ] = $this->recursive_unserialize_replace( $from, $to, $value, false, $recursion_level + 1, $visited_data );
					}

					$data = json_encode( $_tmp );
					unset( $_tmp );
				}
			} else {
				if ( is_string( $data ) ) {
					if ( $case_insensitive ) {
						$data = str_ireplace( $from, $to, $data );
					} else {
						$data = str_replace( $from, $to, $data );
					}
					// Below is the wrong approach. In fact, in the problematic case, the resolution is an extra search/replace to undo unnecessary ones
					// if (is_string($from)) {
					// $data = str_replace($from, $to, $data);
					// } else {
					// # Array. We only want a maximum of one replacement to take place. This is only an issue in non-default setups, but in those situations, carrying out all the search/replaces can be wrong. This is also why the most specific URL should be done first.
					// foreach ($from as $i => $f) {
					// $ndata = str_replace($f, $to[$i], $data);
					// if ($ndata != $data) {
					// $data = $ndata;
					// break;
					// }
					// }
					// }
				}
			}

			if ( $serialised ) {
				return serialize( $data );
			}
		} catch ( Exception $error ) {
			if ( 3 > $error_count ) {
				$log_message = 'PHP Fatal Exception error (' . get_class( $error ) . ') has occurred during recursive_unserialize_replace. Error Message: ' . $error->getMessage() . ' (Code: ' . $error->getCode() . ', line ' . $error->getLine() . ' in ' . $error->getFile() . ')';
				$backupplus->log( $log_message, 'warning-restore' );
				++$error_count;
			}
		}

		return $data;
	}

	/**
	 * This function will get the last database error and log it
	 *
	 * @param string      $sql_line   - the sql line that caused the error
	 * @param string|null $last_error - the error, when it is not the connection's last one
	 *
	 * @return void
	 */
	public function print_error( $sql_line, $last_error = null ) {
		global $wpdb, $backupplus;
		if ( null === $last_error ) {
			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_error -- direct mysqli function used for low-level database operations outside of $wpdb.
			$last_error = $this->use_wpdb ? $wpdb->last_error : mysqli_error( $this->mysql_dbh );
		}
		$backupplus->log( __( 'Error:', 'backupplus' ) . ' ' . $last_error . ' - ' . __( 'the database query being run was:', 'backupplus' ) . ' ' . $sql_line, 'warning-restore' );
		return $last_error;
	}
}
