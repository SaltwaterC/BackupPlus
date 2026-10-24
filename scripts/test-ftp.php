<?php
/**
 * FTP/FTPS regression checks without WordPress.
 * Run: php scripts/test-ftp.php
 * Also run: php -d disable_functions=curl_init scripts/test-ftp.php
 * For live transfers, provide BACKUPPLUS_TEST_FTP_HOST and BACKUPPLUS_TEST_FTPS_HOST
 * (host:port), BACKUPPLUS_TEST_FTP_USER and BACKUPPLUS_TEST_FTP_PASS. The FTPS
 * server must require TLS on both control and data connections, and the plain
 * server must reject TLS. BACKUPPLUS_TEST_FTP_CA supplies the test CA bundle.
 * Use disposable servers with an empty writable login directory and root.
 * scripts/integration/test-ftp.php creates these fixtures and runs this script.
 */
$fixture = sys_get_temp_dir() . '/backupplus-ftp-' . uniqid();
mkdir( $fixture );
mkdir( $fixture . '/wp-includes' );
mkdir( $fixture . '/includes' );
copy( getenv( 'BACKUPPLUS_TEST_FTP_CA' ) ?: dirname( __DIR__ ) . '/includes/cacert.pem', $fixture . '/includes/cacert.pem' );
file_put_contents( $fixture . '/wp-includes/version.php', '<?php // Test upload' );
register_shutdown_function(
	function () use ( $fixture ) {
		foreach ( glob( $fixture . '/*' ) as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		unlink( $fixture . '/wp-includes/version.php' );
		rmdir( $fixture . '/wp-includes' );
		unlink( $fixture . '/includes/cacert.pem' );
		rmdir( $fixture . '/includes' );
		rmdir( $fixture );
	}
);
define( 'ABSPATH', $fixture . '/' );
define( 'WPINC', 'wp-includes' );
define( 'BACKUPPLUS_DIR', $fixture );

function ftp_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

// Minimal WordPress shims; the provider and wrapper themselves run unchanged.
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
	require_once dirname( __DIR__ ) . '/' . $path; }

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

$backupplus = new BackupPlus();
require dirname( __DIR__ ) . '/methods/ftp.php';
$provider = new BackupPlus_BackupModule_ftp();
$defaults = $provider->get_default_options();
ftp_expect( 'none' === $defaults['encryption'], 'Existing settings must default to plain FTP' );

$settings = array(
	'version'  => 1,
	'settings' => array(
		'plain'    => array(
			'host' => 'ftp://example.test:2121/',
			'pass' => " secret \n",
		),
		'secure'   => array(
			'host'       => 'FTPS://example.test:2122/',
			'encryption' => 'none',
		),
		'explicit' => array(
			'host'    => 'ftpes://example.test/',
			'passive' => 0,
		),
		'selected' => array(
			'host'       => 'ftp://example.test/',
			'encryption' => 'tls',
		),
	),
);
$saved    = $provider->options_filter( $settings );
ftp_expect( 'example.test:2121' === $saved['settings']['plain']['host'], 'Plain host prefix and trailing slash must be removed' );
ftp_expect( 'none' === $saved['settings']['plain']['encryption'], 'Plain prefix must keep the FTP default' );
ftp_expect( ' secret ' === $saved['settings']['plain']['pass'], 'Password spaces must survive sanitisation' );
foreach ( array( 'secure', 'explicit', 'selected' ) as $instance ) {
	ftp_expect( 'tls' === $saved['settings'][ $instance ]['encryption'], 'Secure settings must survive saving' );
}
ftp_expect( 'example.test:2122' === $saved['settings']['secure']['host'], 'FTPS custom port must survive sanitisation' );
ftp_expect( 0 === $saved['settings']['explicit']['passive'], 'Encryption must not change active/passive mode' );
$fields = array_column( $provider->transform_template_properties_to_fields_structure(), null, 'id' );
ftp_expect( 'dropdown' === $fields['encryption']['type'], 'Onboarding must expose the encryption selector' );
ftp_expect( 'tls' === $fields['encryption']['options'][1]['value'], 'Onboarding must offer explicit TLS' );

function ftp_test_credentials( $provider, $settings ) {
	$provider->set_connection_status( false );
	ob_start();
	$provider->credentials_test(
		array_merge(
			array(
				'host'           => 'example.test',
				'user'           => 'test',
				'pass'           => 'test',
				'path'           => '',
				'nossl'          => false,
				'disableverify'  => false,
				'useservercerts' => false,
				'passive'        => 1,
			),
			$settings
		)
	);
	return ob_get_clean();
}

