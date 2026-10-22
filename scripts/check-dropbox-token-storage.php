<?php
/** Real WordPress installation, browser maintenance and storage; disposable options/files only. */
if (!defined('WP_CLI') || !WP_CLI || defined('BACKUPPLUS_DIR') || defined('BACKUPPLUS_CREDENTIAL_KEY_DIR')) {
	fwrite(STDERR, "Run using WP-CLI with --skip-plugins=backupplus and no credential key overrides.\n");
	exit(1);
}
function dropbox_wp_expect($condition, $message) {
	if (!$condition) throw new RuntimeException($message);
}
$uploads = wp_upload_dir();
$fixture_name = 'backupplus-sec001-'.wp_generate_password(12, false);
$fixture_directory = $uploads['basedir'].'/'.$fixture_name;
define('BACKUPPLUS_CREDENTIAL_KEY_DIR', $fixture_directory);
$fixture_option = 'backupplus_sec001_test_'.wp_generate_password(12, false);
$fixture_cron_option = $fixture_option.'_cron';
$original_rotation_error = get_option('backupplus_credential_rotation_error', null);
register_shutdown_function(function() use ($fixture_option, $fixture_cron_option, $fixture_directory, $original_rotation_error) {
	delete_option($fixture_option);
	delete_option($fixture_cron_option);
	if (null === $original_rotation_error) delete_option('backupplus_credential_rotation_error');
	else update_option('backupplus_credential_rotation_error', $original_rotation_error, false);
	if (is_dir($fixture_directory)) {
		foreach (scandir($fixture_directory) as $file) if ('.' !== $file && '..' !== $file) unlink($fixture_directory.'/'.$file);
		rmdir($fixture_directory);
	}
});
$metadata = array();
foreach (array('backupplus_credential_key_directory', 'backupplus_dropbox_token_format', 'backupplus_credential_setup_error', 'backupplus_credential_rotation_key') as $name) {
	add_filter('pre_option_'.$name, function($value) use (&$metadata, $name) { return isset($metadata[$name]) ? $metadata[$name] : ''; });
	add_filter('pre_update_option_'.$name, function($value, $old) use (&$metadata, $name) { $metadata[$name] = $value; return $old; }, 10, 2);
}
add_filter('pre_option_cron', function($value) use ($fixture_cron_option) { return get_option($fixture_cron_option, array('version' => 2)); });
add_filter('pre_update_option_cron', function($value, $old) use ($fixture_cron_option) { update_option($fixture_cron_option, $value, false); return $old; }, 10, 2);
// Redirect Dropbox option access to a temporary real DB option; leave user settings alone.
add_filter('pre_option_backupplus_dropbox', function($value) use ($fixture_option) {
	return get_option($fixture_option, array('version' => 1, 'settings' => array()));
});
add_filter('pre_update_option_backupplus_dropbox', function($value, $old) use ($fixture_option) {
	update_option($fixture_option, $value, false);
	return $old;
}, 10, 2);
require dirname(__DIR__).'/backupplus.php';
require_once BACKUPPLUS_DIR.'/includes/Dropbox2/OAuth/Storage/StorageInterface.php';
require_once BACKUPPLUS_DIR.'/includes/Dropbox2/OAuth/Storage/WordPress.php';
wp_set_current_user(1);
$legacy = json_decode(file_get_contents(__DIR__.'/fixtures/dropbox-legacy-token.json'), true);
update_option($fixture_option, array('version' => 1, 'settings' => array('sec001fixture' => array('folder' => 'disposable', 'tk_access_token' => $legacy['ciphertext']))), false);
do_action('activate_'.plugin_basename(BACKUPPLUS_DIR.'/backupplus.php'), false);
$config = BackupPlus_Credential_Key_Store::configuration();
$encrypter = Dropbox_Encrypter::from_config('sec001fixture');
$migrated = BackupPlus_Options::get_backupplus_option('backupplus_dropbox');
dropbox_wp_expect(is_file($fixture_directory.'/credentials.php'), 'Plugin activation must generate the key file automatically');
dropbox_wp_expect(base64_decode($legacy['plaintext']) === $encrypter->decrypt($migrated['settings']['sec001fixture']['tk_access_token']), 'Activation must automatically migrate legacy credentials through real option filters');
BackupPlus_Credential_Key_Store::initialize();
dropbox_wp_expect($migrated === BackupPlus_Options::get_backupplus_option('backupplus_dropbox'), 'Repeated activation/upgrade must preserve credentials');
$provider = BackupPlus_Storage_Provider_Factory::create('dropbox', false);
$provider->set_options($migrated['settings']['sec001fixture'], false, 'sec001fixture');
$storage = new Dropbox_WordPress($encrypter, 'tk_', 'backupplus_dropbox', $provider);
$token = (object) array('oauth_token' => 'disposable-sec001-access', 'refresh_token' => 'disposable-sec001-refresh', 'token_type' => 'bearer');
$storage->set($token, 'access_token');
global $wpdb;
$raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s", $fixture_option));
dropbox_wp_expect(false === strpos($raw, 'disposable-sec001-') && false !== strpos($raw, 'bpcred:v1:'), 'Real DB storage must contain authenticated ciphertext without plaintext tokens');
dropbox_wp_expect(serialize($token) === serialize($storage->get('access_token')), 'Real provider storage must decrypt after WordPress sanitization');
dropbox_wp_expect(apply_filters('backupplus_exclude_file', false, $fixture_directory.'/credentials.php') && apply_filters('backupplus_exclude_directory', false, $fixture_directory), 'Actual archive filters must exclude the key file and directory');

