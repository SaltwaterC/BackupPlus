<?php
/** BPS-20: saved secrets reach pages only as a placeholder, and a placeholder submitted back keeps the saved secret. */

namespace BackupPlus\Tests\Unit\Credentials;

use BackupPlus;
use BackupPlus_Credential_Key_Store;
use BackupPlus_Credential_Storage;
use BackupPlus_Options;
use Credential_Test_State;
use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class CredentialPlaceholderTest extends TestCase {

	protected function setUp(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/credential-storage-stubs.php';
		Credential_Test_State::reset();
		require_once BACKUPPLUS_DIR . '/class-backupplus.php';
		require_once BACKUPPLUS_DIR . '/options.php';
		require_once BACKUPPLUS_DIR . '/methods/backup-module.php';
		require_once BACKUPPLUS_DIR . '/includes/class-credential-encrypter.php';
		require_once BACKUPPLUS_DIR . '/includes/class-credential-storage.php';
		require_once BACKUPPLUS_DIR . '/methods/pcloud/bootstrap.php';
		$backupplus            = new class() extends BackupPlus {
			public function __construct() {}
		};
		$GLOBALS['backupplus'] = $backupplus;
		$backupplus->ensure_phpseclib();
		BackupPlus_Credential_Key_Store::$config   = array(
			'active' => 'original',
			'keys'   => array( 'original' => base64_encode( random_bytes( 32 ) ) ),
		);
		BackupPlus_Credential_Key_Store::$complete = false;
		BackupPlus_Credential_Storage::register();
	}

	/** Secret fields as each provider's settings page shows them. */
	public static function provider_secrets(): array {
		return array(
			'ftp'         => array( 'ftp', array( 'pass' => 'synthetic FTP password' ) ),
			'sftp'        => array(
				'sftp',
				array(
					'pass'           => 'synthetic SSH password',
					'key'            => "-----BEGIN PRIVATE KEY-----\nsynthetic SSH identity",
					'key_passphrase' => 'synthetic SSH key passphrase',
				),
			),
			'webdav'      => array( 'webdav', array( 'pass' => 'synthetic WebDAV password' ) ),
			's3'          => array( 's3', array( 'secretkey' => 'synthetic S3 secret' ) ),
			'cloudfiles'  => array( 'cloudfiles', array( 'apikey' => 'synthetic Rackspace key' ) ),
			'openstack'   => array( 'openstack', array( 'password' => 'synthetic OpenStack password' ) ),
			'googledrive' => array(
				'googledrive',
				array(
					'secret' => 'synthetic OAuth client secret',
					'token'  => array( 'refresh_token' => 'synthetic OAuth refresh' ),
				),
			),
			'azure'       => array( 'azure', array( 'accountkey' => 'synthetic Azure account key' ) ),
			'backblaze'   => array(
				'backblaze',
				array(
					'application_key_id' => 'synthetic B2 key ID',
					'application_key'    => 'synthetic B2 application key',
				),
			),
			'googlecloud' => array(
				'googlecloud',
				array(
					'service_account' => '{"private_key":"synthetic service account identity"}',
					'secret'          => 'synthetic Cloud OAuth secret',
					'token'           => 'synthetic Cloud refresh token',
				),
			),
			'onedrive'    => array(
				'onedrive',
				array(
					'client_secret' => 'synthetic Microsoft client secret',
					'refresh_token' => 'synthetic Microsoft refresh token',
				),
			),
			'pcloud'      => array(
				'pcloud',
				array(
					'pclauth'       => 'synthetic pCloud access token',
					'client_secret' => 'synthetic pCloud app secret',
				),
			),
		);
	}

	/** Two instances of a provider, the second with different secrets of the same names. */
	private static function two_instances( array $secrets ): array {
		$other = array();
		foreach ( $secrets as $field => $secret ) {
			$other[ $field ] = is_array( $secret ) ? array( 'refresh_token' => 'other instance' ) : 'other instance ' . $secret;
		}
		return array(
			'version'  => 1,
			'settings' => array(
				's-one' => array_merge(
					array(
						'host'  => 'one.test',
						'empty' => '',
					),
					$secrets
				),
				's-two' => array_merge( array( 'host' => 'two.test' ), $other ),
			),
		);
	}

	/** The value a form submits back when nothing was edited: what the page was shown. */
	private static function shown( string $option ) {
		return BackupPlus_Credential_Storage::mask( BackupPlus_Options::get_backupplus_option( $option ), $option );
	}

	private function assertNoSecret( $value, array $secrets, string $where ): void {
		$text = serialize( $value );
		foreach ( $secrets as $secret ) {
			foreach ( (array) $secret as $part ) {
				$this->assertStringNotContainsString( $part, $text, $where . ' must not contain a saved secret' );
			}
		}
	}

	#[DataProvider( 'provider_secrets' )]
	public function test_pages_get_a_placeholder_for_every_saved_secret_and_nothing_else_changes( string $provider, array $secrets ): void {
		$option = 'backupplus_' . $provider;
		$value  = self::two_instances( $secrets );
		BackupPlus_Options::update_backupplus_option( $option, $value );
		$shown = self::shown( $option );
		$this->assertNoSecret( $shown, $secrets, 'The page value' );
		foreach ( array_keys( $secrets ) as $field ) {
			$this->assertSame( BackupPlus_Credential_Storage::PLACEHOLDER, $shown['settings']['s-one'][ $field ], "A saved $field must be shown as the placeholder" );
		}
		$this->assertSame( 'one.test', $shown['settings']['s-one']['host'], 'Ordinary settings must be shown' );
		$this->assertSame( '', $shown['settings']['s-one']['empty'], 'An empty field must stay empty' );
		$value['settings']['s-one']['pass'] = '';
		$this->assertSame( '', BackupPlus_Credential_Storage::mask( $value, $option )['settings']['s-one']['pass'], 'An unset secret must not be shown as saved' );
		$this->assertSame( BackupPlus_Credential_Storage::PLACEHOLDER, BackupPlus_Credential_Storage::mask( get_option( $option ), $option )['settings']['s-two'][ array_key_first( $secrets ) ], 'Envelopes must be masked as well' );
	}

	#[DataProvider( 'provider_secrets' )]
	public function test_submitting_the_placeholders_keeps_every_secret_and_its_envelope( string $provider, array $secrets ): void {
		$option = 'backupplus_' . $provider;
		$value  = self::two_instances( $secrets );
		BackupPlus_Options::update_backupplus_option( $option, $value );
		$stored = get_option( $option );

		// The form returns the placeholders, with an unrelated change.
		$submitted                              = self::shown( $option );
		$submitted['settings']['s-one']['path'] = 'changed/path';
		BackupPlus_Options::update_backupplus_option( $option, $submitted );

		$saved = get_option( $option );
		$this->assertStringNotContainsString( BackupPlus_Credential_Storage::PLACEHOLDER, serialize( $saved ), 'The placeholder must never be saved' );
		foreach ( array_keys( $secrets ) as $field ) {
			$this->assertSame( $stored['settings']['s-one'][ $field ], $saved['settings']['s-one'][ $field ], "The envelope of $field must be byte-identical (BPS-10 chain fingerprint)" );
			$this->assertSame( $stored['settings']['s-two'][ $field ], $saved['settings']['s-two'][ $field ], "The envelope of $field in the second instance must be byte-identical" );
		}
		$expected                              = $value;
		$expected['settings']['s-one']['path'] = 'changed/path';
		$this->assertSame( $expected, BackupPlus_Options::get_backupplus_option( $option ), 'Each instance must keep its own secrets' );
	}

	public function test_a_new_secret_replaces_the_saved_one_and_a_cleared_secret_is_cleared(): void {
		BackupPlus_Options::update_backupplus_option(
			'backupplus_sftp',
			self::two_instances(
				array(
					'pass' => 'synthetic old password',
					'key'  => 'synthetic old key',
				)
			)
		);
		$submitted                              = self::shown( 'backupplus_sftp' );
		$submitted['settings']['s-one']['pass'] = 'synthetic new password';
		$submitted['settings']['s-one']['key']  = '';
		BackupPlus_Options::update_backupplus_option( 'backupplus_sftp', $submitted );
		$saved = BackupPlus_Options::get_backupplus_option( 'backupplus_sftp' )['settings']['s-one'];
		$this->assertSame( 'synthetic new password', $saved['pass'] );
		$this->assertSame( '', $saved['key'] );
	}

	public function test_a_placeholder_without_a_saved_secret_at_the_same_path_is_saved_empty(): void {
		BackupPlus_Options::update_backupplus_option( 'backupplus_ftp', self::two_instances( array( 'pass' => 'synthetic FTP password' ) ) );
		$submitted                                = self::shown( 'backupplus_ftp' );
		$submitted['settings']['s-new']           = $submitted['settings']['s-one'];
		$submitted['settings']['s-one']['empty']  = BackupPlus_Credential_Storage::PLACEHOLDER;
		$submitted['settings']['s-one']['secret'] = BackupPlus_Credential_Storage::PLACEHOLDER;
		BackupPlus_Options::update_backupplus_option( 'backupplus_ftp', $submitted );
		$saved = BackupPlus_Options::get_backupplus_option( 'backupplus_ftp' )['settings'];
		$this->assertSame( '', $saved['s-new']['pass'], 'A copied placeholder must not carry another instance\'s secret' );
		$this->assertSame( '', $saved['s-one']['secret'], 'A placeholder for a secret that was never saved must be saved empty' );
		$this->assertSame( BackupPlus_Credential_Storage::PLACEHOLDER, $saved['s-one']['empty'], 'Only secret fields are substituted' );
		$this->assertSame( 'synthetic FTP password', $saved['s-one']['pass'] );

		// A first save, which WordPress sanitizes twice, has nothing saved at all.
		update_option( 'backupplus_s3', array( 'settings' => array( 's-x' => array( 'secretkey' => BackupPlus_Credential_Storage::PLACEHOLDER ) ) ) );
		$this->assertSame( '', BackupPlus_Options::get_backupplus_option( 'backupplus_s3' )['settings']['s-x']['secretkey'] );
	}

	public function test_whole_value_and_structured_secrets(): void {
		BackupPlus_Options::update_backupplus_option( 'backupplus_encryptionphrase', 'synthetic legacy phrase' );
		$envelope = get_option( 'backupplus_encryptionphrase' );
		$this->assertSame( BackupPlus_Credential_Storage::PLACEHOLDER, BackupPlus_Credential_Storage::mask( 'synthetic legacy phrase', 'backupplus_encryptionphrase' ) );
		BackupPlus_Options::update_backupplus_option( 'backupplus_encryptionphrase', BackupPlus_Credential_Storage::PLACEHOLDER );
		$this->assertSame( $envelope, get_option( 'backupplus_encryptionphrase' ), 'A whole-value secret must keep its envelope' );

		$ntfy = array(
			'server' => 'https://ntfy.example',
			'topic'  => 'synthetic-topic',
			'token'  => 'synthetic-token',
		);
		BackupPlus_Options::update_backupplus_option( 'backupplus_ntfy', $ntfy );
		$shown = self::shown( 'backupplus_ntfy' );
		$this->assertSame( 'https://ntfy.example', $shown['server'] );
		$this->assertNoSecret( $shown, array( 'synthetic-topic', 'synthetic-token' ), 'The ntfy settings' );
		BackupPlus_Options::update_backupplus_option( 'backupplus_ntfy', $shown );
		$this->assertSame( $ntfy, BackupPlus_Options::get_backupplus_option( 'backupplus_ntfy' ) );
	}

	public function test_unreadable_saved_secrets_are_neither_shown_nor_replaced_by_a_placeholder(): void {
		BackupPlus_Options::update_backupplus_option( 'backupplus_ftp', self::two_instances( array( 'pass' => 'synthetic FTP password' ) ) );
		$persisted = get_option( 'backupplus_ftp' );
		$submitted = self::shown( 'backupplus_ftp' );
		// The key that encrypted the password is gone; a new one is active.
		$config                                  = BackupPlus_Credential_Key_Store::$config;
		BackupPlus_Credential_Key_Store::$config = array(
			'active' => 'replacement',
			'keys'   => array( 'replacement' => base64_encode( random_bytes( 32 ) ) ),
		);
		$this->assertSame( '', self::shown( 'backupplus_ftp' )['settings']['s-one']['pass'], 'An unreadable secret is shown empty, not as saved' );
		try {
			BackupPlus_Options::update_backupplus_option( 'backupplus_ftp', $submitted );
			$this->fail( 'Placeholders for unreadable secrets must not save' );
		} catch ( Exception $e ) {
			$this->addToAssertionCount( 1 );
		}
		$this->assertSame( $persisted, get_option( 'backupplus_ftp' ), 'The unreadable ciphertext must be kept' );
		BackupPlus_Credential_Key_Store::$config = $config;
		$this->assertSame( 'synthetic FTP password', BackupPlus_Options::get_backupplus_option( 'backupplus_ftp' )['settings']['s-one']['pass'] );
	}

	public function test_connection_tests_get_the_saved_secrets_of_their_own_instance(): void {
		BackupPlus_Options::update_backupplus_option(
			'backupplus_backblaze',
			self::two_instances(
				array(
					'application_key_id' => 'synthetic B2 key ID',
					'application_key'    => 'synthetic B2 application key',
				)
			)
		);
		$posted = array(
			'method'             => 'backblaze',
			'instance_id'        => 's-two',
			'application_key_id' => BackupPlus_Credential_Storage::PLACEHOLDER,
			'application_key'    => 'typed B2 application key',
			'bucketid'           => 'bucket',
		);
		$tested = BackupPlus_Credential_Storage::unmask_at( $posted, 'backupplus_backblaze', array( 'settings', 's-two' ) );
		$this->assertSame( 'other instance synthetic B2 key ID', $tested['application_key_id'], 'The placeholder must be the saved value of the tested instance' );
		$this->assertSame( 'typed B2 application key', $tested['application_key'], 'A typed value must be tested as typed' );
		$this->assertSame( 'bucket', $tested['bucketid'] );
		$this->assertSame( '', BackupPlus_Credential_Storage::unmask_at( $posted, 'backupplus_backblaze', array( 'settings', 's-unsaved' ) )['application_key_id'], 'An unsaved instance has no saved secret' );
		$plain = array(
			'application_key_id' => 'real',
			'method'             => 'backblaze',
		);
		$this->assertSame( $plain, BackupPlus_Credential_Storage::unmask_at( $plain, 'backupplus_backblaze', array( 'settings', '' ) ), 'Real values, as the CLI passes them, are unchanged' );
	}

	public function test_a_saved_secret_is_not_sent_to_a_changed_server(): void {
		BackupPlus_Options::update_backupplus_option(
			'backupplus_ftp',
			array(
				'version'  => 1,
				'settings' => array(
					's-one' => array(
						'host'       => 'ftp.example',
						'encryption' => 'tls',
						'user'       => 'u',
						'pass'       => 'synthetic FTP password',
						'passive'    => 1,
					),
				),
			)
		);
		$shown = self::shown( 'backupplus_ftp' )['settings']['s-one'];
		foreach ( array(
			'host'       => 'attacker.example',
			'encryption' => 'none',
		) as $field => $changed ) {
			$posted           = $shown;
			$posted[ $field ] = $changed;
			$this->assertSame( '', BackupPlus_Credential_Storage::unmask_at( $posted, 'backupplus_ftp', array( 'settings', 's-one' ) )['pass'], "A connection test with a changed $field must not get the saved password" );
			$submitted                      = self::shown( 'backupplus_ftp' );
			$submitted['settings']['s-one'] = $posted;
			BackupPlus_Options::update_backupplus_option( 'backupplus_ftp', $submitted );
			$this->assertSame( '', BackupPlus_Options::get_backupplus_option( 'backupplus_ftp' )['settings']['s-one']['pass'], "Saving a changed $field must not keep the saved password" );
			BackupPlus_Options::update_backupplus_option(
				'backupplus_ftp',
				array(
					'version'  => 1,
					'settings' => array( 's-one' => array_merge( $shown, array( 'pass' => 'synthetic FTP password' ) ) ),
				)
			);
		}
		$posted            = $shown;
		$posted['passive'] = 0;
		$posted['user']    = 'other';
		$this->assertSame( 'synthetic FTP password', BackupPlus_Credential_Storage::unmask_at( $posted, 'backupplus_ftp', array( 'settings', 's-one' ) )['pass'], 'Settings that do not choose the server keep the saved password' );
		$posted         = $shown;
		$posted['host'] = 'ftp.example';
		unset( $posted['encryption'] );
		$this->assertSame( 'synthetic FTP password', BackupPlus_Credential_Storage::unmask_at( $posted, 'backupplus_ftp', array( 'settings', 's-one' ) )['pass'], 'The same server keeps the saved password' );

		BackupPlus_Options::update_backupplus_option(
			'backupplus_ntfy',
			array(
				'server' => 'https://ntfy.example',
				'topic'  => 'synthetic-topic',
				'token'  => 'synthetic-token',
			)
		);
		$submitted           = self::shown( 'backupplus_ntfy' );
		$submitted['server'] = 'https://attacker.example';
		BackupPlus_Options::update_backupplus_option( 'backupplus_ntfy', $submitted );
		$saved = BackupPlus_Options::get_backupplus_option( 'backupplus_ntfy' );
		$this->assertSame( array( '', '' ), array( $saved['topic'], $saved['token'] ), 'A changed ntfy server must not receive the saved topic or token' );
	}

	public function test_job_data_is_shown_with_masked_credentials_and_readable_progress(): void {
		$job   = array(
			'jobstatus'                   => 'finished',
			'option_cache'                => array(
				'backupplus_ftp'     => array(
					'settings' => array(
						's-one' => array(
							'host' => 'ftp.test',
							'pass' => 'synthetic cached FTP password',
						),
					),
				),
				'backupplus_service' => array( 'ftp' ),
			),
			'filesystem_credentials'      => array( 'password' => 'synthetic filesystem password' ),
			'backupplus_encryptionphrase' => 'synthetic job phrase',
			'backup_database'             => array(
				'external' => array(
					'dbinfo' => array(
						'host' => 'db.test',
						'pass' => 'synthetic DB password',
					),
				),
			),
			'restore_options'             => array( 'backupplus_external_targets' => array( 'db7' => array( 'connection' => array( 'password' => 'synthetic restore password' ) ) ) ),
		);
		$shown = BackupPlus_Credential_Storage::mask_job( $job );
		$this->assertNoSecret( $shown, array( 'synthetic cached FTP password', 'synthetic filesystem password', 'synthetic job phrase', 'synthetic DB password', 'synthetic restore password' ), 'The raw job data' );
		$this->assertSame( 'finished', $shown['jobstatus'] );
		$this->assertSame( 'ftp.test', $shown['option_cache']['backupplus_ftp']['settings']['s-one']['host'], 'Snapshot settings stay readable' );
		$this->assertSame( array( 'ftp' ), $shown['option_cache']['backupplus_service'] );
		$this->assertSame( 'db.test', $shown['backup_database']['external']['dbinfo']['host'] );
	}
}
