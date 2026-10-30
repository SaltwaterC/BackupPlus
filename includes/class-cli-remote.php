<?php
/** WP-CLI commands for remote storage destinations. Secrets are read from a file or stdin, never from arguments, and never printed. */
if ( ! defined( 'ABSPATH' ) ) { die( 'No direct access allowed' ); }

/**
 * List, configure, test and authorise remote storage destinations.
 */
class BackupPlus_CLI_Remote {

	/** Services that are configured through this command; email and none have their own settings. */
	private static function service( $service ) {
		global $backupplus;
		if ( ! is_string( $service ) || ! isset( $backupplus->backup_methods[ $service ] ) || in_array( $service, array( 'none', 'email' ), true ) ) { throw new InvalidArgumentException( 'Unknown remote storage service. Run remote list --all for the available ones.' ); }
		$storage = BackupPlus_Storage_Methods_Interface::get_storage_object( $service );
		if ( ! is_a( $storage, 'BackupPlus_BackupModule' ) || is_a( $storage, 'BackupPlus_BackupModule_AddonNotYetPresent' ) ) { throw new InvalidArgumentException( 'This remote storage service is not available.' ); }
		return $storage;
	}

	private static function instance_id( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^[-A-Za-z0-9]{1,64}$/D', $value ) ) { throw new InvalidArgumentException( 'Invalid instance ID.' ); }
		return $value;
	}

	/** Saved instances of a service, keyed by instance ID. */
	private static function instances( $service ) {
		$saved = BackupPlus_Options::get_backupplus_option( 'backupplus_' . $service );
		return is_array( $saved ) && isset( $saved['settings'] ) && is_array( $saved['settings'] ) ? $saved['settings'] : array();
	}

	/** The one instance a command targets: the named one, or the only one saved. */
	private static function pick( $service, $instance ) {
		$instances = self::instances( $service );
		if ( null !== $instance ) {
			if ( ! isset( $instances[ self::instance_id( $instance ) ] ) ) { throw new InvalidArgumentException( 'No such instance.' ); }
			return $instance;
		}
		if ( 1 !== count( $instances ) ) { throw new InvalidArgumentException( $instances ? 'Several instances exist; name one with --instance.' : 'This service has no saved instances.' ); }
		return (string) key( $instances );
	}

