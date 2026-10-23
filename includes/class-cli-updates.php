<?php
/** Native WP-CLI update protection at the upgrader's filesystem boundary. */
if ( ! defined( 'ABSPATH' ) ) { die( 'No direct access allowed' ); }

class BackupPlus_CLI_Updates {
	private static $active  = false;
	private static $busy    = false;
	private static $targets = array();
	private static $batch   = '';

	public static function register() {
		foreach ( array( 'plugin', 'theme', 'core' ) as $type ) {
			foreach ( array( 'update', 'upgrade' ) as $verb ) {
				WP_CLI::add_hook( 'before_invoke:' . $type . ' ' . $verb, array( __CLASS__, 'begin' ) );
				WP_CLI::add_hook( 'after_invoke:' . $type . ' ' . $verb, array( __CLASS__, 'end' ) );
			}
		}
		// WordPress connects before bulk maintenance mode, or before an install can overwrite code.
		add_action( 'shutdown', array( __CLASS__, 'end' ), 20 );
		add_filter( 'filesystem_method', array( __CLASS__, 'filesystem' ), PHP_INT_MAX );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), PHP_INT_MAX - 1, 4 );
	}

	public static function begin() {
		self::$active  = BackupPlus_Pre_Update_Backups::enabled();
		self::$targets = array();
		self::$batch   = '';
		// Native explicit-version plugin updates use install(), which ignores --dry-run.
		if ( self::$active && ! empty( WP_CLI::get_runner()->assoc_args['dry-run'] ) && isset( WP_CLI::get_runner()->assoc_args['version'] ) ) {
			WP_CLI::log( 'Dry run: no installed files changed.' );
			WP_CLI::halt( 0 );
		}
	}

	public static function end() {
		BackupPlus_Pre_Update_Backups::instance()->finish_cli();
		self::$active = false;
	}

	public static function target( $type, $id, $version, $packages ) {
		$target = array(
			'type'     => $type,
			'id'       => (string) $id,
			'version'  => (string) $version,
			'packages' => array_values( array_unique( array_filter( $packages ) ) ),
		);
		if ( ! $target['packages'] ) { throw new RuntimeException( 'The update has no available package.' ); }
		foreach ( $target['packages'] as $package ) {
			if ( is_file( $package ) ) {
				$hash = hash_file( 'sha256', $package );
				if ( false === $hash ) { throw new RuntimeException( 'Cannot fingerprint the local update package.' ); }
				$target['package_hashes'][ $package ] = $hash;
			}
		}
		return $target;
	}

	/** Actual IDs and final download offers come from native upgrader arguments. */
	public static function resolve( $trace ) {
		foreach ( $trace as $frame ) {
			if ( empty( $frame['object'] ) || ! is_a( $frame['object'], 'WP_Upgrader' ) ) { continue; }
			$upgrader = $frame['object'];
			$type     = is_a( $upgrader, 'Plugin_Upgrader' ) ? 'plugin' : ( is_a( $upgrader, 'Theme_Upgrader' ) ? 'theme' : ( is_a( $upgrader, 'Core_Upgrader' ) ? 'core' : '' ) );
			if ( ! $type || empty( $frame['args'] ) ) { continue; }
			if ( 'bulk_upgrade' === $frame['function'] ) {
				$offers  = get_site_transient( 'update_' . $type . 's' );
				$targets = array();
				foreach ( $frame['args'][0] as $id ) {
					if ( empty( $offers->response[ $id ] ) ) { throw new RuntimeException( 'The native update offer is unavailable.' ); }
					$offer                        = (object) $offers->response[ $id ];
					$targets[ $type . ':' . $id ] = self::target( $type, $id, $offer->new_version, array( $offer->package ) );
				}
				return $targets;
			}
			if ( 'core' === $type && 'upgrade' === $frame['function'] ) {
				$offer = $frame['args'][0];
				$id    = (string) $offer->current . ':' . ( isset( $offer->locale ) ? $offer->locale : '' );
				return array( 'core:' . $id => self::target( 'core', $id, $offer->current, (array) $offer->packages ) );
			}
			if ( 'install' === $frame['function'] ) {
				// Resolve every target of explicit-version invocations before the first installation.
				foreach ( $trace as $command ) {
					if ( 'update' !== $command['function'] || empty( $command['object'] ) || ! is_a( $command['object'], '\WP_CLI\CommandWithUpgrade' ) ) { continue; }
					$flags = $command['args'][1];
					if ( ! isset( $flags['version'] ) ) { throw new RuntimeException( 'Cannot resolve the native install update targets.' ); }
					$property = new ReflectionProperty( $command['object'], 'fetcher' );
					if ( PHP_VERSION_ID < 80100 ) { $property->setAccessible( true ); }
					$fetcher = $property->getValue( $command['object'] );
					$ids     = $command['args'][0];
					if ( ! empty( $flags['all'] ) ) { $ids = 'plugin' === $type ? array_keys( get_plugins() ) : array_keys( wp_get_themes() ); }
					$targets = array();
					$alter   = new ReflectionMethod( $command['object'], 'alter_api_response' );
					if ( PHP_VERSION_ID < 80100 ) { $alter->setAccessible( true ); }
					foreach ( $fetcher->get_many( $ids ) as $item ) {
						$id   = 'plugin' === $type ? $item->file : $item->get_stylesheet();
						$slug = 'plugin' === $type ? $item->name : $id;
						$api  = 'plugin' === $type ? plugins_api( 'plugin_information', array( 'slug' => $slug ) ) : themes_api( 'theme_information', array( 'slug' => $slug ) );
						if ( is_wp_error( $api ) ) { throw new RuntimeException( $api->get_error_message() ); }
						$api = clone $api;
						$alter->invoke( null, $api, $flags['version'] );
						$targets[ $type . ':' . $id ] = self::target( $type, $id, $api->version, array( $api->download_link ) );
					}
					return $targets;
				}
			}
		}
		return array();
	}

	public static function filesystem( $method ) {
		if ( ! self::$active || self::$busy ) { return $method; }
		try {
			$targets = self::resolve( debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT ) );
			if ( $targets ) { self::protect( $targets ); }
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
		return $method;
	}

	private static function protect( $targets ) {
		ksort( $targets );
		if ( self::$batch ) {
			foreach ( $targets as $key => $target ) {
				if ( ! isset( self::$targets[ $key ] ) || self::$targets[ $key ] !== $target ) { throw new RuntimeException( 'The update targets or packages changed after backup.' ); }
			}
			return;
		}
		self::$busy = true;
		try {
			$coordinator = BackupPlus_Pre_Update_Backups::instance();
			$batch       = $coordinator->prepare_cli( $targets );
			if ( is_wp_error( $batch ) ) { throw new RuntimeException( $batch->get_error_message() ); }
			self::$batch   = $batch['id'];
			self::$targets = $targets;
			$nonce         = $batch['job'];
			update_site_option( BackupPlus_CLI::REQUEST . $nonce, array( 'batch' => $batch['id'] ) );
			WP_CLI::log( 'Waiting for a verified pre-update backup...' );
			try { BackupPlus_CLI::wait( $nonce, $batch['id'] ); }
			finally {
				delete_site_option( BackupPlus_CLI::REQUEST . $nonce );
				delete_site_option( BackupPlus_CLI::RESULT . $nonce );
			}
		} finally { self::$busy = false; }
	}

	public static function download( $reply, $package, $upgrader, $extra = array() ) {
		if ( ! self::$active || is_a( $upgrader, 'Language_Pack_Upgrader' ) ) { return $reply; }
		// Recheck resolved versions and packages at the last boundary before download.
		try {
			$targets = self::resolve( debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT ) );
			if ( ! $targets ) { throw new RuntimeException( 'Could not resolve native update targets before download.' ); }
			self::protect( $targets );
		} catch ( Throwable $error ) { return new WP_Error( 'backupplus_cli_update_blocked', $error->getMessage() ); }
		return $reply;
	}
}
