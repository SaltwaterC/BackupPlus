<?php
if (!defined('ABSPATH')) die('No direct access allowed.');

/** RFC 8439 setup and constant-time full-tag verification for phpseclib 3.0.57. */
class BackupPlus_Age_ChaCha20 extends \phpseclib3\Crypt\ChaCha20 {
	public function setTag($tag) {
		if (16 !== strlen($tag)) throw new \LengthException('Age requires a full 128-bit authentication tag.');
		// Initialize before setTag generates a key: 3.0.57 otherwise fails to set
		// usingGeneratedPoly1305Key and selects a different MAC construction.
		$this->setup();
		parent::setTag($tag);
	}

	public function decrypt($ciphertext) {
		$this->setup();
		if (false === $this->oldtag || !hash_equals($this->poly1305($ciphertext), $this->oldtag)) {
			throw new \phpseclib3\Exception\BadDecryptionException('Age authentication failed.');
		}
		// The parent now sees only an already-verified, full-length tag.
		return parent::decrypt($ciphertext);
	}
}
