<?php
/**
 * WordPress shims for the FTP/FTPS tests; the provider and the wrapper themselves run unchanged. Loaded once per test process,
 * after the test defined ABSPATH, WPINC and BACKUPPLUS_DIR. The job object is created by the test.
 */

function __( $text, $domain = null ) {
	return $text; }
function esc_html( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_html__( $text, $domain = null ) {
	return esc_html( $text ); }
function esc_html_e( $text, $domain = null ) {
	echo esc_html( $text ); }
function wp_kses( $text, $allowed ) {
	return $text; }
function wp_kses_post( $text ) {
	return $text; }
function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, $args ); }
function apply_filters( $name, $value ) {
	return $value; }
function do_action( $name, ...$args ) {}
function trailingslashit( $text ) {
	return rtrim( $text, '/' ) . '/'; }
function wp_rand( $min, $max ) {
	return mt_rand( $min, $max ); }
function wp_create_nonce( $action ) {
	return 'test-nonce'; }
function is_wp_error( $value ) {
	return $value instanceof WP_Error; }
function backupplus_try_include_file( $path, $mode ) {
	require_once dirname( __DIR__, 2 ) . '/' . $path; }

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
	public static function get_backupplus_option( $key, $default = false ) {
		return $default; }
	public static function admin_page_url() {
		return 'https://example.test/wp-admin/'; }
}

class BackupPlus {
	public $backup_methods     = array( 'ftp' => 'FTP' );
	public $current_resumption = 0;
	public $uploaded           = array();
	public $logs               = array();
	public $progress           = array();
	public $nossl              = false;
	public $use_server_certs   = false;
	public $disable_verify     = false;
	public function get_job_option( $key ) {
		if ( 'backupplus_ssl_nossl' === $key ) {
			return $this->nossl;
		}
		if ( 'backupplus_ssl_useservercerts' === $key ) {
			return $this->use_server_certs;
		}
		if ( 'backupplus_ssl_disableverify' === $key ) {
			return $this->disable_verify;
		}
		return false;
	}
	public function backups_dir_location() {
		return rtrim( ABSPATH, '/' ); }
	public function log( $message, ...$args ) {
		$this->logs[] = $message; }
	public function log_wp_error( $error ) {
		$this->logs[] = $error->get_error_message(); }
	public function jobdata_get( $key ) {
		return false; }
	public function uploaded_file( $file ) {
		$this->uploaded[] = $file; }
	public function record_uploaded_chunk( $percent, ...$args ) {
		$this->progress[] = $percent; }
}
