<?php

/** Probing for working mysqldump and zip executables. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Core_Binaries {

	/**
	 * Find, if possible, a working mysqldump executable
	 *
	 * @param Boolean $log_it  - whether to log the workings or not
	 * @param Boolean $cacheit - whether to cache the results for subsequent queries or not
	 *
	 * @return String|Boolean - either a path to an executable, or false for failure
	 */
	public function find_working_sqldump( $log_it = true, $cacheit = true ) {

		// The hosting provider may have explicitly disabled the popen or proc_open functions
		if ( $this->detect_safe_mode() || ! function_exists( 'popen' ) || ! function_exists( 'escapeshellarg' ) ) {
			if ( $cacheit ) {
				$this->jobdata_set( 'binsqldump', false );
			}
			return false;
		}
		$existing = $this->jobdata_get( 'binsqldump', null );
		// Theoretically, we could have moved machines, due to a migration
		if ( null !== $existing && ( ! is_string( $existing ) || @is_executable( $existing ) ) ) {
			return $existing;// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}
		$backupplus_dir = $this->backups_dir_location();
		global $wpdb;
		$table_name = $wpdb->get_blog_prefix() . 'options';
		// Owner-only, removed below or at shutdown (BPS-08). A password the file cannot hold means the PHP dumper is used.
		try {
			$option_file = BackupPlus_Mysqldump_Option_File::create( $backupplus_dir, DB_PASSWORD );
		} catch ( RuntimeException $e ) {
			if ( $log_it ) {
				$this->log( 'Binary mysqldump not used: ' . $e->getMessage() );
			}
			if ( $cacheit ) {
				$this->jobdata_set( 'binsqldump', false );
			}
			return false;
		}
		try {
			$result = $this->probe_sqldump( basename( $option_file ), $backupplus_dir, $table_name, $log_it );
		} finally {
			BackupPlus_Mysqldump_Option_File::remove( $option_file );
		}

		if ( $cacheit ) {
			$this->jobdata_set( 'binsqldump', $result );
		}

		return $result;
	}

	/**
	 * Try each mysqldump candidate on the options table
	 *
	 * @param String  $pfile          - the option file's name inside the backup directory
	 * @param String  $backupplus_dir - the backup directory
	 * @param String  $table_name     - the options table
	 * @param Boolean $log_it         - whether to log the workings
	 *
	 * @return String|Boolean - the first working executable, or false
	 */
	private function probe_sqldump( $pfile, $backupplus_dir, $table_name, $log_it ) {
		global $wpdb;

		$result = false;
		backupplus_try_include_file( 'includes/class-executables.php', 'require_once' );
		foreach ( explode( ',', BACKUPPLUS_MYSQLDUMP_EXECUTABLE ) as $potsql ) {

			// Absolute and not replaceable by every user; the built-in list must not be writable by PHP either (BPS-20).
			if ( ! BackupPlus_Executables::trusted_binary( $potsql, BackupPlus_Executables::configured( 'BACKUPPLUS_MYSQLDUMP_EXECUTABLE' ) ) ) {
				continue;
			}
			if ( $log_it ) {
				$this->log( "Testing potential mysqldump binary: $potsql" );
			}

			if ( 'win' == strtolower( substr( PHP_OS, 0, 3 ) ) ) {
				$exec    = 'cd ' . escapeshellarg( str_replace( '/', '\\', $backupplus_dir ) ) . ' & ';
				$siteurl = "'siteurl'";
			} else {
				$exec    = 'cd ' . escapeshellarg( $backupplus_dir ) . '; ';
				$siteurl = "\\'siteurl\\'";
			}

			// Allow --max_allowed_packet to be configured via constant. Experience has shown some customers with complex CMS or pagebuilder setups can have extrememly large postmeta entries.
			$msqld_max_allowed_packet = BackupPlus_Executables::max_allowed_packet( '1M' );

			$exec .= BackupPlus_Executables::shell_program( $potsql ) . ' --defaults-file=' . escapeshellarg( $pfile ) . " --max_allowed_packet=$msqld_max_allowed_packet --quote-names --add-drop-table";

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

			$exec .= " --skip-comments --skip-set-charset --allow-keywords --dump-date --extended-insert --where=option_name=$siteurl --user=" . escapeshellarg( DB_USER ) . ' ';

			if ( preg_match( '#^(.*):(\d+)$#', DB_HOST, $matches ) ) {
				// The escapeshellarg() on $matches[2] is only to avoid tripping static analysis tools
				$exec .= '--host=' . escapeshellarg( $matches[1] ) . ' --port=' . escapeshellarg( $matches[2] ) . ' ';
			} elseif ( preg_match( '#^(.*):(.*)$#', DB_HOST, $matches ) && file_exists( $matches[2] ) ) {
				$exec .= '--host=' . escapeshellarg( $matches[1] ) . ' --socket=' . escapeshellarg( $matches[2] ) . ' ';
			} else {
				$exec .= '--host=' . escapeshellarg( DB_HOST ) . ' ';
			}

			$exec .= escapeshellarg( DB_NAME ) . ' ' . escapeshellarg( $table_name );

			$handle = ( function_exists( 'popen' ) && function_exists( 'pclose' ) ) ? popen( $exec, 'r' ) : false;
			if ( $handle ) {
				$output = '';
				// We expect the INSERT statement in the first 100KB
				while ( ! feof( $handle ) && strlen( $output ) < 102400 ) {
					$output .= fgets( $handle, 102400 );
				}
				if ( $output && $log_it ) {
					$log_output = ( strlen( $output ) > 512 ) ? substr( $output, 0, 512 ) . ' (truncated - ' . strlen( $output ) . ' bytes total)' : $output;
					$this->log( 'Output: ' . str_replace( "\n", '\\n', trim( $log_output ) ) );
				}
				$ret = pclose( $handle );
				// The manual page for pclose() claims that only -1 indicates an error, but this is untrue
				if ( 0 != $ret ) {
					if ( $log_it ) {
						$this->log( "Binary mysqldump: error (code: $ret)" );
					}
				} else {
					if ( false !== stripos( $output, 'insert into' ) ) {
						if ( $log_it ) {
							$this->log( "Working binary mysqldump found: $potsql" );
						}
						$result = $potsql;
						break;
					}
				}
			} else {
				if ( $log_it ) {
					$this->log( 'Error: popen failed' );
				}
			}
		}

		return $result;
	}

	/**
	 * This function will work out which zip object we want to use and return it's name
	 *
	 * @return string - the name of the zip object we want to use
	 */
	public function get_zip_object_name() {

		if ( ! class_exists( 'BackupPlus_BinZip' ) ) {
			include_once BACKUPPLUS_DIR . '/includes/class-zip.php';
		}

		$zip_object = 'BackupPlus_ZipArchive';

		// In tests, PclZip was found to be 25% slower than ZipArchive
		if ( ( ( defined( 'BACKUPPLUS_PREFERPCLZIP' ) && BACKUPPLUS_PREFERPCLZIP == true ) || ! class_exists( 'ZipArchive' ) || ! class_exists( 'BackupPlus_ZipArchive' ) || ( ! extension_loaded( 'zip' ) && ! method_exists( 'ZipArchive', 'AddFile' ) ) ) ) {
			$zip_object = 'BackupPlus_PclZip';
		}

		return $zip_object;
	}

	/**
	 * We require -@ and -u -r to work - which is the usual Linux binzip
	 *
	 * @param  Boolean $log_it  - whether to record the results with BackupPlus::log()
	 * @param  Boolean $cacheit - whether to cache the results as job data
	 * @return String|Boolean   - the path to a working zip binary, or false
	 */
	public function find_working_bin_zip( $log_it = true, $cacheit = true ) {
		if ( $this->detect_safe_mode() ) {
			return false;
		}
		// The hosting provider may have explicitly disabled the popen or proc_open functions
		if ( ! function_exists( 'popen' ) || ! function_exists( 'proc_open' ) || ! function_exists( 'proc_close' ) || ! function_exists( 'escapeshellarg' ) ) {
			if ( $cacheit ) {
				$this->jobdata_set( 'binzip', false );
			}
			return false;
		}

		$existing = $this->jobdata_get( 'binzip', null );
		// Theoretically, we could have moved machines, due to a migration
		if ( null !== $existing && ( ! is_string( $existing ) || @is_executable( $existing ) ) ) {
			return $existing;// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}
		$backupplus_dir = $this->backups_dir_location();
		backupplus_try_include_file( 'includes/class-executables.php', 'require_once' );
		foreach ( explode( ',', BACKUPPLUS_ZIP_EXECUTABLE ) as $potzip ) {
			// As for mysqldump (BPS-20).
			if ( ! BackupPlus_Executables::trusted_binary( $potzip, BackupPlus_Executables::configured( 'BACKUPPLUS_ZIP_EXECUTABLE' ) ) ) {
				continue;
			}           if ( $log_it ) {
				$this->log( "Testing: $potzip" );
			}

			// Test it, see if it is compatible with Info-ZIP
			// If you have another kind of zip, then feel free to tell me about it
			@mkdir( $backupplus_dir . '/binziptest/subdir1/subdir2', 0777, true );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.

			if ( ! file_exists( $backupplus_dir . '/binziptest/subdir1/subdir2' ) ) {
				return false;
			}

			file_put_contents( $backupplus_dir . '/binziptest/subdir1/subdir2/test.html', '<html><body>BackupPlus is a great backup and restoration plugin for WordPress.</body></html>' );

			if ( file_exists( $backupplus_dir . '/binziptest/test.zip' ) ) {
				unlink( $backupplus_dir . '/binziptest/test.zip' );
			}

			if ( is_file( $backupplus_dir . '/binziptest/subdir1/subdir2/test.html' ) ) {

				$exec    = 'cd ' . escapeshellarg( $backupplus_dir ) . '; ' . BackupPlus_Executables::shell_program( $potzip );
				$options = BackupPlus_Executables::binzip_options();
				if ( '' !== $options ) {
					$exec .= ' ' . $options;
				}
				$exec .= ' -v -u -r binziptest/test.zip binziptest/subdir1';

				$all_ok = true;
				$handle = ( function_exists( 'popen' ) && function_exists( 'pclose' ) ) ? popen( $exec, 'r' ) : false;
				if ( $handle ) {
					while ( ! feof( $handle ) ) {
						$w = fgets( $handle );
						if ( $w && $log_it ) {
							$this->log( 'Output: ' . trim( $w ) );
						}
					}
					$ret = pclose( $handle );
					// The manual page for pclose() claims that only -1 indicates an error, but this is untrue
					if ( 0 != $ret ) {
						if ( $log_it ) {
							$this->log( "Binary zip: error (code: $ret)" );
						}
						$all_ok = false;
					}
				} else {
					if ( $log_it ) {
						$this->log( 'Error: popen failed' );
					}
					$all_ok = false;
				}

				// Now test -@
				if ( true == $all_ok ) {
					file_put_contents( $backupplus_dir . '/binziptest/subdir1/subdir2/test2.html', '<html><body>BackupPlus is a really great backup and restoration plugin for WordPress.</body></html>' );

					$exec = BackupPlus_Executables::shell_program( $potzip );
					if ( '' !== $options ) {
						$exec .= ' ' . $options;
					}
					$exec .= ' -v -@ binziptest/test.zip';

					$all_ok = true;

					$descriptorspec = array(
						0 => array( 'pipe', 'r' ),
						1 => array( 'pipe', 'w' ),
						2 => array( 'pipe', 'w' ),
					);
					$handle         = proc_open( $exec, $descriptorspec, $pipes, $backupplus_dir );// phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- This function is intentionally used and reviewed for safety.
					if ( is_resource( $handle ) ) {
						if ( ! fwrite( $pipes[0], "binziptest/subdir1/subdir2/test2.html\n" ) ) {
							@fclose( $pipes[0] );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
							@fclose( $pipes[1] );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
							@fclose( $pipes[2] );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
							$all_ok = false;
						} else {
							fclose( $pipes[0] );
							while ( ! feof( $pipes[1] ) ) {
								$w = fgets( $pipes[1] );
								if ( $w && $log_it ) {
									$this->log( 'Output: ' . trim( $w ) );
								}
							}
							fclose( $pipes[1] );

							while ( ! feof( $pipes[2] ) ) {
								$last_error = fgets( $pipes[2] );
								if ( ! empty( $last_error ) && $log_it ) {
									$this->log( 'Stderr output: ' . trim( $w ) );
								}
							}
							fclose( $pipes[2] );

							$ret = function_exists( 'proc_close' ) ? proc_close( $handle ) : -1;
							if ( 0 != $ret ) {
								if ( $log_it ) {
									$this->log( "Binary zip: error (code: $ret)" );
								}
								$all_ok = false;
							}
						}
					} else {
						if ( $log_it ) {
							$this->log( 'Error: proc_open failed' );
						}
						$all_ok = false;
					}
				}

				// Do we now actually have a working zip? Need to test the created object using PclZip
				// If it passes, then remove dirs and then return $potzip;
				$found_first  = false;
				$found_second = false;
				if ( $all_ok && file_exists( $backupplus_dir . '/binziptest/test.zip' ) ) {
					if ( function_exists( 'gzopen' ) ) {
						if ( ! class_exists( 'PclZip' ) ) {
							include_once ABSPATH . '/wp-admin/includes/class-pclzip.php';
						}
						$zip = new PclZip( $backupplus_dir . '/binziptest/test.zip' );
						if ( ( $list = $zip->listContent() ) != 0 ) {
							foreach ( $list as $obj ) {
								if ( $obj['filename'] && ! empty( $obj['stored_filename'] ) && 'binziptest/subdir1/subdir2/test.html' == $obj['stored_filename'] && 131 == $obj['size'] ) {
									$found_first = true;
								}
								if ( $obj['filename'] && ! empty( $obj['stored_filename'] ) && 'binziptest/subdir1/subdir2/test2.html' == $obj['stored_filename'] && 138 == $obj['size'] ) {
									$found_second = true;
								}
							}
						}
					} else {
						// PclZip will die() if gzopen is not found
						// Obviously, this is a kludge - we assume it's working. We could, of course, just return false - but since we already know now that PclZip can't work, that only leaves ZipArchive
						$this->log( 'gzopen function not found; PclZip cannot be invoked; will assume that binary zip works if we have a non-zero file' );
						if ( filesize( $backupplus_dir . '/binziptest/test.zip' ) > 0 ) {
							$found_first  = true;
							$found_second = true;
						}
					}
				}
				$this->remove_binzip_test_files( $backupplus_dir );
				if ( $found_first && $found_second ) {
					if ( $log_it ) {
						$this->log( "Working binary zip found: $potzip" );
					}
					if ( $cacheit ) {
						$this->jobdata_set( 'binzip', $potzip );
					}
					return $potzip;
				}
			}
			$this->remove_binzip_test_files( $backupplus_dir );
		}
		if ( $cacheit ) {
			$this->jobdata_set( 'binzip', false );
		}
		return false;
	}

	/**
	 * Remove potentially existing test files after binzip testing
	 *
	 * @param String $backupplus_dir - directory to find the files in
	 */
	private function remove_binzip_test_files( $backupplus_dir ) {
		@unlink( $backupplus_dir . '/binziptest/subdir1/subdir2/test.html' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
		@unlink( $backupplus_dir . '/binziptest/subdir1/subdir2/test2.html' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
		@rmdir( $backupplus_dir . '/binziptest/subdir1/subdir2' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		@rmdir( $backupplus_dir . '/binziptest/subdir1' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		@unlink( $backupplus_dir . '/binziptest/test.zip' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
		@rmdir( $backupplus_dir . '/binziptest' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
	}
}
