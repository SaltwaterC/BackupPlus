<?php
/**
 * WebDAV regression checks without WordPress. Run: php scripts/test-webdav.php
 * Run live HTTP/HTTPS transfers with: php scripts/integration/test-webdav.php
 */
$fixture = sys_get_temp_dir() . '/backupplus-webdav-' . uniqid();
mkdir( $fixture );
mkdir( $fixture . '/includes' );
mkdir( $fixture . '/methods' );
copy( dirname( __DIR__ ) . '/includes/cacert.pem', $fixture . '/includes/cacert.pem' );
symlink( dirname( __DIR__ ) . '/methods/webdav.php', $fixture . '/methods/webdav.php' );
symlink( dirname( __DIR__ ) . '/methods/backup-module.php', $fixture . '/methods/backup-module.php' );
register_shutdown_function(
	function () use ( $fixture ) {
		foreach ( array( 'includes', 'methods' ) as $dir ) {
			foreach ( glob( $fixture . '/' . $dir . '/*' ) as $file ) {
				unlink( $file );
			}
			rmdir( $fixture . '/' . $dir );
		}
		foreach ( glob( $fixture . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $fixture );
	}
);
define( 'ABSPATH', $fixture . '/' );
define( 'BACKUPPLUS_DIR', $fixture );

function dav_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

// Small WordPress shims; the provider and its HTTP transport run unchanged.
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
	require_once dirname( __DIR__ ) . '/' . $path; }
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
	public static $options = array(
		'backupplus_webdav_settings' => array(
			'host'   => 'old.example.test',
			'webdav' => 'webdavs://',
			'pass'   => ' secret ',
		),
	);
	public static function get_backupplus_option( $key, $default = false ) {
		return isset( self::$options[ $key ] ) ? self::$options[ $key ] : $default; }
	public static function update_backupplus_option( $key, $value ) {
		self::$options[ $key ] = $value; }
	public static function delete_backupplus_option( $key ) {
		unset( self::$options[ $key ] ); }
	public static function admin_page_url() {
		return 'https://example.test/wp-admin/'; }
}

