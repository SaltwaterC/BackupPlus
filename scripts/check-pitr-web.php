<?php

/** Browser preparation protocol and source recovery, only on the redirected disposable DB. */
require_once BACKUPPLUS_DIR . '/includes/class-pitr-restore.php';
$allowed       = true;
$permission    = function () use ( &$allowed ) {
	return $allowed;
};
$configuration = function ( $value, $name ) use ( $config ) {
	return BackupPlus_PITR::OPTION === $name ? $config : $value;
};
add_filter( 'backupplus_user_can_manage', $permission, 100 );
add_filter( 'backupplus_get_option', $configuration, 200, 2 );
$ticket = '';
try {
	$timestamp = null;
	foreach ( $history as $time => $backup ) {
		if ( ! empty( $backup['pitr']['chain_id'] ) && $encrypted_full['chain_id'] === $backup['pitr']['chain_id'] ) { $timestamp = $time;
			break; }
	}
	pitr_check( null !== $timestamp, 'The encrypted PITR chain was not published in backup history.' );
	unset( $history[ $timestamp ]['pitr']['points'] );
	$decorated = BackupPlus_Backup_History::get_history( $timestamp );
	pitr_check( count( $decorated['pitr']['points'] ) >= 2, 'An existing history row did not acquire its PITR recovery points.' );
	$allowed = false;
	pitr_failure(
		function () use ( $timestamp, $encrypted_increment ) {
			BackupPlus_PITR_Restore::manifest( $timestamp, $encrypted_increment['backup_id'], '' );
		},
		'PITR preparation accepted an unauthorized user.'
	);
	$allowed = true;
	pitr_failure(
		function () use ( $timestamp ) {
			BackupPlus_PITR_Restore::manifest( $timestamp, str_repeat( '0', 32 ), '' );
		},
		'PITR preparation accepted a forged recovery point.'
	);
	$upload   = function ( $prepared ) use ( $key ) {
		$ticket = $prepared['ticket'];
		foreach ( $prepared['files'] as $filename ) {
			$cipher = BackupPlus_Age_Restore::download( $ticket, $filename );
			$plain  = BackupPlus_Encryption::decrypt( $cipher, $key['identity'], true, '' );
			pitr_check( is_array( $plain ), 'The fixture could not authenticate a browser PITR archive.' );
			$bytes = file_get_contents( $plain['fullpath'] );
			unlink( $plain['fullpath'] );
			for ( $offset = 0; $offset < strlen( $bytes ) || 0 === $offset; $offset += 524288 ) {
				$chunk = substr( $bytes, $offset, 524288 );
				$last  = $offset + strlen( $chunk ) === strlen( $bytes );
				BackupPlus_Age_Restore::upload( $ticket, $filename, $offset, $chunk, $last ? strlen( $bytes ) : null, $last ? hash( 'sha256', $bytes ) : '' );
			}
		}
	};
	$baseline = BackupPlus_PITR_Restore::manifest( $timestamp, $encrypted_full['backup_id'], '' );
	pitr_check( array( $encrypted_full['artifacts'][0]['name'] ) === $baseline['files'], 'Full snapshot preparation requested binary logs or PITR manifests.' );
	$retry = BackupPlus_PITR_Restore::manifest( $timestamp, $encrypted_full['backup_id'], $baseline['ticket'] );
	pitr_check( $baseline['ticket'] !== $retry['ticket'] && $baseline['files'] === $retry['files'], 'Retrying an incomplete preparation lost the staged ciphertext.' );
	$baseline = $retry;
	$ticket   = $baseline['ticket'];
	$upload( $baseline );
	$backupplus->admin_menu();
	require_once BACKUPPLUS_DIR . '/includes/class-wpadmin-commands.php';
	$commands  = new BackupPlus_WPAdmin_Commands( null );
	$options   = array(
		'backupplus_restore'            => array( 'db' ),
		'backupplus_pitr_restore_point' => $encrypted_full['backup_id'],
		'backupplus_age_ticket'         => $ticket,
	);
	$preflight = $commands->restore_alldownloaded(
		array(
			'timestamp'   => $timestamp,
			'restoreopts' => http_build_query( $options ),
		)
	);
	pitr_check( '' === $preflight['e'], 'Full-snapshot browser preflight rejected the root SQL dump: ' . $preflight['e'] );
	$options['backupplus_pitr_restore_point'] = $encrypted_increment['backup_id'];
	$preflight                                = $commands->restore_alldownloaded(
		array(
			'timestamp'   => $timestamp,
			'restoreopts' => http_build_query( $options ),
		)
	);
	pitr_check( false !== strpos( $preflight['e'], 'Incremental recovery points require binary-log replay' ), 'Ordinary browser preflight accepted a binlog recovery point.' );
	BackupPlus_Age_Restore::discard( $ticket );
	$prepared = BackupPlus_PITR_Restore::manifest( $timestamp, $encrypted_increment['backup_id'], '' );
	$ticket   = $prepared['ticket'];
	pitr_check( count( $prepared['files'] ) >= 4, 'Browser preparation omitted the full backup, log stages or encrypted manifests.' );
	$upload( $prepared );
	$params              = array(
		'timestamp' => $timestamp,
		'point'     => $encrypted_increment['backup_id'],
		'ticket'    => $ticket,
		'operation' => 'verify',
		'username'  => 'pitr_recovery_documented',
		'password'  => 'fixture-documented',
	);
	$missing_credentials = $params;
	unset( $missing_credentials['username'], $missing_credentials['password'] );
	pitr_failure(
		function () use ( $missing_credentials ) {
			BackupPlus_PITR_Restore::request( $missing_credentials );
		},
		'PITR restore silently reused saved credentials.'
	);
	$verified = BackupPlus_PITR_Restore::request( $params );
	pitr_check( $verified['verified'] && 1 === $verified['increments'], 'Browser verification did not select the requested encrypted increment.' );
	$params['operation'] = 'restore';
	$result              = BackupPlus_PITR_Restore::request( $params );
	pitr_check( $result['capture_enabled'] && ! empty( BackupPlus_PITR::config()['enabled'] ) && ! empty( $result['pre_restore_chain'] ) && '3' === $observer->query( 'SELECT value FROM `' . DB_NAME . '`.pitr_b' )[0][0], 'Prepared browser recovery did not replay the encrypted chain directly to the disposable source.' );
	pitr_check( null === get_option( 'backupplus_pitr_recovery', null ), 'Browser recovery persisted a privileged restore account.' );
	echo "PITR history migration, browser encrypted preparation, verification and direct source recovery passed.\n";
} finally {
	if ( $ticket ) { BackupPlus_Age_Restore::discard( $ticket ); }
	remove_filter( 'backupplus_user_can_manage', $permission, 100 );
	remove_filter( 'backupplus_get_option', $configuration, 200 );
}
