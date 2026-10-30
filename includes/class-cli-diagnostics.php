<?php
/** Read-only diagnostics for WP-CLI: environment checks, scheduled events and table sizes. */
if ( ! defined( 'ABSPATH' ) ) { die( 'No direct access allowed' ); }

class BackupPlus_CLI_Diagnostics {

	/** Events later than this are overdue, not merely due. */
	const OVERDUE = 600;

	private static function check( $name, $status, $detail ) {
		return array(
			'check'  => $name,
			'status' => $status,
			'detail' => $detail,
		);
	}

	/** Pending events of this plugin, soonest first. */
	public static function events() {
		BackupPlus_CLI::fresh( 'cron' );
		$schedules = wp_get_schedules();
		$rows      = array();
		foreach ( (array) _get_cron_array() as $time => $hooks ) {
			foreach ( $hooks as $hook => $events ) {
				if ( 0 !== strpos( $hook, 'backupplus_' ) ) { continue; }
				foreach ( $events as $event ) { $rows[] = array(
					'hook'         => $hook,
					'next_run'     => (int) $time,
					'next_run_utc' => gmdate( 'Y-m-d H:i:s', (int) $time ),
					'overdue'      => max( 0, time() - (int) $time ),
					'schedule'     => ! empty( $event['schedule'] ) && isset( $schedules[ $event['schedule'] ] ) ? $event['schedule'] : '',
					'args'         => $event['args'] ? wp_json_encode( $event['args'] ) : '',
				); }
			}
		}
		return $rows;
	}

	public static function checks() {
		global $backupplus;
		$rows   = array();
		$dir    = $backupplus->backups_dir_location();
		$rows[] = self::check( 'backup directory', BackupPlus_Filesystem_Functions::really_is_writable( $dir ) ? 'ok' : 'fail', $dir );
		$free   = @disk_free_space( $dir );
		if ( false === $free ) { $rows[] = self::check( 'free disk space', 'warn', 'unknown' ); }
		else { $rows[] = self::check( 'free disk space', $free < GB_IN_BYTES ? 'warn' : 'ok', size_format( $free, 1 ) ); }
		$cron_off = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$rows[]   = self::check( 'wp-cron', 'ok', $cron_off ? 'disabled; a system job must run wp cron event run --due-now' : 'enabled' );
		$late     = 0;
		foreach ( self::events() as $event ) { $late += $event['overdue'] > self::OVERDUE ? 1 : 0; }
		$rows[] = self::check( 'overdue events', $late ? 'warn' : 'ok', $late ? $late . ' scheduled event(s) are more than ' . ( self::OVERDUE / 60 ) . ' minutes late' : 'none' );
		try {
			BackupPlus_Credential_Key_Store::configuration();
			$rows[] = self::check( 'credential keys', 'ok', BackupPlus_Credential_Key_Store::environment_managed() ? 'managed in the environment' : 'managed on disk' );
		} catch ( Throwable $error ) { $rows[] = self::check( 'credential keys', 'fail', $error->getMessage() ); }
		$enabled = array();
		foreach ( BackupPlus_CLI::schedules() as $schedule ) { if ( ! in_array( $schedule['interval'], array( 'disabled', 'manual', 'none' ), true ) ) { $enabled[] = $schedule['type']; }
		}
		$rows[]  = self::check( 'schedules', $enabled ? 'ok' : 'warn', $enabled ? implode( ', ', $enabled ) : 'no automatic backup is scheduled' );
		$history = BackupPlus_Backup_History::get_history();
		if ( $history ) {
			$latest = max( array_keys( $history ) );
			$rows[] = self::check( 'latest backup', 'ok', gmdate( 'Y-m-d H:i:s', $latest ) . ' UTC (' . human_time_diff( $latest ) . ' ago)' );
		} else { $rows[] = self::check( 'latest backup', 'warn', 'there is no backup in history' ); }
		return $rows;
	}

	/** Tables of the WordPress database with their engine and size in bytes. */
	public static function tables() {
		global $wpdb;
		$rows = array();
		foreach ( (array) BackupPlus_SQL::wpdb()->rows( 'SHOW TABLE STATUS', array(), array(), ARRAY_A ) as $table ) { $rows[] = array(
			'name'   => $table['Name'],
			'engine' => $table['Engine'],
			'data'   => (int) $table['Data_length'],
			'index'  => (int) $table['Index_length'],
			'total'  => (int) $table['Data_length'] + (int) $table['Index_length'],
		); }
		return $rows;
	}
}
