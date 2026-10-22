<?php
/** SEC-002: original UpdraftPlus CBC reads, age-only writes and failure preservation. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'BACKUPPLUS_DIR', dirname( __DIR__ ) );
define( 'BACKUPPLUS_PHPSECLIB_MIN_PHP_VERSION', '5.6.1' );
if ( in_array( '--portable', $argv, true ) ) {
	define( 'BACKUPPLUS_ENCRYPTION_ENGINE', 'PHP' );
	require __DIR__ . '/fixtures/without-crypto-extensions.php';
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
function crypto_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
require BACKUPPLUS_DIR . '/class-backupplus.php';
require BACKUPPLUS_DIR . '/includes/class-backupplus-encryption.php';
require BACKUPPLUS_DIR . '/includes/class-manipulation-functions.php';
class CryptoTestBackupPlus extends BackupPlus {
	public function __construct() {}
	public function log( $line, $level = 'notice', $uniq_id = false, $skip_dblog = false ) {}
}
$backupplus = new CryptoTestBackupPlus();
crypto_expect( true === $backupplus->ensure_phpseclib(), 'The shared crypto loader must load phpseclib 3' );
crypto_expect( '3.0.57' === Composer\InstalledVersions::getPrettyVersion( 'phpseclib/phpseclib' ), 'Composer runtime metadata must identify the bundled phpseclib version' );
crypto_expect( ! class_exists( 'phpseclib\\Crypt\\RSA' ) && ! class_exists( 'phpseclib_Crypt_RSA' ), 'phpseclib 2 and its aliases must be absent' );
$installed = json_decode( file_get_contents( BACKUPPLUS_DIR . '/vendor/composer/installed.json' ), true );
foreach ( $installed['packages'] as $package ) {
	if ( 'phpseclib/phpseclib' === $package['name'] ) {
		crypto_expect( 0 === strpos( $package['version'], '3.' ), 'Only phpseclib 3 may be bundled' );
	}
}
$fixture = sys_get_temp_dir() . '/backupplus-crypto-' . uniqid();
mkdir( $fixture );
register_shutdown_function(
	function () use ( $fixture ) {
		foreach ( glob( $fixture . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $fixture );
	}
);
$source = $fixture . '/source.sql';
file_put_contents( $source, '-- source must survive' );
crypto_expect( false === BackupPlus_Encryption::encrypt( $source, 'removed passphrase' ), 'The default writer must not accept a passphrase' );
crypto_expect( false === BackupPlus_Encryption::encrypt( $source, 'removed passphrase', 'password' ), 'Explicit password writes must be rejected' );
crypto_expect( ! file_exists( $source . '.crypt' ), 'Rejected password writes must not create an archive' );
echo "PASS: backup writing accepts only age public recipients\n";
// These vectors were created with v2 before its removal, rather than by the code under test.
$vectors = json_decode( file_get_contents( __DIR__ . '/fixtures/phpseclib2-compat.json' ), true );
foreach ( $vectors['database'] as $index => $vector ) {
	$key        = base64_decode( $vector['key'] );
	$plain      = base64_decode( $vector['plaintext'] );
	$ciphertext = base64_decode( $vector['ciphertext'] );
	$path       = $fixture . '/database-' . $index . '.sql';
	file_put_contents( $path . '.crypt', $ciphertext );
	$decrypted = BackupPlus_Encryption::decrypt( $path . '.crypt', $key, true );
	crypto_expect( is_array( $decrypted ) && $plain === file_get_contents( $decrypted['fullpath'] ), 'Old encrypted backups must decrypt for key length ' . strlen( $key ) );
	unlink( $decrypted['fullpath'] );
}
echo "PASS: pre-migration database ciphertext, including padded, truncated and 160/224-bit keys\n";


// Reconstruct the saved large legacy fixture and check its pre-migration hash.
$legacy_plain  = str_repeat( "-- chunked database fixture\n", 90000 ) . 'trailing data';
$legacy_cipher = BackupPlus_Crypto::rijndael( $vectors['chunked']['key'] );
$legacy_cipher->disablePadding();
$padding    = ( 16 - strlen( $legacy_plain ) % 16 ) % 16;
$legacy_raw = $legacy_cipher->encrypt( $legacy_plain . str_repeat( chr( $padding ), $padding ) );
crypto_expect( $vectors['chunked']['ciphertext_sha256'] === hash( 'sha256', $legacy_raw ), 'Reconstructed legacy fixture must match the saved pre-migration hash' );
$path = $fixture . '/legacy-chunked.sql.crypt';
file_put_contents( $path, $legacy_raw );
$decrypted = BackupPlus_Encryption::decrypt( $path, $vectors['chunked']['key'], true );
crypto_expect( is_array( $decrypted ) && $vectors['chunked']['plaintext_sha256'] === hash_file( 'sha256', $decrypted['fullpath'] ), 'The large pre-migration backup must restore exactly' );
unlink( $decrypted['fullpath'] );
// Legacy ZIP signatures and gzip both remain readable, including aligned data.
foreach ( array( "\x1f\x8b" . str_repeat( 'g', 30 ), "PK\x03\x04" . str_repeat( 'z', 28 ), "PK\x05\x06" . str_repeat( 'z', 28 ) ) as $plain ) {
	file_put_contents( $path, $legacy_cipher->encrypt( $plain ) );
	$decrypted = BackupPlus_Encryption::decrypt( $path, $vectors['chunked']['key'], true );
	crypto_expect( is_array( $decrypted ) && $plain === file_get_contents( $decrypted['fullpath'] ), 'Legacy compressed archive signatures must restore' );
	unlink( $decrypted['fullpath'] );
}
echo "PASS: large legacy fixture and compressed archive compatibility\n";

// Failed legacy reads preserve destinations and never publish partial plaintext.
$legacy       = $vectors['database'][8];
$legacy_key   = base64_decode( $legacy['key'] );
$legacy_bytes = base64_decode( $legacy['ciphertext'] );
$bad_path     = $fixture . '/rejected.sql.crypt';
foreach ( array(
	'wrong passphrase' => $legacy_bytes,
	'truncated'        => substr( $legacy_bytes, 0, -1 ),
	'empty'            => '',
) as $case => $raw ) {
	file_put_contents( $bad_path, $raw );
	file_put_contents( $fixture . '/rejected.sql', 'preserve destination' );
	foreach ( array( false, true ) as $temporary ) {
		crypto_expect( false === BackupPlus_Encryption::decrypt( $bad_path, 'wrong passphrase' === $case ? 'wrong' : $legacy_key, $temporary ), 'Invalid legacy input must fail' );
		crypto_expect( 'preserve destination' === file_get_contents( $fixture . '/rejected.sql' ), 'A failure must preserve the destination' );
		crypto_expect( array() === glob( $fixture . '/decrypt_*.tmp' ), 'A failed read must remove staged plaintext' );
	}
}
$age        = json_decode( file_get_contents( __DIR__ . '/fixtures/age-keys.json' ), true );
$identity   = $age['keys']['native']['identity'];
$recipients = array( $age['keys']['native']['recipient'] );
// Exercise partial reads/writes and hard I/O failures without large fixtures.
class CryptoShortIO {
	public $context;
	public static $input      = '';
	public static $output     = '';
	public static $fail_read  = false;
	public static $fail_write = false;
	private $offset           = 0;
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
stream_wrapper_register( 'cryptoshort', 'CryptoShortIO' );
CryptoShortIO::$input = str_repeat( "binary\0data", 100 );
$short_plain          = CryptoShortIO::$input;
$input                = fopen( 'cryptoshort://input', 'rb' );
$output               = fopen( 'cryptoshort://output', 'wb' );
BackupPlus_Age_Crypto::encrypt( $input, $output, $recipients );
fclose( $input );
fclose( $output );
$short_ciphertext      = CryptoShortIO::$output;
CryptoShortIO::$input  = $short_ciphertext;
CryptoShortIO::$output = '';
$input                 = fopen( 'cryptoshort://input', 'rb' );
$output                = fopen( 'cryptoshort://output', 'wb' );
BackupPlus_Age_Crypto::decrypt( $input, $output, $identity );
crypto_expect( $short_plain === CryptoShortIO::$output, 'Short reads and writes must preserve the whole stream' );
fclose( $input );
fclose( $output );
foreach ( array( 'fail_read', 'fail_write' ) as $failure ) {
	CryptoShortIO::${$failure} = true;
	$input                     = fopen( 'cryptoshort://input', 'rb' );
	$output                    = fopen( 'cryptoshort://output', 'wb' );
	$thrown                    = false;
	try {
		BackupPlus_Age_Crypto::decrypt( $input, $output, $identity );
	} catch ( Exception $e ) {
		$thrown = true; }
	crypto_expect( $thrown, 'I/O failures must fail instead of completing or looping' );
	CryptoShortIO::${$failure} = false;
	fclose( $input );
	fclose( $output );
}
stream_wrapper_unregister( 'cryptoshort' );
// A failed publication must preserve both the source and an existing destination.
$path = $fixture . '/publication.sql';
file_put_contents( $path, '-- source must survive' );
mkdir( $path . '.crypt' );
set_error_handler(
	function () {
		return true;
	}
);
try {
	$age_fixture = json_decode( file_get_contents( __DIR__ . '/fixtures/age-keys.json' ), true );
	crypto_expect( false === BackupPlus_Encryption::encrypt( $path, array( $age_fixture['keys']['native']['recipient'] ) ), 'Failed age publication must return false' );
} finally {
	restore_error_handler(); }
crypto_expect( '-- source must survive' === file_get_contents( $path ) && is_dir( $path . '.crypt' ), 'Failed publication must preserve source and destination' );
rmdir( $path . '.crypt' );
crypto_expect( array() === glob( $fixture . '/encrypt_*.tmp' ), 'Failed publication must remove its staging file' );
echo "PASS: short I/O, read/write failure handling and publication failure preservation\n";

// Standalone age decryption withholds all plaintext on authentication failure.
$reference = base64_decode( $age['keys']['native']['ciphertext'] );
foreach ( array(
	'valid'   => $reference,
	'corrupt' => substr( $reference, 0, -1 ) . chr( ord( substr( $reference, -1 ) ) ^ 1 ),
) as $case => $raw ) {
	$path = $fixture . '/standalone.sql.crypt';
	file_put_contents( $path, $raw );
	$code    = 'require ' . var_export( BACKUPPLUS_DIR . '/example-decrypt.php', true ) . '; try { rijndael_decrypt_file(' . var_export( $path, true ) . ', ' . var_export( $identity, true ) . '); } catch (Exception $e) { exit(1); }';
	$process = proc_open(
		array( PHP_BINARY, '-d', 'error_reporting=24575', '-r', $code ),
		array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes
	);
	crypto_expect( is_resource( $process ), 'Standalone decryptor process must start' );
	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$status = proc_close( $process );
	crypto_expect( 'valid' === $case ? ( 0 === $status && base64_decode( $age['plaintext'] ) === $stdout ) : ( 1 === $status && '' === $stdout ), 'Standalone decryption must publish only verified age plaintext' );
	crypto_expect( false === strpos( $stderr, $identity ), 'Standalone diagnostics must not contain the identity' );
}
// The original passphrase format is also accepted by the standalone dispatcher.
file_put_contents( $path, $legacy_bytes );
$decrypted = BackupPlus_Encryption::decrypt( $path, $legacy_key, true );
crypto_expect( is_array( $decrypted ) && base64_decode( $legacy['plaintext'] ) === file_get_contents( $decrypted['fullpath'] ), 'The original passphrase format must remain readable' );
unlink( $decrypted['fullpath'] );
echo "PASS: standalone age authentication and original UpdraftPlus passphrase compatibility\n";