$output = ftp_test_credentials( $provider, array( 'encryption' => 'invalid' ) );
ftp_expect( false !== strpos( $output, 'Unsupported FTP encryption' ), 'Unknown encryption must fail before connecting' );
foreach ( array( 'example.test', 'ftps://example.test/', 'ftpes://example.test/' ) as $host ) {
	$output = ftp_test_credentials(
		$provider,
		array(
			'host'       => $host,
			'encryption' => 'example.test' === $host ? 'tls' : 'none',
			'nossl'      => true,
		)
	);
	ftp_expect( false !== strpos( $output, 'FTPS requires SSL/TLS' ), 'Disabling SSL must fail instead of downgrading FTPS' );
}

if ( ! function_exists( 'curl_init' ) ) {
	$output = ftp_test_credentials( $provider, array( 'encryption' => 'tls' ) );
	ftp_expect( false !== strpos( $output, 'curl_init' ), 'Unavailable cURL support must produce an actionable error' );
	$provider->set_options(
		array(
			'host'       => 'example.test',
			'user'       => 'test',
			'pass'       => 'test',
			'encryption' => 'tls',
		)
	);
	$result = $provider->listfiles();
	ftp_expect( is_wp_error( $result ) && 'ftp_missing_support' === $result->get_error_code(), 'Background operations must handle unavailable cURL support' );
	require_once dirname( __DIR__ ) . '/methods/ftp/client.php';
	$ftp      = new BackupPlus_ftp_wrapper( 'example.test', 'test', 'test' );
	$ftp->ssl = true;
	ftp_expect( false === $ftp->connect(), 'The wrapper must also fail safely when cURL is unavailable' );
	echo "PASS: FTP settings, SSL conflict and missing cURL function checks\n";
	exit;
}

$plain_host  = getenv( 'BACKUPPLUS_TEST_FTP_HOST' );
$secure_host = getenv( 'BACKUPPLUS_TEST_FTPS_HOST' );
if ( ! $plain_host || ! $secure_host ) {
	echo "PASS: FTP settings and SSL conflict checks; live transfers skipped (set BACKUPPLUS_TEST_FTP_HOST and BACKUPPLUS_TEST_FTPS_HOST)\n";
	exit;
}

