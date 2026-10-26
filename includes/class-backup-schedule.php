<?php
/** Recurring backup and retention cleanup times, calculated in the site's timezone. */
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

class BackupPlus_Backup_Schedule {
	const STATE      = 'backupplus_schedule_state';
	const RETRY_HOOK = 'backupplus_retention_cleanup_retry';
	const HOOKS      = array(
		'files'      => 'backupplus_backup',
		'db'         => 'backupplus_backup_database',
		'increments' => 'backupplus_backup_increments',
		'cleanup'    => 'backupplus_retention_cleanup',
	);
	// Changing any of these options, including the site timezone, can move a scheduled event.
	const OPTIONS = array( 'backupplus_interval', 'backupplus_interval_database', 'backupplus_interval_increments', 'backupplus_schedule_exact_files', 'backupplus_schedule_exact_db', 'backupplus_schedule_exact_increments', 'backupplus_starttime_files', 'backupplus_starttime_db', 'backupplus_starttime_increments', 'backupplus_startday_files', 'backupplus_startday_db', 'backupplus_retention_cleanup_mode', 'backupplus_retention_cleanup_time', 'timezone_string', 'gmt_offset' );

	private static $instance;
	private $pending = false;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_filter( 'backupplus_override_scheduled_backup_event', array( $this, 'override_event' ) );
		add_action( 'admin_init', array( $this, 'validate_options_submission' ), 1 );
		foreach ( self::OPTIONS as $option ) {
			add_action( 'add_option_' . $option, array( $this, 'defer' ) );
			add_action( 'update_option_' . $option, array( $this, 'defer' ) );
			add_action( 'delete_option_' . $option, array( $this, 'defer' ) );
		}
	}

	/** Reconcile once, after every option in the current request has been written. */
	public function defer() {
		if ( ! $this->pending ) {
			$this->pending = true;
			add_action( 'shutdown', array( $this, 'reconcile_if_pending' ) );
		}
	}

	public function reconcile_if_pending() {
		if ( $this->pending ) {
			$this->reconcile();
		}
	}

	private static function get( $option, $default = null ) {
		return BackupPlus_Options::get_backupplus_option( $option, $default );
	}

	public static function timezone() {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}
		$name = get_option( 'timezone_string' );
		if ( $name ) {
			return new DateTimeZone( $name );
		}
		$offset  = (float) get_option( 'gmt_offset' );
		$minutes = (int) round( abs( $offset ) * 60 );
		return new DateTimeZone( sprintf( '%s%02d:%02d', $offset < 0 ? '-' : '+', intdiv( $minutes, 60 ), $minutes % 60 ) );
	}

	public static function seconds( $interval ) {
		$schedules = function_exists( 'wp_get_schedules' ) ? wp_get_schedules() : array();
		if ( isset( $schedules[ $interval ]['interval'] ) ) {
			return (int) $schedules[ $interval ]['interval'];
		}
		$known = array(
			'everyminute'    => 60,
			'every5minutes'  => 300,
			'every10minutes' => 600,
			'every15minutes' => 900,
			'every30minutes' => 1800,
			'everyhour'      => 3600,
			'hourly'         => 3600,
			'every2hours'    => 7200,
			'every3hours'    => 10800,
			'every4hours'    => 14400,
			'every6hours'    => 21600,
			'every8hours'    => 28800,
			'twicedaily'     => 43200,
			'daily'          => 86400,
			'weekly'         => 604800,
			'fortnightly'    => 1209600,
			'monthly'        => 2592000,
		);
		return isset( $known[ $interval ] ) ? $known[ $interval ] : 0;
	}

	/** Calendar schedules, daily, and sub-daily intervals that repeat identically every day. */
	public static function supports_exact( $interval ) {
		if ( in_array( $interval, array( 'daily', 'weekly', 'fortnightly', 'monthly' ), true ) ) {
			return true;
		}
		$seconds = self::seconds( $interval );
		// A one-minute schedule already runs in every minute slot, so an exact-time offset has no effect.
		return $seconds > 60 && $seconds < DAY_IN_SECONDS && 0 === $seconds % 60 && 0 === DAY_IN_SECONDS % $seconds;
	}

	public static function valid_time( $time ) {
		return is_string( $time ) && preg_match( '/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/D', $time );
	}

	/** The day setting applies only to weekly, fortnightly (weekday 0-6) and monthly (1-28) schedules. */
	public static function day_range( $interval ) {
		if ( 'monthly' === $interval ) {
			return array( 1, 28 );
		}
		return in_array( $interval, array( 'weekly', 'fortnightly' ), true ) ? array( 0, 6 ) : null;
	}

	public static function sanitize_flag( $value ) {
		return empty( $value ) ? 0 : 1;
	}

	public static function sanitize_cleanup_mode( $value ) {
		return 'daily' === $value ? 'daily' : 'after_backup';
	}

	public static function sanitize_time( $value ) {
		return self::valid_time( $value ) ? sprintf( '%05s', $value ) : '';
	}

	/**
	 * How an exact time is chosen. Daily and longer schedules take a full time. Shorter ones repeat
	 * every $step minutes from the first run of the day: intervals dividing an hour take only the
	 * minutes past the hour, and intervals of whole hours take which hours they run at and the minute.
	 *
	 * @param String $interval - a schedule supporting exact times
	 * @return Array - 'mode' (time, minute or hours) and the interval 'step' in minutes
	 */
	public static function time_control( $interval ) {
		$step = intdiv( self::seconds( $interval ), 60 );
		if ( self::day_range( $interval ) || $step <= 0 || $step >= 1440 ) {
			return array(
				'mode' => 'time',
				'step' => 1440,
			);
		}
		if ( 0 === 60 % $step ) {
			$mode = 'minute';
		} else {
			$mode = 0 === $step % 60 ? 'hours' : 'time';
		}
		return array(
			'mode' => $mode,
			'step' => $step,
		);
	}

	/**
	 * The incremental schedule to run: null when disabled; intervals outside one minute to one day run daily.
	 * WordPress's own "hourly" schedule duplicates "everyhour", which the settings offer instead.
	 *
	 * @param String $interval - the saved or submitted incremental interval
	 * @return String|null
	 */
	public static function increments_interval( $interval ) {
		if ( empty( $interval ) || 'none' === $interval ) {
			return null;
		}
		if ( 'hourly' === $interval ) {
			return 'everyhour';
		}
		if ( ! is_string( $interval ) ) {
			return 'daily';
		}
		$seconds = self::seconds( $interval );
		return ( $seconds < 60 || $seconds > DAY_IN_SECONDS ) ? 'daily' : $interval;
	}

	/**
	 * The effective schedule for files, db, increments or cleanup; null when nothing should be scheduled.
	 * Saved exact timing that is no longer valid falls back to an interval-only schedule.
	 */
	public function definition( $which ) {
		if ( 'increments' === $which ) {
			$interval = self::increments_interval( self::get( 'backupplus_interval_increments', 'none' ) );
			return $interval ? self::normalize( $interval, self::get( 'backupplus_schedule_exact_increments', 0 ), self::get( 'backupplus_starttime_increments', '' ), null ) : null;
		}
		if ( 'cleanup' === $which ) {
			$time = self::get( 'backupplus_retention_cleanup_time', '' );
			if ( 'daily' !== self::get( 'backupplus_retention_cleanup_mode', 'after_backup' ) || ! self::valid_time( $time ) ) {
				return null;
			}
			return self::normalize( 'daily', true, $time, null );
		}
		$files    = self::get( 'backupplus_interval', 'manual' );
		$interval = 'files' === $which ? $files : self::get( 'backupplus_interval_database', $files );
		if ( empty( $interval ) || 'manual' === $interval ) {
			return null;
		}
		if ( function_exists( 'wp_get_schedules' ) && ! array_key_exists( $interval, wp_get_schedules() ) ) {
			$interval = 'daily';
		}
		return self::normalize( $interval, self::get( 'backupplus_schedule_exact_' . $which, 0 ), self::get( 'backupplus_starttime_' . $which, '' ), self::get( 'backupplus_startday_' . $which, null ) );
	}

	public static function normalize( $interval, $exact, $time, $day ) {
		$range = self::day_range( $interval );
		$exact = ! empty( $exact ) && self::supports_exact( $interval ) && self::valid_time( $time ) && ( ! $range || ( is_numeric( $day ) && $day >= $range[0] && $day <= $range[1] ) );
		if ( $exact ) {
			// Shorter schedules keep the first run of the day, so 13:30 and 01:30 every four hours are the same schedule.
			list( $hour, $minute ) = array_map( 'intval', explode( ':', $time ) );
			$start                 = ( $hour * 60 + $minute ) % self::time_control( $interval )['step'];
			$time                  = sprintf( '%02d:%02d', intdiv( $start, 60 ), $start % 60 );
		}
		return array(
			'interval' => $interval,
			'exact'    => $exact,
			'time'     => $exact ? sprintf( '%05s', $time ) : null,
			'day'      => $exact && $range ? (int) $day : null,
		);
	}

	/** Exact schedules depend on the timezone, so a timezone change reschedules them. */
	private function signature( $definition ) {
		$parts = $definition['exact'] ? array( $definition['interval'], $definition['time'], $definition['day'], self::timezone()->getName() ) : array( $definition['interval'] );
		return json_encode( $parts );
	}

	/** Whether files and database run as one job: identical schedules, including any exact timing. */
	public function same_schedule() {
		if ( 'xyz' === self::get( 'backupplus_interval_database', 'xyz' ) ) {
			return true;
		}
		$files = $this->definition( 'files' );
		$db    = $this->definition( 'db' );
		return null !== $files && null !== $db && $this->signature( $files ) === $this->signature( $db );
	}

	public function any_exact() {
		foreach ( array( 'files', 'db' ) as $which ) {
			$definition = $this->definition( $which );
			if ( $definition && $definition['exact'] ) {
				return true;
			}
		}
		return false;
	}

	/** Midnight UTC of the local calendar date containing $timestamp, used as a plain date. */
	private static function local_day( $timestamp, DateTimeZone $timezone ) {
		$local = new DateTime( '@' . $timestamp );
		$local->setTimezone( $timezone );
		return gmmktime( 0, 0, 0, (int) $local->format( 'n' ), (int) $local->format( 'j' ), (int) $local->format( 'Y' ) );
	}

	/** Weeks start on Monday; 1 January 1970 was a Thursday. */
	private static function week( $day ) {
		return (int) floor( ( $day / DAY_IN_SECONDS + 3 ) / 7 );
	}

	/**
	 * Convert a local wall-clock time to a UTC timestamp. Times repeated when clocks go back
	 * resolve to their first occurrence; times skipped when clocks go forward resolve to the
	 * first valid minute after the gap.
	 */
	public static function local_time( $date, $minutes, DateTimeZone $timezone ) {
		$wall        = strtotime( $date . ' 00:00:00 UTC' ) + $minutes * 60;
		$wanted      = gmdate( 'Y-m-d H:i', $wall );
		$transitions = $timezone->getTransitions( $wall - 2 * DAY_IN_SECONDS, $wall + 2 * DAY_IN_SECONDS );
		$transitions = is_array( $transitions ) ? $transitions : array();
		$offsets     = array( $timezone->getOffset( new DateTime( '@' . $wall ) ) );
		foreach ( $transitions as $transition ) {
			$offsets[] = $transition['offset'];
		}
		$found = false;
		foreach ( array_unique( $offsets ) as $offset ) {
			$candidate = $wall - $offset;
			$local     = new DateTime( '@' . $candidate );
			$local->setTimezone( $timezone );
			if ( $local->format( 'Y-m-d H:i' ) === $wanted && ( false === $found || $candidate < $found ) ) {
				$found = $candidate;
			}
		}
		if ( false !== $found ) {
			return $found;
		}
		$previous = null;
		foreach ( $transitions as $transition ) {
			if ( null !== $previous && $transition['ts'] + $previous <= $wall && $transition['ts'] + $transition['offset'] > $wall ) {
				return $transition['ts'];
			}
			$previous = $transition['offset'];
		}
		return $wall - $offsets[0];
	}

	/**
	 * The first run strictly after $after. Sub-daily schedules repeat from the chosen time on
	 * every local day. Fortnightly schedules run in weeks an even number of weeks from $anchor.
	 */
	public static function next_run( $definition, $after, DateTimeZone $timezone, $anchor = null ) {
		list( $hour, $minute ) = array_map( 'intval', explode( ':', $definition['time'] ) );
		$start                 = $hour * 60 + $minute;
		$seconds               = self::seconds( $definition['interval'] );
		$minutes               = array( $start );
		if ( $seconds < DAY_IN_SECONDS && ! self::day_range( $definition['interval'] ) ) {
			$step    = intdiv( $seconds, 60 );
			$minutes = range( $start % $step, 1439, $step );
		}
		$anchor_week = $anchor ? self::week( self::local_day( $anchor, $timezone ) ) : null;
		// Start a day early: the first matching local day may begin before $after in UTC.
		$day = self::local_day( $after, $timezone ) - DAY_IN_SECONDS;
		for ( $i = 0; $i < 70; $i++, $day += DAY_IN_SECONDS ) {
			if ( 'monthly' === $definition['interval'] && (int) gmdate( 'j', $day ) !== $definition['day'] ) {
				continue;
			}
			if ( in_array( $definition['interval'], array( 'weekly', 'fortnightly' ), true ) && (int) gmdate( 'w', $day ) !== $definition['day'] ) {
				continue;
			}
			if ( 'fortnightly' === $definition['interval'] && null !== $anchor_week && 0 !== abs( self::week( $day ) - $anchor_week ) % 2 ) {
				continue;
			}
			foreach ( $minutes as $minute_of_day ) {
				$timestamp = self::local_time( gmdate( 'Y-m-d', $day ), $minute_of_day, $timezone );
				if ( $timestamp > $after ) {
					return $timestamp;
				}
			}
		}
		return false;
	}

	/** Recurring WordPress cron runs: place each recurrence at its next calendar time. */
	public function override_event( $event ) {
		$which = is_object( $event ) ? array_search( $event->hook, self::HOOKS, true ) : false;
		if ( false === $which ) {
			return $event;
		}
		$definition = $this->definition( $which );
		if ( ! $definition || ! $definition['exact'] || $event->schedule !== $definition['interval'] ) {
			return $event;
		}
		$state = get_option( self::STATE, array() );
		$next  = self::next_run( $definition, time(), self::timezone(), isset( $state[ $event->hook ]['anchor'] ) ? $state[ $event->hook ]['anchor'] : null );
		if ( $next ) {
			$event->timestamp = $next;
		}
		return $event;
	}

	private function events( $hook ) {
		$events = array();
		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			if ( isset( $hooks[ $hook ] ) && is_array( $hooks[ $hook ] ) ) {
				foreach ( $hooks[ $hook ] as $event ) {
					$events[] = array(
						'timestamp' => (int) $timestamp,
						'schedule'  => isset( $event['schedule'] ) ? $event['schedule'] : false,
					);
				}
			}
		}
		return $events;
	}

	/**
	 * Ensure exactly one event for each enabled schedule. An event whose schedule is unchanged
	 * is kept as queued, even when overdue; anything else is replaced. Installations without
	 * recorded state keep their interval-only events.
	 */
	public function reconcile() {
		global $backupplus;
		$this->pending = false;
		$state         = get_option( self::STATE, array() );
		$state         = is_array( $state ) ? $state : array();
		foreach ( self::HOOKS as $which => $hook ) {
			$definition = $this->definition( $which );
			$events     = $this->events( $hook );
			if ( null === $definition ) {
				if ( $events ) {
					wp_clear_scheduled_hook( $hook );
				}
				unset( $state[ $hook ] );
				continue;
			}
			$signature = $this->signature( $definition );
			$known     = isset( $state[ $hook ] ) ? $state[ $hook ] : array();
			$unchanged = isset( $known['signature'] ) ? $known['signature'] === $signature : ! $definition['exact'];
			if ( 1 === count( $events ) && $events[0]['schedule'] === $definition['interval'] && $unchanged ) {
				$state[ $hook ] = array_merge(
					$known,
					array(
						'signature' => $signature,
						'interval'  => $definition['interval'],
					)
				);
				continue;
			}
			// Keep the queued time when the interval length is unchanged, e.g. from "hourly" to "everyhour".
			$previous = ( $events && $events[0]['schedule'] && self::seconds( $events[0]['schedule'] ) === self::seconds( $definition['interval'] ) ) ? $events[0]['timestamp'] : 0;
			$anchor   = null;
			if ( $definition['exact'] && 'fortnightly' === $definition['interval'] ) {
				// Keep the established two-week phase when only the weekday or time changes.
				if ( isset( $known['interval'], $known['anchor'] ) && 'fortnightly' === $known['interval'] ) {
					$anchor = $known['anchor'];
				} elseif ( $previous ) {
					$anchor = $previous;
				} else {
					$anchor = self::next_run( $definition, time(), self::timezone() );
				}
			}
			$state[ $hook ] = array(
				'signature' => $signature,
				'interval'  => $definition['interval'],
				'anchor'    => $anchor,
			);
			// The event filter reads the anchor while the event is being scheduled.
			update_option( self::STATE, $state, false );
			wp_clear_scheduled_hook( $hook );
			if ( $definition['exact'] ) {
				$first = self::next_run( $definition, time(), self::timezone(), $anchor );
			} else {
				if ( $previous ) {
					$first = $previous;
				} else {
					// Incremental schedules have always started two minutes after being chosen.
					$first = 'increments' === $which ? time() + 120 : $backupplus->random_schedule_time();
				}
				$first = apply_filters( 'backupplus_schedule_firsttime_' . $which, $first );
			}
			wp_schedule_event( $first, $definition['interval'], $hook );
		}
		if ( null === $this->definition( 'cleanup' ) ) {
			wp_clear_scheduled_hook( self::RETRY_HOOK );
		}
		update_option( self::STATE, $state, false );
	}

	/** Remove all scheduled backup and cleanup events and their recorded state. */
	public function reset() {
		foreach ( self::HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		wp_clear_scheduled_hook( self::RETRY_HOOK );
		delete_option( self::STATE );
		$this->pending = false;
	}

	/**
	 * Check the scheduling fields of a settings submission against the values they would replace.
	 *
	 * @param Array $settings - posted settings; absent keys keep their saved values
	 * @return true|WP_Error
	 */
	public function validate( $settings ) {
		$value    = function ( $key, $default = null ) use ( $settings ) {
			return array_key_exists( $key, $settings ) ? $settings[ $key ] : self::get( $key, $default );
		};
		$errors   = array();
		$files    = $value( 'backupplus_interval', 'manual' );
		$schedule = array(
			'files'      => array( $value( 'backupplus_interval', 'manual' ), 'manual', __( 'file backups', 'backupplus' ) ),
			'db'         => array( $value( 'backupplus_interval_database', $files ), 'manual', __( 'database backups', 'backupplus' ) ),
			'increments' => array( $value( 'backupplus_interval_increments', 'none' ), 'none', __( 'incremental file backups', 'backupplus' ) ),
		);
		foreach ( $schedule as $which => $details ) {
			list( $interval, $disabled, $label ) = $details;
			$exact                               = $value( 'backupplus_schedule_exact_' . $which, 0 );
			if ( ! is_string( $interval ) || ( $disabled !== $interval && ! array_key_exists( $interval, wp_get_schedules() ) ) ) {
				/* translators: %s: "file backups", "database backups" or "incremental file backups" */
				$errors[] = sprintf( __( 'Choose a valid schedule for %s.', 'backupplus' ), $label );
				continue;
			}
			if ( ! in_array( $exact, array( '', '0', '1', 0, 1, false, true ), true ) ) {
				/* translators: %s: "file backups", "database backups" or "incremental file backups" */
				$errors[] = sprintf( __( 'The exact time setting for %s is invalid.', 'backupplus' ), $label );
				continue;
			}
			if ( empty( $exact ) || $disabled === $interval ) {
				continue;
			}
			if ( 'increments' === $which ) {
				$interval = self::increments_interval( $interval );
			}
			if ( ! self::supports_exact( $interval ) ) {
				/* translators: %s: "file backups", "database backups" or "incremental file backups" */
				$errors[] = sprintf( __( 'The selected schedule for %s cannot run at an exact time.', 'backupplus' ), $label );
				continue;
			}
			if ( ! self::valid_time( $value( 'backupplus_starttime_' . $which, '' ) ) ) {
				/* translators: %s: "file backups", "database backups" or "incremental file backups" */
				$errors[] = sprintf( __( 'Enter a time for %s in 24-hour HH:MM format.', 'backupplus' ), $label );
			}
			$range = self::day_range( $interval );
			$day   = $value( 'backupplus_startday_' . $which, '' );
			if ( $range && ( ! is_scalar( $day ) || ! preg_match( '/^\d{1,2}$/D', (string) $day ) || $day < $range[0] || $day > $range[1] ) ) {
				$errors[] = 'monthly' === $interval
					/* translators: %s: "file backups", "database backups" or "incremental file backups" */
					? sprintf( __( 'Choose a day of the month from 1 to 28 for %s.', 'backupplus' ), $label )
					/* translators: %s: "file backups", "database backups" or "incremental file backups" */
					: sprintf( __( 'Choose a day of the week for %s.', 'backupplus' ), $label );
			}
		}
		$mode = $value( 'backupplus_retention_cleanup_mode', 'after_backup' );
		if ( ! in_array( $mode, array( 'after_backup', 'daily' ), true ) ) {
			$errors[] = __( 'Choose when old backups are deleted.', 'backupplus' );
		} elseif ( 'daily' === $mode && ! self::valid_time( $value( 'backupplus_retention_cleanup_time', '' ) ) ) {
			$errors[] = __( 'Enter a daily cleanup time in 24-hour HH:MM format.', 'backupplus' );
		}
		return $errors ? new WP_Error( 'backupplus_invalid_schedule', implode( ' ', $errors ) ) : true;
	}

	/** Reject an invalid non-JavaScript settings submission before options.php writes any option. */
	public function validate_options_submission() {
		global $pagenow;
		if ( 'options.php' !== $pagenow || empty( $_POST['option_page'] ) || 'backupplus-options-group' !== $_POST['option_page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only check; options.php verifies the nonce before saving.
			return;
		}
		$result = $this->validate( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- See above.
		if ( is_wp_error( $result ) ) {
			wp_die(
				esc_html( $result->get_error_message() ),
				'',
				array(
					'response'  => 400,
					'back_link' => true,
				)
			);
		}
	}

	public static function format_date( $timestamp ) {
		return function_exists( 'wp_date' ) ? wp_date( 'D, F j, Y H:i', $timestamp ) : get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ), 'D, F j, Y H:i' );
	}

	public static function describe_next( $hook ) {
		$next = wp_next_scheduled( $hook );
		return $next ? self::format_date( $next ) : '';
	}

	/**
	 * Exact-time controls for the file, database or incremental schedule. The hidden start time is
	 * submitted; the script fills it from the time field, or from the minute and run-time selects
	 * shown for schedules shorter than a day.
	 */
	public function timing_controls( $which ) {
		global $backupplus;
		$exact = self::get( 'backupplus_schedule_exact_' . $which, 0 );
		$time  = self::get( 'backupplus_starttime_' . $which, '' );
		$day   = self::get( 'backupplus_startday_' . $which, '' );
		$id    = 'backupplus_schedule_' . $which;
		$html  = '<span class="backupplus-schedule-timing" data-schedule="' . esc_attr( $which ) . '">';
		$html .= '<input type="hidden" name="backupplus_schedule_exact_' . esc_attr( $which ) . '" value="0">';
		$html .= '<label><input type="checkbox" class="backupplus-exact-toggle" id="' . esc_attr( $id ) . '_exact" name="backupplus_schedule_exact_' . esc_attr( $which ) . '" value="1"' . checked( ! empty( $exact ), true, false ) . '> ' . esc_html__( 'Run at an exact time', 'backupplus' ) . '</label>';
		$html .= '<span class="backupplus-exact-fields">';
		// Incremental intervals are a day or shorter, so they need no day.
		if ( 'increments' !== $which ) {
			$html .= '<label class="backupplus-schedule-weekday"><span class="backupplus-field-prefix">' . esc_html__( 'on', 'backupplus' ) . '</span> <select data-day-for="' . esc_attr( $which ) . '" aria-label="' . esc_attr__( 'Day of the week', 'backupplus' ) . '">';
			foreach ( $backupplus->list_days_of_the_week() as $weekday ) {
				$html .= '<option value="' . esc_attr( $weekday['index'] ) . '"' . selected( (string) $day, (string) $weekday['index'], false ) . '>' . esc_html( $weekday['value'] ) . '</option>';
			}
			$html .= '</select></label>';
			$html .= '<label class="backupplus-schedule-monthday"><span class="backupplus-field-prefix">' . esc_html__( 'on day', 'backupplus' ) . '</span> <select data-day-for="' . esc_attr( $which ) . '" aria-label="' . esc_attr__( 'Day of the month', 'backupplus' ) . '">';
			for ( $mday = 1; $mday <= 28; $mday++ ) {
				$html .= '<option value="' . $mday . '"' . selected( (string) $day, (string) $mday, false ) . '>' . $mday . '</option>';
			}
			$html .= '</select></label>';
			$html .= '<input type="hidden" class="backupplus-startday" name="backupplus_startday_' . esc_attr( $which ) . '" value="' . esc_attr( $day ) . '">';
		}
		$time  = self::valid_time( $time ) ? sprintf( '%05s', $time ) : '';
		$html .= '<label class="backupplus-exact-time"><span class="backupplus-field-prefix">' . esc_html__( 'at', 'backupplus' ) . '</span> <input type="time" step="60" id="' . esc_attr( $id ) . '_time" value="' . esc_attr( $time ) . '"></label>';
		$html .= '<label class="backupplus-exact-minute"><span class="backupplus-field-prefix">' . esc_html__( 'at', 'backupplus' ) . '</span> <select data-part="minute" aria-label="' . esc_attr__( 'Minutes past the hour', 'backupplus' ) . '"></select> ' . esc_html__( 'past every hour', 'backupplus' ) . '</label>';
		$html .= '<label class="backupplus-exact-hours"><span class="backupplus-field-prefix">' . esc_html__( 'at', 'backupplus' ) . '</span> <select data-part="hours" aria-label="' . esc_attr__( 'Run times', 'backupplus' ) . '"></select></label>';
		$html .= '<label class="backupplus-exact-hours">' . esc_html__( 'minute', 'backupplus' ) . ' <select data-part="hour-minute" aria-label="' . esc_attr__( 'Minute', 'backupplus' ) . '"></select></label>';
		$html .= '<input type="hidden" class="backupplus-starttime" name="backupplus_starttime_' . esc_attr( $which ) . '" value="' . esc_attr( $time ) . '">';
		$html .= '</span></span>';
		return $html;
	}

	/** Tags used by the schedule controls and summaries, for wp_kses(). */
	public static function allowed_html() {
		global $backupplus_admin;
		$tags = is_object( $backupplus_admin ) ? $backupplus_admin->kses_allow_tags() : array();
		foreach ( array( 'input', 'select', 'span', 'label', 'a', 'option', 'br', 'p' ) as $tag ) {
			$tags[ $tag ] = isset( $tags[ $tag ] ) ? $tags[ $tag ] : array();
		}
		$tags['input']  = array_merge( $tags['input'], array( 'aria-label' => true ) );
		$tags['select'] = array_merge(
			$tags['select'],
			array(
				'data-*'     => true,
				'aria-label' => true,
			)
		);
		$tags['span']   = array_merge( $tags['span'], array( 'data-*' => true ) );
		$tags['a']      = array_merge(
			$tags['a'],
			array(
				'rel'    => true,
				'data-*' => true,
			)
		);
		$tags['p']      = array_merge( $tags['p'], array( 'class' => true ) );
		return $tags;
	}

	/** Timezone and WordPress cron reliability notes shown with the schedule settings. */
	public static function timing_help() {
		/* translators: %s: Timezone name, e.g. Europe/London or +01:00 */
		$text = sprintf( __( 'Exact times use the site timezone (%s).', 'backupplus' ), self::timezone()->getName() ) . ' ' . __( 'WordPress starts scheduled tasks when the site receives a visit, so runs can be late on quiet sites.', 'backupplus' ) . ' ';
		return '<p class="description backupplus-schedule-help">' . esc_html( $text ) . '<a href="https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Optionally, run WordPress cron from your server\'s task scheduler.', 'backupplus' ) . '</a></p>';
	}

	public static function assets() {
		if ( empty( $_GET['page'] ) || 'backupplus' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects which assets to load.
			return;
		}
		wp_enqueue_style( 'backupplus-schedule', BACKUPPLUS_URL . '/css/backupplus-schedule.css', array( 'backupplus-admin-css' ), (string) filemtime( BACKUPPLUS_DIR . '/css/backupplus-schedule.css' ) );
		wp_enqueue_script( 'backupplus-schedule', BACKUPPLUS_URL . '/js/backupplus-schedule.js', array( 'jquery' ), (string) filemtime( BACKUPPLUS_DIR . '/js/backupplus-schedule.js' ), true );
		$intervals = array();
		foreach ( array_keys( wp_get_schedules() ) as $interval ) {
			$range                  = self::day_range( $interval );
			$exact                  = self::supports_exact( $interval );
			$intervals[ $interval ] = array_merge(
				array(
					'exact' => $exact,
					'day'   => $range ? ( 'monthly' === $interval ? 'month' : 'week' ) : null,
				),
				$exact ? self::time_control( $interval ) : array()
			);
		}
		wp_localize_script(
			'backupplus-schedule',
			'backupplus_schedule',
			array(
				'intervals' => $intervals,
				'i18n'      => array(
					'same' => __( 'Database backups run together with the files backup, because both use the same schedule.', 'backupplus' ),
				),
			)
		);
	}
}
