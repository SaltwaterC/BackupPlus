<?php
/** Exact schedule calculation and validation checks; no WordPress installation is required. */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
function add_action() {}
function add_filter() {}
function __( $text ) {
	return $text;
}
function wp_timezone() {
	return new DateTimeZone( 'Europe/London' );
}
function wp_get_schedules() {
	$schedules = array();
	foreach ( array(
		'every15minutes' => 900,
		'everyhour'      => 3600,
		'every2hours'    => 7200,
		'every4hours'    => 14400,
		'every8hours'    => 28800,
		'every90minutes' => 5400,
		'every7hours'    => 25200,
		'twicedaily'     => 43200,
		'daily'          => 86400,
		'weekly'         => 604800,
		'fortnightly'    => 1209600,
		'monthly'        => 2592000,
	) as $name => $interval ) {
		$schedules[ $name ] = array( 'interval' => $interval );
	}
	return $schedules;
}
class WP_Error {
	public $message;
	public function __construct( $code, $message ) {
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
}
class BackupPlus_Options {
	public static $options = array();
	public static function get_backupplus_option( $name, $default = null ) {
		return array_key_exists( $name, self::$options ) ? self::$options[ $name ] : $default;
	}
}
require dirname( __DIR__ ) . '/includes/class-backup-schedule.php';

function schedule_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function schedule_utc( $time ) {
	return strtotime( $time . ' UTC' );
}
function schedule_local( $timestamp, $zone ) {
	$date = new DateTime( '@' . $timestamp );
	$date->setTimezone( new DateTimeZone( $zone ) );
	return $date->format( 'Y-m-d H:i T' );
}
function schedule_next( $interval, $time, $day, $after, $zone = 'UTC', $anchor = null ) {
	$definition = BackupPlus_Backup_Schedule::normalize( $interval, 1, $time, $day );
	schedule_assert( $definition['exact'], "$interval at $time must be an exact schedule" );
	return BackupPlus_Backup_Schedule::next_run( $definition, $after, new DateTimeZone( $zone ), $anchor );
}
function schedule_series( $interval, $time, $day, $after, $count, $zone = 'UTC', $anchor = null ) {
	$runs = array();
	for ( $i = 0; $i < $count; $i++ ) {
		$after  = schedule_next( $interval, $time, $day, $after, $zone, $anchor );
		$runs[] = $after;
	}
	return $runs;
}
$utc = new DateTimeZone( 'UTC' );

// Daily: strictly after the reference time, and independent times for two schedules.
schedule_assert( schedule_utc( '2026-01-01 02:30' ) === schedule_next( 'daily', '02:30', null, schedule_utc( '2026-01-01 01:00' ) ), 'Daily run later today' );
schedule_assert( schedule_utc( '2026-01-02 02:30' ) === schedule_next( 'daily', '02:30', null, schedule_utc( '2026-01-01 02:30' ) ), 'A run at the reference time moves to the next day' );
schedule_assert( schedule_utc( '2026-01-01 23:59' ) === schedule_next( 'daily', '23:59', null, schedule_utc( '2026-01-01 23:58' ) ), 'Last minute of the day' );
schedule_assert( schedule_utc( '2026-01-02 00:00' ) === schedule_next( 'daily', '0:00', null, schedule_utc( '2026-01-01 00:00' ) ), 'Midnight with a one-digit hour' );
schedule_assert( schedule_next( 'daily', '01:15', null, schedule_utc( '2026-01-01 00:00' ) ) !== schedule_next( 'daily', '03:45', null, schedule_utc( '2026-01-01 00:00' ) ), 'Different daily times stay independent' );

// Delayed cron: a late run schedules the next calendar occurrence, without catching up missed runs.
schedule_assert( schedule_utc( '2026-01-03 02:30' ) === schedule_next( 'daily', '02:30', null, schedule_utc( '2026-01-02 19:00' ) ), 'A delayed run keeps the daily time' );
schedule_assert( schedule_utc( '2026-01-07 09:00' ) === schedule_next( 'weekly', '09:00', 3, schedule_utc( '2026-01-01 12:00' ) ), 'A delayed weekly run moves to the next chosen weekday' );

// Sub-daily schedules repeat from the chosen time on every day.
schedule_assert( array( schedule_utc( '2026-01-01 09:30' ), schedule_utc( '2026-01-01 13:30' ), schedule_utc( '2026-01-01 17:30' ), schedule_utc( '2026-01-01 21:30' ), schedule_utc( '2026-01-02 01:30' ), schedule_utc( '2026-01-02 05:30' ) ) === schedule_series( 'every4hours', '01:30', null, schedule_utc( '2026-01-01 06:00' ), 6 ), 'Four-hourly runs anchored at 01:30' );
schedule_assert( array( schedule_utc( '2026-01-01 19:00' ), schedule_utc( '2026-01-02 07:00' ) ) === schedule_series( 'twicedaily', '07:00', null, schedule_utc( '2026-01-01 08:00' ), 2 ), 'Twice-daily runs at 07:00 and 19:00' );
schedule_assert( array( schedule_utc( '2026-01-01 10:45' ), schedule_utc( '2026-01-01 11:45' ) ) === schedule_series( 'everyhour', '23:45', null, schedule_utc( '2026-01-01 10:00' ), 2 ), 'Hourly runs keep the chosen minute' );
schedule_assert( array( schedule_utc( '2026-01-01 22:30' ), schedule_utc( '2026-01-02 00:00' ), schedule_utc( '2026-01-02 01:30' ) ) === schedule_series( 'every90minutes', '00:00', null, schedule_utc( '2026-01-01 22:00' ), 3 ), 'Custom intervals that divide a day are anchored daily' );
schedule_assert( BackupPlus_Backup_Schedule::supports_exact( 'every90minutes' ) && ! BackupPlus_Backup_Schedule::supports_exact( 'every7hours' ), 'Only intervals that divide a day can be anchored' );
schedule_assert( ! BackupPlus_Backup_Schedule::normalize( 'every7hours', 1, '01:00', null )['exact'], 'An unsupported interval falls back to interval scheduling' );

// Weekly: chosen weekday (0 = Sunday).
schedule_assert( schedule_utc( '2026-01-07 10:00' ) === schedule_next( 'weekly', '10:00', 3, schedule_utc( '2026-01-01 00:00' ) ), 'Weekly on Wednesday' );
schedule_assert( schedule_utc( '2026-01-04 10:00' ) === schedule_next( 'weekly', '10:00', 0, schedule_utc( '2026-01-01 00:00' ) ), 'Weekly on Sunday' );
schedule_assert( schedule_utc( '2026-01-14 10:00' ) === schedule_next( 'weekly', '10:00', 3, schedule_utc( '2026-01-07 10:00' ) ), 'Weekly repeats seven days later' );

// Fortnightly: the phase follows the anchor, including when the weekday changes.
$anchor = schedule_utc( '2026-01-05 08:00' );
schedule_assert( array( schedule_utc( '2026-01-19 08:00' ), schedule_utc( '2026-02-02 08:00' ), schedule_utc( '2026-02-16 08:00' ) ) === schedule_series( 'fortnightly', '08:00', 1, $anchor, 3, 'UTC', $anchor ), 'Fortnightly runs every second Monday' );
schedule_assert( schedule_utc( '2026-01-25 08:00' ) === schedule_next( 'fortnightly', '08:00', 0, schedule_utc( '2026-01-12 00:00' ), 'UTC', $anchor ), 'A new weekday keeps the two-week phase' );
schedule_assert( schedule_utc( '2026-01-23 20:00' ) === schedule_next( 'fortnightly', '20:00', 5, schedule_utc( '2026-01-10 00:00' ), 'UTC', $anchor ), 'Off-phase weeks are skipped' );
schedule_assert( schedule_utc( '2026-01-09 08:00' ) === schedule_next( 'fortnightly', '08:00', 5, schedule_utc( '2026-01-06 00:00' ) ), 'Without an anchor the first matching day starts the phase' );
$runs = schedule_series( 'fortnightly', '23:30', 2, schedule_utc( '2026-03-01 00:00' ), 6, 'Europe/London', schedule_utc( '2026-03-03 23:30' ) );
foreach ( $runs as $index => $run ) {
	schedule_assert( 'Tue 23:30' === ( new DateTime( '@' . $run ) )->setTimezone( new DateTimeZone( 'Europe/London' ) )->format( 'D H:i' ), 'Fortnightly runs keep local time across DST' );
	if ( $index ) {
		$days = ( $run - $runs[ $index - 1 ] ) / DAY_IN_SECONDS;
		schedule_assert( abs( $days - 14 ) < 0.1, 'Fortnightly runs are two weeks apart' );
	}
}

// Monthly: days 1-28 only, with month and year boundaries.
schedule_assert( schedule_utc( '2026-02-28 04:00' ) === schedule_next( 'monthly', '04:00', 28, schedule_utc( '2026-01-28 04:00' ) ), 'Day 28 occurs in February' );
schedule_assert( schedule_utc( '2027-01-01 00:05' ) === schedule_next( 'monthly', '00:05', 1, schedule_utc( '2026-12-15 00:00' ) ), 'Monthly rolls over to the next year' );
schedule_assert( schedule_utc( '2026-03-15 12:00' ) === schedule_next( 'monthly', '12:00', 15, schedule_utc( '2026-02-15 12:00' ) ), 'Monthly repeats on the same day' );
schedule_assert( schedule_utc( '2028-02-28 04:00' ) === schedule_next( 'monthly', '04:00', 28, schedule_utc( '2028-01-29 00:00' ) ), 'Leap years do not change day 28' );
schedule_assert( ! BackupPlus_Backup_Schedule::normalize( 'monthly', 1, '04:00', 29 )['exact'] && ! BackupPlus_Backup_Schedule::normalize( 'monthly', 1, '04:00', 0 )['exact'], 'Monthly days outside 1-28 are not exact schedules' );
schedule_assert( ! BackupPlus_Backup_Schedule::normalize( 'weekly', 1, '04:00', 7 )['exact'], 'Weekdays outside 0-6 are not exact schedules' );

// Timezones: local wall-clock times, including fixed offsets.
schedule_assert( schedule_utc( '2026-01-01 18:30' ) === schedule_next( 'daily', '00:00', null, schedule_utc( '2026-01-01 12:00' ), '+05:30' ), 'Fixed positive offset' );
schedule_assert( schedule_utc( '2026-01-02 05:00' ) === schedule_next( 'daily', '00:00', null, schedule_utc( '2026-01-01 12:00' ), 'America/New_York' ), 'Named timezone west of UTC' );
schedule_assert( schedule_utc( '2026-01-01 22:00' ) === schedule_next( 'weekly', '09:00', 5, schedule_utc( '2026-01-01 12:00' ), 'Australia/Sydney' ), 'A local weekday can fall on the previous UTC day' );

// DST gap (Europe/London 2026-03-29 01:00 -> 02:00): run at the first valid minute.
schedule_assert( schedule_utc( '2026-03-29 01:00' ) === schedule_next( 'daily', '01:30', null, schedule_utc( '2026-03-28 12:00' ), 'Europe/London' ), 'A skipped time runs when the clocks go forward' );
schedule_assert( '2026-03-29 02:00 BST' === schedule_local( schedule_next( 'daily', '01:00', null, schedule_utc( '2026-03-28 12:00' ), 'Europe/London' ), 'Europe/London' ), 'The start of the gap runs at its end' );
schedule_assert( schedule_utc( '2026-03-30 00:30' ) === schedule_next( 'daily', '01:30', null, schedule_utc( '2026-03-29 01:00' ), 'Europe/London' ), 'The following day runs at the chosen time again' );
schedule_assert( array( schedule_utc( '2026-03-29 00:30' ), schedule_utc( '2026-03-29 01:00' ), schedule_utc( '2026-03-29 01:30' ), schedule_utc( '2026-03-29 02:30' ) ) === schedule_series( 'everyhour', '00:30', null, schedule_utc( '2026-03-29 00:00' ), 4, 'Europe/London' ), 'Hourly runs in the gap move to its end' );
schedule_assert( schedule_utc( '2026-03-08 07:00' ) === schedule_next( 'daily', '02:30', null, schedule_utc( '2026-03-08 00:00' ), 'America/New_York' ), 'New York gap' );

// DST overlap (Europe/London 2026-10-25 02:00 BST -> 01:00 GMT): a repeated time runs once, at its first occurrence.
$first = schedule_next( 'daily', '01:30', null, schedule_utc( '2026-10-24 12:00' ), 'Europe/London' );
schedule_assert( schedule_utc( '2026-10-25 00:30' ) === $first, 'A repeated time runs at its first occurrence' );
schedule_assert( schedule_utc( '2026-10-26 01:30' ) === schedule_next( 'daily', '01:30', null, $first, 'Europe/London' ), 'A repeated time does not run twice' );
schedule_assert( array( schedule_utc( '2026-10-25 00:30' ), schedule_utc( '2026-10-25 02:30' ), schedule_utc( '2026-10-25 03:30' ) ) === schedule_series( 'everyhour', '00:30', null, schedule_utc( '2026-10-25 00:00' ), 3, 'Europe/London' ), 'Hourly runs skip the repeated hour' );
schedule_assert( array( schedule_utc( '2026-10-24 22:00' ), schedule_utc( '2026-10-25 23:00' ) ) === schedule_series( 'daily', '23:00', null, schedule_utc( '2026-10-24 12:00' ), 2, 'Europe/London' ), 'Daily local time is kept when the offset changes' );
schedule_assert( schedule_utc( '2026-11-01 05:30' ) === schedule_next( 'daily', '01:30', null, schedule_utc( '2026-10-31 12:00' ), 'America/New_York' ), 'New York overlap' );

// A timezone with DST at midnight (America/Havana 2026-03-08 00:00 -> 01:00).
schedule_assert( '2026-03-08 01:00 CDT' === schedule_local( schedule_next( 'daily', '00:00', null, schedule_utc( '2026-03-07 12:00' ), 'America/Havana' ), 'America/Havana' ), 'A gap at midnight runs at the first valid minute' );

// Input validation and sanitising.
foreach ( array( '00:00', '9:05', '23:59' ) as $time ) {
	schedule_assert( BackupPlus_Backup_Schedule::valid_time( $time ), "$time is valid" );
}
foreach ( array( '24:00', '12:60', '9:5', '', '12:00:00', "12:00\n", ' 12:00', '1200', null, array( '12:00' ) ) as $time ) {
	schedule_assert( ! BackupPlus_Backup_Schedule::valid_time( $time ), var_export( $time, true ) . ' is invalid' );
}
schedule_assert( '09:05' === BackupPlus_Backup_Schedule::sanitize_time( '9:05' ) && '' === BackupPlus_Backup_Schedule::sanitize_time( '25:00' ), 'Times are normalised' );
schedule_assert( 'daily' === BackupPlus_Backup_Schedule::sanitize_cleanup_mode( 'daily' ) && 'after_backup' === BackupPlus_Backup_Schedule::sanitize_cleanup_mode( 'hourly' ), 'Cleanup modes are restricted' );
schedule_assert( 1 === BackupPlus_Backup_Schedule::sanitize_flag( '1' ) && 0 === BackupPlus_Backup_Schedule::sanitize_flag( '0' ), 'Flags are 0 or 1' );

$schedule = new BackupPlus_Backup_Schedule();
$valid    = array(
	'backupplus_interval'               => 'daily',
	'backupplus_interval_database'      => 'weekly',
	'backupplus_schedule_exact_files'   => '1',
	'backupplus_starttime_files'        => '02:00',
	'backupplus_schedule_exact_db'      => '1',
	'backupplus_starttime_db'           => '03:00',
	'backupplus_startday_db'            => '6',
	'backupplus_retention_cleanup_mode' => 'daily',
	'backupplus_retention_cleanup_time' => '04:00',
);
schedule_assert( true === $schedule->validate( $valid ), 'Valid settings pass' );
foreach ( array(
	array( 'backupplus_starttime_files', '25:00' ),
	array( 'backupplus_starttime_files', '' ),
	array( 'backupplus_startday_db', '7' ),
	array( 'backupplus_startday_db', '1.5' ),
	array( 'backupplus_startday_db', array( '1' ) ),
	array( 'backupplus_interval', 'every7days' ),
	array( 'backupplus_interval', 'every7hours' ),
	array( 'backupplus_schedule_exact_files', 'yes' ),
	array( 'backupplus_retention_cleanup_mode', 'weekly' ),
	array( 'backupplus_retention_cleanup_time', '' ),
) as $invalid ) {
	$settings                = $valid;
	$settings[ $invalid[0] ] = $invalid[1];
	schedule_assert( $schedule->validate( $settings ) instanceof WP_Error, 'Invalid ' . $invalid[0] . ' is rejected' );
}
$monthly                              = $valid;
$monthly['backupplus_interval']       = 'monthly';
$monthly['backupplus_startday_files'] = '29';
schedule_assert( $schedule->validate( $monthly ) instanceof WP_Error, 'Monthly day 29 is rejected' );
$monthly['backupplus_startday_files'] = '28';
schedule_assert( true === $schedule->validate( $monthly ), 'Monthly day 28 is accepted' );

// Incremental timing: hourly to daily intervals, time only.
$increments = array_merge(
	$valid,
	array(
		'backupplus_interval_increments'       => 'every4hours',
		'backupplus_schedule_exact_increments' => '1',
		'backupplus_starttime_increments'      => '01:30',
	)
);
schedule_assert( true === $schedule->validate( $increments ), 'Valid incremental timing passes' );
schedule_assert( $schedule->validate( array_merge( $increments, array( 'backupplus_starttime_increments' => '7pm' ) ) ) instanceof WP_Error, 'An invalid incremental time is rejected' );
schedule_assert( $schedule->validate( array_merge( $increments, array( 'backupplus_interval_increments' => 'every7days' ) ) ) instanceof WP_Error, 'An unknown incremental schedule is rejected' );
schedule_assert(
	true === $schedule->validate(
		array_merge(
			$increments,
			array(
				'backupplus_interval_increments'  => 'none',
				'backupplus_starttime_increments' => '',
			)
		)
	),
	'Disabled increments need no time'
);
schedule_assert( $schedule->validate( array_merge( $increments, array( 'backupplus_interval_increments' => 'every7hours' ) ) ) instanceof WP_Error, 'Incremental intervals that do not divide a day cannot be exact' );
schedule_assert( 'daily' === BackupPlus_Backup_Schedule::increments_interval( 'monthly' ) && 'every90minutes' === BackupPlus_Backup_Schedule::increments_interval( 'every90minutes' ) && null === BackupPlus_Backup_Schedule::increments_interval( 'none' ) && 'everyhour' === BackupPlus_Backup_Schedule::increments_interval( 'everyhour' ), 'Incremental intervals run from hourly to daily' );
schedule_assert( 'everyhour' === BackupPlus_Backup_Schedule::increments_interval( 'hourly' ), 'WordPress hourly increments run as every hour' );
BackupPlus_Options::$options = array(
	'backupplus_interval_increments'       => 'twicedaily',
	'backupplus_schedule_exact_increments' => 1,
	'backupplus_starttime_increments'      => '06:15',
);
$definition                  = $schedule->definition( 'increments' );
schedule_assert( $definition['exact'] && 'twicedaily' === $definition['interval'] && null === $definition['day'], 'Incremental exact timing has no day' );
schedule_assert( array( schedule_utc( '2026-01-01 18:15' ), schedule_utc( '2026-01-02 06:15' ) ) === schedule_series( 'twicedaily', '06:15', null, schedule_utc( '2026-01-01 07:00' ), 2 ), 'Twice-daily increments run at the chosen time and twelve hours later' );
BackupPlus_Options::$options['backupplus_schedule_exact_increments'] = 0;
schedule_assert( ! $schedule->definition( 'increments' )['exact'], 'Saved incremental times alone do not enable exact scheduling' );
BackupPlus_Options::$options = array();
schedule_assert( null === $schedule->definition( 'increments' ), 'Increments are disabled by default' );

// Shorter schedules choose minutes past the hour, or run hours and a minute; others take a time of day.
$controls = array();
foreach ( array( 'every15minutes', 'everyhour', 'every2hours', 'every4hours', 'twicedaily', 'every90minutes', 'daily', 'weekly', 'monthly' ) as $interval ) {
	$control               = BackupPlus_Backup_Schedule::time_control( $interval );
	$controls[ $interval ] = $control['mode'] . ' ' . $control['step'];
}
schedule_assert(
	array(
		'every15minutes' => 'minute 15',
		'everyhour'      => 'minute 60',
		'every2hours'    => 'hours 120',
		'every4hours'    => 'hours 240',
		'twicedaily'     => 'hours 720',
		'every90minutes' => 'time 90',
		'daily'          => 'time 1440',
		'weekly'         => 'time 1440',
		'monthly'        => 'time 1440',
	) === $controls,
	'Time controls follow the interval: ' . json_encode( $controls )
);
$anchors = array();
foreach ( array( array( 'everyhour', '23:45' ), array( 'every4hours', '13:30' ), array( 'twicedaily', '18:15' ), array( 'every90minutes', '17:12' ), array( 'every15minutes', '10:50' ), array( 'daily', '23:45' ) ) as $case ) {
	$anchors[] = BackupPlus_Backup_Schedule::normalize( $case[0], 1, $case[1], null )['time'];
}
schedule_assert( array( '00:45', '01:30', '06:15', '00:42', '00:05', '23:45' ) === $anchors, 'Shorter schedules store their first run of the day: ' . json_encode( $anchors ) );
BackupPlus_Options::$options = array(
	'backupplus_interval'             => 'every4hours',
	'backupplus_interval_database'    => 'every4hours',
	'backupplus_schedule_exact_files' => 1,
	'backupplus_schedule_exact_db'    => 1,
	'backupplus_starttime_files'      => '13:30',
	'backupplus_starttime_db'         => '01:30',
);
schedule_assert( $schedule->same_schedule(), 'Times naming the same runs are the same schedule' );
BackupPlus_Options::$options = array();

// Hidden legacy times alone do not enable exact scheduling, and are not validated.
$legacy = array(
	'backupplus_interval'          => 'daily',
	'backupplus_interval_database' => 'daily',
	'backupplus_starttime_files'   => 'not a time',
	'backupplus_startday_files'    => '99',
);
schedule_assert( true === $schedule->validate( $legacy ), 'Legacy timing fields are ignored unless exact mode is chosen' );
BackupPlus_Options::$options = array(
	'backupplus_interval'          => 'daily',
	'backupplus_interval_database' => 'daily',
	'backupplus_starttime_files'   => '05:00',
	'backupplus_starttime_db'      => '06:00',
);
schedule_assert( ! $schedule->definition( 'files' )['exact'] && ! $schedule->any_exact(), 'Saved times without the exact setting keep interval scheduling' );
schedule_assert( $schedule->same_schedule(), 'Legacy matching intervals still combine' );
schedule_assert( null === $schedule->definition( 'cleanup' ), 'Cleanup defaults to after each backup' );

// Combining compares complete schedules.
BackupPlus_Options::$options = array_merge(
	BackupPlus_Options::$options,
	array(
		'backupplus_schedule_exact_files' => 1,
		'backupplus_schedule_exact_db'    => 1,
		'backupplus_starttime_db'         => '05:00',
	)
);
schedule_assert( $schedule->same_schedule() && $schedule->any_exact(), 'Identical exact schedules combine' );
BackupPlus_Options::$options['backupplus_starttime_db'] = '05:01';
schedule_assert( ! $schedule->same_schedule(), 'Exact schedules one minute apart stay independent' );
BackupPlus_Options::$options['backupplus_schedule_exact_db'] = 0;
schedule_assert( ! $schedule->same_schedule(), 'An exact and an interval schedule stay independent' );
BackupPlus_Options::$options['backupplus_interval_database'] = 'manual';
schedule_assert( ! $schedule->same_schedule() && null === $schedule->definition( 'db' ), 'Manual database backups are not scheduled' );
BackupPlus_Options::$options['backupplus_interval_database'] = 'daily';
BackupPlus_Options::$options['backupplus_interval']          = 'monthly';
BackupPlus_Options::$options['backupplus_startday_files']    = '31';
schedule_assert( ! $schedule->definition( 'files' )['exact'], 'An invalid saved monthly day falls back to interval scheduling' );

echo "Backup schedule calculations passed.\n";
