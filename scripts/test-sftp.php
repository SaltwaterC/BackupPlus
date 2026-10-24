<?php
/** SSH storage checks without WordPress. Run live checks via scripts/integration/test-sftp.php. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'BACKUPPLUS_DIR', dirname( __DIR__ ) );

function ssh_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
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
	public static $options = array(
		'backupplus_sftp_settings' => array(
			'host' => 'legacy.test',
			'key'  => "legacy\nkey",
		),
	);
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
		ssh_expect( is_file( $file_path ), 'Progress must reference the local backup' ); }
}
$backupplus                 = new SSHTestBackupPlus();
$backupplus->backup_methods = array( 'sftp' => 'SFTP / SCP' );
$backupplus->directory      = sys_get_temp_dir() . '/backupplus-sftp-' . uniqid();
mkdir( $backupplus->directory );
register_shutdown_function(
	function () use ( $backupplus ) {
		foreach ( glob( $backupplus->directory . '/*' ) as $file ) {
			if ( is_file( $file ) || is_link( $file ) ) {
				unlink( $file );
			}
		}
		rmdir( $backupplus->directory );
	}
);
require BACKUPPLUS_DIR . '/methods/sftp.php';
$provider = new BackupPlus_BackupModule_sftp();
ssh_expect( 'legacy.test' === BackupPlus_Options::$options['backupplus_sftp']['host'] && ! isset( BackupPlus_Options::$options['backupplus_sftp_settings'] ), 'Legacy settings must migrate' );
ssh_expect( 'sftp' === $provider->get_default_options()['protocol'], 'SFTP must be the default' );
$saved = $provider->options_filter(
	array(
		'version'  => 1,
		'settings' => array(
			'test' => array(
				'host' => 'SCP://[::1]:2222/',
				'pass' => " secret \n",
				'key'  => "key\nmaterial\n",
			),
		),
	)
);
$saved = $saved['settings']['test'];
ssh_expect( '::1' === $saved['host'] && '2222' === $saved['port'] && 'scp' === $saved['protocol'], 'Prefixes, IPv6 and custom ports must normalise' );
ssh_expect( " secret \n" === $saved['pass'] && "key\nmaterial\n" === $saved['key'], 'Passwords and multiline keys must survive saving exactly' );
$fields = array_column( $provider->transform_template_properties_to_fields_structure(), null, 'id' );
ssh_expect( 'dropdown' === $fields['protocol']['type'] && 'password' === $fields['pass']['type'], 'Onboarding must expose protocol and secret fields' );
$properties = $provider->get_template_properties();
ssh_expect( ! empty( $properties['input_test_label'] ) && false !== strpos( $properties['input_test_label'], 'SFTP' ), 'The test button must have a visible label before the first click' );
ssh_expect( false !== strpos( $provider->get_configuration_template(), 'data-backupplus_settings_test="key"' ), 'Settings must expose the private key to connection tests' );
$options = array(
	'host'     => 'example.test',
	'user'     => 'test',
	'pass'     => 'test',
	'host_key' => 'SHA256:' . str_repeat( 'A', 43 ),
);
foreach ( array( array( 'host' => '' ), array( 'protocol' => 'ftp' ), array( 'port' => '22abc' ), array( 'port' => 0 ), array( 'port' => 65536 ), array( 'host' => 'user@host' ), array( 'host' => 'sftp://host/path' ), array( 'host_key' => 'invalid' ), array( 'path' => "bad\npath" ) ) as $invalid ) {
	$provider->set_options( array_merge( $options, $invalid ) );
	ssh_expect( is_wp_error( $provider->listfiles() ), 'Invalid options must fail before connecting' );
}
if ( ! function_exists( 'fsockopen' ) ) {
	$provider->set_options( $options );
	ssh_expect( 'sftp_support' === $provider->listfiles()->get_error_code(), 'Missing sockets must return an actionable error' );
	echo "PASS: SSH settings, validation, migration and missing socket support\n";
	exit;
}
if ( ! getenv( 'BACKUPPLUS_TEST_SFTP_PORT' ) ) {
	echo "PASS: SSH settings, validation, migration and UI; live tests skipped (run scripts/integration/test-sftp.php)\n";
	exit;
}

function ssh_credentials( $provider, $options ) {
	ob_start();
	$provider->credentials_test( $options );
	return ob_get_clean();
}
$base     = array(
	'host'     => '127.0.0.1',
	'user'     => 'test',
	'pass'     => ' test password ',
	'host_key' => getenv( 'BACKUPPLUS_TEST_SSH_FINGERPRINT' ),
);
$contents = str_repeat( "SSH transfer\x00test\n", 65536 );
foreach ( array( 'sftp', 'scp' ) as $protocol ) {
	$options = array_merge(
		$base,
		array(
			'protocol' => $protocol,
			'port'     => getenv( 'BACKUPPLUS_TEST_' . strtoupper( $protocol ) . '_PORT' ),
		)
	);
	foreach ( array(
		''         => '',
		'relative' => "archives ' #$",
		'absolute' => getenv( 'BACKUPPLUS_TEST_SSH_ROOT' ) . "/login/archives ' #$",
	) as $case => $path ) {
		$options['path'] = $path;
		$provider->set_options( $options );
		$output = ssh_credentials( $provider, $options );
		ssh_expect( 0 === strpos( $output, 'Success:' ) && $provider->is_connection_successful(), "$protocol credentials ($case): $output" );
		foreach ( array( 'backup_normal.zip', 'backup_quoted \';$(touch INJECTED) #?.zip', '-backup_option.zip', 'backup_empty.zip' ) as $name ) {
			$local   = $backupplus->directory . '/' . $name;
			$payload = 'backup_empty.zip' === $name ? '' : $contents;
			file_put_contents( $local, $payload );
			$backupplus->uploaded = array();
			$backupplus->progress = array();
			ssh_expect( is_array( $provider->backup( array( $name ) ) ) && array( $name ) === $backupplus->uploaded, "$protocol upload ($name)" );
			if ( '' !== $payload ) {
				ssh_expect( count( $backupplus->progress ) && max( $backupplus->progress ) <= 100, 'Upload progress must be bounded' );
			}
			$list = $provider->listfiles();
			ssh_expect( is_array( $list ) && in_array( $name, array_column( $list, 'name' ), true ), "$protocol listing ($name)" );
			unlink( $local );
			ssh_expect( $provider->download( $name ) && $payload === file_get_contents( $local ), "$protocol download ($name)" );
			file_put_contents( $local, substr( $payload, 0, 1000 ) );
			ssh_expect( $provider->download( $name ) && $payload === file_get_contents( $local ), "$protocol resumed download ($name)" );
			ssh_expect( $provider->download( $name ), "$protocol completed download ($name)" );
			file_put_contents( $local, $payload . 'oversized' );
			ssh_expect( $provider->download( $name ) && $payload === file_get_contents( $local ), "$protocol oversized local file must restart" );
			// A resumed upload must finish a short remote file, and replace oversized ones.
			file_put_contents( $local, substr( $payload, 0, 1000 ) );
			ssh_expect( is_array( $provider->backup( array( $name ) ) ), 'Partial upload fixture' );
			file_put_contents( $local, $payload );
			$backupplus->current_resumption = 1;
			ssh_expect( is_array( $provider->backup( array( $name ) ) ), "$protocol resumed upload" );
			unlink( $local );
			ssh_expect( $provider->download( $name ) && $payload === file_get_contents( $local ), 'Resumed upload must preserve bytes' );
			file_put_contents( $local, 'x' );
			ssh_expect( is_array( $provider->backup( array( $name ) ) ), 'Smaller replacement must truncate remote file' );
			unlink( $local );
			ssh_expect( $provider->download( $name ) && 'x' === file_get_contents( $local ), 'Remote truncation must preserve replacement' );
			$backupplus->current_resumption = 0;
			ssh_expect( $provider->delete( $name ) && $provider->delete( $name ), "$protocol idempotent deletion" );
		}
		ssh_expect( array() === $provider->listfiles(), 'Only regular matching files may be listed' );
	}
	foreach ( array( '../outside', '/absolute', "bad\nname", 'dir/file', 'dir\\file', '.', '..' ) as $name ) {
		ssh_expect( ! $provider->download( $name ) && ! $provider->delete( $name ) && ! $provider->backup( array( $name ) ), 'Unsafe basenames must be rejected' );
	}
	foreach ( array( 'backup_directory', 'backup_link' ) as $name ) {
		$options['path'] = '';
		$provider->set_options( $options );
		file_put_contents( $backupplus->directory . '/' . $name, 'do not overwrite' );
		ssh_expect( ! $provider->backup( array( $name ) ) && ! $provider->download( $name ), 'Directories and symlinks must not be transferred' );
	}
	foreach ( json_decode( getenv( 'BACKUPPLUS_TEST_SSH_KEYS' ), true ) as $key_fixture ) {
		$options['key']            = file_get_contents( $key_fixture['path'] );
		$options['key_passphrase'] = $key_fixture['passphrase'];
		$options['pass']           = 'this password must not be used';
		$output                    = ssh_credentials( $provider, $options );
		ssh_expect( 0 === strpos( $output, 'Success:' ), "$protocol {$key_fixture['name']} login: $output" );
		$provider->set_options( $options );
		$name  = 'backup_key_auth.zip';
		$local = $backupplus->directory . '/' . $name;
		file_put_contents( $local, $contents );
		ssh_expect( is_array( $provider->backup( array( $name ) ) ), "$protocol key-authenticated backup" );
		unlink( $local );
		ssh_expect( $provider->download( $name ) && $contents === file_get_contents( $local ), "$protocol key-authenticated restore" );
		ssh_expect( $provider->delete( $name ), "$protocol key-authenticated deletion" );
		if ( '' !== $key_fixture['passphrase'] ) {
			$options['key_passphrase'] = 'wrong';
			$provider->set_options( $options );
			ssh_expect( 'sftp_private_key' === $provider->listfiles()->get_error_code(), 'Wrong private key passphrase must fail without falling back to a password' );
		}
		echo "PASS: $protocol {$key_fixture['name']} credentials, backup, restore and deletion\n";
	}
	// Verify Ed25519 host keys as well as Ed25519 client authentication.
	$ed_options = array_merge(
		$options,
		array(
			'port'           => getenv( 'BACKUPPLUS_TEST_' . strtoupper( $protocol ) . '_ED25519_PORT' ),
			'host_key'       => getenv( 'BACKUPPLUS_TEST_SSH_ED25519_FINGERPRINT' ),
			'key_passphrase' => $key_fixture['passphrase'],
		)
	);
	ssh_expect( 0 === strpos( ssh_credentials( $provider, $ed_options ), 'Success:' ), "$protocol Ed25519 host key verification and authentication" );
	$ed_options['host_key'] = $base['host_key'];
	$provider->set_options( $ed_options );
	ssh_expect( 'sftp_host_key_mismatch' === $provider->listfiles()->get_error_code(), 'Ed25519 host key mismatches must fail closed' );
	$options['key']            = file_get_contents( getenv( 'BACKUPPLUS_TEST_SSH_PUBLIC_KEY' ) );
	$options['key_passphrase'] = '';
	$provider->set_options( $options );
	ssh_expect( 'sftp_private_key' === $provider->listfiles()->get_error_code(), 'Public keys must not be accepted as private keys' );
	$options['key'] = 'not a private key';
	$provider->set_options( $options );
	ssh_expect( 'sftp_private_key' === $provider->listfiles()->get_error_code(), 'Malformed keys must fail with an actionable error' );
	$options['key']  = '';
	$options['pass'] = 'wrong';
	ssh_expect( 0 === strpos( ssh_credentials( $provider, $options ), 'Failure:' ) && ! $provider->is_connection_successful(), 'Wrong password must fail and reset connection status' );
	$options['pass'] = $base['pass'];
	$options['path'] = 'read-only';
	ssh_expect( 0 === strpos( ssh_credentials( $provider, $options ), 'Failure:' ), 'Read-only remote directories must fail the write test' );
	$options['pass']     = 'UNVERIFIED';
	$options['host_key'] = '';
	$output              = ssh_credentials( $provider, $options );
	ssh_expect( false !== strpos( $output, $base['host_key'] ) && ! $provider->is_connection_successful(), 'Missing fingerprint must display the key without logging in' );
	$options['host_key'] = 'SHA256:' . str_repeat( 'A', 43 );
	$provider->set_options( $options );
	ssh_expect( 'sftp_host_key_mismatch' === $provider->listfiles()->get_error_code(), 'Changed host keys must fail closed' );
	$options['host_key'] = $base['host_key'];
	$options['pass']     = $base['pass'];
	$options['path']     = 'does-not-exist';
	$provider->set_options( $options );
	ssh_expect( 'sftp_path' === $provider->listfiles()->get_error_code(), 'Missing directories must produce an error' );
	echo "PASS: $protocol transfers, resumption, literal paths, validation, password/key authentication and host verification\n";
}

ssh_expect( ! class_exists( 'phpseclib\\Crypt\\RSA' ) && ! class_exists( 'phpseclib_Crypt_RSA' ), 'The obsolete phpseclib 2 APIs must not be available' );
