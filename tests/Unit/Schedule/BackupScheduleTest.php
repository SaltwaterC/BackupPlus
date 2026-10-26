<?php
/** Exact schedule calculation and validation checks; no WordPress installation is required. */

namespace BackupPlus\Tests\Unit\Schedule;

use BackupPlus_Backup_Schedule;
use BackupPlus_Options;
use DateTime;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WP_Error;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class BackupScheduleTest extends TestCase {

	protected function setUp(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/backup-schedule-stubs.php';
		require_once BACKUPPLUS_DIR . '/includes/class-backup-schedule.php';
		BackupPlus_Options::$options = array();
	}

	private function utc( string $time ): int {
		return strtotime( $time . ' UTC' );
	}

	private function local( int $timestamp, string $zone ): string {
		$date = new DateTime( '@' . $timestamp );
		$date->setTimezone( new DateTimeZone( $zone ) );
		return $date->format( 'Y-m-d H:i T' );
	}

	private function next( string $interval, string $time, ?int $day, int $after, string $zone = 'UTC', ?int $anchor = null ): int {
		$definition = BackupPlus_Backup_Schedule::normalize( $interval, 1, $time, $day );
		$this->assertTrue( $definition['exact'], "$interval at $time must be an exact schedule" );
		return BackupPlus_Backup_Schedule::next_run( $definition, $after, new DateTimeZone( $zone ), $anchor );
	}

	private function series( string $interval, string $time, ?int $day, int $after, int $count, string $zone = 'UTC', ?int $anchor = null ): array {
		$runs = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$after  = $this->next( $interval, $time, $day, $after, $zone, $anchor );
			$runs[] = $after;
		}
		return $runs;
	}

	/** Settings that pass validation. */
	private function valid_settings(): array {
		return array(
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
	}

	public function test_daily_runs_are_strictly_after_the_reference_time(): void {
		$this->assertSame( $this->utc( '2026-01-01 02:30' ), $this->next( 'daily', '02:30', null, $this->utc( '2026-01-01 01:00' ) ), 'Daily run later today' );
		$this->assertSame( $this->utc( '2026-01-02 02:30' ), $this->next( 'daily', '02:30', null, $this->utc( '2026-01-01 02:30' ) ), 'A run at the reference time moves to the next day' );
		$this->assertSame( $this->utc( '2026-01-01 23:59' ), $this->next( 'daily', '23:59', null, $this->utc( '2026-01-01 23:58' ) ), 'Last minute of the day' );
		$this->assertSame( $this->utc( '2026-01-02 00:00' ), $this->next( 'daily', '0:00', null, $this->utc( '2026-01-01 00:00' ) ), 'Midnight with a one-digit hour' );
		$this->assertNotSame( $this->next( 'daily', '01:15', null, $this->utc( '2026-01-01 00:00' ) ), $this->next( 'daily', '03:45', null, $this->utc( '2026-01-01 00:00' ) ), 'Different daily times stay independent' );
	}

	public function test_a_delayed_run_schedules_the_next_occurrence_without_catching_up(): void {
		$this->assertSame( $this->utc( '2026-01-03 02:30' ), $this->next( 'daily', '02:30', null, $this->utc( '2026-01-02 19:00' ) ), 'A delayed run keeps the daily time' );
		$this->assertSame( $this->utc( '2026-01-07 09:00' ), $this->next( 'weekly', '09:00', 3, $this->utc( '2026-01-01 12:00' ) ), 'A delayed weekly run moves to the next chosen weekday' );
	}

	public function test_sub_daily_schedules_repeat_from_the_chosen_time_every_day(): void {
		$this->assertSame( array( $this->utc( '2026-01-01 09:30' ), $this->utc( '2026-01-01 13:30' ), $this->utc( '2026-01-01 17:30' ), $this->utc( '2026-01-01 21:30' ), $this->utc( '2026-01-02 01:30' ), $this->utc( '2026-01-02 05:30' ) ), $this->series( 'every4hours', '01:30', null, $this->utc( '2026-01-01 06:00' ), 6 ), 'Four-hourly runs anchored at 01:30' );
		$this->assertSame( array( $this->utc( '2026-01-01 19:00' ), $this->utc( '2026-01-02 07:00' ) ), $this->series( 'twicedaily', '07:00', null, $this->utc( '2026-01-01 08:00' ), 2 ), 'Twice-daily runs at 07:00 and 19:00' );
		$this->assertSame( array( $this->utc( '2026-01-01 10:45' ), $this->utc( '2026-01-01 11:45' ) ), $this->series( 'everyhour', '23:45', null, $this->utc( '2026-01-01 10:00' ), 2 ), 'Hourly runs keep the chosen minute' );
		$this->assertSame( array( $this->utc( '2026-01-01 22:30' ), $this->utc( '2026-01-02 00:00' ), $this->utc( '2026-01-02 01:30' ) ), $this->series( 'every90minutes', '00:00', null, $this->utc( '2026-01-01 22:00' ), 3 ), 'Custom intervals that divide a day are anchored daily' );
		$this->assertTrue( BackupPlus_Backup_Schedule::supports_exact( 'every90minutes' ), 'Only intervals that divide a day can be anchored' );
		$this->assertFalse( BackupPlus_Backup_Schedule::supports_exact( 'every7hours' ), 'Only intervals that divide a day can be anchored' );
		$this->assertFalse( BackupPlus_Backup_Schedule::normalize( 'every7hours', 1, '01:00', null )['exact'], 'An unsupported interval falls back to interval scheduling' );
	}

	public function test_weekly_runs_on_the_chosen_weekday(): void {
		$this->assertSame( $this->utc( '2026-01-07 10:00' ), $this->next( 'weekly', '10:00', 3, $this->utc( '2026-01-01 00:00' ) ), 'Weekly on Wednesday' );
		$this->assertSame( $this->utc( '2026-01-04 10:00' ), $this->next( 'weekly', '10:00', 0, $this->utc( '2026-01-01 00:00' ) ), 'Weekly on Sunday' );
		$this->assertSame( $this->utc( '2026-01-14 10:00' ), $this->next( 'weekly', '10:00', 3, $this->utc( '2026-01-07 10:00' ) ), 'Weekly repeats seven days later' );
	}

	public function test_fortnightly_phase_follows_the_anchor(): void {
		$anchor = $this->utc( '2026-01-05 08:00' );
		$this->assertSame( array( $this->utc( '2026-01-19 08:00' ), $this->utc( '2026-02-02 08:00' ), $this->utc( '2026-02-16 08:00' ) ), $this->series( 'fortnightly', '08:00', 1, $anchor, 3, 'UTC', $anchor ), 'Fortnightly runs every second Monday' );
		$this->assertSame( $this->utc( '2026-01-25 08:00' ), $this->next( 'fortnightly', '08:00', 0, $this->utc( '2026-01-12 00:00' ), 'UTC', $anchor ), 'A new weekday keeps the two-week phase' );
		$this->assertSame( $this->utc( '2026-01-23 20:00' ), $this->next( 'fortnightly', '20:00', 5, $this->utc( '2026-01-10 00:00' ), 'UTC', $anchor ), 'Off-phase weeks are skipped' );
		$this->assertSame( $this->utc( '2026-01-09 08:00' ), $this->next( 'fortnightly', '08:00', 5, $this->utc( '2026-01-06 00:00' ) ), 'Without an anchor the first matching day starts the phase' );
	}

	public function test_fortnightly_runs_keep_local_time_across_dst(): void {
		$runs = $this->series( 'fortnightly', '23:30', 2, $this->utc( '2026-03-01 00:00' ), 6, 'Europe/London', $this->utc( '2026-03-03 23:30' ) );
		foreach ( $runs as $index => $run ) {
			$this->assertSame( 'Tue 23:30', ( new DateTime( '@' . $run ) )->setTimezone( new DateTimeZone( 'Europe/London' ) )->format( 'D H:i' ), 'Fortnightly runs keep local time across DST' );
			if ( $index ) {
				$days = ( $run - $runs[ $index - 1 ] ) / DAY_IN_SECONDS;
				$this->assertTrue( abs( $days - 14 ) < 0.1, 'Fortnightly runs are two weeks apart' );
			}
		}
	}

	public function test_monthly_runs_cover_month_and_year_boundaries_and_days_one_to_twenty_eight(): void {
		$this->assertSame( $this->utc( '2026-02-28 04:00' ), $this->next( 'monthly', '04:00', 28, $this->utc( '2026-01-28 04:00' ) ), 'Day 28 occurs in February' );
		$this->assertSame( $this->utc( '2027-01-01 00:05' ), $this->next( 'monthly', '00:05', 1, $this->utc( '2026-12-15 00:00' ) ), 'Monthly rolls over to the next year' );
		$this->assertSame( $this->utc( '2026-03-15 12:00' ), $this->next( 'monthly', '12:00', 15, $this->utc( '2026-02-15 12:00' ) ), 'Monthly repeats on the same day' );
		$this->assertSame( $this->utc( '2028-02-28 04:00' ), $this->next( 'monthly', '04:00', 28, $this->utc( '2028-01-29 00:00' ) ), 'Leap years do not change day 28' );
		$this->assertFalse( BackupPlus_Backup_Schedule::normalize( 'monthly', 1, '04:00', 29 )['exact'], 'Monthly days outside 1-28 are not exact schedules' );
		$this->assertFalse( BackupPlus_Backup_Schedule::normalize( 'monthly', 1, '04:00', 0 )['exact'], 'Monthly days outside 1-28 are not exact schedules' );
		$this->assertFalse( BackupPlus_Backup_Schedule::normalize( 'weekly', 1, '04:00', 7 )['exact'], 'Weekdays outside 0-6 are not exact schedules' );
	}

	public function test_timezones_use_local_wall_clock_times(): void {
		$this->assertSame( $this->utc( '2026-01-01 18:30' ), $this->next( 'daily', '00:00', null, $this->utc( '2026-01-01 12:00' ), '+05:30' ), 'Fixed positive offset' );
		$this->assertSame( $this->utc( '2026-01-02 05:00' ), $this->next( 'daily', '00:00', null, $this->utc( '2026-01-01 12:00' ), 'America/New_York' ), 'Named timezone west of UTC' );
		$this->assertSame( $this->utc( '2026-01-01 22:00' ), $this->next( 'weekly', '09:00', 5, $this->utc( '2026-01-01 12:00' ), 'Australia/Sydney' ), 'A local weekday can fall on the previous UTC day' );
	}

	public function test_dst_gaps_run_at_the_first_valid_minute(): void {
		$this->assertSame( $this->utc( '2026-03-29 01:00' ), $this->next( 'daily', '01:30', null, $this->utc( '2026-03-28 12:00' ), 'Europe/London' ), 'A skipped time runs when the clocks go forward' );
		$this->assertSame( '2026-03-29 02:00 BST', $this->local( $this->next( 'daily', '01:00', null, $this->utc( '2026-03-28 12:00' ), 'Europe/London' ), 'Europe/London' ), 'The start of the gap runs at its end' );
		$this->assertSame( $this->utc( '2026-03-30 00:30' ), $this->next( 'daily', '01:30', null, $this->utc( '2026-03-29 01:00' ), 'Europe/London' ), 'The following day runs at the chosen time again' );
		$this->assertSame( array( $this->utc( '2026-03-29 00:30' ), $this->utc( '2026-03-29 01:00' ), $this->utc( '2026-03-29 01:30' ), $this->utc( '2026-03-29 02:30' ) ), $this->series( 'everyhour', '00:30', null, $this->utc( '2026-03-29 00:00' ), 4, 'Europe/London' ), 'Hourly runs in the gap move to its end' );
		$this->assertSame( $this->utc( '2026-03-08 07:00' ), $this->next( 'daily', '02:30', null, $this->utc( '2026-03-08 00:00' ), 'America/New_York' ), 'New York gap' );
	}

	public function test_dst_overlaps_run_once_at_the_first_occurrence(): void {
		$first = $this->next( 'daily', '01:30', null, $this->utc( '2026-10-24 12:00' ), 'Europe/London' );
		$this->assertSame( $this->utc( '2026-10-25 00:30' ), $first, 'A repeated time runs at its first occurrence' );
		$this->assertSame( $this->utc( '2026-10-26 01:30' ), $this->next( 'daily', '01:30', null, $first, 'Europe/London' ), 'A repeated time does not run twice' );
		$this->assertSame( array( $this->utc( '2026-10-25 00:30' ), $this->utc( '2026-10-25 02:30' ), $this->utc( '2026-10-25 03:30' ) ), $this->series( 'everyhour', '00:30', null, $this->utc( '2026-10-25 00:00' ), 3, 'Europe/London' ), 'Hourly runs skip the repeated hour' );
		$this->assertSame( array( $this->utc( '2026-10-24 22:00' ), $this->utc( '2026-10-25 23:00' ) ), $this->series( 'daily', '23:00', null, $this->utc( '2026-10-24 12:00' ), 2, 'Europe/London' ), 'Daily local time is kept when the offset changes' );
		$this->assertSame( $this->utc( '2026-11-01 05:30' ), $this->next( 'daily', '01:30', null, $this->utc( '2026-10-31 12:00' ), 'America/New_York' ), 'New York overlap' );
	}

	public function test_a_gap_at_midnight_runs_at_the_first_valid_minute(): void {
		$this->assertSame( '2026-03-08 01:00 CDT', $this->local( $this->next( 'daily', '00:00', null, $this->utc( '2026-03-07 12:00' ), 'America/Havana' ), 'America/Havana' ), 'A gap at midnight runs at the first valid minute' );
	}


	#[DataProvider( 'valid_times' )]
	public function test_valid_times_are_accepted( string $time ): void {
		$this->assertTrue( BackupPlus_Backup_Schedule::valid_time( $time ), "$time is valid" );
	}

	public static function valid_times(): array {
		return array(
			'midnight'    => array( '00:00' ),
			'one digit'   => array( '9:05' ),
			'last minute' => array( '23:59' ),
		);
	}

	#[DataProvider( 'invalid_times' )]
	public function test_invalid_times_are_rejected( $time ): void {
		$this->assertFalse( BackupPlus_Backup_Schedule::valid_time( $time ), var_export( $time, true ) . ' is invalid' );
	}

	public static function invalid_times(): array {
		return array(
			'hour 24'       => array( '24:00' ),
			'minute 60'     => array( '12:60' ),
			'one digit min' => array( '9:5' ),
			'empty'         => array( '' ),
			'seconds'       => array( '12:00:00' ),
			'trailing lf'   => array( "12:00\n" ),
			'leading space' => array( ' 12:00' ),
			'no separator'  => array( '1200' ),
			'null'          => array( null ),
			'array'         => array( array( '12:00' ) ),
		);
	}

	public function test_times_cleanup_modes_and_flags_are_sanitised(): void {
		$this->assertSame( '09:05', BackupPlus_Backup_Schedule::sanitize_time( '9:05' ), 'Times are normalised' );
		$this->assertSame( '', BackupPlus_Backup_Schedule::sanitize_time( '25:00' ), 'Times are normalised' );
		$this->assertSame( 'daily', BackupPlus_Backup_Schedule::sanitize_cleanup_mode( 'daily' ), 'Cleanup modes are restricted' );
		$this->assertSame( 'after_backup', BackupPlus_Backup_Schedule::sanitize_cleanup_mode( 'hourly' ), 'Cleanup modes are restricted' );
		$this->assertSame( 1, BackupPlus_Backup_Schedule::sanitize_flag( '1' ), 'Flags are 0 or 1' );
		$this->assertSame( 0, BackupPlus_Backup_Schedule::sanitize_flag( '0' ), 'Flags are 0 or 1' );
	}

	public function test_valid_settings_pass_validation(): void {
		$this->assertTrue( ( new BackupPlus_Backup_Schedule() )->validate( $this->valid_settings() ), 'Valid settings pass' );
	}

	#[DataProvider( 'invalid_settings' )]
	public function test_invalid_settings_are_rejected( string $name, $value ): void {
		$settings          = $this->valid_settings();
		$settings[ $name ] = $value;
		$this->assertInstanceOf( WP_Error::class, ( new BackupPlus_Backup_Schedule() )->validate( $settings ), 'Invalid ' . $name . ' is rejected' );
	}

	public static function invalid_settings(): array {
		return array(
			'file time out of range' => array( 'backupplus_starttime_files', '25:00' ),
			'file time empty'        => array( 'backupplus_starttime_files', '' ),
			'database day 7'         => array( 'backupplus_startday_db', '7' ),
			'database day fraction'  => array( 'backupplus_startday_db', '1.5' ),
			'database day array'     => array( 'backupplus_startday_db', array( '1' ) ),
			'unknown interval'       => array( 'backupplus_interval', 'every7days' ),
			'interval not dividing'  => array( 'backupplus_interval', 'every7hours' ),
			'flag not numeric'       => array( 'backupplus_schedule_exact_files', 'yes' ),
			'cleanup mode unknown'   => array( 'backupplus_retention_cleanup_mode', 'weekly' ),
			'cleanup time empty'     => array( 'backupplus_retention_cleanup_time', '' ),
		);
	}

	public function test_monthly_days_are_limited_to_one_to_twenty_eight(): void {
		$schedule                             = new BackupPlus_Backup_Schedule();
		$monthly                              = $this->valid_settings();
		$monthly['backupplus_interval']       = 'monthly';
		$monthly['backupplus_startday_files'] = '29';
		$this->assertInstanceOf( WP_Error::class, $schedule->validate( $monthly ), 'Monthly day 29 is rejected' );
		$monthly['backupplus_startday_files'] = '28';
		$this->assertTrue( $schedule->validate( $monthly ), 'Monthly day 28 is accepted' );
	}

	/** Incremental timing: hourly to daily intervals, time only. */
	private function increment_settings(): array {
		return array_merge(
			$this->valid_settings(),
			array(
				'backupplus_interval_increments'       => 'every4hours',
				'backupplus_schedule_exact_increments' => '1',
				'backupplus_starttime_increments'      => '01:30',
			)
		);
	}

	public function test_incremental_timing_is_validated(): void {
		$schedule   = new BackupPlus_Backup_Schedule();
		$increments = $this->increment_settings();
		$this->assertTrue( $schedule->validate( $increments ), 'Valid incremental timing passes' );
		$this->assertInstanceOf( WP_Error::class, $schedule->validate( array_merge( $increments, array( 'backupplus_starttime_increments' => '7pm' ) ) ), 'An invalid incremental time is rejected' );
		$this->assertInstanceOf( WP_Error::class, $schedule->validate( array_merge( $increments, array( 'backupplus_interval_increments' => 'every7days' ) ) ), 'An unknown incremental schedule is rejected' );
		$this->assertTrue(
			$schedule->validate(
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
		$this->assertInstanceOf( WP_Error::class, $schedule->validate( array_merge( $increments, array( 'backupplus_interval_increments' => 'every7hours' ) ) ), 'Incremental intervals that do not divide a day cannot be exact' );
	}

	public function test_incremental_intervals_run_from_hourly_to_daily(): void {
		$this->assertSame( 'daily', BackupPlus_Backup_Schedule::increments_interval( 'monthly' ), 'Incremental intervals run from hourly to daily' );
		$this->assertSame( 'every90minutes', BackupPlus_Backup_Schedule::increments_interval( 'every90minutes' ), 'Incremental intervals run from hourly to daily' );
		$this->assertNull( BackupPlus_Backup_Schedule::increments_interval( 'none' ), 'Incremental intervals run from hourly to daily' );
		$this->assertSame( 'everyhour', BackupPlus_Backup_Schedule::increments_interval( 'everyhour' ), 'Incremental intervals run from hourly to daily' );
		$this->assertSame( 'everyhour', BackupPlus_Backup_Schedule::increments_interval( 'hourly' ), 'WordPress hourly increments run as every hour' );
	}

	public function test_incremental_exact_timing_has_no_day_and_runs_twelve_hours_apart(): void {
		$schedule                    = new BackupPlus_Backup_Schedule();
		BackupPlus_Options::$options = array(
			'backupplus_interval_increments'       => 'twicedaily',
			'backupplus_schedule_exact_increments' => 1,
			'backupplus_starttime_increments'      => '06:15',
		);
		$definition                  = $schedule->definition( 'increments' );
		$this->assertTrue( $definition['exact'], 'Incremental exact timing has no day' );
		$this->assertSame( 'twicedaily', $definition['interval'], 'Incremental exact timing has no day' );
		$this->assertNull( $definition['day'], 'Incremental exact timing has no day' );
		$this->assertSame( array( $this->utc( '2026-01-01 18:15' ), $this->utc( '2026-01-02 06:15' ) ), $this->series( 'twicedaily', '06:15', null, $this->utc( '2026-01-01 07:00' ), 2 ), 'Twice-daily increments run at the chosen time and twelve hours later' );
	}

	public function test_saved_incremental_times_alone_do_not_enable_exact_scheduling(): void {
		BackupPlus_Options::$options = array(
			'backupplus_interval_increments'       => 'twicedaily',
			'backupplus_schedule_exact_increments' => 0,
			'backupplus_starttime_increments'      => '06:15',
		);
		$this->assertFalse( ( new BackupPlus_Backup_Schedule() )->definition( 'increments' )['exact'], 'Saved incremental times alone do not enable exact scheduling' );
	}

	public function test_increments_are_disabled_by_default(): void {
		$this->assertNull( ( new BackupPlus_Backup_Schedule() )->definition( 'increments' ), 'Increments are disabled by default' );
	}

	public function test_time_controls_follow_the_interval(): void {
		// Shorter schedules choose minutes past the hour, or run hours and a minute; others take a time of day.
		$controls = array();
		foreach ( array( 'every15minutes', 'everyhour', 'every2hours', 'every4hours', 'twicedaily', 'every90minutes', 'daily', 'weekly', 'monthly' ) as $interval ) {
			$control               = BackupPlus_Backup_Schedule::time_control( $interval );
			$controls[ $interval ] = $control['mode'] . ' ' . $control['step'];
		}
		$this->assertSame(
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
			),
			$controls,
			'Time controls follow the interval: ' . json_encode( $controls )
		);
	}

	public function test_shorter_schedules_store_their_first_run_of_the_day(): void {
		$anchors = array();
		foreach ( array( array( 'everyhour', '23:45' ), array( 'every4hours', '13:30' ), array( 'twicedaily', '18:15' ), array( 'every90minutes', '17:12' ), array( 'every15minutes', '10:50' ), array( 'daily', '23:45' ) ) as $case ) {
			$anchors[] = BackupPlus_Backup_Schedule::normalize( $case[0], 1, $case[1], null )['time'];
		}
		$this->assertSame( array( '00:45', '01:30', '06:15', '00:42', '00:05', '23:45' ), $anchors, 'Shorter schedules store their first run of the day: ' . json_encode( $anchors ) );
	}

	public function test_times_naming_the_same_runs_are_the_same_schedule(): void {
		BackupPlus_Options::$options = array(
			'backupplus_interval'             => 'every4hours',
			'backupplus_interval_database'    => 'every4hours',
			'backupplus_schedule_exact_files' => 1,
			'backupplus_schedule_exact_db'    => 1,
			'backupplus_starttime_files'      => '13:30',
			'backupplus_starttime_db'         => '01:30',
		);
		$this->assertTrue( ( new BackupPlus_Backup_Schedule() )->same_schedule(), 'Times naming the same runs are the same schedule' );
	}

	public function test_legacy_timing_fields_are_ignored_unless_exact_mode_is_chosen(): void {
		// Hidden legacy times alone do not enable exact scheduling, and are not validated.
		$legacy = array(
			'backupplus_interval'          => 'daily',
			'backupplus_interval_database' => 'daily',
			'backupplus_starttime_files'   => 'not a time',
			'backupplus_startday_files'    => '99',
		);
		$this->assertTrue( ( new BackupPlus_Backup_Schedule() )->validate( $legacy ), 'Legacy timing fields are ignored unless exact mode is chosen' );
	}

	public function test_saved_times_without_the_exact_setting_keep_interval_scheduling(): void {
		$schedule                    = new BackupPlus_Backup_Schedule();
		BackupPlus_Options::$options = array(
			'backupplus_interval'          => 'daily',
			'backupplus_interval_database' => 'daily',
			'backupplus_starttime_files'   => '05:00',
			'backupplus_starttime_db'      => '06:00',
		);
		$this->assertFalse( $schedule->definition( 'files' )['exact'], 'Saved times without the exact setting keep interval scheduling' );
		$this->assertFalse( $schedule->any_exact(), 'Saved times without the exact setting keep interval scheduling' );
		$this->assertTrue( $schedule->same_schedule(), 'Legacy matching intervals still combine' );
		$this->assertNull( $schedule->definition( 'cleanup' ), 'Cleanup defaults to after each backup' );
	}

	public function test_combining_compares_complete_schedules(): void {
		$schedule                    = new BackupPlus_Backup_Schedule();
		BackupPlus_Options::$options = array(
			'backupplus_interval'             => 'daily',
			'backupplus_interval_database'    => 'daily',
			'backupplus_starttime_files'      => '05:00',
			'backupplus_starttime_db'         => '06:00',
			'backupplus_schedule_exact_files' => 1,
			'backupplus_schedule_exact_db'    => 1,
		);
		BackupPlus_Options::$options['backupplus_starttime_db'] = '05:00';
		$this->assertTrue( $schedule->same_schedule(), 'Identical exact schedules combine' );
		$this->assertTrue( $schedule->any_exact(), 'Identical exact schedules combine' );
		BackupPlus_Options::$options['backupplus_starttime_db'] = '05:01';
		$this->assertFalse( $schedule->same_schedule(), 'Exact schedules one minute apart stay independent' );
		BackupPlus_Options::$options['backupplus_schedule_exact_db'] = 0;
		$this->assertFalse( $schedule->same_schedule(), 'An exact and an interval schedule stay independent' );
		BackupPlus_Options::$options['backupplus_interval_database'] = 'manual';
		$this->assertFalse( $schedule->same_schedule(), 'Manual database backups are not scheduled' );
		$this->assertNull( $schedule->definition( 'db' ), 'Manual database backups are not scheduled' );
	}

	public function test_an_invalid_saved_monthly_day_falls_back_to_interval_scheduling(): void {
		BackupPlus_Options::$options = array(
			'backupplus_interval'             => 'monthly',
			'backupplus_interval_database'    => 'daily',
			'backupplus_starttime_files'      => '05:00',
			'backupplus_schedule_exact_files' => 1,
			'backupplus_startday_files'       => '31',
		);
		$this->assertFalse( ( new BackupPlus_Backup_Schedule() )->definition( 'files' )['exact'], 'An invalid saved monthly day falls back to interval scheduling' );
	}
}
