<?php
/** Exact times, repeated and unrelated saves, delayed cron, invalid input, options.php sanitisers and combining of identical schedules. Required by ../check-backup-schedule.php, in its scope. */

// Independent daily times for files, database and cleanup, with the refreshed displays.
$response = $save( $base );
schedule_expect( ! empty( $response['saved'] ), 'Valid exact settings must save: ' . json_encode( $response ) );
$files_at   = $only( 'backupplus_backup' );
$db_at      = $only( 'backupplus_backup_database' );
$cleanup_at = $only( 'backupplus_retention_cleanup' );
schedule_expect( '02:15' === $local( $files_at ) && '03:40' === $local( $db_at ) && '04:05' === $local( $cleanup_at ), 'Events must use the chosen local times' );
schedule_expect( $within_day( $files_at ) && $within_day( $db_at ) && $within_day( $cleanup_at ), 'Daily events must be the next occurrence' );
schedule_expect( ! $schedule->same_schedule() && $schedule->any_exact(), 'Different exact times must stay independent' );
foreach ( array(
	'files_scheduled'    => $files_at,
	'database_scheduled' => $db_at,
	'cleanup_scheduled'  => $cleanup_at,
) as $key => $timestamp ) {
	schedule_expect( false !== strpos( $response[ $key ], BackupPlus_Backup_Schedule::format_date( $timestamp ) ), "The save response must refresh $key" );
}
schedule_expect( 'daily' === get_option( 'backupplus_retention_cleanup_mode' ) && '04:05' === get_option( 'backupplus_retention_cleanup_time' ) && 1 === (int) get_option( 'backupplus_schedule_exact_db' ), 'Settings must persist' );
backupplus_try_include_file( 'includes/class-commands.php', 'include_once' );
backupplus_try_include_file( 'includes/class-wpadmin-commands.php', 'include_once' );
$listed = wp_list_pluck( ( new ReflectionClass( 'BackupPlus_WPAdmin_Commands' ) )->newInstanceWithoutConstructor()->get_cron_events(), 'hook' );
schedule_expect( ! array_diff( array( 'backupplus_backup', 'backupplus_backup_database', 'backupplus_retention_cleanup' ), $listed ), 'Advanced Tools must list the backup and cleanup events: ' . implode( ', ', $listed ) );

// Repeated and unrelated saves keep queued events, including overdue ones.
$save( $base );
schedule_expect( $files_at === $only( 'backupplus_backup' ) && $db_at === $only( 'backupplus_backup_database' ) && $cleanup_at === $only( 'backupplus_retention_cleanup' ), 'Saving unchanged settings must keep events' );
$overdue = time() - 3 * HOUR_IN_SECONDS;
wp_unschedule_event( $files_at, 'backupplus_backup' );
$test_cron[ $overdue ]['backupplus_backup'][ md5( serialize( array() ) ) ] = array(
	'schedule' => 'daily',
	'args'     => array(),
	'interval' => DAY_IN_SECONDS,
);
ksort( $test_cron );
$save( array_merge( $base, array( 'backupplus_retain' => '3' ) ) );
schedule_expect( $overdue === $only( 'backupplus_backup' ), 'An unrelated save must preserve an overdue event' );
schedule_expect( '3' === (string) get_option( 'backupplus_retain' ), 'The unrelated setting must save' );

// Changing only the time reschedules.
$save( array_merge( $base, array( 'backupplus_starttime_files' => '02:45' ) ) );
$files_at = $only( 'backupplus_backup' );
schedule_expect( '02:45' === $local( $files_at ) && $within_day( $files_at ), 'A time-only change must reschedule' );
schedule_expect( $db_at === $only( 'backupplus_backup_database' ), 'Changing the file time must not move the database event' );
$base['backupplus_starttime_files'] = '02:45';

// Delayed cron: WordPress reschedules a late recurring event from its old time; the next run keeps the calendar time.
$late = time() - 5 * HOUR_IN_SECONDS;
foreach ( array( 'backupplus_backup', 'backupplus_retention_cleanup' ) as $hook ) {
	wp_clear_scheduled_hook( $hook );
	$test_cron[ $late ][ $hook ][ md5( serialize( array() ) ) ] = array(
		'schedule' => 'daily',
		'args'     => array(),
		'interval' => DAY_IN_SECONDS,
	);
	ksort( $test_cron );
	wp_reschedule_event( $late, 'daily', $hook );
	wp_unschedule_event( $late, $hook );
}
schedule_expect( '02:45' === $local( $only( 'backupplus_backup' ) ) && $within_day( $only( 'backupplus_backup' ) ), 'A delayed file run must schedule the next calendar time' );
schedule_expect( '04:05' === $local( $only( 'backupplus_retention_cleanup' ) ), 'A delayed cleanup must schedule the next calendar time' );
$files_at = $only( 'backupplus_backup' );

