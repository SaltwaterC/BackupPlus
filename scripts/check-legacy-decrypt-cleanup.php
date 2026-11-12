<?php
/**
 * BPS-14 in real WordPress: a legacy (UpdraftPlus Rijndael) encrypted database that is scanned by the restore preflight
 * leaves no plaintext on disk. The scan decrypts to a private temporary file and removes it however
 * it ends, and the encrypted backup is never accompanied by a plaintext copy.
 *
 * wp --skip-plugins=backupplus eval-file wp-content/plugins/backupplus/scripts/check-legacy-decrypt-cleanup.php
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || defined( 'BACKUPPLUS_DIR' ) ) {
	die( 'Use WP-CLI with --skip-plugins=backupplus.' );
}
function legacy_cleanup_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	} }

$directory = WP_CONTENT_DIR . '/backupplus-legacy-' . bin2hex( random_bytes( 8 ) );
mkdir( $directory, 0700 );
require dirname( __DIR__ ) . '/backupplus.php';
global $backupplus;
legacy_cleanup_expect( $backupplus->ensure_phpseclib(), 'The crypto loader must load' );
$key    = random_bytes( 32 );
$dump   = "# BackupPlus Backup\n# Backup of: http://example.test\n# WordPress Version: 6.0\n# Table prefix: wp_\n" . str_repeat( "-- padding line of the legacy database\n", 120 ) . "CREATE TABLE `wp_options` (\n  `id` int\n) ENGINE=InnoDB;\n";
$pad    = 16 - strlen( $dump ) % 16;
$raw    = $dump . str_repeat( chr( $pad ), $pad );
$name   = 'backup_2026-01-01-0000_Legacy_abcdef123456-db.sql';
$cipher = BackupPlus_Crypto::rijndael( $key );
$cipher->disablePadding();
$file = $directory . '/' . $name . '.crypt';
file_put_contents( $file, $cipher->encrypt( $raw ) );
$files = function () use ( $directory ) {
	$list = array_map( 'basename', glob( $directory . '/*' ) );
	sort( $list );
	return $list;
};
try {
	$before = $files();
	legacy_cleanup_expect( array( $name . '.crypt' ) === $before, 'The fixture must be the encrypted file only' );

	// The preflight scan: a right key and a wrong key.
	foreach ( array(
		'right key' => $key,
		'wrong key' => random_bytes( 32 ),
	) as $label => $phrase ) {
		list( $mess, $warn, $err ) = $backupplus->analyse_db_file( time(), array( 'backupplus_encryptionphrase' => $phrase ), $file );
		legacy_cleanup_expect( $before === $files(), 'The scan must leave no plaintext beside the encrypted backup (' . $label . '): ' . implode( ', ', $files() ) );
	}
	list( $mess, $warn, $err ) = $backupplus->analyse_db_file( time(), array( 'backupplus_encryptionphrase' => $key ), $file );
	legacy_cleanup_expect( empty( $err ), 'The scan with the right key must read the database: ' . implode( ' ', $err ) );

	// No download decrypts on the server: downloads keep their ciphertext (BPS-20)
	legacy_cleanup_expect( ! method_exists( 'BackupPlus_Encryption', 'spool_crypted_file' ), 'No server-side decrypted download may remain' );
	WP_CLI::success( 'Decrypting a legacy database for the scan leaves no plaintext on disk.' );
} finally {
	foreach ( glob( $directory . '/*' ) as $leftover ) {
		unlink( $leftover );
	}
	rmdir( $directory );
}
