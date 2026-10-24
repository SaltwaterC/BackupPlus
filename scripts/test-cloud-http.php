<?php
/** Generic HTTP policy and optional real HTTPS streaming-upload regression. */
require __DIR__ . '/fixtures/cloud-provider-test-bootstrap.php';
require BACKUPPLUS_DIR . '/includes/class-remote-storage-http.php';
$hosts = array( 'api.example.test', '.files.example.test' );
provider_expect( BackupPlus_Remote_Storage_HTTP::valid_url( 'https://api.example.test/path?opaque=value', $hosts ), 'Exact HTTPS service endpoints must be accepted' );
provider_expect( BackupPlus_Remote_Storage_HTTP::valid_url( 'https://a.files.example.test/file', $hosts ), 'Explicit service subdomains must be accepted' );
foreach ( array( 'http://api.example.test', 'https://api.example.test.evil.test', 'https://evil.test', 'https://user:pass@api.example.test', 'https://api.example.test:8443', 'https://api.example.test/#fragment', "https://api.example.test/\r\nHeader", 'https://api.example.test\\@evil.test' ) as $url ) {
	provider_expect( ! BackupPlus_Remote_Storage_HTTP::valid_url( $url, $hosts ), 'Untrusted or ambiguous endpoints must be rejected' );
}
$cloud_http->handler = function () {
	return Cloud_Test_HTTP::response( 200, array( 'ok' => true ) );
};
$http                = new BackupPlus_Remote_Storage_HTTP();
$http->request( 'GET', 'https://api.example.test/' );
$args = end( $cloud_request_args );
provider_expect( 0 === $args['redirection'] && true === $args['sslverify'] && BACKUPPLUS_DIR . '/includes/cacert.pem' === $args['sslcertificates'], 'HTTP requests must reject redirects and verify TLS with the bundled CA by default' );
$http = new BackupPlus_Remote_Storage_HTTP(
	array(
		'useservercerts' => true,
		'disableverify'  => true,
	)
);
$http->request( 'GET', 'https://api.example.test/' );
$args = end( $cloud_request_args );
provider_expect( false === $args['sslverify'] && ! isset( $args['sslcertificates'] ), 'HTTP requests must honor the explicit certificate settings' );
$disabled = new BackupPlus_Remote_Storage_HTTP( array( 'nossl' => true ) );
cloud_throws(
	function () use ( $disabled ) {
		$disabled->request( 'GET', 'https://api.example.test/' );
	},
	'Disabling SSL must fail instead of downgrading storage requests'
);
$cloud_http->handler = function () {
	return new WP_Error( 'network', 'secret-token-in-transport-error' );
};
$error               = cloud_throws(
	function () use ( $http ) {
		$http->request( 'GET', 'https://api.example.test/' );
	},
	'Network failures must throw'
);
provider_expect( false === strpos( $error->getMessage(), 'secret-token' ), 'Raw transport errors must not expose credentials' );
file_put_contents( $fixture . '/upload.bin', "binary\0\xff" . str_repeat( 'stream', 1000000 ) );
if ( ! function_exists( 'curl_init' ) ) {
	cloud_throws(
		function () use ( $http, $fixture ) {
			$http->upload( 'https://example.test/', $fixture . '/upload.bin', array() );
		},
		'Streaming uploads must fail clearly without cURL'
	);
	echo "PASS: generic endpoint/TLS policy, error redaction and missing-cURL handling\n";
	exit;
}
echo "PASS: generic endpoint/TLS policy, redirect refusal and error redaction\n";
$url = getenv( 'BACKUPPLUS_TEST_CLOUD_HTTPS' );
if ( ! $url ) {
	echo "SKIP: real streaming upload (run scripts/integration/test-cloud-http.php)\n";
	exit; }
$http     = new BackupPlus_Remote_Storage_HTTP( array( 'useservercerts' => true ) );
$progress = array();
$result   = $http->upload(
	$url . '/upload',
	$fixture . '/upload.bin',
	array( 'Content-Type' => 'application/octet-stream' ),
	function ( $sent ) use ( &$progress ) {
		$progress[] = $sent;
	}
);
$data     = json_decode( wp_remote_retrieve_body( $result ), true );
provider_expect( 200 === wp_remote_retrieve_response_code( $result ) && filesize( $fixture . '/upload.bin' ) === $data['size'] && hash_file( 'sha256', $fixture . '/upload.bin' ) === $data['sha256'], 'Real HTTPS uploads must stream every binary byte intact' );
provider_expect( max( $progress ) === filesize( $fixture . '/upload.bin' ), 'Real streaming-upload progress must reach the complete file size' );
$result = $http->upload( $url . '/redirect', $fixture . '/upload.bin', array() );
provider_expect( 302 === wp_remote_retrieve_response_code( $result ), 'Streaming uploads must return redirects without following them' );
$bundled = new BackupPlus_Remote_Storage_HTTP();
cloud_throws(
	function () use ( $bundled, $url, $fixture ) {
		$bundled->upload( $url . '/upload', $fixture . '/upload.bin', array() );
	},
	'The bundled CA must reject the untrusted fixture certificate'
);
$mismatch = str_replace( 'localhost', '127.0.0.1', $url );
cloud_throws(
	function () use ( $http, $mismatch, $fixture ) {
		$http->upload( $mismatch . '/upload', $fixture . '/upload.bin', array() );
	},
	'Streaming uploads must reject certificate hostname mismatches'
);
cloud_throws(
	function () use ( $http, $url, $fixture ) {
		$http->upload( $url . '/oversized', $fixture . '/upload.bin', array() );
	},
	'Streaming uploads must bound response bodies'
);
echo "PASS: real HTTPS binary streaming, progress, redirect refusal, certificate trust/hostname checks and response bounds\n";
