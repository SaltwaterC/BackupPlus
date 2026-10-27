<?php

/** Dumping the database: the per-table loop, the dump file and its header. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Backup_Database {
	private $db_zstd_writer;

	/**
	 * This function is resumable, using the following method:
	 * Each table is written out to ($final_filename).table.tmp
	 * When the writing finishes, it is renamed to ($final_filename).table
	 * When all tables are finished, they are concatenated into the final file
	 *
	 * @param String $already_done Status of backup
	 * @param String $whichdb      Indicated which database is being backed up
	 * @param Array  $dbinfo       is only used when whichdb != 'wp'; and the keys should be: user, pass, name, host, prefix
	 *
	 * @return Boolean|String - the basename of the database backup, or false for failure
	 */
	public function backup_db( $already_done = 'begun', $whichdb = 'wp', $dbinfo = array() ) {

		global $backupplus, $wpdb;

		$this->whichdb        = $whichdb;
		$this->whichdb_suffix = ( 'wp' == $whichdb ) ? '' : $whichdb;

		if ( ! $backupplus->backup_time ) {
			$backupplus->backup_time_nonce();
		}
		if ( ! $backupplus->opened_log_time ) {
			$backupplus->logfile_open( $backupplus->nonce );
		}

		if ( 'wp' == $this->whichdb ) {
			$this->wpdb_obj = $wpdb;
			// The table prefix after being filtered - i.e. what filters what we'll actually backup
			$this->table_prefix = $backupplus->get_table_prefix( true );
			// The unfiltered table prefix - i.e. the real prefix that things are relative to
			$this->table_prefix_raw = $backupplus->get_table_prefix( false );
			$dbinfo['host']         = DB_HOST;
			$dbinfo['name']         = DB_NAME;
			$dbinfo['user']         = DB_USER;
			$dbinfo['pass']         = DB_PASSWORD;
		} else {
			if ( ! is_array( $dbinfo ) || empty( $dbinfo['host'] ) ) {
				return false;
			}
			// The methods that we may use: check_connection (WP>=3.9), get_results, get_row, query
			$this->wpdb_obj = new BackupPlus_WPDB_OtherDB( $dbinfo['user'], $dbinfo['pass'], $dbinfo['name'], $dbinfo['host'] );
			if ( ! empty( $this->wpdb_obj->error ) ) {
				$backupplus->log( $dbinfo['user'] . '@' . $dbinfo['host'] . '/' . $dbinfo['name'] . ' : database connection attempt failed' );
				$backupplus->log( $dbinfo['user'] . '@' . $dbinfo['host'] . '/' . $dbinfo['name'] . ' : ' . __( 'database connection attempt failed.', 'backupplus' ) . ' ' . __( 'Connection failed: check your access details, that the database server is up, and that the network connection is not firewalled.', 'backupplus' ), 'error' );
				return $backupplus->log_wp_error( $this->wpdb_obj->error );
			}
			$this->table_prefix     = isset( $dbinfo['prefix'] ) ? $dbinfo['prefix'] : '';
			$this->table_prefix_raw = $this->table_prefix;
		}

		$this->dbinfo = $dbinfo;
		$coverage     = $backupplus->jobdata_get( 'database_coverage', array() );
		$exact_tables = isset( $coverage[ $whichdb ] ) ? $coverage[ $whichdb ]['tables'] : null;

		do_action( 'backupplus_backup_db_begin', $whichdb, $dbinfo, $already_done, $this );

		BackupPlus_Database_Utility::set_sql_mode( array(), array( 'ANSI_QUOTES' ), $this->wpdb_obj );

		$errors = 0;

		$use_time         = apply_filters( 'backupplus_base_backup_timestamp', $backupplus->backup_time );
		$file_base        = $this->get_backup_file_basename_from_time( $use_time );
		$backup_file_base = $this->backupplus_dir . '/' . $file_base;

		$extension = $this->archive_compression_enabled() ? '.sql.zst' : '.gz';
		if ( 'finished' == $already_done ) {
			return basename( $backup_file_base ) . '-db' . ( ( 'wp' == $whichdb ) ? '' : $whichdb ) . $extension;
		}
		if ( 'encrypted' == $already_done ) {
			return basename( $backup_file_base ) . '-db' . ( ( 'wp' == $whichdb ) ? '' : $whichdb ) . $extension . '.crypt';
		}

		$backupplus->jobdata_set( 'jobstatus', 'dbcreating' . $this->whichdb_suffix );

		$binsqldump = $backupplus->find_working_sqldump();

		$total_tables = 0;

		// WP 3.9 onwards - https://core.trac.wordpress.org/browser/trunk/src/wp-includes/wp-db.php?rev=27925 - check_connection() allows us to get the database connection back if it had dropped
		if ( 'wp' == $whichdb && method_exists( $this->wpdb_obj, 'check_connection' ) && ( ! defined( 'BACKUPPLUS_SUPPRESS_CONNECTION_CHECKS' ) || ! BACKUPPLUS_SUPPRESS_CONNECTION_CHECKS ) ) {
			if ( ! $this->wpdb_obj->check_connection( false ) ) {
				BackupPlus_Job_Scheduler::reschedule( 60 );
				$backupplus->log( 'It seems the database went away; scheduling a resumption and terminating for now' );
				BackupPlus_Job_Scheduler::record_still_alive();
				die;
			}
		}

		// SHOW FULL - so that we get to know whether it's a BASE TABLE or a VIEW
		$all_tables = $this->wpdb_obj->get_results( 'SHOW FULL TABLES', ARRAY_N );

		if ( empty( $all_tables ) && ! empty( $this->wpdb_obj->last_error ) ) {
			$all_tables = $this->wpdb_obj->get_results( 'SHOW TABLES', ARRAY_N );
			$all_tables = array_map( array( $this, 'cb_get_name_base_type' ), $all_tables );
		} else {
			$all_tables = array_map( array( $this, 'cb_get_name_type' ), $all_tables );
		}

		if ( null !== $exact_tables ) {
			$missing = array_diff( $exact_tables, array_column( $all_tables, 'name' ) );
			if ( $missing ) {
				$backupplus->log( 'Selected tables no longer exist: ' . implode( ', ', $missing ), 'error' );
				return false;
			}
			$all_tables = array_values(
				array_filter(
					$all_tables,
					function ( $table ) use ( $exact_tables ) {
						return in_array( $table['name'], $exact_tables, true );
					}
				)
			);
		}

		// If this is not the WP database, then we do not consider it a fatal error if there are no tables
		if ( null === $exact_tables && 'wp' == $whichdb && 0 == count( $all_tables ) ) {
			$extra = ( $backupplus->newresumption_scheduled ) ? ' - ' . __( 'please wait for the rescheduled attempt', 'backupplus' ) : '';
			$backupplus->log( 'Error: No WordPress database tables found (SHOW TABLES returned nothing)' . $extra );
			$backupplus->log( __( 'No database tables found', 'backupplus' ) . $extra, 'error' );
			die;
		}

		// Put the options table first
		BackupPlus_Database_Utility::init( $this->whichdb, $this->table_prefix_raw, $this->wpdb_obj );
		usort( $all_tables, array( 'BackupPlus_Database_Utility', 'backup_db_sorttables' ) );

		$all_table_names = array_map( array( $this, 'cb_get_name' ), $all_tables );

		if ( ! BackupPlus_Filesystem_Functions::really_is_writable( $this->backupplus_dir ) ) {
			$backupplus->log( 'The backup directory (' . $this->backupplus_dir . ') could not be written to (could be account/disk space full, or wrong permissions).' );
			$backupplus->log( $this->backupplus_dir . ': ' . __( 'The backup directory is not writable (or disk space is full) - the database backup is expected to shortly fail.', 'backupplus' ), 'warning' );
			// Why not just fail now? We saw a bizarre case when the results of really_is_writable() changed during the run.
		}

		// This check doesn't strictly get all possible duplicates; it's only designed for the case that can happen when moving between deprecated Windows setups and Linux
		$this->duplicate_tables_exist = false;
		foreach ( $all_table_names as $table ) {
			if ( strtolower( $table ) != $table && in_array( strtolower( $table ), $all_table_names ) ) {
				$this->duplicate_tables_exist = true;
				$backupplus->log( "Tables with names differing only based on case-sensitivity exist in the MySQL database: $table / " . strtolower( $table ) );
			}
		}
		$how_many_tables = count( $all_tables );

		$stitch_files        = array();
		$found_options_table = false;
		$is_multisite        = is_multisite();

		$anonymisation_options = $backupplus->jobdata_get( 'anonymisation_options', array() );

		if ( ! empty( $anonymisation_options ) ) {
			$backupplus->log( 'Anonymisation options have been set, so mysqldump (which does not support them) will be disabled.' );
		}

		// Gather the list of files that look like partial table files once only
		$potential_stitch_files = array();
		$table_file_prefix_base = $file_base . '-db' . $this->whichdb_suffix . '-table-';
		if ( false !== ( $dir_handle = opendir( $this->backupplus_dir ) ) ) {
			while ( false !== ( $e = readdir( $dir_handle ) ) ) {
				// The 'r' in 'tmpr' indicates that the new scheme is being used. N.B. That does *not* imply that the table has a usable primary key.
				if ( ! is_file( $this->backupplus_dir . '/' . $e ) ) {
					continue;
				}
				if ( preg_match( '#' . $table_file_prefix_base . '.*\.table\.tmpr?(\d+)\.gz$#', $e, $matches ) ) {
					// We need to stich them in order
					$potential_stitch_files[] = $e;
				}
			}
		} else {
			$backupplus->log( 'Error: Failed to open directory for reading' );
			$backupplus->log( __( 'Failed to open directory for reading:', 'backupplus' ) . ' ' . $this->backupplus_dir, 'error' );
		}

		$errors_at_all_tables_start = $backupplus->error_count();

		foreach ( $all_tables as $ti ) {

			$table                  = $ti['name'];
			$stitch_files[ $table ] = array();
			$table_type             = $ti['type'];
			$errors_at_table_start  = $backupplus->error_count();

			$this->many_rows_warning = false;
			++$total_tables;

			// Increase script execution time-limit to 15 min for every table.
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( BACKUPPLUS_SET_TIME_LIMIT );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			}           // The table file may already exist if we have produced it on a previous run
			$table_file_prefix = $file_base . '-db' . $this->whichdb_suffix . '-table-' . $table . '.table';

			if ( 'wp' == $whichdb && ( strtolower( $this->table_prefix_raw . 'options' ) == strtolower( $table ) || ( $is_multisite && ( strtolower( $this->table_prefix_raw . 'sitemeta' ) == strtolower( $table ) || strtolower( $this->table_prefix_raw . '1_options' ) == strtolower( $table ) ) ) ) ) {
				$found_options_table = true;
			}

			// Already finished?
			if ( file_exists( $this->backupplus_dir . '/' . $table_file_prefix . '.gz' ) ) {
				$stitched   = count( $stitch_files, COUNT_RECURSIVE );
				$skip_dblog = ( ( $stitched > 10 && 0 != $stitched % 20 ) || ( $stitched > 100 && 0 != $stitched % 100 ) );
				$backupplus->log( "Table $table: corresponding file already exists; moving on", 'notice', false, $skip_dblog );

				$max_record = false;
				foreach ( $potential_stitch_files as $e ) {
					// The 'r' in 'tmpr' indicates that the new scheme is being used. N.B. That does *not* imply that the table has a usable primary key.
					if ( preg_match( '#' . $table_file_prefix . '\.tmpr?(\d+)\.gz$#', $e, $matches ) ) {
						// We need to stich them in order
						$stitch_files[ $table ][ $matches[1] ] = $e;
						if ( false === $max_record || $matches[1] > $max_record ) {
							$max_record = $matches[1];
						}
					}
				}
				$stitch_files[ $table ][ $max_record + 1 ] = $table_file_prefix . '.gz';

				// Move on to the next table
				continue;
			}

			// === is needed with strpos/stripos, otherwise 'false' matches (i.e. prefix does not match)
			if ( null !== $exact_tables || empty( $this->table_prefix ) || ( ! $this->duplicate_tables_exist && 0 === stripos( $table, $this->table_prefix ) ) || ( $this->duplicate_tables_exist && 0 === strpos( $table, $this->table_prefix ) ) ) {

				// Skip table due to filter?
				if ( null === $exact_tables && ! apply_filters( 'backupplus_backup_table', true, $table, $this->table_prefix, $whichdb, $dbinfo ) ) {
					$backupplus->log( "Skipping table (filtered): $table" );
					if ( empty( $this->skipped_tables ) ) {
						$this->skipped_tables = array();
					}

					// whichdb could be an int in which case to get the name of the database and the array key use the name from dbinfo
					$key = ( 'wp' === $whichdb ) ? 'wp' : $dbinfo['name'];

					if ( empty( $this->skipped_tables[ $key ] ) ) {
						$this->skipped_tables[ $key ] = array();
					}
					$this->skipped_tables[ $key ][] = $table;

					--$total_tables;
					continue;
				}

				add_filter( 'backupplus_backup_table_sql_where', array( $this, 'backup_exclude_jobdata' ), 10, 3 );

				$backupplus->jobdata_set(
					'dbcreating_substatus',
					array(
						't' => $table,
						'i' => $total_tables,
						'a' => $how_many_tables,
					)
				);

				// .tmp.gz is the current temporary file. When the row limit has been reached, it is moved to .tmp1.gz, .tmp2.gz, etc. (depending on which already exist). When we're all done, then they all get stitched in.

				$db_temp_file = $this->backupplus_dir . '/' . $table_file_prefix . '.tmp.gz';
				$backupplus->check_recent_modification( $db_temp_file );

				// Open file, store the handle
				if ( false === $this->backup_db_open( $db_temp_file, true ) ) {
					return false;
				}

				$table_status = $this->sql()->row( 'SELECT TABLE_ROWS AS `Rows` FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', array(), array( $table ) );

				// Create the preceding SQL statements for the table
				$this->stow( '# ' . sprintf( 'Table: %s', $this->comment_name( $table ) ) . "\n" );

				// Meaning: false = don't yet know; true = know and have logged it; integer = the expected number
				$this->expected_rows = false;

				if ( isset( $table_status->Rows ) ) {
					$this->expected_rows = $table_status->Rows;
				}

				// If no check-in last time, then we could in future try the other method (but - any point in retrying slow method on large tables??)

				// New Jul 2014: This attempt to use bindump instead at a lower threshold is quite conservative - only if the last successful run was exactly two resumptions ago - may be useful to expand
				$bindump_threshold = ( ! $backupplus->something_useful_happened && ! empty( $backupplus->current_resumption ) && ( 2 == $backupplus->current_resumption - $backupplus->last_successful_resumption ) ) ? 1000 : 8000;

				if ( isset( $table_status->Rows ) && ( $table_status->Rows > $bindump_threshold || ( defined( 'BACKUPPLUS_ALWAYS_TRY_MYSQLDUMP' ) && BACKUPPLUS_ALWAYS_TRY_MYSQLDUMP ) ) && is_string( $binsqldump ) && empty( $anonymisation_options ) ) {
					if ( ! is_bool( $this->expected_rows ) ) {
						$this->log_expected_rows( $table, $this->expected_rows );
						$this->expected_rows = true;
					}
					$bindump = $this->backup_table_bindump( $binsqldump, $table );
				} else {
					$bindump = false;
				}

				// Means "start of table". N.B. The meaning of an integer depends upon whether the table has a usable primary key or not.
				$start_record        = true;
				$can_use_primary_key = apply_filters( 'backupplus_can_use_primary_key_default', true, $table );
				foreach ( $potential_stitch_files as $e ) {
					// The 'r' in 'tmpr' indicates that the new scheme is being used. N.B. That does *not* imply that the table has a usable primary key.
					if ( preg_match( '#' . $table_file_prefix . '\.tmp(r)?(\d+)\.gz$#', $e, $matches ) ) {
						$stitch_files[ $table ][ $matches[2] ] = $e;
						if ( true === $start_record || $matches[2] > $start_record ) {
							$start_record = $matches[2];
						}
						// Legacy scheme. The purpose of this is to prevent backups failing if one is in progress during an upgrade to a new version that implements the new scheme
						if ( 'r' !== $matches[1] ) {
							$can_use_primary_key = false;
						}
					}
				}

				// Legacy file-naming scheme in use
				if ( false === $can_use_primary_key && true !== $start_record ) {
					$start_record = ( $start_record + 100 ) * 1000;
				}

				if ( true !== $bindump ) {

					while ( ! is_array( $start_record ) && ! is_wp_error( $start_record ) ) {
						$start_record = $this->backup_table( $table, $table_type, $start_record, $can_use_primary_key );
						if ( is_integer( $start_record ) || is_array( $start_record ) ) {

							$this->backup_db_close();

							// Add one here in case no records were returned - don't want to over-write the previous file
							$use_record = is_array( $start_record ) ? ( isset( $start_record['next_record'] ) ? $start_record['next_record'] + 1 : false ) : $start_record;
							if ( ! $can_use_primary_key ) {
								$use_record = ( ceil( $use_record / 100000 ) - 1 ) * 100;
							}

							if ( false !== $use_record ) {
								// N.B. Renaming using the *next* record is intentional - it allows UD to know where to resume from.
								$rename_base = $table_file_prefix . '.tmp' . ( $can_use_primary_key ? 'r' : '' ) . $use_record . '.gz';

								rename( $db_temp_file, $this->backupplus_dir . '/' . $rename_base );
								$stitch_files[ $table ][ $use_record ] = $rename_base;
							} elseif ( is_array( $start_record ) && 'view' == strtolower( $table_type ) ) {
								$rename_base = $table_file_prefix . '-view.tmp.gz';
								rename( $db_temp_file, $this->backupplus_dir . '/' . $rename_base );
								$stitch_files[ $table ][] = $rename_base;
							}

							BackupPlus_Job_Scheduler::something_useful_happened();

							if ( false === $this->backup_db_open( $db_temp_file, true ) ) {
								return false;
							}
						} elseif ( is_wp_error( $start_record ) ) {
							$message = "Error (table=$table, type=$table_type) (" . $start_record->get_error_code() . '): ' . $start_record->get_error_message();
							$backupplus->log( $message );
							// If it's a view, then the problem isn't recoverable; but views don't contain actual data except in the definition, which is likely in code, so we should not consider this a fatal error
							$level = 'error';
							if ( 'view' == strtolower( $table_type ) && 'table_details_error' == $start_record->get_error_code() ) {
								$level = 'warning';
								$this->stow( "# $message\n" );
							}
							$backupplus->log( __( 'Failed to backup database table:', 'backupplus' ) . ' ' . $start_record->get_error_message() . ' (' . $start_record->get_error_code() . ')', $level );
						}
					}
				}

				// If we got this far, then there were enough resources; the warning can be removed
				if ( ! empty( $this->many_rows_warning ) ) {
					$backupplus->log_remove_warning( 'manyrows_' . $this->whichdb_suffix . $table );
				}

				$this->backup_db_close();

				if ( $backupplus->error_count() > $errors_at_table_start ) {

					$backupplus->log( 'Errors occurred during backing up the table; therefore the open file will be removed' );
					@unlink( $db_temp_file ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.

				} else {

					// Renaming the file indicates that writing to it finished
					rename( $db_temp_file, $this->backupplus_dir . '/' . $table_file_prefix . '.gz' );
					BackupPlus_Job_Scheduler::something_useful_happened();

					$final_stitch_value = empty( $stitch_files[ $table ] ) ? 1 : max( array_keys( $stitch_files[ $table ] ) ) + 1;

					$stitch_files[ $table ][ $final_stitch_value ] = $table_file_prefix . '.gz';

					$total_db_size = 0;
					// This is more verbose than it would be if we weren't supporting PHP 5.2
					foreach ( $stitch_files[ $table ] as $basename ) {
						$total_db_size += filesize( $this->backupplus_dir . '/' . $basename );
					}

					$backupplus->log( "Table $table: finishing file(s) (" . count( $stitch_files[ $table ] ) . ', ' . round( $total_db_size / 1024, 1 ) . ' KB)', 'notice', false, false );
				}
			} else {
				--$total_tables;
				$backupplus->log( 'Skipping table (lacks our prefix (' . $this->table_prefix . ")): $table" );
				if ( empty( $this->skipped_tables ) ) {
					$this->skipped_tables = array();
				}
				// whichdb could be an int in which case to get the name of the database and the array key use the name from dbinfo
				$key = ( 'wp' === $whichdb ) ? 'wp' : $dbinfo['name'];
				if ( empty( $this->skipped_tables[ $key ] ) ) {
					$this->skipped_tables[ $key ] = array();
				}
				$this->skipped_tables[ $key ][] = $table;
			}
		}

		if ( 'wp' == $whichdb ) {
			if ( ! $found_options_table && ( ! isset( $coverage[ $whichdb ] ) || 'selected' !== $coverage[ $whichdb ]['mode'] ) ) {
				if ( $is_multisite ) {
					$backupplus->log( __( 'The database backup has failed', 'backupplus' ) . ' - ' . __( 'no options or sitemeta table was found', 'backupplus' ), 'warning', 'optstablenotfound' );
				} else {
					$backupplus->log( __( 'The database backup has failed', 'backupplus' ) . ' - ' . __( 'the options table was not found', 'backupplus' ), 'warning', 'optstablenotfound' );
				}
				$time_this_run = time() - $backupplus->opened_log_time;
				if ( $time_this_run > 2000 ) {
					// Have seen this happen; not sure how, but it was apparently deterministic; if the current process had been running for a long time, then apparently all database commands silently failed.
					// If we have been running that long, then the resumption may be far off; bring it closer
					BackupPlus_Job_Scheduler::reschedule( 60 );
					$backupplus->log( 'Have been running very long, and it seems the database went away; scheduling a resumption and terminating for now' );
					BackupPlus_Job_Scheduler::record_still_alive();
					die;
				}
			} else {
				$backupplus->log_remove_warning( 'optstablenotfound' );
			}
		}

		if ( $backupplus->error_count() > $errors_at_all_tables_start ) {
			$backupplus->log( 'Errors occurred whilst backing up the tables; will cease and wait for resumption' );
			die;
		}

		// Race detection - with zip files now being resumable, these can more easily occur, with two running side-by-side
		$backup_final_file_name = $backup_file_base . '-db' . $this->whichdb_suffix . $extension;
		$time_now               = time();
		$time_mod               = (int) @filemtime( $backup_final_file_name );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		if ( file_exists( $backup_final_file_name ) && $time_mod > 100 && ( $time_now - $time_mod ) < 30 ) {
			BackupPlus_Job_Scheduler::terminate_due_to_activity( $backup_final_file_name, $time_now, $time_mod );
		}

		if ( file_exists( $backup_final_file_name ) ) {
			$backupplus->log( "The final database file ($backup_final_file_name) exists, but was not modified within the last 30 seconds (time_mod=$time_mod, time_now=$time_now, diff=" . ( $time_now - $time_mod ) . '). Thus we assume that another BackupPlus terminated; thus we will continue.' );
		}

		// Finally, stitch the files together
		if ( ! function_exists( 'gzopen' ) ) {
			$backupplus->log( 'PHP function is disabled; abort expected: gzopen()' );
		}

		$decompress_mode = $this->archive_compression_enabled() || ( defined( 'BACKUPPLUS_DB_STICH_DECOMPRESS' ) && BACKUPPLUS_DB_STICH_DECOMPRESS );

		if ( false === $this->backup_db_open( $backup_final_file_name, true ) ) {
			return false;
		}

		$this->backup_db_header();

		// Re-open in plain binary append mode
		if ( ! $decompress_mode ) {
			$this->backup_db_close();
			if ( false === $this->backup_db_open( $backup_final_file_name, false, true ) ) {
				return false;
			}
		}

		// We delay the unlinking because if two runs go concurrently and fail to detect each other (should not happen, but there's no harm in assuming the detection failed) then that would lead to files missing from the db dump
		$unlink_files = array();

		$sind = 1;

		// Happily they have the same syntax (as far as we need it)
		// Concatenating gz files produces a valid gz file. So, decompressing is not necessary. We retain the possibility for debugging and the possibility of broken implementations.
		$open_function  = $decompress_mode ? 'gzopen' : 'fopen';
		$fgets_function = $decompress_mode ? 'gzgets' : 'fgets';
		$close_function = $decompress_mode ? 'gzclose' : 'fclose';

		foreach ( $stitch_files as $table => $table_stitch_files ) {
			ksort( $table_stitch_files );
			foreach ( $table_stitch_files as $table_file ) {
				$backupplus->log( "{$table_file} ($sind/$how_many_tables/$open_function): adding to final database dump" );

				if ( filesize( $this->backupplus_dir . '/' . $table_file ) < 27 && '.gz' == substr( $table_file, -3, 3 ) ) {
					// It's a null gzip file. Don't waste time on gzopen/gzgets/gzclose. This micro-optimisation was added after seeing a site with >3000 files that was running out of time (it could apparently process 30 files/second)
					$unlink_files[] = $this->backupplus_dir . '/' . $table_file;
				} elseif ( ! $handle = call_user_func( $open_function, $this->backupplus_dir . '/' . $table_file, 'r' ) ) {
					$backupplus->log( "Error: Failed to open database file for reading: {$table_file}" );
					$backupplus->log( __( 'Failed to open database file for reading:', 'backupplus' ) . ' ' . $table_file, 'error' );
					++$errors;
				} else {
					while ( $line = call_user_func( $fgets_function, $handle, 65536 ) ) {
						$this->stow( $line );
					}
					call_user_func( $close_function, $handle );
					$unlink_files[] = $this->backupplus_dir . '/' . $table_file;
				}
				++$sind;
				// Came across a database with 7600 tables... adding them all took over 500 seconds; and so when the resumption started up, no activity was detected
				if ( 0 == $sind % 100 ) {
					BackupPlus_Job_Scheduler::something_useful_happened();
				}
			}
		}

		// Re-open in gz append mode
		if ( ! $decompress_mode ) {
			$this->backup_db_close();
			if ( false === $this->backup_db_open( $backup_final_file_name, true, true ) ) {
				return false;
			}
		}

		// DB triggers
		if ( $this->wpdb_obj->get_results( 'SHOW TRIGGERS' ) ) {
			// N.B. DELIMITER is not a valid SQL command; you cannot pass it to the server. It has to be interpreted by the interpreter - e.g. /usr/bin/mysql, or BackupPlus, and used to interpret what follows. The effect of this is that using it means that some SQL clients will stumble; but, on the other hand, failure to use it means that others that don't have special support for CREATE TRIGGER may stumble, because they may feed incomplete statements to the SQL server. Since /usr/bin/mysql uses it, we choose to support it too (both reading and writing).
			// Whatever the delimiter is set to needs to be used in the DROP TRIGGER and CREATE TRIGGER commands in this section further down.
			$this->stow( "DELIMITER ;;\n\n" );
			$all_triggers    = $this->sql()->rows( 'SHOW TRIGGERS', array(), array(), ARRAY_A );
			$trigger_moments = array(
				'BEFORE' => array(
					'INSERT' => BackupPlus_SQL::fragment( 'BEFORE INSERT' ),
					'UPDATE' => BackupPlus_SQL::fragment( 'BEFORE UPDATE' ),
					'DELETE' => BackupPlus_SQL::fragment( 'BEFORE DELETE' ),
				),
				'AFTER'  => array(
					'INSERT' => BackupPlus_SQL::fragment( 'AFTER INSERT' ),
					'UPDATE' => BackupPlus_SQL::fragment( 'AFTER UPDATE' ),
					'DELETE' => BackupPlus_SQL::fragment( 'AFTER DELETE' ),
				),
			);
			foreach ( $all_tables as $ti ) {
				$table = $ti['name'];
				if ( ! empty( $this->skipped_tables ) ) {
					if ( 'wp' == $this->whichdb ) {
						if ( in_array( $table, $this->skipped_tables['wp'] ) ) {
							continue;
						}
					} elseif ( isset( $this->skipped_tables[ $this->dbinfo['name'] ] ) ) {
						if ( in_array( $table, $this->skipped_tables[ $this->dbinfo['name'] ] ) ) {
							continue;
						}
					}
				}
				// Matched exactly: SHOW TRIGGERS LIKE treated "_" and "%" in the table name as wildcards.
				$table_triggers = array_filter(
					is_array( $all_triggers ) ? $all_triggers : array(),
					function ( $trigger ) use ( $table ) {
						return $trigger['Table'] === $table;
					}
				);
				if ( $table_triggers ) {
					$this->stow( "\n\n# Triggers of  " . $this->comment_name( $table ) . "\n\n" );
					foreach ( $table_triggers as $trigger ) {
						$trigger_name      = $trigger['Trigger'];
						$trigger_time      = $trigger['Timing'];
						$trigger_event     = $trigger['Event'];
						$trigger_statement = $trigger['Statement'];
						if ( ! isset( $trigger_moments[ $trigger_time ][ $trigger_event ] ) ) {
							$backupplus->log( "Skipping trigger $trigger_name of table $table: unrecognised timing ($trigger_time) or event ($trigger_event)", 'warning' );
							continue;
						}
						$this->stow( BackupPlus_SQL::render( 'DROP TRIGGER IF EXISTS %i;;', array( $trigger_name ) ) . "\n" );
						// The trigger body is the server's own SQL text, copied into the dump
						$this->stow( BackupPlus_SQL::render( 'CREATE TRIGGER %i %f ON %i FOR EACH ROW ', array( $trigger_name, $trigger_moments[ $trigger_time ][ $trigger_event ], $table ) ) . $trigger_statement . ";;\n\n" );
					}
				}
			}
			$this->stow( "DELIMITER ;\n\n" );
		}

		// DB Stored Routines
		$stored_routines = isset( $coverage[ $whichdb ] ) && 'selected' === $coverage[ $whichdb ]['mode'] ? array() : BackupPlus_Database_Utility::get_stored_routines( $this->wpdb_obj, $this->dbinfo['name'] );
		if ( is_array( $stored_routines ) && ! empty( $stored_routines ) ) {
			$stored_routines_log               = $inaccessible_routines = '';
			$stored_routines_delimiter_printed = false;
			$backupplus->log( "Dumping routines for database {$this->dbinfo['name']}" );
			foreach ( $stored_routines as $routine ) {
				if ( empty( $routine[ 'Create ' . ucfirst( strtolower( $routine['Type'] ) ) ] ) ) { // The value displayed for the Create Procedure or Create Function field is NULL if the WP database user has only CREATE ROUTINE, ALTER ROUTINE, and/or EXECUTE privileges. @see https://dev.mysql.com/doc/refman/8.0/en/show-create-procedure.html
					$inaccessible_routines = $inaccessible_routines ? $inaccessible_routines . ', ' . $routine['Name'] : $routine['Name'];
					continue;
				}
				$stored_routines_log = "Dumping routine: {$routine['Name']}";
				if ( ! $stored_routines_delimiter_printed ) {
					$this->stow( "\n\n# Dumping routines for database " . $this->comment_name( $this->dbinfo['name'] ) . "\n\n" );
					$this->stow( "DELIMITER ;;\n\n" );
					$stored_routines_delimiter_printed = true;
				}
				if ( 'FUNCTION' === $routine['Type'] ) {
					$this->stow( BackupPlus_SQL::render( 'DROP FUNCTION IF EXISTS %i;;', array( $routine['Name'] ) ) . "\n\n" );
				} else {
					$this->stow( BackupPlus_SQL::render( 'DROP PROCEDURE IF EXISTS %i;;', array( $routine['Name'] ) ) . "\n\n" );
				}
				$this->stow( $routine[ 'Create ' . ucfirst( strtolower( $routine['Type'] ) ) ] . "\n\n;;\n\n" );
				$backupplus->log( $stored_routines_log . ' - (Successful)' );
			}
			if ( $inaccessible_routines ) {
				$backupplus->log( __( 'Dumping routines: ', 'backupplus' ) . "({$inaccessible_routines})" . ' - (' . __( 'Failed', 'backupplus' ) . ' - ' . __( "Your WordPress database user doesn't have sufficient privileges to read these stored routines.", 'backupplus' ) . ' ' . __( 'To be able to backup the routines, you must be the user named as the routine DEFINER(s), have the SHOW_ROUTINE privilege (for MySQL 8.0.20+ users), have the SELECT privilege at the global level, or have the CREATE ROUTINE, ALTER ROUTINE, or EXECUTE privilege granted at a scope that includes the routines.', 'backupplus' ) . ')', 'warning' );
			}
			if ( $stored_routines_delimiter_printed ) {
				$this->stow( "DELIMITER ;\n\n" );
			}
		} elseif ( is_wp_error( $stored_routines ) ) {
			$backupplus->log( $stored_routines->get_error_message() );
		}

		$this->stow( "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n" );
		$this->stow( "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n" );
		$this->stow( "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n" );
		$this->stow( "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n" );

		if ( ! $this->backup_db_close() ) {
			$backupplus->log( 'An error occurred whilst closing the final database file' );
			$backupplus->log( __( 'An error occurred whilst closing the final database file', 'backupplus' ), 'error' );
			++$errors;
		}

		$backupplus->log( basename( $backup_final_file_name ) . ': finished writing out complete database file (' . round( filesize( $backup_final_file_name ) / 1024, 1 ) . ' KB)' );

		foreach ( $unlink_files as $unlink_file ) {
			@unlink( $unlink_file );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
		}

		if ( $errors > 0 ) {
			return false;
		}

		// We no longer encrypt here - because the operation can take long, we made it resumable and moved it to the upload loop
		$backupplus->jobdata_set( 'jobstatus', 'dbcreated' . $this->whichdb_suffix );

		$checksums = $backupplus->which_checksums();

		$checksum_description = '';

		foreach ( $checksums as $checksum ) {

			$cksum = hash_file( $checksum, $backup_final_file_name );
			$backupplus->jobdata_set( $checksum . '-db' . ( ( 'wp' == $whichdb ) ? '0' : $whichdb . '0' ), $cksum );
			if ( $checksum_description ) {
				$checksum_description .= ', ';
			}
			$checksum_description .= "$checksum: $cksum";

		}

		$backupplus->log( "Total database tables backed up: $total_tables (" . basename( $backup_final_file_name ) . ', size: ' . filesize( $backup_final_file_name ) . ", $checksum_description)" );

		return basename( $backup_final_file_name );
	}

	/**
	 * Log the number of expected rows (both to the backup log, and database backup file)
	 *
	 * @param String  $table         - table name
	 * @param Integer $expected_rows - number of rows
	 * @param Boolean $via_count     - if the expected number comes via a SELECT COUNT() call
	 * @param Boolean $log_in_backup - whether to log in the backup file (not just the backup log file)
	 */
	private function log_expected_rows( $table, $expected_rows, $via_count = false, $log_in_backup = true ) {
		global $backupplus;
		$description = $via_count ? 'via COUNT' : 'approximate';
		$backupplus->log( "Table $table: Total expected rows ($description): " . $expected_rows );
		if ( $log_in_backup ) {
			$this->stow( "# Approximate rows expected in table: $expected_rows\n" );
		}
		if ( $expected_rows > BACKUPPLUS_WARN_DB_ROWS ) {
			$this->many_rows_warning = true;
			$backupplus->log(
				sprintf(
					/* translators: 1: Table name, 2: Row count */
					__( 'Table %1$s has very many rows (%2$s) - we hope your web hosting company gives you enough resources to dump out that table in the backup.', 'backupplus' ),
					$table,
					$expected_rows
				) . ' ' .
				__( 'If not, you will need to either remove data from this table, or contact your hosting company to request more resources.', 'backupplus' ),
				'warning',
				'manyrows_' . $this->whichdb_suffix . $table
			);
		}
	}

	/**
	 * This function will return a SQL WHERE clause to exclude BackupPlus job data
	 *
	 * @param array  $where - an array of where clauses to add to
	 * @param string $table - the table we want to add a where clause for
	 *
	 * @return array - returns an array of where clauses for the table
	 */
	public function backup_exclude_jobdata( $where, $table ) {
		// Don't include the job data for any backups - so that when the database is restored, it doesn't continue an apparently incomplete backup
		global $backupplus;
		$table_prefix = $backupplus->get_table_prefix( false ); // or we can just use $this->table_prefix_raw ??
		if ( 'wp' == $this->whichdb && ( ! empty( $table_prefix ) && strtolower( $table_prefix . 'sitemeta' ) == strtolower( $table ) ) ) {
			$where[] = BackupPlus_SQL::fragment( "meta_key NOT LIKE 'backupplus_jobdata_%' AND meta_key NOT LIKE 'backupplus_jobpart_%'" );
		} elseif ( 'wp' == $this->whichdb && ( ! empty( $table_prefix ) && strtolower( $table_prefix . 'options' ) == strtolower( $table ) ) ) {
			$where[] = BackupPlus_SQL::fragment( "option_name NOT LIKE 'backupplus_jobdata_%' AND option_name NOT LIKE 'backupplus_jobpart_%' AND option_name NOT LIKE '_site_transient_update_%'" );
		}

		return $where;
	}

	/**
	 * Convert hexadecimal (base16) number into binary (base2) and no need to worry about the platform-dependent of 32bit/64bit size limitation
	 *
	 * @param String $hex Hexadecimal number
	 * @return String a base2 format of the given hexadecimal number
	 */
	private function hex2bin( $hex ) {
		$table = array(
			'0' => '0000',
			'1' => '0001',
			'2' => '0010',
			'3' => '0011',
			'4' => '0100',
			'5' => '0101',
			'6' => '0110',
			'7' => '0111',
			'8' => '1000',
			'9' => '1001',
			'a' => '1010',
			'b' => '1011',
			'c' => '1100',
			'd' => '1101',
			'e' => '1110',
			'f' => '1111',
		);
		$bin   = '';

		if ( ! preg_match( '/^[0-9a-f]+$/i', $hex ) ) {
			return '';
		}

		for ( $i = 0; $i < strlen( $hex ); $i++ ) {
			$bin .= $table[ strtolower( substr( $hex, $i, 1 ) ) ];
		}

		return $bin;
	}

	/**
	 * Close the database file currently being written
	 *
	 * @return Boolean
	 */
	private function backup_db_close() {
		if ( $this->db_zstd_writer ) {
			try { return $this->db_zstd_writer->finish(); }
			finally { $this->db_zstd_writer = null; }
		}
		return $this->dbhandle_isgz ? gzclose( $this->dbhandle ) : fclose( $this->dbhandle );
	}

	/**
	 * Open a file, store its file handle, and reset the class variable db_current_raw_bytes back to zero.
	 *
	 * @param String  $file     Full path to the file to open
	 * @param Boolean $allow_gz Use gzopen() if available, instead of fopen()
	 * @param Boolean $append   Use append mode for writing
	 *
	 * @return Resource|Boolean - the opened file handle, or false for an error
	 */
	public function backup_db_open( $file, $allow_gz = true, $append = false ) {
		$mode = $append ? 'ab' : 'w';
		if ( $allow_gz && preg_match( '/\.sql\.zst$/D', $file ) ) {
			$this->db_zstd_writer = new BackupPlus_Zstd_Writer( $file );
			$this->dbhandle       = $this->db_zstd_writer->handle;
			$this->dbhandle_isgz  = false;
		} elseif ( $allow_gz && function_exists( 'gzopen' ) ) {
			$this->dbhandle      = gzopen( $file, $mode );
			$this->dbhandle_isgz = true;
		} else {
			$this->dbhandle      = fopen( $file, $mode );
			$this->dbhandle_isgz = false;
		}
		if ( false === $this->dbhandle ) {
			global $backupplus;
			$backupplus->log( "ERROR: $file: Could not open the backup file for writing (mode: $mode)" );
			$backupplus->log( $file . ': ' . __( 'Could not open the backup file for writing', 'backupplus' ), 'error' );
		}
		$this->db_current_raw_bytes = 0;
		return $this->dbhandle;
	}

	/**
	 * Adds a line to the database backup
	 *
	 * @param String $write_line - the line to write
	 *
	 * @return Integer|Boolean - the number of octets written, or false for a failure (as returned by gzwrite() / fwrite)
	 */
	private function stow( $write_line ) {

		if ( '' === $write_line ) {
			return 0;
		}

		if ( $this->db_zstd_writer ) { BackupPlus_Archive_IO::write_all( $this->dbhandle, $write_line );
			$this->db_current_raw_bytes += strlen( $write_line );
			return strlen( $write_line ); }
		$write_function = $this->dbhandle_isgz ? 'gzwrite' : 'fwrite';

		if ( false == ( $ret = call_user_func( $write_function, $this->dbhandle, $write_line ) ) ) {
			$this->log_with_db_occasionally( "There was an error writing a line to the backup file: $write_line" );
		}

		$this->db_current_raw_bytes += strlen( $write_line );

		return $ret;
	}

	/**
	 * Stow the database backup header
	 */
	private function backup_db_header() {

		global $backupplus;
		$coverage = $backupplus->jobdata_get( 'database_coverage', array() );
		if ( isset( $coverage[ $this->whichdb ] ) ) {
			$this->stow( '# BackupPlus database coverage: ' . json_encode( $coverage[ $this->whichdb ] ) . "\n" );
		}

		$wp_version    = $backupplus->get_wordpress_version();
		$mysql_version = $this->wpdb_obj->get_var( 'SELECT VERSION()' );
		if ( '' == $mysql_version ) {
			$mysql_version = $this->wpdb_obj->db_version();
		}
		$server_software = BackupPlus_Manipulation_Functions::fetch_superglobal( 'server', 'SERVER_SOFTWARE', '' );

		if ( 'wp' == $this->whichdb ) {
			$wp_upload_dir = wp_upload_dir();
			$this->stow( "# WordPress MySQL database backup\n" );
			$this->stow( '# Created by BackupPlus version ' . $backupplus->version . "\n" );
			$this->stow( "# WordPress Version: $wp_version, running on PHP " . phpversion() . ' (' . $server_software . "), MySQL $mysql_version\n" );
			$this->stow( '# Backup of: ' . untrailingslashit( site_url() ) . "\n" );
			$this->stow( '# Home URL: ' . untrailingslashit( home_url() ) . "\n" );
			$this->stow( '# Content URL: ' . untrailingslashit( content_url() ) . "\n" );
			$this->stow( '# Uploads URL: ' . untrailingslashit( $wp_upload_dir['baseurl'] ) . "\n" );
			$this->stow( '# Table prefix: ' . $this->table_prefix_raw . "\n" );
			$this->stow( '# Filtered table prefix: ' . $this->table_prefix . "\n" );
			$this->stow( '# ABSPATH: ' . trailingslashit( ABSPATH ) . "\n" );
			$this->stow( '# BackupPlus plugin slug: ' . BACKUPPLUS_PLUGIN_SLUG . "\n" );
			$this->stow( '# Site info: multisite=' . ( is_multisite() ? '1' : '0' ) . "\n" );

			if ( is_multisite() ) {
				// we're going to use the fallback function wp_get_sites (for older version).
				if ( function_exists( 'get_sites' ) && class_exists( 'WP_Site_Query' ) ) {
					$network_sites = get_sites();
				} elseif ( function_exists( 'wp_get_sites' ) ) {
					$network_sites = wp_get_sites();// phpcs:ignore WordPress.WP.DeprecatedFunctions.wp_get_sitesFound -- This function was only intended for backward compatibility with versions below 4.6.
				}

				if ( $network_sites ) {
					$multisite_data = array();
					foreach ( $network_sites as $site ) {
						// get_sites() gives $site in object form whereas wp_get_sites() gives sites in array form.
						$site    = (array) $site;
						$blog_id = intval( $site['blog_id'] );

						$switched         = switch_to_blog( $blog_id );
						$wp_upload_dir    = wp_upload_dir();
						$multisite_data[] = array(
							'blog_id'     => $blog_id,
							'domain'      => $site['domain'],
							'path'        => $site['path'],
							'siteurl'     => untrailingslashit( site_url() ),
							'home'        => untrailingslashit( home_url() ),
							'uploads_url' => $wp_upload_dir['baseurl'],
						);
						if ( $switched ) {
							restore_current_blog();
						}
					}
					$this->stow( '# Site info: multisite_data=' . json_encode( $multisite_data ) . "\n" );
					if ( function_exists( 'get_main_site_id' ) ) {
						$this->stow( '# Site info: multisite_main_site_id=' . intval( get_main_site_id() ) . "\n" );
					}
				}
			}
			$this->stow( '# Site info: sql_mode=' . $this->wpdb_obj->get_var( 'SELECT @@SESSION.sql_mode' ) . "\n" );

			add_filter( 'backupplus_backup_db_header_site_info', array( $this, 'backup_db_header_site_info_woocommerce' ) );

			// This filter allows a user to add extra information about site info.
			if ( array() !== ( $site_info = apply_filters( 'backupplus_backup_db_header_site_info', array() ) ) ) {
				foreach ( $site_info as $info ) {
					$this->stow( '# Site info: ' . $info . "\n" );
				}
			}

			$this->stow( "# Site info: end\n" );
		} else {
			$this->stow( '# MySQL database backup (supplementary database ' . $this->whichdb . ")\n" );
			$this->stow( '# Created by BackupPlus version ' . $backupplus->version . "\n" );
			$this->stow( "# WordPress Version: $wp_version, running on PHP " . phpversion() . ' (' . $server_software . "), MySQL $mysql_version\n" );
			$this->stow( '# ' . sprintf( 'External database: (%s)', $this->dbinfo['user'] . '@' . $this->dbinfo['host'] . '/' . $this->dbinfo['name'] ) . "\n" );
			$this->stow( '# Backup created by: ' . untrailingslashit( site_url() ) . "\n" );
			$this->stow( '# Table prefix: ' . $this->table_prefix_raw . "\n" );
			$this->stow( '# Filtered table prefix: ' . $this->table_prefix . "\n" );
		}

		$label = $backupplus->jobdata_get( 'label' );
		if ( ! empty( $label ) ) {
			$this->stow( "# Label: $label\n" );
		}

		$this->stow( "\n# Generated: " . gmdate( 'l j. F Y H:i T' ) . "\n" );
		$this->stow( '# Hostname: ' . $this->dbinfo['host'] . "\n" );
		$this->stow( '# Database: ' . $this->comment_name( $this->dbinfo['name'] ) . "\n" );

		if ( ! empty( $this->skipped_tables[ $this->whichdb ] ) ) {
			if ( 'wp' == $this->whichdb ) {
				$this->stow( '# Skipped tables: ' . implode( ', ', $this->skipped_tables['wp'] ) . "\n" );
			} elseif ( isset( $this->skipped_tables[ $this->dbinfo['name'] ] ) ) {
				$this->stow( '# Skipped tables: ' . implode( ', ', $this->skipped_tables[ $this->dbinfo['name'] ] ) . "\n" );
			}
		}

		$this->stow( "# --------------------------------------------------------\n" );

		$this->stow( "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n" );
		$this->stow( "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n" );
		$this->stow( "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n" );
		// N.B. Setting this as the mode matches the export behaviour of both mysqldump and phpMyAdmin
		$this->stow( "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n" );
		$this->stow( '/*!40101 SET NAMES ' . $backupplus->get_connection_charset( $this->wpdb_obj ) . " */;\n" );
		$this->stow( "/*!40101 SET foreign_key_checks = 0 */;\n\n" );

		// This filter allows a user to add their own arbitrary content.
		if ( false !== ( $header_append = apply_filters( 'backupplus_backup_db_header_append', false ) ) ) {
			$this->stow( $header_append );
		}
	}

	/**
	 * Adds WooCommerce-related information to the site info header for backup purposes.
	 *
	 * This method checks if WooCommerce is active and appends relevant information about
	 * WooCommerce's state to the provided site info array. Specifically, it includes:
	 * - Whether WooCommerce is active.
	 * - Whether the High-Performance Order Storage (HPOS) feature is enabled.
	 *
	 * @param array $site_info The existing array of site information to which WooCommerce details will be appended.
	 * @return array The updated site info array with WooCommerce-related details included.
	 */
	public function backup_db_header_site_info_woocommerce( $site_info ) {
		if ( class_exists( 'WooCommerce' ) ) {
			$wc_version = defined( 'WC_VERSION' ) ? WC_VERSION : get_option( 'woocommerce_version' );
			$info       = 'WooCommerce=' . $wc_version;

			if ( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) && is_callable( array( 'Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' ) ) && call_user_func( array( 'Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' ) ) ) {
				$info .= ',HPOS=enabled';
			}

			$site_info[] = $info;
		}
		return $site_info;
	}
}
