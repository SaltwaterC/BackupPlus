<?php

/** WordPress hook registration, the capture schedule, saved settings and the manual capture entry point. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_PITR_Orchestrator_Scheduling {

	public static function register() {
		require_once dirname( __DIR__ ) . '/class-pitr-admin.php';
		BackupPlus_PITR_Admin::register();
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'scheduled' ) );
		add_action( self::BASELINE_HOOK, array( __CLASS__, 'baseline_check' ) );
		add_action( 'init', array( __CLASS__, 'reconcile_schedule' ) );
		add_action( 'backupplus_pitr_status', array( __CLASS__, 'render' ) );
		add_action( 'backupplus_clean_temporary_files', array( __CLASS__, 'clean_temporary' ) );
		register_deactivation_hook( BACKUPPLUS_DIR . '/backupplus.php', array( __CLASS__, 'deactivate' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once dirname( __DIR__ ) . '/class-pitr-cli.php';
			WP_CLI::add_command( 'backupplus pitr', 'BackupPlus_PITR_CLI' );
		}
	}

	public static function schedules( $schedules ) {
		foreach ( self::intervals() as $minutes ) {
			$schedules[ 'backupplus_pitr_' . $minutes ] = array(
				'interval' => $minutes * 60,
				'display'  => 1 === $minutes ? __( 'Database log capture every minute', 'backupplus' ) : sprintf( __( 'Database log capture every %d minutes', 'backupplus' ), $minutes ),
			);
		}
		return $schedules;
	}

	public static function intervals() {
		return array( 1, 5, 10, 15, 30, 60, 120, 180, 240, 360, 480, 720 );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::BASELINE_HOOK );
		delete_option( self::SCHEDULE_STATE );
	}

	public static function scheduled( $scheduled_at = null ) {
		@ignore_user_abort( true );
		@set_time_limit( 0 );
		try {
			$config = self::config();
			if ( self::has_exact_schedule( $config ) && is_numeric( $scheduled_at ) ) {
				self::queue_next_exact( $config );
			}
			$worker = new self();
			$interval = isset( $worker->config['interval'] ) ? (int) $worker->config['interval'] : 15;
			$worker->run( self::has_exact_schedule( $worker->config ) || $interval <= 60 ? 'capture' : 'run' );
		} catch ( Throwable $error ) {
			global $backupplus;
			$backupplus->log( $error->getMessage(), 'error' );
		}
	}

	/** Keep the daily full-snapshot limit independent of an exact incremental capture cadence. */
	public static function baseline_check() {
		@ignore_user_abort( true );
		@set_time_limit( 0 );
		try {
			$config = self::config();
			if ( empty( $config['enabled'] ) || ! self::has_exact_schedule( $config ) || (int) $config['interval'] <= 60 ) {
				return;
			}
			$worker = new self();
			$worker->run( 'baseline' );
		} catch ( Throwable $error ) {
			global $backupplus;
			$backupplus->log( $error->getMessage(), 'error' );
		}
	}

	public static function config() {
		$config = BackupPlus_Options::get_backupplus_option( self::OPTION, array( 'enabled' => false ) );
		return is_array( $config ) ? $config : array( 'enabled' => false );
	}

	/** The next PITR event, including exact-time one-off events with a timestamp argument. */
	public static function next_scheduled() {
		$state     = get_option( self::SCHEDULE_STATE, array() );
		$scheduled = is_array( $state ) && isset( $state['scheduled_at'] ) ? (int) $state['scheduled_at'] : 0;
		if ( $scheduled ) {
			$next = wp_next_scheduled( self::HOOK, array( $scheduled ) );
			if ( $next ) {
				return $next;
			}
		}
		return wp_next_scheduled( self::HOOK );
	}

	/** Persist internal state without treating it as the one-field admin form. */
	public static function save_configuration( $config ) {
		unset( $config['restore_connection'] );
		$callback = array( 'BackupPlus_PITR_Admin', 'sanitize' );
		$hook     = 'sanitize_option_' . self::OPTION;
		$priority = has_filter( $hook, $callback );
		if ( false !== $priority ) { remove_filter( $hook, $callback, $priority ); }
		try { return BackupPlus_Options::update_backupplus_option( self::OPTION, $config ); }
		finally { if ( false !== $priority ) { add_filter( $hook, $callback, $priority ); }
		}
	}

	/** Capture now from the queued admin request, preserving its progress/log identifier. */
	public static function manual_capture( $options ) {
		global $backupplus;
		$nonce = $backupplus->backup_time_nonce( isset( $options['use_nonce'] ) ? $options['use_nonce'] : false );
		$backupplus->jobdata_set_from_array(
			array(
				'job_type'    => 'pitr',
				'jobstatus'   => 'pitrcreating',
				'backup_time' => time(),
			)
		);
		$backupplus->current_resumption = 0;
		$backupplus->logfile_open( $nonce );
		try {
			$backupplus->log( 'Capturing a PITR database recovery point using saved encryption and storage settings.' );
			$worker = new self();
			if ( ! empty( $worker->state['pending'] ) ) {
				// Finish the interrupted publication, then capture changes through this request.
				$worker->run( 'capture', $nonce );
			}
			$result = $worker->run( 'capture', $nonce );
			$backupplus->log( 'PITR database recovery point captured.' );
			return $result;
		} catch ( Throwable $error ) {
			$backupplus->log( $error->getMessage(), 'error' );
			$backupplus->log( 'PITR database recovery point failed: ' . $error->getMessage() );
			$backupplus->jobdata_set( 'pitr_error', $error->getMessage() );
			$status          = get_option( self::STATUS, array() );
			$status['error'] = $error->getMessage();
			update_option( self::STATUS, $status, false );
			return false;
		} finally {
			$backupplus->jobdata_set( 'jobstatus', 'finished' );
			if ( is_resource( $backupplus->logfile_handle ) ) {
				fclose( $backupplus->logfile_handle );
				$backupplus->logfile_handle = null;
			}
		}
	}

	public static function reconcile_schedule() {
		$config = self::config();
		if ( empty( $config['enabled'] ) ) {
			self::deactivate();
			return;
		}
		if ( ! isset( $config['interval'] ) || ! in_array( (int) $config['interval'], self::intervals(), true ) ) {
			return;
		}
		// Renew daily baselines even when incremental capture is only twice a day.
		$interval = (int) $config['interval'];
		if ( self::has_exact_schedule( $config ) ) {
			if ( $interval > 60 ) {
				$baseline_recurrence = 'backupplus_pitr_60';
				if ( wp_get_schedule( self::BASELINE_HOOK ) !== $baseline_recurrence ) {
					wp_clear_scheduled_hook( self::BASELINE_HOOK );
					wp_schedule_event( time(), $baseline_recurrence, self::BASELINE_HOOK );
				}
			} else {
				wp_clear_scheduled_hook( self::BASELINE_HOOK );
			}
			$signature = self::exact_signature( $config );
			$state     = get_option( self::SCHEDULE_STATE, array() );
			$state     = is_array( $state ) ? $state : array();
			$scheduled = isset( $state['scheduled_at'] ) ? (int) $state['scheduled_at'] : 0;
			$pending   = $scheduled && wp_next_scheduled( self::HOOK, array( $scheduled ) );
			if ( isset( $state['signature'] ) && is_string( $state['signature'] ) && hash_equals( $state['signature'], $signature ) && $pending ) {
				return;
			}
			wp_clear_scheduled_hook( self::HOOK );
			delete_option( self::SCHEDULE_STATE );
			self::queue_next_exact( $config );
			return;
		}

		wp_clear_scheduled_hook( self::BASELINE_HOOK );
		$recurrence = 'backupplus_pitr_' . min( 60, $interval );
		$state      = get_option( self::SCHEDULE_STATE, array() );
		if ( wp_get_schedule( self::HOOK ) === $recurrence && ( ! is_array( $state ) || empty( $state['signature'] ) ) ) {
			return;
		}
		self::deactivate();
		wp_schedule_event( time(), $recurrence, self::HOOK );
	}

	private static function has_exact_schedule( $config ) {
		if ( empty( $config['enabled'] ) || empty( $config['exact'] ) || ! isset( $config['interval'], $config['time'] ) ) {
			return false;
		}
		$interval   = (int) $config['interval'];
		$recurrence = 'backupplus_pitr_' . $interval;
		return $interval > 1 && BackupPlus_Backup_Schedule::supports_exact( $recurrence ) && BackupPlus_Backup_Schedule::valid_time( $config['time'] );
	}

	private static function exact_signature( $config ) {
		return hash( 'sha256', serialize( array( (int) $config['interval'], $config['time'], BackupPlus_Backup_Schedule::timezone()->getName() ) ) );
	}

	private static function queue_next_exact( $config ) {
		$signature = self::exact_signature( $config );
		$state     = get_option( self::SCHEDULE_STATE, array() );
		$state     = is_array( $state ) ? $state : array();
		$scheduled = isset( $state['scheduled_at'] ) ? (int) $state['scheduled_at'] : 0;
		if ( isset( $state['signature'] ) && is_string( $state['signature'] ) && hash_equals( $state['signature'], $signature ) && $scheduled && wp_next_scheduled( self::HOOK, array( $scheduled ) ) ) {
			return;
		}
		$definition = array(
			'interval' => 'backupplus_pitr_' . (int) $config['interval'],
			'exact'    => true,
			'time'     => $config['time'],
			'day'      => null,
		);
		$next       = BackupPlus_Backup_Schedule::next_run( $definition, time(), BackupPlus_Backup_Schedule::timezone() );
		if ( $next && wp_schedule_single_event( $next, self::HOOK, array( $next ) ) ) {
			update_option( self::SCHEDULE_STATE, array( 'signature' => $signature, 'scheduled_at' => $next ), false );
		}
	}
}
