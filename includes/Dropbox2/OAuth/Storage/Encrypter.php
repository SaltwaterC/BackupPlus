<?php

/**
 * This class provides the functionality to encrypt
 * and decrypt access tokens stored by the application
 * @author Ben Tadiar <ben@handcraftedbyben.co.uk>
 * @link https://github.com/benthedesigner/dropbox
 * @package Dropbox\Oauth
 * @subpackage Storage
 */

/* BackupPlus: token ciphertext retains its original Rijndael-CBC format,
 * including the 16-byte IV prefix and PKCS#7 padding, using phpseclib 3. */

class Dropbox_Encrypter
{    
    // Encryption settings - default settings yield encryption to AES (256-bit) standard
    // @todo Provide PHPDOC for each class constant
    const KEY_SIZE = 32;
    const IV_SIZE = 16;
    
    /**
     * Encryption key
     * @var null|string
     */
    private $key = null;
    
    /**
     * Validate and set the raw encryption key
     * @param string $key
     * @return void
     */
    public function __construct($key)
    {
        if (preg_match('/^[A-Za-z0-9]+$/', $key) && $length = strlen($key) === self::KEY_SIZE) {
            # Short-cut so that the mbstring extension is not required
            $this->key = $key;
        } elseif (($length = mb_strlen($key, '8bit')) !== self::KEY_SIZE) {
            throw new Dropbox_Exception('Expecting a ' .  self::KEY_SIZE . ' byte key, got ' . $length); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The escaping should happen when the exception is caught and printed
        } else {
            // Set the encryption key
            $this->key = $key;
        }
    }
    
    /**
     * Encrypt the OAuth token
     * @param \stdClass $token Serialized token object
     * @return string
     */
    public function encrypt($token)
    {

        // Encryption: we always use phpseclib for this
        global $backupplus;
        $ensure_phpseclib = $backupplus->ensure_phpseclib();
        
        if (is_wp_error($ensure_phpseclib)) {
            $backupplus->log("Failed to load phpseclib classes (".$ensure_phpseclib->get_error_code()."): ".$ensure_phpseclib->get_error_message());
            $backupplus->log("Failed to load phpseclib classes (".$ensure_phpseclib->get_error_code()."): ".$ensure_phpseclib->get_error_message(), 'error');
            return false;
        }
        
        $iv = \phpseclib3\Crypt\Random::string(self::IV_SIZE);
        
        $rijndael = BackupPlus_Crypto::rijndael($this->key, $iv);

        $cipherText = $rijndael->encrypt($token);
        
        return base64_encode($iv . $cipherText);
    }
    
    /**
     * Decrypt the ciphertext
     * @param string $cipherText
     * @return object \stdClass Unserialized token
     */
    public function decrypt($cipherText)
    {

		global $backupplus;
        $cipherText = base64_decode($cipherText, true);
        if (false === $cipherText || strlen($cipherText) <= self::IV_SIZE || (strlen($cipherText) - self::IV_SIZE) % 16) return false;
        $iv = substr($cipherText, 0, self::IV_SIZE);
        $cipherText = substr($cipherText, self::IV_SIZE);
            
		if (is_wp_error($backupplus->ensure_phpseclib())) return false;

		$rijndael = BackupPlus_Crypto::rijndael($this->key, $iv);
        try {
            $token = $rijndael->decrypt($cipherText);
        } catch (\Exception $e) {
            return false;
        }
        
        return $token;
    }
}
