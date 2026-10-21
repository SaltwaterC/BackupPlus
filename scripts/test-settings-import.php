<?php
/**
 * Regression checks for UpdraftPlus-to-BackupPlus settings migration.
 * Run with: php scripts/test-settings-import.php
 */
define('ABSPATH', __DIR__);
require_once dirname(__DIR__).'/includes/class-settings-importer.php';

function backupplus_settings_import_expect($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: $message\n");
		exit(1);
	}
}

$json_payload = BackupPlus_Settings_Importer::parse_settings_payload(json_encode(array(
	'backupplus_interval' => 'daily',
	'backupplus_service' => array('dropbox'),
)));
backupplus_settings_import_expect('daily' === $json_payload['backupplus_interval'], 'current JSON settings payload parses');
backupplus_settings_import_expect(array('dropbox') === $json_payload['backupplus_service'], 'current JSON array values parse');

$special_credential = 'slash\\and"quote';
$special_json_payload = json_encode(array('backupplus_secret' => $special_credential));
$special_json_result = BackupPlus_Settings_Importer::parse_settings_payload($special_json_payload);
backupplus_settings_import_expect($special_credential === $special_json_result['backupplus_secret'], 'literal backslashes and quotes survive JSON parsing');
$wordpress_slashed_result = BackupPlus_Settings_Importer::parse_settings_payload(addslashes($special_json_payload));
backupplus_settings_import_expect($special_credential === $wordpress_slashed_result['backupplus_secret'], 'WordPress-slashed JSON credentials survive parsing');

$legacy_url_payload = BackupPlus_Settings_Importer::parse_settings_payload(json_encode('updraft_interval=daily&updraft_dir=wp-content%2Fbackups'));
backupplus_settings_import_expect('daily' === $legacy_url_payload['updraft_interval'], 'older URL-encoded payload inside JSON parses');
backupplus_settings_import_expect('wp-content/backups' === $legacy_url_payload['updraft_dir'], 'URL-encoded paths are decoded');

$raw_url_payload = BackupPlus_Settings_Importer::parse_settings_payload('updraft_interval=weekly&updraft_dir=wp-content%2Farchives');
backupplus_settings_import_expect('weekly' === $raw_url_payload['updraft_interval'], 'raw legacy URL-encoded payload parses');
backupplus_settings_import_expect('wp-content/archives' === $raw_url_payload['updraft_dir'], 'raw URL-encoded paths are decoded');

$envelope_payload = BackupPlus_Settings_Importer::parse_settings_payload(json_encode(array(
	'network_site_url' => 'https://example.test',
	'data' => array('updraft_interval' => 'fortnightly'),
)));
backupplus_settings_import_expect('fortnightly' === $envelope_payload['updraft_interval'], 'full export envelope unwraps its data object');

$legacy_settings = array(
	'updraft_interval' => 'daily',
	'updraft_interval_database' => 'weekly',
	'updraft_dir' => 'wp-content/custom-backups',
	'updraft_service' => array('dropbox', 'updraftvault'),
	'updraft_dropbox' => array(
		'version' => 1,
		'settings' => array('s-dropbox' => array('token' => 'keep-me')),
	),
	'updraft_updraftvault' => array('token' => 'removed-feature'),
	'updraft_central_localkeys' => array('key' => 'removed-feature'),
	'updraft_unknown_addon_option' => 'report-this',
);

$allowed_settings = array(
	'backupplus_interval',
	'backupplus_interval_database',
	'backupplus_dir',
	'backupplus_service',
	'backupplus_dropbox',
);
$result = BackupPlus_Settings_Importer::normalize_legacy_settings(
	$legacy_settings,
	array('dropbox', 's3', 'email'),
	$allowed_settings
);

