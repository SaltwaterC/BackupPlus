<?php
/** Loaded by the Dropbox regression harness in a process without external keys. */
require BACKUPPLUS_DIR.'/includes/class-credential-key-admin.php';
$fixture = sys_get_temp_dir().'/backupplus-managed-'.uniqid();
mkdir($fixture, 0700);
define('WP_CONTENT_DIR', $fixture.'/docroot/wp-content');
mkdir(WP_CONTENT_DIR, 0700, true);
$remove_tree = function($path) use (&$remove_tree) {
	if (!is_dir($path) || is_link($path)) { if (file_exists($path)) unlink($path); return; }
	foreach (scandir($path) as $file) if ('.' !== $file && '..' !== $file) $remove_tree($path.'/'.$file);
	rmdir($path);
};
register_shutdown_function(function() use ($fixture, $remove_tree) { $remove_tree($fixture); });

$legacy = json_decode(file_get_contents(__DIR__.'/fixtures/dropbox-legacy-token.json'), true);
BackupPlus_Options::$options = array('version' => 1, 'settings' => array('instance' => array('tk_access_token' => $legacy['ciphertext'])));
BackupPlus_Credential_Key_Store::initialize();
$config = BackupPlus_Credential_Key_Store::configuration();
$directory = BackupPlus_Credential_Key_Store::directory();
expect_dropbox($directory === WP_CONTENT_DIR.'/backupplus-private', 'Default installation must create a dedicated protected file');
expect_dropbox(is_file($directory.'/credentials.php') && is_file($directory.'/.donotbackup') && is_file($directory.'/.htaccess') && is_file($directory.'/web.config'), 'Installation must add backup and HTTP protections');
expect_dropbox(0600 === (fileperms($directory.'/credentials.php') & 0777), 'The private key file must be owner-readable only');
expect_dropbox(0700 === (fileperms($directory) & 0777), 'The private key directory must be owner-accessible only');
delete_option(BackupPlus_Credential_Key_Store::OPTION);
expect_dropbox($config === BackupPlus_Credential_Key_Store::configuration(), 'Restoring location metadata must rediscover a surviving key file');
$encrypter = Dropbox_Encrypter::from_config('instance');
$encrypted = BackupPlus_Options::$options['settings']['instance']['tk_access_token'];
expect_dropbox(base64_decode($legacy['plaintext']) === $encrypter->decrypt($encrypted), 'Installation/upgrade must migrate existing Dropbox credentials without a CLI');
expect_dropbox(1 === (int) get_option(BackupPlus_Dropbox_Token_Migration::OPTION), 'Upgrade must record completion');
BackupPlus_Credential_Key_Store::initialize();
expect_dropbox($config === BackupPlus_Credential_Key_Store::configuration() && $encrypted === BackupPlus_Options::$options['settings']['instance']['tk_access_token'], 'Repeated installation must preserve keys and ciphertext');
$metadata_command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/test-dropbox-encryption.php').' --metadata-only '.escapeshellarg($directory);
exec($metadata_command, $metadata_output, $metadata_status);
expect_dropbox(0 === $metadata_status && in_array('METADATA-ONLY:PASS', $metadata_output, true), 'Key metadata updates must work in a fresh process without the crypto autoloader');
BackupPlus_Options::$options['settings']['instance']['tk_access_token'] = $legacy['ciphertext'];
delete_option(BackupPlus_Dropbox_Token_Migration::OPTION);
BackupPlus_Credential_Key_Store::initialize();
expect_dropbox($legacy['ciphertext'] === BackupPlus_Options::$options['settings']['instance']['tk_access_token'], 'Deleting the database completion marker must never reopen the legacy decoder');
BackupPlus_Options::$options['settings']['instance']['tk_access_token'] = $encrypted;
expect_dropbox(BackupPlus_Credential_Key_Store::exclude(false, $directory.'/credentials.php') && BackupPlus_Credential_Key_Store::exclude(false, $directory), 'Key files and folders must be excluded from backups');
expect_dropbox(!BackupPlus_Credential_Key_Store::exclude(false, $directory.'-other'), 'Unrelated backup paths must remain included');
foreach (get_site_option(BackupPlus_Credential_Key_Store::OPTION) ? array(serialize($dropbox_test_options), serialize(BackupPlus_Options::$options)) : array() as $options) {
	foreach ($config['keys'] as $key) expect_dropbox(false === strpos($options, $key), 'Database options must never contain key material');
}
$guard_output = shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($directory.'/credentials.php'));
expect_dropbox(null === $guard_output || '' === $guard_output, 'Executing the key file must never disclose its contents');
echo "PASS: automatic installation, protected file, automatic one-time legacy upgrade, repeated initialization and backup exclusions\n";

