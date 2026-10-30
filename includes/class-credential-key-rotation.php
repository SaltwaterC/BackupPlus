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
		add_filter( 'backupplus_report_extramessages', array( __CLASS__, 'report_messages' ) );
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
		if ( BackupPlus_Multisite::secondary() ) {
			return;
		}
		if ( BackupPlus_Credential_Key_Store::environment_managed() ) {
			// Environment key rings are read-only in WordPress; their owner controls rotation.
			wp_clear_scheduled_hook( self::HOOK );
			wp_clear_scheduled_hook( self::RETRY_HOOK );
			return;
		}
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
		if ( BackupPlus_Credential_Key_Store::environment_managed() ) {
			self::schedule();
			return;
		}
		// Schedule recovery before making changes, including for a PHP timeout.
		self::queue_retry();
		try {
			// The shared file lock and timestamp guard prevent duplicate jobs or
			// different sites in a network from minting extra annual keys.
			$previous = BackupPlus_Credential_Key_Store::configuration();
			BackupPlus_Credential_Key_Store::rotation_time();
			$config = BackupPlus_Credential_Key_Store::rotate( true );
			self::reencrypt_credentials( $config['active'] );
			self::finish();
			if ( $config['active'] !== $previous['active'] ) {
				self::notify_rotation( $config );
			}
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

	/** Rotate disk-managed keys immediately and keep the ordinary retry behavior. */
	public static function rotate_manually() {
		if ( BackupPlus_Credential_Key_Store::environment_managed() ) {
			throw new RuntimeException( 'Use the generated environment key value to rotate environment-managed credentials.' );
		}
		self::queue_retry();
		try {
			$config = BackupPlus_Credential_Key_Store::rotate();
			self::reencrypt_credentials( $config['active'] );
			self::finish();
			self::notify_rotation( $config );
			self::schedule();
			return $config;
		} catch ( Exception $e ) {
			update_option( self::ERROR_OPTION, $e->getMessage(), false );
			if ( ! wp_next_scheduled( self::RETRY_HOOK ) ) {
				wp_schedule_single_event( time() + 86400, self::RETRY_HOOK );
			}
			throw $e;
		}
	}

	/** Add an age warning to the normal report when a key has reached its annual limit. */
	public static function report_messages( $messages ) {
		if ( ! is_array( $messages ) ) {
			$messages = array();
		}
		if ( get_option( 'backupplus_credential_setup_error', '' ) || get_option( self::ERROR_OPTION, '' ) ) {
			$messages[] = array(
				'key' => __( 'Credential key maintenance alert', 'backupplus' ),
				'val' => __( 'BackupPlus could not confirm that credential key maintenance completed. Check the key status in Settings → Expert settings → Credential encryption.', 'backupplus' ),
			);
		}
		try {
			$config = BackupPlus_Credential_Key_Store::configuration();
		} catch ( Exception $e ) {
			return $messages;
		}
		$age = max( 0, time() - $config['issued_at'] );
		if ( $age < BackupPlus_Credential_Key_Store::ROTATION_INTERVAL ) {
			return $messages;
		}
		$days       = (int) floor( $age / DAY_IN_SECONDS );
		$issued     = date_i18n( get_option( 'date_format' ), $config['issued_at'] );
		$guidance   = BackupPlus_Credential_Key_Store::environment_managed()
			? __( 'Rotate it in the service environment configuration; WordPress cannot rotate environment-managed keys.', 'backupplus' )
			: __( 'Check that WordPress cron is running, or rotate the key in BackupPlus Settings.', 'backupplus' );
		$messages[] = array(
			'key' => __( 'Credential key age alert', 'backupplus' ),
			'val' => sprintf(
				/* translators: 1: date the active key was issued, 2: age in days, 3: rotation guidance */
				__( 'The active credential encryption key was issued on %1$s and is %2$s days old. %3$s', 'backupplus' ),
				$issued,
				number_format_i18n( $days ),
				$guidance
			),
		);
		return $messages;
	}

	/** Send successful disk-key rotations to the addresses enabled for backup reports. */
	public static function notify_rotation( $config ) {
		if ( empty( $config['issued_at'] ) || ! class_exists( 'BackupPlus_Options' ) ) {
			return;
		}
		$email     = BackupPlus_Options::get_backupplus_option( 'backupplus_email' );
		$addresses = array();
		global $backupplus;
		if ( ! empty( $email ) && is_object( $backupplus ) && is_callable( array( $backupplus, 'just_one_email' ) ) ) {
			$recipients = $backupplus->just_one_email( $email, true );
			$recipients = is_array( $recipients ) ? $recipients : array( $recipients );
			foreach ( $recipients as $recipient ) {
				foreach ( explode( ',', (string) $recipient ) as $address ) {
					$address = trim( $address );
					if ( is_email( $address ) ) {
						$addresses[] = $address;
					}
				}
			}
			$addresses = array_unique( $addresses );
		}
		if ( ! $addresses && ! BackupPlus_Ntfy::enabled() ) {
			return;
		}
		$issued  = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $config['issued_at'] );
		$subject = sprintf(
			/* translators: %s: site name */
			__( 'BackupPlus credential key rotated for %s', 'backupplus' ),
			wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES )
		);
		$body = sprintf(
			/* translators: 1: site URL, 2: date and time */
			__( 'The credential encryption key for %1$s was rotated successfully. The active key was issued on %2$s. Previous keys were retained so existing credentials and jobs remain readable. Download a fresh recovery key from BackupPlus Settings.', 'backupplus' ),
			site_url(),
			$issued
		);
		foreach ( $addresses as $address ) {
			try {
				wp_mail( $address, $subject, $body );
			} catch ( Exception $e ) {
				// Mail delivery is separate from key publication and must not trigger a rotation retry.
			}
		}
		BackupPlus_Ntfy::notify( $subject, $body, 3, array( 'key' ) );
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
