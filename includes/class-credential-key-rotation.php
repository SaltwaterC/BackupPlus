<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}

/** Annual rotation of the installation key, with per-site credential rewrites. */
class BackupPlus_Credential_Key_Rotation {
	const HOOK                 = 'backupplus_rotate_credential_keys';
	const RETRY_HOOK           = 'backupplus_retry_credential_key_rotation';
	const SCHEDULE             = 'backupplus_annually';
	const REWRITTEN_KEY_OPTION = 'backupplus_credential_rotation_key';
	const ERROR_OPTION         = 'backupplus_credential_rotation_error';

	public static function register() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ), 21 );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( self::RETRY_HOOK, array( __CLASS__, 'run' ) );
		register_activation_hook( BACKUPPLUS_DIR . '/backupplus.php', array( __CLASS__, 'schedule' ) );
		register_deactivation_hook( BACKUPPLUS_DIR . '/backupplus.php', array( __CLASS__, 'unschedule' ) );
	}

	public static function schedules( $schedules ) {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => BackupPlus_Credential_Key_Store::ROTATION_INTERVAL,
			'display'  => __( 'Annually', 'backupplus' ),
		);
		return $schedules;
	}

	public static function schedule() {
		try {
			$due  = BackupPlus_Credential_Key_Store::rotation_time() + BackupPlus_Credential_Key_Store::ROTATION_INTERVAL;
			$next = wp_next_scheduled( self::HOOK );
			// Keep WP-Cron's next annual event after an overdue failure; the daily
			// retry handles it without scheduling an endless series of past events.
			if ( $next && ( $next < $due || ( $due > time() && $next !== $due ) ) ) {
				wp_clear_scheduled_hook( self::HOOK );
				$next = false;
			}
			if ( ! $next ) {
				wp_schedule_event( $due, self::SCHEDULE, self::HOOK );
				if ( ! wp_next_scheduled( self::HOOK ) ) {
					throw new RuntimeException( 'Automatic encryption key rotation could not be scheduled. Check that WordPress cron is available.' );
				}
			}
			if ( $due <= time() && wp_next_scheduled( self::HOOK ) > time() && ! wp_next_scheduled( self::RETRY_HOOK ) ) {
				wp_schedule_single_event( time() + 60, self::RETRY_HOOK );
			}
		} catch ( Exception $e ) {
			update_option( self::ERROR_OPTION, $e->getMessage(), false );
		}
	}

	public static function run() {
		// Schedule recovery before making changes, including for a PHP timeout.
		self::queue_retry();
		try {
			// The shared file lock and timestamp guard prevent duplicate jobs or
			// different sites in a network from minting extra annual keys.
			BackupPlus_Credential_Key_Store::rotation_time();
			$config = BackupPlus_Credential_Key_Store::rotate( true );
			self::reencrypt_credentials( $config['active'] );
			self::finish();
		} catch ( Exception $e ) {
			// Key publication retains all old keys; retry the rewrite with that
			// active key rather than generating another key on every failure.
			update_option( self::ERROR_OPTION, $e->getMessage(), false );
			if ( ! wp_next_scheduled( self::RETRY_HOOK ) ) {
				wp_schedule_single_event( time() + 86400, self::RETRY_HOOK );
			}
		}
		self::schedule();
	}

	public static function queue_retry() {
		if ( ! wp_next_scheduled( self::RETRY_HOOK ) ) {
			wp_schedule_single_event( time() + 86400, self::RETRY_HOOK );
		}
	}

	public static function finish() {
		wp_clear_scheduled_hook( self::RETRY_HOOK );
		delete_option( self::ERROR_OPTION );
	}

	/** Rewrite every site's credentials when its shared installation key changes. */
	public static function reencrypt_credentials( $active_key ) {
		if ( ! is_multisite() ) {
			self::reencrypt_site( $active_key );
			return;
		}
		$failed = false;
		foreach ( get_sites(
			array(
				'fields'     => 'ids',
				'number'     => 0,
				'network_id' => get_current_network_id(),
			)
		) as $blog_id ) {
			switch_to_blog( $blog_id );
			try {
				self::reencrypt_site( $active_key );
				delete_option( self::ERROR_OPTION );
			} catch ( Exception $e ) {
				update_option( self::ERROR_OPTION, $e->getMessage(), false );
				$failed = true;
			} finally {
				restore_current_blog();
			}
		}
		if ( $failed ) {
			throw new RuntimeException( 'Some stored credentials could not be re-encrypted. Automatic rotation will retry; previous keys have been retained.' );
		}
	}

	private static function reencrypt_site( $active_key ) {
		if ( get_option( self::REWRITTEN_KEY_OPTION, '' ) === $active_key ) {
			return;
		}
		do_action( 'backupplus_credentials_rotate' );
		if ( class_exists( 'BackupPlus_Credential_Storage' ) ) {
			BackupPlus_Credential_Storage::rewrite();
		}
		update_option( self::REWRITTEN_KEY_OPTION, $active_key, false );
		if ( get_option( self::REWRITTEN_KEY_OPTION ) !== $active_key ) {
			throw new RuntimeException( 'The encryption key rotation result could not be saved.' );
		}
	}

	public static function unschedule( $network_wide = false ) {
		if ( $network_wide && is_multisite() ) {
			foreach ( get_sites(
				array(
					'fields'     => 'ids',
					'number'     => 0,
					'network_id' => get_current_network_id(),
				)
			) as $blog_id ) {
				switch_to_blog( $blog_id );
				wp_clear_scheduled_hook( self::HOOK );
				wp_clear_scheduled_hook( self::RETRY_HOOK );
				restore_current_blog();
			}
		} else {
			wp_clear_scheduled_hook( self::HOOK );
			wp_clear_scheduled_hook( self::RETRY_HOOK );
		}
	}
}
