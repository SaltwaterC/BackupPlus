<?php
if (!defined('ABSPATH')) die('No direct access allowed.');

/** age v1 X25519 and SSH recipient wrapping. No identities are persisted. */
class BackupPlus_Age_Keys {
	const X25519_LABEL = 'age-encryption.org/v1/X25519';
	const ED25519_LABEL = 'age-encryption.org/v1/ssh-ed25519';
	const RSA_LABEL = 'age-encryption.org/v1/ssh-rsa';

	/** Parse and normalize a public recipient; private keys never pass this parser. */
	public static function recipient($text) {
		$text = trim($text);
		if (strlen($text) > 4096 || false !== strpbrk($text, "\r\n")) throw new \RuntimeException('Invalid public recipient line.');
		if (0 === strpos(strtolower($text), 'age1')) {
			$raw = self::bech32_decode($text, 'age');
			return array('type' => 'X25519', 'public' => $raw, 'text' => strtolower($text));
		}
		if (!preg_match('/\A(ssh-rsa|ssh-ed25519)[ \t]+([A-Za-z0-9+\/=]+)(?:[ \t].*)?\z/D', $text, $parts)) throw new \RuntimeException('Use a native age X25519 or SSH RSA/Ed25519 public key.');
		$key = \phpseclib3\Crypt\PublicKeyLoader::loadPublicKey($parts[1].' '.$parts[2]);
		$canonical = preg_split('/\s+/', $key->toString('OpenSSH'));
		$wire = base64_decode($canonical[1], true);
		if ($parts[1] !== $canonical[0] || base64_decode($parts[2], true) !== $wire) throw new \RuntimeException('Invalid SSH public key.');
		if ('ssh-rsa' === $parts[1]) {
			if (!($key instanceof \phpseclib3\Crypt\RSA\PublicKey) || $key->getLength() < 2048) throw new \RuntimeException('SSH RSA keys must contain at least 2048 bits.');
			$public = null;
		} else {
			if (!($key instanceof \phpseclib3\Crypt\EC\PublicKey) || 'Ed25519' !== $key->getCurve()) throw new \RuntimeException('Invalid SSH Ed25519 key.');
			$public = self::edwards_to_montgomery($key->toString('libsodium'));
		}
		return array('type' => $parts[1], 'key' => $key, 'public' => $public, 'wire' => $wire, 'hint' => self::base64(substr(hash('sha256', $wire, true), 0, 4)), 'text' => $canonical[0].' '.$canonical[1]);
	}

	/** Import a request-only native age identity or encrypted OpenSSH/PEM key. */
	public static function identity($text, $passphrase = '') {
		$text = trim(preg_replace('/^#[^\r\n]*(?:\r?\n|$)/m', '', $text));
		if (0 === strpos(strtoupper($text), 'AGE-SECRET-KEY-1')) {
			$key = \phpseclib3\Crypt\EC::loadFormat('MontgomeryPrivate', self::bech32_decode($text, 'age-secret-key-'));
			return array('type' => 'X25519', 'key' => $key, 'public' => $key->getPublicKey()->toString('MontgomeryPublic'));
		}
		$key = \phpseclib3\Crypt\PublicKeyLoader::loadPrivateKey($text, $passphrase);
		if ($key instanceof \phpseclib3\Crypt\EC\PrivateKey && 'Curve25519' === $key->getCurve()) {
			return array('type' => 'X25519', 'key' => $key, 'public' => $key->getPublicKey()->toString('MontgomeryPublic'));
		}
		$recipient = self::recipient($key->getPublicKey()->toString('OpenSSH'));
		$recipient['key'] = $key;
		if ('ssh-ed25519' === $recipient['type']) {
			// RFC 8032 Ed25519 seed expansion; X25519 clamps the resulting scalar.
			$seed = substr($key->withPassword('')->toString('libsodium'), 0, 32);
			$recipient['key'] = \phpseclib3\Crypt\EC::loadFormat('MontgomeryPrivate', substr(hash('sha512', $seed, true), 0, 32));
		}
		return $recipient;
	}

