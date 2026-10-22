<?php

if (!defined('ABSPATH')) {
	if ('cli' !== PHP_SAPI) die('No direct access allowed');
	define('ABSPATH', __DIR__.'/');
}
// @codingStandardsIgnoreStart
/*
To dump the decrypted file using the given key on stdout, call:

rijndael_decrypt_file('../path/to/file.crypt' , 'mykey');

Thus, here are the easy instructions:

1) Add a line like the above into this PHP file (not inside these comments, but outside)
e.g.
rijndael_decrypt_file('/home/myself/myfile.crypt' , 'MYKEY');

2) Run this file (and make sure that the vendor/ and includes/ directories are available, if you are moving this file around)
e.g. 
php /home/myself/example-decrypt.php >output.sql.gz

3) You may then want to gunzip the resulting file to have a standard SQL file.
e.g.
gunzip output.sql.gz

*/
// @codingStandardsIgnoreEnd

/**
 * An example of how to decrypt a file
 *
 * @param String $file           Full path to file to decrypt
 * @param String $key            Archive passphrase or age private identity
 * @param String $key_passphrase Passphrase for an encrypted private identity
 */
function rijndael_decrypt_file($file, $key, $key_passphrase = '') {

	require_once __DIR__.'/vendor/autoload.php';
	require_once __DIR__.'/includes/class-backupplus-crypto.php';
	require_once __DIR__.'/includes/class-backupplus-encryption.php';
	$input = fopen($file, 'rb');
	$output = tmpfile();
	if (false === $input || false === $output) {
		if (is_resource($input)) fclose($input);
		if (is_resource($output)) fclose($output);
		throw new RuntimeException('Could not open decryption files.');
	}
	try {
		BackupPlus_Encryption::decrypt_stream($input, $output, $key, $key_passphrase);
		if (!fflush($output) || 0 !== fseek($output, 0)) throw new RuntimeException('Could not read decrypted file.');
		fpassthru($output); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- intentional decrypted binary output
	} finally {
		fclose($input);
		fclose($output);
	}

}
