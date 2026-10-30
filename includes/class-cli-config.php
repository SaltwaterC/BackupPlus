<?php
/** WP-CLI commands for saved configuration: settings, external databases, notifications and storage limits. */
if ( ! defined( 'ABSPATH' ) ) { die( 'No direct access allowed' ); }

/** Read a JSON object from a file or stdin, so credentials never travel in arguments or the process list. */
class BackupPlus_CLI_Input {

	public static function object( $source ) {
		$raw = '-' === $source ? file_get_contents( 'php://stdin' ) : ( is_file( $source ) && is_readable( $source ) ? file_get_contents( $source ) : false );
		if ( false === $raw ) { throw new InvalidArgumentException( 'Cannot read the input file.' ); }
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || '{' !== substr( ltrim( $raw ), 0, 1 ) ) { throw new InvalidArgumentException( 'The input must be a JSON object.' ); }
		return $data;
	}

	/** Write with owner-only permissions, never over an existing file unless asked. */
	public static function write( $path, $contents, $force ) {
		if ( ( file_exists( $path ) || is_link( $path ) ) && ! $force ) { throw new RuntimeException( 'Refusing to overwrite ' . $path . '. Use --force.' ); }
		$partial = $path . '.part';
		$handle  = fopen( $partial, 'w' );
		if ( ! $handle ) { throw new RuntimeException( 'Cannot write ' . $path . '.' ); }
		chmod( $partial, 0600 );
		$written = fwrite( $handle, $contents );
		fclose( $handle );
		if ( strlen( $contents ) !== $written || ! rename( $partial, $path ) ) {
			@unlink( $partial );
			throw new RuntimeException( 'Cannot write ' . $path . '.' );
		}
	}
}

/**
 * Read, change, export and import saved settings. Connection credentials have their own commands and are never exported.
 */
class BackupPlus_CLI_Settings {

	/** Run state, dismissed notices, provider secrets and settings that have a command of their own. */
	const EXCLUDED = '/^(backupplus_(dismiss|tour_|last|version$|restore_in_progress|database_next_id|dropboxtk_|pitr|ntfy|extradbs|database_selection|service$)|dismissed_)|(_pass|_login|_secret|_apikey|_clientid|_user|_token)$/D';

	/** Option names this command may touch. */
	public static function keys() {
		global $backupplus;
		$omitted = BackupPlus_Credential_Storage::export_omitted_options();
		$keys    = array();
		foreach ( $backupplus->get_settings_keys() as $key ) {
			if ( in_array( $key, $omitted, true ) || preg_match( self::EXCLUDED, $key ) ) { continue; }
			$keys[] = $key;
		}
		return $keys;
	}

	private static function known( $key ) {
		if ( ! in_array( $key, self::keys(), true ) ) { throw new InvalidArgumentException( 'Unknown or protected setting. Credentials, destinations, external databases and notifications have their own commands.' ); }
		return $key;
	}

