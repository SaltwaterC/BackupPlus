<?php
/** SEC-005 policy regression using real option/module/job APIs and disposable keys. */
define('ABSPATH', dirname(__DIR__).'/');
define('BACKUPPLUS_DIR', dirname(__DIR__));
define('BACKUPPLUS_PHPSECLIB_MIN_PHP_VERSION', '5.6.1');
$filters = $options = $site_options = array();
$blog_id = 1;
$fail_save = null;
$fail_blog = null;
$credential_multisite = false;
$blog_stack = array();
function backupplus_try_include_file($file, $mode) { require_once BACKUPPLUS_DIR.'/'.$file; }
function add_action() {}
function add_filter($name, $callback, $priority = 10, $args = 1) { global $filters; $filters[$name][$priority][] = array($callback, $args); }
function apply_filters($name, $value, ...$args) {
	global $filters;
	$callbacks = isset($filters[$name]) ? $filters[$name] : array();
	ksort($callbacks);
	foreach ($callbacks as $entries) foreach ($entries as $entry) $value = call_user_func_array($entry[0], array_slice(array_merge(array($value), $args), 0, $entry[1]));
	return $value;
}
function get_current_blog_id() { global $blog_id; return $blog_id; }
function get_option($name, $default = false) { global $options; return isset($options[get_current_blog_id()][$name]) ? $options[get_current_blog_id()][$name] : $default; }
function update_option($name, $value, $autoload = null) {
	global $options, $fail_save, $fail_blog;
	if ($fail_save === $name && (null === $fail_blog || $fail_blog === get_current_blog_id())) return false;
	if (!isset($options[get_current_blog_id()][$name])) $value = apply_filters('sanitize_option_'.$name, $value, $name);
	$options[get_current_blog_id()][$name] = apply_filters('sanitize_option_'.$name, $value, $name);
	return true;
}
function delete_option($name) { global $options; unset($options[get_current_blog_id()][$name]); }
function get_site_option($name, $default = false) { global $site_options; return isset($site_options[$name]) ? $site_options[$name] : $default; }
function update_site_option($name, $value) { global $site_options; $site_options[$name] = $value; return true; }
function is_multisite() { global $credential_multisite; return $credential_multisite; }
function get_current_network_id() { return 1; }
function get_sites($args) { return array(1, 2); }
function switch_to_blog($id) { global $blog_id, $blog_stack; $blog_stack[] = $blog_id; $blog_id = $id; }
function restore_current_blog() { global $blog_id, $blog_stack; $blog_id = array_pop($blog_stack); }
function is_wp_error($value) { return $value instanceof WP_Error; }
class WP_Error {}
class BackupPlus_Storage_Provider_Factory {
	public static function get_methods() { return array_fill_keys(array('ftp', 'sftp', 'webdav', 's3', 's3generic', 'dreamobjects', 'cloudfiles', 'openstack', 'googledrive', 'dropbox', 'future'), 'Test'); }
}
class BackupPlus_Credential_Key_Store {
	public static $config;
	public static $complete = false;
	public static function configuration() { if (!self::$config) throw new RuntimeException('Missing key'); return self::$config; }
	public static function upgrade_complete($scope) { return self::$complete; }
	public static function mark_upgraded($scope) { self::$complete = true; }
}
class CredentialTestDB {
	public $options = 'options';
	public $sitemeta = 'sitemeta';
	public function prepare($query, $value) { return $query; }
	public function esc_like($value) { return $value; }
	public function get_col($query) { global $site_options; return array_keys($site_options); }
}
$wpdb = new CredentialTestDB();
require BACKUPPLUS_DIR.'/class-backupplus.php';
require BACKUPPLUS_DIR.'/options.php';
require BACKUPPLUS_DIR.'/methods/backup-module.php';
require BACKUPPLUS_DIR.'/includes/class-credential-encrypter.php';
require BACKUPPLUS_DIR.'/includes/class-credential-storage.php';
class CredentialTestBackupPlus extends BackupPlus { public function __construct() {} }
$backupplus = new CredentialTestBackupPlus();
$backupplus->ensure_phpseclib();
BackupPlus_Credential_Key_Store::$config = array('active' => 'original', 'keys' => array('original' => base64_encode(random_bytes(32))));
BackupPlus_Credential_Storage::register();
function credential_expect($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function credential_reject($callback, $message) { try { $callback(); } catch (Exception $e) { return; } throw new RuntimeException($message); }
function credential_raw_safe($value, $secrets) { foreach ($secrets as $secret) credential_expect(false === strpos(serialize($value), $secret), 'Raw storage exposed a synthetic credential'); }

$providers = array(
	'ftp' => array('pass' => 'synthetic FTP password'),
	'sftp' => array('pass' => 'synthetic SSH password', 'key' => "-----BEGIN PRIVATE KEY-----\nsynthetic SSH identity", 'key_passphrase' => 'synthetic SSH key passphrase'),
	'webdav' => array('pass' => 'synthetic WebDAV password', 'url' => 'https://user:synthetic-url-password@host.test/backups'),
	's3' => array('secretkey' => 'synthetic S3 secret', 'sessiontoken' => 'synthetic S3 session'),
	's3generic' => array('secretkey' => 'synthetic S3 compatible secret'),
	'dreamobjects' => array('secretkey' => 'synthetic DreamObjects secret'),
	'cloudfiles' => array('apikey' => 'synthetic Rackspace key'),
	'openstack' => array('password' => 'synthetic OpenStack password', 'apikey' => 'synthetic OpenStack key'),
	'googledrive' => array('secret' => 'synthetic OAuth client secret', 'token' => array('refresh_token' => 'synthetic OAuth refresh')),
);
foreach ($providers as $provider => $secrets) {
	$name = 'backupplus_'.$provider;
	$value = array('version' => 1, 'settings' => array('one' => array_merge(array('host' => 'host.test', 'user' => 'user'), $secrets)));
	BackupPlus_Options::update_backupplus_option($name, $value);
	credential_expect($value === BackupPlus_Options::get_backupplus_option($name), 'Provider option API must preserve credential types and content: '.$provider);
	foreach ($secrets as $field => $secret) credential_expect(0 === strpos(get_option($name)['settings']['one'][$field], 'bpcred:v1:'), 'Provider field must use the shared authenticated envelope');
	credential_expect('host.test' === get_option($name)['settings']['one']['host'], 'Ordinary options must stay readable');
}
// Extensions specify field names, with no dependency on the cipher or key lifecycle.
add_filter('backupplus_credential_secret_fields', function($fields, $option) { if ('backupplus_future' === $option) $fields[] = 'new_secret'; return $fields; }, 10, 2);
update_option('backupplus_future', array('new_secret' => 'synthetic extension credential'));
credential_raw_safe(get_option('backupplus_future'), array('synthetic extension credential'));
credential_expect('synthetic extension credential' === BackupPlus_Options::get_backupplus_option('backupplus_future')['new_secret'], 'Extension fields must share the policy');

// Direct WordPress saves and the form's provider sanitizer must see plaintext.
add_filter('sanitize_option_backupplus_sftp', function($value) {
	credential_expect('synthetic SSH identity via form' === $value['settings']['one']['key'], 'Provider sanitizers must receive plaintext even on double sanitization');
	return $value;
}, 10, 2);
update_option('backupplus_sftp', array('version' => 1, 'settings' => array('one' => array('key' => 'synthetic SSH identity via form'))));
update_option('backupplus_sftp', get_option('backupplus_sftp'));
credential_raw_safe(get_option('backupplus_sftp'), array('synthetic SSH identity via form'));
unset($filters['sanitize_option_backupplus_sftp'][10]);

$original = get_option('backupplus_ftp');
$wrong_instance = $original;
$wrong_instance['settings']['two'] = $wrong_instance['settings']['one'];
credential_reject(function() use ($wrong_instance) { BackupPlus_Credential_Storage::incoming($wrong_instance, 'backupplus_ftp'); }, 'Swapping instances must reject the ciphertext');
credential_reject(function() use ($original) { BackupPlus_Credential_Storage::incoming($original, 'backupplus_webdav'); }, 'Swapping providers must reject the ciphertext');
$wrong_field = $original;
$wrong_field['settings']['one']['key'] = $wrong_field['settings']['one']['pass'];
credential_reject(function() use ($wrong_field) { BackupPlus_Credential_Storage::incoming($wrong_field, 'backupplus_ftp'); }, 'Swapping secret fields must reject the ciphertext');
$blog_id = 2;
credential_reject(function() use ($original) { BackupPlus_Credential_Storage::incoming($original, 'backupplus_ftp'); }, 'Swapping sites must reject the ciphertext');
$blog_id = 1;
$bad = $original;
$bad['settings']['one']['pass'] .= 'A';
credential_reject(function() use ($bad) { update_option('backupplus_ftp', $bad); }, 'Tampered ciphertext must not be saved');
credential_expect(get_option('backupplus_ftp') === $original, 'Rejected writes must retain credentials');

// Real job APIs must encrypt each persistence path and resume with identical data.
$backupplus->nonce = 'disposable-job';
$job = array('job_type' => 'backup', 'option_cache' => array('backupplus_ftp' => BackupPlus_Options::get_backupplus_option('backupplus_ftp')), 'filesystem_credentials' => array('password' => 'synthetic filesystem password'), 'backup_database' => array('external' => array('status' => 'begun', 'dbinfo' => array('host' => 'db.test', 'pass' => 'synthetic external DB snapshot password'))));
$backupplus->jobdata_set_from_array($job);
credential_raw_safe(get_site_option('backupplus_jobdata_disposable-job'), array('synthetic FTP password', 'synthetic filesystem password', 'synthetic external DB snapshot password'));
credential_expect($job === $backupplus->jobdata_getarray('disposable-job'), 'Jobs must resume with decrypted options');
$backupplus->jobdata_reset();
$backupplus->jobdata_set_multi('jobstatus', 'running');
$backupplus->jobdata_set('progress', 2);
$backupplus->jobdata_delete('progress');
credential_expect('running' === get_site_option('backupplus_jobdata_disposable-job')['jobstatus'], 'Job bookkeeping remains readable');
credential_reject(function() use ($site_options) { BackupPlus_Credential_Storage::job($site_options['backupplus_jobdata_disposable-job'], 'another-job', 'decrypt'); }, 'Job ciphertext cannot move to a different job');

// One-time migration also handles flat settings, phrases, extra DBs and old jobs.
$options[1]['backupplus_ftp'] = array('pass' => 'synthetic legacy flat password');
$options[1]['backupplus_encryptionphrase'] = 'synthetic legacy archive phrase';
$options[1]['backupplus_extradbs'] = array(array('password' => 'synthetic extra DB password'));
$site_options['backupplus_jobdata_legacy-job'] = array('option_cache' => array('secret' => 'synthetic legacy job password'));
BackupPlus_Credential_Storage::upgrade();
credential_expect(BackupPlus_Credential_Key_Store::$complete, 'Migration records completion only after saved replacements verify');
credential_raw_safe($options, array('synthetic legacy flat password', 'synthetic legacy archive phrase', 'synthetic extra DB password'));
credential_raw_safe($site_options, array('synthetic legacy job password'));
credential_expect('synthetic legacy archive phrase' === BackupPlus_Options::get_backupplus_option('backupplus_encryptionphrase'), 'Legacy phrases remain a read-only restore fallback');
$options[1]['backupplus_ftp'] = array('pass' => 'injected plaintext after migration');
BackupPlus_Credential_Storage::upgrade();
credential_expect('' === BackupPlus_Options::get_backupplus_option('backupplus_ftp')['pass'], 'Completed migration does not reopen plaintext reads');
BackupPlus_Credential_Storage::upgrade(true);
credential_expect('injected plaintext after migration' === BackupPlus_Options::get_backupplus_option('backupplus_ftp')['pass'], 'Explicit restored-settings upgrade can convert legacy data');

// Rotation verifies all replacements and retains old keys for jobs/backups.
$before = $options;
$config = BackupPlus_Credential_Key_Store::$config;
$config['keys']['rotated'] = base64_encode(random_bytes(32));
$config['active'] = 'rotated';
BackupPlus_Credential_Key_Store::$config = $config;
BackupPlus_Credential_Storage::rewrite();
credential_expect(false !== strpos(get_option('backupplus_ftp')['pass'], ':rotated:'), 'Rotation must rewrite provider credentials with the active key');
credential_expect(false !== strpos(get_site_option('backupplus_jobdata_disposable-job')['option_cache'], ':rotated:'), 'Rotation must rewrite job snapshots');
BackupPlus_Credential_Storage::verify($config);
$wrong = array('active' => 'wrong', 'keys' => array('wrong' => base64_encode(random_bytes(32))));
credential_reject(function() use ($wrong) { BackupPlus_Credential_Storage::verify($wrong); }, 'Recovery verifies generic provider and job credentials');
$persisted = get_option('backupplus_ftp');
BackupPlus_Credential_Key_Store::$config = null;
credential_expect('' === BackupPlus_Options::get_backupplus_option('backupplus_ftp')['pass'], 'Missing keys return no usable secret');
credential_reject(function() { update_option('backupplus_ftp', array('pass' => '')); }, 'Saving redacted fields with a missing key must preserve ciphertext');
credential_reject(function() { update_option('backupplus_ftp', array('pass' => 'synthetic replacement')); }, 'Missing keys must prohibit plaintext writes');
credential_expect($persisted === get_option('backupplus_ftp'), 'Missing-key failures must retain credentials');
BackupPlus_Credential_Key_Store::$config = $config;

$fail_save = 'backupplus_encryptionphrase';
$options[1]['backupplus_encryptionphrase'] = 'synthetic migration save failure';
BackupPlus_Credential_Key_Store::$complete = false;
credential_reject(function() { BackupPlus_Credential_Storage::upgrade(); }, 'Failed migration saves must not mark completion');
credential_expect(!BackupPlus_Credential_Key_Store::$complete && 'synthetic migration save failure' === get_option('backupplus_encryptionphrase'), 'Failed saves preserve their old credential value');
// Generic credentials participate in network rotation, including partial retries.
$fail_save = null;
BackupPlus_Credential_Storage::upgrade(true);
$credential_multisite = true;
$blog_id = 2;
update_option('backupplus_ftp', array('pass' => 'synthetic second site password'));
$second_before = get_option('backupplus_ftp');
$blog_id = 1;
require BACKUPPLUS_DIR.'/includes/class-credential-key-rotation.php';
class BackupPlus_Dropbox_Token_Migration { public static function rotate() {} }
$config['keys']['network'] = base64_encode(random_bytes(32));
$config['active'] = 'network';
BackupPlus_Credential_Key_Store::$config = $config;
$fail_save = 'backupplus_ftp';
$fail_blog = 2;
credential_reject(function() { BackupPlus_Credential_Key_Rotation::reencrypt_credentials('network'); }, 'A failed site must prevent network completion');
$first_completed = get_option('backupplus_ftp');
credential_expect('network' === get_option(BackupPlus_Credential_Key_Rotation::REWRITTEN_KEY_OPTION), 'Successful sites record rotation completion');
$blog_id = 2;
credential_expect($second_before === get_option('backupplus_ftp'), 'A failed site retains readable credentials under its old key');
$blog_id = 1;
$fail_save = $fail_blog = null;
BackupPlus_Credential_Key_Rotation::reencrypt_credentials('network');
credential_expect($first_completed === get_option('backupplus_ftp'), 'A retry skips completed sites');
$blog_id = 2;
credential_expect('network' === get_option(BackupPlus_Credential_Key_Rotation::REWRITTEN_KEY_OPTION) && 'synthetic second site password' === BackupPlus_Options::get_backupplus_option('backupplus_ftp')['pass'], 'A retry re-encrypts unfinished sites without losing credentials');
$blog_id = 1;
credential_expect(BackupPlus_Credential_Storage::has_ciphertext() && 1 === get_current_blog_id(), 'Network ciphertext detection restores the original blog context');
$export = BackupPlus_Credential_Storage::export_omitted_options();
foreach (array('backupplus_ftp', 'backupplus_sftp', 'backupplus_dropbox', 'backupplus_encryptionphrase', 'backupplus_extradbs') as $name) credential_expect(in_array($name, $export, true), 'Exports must omit connection settings and phrases');
echo "PASS: shared credential encryption for providers, WordPress/API writes, jobs, migration, context binding, recovery, rotation and fail-closed storage\n";