$http = wp_remote_get($uploads['baseurl'].'/'.$fixture_name.'/credentials.php', array('timeout' => 10));
dropbox_wp_expect(!is_wp_error($http) && 404 === wp_remote_retrieve_response_code($http) && '' === wp_remote_retrieve_body($http), 'The generated key file must reject an unauthenticated HTTP request without disclosing key material');
ob_start();
BackupPlus_Credential_Key_Admin::render();
$html = ob_get_clean();
foreach ($config['keys'] as $key) dropbox_wp_expect(false === strpos($html, $key), 'Settings must never render key material');
dropbox_wp_expect(false !== strpos($html, 'Download recovery key') && false !== strpos($html, 'Restore recovery key') && false !== strpos($html, 'Move key file'), 'Settings must offer browser-only key maintenance');
BackupPlus_Credential_Key_Admin::perform('rotate');
$rotated = BackupPlus_Credential_Key_Store::configuration();
dropbox_wp_expect($rotated['active'] !== $config['active'] && isset($rotated['keys'][$config['active']]), 'Browser rotation must retain historical keys');
$current = BackupPlus_Options::get_backupplus_option('backupplus_dropbox');
dropbox_wp_expect(serialize($token) === Dropbox_Encrypter::from_config('sec001fixture')->decrypt($current['settings']['sec001fixture']['tk_access_token']), 'Browser rotation must persist readable credentials');
$recovery = BackupPlus_Credential_Key_Store::encode($rotated);
unlink($fixture_directory.'/credentials.php');
BackupPlus_Credential_Key_Admin::perform('restore', $recovery);
dropbox_wp_expect($rotated === BackupPlus_Credential_Key_Store::configuration(), 'Browser recovery must restore the missing file without SSH');
require __DIR__.'/check-recovery-key-download.php';
require __DIR__.'/check-credential-key-rotation.php';
$rotated = BackupPlus_Credential_Key_Store::configuration();
wp_set_current_user(0);
$denied = false;
try { BackupPlus_Credential_Key_Admin::perform('rotate'); } catch (Exception $e) { $denied = true; }
dropbox_wp_expect($denied && $rotated === BackupPlus_Credential_Key_Store::configuration(), 'Unauthenticated key maintenance must fail without modifying keys');
wp_set_current_user(1);
// Exercise request guards before any operation executes.
if (!defined('DOING_AJAX')) define('DOING_AJAX', true);
$stop = function() { return function() { throw new RuntimeException('Request stopped'); }; };
add_filter('wp_die_handler', $stop, PHP_INT_MAX);
add_filter('wp_die_ajax_handler', $stop, PHP_INT_MAX);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_REQUEST['nonce'] = 'invalid';
$_POST['operation'] = 'rotate';
$denied = false;
try { BackupPlus_Credential_Key_Admin::ajax(); } catch (Exception $e) { $denied = true; }
dropbox_wp_expect($denied && $rotated === BackupPlus_Credential_Key_Store::configuration(), 'A bad nonce must reject key maintenance before any changes');
remove_filter('wp_die_handler', $stop, PHP_INT_MAX);
remove_filter('wp_die_ajax_handler', $stop, PHP_INT_MAX);
echo "PASS: activation key generation, automatic migration, real storage, archive exclusion, HTTP denial, browser rotation/recovery and request authorization\n";
