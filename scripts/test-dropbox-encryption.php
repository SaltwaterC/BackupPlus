<?php
/** SEC-001: authenticated storage, explicit legacy migration and key lifecycle. */
define('ABSPATH', dirname(__DIR__).'/');
define('BACKUPPLUS_DIR', dirname(__DIR__));
define('BACKUPPLUS_PHPSECLIB_MIN_PHP_VERSION', '5.6.1');
define('WP_CLI', true);
function __($text, $domain = null) { return $text; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function get_current_blog_id() { return 1; }
function wp_upload_dir($time = null, $create = true) { return array('basedir' => WP_CONTENT_DIR.'/uploads', 'error' => false); }
$dropbox_is_multisite = false;
$dropbox_network_admin = true;
function is_multisite() { global $dropbox_is_multisite; return $dropbox_is_multisite; }
$dropbox_can_manage = true;
function current_user_can($capability) { global $dropbox_can_manage, $dropbox_network_admin; return 'manage_network_options' === $capability ? $dropbox_network_admin : $dropbox_can_manage; }

function backupplus_try_include_file($path, $mode) { require_once BACKUPPLUS_DIR.'/'.$path; }
class WP_Error {}
$dropbox_test_options = array();
function get_option($name, $default = false) { global $dropbox_test_options; return isset($dropbox_test_options[$name]) ? $dropbox_test_options[$name] : $default; }
function update_option($name, $value, $autoload = null) { global $dropbox_test_options; $dropbox_test_options[$name] = $value; return true; }
function delete_option($name) { global $dropbox_test_options; unset($dropbox_test_options[$name]); return true; }
function get_site_option($name, $default = false) { return get_option($name, $default); }
function update_site_option($name, $value) { return update_option($name, $value); }

class WP_CLI {
	public static $messages = array();
	public static function error($message) { throw new RuntimeException($message); }
	public static function success($message) { self::$messages[] = $message; }
}
function expect_dropbox($condition, $message) {
	if (!$condition) throw new RuntimeException($message);
}
function expect_dropbox_failure($callback, $message) {
	try { $callback(); } catch (Exception $e) { return; }
	throw new RuntimeException($message);
}
require BACKUPPLUS_DIR.'/class-backupplus.php';
require BACKUPPLUS_DIR.'/methods/backup-module.php';
require BACKUPPLUS_DIR.'/includes/Dropbox2/Exception.php';
require BACKUPPLUS_DIR.'/includes/Dropbox2/OAuth/Storage/StorageInterface.php';
require BACKUPPLUS_DIR.'/includes/Dropbox2/OAuth/Storage/Encrypter.php';
require BACKUPPLUS_DIR.'/includes/class-credential-key-store.php';
require BACKUPPLUS_DIR.'/includes/class-dropbox-token-migration.php';
require BACKUPPLUS_DIR.'/includes/Dropbox2/OAuth/Storage/WordPress.php';
class DropboxTestBackupPlus extends BackupPlus {
	public $unavailable = false;
	public function __construct() {}
	public function log($line, $level = 'notice', $uniq_id = false, $skip_dblog = false) {}
	public function ensure_phpseclib() { return $this->unavailable ? new WP_Error() : parent::ensure_phpseclib(); }
}
class DropboxTestModule extends BackupPlus_BackupModule {
	public $options = array();
	public $saves = 0;
	public function get_options() { return $this->options; }
	public function set_options($options, $save = false, $instance_id = null) { $this->options = $options; if ($save) $this->saves++; return true; }
}
class BackupPlus_Options {
	public static $options;
	public static $saves = 0;
	public static $fail_save = false;
	public static function user_can_manage() { global $dropbox_can_manage; return $dropbox_can_manage; }
	public static function get_backupplus_option($name) { return self::$options; }
	public static function update_backupplus_option($name, $options) {
		if (self::$fail_save) return false;
		self::$options = $options;
		self::$saves++;
		return true;
	}
}
$backupplus = new DropboxTestBackupPlus();
if (isset($argv[1]) && '--metadata-only' === $argv[1]) {
	update_site_option(BackupPlus_Credential_Key_Store::OPTION, $argv[2]);
	expect_dropbox(!class_exists('phpseclib3\\Crypt\\Random', false), 'Metadata regression must start before crypto is loaded');
	BackupPlus_Credential_Key_Store::mark_upgraded();
	expect_dropbox(BackupPlus_Credential_Key_Store::upgrade_complete(), 'Key metadata must be writable before crypto is loaded');
	echo "METADATA-ONLY:PASS\n";
	exit(0);
}
$backupplus->ensure_phpseclib();
if (isset($argv[1]) && '--restricted-child' === $argv[1]) {
	define('WP_CONTENT_DIR', $argv[2]);
	$config = BackupPlus_Credential_Key_Store::configuration();
	$blocked = false;
	set_error_handler(function($severity, $message) { if (error_reporting() & $severity) throw new RuntimeException($message); });
	try { BackupPlus_Credential_Key_Store::relocate($argv[3]); } catch (Exception $e) { $blocked = true; }
	restore_error_handler();
	expect_dropbox($blocked && $config === BackupPlus_Credential_Key_Store::configuration(), 'open_basedir restrictions must preserve the working key');
	echo "RESTRICTED-HOST:PASS\n";
	exit(0);
}
if (isset($argv[1]) && '--managed-child' === $argv[1]) {
	define('WP_CONTENT_DIR', $argv[2]);
	$config = BackupPlus_Credential_Key_Store::configuration();
	echo 'KEY-FINGERPRINT:'.hash('sha256', json_encode($config))."\n";
	exit(0);
}
if (isset($argv[1]) && '--managed' === $argv[1]) {
	require __DIR__.'/check-credential-key-store.php';
	exit(0);
}
if (!isset($argv[1])) {
	passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --managed', $managed_status);
	expect_dropbox(0 === $managed_status, 'Managed key storage checks must pass');
}

// A new PHP process restores only the DB/settings export and separate server config.
if (isset($argv[1]) && '--restore' === $argv[1]) {
	define('WP_CONTENT_DIR', dirname($argv[2]).'/missing-content');
	$db = json_decode(file_get_contents($argv[2]), true);
	if (isset($argv[3])) require $argv[3];
	if (!isset($argv[3])) {
		BackupPlus_Options::$options = array('settings' => array('instance' => array('tk_access_token' => $db['token'])));
		expect_dropbox_failure(function() { Dropbox_Encrypter::from_config('instance'); }, 'A database copy alone must not restore credentials');
	} else {
		$restored = Dropbox_Encrypter::from_config('instance')->decrypt($db['token']);
		expect_dropbox(hash('sha256', $restored) === $db['plaintext_hash'], 'Restart/restore with separate key material must work');
	}
	exit(0);
}
$keys = array('old' => base64_encode(random_bytes(32)), 'new' => base64_encode(random_bytes(32)));
$key_fixture = sys_get_temp_dir().'/backupplus-keyring-'.uniqid();
mkdir($key_fixture, 0700);
define('WP_CONTENT_DIR', $key_fixture.'/content');
define('BACKUPPLUS_CREDENTIAL_KEY_DIR', $key_fixture);
file_put_contents($key_fixture.'/credentials.php', BackupPlus_Credential_Key_Store::encode(array('keys' => $keys, 'active' => 'new')));
register_shutdown_function(function() use ($key_fixture) {
	foreach (scandir($key_fixture) as $file) if ('.' !== $file && '..' !== $file) unlink($key_fixture.'/'.$file);
	rmdir($key_fixture);
});
$context = 'dropbox:1:instance';
$encrypter = Dropbox_Encrypter::from_config('instance');
$old = new Dropbox_Encrypter($keys, 'old', $context);
$token = (object) array('oauth_token' => 'disposable-access', 'refresh_token' => 'disposable-refresh', 'token_type' => 'bearer');
$plain = serialize($token);
$encrypted = $encrypter->encrypt($plain);
expect_dropbox(0 === strpos($encrypted, 'bpcred:v1:new:'), 'Writes must use the versioned envelope and active key ID');
expect_dropbox($plain === $encrypter->decrypt($encrypted), 'Authenticated credentials must roundtrip');
expect_dropbox($encrypted !== $encrypter->encrypt($plain), 'Repeated writes must use independent nonces');
$parts = explode(':', $encrypted);
$raw = base64_decode($parts[3]);
$nonce = substr($raw, 0, 12);
$ciphertext = substr($raw, 12, -16);
$tag = substr($raw, -16);
expect_dropbox($plain === openssl_decrypt($ciphertext, 'aes-256-gcm', base64_decode($keys['new']), OPENSSL_RAW_DATA, $nonce, $tag, 'bpcred:v1:new:'.$context), 'Envelope must interoperate with native AES-256-GCM');
$internal = new \phpseclib3\Crypt\AES('gcm');
$internal->setPreferredEngine('PHP');
$internal->setKey(base64_decode($keys['new']));
$internal->setNonce($nonce);
$internal->setAAD('bpcred:v1:new:'.$context);
$internal->setTag($tag);
expect_dropbox($plain === $internal->decrypt($ciphertext), 'The portable PHP implementation must read the same envelope');
for ($i = 0; $i < strlen($raw); $i++) {
	$modified = $raw;
	$modified[$i] = chr(ord($modified[$i]) ^ 1);
	expect_dropbox(false === $encrypter->decrypt('bpcred:v1:new:'.base64_encode($modified)), 'Every nonce/ciphertext/tag byte must be authenticated');
}
foreach (array(null, array(), '', 'not base64!', base64_encode($plain), $plain, 'bpcred:v2:new:'.$parts[3], 'bpcred:v1:unknown:'.$parts[3], 'bpcred:v1:old:'.$parts[3], substr($encrypted, 0, -4), $encrypted.'AAAA', 'bpcred:v1:new:'.base64_encode('short')) as $invalid) {
	expect_dropbox(false === $encrypter->decrypt($invalid), 'Invalid, unknown, truncated and legacy formats must fail');
}
expect_dropbox(false === (new Dropbox_Encrypter($keys, 'new', 'dropbox:1:another'))->decrypt($encrypted), 'Credentials must be bound to their provider instance');
expect_dropbox(false === (new Dropbox_Encrypter($keys, 'new', 'dropbox:2:instance'))->decrypt($encrypted), 'Credentials must be bound to their multisite blog');
$other_site = new Dropbox_Encrypter(array('new' => base64_encode(random_bytes(32))), 'new', $context);
expect_dropbox(false === $other_site->decrypt($encrypted), 'Another installation key must not decrypt a database copy');
foreach (array(array(), array('new' => 'short'), array('new' => base64_encode(random_bytes(31))), array('bad:id' => $keys['new'])) as $bad) {
	expect_dropbox_failure(function() use ($bad, $context) { new Dropbox_Encrypter($bad, 'new', $context); }, 'Bad key configuration must fail closed');
}
expect_dropbox_failure(function() use ($keys, $context) { new Dropbox_Encrypter($keys, 'missing', $context); }, 'Missing active key must fail');
echo "PASS: AES-GCM interoperability, portable engine, nonce uniqueness, tampering, context binding and configuration failures\n";

$module = new DropboxTestModule();
$storage = new Dropbox_WordPress($encrypter, 'tk_', 'backupplus_dropbox', $module);
expect_dropbox_failure(function() use ($module) { new Dropbox_WordPress(null, 'tk_', 'backupplus_dropbox', $module); }, 'Unencrypted storage must be unavailable');
foreach (array('access_token', 'request_token') as $type) {
	$storage->set($token, $type);
	expect_dropbox(0 === strpos($module->options['tk_'.$type], 'bpcred:v1:new:'), 'All token writes must be encrypted');
	expect_dropbox($plain === serialize($storage->get($type)), 'Storage must decrypt authenticated credentials');
}
$before = $module->options;
$saves = $module->saves;
$backupplus->unavailable = true;
expect_dropbox_failure(function() use ($storage, $token) { $storage->set($token, 'access_token'); }, 'Encryption failure must stop persistence');
expect_dropbox($before === $module->options && $saves === $module->saves, 'Encryption failure must preserve saved credentials');
$backupplus->unavailable = false;
$module->options['tk_access_token'] = $plain;
expect_dropbox_failure(function() use ($storage) { $storage->get('access_token'); }, 'Plaintext must never be used as a fallback');
echo "PASS: WordPress token storage and failure paths preserve credentials without plaintext fallback\n";

$legacy = json_decode(file_get_contents(__DIR__.'/fixtures/dropbox-legacy-token.json'), true);
expect_dropbox(false === $encrypter->decrypt($legacy['ciphertext']), 'The runtime must reject shared-key CBC credentials');
$run_migration = function($mode) {
	$args = array($mode);
	require __DIR__.'/migrate-dropbox-tokens.php';
};
$base = array('version' => 1, 'settings' => array('instance' => array('folder' => 'test', 'tk_access_token' => $legacy['ciphertext'], 'tk_request_token' => $token)));
BackupPlus_Options::$options = $base;
expect_dropbox_failure(function() use ($run_migration) { $run_migration('rotate'); }, 'Rotation must not silently decode legacy credentials');
expect_dropbox($base === BackupPlus_Options::$options && 0 === BackupPlus_Options::$saves, 'Wrong migration mode must not mutate settings');
$run_migration('migrate-legacy');
$migrated = BackupPlus_Options::$options;
expect_dropbox(base64_decode($legacy['plaintext']) === $encrypter->decrypt($migrated['settings']['instance']['tk_access_token']), 'The saved pre-change CBC fixture must migrate');
expect_dropbox($plain === $encrypter->decrypt($migrated['settings']['instance']['tk_request_token']), 'Old plaintext request tokens must migrate only explicitly');
$run_migration('migrate-legacy');
expect_dropbox($migrated === BackupPlus_Options::$options && 1 === BackupPlus_Options::$saves, 'Re-running legacy migration must be a no-op');
BackupPlus_Options::$options['settings']['instance']['tk_access_token'] = $old->encrypt($plain);
$run_migration('rotate');
$rotated = BackupPlus_Options::$options['settings']['instance']['tk_access_token'];
$new_only = new Dropbox_Encrypter(array('new' => $keys['new']), 'new', $context);
expect_dropbox($plain === $new_only->decrypt($rotated), 'Rotated credentials must work after retiring the old key');
expect_dropbox(false === $new_only->decrypt($old->encrypt($plain)), 'Retired keys must not decrypt old database copies');
foreach (array('invalid legacy', 'bpcred:v1:unknown:'.base64_encode(str_repeat('x', 60))) as $invalid) {
	$bad = $base;
	$bad['settings']['broken'] = array('tk_access_token' => $invalid);
	BackupPlus_Options::$options = $bad;
	delete_option(BackupPlus_Dropbox_Token_Migration::OPTION);
	expect_dropbox_failure(function() { BackupPlus_Dropbox_Token_Migration::upgrade_restored(); }, 'Malformed/unknown credentials must abort the complete migration');
	expect_dropbox($bad === BackupPlus_Options::$options, 'A bad later entry must preserve all original ciphertexts');
}
BackupPlus_Options::$options = $base;
delete_option(BackupPlus_Dropbox_Token_Migration::OPTION);
BackupPlus_Options::$fail_save = true;
expect_dropbox_failure(function() { BackupPlus_Dropbox_Token_Migration::upgrade_restored(); }, 'Persistence failure must be reported');
expect_dropbox($base === BackupPlus_Options::$options, 'Failed persistence must retain original credentials');
BackupPlus_Options::$fail_save = false;
foreach (WP_CLI::$messages as $message) {
	expect_dropbox(false === strpos($message, 'disposable-') && false === strpos($message, $keys['new']), 'Migration diagnostics must contain no secrets');
}
echo "PASS: one-time legacy fixture upgrade, idempotence, key rotation/retirement and all-or-nothing preparation\n";

$fixture = sys_get_temp_dir().'/backupplus-dropbox-'.uniqid();
mkdir($fixture, 0700);
register_shutdown_function(function() use ($fixture) {
	foreach (glob($fixture.'/*') as $file) unlink($file);
	rmdir($fixture);
});
file_put_contents($fixture.'/database.json', json_encode(array('token' => $encrypted, 'plaintext_hash' => hash('sha256', $plain))));
file_put_contents($fixture.'/server-config.php', '<?php define("BACKUPPLUS_CREDENTIAL_KEY_DIR", '.var_export($key_fixture, true).');');
chmod($fixture.'/server-config.php', 0600);
$db = file_get_contents($fixture.'/database.json');
foreach ($keys as $encoded) expect_dropbox(false === strpos($db, $encoded) && false === strpos($db, base64_decode($encoded)), 'The settings export must contain no key material');
foreach (array(false, true) as $with_key) {
	$command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --restore '.escapeshellarg($fixture.'/database.json');
	if ($with_key) $command .= ' '.escapeshellarg($fixture.'/server-config.php');
	passthru($command, $status);
	expect_dropbox(0 === $status, 'A fresh process must enforce restore/key requirements');
}
echo "PASS: fresh-process restart/restore with separate keys; database-only copy cannot recover credentials\n";
