<?php
/**
 * WordPress and key store shims for the credential storage tests. Loaded once per test process.
 * The state they keep is held by Credential_Test_State, which the test resets.
 */
define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
define( 'BACKUPPLUS_DIR', dirname( __DIR__, 2 ) );
define( 'BACKUPPLUS_PHPSECLIB_MIN_PHP_VERSION', '5.6.1' );

class Credential_Test_State {
	/** Callbacks registered through add_filter(), by hook and priority. */
	public static $filters = array();
	/** Site options by blog ID, then option name. */
	public static $options = array();
	/** Network options. */
	public static $site_options = array();
	public static $blog_id      = 1;
	/** The option name whose saves fail, and optionally the only blog it fails on. */
	public static $fail_save  = null;
	public static $fail_blog  = null;
	public static $multisite  = false;
	public static $blog_stack = array();

	public static function reset() {
		self::$filters      = array();
		self::$options      = array();
		self::$site_options = array();
		self::$blog_id      = 1;
		self::$fail_save    = null;
		self::$fail_blog    = null;
		self::$multisite    = false;
		self::$blog_stack   = array();
	}
}
function backupplus_try_include_file( $file, $mode ) {
	require_once BACKUPPLUS_DIR . '/' . $file; }
function add_action() {}
function do_action() {}
function add_filter( $name, $callback, $priority = 10, $args = 1 ) {
	Credential_Test_State::$filters[ $name ][ $priority ][] = array( $callback, $args ); }
function apply_filters( $name, $value, ...$args ) {
	$callbacks = isset( Credential_Test_State::$filters[ $name ] ) ? Credential_Test_State::$filters[ $name ] : array();
	ksort( $callbacks );
	foreach ( $callbacks as $entries ) {
		foreach ( $entries as $entry ) {
			$value = call_user_func_array( $entry[0], array_slice( array_merge( array( $value ), $args ), 0, $entry[1] ) );
		}
	}
	return $value;
}
function get_current_blog_id() {
	return Credential_Test_State::$blog_id; }
function get_option( $name, $default = false ) {
	return isset( Credential_Test_State::$options[ get_current_blog_id() ][ $name ] ) ? Credential_Test_State::$options[ get_current_blog_id() ][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) {
	if ( Credential_Test_State::$fail_save === $name && ( null === Credential_Test_State::$fail_blog || Credential_Test_State::$fail_blog === get_current_blog_id() ) ) {
		return false;
	}
	if ( ! isset( Credential_Test_State::$options[ get_current_blog_id() ][ $name ] ) ) {
		$value = apply_filters( 'sanitize_option_' . $name, $value, $name );
	}
	Credential_Test_State::$options[ get_current_blog_id() ][ $name ] = apply_filters( 'sanitize_option_' . $name, $value, $name );
	return true; }
function delete_option( $name ) {
	unset( Credential_Test_State::$options[ get_current_blog_id() ][ $name ] ); }
function get_site_option( $name, $default = false ) {
	return isset( Credential_Test_State::$site_options[ $name ] ) ? Credential_Test_State::$site_options[ $name ] : $default; }
function update_site_option( $name, $value ) {
	Credential_Test_State::$site_options[ $name ] = $value;
	return true; }
function is_multisite() {
	return Credential_Test_State::$multisite; }
function get_current_network_id() {
	return 1; }
function get_sites( $args ) {
	return array( 1, 2 ); }
function switch_to_blog( $id ) {
	Credential_Test_State::$blog_stack[] = Credential_Test_State::$blog_id;
	Credential_Test_State::$blog_id      = $id; }
function restore_current_blog() {
	Credential_Test_State::$blog_id = array_pop( Credential_Test_State::$blog_stack ); }
function is_wp_error( $value ) {
	return $value instanceof WP_Error; }
class WP_Error {}
class BackupPlus_Storage_Provider_Factory {
	public static function get_methods() {
		return array_fill_keys( array( 'ftp', 'sftp', 'webdav', 's3', 's3generic', 'dreamobjects', 'cloudfiles', 'openstack', 'googledrive', 'dropbox', 'azure', 'backblaze', 'googlecloud', 'onedrive', 'pcloud', 'future' ), 'Test' ); }
}
class BackupPlus_Credential_Key_Store {
	public static $config;
	public static $complete = false;
	public static function configuration() {
		if ( ! self::$config ) {
			throw new RuntimeException( 'Missing key' );
		} return self::$config; }
	public static function upgrade_complete( $scope ) {
		return self::$complete; }
	public static function mark_upgraded( $scope ) {
		self::$complete = true; }
}
class CredentialTestDB {
	public $options  = 'options';
	public $sitemeta = 'sitemeta';
	public function prepare( $query, $value ) {
		return $query; }
	public function esc_like( $value ) {
		return $value; }
	public function get_col( $query ) {
		return array_keys( Credential_Test_State::$site_options ); }
}
$GLOBALS['wpdb'] = new CredentialTestDB();
// No database here: job lookups list the in-memory site options.
class BackupPlus_SQL {
	public static function wpdb() {
		return new self(); }
	public function column( $template, $parts = array(), $values = array() ) {
		return array_keys( Credential_Test_State::$site_options ); }
}