$outside = $fixture.'/outside-docroot/private';
BackupPlus_Credential_Key_Admin::perform('move', $outside);
expect_dropbox($outside === BackupPlus_Credential_Key_Store::directory() && $config === BackupPlus_Credential_Key_Store::configuration(), 'Browser relocation outside docroot must preserve every key');
expect_dropbox(!file_exists($directory.'/credentials.php') && is_file($directory.'/.donotbackup'), 'Relocation must remove the old secret and retain backup protection');
$before = file_get_contents($outside.'/credentials.php');
mkdir($fixture.'/occupied');
file_put_contents($fixture.'/occupied/index.html', 'existing website');
expect_dropbox_failure(function() use ($fixture) { BackupPlus_Credential_Key_Admin::perform('move', $fixture.'/occupied'); }, 'Moving into a website folder must fail safely');
expect_dropbox($before === file_get_contents($outside.'/credentials.php') && 'existing website' === file_get_contents($fixture.'/occupied/index.html'), 'Failed relocation must leave the old key and destination files intact');
$dropbox_can_manage = false;
expect_dropbox_failure(function() use ($fixture) { BackupPlus_Credential_Key_Admin::perform('move', $fixture.'/unauthorized'); }, 'Unauthorized users must not manage keys');
$dropbox_can_manage = true;
$dropbox_is_multisite = true;
$dropbox_network_admin = false;
expect_dropbox_failure(function() { BackupPlus_Credential_Key_Admin::perform('rotate'); }, 'Subsite administrators must not manage shared network keys');
$dropbox_network_admin = true;
expect_dropbox(BackupPlus_Credential_Key_Admin::allowed(), 'Authorized network administrators must be able to manage keys');
$dropbox_is_multisite = false;
BackupPlus_Credential_Key_Admin::perform('rotate');
$rotated = BackupPlus_Credential_Key_Store::configuration();
expect_dropbox($rotated['active'] !== $config['active'] && isset($rotated['keys'][$config['active']]), 'Rotation must retain historical keys');
expect_dropbox(base64_decode($legacy['plaintext']) === Dropbox_Encrypter::from_config('instance')->decrypt($encrypted), 'Rotation must preserve in-flight and historical credentials');
$current_ciphertext = BackupPlus_Options::$options['settings']['instance']['tk_access_token'];
$older_recovery = BackupPlus_Credential_Key_Store::encode($config);
$restore_message = BackupPlus_Credential_Key_Admin::perform('restore', $older_recovery);
$reactivated = BackupPlus_Credential_Key_Store::configuration();
expect_dropbox($reactivated['active'] === $config['active'], 'Restoring a pre-rotation recovery copy must reactivate its active key');
expect_dropbox($reactivated['keys'] === $rotated['keys'], 'Restoring an older recovery copy must retain every newer key');
expect_dropbox(base64_decode($legacy['plaintext']) === Dropbox_Encrypter::from_config('instance')->decrypt($current_ciphertext), 'Credentials encrypted after the recovery copy was downloaded must remain readable');
$new_ciphertext = Dropbox_Encrypter::from_config('instance')->encrypt('restored-key-write');
expect_dropbox(0 === strpos($new_ciphertext, Dropbox_Encrypter::PREFIX.$config['active'].':'), 'Subsequent credential writes must use the restored active key');
expect_dropbox(false !== strpos($restore_message, 'active'), 'The restore result must tell users that the uploaded key is active');
BackupPlus_Credential_Key_Admin::perform('restore', $older_recovery);
expect_dropbox($reactivated === BackupPlus_Credential_Key_Store::configuration(), 'Repeated restoration of an older recovery copy must be idempotent');
BackupPlus_Credential_Key_Admin::perform('restore', BackupPlus_Credential_Key_Store::encode($rotated));
$recovery = BackupPlus_Credential_Key_Store::encode($rotated);
expect_dropbox_failure(function() { BackupPlus_Credential_Key_Admin::perform('restore', '<?php malicious();'); }, 'Uploaded recovery files must never execute PHP');
$wrong = $rotated;
$wrong['keys'][$rotated['active']] = base64_encode(random_bytes(32));
expect_dropbox_failure(function() use ($wrong) { BackupPlus_Credential_Key_Admin::perform('restore', BackupPlus_Credential_Key_Store::encode($wrong)); }, 'Conflicting recovery material must fail');
unlink($outside.'/credentials.php');
expect_dropbox_failure(function() { BackupPlus_Credential_Key_Store::configuration(); }, 'A lost key must never be silently regenerated');
expect_dropbox(!file_exists($outside.'/credentials.php'), 'Missing-key detection must not write replacement keys');
$unrelated = array('active' => 'unrelated', 'keys' => array('unrelated' => base64_encode(random_bytes(32))));
expect_dropbox_failure(function() use ($unrelated) { BackupPlus_Credential_Key_Admin::perform('restore', BackupPlus_Credential_Key_Store::encode($unrelated)); }, 'Recovery must verify saved credentials before publishing keys');
expect_dropbox(!file_exists($outside.'/credentials.php'), 'A wrong recovery file must not replace a lost key');
BackupPlus_Credential_Key_Admin::perform('restore', $recovery);
expect_dropbox($rotated === BackupPlus_Credential_Key_Store::configuration(), 'Browser restore must recover a missing key without SSH');
unlink($outside.'/credentials.php');
$restored_directory = $fixture.'/new-host/private';
BackupPlus_Credential_Key_Admin::perform('restore', $recovery, $restored_directory);
expect_dropbox($restored_directory === BackupPlus_Credential_Key_Store::directory() && $rotated === BackupPlus_Credential_Key_Store::configuration(), 'Browser recovery must accept a new folder when the original key location is gone');
expect_dropbox_failure(function() { BackupPlus_Credential_Key_Admin::perform('replace_missing'); }, 'Replacement must never overwrite an existing key file');
unlink($restored_directory.'/credentials.php');
$before_replacement = BackupPlus_Options::$options;
BackupPlus_Credential_Key_Admin::perform('replace_missing');
expect_dropbox($rotated !== BackupPlus_Credential_Key_Store::configuration() && $before_replacement === BackupPlus_Options::$options, 'Explicit missing-key replacement must permit reconnection while retaining saved credentials');
BackupPlus_Credential_Key_Admin::perform('restore', $recovery);
expect_dropbox(base64_decode($legacy['plaintext']) === Dropbox_Encrypter::from_config('instance')->decrypt($encrypted), 'Later recovery must merge old keys without discarding a replacement key');
echo "PASS: browser relocation outside docroot, permission checks, failure preservation, rotation and verified recovery without SSH\n";

