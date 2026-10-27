<?php
/** Sweep abandoned tar staging files using isolated WordPress jobs and archives. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || defined( 'BACKUPPLUS_DIR' ) ) { die( 'Use WP-CLI with --skip-plugins=backupplus.' ); }
function temporary_expect( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
$directory = get_temp_dir() . 'backupplus-temporary-' . bin2hex( random_bytes( 8 ) );
mkdir( $directory, 0700 );
$history = array();
$cron    = array();
$options = array();
$jobs    = array();
add_filter(
	'pre_option_backupplus_dir',
	function () use ( $directory ) {
		return $directory;
	},
	100
);
add_filter(
	'pre_option_backupplus_backup_history',
	function () use ( &$history ) {
		return $history;
	},
	100
);
add_filter(
	'pre_option_cron',
	function () use ( &$cron ) {
		return $cron;
	},
	100
);
add_filter(
	'pre_update_option_cron',
	function ( $value, $old ) use ( &$cron ) {
		$cron = $value;
		return $old;
	},
	100,
	2
);
require dirname( __DIR__ ) . '/backupplus.php';
global $backupplus;
$missing = new stdClass();
foreach ( array( 'backupplus_oneshotnonce', 'backupplus_restore_in_progress' ) as $name ) {
	$options[ $name ] = get_site_option( $name, $missing );
	delete_site_option( $name );
}
$make = function ( $suffix, $age = 46800 ) use ( $directory ) {
	$name = 'backup_2026-01-01-0000_Temporary_abcdef123456-' . $suffix;
	file_put_contents( $directory . '/' . $name, 'disposable fixture' );
	touch( $directory . '/' . $name, time() - $age );
	return $name;
};
try {
	$abandoned  = $make( 'uploads.tar' );
	$split      = $make( 'uploads2.tar' );
	$custom     = $make( 'custom-abcdefghijklmnop3.tar' );
	$recent     = $make( 'plugins.tar', 3600 );
	$referenced = $make( 'themes.tar' );
	$published  = $make( 'uploads.tar.zst' );
	$encrypted  = $make( 'uploads2.tar.zst.crypt' );
	$partial    = $make( 'others.tar.tmp' );
	$history    = array(
		time() => array(
			'themes' => array( $referenced ),
			'native' => false,
		),
	);
	file_put_contents( $directory . '/unrelated.tar', 'unrelated user archive' );
	touch( $directory . '/unrelated.tar', time() - 46800 );
	file_put_contents( $directory . '/outside.txt', 'symlink target' );
	$link = 'backup_2026-01-01-0000_Temporary_abcdef123456-wpcore.tar';
	symlink( $directory . '/outside.txt', $directory . '/' . $link );
	touch( $directory . '/outside.txt', time() - 46800 );

	// A pending incremental uses the original full backup's file nonce.
	$job    = bin2hex( random_bytes( 6 ) );
	$jobs[] = $job;
	update_site_option(
		'backupplus_jobdata_' . $job,
		array(
			'job_type'    => 'incremental',
			'jobstatus'   => 'filescreating',
			'file_nonce'  => 'abcdef123456',
			'job_time_ms' => time(),
		)
	);
	wp_schedule_single_event( time() + 600, 'backupplus_backup_resume', array( 2, $job ) );
	BackupPlus_Filesystem_Functions::clean_temporary_files( '', 9999 );
	temporary_expect( is_file( $directory . '/' . $abandoned ) && is_file( $directory . '/' . $split ) && is_file( $directory . '/' . $partial ), 'Pending resumptions must protect completed and partial old tar staging parts.' );
	wp_clear_scheduled_hook( 'backupplus_backup_resume', array( 2, $job ) );

	update_site_option( 'backupplus_oneshotnonce', $job );
	BackupPlus_Filesystem_Functions::clean_temporary_files( '', 9999 );
	temporary_expect( is_file( $directory . '/' . $abandoned ), 'Running one-shot backups must protect tar staging parts.' );
	delete_site_option( 'backupplus_oneshotnonce' );

	update_site_option( 'backupplus_restore_in_progress', array( 'nonce' => $job ) );
	BackupPlus_Filesystem_Functions::clean_temporary_files( '', 9999 );
	temporary_expect( is_file( $directory . '/' . $abandoned ), 'Active restores must defer tar staging cleanup.' );
	delete_site_option( 'backupplus_restore_in_progress' );

	// Exercise the actual twice-daily hook, including the ordinary temporary-file sweep.
	update_site_option(
		'backupplus_jobdata_' . $job,
		array(
			'job_type'    => 'incremental',
			'jobstatus'   => 'finished',
			'job_time_ms' => time(),
		)
	);
	wp_schedule_single_event( time() + 600, 'backupplus_backup_resume', array( 2, $job ) );
	do_action( 'backupplus_clean_temporary_files' );
	foreach ( array( $abandoned, $split, $custom, $partial ) as $name ) {
		temporary_expect( ! file_exists( $directory . '/' . $name ), 'The sweep must remove abandoned staging files: ' . $name );
	}
	foreach ( array( $recent, $referenced, $published, $encrypted, 'unrelated.tar', 'outside.txt' ) as $name ) {
		temporary_expect( is_file( $directory . '/' . $name ), 'The sweep must preserve recent, referenced and published files: ' . $name );
	}
	temporary_expect( is_link( $directory . '/' . $link ) && 'symlink target' === file_get_contents( $directory . '/outside.txt' ), 'The sweep must not follow or remove tar symlinks.' );
	WP_CLI::success( 'Abandoned tar cleanup, split/custom names, active jobs, restores, history and published archive protection passed.' );
} finally {
	foreach ( $jobs as $job ) { delete_site_option( 'backupplus_jobdata_' . $job ); }
	foreach ( $options as $name => $value ) { delete_site_option( $name );
		if ( $value !== $missing ) { update_site_option( $name, $value ); }
	}
	foreach ( glob( $directory . '/*' ) as $path ) { unlink( $path ); }
	rmdir( $directory );
}
