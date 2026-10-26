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
	}

	public static function scheduled() {
		@ignore_user_abort( true );
		@set_time_limit( 0 );
		try {
			$worker = new self();
			// Short intervals already have their own cron cadence; dump/upload time must not skip the next tick.
			$interval = isset( $worker->config['interval'] ) ? (int) $worker->config['interval'] : 15;
			$worker->run( $interval <= 60 ? 'capture' : 'run' );
		} catch ( Throwable $error ) {
			global $backupplus;
			$backupplus->log( $error->getMessage(), 'error' );
		}
	}

	public static function config() {
		$config = BackupPlus_Options::get_backupplus_option( self::OPTION, array( 'enabled' => false ) );
		return is_array( $config ) ? $config : array( 'enabled' => false );
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
			if ( wp_next_scheduled( self::HOOK ) ) {
				self::deactivate();
			} return;
		}
		if ( ! isset( $config['interval'] ) || ! in_array( (int) $config['interval'], self::intervals(), true ) ) {
			return;
		}
		// Renew daily baselines even when incremental capture is only twice a day.
		$recurrence = 'backupplus_pitr_' . min( 60, (int) $config['interval'] );
		if ( wp_get_schedule( self::HOOK ) !== $recurrence ) {
			self::deactivate();
			wp_schedule_event( time(), $recurrence, self::HOOK );
		}
	}
}
