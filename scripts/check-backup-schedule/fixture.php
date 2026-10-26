<?php
/** Disposable fixture of the backup schedule checks: in-memory cron and history options, WordPress boot, settings backup and restore, and the helper closures. Required by ../check-backup-schedule.php, in its scope. */


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
require dirname( __DIR__, 2 ) . '/backupplus.php';
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
	'backupplus_interval'                  => 'daily',
	'backupplus_interval_database'         => 'daily',
	'backupplus_interval_increments'       => 'none',
	'backupplus_schedule_exact_increments' => '0',
	'backupplus_schedule_exact_files'      => '1',
	'backupplus_starttime_files'           => '02:15',
	'backupplus_startday_files'            => '',
	'backupplus_schedule_exact_db'         => '1',
	'backupplus_starttime_db'              => '03:40',
	'backupplus_startday_db'               => '',
	'backupplus_retention_cleanup_mode'    => 'daily',
	'backupplus_retention_cleanup_time'    => '04:05',
	'backupplus_retain'                    => '2',
	'backupplus_retain_db'                 => '2',
	'backupplus_service'                   => array( 'none' ),
);
