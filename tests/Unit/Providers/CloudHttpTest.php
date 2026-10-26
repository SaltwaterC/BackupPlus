<?php
/** Generic HTTP policy and optional real HTTPS streaming-upload regression. */

namespace BackupPlus\Tests\Unit\Providers;

use PHPUnit\Framework\Attributes\Group;
use BackupPlus\Tests\Support\ProviderTestCase;
use BackupPlus_Remote_Storage_HTTP;
use Cloud_Test_HTTP;
use WP_Error;

final class CloudHttpTest extends ProviderTestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once BACKUPPLUS_DIR . '/includes/class-remote-storage-http.php';
	}

	public function test_only_trusted_https_endpoints_are_valid(): void {
		$hosts = array( 'api.example.test', '.files.example.test' );
		$this->assertTrue( BackupPlus_Remote_Storage_HTTP::valid_url( 'https://api.example.test/path?opaque=value', $hosts ), 'Exact HTTPS service endpoints must be accepted' );
		$this->assertTrue( BackupPlus_Remote_Storage_HTTP::valid_url( 'https://a.files.example.test/file', $hosts ), 'Explicit service subdomains must be accepted' );
		foreach ( array( 'http://api.example.test', 'https://api.example.test.evil.test', 'https://evil.test', 'https://user:pass@api.example.test', 'https://api.example.test:8443', 'https://api.example.test/#fragment', "https://api.example.test/\r\nHeader", 'https://api.example.test\\@evil.test' ) as $url ) {
			$this->assertFalse( BackupPlus_Remote_Storage_HTTP::valid_url( $url, $hosts ), 'Untrusted or ambiguous endpoints must be rejected: ' . $url );
		}
	}

	public function test_requests_reject_redirects_and_verify_tls_with_the_bundled_ca_by_default(): void {
		$this->http->handler = function () {
			return Cloud_Test_HTTP::response( 200, array( 'ok' => true ) );
		};
		( new BackupPlus_Remote_Storage_HTTP() )->request( 'GET', 'https://api.example.test/' );
		$args = end( $this->requests );
		$this->assertSame( 0, $args['redirection'], 'HTTP requests must reject redirects' );
		$this->assertTrue( $args['sslverify'], 'HTTP requests must verify TLS by default' );
		$this->assertSame( BACKUPPLUS_DIR . '/includes/cacert.pem', $args['sslcertificates'], 'HTTP requests must use the bundled CA by default' );
	}

	public function test_requests_honor_the_explicit_certificate_settings(): void {
		$this->http->handler = function () {
			return Cloud_Test_HTTP::response( 200, array( 'ok' => true ) );
		};
		$http                = new BackupPlus_Remote_Storage_HTTP(
			array(
				'useservercerts' => true,
				'disableverify'  => true,
			)
		);
		$http->request( 'GET', 'https://api.example.test/' );
		$args = end( $this->requests );
		$this->assertFalse( $args['sslverify'], 'HTTP requests must honor the explicit certificate settings' );
		$this->assertArrayNotHasKey( 'sslcertificates', $args, 'HTTP requests must honor the explicit certificate settings' );
	}

	public function test_disabling_ssl_fails_instead_of_downgrading(): void {
		$disabled = new BackupPlus_Remote_Storage_HTTP( array( 'nossl' => true ) );
		$this->thrown(
			function () use ( $disabled ) {
				$disabled->request( 'GET', 'https://api.example.test/' );
			},
			'Disabling SSL must fail instead of downgrading storage requests'
		);
	}

	public function test_network_errors_do_not_expose_credentials(): void {
		$this->http->handler = function () {
			return new WP_Error( 'network', 'secret-token-in-transport-error' );
		};
		$http                = new BackupPlus_Remote_Storage_HTTP();
		$error               = $this->thrown(
			function () use ( $http ) {
				$http->request( 'GET', 'https://api.example.test/' );
			},
			'Network failures must throw'
		);
		$this->assertStringNotContainsString( 'secret-token', $error->getMessage(), 'Raw transport errors must not expose credentials' );
	}

	#[Group( 'variant' )]
	public function test_streaming_uploads_fail_clearly_without_curl(): void {
		if ( function_exists( 'curl_init' ) ) {
			$this->markTestSkipped( 'cURL is available; the DisabledFunctionsTest suite runs this without it.' );
		}
		file_put_contents( $this->fixture . '/upload.bin', 'binary' );
		$http = new BackupPlus_Remote_Storage_HTTP();
		$this->thrown(
			function () use ( $http ) {
				$http->upload( 'https://example.test/', $this->fixture . '/upload.bin', array() );
			},
			'Streaming uploads must fail clearly without cURL'
		);
	}

	/** Needs the disposable TLS server that scripts/integration/test-cloud-http.php starts. */
	#[Group( 'live' )]
	public function test_real_https_streaming_upload(): void {
		$url = getenv( 'BACKUPPLUS_TEST_CLOUD_HTTPS' );
		if ( ! $url ) {
			$this->markTestSkipped( 'Run scripts/integration/test-cloud-http.php for the real streaming upload.' );
		}
		file_put_contents( $this->fixture . '/upload.bin', "binary\0\xff" . str_repeat( 'stream', 1000000 ) );
		$http     = new BackupPlus_Remote_Storage_HTTP( array( 'useservercerts' => true ) );
		$progress = array();
		$result   = $http->upload(
			$url . '/upload',
			$this->fixture . '/upload.bin',
			array( 'Content-Type' => 'application/octet-stream' ),
			function ( $sent ) use ( &$progress ) {
				$progress[] = $sent;
			}
		);
		$data     = json_decode( wp_remote_retrieve_body( $result ), true );
		$this->assertSame( 200, wp_remote_retrieve_response_code( $result ), 'Real HTTPS uploads must succeed' );
		$this->assertSame( filesize( $this->fixture . '/upload.bin' ), $data['size'], 'Real HTTPS uploads must stream every binary byte' );
		$this->assertSame( hash_file( 'sha256', $this->fixture . '/upload.bin' ), $data['sha256'], 'Real HTTPS uploads must stream every binary byte intact' );
		$this->assertSame( filesize( $this->fixture . '/upload.bin' ), max( $progress ), 'Real streaming-upload progress must reach the complete file size' );

		$result = $http->upload( $url . '/redirect', $this->fixture . '/upload.bin', array() );
		$this->assertSame( 302, wp_remote_retrieve_response_code( $result ), 'Streaming uploads must return redirects without following them' );

		$bundled = new BackupPlus_Remote_Storage_HTTP();
		$this->thrown(
			function () use ( $bundled, $url ) {
				$bundled->upload( $url . '/upload', $this->fixture . '/upload.bin', array() );
			},
			'The bundled CA must reject the untrusted fixture certificate'
		);
		$mismatch = str_replace( 'localhost', '127.0.0.1', $url );
		$this->thrown(
			function () use ( $http, $mismatch ) {
				$http->upload( $mismatch . '/upload', $this->fixture . '/upload.bin', array() );
			},
			'Streaming uploads must reject certificate hostname mismatches'
		);
		$this->thrown(
			function () use ( $http, $url ) {
				$http->upload( $url . '/oversized', $this->fixture . '/upload.bin', array() );
			},
			'Streaming uploads must bound response bodies'
		);
	}
}
