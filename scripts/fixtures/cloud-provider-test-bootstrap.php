<?php
require __DIR__ . '/provider-test-bootstrap.php';
require BACKUPPLUS_DIR . '/includes/class-storage-methods-interface.php';

function wp_tempnam( $name ) {
	return tempnam( ABSPATH, 'cloud-test-' ); }
function wp_kses_post( $html ) {
	return $html; }
function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $response ) {
	return $response['body'] ?? ''; }
function wp_remote_retrieve_header( $response, $name ) {
	$headers = array_change_key_case( $response['headers'] ?? array(), CASE_LOWER );
	return $headers[ strtolower( $name ) ] ?? '';
}
function wp_remote_request( $url, $args ) {
	$GLOBALS['cloud_request_args'][] = $args;
	return $GLOBALS['cloud_http']->request( $args['method'], $url, $args['headers'], $args['body'], $args['filename'] ?? null );
}
function get_current_user_id() {
	return $GLOBALS['cloud_user'] ?? 1; }
function do_action( $name, ...$args ) {
	foreach ( $GLOBALS['provider_filters'][ $name ] ?? array() as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$callback( ...$args );
		}
	}
}

/** Fake only the network boundary; clients still build and interpret wire messages. */
class Cloud_Test_HTTP {
	public $handler;
	public $calls    = array();
	public $fail     = false;
	public $truncate = false;
	public function request( $method, $url, $headers = array(), $body = null, $destination = null ) {
		$this->calls[] = compact( 'method', 'url', 'headers', 'body', 'destination' );
		if ( $this->fail ) {
			return self::response( 503, array( 'error' => 'unavailable' ) );
		}
		$response = ( $this->handler )( $method, $url, array_change_key_case( $headers, CASE_LOWER ), $body );
		if ( null !== $destination ) {
			file_put_contents( $destination, $this->truncate ? substr( $response['body'], 0, max( 0, strlen( $response['body'] ) - 1 ) ) : $response['body'] );
			$response['body'] = '';
		}
		return $response;
	}
	public function upload( $url, $source, $headers, $progress = null ) {
		$response = $this->request( 'PUT', $url, $headers, file_get_contents( $source ) );
		if ( $progress ) {
			$progress( filesize( $source ) );
		}
		return $response;
	}
	public static function response( $code, $data = '', $headers = array() ) {
		return array(
			'response' => array( 'code' => $code ),
			'headers'  => $headers,
			'body'     => is_array( $data ) ? json_encode( $data ) : $data,
		);
	}
}
function cloud_throws( $callback, $message ) {
	try {
		$callback();
	} catch ( RuntimeException $error ) {
		return $error; }
	throw new RuntimeException( $message );
}

/** Shared API-contract assertions, equally applicable to any file storage provider. */
function cloud_module_checks( $id, $client, $options, $http, $provider = null ) {
	global $backupplus, $fixture;
	$provider = $provider ?: provider_create( $id );
	$provider->set_options( $options );
	$provider->set_storage( $client );
	$name     = 'backup_测试 +#%25.zip';
	$contents = "archive\0\xff\n" . str_repeat( 'data', 4096 );
	file_put_contents( $fixture . '/' . $name, $contents );
	$backupplus->uploaded = array();
	provider_expect( true === $provider->backup( array( 'plugins' => $name ) ) && array( $name ) === $backupplus->uploaded, "$id must upload binary archives and mark completion" );
	provider_expect( in_array( 100, $backupplus->progress ) || in_array( 100.0, $backupplus->progress, true ), "$id must report upload progress" );
	provider_expect(
		in_array(
			array(
				'name' => $name,
				'size' => strlen( $contents ),
			),
			$provider->listfiles(),
			true
		),
		"$id must list names and integer sizes"
	);
	provider_expect( array() === $provider->listfiles( 'unmatched' ), "$id must honor listing matches" );
	file_put_contents( $fixture . '/' . $name, 'previous archive' );
	provider_expect( true === $provider->download( $name ) && $contents === file_get_contents( $fixture . '/' . $name ), "$id must restore the exact binary archive" );
	file_put_contents( $fixture . '/' . $name, 'keep this archive' );
	$http->truncate = true;
	provider_expect( false === $provider->download( $name ) && 'keep this archive' === file_get_contents( $fixture . '/' . $name ), "$id must preserve the local archive on truncated downloads" );
	$http->truncate = false;
	provider_expect( false === $provider->download( 'backup_missing.zip' ), "$id must fail missing downloads" );
	$empty = 'backup_empty.zip';
	file_put_contents( $fixture . '/' . $empty, '' );
	provider_expect( true === $provider->backup( array( $empty ) ) && true === $provider->download( $empty ) && '' === file_get_contents( $fixture . '/' . $empty ), "$id must round-trip empty archives" );
	foreach ( array( '../escape', '/absolute', 'nested/file', 'nested\\file', "bad\0name", "bad\nname" ) as $invalid ) {
		provider_expect( false === $provider->backup( array( $invalid ) ) && false === $provider->download( $invalid ) && false === $provider->delete( $invalid ), "$id operations must reject unsafe filenames" );
	}
	$http->fail           = true;
	$backupplus->uploaded = array();
	provider_expect( false === $provider->backup( array( $name ) ) && array() === $backupplus->uploaded, "$id must not mark failed uploads complete" );
	provider_expect( is_wp_error( $provider->listfiles() ) && false === $provider->download( $name ) && false === $provider->delete( $name ), "$id must propagate service failures" );
	$http->fail = false;
	provider_expect( true === $provider->delete( array( $name, $empty ) ) && true === $provider->delete( $name ), "$id must delete arrays, strings and already missing files" );
	provider_expect( array() === glob( $fixture . '/.backupplus-download-*' ), "$id must clean temporary downloads" );
	$template = $provider->get_configuration_template();
	foreach ( array_keys( $provider->get_default_options() ) as $field ) {
		provider_expect( false !== strpos( $template, 'data-backupplus_settings_test="' . $field . '"' ), "$id must include $field in unsaved connection tests" );
	}
	provider_expect( ! empty( $provider->get_template_properties()['input_test_label'] ), "$id must label its connection test" );
	$before = $provider->get_options();
	ob_start();
	$provider->credentials_test( $options );
	$output = ob_get_clean();
	provider_expect( 0 === strpos( $output, 'Success:' ) && $provider->is_connection_successful(), "$id credential roundtrip must succeed: $output" );
	provider_expect( $before === $provider->get_options(), "$id credential tests must preserve saved instance settings" );
	ob_start();
	$provider->credentials_test( array() );
	$output = ob_get_clean();
	provider_expect( 0 === strpos( $output, 'Failure:' ) && ! $provider->is_connection_successful(), "$id credential failures must reset connection status" );
	$provider->set_options( array_merge( $options, array( 'path' => 'another-site' ) ) );
	provider_expect( empty( $provider->get_storage() ), "$id must discard cached clients when changing instances" );
	foreach ( get_included_files() as $file ) {
		if ( 0 !== strpos( $file, BACKUPPLUS_DIR . '/methods/' ) ) {
			continue;
		}
		$relative = substr( $file, strlen( BACKUPPLUS_DIR . '/methods/' ) );
		provider_expect( 'backup-module.php' === $relative || $id . '.php' === $relative || 0 === strpos( $relative, $id . '/' ), "$id must not load another provider: $relative" );
	}
	echo "PASS: $id factory, configuration, binary/empty transfers, atomic restores, deletion, failures, credentials and isolation\n";
}
$cloud_http = new Cloud_Test_HTTP();
