<?php

if (!defined('ABSPATH')) die('No direct access allowed');
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
 * @param String $file Full path to file to decrypt
 * @param String $key  Key or salting to be used
 */
function rijndael_decrypt_file($file, $key) {

	require_once __DIR__.'/vendor/autoload.php';
	require_once __DIR__.'/includes/class-backupplus-crypto.php';
	$rijndael = BackupPlus_Crypto::rijndael($key);

	$ciphertext = file_get_contents($file);

	print $rijndael->decrypt($ciphertext);// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- intentional binary output

}
