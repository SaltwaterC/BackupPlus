<?php
/**
 * SSH wire format for the disposable SSH server: message numbers, encoding helpers, the
 * sequential reader, host keys and client signature verification. Loaded by ../server-ssh.php.
 */

const SSH_MSG_DISCONNECT            = 1;
const SSH_MSG_IGNORE                = 2;
const SSH_MSG_UNIMPLEMENTED         = 3;
const SSH_MSG_DEBUG                 = 4;
const SSH_MSG_SERVICE_REQUEST       = 5;
const SSH_MSG_SERVICE_ACCEPT        = 6;
const SSH_MSG_KEXINIT               = 20;
const SSH_MSG_NEWKEYS               = 21;
const SSH_MSG_KEX_ECDH_INIT         = 30;
const SSH_MSG_KEX_ECDH_REPLY        = 31;
const SSH_MSG_USERAUTH_REQUEST      = 50;
const SSH_MSG_USERAUTH_FAILURE      = 51;
const SSH_MSG_USERAUTH_SUCCESS      = 52;
const SSH_MSG_USERAUTH_PK_OK        = 60;
const SSH_MSG_GLOBAL_REQUEST        = 80;
const SSH_MSG_REQUEST_FAILURE       = 82;
const SSH_MSG_CHANNEL_OPEN          = 90;
const SSH_MSG_CHANNEL_OPEN_CONFIRM  = 91;
const SSH_MSG_CHANNEL_OPEN_FAILURE  = 92;
const SSH_MSG_CHANNEL_WINDOW_ADJUST = 93;
const SSH_MSG_CHANNEL_DATA          = 94;
const SSH_MSG_CHANNEL_EXTENDED_DATA = 95;
const SSH_MSG_CHANNEL_EOF           = 96;
const SSH_MSG_CHANNEL_CLOSE         = 97;
const SSH_MSG_CHANNEL_REQUEST       = 98;
const SSH_MSG_CHANNEL_SUCCESS       = 99;
const SSH_MSG_CHANNEL_FAILURE       = 100;

function ssh_log( $line ) {
	if ( getenv( 'BACKUPPLUS_TEST_SERVER_DEBUG' ) ) {
		fwrite( STDERR, $line . "\n" );
	}
}

function ssh_string( $value ) {
	return pack( 'N', strlen( $value ) ) . $value;
}

/** Encode a big-endian unsigned integer as an SSH mpint. */
function ssh_mpint( $binary ) {
	$binary = ltrim( $binary, "\0" );
	if ( '' !== $binary && ord( $binary[0] ) & 0x80 ) {
		$binary = "\0" . $binary;
	}
	return ssh_string( $binary );
}

function ssh_list( $names ) {
	return ssh_string( implode( ',', $names ) );
}

/** Add a block count to a big-endian 128-bit AES-CTR counter. */
function ssh_counter_add( $counter, $blocks ) {
	for ( $index = 15; $index >= 0 && $blocks > 0; $index-- ) {
		$sum               = ord( $counter[ $index ] ) + ( $blocks & 255 );
		$counter[ $index ] = chr( $sum & 255 );
		$blocks            = ( $blocks >> 8 ) + ( $sum >> 8 );
	}
	return $counter;
}

function ssh_der( $tag, $content ) {
	$length = strlen( $content );
	if ( $length < 128 ) {
		return chr( $tag ) . chr( $length ) . $content;
	}
	$bytes = ltrim( pack( 'N', $length ), "\0" );
	return chr( $tag ) . chr( 0x80 | strlen( $bytes ) ) . $bytes . $content;
}

/** Sequential reader for SSH wire-format data. */
class Ssh_Reader {
	private $data;
	private $offset = 0;

	public function __construct( $data ) {
		$this->data = $data;
	}

	private function take( $length ) {
		if ( $length < 0 || $this->offset + $length > strlen( $this->data ) ) {
			throw new RuntimeException( 'Truncated SSH message' );
		}
		$value         = substr( $this->data, $this->offset, $length );
		$this->offset += $length;
		return $value;
	}

