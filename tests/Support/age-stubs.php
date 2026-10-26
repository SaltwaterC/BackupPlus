<?php
/**
 * WordPress/job shims for the age tests. Loaded once per test process by AgeTestCase; the state the shims keep lives
 * in AgeTestState and is reset before every test.
 */
define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
define( 'BACKUPPLUS_DIR', dirname( __DIR__, 2 ) );
define( 'BACKUPPLUS_PHPSECLIB_MIN_PHP_VERSION', '5.6.1' );
if ( getenv( 'BACKUPPLUS_TEST_PORTABLE' ) ) {
	define( 'BACKUPPLUS_ENCRYPTION_ENGINE', 'PHP' );
	require BACKUPPLUS_DIR . '/scripts/fixtures/without-crypto-extensions.php';
}

/** What the shims record and answer with. */
final class AgeTestState {

	public static bool $allowed = true;

	public static int $user = 1;

	public static int $blog = 1;

	public static array $transients = array();

	public static array $settings_errors = array();

	public static array $http_requests = array();

	public static string $http_body = '';

	public static int $http_status = 200;

	public static function reset(): void {
		self::$allowed         = true;
		self::$user            = 1;
		self::$blog            = 1;
		self::$transients      = array();
		self::$settings_errors = array();
		self::$http_requests   = array();
		self::$http_body       = '';
		self::$http_status     = 200;
	}
}

function __( $text, $domain = null ) {
	return $text; }
function esc_html( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES ); }
function is_wp_error( $value ) {
	return $value instanceof WP_Error; }
function backupplus_try_include_file( $path, $mode ) {
	require_once BACKUPPLUS_DIR . '/' . $path; }
class WP_Error {}
class wpdb {}
class BackupPlus_Options {
	public static $options = array();
	public static function get_backupplus_option( $name, $default = false ) {
		return isset( self::$options[ $name ] ) ? self::$options[ $name ] : $default; }
	public static function user_can_manage() {
		return AgeTestState::$allowed; }
}
function get_current_user_id() {
	return AgeTestState::$user; }
function get_current_blog_id() {
	return AgeTestState::$blog; }
function set_transient( $name, $value, $lifetime ) {
	AgeTestState::$transients[ $name ] = $value;
	return true; }
function delete_transient( $name ) {
	unset( AgeTestState::$transients[ $name ] );
}
function get_transient( $name ) {
	return isset( AgeTestState::$transients[ $name ] ) ? AgeTestState::$transients[ $name ] : false; }
function add_settings_error( $setting, $code, $text ) {
	AgeTestState::$settings_errors[] = $text; }
function wp_safe_remote_get( $url, $args ) {
	AgeTestState::$http_requests[] = array( $url, $args );
	return array(); }
function wp_remote_retrieve_response_code( $response ) {
	return AgeTestState::$http_status; }
function wp_remote_retrieve_body( $response ) {
	return AgeTestState::$http_body; }
require BACKUPPLUS_DIR . '/class-backupplus.php';
require BACKUPPLUS_DIR . '/includes/class-backupplus-encryption.php';
require BACKUPPLUS_DIR . '/includes/class-database-encryption.php';
require BACKUPPLUS_DIR . '/includes/class-age-restore.php';
require_once BACKUPPLUS_DIR . '/includes/class-credential-key-store.php';
require BACKUPPLUS_DIR . '/backup.php';
require_once BACKUPPLUS_DIR . '/includes/class-manipulation-functions.php';
class AgeTestBackupPlus extends BackupPlus {
	public $directory;
	public $logs          = array();
	public $test_job      = array();
	public $test_uploaded = array();
	public function __construct() {}
	public function log( $line, $level = 'notice', $uniq_id = false, $skip_dblog = false ) {
		$this->logs[] = $line; }
	public function backups_dir_location( $cache = true ) {
		return $this->directory; }
	public function get_job_option( $name ) {
		return BackupPlus_Options::get_backupplus_option( $name ); }
	public function get_backupable_file_entities( $include_others = true, $full_info = false ) {
		return array(
			'plugins' => '/plugins',
			'uploads' => '/uploads',
			'others'  => '/others',
		); }
	public function jobdata_set( $key, $value ) {
		$this->test_job[ $key ] = $value; }
	public function jobdata_get( $key, $default = null ) {
		return isset( $this->test_job[ $key ] ) ? $this->test_job[ $key ] : $default; }
	public function which_checksums() {
		return array( 'sha256' ); }
	public function is_uploaded( $file, $service = '', $instance_id = '' ) {
		return in_array( $file, $this->test_uploaded, true ); }
}
/** The real backup writer with the file-part checkpoints and the upload boundary observable. */
class FileEncryptionFailureProbe extends BackupPlus_Backup {
	public $fail_file = '';
	public $uploads   = array();
	public function __construct() {}
	public function encrypt_file( $file ) {
		return $file === $this->fail_file ? false : parent::encrypt_file( $file ); }
	public function cloud_backup( $files, $stage = 'clouduploading' ) {
		$this->uploads[] = $files; }
}
