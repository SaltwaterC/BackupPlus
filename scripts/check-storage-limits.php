<?php
/** Real WordPress settings, notifications and local retention using disposable archives. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || defined( 'BACKUPPLUS_DIR' ) ) { die( 'Use WP-CLI with --skip-plugins=backupplus.' ); }
function limits_expect( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); } }
$directory = get_temp_dir() . 'backupplus-limits-' . bin2hex( random_bytes( 8 ) );
mkdir( $directory );
$history = array();
$cron    = array();
$mails   = array();
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
	'pre_update_option_backupplus_backup_history',
	function ( $value, $old ) use ( &$history ) {
		$history = $value;
		return $old;
	},
	100,
	2
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
add_filter(
	'pre_wp_mail',
	function ( $result, $attributes ) use ( &$mails ) {
		$mails[] = $attributes;
		return true;
	},
	100,
	2
);
add_filter(
	'pre_http_request',
	function () {
		return new WP_Error( 'test_offline' );
	}
);
global $backupplus, $backupplus_admin;
require dirname( __DIR__ ) . '/backupplus.php';
require_once BACKUPPLUS_DIR . '/admin.php';
BackupPlus_Options::admin_init();
$original = array();
$missing  = new stdClass();
foreach ( array( BackupPlus_Storage_Limits::OPTION, BackupPlus_Storage_Limits::STATUS, 'backupplus_service', 'backupplus_delete_local', 'backupplus_oneshotnonce', 'backupplus_restore_in_progress' ) as $name ) { $original[ $name ] = get_option( $name, $missing ); }
try {
	update_option( 'backupplus_service', array( 'none' ) );
	update_option( 'backupplus_delete_local', 0 );
	delete_option( 'backupplus_oneshotnonce' );
	delete_option( 'backupplus_restore_in_progress' );
	delete_option( BackupPlus_Storage_Limits::STATUS );
	update_option(
		BackupPlus_Storage_Limits::OPTION,
		array(
			'_unit' => 'GB',
			'local' => array(
				'__default' => array(
					'alert' => '1.25',
					'quota' => '',
				),
			),
		)
	);
	limits_expect( 1250000000 === get_option( BackupPlus_Storage_Limits::OPTION )['local']['']['alert'], 'GB form values are stored as bytes.' );
	limits_expect( wp_next_scheduled( BackupPlus_Storage_Limits::HOOK ), 'Limits schedule the hourly monitor.' );
	$export = get_option( BackupPlus_Storage_Limits::OPTION );
	update_option( BackupPlus_Storage_Limits::OPTION, json_decode( wp_json_encode( $export ), true ) );
	limits_expect( $export === get_option( BackupPlus_Storage_Limits::OPTION ), 'An export/import round trip preserves integer bytes.' );
	limits_expect( in_array( BackupPlus_Storage_Limits::OPTION, $backupplus->get_settings_keys(), true ), 'Limits are included in save, export, import and reset keys.' );
	foreach ( array( 100, 200 ) as $time ) {
		$name = 'backup_2026-01-01-0000_test_' . str_pad( (string) $time, 12, '0', STR_PAD_LEFT ) . '-plugins.zip';
		file_put_contents( $directory . '/' . $name, str_repeat( 'a', 60 ) );
		$history[ $time ] = array(
			'plugins'      => array( $name ),
			'plugins-size' => 60,
			'native'       => true,
			'service'      => array( 'none' ),
		);
	}
	update_option(
		BackupPlus_Storage_Limits::OPTION,
		array(
			'local' => array(
				'' => array(
					'alert' => 120,
					'quota' => 120,
				),
			),
		)
	);
	BackupPlus_Storage_Limits::run();
	limits_expect( ! is_file( $directory . '/' . $history[100]['plugins'][0] ) && is_file( $directory . '/' . $history[200]['plugins'][0] ), 'At the quota boundary FIFO eviction keeps the newest restorable chain.' );
	limits_expect( count( $mails ) === 2, 'Independent alert and quota limits each notify on a direct critical crossing.' );
	limits_expect( $mails[0]['to'] === get_option( 'admin_email' ) && false !== strpos( $mails[0]['message'], 'Usage: 120' ), 'The administrator receives the pre-eviction crossing and outcome.' );
	BackupPlus_Storage_Limits::run();
	limits_expect( count( $mails ) === 2, 'Repeating the monitor does not duplicate notifications.' );
	limits_expect( false !== strpos( BackupPlus_Storage_Limits::summary_html(), 'Local storage' ), 'BackupPlus displays destination usage.' );
	delete_option( BackupPlus_Storage_Limits::OPTION );
	limits_expect( ! wp_next_scheduled( BackupPlus_Storage_Limits::HOOK ) && ! get_option( BackupPlus_Storage_Limits::STATUS ), 'Reset clears monitor hooks and alert state.' );
	WP_CLI::success( 'Storage limits settings, accounting, FIFO retention, notifications and reset passed.' );
} finally {
	foreach ( $original as $name => $value ) { delete_option( $name );
		if ( $value !== $missing ) { update_option( $name, $value ); }
	}
	foreach ( glob( $directory . '/*' ) as $path ) { unlink( $path ); }
	rmdir( $directory );
}
