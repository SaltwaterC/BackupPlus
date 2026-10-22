<?php
/**
 * Integration checks using an isolated plugin copy and real WordPress APIs.
 * Run a fresh process for each removal (or "all" for no providers):
 * wp --skip-plugins=backupplus eval-file wp-content/plugins/backupplus/scripts/test-storage-providers.php s3
 * Omit the last argument to check the complete installation.
 * Original provider files and saved options are never modified.
 */
if (!defined('ABSPATH') || defined('BACKUPPLUS_DIR')) {
	fwrite(STDERR, "Run using WP-CLI with --skip-plugins=backupplus.\n");
	exit(1);
}

function backupplus_provider_expect($condition, $message) {
	if (!$condition) throw new RuntimeException($message);
}

$source = dirname(__DIR__);
$removed = isset($args[0]) ? $args[0] : '';
$only = 0 === strpos($removed, 'only:') ? substr($removed, 5) : '';
if ($only) $removed = '';
$fixture = sys_get_temp_dir().'/backupplus-providers-'.uniqid();
mkdir($fixture);
mkdir($fixture.'/methods');
register_shutdown_function(function() use ($fixture) {
	backupplus_provider_remove_tree($fixture);
});

function backupplus_provider_remove_tree($path) {
	if (is_link($path) || !is_dir($path)) { unlink($path); return; }
	foreach (scandir($path) as $name) {
		if ('.' !== $name && '..' !== $name) backupplus_provider_remove_tree($path.'/'.$name);
	}
	rmdir($path);
}

function backupplus_provider_copy_tree($source, $target) {
	if (!is_dir($source)) { copy($source, $target); return; }
	mkdir($target);
	foreach (scandir($source) as $name) {
		if ('.' !== $name && '..' !== $name) backupplus_provider_copy_tree($source.'/'.$name, $target.'/'.$name);
	}
}

$expected = array();
foreach (glob($source.'/methods/*.php') as $file) {
	$headers = get_file_data($file, array('name' => 'BackupPlus Provider', 'id' => 'Provider ID'));
	if ($headers['name'] && ('all' === $removed || $removed === $headers['id'])) continue;
	if ($headers['name'] && $only && $only !== $headers['id']) continue;
	copy($file, $fixture.'/methods/'.basename($file));
	if ($headers['name']) {
		$expected[$headers['id']] = $headers['name'];
		$directory = $source.'/methods/'.$headers['id'];
		if (is_dir($directory)) backupplus_provider_copy_tree($directory, $fixture.'/methods/'.$headers['id']);
	}
}
foreach (glob($source.'/*') as $file) {
	if ('methods' === basename($file)) continue;
	if ('backupplus.php' === basename($file)) {
		copy($file, $fixture.'/backupplus.php');
	} else {
		symlink($file, $fixture.'/'.basename($file));
	}
}

// An entirely new provider with a custom class name must need no core changes.
if ('all' !== $removed) file_put_contents($fixture.'/methods/fixture.php', <<<'PHP'
<?php
/**
 * BackupPlus Provider: a Fixture Storage
 * Provider ID: fixture
 * Provider Class: FixtureStorage
 */
class FixtureStorage extends BackupPlus_BackupModule {
	public function get_id() { return 'fixture'; }
	public function get_supported_features() { return array('multi_options'); }
	public function get_default_options() { return array('fixture' => true); }
	public function options_exist($settings) { return true; }
	public function authentication_required($settings) { return true; }
	public function get_authentication_link($echo_instead_of_return = true, $template_instead_of_notice = true) { return 'Fixture authentication'; }
	public function get_admin_notices($settings) { return array(array('message' => 'Fixture provider notice', 'class' => 'updated')); }
}
PHP
);
if ('all' !== $removed) $expected['fixture'] = 'a Fixture Storage';
natcasesort($expected);
file_put_contents($fixture.'/methods/unregistered.php', '<?php throw new RuntimeException("Helpers must not be executed during discovery");');