	/**
	 * List configured destinations without credentials.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Also list services and instances that are not configured.
	 *
	 * [--format=<format>]
	 * : Output format: table, json or csv.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated output fields.
	 *
	 * @subcommand list
	 */
	public function list_remotes( $args, $assoc ) {
		global $backupplus;
		try {
			$selected = $backupplus->get_canonical_service_list();
			$rows     = array();
			foreach ( $backupplus->backup_methods as $service => $label ) {
				if ( in_array( $service, array( 'none', 'email' ), true ) ) { continue; }
				$storage   = BackupPlus_Storage_Methods_Interface::get_storage_object( $service );
				$usable    = is_a( $storage, 'BackupPlus_BackupModule' ) && ! is_a( $storage, 'BackupPlus_BackupModule_AddonNotYetPresent' );
				$instances = self::instances( $service );
				if ( ! $instances && isset( $assoc['all'] ) ) { $rows[] = array(
					'service'    => $service,
					'instance'   => '',
					'label'      => $label,
					'selected'   => (int) in_array( $service, $selected, true ),
					'enabled'    => 0,
					'configured' => 0,
				); }
				foreach ( $instances as $id => $options ) {
					$configured = $usable && is_callable( array( $storage, 'options_exist' ) ) && $storage->options_exist( $options );
					if ( ! $configured && ! isset( $assoc['all'] ) ) { continue; }
					$rows[] = array(
						'service'    => $service,
						'instance'   => $id,
						'label'      => ! empty( $options['instance_label'] ) ? $options['instance_label'] : $label,
						'selected'   => (int) in_array( $service, $selected, true ),
						'enabled'    => (int) ( ! isset( $options['instance_enabled'] ) || $options['instance_enabled'] ),
						'configured' => (int) $configured,
					);
				}
			}
			BackupPlus_CLI::output( $rows, $assoc, array( 'service', 'instance', 'label', 'selected', 'enabled', 'configured' ) );
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}

	/**
	 * Run the connection test of a destination from its saved settings.
	 *
	 * ## OPTIONS
	 *
	 * <service>
	 * : Service ID from remote list.
	 *
	 * [--instance=<id>]
	 * : Instance ID; optional when the service has one instance.
	 */
	public function test( $args, $assoc ) {
		try {
			self::service( $args[0] );
			$instance = self::pick( $args[0], isset( $assoc['instance'] ) ? $assoc['instance'] : null );
			$options  = self::instances( $args[0] )[ $instance ];
			// The test reads the saved settings, so no secret ever travels through arguments.
			$options['method'] = $args[0];
			$result            = BackupPlus_CLI::admin()->do_credentials_test( $options, true );
			$output            = trim( html_entity_decode( wp_strip_all_tags( isset( $result['output'] ) ? $result['output'] : '' ), ENT_QUOTES ) );
			if ( empty( $result['data']['success'] ) ) { throw new RuntimeException( '' === $output ? 'The connection test failed.' : $output ); }
			WP_CLI::success( '' === $output ? 'The connection test passed.' : $output );
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}

	/**
	 * Create or update a destination from a JSON object of its settings.
	 *
	 * Credentials belong in the file or on stdin, never in arguments. Keys are the provider's saved setting names
	 * (accesskey, secretkey, path, host, user, pass and so on); existing keys of the instance that are not
	 * given are kept.
	 *
	 * ## OPTIONS
	 *
	 * <service>
	 * : Service ID from remote list --all.
	 *
	 * <file>
	 * : Path to a JSON file, or - for stdin.
	 *
	 * [--instance=<id>]
	 * : Update this instance; a new instance is created when omitted.
	 *
	 * [--label=<label>]
	 * : Display label of the instance.
	 *
	 * [--enabled=<enabled>]
	 * : 0 or 1.
	 *
	 * [--select]
	 * : Also add the service to the destinations used by backups.
	 */
	public function set( $args, $assoc ) {
		global $backupplus;
		try {
			$storage = self::service( $args[0] );
			if ( ! $storage->supports_feature( 'multi_options' ) ) { throw new InvalidArgumentException( 'This service does not support managed instances.' ); }
			$input = BackupPlus_CLI_Input::object( $args[1] );
			if ( isset( $assoc['enabled'] ) && ! in_array( $assoc['enabled'], array( '0', '1' ), true ) ) { throw new InvalidArgumentException( '--enabled must be 0 or 1.' ); }
			$instances = self::instances( $args[0] );
			$instance  = isset( $assoc['instance'] ) ? self::instance_id( $assoc['instance'] ) : 's-' . md5( wp_rand() . uniqid() . microtime( true ) );
			$options   = array_merge( isset( $instances[ $instance ] ) ? $instances[ $instance ] : array(), $input );
			if ( isset( $assoc['label'] ) ) { $options['instance_label'] = $assoc['label']; }
			if ( isset( $assoc['enabled'] ) ) { $options['instance_enabled'] = (int) $assoc['enabled']; }
			$instances[ $instance ] = $options;
			BackupPlus_Options::admin_init();
			BackupPlus_Options::update_backupplus_option(
				'backupplus_' . $args[0],
				array(
					'version'  => 1,
					'settings' => $instances,
				)
			);
			if ( ! isset( self::instances( $args[0] )[ $instance ] ) ) { throw new RuntimeException( 'The settings were rejected.' ); }
			if ( isset( $assoc['select'] ) ) {
				$services = $backupplus->get_canonical_service_list();
				if ( ! in_array( $args[0], $services, true ) ) { BackupPlus_Options::update_backupplus_option( 'backupplus_service', array_merge( $services, array( $args[0] ) ) ); }
			}
			WP_CLI::success( 'Saved ' . $args[0] . ' instance ' . $instance . '.' );
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}

	/**
	 * Remove a destination instance and its saved settings.
	 *
	 * ## OPTIONS
	 *
	 * <service>
	 * : Service ID from remote list.
	 *
	 * <instance>
	 * : Instance ID.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 */
	public function remove( $args, $assoc ) {
		try {
			self::service( $args[0] );
			$instance = self::pick( $args[0], $args[1] );
			WP_CLI::confirm( 'Remove ' . $args[0] . ' instance ' . $instance . '? Backups already stored there are kept.', $assoc );
			$instances = self::instances( $args[0] );
			unset( $instances[ $instance ] );
			BackupPlus_Options::admin_init();
			BackupPlus_Options::update_backupplus_option(
				'backupplus_' . $args[0],
				array(
					'version'  => 1,
					'settings' => $instances,
				)
			);
			WP_CLI::success( 'Removed instance ' . $instance . '.' );
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}

	/**
	 * Complete authorisation of an OAuth destination from the code the provider displays.
	 *
	 * Open the authorisation page in a browser, choose "Having problems authenticating?" and pass the code it
	 * shows in a file or on stdin. Services that use keys instead of OAuth need remote set.
	 *
	 * ## OPTIONS
	 *
	 * <service>
	 * : Service ID from remote list.
	 *
	 * <file>
	 * : File holding the authentication code, or - for stdin.
	 */
	public function authorize( $args, $assoc ) {
		try {
			self::service( $args[0] );
			$raw = '-' === $args[1] ? file_get_contents( 'php://stdin' ) : ( is_file( $args[1] ) && is_readable( $args[1] ) ? file_get_contents( $args[1] ) : false );
			if ( false === $raw || '' === trim( $raw ) ) { throw new InvalidArgumentException( 'Cannot read the authentication code.' ); }
			$commands = new BackupPlus_Commands( null );
			$result   = $commands->manual_remote_storage_authentication(
				array(
					'method'    => $args[0],
					'auth_data' => trim( $raw ),
				)
			);
			if ( is_wp_error( $result ) || ! is_array( $result ) || 'success' !== $result['result'] ) { throw new RuntimeException( wp_strip_all_tags( is_array( $result ) && isset( $result['data'] ) && is_string( $result['data'] ) ? $result['data'] : 'Authorisation failed.' ) ); }
			WP_CLI::success( wp_strip_all_tags( is_string( $result['data'] ) ? $result['data'] : 'Authorised.' ) );
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}

	/**
	 * Remove the authorisation of an OAuth destination instance.
	 *
	 * ## OPTIONS
	 *
	 * <service>
	 * : Service ID from remote list.
	 *
	 * [--instance=<id>]
	 * : Instance ID; optional when the service has one instance.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 */
	public function deauthorize( $args, $assoc ) {
		try {
			self::service( $args[0] );
			$instance = self::pick( $args[0], isset( $assoc['instance'] ) ? $assoc['instance'] : null );
			WP_CLI::confirm( 'Remove the authorisation of ' . $args[0] . ' instance ' . $instance . '?', $assoc );
			$result = BackupPlus_CLI::admin()->deauth_remote_method(
				array(
					'remote_method' => $args[0],
					'instance_id'   => $instance,
				)
			);
			if ( 'success' !== $result['result'] ) { throw new RuntimeException( isset( $result['message'] ) ? wp_strip_all_tags( $result['message'] ) : 'Deauthorisation failed.' ); }
			WP_CLI::success( 'Authorisation removed.' );
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}
}
