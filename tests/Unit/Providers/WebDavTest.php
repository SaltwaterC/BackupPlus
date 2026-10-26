<?php
/**
 * WebDAV provider: settings, endpoint and filename validation and, when the disposable servers of
 * scripts/integration/test-webdav.php run, live transfers over loopback HTTP (Digest) and HTTPS (Basic).
 *
 * The offline tests whose names end in _with_curl need the cURL functions; the others also run without them.
 */

namespace BackupPlus\Tests\Unit\Providers;

use PHPUnit\Framework\Attributes\Group;
use BackupPlus_BackupModule;
use BackupPlus_Options;
use BackupPlus_Storage_Provider_Factory;
use BackupPlus_WebDAV_Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WebDav_Test_Job;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class WebDavTest extends TestCase {

	private string $fixture = '';

	private WebDav_Test_Job $job;

	/** The provider as the factory builds it. */
	private $provider;

	protected function setUp(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/webdav-stubs.php';
		$this->fixture = rtrim( ABSPATH, '/' );

		BackupPlus_Options::$options = array(
			'backupplus_webdav_settings' => array(
				'host'   => 'old.example.test',
				'webdav' => 'webdavs://',
				'pass'   => ' secret ',
			),
		);
		$this->job                   = new WebDav_Test_Job();
		$GLOBALS['backupplus']       = $this->job;
		$this->provider              = BackupPlus_Storage_Provider_Factory::create( 'webdav' );
		backupplus_try_include_file( 'methods/webdav/client.php', 'require_once' );
	}

	private function credentials( array $options ): string {
		ob_start();
		$this->provider->credentials_test( $options );
		return ob_get_clean();
	}

	private function require_curl(): void {
		if ( ! function_exists( 'curl_init' ) ) {
			$this->markTestSkipped( 'cURL is not available; the missing cURL tests cover this.' );
		}
	}

	public function test_the_factory_builds_the_builtin_provider(): void {
		$this->assertInstanceOf( BackupPlus_BackupModule::class, $this->provider, 'Factory must construct the built-in WebDAV provider' );
		$this->assertFalse( is_a( $this->provider, 'BackupPlus_BackupModule_AddonNotYetPresent' ), 'WebDAV must be available without an add-on' );
	}

	public function test_legacy_options_migrate(): void {
		$this->assertSame( 'old.example.test', BackupPlus_Options::$options['backupplus_webdav']['host'], 'Legacy options must migrate' );
		$this->assertArrayNotHasKey( 'backupplus_webdav_settings', BackupPlus_Options::$options, 'Legacy options must migrate' );
	}

	public function test_new_connections_default_to_https(): void {
		$this->assertSame( 'https://', $this->provider->get_default_options()['webdav'], 'New connections must default to HTTPS' );
	}

	private function normalised_settings(): array {
		return $this->provider->options_filter(
			array(
				'version'  => 1,
				'settings' => array(
					'old'   => BackupPlus_Options::$options['backupplus_webdav'],
					'url'   => array( 'url' => 'webdav://user%40example.test:p%40ss%20word@example.test:8080/dav/my%20backups/' ),
					'other' => array(
						'host'             => 'other.example.test',
						'instance_enabled' => 0,
						'instance_label'   => 'Other',
					),
				),
			)
		);
	}

	public function test_normalisation_preserves_password_whitespace_and_secures_legacy_schemes(): void {
		$settings = $this->normalised_settings();
		$this->assertSame( 'https://', $settings['settings']['old']['webdav'], 'Normalisation must preserve password whitespace and secure legacy schemes' );
		$this->assertSame( ' secret ', $settings['settings']['old']['pass'], 'Normalisation must preserve password whitespace and secure legacy schemes' );
	}

	public function test_legacy_url_settings_decode_exactly_once(): void {
		$url_options = $this->normalised_settings()['settings']['url'];
		$this->assertSame( 'http://', $url_options['webdav'], 'Legacy URL settings must decode exactly once' );
		$this->assertSame( '8080', $url_options['port'], 'Legacy URL settings must decode exactly once' );
		$this->assertSame( '/dav/my backups/', $url_options['path'], 'Legacy URL settings must decode exactly once' );
		$this->assertSame( 'user@example.test', $url_options['user'], 'Legacy URL settings must decode exactly once' );
		$this->assertSame( 'p@ss word', $url_options['pass'], 'Legacy URL settings must decode exactly once' );
	}

	public function test_instance_metadata_survives_sanitisation(): void {
		$this->assertSame( 0, $this->normalised_settings()['settings']['other']['instance_enabled'], 'Instance metadata must survive sanitisation' );
	}

	public function test_the_configured_state_uses_the_endpoint(): void {
		$url_options = $this->normalised_settings()['settings']['url'];
		$this->assertFalse( $this->provider->options_exist( array() ), 'Configured state must use the endpoint' );
		$this->assertTrue( $this->provider->options_exist( $url_options ), 'Configured state must use the endpoint' );
	}

	public function test_the_test_button_has_its_label_before_any_interaction(): void {
		$this->assertSame( 'Test WebDAV Settings', $this->provider->get_template_properties()['input_test_label'], 'The test button must have its label before any interaction' );
	}

	public function test_settings_include_a_masked_password_and_a_protocol_selector(): void {
		$fields = $this->provider->transform_template_properties_to_fields_structure();
		$this->assertSame( 'password', $fields[4]['type'], 'Settings must include a masked password and protocol selector' );
		$this->assertSame( 'dropdown', $fields[0]['type'], 'Settings must include a masked password and protocol selector' );
	}

	public function test_every_setting_is_included_in_unsaved_tests(): void {
		$template = $this->provider->get_configuration_template();
		foreach ( array_keys( $this->provider->get_default_options() ) as $field ) {
			$this->assertStringContainsString( 'data-backupplus_settings_test="' . $field . '"', $template, 'Each setting must be included in unsaved tests' );
		}
	}

	#[Group( 'variant' )]
	public function test_missing_curl_fails_the_operations_gracefully(): void {
		if ( function_exists( 'curl_init' ) ) {
			$this->markTestSkipped( 'cURL is available; the no curl variant of the suite runs this without it.' );
		}
		$this->provider->set_options( array( 'host' => 'example.test' ) );
		$this->assertTrue( is_wp_error( $this->provider->listfiles() ), 'Missing cURL must return a storage error' );
		$this->assertFalse( $this->provider->backup( array( 'file' ) ), 'Missing cURL must fail operations gracefully' );
		$this->assertFalse( $this->provider->download( 'file' ), 'Missing cURL must fail operations gracefully' );
		$this->assertFalse( $this->provider->delete( 'file' ), 'Missing cURL must fail operations gracefully' );
		$this->assertStringStartsWith( 'Failure:', $this->credentials( array( 'host' => 'example.test' ) ), 'Missing cURL must fail the credentials test' );
	}

	public static function invalid_connection_settings(): array {
		return array(
			'no host'               => array( array() ),
			'host with a scheme'    => array( array( 'host' => 'https://example.test/dav' ) ),
			'host with credentials' => array( array( 'host' => 'user:pass@example.test' ) ),
			'unsupported protocol'  => array(
				array(
					'host'   => 'example.test',
					'webdav' => 'ftp://',
				),
			),
			'port above the range'  => array(
				array(
					'host' => 'example.test',
					'port' => '65536',
				),
			),
			'negative port'         => array(
				array(
					'host' => 'example.test',
					'port' => '-1',
				),
			),
			'header injection'      => array( array( 'host' => "example.test\r\nHeader: injected" ) ),
			'path traversal'        => array(
				array(
					'host' => 'example.test',
					'path' => '../outside',
				),
			),
			'path with a NUL byte'  => array(
				array(
					'host' => 'example.test',
					'path' => "dav/\0file",
				),
			),
		);
	}

	#[DataProvider( 'invalid_connection_settings' )]
	public function test_invalid_connection_settings_are_rejected_with_curl( array $options ): void {
		$this->require_curl();
		$this->assertTrue( is_wp_error( BackupPlus_WebDAV_Client::open( $options ) ), 'Invalid connection settings must be rejected' );
	}

	public function test_https_does_not_downgrade_when_ssl_is_disabled_with_curl(): void {
		$this->require_curl();
		$this->assertTrue( is_wp_error( BackupPlus_WebDAV_Client::open( array( 'host' => 'example.test' ), array( 'nossl' => true ) ) ), 'HTTPS must not downgrade when SSL is disabled' );
	}

	public function test_ipv6_and_root_paths_are_supported_with_curl(): void {
		$this->require_curl();
		$this->assertFalse(
			is_wp_error(
				BackupPlus_WebDAV_Client::open(
					array(
						'host' => '[::1]:8080',
						'path' => '/',
					)
				)
			),
			'IPv6 and root paths must be supported'
		);
	}

	public static function invalid_filenames(): array {
		return array(
			'empty'               => array( '' ),
			'dot'                 => array( '.' ),
			'dot dot'             => array( '..' ),
			'parent traversal'    => array( '../file' ),
			'absolute'            => array( '/file' ),
			'directory'           => array( 'dir/file' ),
			'backslash directory' => array( 'dir\\file' ),
			'NUL byte'            => array( "bad\0file" ),
			'newline'             => array( "bad\nfile" ),
		);
	}

	#[DataProvider( 'invalid_filenames' )]
	public function test_traversal_and_control_characters_in_filenames_are_rejected( string $file ): void {
		$this->assertFalse( BackupPlus_WebDAV_Client::valid_filename( $file ), 'Traversal and control characters must be rejected' );
	}

	/** The disposable servers of scripts/integration/test-webdav.php, as the options of a connection. */
	private function live_options( string $scheme, string $variable ): array {
		$authority = getenv( $variable );
		if ( ! $authority ) {
			$this->markTestSkipped( 'Run scripts/integration/test-webdav.php for the live transfers.' );
		}
		return array(
			'host'   => $authority,
			'user'   => 'backup+user@example.test',
			'pass'   => ' secret:@/?#% ',
			'path'   => 'dav/my backups +#?%',
			'webdav' => $scheme,
		);
	}

	public static function live_schemes(): array {
		return array(
			'HTTP with Digest' => array( 'http://', 'BACKUPPLUS_TEST_DAV_HOST' ),
			'HTTPS with Basic' => array( 'https://', 'BACKUPPLUS_TEST_DAV_TLS_HOST' ),
		);
	}

	private function live_name(): string {
		return 'backup_测试 +#?%25&.zip';
	}

	private function live_contents(): string {
		return str_repeat( "WebDAV binary\0\xff\n", 65536 );
	}

	/** HTTPS uses the generated local CA through the PHP curl.cainfo setting. */
	private function use_the_system_ca(): void {
		$this->job->tls['backupplus_ssl_useservercerts'] = true;
	}

	private function assertNoTemporaryFiles(): void {
		$this->assertSame( array(), glob( $this->fixture . '/.webdav-*' ), 'All download and credentials temporary files must be removed' );
		$this->assertSame( array(), glob( $this->fixture . '/dav-test-*' ), 'All download and credentials temporary files must be removed' );
	}

	#[Group( 'live' )]
	#[DataProvider( 'live_schemes' )]
	public function test_live_credentials_roundtrip( string $scheme, string $variable ): void {
		$options = $this->live_options( $scheme, $variable );
		$this->use_the_system_ca();
		$this->provider->set_options( $options );
		$output = $this->credentials( array_merge( $options, array( 'useservercerts' => true ) ) );
		$this->assertStringStartsWith( 'Success:', $output, 'Credentials roundtrip failed: ' . $output );
		$this->assertTrue( $this->provider->is_connection_successful(), 'Credentials roundtrip failed: ' . $output );
	}

	#[Group( 'live' )]
	#[DataProvider( 'live_schemes' )]
	public function test_live_binary_transfer_listing_overwrite_and_deletion( string $scheme, string $variable ): void {
		$options  = $this->live_options( $scheme, $variable );
		$name     = $this->live_name();
		$contents = $this->live_contents();
		$this->use_the_system_ca();
		$this->provider->set_options( $options );
		file_put_contents( $this->fixture . '/' . $name, $contents );
		$result = $this->provider->backup( array( $name ) );
		$this->assertNotFalse( $result, 'Upload must mark completed backups' );
		$this->assertContains( $name, $this->job->uploaded, 'Upload must mark completed backups' );
		$this->assertContains( 100, $this->job->progress, 'Upload progress must reach 100 percent' );
		$files = $this->provider->listfiles();
		$this->assertSame(
			array(
				array(
					'name' => $name,
					'size' => strlen( $contents ),
				),
			),
			$files,
			'Listing must preserve encoded filenames, sizes, and ignore directories or failed properties'
		);
		$this->assertSame( array(), $this->provider->listfiles( 'unmatched' ), 'Listing match must filter files' );
		file_put_contents( $this->fixture . '/' . $name, 'local partial data' );
		$this->assertTrue( $this->provider->download( $name ), 'Download must replace partial data with the complete archive' );
		$this->assertSame( $contents, file_get_contents( $this->fixture . '/' . $name ), 'Download must replace partial data with the complete archive' );
		// Retry PUT must replace a previously uploaded object in full.
		file_put_contents( $this->fixture . '/' . $name, 'replacement' );
		$this->assertNotFalse( $this->provider->backup( array( $name ) ), 'Repeated PUT must succeed' );
		unlink( $this->fixture . '/' . $name );
		$this->assertTrue( $this->provider->download( $name ), 'Repeated PUT must replace old content' );
		$this->assertSame( 'replacement', file_get_contents( $this->fixture . '/' . $name ), 'Repeated PUT must replace old content' );
		$this->assertTrue( $this->provider->delete( $name, $result ), 'Deletion must support strings, arrays and missing files' );
		$this->assertTrue( $this->provider->delete( array( $name ) ), 'Deletion must support strings, arrays and missing files' );
	}

	#[Group( 'live' )]
	#[DataProvider( 'live_schemes' )]
	public function test_live_empty_file_roundtrip( string $scheme, string $variable ): void {
		$options = $this->live_options( $scheme, $variable );
		$empty   = 'backup_empty.zip';
		$this->use_the_system_ca();
		$this->provider->set_options( $options );
		file_put_contents( $this->fixture . '/' . $empty, '' );
		$this->assertNotFalse( $this->provider->backup( array( $empty ) ), 'Empty file roundtrip must succeed' );
		$this->assertTrue( $this->provider->download( $empty ), 'Empty file roundtrip must succeed' );
		$this->assertSame( '', file_get_contents( $this->fixture . '/' . $empty ), 'Empty file roundtrip must succeed' );
		$this->assertTrue( $this->provider->delete( $empty ), 'Empty files must be deletable' );
	}

	#[Group( 'live' )]
	#[DataProvider( 'live_schemes' )]
	public function test_live_root_collection_and_instance_isolation( string $scheme, string $variable ): void {
		$options = $this->live_options( $scheme, $variable );
		$name    = $this->live_name();
		$this->use_the_system_ca();
		file_put_contents( $this->fixture . '/' . $name, 'replacement' );
		// Root collection and a second instance must not reuse the prior path.
		$this->provider->set_options( array_merge( $options, array( 'path' => '/' ) ) );
		$this->assertNotFalse( $this->provider->backup( array( $name ) ), 'Root collection upload must succeed' );
		$this->assertCount( 1, $this->provider->listfiles(), 'Root collection listing must succeed' );
		$this->assertTrue( $this->provider->delete( $name ), 'Root collection deletion must succeed' );
		$this->provider->set_options( $options );
		$this->assertSame( array(), $this->provider->listfiles(), 'Changing instances must clear the cached client' );
	}

	#[Group( 'live' )]
	#[DataProvider( 'live_schemes' )]
	public function test_live_incorrect_credentials_fail( string $scheme, string $variable ): void {
		$options = $this->live_options( $scheme, $variable );
		$name    = $this->live_name();
		$this->use_the_system_ca();
		$this->provider->set_options( $options );
		file_put_contents( $this->fixture . '/' . $name, 'replacement' );
		$bad = array_merge(
			$options,
			array(
				'pass'           => 'wrong-password',
				'useservercerts' => true,
			)
		);
		$this->assertStringStartsWith( 'Failure:', $this->credentials( $bad ), 'Incorrect credentials must reset connection status' );
		$this->assertFalse( $this->provider->is_connection_successful(), 'Incorrect credentials must reset connection status' );
		$this->provider->set_options( $bad );
		$this->assertTrue( is_wp_error( $this->provider->listfiles() ), 'Authentication errors must fail listing and upload' );
		$this->assertFalse( $this->provider->backup( array( $name ) ), 'Authentication errors must fail listing and upload' );
	}

	public static function invalid_directory_responses(): array {
		return array(
			'malformed XML' => array( 'malformed' ),
			'empty XML'     => array( 'empty-xml' ),
			'XXE'           => array( 'xxe' ),
			'missing'       => array( 'missing' ),
			'redirect'      => array( 'redirect' ),
		);
	}

	#[Group( 'live' )]
	#[DataProvider( 'invalid_directory_responses' )]
	public function test_live_invalid_directory_responses_fail( string $path ): void {
		$options = $this->live_options( 'http://', 'BACKUPPLUS_TEST_DAV_HOST' );
		$this->provider->set_options( array_merge( $options, array( 'path' => $path ) ) );
		$this->assertTrue( is_wp_error( $this->provider->listfiles() ), 'Invalid directory response must fail: ' . $path );
		$this->assertNoTemporaryFiles();
	}

	public static function failing_downloads(): array {
		return array(
			'missing'   => array( 'backup_missing.zip' ),
			'truncated' => array( 'backup_truncated.zip' ),
			'redirect'  => array( 'backup_redirect.zip' ),
		);
	}

	#[Group( 'live' )]
	#[DataProvider( 'failing_downloads' )]
	public function test_live_failed_downloads_preserve_an_existing_archive( string $file ): void {
		$this->provider->set_options( $this->live_options( 'http://', 'BACKUPPLUS_TEST_DAV_HOST' ) );
		file_put_contents( $this->fixture . '/' . $file, 'keep existing archive' );
		$this->assertFalse( $this->provider->download( $file ), 'Failed download must preserve an existing archive: ' . $file );
		$this->assertSame( 'keep existing archive', file_get_contents( $this->fixture . '/' . $file ), 'Failed download must preserve an existing archive: ' . $file );
		$this->assertNoTemporaryFiles();
	}

	public static function operation_filenames(): array {
		return array(
			'empty'            => array( '' ),
			'dot'              => array( '.' ),
			'dot dot'          => array( '..' ),
			'parent traversal' => array( '../outside' ),
			'folder'           => array( 'folder/file' ),
			'newline'          => array( "bad\nfile" ),
		);
	}

	#[Group( 'live' )]
	#[DataProvider( 'operation_filenames' )]
	public function test_live_operations_reject_invalid_filenames( string $file ): void {
		$this->provider->set_options( $this->live_options( 'http://', 'BACKUPPLUS_TEST_DAV_HOST' ) );
		$this->assertFalse( $this->provider->download( $file ), 'Operations must reject invalid filenames' );
		$this->assertFalse( $this->provider->backup( array( $file ) ), 'Operations must reject invalid filenames' );
		$this->assertFalse( $this->provider->delete( $file ), 'Operations must reject invalid filenames' );
		$this->assertNoTemporaryFiles();
	}

	#[Group( 'live' )]
	public function test_live_missing_local_files_fail_the_upload(): void {
		$this->provider->set_options( $this->live_options( 'http://', 'BACKUPPLUS_TEST_DAV_HOST' ) );
		$this->assertFalse( $this->provider->backup( array( 'nonexistent.zip' ) ), 'Missing local files must fail upload' );
		$this->assertNoTemporaryFiles();
	}

	#[Group( 'live' )]
	public function test_live_http_permission_errors_fail_the_deletion(): void {
		$this->provider->set_options( $this->live_options( 'http://', 'BACKUPPLUS_TEST_DAV_HOST' ) );
		$this->assertFalse( $this->provider->delete( 'backup_forbidden.zip' ), 'HTTP permission errors must fail deletion' );
		$this->assertNoTemporaryFiles();
	}

	private function tls_options(): array {
		return $this->live_options( 'https://', 'BACKUPPLUS_TEST_DAV_TLS_HOST' );
	}

	#[Group( 'live' )]
	public function test_live_the_bundled_ca_rejects_an_untrusted_local_certificate(): void {
		$this->provider->set_options( $this->tls_options() );
		$this->assertTrue( is_wp_error( $this->provider->listfiles() ), 'The bundled CA must reject an untrusted local certificate' );
	}

	#[Group( 'live' )]
	public function test_live_the_system_ca_choice_honors_curl_cainfo(): void {
		$tls_options = $this->tls_options();
		$this->provider->set_options( $tls_options );
		$output = $this->credentials( array_merge( $tls_options, array( 'useservercerts' => true ) ) );
		$this->assertStringStartsWith( 'Success:', $output, 'System CA choice must honor curl.cainfo: ' . $output );
	}

	#[Group( 'live' )]
	public function test_live_tls_hostname_mismatches_are_rejected_unless_overridden(): void {
		$tls_options = $this->tls_options();
		$this->provider->set_options( $tls_options );
		$mismatch = array_merge(
			$tls_options,
			array(
				'host'           => str_replace( 'localhost', '127.0.0.1', $tls_options['host'] ),
				'useservercerts' => true,
			)
		);
		$this->assertStringStartsWith( 'Failure:', $this->credentials( $mismatch ), 'TLS hostname mismatches must be rejected' );
		$this->assertStringStartsWith( 'Success:', $this->credentials( array_merge( $mismatch, array( 'disableverify' => true ) ) ), 'Explicit certificate verification override must be honored' );
	}

	#[Group( 'live' )]
	public function test_live_disabling_ssl_rejects_https_without_downgrade(): void {
		$tls_options = $this->tls_options();
		$this->provider->set_options( $tls_options );
		$this->assertStringStartsWith( 'Failure:', $this->credentials( array_merge( $tls_options, array( 'nossl' => true ) ) ), 'Disabling SSL must reject HTTPS without downgrade' );
	}
}
