<?php
/**
 * FTP/FTPS regression checks without WordPress.
 *
 * The settings, validation and conflict checks need nothing else. The test_live_* tests transfer files to disposable FTP and FTPS
 * servers and are skipped unless scripts/integration/test-ftp.php started them: it creates the fixtures and runs exactly those
 * tests with BACKUPPLUS_TEST_FTP_HOST and BACKUPPLUS_TEST_FTPS_HOST (host:port), BACKUPPLUS_TEST_FTP_USER and
 * BACKUPPLUS_TEST_FTP_PASS. The FTPS server must require TLS on both control and data connections, and the plain server must
 * reject TLS. BACKUPPLUS_TEST_FTP_CA supplies the test CA bundle. The servers need an empty writable login directory and root.
 */

namespace BackupPlus\Tests\Unit\Providers;

use PHPUnit\Framework\Attributes\Group;
use BackupPlus;
use BackupPlus_BackupModule_ftp;
use BackupPlus_ftp_wrapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class FtpTest extends TestCase {

	/** Stands in for ABSPATH and BACKUPPLUS_DIR, with the files the provider reads from them. */
	private string $fixture = '';

	private BackupPlus $job;

	private BackupPlus_BackupModule_ftp $provider;

	protected function setUp(): void {
		$this->fixture = sys_get_temp_dir() . '/backupplus-ftp-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->fixture . '/wp-includes', 0700, true );
		mkdir( $this->fixture . '/includes' );
		copy( getenv( 'BACKUPPLUS_TEST_FTP_CA' ) ?: dirname( __DIR__, 3 ) . '/includes/cacert.pem', $this->fixture . '/includes/cacert.pem' );
		file_put_contents( $this->fixture . '/wp-includes/version.php', '<?php // Test upload' );
		define( 'ABSPATH', $this->fixture . '/' );
		define( 'WPINC', 'wp-includes' );
		define( 'BACKUPPLUS_DIR', $this->fixture );
		require_once dirname( __DIR__, 2 ) . '/Support/ftp-stubs.php';

		$this->job             = new BackupPlus();
		$GLOBALS['backupplus'] = $this->job;
		require dirname( __DIR__, 3 ) . '/methods/ftp.php';
		$this->provider = new BackupPlus_BackupModule_ftp();
	}

	protected function tearDown(): void {
		if ( '' !== $this->fixture && is_dir( $this->fixture ) ) {
			$entries = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->fixture, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $entries as $entry ) {
				$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
			}
			rmdir( $this->fixture );
		}
	}

	public function test_existing_settings_default_to_plain_ftp(): void {
		$defaults = $this->provider->get_default_options();
		$this->assertSame( 'none', $defaults['encryption'], 'Existing settings must default to plain FTP' );
	}

	public function test_saved_settings_are_sanitised(): void {
		$settings = array(
			'version'  => 1,
			'settings' => array(
				'plain'    => array(
					'host' => 'ftp://example.test:2121/',
					'pass' => " secret \n",
				),
				'secure'   => array(
					'host'       => 'FTPS://example.test:2122/',
					'encryption' => 'none',
				),
				'explicit' => array(
					'host'    => 'ftpes://example.test/',
					'passive' => 0,
				),
				'selected' => array(
					'host'       => 'ftp://example.test/',
					'encryption' => 'tls',
				),
			),
		);
		$saved    = $this->provider->options_filter( $settings );
		$this->assertSame( 'example.test:2121', $saved['settings']['plain']['host'], 'Plain host prefix and trailing slash must be removed' );
		$this->assertSame( 'none', $saved['settings']['plain']['encryption'], 'Plain prefix must keep the FTP default' );
		$this->assertSame( ' secret ', $saved['settings']['plain']['pass'], 'Password spaces must survive sanitisation' );
		foreach ( array( 'secure', 'explicit', 'selected' ) as $instance ) {
			$this->assertSame( 'tls', $saved['settings'][ $instance ]['encryption'], 'Secure settings must survive saving' );
		}
		$this->assertSame( 'example.test:2122', $saved['settings']['secure']['host'], 'FTPS custom port must survive sanitisation' );
		$this->assertSame( 0, $saved['settings']['explicit']['passive'], 'Encryption must not change active/passive mode' );
	}

	public function test_onboarding_exposes_the_encryption_selector(): void {
		$fields = array_column( $this->provider->transform_template_properties_to_fields_structure(), null, 'id' );
		$this->assertSame( 'dropdown', $fields['encryption']['type'], 'Onboarding must expose the encryption selector' );
		$this->assertSame( 'tls', $fields['encryption']['options'][1]['value'], 'Onboarding must offer explicit TLS' );
	}

	public function test_unknown_encryption_fails_before_connecting(): void {
		$output = $this->credentials_test( array( 'encryption' => 'invalid' ) );
		$this->assertStringContainsString( 'Unsupported FTP encryption', $output, 'Unknown encryption must fail before connecting' );
	}

	public static function secure_hosts(): array {
		return array(
			'selected TLS' => array( 'example.test', 'tls' ),
			'ftps prefix'  => array( 'ftps://example.test/', 'none' ),
			'ftpes prefix' => array( 'ftpes://example.test/', 'none' ),
		);
	}

	#[DataProvider( 'secure_hosts' )]
	public function test_disabling_ssl_fails_instead_of_downgrading_ftps( string $host, string $encryption ): void {
		$output = $this->credentials_test(
			array(
				'host'       => $host,
				'encryption' => $encryption,
				'nossl'      => true,
			)
		);
		$this->assertStringContainsString( 'FTPS requires SSL/TLS', $output, 'Disabling SSL must fail instead of downgrading FTPS' );
	}

	/** Only meaningful without cURL: ScriptsTest runs it with curl_init disabled. */
	private function require_missing_curl(): void {
		if ( function_exists( 'curl_init' ) ) {
			$this->markTestSkipped( 'cURL is available; the disabled curl_init variant of the FTP tests runs this without it.' );
		}
	}

	#[Group( 'variant' )]
	public function test_without_curl_the_credentials_test_reports_an_actionable_error(): void {
		$this->require_missing_curl();
		$output = $this->credentials_test( array( 'encryption' => 'tls' ) );
		$this->assertStringContainsString( 'curl_init', $output, 'Unavailable cURL support must produce an actionable error' );
	}

	#[Group( 'variant' )]
	public function test_without_curl_background_operations_fail_with_a_storage_error(): void {
		$this->require_missing_curl();
		$this->provider->set_options(
			array(
				'host'       => 'example.test',
				'user'       => 'test',
				'pass'       => 'test',
				'encryption' => 'tls',
			)
		);
		$result = $this->provider->listfiles();
		$this->assertTrue( is_wp_error( $result ), 'Background operations must handle unavailable cURL support' );
		$this->assertSame( 'ftp_missing_support', $result->get_error_code(), 'Background operations must handle unavailable cURL support' );
	}

	#[Group( 'variant' )]
	public function test_without_curl_the_wrapper_fails_safely(): void {
		$this->require_missing_curl();
		require_once dirname( __DIR__, 3 ) . '/methods/ftp/client.php';
		$ftp      = new BackupPlus_ftp_wrapper( 'example.test', 'test', 'test' );
		$ftp->ssl = true;
		$this->assertFalse( $ftp->connect(), 'The wrapper must also fail safely when cURL is unavailable' );
	}

	/** Run the credentials test against the unsaved settings and return what it printed. */
	private function credentials_test( array $settings ): string {
		$this->provider->set_connection_status( false );
		ob_start();
		$this->provider->credentials_test(
			array_merge(
				array(
					'host'           => 'example.test',
					'user'           => 'test',
					'pass'           => 'test',
					'path'           => '',
					'nossl'          => false,
					'disableverify'  => false,
					'useservercerts' => false,
					'passive'        => 1,
				),
				$settings
			)
		);
		return ob_get_clean();
	}

	/** The host:port values and credentials of the disposable servers, or skip when they are not running. */
	private function live_servers(): array {
		$plain  = getenv( 'BACKUPPLUS_TEST_FTP_HOST' );
		$secure = getenv( 'BACKUPPLUS_TEST_FTPS_HOST' );
		if ( ! $plain || ! $secure ) {
			$this->markTestSkipped( 'Run scripts/integration/test-ftp.php for live transfers (sets BACKUPPLUS_TEST_FTP_HOST and BACKUPPLUS_TEST_FTPS_HOST).' );
		}
		return array(
			'plain'  => $plain,
			'secure' => $secure,
			'user'   => getenv( 'BACKUPPLUS_TEST_FTP_USER' ),
			'pass'   => getenv( 'BACKUPPLUS_TEST_FTP_PASS' ),
		);
	}

	/** Provider options for one live server, encryption mode and active/passive mode. */
	private function live_options( string $encryption, int $passive ): array {
		$servers = $this->live_servers();
		return array(
			'host'       => 'tls' === $encryption ? $servers['secure'] : $servers['plain'],
			'user'       => $servers['user'],
			'pass'       => $servers['pass'],
			'path'       => '',
			'encryption' => $encryption,
			'passive'    => $passive,
		);
	}

	public static function live_modes(): array {
		return array(
			'plain passive' => array( 'none', 1 ),
			'plain active'  => array( 'none', 0 ),
			'tls passive'   => array( 'tls', 1 ),
			'tls active'    => array( 'tls', 0 ),
		);
	}

	private function contents(): string {
		return str_repeat( "FTP and FTPS transfer regression test\n", 32768 );
	}

	/** Upload a small file through the provider and return the connection it keeps for the follow-up operations. */
	private function open_backup_connection( array $options ) {
		$this->provider->set_options( $options );
		$name = 'backup_ftps_connection_' . uniqid() . '.zip';
		file_put_contents( $this->fixture . '/' . $name, 'connection' );
		$result = $this->provider->backup( array( $name ) );
		$this->assertTrue( is_array( $result ), 'The backup connection must be established' );
		unlink( $this->fixture . '/' . $name );
		$ftp = $result['ftp_object'];
		$this->assertTrue( $ftp->delete( $name ), 'The connection check upload must be removable' );
		return $ftp;
	}

	/** Needs the disposable servers that scripts/integration/test-ftp.php starts. */
	#[Group( 'live' )]
	#[DataProvider( 'live_modes' )]
	public function test_live_credentials_upload_listing_download_resumption_and_deletion( string $encryption, int $passive ): void {
		$options  = $this->live_options( $encryption, $passive );
		$name     = 'backup_ftps_test_' . uniqid() . '.zip';
		$contents = $this->contents();
		$this->provider->set_options( $options );
		$output = $this->credentials_test( $options );
		$this->assertStringStartsWith( 'Success:', $output, "$encryption credentials test failed: $output" );
		$this->assertStringContainsString( 'tls' === $encryption ? 'encrypted (explicit TLS)' : 'non-encrypted', $output, 'Credentials test must report the actual login type' );
		file_put_contents( $this->fixture . '/' . $name, $contents );
		$result = $this->provider->backup( array( $name ) );
		$this->assertTrue( is_array( $result ), "$encryption upload must succeed" );
		$this->assertContains( $name, $this->job->uploaded, "$encryption upload must succeed" );
		$this->assertTrue( count( $this->job->progress ) > 0, 'Uploads must record bounded backup progress' );
		$this->assertTrue( max( $this->job->progress ) <= 100, 'Uploads must record bounded backup progress' );
		$files = $this->provider->listfiles();
		$this->assertTrue( is_array( $files ), "$encryption listing must find the uploaded backup" );
		$this->assertContains( $name, array_column( $files, 'name' ), "$encryption listing must find the uploaded backup" );
		unlink( $this->fixture . '/' . $name );
		$this->assertTrue( $this->provider->download( $name ), "$encryption download must succeed" );
		$this->assertSame( $contents, file_get_contents( $this->fixture . '/' . $name ), 'Downloaded content must match the original' );
		file_put_contents( $this->fixture . '/' . $name, substr( $contents, 0, 1024 ) );
		$this->assertTrue( $this->provider->download( $name ), "$encryption resumed download must succeed" );
		$this->assertSame( $contents, file_get_contents( $this->fixture . '/' . $name ), 'Resumed download must match the original' );
		$this->assertTrue( $this->provider->download( $name ), 'Resuming a complete download must succeed without appending data' );
		$this->assertTrue( $this->provider->delete( $name ), "$encryption deletion through a fresh connection must succeed" );
		$this->assertSame( array(), $this->provider->listfiles(), 'The remote test directory must be empty after deletion' );
		// Upload a partial file, then resume through the same encrypted connection.
		file_put_contents( $this->fixture . '/' . $name, substr( $contents, 0, 1024 ) );
		$ftp = $result['ftp_object'];
		$this->assertNotSame( '', $ftp->system_type, 'The wrapper must retain the remote system type' );
		$this->assertTrue( $ftp->put( $this->fixture . '/' . $name, $name ), 'Partial upload must succeed' );
		file_put_contents( $this->fixture . '/' . $name, $contents );
		$this->assertTrue( $ftp->put( $this->fixture . '/' . $name, $name, BackupPlus_ftp_wrapper::BINARY, true, $this->job ), 'Resumed upload must succeed' );
		unlink( $this->fixture . '/' . $name );
		$this->assertTrue( $this->provider->download( $name ), 'Resumed upload must preserve the full content' );
		$this->assertSame( $contents, file_get_contents( $this->fixture . '/' . $name ), 'Resumed upload must preserve the full content' );
		$this->assertTrue( $this->provider->delete( $name, $result ), 'Deletion must also reuse the backup connection' );
	}

	/** Directory commands and URL encoding must preserve literal path characters. */
	#[Group( 'live' )]
	#[DataProvider( 'live_modes' )]
	public function test_live_literal_path_characters_and_directory_commands( string $encryption, int $passive ): void {
		$options   = $this->live_options( $encryption, $passive );
		$contents  = $this->contents();
		$ftp       = $this->open_backup_connection( $options );
		$directory = 'archives #?% space';
		$this->assertTrue( $ftp->make_dir( $directory ), 'Creating a relative directory must succeed' );
		$this->assertTrue( $ftp->make_dir( '/' . $directory ), 'Creating an absolute directory must succeed' );
		foreach ( array( $directory, '/' . $directory ) as $path ) {
			$special_name    = 'backup_#?% ;type=a space.zip';
			$options['path'] = $path;
			$this->provider->set_options( $options );
			file_put_contents( $this->fixture . '/' . $special_name, $contents );
			$output = $this->credentials_test( $options );
			$this->assertStringStartsWith( 'Success:', $output, 'Credentials test must support literal directory characters' );
			$path_result = $this->provider->backup( array( $special_name ) );
			$this->assertTrue( is_array( $path_result ), 'Uploads must preserve absolute and relative paths with URL metacharacters' );
			$this->assertSame( strlen( $contents ), $ftp->size( $path . '/' . $special_name ), 'Uploads must preserve absolute and relative paths with URL metacharacters' );
			$files = $this->provider->listfiles();
			$this->assertTrue( is_array( $files ), 'Listing must preserve literal filenames' );
			$this->assertContains( $special_name, array_column( $files, 'name' ), 'Listing must preserve literal filenames' );
			unlink( $this->fixture . '/' . $special_name );
			$this->assertTrue( $this->provider->download( $special_name ), 'Encoded downloads must preserve the full content' );
			$this->assertSame( $contents, file_get_contents( $this->fixture . '/' . $special_name ), 'Encoded downloads must preserve the full content' );
			$remote_file = $path . '/' . $special_name;
			$this->assertTrue( $ftp->rename( $remote_file, $remote_file . '.renamed' ), 'Renaming must support literal path characters' );
			$this->assertTrue( $ftp->chmod( 0644, $remote_file . '.renamed' ), 'Changing permissions must succeed' );
			$this->assertTrue( $ftp->rename( $remote_file . '.renamed', $remote_file ), 'Renaming back must succeed' );
			$this->assertTrue( $this->provider->delete( $special_name, $path_result ), 'Deletion must preserve literal paths' );
		}
		$this->provider->set_options( array_merge( $options, array( 'path' => 'missing-directory' ) ) );
		$this->assertTrue( is_wp_error( $this->provider->listfiles() ), 'A failed listing must return a storage error' );
		$entry = $ftp->current_dir();
		$this->assertTrue( $ftp->chdir( $directory ), 'Changing directories must succeed' );
		$this->assertSame( $entry . '/' . $directory, $ftp->current_dir(), 'Working directory must reflect the directory change' );
		$this->assertTrue( $ftp->cdup(), 'Moving to the parent directory must succeed' );
		$this->assertSame( $entry, $ftp->current_dir(), 'Moving to the parent directory must succeed' );
		$this->assertTrue( $ftp->remove_dir( $directory ), 'Removing directories must succeed' );
		$this->assertTrue( $ftp->remove_dir( '/' . $directory ), 'Removing directories must succeed' );
	}

	#[Group( 'live' )]
	#[DataProvider( 'live_modes' )]
	public function test_live_raw_commands_reject_newline_injection_and_zero_byte_files_transfer( string $encryption, int $passive ): void {
		$ftp = $this->open_backup_connection( $this->live_options( $encryption, $passive ) );
		$this->assertFalse( $ftp->delete( "file\r\nDELE other-file" ), 'Raw FTP commands must reject newline injection' );
		$empty_file = $this->fixture . '/empty-file';
		file_put_contents( $empty_file, '' );
		$this->assertTrue( $ftp->put( $empty_file, 'empty-file' ), 'Zero-byte uploads must succeed' );
		$this->assertSame( 0, $ftp->size( 'empty-file' ), 'Zero-byte uploads must succeed' );
		$this->assertTrue( $ftp->get( $empty_file, 'empty-file' ), 'Zero-byte downloads must succeed' );
		$this->assertSame( '', file_get_contents( $empty_file ), 'Zero-byte downloads must succeed' );
		$this->assertTrue( $ftp->delete( 'empty-file' ), 'Zero-byte files must be deletable' );
	}

	public static function secure_prefixes(): array {
		return array(
			'ftps'  => array( 'ftps://' ),
			'ftpes' => array( 'ftpes://' ),
		);
	}

	#[Group( 'live' )]
	#[DataProvider( 'secure_prefixes' )]
	public function test_live_secure_host_prefixes_select_tls_in_unsaved_tests( string $prefix ): void {
		$servers = $this->live_servers();
		$output  = $this->credentials_test(
			array(
				'host' => $prefix . $servers['secure'] . '/',
				'user' => $servers['user'],
				'pass' => $servers['pass'],
			)
		);
		$this->assertStringStartsWith( 'Success:', $output, 'Secure host prefixes must select TLS in unsaved tests' );
		$this->assertStringContainsString( 'encrypted (explicit TLS)', $output, 'Secure host prefixes must select TLS in unsaved tests' );
	}

	/** A server that rejects AUTH TLS must not receive a plain FTP retry. */
	#[Group( 'live' )]
	public function test_live_tls_is_never_downgraded_against_a_plain_only_server(): void {
		$servers = $this->live_servers();
		$this->provider->set_options(
			array(
				'host'       => $servers['plain'],
				'user'       => $servers['user'],
				'pass'       => $servers['pass'],
				'encryption' => 'tls',
			)
		);
		$result = @$this->provider->listfiles();
		$this->assertTrue( is_wp_error( $result ), 'FTPS must fail against a plain-only server even with valid credentials' );
		$output = @$this->credentials_test(
			array(
				'host' => 'ftps://' . $servers['plain'] . '/',
				'user' => $servers['user'],
				'pass' => $servers['pass'],
			)
		);
		$this->assertStringStartsWith( 'Failure:', $output, 'An unsaved secure host prefix must require TLS' );
	}

	#[Group( 'live' )]
	public function test_live_incorrect_credentials_are_rejected(): void {
		$servers = $this->live_servers();
		$output  = @$this->credentials_test(
			array(
				'host'       => $servers['secure'],
				'user'       => $servers['user'],
				'pass'       => 'wrong-password',
				'encryption' => 'tls',
			)
		);
		$this->assertStringStartsWith( 'Failure:', $output, 'FTPS must reject incorrect credentials' );
		$this->assertFalse( $this->provider->is_connection_successful(), 'A failed credentials test must not report a successful connection' );
	}

	public static function bad_certificates(): array {
		return array(
			'untrusted'         => array( 'untrusted' ),
			'expired'           => array( 'expired' ),
			'hostname mismatch' => array( 'hostname mismatch' ),
		);
	}

	/** Default verification must reject an untrusted issuer, expired certificate and wrong hostname. */
	#[Group( 'live' )]
	#[DataProvider( 'bad_certificates' )]
	public function test_live_default_verification_rejects_bad_certificates( string $reason ): void {
		$servers = $this->live_servers();
		$hosts   = array(
			'untrusted'         => getenv( 'BACKUPPLUS_TEST_FTPS_UNTRUSTED_HOST' ),
			'expired'           => getenv( 'BACKUPPLUS_TEST_FTPS_EXPIRED_HOST' ),
			'hostname mismatch' => preg_replace( '/^localhost:/', '127.0.0.1:', $servers['secure'] ),
		);
		$host    = $hosts[ $reason ];
		if ( ! $host || ( 'hostname mismatch' === $reason && $host === $servers['secure'] ) ) {
			$this->markTestSkipped( 'No fixture for the ' . $reason . ' certificate case.' );
		}
		$options = array(
			'host'       => $host,
			'user'       => $servers['user'],
			'pass'       => $servers['pass'],
			'encryption' => 'tls',
		);
		$output  = $this->credentials_test( $options );
		$this->assertStringStartsWith( 'Failure:', $output, "FTPS must reject $reason with a certificate error: $output" );
		$this->assertStringContainsStringIgnoringCase( 'certificate', $output, "FTPS must reject $reason with a certificate error: $output" );
		$this->provider->set_options( $options );
		$this->assertTrue( is_wp_error( $this->provider->listfiles() ), "Background jobs must reject $reason" );
		$output = $this->credentials_test( array_merge( $options, array( 'disableverify' => true ) ) );
		$this->assertStringStartsWith( 'Success:', $output, 'The existing explicit verification override must be honored' );
	}

	/** Selecting system certificates must use PHP's configured CA file rather than the bundle. */
	#[Group( 'live' )]
	public function test_live_system_certificate_selection_uses_the_configured_ca_file_rather_than_the_bundle(): void {
		$servers = $this->live_servers();
		copy( dirname( __DIR__, 3 ) . '/includes/cacert.pem', $this->fixture . '/includes/cacert.pem' );
		$options = array(
			'host'       => $servers['secure'],
			'user'       => $servers['user'],
			'pass'       => $servers['pass'],
			'encryption' => 'tls',
		);
		$output  = $this->credentials_test( $options );
		$this->assertStringStartsWith( 'Failure:', $output, 'The bundled CA selection must not silently use the system test CA' );
		$output = $this->credentials_test( array_merge( $options, array( 'useservercerts' => true ) ) );
		$this->assertStringStartsWith( 'Success:', $output, 'System CA selection must honor curl.cainfo across request resets' );
		$this->job->use_server_certs = true;
		$this->provider->set_options( $options );
		$this->assertTrue( is_array( $this->provider->listfiles() ), 'Background jobs must honor system CA selection' );
	}
}
