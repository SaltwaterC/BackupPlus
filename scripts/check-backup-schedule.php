<?php
/** WordPress integration checks for exact backup times and the daily retention cleanup, using disposable data. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || defined( 'BACKUPPLUS_DIR' ) ) {
	die( 'Use WP-CLI with --skip-plugins=backupplus.' );
}
$test_complete = false;
register_shutdown_function(
	function () use ( &$test_complete ) {
		if ( ! $test_complete ) {
			fwrite( STDERR, "Backup schedule integration checks did not reach completion.\n" );
			register_shutdown_function(
				function () {
					exit( 1 );
				}
			);
		}
	}
);
// A failure must not end in WordPress's fatal error handler, which exits before the settings below are restored.
set_exception_handler(
	function ( $error ) {
		fwrite( STDERR, 'Backup schedule check failed: ' . $error->getMessage() . "\n" . $error->getTraceAsString() . "\n" );
		exit( 1 );
	}
);
function schedule_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
class Schedule_Test_Die extends Exception {}
class Schedule_Test_Interruption extends Exception {}

$token     = bin2hex( random_bytes( 6 ) );
$directory = get_temp_dir() . 'backupplus-schedule-' . $token;
mkdir( $directory );
$archives = $directory . '/archives';
mkdir( $archives );
$test_cron    = array();
$test_history = array();
$mails        = 0;
$finished     = 0;
$fake_jobs    = array();

add_filter(
	'pre_option_cron',
	function () use ( &$test_cron ) {
		return $test_cron;
	},
	100
);
add_filter(
	'pre_update_option_cron',
	function ( $value, $old ) use ( &$test_cron ) {
		$test_cron = $value;
		return $old;
	},
	100,
	2
);
add_filter(
	'pre_option_backupplus_backup_history',
	function () use ( &$test_history ) {
		return $test_history;
	},
	100
);
add_filter(
	'pre_update_option_backupplus_backup_history',
	function ( $value, $old ) use ( &$test_history ) {
		$test_history = $value;
		return $old;
	},
	100,
	2
);
add_filter(
	'pre_option_backupplus_dir',
	function () use ( $archives ) {
		return $archives;
	},
	100
);
add_filter(
	'pre_wp_mail',
	function () use ( &$mails ) {
		++$mails;
		return true;
	}
);
add_filter(
	'pre_http_request',
	function () {
		return new WP_Error( 'test_offline', 'External requests disabled in this test.' );
	},
	100
);

global $backupplus, $backupplus_admin;
require dirname( __DIR__ ) . '/backupplus.php';
backupplus_try_include_file( 'admin.php', 'include_once' );
$schedule = BackupPlus_Backup_Schedule::instance();
$cleanup  = BackupPlus_Retention_Cleanup::instance();

// Every setting is restored afterwards: saves and the reset check write real options.
$missing  = new stdClass();
$original = array();
foreach ( array_unique( array_merge( $backupplus->get_settings_keys(), BackupPlus_Backup_Schedule::OPTIONS, array( BackupPlus_Backup_Schedule::STATE, BackupPlus_Retention_Cleanup::STATUS, BackupPlus_Retention_Cleanup::FAILURES, 'backupplus_combine_jobs_around', 'backupplus_oneshotnonce', 'backupplus_last_backup', 'backupplus_lastmessage', 'backupplus_lastlogmessage', 'backupplus_last_scheduled_f', 'backupplus_restore_in_progress' ) ) ) as $name ) {
	$original[ $name ] = get_option( $name, $missing );
}
$remove_tree = function ( $path ) use ( &$remove_tree ) {
	if ( is_file( $path ) || is_link( $path ) ) {
		unlink( $path );
		return;
	}
	foreach ( scandir( $path ) as $entry ) {
		if ( '.' !== $entry && '..' !== $entry ) {
			$remove_tree( $path . '/' . $entry );
		}
	}
	rmdir( $path );
};
register_shutdown_function(
	function () use ( &$original, &$fake_jobs, $missing, $directory, $remove_tree ) {
		foreach ( $fake_jobs as $job ) {
			delete_site_option( 'backupplus_jobdata_' . $job );
		}
		foreach ( $original as $name => $value ) {
			remove_all_filters( 'sanitize_option_' . $name );
			delete_option( $name );
			if ( $value !== $missing ) {
				add_option( $name, $value, '', in_array( $name, array( 'timezone_string', 'gmt_offset' ), true ) ? 'yes' : 'no' );
			}
		}
		$remove_tree( $directory );
	}
);
add_action(
	'backupplus_backup_finished',
	function () use ( &$finished ) {
		++$finished;
	}
);
update_option( 'timezone_string', 'Europe/London' );
update_option( 'gmt_offset', '' );
foreach ( array( BackupPlus_Backup_Schedule::STATE, BackupPlus_Retention_Cleanup::STATUS, BackupPlus_Retention_Cleanup::FAILURES ) as $name ) {
	delete_option( $name );
}
$schedule->reconcile();

$events     = function ( $hook ) {
	$found = array();
	foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
		if ( ! empty( $hooks[ $hook ] ) ) {
			foreach ( $hooks[ $hook ] as $event ) {
				$found[] = array( $timestamp, $event );
			}
		}
	}
	return $found;
};
$only       = function ( $hook ) use ( $events ) {
	$found = $events( $hook );
	schedule_expect( 1 === count( $found ), "Exactly one $hook event must be scheduled; found " . count( $found ) );
	return $found[0][0];
};
$none       = function ( $hook ) use ( $events ) {
	schedule_expect( ! $events( $hook ), "No $hook event must be scheduled" );
};
$local      = function ( $timestamp, $format = 'H:i' ) {
	return wp_date( $format, $timestamp );
};
$save       = function ( $settings ) use ( $backupplus, $backupplus_admin ) {
	ob_start();
	$response = $backupplus_admin->save_settings( array_merge( array( 'backupplus_version' => $backupplus->version ), $settings ) );
	ob_end_clean();
	return $response;
};
$within_day = function ( $timestamp ) {
	return $timestamp > time() && $timestamp <= time() + DAY_IN_SECONDS + HOUR_IN_SECONDS;
};
$base       = array(
	'backupplus_interval'               => 'daily',
	'backupplus_interval_database'      => 'daily',
	'backupplus_interval_increments'    => 'none',
	'backupplus_schedule_exact_files'   => '1',
	'backupplus_starttime_files'        => '02:15',
	'backupplus_startday_files'         => '',
	'backupplus_schedule_exact_db'      => '1',
	'backupplus_starttime_db'           => '03:40',
	'backupplus_startday_db'            => '',
	'backupplus_retention_cleanup_mode' => 'daily',
	'backupplus_retention_cleanup_time' => '04:05',
	'backupplus_retain'                 => '2',
	'backupplus_retain_db'              => '2',
	'backupplus_service'                => array( 'none' ),
);

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

// Sub-daily anchor, weekday, fortnightly phase and monthly day.
$save(
	array_merge(
		$base,
		array(
			'backupplus_interval'        => 'every4hours',
			'backupplus_starttime_files' => '01:30',
		)
	)
);
$at = $only( 'backupplus_backup' );
schedule_expect( '30' === $local( $at, 'i' ) && 1 === (int) $local( $at, 'G' ) % 4 && $at - time() <= 4 * HOUR_IN_SECONDS, 'Sub-daily runs must be anchored to the chosen time' );
$save(
	array_merge(
		$base,
		array(
			'backupplus_interval'        => 'weekly',
			'backupplus_starttime_files' => '10:00',
			'backupplus_startday_files'  => '3',
		)
	)
);
$at = $only( 'backupplus_backup' );
schedule_expect( '3 10:00' === $local( $at, 'w H:i' ) && $at - time() <= WEEK_IN_SECONDS, 'Weekly runs must use the chosen weekday' );
$fortnightly = array_merge(
	$base,
	array(
		'backupplus_interval'        => 'fortnightly',
		'backupplus_starttime_files' => '08:00',
		'backupplus_startday_files'  => '1',
	)
);
$save( $fortnightly );
$first  = $only( 'backupplus_backup' );
$state  = get_option( BackupPlus_Backup_Schedule::STATE );
$anchor = $state['backupplus_backup']['anchor'];
$week   = function ( $timestamp ) {
	$day = DateTime::createFromFormat( '!Y-m-d', wp_date( 'Y-m-d', $timestamp ), new DateTimeZone( 'UTC' ) )->getTimestamp();
	return (int) floor( ( $day / DAY_IN_SECONDS + 3 ) / 7 );
};
schedule_expect( '1 08:00' === $local( $first, 'w H:i' ) && $anchor === $first, 'The first fortnightly run sets the phase' );
$save( array_merge( $fortnightly, array( 'backupplus_starttime_files' => '09:30' ) ) );
$moved = $only( 'backupplus_backup' );
schedule_expect( '1 09:30' === $local( $moved, 'w H:i' ) && 0 === ( $week( $moved ) - $week( $anchor ) ) % 2, 'A new fortnightly time keeps the phase' );
$save(
	array_merge(
		$fortnightly,
		array(
			'backupplus_starttime_files' => '09:30',
			'backupplus_startday_files'  => '4',
		)
	)
);
$moved = $only( 'backupplus_backup' );
schedule_expect( '4 09:30' === $local( $moved, 'w H:i' ) && 0 === ( $week( $moved ) - $week( $anchor ) ) % 2, 'A new fortnightly weekday keeps the phase' );
schedule_expect( $anchor === get_option( BackupPlus_Backup_Schedule::STATE )['backupplus_backup']['anchor'], 'The fortnightly anchor is retained' );
$save(
	array_merge(
		$base,
		array(
			'backupplus_interval'        => 'monthly',
			'backupplus_starttime_files' => '23:59',
			'backupplus_startday_files'  => '28',
		)
	)
);
$at = $only( 'backupplus_backup' );
schedule_expect( '28 23:59' === $local( $at, 'j H:i' ) && $at - time() <= 32 * DAY_IN_SECONDS, 'Monthly runs must use the chosen day' );
$save( $base );

// Incremental backups: a new interval schedule starts two minutes later; an exact time anchors each day's runs.
$none( 'backupplus_backup_increments' );
$save( array_merge( $base, array( 'backupplus_interval_increments' => 'every4hours' ) ) );
$at = $only( 'backupplus_backup_increments' );
schedule_expect( abs( $at - time() - 120 ) <= 5, 'A new interval-only incremental schedule must start two minutes later' );
$save(
	array_merge(
		$base,
		array(
			'backupplus_interval_increments' => 'every4hours',
			'backupplus_retain'              => '3',
		)
	)
);
schedule_expect( $at === $only( 'backupplus_backup_increments' ), 'Unrelated saves must keep the incremental event' );
// WordPress's "hourly" schedule was offered before; it becomes "everyhour" and keeps its queued run.
$save( $base );
$legacy = time() + 1800;
$test_cron[ $legacy ]['backupplus_backup_increments'][ md5( serialize( array() ) ) ] = array(
	'schedule' => 'hourly',
	'args'     => array(),
	'interval' => HOUR_IN_SECONDS,
);
ksort( $test_cron );
$save( array_merge( $base, array( 'backupplus_interval_increments' => 'hourly' ) ) );
schedule_expect( 'everyhour' === get_option( 'backupplus_interval_increments' ) && $legacy === $only( 'backupplus_backup_increments' ) && 'everyhour' === $events( 'backupplus_backup_increments' )[0][1]['schedule'], 'Hourly increments must become every hour at the same time' );
$increments = array_merge(
	$base,
	array(
		'backupplus_interval_increments'       => 'every4hours',
		'backupplus_schedule_exact_increments' => '1',
		'backupplus_starttime_increments'      => '01:30',
	)
);
$save( $increments );
$at = $only( 'backupplus_backup_increments' );
schedule_expect( '30' === $local( $at, 'i' ) && 1 === (int) $local( $at, 'G' ) % 4 && $at - time() <= 4 * HOUR_IN_SECONDS, 'Exact incremental runs must be anchored to the chosen time' );
schedule_expect( $files_at === $only( 'backupplus_backup' ) || '02:45' === $local( $only( 'backupplus_backup' ) ), 'Incremental timing must not move full backups' );
schedule_expect( 1 === (int) get_option( 'backupplus_schedule_exact_increments' ) && '01:30' === get_option( 'backupplus_starttime_increments' ), 'Incremental timing must persist' );
$response = $save( array_merge( $increments, array( 'backupplus_starttime_increments' => '' ) ) );
schedule_expect( empty( $response['saved'] ) && $at === $only( 'backupplus_backup_increments' ), 'An invalid incremental time must be rejected' );
$response = $save( array_merge( $increments, array( 'backupplus_interval_increments' => 'not-a-schedule' ) ) );
schedule_expect( empty( $response['saved'] ), 'An invalid incremental schedule must be rejected' );
$save(
	array_merge(
		$increments,
		array(
			'backupplus_interval_increments'  => 'daily',
			'backupplus_starttime_increments' => '22:10',
		)
	)
);
$at = $only( 'backupplus_backup_increments' );
schedule_expect( '22:10' === $local( $at ) && $within_day( $at ), 'Daily increments must run at the chosen time' );
$test_cron[ time() - HOUR_IN_SECONDS ]['backupplus_backup_increments'][ md5( serialize( array() ) ) ] = array(
	'schedule' => 'daily',
	'args'     => array(),
	'interval' => DAY_IN_SECONDS,
);
wp_unschedule_event( $at, 'backupplus_backup_increments' );
ksort( $test_cron );
$late = time() - HOUR_IN_SECONDS;
wp_reschedule_event( $late, 'daily', 'backupplus_backup_increments' );
wp_unschedule_event( $late, 'backupplus_backup_increments' );
schedule_expect( '22:10' === $local( $only( 'backupplus_backup_increments' ) ), 'A delayed incremental run must keep the chosen time' );
$save( array_merge( $increments, array( 'backupplus_interval_increments' => 'monthly' ) ) );
schedule_expect( 'daily' === get_option( 'backupplus_interval_increments' ) && 'daily' === $events( 'backupplus_backup_increments' )[0][1]['schedule'], 'Incremental schedules longer than a day run daily' );
$listed = wp_list_pluck( ( new ReflectionClass( 'BackupPlus_WPAdmin_Commands' ) )->newInstanceWithoutConstructor()->get_cron_events(), 'hook' );
schedule_expect( in_array( 'backupplus_backup_increments', $listed, true ), 'Advanced Tools must list incremental backups' );
$save( $base );
$none( 'backupplus_backup_increments' );
$files_at = $only( 'backupplus_backup' );

// Timezone changes move exact events to the same local time in the new zone.
update_option( 'timezone_string', 'America/New_York' );
$schedule->reconcile_if_pending();
schedule_expect( '02:45' === $local( $only( 'backupplus_backup' ) ) && '04:05' === $local( $only( 'backupplus_retention_cleanup' ) ), 'A named timezone change must reschedule' );
schedule_expect( 'America/New_York' === wp_timezone()->getName(), 'The site timezone must change' );
update_option( 'timezone_string', '' );
update_option( 'gmt_offset', 5.5 );
$schedule->reconcile_if_pending();
schedule_expect( '+05:30' === wp_timezone()->getName() && '02:45' === $local( $only( 'backupplus_backup' ) ), 'A UTC offset change must reschedule' );
update_option( 'timezone_string', 'Europe/London' );
update_option( 'gmt_offset', '' );
$schedule->reconcile_if_pending();

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

// Retention cleanup. Sets newest first: two kept, Always Keep, imported, an old incremental chain and an old set.
$sets          = array();
$files         = array();
$add           = function ( $age, $nonce, $extra = array(), $plugins = 1 ) use ( &$test_history, &$files, $archives ) {
	$time           = time() - $age * DAY_IN_SECONDS;
	$set            = array_merge(
		array(
			'nonce' => $nonce,
			'db'    => "backup_{$nonce}-db.gz",
		),
		$extra
	);
	$set['plugins'] = array();
	for ( $i = 0; $i < $plugins; $i++ ) {
		$set['plugins'][] = "backup_{$nonce}-plugins" . ( $i ? $i : '' ) . '.zip';
	}
	foreach ( array_merge( array( $set['db'] ), $set['plugins'] ) as $file ) {
		file_put_contents( $archives . '/' . $file, $nonce );
		$files[ $nonce ][] = $file;
	}
	file_put_contents( $archives . "/log.$nonce.txt", 'log' );
	$test_history[ $time ] = $set;
	krsort( $test_history );
	return $time;
};
$exists        = function ( $nonce ) use ( &$files, $archives ) {
	$present = array_filter(
		$files[ $nonce ],
		function ( $file ) use ( $archives ) {
			return file_exists( $archives . '/' . $file );
		}
	);
	return count( $present ) === count( $files[ $nonce ] ) ? true : ( $present ? 'partly' : false );
};
$reset_fixture = function () use ( &$test_history, &$files, $add ) {
	$test_history = array();
	$files        = array();
	$add( 1, 'aaaaaaaaaaaa' );
	$add( 2, 'bbbbbbbbbbbb', array( 'incremental' => array( 'complete' => true ) ), 3 );
	$add( 3, 'cccccccccccc', array( 'always_keep' => true ) );
	$add( 4, 'dddddddddddd', array( 'native' => false ) );
	$add( 5, 'eeeeeeeeeeee', array( 'incremental' => array( 'complete' => true ) ), 3 );
	$add( 6, 'ffffffffffff' );
};
$set_of        = function ( $nonce ) use ( &$test_history ) {
	foreach ( $test_history as $set ) {
		if ( $nonce === $set['nonce'] ) {
			return $set;
		}
	}
	return null;
};
$resume_events = function () use ( $events ) {
	return count( $events( 'backupplus_backup_resume' ) );
};
$reset_fixture();
$history_before = count( $test_history );

// Backups defer pruning to the cleanup in daily mode, and prune after each backup otherwise.
$backup_job = function () use ( $backupplus, &$fake_jobs ) {
	$backupplus->backup_time_nonce();
	$backupplus->jobdata_reset();
	$fake_jobs[] = $backupplus->nonce;
	$backupplus->jobdata_set_multi(
		array(
			'job_type'  => 'backup',
			'jobstatus' => 'clouduploading',
		)
	);
	backupplus_try_include_file( 'backup.php', 'include_once' );
	return new BackupPlus_Backup( 'no' );
};
$backup_job()->prune_retained_backups( array( 'none' => array( 'all' => array( null, null ) ) ) );
schedule_expect( 'finished' === $backupplus->jobdata_get( 'prune' ) && $exists( 'ffffffffffff' ) && $history_before === count( $test_history ), 'Daily mode must defer pruning from backups' );
$response = $save( array_merge( $base, array( 'backupplus_retention_cleanup_mode' => 'after_backup' ) ) );
schedule_expect( false !== strpos( $response['cleanup_scheduled'], 'After each backup' ), 'The display must show cleanup after each backup' );
$backup_job()->prune_retained_backups( array( 'none' => array( 'all' => array( null, null ) ) ) );
schedule_expect( ! $exists( 'ffffffffffff' ) && ! $exists( 'eeeeeeeeeeee' ) && 4 === count( $test_history ), 'After-backup mode must keep pruning at the end of backups' );
$save( $base );
$reset_fixture();

// The daily cleanup deletes without creating a backup, and keeps protected sets and complete chains.
$mails    = 0;
$finished = 0;
do_action( 'backupplus_retention_cleanup' );
$status = $cleanup->status();
schedule_expect( isset( $status['last'] ) && 'success' === $status['last']['outcome'] && 'idle' === $status['state'], 'Cleanup must complete: ' . json_encode( $status ) );
schedule_expect( 6 === $status['last']['deleted'], 'Cleanup must delete the old set and the whole old chain: ' . json_encode( $status ) );
foreach ( array( 'aaaaaaaaaaaa', 'bbbbbbbbbbbb', 'cccccccccccc', 'dddddddddddd' ) as $nonce ) {
	schedule_expect( true === $exists( $nonce ), "Backup set $nonce must be kept" );
}
foreach ( array( 'eeeeeeeeeeee', 'ffffffffffff' ) as $nonce ) {
	schedule_expect( false === $exists( $nonce ) && ! file_exists( $archives . "/log.$nonce.txt" ), "Backup set $nonce must be deleted with its log" );
}
schedule_expect( 4 === count( $test_history ) && 3 === count( $set_of( 'bbbbbbbbbbbb' )['plugins'] ) && ! $set_of( 'eeeeeeeeeeee' ), 'History must drop only the deleted sets and keep the kept chain intact' );
schedule_expect( 0 === $mails && 0 === $finished, 'Cleanup must not send backup reports or finish a backup' );
schedule_expect( 0 === $resume_events() && false === get_site_option( 'backupplus_jobdata_' . $status['last']['log'] ), 'Cleanup must end its job' );
schedule_expect( is_file( $backupplus->get_logfile_name( $status['last']['log'] ) ) && false !== strpos( file_get_contents( $backupplus->get_logfile_name( $status['last']['log'] ) ), 'Retention cleanup finished' ), 'Cleanup must keep a log' );
$summary = $cleanup->summary_html();
schedule_expect( false !== strpos( $summary, 'Last cleanup:' ) && false !== strpos( $summary, '6 files deleted' ) && false !== strpos( $summary, BackupPlus_Backup_Schedule::format_date( $only( 'backupplus_retention_cleanup' ) ) ), 'The display must show the next cleanup and the latest result' );
schedule_expect( false !== strpos( $backupplus_admin->next_scheduled_files_backups_output( true ), BackupPlus_Backup_Schedule::format_date( $only( 'backupplus_backup' ) ) ), 'The files display must show the next file backup' );
do_action( 'backupplus_retention_cleanup' );
schedule_expect( 0 === $cleanup->status()['last']['deleted'], 'A second cleanup has nothing to delete' );

// With local deletion turned off, local-only cleanup deletes nothing.
$reset_fixture();
update_option( 'backupplus_delete_local', 0 );
do_action( 'backupplus_retention_cleanup' );
schedule_expect( $exists( 'ffffffffffff' ) && 6 === count( $test_history ), 'The local deletion preference must be respected' );
update_option( 'backupplus_delete_local', 1 );

// A running backup delays the cleanup by five minutes.
$fake_backup = 'fakebackup' . substr( $token, 0, 2 );
$fake_jobs[] = $fake_backup;
$start_fake  = function () use ( $fake_backup, &$test_cron ) {
	update_site_option(
		'backupplus_jobdata_' . $fake_backup,
		array(
			'job_type'  => 'backup',
			'jobstatus' => 'filescreating',
		)
	);
	wp_schedule_single_event( time() + 600, 'backupplus_backup_resume', array( 1, $fake_backup ) );
};
$stop_fake   = function () use ( $fake_backup ) {
	delete_site_option( 'backupplus_jobdata_' . $fake_backup );
	wp_clear_scheduled_hook( 'backupplus_backup_resume', array( 1, $fake_backup ) );
};
$start_fake();
do_action( 'backupplus_retention_cleanup' );
$retry = $only( BackupPlus_Backup_Schedule::RETRY_HOOK );
schedule_expect( $retry >= time() + 290 && $retry <= time() + 300 && 'waiting' === $cleanup->status()['state'], 'Cleanup must wait five minutes for a running backup' );
schedule_expect( $exists( 'ffffffffffff' ), 'Nothing is deleted while a backup runs' );
schedule_expect( false !== strpos( $cleanup->summary_html(), 'Waiting for a running backup' ), 'The display must show that cleanup is waiting' );
do_action( BackupPlus_Backup_Schedule::RETRY_HOOK );
schedule_expect( 1 === count( $events( BackupPlus_Backup_Schedule::RETRY_HOOK ) ), 'A further retry is scheduled while the backup still runs' );
wp_clear_scheduled_hook( BackupPlus_Backup_Schedule::RETRY_HOOK );
$stop_fake();
do_action( BackupPlus_Backup_Schedule::RETRY_HOOK );
schedule_expect( ! $exists( 'ffffffffffff' ) && 6 === $cleanup->status()['last']['deleted'], 'The retry must complete the cleanup' );
$only( 'backupplus_retention_cleanup' );

// A backup starting mid-cleanup makes it stop between sets and resume later; history added meanwhile is kept.
$reset_fixture();
$last_check = new ReflectionProperty( 'BackupPlus_Retention_Cleanup', 'last_check' );
$added      = 0;
$interrupt  = function ( $prune ) use ( $start_fake, $cleanup, $last_check, &$added, &$test_history ) {
	if ( ! $added++ ) {
		// A backup saves its new set to the history while the cleanup is running.
		$test_history[ time() + 5 ] = array(
			'nonce' => 'newbackup000',
			'db'    => 'backup_newbackup000-db.gz',
		);
		$start_fake();
		$last_check->setValue( $cleanup, 0 );
	}
	return $prune;
};
add_filter( 'backupplus_prune_file', $interrupt );
do_action( 'backupplus_retention_cleanup' );
remove_filter( 'backupplus_prune_file', $interrupt );
$status = $cleanup->status();
schedule_expect( 'waiting' === $status['state'] && 1 === $status['carry']['deleted'] && $only( BackupPlus_Backup_Schedule::RETRY_HOOK ), 'Cleanup must pause for a backup that starts: ' . json_encode( $status ) );
schedule_expect( null !== $set_of( 'newbackup000' ), 'A set added during cleanup must be kept' );
schedule_expect( 'partly' === $exists( 'ffffffffffff' ) || 'partly' === $exists( 'eeeeeeeeeeee' ), 'Cleanup must stop part way' );
$stop_fake();
wp_clear_scheduled_hook( BackupPlus_Backup_Schedule::RETRY_HOOK );
do_action( BackupPlus_Backup_Schedule::RETRY_HOOK );
$status = $cleanup->status();
// The new backup counts towards the retention limit, so one more database is deleted.
schedule_expect( 7 === $status['last']['deleted'] && ! $exists( 'ffffffffffff' ) && ! $exists( 'eeeeeeeeeeee' ) && 'partly' === $exists( 'bbbbbbbbbbbb' ), 'The resumed cleanup must finish and count every deletion: ' . json_encode( $status ) );
schedule_expect( null !== $set_of( 'newbackup000' ) && 5 === count( $test_history ), 'The concurrent backup set must remain in history' );
foreach ( array_keys( $test_history ) as $time ) {
	if ( 'newbackup000' === $test_history[ $time ]['nonce'] ) {
		unset( $test_history[ $time ] );
	}
}

// An interrupted cleanup continues through its scheduled resumption.
$reset_fixture();
$calls = 0;
$crash = function ( $prune ) use ( &$calls ) {
	if ( 2 === ++$calls ) {
		throw new Schedule_Test_Interruption( 'interrupted' );
	}
	return $prune;
};
add_filter( 'backupplus_prune_file', $crash );
try {
	do_action( 'backupplus_retention_cleanup' );
	schedule_expect( false, 'The simulated interruption must stop the cleanup' );
} catch ( Schedule_Test_Interruption $e ) {
	restore_error_handler();
}
remove_filter( 'backupplus_prune_file', $crash );
$job = $cleanup->status()['job'];
schedule_expect( 'running' === $cleanup->status()['state'] && 1 === $resume_events(), 'An interrupted cleanup must have a pending resumption' );
do_action( 'backupplus_retention_cleanup' );
schedule_expect( $job === $cleanup->status()['job'] && 1 === $resume_events(), 'The daily event must not start a second cleanup while one can resume' );
$backupplus->jobdata_set_multi(
	array(
		'runs_started' => array( 0 => time() - 1000 ),
		'run_times'    => array( 0 => 5 ),
	)
);
// WordPress removes the resumption event before running it.
wp_clear_scheduled_hook( 'backupplus_backup_resume', array( 1, $job ) );
$backupplus->backup_resume( 1, $job );
$status = $cleanup->status();
schedule_expect( 'idle' === $status['state'] && 'success' === $status['last']['outcome'] && 6 === $status['last']['deleted'], 'The resumed cleanup must finish: ' . json_encode( $status ) );
schedule_expect( ! $exists( 'ffffffffffff' ) && ! $exists( 'eeeeeeeeeeee' ) && 4 === count( $test_history ) && 0 === $resume_events(), 'The resumed cleanup must delete the remaining sets' );

// Failed local deletions stay queued, are shown, and are retried.
$reset_fixture();
$stuck = $files['ffffffffffff'][0];
unlink( $archives . '/' . $stuck );
mkdir( $archives . '/' . $stuck );
do_action( 'backupplus_retention_cleanup' );
$status   = $cleanup->status();
$failures = $cleanup->failures();
schedule_expect( 'failed' === $status['last']['outcome'] && 1 === $status['last']['failed'] && 1 === $status['last']['pending'], 'A failed deletion must be reported: ' . json_encode( $status ) );
schedule_expect( 1 === count( $failures ) && 'local' === $failures[0]['service'] && array( $stuck ) === $failures[0]['files'], 'A failed deletion must be queued: ' . json_encode( $failures ) );
schedule_expect( 4 === count( $test_history ), 'The failed set leaves history but stays queued' );
schedule_expect( false !== strpos( $cleanup->summary_html(), 'Some deletions failed' ) && false !== strpos( $cleanup->summary_html(), '1 file will be retried' ), 'The display must show failed deletions' );
do_action( 'backupplus_retention_cleanup' );
schedule_expect( 1 === count( $cleanup->failures() ) && 1 === $cleanup->failures()[0]['attempts'] && 'failed' === $cleanup->status()['last']['outcome'], 'A deletion that fails again stays queued and is reported' );
rmdir( $archives . '/' . $stuck );
file_put_contents( $archives . '/' . $stuck, 'x' );
do_action( 'backupplus_retention_cleanup' );
schedule_expect( array() === $cleanup->failures() && ! file_exists( $archives . '/' . $stuck ) && 'success' === $cleanup->status()['last']['outcome'] && 1 === $cleanup->status()['last']['deleted'], 'A queued deletion must be retried' );

// Failed remote deletions are queued per destination and retried.
class Schedule_Test_Storage {
	public $fail  = true;
	public $calls = array();
	public function supports_feature( $feature ) {
		return false;
	}
	public function delete( $files, $passback = null, $sizes = array() ) {
		$this->calls[] = $files;
		return $this->fail ? new WP_Error( 'test', 'Remote deletion failed' ) : true;
	}
}
class Schedule_Test_Cleanup extends BackupPlus_Retention_Cleanup {
	public $storage;
	protected function prune_services() {
		return array( 'teststorage' => array( 'all' => array( $this->storage, null ) ) );
	}
	protected function storage( $service, $instance ) {
		return 'teststorage' === $service ? $this->storage : null;
	}
}
$instance        = new ReflectionProperty( 'BackupPlus_Retention_Cleanup', 'instance' );
$remote          = ( new ReflectionClass( 'Schedule_Test_Cleanup' ) )->newInstanceWithoutConstructor();
$remote->storage = new Schedule_Test_Storage();
$instance->setValue( null, $remote );
$reset_fixture();
foreach ( $files as $nonce => $list ) {
	foreach ( $list as $file ) {
		unlink( $archives . '/' . $file );
	}
}
$remote->run();
$failures = $remote->failures();
schedule_expect( 4 === count( $remote->storage->calls ) && 1 === count( $failures ) && 'teststorage' === $failures[0]['service'] && 6 === count( $failures[0]['files'] ), 'Failed remote deletions must be queued per destination: ' . json_encode( $failures ) );
schedule_expect( 4 === count( $test_history ) && 'failed' === $remote->status()['last']['outcome'], 'Failed remote deletions must be reported' );
$remote->storage->fail = false;
$remote->run();
schedule_expect( array() === $remote->failures() && 6 === $remote->status()['last']['deleted'] && 'success' === $remote->status()['last']['outcome'] && 5 === count( $remote->storage->calls ), 'Queued remote deletions must be retried in one batch' );
$instance->setValue( null, $cleanup );

$test_complete = true;
WP_CLI::success( 'Exact schedules, combining, saves, imports, reset, timezone changes, delayed cron and retention cleanup passed.' );
