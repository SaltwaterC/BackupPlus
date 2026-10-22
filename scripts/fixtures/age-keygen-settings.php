<?php
/** Disposable, request-scoped settings isolation for the actual browser Save Changes test. */
if (!defined('WP_CLI') || !WP_CLI) die('CLI only.');
$token = isset($args[0]) ? $args[0] : '';
$operation = isset($args[1]) ? $args[1] : '';
if (!preg_match('/\A[a-f0-9]{24}\z/D', $token)) throw new RuntimeException('Invalid test token.');
$option = 'backupplus_keygen_test_'.$token;
$path = WPMU_PLUGIN_DIR.'/'.$option.'.php';
if ('setup' === $operation) {
	add_option($option,array('mode'=>'none','sources'=>array(),'recipients'=>array()),'','no');
	$code = <<<'PHP'
<?php
if (!isset($_SERVER['HTTP_X_BACKUPPLUS_KEYGEN_TEST']) || !hash_equals(TEST_TOKEN, $_SERVER['HTTP_X_BACKUPPLUS_KEYGEN_TEST'])) return;
add_filter('pre_option_backupplus_database_encryption', function() { return get_option(TEST_OPTION); }, 100);
add_filter('pre_update_option', function($value,$name,$old) {
    if (TEST_OPTION === $name) return $value;
    if ('backupplus_database_encryption' === $name) update_option(TEST_OPTION,$value,false);
    return $old;
}, PHP_INT_MAX, 3);
PHP;
	$code = strtr($code,array('TEST_TOKEN'=>var_export($token,true),'TEST_OPTION'=>var_export($option,true)));
	if (false === file_put_contents($path,$code)) throw new RuntimeException('Cannot create settings isolation.');
} elseif ('original' === $operation) {
	global $wpdb;
	$rows = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE (option_name LIKE 'backupplus_%' OR option_name = 'rewrite_rules') AND option_name NOT LIKE 'backupplus_keygen_test_%' AND option_name NOT LIKE 'backupplus_jobdata_%' ORDER BY option_name", ARRAY_A);
	echo json_encode(hash('sha256',serialize($rows)));
} elseif ('read' === $operation) {
	echo json_encode(get_option($option));
} elseif ('cleanup' === $operation) {
	delete_option($option);
	if (is_file($path)) unlink($path);
} else throw new RuntimeException('Invalid operation.');
