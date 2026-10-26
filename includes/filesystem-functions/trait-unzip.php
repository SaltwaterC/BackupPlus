<?php

/** Opening gzip files and extracting zip archives. */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- we try to reduce overhead by bypassing WP APIs and other extra layers; Some custom complex queries tailored specifically to our needs, giving us full control over the SQL commands and data manipulation
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fgets, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.WP.AlternativeFunctions.file_system_operations_fputs, WordPress.WP.AlternativeFunctions.file_system_operations_is_writable, WordPress.WP.AlternativeFunctions.file_system_operations_chown, WordPress.WP.AlternativeFunctions.file_system_operations_chgrp, WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Native PHP fileystem function is used for direct control and performance because it can bypass additional layers of abstraction so that no overhead from the WordPress filesystem API's internal handling
// phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename -- rename() usage is intentional and safe within this context
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- some query operations need to always receive the most up-to-date or actual data directly from the database, reducing the risk of serving stale information.
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Filesystem_Functions_Unzip {

	/**
	 * Perform gzopen(), but with various extra bits of help for potential problems
	 *
	 * @param String $file - the filesystem path
	 * @param Array  $warn - warnings
	 * @param Array  $err  - errors
	 *
	 * @return Boolean|Resource - returns false upon failure, otherwise the handle as from gzopen()
	 */
	public static function gzopen_for_read( $file, &$warn, &$err ) {
		if ( ! function_exists( 'gzopen' ) || ! function_exists( 'gzread' ) ) {
			$missing = '';
			if ( ! function_exists( 'gzopen' ) ) {
				$missing .= 'gzopen';
			}
			if ( ! function_exists( 'gzread' ) ) {
				$missing .= ( $missing ) ? ', gzread' : 'gzread';
			}
			/* translators: %s: List of disabled PHP functions. */
			$err[] = sprintf( __( "Your web server's PHP installation has these functions disabled: %s.", 'backupplus' ), $missing ) . ' ' .
			sprintf(
				/* translators: %s: The process that requires the functions. */
				__( 'Your hosting company must enable these functions before %s can work.', 'backupplus' ),
				__( 'restoration', 'backupplus' )
			);
			return false;
		}
		if ( false === ( $dbhandle = gzopen( $file, 'r' ) ) ) {
			return false;
		}

		if ( ! function_exists( 'gzseek' ) ) {
			return $dbhandle;
		}

		if ( false === ( $bytes = gzread( $dbhandle, 3 ) ) ) {
			return false;
		}
		// Double-gzipped?
		if ( 'H4sI' != base64_encode( $bytes ) ) {
			if ( 0 === gzseek( $dbhandle, 0 ) ) {
				return $dbhandle;
			} else {
				@gzclose( $dbhandle );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
				return gzopen( $file, 'r' );
			}
		}
		// Yes, it's double-gzipped

		$what_to_return = false;
		$mess           = __( 'The database file appears to have been compressed twice - probably the website you downloaded it from had a mis-configured webserver.', 'backupplus' );
		$messkey        = 'doublecompress';
		$err_msg        = '';

		if ( false === ( $fnew = fopen( $file . '.tmp', 'w' ) ) || ! is_resource( $fnew ) ) {

			@gzclose( $dbhandle );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			$err_msg = __( 'The attempt to undo the double-compression failed.', 'backupplus' );

		} else {

			@fwrite( $fnew, $bytes );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			$emptimes = 0;
			while ( ! gzeof( $dbhandle ) ) {
				$bytes = @gzread( $dbhandle, 262144 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
				if ( empty( $bytes ) ) {
					++$emptimes;
					global $backupplus;
					$backupplus->log( "Got empty gzread ($emptimes times)" );
					if ( $emptimes > 2 ) {
						break;
					}
				} else {
					@fwrite( $fnew, $bytes );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
				}
			}

			gzclose( $dbhandle );
			fclose( $fnew );
			// On some systems (all Windows?) you can't rename a gz file whilst it's gzopened
			if ( ! rename( $file . '.tmp', $file ) ) {
				$err_msg = __( 'The attempt to undo the double-compression failed.', 'backupplus' );
			} else {
				$mess          .= ' ' . __( 'The attempt to undo the double-compression succeeded.', 'backupplus' );
				$messkey        = 'doublecompressfixed';
				$what_to_return = gzopen( $file, 'r' );
			}
		}

		$warn[ $messkey ] = $mess;
		if ( ! empty( $err_msg ) ) {
			$err[] = $err_msg;
		}
		return $what_to_return;
	}

	/**
	 * Unzips a specified ZIP file to a location on the filesystem via the WordPress
	 * Filesystem Abstraction. Forked from WordPress core in version 5.1-alpha-44182,
	 * to allow us to provide feedback on progress.
	 *
	 * Assumes that WP_Filesystem() has already been called and set up. Does not extract
	 * a root-level __MACOSX directory, if present.
	 *
	 * Attempts to increase the PHP memory limit before uncompressing. However,
	 * the most memory required shouldn't be much larger than the archive itself.
	 *
	 * @global WP_Filesystem_Base $wp_filesystem WordPress filesystem subclass.
	 *
	 * @param string  $file                    - Full path and filename of ZIP archive.
	 * @param string  $to                      - Full path on the filesystem to extract archive to.
	 * @param integer $starting_index          - index of entry to start unzipping from (allows resumption)
	 * @param array   $folders_to_look         - an array of folders that want to be extracted or not. It can contain:
	 *                                           * The names of the second-level folders. For example: '2025'.
	 *                                           * The relative paths of the folders. For example: '2025/12'.
	 * @param string  $extract_matched_folders - the value is either 'extract_only' or 'extract_except'.
	 *                                           * If the value is 'extract_only', then it'll extract only files in the '$folders_to_look' parameter.
	 *                                           * If the value is 'extract_except', then it'll extract all files except the ones in the '$folders_to_look' parameter.
	 *
	 * @return boolean|WP_Error True on success, WP_Error on failure.
	 */
	public static function unzip_file( $file, $to, $starting_index = 0, $folders_to_look = array(), $extract_matched_folders = 'extract_only' ) {
		global $wp_filesystem;

		if ( ! $wp_filesystem || ! is_object( $wp_filesystem ) ) {
			return new WP_Error( 'fs_unavailable', __( 'Could not access filesystem.' ) );// phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- The string exists within the WordPress core.
		}

		// Unzip can use a lot of memory, but not this much hopefully.
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		$needed_dirs = array();
		$to          = trailingslashit( $to );

		// Determine any parent dir's needed (of the upgrade directory)
		if ( ! $wp_filesystem->is_dir( $to ) ) { // Only do parents if no children exist
			$path = preg_split( '![/\\\]!', untrailingslashit( $to ) );
			for ( $i = count( $path ); $i >= 0; $i-- ) {

				if ( empty( $path[ $i ] ) ) {
					continue;
				}

				$dir = implode( '/', array_slice( $path, 0, $i + 1 ) );

				// Skip it if it looks like a Windows Drive letter.
				if ( preg_match( '!^[a-z]:$!i', $dir ) ) {
					continue;
				}

				// A folder exists; therefore, we don't need the check the levels below this
				if ( $wp_filesystem->is_dir( $dir ) ) {
					break;
				}

				$needed_dirs[] = $dir;

			}
		}

		static $added_unzip_action = false;
		if ( ! $added_unzip_action ) {
			add_action( 'backupplus_unzip_file_unzipped', array( 'BackupPlus_Filesystem_Functions', 'unzip_file_unzipped' ), 10, 5 );
			$added_unzip_action = true;
		}

		if ( class_exists( 'ZipArchive', false ) && apply_filters( 'unzip_file_use_ziparchive', true ) ) {
			$result = self::unzip_file_go( $file, $to, $needed_dirs, 'ziparchive', $starting_index, $folders_to_look, $extract_matched_folders );
			if ( true === $result || ( is_wp_error( $result ) && 'incompatible_archive' != $result->get_error_code() ) ) {
				return $result;
			}
			if ( is_wp_error( $result ) ) {
				global $backupplus;
				$backupplus->log( 'ZipArchive returned an error (will try again with PclZip): ' . $result->get_error_code() );
			}
		}

		// Fall through to PclZip if ZipArchive is not available, or encountered an error opening the file.
		// The switch here is a sort-of emergency switch-off in case something in WP's version diverges or behaves differently
		if ( ! defined( 'BACKUPPLUS_USE_INTERNAL_PCLZIP' ) || BACKUPPLUS_USE_INTERNAL_PCLZIP ) {
			return self::unzip_file_go( $file, $to, $needed_dirs, 'pclzip', $starting_index, $folders_to_look, $extract_matched_folders );
		} else {
			return _unzip_file_pclzip( $file, $to, $needed_dirs );
		}
	}

	/**
	 * Called upon the WP action backupplus_unzip_file_unzipped, to indicate that a file has been unzipped.
	 *
	 * @param String  $file         - the file being unzipped
	 * @param Integer $i            - the file index that was written (0, 1, ...)
	 * @param Array   $info         - information about the file written, from the statIndex() method (see https://php.net/manual/en/ziparchive.statindex.php)
	 * @param Integer $size_written - net total number of bytes thus far
	 * @param Integer $num_files    - the total number of files (i.e. one more than the the maximum value of $i)
	 */
	public static function unzip_file_unzipped( $file, $i, $info, $size_written, $num_files ) {

		global $backupplus;

		static $last_file_seen = null;

		static $last_logged_bytes;
		static $last_logged_index;
		static $last_logged_time;
		static $last_saved_time;

		$jobdata_key = self::get_jobdata_progress_key( $file );

		// Detect a new zip file; reset state
		if ( $file !== $last_file_seen ) {
			$last_file_seen    = $file;
			$last_logged_bytes = 0;
			$last_logged_index = 0;
			$last_logged_time  = time();
			$last_saved_time   = time();
		}

		// Useful for debugging
		$record_every_indexes = ( defined( 'BACKUPPLUS_UNZIP_PROGRESS_RECORD_AFTER_INDEXES' ) && BACKUPPLUS_UNZIP_PROGRESS_RECORD_AFTER_INDEXES > 0 ) ? BACKUPPLUS_UNZIP_PROGRESS_RECORD_AFTER_INDEXES : 1000;

		// We always log the last one for clarity (the log/display looks odd if the last mention of something being unzipped isn't the last). Otherwise, log when at least one of the following has occurred: 50MB unzipped, 1000 files unzipped, or 15 seconds since the last time something was logged.
		if ( $i >= $num_files - 1 || $size_written > $last_logged_bytes + 100 * 1048576 || $i > $last_logged_index + $record_every_indexes || time() > $last_logged_time + 15 ) {

			$backupplus->jobdata_set(
				$jobdata_key,
				array(
					'index'        => $i,
					'info'         => $info,
					'size_written' => $size_written,
				)
			);

			/* translators: 1: Current file number, 2: Total number of files */
			$backupplus->log( sprintf( __( 'Unzip progress: %1$d out of %2$d files', 'backupplus' ) . ' (%3$s, %4$s)', $i + 1, $num_files, BackupPlus_Manipulation_Functions::convert_numeric_size_to_text( $size_written ), $info['name'] ), 'notice-restore' );
			$backupplus->log( sprintf( 'Unzip progress: %1$d out of %2$d files (%3$s, %4$s)', $i + 1, $num_files, BackupPlus_Manipulation_Functions::convert_numeric_size_to_text( $size_written ), $info['name'] ), 'notice' );

			do_action( 'backupplus_unzip_progress_restore_info', $file, $i, $size_written, $num_files );

			$last_logged_bytes = $size_written;
			$last_logged_index = $i;
			$last_logged_time  = time();
			$last_saved_time   = time();
		}

		// Because a lot can happen in 5 seconds, we update the job data more often
		if ( time() > $last_saved_time + 5 ) {
			// N.B. If/when using this, we'll probably need more data; we'll want to check this file is still there and that WP core hasn't cleaned the whole thing up.
			$backupplus->jobdata_set(
				$jobdata_key,
				array(
					'index'        => $i,
					'info'         => $info,
					'size_written' => $size_written,
				)
			);
			$last_saved_time = time();
		}
	}

	/**
	 * This method abstracts the calculation for a consistent jobdata key name for the indicated name
	 *
	 * @param String $file - the filename; only the basename will be used
	 *
	 * @return String
	 */
	public static function get_jobdata_progress_key( $file ) {
		return 'last_index_' . md5( basename( $file ) );
	}

	/**
	 * Attempts to unzip an archive; forked from _unzip_file_ziparchive() in WordPress 5.1-alpha-44182, and modified to use the UD zip classes.
	 *
	 * Assumes that WP_Filesystem() has already been called and set up.
	 *
	 * @global WP_Filesystem_Base $wp_filesystem WordPress filesystem subclass.
	 *
	 * @param string  $file                    - full path and filename of ZIP archive.
	 * @param string  $to                      - full path on the filesystem to extract archive to.
	 * @param array   $needed_dirs             - a partial list of required folders needed to be created.
	 * @param string  $method                  - either 'ziparchive' or 'pclzip'.
	 * @param integer $starting_index          - index of entry to start unzipping from (allows resumption)
	 * @param array   $folders_to_look         - an array of folders that want to be extracted or not. It can contain:
	 *                                           * The names of the second-level folders. For example: '2025'.
	 *                                           * The relative paths of the folders. For example: '/2025/12'.
	 * @param string  $extract_matched_folders - the value is either 'extract_only' or 'extract_except'.
	 *                                           * If the value is 'extract_only', then it'll extract only files in the '$folders_to_look' parameter.
	 *                                           * If the value is 'extract_except', then it'll extract all files except the ones in the '$folders_to_look' parameter.
	 *
	 * @return boolean|WP_Error True on success, WP_Error on failure.
	 */
	private static function unzip_file_go( $file, $to, $needed_dirs = array(), $method = 'ziparchive', $starting_index = 0, $folders_to_look = array(), $extract_matched_folders = 'extract_only' ) {
		global $wp_filesystem, $backupplus;

		$class_to_use = ( 'ziparchive' == $method ) ? 'BackupPlus_ZipArchive' : 'BackupPlus_PclZip';

		// Check if the current filesystem is case-sensitive
		$case_sensitive_filesystem = self::really_is_writable( $to, true );

		if ( ! class_exists( $class_to_use ) ) {
			backupplus_try_include_file( 'includes/class-zip.php', 'require_once' );
		}

		$backupplus->log( 'Unzipping ' . basename( $file ) . ' to ' . $to . ' using ' . $class_to_use . ', starting index ' . $starting_index );

		$z = new $class_to_use();

		$flags = ( version_compare( PHP_VERSION, '5.2.12', '>' ) && defined( 'ZIPARCHIVE::CHECKCONS' ) ) ? ZIPARCHIVE::CHECKCONS : 4;

		// This is just for crazy people with mbstring.func_overload enabled (deprecated from PHP 7.2)
		// This belongs somewhere else
		// if ('BackupPlus_PclZip' == $class_to_use) mbstring_binary_safe_encoding();
		// if ('BackupPlus_PclZip' == $class_to_use) reset_mbstring_encoding();

		$zopen = $z->open( $file, $flags );

		if ( true !== $zopen ) {
			return new WP_Error( 'incompatible_archive', __( 'Incompatible Archive.' ), array( $method . '_error' => $z->last_error ) );// phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- The string exists within the WordPress core.
		}

		$uncompressed_size = 0;

		$num_files = $z->numFiles;

		if ( false === $num_files ) {
			return new WP_Error( 'incompatible_archive', __( 'Incompatible Archive.' ), array( $method . '_error' => $z->last_error ) );// phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- The string exists within the WordPress core.
		}
		for ( $i = $starting_index; $i < $num_files; $i++ ) {
			if ( ! $info = $z->statIndex( $i ) ) {
				return new WP_Error( 'stat_failed_' . $method, __( 'Could not retrieve file from archive.' ) . ' (' . $z->last_error . ')' );// phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- The string exists within the WordPress core.
			}

			// Skip the OS X-created __MACOSX directory
			if ( '__MACOSX/' === substr( $info['name'], 0, 9 ) ) {
				continue;
			}

			// Don't extract invalid files:
			if ( 0 !== validate_file( $info['name'] ) ) {
				continue;
			}

			if ( ! empty( $folders_to_look ) ) {
				// Don't create folders that we want to exclude
				$path                      = trim( BackupPlus_Manipulation_Functions::wp_normalize_path( $info['name'] ), '/' );
				$path                      = strstr( $path, '/' );
				$folder_matches_given_path = self::is_path_in_files( (string) $path, $folders_to_look, $case_sensitive_filesystem );
				if ( ( 'extract_only' === $extract_matched_folders && ! $folder_matches_given_path ) || ( 'extract_except' === $extract_matched_folders && $folder_matches_given_path ) ) {
					continue;
				}
			}

			$uncompressed_size += $info['size'];

			if ( '/' === substr( $info['name'], -1 ) ) {
				// Directory.
				$needed_dirs[] = $to . untrailingslashit( $info['name'] );
			} elseif ( '.' !== ( $dirname = dirname( $info['name'] ) ) ) {
				// Path to a file.
				$needed_dirs[] = $to . untrailingslashit( $dirname );
			}

			// Protect against memory over-use
			if ( 0 == $i % 500 ) {
				$needed_dirs = array_unique( $needed_dirs );
			}
		}

		/*
		* disk_free_space() could return false. Assume that any falsey value is an error.
		* A disk that has zero free bytes has bigger problems.
		* Require we have enough space to unzip the file and copy its contents, with a 10% buffer.
		*/
		if ( self::wp_doing_cron() ) {
			$available_space = function_exists( 'disk_free_space' ) ? @disk_free_space( WP_CONTENT_DIR ) : false;// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Call is speculative
			if ( $available_space && ( $uncompressed_size * 2.1 ) > $available_space ) {
				return new WP_Error( 'disk_full_unzip_file', __( 'Could not copy files.' ) . ' ' . __( 'You may have run out of disk space.' ), compact( 'uncompressed_size', 'available_space' ) );// phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- The string exists within the WordPress core.
			}
		}

		$needed_dirs = array_unique( $needed_dirs );
		foreach ( $needed_dirs as $dir ) {
			// Check the parent folders of the folders all exist within the creation array.
			if ( untrailingslashit( $to ) == $dir ) {
				// Skip over the working directory, We know this exists (or will exist)
				continue;
			}

			// If the directory is not within the working directory then skip it
			if ( false === strpos( $dir, $to ) ) {
				continue;
			}

			$parent_folder = dirname( $dir );
			while ( ! empty( $parent_folder ) && untrailingslashit( $to ) != $parent_folder && ! in_array( $parent_folder, $needed_dirs ) ) {
				$needed_dirs[] = $parent_folder;
				$parent_folder = dirname( $parent_folder );
			}
		}
		asort( $needed_dirs );

		// Create those directories if need be:
		foreach ( $needed_dirs as $_dir ) {
			// Only check to see if the Dir exists upon creation failure. Less I/O this way.
			if ( ! $wp_filesystem->mkdir( $_dir, FS_CHMOD_DIR ) && ! $wp_filesystem->is_dir( $_dir ) ) {
				return new WP_Error( 'mkdir_failed_' . $method, __( 'Could not create directory.' ), substr( $_dir, strlen( $to ) ) );// phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- The string exists within the WordPress core.
			}
		}
		unset( $needed_dirs );

		$size_written = 0;

		$content_cache         = array();
		$content_cache_highest = -1;

		for ( $i = $starting_index; $i < $num_files; $i++ ) {

			if ( ! $info = $z->statIndex( $i ) ) {
				return new WP_Error( 'stat_failed_' . $method, __( 'Could not retrieve file from archive.' ) );// phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- The string exists within the WordPress core.
			}

			// directory
			if ( '/' == substr( $info['name'], -1 ) ) {
				continue;
			}

			// Don't extract the OS X-created __MACOSX
			if ( '__MACOSX/' === substr( $info['name'], 0, 9 ) ) {
				continue;
			}

			// Don't extract invalid files:
			if ( 0 !== validate_file( $info['name'] ) ) {
				continue;
			}

			if ( ! empty( $folders_to_look ) ) {
				// Don't extract folders that we want to exclude
				$path                      = trim( BackupPlus_Manipulation_Functions::wp_normalize_path( $info['name'] ), '/' );
				$path                      = strstr( $path, '/', false ); // phpcs:ignore PHPCompatibility.FunctionUse.NewFunctionParameters.strstr_before_needleFound -- The third param ($before_needle) of the strstr function doesn't exist on PHP 5.2, but we don't longer support PHP 5.2.
				$folder_matches_given_path = self::is_path_in_files( (string) $path, $folders_to_look, $case_sensitive_filesystem );
				if ( ( 'extract_only' === $extract_matched_folders && ! $folder_matches_given_path ) || ( 'extract_except' === $extract_matched_folders && $folder_matches_given_path ) ) {
					continue;
				}
			}

			$is_stream_extract = false;

			// N.B. PclZip will return (boolean)false for an empty file
			if ( isset( $info['size'] ) && 0 == $info['size'] ) {
				$contents = '';
			} else {

				// BackupPlus_PclZip::getFromIndex() calls PclZip::extract(PCLZIP_OPT_BY_INDEX, array($i), PCLZIP_OPT_EXTRACT_AS_STRING), and this is expensive when done only one item at a time. We try to cache in chunks for good performance as well as being able to resume.
				if ( $i > $content_cache_highest && 'BackupPlus_PclZip' == $class_to_use ) {

					$memory_usage = memory_get_usage( false );
					$total_memory = $backupplus->memory_check_current();

					if ( $memory_usage > 0 && $total_memory > 0 ) {
						$memory_free = $total_memory * 1048576 - $memory_usage;
					} else {
						// A sane default. Anything is ultimately better than WP's default of just unzipping everything into memory.
						$memory_free = 50 * 1048576;
					}

					$use_memory = max( 10485760, $memory_free - 10485760 );

					$total_byte_count = 0;
					$content_cache    = array();
					$cache_indexes    = array();

					$cache_index = $i;
					while ( $cache_index < $num_files && $total_byte_count < $use_memory ) {
						if ( false !== ( $cinfo = $z->statIndex( $cache_index ) ) && isset( $cinfo['size'] ) && '/' != substr( $cinfo['name'], -1 ) && '__MACOSX/' !== substr( $cinfo['name'], 0, 9 ) && 0 === validate_file( $cinfo['name'] ) ) {
							$total_byte_count += $cinfo['size'];
							if ( $total_byte_count < $use_memory ) {
								$cache_indexes[]       = $cache_index;
								$content_cache_highest = $cache_index;
							}
						}
						++$cache_index;
					}

					if ( ! empty( $cache_indexes ) ) {
						$content_cache = $z->backupplus_getFromIndexBulk( $cache_indexes );
					}
				}

				if ( isset( $content_cache[ $i ] ) ) {
					$contents = $content_cache[ $i ];
				} elseif ( $backupplus->verify_free_memory( $info['size'] * 1.2 ) ) {
					$contents = $z->getFromIndex( $i );
				} else {
					// Use streaming extraction when remaining PHP memory is insufficient for in-memory extraction (ZIP + inflate overhead).
					if ( 'BackupPlus_PclZip' == $class_to_use ) {
						$extract_result = $z->extract( $to, $info['name'] );
						if ( ! $extract_result ) {
							return new WP_Error( 'extract_failed_' . $method, __( 'Could not extract file from archive.' ) );// phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- The string exists within the WordPress core.
						}
					} else {
						$stream = $z->getStream( $info['name'] );
						if ( $stream ) {
							$handle = fopen( $to . $info['name'], 'w' );
							if ( false === $handle ) {
								fclose( $stream );
								return new WP_Error( 'extract_failed_' . $method, __( 'Could not extract file from archive.' ) );// phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- The string exists within the WordPress core.
							}

							while ( ! feof( $stream ) ) {
								// 512KB chunks
								if ( false === fwrite( $handle, fread( $stream, 524288 ) ) ) {
									return new WP_Error( 'extract_failed_' . $method, __( 'Could not extract file from archive.' ) );// phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- The string exists within the WordPress core.
								}
							}

							fclose( $handle );
							fclose( $stream );
						}
					}
						$is_stream_extract = true;
				}
			}

			if ( ! $is_stream_extract && false === $contents && ( 'pclzip' !== $method || 0 !== $info['size'] ) ) {
				return new WP_Error( 'extract_failed_' . $method, __( 'Could not extract file from archive.' ) . ' ' . $z->last_error, json_encode( $info ) );// phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- The string exists within the WordPress core.
			}

			if ( ! $is_stream_extract && ! $wp_filesystem->put_contents( $to . $info['name'], $contents, FS_CHMOD_FILE ) ) {
				return new WP_Error( 'copy_failed_' . $method, __( 'Could not copy file.' ), $info['name'] );// phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- The string exists within the WordPress core.
			}

			if ( ! empty( $info['size'] ) ) {
				$size_written += $info['size'];
			}

			do_action( 'backupplus_unzip_file_unzipped', $file, $i, $info, $size_written, $num_files );

		}

		$z->close();

		return true;
	}
}
