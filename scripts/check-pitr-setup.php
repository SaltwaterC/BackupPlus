<?php

/** Saved backup access and request-only restore access on the disposable source. */
require_once BACKUPPLUS_DIR . '/includes/class-pitr-setup.php';
$setup_allowed    = false;
$setup_permission = function () use ( &$setup_allowed ) {
	return $setup_allowed;
};
add_filter( 'backupplus_user_can_manage', $setup_permission, 300 );
$setup_options = function ( $value, $name ) use ( $config ) {
	return BackupPlus_PITR::OPTION === $name ? $config : $value;
};
add_filter( 'backupplus_get_option', $setup_options, 300, 2 );
$original_capture = get_option( BackupPlus_PITR_Setup::OPTION, null );
try {
	$result = BackupPlus_PITR_Setup::request(
		array(
			'operation' => 'connect',
			'username'  => 'pitr_saved_capture',
			'password'  => 'fixture-capture-only',
		)
	);
	pitr_check( isset( $result['error'] ) && 'Not authorized.' === $result['error'], 'Backup setup accepted an unauthorized request.' );
	$setup_allowed = true;
	$result        = BackupPlus_PITR_Setup::request( array( 'operation' => 'create' ) );
	pitr_check( isset( $result['error'] ), 'Account provisioning is still exposed.' );
	$admin->query( "CREATE USER IF NOT EXISTS 'pitr_saved_capture'@'%' IDENTIFIED BY 'fixture-capture-only'", true );
	$admin->query( pitr_grants( $engine, DB_NAME, "'pitr_saved_capture'@'%'" ), true );
	$result = BackupPlus_PITR_Setup::request(
		array(
			'operation' => 'connect',
			'username'  => 'pitr_saved_capture',
			'password'  => 'fixture-capture-only',
		)
	);
	pitr_check( ! empty( $result['ready'] ), 'Saving the capture account failed: ' . json_encode( $result ) );
	$raw = get_option( BackupPlus_PITR_Setup::OPTION );
	pitr_check( is_string( $raw['credentials'] ) && 0 === strpos( $raw['credentials'], 'bpcred:' ) && false === strpos( serialize( $raw ), 'fixture-capture-only' ), 'Capture credentials were stored in plaintext.' );
	$result = BackupPlus_PITR_Setup::request( array( 'operation' => 'status' ) );
	pitr_check( ! empty( $result['ready'] ), 'Saved encrypted capture access failed: ' . json_encode( $result ) );
	$captured_config                    = $config;
	$captured_config['capture_account'] = true;
	$captured                           = new BackupPlus_PITR_Database( $captured_config, $work );
	pitr_check( 0 === strpos( $captured->query( 'SELECT CURRENT_USER()' )[0][0], 'pitr_saved_capture@' ), 'Scheduled capture did not use the saved backup account.' );
	pitr_failure(
		function () use ( $captured ) {
			$captured->query( 'UPDATE `' . DB_NAME . '`.pitr_b SET value=999' );
		},
		'The capture account can write application data.'
	);
	$before_files                        = glob( $work . '/*' );
	$restore                             = BackupPlus_PITR_Setup::supplied_connection( $config, $restore_login[0], $restore_login[1] );
	$temporary_config                    = $config;
	$temporary_config['source_config']   = $restore;
	$temporary_config['capture_account'] = false;
	$temporary                           = new BackupPlus_PITR_Database( $temporary_config, $work );
	pitr_check( 0 === strpos( $temporary->query( 'SELECT CURRENT_USER()' )[0][0], $restore_login[0] . '@' ), 'Restore credentials could not reach the native client through memory.' );
	pitr_check( $before_files === glob( $work . '/*' ), 'Request-only restore access left a credential file on disk.' );
	BackupPlus_PITR_Process::forget_connection( $restore );
	pitr_failure(
		function () use ( $temporary ) {
			$temporary->query( 'SELECT 1' );
		},
		'Expired restore credentials remained usable.'
	);
	pitr_check( null === get_option( 'backupplus_pitr_recovery', null ), 'A privileged restore login was persisted.' );
	pitr_check( ! glob( $directory . '/archives/pitr/work-setup-*' ), 'Backup-account setup left working files.' );
	echo "PITR encrypted backup account, minimum capture grants, in-memory restore credentials and expiry passed.\n";
} finally {
	null === $original_capture ? delete_option( BackupPlus_PITR_Setup::OPTION ) : update_option( BackupPlus_PITR_Setup::OPTION, $original_capture );
	$admin->query( "DROP USER IF EXISTS 'pitr_saved_capture'@'%'", true );
	remove_filter( 'backupplus_user_can_manage', $setup_permission, 300 );
	remove_filter( 'backupplus_get_option', $setup_options, 300 );
	wp_cache_flush();
}
