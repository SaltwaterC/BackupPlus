<?php
/** GitHub public key pinning, the constrained lookup at save time, settings failure preservation and the backup writer integration. */

namespace BackupPlus\Tests\Unit\Age;

use AgeTestState;
use BackupPlus\Tests\Support\AgeTestCase;
use BackupPlus_Database_Encryption;
use BackupPlus_Options;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class AgeRecipientsTest extends AgeTestCase {

	/** Save a GitHub user and a literal recipient, which pins the supported keys the lookup returns. */
	private function pinned_config(): array {
		AgeTestState::$http_body = $this->fixture['keys']['rsa']['recipient'] . "\n" . $this->fixture['keys']['ed25519']['recipient'] . "\n" . 'ecdsa-sha2-nistp256 unsupported' . "\n";
		$config                  = BackupPlus_Database_Encryption::sanitize(
			array(
				'mode'    => 'age',
				'sources' => "github:fixture-user\n" . $this->native['recipient'],
			)
		);
		BackupPlus_Options::$options['backupplus_database_encryption'] = $config;
		return $config;
	}

	/** Pin the config, then forget the lookup. */
	private function saved_config(): array {
		$config                      = $this->pinned_config();
		AgeTestState::$http_requests = array();
		AgeTestState::$http_body     = '';
		return $config;
	}

	private function sanitize_age( string $sources ): array {
		return BackupPlus_Database_Encryption::sanitize(
			array(
				'mode'    => 'age',
				'sources' => $sources,
			)
		);
	}

	public function test_github_expansion_pins_supported_public_keys(): void {
		$config = $this->pinned_config();
		$this->assertSame( 3, count( $config['recipients'] ), 'GitHub expansion must pin supported public keys' );
		$this->assertSame( 1, count( AgeTestState::$http_requests ), 'GitHub expansion must pin supported public keys' );
	}

	public function test_lookup_uses_a_fixed_https_endpoint_without_redirects_or_tls_overrides(): void {
		$this->pinned_config();
		$request = AgeTestState::$http_requests[0];
		$this->assertSame( 'https://github.com/fixture-user.keys', $request[0], 'Lookup must use a fixed HTTPS endpoint without redirects or TLS overrides' );
		$this->assertSame( 0, $request[1]['redirection'], 'Lookup must use a fixed HTTPS endpoint without redirects or TLS overrides' );
		$this->assertTrue( $request[1]['sslverify'], 'Lookup must use a fixed HTTPS endpoint without redirects or TLS overrides' );
	}

	public static function invalid_sources(): iterable {
		yield 'dot dot user'           => array( 'github:../elsewhere', null );
		yield 'dot dot path'           => array( 'github:user/../../secret', null );
		yield 'private age identity'   => array( null, 'native' );
		yield 'malformed recipient'    => array( 'age1invalid', null );
		yield 'private ssh identity'   => array( null, 'ed25519' );
	}

	#[DataProvider( 'invalid_sources' )]
	public function test_invalid_recipients_preserve_prior_settings_and_fail_before_network_access( ?string $literal, ?string $identity_of ): void {
		$config   = $this->saved_config();
		$bad      = $literal ?? $this->fixture['keys'][ $identity_of ]['identity'];
		$requests = count( AgeTestState::$http_requests );
		$this->assertSame( $config, $this->sanitize_age( $bad ), 'Invalid recipients must preserve prior settings' );
		$this->assertSame( $requests, count( AgeTestState::$http_requests ), 'Invalid inputs must fail before network access' );
	}

	public function test_a_github_outage_preserves_prior_settings(): void {
		$config                    = $this->saved_config();
		AgeTestState::$http_status = 503;
		$this->assertSame( $config, $this->sanitize_age( 'github:fixture-user' ), 'A GitHub outage must preserve prior settings' );
	}

	public function test_disabling_age_does_not_require_github_access(): void {
		$this->saved_config();
		AgeTestState::$http_status = 503;
		$requests                  = count( AgeTestState::$http_requests );
		$disabled                  = BackupPlus_Database_Encryption::sanitize(
			array(
				'mode'    => 'none',
				'sources' => 'github:fixture-user',
			)
		);
		$this->assertSame( 'none', $disabled['mode'], 'Disabling age must not require GitHub access' );
		$this->assertSame( $requests, count( AgeTestState::$http_requests ), 'Disabling age must not require GitHub access' );
	}

	public function test_empty_github_results_preserve_prior_settings(): void {
		$config                  = $this->saved_config();
		AgeTestState::$http_body = 'no supported keys';
		$this->assertSame( $config, $this->sanitize_age( 'github:fixture-user' ), 'Empty GitHub results must preserve prior settings' );
	}

	public function test_oversized_github_results_preserve_prior_settings(): void {
		$config                  = $this->saved_config();
		AgeTestState::$http_body = str_repeat( 'x', 65537 );
		$this->assertSame( $config, $this->sanitize_age( 'github:fixture-user' ), 'Oversized GitHub results must preserve prior settings' );
	}

	public function test_job_snapshots_contain_pinned_recipients_without_network_access_or_legacy_passphrases(): void {
		$config   = $this->saved_config();
		$before   = count( AgeTestState::$http_requests );
		$snapshot = BackupPlus_Database_Encryption::snapshot( array() );
		$this->assertArrayNotHasKey( 'backupplus_encryptionphrase', $snapshot, 'New backup snapshots must not copy legacy passphrases' );
		$this->assertSame( $config, $snapshot['backupplus_database_encryption'], 'Job snapshots must contain pinned recipients without network access' );
		$this->assertSame( $before, count( AgeTestState::$http_requests ), 'Job snapshots must contain pinned recipients without network access' );
	}

	public function test_the_actual_backup_writer_uses_pinned_age_recipients(): void {
		$this->saved_config();
		$before = count( AgeTestState::$http_requests );
		file_put_contents( $this->directory . '/job.sql', $this->plain );
		$result = $this->worker()->encrypt_file( 'job.sql' );
		$this->assertSame( 'job.sql.crypt', $result, 'Actual backup writer must use pinned age recipients' );
		$this->assertSame( $before, count( AgeTestState::$http_requests ), 'Actual backup writer must use pinned age recipients' );
	}

	public function test_an_invalid_encryption_configuration_fails_closed(): void {
		$this->saved_config();
		file_put_contents( $this->directory . '/job.sql', $this->plain );
		BackupPlus_Options::$options['backupplus_database_encryption']['recipients'] = array( 'invalid' );
		$this->assertFalse( $this->worker()->encrypt_file( 'job.sql' ), 'Invalid encryption configuration must fail closed' );
	}

	public static function removed_modes(): iterable {
		yield 'password' => array( 'password' );
		yield 'invalid'  => array( 'invalid' );
	}

	#[DataProvider( 'removed_modes' )]
	public function test_removed_or_unknown_modes_are_not_saved_and_fail_closed( string $removed_mode ): void {
		$config = $this->saved_config();
		file_put_contents( $this->directory . '/job.sql', $this->plain );
		$this->assertSame( $config, BackupPlus_Database_Encryption::sanitize( array( 'mode' => $removed_mode ) ), 'Removed or unknown encryption modes must not be saved' );
		BackupPlus_Options::$options['backupplus_database_encryption'] = array( 'mode' => $removed_mode );
		$worker = $this->worker();
		$this->assertFalse( $worker->encrypt_file( 'job.sql' ), 'Stale passphrase settings must fail closed for databases' );
		$this->assertFalse( $worker->encrypt_file_archives( array( 'plugins' => 'job.zip' ) ), 'Stale passphrase settings must fail closed for files' );
	}
}