// Keep option writes inside this test process (including storage format migration).
define('BACKUPPLUS_CREDENTIAL_KEY_DIR', $fixture.'/keys');
// Ignore live backup jobs during credential lifecycle checks.
add_filter('query', function($query) {
	return false !== strpos($query, 'SELECT option_name') && false !== strpos($query, 'backupplus') && false !== strpos($query, 'jobdata') ? 'SELECT option_name FROM '. $GLOBALS['wpdb']->options.' WHERE 1=0' : $query;
});
$test_options = array('backupplus_service' => $removed && 'all' !== $removed ? array($removed) : array());
add_filter('pre_option', function($value, $option) use (&$test_options) {
	if (0 !== strpos($option, 'backupplus_')) return $value;
	return array_key_exists($option, $test_options) ? $test_options[$option] : '';
}, 10, 2);
add_filter('pre_update_option', function($value, $option, $old) use (&$test_options) {
	if (0 !== strpos($option, 'backupplus_')) return $value;
	$test_options[$option] = $value;
	return $old;
}, 10, 3);

global $backupplus, $backupplus_admin;
require $fixture.'/backupplus.php';
backupplus_provider_expect($expected === $backupplus->backup_methods, 'Only installed providers should be registered in alphabetical order');
foreach ($expected as $id => $name) {
	$object = BackupPlus_Storage_Methods_Interface::get_storage_object($id);
	backupplus_provider_expect($object instanceof BackupPlus_BackupModule, "Cannot construct $id");
	backupplus_provider_expect($object === BackupPlus_Storage_Methods_Interface::get_storage_object($id), 'Shared objects should be cached');
	backupplus_provider_expect($object !== BackupPlus_Storage_Provider_Factory::create($id, false), 'Fresh objects should be independent');
	$authenticated = array(
		'dropbox' => array('tk_access_token' => 'fixture'), 'googledrive' => array('user_id' => 'fixture'),
		'googlecloud' => array('user_id' => 'fixture'), 'onedrive' => array('refresh_token' => 'fixture'),
		'pcloud' => array('pclauth' => 'fixture'),
	);
	if (isset($authenticated[$id])) {
		backupplus_provider_expect($object->authentication_required(array()), "$id must request authentication for an empty connection");
		backupplus_provider_expect(!$object->authentication_required($authenticated[$id]), "$id must recognize an authenticated connection");
	}
	if ('dreamobjects' === $id) {
		backupplus_provider_expect(array() === $object->get_admin_notices(array('endpoint' => 's3.us-east-005.dream.io')), 'Current DreamObjects endpoint should have no warning');
		backupplus_provider_expect('error' === $object->get_admin_notices(array('endpoint' => 'invalid.example'))[0]['class'], 'Invalid DreamObjects endpoint should show the provider error');
		backupplus_provider_expect('updated' === $object->get_admin_notices(array('endpoint' => 'objects-us-east-1.dream.io'))[0]['class'], 'Retired DreamObjects endpoint should show the migration notice');
	}
	if ('dropbox' === $id) {
		$path = $fixture.'/download';
		file_put_contents($path, 'fixture backupnull');
		$handle = fopen($path, 'r+');
		$object->download_complete($handle, $path);
		fclose($handle);
		backupplus_provider_expect('fixture backup' === file_get_contents($path), 'Dropbox must retain its historical download repair');
	}
	if (in_array($id, array('s3', 's3generic', 'dreamobjects'), true)) {
		$method = new ReflectionMethod($object, 'indicate_s3_class');
		if (PHP_VERSION_ID < 80100) $method->setAccessible(true);
		$class = $method->invoke($object);
		backupplus_provider_expect(0 === strpos((new ReflectionClass($class))->getFileName(), $fixture.'/methods/'.$id.'/'), "$id must load its own transport");
	}
}
foreach (array('../backupplus', 'template', 'unregistered', array(), null, '') as $id) {
	backupplus_provider_expect(is_wp_error(BackupPlus_Storage_Methods_Interface::get_storage_object($id)), 'Unregistered input must return WP_Error');
}
if ($removed && 'all' !== $removed) {
	backupplus_provider_expect(is_wp_error(BackupPlus_Storage_Methods_Interface::get_storage_object($removed)), 'Removed provider must return WP_Error');
	backupplus_provider_expect(array() === BackupPlus_Storage_Methods_Interface::get_storage_objects_and_ids(array($removed)), 'Stale provider settings must be skipped');
}

