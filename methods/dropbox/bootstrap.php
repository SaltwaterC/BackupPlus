<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}

// Keep legacy token compatibility with the provider; the cipher and key store
// remain shared services. These hooks run before generic credential maintenance.
require_once __DIR__ . '/Dropbox2/Exception.php';
require_once __DIR__ . '/Dropbox2/OAuth/Storage/Encrypter.php';
require_once __DIR__ . '/token-migration.php';

foreach ( array( 'upgrade', 'upgrade_restored', 'rotate', 'retire', 'verify' ) as $backupplus_credential_operation ) {
	add_action( 'backupplus_credentials_' . $backupplus_credential_operation, array( 'BackupPlus_Dropbox_Token_Migration', $backupplus_credential_operation ) );
}
add_filter(
	'backupplus_credentials_has_ciphertext',
	function ( $found ) {
		return $found || false !== strpos( serialize( BackupPlus_Options::get_backupplus_option( 'backupplus_dropbox' ) ), 'bpcred:' );
	}
);
// Saved jobs hold a copy of the Dropbox settings inside their own envelope, which key maintenance re-encrypts as a whole
add_filter( 'backupplus_credentials_job_snapshot', array( 'BackupPlus_Dropbox_Token_Migration', 'job_snapshot' ), 10, 2 );
