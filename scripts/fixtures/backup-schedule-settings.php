<?php
/** Snapshot, inspect and restore the settings and cron events changed by the schedule browser test. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	die( 'CLI only.' );
}
$token     = isset( $args[0] ) ? $args[0] : '';
$operation = isset( $args[1] ) ? $args[1] : '';
if ( ! preg_match( '/\A[a-f0-9]{24}\z/D', $token ) ) {
	throw new RuntimeException( 'Invalid test token.' );
}
global $wpdb;
$path  = get_temp_dir() . 'backupplus-schedule-browser-' . $token . '.json';
$where = "(option_name LIKE 'backupplus\\_%' OR option_name IN ('cron', 'timezone_string', 'gmt_offset')) AND option_name NOT LIKE 'backupplus\\_jobdata\\_%' AND option_name <> 'backupplus_backup_history'";
if ( 'snapshot' === $operation ) {
	$rows = $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE $where", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Constant condition.
	if ( false === file_put_contents( $path, wp_json_encode( $rows ) ) ) {
		throw new RuntimeException( 'Cannot save the settings snapshot.' );
	}
} elseif ( 'read' === $operation ) {
	$read = array();
	foreach ( array( 'backupplus_interval', 'backupplus_interval_database', 'backupplus_schedule_exact_files', 'backupplus_schedule_exact_db', 'backupplus_interval_increments', 'backupplus_schedule_exact_increments', 'backupplus_starttime_increments', 'backupplus_starttime_files', 'backupplus_starttime_db', 'backupplus_startday_files', 'backupplus_startday_db', 'backupplus_retention_cleanup_mode', 'backupplus_retention_cleanup_time' ) as $name ) {
		$read['options'][ $name ] = get_option( $name, null );
	}
	foreach ( array( 'backupplus_backup', 'backupplus_backup_database', 'backupplus_backup_increments', 'backupplus_retention_cleanup' ) as $hook ) {
		$read['events'][ $hook ] = array();
		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			if ( ! empty( $hooks[ $hook ] ) ) {
				foreach ( $hooks[ $hook ] as $event ) {
					$read['events'][ $hook ][] = wp_date( 'w H:i', $timestamp ) . ' ' . $event['schedule'];
				}
			}
		}
	}
	echo wp_json_encode( $read );
} elseif ( 'restore' === $operation ) {
	$rows = is_file( $path ) ? json_decode( file_get_contents( $path ), true ) : null;
	if ( ! is_array( $rows ) ) {
		throw new RuntimeException( 'The settings snapshot is missing.' );
	}
	// Overwrite in place: deleting first would let a concurrent request find no cron schedule and start a new one.
	$names = array();
	foreach ( $rows as $row ) {
		$names[] = $row['option_name'];
		if ( false === $wpdb->replace( $wpdb->options, $row ) ) {
			throw new RuntimeException( 'Cannot restore ' . $row['option_name'] . ': ' . $wpdb->last_error );
		}
	}
	foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE $where" ) as $name ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Constant condition.
		if ( ! in_array( $name, $names, true ) ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
		}
	}
	wp_cache_flush();
	unlink( $path );
} else {
	throw new RuntimeException( 'Invalid operation.' );
}
