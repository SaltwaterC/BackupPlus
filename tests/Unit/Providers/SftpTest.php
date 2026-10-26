<?php
/**
 * SSH storage checks without WordPress. The test_live_* tests need the disposable SSH servers that
 * scripts/integration/test-sftp.php starts (BACKUPPLUS_TEST_SFTP_PORT and friends) and are skipped without them.
 */

namespace BackupPlus\Tests\Unit\Providers;

use PHPUnit\Framework\Attributes\Group;
use BackupPlus_BackupModule_sftp;
use BackupPlus_Options;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use SSHTestBackupPlus;
use WP_Error;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class SftpTest extends TestCase {

	private SSHTestBackupPlus $job;

	private BackupPlus_BackupModule_sftp $provider;

	/** Options that pass the validation but never reach a server. */
	private array $offline_options = array(
		'host'     => 'example.test',
		'user'     => 'test',
		'pass'     => 'test',
		'host_key' => 'SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
	);

	protected function setUp(): void {
		require_once __DIR__ . '/../../Support/sftp-stubs.php';
		BackupPlus_Options::$options = array(
			'backupplus_sftp_settings' => array(
				'host' => 'legacy.test',
				'key'  => "legacy\nkey",
			),
		);

		$this->job                 = new SSHTestBackupPlus();
		$this->job->backup_methods = array( 'sftp' => 'SFTP / SCP' );
		$this->job->directory      = sys_get_temp_dir() . '/backupplus-sftp-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->job->directory );
		$GLOBALS['backupplus'] = $this->job;
		require BACKUPPLUS_DIR . '/methods/sftp.php';
		$this->provider = new BackupPlus_BackupModule_sftp();
	}

	protected function tearDown(): void {
		foreach ( glob( $this->job->directory . '/*' ) as $file ) {
			if ( is_file( $file ) || is_link( $file ) ) {
				unlink( $file );
			}
		}
		rmdir( $this->job->directory );
	}

	public function test_legacy_settings_migrate(): void {
		$this->assertSame( 'legacy.test', BackupPlus_Options::$options['backupplus_sftp']['host'], 'Legacy settings must migrate' );
		$this->assertFalse( isset( BackupPlus_Options::$options['backupplus_sftp_settings'] ), 'Legacy settings must migrate' );
	}

	public function test_sftp_is_the_default_protocol(): void {
		$this->assertSame( 'sftp', $this->provider->get_default_options()['protocol'], 'SFTP must be the default' );
	}

	public function test_prefixes_ipv6_and_custom_ports_normalise(): void {
		$saved = $this->save_settings();
		$this->assertSame( '::1', $saved['host'], 'Prefixes, IPv6 and custom ports must normalise' );
		$this->assertSame( '2222', $saved['port'], 'Prefixes, IPv6 and custom ports must normalise' );
		$this->assertSame( 'scp', $saved['protocol'], 'Prefixes, IPv6 and custom ports must normalise' );
	}

	public function test_passwords_and_multiline_keys_survive_saving_exactly(): void {
		$saved = $this->save_settings();
		$this->assertSame( " secret \n", $saved['pass'], 'Passwords and multiline keys must survive saving exactly' );
		$this->assertSame( "key\nmaterial\n", $saved['key'], 'Passwords and multiline keys must survive saving exactly' );
	}

	public function test_onboarding_exposes_protocol_and_secret_fields(): void {
		$fields = array_column( $this->provider->transform_template_properties_to_fields_structure(), null, 'id' );
		$this->assertSame( 'dropdown', $fields['protocol']['type'], 'Onboarding must expose protocol and secret fields' );
		$this->assertSame( 'password', $fields['pass']['type'], 'Onboarding must expose protocol and secret fields' );
	}

	public function test_the_test_button_has_a_visible_label_before_the_first_click(): void {
		$properties = $this->provider->get_template_properties();
		$this->assertNotEmpty( $properties['input_test_label'], 'The test button must have a visible label before the first click' );
		$this->assertStringContainsString( 'SFTP', $properties['input_test_label'], 'The test button must have a visible label before the first click' );
	}

	public function test_settings_expose_the_private_key_to_connection_tests(): void {
		$this->assertStringContainsString( 'data-backupplus_settings_test="key"', $this->provider->get_configuration_template(), 'Settings must expose the private key to connection tests' );
	}

	public static function invalid_options(): array {
		return array(
			'empty host'           => array( array( 'host' => '' ) ),
			'unsupported protocol' => array( array( 'protocol' => 'ftp' ) ),
			'malformed port'       => array( array( 'port' => '22abc' ) ),
			'port zero'            => array( array( 'port' => 0 ) ),
			'port above the range' => array( array( 'port' => 65536 ) ),
			'user in the host'     => array( array( 'host' => 'user@host' ) ),
			'url in the host'      => array( array( 'host' => 'sftp://host/path' ) ),
			'malformed host key'   => array( array( 'host_key' => 'invalid' ) ),
			'newline in the path'  => array( array( 'path' => "bad\npath" ) ),
		);
	}

	#[DataProvider( 'invalid_options' )]
	public function test_invalid_options_fail_before_connecting( array $invalid ): void {
		$this->provider->set_options( array_merge( $this->offline_options, $invalid ) );
		$this->assertTrue( is_wp_error( $this->provider->listfiles() ), 'Invalid options must fail before connecting' );
	}

	/** Only meaningful, and only selected, when the PHP socket function is disabled (see the driver and ScriptsTest). */
	#[Group( 'variant' )]
	public function test_missing_sockets_return_an_actionable_error(): void {
		if ( function_exists( 'fsockopen' ) ) {
			$this->markTestSkipped( 'fsockopen is available; the variant run without it covers this.' );
		}
		$this->provider->set_options( $this->offline_options );
		$this->assertSame( 'sftp_support', $this->error_code( $this->provider->listfiles() ), 'Missing sockets must return an actionable error' );
	}

	public function test_obsolete_phpseclib_2_apis_are_not_available(): void {
		require_once BACKUPPLUS_DIR . '/vendor/autoload.php';
		$this->assertFalse( class_exists( 'phpseclib\\Crypt\\RSA' ), 'The obsolete phpseclib 2 APIs must not be available' );
		$this->assertFalse( class_exists( 'phpseclib_Crypt_RSA' ), 'The obsolete phpseclib 2 APIs must not be available' );
	}

	public static function protocols(): array {
		return array(
			'sftp' => array( 'sftp' ),
			'scp'  => array( 'scp' ),
		);
	}

	public static function transfer_cases(): array {
		$cases = array();
		foreach ( array( 'sftp', 'scp' ) as $protocol ) {
			foreach ( array( 'default directory', 'relative', 'absolute' ) as $case ) {
				$cases[ $protocol . ', ' . $case ] = array( $protocol, $case );
			}
		}
		return $cases;
	}

	#[Group( 'live' )]
	#[DataProvider( 'transfer_cases' )]
	public function test_live_transfers_resumption_and_literal_paths( string $protocol, string $case ): void {
		$this->require_live();
		$contents = str_repeat( "SSH transfer\x00test\n", 65536 );
		$paths    = array(
			'default directory' => '',
			'relative'          => "archives ' #$",
			'absolute'          => getenv( 'BACKUPPLUS_TEST_SSH_ROOT' ) . "/login/archives ' #$",
		);
		$options  = $this->live_options( $protocol, array( 'path' => $paths[ $case ] ) );
		$case     = 'default directory' === $case ? '' : $case;
		$this->provider->set_options( $options );
		$output = $this->credentials( $options );
		$this->assertStringStartsWith( 'Success:', $output, "$protocol credentials ($case): $output" );
		$this->assertTrue( $this->provider->is_connection_successful(), "$protocol credentials ($case): $output" );
		foreach ( array( 'backup_normal.zip', 'backup_quoted \';$(touch INJECTED) #?.zip', '-backup_option.zip', 'backup_empty.zip' ) as $name ) {
			$local   = $this->job->directory . '/' . $name;
			$payload = 'backup_empty.zip' === $name ? '' : $contents;
			file_put_contents( $local, $payload );
			$this->job->uploaded = array();
			$this->job->progress = array();
			$this->assertIsArray( $this->provider->backup( array( $name ) ), "$protocol upload ($name)" );
			$this->assertSame( array( $name ), $this->job->uploaded, "$protocol upload ($name)" );
			if ( '' !== $payload ) {
				$this->assertNotEmpty( $this->job->progress, 'Upload progress must be bounded' );
				$this->assertLessThanOrEqual( 100, max( $this->job->progress ), 'Upload progress must be bounded' );
			}
			$list = $this->provider->listfiles();
			$this->assertIsArray( $list, "$protocol listing ($name)" );
			$this->assertContains( $name, array_column( $list, 'name' ), "$protocol listing ($name)" );
			unlink( $local );
			$this->assertTrue( $this->provider->download( $name ), "$protocol download ($name)" );
			$this->assertSame( $payload, file_get_contents( $local ), "$protocol download ($name)" );
			file_put_contents( $local, substr( $payload, 0, 1000 ) );
			$this->assertTrue( $this->provider->download( $name ), "$protocol resumed download ($name)" );
			$this->assertSame( $payload, file_get_contents( $local ), "$protocol resumed download ($name)" );
			$this->assertTrue( $this->provider->download( $name ), "$protocol completed download ($name)" );
			file_put_contents( $local, $payload . 'oversized' );
			$this->assertTrue( $this->provider->download( $name ), "$protocol oversized local file must restart" );
			$this->assertSame( $payload, file_get_contents( $local ), "$protocol oversized local file must restart" );
			// A resumed upload must finish a short remote file, and replace oversized ones.
			file_put_contents( $local, substr( $payload, 0, 1000 ) );
			$this->assertIsArray( $this->provider->backup( array( $name ) ), 'Partial upload fixture' );
			file_put_contents( $local, $payload );
			$this->job->current_resumption = 1;
			$this->assertIsArray( $this->provider->backup( array( $name ) ), "$protocol resumed upload" );
			unlink( $local );
			$this->assertTrue( $this->provider->download( $name ), 'Resumed upload must preserve bytes' );
			$this->assertSame( $payload, file_get_contents( $local ), 'Resumed upload must preserve bytes' );
			file_put_contents( $local, 'x' );
			$this->assertIsArray( $this->provider->backup( array( $name ) ), 'Smaller replacement must truncate remote file' );
			unlink( $local );
			$this->assertTrue( $this->provider->download( $name ), 'Remote truncation must preserve replacement' );
			$this->assertSame( 'x', file_get_contents( $local ), 'Remote truncation must preserve replacement' );
			$this->job->current_resumption = 0;
			$this->assertTrue( $this->provider->delete( $name ), "$protocol idempotent deletion" );
			$this->assertTrue( $this->provider->delete( $name ), "$protocol idempotent deletion" );
		}
		$this->assertSame( array(), $this->provider->listfiles(), 'Only regular matching files may be listed' );
	}

	public static function unsafe_basenames(): array {
		$cases = array();
		foreach ( array( 'sftp', 'scp' ) as $protocol ) {
			foreach ( array( '../outside', '/absolute', "bad\nname", 'dir/file', 'dir\\file', '.', '..' ) as $name ) {
				$cases[ $protocol . ', ' . addcslashes( $name, "\n" ) ] = array( $protocol, $name );
			}
		}
		return $cases;
	}

	#[Group( 'live' )]
	#[DataProvider( 'unsafe_basenames' )]
	public function test_live_unsafe_basenames_are_rejected( string $protocol, string $name ): void {
		$this->require_live();
		$this->provider->set_options( $this->live_options( $protocol ) );
		$this->assertFalse( $this->provider->download( $name ), 'Unsafe basenames must be rejected' );
		$this->assertFalse( $this->provider->delete( $name ), 'Unsafe basenames must be rejected' );
		$this->assertFalse( $this->provider->backup( array( $name ) ), 'Unsafe basenames must be rejected' );
	}

	public static function directory_and_symlink_names(): array {
		$cases = array();
		foreach ( array( 'sftp', 'scp' ) as $protocol ) {
			foreach ( array( 'backup_directory', 'backup_link' ) as $name ) {
				$cases[ $protocol . ', ' . $name ] = array( $protocol, $name );
			}
		}
		return $cases;
	}

	#[Group( 'live' )]
	#[DataProvider( 'directory_and_symlink_names' )]
	public function test_live_directories_and_symlinks_are_not_transferred( string $protocol, string $name ): void {
		$this->require_live();
		$this->provider->set_options( $this->live_options( $protocol ) );
		file_put_contents( $this->job->directory . '/' . $name, 'do not overwrite' );
		$this->assertFalse( $this->provider->backup( array( $name ) ), 'Directories and symlinks must not be transferred' );
		$this->assertFalse( $this->provider->download( $name ), 'Directories and symlinks must not be transferred' );
	}

	public static function key_fixtures(): array {
		$keys  = json_decode( (string) getenv( 'BACKUPPLUS_TEST_SSH_KEYS' ), true );
		$cases = array();
		foreach ( array( 'sftp', 'scp' ) as $protocol ) {
			foreach ( is_array( $keys ) ? $keys : array( array( 'name' => 'no key fixtures' ) ) as $fixture ) {
				$cases[ $protocol . ', ' . $fixture['name'] ] = array( $protocol, $fixture );
			}
		}
		return $cases;
	}

	#[Group( 'live' )]
	#[DataProvider( 'key_fixtures' )]
	public function test_live_key_authentication_backup_restore_and_deletion( string $protocol, array $key_fixture ): void {
		$this->require_live();
		$contents = str_repeat( "SSH transfer\x00test\n", 65536 );
		$options  = $this->key_options( $protocol, $key_fixture );
		$output   = $this->credentials( $options );
		$this->assertStringStartsWith( 'Success:', $output, "$protocol {$key_fixture['name']} login: $output" );
		$this->provider->set_options( $options );
		$name  = 'backup_key_auth.zip';
		$local = $this->job->directory . '/' . $name;
		file_put_contents( $local, $contents );
		$this->assertIsArray( $this->provider->backup( array( $name ) ), "$protocol key-authenticated backup" );
		unlink( $local );
		$this->assertTrue( $this->provider->download( $name ), "$protocol key-authenticated restore" );
		$this->assertSame( $contents, file_get_contents( $local ), "$protocol key-authenticated restore" );
		$this->assertTrue( $this->provider->delete( $name ), "$protocol key-authenticated deletion" );
		if ( '' !== $key_fixture['passphrase'] ) {
			$options['key_passphrase'] = 'wrong';
			$this->provider->set_options( $options );
			$this->assertSame( 'sftp_private_key', $this->error_code( $this->provider->listfiles() ), 'Wrong private key passphrase must fail without falling back to a password' );
		}
	}

	#[Group( 'live' )]
	#[DataProvider( 'protocols' )]
	public function test_live_ed25519_host_keys_are_verified_and_mismatches_fail_closed( string $protocol ): void {
		$this->require_live();
		// Verify Ed25519 host keys as well as Ed25519 client authentication.
		$ed_options = array_merge(
			$this->key_options( $protocol, $this->last_key_fixture() ),
			array(
				'port'     => getenv( 'BACKUPPLUS_TEST_' . strtoupper( $protocol ) . '_ED25519_PORT' ),
				'host_key' => getenv( 'BACKUPPLUS_TEST_SSH_ED25519_FINGERPRINT' ),
			)
		);
		$this->assertStringStartsWith( 'Success:', $this->credentials( $ed_options ), "$protocol Ed25519 host key verification and authentication" );
		$ed_options['host_key'] = getenv( 'BACKUPPLUS_TEST_SSH_FINGERPRINT' );
		$this->provider->set_options( $ed_options );
		$this->assertSame( 'sftp_host_key_mismatch', $this->error_code( $this->provider->listfiles() ), 'Ed25519 host key mismatches must fail closed' );
	}

	#[Group( 'live' )]
	#[DataProvider( 'protocols' )]
	public function test_live_public_keys_are_not_accepted_as_private_keys( string $protocol ): void {
		$this->require_live();
		$options                   = $this->key_options( $protocol, $this->last_key_fixture() );
		$options['key']            = file_get_contents( getenv( 'BACKUPPLUS_TEST_SSH_PUBLIC_KEY' ) );
		$options['key_passphrase'] = '';
		$this->provider->set_options( $options );
		$this->assertSame( 'sftp_private_key', $this->error_code( $this->provider->listfiles() ), 'Public keys must not be accepted as private keys' );
	}

	#[Group( 'live' )]
	#[DataProvider( 'protocols' )]
	public function test_live_malformed_keys_fail_with_an_actionable_error( string $protocol ): void {
		$this->require_live();
		$options                   = $this->key_options( $protocol, $this->last_key_fixture() );
		$options['key']            = 'not a private key';
		$options['key_passphrase'] = '';
		$this->provider->set_options( $options );
		$this->assertSame( 'sftp_private_key', $this->error_code( $this->provider->listfiles() ), 'Malformed keys must fail with an actionable error' );
	}

	#[Group( 'live' )]
	#[DataProvider( 'protocols' )]
	public function test_live_wrong_passwords_fail_and_reset_the_connection_status( string $protocol ): void {
		$this->require_live();
		$options = $this->live_options( $protocol, array( 'pass' => 'wrong' ) );
		$this->assertStringStartsWith( 'Failure:', $this->credentials( $options ), 'Wrong password must fail and reset connection status' );
		$this->assertFalse( $this->provider->is_connection_successful(), 'Wrong password must fail and reset connection status' );
	}

	#[Group( 'live' )]
	#[DataProvider( 'protocols' )]
	public function test_live_read_only_remote_directories_fail_the_write_test( string $protocol ): void {
		$this->require_live();
		$options = $this->live_options( $protocol, array( 'path' => 'read-only' ) );
		$this->assertStringStartsWith( 'Failure:', $this->credentials( $options ), 'Read-only remote directories must fail the write test' );
	}

	#[Group( 'live' )]
	#[DataProvider( 'protocols' )]
	public function test_live_a_missing_fingerprint_is_displayed_without_logging_in( string $protocol ): void {
		$this->require_live();
		$options = $this->live_options(
			$protocol,
			array(
				'path'     => 'read-only',
				'pass'     => 'UNVERIFIED',
				'host_key' => '',
			)
		);
		$output  = $this->credentials( $options );
		$this->assertStringContainsString( getenv( 'BACKUPPLUS_TEST_SSH_FINGERPRINT' ), $output, 'Missing fingerprint must display the key without logging in' );
		$this->assertFalse( $this->provider->is_connection_successful(), 'Missing fingerprint must display the key without logging in' );
	}

	#[Group( 'live' )]
	#[DataProvider( 'protocols' )]
	public function test_live_changed_host_keys_fail_closed( string $protocol ): void {
		$this->require_live();
		$options = $this->live_options(
			$protocol,
			array(
				'path'     => 'read-only',
				'pass'     => 'UNVERIFIED',
				'host_key' => 'SHA256:' . str_repeat( 'A', 43 ),
			)
		);
		$this->provider->set_options( $options );
		$this->assertSame( 'sftp_host_key_mismatch', $this->error_code( $this->provider->listfiles() ), 'Changed host keys must fail closed' );
	}

	#[Group( 'live' )]
	#[DataProvider( 'protocols' )]
	public function test_live_missing_directories_produce_an_error( string $protocol ): void {
		$this->require_live();
		$this->provider->set_options( $this->live_options( $protocol, array( 'path' => 'does-not-exist' ) ) );
		$this->assertSame( 'sftp_path', $this->error_code( $this->provider->listfiles() ), 'Missing directories must produce an error' );
	}

	/** The normalised settings of the instance saved through the provider's filter. */
	private function save_settings(): array {
		$saved = $this->provider->options_filter(
			array(
				'version'  => 1,
				'settings' => array(
					'test' => array(
						'host' => 'SCP://[::1]:2222/',
						'pass' => " secret \n",
						'key'  => "key\nmaterial\n",
					),
				),
			)
		);
		return $saved['settings']['test'];
	}

	private function require_live(): void {
		if ( ! getenv( 'BACKUPPLUS_TEST_SFTP_PORT' ) ) {
			$this->markTestSkipped( 'Live SSH checks need the servers started by scripts/integration/test-sftp.php.' );
		}
	}

	/** Password login against the live server of the protocol, with the overrides applied. */
	private function live_options( string $protocol, array $overrides = array() ): array {
		return array_merge(
			array(
				'host'     => '127.0.0.1',
				'user'     => 'test',
				'pass'     => ' test password ',
				'host_key' => getenv( 'BACKUPPLUS_TEST_SSH_FINGERPRINT' ),
				'protocol' => $protocol,
				'port'     => getenv( 'BACKUPPLUS_TEST_' . strtoupper( $protocol ) . '_PORT' ),
			),
			$overrides
		);
	}

	/** Login with a private key; the password must never be used. */
	private function key_options( string $protocol, array $key_fixture ): array {
		return $this->live_options(
			$protocol,
			array(
				'path'           => '',
				'key'            => file_get_contents( $key_fixture['path'] ),
				'key_passphrase' => $key_fixture['passphrase'],
				'pass'           => 'this password must not be used',
			)
		);
	}

	private function last_key_fixture(): array {
		$keys = json_decode( (string) getenv( 'BACKUPPLUS_TEST_SSH_KEYS' ), true );
		return end( $keys );
	}

	private function credentials( array $options ): string {
		ob_start();
		$this->provider->credentials_test( $options );
		return ob_get_clean();
	}

	private function error_code( $result ): string {
		$this->assertInstanceOf( WP_Error::class, $result, 'The operation must return an error' );
		return $result->get_error_code();
	}
}
