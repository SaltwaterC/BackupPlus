<?php
/**
 * Run live FTP/FTPS checks with disposable servers and locally issued certificates.
 *
 * All listeners bind only to loopback. No external FTP service or WordPress installation
 * is needed.
 */
require __DIR__ . '/lib/support.php';

$directory = integration_temp_dir( 'backupplus-ftps-' );
$server    = null;
try {
	$authority    = integration_issue_certificate( $directory, 'test-ca' );
	$other        = integration_issue_certificate( $directory, 'untrusted-ca' );
	$certificates = array(
		'FTP'            => null,
		'FTPS'           => integration_issue_certificate( $directory, 'trusted-server', $authority ),
		'FTPS_UNTRUSTED' => integration_issue_certificate( $directory, 'untrusted-server', $other ),
		'FTPS_EXPIRED'   => integration_issue_certificate( $directory, 'expired-server', $authority, true ),
	);
	$config       = array();
	foreach ( $certificates as $name => $certificate ) {
		mkdir( $directory . '/' . $name . '/login', 0700, true );
		$config[ $name ] = array( 'root' => $directory . '/' . $name );
		if ( $certificate ) {
			$config[ $name ]['certificate'] = $certificate['path'];
			$config[ $name ]['key']         = $certificate['key_path'];
		}
	}
	$server      = Integration_Server::start( __DIR__ . '/lib/server-ftp.php', array( json_encode( $config ) ) );
	$environment = array_merge(
		getenv(),
		array(
			'BACKUPPLUS_TEST_FTP_USER' => 'backup+user@example.test',
			'BACKUPPLUS_TEST_FTP_PASS' => 'p@ss:/?#% word',
			'BACKUPPLUS_TEST_FTP_CA'   => $authority['path'],
		)
	);
	foreach ( $server->info['ports'] as $name => $port ) {
		$environment[ 'BACKUPPLUS_TEST_' . $name . '_HOST' ] = 'localhost:' . $port;
	}
	// Disable the native FTP API to guard against accidental transport fallbacks.
	$native = 'ftp_connect,ftp_ssl_connect,ftp_login,ftp_nb_fput,ftp_nb_fget,ftp_nlist,ftp_size,ftp_delete';
	$result = integration_run( array( PHP_BINARY, '-d', 'curl.cainfo=' . $authority['path'], '-d', 'disable_functions=' . $native, dirname( __DIR__ ) . '/test-ftp.php' ), '', $environment );
	echo $result['stdout'], $result['stderr'];
	integration_expect( 0 === $result['status'], 'The FTP checks failed' );
} finally {
	if ( $server ) {
		$server->stop();
	}
	integration_remove_tree( $directory );
}