// Simulate a shared host where wp-content itself is not writable to the plugin.
$remove_tree(WP_CONTENT_DIR.'/backupplus-private');
file_put_contents(WP_CONTENT_DIR.'/backupplus-private', 'blocked');
delete_option(BackupPlus_Credential_Key_Store::OPTION);
BackupPlus_Options::$options = array();
$fallback = BackupPlus_Credential_Key_Store::configuration();
expect_dropbox(WP_CONTENT_DIR.'/uploads/backupplus-private' === BackupPlus_Credential_Key_Store::directory(), 'Restricted hosts must automatically use writable uploads storage');
expect_dropbox(!empty($fallback['keys']), 'Fallback installation must create usable keys');
echo "PASS: shared-host fallback to writable uploads storage\n";

// Fresh processes racing to initialize the same installation must converge on one key.
$concurrent_root = $fixture.'/concurrent/wp-content';
mkdir($concurrent_root, 0700, true);
$children = array();
for ($i = 0; $i < 3; $i++) {
	$process = proc_open(array(PHP_BINARY, '-d', 'error_reporting=24575', __DIR__.'/test-dropbox-encryption.php', '--managed-child', $concurrent_root), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	expect_dropbox(is_resource($process), 'Concurrent initialization process must start');
	$children[] = array($process, $pipes);
}
$fingerprints = array();
foreach ($children as $child) {
	$output = stream_get_contents($child[1][1]);
	$errors = stream_get_contents($child[1][2]);
	fclose($child[1][1]); fclose($child[1][2]);
	expect_dropbox(0 === proc_close($child[0]), 'Concurrent initialization must succeed: '.$errors);
	expect_dropbox(1 === preg_match('/KEY-FINGERPRINT:([a-f0-9]{64})/', $output, $matches), 'Child initialization must return a fingerprint');
	$fingerprints[] = $matches[1];
}
expect_dropbox(1 === count(array_unique($fingerprints)), 'Concurrent installations must never create divergent keys');
echo "PASS: concurrent fresh-process installation and persistent file reuse\n";

$restricted_root = $fixture.'/restricted';
mkdir($restricted_root, 0700);
$allowed = BACKUPPLUS_DIR.PATH_SEPARATOR.$restricted_root;
$command = array(PHP_BINARY, '-d', 'error_reporting=24575', '-d', 'open_basedir='.$allowed, __DIR__.'/test-dropbox-encryption.php', '--restricted-child', $restricted_root.'/wp-content', $fixture.'/forbidden');
$process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
$output = stream_get_contents($pipes[1]);
$errors = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
expect_dropbox(0 === proc_close($process) && false !== strpos($output, 'RESTRICTED-HOST:PASS'), 'A host restricted by open_basedir must retain a working key: '.$errors);
echo "PASS: restricted open_basedir hosting rejects inaccessible paths without losing the working key\n";

require __DIR__.'/check-credential-key-rotation-network.php';
