<?php
/**
 * Exercise WebDAV over loopback HTTP (Digest auth) and HTTPS (Basic auth).
 *
 * Requires PHP with cURL/DOM. The disposable DAV fixture also returns malformed/mixed
 * XML and incomplete HTTP responses to exercise cases that are difficult to reproduce
 * with a normal storage service.
 */
require __DIR__ . '/lib/support.php';

$directory = integration_temp_dir( 'backupplus-dav-' );
$server    = null;
try {
	$authority = integration_issue_certificate( $directory, 'test-ca' );
	$localhost = integration_issue_certificate( $directory, 'localhost-server', $authority );
	$server    = Integration_Server::start( __DIR__ . '/lib/server-http.php', array( 'webdav', $localhost['path'], $localhost['key_path'] ) );
	$result    = integration_run(
		array( PHP_BINARY, '-d', 'curl.cainfo=' . $authority['path'], dirname( __DIR__ ) . '/test-webdav.php' ),
		'',
		array_merge(
			getenv(),
			array(
				'BACKUPPLUS_TEST_DAV_HOST'     => 'localhost:' . $server->info['ports']['plain'],
				'BACKUPPLUS_TEST_DAV_TLS_HOST' => 'localhost:' . $server->info['ports']['tls'],
			)
		),
		90
	);
	echo $result['stdout'], $result['stderr'];
	integration_expect( 0 === $result['status'], 'The WebDAV checks failed' );
	$state = $server->state();
	integration_expect( array() === $state['violations'], 'The WebDAV server saw a protocol violation: ' . implode( '; ', $state['violations'] ) );
	integration_expect( array( array(), array() ) === $state['files'], 'Tests must remove all remote test archives' );
} finally {
	if ( $server ) {
		$server->stop();
	}
	integration_remove_tree( $directory );
}
