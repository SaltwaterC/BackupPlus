<?php
/** Regression checks for UpdraftPlus-to-BackupPlus settings migration. */

namespace BackupPlus\Tests\Unit\Settings;

use BackupPlus_FTP_Settings_Importer;
use BackupPlus_Settings_Importer;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class SettingsImportTest extends TestCase {

	protected function setUp(): void {
		define( 'ABSPATH', __DIR__ );
		require_once dirname( __DIR__, 3 ) . '/includes/class-settings-importer.php';
		require_once dirname( __DIR__, 3 ) . '/methods/ftp/settings-import.php';
	}

	public function test_current_json_payloads_parse(): void {
		$json_payload = BackupPlus_Settings_Importer::parse_settings_payload(
			json_encode(
				array(
					'backupplus_interval' => 'daily',
					'backupplus_service'  => array( 'dropbox' ),
				)
			)
		);
		$this->assertSame( 'daily', $json_payload['backupplus_interval'], 'current JSON settings payload parses' );
		$this->assertSame( array( 'dropbox' ), $json_payload['backupplus_service'], 'current JSON array values parse' );
	}

	public function test_special_characters_in_credentials_survive_json_parsing(): void {
		$special_credential   = 'slash\\and"quote';
		$special_json_payload = json_encode( array( 'backupplus_secret' => $special_credential ) );
		$special_json_result  = BackupPlus_Settings_Importer::parse_settings_payload( $special_json_payload );
		$this->assertSame( $special_credential, $special_json_result['backupplus_secret'], 'literal backslashes and quotes survive JSON parsing' );
		$wordpress_slashed_result = BackupPlus_Settings_Importer::parse_settings_payload( addslashes( $special_json_payload ) );
		$this->assertSame( $special_credential, $wordpress_slashed_result['backupplus_secret'], 'WordPress-slashed JSON credentials survive parsing' );
	}

	public function test_url_encoded_payload_inside_json_parses(): void {
		$legacy_url_payload = BackupPlus_Settings_Importer::parse_settings_payload( json_encode( 'updraft_interval=daily&updraft_dir=wp-content%2Fbackups' ) );
		$this->assertSame( 'daily', $legacy_url_payload['updraft_interval'], 'older URL-encoded payload inside JSON parses' );
		$this->assertSame( 'wp-content/backups', $legacy_url_payload['updraft_dir'], 'URL-encoded paths are decoded' );
	}

	public function test_raw_url_encoded_payload_parses(): void {
		$raw_url_payload = BackupPlus_Settings_Importer::parse_settings_payload( 'updraft_interval=weekly&updraft_dir=wp-content%2Farchives' );
		$this->assertSame( 'weekly', $raw_url_payload['updraft_interval'], 'raw legacy URL-encoded payload parses' );
		$this->assertSame( 'wp-content/archives', $raw_url_payload['updraft_dir'], 'raw URL-encoded paths are decoded' );
	}

	public function test_full_export_envelope_unwraps_its_data_object(): void {
		$envelope_payload = BackupPlus_Settings_Importer::parse_settings_payload(
			json_encode(
				array(
					'network_site_url' => 'https://example.test',
					'data'             => array( 'updraft_interval' => 'fortnightly' ),
				)
			)
		);
		$this->assertSame( 'fortnightly', $envelope_payload['updraft_interval'], 'full export envelope unwraps its data object' );
	}

	public function test_legacy_settings_map_to_backupplus_keys_and_removed_features_are_reported(): void {
		$legacy_settings = array(
			'updraft_interval'             => 'daily',
			'updraft_interval_database'    => 'weekly',
			'updraft_dir'                  => 'wp-content/custom-backups',
			'updraft_service'              => array( 'dropbox', 'updraftvault' ),
			'updraft_dropbox'              => array(
				'version'  => 1,
				'settings' => array( 's-dropbox' => array( 'token' => 'keep-me' ) ),
			),
			'updraft_updraftvault'         => array( 'token' => 'removed-feature' ),
			'updraft_central_localkeys'    => array( 'key' => 'removed-feature' ),
			'updraft_unknown_addon_option' => 'report-this',
		);

		$allowed_settings = array(
			'backupplus_interval',
			'backupplus_interval_database',
			'backupplus_dir',
			'backupplus_service',
			'backupplus_dropbox',
		);
		$result           = BackupPlus_Settings_Importer::normalize_legacy_settings(
			$legacy_settings,
			array( 'dropbox', 's3', 'email' ),
			$allowed_settings
		);

		$this->assertSame( 'daily', $result['settings']['backupplus_interval'], 'file schedule maps to BackupPlus key' );
		$this->assertSame( 'weekly', $result['settings']['backupplus_interval_database'], 'database schedule maps to BackupPlus key' );
		$this->assertSame( 'wp-content/custom-backups', $result['settings']['backupplus_dir'], 'backup directory maps to BackupPlus key' );
		$this->assertSame( 'dropbox', $result['settings']['backupplus_service'], 'supported destination remains selected' );
		$this->assertTrue( isset( $result['settings']['backupplus_dropbox']['settings']['s-dropbox']['token'] ), 'provider credentials are mapped to BackupPlus key' );
		$this->assertFalse( isset( $result['settings']['updraft_interval'] ), 'legacy scalar source key is removed after mapping' );
		$this->assertFalse( isset( $result['settings']['updraft_dropbox'] ), 'legacy provider key is removed after mapping' );
		$this->assertFalse( isset( $result['settings']['backupplus_updraftvault'] ), 'Vault settings are excluded' );
		$this->assertContains( 'updraft_updraftvault', $result['skipped_settings'], 'removed Vault setting is reported' );
		$this->assertContains( 'updraft_service:updraftvault', $result['skipped_settings'], 'removed Vault destination is reported' );
		$this->assertContains( 'updraft_central_localkeys', $result['skipped_settings'], 'Central settings are reported' );
		$this->assertContains( 'updraft_unknown_addon_option', $result['skipped_settings'], 'unsupported extension settings are reported' );
	}

	public function test_empty_directory_keeps_the_historical_default(): void {
		$default_directory_settings = BackupPlus_Settings_Importer::normalize_legacy_settings(
			array( 'updraft_dir' => '' ),
			array(),
			array( 'backupplus_dir' )
		);
		$this->assertSame( 'updraft', $default_directory_settings['settings']['backupplus_dir'], 'empty UpdraftPlus directory setting keeps its historical default for archive compatibility' );
	}

	public function test_allowed_future_options_accept_their_legacy_export_key(): void {
		$future_alias_result = BackupPlus_Settings_Importer::normalize_legacy_settings(
			array( 'updraft_future_setting' => 'preserve-me' ),
			array(),
			array( 'backupplus_future_setting' )
		);
		$this->assertSame( 'preserve-me', $future_alias_result['settings']['backupplus_future_setting'], 'allowed future options accept their legacy export key' );
	}

	public function test_pre_2014_ftp_settings_become_structured_storage_settings(): void {
		$legacy_ftp        = BackupPlus_FTP_Settings_Importer::normalize(
			array(
				'updraft_server_address'  => 'ftp.example.test',
				'updraft_ftp_login'       => 'backup-user',
				'updraft_ftp_pass'        => 'secret',
				'updraft_ftp_remote_path' => '/backups',
			)
		);
		$legacy_ftp_result = BackupPlus_Settings_Importer::normalize_legacy_settings(
			$legacy_ftp,
			array( 'ftp' ),
			array( 'backupplus_ftp' )
		);
		$this->assertSame( 'ftp.example.test', $legacy_ftp_result['settings']['backupplus_ftp']['host'], 'pre-2014 FTP host is migrated to structured storage settings' );
		$this->assertSame( 'backup-user', $legacy_ftp_result['settings']['backupplus_ftp']['user'], 'pre-2014 FTP login is preserved' );
		$this->assertSame( 'secret', $legacy_ftp_result['settings']['backupplus_ftp']['pass'], 'pre-2014 FTP password is preserved' );
		$this->assertSame( '/backups', $legacy_ftp_result['settings']['backupplus_ftp']['path'], 'pre-2014 FTP path is preserved' );
		$this->assertTrue( $legacy_ftp_result['settings']['backupplus_ftp']['passive'], 'legacy FTP mode keeps its original passive default' );
	}

	public function test_multiple_destinations_reduce_to_the_active_single_destination(): void {
		$multiple_result = BackupPlus_Settings_Importer::normalize_legacy_settings(
			array( 'updraft_service' => array( 's3', 'dropbox' ) ),
			array( 's3', 'dropbox' ),
			array( 'backupplus_service' )
		);
		$this->assertSame( 'dropbox', $multiple_result['settings']['backupplus_service'], 'multiple destinations reduce to the active single destination' );
		$this->assertContains( 'updraft_service:multiple_destinations', $multiple_result['skipped_settings'], 'extra destinations are reported' );
	}

	public function test_unavailable_providers_are_not_activated_but_keep_their_credentials(): void {
		$unavailable_result = BackupPlus_Settings_Importer::normalize_legacy_settings(
			array(
				'updraft_service' => 'sftp',
				'updraft_sftp'    => array( 'host' => 'example.test' ),
			),
			array( 'sftp' ),
			array( 'backupplus_service', 'backupplus_sftp' ),
			array()
		);
		$this->assertSame( 'none', $unavailable_result['settings']['backupplus_service'], 'unavailable provider is not activated' );
		$this->assertTrue( isset( $unavailable_result['settings']['backupplus_sftp']['host'] ), 'unavailable optional provider credentials are preserved for later use' );
		$this->assertContains( 'updraft_service:sftp', $unavailable_result['skipped_settings'], 'unavailable provider selection is reported' );
	}
}
