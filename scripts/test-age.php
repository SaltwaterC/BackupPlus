<?php
/** age v1 interoperability fixtures, key handling and request-only restore identities. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'BACKUPPLUS_DIR', dirname( __DIR__ ) );
define( 'BACKUPPLUS_PHPSECLIB_MIN_PHP_VERSION', '5.6.1' );
$portable = in_array( '--portable', $argv, true );
if ( $portable ) {
	define( 'BACKUPPLUS_ENCRYPTION_ENGINE', 'PHP' );
	require __DIR__ . '/fixtures/without-crypto-extensions.php';
}
function __( $text, $domain = null ) {
	return $text; }
function esc_html( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES ); }
function is_wp_error( $value ) {
	return $value instanceof WP_Error; }
function backupplus_try_include_file( $path, $mode ) {
	require_once BACKUPPLUS_DIR . '/' . $path; }
function age_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	} }
class WP_Error {}
class wpdb {}
class BackupPlus_Options {
	public static $options = array();
	public static function get_backupplus_option( $name, $default = false ) {
		return isset( self::$options[ $name ] ) ? self::$options[ $name ] : $default; }
	public static function user_can_manage() {
		global $age_allowed;
		return $age_allowed; }
}
$age_allowed         = true;
$age_user            = 1;
$age_blog            = 1;
$age_transients      = array();
$age_settings_errors = array();
$age_http_requests   = array();
$age_http_body       = '';
$age_http_status     = 200;
function get_current_user_id() {
	global $age_user;
	return $age_user; }
function get_current_blog_id() {
	global $age_blog;
	return $age_blog; }
function set_transient( $name, $value, $lifetime ) {
	global $age_transients;
	$age_transients[ $name ] = $value;
	return true; }
function get_transient( $name ) {
	global $age_transients;
	return isset( $age_transients[ $name ] ) ? $age_transients[ $name ] : false; }
function add_settings_error( $setting, $code, $text ) {
	global $age_settings_errors;
	$age_settings_errors[] = $text; }
function wp_safe_remote_get( $url, $args ) {
	global $age_http_requests;
	$age_http_requests[] = array( $url, $args );
	return array(); }
function wp_remote_retrieve_response_code( $response ) {
	global $age_http_status;
	return $age_http_status; }
function wp_remote_retrieve_body( $response ) {
	global $age_http_body;
	return $age_http_body; }
require BACKUPPLUS_DIR . '/class-backupplus.php';
require BACKUPPLUS_DIR . '/includes/class-backupplus-encryption.php';
require BACKUPPLUS_DIR . '/includes/class-database-encryption.php';
require BACKUPPLUS_DIR . '/includes/class-age-restore.php';
require BACKUPPLUS_DIR . '/backup.php';
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
$backupplus = new AgeTestBackupPlus();
age_expect( true === $backupplus->ensure_phpseclib(), 'Crypto loader must succeed' );
if ( $portable ) {
	\phpseclib3\Math\BigInteger::setEngine( PHP_INT_SIZE >= 8 ? 'PHP64' : 'PHP32' );
	\phpseclib3\Crypt\EC::forceEngine( 'PHP' );
	\phpseclib3\Crypt\RSA::forceEngine( 'PHP' );
}
$directory = sys_get_temp_dir() . '/backupplus-age-' . bin2hex( random_bytes( 8 ) );
mkdir( $directory, 0700 );
$backupplus->directory = $directory;
register_shutdown_function(
	function () use ( $directory ) {
		foreach ( glob( $directory . '/*' ) as $file ) {
			if ( is_file( $file ) || is_link( $file ) ) {
				unlink( $file );
			}
		}
		rmdir( $directory );
	}
);
$fixture  = json_decode( file_get_contents( __DIR__ . '/fixtures/age-keys.json' ), true );
$password = $fixture['passphrase'];
$plain    = base64_decode( $fixture['plaintext'] );
$path     = $directory . '/backup_2026-10-22-1200_fixture_0123456789ab-db.gz.crypt';
foreach ( $fixture['keys'] as $type => $key ) {
	file_put_contents( $path, base64_decode( $key['ciphertext'] ) );
	foreach ( array( 'identity', 'encrypted_pem' ) as $format ) {
		$result = BackupPlus_Encryption::decrypt( $path, $key[ $format ], true, $password );
		age_expect( is_array( $result ) && $plain === file_get_contents( $result['fullpath'] ), 'Reference age output must decrypt with ' . $type . ' ' . $format );
		unlink( $result['fullpath'] );
	}
}
// RFC 8439 AEAD compatibility, independently generated by OpenSSL.
$aead_key = str_repeat( 'k', 32 );
$nonce    = str_repeat( 'n', 12 );
$expected = hex2bin( 'e8964626b7a0ce3f5a069f649079729b68b701e0bca348' );
age_expect( $expected === BackupPlus_Age_Keys::aead( 'fixture', $aead_key, $nonce ), 'ChaCha20-Poly1305 must match the independent AEAD vector' );
age_expect( 'fixture' === BackupPlus_Age_Keys::aead( $expected, $aead_key, $nonce, true ), 'AEAD decryption must use the same RFC 8439 construction' );
echo "PASS: independent age fixtures for X25519, SSH RSA/Ed25519, encrypted OpenSSH and encrypted PEM identities\n";

$source     = $directory . '/database.sql';
$recipients = array_column( $fixture['keys'], 'recipient' );
foreach ( $fixture['keys'] as $type => $key ) {
	$bytes = 'native' === $type ? str_repeat( "binary\0\xff", 20000 ) . 'tail' : $plain;
	file_put_contents( $source, $bytes );
	$encrypted = BackupPlus_Encryption::encrypt( $source, array( $key['recipient'] ), 'age' );
	age_expect( false !== $encrypted && 0 === strpos( file_get_contents( $encrypted ), BackupPlus_Age_Crypto::MAGIC ), 'New age writes must use the standard format' );
	$result = BackupPlus_Encryption::decrypt( $encrypted, $key['identity'], true, $password );
	age_expect( is_array( $result ) && $bytes === file_get_contents( $result['fullpath'] ), 'Fresh output must roundtrip with ' . $type );
	unlink( $result['fullpath'] );
}
if ( $portable ) {
	echo "PASS: portable PHP X25519, ChaCha20-Poly1305 and SSH recipient wrapping\n";
	exit;
}

function age_reject( $raw, $identity, $passphrase, $message ) {
	global $path, $directory;
	file_put_contents( $path, $raw );
	$destination = substr( $path, 0, -6 );
	file_put_contents( $destination, 'preserve destination' );
	age_expect( false === BackupPlus_Encryption::decrypt( $path, $identity, false, $passphrase ), $message );
	age_expect( 'preserve destination' === file_get_contents( $destination ), 'Rejection must preserve an existing destination' );
	age_expect( array() === glob( $directory . '/decrypt_*.tmp' ), 'Rejection must remove staged plaintext' );
}
$native    = $fixture['keys']['native'];
$reference = base64_decode( $native['ciphertext'] );
age_reject( $reference, $fixture['keys']['ed25519']['identity'], $password, 'Wrong identities must fail' );
age_reject( $reference, $native['encrypted_pem'], 'wrong password sentinel', 'Wrong PEM passwords must fail' );
age_reject( base64_decode( $fixture['keys']['ed25519']['ciphertext'] ), $fixture['keys']['ed25519']['identity'], 'wrong password sentinel', 'Wrong OpenSSH passwords must fail' );
$payload_start = strpos( $reference, "\n", strpos( $reference, '--- ' ) ) + 1;
foreach ( array_merge( array( 0, 17, 19, 24, 40, 80, $payload_start - 10 ), range( $payload_start, strlen( $reference ) - 1 ) ) as $offset ) {
	$changed            = $reference;
	$changed[ $offset ] = chr( ord( $changed[ $offset ] ) ^ 1 );
	age_reject( $changed, $native['identity'], '', 'Header/nonce/ciphertext/tag changes must fail at byte ' . $offset );
}
age_reject( substr( $reference, 0, -1 ), $native['identity'], '', 'Truncation must fail' );
age_reject( $reference . 'extra', $native['identity'], '', 'Trailing data must fail' );
age_reject( BackupPlus_Age_Crypto::MAGIC . str_repeat( 'x', 70000 ), $native['identity'], '', 'Oversized headers must fail' );
age_reject( str_replace( "/v1\n", "/v2\n", $reference ), $native['identity'], '', 'Unknown versions must fail' );

foreach ( array( '', str_repeat( 'x', 65536 ), str_repeat( 'x', 65537 ), str_repeat( 'x', 196608 ) . 'last' ) as $bytes ) {
	file_put_contents( $source, $bytes );
	$encrypted = BackupPlus_Encryption::encrypt( $source, $recipients, 'age' );
	age_expect( false !== $encrypted, 'Multi-recipient encryption must succeed' );
	$raw = file_get_contents( $encrypted );
	foreach ( $fixture['keys'] as $key ) {
		$result = BackupPlus_Encryption::decrypt( $encrypted, $key['encrypted_pem'], true, $password );
		age_expect( is_array( $result ) && $bytes === file_get_contents( $result['fullpath'] ), 'Each recipient must independently decrypt exact chunk boundaries' );
		unlink( $result['fullpath'] );
	}
}
$start  = strpos( $raw, "\n", strpos( $raw, '--- ' ) ) + 1 + 16;
$header = substr( $raw, 0, $start );
$chunks = str_split( substr( $raw, $start ), 65552 );
foreach ( array( $header . $chunks[1] . $chunks[0] . $chunks[2] . $chunks[3], $header . $chunks[0] . $chunks[0] . $chunks[1] . $chunks[2] . $chunks[3], $header . $chunks[0] . $chunks[2] . $chunks[3], substr( $raw, 0, -strlen( $chunks[3] ) ) ) as $changed ) {
	age_reject( $changed, $native['identity'], '', 'Chunk order, duplication, removal and completion must authenticate' );
}
file_put_contents( $source, $plain );
$encrypted = BackupPlus_Encryption::encrypt( $source, $recipients, 'age' );
$first_raw = file_get_contents( $encrypted );
age_expect( false !== BackupPlus_Encryption::encrypt( $source, $recipients, 'age' ) && $first_raw !== file_get_contents( $encrypted ), 'Retries and repeated encryption must generate fresh file keys and nonces' );
echo "PASS: multi-recipient files, empty/exact chunk boundaries, fresh randomness, tampering/order/completion rejection and cleanup\n";

// GitHub lookup is constrained and performed at save time; jobs use pinned public keys.
$age_http_body = $fixture['keys']['rsa']['recipient'] . "\n" . $fixture['keys']['ed25519']['recipient'] . "\n" . 'ecdsa-sha2-nistp256 unsupported' . "\n";
$config        = BackupPlus_Database_Encryption::sanitize(
	array(
		'mode'    => 'age',
		'sources' => "github:fixture-user\n" . $native['recipient'],
	)
);
age_expect( 3 === count( $config['recipients'] ) && 1 === count( $age_http_requests ), 'GitHub expansion must pin supported public keys' );
age_expect( 'https://github.com/fixture-user.keys' === $age_http_requests[0][0] && 0 === $age_http_requests[0][1]['redirection'] && true === $age_http_requests[0][1]['sslverify'], 'Lookup must use a fixed HTTPS endpoint without redirects or TLS overrides' );
BackupPlus_Options::$options['backupplus_database_encryption'] = $config;
foreach ( array( 'github:../elsewhere', 'github:user/../../secret', $native['identity'], 'age1invalid', $fixture['keys']['ed25519']['identity'] ) as $bad ) {
	$requests = count( $age_http_requests );
	age_expect(
		$config === BackupPlus_Database_Encryption::sanitize(
			array(
				'mode'    => 'age',
				'sources' => $bad,
			)
		),
		'Invalid recipients must preserve prior settings'
	);
	age_expect( $requests === count( $age_http_requests ), 'Invalid inputs must fail before network access' );
}
$age_http_status = 503;
age_expect(
	$config === BackupPlus_Database_Encryption::sanitize(
		array(
			'mode'    => 'age',
			'sources' => 'github:fixture-user',
		)
	),
	'A GitHub outage must preserve prior settings'
);
$requests = count( $age_http_requests );
$disabled = BackupPlus_Database_Encryption::sanitize(
	array(
		'mode'    => 'none',
		'sources' => 'github:fixture-user',
	)
);
age_expect( 'none' === $disabled['mode'] && $requests === count( $age_http_requests ), 'Disabling age must not require GitHub access' );
$age_http_status = 200;
$age_http_body   = 'no supported keys';
age_expect(
	$config === BackupPlus_Database_Encryption::sanitize(
		array(
			'mode'    => 'age',
			'sources' => 'github:fixture-user',
		)
	),
	'Empty GitHub results must preserve prior settings'
);
$age_http_body = str_repeat( 'x', 65537 );
age_expect(
	$config === BackupPlus_Database_Encryption::sanitize(
		array(
			'mode'    => 'age',
			'sources' => 'github:fixture-user',
		)
	),
	'Oversized GitHub results must preserve prior settings'
);
$before   = count( $age_http_requests );
$snapshot = BackupPlus_Database_Encryption::snapshot( array() );
age_expect( ! array_key_exists( 'backupplus_encryptionphrase', $snapshot ), 'New backup snapshots must not copy legacy passphrases' );
age_expect( $config === $snapshot['backupplus_database_encryption'] && $before === count( $age_http_requests ), 'Job snapshots must contain pinned recipients without network access' );
$worker = ( new ReflectionClass( 'BackupPlus_Backup' ) )->newInstanceWithoutConstructor();
file_put_contents( $directory . '/job.sql', $plain );
$result = $worker->encrypt_file( 'job.sql' );
age_expect( 'job.sql.crypt' === $result && $before === count( $age_http_requests ), 'Actual backup writer must use pinned age recipients' );
BackupPlus_Options::$options['backupplus_database_encryption']['recipients'] = array( 'invalid' );
age_expect( false === $worker->encrypt_file( 'job.sql' ), 'Invalid encryption configuration must fail closed' );
BackupPlus_Options::$options['backupplus_database_encryption'] = $config;
foreach ( array( 'password', 'invalid' ) as $removed_mode ) {
	BackupPlus_Options::$options['backupplus_database_encryption'] = $config;
	age_expect( $config === BackupPlus_Database_Encryption::sanitize( array( 'mode' => $removed_mode ) ), 'Removed or unknown encryption modes must not be saved' );
	BackupPlus_Options::$options['backupplus_database_encryption'] = array( 'mode' => $removed_mode );
	age_expect( false === $worker->encrypt_file( 'job.sql' ), 'Stale passphrase settings must fail closed for databases' );
	age_expect( false === $worker->encrypt_file_archives( array( 'plugins' => 'job.zip' ) ), 'Stale passphrase settings must fail closed for files' );
}
BackupPlus_Options::$options['backupplus_database_encryption'] = $config;
echo "PASS: GitHub public key pinning, constrained lookup, settings failure preservation and backup writer integration\n";

// A restore ticket holds only public file bindings, never private keys/passphrases.
file_put_contents( $path, $reference );
$ticket = BackupPlus_Age_Restore::prepare( array( 'db' => basename( $path ) ), 123, $native['encrypted_pem'], $password );
age_expect( 64 === strlen( $ticket ), 'Restore preflight must produce an opaque ticket' );
$cached = serialize( $age_transients );
foreach ( array( $native['identity'], $native['encrypted_pem'], $password ) as $secret ) {
	age_expect( false === strpos( $cached, $secret ), 'Restore cache must never contain identities or passphrases' );
}
$prepared = BackupPlus_Age_Restore::file( $path, $ticket, '', 123 );
age_expect( is_array( $prepared ) && $plain === file_get_contents( $prepared['fullpath'] ), 'Prepared file must be authenticated and complete' );
$age_user = 2;
age_expect( false === BackupPlus_Age_Restore::file( $path, $ticket ), 'Another user must not reuse the ticket' );
$age_user = 1;
$age_blog = 2;
age_expect( false === BackupPlus_Age_Restore::file( $path, $ticket ), 'Another blog must not reuse the ticket' );
$age_blog = 1;
age_expect( false === BackupPlus_Age_Restore::file( $path, $ticket, '', 124 ), 'Another backup must not reuse the ticket' );
BackupPlus_Age_Restore::claim( $ticket, 123, 'restore-job' );
age_expect( false === BackupPlus_Age_Restore::file( $path, $ticket, 'other-job' ), 'Another job must not reuse the ticket' );
$age_user = 0;
age_expect( is_array( BackupPlus_Age_Restore::file( $path, $ticket, 'restore-job' ) ), 'A bound background job must continue without an identity' );
$age_user = 1;
file_put_contents( $prepared['fullpath'], 'tampered plaintext' );
age_expect( false === BackupPlus_Age_Restore::file( $path, $ticket, 'restore-job' ), 'Prepared plaintext changes must fail' );
file_put_contents( $prepared['fullpath'], $plain );
file_put_contents( $path, $reference . 'changed' );
age_expect( false === BackupPlus_Age_Restore::file( $path, $ticket, 'restore-job' ), 'Ciphertext changes after preflight must fail' );
file_put_contents( $path, $reference );
$age_transients[ 'backupplus_age_restore_' . $ticket ]['expires'] = time() - 1;
age_expect( false === BackupPlus_Age_Restore::file( $path, $ticket, 'restore-job' ), 'Expired tickets must fail' );
unlink( $prepared['fullpath'] );
age_expect( false === strpos( implode( "\n", $backupplus->logs ), $password ) && false === strpos( implode( "\n", $age_settings_errors ), $password ), 'Logs and settings errors must omit supplied secrets' );
$js = file_get_contents( BACKUPPLUS_DIR . '/includes/backupplus-admin-common.js' );
age_expect( false === strpos( $js, '&decrypt_key=' ) && false === strpos( $js, 'backupplus_i18n.thiskey' ), 'Manual decryption UI must not place secrets in links or page text' );
echo "PASS: ephemeral restore keys, user/blog/backup/job binding, background continuation, tamper checks and expiration\n";

// Exercise file-part checkpoints and the upload boundary using the real writer.
class FileEncryptionFailureProbe extends BackupPlus_Backup {
	public $fail_file = '';
	public $uploads   = array();
	public function __construct() {}
	public function encrypt_file( $file ) {
		return $file === $this->fail_file ? false : parent::encrypt_file( $file ); }
	public function cloud_backup( $files, $stage = 'clouduploading' ) {
		$this->uploads[] = $files; }
}
$parts     = array( 'backup_2026-10-22-1200_fixture_0123456789ab-plugins.zip', 'backup_2026-10-22-1200_fixture_0123456789ab-plugins2.zip' );
$zip_plain = "PK\x03\x04" . str_repeat( "synthetic WordPress secret\0", 5000 );
foreach ( array( 'age' ) as $mode ) {
	BackupPlus_Options::$options['backupplus_database_encryption'] = array(
		'mode'       => $mode,
		'recipients' => array( $native['recipient'] ),
	);
	BackupPlus_Options::$options['backupplus_encryptionphrase']    = 'file archive passphrase';
	$probe = new FileEncryptionFailureProbe();
	foreach ( $parts as $part ) {
		file_put_contents( $directory . '/' . $part, $zip_plain );
		file_put_contents( $directory . '/' . $part . '.list.tmp', '{"files":["synthetic-key"]}' );
	}
	$files        = array(
		'plugins'       => $parts,
		'plugins-size'  => strlen( $zip_plain ),
		'plugins1-size' => strlen( $zip_plain ),
	);
	$early_upload = new ReflectionMethod( $probe, 'maybe_cloud_backup' );
	if ( PHP_VERSION_ID < 80100 ) {
		$early_upload->setAccessible( true );
	}
	$early_upload->invoke( $probe, $parts[0], 'plugins', 0 );
	age_expect( array() === $probe->uploads, 'Encrypted backups must not upload a ZIP before encryption' );
	$probe->fail_file = $parts[1];
	age_expect( false === $probe->encrypt_file_archives( $files ), 'A failed ZIP part must stop dispatch' );
	$checkpoint = $backupplus->jobdata_get( 'backup_files_array' );
	age_expect( $parts[0] . '.crypt' === $checkpoint['plugins'][0] && $parts[1] === $checkpoint['plugins'][1], 'Completed parts must be checkpointed individually' );
	age_expect( ! file_exists( $directory . '/' . $parts[0] ) && file_exists( $directory . '/' . $parts[1] ), 'Only successfully encrypted parts may lose their plaintext' );
	$first_cipher_hash = hash_file( 'sha256', $directory . '/' . $parts[0] . '.crypt' );
	$probe->fail_file  = '';
	$files             = $probe->encrypt_file_archives( $checkpoint );
	age_expect( is_array( $files ) && $first_cipher_hash === hash_file( 'sha256', $directory . '/' . $parts[0] . '.crypt' ), 'A retry must preserve completed ciphertext and finish remaining parts' );
	foreach ( $parts as $index => $part ) {
		age_expect( $part . '.crypt' === $files['plugins'][ $index ] && ! file_exists( $directory . '/' . $part ) && ! file_exists( $directory . '/' . $part . '.list.tmp' ), 'History must refer to ciphertext and remove completed plaintext/manifests' );
		age_expect( $backupplus->jobdata_get( 'sha256-plugins' . $index . '.crypt' ) === hash_file( 'sha256', $directory . '/' . $part . '.crypt' ), 'Upload checksums must cover ciphertext' );
		age_expect( $files[ 'plugins' . ( $index ? $index : '' ) . '-size' ] === filesize( $directory . '/' . $part . '.crypt' ), 'History sizes must describe ciphertext' );
	}
	$backup = array(
		'plugins' => $files['plugins'],
		'db'      => 'unselected-unavailable-db.gz.crypt',
	);
	$ticket = BackupPlus_Age_Restore::prepare( $backup, 321, $native['identity'], '', '', array( 'plugins' ), 'file archive passphrase' );
	age_expect( $ticket === BackupPlus_Age_Restore::prepare( $backup, 321, '', '', $ticket, array( 'plugins' ) ), 'File-only preflight must reuse prepared parts without retaining a key' );
	$backupplus->nonce = 'restore-file-job';
	BackupPlus_Age_Restore::claim( $ticket, 321, $backupplus->nonce );
	unset( $age_transients[ 'backupplus_age_restore_' . $ticket ] );
	foreach ( array( $native['identity'], 'file archive passphrase' ) as $secret ) {
		age_expect( false === strpos( serialize( $backupplus->jobdata_get( 'backupplus_prepared_archives' ) ), $secret ), 'Preserved job bindings must not contain keys or passphrases' );
	}
	foreach ( $files['plugins'] as $file ) {
		$prepared = BackupPlus_Age_Restore::file( $directory . '/' . $file, $ticket, $backupplus->nonce, 321 );
		age_expect( is_array( $prepared ) && $zip_plain === file_get_contents( $prepared['fullpath'] ), 'File-only preflight must authenticate every selected split archive' );
		unlink( $prepared['fullpath'] );
	}
	$encrypted_path      = $directory . '/' . $files['plugins'][0];
	$original_ciphertext = file_get_contents( $encrypted_path );
	file_put_contents( $encrypted_path, $original_ciphertext . 'changed' );
	age_expect( false === $probe->encrypt_file_archives( $files ), 'Retries must reject changes to completed ciphertext' );
	file_put_contents( $encrypted_path, $original_ciphertext );
	try {
		$worker->cloud_backup( array( 'plugins0' => $parts[0] ) );
		age_expect( false, 'Plaintext must never reach upload dispatch' ); } catch ( RuntimeException $e ) {
		age_expect( false !== strpos( $e->getMessage(), 'refused' ), 'Dispatch must reject plaintext before constructing providers' ); }
		$backupplus->test_uploaded = $files['plugins'];
		foreach ( $files['plugins'] as $file ) {
			unlink( $directory . '/' . $file );
		}
		age_expect( $files === $probe->encrypt_file_archives( $files ), 'Resumption must retain already uploaded encrypted parts after local removal' );
		$backupplus->test_uploaded = array();
}
BackupPlus_Options::$options['backupplus_database_encryption'] = array( 'mode' => 'none' );
file_put_contents( $directory . '/' . $parts[0], $zip_plain );
age_expect( $parts[0] === $worker->encrypt_file( $parts[0] ) && ! file_exists( $directory . '/' . $parts[0] . '.crypt' ), 'Disabled mode must ignore a previously saved backup passphrase' );
age_expect( array( 'plugins' => $parts[0] ) === $worker->encrypt_file_archives( array( 'plugins' => $parts[0] ) ) && is_file( $directory . '/' . $parts[0] ), 'Disabled encryption must preserve ordinary file backup behavior' );
require_once BACKUPPLUS_DIR . '/includes/class-manipulation-functions.php';
$parsed = BackupPlus_Manipulation_Functions::parse_filename( $parts[1] . '.crypt' );
age_expect( 'plugins' === $parsed['type'] && 1 === $parsed['index'] && 'zip.crypt' === $parsed['extension'], 'Encrypted split archive filenames must preserve their type and index' );
echo "PASS: age file archives, split-part checkpoints and retry, ciphertext sizes/checksums, plaintext cleanup, file-only preflight and upload refusal\n";