	public static function wrap($recipient, $file_key) {
		$type = $recipient['type'];
		if ('ssh-rsa' === $type) {
			$body = self::rsa($recipient['key'])->encrypt($file_key);
			return array('type' => $type, 'args' => array($recipient['hint']), 'body' => $body);
		}
		$ephemeral = \phpseclib3\Crypt\EC::loadFormat('MontgomeryPrivate', \phpseclib3\Crypt\Random::string(32));
		$share = $ephemeral->getPublicKey()->toString('MontgomeryPublic');
		$shared = self::shared($ephemeral, $recipient['public']);
		$args = array(self::base64($share));
		$label = self::X25519_LABEL;
		if ('ssh-ed25519' === $type) {
			$label = self::ED25519_LABEL;
			$shared = self::tweak($shared, $recipient['wire']);
			array_unshift($args, $recipient['hint']);
		}
		$key = self::hkdf($shared, $share.$recipient['public'], $label);
		return array('type' => $type, 'args' => $args, 'body' => self::aead($file_key, $key, str_repeat("\0", 12)));
	}

	/** Non-matching recipients return false; malformed recognized stanzas fail. */
	public static function unwrap($identity, $stanza) {
		if ($identity['type'] !== $stanza['type']) return false;
		$args = $stanza['args'];
		$type = $stanza['type'];
		if ('ssh-rsa' === $type) {
			if (1 !== count($args)) throw new \RuntimeException('Invalid age RSA stanza.');
			if ($args[0] !== $identity['hint']) return false;
			return self::rsa($identity['key'])->decrypt($stanza['body']);
		}
		$expected = 'X25519' === $type ? 1 : 2;
		if (count($args) !== $expected || 32 !== strlen($stanza['body'])) throw new \RuntimeException('Invalid age recipient stanza.');
		if (2 === $expected && $args[0] !== $identity['hint']) return false;
		$share = self::unbase64($args[$expected - 1]);
		if (32 !== strlen($share)) throw new \RuntimeException('Invalid age ephemeral key.');
		$shared = self::shared($identity['key'], $share);
		$label = self::X25519_LABEL;
		if (2 === $expected) {
			$shared = self::tweak($shared, $identity['wire']);
			$label = self::ED25519_LABEL;
		}
		$key = self::hkdf($shared, $share.$identity['public'], $label);
		return self::aead($stanza['body'], $key, str_repeat("\0", 12), true);
	}

