<?php
/** WordPress shims and the plugin files for the backup encryption tests. Loaded once per test process. */

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
define( 'BACKUPPLUS_DIR', dirname( __DIR__, 2 ) );
define( 'BACKUPPLUS_PHPSECLIB_MIN_PHP_VERSION', '5.6.1' );

/** Without OpenSSL, sodium and mcrypt the library has to fall back to its pure PHP engines. */
if ( ! function_exists( 'openssl_encrypt' ) ) {
	define( 'BACKUPPLUS_ENCRYPTION_ENGINE', 'PHP' );
	require BACKUPPLUS_DIR . '/scripts/fixtures/without-crypto-extensions.php';
}

function __( $text, $domain = null ) {
	return $text; }
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

require BACKUPPLUS_DIR . '/class-backupplus.php';
require BACKUPPLUS_DIR . '/includes/class-backupplus-encryption.php';
require BACKUPPLUS_DIR . '/includes/class-manipulation-functions.php';

class CryptoTestBackupPlus extends BackupPlus {
	public function __construct() {}
	public function log( $line, $level = 'notice', $uniq_id = false, $skip_dblog = false ) {}
}

/** A stream wrapper with short reads and writes, and hard failures on demand. */
class CryptoShortIO {
	public $context;
	public static $input      = '';
	public static $output     = '';
	public static $fail_read  = false;
	public static $fail_write = false;
	private $offset           = 0;
	public static function reset() {
		self::$input      = '';
		self::$output     = '';
		self::$fail_read  = false;
		self::$fail_write = false; }
	public function stream_open( $path, $mode, $options, &$opened_path ) {
		return true; }
	public function stream_read( $length ) {
		if ( self::$fail_read ) {
			return false;
		}
		$data          = substr( self::$input, $this->offset, min( 13, $length ) );
		$this->offset += strlen( $data );
		return $data;
	}
	public function stream_write( $data ) {
		if ( self::$fail_write ) {
			return 0;
		}
		$data          = substr( $data, 0, 11 );
		self::$output .= $data;
		return strlen( $data );
	}
	public function stream_eof() {
		return $this->offset >= strlen( self::$input ); }
	public function stream_stat() {
		return array(); }
}
