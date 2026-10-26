<?php
/** WordPress shims and a job object for the SSH storage tests. Loaded once per process by SftpTest. */

use PHPUnit\Framework\Assert;

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
define( 'BACKUPPLUS_DIR', dirname( __DIR__, 2 ) );

function __( $text, $domain = null ) {
	return $text; }
function esc_html( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_attr( $text ) {
	return esc_html( $text ); }
function wp_kses_post( $text ) {
	return $text; }
function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, $args ); }
function apply_filters( $name, $value ) {
	return $value; }
function do_action( $name, ...$args ) {}
function wp_create_nonce( $action ) {
	return 'test'; }
function wp_generate_password( $length, $special = true ) {
	return bin2hex( random_bytes( $length / 2 ) ); }
function wp_tempnam( $name ) {
	return tempnam( sys_get_temp_dir(), 'backupplus-ssh-' ); }
function is_wp_error( $value ) {
	return $value instanceof WP_Error; }
function backupplus_try_include_file( $path, $mode ) {
	require_once BACKUPPLUS_DIR . '/' . $path; }
class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message ) {
		$this->code    = $code;
		$this->message = $message; }
	public function get_error_code() {
		return $this->code; }
	public function get_error_message() {
		return $this->message; }
}
class BackupPlus_Options {
	public static $options = array();
	public static function get_backupplus_option( $key, $default = '' ) {
		return isset( self::$options[ $key ] ) ? self::$options[ $key ] : $default; }
	public static function update_backupplus_option( $key, $value ) {
		self::$options[ $key ] = $value; }
	public static function delete_backupplus_option( $key ) {
		unset( self::$options[ $key ] ); }
	public static function admin_page_url() {
		return 'https://example.test/wp-admin/'; }
}

// Use the real plugin class without starting WordPress.
require BACKUPPLUS_DIR . '/class-backupplus.php';
class SSHTestBackupPlus extends BackupPlus {
	public $directory;
	public $uploaded = array();
	public $progress = array();
	public $logs     = array();
	public function __construct() {}
	public function phpseclib_requirements_met() {
		return true; }
	public function backups_dir_location( $allow_cache = true ) {
		return $this->directory; }
	public function log( $line, $level = 'notice', $uniq_id = false, $skip_dblog = false ) {
		$this->logs[] = $line; }
	public function uploaded_file( $file, $force = false ) {
		$this->uploaded[] = $file; }
	public function record_uploaded_chunk( $percent, $extra = '', $file_path = false, $log_it = true ) {
		$this->progress[] = $percent;
		Assert::assertTrue( is_file( $file_path ), 'Progress must reference the local backup' ); }
}
