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
$fixture = sys_get_temp_dir().'/backupplus-providers-'.uniqid();
mkdir($fixture);
mkdir($fixture.'/methods');
register_shutdown_function(function() use ($fixture) {
	foreach (glob($fixture.'/methods/*') as $file) unlink($file);
	rmdir($fixture.'/methods');
	foreach (glob($fixture.'/*') as $file) unlink($file);
	rmdir($fixture);
});

$expected = array();
foreach (glob($source.'/methods/*.php') as $file) {
	$headers = get_file_data($file, array('name' => 'BackupPlus Provider', 'id' => 'Provider ID'));
	if ($headers['name'] && ('all' === $removed || $removed === $headers['id'])) continue;
	copy($file, $fixture.'/methods/'.basename($file));
	if ($headers['name']) $expected[$headers['id']] = $headers['name'];
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
}
PHP
);
if ('all' !== $removed) $expected['fixture'] = 'a Fixture Storage';
natcasesort($expected);
file_put_contents($fixture.'/methods/unregistered.php', '<?php throw new RuntimeException("Helpers must not be executed during discovery");');

// Keep option writes inside this test process (including storage format migration).
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
}
foreach (array('../backupplus', 'template', 'unregistered', array(), null, '') as $id) {
	backupplus_provider_expect(is_wp_error(BackupPlus_Storage_Methods_Interface::get_storage_object($id)), 'Unregistered input must return WP_Error');
}
if ($removed && 'all' !== $removed) {
	backupplus_provider_expect(is_wp_error(BackupPlus_Storage_Methods_Interface::get_storage_object($removed)), 'Removed provider must return WP_Error');
	backupplus_provider_expect(array() === BackupPlus_Storage_Methods_Interface::get_storage_objects_and_ids(array($removed)), 'Stale provider settings must be skipped');
}

wp_set_current_user(1);
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
$result = $backupplus_admin->do_credentials_test(array('method' => 'missingprovider'), true);
backupplus_provider_expect(false === $result['data']['success'], 'Credentials test must handle an unavailable provider');
foreach (array('auth_remote_method', 'deauth_remote_method') as $action) {
	$result = $backupplus_admin->$action(array('remote_method' => 'missingprovider', 'instance_id' => 'missing'));
	backupplus_provider_expect('error' === $result['result'], 'Authentication must handle an unavailable provider');
}
echo 'PASS: '.($removed ? "removed $removed" : 'all providers installed')."; discovery, construction, settings and stale requests\n";
