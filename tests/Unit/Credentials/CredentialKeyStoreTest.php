<?php
/** The managed credential key file: installation, protection, relocation, rotation and recovery without SSH. */

namespace BackupPlus\Tests\Unit\Credentials;

use BackupPlus\Tests\Support\CredentialEncryptionTestCase;
use BackupPlus;
use BackupPlus_Credential_Key_Admin;
use BackupPlus_Credential_Key_Rotation;
use BackupPlus_Credential_Key_Store;
use BackupPlus_Dropbox_Token_Migration;
use BackupPlus_Options;
use Dropbox_Encrypter;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class CredentialKeyStoreTest extends CredentialEncryptionTestCase {

	/** @var array<string, mixed> The fixture of a pre-change Dropbox token. */
	private array $legacy;

	/** The configuration right after the default installation. */
	private array $config;

	private string $directory;

	/** The migrated credentials of the installation. */
	private string $encrypted;

	/** Where the browser relocation moves the key file. */
	private string $outside;

	protected function setUp(): void {
		parent::setUp();
		$this->legacy  = json_decode( file_get_contents( BACKUPPLUS_DIR . '/scripts/fixtures/dropbox-legacy-token.json' ), true );
		$this->outside = $this->fixture . '/outside-docroot/private';
		define( 'WP_CONTENT_DIR', $this->fixture . '/docroot/wp-content' );
		mkdir( WP_CONTENT_DIR, 0700, true );
		require_once BACKUPPLUS_DIR . '/includes/class-credential-key-admin.php';
		$this->backupplus->ensure_phpseclib();
	}

	/** A new installation with a saved pre-change token, which the installation upgrades. */
	private function install(): void {
		BackupPlus_Options::$options = array(
			'version'  => 1,
			'settings' => array( 'instance' => array( 'tk_access_token' => $this->legacy['ciphertext'] ) ),
		);
		BackupPlus_Credential_Key_Store::initialize();
		$this->config    = BackupPlus_Credential_Key_Store::configuration();
		$this->directory = BackupPlus_Credential_Key_Store::directory();
		$encrypted       = BackupPlus_Options::$options['settings']['instance']['tk_access_token'];
		$this->encrypted = $encrypted;
	}

	private function install_outside_docroot(): void {
		$this->install();
		BackupPlus_Credential_Key_Admin::perform( 'move', $this->outside );
	}

	private function plaintext(): string {
		return base64_decode( $this->legacy['plaintext'] );
	}

	/** The key file of the installation, rotated once. */
	private function rotate(): array {
		BackupPlus_Credential_Key_Admin::perform( 'rotate' );
		return BackupPlus_Credential_Key_Store::configuration();
	}

	/**
	 * A rotated installation outside the docroot whose recovery copies went through an older and the current recovery file.
	 *
	 * @return array{0: array, 1: string} The rotated configuration and its recovery file.
	 */
	private function recovered_state(): array {
		$this->install_outside_docroot();
		$rotated = $this->rotate();
		BackupPlus_Credential_Key_Admin::perform( 'restore', BackupPlus_Credential_Key_Store::encode( $this->config ) );
		BackupPlus_Credential_Key_Admin::perform( 'restore', BackupPlus_Credential_Key_Store::encode( $rotated ) );
		return array( $rotated, BackupPlus_Credential_Key_Store::encode( $rotated ) );
	}

	public function test_the_default_installation_creates_a_protected_key_file(): void {
		$this->install();
		$directory = $this->directory;
		$this->assertSame( WP_CONTENT_DIR . '/backupplus-private', $directory, 'Default installation must create a dedicated protected file' );
		foreach ( array( 'credentials.php', '.donotbackup', '.htaccess', 'web.config' ) as $file ) {
			$this->assertFileExists( $directory . '/' . $file, 'Installation must add backup and HTTP protections' );
		}
		$this->assertSame( 0600, fileperms( $directory . '/credentials.php' ) & 0777, 'The private key file must be owner-readable only' );
		$this->assertSame( 0700, fileperms( $directory ) & 0777, 'The private key directory must be owner-accessible only' );
	}

	public function test_restoring_location_metadata_rediscovers_a_surviving_key_file(): void {
		$this->install();
		delete_option( BackupPlus_Credential_Key_Store::OPTION );
		$this->assertSame( $this->config, BackupPlus_Credential_Key_Store::configuration(), 'Restoring location metadata must rediscover a surviving key file' );
	}

	public function test_installation_upgrades_existing_credentials_and_repeated_initialization_preserves_everything(): void {
		$this->install();
		$this->assertSame( $this->plaintext(), Dropbox_Encrypter::from_config( 'instance' )->decrypt( $this->encrypted ), 'Installation/upgrade must migrate existing Dropbox credentials without a CLI' );
		$this->assertSame( 1, (int) get_option( BackupPlus_Dropbox_Token_Migration::OPTION ), 'Upgrade must record completion' );
		BackupPlus_Credential_Key_Store::initialize();
		$this->assertSame( $this->config, BackupPlus_Credential_Key_Store::configuration(), 'Repeated installation must preserve keys and ciphertext' );
		$this->assertSame( $this->encrypted, BackupPlus_Options::$options['settings']['instance']['tk_access_token'], 'Repeated installation must preserve keys and ciphertext' );
	}

	public function test_key_metadata_updates_work_in_a_fresh_process_without_the_crypto_autoloader(): void {
		$this->install();
		$child = $this->run_child( array( 'metadata-only', $this->directory ) );
		$this->assertSame( 0, $child['status'], 'Key metadata updates must work in a fresh process without the crypto autoloader: ' . $child['stderr'] );
		$result = json_decode( $child['stdout'], true );
		$this->assertFalse( $result['crypto_loaded_before'], 'Metadata regression must start before crypto is loaded' );
		$this->assertTrue( $result['upgrade_complete'], 'Key metadata must be writable before crypto is loaded' );
	}

	public function test_deleting_the_completion_marker_never_reopens_the_legacy_decoder(): void {
		$this->install();
		BackupPlus_Options::$options['settings']['instance']['tk_access_token'] = $this->legacy['ciphertext'];
		delete_option( BackupPlus_Dropbox_Token_Migration::OPTION );
		BackupPlus_Credential_Key_Store::initialize();
		$this->assertSame( $this->legacy['ciphertext'], BackupPlus_Options::$options['settings']['instance']['tk_access_token'], 'Deleting the database completion marker must never reopen the legacy decoder' );
	}

	public function test_key_files_and_folders_are_excluded_from_backups(): void {
		$this->install();
		$this->assertTrue( BackupPlus_Credential_Key_Store::exclude( false, $this->directory . '/credentials.php' ), 'Key files and folders must be excluded from backups' );
		$this->assertTrue( BackupPlus_Credential_Key_Store::exclude( false, $this->directory ), 'Key files and folders must be excluded from backups' );
		$this->assertFalse( BackupPlus_Credential_Key_Store::exclude( false, $this->directory . '-other' ), 'Unrelated backup paths must remain included' );
	}

	public function test_database_options_never_contain_key_material(): void {
		$this->install();
		$this->assertNotEmpty( get_site_option( BackupPlus_Credential_Key_Store::OPTION ), 'The key location must be remembered' );
		foreach ( array( serialize( $GLOBALS['dropbox_test_options'] ), serialize( BackupPlus_Options::$options ) ) as $options ) {
			foreach ( $this->config['keys'] as $key ) {
				$this->assertFalse( strpos( $options, $key ), 'Database options must never contain key material' );
			}
		}
	}

	public function test_executing_the_key_file_never_discloses_its_contents(): void {
		$this->install();
		$guard_output = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $this->directory . '/credentials.php' ) );
		$this->assertTrue( null === $guard_output || '' === $guard_output, 'Executing the key file must never disclose its contents' );
	}

	public function test_browser_relocation_outside_the_docroot_preserves_every_key(): void {
		$this->install();
		$directory = $this->directory;
		BackupPlus_Credential_Key_Admin::perform( 'move', $this->outside );
		$this->assertSame( $this->outside, BackupPlus_Credential_Key_Store::directory(), 'Browser relocation outside docroot must preserve every key' );
		$this->assertSame( $this->config, BackupPlus_Credential_Key_Store::configuration(), 'Browser relocation outside docroot must preserve every key' );
		$this->assertFileDoesNotExist( $directory . '/credentials.php', 'Relocation must remove the old secret and retain backup protection' );
		$this->assertFileExists( $directory . '/.donotbackup', 'Relocation must remove the old secret and retain backup protection' );
	}

	public function test_a_failed_relocation_leaves_the_old_key_and_destination_files_intact(): void {
		$this->install_outside_docroot();
		$before   = file_get_contents( $this->outside . '/credentials.php' );
		$occupied = $this->fixture . '/occupied';
		mkdir( $occupied );
		file_put_contents( $occupied . '/index.html', 'existing website' );
		$this->assert_fails(
			function () use ( $occupied ) {
				BackupPlus_Credential_Key_Admin::perform( 'move', $occupied );
			},
			'Moving into a website folder must fail safely'
		);
		$this->assertSame( $before, file_get_contents( $this->outside . '/credentials.php' ), 'Failed relocation must leave the old key and destination files intact' );
		$this->assertSame( 'existing website', file_get_contents( $occupied . '/index.html' ), 'Failed relocation must leave the old key and destination files intact' );
	}

	public function test_unauthorized_users_cannot_manage_keys(): void {
		$this->install_outside_docroot();
		$GLOBALS['dropbox_can_manage'] = false;
		$unauthorized                  = $this->fixture . '/unauthorized';
		$this->assert_fails(
			function () use ( $unauthorized ) {
				BackupPlus_Credential_Key_Admin::perform( 'move', $unauthorized );
			},
			'Unauthorized users must not manage keys'
		);
	}

	public function test_only_network_administrators_manage_shared_network_keys(): void {
		$this->install_outside_docroot();
		$GLOBALS['dropbox_is_multisite']  = true;
		$GLOBALS['dropbox_network_admin'] = false;
		$this->assert_fails(
			function () {
				BackupPlus_Credential_Key_Admin::perform( 'rotate' );
			},
			'Subsite administrators must not manage shared network keys'
		);
		$GLOBALS['dropbox_network_admin'] = true;
		$this->assertTrue( BackupPlus_Credential_Key_Admin::allowed(), 'Authorized network administrators must be able to manage keys' );
	}

	public function test_rotation_retains_historical_keys_and_credentials(): void {
		$this->install_outside_docroot();
		$rotated = $this->rotate();
		$this->assertNotSame( $this->config['active'], $rotated['active'], 'Rotation must retain historical keys' );
		$this->assertArrayHasKey( $this->config['active'], $rotated['keys'], 'Rotation must retain historical keys' );
		$this->assertSame( $this->plaintext(), Dropbox_Encrypter::from_config( 'instance' )->decrypt( $this->encrypted ), 'Rotation must preserve in-flight and historical credentials' );
	}

	public function test_restoring_an_older_recovery_copy_reactivates_its_key_and_retains_newer_keys(): void {
		$this->install_outside_docroot();
		$rotated            = $this->rotate();
		$current_ciphertext = BackupPlus_Options::$options['settings']['instance']['tk_access_token'];
		$older_recovery     = BackupPlus_Credential_Key_Store::encode( $this->config );
		$restore_message    = BackupPlus_Credential_Key_Admin::perform( 'restore', $older_recovery );
		$reactivated        = BackupPlus_Credential_Key_Store::configuration();
		$this->assertSame( $this->config['active'], $reactivated['active'], 'Restoring a pre-rotation recovery copy must reactivate its active key' );
		$this->assertSame( $rotated['keys'], $reactivated['keys'], 'Restoring an older recovery copy must retain every newer key' );
		$this->assertSame( $this->plaintext(), Dropbox_Encrypter::from_config( 'instance' )->decrypt( $current_ciphertext ), 'Credentials encrypted after the recovery copy was downloaded must remain readable' );
		$new_ciphertext = Dropbox_Encrypter::from_config( 'instance' )->encrypt( 'restored-key-write' );
		$this->assertSame( 0, strpos( $new_ciphertext, Dropbox_Encrypter::PREFIX . $this->config['active'] . ':' ), 'Subsequent credential writes must use the restored active key' );
		$this->assertNotFalse( strpos( $restore_message, 'active' ), 'The restore result must tell users that the uploaded key is active' );
		BackupPlus_Credential_Key_Admin::perform( 'restore', $older_recovery );
		$this->assertSame( $reactivated, BackupPlus_Credential_Key_Store::configuration(), 'Repeated restoration of an older recovery copy must be idempotent' );
	}

	public function test_uploaded_recovery_files_never_execute_php(): void {
		$this->recovered_state();
		$this->assert_fails(
			function () {
				BackupPlus_Credential_Key_Admin::perform( 'restore', '<?php malicious();' );
			},
			'Uploaded recovery files must never execute PHP'
		);
	}

	public function test_conflicting_recovery_material_fails(): void {
		list( $rotated )                     = $this->recovered_state();
		$wrong                               = $rotated;
		$wrong['keys'][ $rotated['active'] ] = base64_encode( random_bytes( 32 ) );
		$this->assert_fails(
			function () use ( $wrong ) {
				BackupPlus_Credential_Key_Admin::perform( 'restore', BackupPlus_Credential_Key_Store::encode( $wrong ) );
			},
			'Conflicting recovery material must fail'
		);
	}

	public function test_a_lost_key_is_never_silently_regenerated(): void {
		$this->recovered_state();
		unlink( $this->outside . '/credentials.php' );
		$this->assert_fails(
			function () {
				BackupPlus_Credential_Key_Store::configuration();
			},
			'A lost key must never be silently regenerated'
		);
		$this->assertFileDoesNotExist( $this->outside . '/credentials.php', 'Missing-key detection must not write replacement keys' );
	}

	public function test_recovery_verifies_saved_credentials_before_publishing_keys(): void {
		$this->recovered_state();
		unlink( $this->outside . '/credentials.php' );
		$unrelated = array(
			'active' => 'unrelated',
			'keys'   => array( 'unrelated' => base64_encode( random_bytes( 32 ) ) ),
		);
		$this->assert_fails(
			function () use ( $unrelated ) {
				BackupPlus_Credential_Key_Admin::perform( 'restore', BackupPlus_Credential_Key_Store::encode( $unrelated ) );
			},
			'Recovery must verify saved credentials before publishing keys'
		);
		$this->assertFileDoesNotExist( $this->outside . '/credentials.php', 'A wrong recovery file must not replace a lost key' );
	}

	public function test_browser_restore_recovers_a_missing_key_without_ssh(): void {
		list( $rotated, $recovery ) = $this->recovered_state();
		unlink( $this->outside . '/credentials.php' );
		BackupPlus_Credential_Key_Admin::perform( 'restore', $recovery );
		$this->assertSame( $rotated, BackupPlus_Credential_Key_Store::configuration(), 'Browser restore must recover a missing key without SSH' );
	}

	public function test_browser_recovery_accepts_a_new_folder_when_the_original_location_is_gone(): void {
		list( $rotated, $recovery ) = $this->recovered_state();
		unlink( $this->outside . '/credentials.php' );
		$restored_directory = $this->fixture . '/new-host/private';
		BackupPlus_Credential_Key_Admin::perform( 'restore', $recovery, $restored_directory );
		$this->assertSame( $restored_directory, BackupPlus_Credential_Key_Store::directory(), 'Browser recovery must accept a new folder when the original key location is gone' );
		$this->assertSame( $rotated, BackupPlus_Credential_Key_Store::configuration(), 'Browser recovery must accept a new folder when the original key location is gone' );
	}

	public function test_missing_key_replacement_never_overwrites_and_later_recovery_merges_old_keys(): void {
		list( , $recovery ) = $this->recovered_state();
		unlink( $this->outside . '/credentials.php' );
		$restored_directory = $this->fixture . '/new-host/private';
		BackupPlus_Credential_Key_Admin::perform( 'restore', $recovery, $restored_directory );
		$rotated = BackupPlus_Credential_Key_Store::configuration();
		$this->assert_fails(
			function () {
				BackupPlus_Credential_Key_Admin::perform( 'replace_missing' );
			},
			'Replacement must never overwrite an existing key file'
		);
		unlink( $restored_directory . '/credentials.php' );
		$before_replacement = BackupPlus_Options::$options;
		BackupPlus_Credential_Key_Admin::perform( 'replace_missing' );
		$this->assertNotSame( $rotated, BackupPlus_Credential_Key_Store::configuration(), 'Explicit missing-key replacement must permit reconnection while retaining saved credentials' );
		$this->assertSame( $before_replacement, BackupPlus_Options::$options, 'Explicit missing-key replacement must permit reconnection while retaining saved credentials' );
		BackupPlus_Credential_Key_Admin::perform( 'restore', $recovery );
		$this->assertSame( $this->plaintext(), Dropbox_Encrypter::from_config( 'instance' )->decrypt( $this->encrypted ), 'Later recovery must merge old keys without discarding a replacement key' );
	}

	/** Simulate a shared host where wp-content itself is not writable to the plugin. */
	public function test_restricted_hosts_fall_back_to_writable_uploads_storage(): void {
		file_put_contents( WP_CONTENT_DIR . '/backupplus-private', 'blocked' );
		BackupPlus_Options::$options = array();
		$fallback                    = BackupPlus_Credential_Key_Store::configuration();
		$this->assertSame( WP_CONTENT_DIR . '/uploads/backupplus-private', BackupPlus_Credential_Key_Store::directory(), 'Restricted hosts must automatically use writable uploads storage' );
		$this->assertNotEmpty( $fallback['keys'], 'Fallback installation must create usable keys' );
	}

	/** Fresh processes racing to initialize the same installation must converge on one key. */
	public function test_concurrent_fresh_processes_converge_on_one_key(): void {
		$concurrent_root = $this->fixture . '/concurrent/wp-content';
		mkdir( $concurrent_root, 0700, true );
		$children = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$children[] = $this->start_child( array( PHP_BINARY, '-d', 'error_reporting=24575', dirname( __DIR__, 2 ) . '/Support/dropbox-encryption-child.php', 'managed', $concurrent_root ), 'Concurrent initialization process must start' );
		}
		$fingerprints = array();
		foreach ( $children as $child ) {
			$result = $this->finish_child( $child );
			$this->assertSame( 0, $result['status'], 'Concurrent initialization must succeed: ' . $result['stderr'] );
			$this->assertSame( 1, preg_match( '/KEY-FINGERPRINT:([a-f0-9]{64})/', $result['stdout'], $matches ), 'Child initialization must return a fingerprint' );
			$fingerprints[] = $matches[1];
		}
		$this->assertCount( 1, array_unique( $fingerprints ), 'Concurrent installations must never create divergent keys' );
	}

	public function test_open_basedir_restricted_hosts_reject_inaccessible_paths_without_losing_the_key(): void {
		$restricted_root = $this->fixture . '/restricted';
		mkdir( $restricted_root, 0700 );
		$child = $this->run_child( array( 'restricted', $restricted_root . '/wp-content', $this->fixture . '/forbidden' ), array( '-d', 'open_basedir=' . BACKUPPLUS_DIR . PATH_SEPARATOR . $restricted_root ) );
		$this->assertSame( 0, $child['status'], 'A host restricted by open_basedir must retain a working key: ' . $child['stderr'] );
		$result = json_decode( $child['stdout'], true );
		$this->assertTrue( $result['blocked'], 'open_basedir restrictions must preserve the working key' );
		$this->assertTrue( $result['same_config'], 'open_basedir restrictions must preserve the working key' );
	}

	/**
	 * Three simulated WordPress sites sharing one key file, each with saved credentials, and a rotated shared key.
	 *
	 * @return array{0: array, 1: array, 2: array} The original key, the settings per site and the rotated key.
	 */
	private function network_with_rotated_key(): array {
		require_once BACKUPPLUS_DIR . '/includes/class-credential-key-rotation.php';
		$GLOBALS['dropbox_is_multisite'] = true;
		$original_key                    = BackupPlus_Credential_Key_Store::configuration();
		$original_options                = array();
		foreach ( array( 1, 2, 3 ) as $site ) {
			switch_to_blog( $site );
			$token     = serialize( (object) array( 'oauth_token' => 'disposable-network-' . $site ) );
			$encrypter = Dropbox_Encrypter::from_config( 'instance' );
			$settings  = array(
				'version'  => 1,
				'settings' => array(
					'instance' => array(
						'tk_access_token'  => $encrypter->encrypt( $token ),
						'tk_request_token' => $encrypter->encrypt( $token ),
					),
				),
			);
			BackupPlus_Options::update_backupplus_option( 'backupplus_dropbox', $settings );
			$original_options[ $site ] = $settings;
			restore_current_blog();
		}
		return array( $original_key, $original_options, BackupPlus_Credential_Key_Store::rotate() );
	}

	/** Rewrites the credentials of the network while the second site cannot save. */
	private function reencrypt_with_a_failing_second_site( array $new_key ): void {
		BackupPlus_Options::$fail_blog_id = 2;
		$this->assert_fails(
			function () use ( $new_key ) {
				BackupPlus_Credential_Key_Rotation::reencrypt_credentials( $new_key['active'] );
			},
			'A failed subsite rewrite must be reported for retry'
		);
	}

	/** Real crypto and rewrites across three simulated WordPress sites sharing one file. */
	public function test_a_failed_subsite_rewrite_is_reported_without_blocking_the_other_sites(): void {
		list( , $original_options, $new_key ) = $this->network_with_rotated_key();
		$this->reencrypt_with_a_failing_second_site( $new_key );
		$this->assertSame( 1, get_current_blog_id(), 'Network reencryption must restore the caller site even after failures' );
		$this->assertSame( array(), $GLOBALS['dropbox_blog_stack'], 'Network reencryption must restore the caller site even after failures' );
		foreach ( array( 1, 2, 3 ) as $site ) {
			switch_to_blog( $site );
			$settings = BackupPlus_Options::get_backupplus_option( 'backupplus_dropbox' );
			if ( 2 === $site ) {
				$this->assertSame( $original_options[ $site ], $settings, 'The failed site must retain readable old ciphertext and expose a rotation error' );
				$this->assertNotEmpty( get_option( BackupPlus_Credential_Key_Rotation::ERROR_OPTION ), 'The failed site must retain readable old ciphertext and expose a rotation error' );
			} else {
				foreach ( $settings['settings']['instance'] as $encrypted ) {
					$this->assertSame( 0, strpos( $encrypted, Dropbox_Encrypter::PREFIX . $new_key['active'] . ':' ), 'Other sites must finish reencryption despite a neighboring site failure' );
				}
			}
			restore_current_blog();
		}
	}

	public function test_network_retries_skip_completed_sites_and_every_site_ends_on_the_new_key(): void {
		list( $original_key, , $new_key ) = $this->network_with_rotated_key();
		$this->reencrypt_with_a_failing_second_site( $new_key );
		$completed = array();
		foreach ( array( 1, 3 ) as $site ) {
			switch_to_blog( $site );
			$completed[ $site ] = BackupPlus_Options::get_backupplus_option( 'backupplus_dropbox' );
			restore_current_blog();
		}
		BackupPlus_Options::$fail_blog_id = null;
		BackupPlus_Credential_Key_Rotation::reencrypt_credentials( $new_key['active'] );
		foreach ( array( 1, 2, 3 ) as $site ) {
			switch_to_blog( $site );
			$settings = BackupPlus_Options::get_backupplus_option( 'backupplus_dropbox' );
			if ( isset( $completed[ $site ] ) ) {
				$this->assertSame( $completed[ $site ], $settings, 'Network retries must skip already completed sites' );
			}
			foreach ( $settings['settings']['instance'] as $encrypted ) {
				$this->assertSame( 0, strpos( $encrypted, Dropbox_Encrypter::PREFIX . $new_key['active'] . ':' ), 'Every site credential must eventually use the new key' );
				$plain = Dropbox_Encrypter::from_config( 'instance' )->decrypt( $encrypted );
				$this->assertSame( 'disposable-network-' . $site, BackupPlus::unserialize( $plain, array( 'stdClass' ) )->oauth_token, 'Reencryption must preserve each site token and context binding' );
			}
			$this->assertEmpty( get_option( BackupPlus_Credential_Key_Rotation::ERROR_OPTION ), 'Completed sites must clear their rotation errors' );
			restore_current_blog();
		}
		$this->assertSame( $new_key, BackupPlus_Credential_Key_Store::configuration(), 'Network rewrites and retries must share one rotated key and retain the historical key' );
		$this->assertArrayHasKey( $original_key['active'], $new_key['keys'], 'Network rewrites and retries must share one rotated key and retain the historical key' );
	}
}
