<?php

/** Detection of the hosting environment, its limits and the site identity. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Core_Environment {

	/**
	 * Attempt to close the connection to the browser, optionally with some output sent first, whilst continuing execution
	 *
	 * @param String $txt - JSON-encoded output to send
	 */
	public function close_browser_connection( $txt = '' ) {
		// Close browser connection so that it can resume AJAX polling
		header( 'Content-Length: ' . ( empty( $txt ) ? '0' : 4 + strlen( $txt ) ) );
		header( 'Connection: close' );
		header( 'Content-Type: application/json' ); // Used to be 'none', but all inputs to this method are JSON-encoded
		if ( function_exists( 'session_id' ) && session_id() ) {
			session_write_close();
		}
		echo "\r\n\r\n";
		echo $txt; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- All inputs to this method are already JSON-encoded, and the output context is not HTML
		// These two added - 19-Feb-15 - started being required on local dev machine, for unknown reason (probably some plugin that started an output buffer).
		$ob_level = ob_get_level();
		while ( $ob_level > 0 ) {
			ob_end_flush();
			--$ob_level;
		}
		flush();
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		if ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
	}

	/**
	 * Returns the number of bytes free, if it can be detected; otherwise, false
	 * Presently, we only detect CPanel. If you know of others, then feel free to contribute!
	 */
	public function get_hosting_disk_quota_free() {
		if ( ( defined( 'BACKUPPLUS_SKIP_CPANEL_QUOTA_CHECK' ) && BACKUPPLUS_SKIP_CPANEL_QUOTA_CHECK ) || $this->detect_safe_mode() || ! @is_dir( '/usr/local/cpanel' ) || ! function_exists( 'popen' ) || ( ! @is_executable( '/usr/local/bin/perl' ) && ! @is_executable( '/usr/local/cpanel/3rdparty/bin/perl' ) ) ) {
			return false;// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}
		$perl = ( @is_executable( '/usr/local/cpanel/3rdparty/bin/perl' ) ) ? '/usr/local/cpanel/3rdparty/bin/perl' : '/usr/local/bin/perl';// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.

		$exec = "BACKUPPLUSKEY=backupplus $perl " . BACKUPPLUS_DIR . '/includes/get-cpanel-quota-usage.pl';

		// A case was seen of a web hosting company that disabled pclose() but not popen()
		$handle = ( function_exists( 'popen' ) && function_exists( 'pclose' ) ) ? @popen( $exec, 'r' ) : false; // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		if ( ! is_resource( $handle ) ) {
			return false;
		}

		$found = false;
		$lines = 0;
		while ( false === $found && ! feof( $handle ) && $lines < 100 ) {
			++$lines;
			$w = fgets( $handle );
			// Used, limit, remain
			if ( preg_match( '/RESULT: (\d+) (\d+) (\d+) /', $w, $matches ) ) {
				$found = true;
			}
		}
		$ret = pclose( $handle );
		// The manual page for pclose() claims that only -1 indicates an error, but this is untrue
		if ( false === $found || 0 != $ret ) {
			return false;
		}

		if ( (int) $matches[2] < 100 || ( $matches[1] + $matches[3] != $matches[2] ) ) {
			return false;
		}

		$this->cpanel_quota_readable = true;

		return $matches;
	}

	/**
	 * Get the installation's base table prefix, optionally allowing the result to be filtered
	 *
	 * @param Boolean $allow_override - allow the result to be filtered
	 *
	 * @return String
	 */
	public function get_table_prefix( $allow_override = false ) {
		global $wpdb;
		if ( is_multisite() && ! defined( 'MULTISITE' ) ) {
			// In this case (which should only be possible on installs upgraded from pre WP 3.0 WPMU), $wpdb->get_blog_prefix() cannot be made to return the right thing. $wpdb->base_prefix is not explicitly marked as public, so we prefer to use get_blog_prefix if we can, for future compatibility.
			$prefix = $wpdb->base_prefix;
		} else {
			$prefix = $wpdb->get_blog_prefix( 0 );
		}
		return $allow_override ? apply_filters( 'backupplus_get_table_prefix', $prefix ) : $prefix;
	}

	/**
	 * Get the character set for the current database connection
	 *
	 * @uses WPDB::determine_charset() - exists on WP 4.6+
	 *
	 * @param Object|Null $wpdb - WPDB object; if none passed, then use the global one
	 *
	 * @return String
	 */
	public function get_connection_charset( $wpdb = null ) {
		if ( null === $wpdb ) {
			global $wpdb;
		}

		$charset = ( defined( 'DB_CHARSET' ) && DB_CHARSET ) ? DB_CHARSET : 'utf8mb4';

		if ( method_exists( $wpdb, 'determine_charset' ) ) {
			$charset_collate = $wpdb->determine_charset( $charset, '' );
			if ( ! empty( $charset_collate['charset'] ) ) {
				$charset = $charset_collate['charset'];
			}
		}

		return $charset;
	}

	/**
	 * Get the WordPress version
	 *
	 * @return String - the version
	 */
	public function get_wordpress_version() {
		static $got_wp_version = false;
		if ( ! $got_wp_version ) {
			// This doesn't need to be global, but is kept just in case version.php changes its way of doing things in a future WP core release
			global $wp_version;
			include ABSPATH . WPINC . '/version.php';
			$got_wp_version = $wp_version;
		}
		return $got_wp_version;
	}

	/**
	 * Get the BackupPlus version and convert it to the correct format to be used in filenames
	 *
	 * @return String - the file version number
	 */
	public function get_backupplus_file_version() {

		if ( $this->use_unminified_scripts() ) {
			return '';
		}

		$version_parts = explode( '.', $this->version );
		$version_parts = array_slice( $version_parts, 0, 3 );
		$version       = implode( '.', $version_parts );

		return '-' . str_replace( '.', '-', $version ) . '.min';
	}

	/**
	 *
	 * Verifies that the indicated amount of memory is available
	 *
	 * @param Integer $how_many_bytes_needed - how many bytes need to be available
	 *
	 * @return Boolean - whether the needed number of bytes is available
	 */
	public function verify_free_memory( $how_many_bytes_needed ) {
		// This returns in MB
		$memory_limit = $this->memory_check_current();
		if ( ! is_numeric( $memory_limit ) ) {
			return false;
		}
		$memory_limit  = $memory_limit * 1048576;
		$memory_usage  = round( @memory_get_usage( false ), 1 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		$memory_usage2 = round( @memory_get_usage( true ), 1 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		if ( $memory_limit - $memory_usage > $how_many_bytes_needed && $memory_limit - $memory_usage2 > $how_many_bytes_needed ) {
			return true;
		}
		return false;
	}

	/**
	 * Get the maximum packet size on the WPDB MySQL connection, in bytes, after (optionally) attempting to raise it to 32MB if it appeared to be lower.
	 * A default value equal to 1MB is returned if the true value could not be found - it has been found reasonable to assume that at least this is available.
	 *
	 * @param Boolean $first_raise
	 * @param Boolean $log_it
	 *
	 * @return Integer
	 */
	public function max_packet_size( $first_raise = true, $log_it = true ) {
		global $wpdb;
		$mp = (int) $wpdb->get_var( 'SELECT @@session.max_allowed_packet' );
		// Default to 1MB
		$mp = ( is_numeric( $mp ) && $mp > 0 ) ? $mp : 1048576;
		// 32MB
		if ( $first_raise && $mp < 33554432 ) {
			$save = $wpdb->show_errors( false );
			$req  = @$wpdb->query( 'SET GLOBAL max_allowed_packet=33554432' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.
			$wpdb->show_errors( $save );
			if ( ! $req ) {
				$this->log( 'Tried to raise max_allowed_packet from ' . round( $mp / 1048576, 1 ) . ' MB to 32 MB, but failed (' . $wpdb->last_error . ', ' . serialize( $req ) . ')' );
			}
			$mp = (int) $wpdb->get_var( 'SELECT @@session.max_allowed_packet' );
			// Default to 1MB
			$mp = ( is_numeric( $mp ) && $mp > 0 ) ? $mp : 1048576;
		}
		if ( $log_it ) {
			$this->log( 'Max packet size: ' . round( $mp / 1048576, 1 ) . ' MB' );
		}
		return $mp;
	}

	/**
	 * Detect if safe_mode is on. N.B. This is abolished from PHP 7.0
	 *
	 * @return Integer - 1 or 0
	 */
	public function detect_safe_mode() {
		return ( @ini_get( 'safe_mode' ) && 'off' != strtolower( @ini_get( 'safe_mode' ) ) ) ? 1 : 0; // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, PHPCompatibility.IniDirectives.RemovedIniDirectives.safe_modeDeprecatedRemoved -- Silenced to suppress errors that may arise because of the function, ignore the removed ini directives compatibility.
	}

	/**
	 * Indicate which checksums to take for backup files. Abstracted for extensibilty and future changes.
	 *
	 * @returns array - a list of hashing algorithms, as understood by PHP's hash() function
	 */
	public function which_checksums() {
		return apply_filters( 'backupplus_which_checksums', array( 'sha1', 'sha256' ) );
	}

	public function memory_check_current( $memory_limit = false ) {
		// Returns in megabytes
		if ( false == $memory_limit ) {
			$memory_limit = ini_get( 'memory_limit' );
		}
		$memory_limit = rtrim( $memory_limit );
		$memory_unit  = $memory_limit[ strlen( $memory_limit ) - 1 ];
		if ( 0 == (int) $memory_unit && '0' !== $memory_unit ) {
			$memory_limit = substr( $memory_limit, 0, strlen( $memory_limit ) - 1 );
		} else {
			$memory_unit = '';
		}
		switch ( $memory_unit ) {
			case '':
				$memory_limit = floor( $memory_limit / 1048576 );
				break;
			case 'K':
			case 'k':
				$memory_limit = floor( $memory_limit / 1024 );
				break;
			case 'G':
				$memory_limit = $memory_limit * 1024;
				break;
			case 'M':
				// assumed size, no change needed
				break;
		}
		return $memory_limit;
	}

	public function memory_check( $memory, $check_using = false ) {
		$memory_limit = $this->memory_check_current( $check_using );
		return ( $memory_limit >= $memory ) ? true : false;
	}

	/**
	 * Retrieves current clean url for anchor link where href attribute value is not url (for ex. #div) or empty. Output is not escaped - caller should escape.
	 *
	 * @return String - current clean url
	 */
	public static function get_current_clean_url() {

		// CLI hooks can call this without a browser request.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return '';
		}

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$current_url = BackupPlus_Manipulation_Functions::fetch_superglobal( 'server', 'HTTP_REFERER' );
		} else {
			$url_prefix  = is_ssl() ? 'https' : 'http';
			$host        = BackupPlus_Manipulation_Functions::fetch_superglobal( 'server', 'HTTP_HOST', parse_url( network_site_url(), PHP_URL_HOST ) );
			$request_uri = BackupPlus_Manipulation_Functions::fetch_superglobal( 'server', 'REQUEST_URI', '' );
			$current_url = $url_prefix . '://' . $host . $request_uri;
		}
		$remove_query_args = array( 'state', 'action', 'oauth_verifier', 'nonce', 'backupplus_instance', 'access_token', 'user_id', 'backupplus_googledriveauth' );

		return BackupPlus_Manipulation_Functions::wp_unslash( remove_query_arg( $remove_query_args, $current_url ) );
	}

	/**
	 * Checks whether POSIX functions exists or not
	 *
	 * @return boolean true if POSIX functions exists or not
	 */
	private function do_posix_functions_exist() {
		return function_exists( 'posix_geteuid' ) && function_exists( 'posix_getuid' ) && function_exists( 'posix_getegid' ) && function_exists( 'posix_getgid' );
	}

	/**
	 * Checks whether debug mode is on or not. If it is on then unminified script will be used.
	 *
	 * @return boolean true indicate use the unminified script
	 */
	public function use_unminified_scripts() {
		return BackupPlus_Options::get_backupplus_option( 'backupplus_debug_mode' ) || ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG );
	}

	/**
	 * Retrieve the list of server (Apache, Nginx, PHP, etc..) configuration file names
	 */
	public function server_configuration_file_list() {
		$server_config_filenames = array(
			'.user.ini',
			'.htaccess',
		);
		// the default value for user_ini.filename setting in PHP.ini file is '.user.ini' but this could be set to use different file name
		$server_config_filenames[] = ini_get( 'user_ini.filename' ); // phpcs:ignore PHPCompatibility.IniDirectives.NewIniDirectives.user_ini_filenameFound -- Ignore ini_get function compatibility.
		$server_config_filenames   = array_unique( $server_config_filenames );
		return $server_config_filenames;
	}

	/**
	 * Check what hosting company that this plugin is installed onto and if there appear to be any restriction being applied to it
	 *
	 * @return Array An array of information regarding the hosting company or empty array if this method fails to recognise the hosting company
	 */
	public function get_hosting_info() {

		$hosting_company = array(
			'name'        => '',
			'website'     => '',
			'restriction' => array(),
		);

		if ( array_key_exists( 'KINSTA_CACHE_ZONE', $_SERVER ) ) {
			$hosting_company = array(
				'name'        => 'Kinsta',
				'website'     => 'kinsta.com',
				'restriction' => array(
					'only_one_backup_per_month',
					'only_one_incremental_per_day',
				),
			);
		}

		return apply_filters( 'backupplus_get_hosting_info', $hosting_company );
	}

	/**
	 * Check whether the hosting provider has some restriction
	 *
	 * @param String|Array $restriction An array or string of restriction
	 * @return Boolean True if the hosting provider has the given restriction, false otherwise
	 */
	public function is_restricted_hosting( $restriction ) {

		$restriction = (array) $restriction;

		$hosting_company = $this->get_hosting_info();

		if ( empty( $hosting_company ) ) {
			return false;
		}

		foreach ( $restriction as $rstc ) {
			if ( in_array( $rstc, $hosting_company['restriction'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether the hosting has a number of backups restrictions that can be created at a particular time and whether that number has reached the limit or the time elapsed has passed the limit
	 */
	public function is_hosting_backup_limit_reached() {
		$res         = array();
		$last_backup = BackupPlus_Options::get_backupplus_option( 'backupplus_last_backup', array() );
		if ( empty( $last_backup ) ) {
			$last_backup = array();
		}
		$current_time = time();
		if ( ! empty( $last_backup['incremental_backup_time'] ) ) {
			// $next_day_from_last_backup = strtotime(gmdate('Y-m-d', (int) $last_backup['backup_time'])) + 86400;
			$next_24hours_from_last_backup = strtotime( gmdate( 'Y-m-d H:i:s', (int) $last_backup['incremental_backup_time'] ) ) + 86400;
			// one incremental per day and the time has gone 24 hours past the last incremental backup time
			if ( $this->is_restricted_hosting( 'only_one_incremental_per_day' ) && $current_time < $next_24hours_from_last_backup ) {
				$res[] = 'only_one_incremental_per_day';
			}
		}
		if ( ! empty( $last_backup['nonincremental_backup_time'] ) ) {
			// $first_day_of_next_month_from_last_backup = strtotime(gmdate('Y-m-t', (int) $last_backup['backup_time']))+86400;
			$next_thirty_days_from_last_backup = strtotime( gmdate( 'Y-m-d H:i:s', (int) $last_backup['nonincremental_backup_time'] ) ) + ( 86400 * 30 );
			// Check whether the hosting provider permits only one backup per month and whether the time has gone 30 days past the last backup time
			if ( $this->is_restricted_hosting( 'only_one_backup_per_month' ) && $current_time < $next_thirty_days_from_last_backup ) {
				$res[] = 'only_one_backup_per_month';
			}
		}
		return $res;
	}

	/**
	 * Retrieve site name by outputing the hostname of the URL parsed
	 *
	 * @return String Site name
	 */
	private function get_site_name() {
		return preg_replace( '/^www\./i', '', strtolower( parse_url( network_site_url(), PHP_URL_HOST ) ) );
	}
}
