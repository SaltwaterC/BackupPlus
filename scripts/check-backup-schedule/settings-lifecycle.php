<?php
/** Manual scheduling, legacy installations, and settings import, export and reset. Required by ../check-backup-schedule.php, in its scope. */

// Switching to manual scheduling or cleanup after each backup removes events.
$save( array_merge( $base, array( 'backupplus_interval' => 'manual' ) ) );
$none( 'backupplus_backup' );
$only( 'backupplus_backup_database' );
$save(
	array_merge(
		$base,
		array(
			'backupplus_interval'               => 'manual',
			'backupplus_interval_database'      => 'manual',
			'backupplus_retention_cleanup_mode' => 'after_backup',
		)
	)
);
$none( 'backupplus_backup' );
$none( 'backupplus_backup_database' );
$none( 'backupplus_retention_cleanup' );
schedule_expect( array() === get_option( BackupPlus_Backup_Schedule::STATE ), 'No schedule state remains for disabled schedules' );

// Existing installations keep their interval timing until exact mode is chosen; saved hidden times alone do not enable it.
delete_option( BackupPlus_Backup_Schedule::STATE );
$legacy = time() + 7 * HOUR_IN_SECONDS + 17;
update_option( 'backupplus_interval', 'daily' );
update_option( 'backupplus_schedule_exact_files', 0 );
update_option( 'backupplus_starttime_files', '05:00' );
$test_cron = array( 'version' => 2 );
$test_cron[ $legacy ]['backupplus_backup'][ md5( serialize( array() ) ) ] = array(
	'schedule' => 'daily',
	'args'     => array(),
	'interval' => DAY_IN_SECONDS,
);
delete_option( BackupPlus_Backup_Schedule::STATE );
$schedule->reconcile();
schedule_expect( $legacy === $only( 'backupplus_backup' ), 'An existing interval event must be kept' );
$save(
	array(
		'backupplus_interval'          => 'daily',
		'backupplus_interval_database' => 'manual',
		'backupplus_starttime_files'   => '06:00',
		'backupplus_retain'            => '4',
	)
);
schedule_expect( $legacy === $only( 'backupplus_backup' ), 'Saving without choosing exact mode must keep the interval event' );
$save( array_merge( $base, array( 'backupplus_interval_database' => 'manual' ) ) );
schedule_expect( '02:45' === $local( $only( 'backupplus_backup' ) ), 'Choosing exact mode must reschedule' );

// Imports and exports include the new settings; reset removes events and state.
$keys = $backupplus->get_settings_keys();
foreach ( array( 'backupplus_schedule_exact_files', 'backupplus_schedule_exact_db', 'backupplus_schedule_exact_increments', 'backupplus_starttime_increments', 'backupplus_retention_cleanup_mode', 'backupplus_retention_cleanup_time' ) as $key ) {
	schedule_expect( in_array( $key, $keys, true ), "$key must be an exported setting" );
}
foreach ( array( BackupPlus_Backup_Schedule::STATE, BackupPlus_Retention_Cleanup::STATUS, BackupPlus_Retention_Cleanup::FAILURES ) as $key ) {
	schedule_expect( ! in_array( $key, $keys, true ), "$key is state, not a setting" );
}
$import = array_merge(
	$base,
	array(
		'backupplus_starttime_files'        => '01:05',
		'backupplus_interval_database'      => 'weekly',
		'backupplus_startday_db'            => '6',
		'backupplus_retention_cleanup_time' => '05:10',
	)
);
ob_start();
$response = $backupplus_admin->import_settings(
	array(
		'settings'           => wp_json_encode(
			array(
				'data'             => $import,
				'network_site_url' => 'https://example.invalid',
			)
		),
		'backupplus_version' => $backupplus->version,
	),
	true
);
ob_end_clean();
schedule_expect( ! empty( $response['saved'] ), 'Import must save: ' . json_encode( $response ) );
schedule_expect( '01:05' === $local( $only( 'backupplus_backup' ) ) && '6 03:40' === $local( $only( 'backupplus_backup_database' ), 'w H:i' ) && '05:10' === $local( $only( 'backupplus_retention_cleanup' ) ), 'Imported settings must schedule' );
ob_start();
$response = $backupplus_admin->import_settings(
	array(
		'settings'           => wp_json_encode(
			array(
				'data'             => array_merge( $import, array( 'backupplus_retention_cleanup_time' => '' ) ),
				'network_site_url' => 'https://example.invalid',
			)
		),
		'backupplus_version' => $backupplus->version,
	),
	true
);
ob_end_clean();
schedule_expect( empty( $response['saved'] ) && '05:10' === get_option( 'backupplus_retention_cleanup_time' ), 'An invalid import must change nothing' );
ob_start();
$backupplus_admin->wipe_settings();
ob_end_clean();
foreach ( BackupPlus_Backup_Schedule::HOOKS as $hook ) {
	$none( $hook );
}
schedule_expect( false === get_option( BackupPlus_Backup_Schedule::STATE ) && false === get_option( 'backupplus_retention_cleanup_mode' ), 'Reset must remove schedule state and settings' );
$response = $save( $base );
schedule_expect( ! empty( $response['saved'] ), 'Settings must save after a reset' );