	private static function rsa($key) {
		return $key->withPadding(\phpseclib3\Crypt\RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256')->withLabel(self::RSA_LABEL);
	}

	private static function shared($private, $public) {
		if (32 !== strlen($public)) throw new \RuntimeException('Invalid X25519 public key.');
		$result = \phpseclib3\Crypt\DH::computeSecret($private, \phpseclib3\Crypt\EC::loadFormat('MontgomeryPublic', $public));
		if (32 !== strlen($result) || hash_equals(str_repeat("\0", 32), $result)) throw new \RuntimeException('Invalid X25519 shared secret.');
		return $result;
	}

	private static function tweak($shared, $wire) {
		$tweak = \phpseclib3\Crypt\EC::loadFormat('MontgomeryPrivate', self::hkdf('', $wire, self::ED25519_LABEL));
		return self::shared($tweak, $shared);
	}

	private static function edwards_to_montgomery($encoded) {
		if (32 !== strlen($encoded)) throw new \RuntimeException('Invalid Ed25519 public key.');
		$encoded[31] = chr(ord($encoded[31]) & 127);
		$y = new \phpseclib3\Math\BigInteger(strrev($encoded), 256);
		$p = new \phpseclib3\Math\BigInteger('7fffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffed', 16);
		$one = new \phpseclib3\Math\BigInteger(1);
		$inverse = $one->subtract($y)->modInverse($p);
		if (false === $inverse) throw new \RuntimeException('Invalid Ed25519 public key.');
		list(, $u) = $one->add($y)->multiply($inverse)->divide($p);
		return strrev(str_pad($u->toBytes(), 32, "\0", STR_PAD_LEFT));
	}

	/** RFC 5869 SHA-256, 32-byte output. */
	public static function hkdf($ikm, $salt, $info) {
		return hash_hmac('sha256', $info."\x01", hash_hmac('sha256', $ikm, $salt, true), true);
	}

	/** RFC 8439 AEAD, 128-bit tags, empty additional data. */
	public static function aead($data, $key, $nonce, $decrypt = false) {
		require_once __DIR__.'/class-age-chacha20.php';
		$cipher = new BackupPlus_Age_ChaCha20();
		// Select a tested engine explicitly, including phpseclib's portable path.
		$openssl = function_exists('openssl_encrypt') && in_array('chacha20', openssl_get_cipher_methods(), true);
		$cipher->setPreferredEngine(defined('BACKUPPLUS_ENCRYPTION_ENGINE') ? BACKUPPLUS_ENCRYPTION_ENGINE : ($openssl ? 'OpenSSL' : 'PHP'));
		$cipher->enablePoly1305();
		$cipher->setCounter(1);
		$cipher->setKey($key);
		$cipher->setNonce($nonce);
		if ($decrypt) {
			if (strlen($data) < 16) throw new \RuntimeException('Truncated age ciphertext.');
			$cipher->setTag(substr($data, -16));
			return $cipher->decrypt(substr($data, 0, -16));
		}
		$output = $cipher->encrypt($data);
		return $output.$cipher->getTag(16);
	}

	public static function base64($raw) { return rtrim(base64_encode($raw), '='); }
	public static function unbase64($text) {
		$raw = base64_decode($text, true);
		if (false === $raw || self::base64($raw) !== $text) throw new \RuntimeException('Invalid age base64.');
		return $raw;
	}

	/** BIP 173 Bech32 with exact 32-byte key payloads and strict padding. */
	public static function bech32_decode($text, $hrp) {
		if (strlen($text) > 100 || (strtolower($text) !== $text && strtoupper($text) !== $text)) throw new \RuntimeException('Invalid age key encoding.');
		$text = strtolower($text);
		$pos = strrpos($text, '1');
		if (false === $pos || substr($text, 0, $pos) !== $hrp) throw new \RuntimeException('Invalid age key prefix.');
		$alphabet = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
		$values = array();
		for ($i = $pos + 1; $i < strlen($text); $i++) {
			$value = strpos($alphabet, $text[$i]);
			if (false === $value) throw new \RuntimeException('Invalid age key character.');
			$values[] = $value;
		}
		$expanded = array();
		for ($i = 0; $i < strlen($hrp); $i++) $expanded[] = ord($hrp[$i]) >> 5;
		$expanded[] = 0;
		for ($i = 0; $i < strlen($hrp); $i++) $expanded[] = ord($hrp[$i]) & 31;
		$check = 1;
		$generators = array(0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3);
		foreach (array_merge($expanded, $values) as $value) {
			$top = $check >> 25;
			$check = (($check & 0x1ffffff) << 5) ^ $value;
			for ($i = 0; $i < 5; $i++) if (($top >> $i) & 1) $check ^= $generators[$i];
		}
		if (1 !== $check || count($values) !== 58) throw new \RuntimeException('Invalid age key checksum or length.');
		$acc = 0;
		$bits = 0;
		$result = '';
		foreach (array_slice($values, 0, -6) as $value) {
			$acc = (($acc << 5) | $value) & 4095;
			$bits += 5;
			if ($bits >= 8) { $bits -= 8; $result .= chr(($acc >> $bits) & 255); }
		}
		if (32 !== strlen($result) || $bits >= 5 || (($acc << (8 - $bits)) & 255)) throw new \RuntimeException('Invalid age key padding.');
		return $result;
	}
}
