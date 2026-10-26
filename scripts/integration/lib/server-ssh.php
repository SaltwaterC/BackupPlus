<?php
/**
 * Disposable loopback SSH server for the SFTP/SCP storage tests.
 *
 * Usage: php server-ssh.php '{"root": "/login", "keys": ["/client.pub"], "servers": {"sftp": {"shell": false, "host": "rsa"}}}'
 * A "shell" server runs exec requests with sh in the root directory and rejects the sftp
 * subsystem; the other servers speak SFTP version 3 and reject exec. The password is
 * " test password " for the user "test"; only the listed public keys are accepted. Host
 * keys are generated for the run. This is not a sandbox: only use it with the test client.
 *
 * Cryptography is deliberately independent of phpseclib: curve25519-sha256 key exchange
 * (sodium), aes128-ctr/aes256-ctr (OpenSSL) and hmac-sha2-256, with RSA or Ed25519 host keys.
 */
require_once __DIR__ . '/event-loop.php';
require_once __DIR__ . '/ssh/protocol.php';
require_once __DIR__ . '/ssh/sftp.php';
require_once __DIR__ . '/ssh/session.php';

$config          = json_decode( $argv[1], true );
$loop            = new Integration_Loop();
$root            = realpath( $config['root'] );
$hosts           = array(
	'rsa'     => Ssh_Host_Key::rsa(),
	'ed25519' => Ssh_Host_Key::ed25519(),
);
$state           = new stdClass();
$state->attempts = array();
$state->failures = array();
$keys            = array();
foreach ( $config['keys'] as $path ) {
	$fields = explode( ' ', trim( file_get_contents( $path ) ) );
	$keys[] = base64_decode( $fields[1] );
}
$ports = array();
foreach ( $config['servers'] as $name => $server ) {
	$listener = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $error );
	if ( ! $listener ) {
		fwrite( STDERR, "Unable to listen: $error\n" );
		exit( 1 );
	}
	$ports[ $name ] = (int) substr( strrchr( stream_socket_get_name( $listener, false ), ':' ), 1 );
	$settings       = array(
		'root'  => $root,
		'keys'  => $keys,
		'shell' => $server['shell'],
		'host'  => $hosts[ $server['host'] ],
		'state' => $state,
	);
	$loop->on_read(
		$listener,
		function ( $listener ) use ( $loop, $settings ) {
			$client = @stream_socket_accept( $listener, 0 );
			if ( $client ) {
				new Ssh_Session( $loop, $client, $settings );
			}
		}
	);
}
$loop->run(
	array(
		'ports'        => $ports,
		'fingerprints' => array(
			'rsa'     => $hosts['rsa']->fingerprint,
			'ed25519' => $hosts['ed25519']->fingerprint,
		),
	),
	function () use ( $state ) {
		return array(
			'attempts' => $state->attempts,
			'failures' => $state->failures,
		);
	}
);
