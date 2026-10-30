<?php
/** Destination-specific archive accounting, retention and notifications. */
if ( ! defined( 'ABSPATH' ) ) { die( 'No direct access allowed' ); }
require_once __DIR__ . '/class-ntfy.php';
require_once __DIR__ . '/storage-limits/trait-policy.php';
require_once __DIR__ . '/storage-limits/trait-monitor.php';
require_once __DIR__ . '/storage-limits/trait-interface.php';

class BackupPlus_Storage_Limits {
	use BackupPlus_Storage_Limits_Policy;
	use BackupPlus_Storage_Limits_Monitor;
	use BackupPlus_Storage_Limits_Interface;
	const OPTION = 'backupplus_storage_limits';
	const STATUS = 'backupplus_storage_limits_status';
	const HOOK   = 'backupplus_storage_monitor';
	const RETRY  = 'backupplus_storage_monitor_retry';
	const GB     = 1000000000;

	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( self::RETRY, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'reconcile' ) );
		add_action( 'add_option_' . self::OPTION, array( __CLASS__, 'reconcile' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'reconcile' ) );
		add_action( 'delete_option_' . self::OPTION, array( __CLASS__, 'reset' ) );
		add_action( 'backupplus_backup_finished', array( __CLASS__, 'published' ), 30, 3 );
	}

	/** Values from exports/API are bytes; only the explicit form marker requests GB conversion. */
	public static function sanitize( $input ) {
		$result = array();
		if ( ! is_array( $input ) ) { return $result; }
		$factor = isset( $input['_unit'] ) && 'GB' === $input['_unit'] ? self::GB : 1;
		foreach ( $input as $service => $instances ) {
			if ( ! is_string( $service ) || ! preg_match( '/\A[a-z][a-z0-9_-]*\z/D', $service ) || ! is_array( $instances ) ) { continue; }
			foreach ( $instances as $id => $limits ) {
				if ( ! preg_match( '/\A[a-zA-Z0-9_-]*\z/D', (string) $id ) || ! is_array( $limits ) ) { continue; }
				foreach ( array( 'alert', 'quota' ) as $field ) {
					$value = isset( $limits[ $field ] ) && is_scalar( $limits[ $field ] ) && is_numeric( $limits[ $field ] ) ? (float) $limits[ $field ] * $factor : 0;
					$result[ $service ][ '__default' === (string) $id ? '' : (string) $id ][ $field ] = is_finite( $value ) && $value > 0 ? (int) min( floor( $value ), PHP_INT_MAX - 1024 ) : 0;
				}
			}
		}
		return $result;
	}

	public static function limits() {
		return self::sanitize( BackupPlus_Options::get_backupplus_option( self::OPTION, array() ) ); }
	public static function quota( $service = 'local', $id = '' ) {
		$limits = self::limits();
		return isset( $limits[ $service ][ $id ]['quota'] ) ? $limits[ $service ][ $id ]['quota'] : 0;
	}
	public static function managed() {
		foreach ( self::limits() as $instances ) {
			foreach ( $instances as $limits ) {
				if ( $limits['quota'] ) { return true; }
			}
		}
		return false;
	}
	public static function enabled() {
		foreach ( self::limits() as $instances ) {
			foreach ( $instances as $limits ) {
				if ( $limits['quota'] || $limits['alert'] ) { return true; }
			}
		}
		return false;
	}

	public static function reconcile() {
		if ( BackupPlus_Multisite::secondary() ) {
			return;
		}
		if ( self::enabled() ) {
			if ( ! wp_next_scheduled( self::HOOK ) ) { wp_schedule_event( time() + 60, 'hourly', self::HOOK ); }
		} else { self::deactivate(); }
	}
	public static function deactivate() {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::RETRY ); }
	public static function reset() {
		self::deactivate();
		delete_option( self::STATUS ); }
	public static function retry() {
		if ( self::enabled() && ! wp_next_scheduled( self::RETRY ) ) { wp_schedule_single_event( time() + 300, self::RETRY ); }
	}

	public static function published( $nonce, $outcome, $job ) {
		$history = BackupPlus_Backup_History::get_history();
		$time    = isset( $job['incremental_base_time'] ) ? $job['incremental_base_time'] : ( isset( $job['backup_time'] ) ? $job['backup_time'] : 0 );
		if ( isset( $history[ $time ] ) ) {
			$history[ $time ]['storage_complete'] = 'success' === $outcome;
			BackupPlus_Backup_History::save_history( $history );
		}
		self::run( null, ! BackupPlus_Retention_Cleanup::deferred() );
	}
}
