<?php
/** Sub-daily, weekly, fortnightly and monthly recurrence, incremental backup schedules and timezone changes. Required by ../check-backup-schedule.php, in its scope. */

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