class BackupPlus {
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

$backupplus = new BackupPlus();
require dirname( __DIR__ ) . '/includes/class-storage-provider-factory.php';
$provider = BackupPlus_Storage_Provider_Factory::create( 'webdav' );
dav_expect( $provider instanceof BackupPlus_BackupModule, 'Factory must construct the built-in WebDAV provider' );
dav_expect( ! is_a( $provider, 'BackupPlus_BackupModule_AddonNotYetPresent' ), 'WebDAV must be available without an add-on' );
dav_expect( 'old.example.test' === BackupPlus_Options::$options['backupplus_webdav']['host'] && ! isset( BackupPlus_Options::$options['backupplus_webdav_settings'] ), 'Legacy options must migrate' );
dav_expect( 'https://' === $provider->get_default_options()['webdav'], 'New connections must default to HTTPS' );
$settings = $provider->options_filter(
	array(
		'version'  => 1,
		'settings' => array(
			'old'   => BackupPlus_Options::$options['backupplus_webdav'],
			'url'   => array( 'url' => 'webdav://user%40example.test:p%40ss%20word@example.test:8080/dav/my%20backups/' ),
			'other' => array(
				'host'             => 'other.example.test',
				'instance_enabled' => 0,
				'instance_label'   => 'Other',
			),
		),
	)
);
dav_expect( 'https://' === $settings['settings']['old']['webdav'] && ' secret ' === $settings['settings']['old']['pass'], 'Normalisation must preserve password whitespace and secure legacy schemes' );
$url_options = $settings['settings']['url'];
dav_expect( 'http://' === $url_options['webdav'] && '8080' === $url_options['port'] && '/dav/my backups/' === $url_options['path'] && 'user@example.test' === $url_options['user'] && 'p@ss word' === $url_options['pass'], 'Legacy URL settings must decode exactly once' );
dav_expect( 0 === $settings['settings']['other']['instance_enabled'], 'Instance metadata must survive sanitisation' );
dav_expect( ! $provider->options_exist( array() ) && $provider->options_exist( $url_options ), 'Configured state must use the endpoint' );
dav_expect( 'Test WebDAV Settings' === $provider->get_template_properties()['input_test_label'], 'The test button must have its label before any interaction' );
$fields = $provider->transform_template_properties_to_fields_structure();
dav_expect( 'password' === $fields[4]['type'] && 'dropdown' === $fields[0]['type'], 'Settings must include a masked password and protocol selector' );
$template = $provider->get_configuration_template();
foreach ( array_keys( $provider->get_default_options() ) as $field ) {
	dav_expect( false !== strpos( $template, 'data-backupplus_settings_test="' . $field . '"' ), 'Each setting must be included in unsaved tests' );
}
echo "PASS: discovery, settings migration, instances and configuration template\n";

function dav_credentials( $provider, $options ) {
	ob_start();
	$provider->credentials_test( $options );
	return ob_get_clean();
}

if ( ! function_exists( 'curl_init' ) ) {
	$provider->set_options( array( 'host' => 'example.test' ) );
	dav_expect( is_wp_error( $provider->listfiles() ), 'Missing cURL must return a storage error' );
	dav_expect( false === $provider->backup( array( 'file' ) ) && false === $provider->download( 'file' ) && false === $provider->delete( 'file' ), 'Missing cURL must fail operations gracefully' );
	dav_expect( 0 === strpos( dav_credentials( $provider, array( 'host' => 'example.test' ) ), 'Failure:' ), 'Missing cURL must fail the credentials test' );
	echo "PASS: missing cURL handling\n";
	exit;
}

foreach ( array(
	array(),
	array( 'host' => 'https://example.test/dav' ),
	array( 'host' => 'user:pass@example.test' ),
	array(
		'host'   => 'example.test',
		'webdav' => 'ftp://',
	),
	array(
		'host' => 'example.test',
		'port' => '65536',
	),
	array(
		'host' => 'example.test',
		'port' => '-1',
	),
	array( 'host' => "example.test\r\nHeader: injected" ),
	array(
		'host' => 'example.test',
		'path' => '../outside',
	),
	array(
		'host' => 'example.test',
		'path' => "dav/\0file",
	),
) as $options ) {
	dav_expect( is_wp_error( BackupPlus_WebDAV_Client::open( $options ) ), 'Invalid connection settings must be rejected' );
}
dav_expect( is_wp_error( BackupPlus_WebDAV_Client::open( array( 'host' => 'example.test' ), array( 'nossl' => true ) ) ), 'HTTPS must not downgrade when SSL is disabled' );
dav_expect(
	! is_wp_error(
		BackupPlus_WebDAV_Client::open(
			array(
				'host' => '[::1]:8080',
				'path' => '/',
			)
		)
	),
	'IPv6 and root paths must be supported'
);
foreach ( array( '', '.', '..', '../file', '/file', 'dir/file', 'dir\\file', "bad\0file", "bad\nfile" ) as $file ) {
	dav_expect( ! BackupPlus_WebDAV_Client::valid_filename( $file ), 'Traversal and control characters must be rejected' );
}
echo "PASS: endpoint, TLS policy and filename validation\n";

$host = getenv( 'BACKUPPLUS_TEST_DAV_HOST' );
if ( ! $host ) {
	echo "SKIP: live transfers (run php scripts/integration/test-webdav.php)\n";
	exit;
}
$contents = str_repeat( "WebDAV binary\0\xff\n", 65536 );
$name     = 'backup_测试 +#?%25&.zip';
$base     = array(
	'host' => 'localhost',
	'user' => 'backup+user@example.test',
	'pass' => ' secret:@/?#% ',
	'path' => 'dav/my backups +#?%',
);
foreach ( array(
	'http://'  => $host,
	'https://' => getenv( 'BACKUPPLUS_TEST_DAV_TLS_HOST' ),
) as $scheme => $authority ) {
	$options = array_merge(
		$base,
		array(
			'host'   => $authority,
			'webdav' => $scheme,
		)
	);
	// HTTPS uses the generated local CA via PHP curl.cainfo.
	$backupplus->tls['backupplus_ssl_useservercerts'] = true;
	$provider->set_options( $options );
	$output = dav_credentials( $provider, array_merge( $options, array( 'useservercerts' => true ) ) );
	dav_expect( 0 === strpos( $output, 'Success:' ) && $provider->is_connection_successful(), 'Credentials roundtrip failed: ' . $output );
	file_put_contents( $fixture . '/' . $name, $contents );
	$result = $provider->backup( array( $name ) );
	dav_expect( false !== $result && in_array( $name, $backupplus->uploaded, true ), 'Upload must mark completed backups' );
	dav_expect( in_array( 100, $backupplus->progress, true ), 'Upload progress must reach 100 percent' );
	$files = $provider->listfiles();
	dav_expect(
		array(
			array(
				'name' => $name,
				'size' => strlen( $contents ),
			),
		) === $files,
		'Listing must preserve encoded filenames, sizes, and ignore directories or failed properties'
	);
	dav_expect( array() === $provider->listfiles( 'unmatched' ), 'Listing match must filter files' );
	file_put_contents( $fixture . '/' . $name, 'local partial data' );
	dav_expect( $provider->download( $name ) && $contents === file_get_contents( $fixture . '/' . $name ), 'Download must replace partial data with the complete archive' );
	// Retry PUT must replace a previously uploaded object in full.
	file_put_contents( $fixture . '/' . $name, 'replacement' );
	dav_expect( false !== $provider->backup( array( $name ) ), 'Repeated PUT must succeed' );
	unlink( $fixture . '/' . $name );
	dav_expect( $provider->download( $name ) && 'replacement' === file_get_contents( $fixture . '/' . $name ), 'Repeated PUT must replace old content' );
	dav_expect( $provider->delete( $name, $result ) && $provider->delete( array( $name ) ), 'Deletion must support strings, arrays and missing files' );
	$empty = 'backup_empty.zip';
	file_put_contents( $fixture . '/' . $empty, '' );
	dav_expect( false !== $provider->backup( array( $empty ) ) && $provider->download( $empty ) && '' === file_get_contents( $fixture . '/' . $empty ), 'Empty file roundtrip must succeed' );
	dav_expect( $provider->delete( $empty ), 'Empty files must be deletable' );
	// Root collection and a second instance must not reuse the prior path.
	$provider->set_options( array_merge( $options, array( 'path' => '/' ) ) );
	dav_expect( false !== $provider->backup( array( $name ) ), 'Root collection upload must succeed' );
	dav_expect( 1 === count( $provider->listfiles() ), 'Root collection listing must succeed' );
	dav_expect( $provider->delete( $name ), 'Root collection deletion must succeed' );
	$provider->set_options( $options );
	dav_expect( array() === $provider->listfiles(), 'Changing instances must clear the cached client' );
	$bad = array_merge(
		$options,
		array(
			'pass'           => 'wrong-password',
			'useservercerts' => true,
		)
	);
	dav_expect( 0 === strpos( dav_credentials( $provider, $bad ), 'Failure:' ) && ! $provider->is_connection_successful(), 'Incorrect credentials must reset connection status' );
	$provider->set_options( $bad );
	dav_expect( is_wp_error( $provider->listfiles() ) && false === $provider->backup( array( $name ) ), 'Authentication errors must fail listing and upload' );
	echo "PASS: $scheme credentials, binary/empty transfers, listing, overwrite, deletion and instance isolation\n";
}

$options = array_merge(
	$base,
	array(
		'host'   => $host,
		'webdav' => 'http://',
	)
);
foreach ( array( 'malformed', 'empty-xml', 'xxe', 'missing', 'redirect' ) as $path ) {
	$provider->set_options( array_merge( $options, array( 'path' => $path ) ) );
	dav_expect( is_wp_error( $provider->listfiles() ), 'Invalid directory response must fail: ' . $path );
}
$provider->set_options( $options );
foreach ( array( 'backup_missing.zip', 'backup_truncated.zip', 'backup_redirect.zip' ) as $file ) {
	file_put_contents( $fixture . '/' . $file, 'keep existing archive' );
	dav_expect( ! $provider->download( $file ) && 'keep existing archive' === file_get_contents( $fixture . '/' . $file ), 'Failed download must preserve an existing archive: ' . $file );
}
foreach ( array( '', '.', '..', '../outside', 'folder/file', "bad\nfile" ) as $file ) {
	dav_expect( false === $provider->download( $file ) && false === $provider->backup( array( $file ) ) && false === $provider->delete( $file ), 'Operations must reject invalid filenames' );
}
dav_expect( false === $provider->backup( array( 'nonexistent.zip' ) ), 'Missing local files must fail upload' );
dav_expect( false === $provider->delete( 'backup_forbidden.zip' ), 'HTTP permission errors must fail deletion' );
dav_expect( array() === glob( $fixture . '/.webdav-*' ) && array() === glob( $fixture . '/dav-test-*' ), 'All download and credentials temporary files must be removed' );
echo "PASS: malformed XML, XXE rejection, redirects, HTTP errors, truncated downloads and traversal\n";

$tls_options     = array_merge(
	$base,
	array(
		'host'   => getenv( 'BACKUPPLUS_TEST_DAV_TLS_HOST' ),
		'webdav' => 'https://',
	)
);
$backupplus->tls = array();
$provider->set_options( $tls_options );
dav_expect( is_wp_error( $provider->listfiles() ), 'The bundled CA must reject an untrusted local certificate' );
$output = dav_credentials( $provider, array_merge( $tls_options, array( 'useservercerts' => true ) ) );
dav_expect( 0 === strpos( $output, 'Success:' ), 'System CA choice must honor curl.cainfo: ' . $output );
$mismatch = array_merge(
	$tls_options,
	array(
		'host'           => str_replace( 'localhost', '127.0.0.1', $tls_options['host'] ),
		'useservercerts' => true,
	)
);
dav_expect( 0 === strpos( dav_credentials( $provider, $mismatch ), 'Failure:' ), 'TLS hostname mismatches must be rejected' );
dav_expect( 0 === strpos( dav_credentials( $provider, array_merge( $mismatch, array( 'disableverify' => true ) ) ), 'Success:' ), 'Explicit certificate verification override must be honored' );
dav_expect( 0 === strpos( dav_credentials( $provider, array_merge( $tls_options, array( 'nossl' => true ) ) ), 'Failure:' ), 'Disabling SSL must reject HTTPS without downgrade' );
echo "PASS: certificate trust, hostname verification, CA selection and explicit expert overrides\n";
