<?php
/** Retention cleanup: pruning, daily cleanup, waiting for running backups, interruption and resumption, and failed local and remote deletions. Required by ../check-backup-schedule.php, in its scope. */

// Local deletion is opt-in; these checks exercise it.
update_option( 'backupplus_delete_local', 1 );

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