backupplus_settings_import_expect('daily' === $result['settings']['backupplus_interval'], 'file schedule maps to BackupPlus key');
backupplus_settings_import_expect('weekly' === $result['settings']['backupplus_interval_database'], 'database schedule maps to BackupPlus key');
backupplus_settings_import_expect('wp-content/custom-backups' === $result['settings']['backupplus_dir'], 'backup directory maps to BackupPlus key');
backupplus_settings_import_expect('dropbox' === $result['settings']['backupplus_service'], 'supported destination remains selected');
backupplus_settings_import_expect(isset($result['settings']['backupplus_dropbox']['settings']['s-dropbox']['token']), 'provider credentials are mapped to BackupPlus key');
backupplus_settings_import_expect(!isset($result['settings']['updraft_interval']), 'legacy scalar source key is removed after mapping');
backupplus_settings_import_expect(!isset($result['settings']['updraft_dropbox']), 'legacy provider key is removed after mapping');
backupplus_settings_import_expect(!isset($result['settings']['backupplus_updraftvault']), 'Vault settings are excluded');
backupplus_settings_import_expect(in_array('updraft_updraftvault', $result['skipped_settings'], true), 'removed Vault setting is reported');
backupplus_settings_import_expect(in_array('updraft_service:updraftvault', $result['skipped_settings'], true), 'removed Vault destination is reported');
backupplus_settings_import_expect(in_array('updraft_central_localkeys', $result['skipped_settings'], true), 'Central settings are reported');
backupplus_settings_import_expect(in_array('updraft_unknown_addon_option', $result['skipped_settings'], true), 'unsupported extension settings are reported');

$default_directory_settings = BackupPlus_Settings_Importer::normalize_legacy_settings(
	array('updraft_dir' => ''),
	array(),
	array('backupplus_dir')
);
backupplus_settings_import_expect('updraft' === $default_directory_settings['settings']['backupplus_dir'], 'empty UpdraftPlus directory setting keeps its historical default for archive compatibility');

$future_alias_result = BackupPlus_Settings_Importer::normalize_legacy_settings(
	array('updraft_future_setting' => 'preserve-me'),
	array(),
	array('backupplus_future_setting')
);
backupplus_settings_import_expect('preserve-me' === $future_alias_result['settings']['backupplus_future_setting'], 'allowed future options accept their legacy export key');

$legacy_ftp = BackupPlus_Settings_Importer::normalize_legacy_ftp_settings(array(
	'updraft_server_address' => 'ftp.example.test',
	'updraft_ftp_login' => 'backup-user',
	'updraft_ftp_pass' => 'secret',
	'updraft_ftp_remote_path' => '/backups',
));
$legacy_ftp_result = BackupPlus_Settings_Importer::normalize_legacy_settings(
	$legacy_ftp,
	array('ftp'),
	array('backupplus_ftp')
);
backupplus_settings_import_expect('ftp.example.test' === $legacy_ftp_result['settings']['backupplus_ftp']['host'], 'pre-2014 FTP host is migrated to structured storage settings');
backupplus_settings_import_expect('backup-user' === $legacy_ftp_result['settings']['backupplus_ftp']['user'], 'pre-2014 FTP login is preserved');
backupplus_settings_import_expect('secret' === $legacy_ftp_result['settings']['backupplus_ftp']['pass'], 'pre-2014 FTP password is preserved');
backupplus_settings_import_expect('/backups' === $legacy_ftp_result['settings']['backupplus_ftp']['path'], 'pre-2014 FTP path is preserved');
backupplus_settings_import_expect(true === $legacy_ftp_result['settings']['backupplus_ftp']['passive'], 'legacy FTP mode keeps its original passive default');

$multiple_result = BackupPlus_Settings_Importer::normalize_legacy_settings(
	array('updraft_service' => array('s3', 'dropbox')),
	array('s3', 'dropbox'),
	array('backupplus_service')
);
backupplus_settings_import_expect('dropbox' === $multiple_result['settings']['backupplus_service'], 'multiple destinations reduce to the active single destination');
backupplus_settings_import_expect(in_array('updraft_service:multiple_destinations', $multiple_result['skipped_settings'], true), 'extra destinations are reported');

$unavailable_result = BackupPlus_Settings_Importer::normalize_legacy_settings(
	array('updraft_service' => 'sftp', 'updraft_sftp' => array('host' => 'example.test')),
	array('sftp'),
	array('backupplus_service', 'backupplus_sftp'),
	array()
);
backupplus_settings_import_expect('none' === $unavailable_result['settings']['backupplus_service'], 'unavailable provider is not activated');
backupplus_settings_import_expect(isset($unavailable_result['settings']['backupplus_sftp']['host']), 'unavailable optional provider credentials are preserved for later use');
backupplus_settings_import_expect(in_array('updraft_service:sftp', $unavailable_result['skipped_settings'], true), 'unavailable provider selection is reported');

fwrite(STDOUT, "Settings import compatibility checks passed.\n");
