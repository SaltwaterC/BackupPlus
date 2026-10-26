<?php

/** The job log file, log lines and PHP error capture. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Core_Logging {

	/**
	 * Fetch information about the most recently modified log file
	 *
	 * @return Array - lists the modification time, the full path to the log file, and the log's nonce (ID)
	 */
	public function last_modified_log() {
		$backupplus_dir = $this->backups_dir_location();

		$log_file = '';
		$mod_time = false;
		$nonce    = '';

		if ( $handle = @opendir( $backupplus_dir ) ) {// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			while ( false !== ( $entry = readdir( $handle ) ) ) {
				// The latter match is for files created internally by zipArchive::addFile
				if ( preg_match( '/^log\.([a-z0-9]+)\.txt$/i', $entry, $matches ) ) {
					$mtime = filemtime( $backupplus_dir . '/' . $entry );
					if ( $mtime > $mod_time ) {
						$mod_time = $mtime;
						$log_file = $backupplus_dir . '/' . $entry;
						$nonce    = $matches[1];
					}
				}
			}
			@closedir( $handle );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}

		return array( $mod_time, $log_file, $nonce );
	}

	public function show_admin_warning_unreadablelog() {
		global $backupplus_admin;
		$backupplus_admin->show_admin_warning( '<strong>' . __( 'BackupPlus notice:', 'backupplus' ) . '</strong> ' . __( 'The log file could not be read.', 'backupplus' ) );
	}

	public function show_admin_warning_nolog() {
		global $backupplus_admin;
		$backupplus_admin->show_admin_warning( '<strong>' . __( 'BackupPlus notice:', 'backupplus' ) . '</strong> ' . __( 'No log files were found.', 'backupplus' ) );
	}

	public function show_admin_warning_unreadablefile() {
		global $backupplus_admin;
		$backupplus_admin->show_admin_warning( '<strong>' . __( 'BackupPlus notice:', 'backupplus' ) . '</strong> ' . __( 'The given file was not found, or could not be read.', 'backupplus' ) );
	}

	/**
	 * Opens the log file, writes a standardised header, and stores the resulting name and handle in the class variables logfile_name/logfile_handle/opened_log_time (and possibly backup_is_already_complete)
	 *
	 * @param String $nonce - Used in the log file name to distinguish it from other log files. Should be the job nonce.
	 * @returns void
	 */
	public function logfile_open( $nonce ) {

		$this->logfile_name = $this->get_logfile_name( $nonce );

		$this->backup_is_already_complete = $this->found_backup_complete_in_logfile( $nonce, false );

		$this->logfile_handle = fopen( $this->logfile_name, 'a' );

		$this->opened_log_time = microtime( true );

		$this->write_log_header( array( $this, 'log' ) );
	}

	/**
	 * Opens the log file, and finds if backup_is_already_complete
	 *
	 * @param String  $nonce               - Used in the log file name to distinguish it from other log files. Should be the job nonce.
	 * @param Boolean $use_existing_result - Whether to use any existing result or not
	 *
	 * @return boolean - returns true if the backup is complete otherwise returns false
	 */
	public function found_backup_complete_in_logfile( $nonce, $use_existing_result = true ) {

		static $checked_files = array();

		if ( isset( $checked_files[ $nonce ] ) && $use_existing_result ) {
			return $checked_files[ $nonce ];
		}
		$logfile_name = $this->get_logfile_name( $nonce );

		if ( ! file_exists( $logfile_name ) ) {
			return false;
		}

		$backup_is_already_complete = false;

		$seek_to = max( ( filesize( $logfile_name ) - 340 ), 1 );
		$handle  = fopen( $logfile_name, 'r' );
		if ( is_resource( $handle ) ) {
			// Returns 0 on success
			if ( 0 === @fseek( $handle, $seek_to ) ) {// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
				$bytes_back = filesize( $logfile_name ) - $seek_to;
				// Return to the end of the file
				$read_recent = fread( $handle, $bytes_back );
				// Move to end of file - ought to be redundant
				if ( ( false !== strpos( $read_recent, ') The backup apparently succeeded' ) || false !== strpos( $read_recent, ') The backup succeeded' ) ) && false !== strpos( $read_recent, 'and is now complete' ) ) {
					$backup_is_already_complete = true;
				}
			}
			fclose( $handle );
		}

		$checked_files[ $nonce ] = $backup_is_already_complete;

		return $backup_is_already_complete;
	}

	/**
	 * Returns the logfile name for a given job
	 *
	 * @param String $nonce - Used in the log file name to distinguish it from other log files. Should be the job nonce.
	 * @return string
	 */
	public function get_logfile_name( $nonce ) {
		$backupplus_dir = $this->backups_dir_location();
		return $backupplus_dir . "/log.$nonce.txt";
	}

	/**
	 * Writes a standardised header to the log file, using the specified logging function, which needs to be compatible with (or to be) BackupPlus::log()
	 *
	 * @param callable $logging_function
	 */
	public function write_log_header( $logging_function ) {

		global $wpdb;

		$backupplus_dir = $this->backups_dir_location();

		call_user_func( $logging_function, 'Opened log file at time: ' . gmdate( 'r' ) . ' on ' . network_site_url() );

		$wp_version    = $this->get_wordpress_version();
		$mysql_version = $wpdb->get_var( 'SELECT VERSION()' );
		if ( '' == $mysql_version ) {
			$mysql_version = $wpdb->db_version();
		}
		$safe_mode = $this->detect_safe_mode();

		$memory_limit  = ini_get( 'memory_limit' );
		$memory_usage  = round( @memory_get_usage( false ) / 1048576, 1 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		$memory_usage2 = round( @memory_get_usage( true ) / 1048576, 1 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.

		// Attempt to raise limit to avoid false positives
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( BACKUPPLUS_SET_TIME_LIMIT );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}       $max_execution_time = (int) @ini_get( 'max_execution_time' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.

		$mp = (int) $wpdb->get_var( 'SELECT @@session.max_allowed_packet' );

		$proxy = new WP_HTTP_Proxy();

		$server_software = BackupPlus_Manipulation_Functions::fetch_superglobal( 'server', 'SERVER_SOFTWARE', '' );
		$logline         = 'BackupPlus, a GPL fork of the UpdraftPlus WordPress backup plugin: ' . $this->version . ' WP: ' . $wp_version . ' PHP: ' . phpversion() . ' (' . PHP_SAPI . ', ' . ( function_exists( 'php_uname' ) ? @php_uname() : PHP_OS ) . ") MySQL: $mysql_version (max packet size=$mp) WPLANG: " . get_locale() . ' Server: ' . $server_software . " safe_mode: $safe_mode max_execution_time: $max_execution_time memory_limit: $memory_limit (used: {$memory_usage}M | {$memory_usage2}M) multisite: " . ( is_multisite() ? ( is_subdomain_install() ? 'Y (sub-domain)' : 'Y (sub-folder)' ) : 'N' ) . ' openssl: ' . ( defined( 'OPENSSL_VERSION_TEXT' ) ? OPENSSL_VERSION_TEXT : 'N' ) . ' mcrypt: ' . ( function_exists( 'mcrypt_encrypt' ) ? 'Y' : 'N' ) . ' LANG: ' . getenv( 'LANG' ) . ' WP Proxy: ' . ( $proxy->is_enabled() ? 'enabled' : 'disabled' ) . ' ZipArchive::addFile: ';// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.

		// method_exists causes some faulty PHP installations to segfault, leading to support requests
		if ( version_compare( phpversion(), '5.2.0', '>=' ) && extension_loaded( 'zip' ) ) {
			$logline .= 'Y';
		} else {
			$logline .= ( class_exists( 'ZipArchive' ) && method_exists( 'ZipArchive', 'addFile' ) ) ? 'Y' : 'N';
		}

		if ( 0 === $this->current_resumption ) {
			$memlim = $this->memory_check_current();
			if ( $memlim < 65 && $memlim > 0 ) {
				/* translators: %s: Memory limit in MB */
				$this->log( sprintf( __( 'The amount of memory (RAM) allowed for PHP is very low (%s Mb) - you should increase it to avoid failures due to insufficient memory (consult your web hosting company for more help)', 'backupplus' ), round( $memlim, 1 ) ), 'warning', 'lowram' );
			}
			if ( $max_execution_time > 0 && $max_execution_time < 20 ) {
				/* translators: 1: Execution time in seconds, 2: Recommended execution time in seconds */
				call_user_func( $logging_function, sprintf( __( 'The amount of time allowed for WordPress plugins to run is very low (%1$s seconds) - you should increase it to avoid backup failures due to time-outs (consult your web hosting company for more help - it is the max_execution_time PHP setting; the recommended value is %2$s seconds or more)', 'backupplus' ), $max_execution_time, 90 ), 'warning', 'lowmaxexecutiontime' );
			}
		}

		call_user_func( $logging_function, $logline );

		$hosting_bytes_free = $this->get_hosting_disk_quota_free();
		if ( is_array( $hosting_bytes_free ) ) {
			$perc       = round( 100 * $hosting_bytes_free[1] / ( max( $hosting_bytes_free[2], 1 ) ), 1 );
			$quota_free = ' / ' . sprintf( 'Free disk space in account: %s (%s used)', round( $hosting_bytes_free[3] / 1048576, 1 ) . ' MB', "$perc %" );
			if ( $hosting_bytes_free[3] < 1048576 * 50 ) {
				$quota_free_mb = round( $hosting_bytes_free[3] / 1048576, 1 );
				/* translators: %s: Available disk space in MB */
				call_user_func( $logging_function, sprintf( __( 'Your free space in your hosting account is very low - only %s Mb remain', 'backupplus' ), $quota_free_mb ), 'warning', 'lowaccountspace' . $quota_free_mb );
			}
		} else {
			$quota_free = '';
		}

		$disk_free_space = function_exists( 'disk_free_space' ) ? @disk_free_space( $backupplus_dir ) : false;// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		// == rather than === here is deliberate; support experience shows that a result of (int)0 is not reliable. i.e. 0 can be returned when the real result should be false.
		if ( false == $disk_free_space ) {
			call_user_func( $logging_function, "Free space on disk containing BackupPlus's temporary directory: Unknown" . $quota_free );
		} else {
			call_user_func( $logging_function, "Free space on disk containing BackupPlus's temporary directory: " . round( $disk_free_space / 1048576, 1 ) . ' MB' . $quota_free );
			$disk_free_mb = round( $disk_free_space / 1048576, 1 );
			/* translators: %s: Available disk space in MB */
			if ( $disk_free_space < 50 * 1048576 ) {
				call_user_func( $logging_function, sprintf( __( 'Your free disk space is very low - only %s Mb remain', 'backupplus' ), round( $disk_free_space / 1048576, 1 ) ), 'warning', 'lowdiskspace' . $disk_free_mb );
			}
		}
	}

	/**
	 * Logs the given line, adding (relative) time stamp and newline
	 * Note these subtleties of log handling:
	 * - Messages at level 'error' are not logged to file - it is assumed that a separate call to log() at another level will take place. This is because at level 'error', messages are translated; whereas the log file is for developers who may not know the translated language. Messages at level 'error' are for the user.
	 * - Messages at level 'error' do not persist through the job (they are only saved with save_backup_to_history(), and never restored from there - so only the final save_backup_to_history() errors
	 * persist); we presume that either a) they will be cleared on the next attempt, or b) they will occur again on the final attempt (at which point they will go to the user). But...
	 * - messages at level 'warning' persist. These are conditions that are unlikely to be cleared, not-fatal, but the user should be informed about. The $uniq_id field (which should not be numeric) can then be used for warnings that should only be logged once
	 * $skip_dblog = true is suitable when there's a risk of excessive logging, and the information is not important for the user to see in the browser on the settings page
	 * The uniq_id field is also used with PHP event detection - it is set then to 'php_event' - which is useful for anything hooking the action to detect
	 *
	 * @param  string  $line       the log line
	 * @param  string  $level      the log level: notice, warning, error. If suffixed with a hyphen and a destination, then the default destination is changed too.
	 * @param  boolean $uniq_id    each of these will only be logged once
	 * @param  boolean $skip_dblog if true, then do not write to the database
	 * @return null
	 */
	public function log( $line, $level = 'notice', $uniq_id = false, $skip_dblog = false ) {

		$destination = 'default';
		if ( preg_match( '/^([a-z]+)-([a-z]+)$/', $level, $matches ) ) {
			$level       = $matches[1];
			$destination = $matches[2];
		}

		if ( 'error' == $level || 'warning' == $level ) {
			if ( 'error' == $level && 0 == $this->error_count() ) {
				$this->log( 'An error condition has occurred for the first time during this job' );
			}
			if ( $uniq_id ) {
				$this->errors[ $uniq_id ] = array(
					'level'   => $level,
					'message' => $line,
				);
			} else {
				$this->errors[] = array(
					'level'   => $level,
					'message' => $line,
				);
			}
			// Errors are logged separately
			if ( 'error' == $level ) {
				return;
			}
			// It's a warning
			$warnings = $this->jobdata_get( 'warnings' );
			if ( ! is_array( $warnings ) ) {
				$warnings = array();
			}
			if ( $uniq_id ) {
				$warnings[ $uniq_id ] = $line;
			} else {
				$warnings[] = $line;
			}
			$this->jobdata_set( 'warnings', $warnings );
		}

		if ( false === ( $line = apply_filters( 'backupplus_logline', $line, $this->nonce, $level, $uniq_id, $destination ) ) ) {
			return;
		}

		if ( $this->logfile_handle ) {
			// Record log file times relative to the backup start, if possible
			$rtime = ( ! empty( $this->job_time_ms ) ) ? microtime( true ) - $this->job_time_ms : microtime( true ) - $this->opened_log_time;
			fwrite( $this->logfile_handle, sprintf( '%08.03f', round( $rtime, 3 ) ) . ' (' . $this->current_resumption . ') ' . ( ( 'notice' != $level ) ? '[' . ucfirst( $level ) . '] ' : '' ) . $line . "\n" );
		}

		switch ( $this->jobdata_get( 'job_type' ) ) {
			case 'download':
				// Download messages are keyed on the job (since they could be running several), and type
				// The values of the POST array were checked before
				list($findex, $timestamp, $type) = array_values(
					BackupPlus_Manipulation_Functions::fetch_superglobal_array(
						array( 'post', 'findex', 0 ),
						array( 'post', 'timestamp' ),
						array( 'post', 'type' )
					)
				);

				if ( $timestamp && $type ) {
					$this->jobdata_set( 'dlmessage_' . $timestamp . '_' . $type . '_' . $findex, $line );
				}
				break;

			case 'restore':
				// if ('debug' != $level) echo $line."\n";
				break;

			case 'cleanup':
				// The dashboard's last message describes backups; cleanup results are shown separately.
				break;

			default:
				if ( ! $skip_dblog && 'debug' != $level ) {
					BackupPlus_Options::update_backupplus_option( 'backupplus_lastmessage', $line . ' (' . date_i18n( 'M d H:i:s' ) . ')', false );
				}
				break;
		}

		if ( defined( 'BACKUPPLUS_CONSOLELOG' ) && BACKUPPLUS_CONSOLELOG ) {
			echo $line . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- This constant can only be set deliberately by a user who is indicating he requires raw output on in a console (i.e. non-HTML) context
		}       if ( defined( 'BACKUPPLUS_BROWSERLOG' ) && BACKUPPLUS_BROWSERLOG ) {
			echo esc_html( $line ) . "<br>\n";
		}
	}

	/**
	 * Remove any logged warnings with the specified identifier. (The use case for this is that you can warn of something that may be about to happen (with a probably crash if it does), and then remove the warning if it did not happen).
	 *
	 * @see self::log()
	 *
	 * @param String $uniq_id - the identifier, previously passed to self::log()
	 */
	public function log_remove_warning( $uniq_id ) {
		$warnings = $this->jobdata_get( 'warnings' );
		if ( ! is_array( $warnings ) ) {
			$warnings = array();
		}
		// Avoid an unnecessary database write if nothing changed
		if ( isset( $warnings[ $uniq_id ] ) ) {
			unset( $warnings[ $uniq_id ] );
			$this->jobdata_set( 'warnings', $warnings );
		}
		unset( $this->errors[ $uniq_id ] );
	}

	/**
	 * Indicate whether or not a warning is logged with a specific identifier
	 *
	 * @see self::log()
	 *
	 * @param String $uniq_id - the identifier, previously passed to self::log()
	 *
	 * @return Boolean
	 */
	public function warning_exists( $uniq_id ) {
		$warnings = $this->jobdata_get( 'warnings' );
		return ! empty( $warnings[ $uniq_id ] );
	}

	/**
	 * For efficiency, you can also feed false or a string into this function
	 *
	 * @param  Boolean|String|WP_Error $err      - the errors
	 * @param  Boolean                 $echo     - whether to echo() the error(s)
	 * @param  Boolean                 $logerror - whether to pass errors to BackupPlus::log()
	 * @return Boolean - returns false for convenience
	 */
	public function log_wp_error( $err, $echo = false, $logerror = false ) {
		if ( false === $err ) {
			return false;
		}
		if ( is_string( $err ) ) {
			$this->log( "Error message: $err" );
			/* translators: %s: Error message */
			if ( $echo ) {
				$this->log( sprintf( __( 'Error: %s', 'backupplus' ), $err ), 'notice-warning' );
			}
			if ( $logerror ) {
				$this->log( $err, 'error' );
			}
			return false;
		}
		foreach ( $err->get_error_messages() as $msg ) {
			$this->log( "Error message: $msg" );
			/* translators: %s: Error message */
			if ( $echo ) {
				$this->log( sprintf( __( 'Error: %s', 'backupplus' ), $msg ), 'notice-warning' );
			}
			if ( $logerror ) {
				$this->log( $msg, 'error' );
			}
		}
		$codes = $err->get_error_codes();
		if ( is_array( $codes ) ) {
			foreach ( $codes as $code ) {
				$data = $err->get_error_data( $code );
				if ( ! empty( $data ) ) {
					$ll = ( is_string( $data ) ) ? $data : serialize( $data );
					$this->log( 'Error data (' . $code . '): ' . $ll );
				}
			}
		}
		// Returns false so that callers can return with false more efficiently if they wish
		return false;
	}

	/**
	 * This function will construct the restore information log line using the passed in parameters and then log the line using $this->log();
	 *
	 * @param array $restore_information - an array of restore information
	 *
	 * @return void
	 */
	public function log_restore_update( $restore_information ) {
		$this->log( 'RINFO:' . json_encode( $restore_information ), 'notice-progress' );
	}

	/**
	 * Outputs data to the browser.
	 * Will also fill the buffer on nginx systems after a specified amount of time.
	 *
	 * @param String $line The text to output. This may validly include HTML.
	 *
	 * @return void
	 */
	public function output_to_browser( $line ) {
		echo wp_kses_post( $line );
		$server_software = BackupPlus_Manipulation_Functions::fetch_superglobal( 'server', 'SERVER_SOFTWARE', '' );
		if ( false === stripos( $server_software, 'nginx' ) ) {
			return;
		}
		// Change it to what was actually output
		$line            = wp_kses_post( $line );
		static $strcount = 0;
		static $time     = 0;
		$buffer_size     = 65536; // The default NGINX config uses a buffer size of 32 or 64k, depending on the system. So we use 64K.
		if ( 0 == $time ) {
			$time = time();
		}
		$strcount += strlen( $line );
		if ( ( time() - $time ) >= 8 ) {
			// if the string count is > the buffer size, we reset, as it's likely the string was already sent.
			if ( $strcount > $buffer_size ) {
				$time     = time();
				$strcount = $strcount - $buffer_size;
				return;
			}
			echo str_repeat( ' ', $buffer_size - $strcount ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Absurd false positive
			// reset values
			$time     = time();
			$strcount = 0;
		}
	}

	/**
	 * Q. Why is this abstracted into a separate function? A. To allow poedit and other parsers to pick up the need to translate strings passed to it (and not pick up all of those passed to log()).
	 * 1st argument = the line to be logged (obligatory)
	 * Further arguments = parameters for sprintf()
	 *
	 * @return null
	 */
	public function log_e() {
		$args = func_get_args();
		// Get first argument
		$pre_line = array_shift( $args );
		// Log it whilst still in English
		if ( is_wp_error( $pre_line ) ) {
			$this->log_wp_error( $pre_line );
		} else {
			// Now run (v)sprintf on it, using any remaining arguments. vsprintf = sprintf but takes an array instead of individual arguments
			$this->log( vsprintf( $pre_line, $args ) );
			// This is slightly hackish, in that we have no way to use a different level or destination. In that case, the caller should instead call log() twice with different parameters, instead of using this convenience function.
			$this->log( vsprintf( $pre_line, $args ), 'notice-restore' );
		}
	}

	/**
	 * This function returns a list of specific php error messages and their action block
	 *
	 * @return array An associative array containing information about certain specific php errors and their error messages.
	 */
	private function php_specific_error_handler_data() {
		$handle_specific_messages = array(
			'open_basedir restriction.*/cpanel' => array(
				'action'      => 'replace',
				'action_data' => 'Could not ask cPanel about disk space (open_basedir) - this is not an error',
			),
			'ftp_nb_fput\(\): php_connect_nonb\(\) failed: Operation now in progress' => array(
				'action'      => 'append',
				'action_data' => "\nPHP logs this condition if a firewall blocked your FTP data channel from your webserver to your FTP server (but allowed your FTP control channel). You will need to speak to one or both of the administrators of those two servers to investigate (note that BackupPlus support cannot access any more information about the network than this PHP notice gives - talking to the administrators who can access that information is the next step).",
			),
		);
		return $handle_specific_messages;
	}

	public function php_error_to_logline( $errno, $errstr, $errfile, $errline ) {
		switch ( $errno ) {
			case 1:
				$e_type = 'E_ERROR';
				break;
			case 2:
				$e_type = 'E_WARNING';
				break;
			case 4:
				$e_type = 'E_PARSE';
				break;
			case 8:
				$e_type = 'E_NOTICE';
				break;
			case 16:
				$e_type = 'E_CORE_ERROR';
				break;
			case 32:
				$e_type = 'E_CORE_WARNING';
				break;
			case 64:
				$e_type = 'E_COMPILE_ERROR';
				break;
			case 128:
				$e_type = 'E_COMPILE_WARNING';
				break;
			case 256:
				$e_type = 'E_USER_ERROR';
				break;
			case 512:
				$e_type = 'E_USER_WARNING';
				break;
			case 1024:
				$e_type = 'E_USER_NOTICE';
				break;
			case 2048:
				$e_type = 'E_STRICT';
				break;
			case 4096:
				$e_type = 'E_RECOVERABLE_ERROR';
				break;
			case 8192:
				$e_type = 'E_DEPRECATED';
				break;
			case 16384:
				$e_type = 'E_USER_DEPRECATED';
				break;
			case 30719:
				$e_type = 'E_ALL';
				break;
			default:
				$e_type = "E_UNKNOWN ($errno)";
				break;
		}

		if ( false !== stripos( $errstr, 'table which is not valid in this version of Gravity Forms' ) ) {
			return false;
		}

		if ( ! is_string( $errstr ) ) {
			$errstr = serialize( $errstr );
		}

		if ( 0 === strpos( $errfile, ABSPATH ) ) {
			$errfile = substr( $errfile, strlen( ABSPATH ) );
		}

		if ( 'E_DEPRECATED' == $e_type && ! empty( $this->no_deprecation_warnings ) ) {
			return false;
		}

		$error_string = "PHP event: code $e_type: $errstr";

		foreach ( $this->php_specific_error_handler_data() as $pattern => $action_block ) {

			if ( preg_match( '#' . $pattern . '#i', $error_string ) ) {
				if ( 'replace' == $action_block['action'] ) {
					$error_string = 'PHP event: ' . $action_block['action_data'];
				}

				if ( 'append' == $action_block['action'] ) {
					$error_string = $error_string . ' ' . $action_block['action_data'];
				}
			}
		}

		$error_string .= " (line $errline, $errfile)";

		return $error_string;
	}

	/**
	 * Custom PHP error handler. This will print the error to the log file.
	 *
	 * @param integer $errno   - The level of the error raised
	 * @param string  $errstr  - The error message
	 * @param string  $errfile - The filename that the error was raised in
	 * @param integer $errline - The line number the error was raised at
	 *
	 * @return bool If the function returns false, the standard PHP error handler will continue to process the error
	 */
	public function php_error( $errno, $errstr, $errfile, $errline ) {
		// Unlike in PHP 7.4 and lower, the 'error_reporting()' function in PHP 8.0+ will return the value of this bitwise expression: 'E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR | E_PARSE' instead of '0' when we suppress the error using the '@' operator.
		$error_level = error_reporting(); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting -- The error_reporting() function is used to get the current PHP error level.
		$fatal_mask  = E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR | E_PARSE;
		if ( 0 === $error_level || 0 === ( $error_level & ~$fatal_mask ) ) {
			return true;
		}

		$logline = $this->php_error_to_logline( $errno, $errstr, $errfile, $errline );
		if ( false !== $logline ) {
			$this->log( $logline, 'notice', 'php_event' );
		}
		// Pass it up the chain
		return $this->error_reporting_stop_when_logged;
	}

	/**
	 * Get log message for permission failure
	 *
	 * @param String $path                            full path of file or folder
	 * @param String $log_message_prefix              action which is performed to path
	 * @param String $directory_prefix_in_log_message Directory Prefix. It should be either "Parent" or "Destination"
	 * @return string|boolean log message (HTML). If posix function doesn't exist, It returns false
	 */
	public function log_permission_failure_message( $path, $log_message_prefix, $directory_prefix_in_log_message = 'Parent' ) {
		if ( $this->do_posix_functions_exist() ) {
			$stat_data    = stat( $path );
			$log_message  = $log_message_prefix . ': Failed. ';
			$log_message .= $directory_prefix_in_log_message . ' Directory UID=' . $stat_data['uid'] . ', GID=' . $stat_data['gid'] . '. ';
			$log_message .= $this->get_log_message_for_current_uid_and_gid();
			return $log_message;
		} else {
			return false;
		}
	}

	/**
	 * Get log message for current uid and gid
	 *
	 * @return String log message of current process (HTML)
	 */
	private function get_log_message_for_current_uid_and_gid() {
		$log_message  = 'Effective/real user IDs of the current process: ' . posix_geteuid() . '/' . posix_getuid() . '. ';
		$log_message .= 'Effective/real group IDs of the current process: ' . posix_getegid() . '/' . posix_getgid() . '. ';
		return $log_message;
	}
}
