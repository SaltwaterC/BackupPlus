<?php
/** CLI-only bridge for independent age interoperability tests. Synthetic keys only. */
if ('cli' !== PHP_SAPI) die('CLI only.');
define('ABSPATH', dirname(__DIR__, 2).'/');
$request = json_decode(stream_get_contents(STDIN), true);
if (!is_array($request)) exit(2);
if (!empty($request['portable'])) {
	define('BACKUPPLUS_ENCRYPTION_ENGINE', 'PHP');
	require __DIR__.'/without-crypto-extensions.php';
}
require ABSPATH.'vendor/autoload.php';
require ABSPATH.'includes/class-backupplus-crypto.php';
require ABSPATH.'includes/class-backupplus-encryption.php';
if (!empty($request['portable'])) {
	\phpseclib3\Math\BigInteger::setEngine(PHP_INT_SIZE >= 8 ? 'PHP64' : 'PHP32');
	\phpseclib3\Crypt\EC::forceEngine('PHP');
	\phpseclib3\Crypt\RSA::forceEngine('PHP');
}
function is_wp_error($value) { return false; }
class AgeInteropLoader {
	public function ensure_phpseclib() { return true; }
	public function log($message, $level = 'notice') {}
}
$backupplus = new AgeInteropLoader();
try {
	if ('identity' === $request['operation']) {
		$key = \phpseclib3\Crypt\PublicKeyLoader::loadPrivateKey($request['identity'], $request['passphrase']);
		echo $key->withPassword('')->toString('OpenSSH');
	} elseif ('encrypt' === $request['operation']) {
		$path = BackupPlus_Encryption::encrypt($request['input'], $request['recipients'], 'age');
		if (false === $path || !rename($path, $request['output'])) throw new RuntimeException('Encryption failed.');
	} elseif ('decrypt' === $request['operation']) {
		$result = BackupPlus_Encryption::decrypt($request['input'], $request['identity'], true, $request['passphrase']);
		if (false === $result) throw new RuntimeException('Decryption failed.');
		try {
			if (!copy($result['fullpath'], $request['output'])) throw new RuntimeException('Copy failed.');
		} finally { unlink($result['fullpath']); }
	} else { exit(2); }
} catch (Throwable $e) {
	fwrite(STDERR, "age interoperability operation failed.\n");
	exit(1);
}
