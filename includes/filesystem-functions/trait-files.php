<?php

/** Checking, copying and deleting local files and directories, including the temporary files left by jobs. */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- we try to reduce overhead by bypassing WP APIs and other extra layers; Some custom complex queries tailored specifically to our needs, giving us full control over the SQL commands and data manipulation
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fgets, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.WP.AlternativeFunctions.file_system_operations_fputs, WordPress.WP.AlternativeFunctions.file_system_operations_is_writable, WordPress.WP.AlternativeFunctions.file_system_operations_chown, WordPress.WP.AlternativeFunctions.file_system_operations_chgrp, WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Native PHP fileystem function is used for direct control and performance because it can bypass additional layers of abstraction so that no overhead from the WordPress filesystem API's internal handling
// phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename -- rename() usage is intentional and safe within this context
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- some query operations need to always receive the most up-to-date or actual data directly from the database, reducing the risk of serving stale information.
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Filesystem_Functions_Files {

	/**
	 * Ensure that WP_Filesystem is instantiated and functional. Otherwise, outputs necessary HTML and dies.
	 *
	 * @param array $url_parameters - parameters and values to be added to the URL output
	 *
	 * @return void
	 */
	public static function ensure_wp_filesystem_set_up_for_restore( $url_parameters = array() ) {

		global $wp_filesystem, $backupplus;

		$build_url = BackupPlus_Options::admin_page() . '?page=backupplus&action=backupplus_restore&nonce=' . wp_create_nonce( 'backupplus-credentialtest-nonce' );

		foreach ( $url_parameters as $k => $v ) {
			$build_url .= '&' . $k . '=' . $v;
		}

		if ( false === ( $credentials = request_filesystem_credentials( $build_url, '', false, false ) ) ) {
			exit;
		}

		if ( ! WP_Filesystem( $credentials ) ) {

			$backupplus->log( 'Filesystem credentials are required for WP_Filesystem' );

			// If the filesystem credentials provided are wrong then we need to change our ajax_restore action so that we ask for them again
			if ( false !== strpos( $build_url, 'backupplus_ajax_restore=do_ajax_restore' ) ) {
				$build_url = str_replace( 'backupplus_ajax_restore=do_ajax_restore', 'backupplus_ajax_restore=continue_ajax_restore', $build_url );
			}

			request_filesystem_credentials( $build_url, '', true, false );

			if ( $wp_filesystem->errors->get_error_code() ) {
				echo '<div class="restore-credential-errors">';
				echo '<p class="restore-credential-errors--link"><em>' . esc_html__( 'WordPress is requesting filesystem credentials because it cannot write directly to the required files or directories.', 'backupplus' ) . '</em></p>';
				echo '<div class="restore-credential-errors--list">';
				foreach ( $wp_filesystem->errors->get_error_messages() as $message ) {
					show_message( $message );
				}
				echo '</div>';
				echo '</div>';
				exit;
			}
		}
	}

	/**
	 * Cleans up temporary files found in the BackupPlus directory (and some in the site root - pclzip)
	 * Always cleans up temporary files over 12 hours old.
	 * With parameters, also cleans up those.
	 * Also cleans out old job data older than 12 hours old (immutable value)
	 * include_cachelist also looks to match any files of cached file analysis data
	 *
	 * @param String  $match             - if specified, then a prefix to require
	 * @param Integer $older_than        - in seconds
	 * @param Boolean $include_cachelist - include cachelist files in what can be purged
	 */
	public static function clean_temporary_files( $match = '', $older_than = 43200, $include_cachelist = false ) {

		global $backupplus;

		// Clean out old job data
		if ( $older_than > 10000 ) {

			global $wpdb;
			$sql          = BackupPlus_SQL::wpdb();
			$table        = is_multisite() ? $wpdb->sitemeta : $wpdb->options;
			$key_column   = is_multisite() ? 'meta_key' : 'option_name';
			$value_column = is_multisite() ? 'meta_value' : 'option_value';

			// Limit the maximum number for performance (the rest will get done next time, if for some reason there was a back-log)
			$all_jobs = $sql->rows( 'SELECT %i, %i FROM %i WHERE %i LIKE ? LIMIT 100', array( $key_column, $value_column, $table, $key_column ), array( 'backupplus_jobdata_%' ), ARRAY_A );

			foreach ( (array) $all_jobs as $job ) {
				$nonce = str_replace( 'backupplus_jobdata_', '', $job[ $key_column ] );
				$val   = empty( $job[ $value_column ] ) ? array() : $backupplus->unserialize( $job[ $value_column ] );
				// TODO: Can simplify this after a while (now all jobs use job_time_ms) - 1 Jan 2014
				$delete = false;
				if ( ! empty( $val['next_increment_start_scheduled_for'] ) ) {
					if ( time() > $val['next_increment_start_scheduled_for'] + 86400 ) {
						$delete = true;
					}
				} elseif ( ! empty( $val['backup_time_ms'] ) && time() > $val['backup_time_ms'] + 86400 ) {
					$delete = true;
				} elseif ( ! empty( $val['job_time_ms'] ) && time() > $val['job_time_ms'] + 86400 ) {
					$delete = true;
				} elseif ( ! empty( $val['job_type'] ) && 'backup' != $val['job_type'] && empty( $val['backup_time_ms'] ) && empty( $val['job_time_ms'] ) ) {
					$delete = true;
				}
				if ( isset( $val['temp_import_table_prefix'] ) && '' != $val['temp_import_table_prefix'] && $wpdb->prefix != $val['temp_import_table_prefix'] ) {
					$prefix = BackupPlus_Database_Utility::esc_like( $val['temp_import_table_prefix'] ) . '%';
					// SHOW cannot take bound values.
					$tables_to_remove = $sql->column( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?', array(), array( $prefix ) );

					foreach ( (array) $tables_to_remove as $table_name ) {
						$sql->execute( 'DROP TABLE %i', array( $table_name ) );
					}
				}
				if ( $delete ) {
					delete_site_option( $job[ $key_column ] );
					delete_site_option( 'backupplus_semaphore_' . $nonce );
				}
			}
			$sql->execute( 'DELETE FROM %i WHERE (option_name REGEXP ? AND CAST(option_value AS UNSIGNED) < ?) OR (option_name REGEXP ? AND UNIX_TIMESTAMP() > CAST(option_value AS UNSIGNED) + ?) LIMIT 1000', array( $wpdb->options ), array( '^backupplus_lock_[a-f0-9A-F]{12}$', (int) strtotime( '2025-03-01' ), '^backupplus_lock_udp_backupjob_[a-f0-9A-F]{12}$', (int) $older_than ) );
		}
		$backupplus_dir    = $backupplus->backups_dir_location();
		$now_time          = time();
		$files_deleted     = 0;
		$include_cachelist = defined( 'DOING_CRON' ) && DOING_CRON && doing_action( 'backupplus_clean_temporary_files' ) ? true : $include_cachelist;
		if ( $handle = opendir( $backupplus_dir ) ) {
			while ( false !== ( $entry = readdir( $handle ) ) ) {
				$manifest_match = preg_match( '/backupplus-manifest\.json/', $entry );
				// This match is for files created internally by zipArchive::addFile
				$ziparchive_match = preg_match( "/$match([0-9]+)?\.zip\.tmp\.(?:[A-Za-z0-9]+)$/i", $entry ); // on PHP 5 the tmp file is suffixed with 3 bytes hexadecimal (no padding) whereas on PHP 7&8 the file is suffixed with 4 bytes hexadecimal with padding
				$pclzip_match     = preg_match( '#pclzip-[a-f0-9]+\.(?:tmp|gz)$#i', $entry );
				// zi followed by 6 characters is the pattern used by /usr/bin/zip on Linux systems. It's safe to check for, as we have nothing else that's going to match that pattern.
				$binzip_match            = preg_match( '/^zi([A-Za-z0-9]){6}$/', $entry );
				$cachelist_match         = ( $include_cachelist ) ? preg_match( '/-cachelist-.*(?:info|\.tmp)$/i', $entry ) : false;
				$browserlog_match        = preg_match( '/^log\.[0-9a-f]+-browser\.txt$/', $entry );
				$downloader_client_match = preg_match( "/$match([0-9]+)?\.zip\.tmp\.(?:[A-Za-z0-9]+)\.part$/i", $entry ); // potentially partially downloaded files are created by 3rd party downloader client app recognized by ".part" extension at the end of the backup file name (e.g. .zip.tmp.3b9r8r.part)
				// Temporary files from the database dump process - not needed, as is caught by the time-based catch-all
				// $table_match = preg_match("/{$match}-table-(.*)\.table(\.tmp)?\.gz$/i", $entry);
				// The gz goes in with the txt, because we *don't* want to reap the raw .txt files
				if ( ( preg_match( "/$match\.(tmp|table|txt\.gz)(\.gz)?$/i", $entry ) || $cachelist_match || $ziparchive_match || $pclzip_match || $binzip_match || $manifest_match || $browserlog_match || $downloader_client_match ) && is_file( $backupplus_dir . '/' . $entry ) ) {
					// We delete if a parameter was specified (and either it is a ZipArchive match or an order to delete of whatever age), or if over 12 hours old
					if ( ( $match && ( $ziparchive_match || $pclzip_match || $binzip_match || $cachelist_match || $manifest_match || 0 == $older_than ) && $now_time - filemtime( $backupplus_dir . '/' . $entry ) >= $older_than ) || $now_time - filemtime( $backupplus_dir . '/' . $entry ) > 43200 ) {
						$skip_dblog = ( 0 == $files_deleted % 25 ) ? false : true;
						$backupplus->log( "Deleting old temporary file: $entry", 'notice', false, $skip_dblog );
						@unlink( $backupplus_dir . '/' . $entry );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
						++$files_deleted;
					}
				} elseif ( preg_match( '/^log\.[0-9a-f]+\.txt$/', $entry ) && $now_time - filemtime( $backupplus_dir . '/' . $entry ) > apply_filters( 'backupplus_log_delete_age', 86400 * 40, $entry ) ) {
					$skip_dblog = ( 0 == $files_deleted % 25 ) ? false : true;
					$backupplus->log( "Deleting old log file: $entry", 'notice', false, $skip_dblog );
					@unlink( $backupplus_dir . '/' . $entry );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
					++$files_deleted;
				}
			}
			@closedir( $handle );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}

		// Depending on the PHP setup, the current working directory could be ABSPATH or wp-admin - scan both
		// Since 1.9.32, we set them to go into $backupplus_dir, so now we must check there too. Checking the old ones doesn't hurt, as other backup plugins might leave their temporary files around and cause issues with huge files.
		foreach ( array( ABSPATH, ABSPATH . 'wp-admin/', $backupplus_dir . '/' ) as $path ) {
			if ( $handle = opendir( $path ) ) {
				while ( false !== ( $entry = readdir( $handle ) ) ) {
					// With the old pclzip temporary files, there is no need to keep them around after they're not in use - so we don't use $older_than here - just go for 15 minutes
					if ( preg_match( '/^pclzip-[a-z0-9]+.tmp$/', $entry ) && $now_time - filemtime( $path . $entry ) >= 900 ) {
						$backupplus->log( "Deleting old PclZip temporary file: $entry (from " . basename( $path ) . ')' );
						@unlink( $path . $entry );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
					}
				}
				@closedir( $handle );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			}
		}
	}

	/**
	 * Find out whether we really can write to a particular folder
	 *
	 * @param String  $dir                   - the folder path
	 * @param Boolean $test_case_sensitivity - also require that the filesystem be case-sensitive to return true (hence, false could be for multiple reasons)
	 *
	 * @return Boolean - the result
	 */
	public static function really_is_writable( $dir, $test_case_sensitivity = false ) {
		// Suppress warnings, since if the user is dumping warnings to screen, then invalid JavaScript results and the screen breaks.
		if ( ! @is_writable( $dir ) ) {
			return false;// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- PHP's logging is not useful here.
		}       // Found a case - GoDaddy server, Windows, PHP 5.2.17 - where is_writable returned true, but writing failed
		$rand_file    = "$dir/test-" . md5( wp_rand() . time() ) . '-ud.txt';
		$rand_file_uc = substr( $rand_file, 0, -7 ) . '-UD.txt';
		while ( file_exists( $rand_file ) && ( ! $test_case_sensitivity || file_exists( $rand_file_uc ) ) ) {
			$rand_file    = "$dir/test-" . md5( wp_rand() . time() ) . '-ud.txt';
			$rand_file_uc = substr( $rand_file, 0, -7 ) . '-UD.txt';
		}

		$file_contents = 'testing... ' . wp_rand();

		$ret = @file_put_contents( $rand_file, $file_contents );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- PHP's logging is not useful here

		if ( $ret && $test_case_sensitivity ) {
			if ( is_file( $rand_file_uc ) ) {
				if ( file_get_contents( $rand_file_uc ) === $file_contents ) {
					$ret = 0;
				}
				// If it exists but was different, then it's apparently been created by something else. N.B. We only attempt to remove the file we created.
			}
		}

		@unlink( $rand_file );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
		return ( $ret > 0 );
	}

	/**
	 * Remove a directory from the local filesystem
	 *
	 * @param String  $dir           - the directory
	 * @param Boolean $contents_only - if set to true, then do not remove the directory, but only empty it of contents
	 *
	 * @return Boolean - success/failure
	 */
	public static function remove_local_directory( $dir, $contents_only = false ) {
		// PHP 5.3+ only
		// foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $path) {
		// $path->isFile() ? unlink($path->getPathname()) : rmdir($path->getPathname());
		// }
		// return rmdir($dir);

		if ( $handle = @opendir( $dir ) ) {// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			while ( false !== ( $entry = readdir( $handle ) ) ) {
				if ( '.' !== $entry && '..' !== $entry ) {
					if ( is_dir( $dir . '/' . $entry ) ) {
						self::remove_local_directory( $dir . '/' . $entry, false );
					} else {
						@unlink( $dir . '/' . $entry );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
					}
				}
			}
			@closedir( $handle );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}

		return $contents_only ? true : rmdir( $dir );
	}

	/**
	 * Compatibility function (exists in WP 4.8+)
	 */
	public static function wp_doing_cron() {
		if ( function_exists( 'wp_doing_cron' ) ) {
			return wp_doing_cron();
		}
		return apply_filters( 'wp_doing_cron', defined( 'DOING_CRON' ) && DOING_CRON );
	}

	/**
	 * Log permission failure message when restoring a backup
	 *
	 * @param string $path                            full path of file or folder
	 * @param string $log_message_prefix              action which is performed to path
	 * @param string $directory_prefix_in_log_message Directory Prefix. It should be either "Parent" or "Destination"
	 */
	public static function restore_log_permission_failure_message( $path, $log_message_prefix, $directory_prefix_in_log_message = 'Parent' ) {
		global $backupplus;
		$log_message = $backupplus->log_permission_failure_message( $path, $log_message_prefix, $directory_prefix_in_log_message );
		if ( $log_message ) {
			$backupplus->log( $log_message, 'warning-restore' );
		}
	}

	/**
	 * Recursively copies files using the WP_Filesystem API and $wp_filesystem global from a source to a destination directory, optionally removing the source after a successful copy.
	 *
	 * @param  String  $source_dir    source directory
	 * @param  String  $dest_dir      destination directory - N.B. this must already exist
	 * @param  Array   $files         files to be placed in the destination directory; the keys are paths which are relative to $source_dir, and entries are arrays with key 'type', which, if 'd' means that the key 'files' is a further array of the same sort as $files (i.e. it is recursive)
	 * @param  Boolean $chmod         chmod type
	 * @param  Boolean $delete_source indicate whether source needs deleting after a successful copy
	 *
	 * @uses $GLOBALS['wp_filesystem']
	 * @uses self::restore_log_permission_failure_message()
	 *
	 * @return WP_Error|Boolean
	 */
	public static function copy_files_in( $source_dir, $dest_dir, $files, $chmod = false, $delete_source = false ) {

		global $wp_filesystem, $backupplus;

		foreach ( $files as $rname => $rfile ) {
			if ( 'd' != $rfile['type'] ) {

				// Third-parameter: (boolean) $overwrite
				if ( ! $wp_filesystem->move( $source_dir . '/' . $rname, $dest_dir . '/' . $rname, true ) ) {

					self::restore_log_permission_failure_message( $dest_dir, $source_dir . '/' . $rname . ' -> ' . $dest_dir . '/' . $rname, 'Destination' );

					return false;

				}
			} else {
				// $rfile['type'] is 'd'

				// Attempt to remove any already-existing file with the same name
				if ( $wp_filesystem->is_file( $dest_dir . '/' . $rname ) ) {
					@$wp_filesystem->delete( $dest_dir . '/' . $rname, false, 'f' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- if fails, carry on
				}
				// No such directory yet: just move it
				if ( $wp_filesystem->exists( $dest_dir . '/' . $rname ) && ! $wp_filesystem->is_dir( $dest_dir . '/' . $rname ) && ! $wp_filesystem->move( $source_dir . '/' . $rname, $dest_dir . '/' . $rname, false ) ) {

					self::restore_log_permission_failure_message( $dest_dir, 'Move ' . $source_dir . '/' . $rname . ' -> ' . $dest_dir . '/' . $rname, 'Destination' );
					$backupplus->log_e( 'Failed to move directory (check your file permissions and disk quota): %s', $source_dir . '/' . $rname . ' -&gt; ' . $dest_dir . '/' . $rname );

					return false;

				} elseif ( ! empty( $rfile['files'] ) ) {

					if ( ! $wp_filesystem->exists( $dest_dir . '/' . $rname ) ) {
						$wp_filesystem->mkdir( $dest_dir . '/' . $rname, $chmod );
					}

					// There is a directory - and we want to to copy in
					$do_copy = self::copy_files_in( $source_dir . '/' . $rname, $dest_dir . '/' . $rname, $rfile['files'], $chmod, false );

					if ( is_wp_error( $do_copy ) || false === $do_copy ) {
						return $do_copy;
					}
				} else {
					// There is a directory: but nothing to copy in to it (i.e. $file['files'] is empty). Just remove the directory.
					@$wp_filesystem->rmdir( $source_dir . '/' . $rname );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.
				}
			}
		}

		// We are meant to leave the working directory empty. Hence, need to rmdir() once a directory is empty. But not the root of it all in case of others/wpcore.
		if ( $delete_source || false !== strpos( $source_dir, '/' ) ) {
			if ( ! $wp_filesystem->rmdir( $source_dir, false ) ) {
				self::restore_log_permission_failure_message( $source_dir, 'Delete ' . $source_dir );
			}
		}

		return true;
	}

	/**
	 * Recursively scan and delete files located in a directory whose modification time is considered older than a given time
	 *
	 * @param string  $working_dir   - An absolute path of working directory that will be scanned.
	 * @param integer $timestamp     - The timestamp to compare with the files' modified time. Files will be deleted if the modified time is older than this timestamp.
	 * @param array   $paths_to_keep - An array of folder or file absolute paths that we want to keep.
	 *
	 * @return void
	 */
	public static function delete_files_by_age( $working_dir, $timestamp, $paths_to_keep = array() ) {
		global $backupplus;

		$dir = dir( $working_dir );
		if ( ! $dir ) {
			$backupplus->log( "Cannot access the $working_dir directory.", 'notice', false, true );
			return;
		}

		static $depth_level   = 0;
		static $total_deleted = 0;

		if ( 0 === $depth_level ) {
			$total_deleted = 0;
		}

		++$depth_level;
		while ( false !== ( $filename = $dir->read() ) ) {
			if ( '.' === $filename || '..' === $filename ) {
				continue;
			}

			$result     = null;
			$filepath   = BackupPlus_Manipulation_Functions::wp_normalize_path( $working_dir . '/' . $filename );
			$file_mtime = @filemtime( $filepath ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			if ( is_dir( $filepath ) ) {
				self::delete_files_by_age( $filepath, $timestamp, $paths_to_keep );
			}

			if ( false === $file_mtime ) {
				$backupplus->log( "Unable to get the '$filepath' modification time.", 'notice', false, true );
				continue;
			}

			// Keep file that is inside the paths that we want to keep or whose modification time is considered newer than a given time.
			if ( self::is_path_in_files( $filepath, $paths_to_keep ) || $file_mtime >= $timestamp ) {
				continue;
			}

			if ( is_dir( $filepath ) ) {
				// Delete the empty folder
				$result = @rmdir( $filepath ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the folder isn't empty.
			} else {
				// Delete the file whose modified time is older than the given timestamp
				$result = @unlink( $filepath ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist or we don't have sufficient permission to it.
			}

			if ( ! isset( $result ) ) {
				continue;
			}

			if ( $result ) {
				++$total_deleted;
			} else {
				$reason = ( is_dir( $filepath ) ) ? "The directory isn't empty." : "The file doesn't exist, or you don't have sufficient permission to it.";
				$backupplus->log( "Cannot delete '$filepath'. $reason", 'notice', false, true );
			}

			if ( $total_deleted > 0 && 0 === $total_deleted % 100 ) {
				$backupplus->log( "$total_deleted files deleted.", 'notice', false, true );
			}
		}

		--$depth_level;

		if ( 0 === $depth_level && $total_deleted % 100 > 0 ) {
			$backupplus->log( "$total_deleted files deleted.", 'notice', false, true );
		}
	}

	/**
	 * Check if a given path matches with any files/folders in the list (case sensitive)
	 * NOTE: Since the given path can be a file or folder hence a check might be required if a boolean true is returned by this method.
	 * The check can also be done before calling and passing the path into this method.
	 *
	 * @param string  $path           - The path of a folder or file that want to be checked.
	 * @param array   $files          - The list of files and/or folders that want to be checked.
	 * @param boolean $case_sensitive - Whether or not the filesystem is case-sensitive.
	 *
	 * @return boolean True if the path matches with any folder in the list, false otherwise
	 */
	private static function is_path_in_files( $path, $files, $case_sensitive = false ) {
		$path = trim( BackupPlus_Manipulation_Functions::wp_normalize_path( $path ), '/' );
		$path = ( $case_sensitive ) ? $path : strtolower( $path );
		foreach ( $files as $file ) {
			$file       = trim( BackupPlus_Manipulation_Functions::wp_normalize_path( $file ), '/' );
			$file       = ( $case_sensitive ) ? $file : strtolower( $file );
			$path_parts = explode( '/', $path );
			$file_parts = explode( '/', $file );
			array_splice( $path_parts, count( $file_parts ) );
			if ( $path_parts === $file_parts ) {
				return true;
			}
		}
		return false;
	}
}