wp_set_current_user(1);
BackupPlus_Credential_Key_Store::initialize();
backupplus_provider_expect(!get_option('backupplus_credential_setup_error'), 'Credential initialization must work with the installed providers');
BackupPlus_Credential_Key_Admin::perform('upgrade');
BackupPlus_Credential_Key_Admin::perform('rotate');
$config = BackupPlus_Credential_Key_Store::configuration();
BackupPlus_Credential_Key_Admin::perform('restore', BackupPlus_Credential_Key_Store::encode($config));
backupplus_provider_expect(isset($expected['dropbox']) === class_exists('Dropbox_Encrypter', false), 'Dropbox helpers must only load when Dropbox is installed');
$backupplus->admin_menu();
$backupplus_admin->admin_enqueue_scripts();
$html = $backupplus_admin->include_template('wp-admin/settings/form-contents.php', true, array('options' => array()));
preg_match_all('/class="backupplus_servicecheckbox [^"]*"[^>]*value="([^"]+)"/', $html, $matches);
$visible = array();
foreach ($expected as $id => $name) {
	$object = BackupPlus_Storage_Methods_Interface::get_storage_object($id);
	if (!is_a($object, 'BackupPlus_BackupModule_AddonNotYetPresent')) $visible[] = $id;
	if ($object->supports_feature('config_templates') && is_callable(array($object, 'credentials_test'))) {
		$properties = $object->get_template_properties();
		backupplus_provider_expect(!empty($properties['input_test_label']), "$id settings test button must have an initial label");
	}
}
backupplus_provider_expect($visible === $matches[1], 'Settings must render available providers alphabetically');
if (isset($expected['fixture'])) {
	$test_options['backupplus_service'] = array('fixture');
	$test_options['backupplus_fixture'] = array('version' => 1, 'settings' => array('fixture-instance' => array('fixture' => true)));
	$setup = new ReflectionMethod($backupplus_admin, 'setup_all_admin_notices_global');
	if (PHP_VERSION_ID < 80100) $setup->setAccessible(true);
	// AJAX saves clear the notice hooks before setting them up again.
	for ($attempt = 0; $attempt < 2; $attempt++) {
		remove_all_actions('all_admin_notices');
		$setup->invoke($backupplus_admin, array('fixture'));
		ob_start();
		do_action('all_admin_notices');
		$notices = ob_get_clean();
		backupplus_provider_expect(1 === substr_count($notices, 'Fixture authentication'), 'New providers must supply authentication notices, including after an AJAX reset');
		backupplus_provider_expect(1 === substr_count($notices, 'Fixture provider notice'), 'New providers must supply dashboard notices, including after an AJAX reset');
	}
}
$result = $backupplus_admin->do_credentials_test(array('method' => 'missingprovider'), true);
backupplus_provider_expect(false === $result['data']['success'], 'Credentials test must handle an unavailable provider');
foreach (array('auth_remote_method', 'deauth_remote_method') as $action) {
	$result = $backupplus_admin->$action(array('remote_method' => 'missingprovider', 'instance_id' => 'missing'));
	backupplus_provider_expect('error' === $result['result'], 'Authentication must handle an unavailable provider');
}
echo 'PASS: '.($only ? "only $only" : ($removed ? "removed $removed" : 'all providers installed'))."; discovery, construction, credential lifecycle, settings and stale requests\n";
