<?php
/** Isolated synthetic recovery point for browser restore preparation checks. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { die( 'CLI only.' ); }
$token     = $args[0] ?? '';
$operation = $args[1] ?? '';
if ( ! preg_match( '/\A[a-f0-9]{24}\z/D', $token ) ) { throw new RuntimeException( 'Invalid token.' ); }
$option    = 'backupplus_restore_browser_' . $token;
$directory = wp_upload_dir()['basedir'] . '/' . $option;
$mu_file   = WPMU_PLUGIN_DIR . '/' . $option . '.php';
$timestamp = 1792612800;
if ( 'cleanup' === $operation ) {
	foreach ( (array) get_option( $option . '_tickets', array() ) as $ticket ) { delete_transient( 'backupplus_age_restore_' . $ticket ); }
	foreach ( array( '', '_tickets' ) as $suffix ) { delete_option( $option . $suffix ); }
	if ( is_file( $mu_file ) ) { unlink( $mu_file ); }
	foreach ( glob( $directory . '/*' ) ?: array() as $path ) { unlink( $path ); }
	if ( is_dir( $directory ) ) { rmdir( $directory ); }
	return;
}
$isolation = strtr(
	<<<'PHP'
add_filter('pre_option_backupplus_dir', function() { return TEST_DIRECTORY; }, 100);
add_filter('pre_option_backupplus_backup_history', function() { return get_option(TEST_OPTION, array()); }, 100);
add_filter('pre_update_option_backupplus_backup_history', function($value,$old) { update_option(TEST_OPTION,$value,false); return $old; }, 100, 2);
PHP,
	array(
		'TEST_DIRECTORY' => var_export( $directory, true ),
		'TEST_OPTION'    => var_export( $option, true ),
	)
);
eval( $isolation );
require dirname( __DIR__, 2 ) . '/backupplus.php';
if ( 'setup' === $operation ) {
	mkdir( $directory, 0700 );
	file_put_contents( $mu_file, '<?php if (($_SERVER["HTTP_X_BACKUPPLUS_RESTORE_TEST"] ?? "") !== ' . var_export( $token, true ) . ') return;' . "\n" . $isolation );
	$fixture = json_decode( file_get_contents( __DIR__ . '/age-keys.json' ), true );
	$plain   = 'backup_2026-10-21-2000_Browser_0123456789ab-uploads.zip';
	$zip     = new ZipArchive();
	$zip->open( $directory . '/' . $plain, ZipArchive::CREATE );
	$zip->addFromString( 'uploads/restore-browser.txt', random_bytes( 1100000 ) );
	$zip->close();
	if ( BackupPlus_Archive_Compression::enabled() ) {
		unlink( $directory . '/' . $plain );
		$plain   = str_replace( '.zip', '.tar', $plain );
		$payload = $directory . '/payload.tmp';
		file_put_contents( $payload, random_bytes( 1100000 ) );
		$tar = new BackupPlus_Tar();
		$tar->open( $directory . '/' . $plain );
		$tar->addFile( $payload, 'uploads/restore-browser.txt' );
		$tar->close();
		unlink( $payload );
		BackupPlus_Archive_Compression::compress_file( $directory . '/' . $plain );
		unlink( $directory . '/' . $plain );
		$plain .= '.zst';
	}
	$hash = hash_file( 'sha256', $directory . '/' . $plain );
	global $backupplus;
	$backupplus->ensure_phpseclib();
	$input  = fopen( $directory . '/' . $plain, 'rb' );
	$output = fopen( $directory . '/' . $plain . '.crypt', 'wb' );
	BackupPlus_Age_Crypto::encrypt( $input, $output, array( $fixture['keys']['native']['recipient'], $fixture['keys']['ed25519']['recipient'] ) );
	fclose( $input );
	fclose( $output );
	unlink( $directory . '/' . $plain );
	update_option(
		$option,
		array(
			$timestamp => array(
				'nonce'        => '0123456789ab',
				'native'       => true,
				'service'      => array( 'none' ),
				'uploads'      => array( $plain . '.crypt' ),
				'uploads-size' => filesize( $directory . '/' . $plain . '.crypt' ),
			),
		),
		false
	);
	echo json_encode(
		array(
			'timestamp' => $timestamp,
			'hash'      => $hash,
			'file'      => $plain . '.crypt',
		)
	);
} elseif ( 'cancelled' === $operation ) {
	echo json_encode(
		array(
			'exists' => false !== get_transient( 'backupplus_age_restore_' . $args[2] ),
			'files'  => glob( $directory . '/decrypt_*.tmp' ) ?: array(),
		)
	);
} elseif ( 'verify' === $operation ) {
	$ticket = $args[2];
	$user   = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
		)
	)[0];
	wp_set_current_user( $user->ID );
	$data = get_transient( 'backupplus_age_restore_' . $ticket );
	if ( ! $data ) { throw new RuntimeException( 'Missing ticket.' ); }
	$tickets   = get_option( $option . '_tickets', array() );
	$tickets[] = $ticket;
	update_option( $option . '_tickets', $tickets, false );
	$result = array();
	foreach ( $data['files'] as $name => $entry ) {
		$result[ $name ] = hash_file( 'sha256', $directory . '/' . $entry['path'] );
	}
	echo json_encode( $result );
} else { throw new RuntimeException( 'Invalid operation.' ); }
