<?php
/** SEC-005 policy regression using real option/module/job APIs and disposable keys. */

namespace BackupPlus\Tests\Unit\Credentials;

use BackupPlus;
use BackupPlus_Credential_Key_Rotation;
use BackupPlus_Credential_Key_Store;
use BackupPlus_Credential_Storage;
use BackupPlus_Options;
use BackupPlus_Pcloud_Credential_Migration;
use Credential_Test_State;
use Exception;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class CredentialStorageTest extends TestCase {

	private BackupPlus $backupplus;

	protected function setUp(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/credential-storage-stubs.php';
		Credential_Test_State::reset();
		require_once BACKUPPLUS_DIR . '/class-backupplus.php';
		require_once BACKUPPLUS_DIR . '/options.php';
		require_once BACKUPPLUS_DIR . '/methods/backup-module.php';
		require_once BACKUPPLUS_DIR . '/includes/class-credential-encrypter.php';
		require_once BACKUPPLUS_DIR . '/includes/class-credential-storage.php';
		require_once BACKUPPLUS_DIR . '/methods/pcloud/bootstrap.php';
		$this->backupplus      = new class() extends BackupPlus {
			public function __construct() {}
		};
		$GLOBALS['backupplus'] = $this->backupplus;
		$this->backupplus->ensure_phpseclib();
		BackupPlus_Credential_Key_Store::$config   = array(
			'active' => 'original',
			'keys'   => array( 'original' => base64_encode( random_bytes( 32 ) ) ),
		);
		BackupPlus_Credential_Key_Store::$complete = false;
		BackupPlus_Credential_Storage::register();
	}

	/** The callback must throw. */
	private function assertRejects( callable $callback, string $message ): void {
		try {
			$callback();
		} catch ( Exception $e ) {
			$this->addToAssertionCount( 1 );
			return;
		}
		$this->fail( $message );
	}

	/** None of the synthetic credentials may appear in the raw stored value. */
	private function assertRawSafe( $value, array $secrets ): void {
		foreach ( $secrets as $secret ) {
			$this->assertStringNotContainsString( $secret, serialize( $value ), 'Raw storage exposed a synthetic credential' );
		}
	}

	/** Secret fields by provider. */
	public static function provider_secrets(): array {
		return array(
			'ftp'          => array( 'ftp', array( 'pass' => 'synthetic FTP password' ) ),
			'sftp'         => array(
				'sftp',
				array(
					'pass'           => 'synthetic SSH password',
					'key'            => "-----BEGIN PRIVATE KEY-----\nsynthetic SSH identity",
					'key_passphrase' => 'synthetic SSH key passphrase',
				),
			),
			'webdav'       => array(
				'webdav',
				array(
					'pass' => 'synthetic WebDAV password',
					'url'  => 'https://user:synthetic-url-password@host.test/backups',
				),
			),
			's3'           => array(
				's3',
				array(
					'secretkey'    => 'synthetic S3 secret',
					'sessiontoken' => 'synthetic S3 session',
				),
			),
			's3generic'    => array( 's3generic', array( 'secretkey' => 'synthetic S3 compatible secret' ) ),
			'dreamobjects' => array( 'dreamobjects', array( 'secretkey' => 'synthetic DreamObjects secret' ) ),
			'cloudfiles'   => array( 'cloudfiles', array( 'apikey' => 'synthetic Rackspace key' ) ),
			'openstack'    => array(
				'openstack',
				array(
					'password' => 'synthetic OpenStack password',
					'apikey'   => 'synthetic OpenStack key',
				),
			),
			'googledrive'  => array(
				'googledrive',
				array(
					'secret' => 'synthetic OAuth client secret',
					'token'  => array( 'refresh_token' => 'synthetic OAuth refresh' ),
				),
			),
			'azure'        => array( 'azure', array( 'accountkey' => 'synthetic Azure account key' ) ),
			'backblaze'    => array(
				'backblaze',
				array(
					'application_key_id' => 'synthetic B2 key ID',
					'application_key'    => 'synthetic B2 application key',
				),
			),
			'googlecloud'  => array(
				'googlecloud',
				array(
					'service_account' => '{"private_key":"synthetic service account identity"}',
					'secret'          => 'synthetic Cloud OAuth secret',
					'token'           => 'synthetic Cloud refresh token',
				),
			),
			'onedrive'     => array(
				'onedrive',
				array(
					'client_secret' => 'synthetic Microsoft client secret',
					'refresh_token' => 'synthetic Microsoft refresh token',
				),
			),
			'pcloud'       => array(
				'pcloud',
				array(
					'pclauth'       => 'synthetic pCloud access token',
					'client_secret' => 'synthetic pCloud app secret',
				),
			),
		);
	}

	/** A versioned provider option with one instance holding the secrets. */
	private static function provider_value( array $secrets ): array {
		return array(
			'version'  => 1,
			'settings' => array(
				'one' => array_merge(
					array(
						'host' => 'host.test',
						'user' => 'user',
					),
					$secrets
				),
			),
		);
	}

	/** Save a provider option through the option API and return the value that was saved. */
	private function store_provider( string $provider ): array {
		$value = self::provider_value( self::provider_secrets()[ $provider ][1] );
		BackupPlus_Options::update_backupplus_option( 'backupplus_' . $provider, $value );
		return $value;
	}

	/** A job holding credentials in every persistence path, as saved by the job API. */
	private function store_job(): array {
		$this->store_provider( 'ftp' );
		$this->backupplus->nonce            = 'disposable-job';
		$job                                = array(
			'job_type'               => 'backup',
			'option_cache'           => array( 'backupplus_ftp' => BackupPlus_Options::get_backupplus_option( 'backupplus_ftp' ) ),
			'filesystem_credentials' => array( 'password' => 'synthetic filesystem password' ),
			'backup_database'        => array(
				'external' => array(
					'status' => 'begun',
					'dbinfo' => array(
						'host' => 'db.test',
						'pass' => 'synthetic external DB snapshot password',
					),
				),
			),
		);
		$job['backupplus_external_targets'] = array(
			'db7' => array(
				'connection' => array(
					'pass'     => 'synthetic restore password',
					'password' => 'synthetic restore alias password',
				),
			),
		);
		$job['restore_options']['backupplus_external_targets']                     = $job['backupplus_external_targets'];
		$job['backupplus_restorer_restore_options']['backupplus_external_targets'] = $job['backupplus_external_targets'];
		$this->backupplus->jobdata_set_from_array( $job );
		return $job;
	}

	/** The key configuration with additional keys, the last one active. */
	private function rotated_config( string ...$names ): array {
		$config = BackupPlus_Credential_Key_Store::$config;
		foreach ( $names as $name ) {
			$config['keys'][ $name ] = base64_encode( random_bytes( 32 ) );
			$config['active']        = $name;
		}
		return $config;
	}

	#[DataProvider( 'provider_secrets' )]
	public function test_provider_options_preserve_credential_types_and_content_but_store_secrets_in_the_shared_envelope( string $provider, array $secrets ): void {
		$name  = 'backupplus_' . $provider;
		$value = self::provider_value( $secrets );
		BackupPlus_Options::update_backupplus_option( $name, $value );
		$this->assertSame( $value, BackupPlus_Options::get_backupplus_option( $name ), 'Provider option API must preserve credential types and content: ' . $provider );
		foreach ( $secrets as $field => $secret ) {
			$this->assertStringStartsWith( 'bpcred:v1:', get_option( $name )['settings']['one'][ $field ], 'Provider field must use the shared authenticated envelope' );
		}
		$this->assertSame( 'host.test', get_option( $name )['settings']['one']['host'], 'Ordinary options must stay readable' );
	}

	/** pCloud's legacy field was not recognized by the earlier generic policy. */
	private function legacy_pcloud(): array {
		return array(
			'version'  => 1,
			'settings' => array(
				'old' => array(
					'pclauth' => 'synthetic legacy pCloud token',
					'path'    => 'backups',
				),
			),
		);
	}

	public function test_a_failed_pcloud_migration_save_is_reported_and_preserves_the_original_settings(): void {
		$legacy_pcloud = $this->legacy_pcloud();
		Credential_Test_State::$options[1]['backupplus_pcloud'] = $legacy_pcloud;
		BackupPlus_Credential_Key_Store::$complete              = true;
		Credential_Test_State::$fail_save                       = 'backupplus_pcloud';
		$this->assertRejects(
			function () {
				BackupPlus_Pcloud_Credential_Migration::upgrade();
			},
			'A failed pCloud migration save must be reported'
		);
		$this->assertSame( $legacy_pcloud, get_option( 'backupplus_pcloud' ), 'A failed pCloud migration must preserve the original settings' );
	}

	public function test_pcloud_migration_encrypts_legacy_tokens_and_preserves_settings_on_an_already_upgraded_site(): void {
		$legacy_pcloud = $this->legacy_pcloud();
		Credential_Test_State::$options[1]['backupplus_pcloud'] = $legacy_pcloud;
		BackupPlus_Credential_Key_Store::$complete              = true;
		Credential_Test_State::$fail_save                       = 'backupplus_pcloud';
		$this->assertRejects(
			function () {
				BackupPlus_Pcloud_Credential_Migration::upgrade();
			},
			'A failed pCloud migration save must be reported'
		);
		Credential_Test_State::$fail_save = null;
		BackupPlus_Pcloud_Credential_Migration::upgrade();
		$this->assertRawSafe( get_option( 'backupplus_pcloud' ), array( 'synthetic legacy pCloud token' ) );
		$this->assertSame( $legacy_pcloud, BackupPlus_Options::get_backupplus_option( 'backupplus_pcloud' ), 'pCloud migration must preserve settings on an already upgraded site' );
	}

	public function test_pcloud_migration_is_idempotent(): void {
		Credential_Test_State::$options[1]['backupplus_pcloud'] = $this->legacy_pcloud();
		BackupPlus_Credential_Key_Store::$complete              = true;
		BackupPlus_Pcloud_Credential_Migration::upgrade();
		$migrated_pcloud = get_option( 'backupplus_pcloud' );
		BackupPlus_Pcloud_Credential_Migration::upgrade();
		$this->assertSame( $migrated_pcloud, get_option( 'backupplus_pcloud' ), 'pCloud migration must be idempotent' );
	}

	public function test_extension_fields_share_the_policy_without_depending_on_the_cipher_or_key_lifecycle(): void {
		// Extensions specify field names, with no dependency on the cipher or key lifecycle.
		add_filter(
			'backupplus_credential_secret_fields',
			function ( $fields, $option ) {
				if ( 'backupplus_future' === $option ) {
					$fields[] = 'new_secret';
				} return $fields;
			},
			10,
			2
		);
		update_option( 'backupplus_future', array( 'new_secret' => 'synthetic extension credential' ) );
		$this->assertRawSafe( get_option( 'backupplus_future' ), array( 'synthetic extension credential' ) );
		$this->assertSame( 'synthetic extension credential', BackupPlus_Options::get_backupplus_option( 'backupplus_future' )['new_secret'], 'Extension fields must share the policy' );
	}

	public function test_direct_wordpress_saves_and_the_provider_sanitizer_see_plaintext(): void {
		$calls = 0;
		add_filter(
			'sanitize_option_backupplus_sftp',
			function ( $value ) use ( &$calls ) {
				++$calls;
				Assert::assertSame( 'synthetic SSH identity via form', $value['settings']['one']['key'], 'Provider sanitizers must receive plaintext even on double sanitization' );
				return $value;
			},
			10,
			2
		);
		update_option(
			'backupplus_sftp',
			array(
				'version'  => 1,
				'settings' => array( 'one' => array( 'key' => 'synthetic SSH identity via form' ) ),
			)
		);
		update_option( 'backupplus_sftp', get_option( 'backupplus_sftp' ) );
		$this->assertGreaterThan( 1, $calls, 'The provider sanitizer must run for every save' );
		$this->assertRawSafe( get_option( 'backupplus_sftp' ), array( 'synthetic SSH identity via form' ) );
	}

	public function test_saving_unchanged_settings_keeps_the_stored_ciphertext_and_a_changed_secret_gets_a_new_one(): void {
		$settings = array(
			'version'  => 1,
			'settings' => array(
				'one' => array(
					'host' => 'one.example',
					'pass' => 'synthetic first password',
					'key'  => 'synthetic key',
				),
			),
		);
		update_option( 'backupplus_sftp', $settings );
		$stored = get_option( 'backupplus_sftp' );
		$this->assertNotSame( 'synthetic first password', $stored['settings']['one']['pass'], 'The password must be stored as an envelope' );

		// The Settings page posts plaintext back for secrets that were not edited.
		update_option( 'backupplus_sftp', array_replace_recursive( $stored, array( 'settings' => array( 'one' => array( 'host' => 'one.example' ) ) ) ) );
		$this->assertSame( $stored, get_option( 'backupplus_sftp' ), 'Re-saving unchanged settings must not change the stored value' );

		$resaved                            = BackupPlus_Options::get_backupplus_option( 'backupplus_sftp' );
		$resaved['settings']['one']['pass'] = 'synthetic second password';
		update_option( 'backupplus_sftp', $resaved );
		$changed = get_option( 'backupplus_sftp' );
		$this->assertNotSame( $stored['settings']['one']['pass'], $changed['settings']['one']['pass'], 'A changed secret needs a new envelope' );
		$this->assertSame( $stored['settings']['one']['key'], $changed['settings']['one']['key'], 'Untouched secrets keep their envelope' );
		$this->assertSame( 'synthetic second password', BackupPlus_Options::get_backupplus_option( 'backupplus_sftp' )['settings']['one']['pass'] );
	}

	public function test_swapping_instances_rejects_the_ciphertext(): void {
		$this->store_provider( 'ftp' );
		$wrong_instance                    = get_option( 'backupplus_ftp' );
		$wrong_instance['settings']['two'] = $wrong_instance['settings']['one'];
		$this->assertRejects(
			function () use ( $wrong_instance ) {
				BackupPlus_Credential_Storage::incoming( $wrong_instance, 'backupplus_ftp' );
			},
			'Swapping instances must reject the ciphertext'
		);
	}

	public function test_swapping_providers_rejects_the_ciphertext(): void {
		$this->store_provider( 'ftp' );
		$original = get_option( 'backupplus_ftp' );
		$this->assertRejects(
			function () use ( $original ) {
				BackupPlus_Credential_Storage::incoming( $original, 'backupplus_webdav' );
			},
			'Swapping providers must reject the ciphertext'
		);
	}

	public function test_swapping_secret_fields_rejects_the_ciphertext(): void {
		$this->store_provider( 'ftp' );
		$wrong_field                           = get_option( 'backupplus_ftp' );
		$wrong_field['settings']['one']['key'] = $wrong_field['settings']['one']['pass'];
		$this->assertRejects(
			function () use ( $wrong_field ) {
				BackupPlus_Credential_Storage::incoming( $wrong_field, 'backupplus_ftp' );
			},
			'Swapping secret fields must reject the ciphertext'
		);
	}

	public function test_swapping_sites_rejects_the_ciphertext(): void {
		$this->store_provider( 'ftp' );
		$original                       = get_option( 'backupplus_ftp' );
		Credential_Test_State::$blog_id = 2;
		$this->assertRejects(
			function () use ( $original ) {
				BackupPlus_Credential_Storage::incoming( $original, 'backupplus_ftp' );
			},
			'Swapping sites must reject the ciphertext'
		);
	}

	public function test_tampered_ciphertext_is_not_saved_and_rejected_writes_retain_credentials(): void {
		$this->store_provider( 'ftp' );
		$original                        = get_option( 'backupplus_ftp' );
		$bad                             = $original;
		$bad['settings']['one']['pass'] .= 'A';
		$this->assertRejects(
			function () use ( $bad ) {
				update_option( 'backupplus_ftp', $bad );
			},
			'Tampered ciphertext must not be saved'
		);
		$this->assertSame( $original, get_option( 'backupplus_ftp' ), 'Rejected writes must retain credentials' );
	}

	public function test_jobs_resume_with_decrypted_options_while_every_persistence_path_is_encrypted(): void {
		$job = $this->store_job();
		$this->assertRawSafe( get_site_option( 'backupplus_jobdata_disposable-job' ), array( 'synthetic FTP password', 'synthetic filesystem password', 'synthetic external DB snapshot password', 'synthetic restore password', 'synthetic restore alias password' ) );
		$this->assertSame( $job, $this->backupplus->jobdata_getarray( 'disposable-job' ), 'Jobs must resume with decrypted options' );
	}

	public function test_progress_updates_reuse_unchanged_job_credentials_across_resumptions(): void {
		$this->store_job();
		$before = get_site_option( 'backupplus_jobdata_disposable-job' );
		$this->backupplus->jobdata_reset();
		$this->backupplus->jobdata_set( 'progress', 7 );
		$after = get_site_option( 'backupplus_jobdata_disposable-job' );
		unset( $after['progress'] );
		$this->assertSame( $before, $after, 'Progress must not re-encrypt unchanged credentials anywhere in the job.' );
	}

	public function test_changed_job_credentials_and_new_keys_get_new_authenticated_envelopes(): void {
		$job                               = $this->store_job();
		$before                            = get_site_option( 'backupplus_jobdata_disposable-job' )['option_cache'];
		$job['option_cache']['additional'] = 'changed synthetic credential';
		$this->backupplus->jobdata_set_from_array( $job );
		$this->assertNotSame( $before, get_site_option( 'backupplus_jobdata_disposable-job' )['option_cache'] );
		$this->assertSame( $job, $this->backupplus->jobdata_getarray( 'disposable-job' ) );
		BackupPlus_Credential_Key_Store::$config['keys']['next'] = base64_encode( random_bytes( 32 ) );
		BackupPlus_Credential_Key_Store::$config['active']       = 'next';
		$this->backupplus->jobdata_reset();
		$this->backupplus->jobdata_set( 'progress', 8 );
		$this->assertStringStartsWith( 'bpcred:v1:next:', get_site_option( 'backupplus_jobdata_disposable-job' )['option_cache'] );
	}

	public function test_job_bookkeeping_remains_readable_and_ciphertext_is_bound_to_its_job(): void {
		$this->store_job();
		$this->backupplus->jobdata_reset();
		$this->backupplus->jobdata_set_multi( 'jobstatus', 'running' );
		$this->backupplus->jobdata_set( 'progress', 2 );
		$this->backupplus->jobdata_delete( 'progress' );
		$this->assertSame( 'running', get_site_option( 'backupplus_jobdata_disposable-job' )['jobstatus'], 'Job bookkeeping remains readable' );
		$stored = Credential_Test_State::$site_options['backupplus_jobdata_disposable-job'];
		$this->assertRejects(
			function () use ( $stored ) {
				BackupPlus_Credential_Storage::job( $stored, 'another-job', 'decrypt' );
			},
			'Job ciphertext cannot move to a different job'
		);
	}

	/** Flat settings, phrases, extra databases and old jobs, with already encrypted data in place. */
	private function seed_legacy_settings(): void {
		$this->store_provider( 'pcloud' );
		$this->store_job();
		Credential_Test_State::$options[1]['backupplus_ftp']                  = array( 'pass' => 'synthetic legacy flat password' );
		Credential_Test_State::$options[1]['backupplus_encryptionphrase']     = 'synthetic legacy archive phrase';
		Credential_Test_State::$options[1]['backupplus_extradbs']             = array( array( 'password' => 'synthetic extra DB password' ) );
		Credential_Test_State::$site_options['backupplus_jobdata_legacy-job'] = array( 'option_cache' => array( 'secret' => 'synthetic legacy job password' ) );
	}

	public function test_migration_encrypts_flat_settings_phrases_extra_databases_and_old_jobs(): void {
		$this->seed_legacy_settings();
		BackupPlus_Credential_Storage::upgrade();
		$this->assertTrue( BackupPlus_Credential_Key_Store::$complete, 'Migration records completion only after saved replacements verify' );
		$this->assertRawSafe( Credential_Test_State::$options, array( 'synthetic legacy flat password', 'synthetic legacy archive phrase', 'synthetic extra DB password' ) );
		$this->assertRawSafe( Credential_Test_State::$site_options, array( 'synthetic legacy job password' ) );
		$this->assertSame( 'synthetic legacy archive phrase', BackupPlus_Options::get_backupplus_option( 'backupplus_encryptionphrase' ), 'Legacy phrases remain a read-only restore fallback' );
	}

	public function test_a_completed_migration_does_not_reopen_plaintext_reads_until_an_explicit_upgrade(): void {
		$this->seed_legacy_settings();
		BackupPlus_Credential_Storage::upgrade();
		Credential_Test_State::$options[1]['backupplus_ftp'] = array( 'pass' => 'injected plaintext after migration' );
		BackupPlus_Credential_Storage::upgrade();
		$this->assertSame( '', BackupPlus_Options::get_backupplus_option( 'backupplus_ftp' )['pass'], 'Completed migration does not reopen plaintext reads' );
		BackupPlus_Credential_Storage::upgrade( true );
		$this->assertSame( 'injected plaintext after migration', BackupPlus_Options::get_backupplus_option( 'backupplus_ftp' )['pass'], 'Explicit restored-settings upgrade can convert legacy data' );
	}

	/** A flat FTP option and a job snapshot, both under the original key. */
	private function store_flat_ftp_and_job(): void {
		$this->store_job();
		update_option( 'backupplus_ftp', array( 'pass' => 'synthetic flat FTP password' ) );
	}

	public function test_rotation_rewrites_provider_credentials_and_job_snapshots_with_the_active_key_and_keeps_old_keys(): void {
		$this->store_flat_ftp_and_job();
		$config                                  = $this->rotated_config( 'rotated' );
		BackupPlus_Credential_Key_Store::$config = $config;
		BackupPlus_Credential_Storage::rewrite();
		$this->assertStringContainsString( ':rotated:', get_option( 'backupplus_ftp' )['pass'], 'Rotation must rewrite provider credentials with the active key' );
		$this->assertStringContainsString( ':rotated:', get_site_option( 'backupplus_jobdata_disposable-job' )['option_cache'], 'Rotation must rewrite job snapshots' );
		BackupPlus_Credential_Storage::verify( $config );
		$this->assertSame( 'synthetic flat FTP password', BackupPlus_Options::get_backupplus_option( 'backupplus_ftp' )['pass'], 'Rotation must retain readable credentials' );
	}

	public function test_recovery_verifies_generic_provider_and_job_credentials(): void {
		$this->store_flat_ftp_and_job();
		$wrong = array(
			'active' => 'wrong',
			'keys'   => array( 'wrong' => base64_encode( random_bytes( 32 ) ) ),
		);
		$this->assertRejects(
			function () use ( $wrong ) {
				BackupPlus_Credential_Storage::verify( $wrong );
			},
			'Recovery verifies generic provider and job credentials'
		);
	}

	public function test_missing_keys_return_no_usable_secret_and_fail_closed(): void {
		$this->store_flat_ftp_and_job();
		$config                                  = BackupPlus_Credential_Key_Store::$config;
		$persisted                               = get_option( 'backupplus_ftp' );
		BackupPlus_Credential_Key_Store::$config = null;
		$this->assertSame( '', BackupPlus_Options::get_backupplus_option( 'backupplus_ftp' )['pass'], 'Missing keys return no usable secret' );
		$this->assertRejects(
			function () {
				update_option( 'backupplus_ftp', array( 'pass' => '' ) );
			},
			'Saving redacted fields with a missing key must preserve ciphertext'
		);
		$this->assertRejects(
			function () {
				update_option( 'backupplus_ftp', array( 'pass' => 'synthetic replacement' ) );
			},
			'Missing keys must prohibit plaintext writes'
		);
		$this->assertSame( $persisted, get_option( 'backupplus_ftp' ), 'Missing-key failures must retain credentials' );
		BackupPlus_Credential_Key_Store::$config = $config;
		$this->assertSame( 'synthetic flat FTP password', BackupPlus_Options::get_backupplus_option( 'backupplus_ftp' )['pass'], 'Restoring the keys must make the credentials readable again' );
	}

	public function test_failed_migration_saves_do_not_mark_completion_and_preserve_the_old_credential_value(): void {
		Credential_Test_State::$fail_save                                 = 'backupplus_encryptionphrase';
		Credential_Test_State::$options[1]['backupplus_encryptionphrase'] = 'synthetic migration save failure';
		BackupPlus_Credential_Key_Store::$complete                        = false;
		$this->assertRejects(
			function () {
				BackupPlus_Credential_Storage::upgrade();
			},
			'Failed migration saves must not mark completion'
		);
		$this->assertFalse( BackupPlus_Credential_Key_Store::$complete, 'Failed saves preserve their old credential value' );
		$this->assertSame( 'synthetic migration save failure', get_option( 'backupplus_encryptionphrase' ), 'Failed saves preserve their old credential value' );
	}

	/** Two sites with an FTP password each, and the key rotated to a network key. Returns the second site's stored option. */
	private function prepare_network_rotation(): array {
		Credential_Test_State::$multisite = true;
		BackupPlus_Credential_Storage::upgrade( true );
		update_option( 'backupplus_ftp', array( 'pass' => 'synthetic first site password' ) );
		Credential_Test_State::$blog_id = 2;
		update_option( 'backupplus_ftp', array( 'pass' => 'synthetic second site password' ) );
		$second_before                  = get_option( 'backupplus_ftp' );
		Credential_Test_State::$blog_id = 1;
		require_once BACKUPPLUS_DIR . '/includes/class-credential-key-rotation.php';
		BackupPlus_Credential_Key_Store::$config = $this->rotated_config( 'network' );
		return $second_before;
	}

	public function test_a_failed_site_prevents_network_completion_and_retains_readable_credentials_under_its_old_key(): void {
		$second_before                    = $this->prepare_network_rotation();
		Credential_Test_State::$fail_save = 'backupplus_ftp';
		Credential_Test_State::$fail_blog = 2;
		$this->assertRejects(
			function () {
				BackupPlus_Credential_Key_Rotation::reencrypt_credentials( 'network' );
			},
			'A failed site must prevent network completion'
		);
		$this->assertSame( 'network', get_option( BackupPlus_Credential_Key_Rotation::REWRITTEN_KEY_OPTION ), 'Successful sites record rotation completion' );
		Credential_Test_State::$blog_id = 2;
		$this->assertSame( $second_before, get_option( 'backupplus_ftp' ), 'A failed site retains readable credentials under its old key' );
	}

	public function test_a_retry_skips_completed_sites_and_re_encrypts_unfinished_ones_without_losing_credentials(): void {
		$this->prepare_network_rotation();
		Credential_Test_State::$fail_save = 'backupplus_ftp';
		Credential_Test_State::$fail_blog = 2;
		$this->assertRejects(
			function () {
				BackupPlus_Credential_Key_Rotation::reencrypt_credentials( 'network' );
			},
			'A failed site must prevent network completion'
		);
		$first_completed                  = get_option( 'backupplus_ftp' );
		Credential_Test_State::$fail_save = null;
		Credential_Test_State::$fail_blog = null;
		BackupPlus_Credential_Key_Rotation::reencrypt_credentials( 'network' );
		$this->assertSame( $first_completed, get_option( 'backupplus_ftp' ), 'A retry skips completed sites' );
		Credential_Test_State::$blog_id = 2;
		$this->assertSame( 'network', get_option( BackupPlus_Credential_Key_Rotation::REWRITTEN_KEY_OPTION ), 'A retry re-encrypts unfinished sites without losing credentials' );
		$this->assertSame( 'synthetic second site password', BackupPlus_Options::get_backupplus_option( 'backupplus_ftp' )['pass'], 'A retry re-encrypts unfinished sites without losing credentials' );
	}

	public function test_network_ciphertext_detection_restores_the_original_blog_context(): void {
		// Only the second site holds ciphertext, so the detection has to switch sites to find it.
		Credential_Test_State::$multisite = true;
		Credential_Test_State::$blog_id   = 2;
		update_option( 'backupplus_ftp', array( 'pass' => 'synthetic second site password' ) );
		Credential_Test_State::$blog_id = 1;
		$this->assertTrue( BackupPlus_Credential_Storage::has_ciphertext(), 'Network ciphertext detection restores the original blog context' );
		$this->assertSame( 1, get_current_blog_id(), 'Network ciphertext detection restores the original blog context' );
	}

	public function test_exports_omit_connection_settings_and_phrases(): void {
		$this->prepare_network_rotation();
		$export = BackupPlus_Credential_Storage::export_omitted_options();
		foreach ( array( 'backupplus_ftp', 'backupplus_sftp', 'backupplus_dropbox', 'backupplus_encryptionphrase', 'backupplus_extradbs' ) as $name ) {
			$this->assertContains( $name, $export, 'Exports must omit connection settings and phrases' );
		}
	}
}
