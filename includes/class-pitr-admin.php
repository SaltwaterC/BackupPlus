<?php

/** Administrator controls for database log backups. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

class BackupPlus_PITR_Admin {

	public static function register() {
		add_action( 'admin_init', array( __CLASS__, 'settings' ) );
		add_action( 'init', array( __CLASS__, 'retire_saved_restore' ), 1 );
		add_action( 'updated_option', array( __CLASS__, 'updated' ), 10, 3 );
		add_action(
			'added_option',
			function ( $name, $value ) {
				self::updated( $name, null, $value );
			},
			10,
			2
		);
	}

	/** The previous experimental saved restore login must no longer remain configured. */
	public static function retire_saved_restore() {
		if ( null !== get_option( 'backupplus_pitr_recovery', null ) ) { delete_option( 'backupplus_pitr_recovery' ); }
	}

	public static function settings() {
		register_setting( 'backupplus-options-group', BackupPlus_PITR::OPTION, array( __CLASS__, 'sanitize' ) );
	}

	/** Keep the interval form separate from the encrypted backup-account dialog. */
	public static function sanitize( $value ) {
		$current = BackupPlus_PITR::config();
		if ( ! is_array( $value ) ) {
			return $current;
		}

		$interval = isset( $value['interval'] ) && is_scalar( $value['interval'] ) ? (string) $value['interval'] : '';
		if ( ! in_array( $interval, array_map( 'strval', array_merge( array( 0 ), BackupPlus_PITR::intervals() ) ), true ) ) {
			self::error( __( 'Choose a supported capture interval.', 'backupplus' ) );
			return $current;
		}
		$enabled = '0' !== $interval;
		$exact   = array_key_exists( 'exact', $value ) ? $value['exact'] : ( isset( $current['exact'] ) ? $current['exact'] : 0 );
		if ( ! in_array( $exact, array( '', '0', '1', 0, 1, false, true ), true ) ) {
			self::error( __( 'The exact time setting for database point-in-time recovery is invalid.', 'backupplus' ) );
			return $current;
		}
		$exact = $enabled && 1 !== (int) $interval && ! empty( $exact );
		$time  = isset( $value['time'] ) && is_scalar( $value['time'] ) ? (string) $value['time'] : ( isset( $current['time'] ) ? (string) $current['time'] : '' );
		if ( $exact ) {
			$recurrence = 'backupplus_pitr_' . (int) $interval;
			if ( ! BackupPlus_Backup_Schedule::supports_exact( $recurrence ) || ! BackupPlus_Backup_Schedule::valid_time( $time ) ) {
				self::error( __( 'Choose a valid exact time for database point-in-time recovery.', 'backupplus' ) );
				return $current;
			}
			$timing = BackupPlus_Backup_Schedule::normalize( $recurrence, true, $time, null );
			$time   = $timing['time'];
		} else {
			$time = BackupPlus_Backup_Schedule::valid_time( $time ) ? BackupPlus_Backup_Schedule::sanitize_time( $time ) : '';
		}

		if ( $enabled ) {
			try {
				$current = array_merge( $current, self::wordpress_configuration() );
			} catch ( Throwable $error ) {
				self::error( $error->getMessage() );
				return $current;
			}
		}

		if ( $enabled && empty( $current['source_config'] ) ) {
			self::error( __( 'Could not prepare the WordPress database connection for point-in-time recovery.', 'backupplus' ) );
			return $current;
		}
		$current['enabled']  = $enabled;
		$current['interval'] = $enabled ? (int) $interval : ( isset( $current['interval'] ) ? $current['interval'] : 15 );
		$current['exact']    = $exact;
		$current['time']     = $time;
		unset( $current['global_read_lock'] );
		return $current;
	}

	private static function error( $message ) {
		add_settings_error( BackupPlus_PITR::OPTION, 'invalid_pitr', $message, 'error' );
	}

	public static function updated( $name, $previous, $value ) {
		if ( BackupPlus_PITR::OPTION === $name ) {
			BackupPlus_PITR::reconcile_schedule();
		}
	}

	/** Derive engine, credentials and host from the active WordPress database connection. */
	public static function wordpress_configuration( $native_directory = null, $temporary_directory = null ) {
		global $wpdb, $backupplus;
		if ( ! is_object( $wpdb ) || ! is_callable( array( $wpdb, 'db_version' ) ) ) {
			throw new RuntimeException( __( 'WordPress could not identify its active database connection.', 'backupplus' ) );
		}
		if ( ! function_exists( 'proc_open' ) || '\\' === DIRECTORY_SEPARATOR ) {
			throw new RuntimeException( __( 'Database point-in-time recovery requires PHP process support on a Unix host.', 'backupplus' ) );
		}
		// db_version() strips the vendor suffix on WordPress; keep it for MariaDB detection.
		$version = is_callable( array( $wpdb, 'db_server_info' ) ) ? $wpdb->db_server_info() : $wpdb->db_version();
		$engine  = false !== stripos( $version, 'mariadb' ) ? 'mariadb' : 'mysql';
		$host    = isset( $wpdb->dbhost ) && is_string( $wpdb->dbhost ) ? $wpdb->dbhost : ( defined( 'DB_HOST' ) ? DB_HOST : 'localhost' );
		$user    = isset( $wpdb->dbuser ) && is_string( $wpdb->dbuser ) ? $wpdb->dbuser : ( defined( 'DB_USER' ) ? DB_USER : '' );
		$pass    = isset( $wpdb->dbpassword ) && is_string( $wpdb->dbpassword ) ? $wpdb->dbpassword : ( defined( 'DB_PASSWORD' ) ? DB_PASSWORD : '' );
		$db_name = defined( 'DB_NAME' ) ? DB_NAME : ( isset( $wpdb->dbname ) ? $wpdb->dbname : '' );
		if ( '' === $user || ! is_string( $db_name ) || ! preg_match( '/\\A[A-Za-z0-9_$-]+\\z/D', $db_name ) ) {
			throw new RuntimeException( __( 'WordPress database connection details are incomplete or unsupported.', 'backupplus' ) );
		}

		$connection = self::parse_host( $host );
		$directory  = null === $native_directory ? self::native_directory( $engine ) : $native_directory;
		$tools      = 'mariadb' === $engine ? array( 'mariadb', 'mariadb-dump', 'mariadb-binlog' ) : array( 'mysql', 'mysqldump', 'mysqlbinlog' );
		foreach ( $tools as $tool ) {
			BackupPlus_PITR_Process::binary( $tool, $directory );
		}

		$local = '' !== $connection['socket'] || in_array( strtolower( $connection['host'] ), array( 'localhost', '127.0.0.1', '::1' ), true );
		$ca    = '';
		if ( ! $local ) {
			$ca = self::system_ca_bundle();
			if ( '' === $ca ) {
				throw new RuntimeException( __( 'The WordPress database uses a remote host, but no system CA bundle is available to verify it securely.', 'backupplus' ) );
			}
		}

		$lines = array( '[client]', 'host=' . self::ini_value( $connection['host'] ), 'user=' . self::ini_value( $user ), 'password=' . self::ini_value( $pass ) );
		if ( '' !== $connection['socket'] ) {
			$lines[] = 'socket=' . self::ini_value( $connection['socket'] );
			$lines[] = 'protocol=socket';
		} elseif ( '' !== $connection['port'] ) {
			$lines[] = 'port=' . $connection['port'];
			$lines[] = 'protocol=tcp';
		}
		if ( ! $local ) {
			$lines[] = 'ssl-ca=' . self::ini_value( $ca );
			$lines[] = 'mysql' === $engine ? 'ssl-mode=VERIFY_IDENTITY' : 'ssl-verify-server-cert=1';
		} elseif ( 'mariadb' === $engine ) {
			$tls = $wpdb->get_row( "SHOW SESSION STATUS LIKE 'Ssl_cipher'", ARRAY_N );
			if ( is_array( $tls ) && isset( $tls[1] ) && '' === $tls[1] ) { $lines[] = 'ssl=0'; }
		}

		$root = null === $temporary_directory ? self::private_directory( $backupplus ) : BackupPlus_PITR_Process::directory( $temporary_directory );
		$path = $root . '/source.cnf';
		if ( is_link( $path ) ) {
			throw new RuntimeException( __( 'The private database connection file is unsafe. Contact your hosting provider.', 'backupplus' ) );
		}
		$temp = $root . '/.source-' . bin2hex( random_bytes( 8 ) ) . '.tmp';
		BackupPlus_PITR_Process::private_file( $temp, implode( "\n", $lines ) . "\n" );
		if ( ! @rename( $temp, $path ) ) {
			@unlink( $temp );
			throw new RuntimeException( __( 'Could not safely save the private database connection.', 'backupplus' ) );
		}
		$permissions = @fileperms( $path );
		if ( ! is_file( $path ) || is_link( $path ) || false === $permissions || ( $permissions & 0077 ) ) {
			throw new RuntimeException( __( 'The private database connection could not be verified.', 'backupplus' ) );
		}

		return array(
			'engine'          => $engine,
			'source_config'   => $path,
			'database'        => $db_name,
			'bin_directory'   => $directory,
			'capture_account' => true,
		);
	}

	private static function parse_host( $raw ) {
		$parsed = array(
			'host'   => (string) $raw,
			'port'   => '',
			'socket' => '',
		);
		if ( isset( $raw[0] ) && '/' === $raw[0] ) {
			$parsed['host']   = 'localhost';
			$parsed['socket'] = $raw;
		} elseif ( preg_match( '/^([^:]+):(\\d+):(\\/.*)$/', $raw, $matches ) ) {
			$parsed['host']   = $matches[1];
			$parsed['socket'] = $matches[3];
		} elseif ( preg_match( '/^([^:]+):(\\/.*)$/', $raw, $matches ) ) {
			$parsed['host']   = $matches[1];
			$parsed['socket'] = $matches[2];
		} elseif ( preg_match( '/^\\[([^\\]]+)\\]:(\\d+)$/', $raw, $matches ) ) {
			$parsed['host'] = $matches[1];
			$parsed['port'] = $matches[2];
		} elseif ( preg_match( '/^([^:]+):(\\d+)$/', $raw, $matches ) ) {
			$parsed['host'] = $matches[1];
			$parsed['port'] = $matches[2];
		} elseif ( preg_match( '/^\\[([^\\]]+)\\]$/', $raw, $matches ) ) {
			$parsed['host'] = $matches[1];
		}
		if ( '' === $parsed['host'] || false !== strpbrk( $parsed['host'], "\r\n\0" ) ) {
			throw new RuntimeException( __( 'WordPress returned an invalid database host.', 'backupplus' ) );
		}
		return $parsed;
	}

	private static function native_directory( $engine ) {
		$configured = defined( 'BACKUPPLUS_PITR_BIN_DIRECTORY' ) ? BACKUPPLUS_PITR_BIN_DIRECTORY : apply_filters( 'backupplus_pitr_bin_directory', '' );
		$candidates = array();
		if ( is_string( $configured ) && '' !== $configured ) {
			$candidates[] = $configured;
		}
		$path = getenv( 'PATH' );
		if ( is_string( $path ) ) {
			$candidates = array_merge( $candidates, explode( PATH_SEPARATOR, $path ) );
		}
		$candidates = array_merge( $candidates, array( '/usr/bin', '/usr/local/bin', '/bin', '/usr/local/mysql/bin' ) );
		$tools      = 'mariadb' === $engine ? array( 'mariadb', 'mariadb-dump', 'mariadb-binlog' ) : array( 'mysql', 'mysqldump', 'mysqlbinlog' );
		foreach ( array_unique( $candidates ) as $candidate ) {
			$directory = realpath( $candidate );
			if ( false === $directory || ! is_dir( $directory ) ) {
				continue;
			}
			try {
				foreach ( $tools as $tool ) {
					BackupPlus_PITR_Process::binary( $tool, $directory );
				}
				return $directory;
			} catch ( RuntimeException $error ) {
				continue;
			}
		}
		throw new RuntimeException( __( 'Matching native database clients are not installed in a standard location on this server.', 'backupplus' ) );
	}

	private static function system_ca_bundle() {
		foreach ( array( '/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/ca-bundle.pem', '/etc/pki/ca-trust/extracted/pem/tls-ca-bundle.pem' ) as $path ) {
			if ( is_file( $path ) && is_readable( $path ) ) {
				return $path;
			}
		}
		return '';
	}

	public static function private_directory( $backupplus ) {
		$directory = BackupPlus_PITR_Process::directory( $backupplus->backups_dir_location() . '/pitr' );
		$guards    = array(
			'.htaccess'  => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'index.php'  => "<?php exit;\n",
			'web.config' => '<?xml version="1.0"?><configuration><system.webServer><security><authorization><remove users="*" roles="" verbs=""/><add accessType="Deny" users="*"/></authorization></security></system.webServer></configuration>',
		);
		foreach ( $guards as $name => $contents ) {
			$path = $directory . '/' . $name;
			if ( is_link( $path ) ) {
				throw new RuntimeException( __( 'The point-in-time recovery folder contains an unsafe protection file.', 'backupplus' ) );
			}
			if ( ! is_file( $path ) ) {
				try {
					BackupPlus_PITR_Process::private_file( $path, $contents );
				} catch ( Throwable $error ) {
					if ( ! is_file( $path ) || is_link( $path ) ) {
						throw $error;
					}
				}
			}
			if ( ! @chmod( $path, 0644 ) ) {
				throw new RuntimeException( __( 'Could not protect point-in-time recovery files from web access.', 'backupplus' ) );
			}
		}
		return $directory;
	}

	public static function ini_value( $value ) {
		if ( false !== strpbrk( $value, "\r\n\0" ) ) {
			throw new RuntimeException( __( 'WordPress database connection values contain unsupported control characters.', 'backupplus' ) );
		}
		return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value ) . '"';
	}

	private static function form_defaults( $config ) {
		if ( empty( $config['enabled'] ) ) {
			return 0;
		}
		$interval = isset( $config['interval'] ) ? (int) $config['interval'] : 15;
		return in_array( $interval, BackupPlus_PITR::intervals(), true ) ? $interval : 15;
	}

	public static function controls( $config ) {
		foreach ( get_settings_errors( BackupPlus_PITR::OPTION ) as $error ) {
			echo '<div class="notice notice-' . esc_attr( $error['type'] ) . '"><p>' . esc_html( $error['message'] ) . '</p></div>';
		}
		$choices = array(
			0   => __( 'Disabled', 'backupplus' ),
			1   => __( 'Every minute', 'backupplus' ),
			5   => __( 'Every 5 minutes', 'backupplus' ),
			10  => __( 'Every 10 minutes', 'backupplus' ),
			15  => __( 'Every 15 minutes', 'backupplus' ),
			30  => __( 'Every 30 minutes', 'backupplus' ),
			60  => __( 'Every hour', 'backupplus' ),
			120 => __( 'Every 2 hours', 'backupplus' ),
			180 => __( 'Every 3 hours', 'backupplus' ),
			240 => __( 'Every 4 hours', 'backupplus' ),
			360 => __( 'Every 6 hours', 'backupplus' ),
			480 => __( 'Every 8 hours', 'backupplus' ),
			720 => __( 'Every 12 hours', 'backupplus' ),
		);
		echo '<select id="backupplus-pitr-interval" name="backupplus_pitr[interval]" aria-label="' . esc_attr__( 'Database point-in-time recovery', 'backupplus' ) . '">';
		foreach ( $choices as $minutes => $label ) {
			echo '<option value="' . esc_attr( $minutes ) . '" ' . selected( self::form_defaults( $config ), $minutes, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo ' <button type="button" class="button" id="backupplus-pitr-setup-open">' . esc_html__( 'Backup account', 'backupplus' ) . '</button>';
		self::timing_controls( $config );
		require_once __DIR__ . '/class-pitr-setup.php';
		$capture = get_option( BackupPlus_PITR_Setup::OPTION, null );
		$summary = is_array( $capture ) && ! empty( $capture['credentials'] ) ? __( 'A separate backup account is saved.', 'backupplus' ) : __( 'Capture uses the WordPress database account.', 'backupplus' );
		echo '<p id="backupplus-pitr-setup-summary" class="description" role="status" aria-live="polite">' . esc_html( $summary ) . '</p>';
	}

	/** Exact local-time controls for PITR, using the shared schedule settings behavior. */
	private static function timing_controls( $config ) {
		$interval = self::form_defaults( $config );
		$exact    = $interval > 1 && ! empty( $config['exact'] );
		$time     = isset( $config['time'] ) && BackupPlus_Backup_Schedule::valid_time( $config['time'] ) ? BackupPlus_Backup_Schedule::sanitize_time( $config['time'] ) : '';
		$hidden   = in_array( $interval, array( 0, 1 ), true ) ? ' style="display:none"' : '';
		$html     = '<span class="backupplus-schedule-timing" data-schedule="pitr"' . $hidden . '>';
		$html    .= '<input type="hidden" name="backupplus_pitr[exact]" value="0">';
		$html    .= '<label><input type="checkbox" class="backupplus-exact-toggle" id="backupplus-pitr-exact" name="backupplus_pitr[exact]" value="1"' . checked( $exact, true, false ) . '> ' . esc_html__( 'Run at an exact time', 'backupplus' ) . '</label>';
		$html    .= '<span class="backupplus-exact-fields">';
		$html    .= '<label class="backupplus-exact-time"><span class="backupplus-field-prefix">' . esc_html__( 'at', 'backupplus' ) . '</span> <input type="time" step="60" id="backupplus-pitr-time" value="' . esc_attr( $time ) . '"></label>';
		$html    .= '<label class="backupplus-exact-minute"><span class="backupplus-field-prefix">' . esc_html__( 'at', 'backupplus' ) . '</span> <select data-part="minute" aria-label="' . esc_attr__( 'Minutes past the hour', 'backupplus' ) . '"></select> ' . esc_html__( 'past every hour', 'backupplus' ) . '</label>';
		$html    .= '<label class="backupplus-exact-hours"><span class="backupplus-field-prefix">' . esc_html__( 'at', 'backupplus' ) . '</span> <select data-part="hours" aria-label="' . esc_attr__( 'Run times', 'backupplus' ) . '"></select></label>';
		$html    .= '<label class="backupplus-exact-hours">' . esc_html__( 'minute', 'backupplus' ) . ' <select data-part="hour-minute" aria-label="' . esc_attr__( 'Minute', 'backupplus' ) . '"></select></label>';
		$html    .= '<input type="hidden" class="backupplus-starttime" name="backupplus_pitr[time]" value="' . esc_attr( $time ) . '">';
		$html    .= '</span></span>';
		echo $html;
	}
}
