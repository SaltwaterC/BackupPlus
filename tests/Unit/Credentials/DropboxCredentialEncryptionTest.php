<?php
/** SEC-001: authenticated credential storage, explicit legacy migration and key rotation. */

namespace BackupPlus\Tests\Unit\Credentials;

use BackupPlus\Tests\Support\CredentialEncryptionTestCase;
use BackupPlus_ChaCha20_Poly1305;
use BackupPlus_Credential_Key_Store;
use BackupPlus_Dropbox_Token_Migration;
use BackupPlus_Options;
use Dropbox_Encrypter;
use Dropbox_WordPress;
use DropboxTestModule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;
use WP_CLI;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class DropboxCredentialEncryptionTest extends CredentialEncryptionTestCase {

	/** @var array{old: string, new: string} Base64 keys of the key file. */
	private array $keys;

	private string $key_dir;

	private string $context = 'dropbox:1:instance';

	private Dropbox_Encrypter $encrypter;

	private object $token;

	private string $plain;

	protected function setUp(): void {
		parent::setUp();
		$this->keys    = array(
			'old' => base64_encode( random_bytes( 32 ) ),
			'new' => base64_encode( random_bytes( 32 ) ),
		);
		$this->key_dir = $this->fixture . '/keyring';
		mkdir( $this->key_dir, 0700 );
		define( 'WP_CONTENT_DIR', $this->key_dir . '/content' );
		define( 'BACKUPPLUS_CREDENTIAL_KEY_DIR', $this->key_dir );
		file_put_contents(
			$this->key_dir . '/credentials.php',
			BackupPlus_Credential_Key_Store::encode(
				array(
					'keys'   => $this->keys,
					'active' => 'new',
				)
			)
		);
		$this->backupplus->ensure_phpseclib();
		$this->encrypter = Dropbox_Encrypter::from_config( 'instance' );
		$this->token     = (object) array(
			'oauth_token'   => 'disposable-access',
			'refresh_token' => 'disposable-refresh',
			'token_type'    => 'bearer',
		);
		$this->plain     = serialize( $this->token );
	}

	/** An encrypter with the original key only. */
	private function old_encrypter(): Dropbox_Encrypter {
		return new Dropbox_Encrypter( $this->keys, 'old', $this->context );
	}

	/** @return array{nonce: string, ciphertext: string, tag: string, raw: string, parts: string[]} */
	private function envelope( string $encrypted ): array {
		$parts = explode( ':', $encrypted );
		$raw   = base64_decode( $parts[3] );
		return array(
			'parts'      => $parts,
			'raw'        => $raw,
			'nonce'      => substr( $raw, 0, 12 ),
			'ciphertext' => substr( $raw, 12, -16 ),
			'tag'        => substr( $raw, -16 ),
		);
	}

	public function test_writes_use_the_versioned_envelope_with_independent_nonces(): void {
		$encrypted = $this->encrypter->encrypt( $this->plain );
		$this->assertSame( 0, strpos( $encrypted, 'bpcred:v1:new:' ), 'Writes must use the versioned envelope and active key ID' );
		$this->assertSame( $this->plain, $this->encrypter->decrypt( $encrypted ), 'Authenticated credentials must roundtrip' );
		$this->assertNotSame( $encrypted, $this->encrypter->encrypt( $this->plain ), 'Repeated writes must use independent nonces' );
	}

	/** Not meaningful without native OpenSSL, so the portable variant run leaves it out. */
	public function test_the_envelope_interoperates_with_native_chacha20_poly1305(): void {
		if ( ! function_exists( 'openssl_decrypt' ) || ! in_array( 'chacha20-poly1305', openssl_get_cipher_methods(), true ) ) {
			$this->markTestSkipped( 'OpenSSL without ChaCha20-Poly1305 support.' );
		}
		$envelope = $this->envelope( $this->encrypter->encrypt( $this->plain ) );
		$this->assertSame( $this->plain, openssl_decrypt( $envelope['ciphertext'], 'chacha20-poly1305', base64_decode( $this->keys['new'] ), OPENSSL_RAW_DATA, $envelope['nonce'], $envelope['tag'], 'bpcred:v1:new:' . $this->context ), 'Envelope must interoperate with native ChaCha20-Poly1305' );
	}

	public function test_the_portable_php_implementation_reads_and_writes_the_same_envelope(): void {
		$envelope = $this->envelope( $this->encrypter->encrypt( $this->plain ) );
		$internal = new BackupPlus_ChaCha20_Poly1305();
		$internal->setPreferredEngine( 'PHP' );
		$internal->enablePoly1305();
		$internal->setCounter( 1 );
		$internal->setKey( base64_decode( $this->keys['new'] ) );
		$internal->setNonce( $envelope['nonce'] );
		$internal->setAAD( 'bpcred:v1:new:' . $this->context );
		$this->assertSame( $envelope['ciphertext'], $internal->encrypt( $this->plain ), 'The portable PHP implementation must produce the same ciphertext and tag' );
		$this->assertSame( $envelope['tag'], $internal->getTag( 16 ), 'The portable PHP implementation must produce the same ciphertext and tag' );
		$internal->setTag( $envelope['tag'] );
		$this->assertSame( $this->plain, $internal->decrypt( $envelope['ciphertext'] ), 'The portable PHP implementation must read the same envelope' );
		$this->assertSame( 'PHP', $internal->getEngine(), 'The portable implementation must use the PHP engine' );
	}

	/** Fixed independent OpenSSL fixture, including nonempty AAD and binary plaintext. */
	public function test_credentials_match_the_independent_chacha20_poly1305_fixture(): void {
		$vector = new Dropbox_Encrypter( array( 'vector' => base64_encode( hex2bin( '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f' ) ) ), 'vector', $this->context );
		$this->assertSame( "credential vector\0\xff", $vector->decrypt( 'bpcred:v1:vector:AAECAwQFBgcICQoL6oltZEx50SnW7x+F/X56DLtwTSWX59AVodieqaNRHpOaN6w=' ), 'Credentials must match the independent ChaCha20-Poly1305 fixture without native extensions' );
	}

	/** @return iterable<string, array{0: string}> */
	public static function boundary_messages(): iterable {
		yield 'empty' => array( '' );
		yield 'binary' => array( "\0\xff" );
		yield 'one block' => array( str_repeat( 'x', 64 ) );
		yield 'one block and a byte' => array( str_repeat( 'x', 65 ) );
	}

	#[DataProvider( 'boundary_messages' )]
	public function test_empty_binary_and_block_boundary_credentials_roundtrip( string $message ): void {
		$this->assertSame( $message, $this->encrypter->decrypt( $this->encrypter->encrypt( $message ) ), 'Empty, binary and block-boundary credentials must roundtrip' );
	}

	public function test_every_nonce_ciphertext_and_tag_byte_is_authenticated(): void {
		$raw = $this->envelope( $this->encrypter->encrypt( $this->plain ) )['raw'];
		for ( $i = 0; $i < strlen( $raw ); $i++ ) {
			$modified       = $raw;
			$modified[ $i ] = chr( ord( $modified[ $i ] ) ^ 1 );
			$this->assertFalse( $this->encrypter->decrypt( 'bpcred:v1:new:' . base64_encode( $modified ) ), 'Every nonce/ciphertext/tag byte must be authenticated' );
		}
	}

	/** @return iterable<string, array{0: string}> */
	public static function invalid_formats(): iterable {
		foreach ( array( 'null', 'array', 'empty', 'not base64', 'base64 of the plaintext', 'plaintext', 'unknown version', 'unknown key', 'another existing key', 'truncated', 'trailing data', 'short payload' ) as $case ) {
			yield $case => array( $case );
		}
	}

	#[DataProvider( 'invalid_formats' )]
	public function test_invalid_unknown_truncated_and_legacy_formats_fail( string $case ): void {
		$encrypted = $this->encrypter->encrypt( $this->plain );
		$payload   = $this->envelope( $encrypted )['parts'][3];
		$invalid   = array(
			'null'                    => null,
			'array'                   => array(),
			'empty'                   => '',
			'not base64'              => 'not base64!',
			'base64 of the plaintext' => base64_encode( $this->plain ),
			'plaintext'               => $this->plain,
			'unknown version'         => 'bpcred:v2:new:' . $payload,
			'unknown key'             => 'bpcred:v1:unknown:' . $payload,
			'another existing key'    => 'bpcred:v1:old:' . $payload,
			'truncated'               => substr( $encrypted, 0, -4 ),
			'trailing data'           => $encrypted . 'AAAA',
			'short payload'           => 'bpcred:v1:new:' . base64_encode( 'short' ),
		)[ $case ];
		$this->assertFalse( $this->encrypter->decrypt( $invalid ), 'Invalid, unknown, truncated and legacy formats must fail' );
	}

	public function test_credentials_are_bound_to_their_instance_blog_and_installation_key(): void {
		$encrypted = $this->encrypter->encrypt( $this->plain );
		$this->assertFalse( ( new Dropbox_Encrypter( $this->keys, 'new', 'dropbox:1:another' ) )->decrypt( $encrypted ), 'Credentials must be bound to their provider instance' );
		$this->assertFalse( ( new Dropbox_Encrypter( $this->keys, 'new', 'dropbox:2:instance' ) )->decrypt( $encrypted ), 'Credentials must be bound to their multisite blog' );
		$other_site = new Dropbox_Encrypter( array( 'new' => base64_encode( random_bytes( 32 ) ) ), 'new', $this->context );
		$this->assertFalse( $other_site->decrypt( $encrypted ), 'Another installation key must not decrypt a database copy' );
	}

	/** @return iterable<string, array{0: array<string, string>}> */
	public static function bad_key_configurations(): iterable {
		yield 'no keys' => array( array() );
		yield 'short key' => array( array( 'new' => 'short' ) );
		yield '31 byte key' => array( array( 'new' => base64_encode( random_bytes( 31 ) ) ) );
		yield 'invalid key id' => array( array( 'bad:id' => base64_encode( random_bytes( 32 ) ) ) );
	}

	#[DataProvider( 'bad_key_configurations' )]
	public function test_bad_key_configuration_fails_closed( array $bad ): void {
		$context = $this->context;
		$this->assert_fails(
			function () use ( $bad, $context ) {
				new Dropbox_Encrypter( $bad, 'new', $context );
			},
			'Bad key configuration must fail closed'
		);
	}

	public function test_a_missing_active_key_fails(): void {
		$keys    = $this->keys;
		$context = $this->context;
		$this->assert_fails(
			function () use ( $keys, $context ) {
				new Dropbox_Encrypter( $keys, 'missing', $context );
			},
			'Missing active key must fail'
		);
	}

	private function storage( DropboxTestModule $module ): Dropbox_WordPress {
		return new Dropbox_WordPress( $this->encrypter, 'tk_', 'backupplus_dropbox', $module );
	}

	public function test_unencrypted_storage_is_unavailable(): void {
		$module = new DropboxTestModule();
		$this->assert_fails(
			function () use ( $module ) {
				new Dropbox_WordPress( null, 'tk_', 'backupplus_dropbox', $module );
			},
			'Unencrypted storage must be unavailable'
		);
	}

	/** @return iterable<string, array{0: string}> */
	public static function token_types(): iterable {
		yield 'access token' => array( 'access_token' );
		yield 'request token' => array( 'request_token' );
	}

	#[DataProvider( 'token_types' )]
	public function test_token_writes_are_encrypted_and_read_back_authenticated( string $type ): void {
		$module  = new DropboxTestModule();
		$storage = $this->storage( $module );
		$storage->set( $this->token, $type );
		$this->assertSame( 0, strpos( $module->options[ 'tk_' . $type ], 'bpcred:v1:new:' ), 'All token writes must be encrypted' );
		$this->assertSame( $this->plain, serialize( $storage->get( $type ) ), 'Storage must decrypt authenticated credentials' );
	}

	public function test_an_encryption_failure_stops_persistence_and_preserves_saved_credentials(): void {
		$module  = new DropboxTestModule();
		$storage = $this->storage( $module );
		foreach ( array( 'access_token', 'request_token' ) as $type ) {
			$storage->set( $this->token, $type );
		}
		$before                        = $module->options;
		$saves                         = $module->saves;
		$token                         = $this->token;
		$this->backupplus->unavailable = true;
		$this->assert_fails(
			function () use ( $storage, $token ) {
				$storage->set( $token, 'access_token' );
			},
			'Encryption failure must stop persistence'
		);
		$this->assertSame( $before, $module->options, 'Encryption failure must preserve saved credentials' );
		$this->assertSame( $saves, $module->saves, 'Encryption failure must preserve saved credentials' );
	}

	public function test_plaintext_is_never_used_as_a_fallback(): void {
		$module                             = new DropboxTestModule();
		$storage                            = $this->storage( $module );
		$module->options['tk_access_token'] = $this->plain;
		$this->assert_fails(
			function () use ( $storage ) {
				$storage->get( 'access_token' );
			},
			'Plaintext must never be used as a fallback'
		);
	}

	private function legacy(): array {
		return json_decode( file_get_contents( BACKUPPLUS_DIR . '/scripts/fixtures/dropbox-legacy-token.json' ), true );
	}

	/** The saved settings of a pre-change installation. */
	private function legacy_settings(): array {
		return array(
			'version'  => 1,
			'settings' => array(
				'instance' => array(
					'folder'           => 'test',
					'tk_access_token'  => $this->legacy()['ciphertext'],
					'tk_request_token' => $this->token,
				),
			),
		);
	}

	/** The optional maintenance shortcut, run the way WP-CLI would. */
	private function run_migration( string $mode ): void {
		$args = array( $mode );
		require BACKUPPLUS_DIR . '/scripts/migrate-dropbox-tokens.php';
	}

	public function test_the_runtime_rejects_shared_key_cbc_credentials(): void {
		$this->assertFalse( $this->encrypter->decrypt( $this->legacy()['ciphertext'] ), 'The runtime must reject shared-key CBC credentials' );
	}

	public function test_an_explicit_import_converts_legacy_credentials(): void {
		$legacy   = $this->legacy();
		$imported = BackupPlus_Dropbox_Token_Migration::import_options( $this->legacy_settings() );
		$this->assertSame( base64_decode( $legacy['plaintext'] ), $this->encrypter->decrypt( $imported['settings']['instance']['tk_access_token'] ), 'An explicit UpdraftPlus import must convert the hardcoded-key CBC fixture to ChaCha20-Poly1305' );
		$this->assertSame( $this->plain, $this->encrypter->decrypt( $imported['settings']['instance']['tk_request_token'] ), 'An explicit import must encrypt legacy plaintext request tokens too' );
		$this->assertSame( $imported, BackupPlus_Dropbox_Token_Migration::import_options( $imported ), 'Importing current authenticated tokens must preserve them' );
	}

	public function test_a_wrong_migration_mode_does_not_decode_or_mutate_legacy_credentials(): void {
		$base                        = $this->legacy_settings();
		BackupPlus_Options::$options = $base;
		$this->assert_fails(
			function () {
				$this->run_migration( 'rotate' );
			},
			'Rotation must not silently decode legacy credentials'
		);
		$this->assertSame( $base, BackupPlus_Options::$options, 'Wrong migration mode must not mutate settings' );
		$this->assertSame( 0, BackupPlus_Options::$saves, 'Wrong migration mode must not mutate settings' );
	}

	public function test_the_legacy_migration_is_explicit_and_idempotent(): void {
		$legacy                      = $this->legacy();
		BackupPlus_Options::$options = $this->legacy_settings();
		$this->run_migration( 'migrate-legacy' );
		$migrated = BackupPlus_Options::$options;
		$this->assertSame( base64_decode( $legacy['plaintext'] ), $this->encrypter->decrypt( $migrated['settings']['instance']['tk_access_token'] ), 'The saved pre-change CBC fixture must migrate' );
		$this->assertSame( $this->plain, $this->encrypter->decrypt( $migrated['settings']['instance']['tk_request_token'] ), 'Old plaintext request tokens must migrate only explicitly' );
		$this->run_migration( 'migrate-legacy' );
		$this->assertSame( $migrated, BackupPlus_Options::$options, 'Re-running legacy migration must be a no-op' );
		$this->assertSame( 1, BackupPlus_Options::$saves, 'Re-running legacy migration must be a no-op' );
	}

	public function test_rotation_works_after_retiring_the_old_key_and_diagnostics_contain_no_secrets(): void {
		BackupPlus_Options::$options = $this->legacy_settings();
		$this->run_migration( 'migrate-legacy' );
		BackupPlus_Options::$options['settings']['instance']['tk_access_token'] = $this->old_encrypter()->encrypt( $this->plain );
		$this->run_migration( 'rotate' );
		$rotated  = BackupPlus_Options::$options['settings']['instance']['tk_access_token'];
		$new_only = new Dropbox_Encrypter( array( 'new' => $this->keys['new'] ), 'new', $this->context );
		$this->assertSame( $this->plain, $new_only->decrypt( $rotated ), 'Rotated credentials must work after retiring the old key' );
		$this->assertFalse( $new_only->decrypt( $this->old_encrypter()->encrypt( $this->plain ) ), 'Retired keys must not decrypt old database copies' );
		$this->assertNotEmpty( WP_CLI::$messages, 'Migrations must report their result' );
		foreach ( WP_CLI::$messages as $message ) {
			$this->assertFalse( strpos( $message, 'disposable-' ), 'Migration diagnostics must contain no secrets' );
			$this->assertFalse( strpos( $message, $this->keys['new'] ), 'Migration diagnostics must contain no secrets' );
		}
	}

	/** @return iterable<string, array{0: string}> */
	public static function malformed_credentials(): iterable {
		yield 'invalid legacy' => array( 'invalid legacy' );
		yield 'unknown key id' => array( 'bpcred:v1:unknown:' . base64_encode( str_repeat( 'x', 60 ) ) );
	}

	#[DataProvider( 'malformed_credentials' )]
	public function test_malformed_or_unknown_credentials_abort_the_complete_migration( string $invalid ): void {
		$bad                         = $this->legacy_settings();
		$bad['settings']['broken']   = array( 'tk_access_token' => $invalid );
		BackupPlus_Options::$options = $bad;
		delete_option( BackupPlus_Dropbox_Token_Migration::OPTION );
		$this->assert_fails(
			function () {
				BackupPlus_Dropbox_Token_Migration::upgrade_restored();
			},
			'Malformed/unknown credentials must abort the complete migration'
		);
		$this->assertSame( $bad, BackupPlus_Options::$options, 'A bad later entry must preserve all original ciphertexts' );
	}

	public function test_a_persistence_failure_is_reported_and_retains_the_original_credentials(): void {
		$base                          = $this->legacy_settings();
		BackupPlus_Options::$options   = $base;
		BackupPlus_Options::$fail_save = true;
		delete_option( BackupPlus_Dropbox_Token_Migration::OPTION );
		$this->assert_fails(
			function () {
				BackupPlus_Dropbox_Token_Migration::upgrade_restored();
			},
			'Persistence failure must be reported'
		);
		$this->assertSame( $base, BackupPlus_Options::$options, 'Failed persistence must retain original credentials' );
	}

	/**
	 * A new PHP process restores only the DB/settings export and the separate server config.
	 *
	 * @return array<string, mixed> What the process reported.
	 */
	private function restore_in_new_process( bool $with_key ): array {
		$encrypted = $this->encrypter->encrypt( $this->plain );
		file_put_contents(
			$this->fixture . '/database.json',
			json_encode(
				array(
					'token'          => $encrypted,
					'plaintext_hash' => hash( 'sha256', $this->plain ),
				)
			)
		);
		file_put_contents( $this->fixture . '/server-config.php', '<?php define("BACKUPPLUS_CREDENTIAL_KEY_DIR", ' . var_export( $this->key_dir, true ) . ');' );
		chmod( $this->fixture . '/server-config.php', 0600 );
		$db = file_get_contents( $this->fixture . '/database.json' );
		foreach ( $this->keys as $encoded ) {
			$this->assertFalse( strpos( $db, $encoded ), 'The settings export must contain no key material' );
			$this->assertFalse( strpos( $db, base64_decode( $encoded ) ), 'The settings export must contain no key material' );
		}
		$arguments = array( 'restore', $this->fixture . '/database.json' );
		if ( $with_key ) {
			$arguments[] = $this->fixture . '/server-config.php';
		}
		$child = $this->run_child( $arguments );
		$this->assertSame( 0, $child['status'], 'A fresh process must enforce restore/key requirements: ' . $child['stderr'] );
		return json_decode( $child['stdout'], true );
	}

	public function test_a_database_copy_alone_cannot_restore_credentials(): void {
		$result = $this->restore_in_new_process( false );
		$this->assertTrue( $result['failed'], 'A database copy alone must not restore credentials' );
	}

	public function test_restart_with_separate_key_material_restores_credentials(): void {
		$result = $this->restore_in_new_process( true );
		$this->assertTrue( $result['hash_matches'], 'Restart/restore with separate key material must work' );
	}
}
