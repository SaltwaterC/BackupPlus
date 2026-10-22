<?php
/** Verify pre-migration ciphertext and encrypted backup resumption with phpseclib 3. */
define('ABSPATH', dirname(__DIR__).'/');
define('BACKUPPLUS_DIR', dirname(__DIR__));
define('BACKUPPLUS_PHPSECLIB_MIN_PHP_VERSION', '5.6.1');
function __($text, $domain = null) { return $text; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function backupplus_try_include_file($path, $mode) { require_once BACKUPPLUS_DIR.'/'.$path; }
class WP_Error {
	private $code;
	private $message;
	public function __construct($code, $message) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function crypto_expect($condition, $message) {
	if (!$condition) throw new RuntimeException($message);
}
require BACKUPPLUS_DIR.'/class-backupplus.php';
require BACKUPPLUS_DIR.'/includes/class-backupplus-encryption.php';
require BACKUPPLUS_DIR.'/includes/class-manipulation-functions.php';
require BACKUPPLUS_DIR.'/includes/Dropbox2/OAuth/Storage/Encrypter.php';
class CryptoTestBackupPlus extends BackupPlus {
	public function __construct() {}
	public function log($line, $level = 'notice', $uniq_id = false, $skip_dblog = false) {}
}
$backupplus = new CryptoTestBackupPlus();
crypto_expect(true === $backupplus->ensure_phpseclib(), 'The shared crypto loader must load phpseclib 3');
crypto_expect('3.0.57' === Composer\InstalledVersions::getPrettyVersion('phpseclib/phpseclib'), 'Composer runtime metadata must identify the bundled phpseclib version');
crypto_expect(!class_exists('phpseclib\\Crypt\\RSA') && !class_exists('phpseclib_Crypt_RSA'), 'phpseclib 2 and its aliases must be absent');
$installed = json_decode(file_get_contents(BACKUPPLUS_DIR.'/vendor/composer/installed.json'), true);
foreach ($installed['packages'] as $package) {
	if ('phpseclib/phpseclib' === $package['name']) crypto_expect(0 === strpos($package['version'], '3.'), 'Only phpseclib 3 may be bundled');
}
$fixture = sys_get_temp_dir().'/backupplus-crypto-'.uniqid();
mkdir($fixture);
register_shutdown_function(function() use ($fixture) {
	foreach (glob($fixture.'/*') as $file) unlink($file);
	rmdir($fixture);
});
// These vectors were created with v2 before its removal, rather than by the code under test.
$vectors = json_decode(file_get_contents(__DIR__.'/fixtures/phpseclib2-compat.json'), true);
foreach ($vectors['database'] as $index => $vector) {
	$key = base64_decode($vector['key']);
	$plain = base64_decode($vector['plaintext']);
	$ciphertext = base64_decode($vector['ciphertext']);
	$path = $fixture.'/database-'.$index.'.sql';
	file_put_contents($path.'.crypt', $ciphertext);
	$decrypted = BackupPlus_Encryption::decrypt($path.'.crypt', $key, true);
	crypto_expect(is_array($decrypted) && $plain === file_get_contents($decrypted['fullpath']), 'Old encrypted backups must decrypt for key length '.strlen($key));
	file_put_contents($path, $plain);
	$encrypted = BackupPlus_Encryption::encrypt($path, $key);
	crypto_expect($encrypted && $ciphertext === file_get_contents($encrypted), 'Encryption must preserve existing ciphertext for key length '.strlen($key));
}
echo "PASS: pre-migration database ciphertext, including padded, truncated and 160/224-bit keys\n";

$large = str_repeat("-- chunked database fixture\n", 90000).'trailing data';
$path = $fixture.'/chunked.sql';
file_put_contents($path, $large);
$encrypted = BackupPlus_Encryption::encrypt($path, $vectors['chunked']['key']);
crypto_expect($encrypted && $vectors['chunked']['ciphertext_sha256'] === hash_file('sha256', $encrypted), 'Chunked backup encryption must match the pre-migration file');
$first_chunk = file_get_contents($encrypted, false, null, 0, 2097152);
foreach (array($first_chunk, $first_chunk.'halfblock', 'invalid') as $partial) {
	file_put_contents($fixture.'/encrypt_chunked.sql.tmp', $partial);
	$encrypted = BackupPlus_Encryption::encrypt($path, $vectors['chunked']['key']);
	crypto_expect($encrypted && $vectors['chunked']['ciphertext_sha256'] === hash_file('sha256', $encrypted), 'Resumed backup encryption must match the original format');
}
$decrypted = BackupPlus_Encryption::decrypt($encrypted, $vectors['chunked']['key']);
crypto_expect(is_array($decrypted) && $vectors['chunked']['plaintext_sha256'] === hash_file('sha256', $decrypted['fullpath']), 'A chunked encrypted backup must restore exactly');
echo "PASS: multi-buffer backup encryption, interrupted encryption resumption and decryption\n";

foreach ($vectors['dropbox'] as $vector) {
	$encrypter = new Dropbox_Encrypter($vector['key']);
	$plain = base64_decode($vector['plaintext']);
	crypto_expect($plain === $encrypter->decrypt($vector['ciphertext']), 'Existing Dropbox tokens must decrypt');
	$encrypted = $encrypter->encrypt($plain);
	crypto_expect($plain === $encrypter->decrypt($encrypted), 'New Dropbox tokens must roundtrip');
	crypto_expect($encrypted !== $encrypter->encrypt($plain), 'Dropbox encryption must use a fresh random IV');
	$raw = base64_decode($vector['ciphertext']);
	$cipher = BackupPlus_Crypto::rijndael($vector['key'], substr($raw, 0, 16));
	crypto_expect(substr($raw, 16) === $cipher->encrypt($plain), 'Dropbox token encryption must preserve its ciphertext format');
	foreach (array('not base64!', '', base64_encode('short'), base64_encode(str_repeat('x', 33))) as $invalid) {
		crypto_expect(false === $encrypter->decrypt($invalid), 'Malformed stored tokens must fail without exceptions');
	}
}
echo "PASS: pre-migration Dropbox tokens, new token encryption and malformed token handling\n";