	private static function show( $value ) {
		return is_scalar( $value ) || null === $value ? (string) $value : wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Show one setting, or all that can be managed here.
	 *
	 * ## OPTIONS
	 *
	 * [<key>]
	 * : Option name.
	 *
	 * [--format=<format>]
	 * : Output format: table, json or csv.
	 */
	public function get( $args, $assoc ) {
		try {
			if ( isset( $args[0] ) ) {
				$value = BackupPlus_Options::get_backupplus_option( self::known( $args[0] ) );
				if ( isset( $assoc['format'] ) && 'json' === $assoc['format'] ) { WP_CLI::line( wp_json_encode( $value, JSON_UNESCAPED_SLASHES ) ); }
				else { WP_CLI::line( self::show( $value ) ); }
				return;
			}
			$rows = array();
			foreach ( self::keys() as $key ) { $rows[] = array(
				'key'   => $key,
				'value' => self::show( BackupPlus_Options::get_backupplus_option( $key ) ),
			); }
			BackupPlus_CLI::output( $rows, $assoc, array( 'key', 'value' ) );
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}

	/**
	 * Change one setting with the same validation as the settings screen.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Option name from settings get.
	 *
	 * <value>
	 * : New value.
	 *
	 * [--format=<format>]
	 * : plaintext (default) or json for arrays such as backupplus_include_more_path.
	 */
	public function set( $args, $assoc ) {
		try {
			$key   = self::known( $args[0] );
			$value = $args[1];
			if ( isset( $assoc['format'] ) && 'json' === $assoc['format'] ) {
				$value = json_decode( $value, true );
				if ( JSON_ERROR_NONE !== json_last_error() ) { throw new InvalidArgumentException( 'The value is not valid JSON.' ); }
			} elseif ( isset( $assoc['format'] ) && 'plaintext' !== $assoc['format'] ) { throw new InvalidArgumentException( 'Use --format=plaintext or json.' ); }
			BackupPlus_Options::admin_init();
			BackupPlus_CLI::save_settings( array( $key => $value ) );
			WP_CLI::success( 'Saved ' . $key . '.' );
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}

	/**
	 * Export settings in the settings screen's export format, without credentials.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<path>]
	 * : Write to this file instead of stdout; created with owner-only permissions.
	 *
	 * [--force]
	 * : Overwrite an existing file.
	 */
	public function export( $args, $assoc ) {
		try {
			$data = array();
			foreach ( self::keys() as $key ) {
				$value = BackupPlus_Options::get_backupplus_option( $key );
				if ( null !== $value ) { $data[ $key ] = $value; }
			}
			$now      = microtime( true );
			$contents = wp_json_encode(
				array(
					'version'          => '1.12.40',
					'epoch_date'       => (int) round( $now * 1000 ),
					'local_date'       => gmdate( 'Y-m-d H:i:s', (int) $now ) . ' UTC',
					'network_site_url' => network_site_url(),
					'data'             => $data,
				),
				JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
			);
			if ( isset( $assoc['file'] ) ) {
				BackupPlus_CLI_Input::write( $assoc['file'], $contents . "\n", isset( $assoc['force'] ) );
				WP_CLI::success( 'Exported ' . count( $data ) . ' settings.' );
			} else { WP_CLI::line( $contents ); }
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}

	/**
	 * Import a settings export with the settings screen's import rules.
	 *
	 * Exports hold no credentials, so saved destinations and external database connections are kept as they are.
	 * Report addresses, extra retention rules and additional file paths are replaced, as on the settings screen.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Export file, or - for stdin.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 */
	public function import( $args, $assoc ) {
		global $backupplus;
		try {
			$export = BackupPlus_CLI_Input::object( $args[0] );
			if ( ! isset( $export['data'] ) || ! is_array( $export['data'] ) ) { throw new InvalidArgumentException( 'The file is not a settings export.' ); }
			WP_CLI::confirm( 'Import ' . count( $export['data'] ) . ' settings? This replaces the current ones.', $assoc );
			BackupPlus_Options::admin_init();
			// The screen's save clears these first, and an export never carries them.
			$databases = BackupPlus_Options::get_backupplus_option( 'backupplus_extradbs', array() );
			$result    = BackupPlus_CLI::admin()->import_settings(
				array(
					'settings'           => wp_json_encode( $export['data'] ),
					'backupplus_version' => $backupplus->version,
				),
				true
			);
			if ( $databases ) { BackupPlus_Options::update_backupplus_option( 'backupplus_extradbs', $databases ); }
			if ( empty( $result['saved'] ) ) { throw new RuntimeException( isset( $result['error_message'] ) ? wp_strip_all_tags( $result['error_message'] ) : 'The import failed.' ); }
			foreach ( isset( $result['import_skipped_settings'] ) ? $result['import_skipped_settings'] : array() as $skipped ) { WP_CLI::warning( 'Skipped ' . $skipped ); }
			WP_CLI::success( 'Settings imported.' );
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}
}

/**
 * External database connections that backups can include.
 */
class BackupPlus_CLI_Databases {

	const FIELDS = array( 'host', 'user', 'pass', 'name', 'prefix', 'enabled' );

	private static function id( $value ) {
		if ( ! ctype_digit( (string) $value ) || (int) $value < 1 ) { throw new InvalidArgumentException( 'Use a database ID from databases list.' ); }
		return (int) $value;
	}

	private static function save( $connections ) {
		BackupPlus_Options::admin_init();
		BackupPlus_Options::update_backupplus_option( 'backupplus_extradbs', $connections );
	}

	/**
	 * List external database connections without passwords.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format: table, json or csv.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated output fields.
	 *
	 * @subcommand list
	 */
	public function list_databases( $args, $assoc ) {
		$rows = array();
		foreach ( BackupPlus_Backup_Sources::connections() as $id => $connection ) { $rows[] = array(
			'id'      => $id,
			'host'    => $connection['host'],
			'name'    => $connection['name'],
			'user'    => $connection['user'],
			'prefix'  => $connection['prefix'],
			'enabled' => (int) $connection['enabled'],
		); }
		BackupPlus_CLI::output( $rows, $assoc, array( 'id', 'host', 'name', 'user', 'prefix', 'enabled' ) );
	}

	/**
	 * Test the connection of a saved database, or of a JSON file without saving it.
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : Database ID from databases list.
	 *
	 * [--file=<file>]
	 * : JSON object with host, name, user and pass, or - for stdin.
	 */
	public function test( $args, $assoc ) {
		try {
			if ( isset( $assoc['file'] ) ) { $info = BackupPlus_CLI_Input::object( $assoc['file'] ); }
			elseif ( isset( $args[0] ) ) {
				$connections = BackupPlus_Backup_Sources::connections();
				$id          = self::id( $args[0] );
				if ( ! isset( $connections[ $id ] ) ) { throw new InvalidArgumentException( 'No such database connection.' ); }
				$info = $connections[ $id ];
			} else { throw new InvalidArgumentException( 'Supply a database ID or --file.' ); }
			BackupPlus_Backup_Sources::connect( $info );
			WP_CLI::success( 'The connection works.' );
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}

	/**
	 * Create or update a connection from a JSON object. Use a dedicated read-only account, not a privileged one.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : JSON object with host, name, user, pass and optionally prefix and enabled; - for stdin.
	 *
	 * [--id=<id>]
	 * : Update this connection; a new one is created when omitted. Given keys replace saved ones.
	 *
	 * [--skip-test]
	 * : Save without testing the connection first.
	 */
	public function set( $args, $assoc ) {
		try {
			$input = BackupPlus_CLI_Input::object( $args[0] );
			if ( array_diff( array_keys( $input ), self::FIELDS ) ) { throw new InvalidArgumentException( 'Unknown field. Use ' . implode( ', ', self::FIELDS ) . '.' ); }
			$connections = BackupPlus_Backup_Sources::connections();
			if ( isset( $assoc['id'] ) ) {
				$id = self::id( $assoc['id'] );
				if ( ! isset( $connections[ $id ] ) ) { throw new InvalidArgumentException( 'No such database connection.' ); }
			} else { $id = max( array_merge( array( 0 ), array_keys( $connections ), array( (int) BackupPlus_Options::get_backupplus_option( 'backupplus_database_next_id', 1 ) - 1 ) ) ) + 1; }
			$connection = array_merge( isset( $connections[ $id ] ) ? $connections[ $id ] : array( 'enabled' => true ), $input );
			if ( isset( $input['enabled'] ) ) { $connection['enabled'] = (bool) $input['enabled']; }
			if ( ! isset( $assoc['skip-test'] ) ) { BackupPlus_Backup_Sources::connect( $connection ); }
			$connections[ $id ] = $connection;
			self::save( $connections );
			BackupPlus_Options::update_backupplus_option( 'backupplus_database_next_id', max( (int) BackupPlus_Options::get_backupplus_option( 'backupplus_database_next_id', 1 ), $id + 1 ) );
			WP_CLI::success( 'Saved database connection ' . $id . '.' );
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}

	/**
	 * Remove a connection and its saved table selection. Existing backups are kept.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Database ID from databases list.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 */
	public function remove( $args, $assoc ) {
		try {
			$id          = self::id( $args[0] );
			$connections = BackupPlus_Backup_Sources::connections();
			if ( ! isset( $connections[ $id ] ) ) { throw new InvalidArgumentException( 'No such database connection.' ); }
			WP_CLI::confirm( 'Remove database connection ' . $id . '?', $assoc );
			unset( $connections[ $id ] );
			self::save( $connections );
			$selection = BackupPlus_Options::get_backupplus_option( 'backupplus_database_selection', array() );
			if ( is_array( $selection ) && isset( $selection[ $id ] ) ) {
				unset( $selection[ $id ] );
				BackupPlus_Options::update_backupplus_option( 'backupplus_database_selection', $selection );
			}
			WP_CLI::success( 'Removed database connection ' . $id . '.' );
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}
}

/**
 * Report channels: email and ntfy.
 */
class BackupPlus_CLI_Notify {

	private static function addresses() {
		global $backupplus;
		$saved = $backupplus->just_one_email( BackupPlus_Options::get_backupplus_option( 'backupplus_email' ) );
		$list  = array();
		foreach ( (array) $saved as $entry ) {
			foreach ( explode( ',', (string) $entry ) as $address ) { if ( '' !== trim( $address ) ) { $list[] = trim( $address ); }
			}
		}
		return $list;
	}

	/** Webhook addresses are as sensitive as tokens: show the host only. */
	private static function describe( $address ) {
		if ( preg_match( '/^https?:\/\//i', $address ) ) {
			$host = wp_parse_url( $address, PHP_URL_HOST );
			return 'webhook ' . ( $host ? $host : '(invalid)' );
		}
		return $address;
	}

	/**
	 * Show the report channels. Secrets are never printed.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format: table, json or csv.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated output fields.
	 */
	public function status( $args, $assoc ) {
		$rows = array();
		foreach ( self::addresses() as $address ) { $rows[] = array(
			'channel' => preg_match( '/^https?:\/\//i', $address ) ? 'webhook' : 'email',
			'target'  => self::describe( $address ),
			'detail'  => '',
		); }
		$ntfy = BackupPlus_Ntfy::config();
		if ( $ntfy ) { $rows[] = array(
			'channel' => 'ntfy',
			'target'  => $ntfy['server'],
			'detail'  => $ntfy['token'] ? 'topic set, token set' : 'topic set, no token',
		); }
		BackupPlus_CLI::output( $rows, $assoc, array( 'channel', 'target', 'detail' ) );
	}

	/**
	 * Change report channels. The ntfy topic and token come from a JSON file or stdin, never from arguments.
	 *
	 * ## OPTIONS
	 *
	 * [--email=<enabled>]
	 * : 1 emails the report to the site administrator address, 0 stops it.
	 *
	 * [--ntfy=<file>]
	 * : JSON object with server, topic and optionally token, or - for stdin.
	 *
	 * [--disable-ntfy]
	 * : Disable ntfy.
	 */
	public function set( $args, $assoc ) {
		try {
			if ( isset( $assoc['ntfy'] ) && isset( $assoc['disable-ntfy'] ) ) { throw new InvalidArgumentException( '--ntfy and --disable-ntfy are exclusive.' ); }
			if ( ! isset( $assoc['email'] ) && ! isset( $assoc['ntfy'] ) && ! isset( $assoc['disable-ntfy'] ) ) { throw new InvalidArgumentException( 'Supply at least one setting.' ); }
			if ( isset( $assoc['email'] ) && ! in_array( $assoc['email'], array( '0', '1' ), true ) ) { throw new InvalidArgumentException( '--email must be 0 or 1.' ); }
			$ntfy = null;
			if ( isset( $assoc['ntfy'] ) ) {
				$ntfy = BackupPlus_CLI_Input::object( $assoc['ntfy'] );
				if ( ! BackupPlus_Ntfy::sanitize( $ntfy ) ) { throw new InvalidArgumentException( 'ntfy needs a topic of letters, digits, _ and - (up to 64 characters).' ); }
			}
			BackupPlus_Options::admin_init();
			if ( isset( $assoc['email'] ) ) { BackupPlus_Options::update_backupplus_option( 'backupplus_email', '1' === $assoc['email'] ? get_bloginfo( 'admin_email' ) : '' ); }
			if ( null !== $ntfy ) { BackupPlus_Options::update_backupplus_option( BackupPlus_Ntfy::OPTION, $ntfy ); }
			if ( isset( $assoc['disable-ntfy'] ) ) { BackupPlus_Options::update_backupplus_option( BackupPlus_Ntfy::OPTION, array() ); }
			WP_CLI::success( 'Notification settings saved.' );
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}

	/**
	 * Send a test message through every configured channel.
	 */
	public function test( $args, $assoc ) {
		$failed = 0;
		$sent   = 0;
		$title  = 'BackupPlus test notification';
		$body   = 'This is a test from ' . home_url() . '.';
		foreach ( self::addresses() as $address ) {
			++$sent;
			if ( preg_match( '/^https?:\/\//i', $address ) ) {
				$response = wp_remote_post(
					$address,
					array(
						'timeout' => 15,
						'body'    => wp_json_encode( array( 'text' => $title . "\n" . $body ) ),
					)
				);
				$code     = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
				$ok       = $code >= 200 && $code < 300;
			} else {
				$ok = wp_mail( $address, $title, $body );
			}
			if ( $ok ) { WP_CLI::log( 'Sent: ' . self::describe( $address ) ); }
			else {
				++$failed;
				WP_CLI::warning( 'Failed: ' . self::describe( $address ) );
			}
		}
		if ( BackupPlus_Ntfy::enabled() ) {
			++$sent;
			if ( BackupPlus_Ntfy::notify( $title, $body, 3, array( 'white_check_mark' ) ) ) { WP_CLI::log( 'Sent: ntfy' ); }
			else {
				++$failed;
				WP_CLI::warning( 'Failed: ntfy' );
			}
		}
		if ( ! $sent ) { WP_CLI::error( 'No notification channel is configured.' ); }
		if ( $failed ) { WP_CLI::error( $failed . ' of ' . $sent . ' channel(s) failed.' ); }
		WP_CLI::success( 'Test sent to ' . $sent . ' channel(s).' );
	}
}

/**
 * Per-destination usage alerts and quotas.
 */
class BackupPlus_CLI_Storage_Limits {

	/** Decimal gigabytes, as on the settings screen, to bytes. */
	private static function bytes( $value, $name ) {
		if ( ! is_string( $value ) || ! preg_match( '/^(0|[0-9]+(\.[0-9]+)?)$/D', $value ) ) { throw new InvalidArgumentException( '--' . $name . ' must be a number of gigabytes; 0 disables it.' ); }
		return (int) min( floor( (float) $value * BackupPlus_Storage_Limits::GB ), PHP_INT_MAX - 1024 );
	}

	/**
	 * Show limits, measured usage and the state of each destination.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format: table, json or csv.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated output fields.
	 */
	public function status( $args, $assoc ) {
		try {
			BackupPlus_CLI::fresh( BackupPlus_Storage_Limits::STATUS );
			$status = get_option( BackupPlus_Storage_Limits::STATUS, array() );
			$limits = BackupPlus_Storage_Limits::limits();
			$rows   = array();
			foreach ( BackupPlus_Storage_Limits::destinations() as $key => $destination ) {
				$config = isset( $limits[ $destination['service'] ][ $destination['instance'] ] ) ? $limits[ $destination['service'] ][ $destination['instance'] ] : array(
					'alert' => 0,
					'quota' => 0,
				);
				$entry  = isset( $status[ $key ] ) ? $status[ $key ] : array();
				if ( ! $config['alert'] && ! $config['quota'] && ! $entry ) { continue; }
				$rows[] = array(
					'service'  => $destination['service'],
					'instance' => $destination['instance'],
					'label'    => BackupPlus_Storage_Limits::label( $destination['service'], $destination['instance'] ),
					'alert'    => $config['alert'],
					'quota'    => $config['quota'],
					'usage'    => isset( $entry['usage'] ) ? $entry['usage'] : null,
					'measured' => isset( $entry['measured'] ) ? gmdate( 'Y-m-d H:i:s', $entry['measured'] ) : null,
					'outcome'  => isset( $entry['outcome'] ) ? $entry['outcome'] : '',
				);
			}
			BackupPlus_CLI::output( $rows, $assoc, array( 'service', 'instance', 'label', 'alert', 'quota', 'usage', 'measured', 'outcome' ) );
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}

	/**
	 * Set the usage alert and quota of a destination, in decimal gigabytes; 0 disables one.
	 *
	 * ## OPTIONS
	 *
	 * <service>
	 * : local or a service ID from remote list.
	 *
	 * [--instance=<id>]
	 * : Instance ID; omit for the service default and for local.
	 *
	 * [--alert=<gigabytes>]
	 * : Send a notification above this usage.
	 *
	 * [--quota=<gigabytes>]
	 * : Delete the oldest unprotected backups above this usage.
	 */
	public function set( $args, $assoc ) {
		try {
			if ( ! isset( $assoc['alert'] ) && ! isset( $assoc['quota'] ) ) { throw new InvalidArgumentException( 'Supply --alert, --quota or both.' ); }
			$service  = $args[0];
			$instance = isset( $assoc['instance'] ) ? $assoc['instance'] : '';
			if ( ! preg_match( '/\A[a-z][a-z0-9_-]*\z/D', $service ) || ! preg_match( '/\A[a-zA-Z0-9_-]*\z/D', $instance ) ) { throw new InvalidArgumentException( 'Invalid service or instance.' ); }
			if ( 'local' === $service && '' !== $instance ) { throw new InvalidArgumentException( 'Local storage has no instances.' ); }
			$limits = BackupPlus_Storage_Limits::limits();
			$entry  = isset( $limits[ $service ][ $instance ] ) ? $limits[ $service ][ $instance ] : array(
				'alert' => 0,
				'quota' => 0,
			);
			foreach ( array( 'alert', 'quota' ) as $field ) { if ( isset( $assoc[ $field ] ) ) { $entry[ $field ] = self::bytes( $assoc[ $field ], $field ); }
			}
			if ( $entry['alert'] && $entry['quota'] && $entry['alert'] > $entry['quota'] ) { throw new InvalidArgumentException( 'The alert must not exceed the quota.' ); }
			$limits[ $service ][ $instance ] = $entry;
			BackupPlus_Options::admin_init();
			BackupPlus_Options::update_backupplus_option( BackupPlus_Storage_Limits::OPTION, $limits );
			WP_CLI::success( 'Limits saved for ' . BackupPlus_Storage_Limits::label( $service, $instance ) . '.' );
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}

	/**
	 * Measure usage now and enforce alerts and quotas, as the hourly monitor does.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip confirmation; quota enforcement deletes the oldest unprotected backups.
	 */
	public function run( $args, $assoc ) {
		try {
			if ( ! BackupPlus_Storage_Limits::enabled() ) { throw new RuntimeException( 'No alert or quota is configured.' ); }
			WP_CLI::confirm( 'Measure usage and enforce quotas now?', $assoc );
			BackupPlus_Storage_Limits::run();
			WP_CLI::success( 'Usage measured.' );
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}
}
