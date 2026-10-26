<?php
/**
 * A fresh process of the Dropbox credential tests, which report what they observed as JSON on stdout.
 *
 * Usage: php dropbox-encryption-child.php metadata-only <key directory>
 *        php dropbox-encryption-child.php managed <wp-content>
 *        php dropbox-encryption-child.php restricted <wp-content> <forbidden directory>
 *        php dropbox-encryption-child.php restore <settings export> [server config]
 */

require_once __DIR__ . '/dropbox-encryption-stubs.php';
dropbox_stubs_reset();

$mode = isset( $argv[1] ) ? $argv[1] : '';
if ( 'metadata-only' === $mode ) {
	update_site_option( BackupPlus_Credential_Key_Store::OPTION, $argv[2] );
	$loaded = class_exists( 'phpseclib3\\Crypt\\Random', false );
	BackupPlus_Credential_Key_Store::mark_upgraded();
	echo json_encode(
		array(
			'crypto_loaded_before' => $loaded,
			'upgrade_complete'     => BackupPlus_Credential_Key_Store::upgrade_complete(),
		)
	);
	exit( 0 );
}
$backupplus->ensure_phpseclib();
if ( 'restricted' === $mode ) {
	define( 'WP_CONTENT_DIR', $argv[2] );
	$config  = BackupPlus_Credential_Key_Store::configuration();
	$blocked = false;
	set_error_handler(
		function ( $severity, $message ) {
			if ( error_reporting() & $severity ) {
				throw new RuntimeException( $message );
			} }
	);
	try {
		BackupPlus_Credential_Key_Store::relocate( $argv[3] );
	} catch ( Exception $e ) {
		$blocked = true; }
	restore_error_handler();
	echo json_encode(
		array(
			'blocked'     => $blocked,
			'same_config' => $config === BackupPlus_Credential_Key_Store::configuration(),
		)
	);
	exit( 0 );
}
if ( 'managed' === $mode ) {
	define( 'WP_CONTENT_DIR', $argv[2] );
	echo 'KEY-FINGERPRINT:' . hash( 'sha256', json_encode( BackupPlus_Credential_Key_Store::configuration() ) ) . "\n";
	exit( 0 );
}
if ( 'restore' === $mode ) {
	// A new PHP process restores only the DB/settings export and separate server config.
	define( 'WP_CONTENT_DIR', dirname( $argv[2] ) . '/missing-content' );
	$db = json_decode( file_get_contents( $argv[2] ), true );
	if ( isset( $argv[3] ) ) {
		require $argv[3];
	}
	$result = array();
	if ( ! isset( $argv[3] ) ) {
		BackupPlus_Options::$options = array( 'settings' => array( 'instance' => array( 'tk_access_token' => $db['token'] ) ) );
		$result['failed']            = false;
		try {
			Dropbox_Encrypter::from_config( 'instance' );
		} catch ( Exception $e ) {
			$result['failed'] = true; }
	} else {
		$restored               = Dropbox_Encrypter::from_config( 'instance' )->decrypt( $db['token'] );
		$result['hash_matches'] = hash( 'sha256', $restored ) === $db['plaintext_hash'];
	}
	echo json_encode( $result );
	exit( 0 );
}
fwrite( STDERR, "Unknown mode\n" );
exit( 2 );
