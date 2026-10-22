<?php
if (!defined('ABSPATH')) die('No direct access allowed.');

/** Read-only compatibility for legacy encrypted backup and token formats. */
class BackupPlus_Crypto {
	/**
	 * Existing ciphertext uses CBC with a 128-bit block and raw passphrase bytes.
	 * Preserve its key-size rounding, zero padding and truncation explicitly:
	 * phpseclib 3 requires exact key sizes instead of adjusting them implicitly.
	 *
	 * @param String      $key Raw encryption key or backup passphrase.
	 * @param String|null $iv  Token IV, or the existing backup format's zero IV.
	 * @return \phpseclib3\Crypt\Rijndael
	 */
	public static function rijndael($key, $iv = null) {
		$length = max(16, min(32, 4 * (int) ceil(strlen($key) / 4)));
		$cipher = new \phpseclib3\Crypt\Rijndael('cbc');
		$cipher->setKey(substr(str_pad($key, $length, "\0"), 0, $length));
		$cipher->setIV(null === $iv ? str_repeat("\0", 16) : $iv);
		return $cipher;
	}
}