$user     = getenv( 'BACKUPPLUS_TEST_FTP_USER' );
$pass     = getenv( 'BACKUPPLUS_TEST_FTP_PASS' );
$name     = 'backup_ftps_test_' . uniqid() . '.zip';
$contents = str_repeat( "FTP and FTPS transfer regression test\n", 32768 );
foreach ( array(
	'none' => $plain_host,
	'tls'  => $secure_host,
) as $encryption => $host ) {
	foreach ( array( 1, 0 ) as $passive ) {
		$options = array(
			'host'       => $host,
			'user'       => $user,
			'pass'       => $pass,
			'path'       => '',
			'encryption' => $encryption,
			'passive'    => $passive,
		);
		$provider->set_options( $options );
		$backupplus->uploaded = array();
		$backupplus->progress = array();
		$output               = ftp_test_credentials( $provider, $options );
		ftp_expect( 0 === strpos( $output, 'Success:' ), "$encryption credentials test failed: $output" );
		ftp_expect( false !== strpos( $output, 'tls' === $encryption ? 'encrypted (explicit TLS)' : 'non-encrypted' ), 'Credentials test must report the actual login type' );
		file_put_contents( $fixture . '/' . $name, $contents );
		$result = $provider->backup( array( $name ) );
		ftp_expect( is_array( $result ) && in_array( $name, $backupplus->uploaded, true ), "$encryption upload must succeed" );
		ftp_expect( count( $backupplus->progress ) > 0 && max( $backupplus->progress ) <= 100, 'Uploads must record bounded backup progress' );
		$files = $provider->listfiles();
		ftp_expect( is_array( $files ) && in_array( $name, array_column( $files, 'name' ), true ), "$encryption listing must find the uploaded backup" );
		unlink( $fixture . '/' . $name );
		ftp_expect( $provider->download( $name ), "$encryption download must succeed" );
		ftp_expect( $contents === file_get_contents( $fixture . '/' . $name ), 'Downloaded content must match the original' );
		file_put_contents( $fixture . '/' . $name, substr( $contents, 0, 1024 ) );
		ftp_expect( $provider->download( $name ), "$encryption resumed download must succeed" );
		ftp_expect( $contents === file_get_contents( $fixture . '/' . $name ), 'Resumed download must match the original' );
		ftp_expect( $provider->download( $name ), 'Resuming a complete download must succeed without appending data' );
		ftp_expect( true === $provider->delete( $name ), "$encryption deletion through a fresh connection must succeed" );
		ftp_expect( array() === $provider->listfiles(), 'The remote test directory must be empty after deletion' );
		// Upload a partial file, then resume through the same encrypted connection.
		file_put_contents( $fixture . '/' . $name, substr( $contents, 0, 1024 ) );
		$ftp = $result['ftp_object'];
		ftp_expect( '' !== $ftp->system_type, 'The wrapper must retain the remote system type' );
		ftp_expect( $ftp->put( $fixture . '/' . $name, $name ), 'Partial upload must succeed' );
		file_put_contents( $fixture . '/' . $name, $contents );
		ftp_expect( $ftp->put( $fixture . '/' . $name, $name, BackupPlus_ftp_wrapper::BINARY, true, $backupplus ), 'Resumed upload must succeed' );
		unlink( $fixture . '/' . $name );
		ftp_expect( $provider->download( $name ) && $contents === file_get_contents( $fixture . '/' . $name ), 'Resumed upload must preserve the full content' );
		ftp_expect( true === $provider->delete( $name, $result ), 'Deletion must also reuse the backup connection' );
		// Directory commands and URL encoding must preserve literal path characters.
		$directory = 'archives #?% space';
		ftp_expect( $ftp->make_dir( $directory ), 'Creating a relative directory must succeed' );
		ftp_expect( $ftp->make_dir( '/' . $directory ), 'Creating an absolute directory must succeed' );
		foreach ( array( $directory, '/' . $directory ) as $path ) {
			$special_name    = 'backup_#?% ;type=a space.zip';
			$options['path'] = $path;
			$provider->set_options( $options );
			file_put_contents( $fixture . '/' . $special_name, $contents );
			$output = ftp_test_credentials( $provider, $options );
			ftp_expect( 0 === strpos( $output, 'Success:' ), 'Credentials test must support literal directory characters' );
			$path_result = $provider->backup( array( $special_name ) );
			ftp_expect( is_array( $path_result ) && $ftp->size( $path . '/' . $special_name ) === strlen( $contents ), 'Uploads must preserve absolute and relative paths with URL metacharacters' );
			$files = $provider->listfiles();
			ftp_expect( is_array( $files ) && in_array( $special_name, array_column( $files, 'name' ), true ), 'Listing must preserve literal filenames' );
			unlink( $fixture . '/' . $special_name );
			ftp_expect( $provider->download( $special_name ) && $contents === file_get_contents( $fixture . '/' . $special_name ), 'Encoded downloads must preserve the full content' );
			$remote_file = $path . '/' . $special_name;
			ftp_expect( $ftp->rename( $remote_file, $remote_file . '.renamed' ), 'Renaming must support literal path characters' );
			ftp_expect( $ftp->chmod( 0644, $remote_file . '.renamed' ), 'Changing permissions must succeed' );
			ftp_expect( $ftp->rename( $remote_file . '.renamed', $remote_file ), 'Renaming back must succeed' );
			ftp_expect( true === $provider->delete( $special_name, $path_result ), 'Deletion must preserve literal paths' );
		}
		$provider->set_options( array_merge( $options, array( 'path' => 'missing-directory' ) ) );
		ftp_expect( is_wp_error( $provider->listfiles() ), 'A failed listing must return a storage error' );
		$entry = $ftp->current_dir();
		ftp_expect( $ftp->chdir( $directory ), 'Changing directories must succeed' );
		ftp_expect( $ftp->current_dir() === $entry . '/' . $directory, 'Working directory must reflect the directory change' );
		ftp_expect( $ftp->cdup() && $entry === $ftp->current_dir(), 'Moving to the parent directory must succeed' );
		ftp_expect( $ftp->remove_dir( $directory ) && $ftp->remove_dir( '/' . $directory ), 'Removing directories must succeed' );
		ftp_expect( ! $ftp->delete( "file\r\nDELE other-file" ), 'Raw FTP commands must reject newline injection' );
		$empty_file = $fixture . '/empty-file';
		file_put_contents( $empty_file, '' );
		ftp_expect( $ftp->put( $empty_file, 'empty-file' ) && 0 === $ftp->size( 'empty-file' ), 'Zero-byte uploads must succeed' );
		ftp_expect( $ftp->get( $empty_file, 'empty-file' ) && '' === file_get_contents( $empty_file ), 'Zero-byte downloads must succeed' );
		ftp_expect( $ftp->delete( 'empty-file' ), 'Zero-byte files must be deletable' );
		echo "PASS: $encryption (passive=$passive) credentials, upload, listing, download, resumption and deletion\n";
	}
}

