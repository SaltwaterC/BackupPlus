<?php
/** Disposable encrypted restore fixture. Secrets leave setup only through its captured stdout pipe. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || defined( 'BACKUPPLUS_DIR' ) ) { die( 'Use --skip-plugins=backupplus eval-file.' ); }
set_exception_handler(
	function () {
		fwrite( STDERR, "CLI restore fixture failed.\n" );
		exit( 1 );
	}
);
global $backupplus, $wpdb;
$action    = isset( $args[0] ) ? $args[0] : '';
$directory = isset( $args[1] ) ? $args[1] : '';
if ( ! preg_match( '#^/tmp/backupplus-cli-restore-[a-f0-9]{24}$#D', $directory ) ) { WP_CLI::error( 'Invalid fixture directory.' ); }
$prefix = 'backupplus_cli_restore_test_' . substr( $directory, -24 ) . '_';
$table  = $wpdb->prefix . 'bkp_cli_restore_' . substr( $directory, -24 );
if ( 'setup' === $action ) {
	if ( file_exists( $directory ) || ! mkdir( $directory, 0700 ) ) { WP_CLI::error( 'Fixture directory unavailable.' ); }
	mkdir( $directory . '/archives', 0700 );
	file_put_contents( $directory . '/custom.txt', 'BACKUP' );
	$wpdb->query( "CREATE TABLE `$table` (id int PRIMARY KEY, value varchar(100))" );
	$wpdb->query( "INSERT INTO `$table` VALUES (1, 'BACKUP')" );
	require dirname( __DIR__ ) . '/backupplus.php';
	if ( is_wp_error( $backupplus->ensure_phpseclib() ) ) { WP_CLI::error( 'Crypto unavailable.' ); }
	$password = 'cli-restore-secret-' . bin2hex( random_bytes( 16 ) );
	$key      = \phpseclib3\Crypt\RSA::createKey( 2048 );
	$identity = $key->withPassword( $password )->toString( 'PKCS8' );
	$public   = $key->getPublicKey()->toString( 'OpenSSH' );
	$options  = array(
		'backupplus_dir'                    => $directory . '/archives',
		'backupplus_backup_history'         => array(),
		'cron'                              => array(),
		'backupplus_service'                => array( 'none' ),
		'backupplus_database_encryption'    => array(
			'mode'       => 'age',
			'recipients' => array( $public ),
		),
		'backupplus_delete_local'           => 0,
		'backupplus_email'                  => '',
		'backupplus_debug_mode'             => 0,
		'backupplus_interval'               => 'manual',
		'backupplus_interval_database'      => 'manual',
		'backupplus_interval_increments'    => 'none',
		'backupplus_include_more_path'      => array( $directory . '/custom.txt' ),
		'backupplus_include_more'           => 1,
		'backupplus_database_selection'     => array(
			'wp' => array(
				'mode'   => 'selected',
				'tables' => array( $table ),
			),
		),
		'backupplus_extradbs'               => array(),
		'backupplus_retain'                 => 2,
		'backupplus_retain_db'              => 2,
		'backupplus_retention_cleanup_mode' => 'after_backup',
	);
	foreach ( array( 'backupplus_last_backup', 'backupplus_lastmessage', 'backupplus_lastlogmessage', 'backupplus_oneshotnonce', 'backupplus_last_scheduled_f', 'backupplus_last_scheduled_d', 'backupplus_last_scheduled_fd', 'backupplus_restore_in_progress' ) as $name ) { $options[ $name ] = get_option( $name, array() ); }
	file_put_contents(
		$directory . '/config.json',
		json_encode(
			array(
				'prefix'  => $prefix,
				'options' => $options,
			)
		)
	);
	putenv( 'BACKUPPLUS_CLI_TEST_DIR=' . $directory );
	require __DIR__ . '/fixtures/cli.php';
	$id     = BackupPlus_Backup_Sources::source_id( $directory . '/custom.txt' );
	$nonce  = bin2hex( random_bytes( 6 ) );
	$result = $backupplus->boot_backup( 1, 1, array( $id ), true, array( 'none' ), array( 'use_nonce' => $nonce ) );
	if ( ! $result || $backupplus->error_count() || $backupplus->error_count( 'warning' ) ) { WP_CLI::error( 'Encrypted fixture backup failed.' ); }
	$timestamp     = $backupplus->backup_time;
	$history       = BackupPlus_Backup_History::get_history( $timestamp );
	$file_archives = (array) $history[ $id ];
	$db_archives   = (array) $history['db'];
	if ( ! BackupPlus_Encryption::is_file_encrypted( reset( $file_archives ) ) || ! BackupPlus_Encryption::is_file_encrypted( reset( $db_archives ) ) ) { WP_CLI::error( 'Fixture archives were not encrypted.' ); }
	file_put_contents( $directory . '/custom.txt', 'LIVE' );
	$wpdb->query( "UPDATE `$table` SET value='LIVE'" );
	// The parent test keeps these values in RAM. Never place them in fixture settings or files.
	echo json_encode(
		array(
			'timestamp'            => $timestamp,
			'identity'             => $identity,
			'unencrypted_identity' => $key->toString( 'PKCS8' ),
			'passphrase'           => $password,
			'component'            => $id,
		)
	);
	return;
}
if ( ! is_dir( $directory ) ) { WP_CLI::error( 'Fixture not found.' ); }
if ( 'reset' === $action ) {
	file_put_contents( $directory . '/custom.txt', 'LIVE' );
	$wpdb->query( "UPDATE `$table` SET value='LIVE'" );
	return;
}
if ( 'tamper' === $action ) {
	$history = get_option( $prefix . 'backupplus_backup_history' );
	foreach ( $history as &$backup ) {
		$file                          = is_array( $backup['db'] ) ? reset( $backup['db'] ) : $backup['db'];
		$path                          = $directory . '/archives/' . $file;
		$bytes                         = file_get_contents( $path );
		$bytes[ strlen( $bytes ) - 1 ] = chr( ord( $bytes[ strlen( $bytes ) - 1 ] ) ^ 1 );
		file_put_contents( $path, $bytes );
		foreach ( $backup['checksums'] as $algorithm => &$checksums ) { foreach ( $checksums as $key => &$hash ) { if ( 0 === strpos( $key, 'db' ) ) { $hash = hash_file( $algorithm, $path );
		}
		} unset( $hash );
		} unset( $checksums );
	} unset( $backup );
	update_option( $prefix . 'backupplus_backup_history', $history, false );
	return;
}
$logs = glob( $directory . '/archives/log.*.txt' );
if ( 'report' === $action ) {
	$state = '';
	foreach ( $logs as $log ) {
		$state .= file_get_contents( $log );
		if ( preg_match( '/log\.([a-f0-9]{12})(?:-browser)?\.txt$/D', $log, $match ) ) { $state .= serialize( get_site_option( 'backupplus_jobdata_' . $match[1] ) ); }
	}
	$names = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ), ARRAY_A );
	echo json_encode(
		array(
			'file'    => file_get_contents( $directory . '/custom.txt' ),
			'table'   => $wpdb->get_var( "SELECT value FROM `$table` WHERE id=1" ),
			'staging' => glob( $directory . '/archives/decrypt_*.tmp' ),
			'state'   => $state . serialize( $names ),
		)
	);
	return;
}
if ( 'cleanup' === $action ) {
	$wpdb->query( "DROP TABLE IF EXISTS `$table`" );
	foreach ( $logs as $log ) { if ( preg_match( '/log\.([a-f0-9]{12})(?:-browser)?\.txt$/D', $log, $match ) ) { delete_site_option( 'backupplus_jobdata_' . $match[1] ); }
	}
	$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
	foreach ( $names as $name ) { delete_option( $name ); }
	$remove = function ( $path ) use ( &$remove ) {
		if ( is_dir( $path ) && ! is_link( $path ) ) { foreach ( array_diff( scandir( $path ), array( '.', '..' ) ) as $entry ) { $remove( $path . '/' . $entry );
		} rmdir( $path );
		} else { unlink( $path );
		} };
	$remove( $directory );
	return;
}
WP_CLI::error( 'Unknown fixture operation.' );
