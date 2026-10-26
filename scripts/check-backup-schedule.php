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

require __DIR__ . '/check-backup-schedule/fixture.php';
require __DIR__ . '/check-backup-schedule/saves.php';
require __DIR__ . '/check-backup-schedule/recurrence.php';
require __DIR__ . '/check-backup-schedule/settings-lifecycle.php';
require __DIR__ . '/check-backup-schedule/cleanup.php';

$test_complete = true;
WP_CLI::success( 'Exact schedules, combining, saves, imports, reset, timezone changes, delayed cron and retention cleanup passed.' );