foreach ( array( 'ftps://', 'ftpes://' ) as $prefix ) {
	$output = ftp_test_credentials(
		$provider,
		array(
			'host' => $prefix . $secure_host . '/',
			'user' => $user,
			'pass' => $pass,
		)
	);
	ftp_expect( 0 === strpos( $output, 'Success:' ) && false !== strpos( $output, 'encrypted (explicit TLS)' ), 'Secure host prefixes must select TLS in unsaved tests' );
}

// A server that rejects AUTH TLS must not receive a plain FTP retry.
$provider->set_options(
	array(
		'host'       => $plain_host,
		'user'       => $user,
		'pass'       => $pass,
		'encryption' => 'tls',
	)
);
$result = @$provider->listfiles();
ftp_expect( is_wp_error( $result ), 'FTPS must fail against a plain-only server even with valid credentials' );
$output = @ftp_test_credentials(
	$provider,
	array(
		'host' => 'ftps://' . $plain_host . '/',
		'user' => $user,
		'pass' => $pass,
	)
);
ftp_expect( 0 === strpos( $output, 'Failure:' ), 'An unsaved secure host prefix must require TLS' );
$output = @ftp_test_credentials(
	$provider,
	array(
		'host'       => $secure_host,
		'user'       => $user,
		'pass'       => 'wrong-password',
		'encryption' => 'tls',
	)
);
ftp_expect( 0 === strpos( $output, 'Failure:' ), 'FTPS must reject incorrect credentials' );
ftp_expect( ! $provider->is_connection_successful(), 'A failed credentials test must not report a successful connection' );
echo "PASS: TLS refusal, secure-prefix handling and authentication failure\n";

// Default verification must reject an untrusted issuer, expired certificate and wrong hostname.
$bad_certificates = array(
	'untrusted'         => getenv( 'BACKUPPLUS_TEST_FTPS_UNTRUSTED_HOST' ),
	'expired'           => getenv( 'BACKUPPLUS_TEST_FTPS_EXPIRED_HOST' ),
	'hostname mismatch' => preg_replace( '/^localhost:/', '127.0.0.1:', $secure_host ),
);
foreach ( $bad_certificates as $reason => $host ) {
	if ( ! $host || ( 'hostname mismatch' === $reason && $host === $secure_host ) ) {
		continue;
	}
	$options = array(
		'host'       => $host,
		'user'       => $user,
		'pass'       => $pass,
		'encryption' => 'tls',
	);
	$output  = ftp_test_credentials( $provider, $options );
	ftp_expect( 0 === strpos( $output, 'Failure:' ) && false !== stripos( $output, 'certificate' ), "FTPS must reject $reason with a certificate error: $output" );
	$provider->set_options( $options );
	ftp_expect( is_wp_error( $provider->listfiles() ), "Background jobs must reject $reason" );
	$output = ftp_test_credentials( $provider, array_merge( $options, array( 'disableverify' => true ) ) );
	ftp_expect( 0 === strpos( $output, 'Success:' ), 'The existing explicit verification override must be honored' );
}
echo "PASS: certificate trust, expiration, hostname checks and explicit verification override\n";

// Selecting system certificates must use PHP's configured CA file rather than the bundle.
$bundle = file_get_contents( $fixture . '/includes/cacert.pem' );
copy( dirname( __DIR__ ) . '/includes/cacert.pem', $fixture . '/includes/cacert.pem' );
$options = array(
	'host'       => $secure_host,
	'user'       => $user,
	'pass'       => $pass,
	'encryption' => 'tls',
);
$output  = ftp_test_credentials( $provider, $options );
ftp_expect( 0 === strpos( $output, 'Failure:' ), 'The bundled CA selection must not silently use the system test CA' );
$output = ftp_test_credentials( $provider, array_merge( $options, array( 'useservercerts' => true ) ) );
ftp_expect( 0 === strpos( $output, 'Success:' ), 'System CA selection must honor curl.cainfo across request resets' );
$backupplus->use_server_certs = true;
$provider->set_options( $options );
ftp_expect( is_array( $provider->listfiles() ), 'Background jobs must honor system CA selection' );
file_put_contents( $fixture . '/includes/cacert.pem', $bundle );
echo "PASS: bundled and system CA selection in tests and background jobs\n";