// Invalid input is rejected before anything is saved.
foreach ( array(
	array( 'backupplus_starttime_files' => '25:00' ),
	array( 'backupplus_starttime_db' => '' ),
	array(
		'backupplus_interval'       => 'monthly',
		'backupplus_startday_files' => '31',
	),
	array( 'backupplus_retention_cleanup_mode' => 'hourly' ),
	array( 'backupplus_retention_cleanup_time' => 'soon' ),
	array( 'backupplus_interval' => 'not-a-schedule' ),
) as $invalid ) {
	$retain   = (string) get_option( 'backupplus_retain' );
	$response = $save( array_merge( $base, array( 'backupplus_retain' => '9' ), $invalid ) );
	schedule_expect( empty( $response['saved'] ) && ! empty( $response['error_message'] ), 'Invalid input must be rejected: ' . json_encode( $invalid ) );
	schedule_expect( '9' !== $retain && $retain === (string) get_option( 'backupplus_retain' ) && 'daily' === get_option( 'backupplus_interval' ), 'A rejected save must change nothing' );
	schedule_expect( $files_at === $only( 'backupplus_backup' ) && $db_at === $only( 'backupplus_backup_database' ), 'A rejected save must keep events' );
}
// The options.php form is checked before WordPress writes any option.
$handler = function () {
	return function ( $message ) {
		throw new Schedule_Test_Die( is_string( $message ) ? $message : 'died' );
	};
};
add_filter( 'wp_die_handler', $handler, 1000 );
$GLOBALS['pagenow'] = 'options.php';
$_POST              = array_merge(
	$base,
	array(
		'option_page'                => 'backupplus-options-group',
		'backupplus_starttime_files' => '7pm',
	)
);
try {
	$schedule->validate_options_submission();
	schedule_expect( false, 'An invalid options.php submission must stop' );
} catch ( Schedule_Test_Die $e ) {
	schedule_expect( false !== strpos( $e->getMessage(), 'HH:MM' ), 'The options.php error must explain the time format' );
}
$_POST['backupplus_starttime_files'] = '19:00';
$schedule->validate_options_submission();
$_POST              = array();
$GLOBALS['pagenow'] = 'index.php';
remove_filter( 'wp_die_handler', $handler, 1000 );

// The settings sanitisers used by options.php, with reconciliation once all options are written.
BackupPlus_Options::admin_init();
update_option( 'backupplus_starttime_db', '3:55' );
update_option( 'backupplus_retention_cleanup_mode', 'bogus' );
schedule_expect( '03:55' === get_option( 'backupplus_starttime_db' ) && 'after_backup' === get_option( 'backupplus_retention_cleanup_mode' ), 'Sanitisers must normalise values' );
$schedule->reconcile_if_pending();
schedule_expect( '03:55' === $local( $only( 'backupplus_backup_database' ) ), 'Option writes must reconcile the database event' );
$none( 'backupplus_retention_cleanup' );
$save( $base );
$db_at = $only( 'backupplus_backup_database' );
schedule_expect( '03:40' === $local( $db_at ) && '04:05' === $local( $only( 'backupplus_retention_cleanup' ) ), 'Saving restores the database and cleanup events' );

// Identical schedules combine once; different exact schedules bypass the ten-minute combining margin.
$booted  = array();
$capture = function ( $continue, $files, $database ) use ( &$booted ) {
	$booted[] = array( $files, $database );
	return false;
};
add_filter( 'backupplus_boot_backup', $capture, 10, 3 );
$save( array_merge( $base, array( 'backupplus_starttime_db' => '02:45' ) ) );
schedule_expect( $schedule->same_schedule(), 'Identical exact schedules must combine' );
schedule_expect( $only( 'backupplus_backup' ) === $only( 'backupplus_backup_database' ), 'Identical schedules have the same next time' );
$backupplus->backup_files();
$backupplus->backup_database();
schedule_expect( array( array( true, true ), array( false, false ) ) === $booted, 'An identical schedule must run once, from the files event: ' . json_encode( $booted ) );
$booted = array();
$save( array_merge( $base, array( 'backupplus_starttime_db' => '02:50' ) ) );
schedule_expect( ! $schedule->same_schedule() && 300 === $only( 'backupplus_backup_database' ) - $only( 'backupplus_backup' ), 'Exact schedules five minutes apart stay separate' );
schedule_expect( false === get_option( 'backupplus_combine_jobs_around', false ), 'Exact schedules must not set the combining marker' );
$backupplus->backup_files();
$backupplus->backup_database();
schedule_expect( array( array( true, false ), array( false, true ) ) === $booted, 'Close exact schedules must run independently: ' . json_encode( $booted ) );
remove_filter( 'backupplus_boot_backup', $capture, 10 );
$save( $base );
