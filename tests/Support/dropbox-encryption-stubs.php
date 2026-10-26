<?php
/**
 * WordPress shims and the plugin files for the Dropbox credential encryption and key store tests.
 *
 * Loaded once per process, by the tests and by the child processes they spawn. Mutable state lives in globals and static
 * properties that dropbox_stubs_reset() puts back to a clean installation.
 */

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
define( 'BACKUPPLUS_DIR', dirname( __DIR__, 2 ) );
define( 'BACKUPPLUS_PHPSECLIB_MIN_PHP_VERSION', '5.6.1' );
define( 'WP_CLI', true );

/** Without OpenSSL and sodium the library has to fall back to its pure PHP engines. */
if ( ! function_exists( 'openssl_encrypt' ) ) {
	define( 'BACKUPPLUS_ENCRYPTION_ENGINE', 'PHP' );
	require BACKUPPLUS_DIR . '/scripts/fixtures/without-crypto-extensions.php';
}

function __( $text, $domain = null ) {
	return $text; }
function is_wp_error( $value ) {
	return $value instanceof WP_Error; }
function get_current_blog_id() {
	global $dropbox_blog_id;
	return $dropbox_blog_id; }
function get_current_network_id() {
	return 1; }
function get_sites( $args ) {
	return array( 1, 2, 3 ); }
function switch_to_blog( $id ) {
	global $dropbox_blog_id, $dropbox_blog_stack;
	$dropbox_blog_stack[] = $dropbox_blog_id;
	$dropbox_blog_id      = $id; }
function restore_current_blog() {
	global $dropbox_blog_id, $dropbox_blog_stack;
	$dropbox_blog_id = array_pop( $dropbox_blog_stack ); }
function wp_upload_dir( $time = null, $create = true ) {
	return array(
		'basedir' => WP_CONTENT_DIR . '/uploads',
		'error'   => false,
	); }
function is_multisite() {
	global $dropbox_is_multisite;
	return $dropbox_is_multisite; }
function current_user_can( $capability ) {
	global $dropbox_can_manage, $dropbox_network_admin;
	return 'manage_network_options' === $capability ? $dropbox_network_admin : $dropbox_can_manage; }
function backupplus_try_include_file( $path, $mode ) {
	require_once BACKUPPLUS_DIR . '/' . $path; }
/** The plugin files register their hooks as they load, so the registry must exist before and survive a reset. */
$GLOBALS['dropbox_hooks'] = array();
function add_action( $hook, $callback ) {
	$GLOBALS['dropbox_hooks'][ $hook ][] = $callback; }
function add_filter( $hook, $callback ) {
	add_action( $hook, $callback ); }
function do_action( $hook, ...$args ) {
	foreach ( $GLOBALS['dropbox_hooks'][ $hook ] ?? array() as $callback ) {
		$callback( ...$args );
	} }
function apply_filters( $hook, $value ) {
	foreach ( $GLOBALS['dropbox_hooks'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value );
	} return $value; }
class WP_Error {}
function get_option( $name, $default = false ) {
	global $dropbox_test_options, $dropbox_other_blog_options;
	$options = 1 === get_current_blog_id() ? $dropbox_test_options : ( isset( $dropbox_other_blog_options[ get_current_blog_id() ] ) ? $dropbox_other_blog_options[ get_current_blog_id() ] : array() );
	return isset( $options[ $name ] ) ? $options[ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) {
	global $dropbox_test_options, $dropbox_other_blog_options;
	if ( 1 === get_current_blog_id() ) {
		$dropbox_test_options[ $name ] = $value;
	} else {
		$dropbox_other_blog_options[ get_current_blog_id() ][ $name ] = $value;
	}
	return true; }
function delete_option( $name ) {
	global $dropbox_test_options, $dropbox_other_blog_options;
	if ( 1 === get_current_blog_id() ) {
		unset( $dropbox_test_options[ $name ] );
	} else {
		unset( $dropbox_other_blog_options[ get_current_blog_id() ][ $name ] );
	}
	return true; }
function get_site_option( $name, $default = false ) {
	global $dropbox_test_options;
	return isset( $dropbox_test_options[ $name ] ) ? $dropbox_test_options[ $name ] : $default; }
function update_site_option( $name, $value ) {
	global $dropbox_test_options;
	$dropbox_test_options[ $name ] = $value;
	return true; }

class WP_CLI {
	public static $messages = array();
	public static function error( $message ) {
		throw new RuntimeException( $message ); }
	public static function success( $message ) {
		self::$messages[] = $message; }
}

require BACKUPPLUS_DIR . '/class-backupplus.php';
require BACKUPPLUS_DIR . '/methods/backup-module.php';
require BACKUPPLUS_DIR . '/methods/dropbox/Dropbox2/Exception.php';
require BACKUPPLUS_DIR . '/methods/dropbox/Dropbox2/OAuth/Storage/StorageInterface.php';
require BACKUPPLUS_DIR . '/methods/dropbox/Dropbox2/OAuth/Storage/Encrypter.php';
require BACKUPPLUS_DIR . '/includes/class-credential-key-store.php';
require BACKUPPLUS_DIR . '/methods/dropbox/bootstrap.php';
require BACKUPPLUS_DIR . '/methods/dropbox/Dropbox2/OAuth/Storage/WordPress.php';

class DropboxTestBackupPlus extends BackupPlus {
	public $unavailable = false;
	public function __construct() {}
	public function log( $line, $level = 'notice', $uniq_id = false, $skip_dblog = false ) {}
	public function ensure_phpseclib() {
		return $this->unavailable ? new WP_Error() : parent::ensure_phpseclib(); }
}

class DropboxTestModule extends BackupPlus_BackupModule {
	public $options = array();
	public $saves   = 0;
	public function get_options() {
		return $this->options; }
	public function set_options( $options, $save = false, $instance_id = null ) {
		$this->options = $options;
		if ( $save ) {
			++$this->saves;
		} return true; }
}

class BackupPlus_Options {
	public static $options;
	public static $blog_options = array();
	public static $saves        = 0;
	public static $fail_save    = false;
	public static $fail_blog_id = null;
	public static function user_can_manage() {
		global $dropbox_can_manage;
		return $dropbox_can_manage; }
	public static function get_backupplus_option( $name ) {
		return 1 === get_current_blog_id() ? self::$options : self::$blog_options[ get_current_blog_id() ]; }
	public static function update_backupplus_option( $name, $options ) {
		if ( self::$fail_save || self::$fail_blog_id === get_current_blog_id() ) {
			return false;
		}
		if ( 1 === get_current_blog_id() ) {
			self::$options = $options;
		} else {
			self::$blog_options[ get_current_blog_id() ] = $options;
		}
		++self::$saves;
		return true;
	}
}

/** A clean single site installation of a user who can manage everything. */
function dropbox_stubs_reset() {
	$GLOBALS['dropbox_blog_id']            = 1;
	$GLOBALS['dropbox_blog_stack']         = array();
	$GLOBALS['dropbox_is_multisite']       = false;
	$GLOBALS['dropbox_network_admin']      = true;
	$GLOBALS['dropbox_can_manage']         = true;
	$GLOBALS['dropbox_test_options']       = array();
	$GLOBALS['dropbox_other_blog_options'] = array();
	WP_CLI::$messages                      = array();
	BackupPlus_Options::$options           = null;
	BackupPlus_Options::$blog_options      = array();
	BackupPlus_Options::$saves             = 0;
	BackupPlus_Options::$fail_save         = false;
	BackupPlus_Options::$fail_blog_id      = null;
	$GLOBALS['backupplus']                 = new DropboxTestBackupPlus();
}
