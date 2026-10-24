<?php
/** Check the PHP implementation against the independent age executable. */
require __DIR__ . '/lib/support.php';

$root    = dirname( __DIR__, 2 );
$fixture = json_decode( file_get_contents( $root . '/scripts/fixtures/age-keys.json' ), true );

function age_php( $request ) {
	global $root;
	$command = array( PHP_BINARY, '-d', 'error_reporting=24575' );
	if ( ! empty( $request['portable'] ) ) {
		$command[] = '-d';
		$command[] = 'disable_functions=openssl_encrypt,openssl_decrypt,sodium_crypto_aead_chacha20poly1305_ietf_encrypt,sodium_crypto_aead_chacha20poly1305_ietf_decrypt,sodium_crypto_scalarmult,sodium_crypto_box_publickey_from_secretkey';
	}
	$command[] = $root . '/scripts/fixtures/age-interop.php';
	$result    = integration_run( $command, json_encode( $request ), null, 120 );
	integration_expect( 0 === $result['status'], 'PHP interoperability check failed: ' . $result['stderr'] );
	return $result['stdout'];
}

function age_run( $arguments, $message ) {
	$result = integration_run( array_merge( array( 'age' ), $arguments ), '', null, 30 );
	integration_expect( 0 === $result['status'], $message . ': ' . $result['stderr'] );
	return $result['stdout'];
}

integration_expect( integration_find_executable( 'age' ), 'age is required for the interoperability tests.' );
$folder = integration_temp_dir( 'backupplus-age-reference-' );
try {
	$identities = array();
	foreach ( $fixture['keys'] as $name => $key ) {
		$path = $folder . '/' . $name . '.key';
		file_put_contents(
			$path,
			'native' === $name ? $key['identity'] : age_php(
				array(
					'operation'  => 'identity',
					'identity'   => $key['identity'],
					'passphrase' => $fixture['passphrase'],
				)
			)
		);
		chmod( $path, 0600 );
		$identities[ $name ] = $path;
	}
	$source      = $folder . '/source.sql';
	$php_archive = $folder . '/php.age';
	$cli_archive = $folder . '/cli.age';
	$restored    = $folder . '/restored.sql';
	foreach ( array( 0, 1, 65536, 65537, 3 * 65536 + 17 ) as $size ) {
		$plain = substr( str_repeat( implode( '', array_map( 'chr', range( 0, 255 ) ) ), (int) ceil( $size / 256 ) ), 0, $size );
		file_put_contents( $source, $plain );
		foreach ( $fixture['keys'] as $name => $key ) {
			age_php(
				array(
					'operation'  => 'encrypt',
					'input'      => $source,
					'output'     => $php_archive,
					'recipients' => array( $key['recipient'] ),
				)
			);
			integration_expect( age_run( array( '-d', '-i', $identities[ $name ], $php_archive ), "PHP -> age ($name, $size)" ) === $plain, "PHP -> age ($name, $size)" );
			age_run( array( '-r', $key['recipient'], '-o', $cli_archive, $source ), "age encryption ($name, $size)" );
			foreach ( array( $key['identity'], $key['encrypted_pem'] ) as $identity ) {
				age_php(
					array(
						'operation'  => 'decrypt',
						'input'      => $cli_archive,
						'output'     => $restored,
						'identity'   => $identity,
						'passphrase' => $fixture['passphrase'],
					)
				);
				integration_expect( file_get_contents( $restored ) === $plain, "age -> PHP ($name, $size)" );
			}
		}
		echo 'PASS: bidirectional age interoperability for all recipients at ', $size, " bytes\n";
	}
	$recipients = array_column( $fixture['keys'], 'recipient' );
	age_php(
		array(
			'operation'  => 'encrypt',
			'input'      => $source,
			'output'     => $php_archive,
			'recipients' => $recipients,
			'portable'   => true,
		)
	);
	foreach ( $fixture['keys'] as $name => $key ) {
		integration_expect( age_run( array( '-d', '-i', $identities[ $name ], $php_archive ), "portable multi-recipient ($name)" ) === $plain, "portable multi-recipient ($name)" );
		age_php(
			array(
				'operation'  => 'decrypt',
				'input'      => $php_archive,
				'output'     => $restored,
				'identity'   => $key['encrypted_pem'],
				'passphrase' => $fixture['passphrase'],
				'portable'   => true,
			)
		);
		integration_expect( file_get_contents( $restored ) === $plain, "portable restoration ($name)" );
	}
	echo "PASS: portable PHP multi-recipient output and encrypted PEM restoration without OpenSSL/sodium encryption\n";
} finally {
	integration_remove_tree( $folder );
}
