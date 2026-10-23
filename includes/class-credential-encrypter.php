<?php

/** Authenticated, versioned encryption for locally stored credentials. */
class BackupPlus_Credential_Encrypter {
	const PREFIX     = 'bpcred:v1:';
	const KEY_SIZE   = 32;
	const NONCE_SIZE = 12;
	const TAG_SIZE   = 16;

	private $keys = array();
	private $active_key_id;
	private $context;

	/**
	 * Keys are base64-encoded random 32-byte secrets, stored outside the DB.
	 * The first-party runtime never reads the old shared-key CBC format.
	 */
	public function __construct( $keys, $active_key_id, $context ) {
		if ( ! is_array( $keys ) || ! $keys || ! is_string( $active_key_id ) || ! is_string( $context ) || '' === $context ) {
			throw new RuntimeException( 'The encryption key is unavailable. Open BackupPlus Settings to retry key setup or restore your recovery key.' );
		}
		foreach ( $keys as $id => $encoded ) {
			$key = is_string( $encoded ) ? base64_decode( $encoded, true ) : false;
			if ( ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/D', (string) $id ) || false === $key || self::KEY_SIZE !== strlen( $key ) || base64_encode( $key ) !== $encoded ) {
				throw new RuntimeException( 'The encryption key file is invalid. Restore your recovery key in BackupPlus Settings.' );
			}
			$this->keys[ $id ] = $key;
		}
		if ( ! isset( $this->keys[ $active_key_id ] ) ) {
			throw new RuntimeException( 'The active encryption key is missing. Restore your recovery key in BackupPlus Settings.' );
		}
		$this->active_key_id = $active_key_id;
		$this->context       = $context;
	}

	/** Use the automatically generated installation key file. */
	public static function from_config( $context ) {
		$config = BackupPlus_Credential_Key_Store::configuration();
		return new self( $config['keys'], $config['active'], $context );
	}

	/** ChaCha20-Poly1305 with a full authentication tag and a fresh random nonce. */
	public function encrypt( $plaintext ) {
		$this->ensure_crypto();
		$nonce      = \phpseclib3\Crypt\Random::string( self::NONCE_SIZE );
		$header     = self::PREFIX . $this->active_key_id . ':';
		$cipher     = $this->cipher( $this->active_key_id, $nonce, $header );
		$ciphertext = $cipher->encrypt( $plaintext );
		return $header . base64_encode( $nonce . $ciphertext . $cipher->getTag( self::TAG_SIZE ) );
	}

	/** Authenticate before returning any plaintext. Invalid/unknown formats fail closed. */
	public function decrypt( $ciphertext ) {
		if ( ! is_string( $ciphertext ) || ! preg_match( '/^bpcred:v1:([A-Za-z0-9_-]{1,64}):([A-Za-z0-9+\/=]+)$/D', $ciphertext, $parts ) ) {
			return false;
		}
		$id = $parts[1];
		if ( ! isset( $this->keys[ $id ] ) ) {
			return false;
		}
		$raw = base64_decode( $parts[2], true );
		if ( false === $raw || base64_encode( $raw ) !== $parts[2] || strlen( $raw ) < self::NONCE_SIZE + self::TAG_SIZE ) {
			return false;
		}
		try {
			$this->ensure_crypto();
			$cipher = $this->cipher( $id, substr( $raw, 0, self::NONCE_SIZE ), self::PREFIX . $id . ':' );
			$cipher->setTag( substr( $raw, -self::TAG_SIZE ) );
			return $cipher->decrypt( substr( $raw, self::NONCE_SIZE, -self::TAG_SIZE ) );
		} catch ( \Exception $e ) {
			return false;
		}
	}

	private function ensure_crypto() {
		global $backupplus;
		if ( is_wp_error( $backupplus->ensure_phpseclib() ) ) {
			throw new RuntimeException( 'Credential encryption is unavailable.' );
		}
	}

	private function cipher( $id, $nonce, $header ) {
		require_once __DIR__ . '/class-chacha20-poly1305.php';
		$cipher = new BackupPlus_ChaCha20_Poly1305();
		// Use the same tested native/portable engines as archive encryption.
		$openssl = function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' ) && in_array( 'chacha20', openssl_get_cipher_methods(), true );
		$cipher->setPreferredEngine( defined( 'BACKUPPLUS_ENCRYPTION_ENGINE' ) ? BACKUPPLUS_ENCRYPTION_ENGINE : ( $openssl ? 'OpenSSL' : 'PHP' ) );
		$cipher->enablePoly1305();
		$cipher->setCounter( 1 );
		$cipher->setKey( $this->keys[ $id ] );
		$cipher->setNonce( $nonce );
		$cipher->setAAD( $header . $this->context );
		return $cipher;
	}
}