	public function byte() {
		return ord( $this->take( 1 ) );
	}

	public function boolean() {
		return 0 !== $this->byte();
	}

	public function uint32() {
		return unpack( 'N', $this->take( 4 ) )[1];
	}

	public function uint64() {
		return unpack( 'J', $this->take( 8 ) )[1];
	}

	public function string() {
		return $this->take( $this->uint32() );
	}

	public function names() {
		$list = $this->string();
		return '' === $list ? array() : explode( ',', $list );
	}

	public function skip( $length ) {
		$this->take( $length );
	}
}

class Ssh_Host_Key {
	public $blob;
	public $fingerprint;
	public $algorithms;
	private $private;

	public static function rsa() {
		$key             = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		$rsa             = openssl_pkey_get_details( $key )['rsa'];
		$new             = new self();
		$new->private    = $key;
		$new->blob       = ssh_string( 'ssh-rsa' ) . ssh_mpint( $rsa['e'] ) . ssh_mpint( $rsa['n'] );
		$new->algorithms = array( 'rsa-sha2-512', 'rsa-sha2-256', 'ssh-rsa' );
		return $new->finish();
	}

	public static function ed25519() {
		$pair            = sodium_crypto_sign_keypair();
		$new             = new self();
		$new->private    = sodium_crypto_sign_secretkey( $pair );
		$new->blob       = ssh_string( 'ssh-ed25519' ) . ssh_string( sodium_crypto_sign_publickey( $pair ) );
		$new->algorithms = array( 'ssh-ed25519' );
		return $new->finish();
	}

	private function finish() {
		$this->fingerprint = 'SHA256:' . rtrim( base64_encode( hash( 'sha256', $this->blob, true ) ), '=' );
		return $this;
	}

	/** Signature blob for the negotiated host key algorithm. */
	public function sign( $data, $algorithm ) {
		if ( 'ssh-ed25519' === $algorithm ) {
			return ssh_string( $algorithm ) . ssh_string( sodium_crypto_sign_detached( $data, $this->private ) );
		}
		$hashes = array(
			'rsa-sha2-512' => OPENSSL_ALGO_SHA512,
			'rsa-sha2-256' => OPENSSL_ALGO_SHA256,
			'ssh-rsa'      => OPENSSL_ALGO_SHA1,
		);
		openssl_sign( $data, $signature, $this->private, $hashes[ $algorithm ] );
		return ssh_string( $algorithm ) . ssh_string( $signature );
	}
}

/** Verify a client public key signature for the algorithms the tests use. */
function ssh_verify_client( $blob, $signature_blob, $data ) {
	$key       = new Ssh_Reader( $blob );
	$type      = $key->string();
	$signature = new Ssh_Reader( $signature_blob );
	$algorithm = $signature->string();
	$raw       = $signature->string();
	if ( 'ssh-ed25519' === $type && 'ssh-ed25519' === $algorithm ) {
		return sodium_crypto_sign_verify_detached( $raw, $data, $key->string() );
	}
	$hashes = array(
		'rsa-sha2-512' => OPENSSL_ALGO_SHA512,
		'rsa-sha2-256' => OPENSSL_ALGO_SHA256,
		'ssh-rsa'      => OPENSSL_ALGO_SHA1,
	);
	if ( 'ssh-rsa' !== $type || ! isset( $hashes[ $algorithm ] ) ) {
		return false;
	}
	$exponent = $key->string();
	$modulus  = $key->string();
	$spki     = ssh_der(
		0x30,
		ssh_der( 0x30, ssh_der( 0x06, "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" ) . "\x05\x00" ) .
		ssh_der( 0x03, "\0" . ssh_der( 0x30, ssh_der( 0x02, $modulus ) . ssh_der( 0x02, $exponent ) ) )
	);
	$pem      = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $spki ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	return 1 === openssl_verify( $data, $raw, $pem, $hashes[ $algorithm ] );
}
