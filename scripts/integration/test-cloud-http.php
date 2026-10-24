<?php
/** Exercise the generic streaming HTTP transport against a disposable TLS server. */
require __DIR__ . '/lib/support.php';

$directory = integration_temp_dir( 'backupplus-cloud-http-' );
$server    = null;
try {
	$authority = integration_issue_certificate( $directory, 'test-ca' );
	$localhost = integration_issue_certificate( $directory, 'localhost-server', $authority );
	$server    = Integration_Server::start( __DIR__ . '/lib/server-http.php', array( 'cloud', $localhost['path'], $localhost['key_path'] ) );
	$result    = integration_run(
		array( PHP_BINARY, '-d', 'curl.cainfo=' . $authority['path'], dirname( __DIR__ ) . '/test-cloud-http.php' ),
		'',
		array_merge( getenv(), array( 'BACKUPPLUS_TEST_CLOUD_HTTPS' => 'https://localhost:' . $server->info['ports']['tls'] ) )
	);
	echo $result['stdout'], $result['stderr'];
	integration_expect( 0 === $result['status'], 'The cloud HTTP checks failed' );
	integration_expect( array() === $server->state()['violations'], 'The HTTP server saw a protocol violation' );
} finally {
	if ( $server ) {
		$server->stop();
	}
	integration_remove_tree( $directory );
}
