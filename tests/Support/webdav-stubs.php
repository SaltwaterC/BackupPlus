<?php
/**
 * WordPress/job shims for the WebDAV tests. Loaded once per test process by WebDavTest; the provider and its HTTP
 * transport run unchanged. The fixture directory doubles as ABSPATH and as the backups directory of the job: it
 * holds the bundled CA and links to the provider files, like a plugin directory.
 */
$webdav_fixture = sys_get_temp_dir() . '/backupplus-webdav-' . bin2hex( random_bytes( 8 ) );
mkdir( $webdav_fixture );
mkdir( $webdav_fixture . '/includes' );
mkdir( $webdav_fixture . '/methods' );
copy( dirname( __DIR__, 2 ) . '/includes/cacert.pem', $webdav_fixture . '/includes/cacert.pem' );
symlink( dirname( __DIR__, 2 ) . '/methods/webdav.php', $webdav_fixture . '/methods/webdav.php' );
symlink( dirname( __DIR__, 2 ) . '/methods/backup-module.php', $webdav_fixture . '/methods/backup-module.php' );
register_shutdown_function(
	function () use ( $webdav_fixture ) {
		foreach ( array( 'includes', 'methods' ) as $dir ) {
			foreach ( glob( $webdav_fixture . '/' . $dir . '/{,.}*[!.]', GLOB_BRACE ) as $file ) {
				unlink( $file );
			}
			rmdir( $webdav_fixture . '/' . $dir );
		}
		foreach ( glob( $webdav_fixture . '/{,.}*[!.]', GLOB_BRACE ) as $file ) {
			unlink( $file );
		}
		rmdir( $webdav_fixture );
	}
);
define( 'ABSPATH', $webdav_fixture . '/' );
define( 'BACKUPPLUS_DIR', $webdav_fixture );
unset( $webdav_fixture );

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
	return 'test-nonce'; }
function wp_generate_password( $length, $special = true ) {
	return substr( bin2hex( random_bytes( $length ) ), 0, $length ); }
function wp_tempnam( $name ) {
	return tempnam( ABSPATH, 'dav-test-' ); }
function is_wp_error( $value ) {
	return $value instanceof WP_Error; }
function backupplus_try_include_file( $path, $mode ) {
	require_once dirname( __DIR__, 2 ) . '/' . $path; }
function get_file_data( $file, $headers ) {
	$content = file_get_contents( $file );
	foreach ( $headers as $key => $header ) {
		$headers[ $key ] = preg_match( '/' . preg_quote( $header, '/' ) . ': (.*)/', $content, $match ) ? trim( $match[1] ) : '';
	}
	return $headers;
}

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
	public static function get_backupplus_option( $key, $default = false ) {
		return isset( self::$options[ $key ] ) ? self::$options[ $key ] : $default; }
	public static function update_backupplus_option( $key, $value ) {
		self::$options[ $key ] = $value; }
	public static function delete_backupplus_option( $key ) {
		unset( self::$options[ $key ] ); }
	public static function admin_page_url() {
		return 'https://example.test/wp-admin/'; }
}

class WebDav_Test_Job {
	public $backup_methods = array( 'webdav' => 'WebDAV' );
	public $uploaded       = array();
	public $logs           = array();
	public $progress       = array();
	public $tls            = array();
	public function get_job_option( $key ) {
		return isset( $this->tls[ $key ] ) ? $this->tls[ $key ] : false; }
	public function backups_dir_location() {
		return rtrim( ABSPATH, '/' ); }
	public function log( $message, ...$args ) {
		$this->logs[] = $message; }
	public function uploaded_file( $file ) {
		$this->uploaded[] = $file; }
	public function record_uploaded_chunk( $percent, ...$args ) {
		$this->progress[] = $percent; }
}

require_once dirname( __DIR__, 2 ) . '/includes/class-storage-provider-factory.php';
