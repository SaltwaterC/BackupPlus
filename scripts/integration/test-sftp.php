<?php
/**
 * Exercise SSH storage against disposable loopback SSH servers.
 *
 * Requires ssh-keygen to create the client keys. The SFTP server rejects exec; the SCP
 * server rejects SFTP and runs commands in a temporary login directory. All keys and
 * credentials are disposable. Run only this test's generated client against these
 * fixtures: the command server is not a shell sandbox.
 */
require __DIR__ . '/lib/support.php';

function ssh_keygen( $arguments ) {
	$result = integration_run( array_merge( array( 'ssh-keygen', '-q' ), $arguments ) );
	integration_expect( 0 === $result['status'], 'ssh-keygen failed: ' . $result['stderr'] );
}

/** Copy a private key and change its passphrase. */
function ssh_encrypted_copy( $source, $target, $arguments ) {
	copy( $source, $target );
	chmod( $target, 0600 );
	ssh_keygen( array_merge( array( '-p', '-P', '', '-N', 'key secret', '-f', $target ), $arguments ) );
}

$root   = integration_temp_dir( 'backupplus-ssh-' );
$server = null;
try {
	$login = $root . '/login';
	mkdir( $login );
	mkdir( $login . "/archives ' #$" );
	mkdir( $login . '/backup_directory' );
	mkdir( $login . '/read-only', 0500 );
	file_put_contents( $login . '/outside', 'protected' );
	symlink( $login . '/outside', $login . '/backup_link' );
	// Traditional PEM RSA keys and OpenSSH-format Ed25519 keys, plain and encrypted.
	ssh_keygen( array( '-t', 'rsa', '-b', '2048', '-m', 'PEM', '-N', '', '-f', $root . '/client-plain.pem' ) );
	ssh_encrypted_copy( $root . '/client-plain.pem', $root . '/client.pem', array( '-m', 'PEM' ) );
	ssh_keygen( array( '-t', 'ed25519', '-N', '', '-f', $root . '/client-ed25519' ) );
	ssh_encrypted_copy( $root . '/client-ed25519', $root . '/client-ed25519-encrypted', array() );
	$keys   = array(
		array(
			'name'       => 'RSA PEM',
			'path'       => $root . '/client-plain.pem',
			'passphrase' => '',
		),
		array(
			'name'       => 'encrypted RSA PEM',
			'path'       => $root . '/client.pem',
			'passphrase' => 'key secret',
		),
		array(
			'name'       => 'Ed25519 OpenSSH',
			'path'       => $root . '/client-ed25519',
			'passphrase' => '',
		),
		array(
			'name'       => 'encrypted Ed25519 OpenSSH',
			'path'       => $root . '/client-ed25519-encrypted',
			'passphrase' => 'key secret',
		),
	);
	$server = Integration_Server::start(
		__DIR__ . '/lib/server-ssh.php',
		array(
			json_encode(
				array(
					'root'    => $login,
					'keys'    => array( $root . '/client-plain.pem.pub', $root . '/client-ed25519.pub' ),
					'servers' => array(
						'sftp'         => array(
							'shell' => false,
							'host'  => 'rsa',
						),
						'scp'          => array(
							'shell' => true,
							'host'  => 'rsa',
						),
						'sftp_ed25519' => array(
							'shell' => false,
							'host'  => 'ed25519',
						),
						'scp_ed25519'  => array(
							'shell' => true,
							'host'  => 'ed25519',
						),
					),
				)
			),
		)
	);
	$ports  = $server->info['ports'];
	$result = integration_run(
		array( PHP_BINARY, '-d', 'error_reporting=24575', dirname( __DIR__ ) . '/test-sftp.php' ),
		'',
		array_merge(
			getenv(),
			array(
				'BACKUPPLUS_TEST_SFTP_PORT'               => (string) $ports['sftp'],
				'BACKUPPLUS_TEST_SCP_PORT'                => (string) $ports['scp'],
				'BACKUPPLUS_TEST_SSH_ROOT'                => $root,
				'BACKUPPLUS_TEST_SSH_KEYS'                => json_encode( $keys ),
				'BACKUPPLUS_TEST_SSH_PUBLIC_KEY'          => $root . '/client-ed25519.pub',
				'BACKUPPLUS_TEST_SFTP_ED25519_PORT'       => (string) $ports['sftp_ed25519'],
				'BACKUPPLUS_TEST_SCP_ED25519_PORT'        => (string) $ports['scp_ed25519'],
				'BACKUPPLUS_TEST_SSH_ED25519_FINGERPRINT' => $server->info['fingerprints']['ed25519'],
				'BACKUPPLUS_TEST_SSH_FINGERPRINT'         => $server->info['fingerprints']['rsa'],
			)
		),
		240
	);
	echo $result['stdout'], $result['stderr'];
	integration_expect( 0 === $result['status'], 'The SSH storage checks failed' );
	$state = $server->state();
	integration_expect( array() === $state['failures'], 'The SSH server rejected a client: ' . implode( '; ', $state['failures'] ) );
	$leftovers = array();
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $login, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
		$leftovers[] = $file->getFilename();
	}
	integration_expect( ! in_array( 'INJECTED', $leftovers, true ), 'A filename executed a shell command' );
	integration_expect( 'protected' === file_get_contents( $login . '/outside' ), 'A transfer followed a symlink' );
	integration_expect( ! preg_grep( '/^backupplus-test-/', $leftovers ), 'Credentials tests left remote files' );
	foreach ( $state['attempts'] as $attempt ) {
		integration_expect( 'password' !== $attempt[0] || 'UNVERIFIED' !== $attempt[1], 'Host verification sent credentials before verification' );
	}
	echo "PASS: SCP without SFTP, SFTP without exec, shell escaping, symlink protection and pre-authentication host verification\n";
} finally {
	if ( $server ) {
		$server->stop();
	}
	integration_remove_tree( $root );
}
