<?php
/**
 * Disposable loopback SSH server for the SFTP/SCP storage tests.
 *
 * Usage: php server-ssh.php '{"root": "/login", "keys": ["/client.pub"], "servers": {"sftp": {"shell": false, "host": "rsa"}}}'
 * A "shell" server runs exec requests with sh in the root directory and rejects the sftp
 * subsystem; the other servers speak SFTP version 3 and reject exec. The password is
 * " test password " for the user "test"; only the listed public keys are accepted. Host
 * keys are generated for the run. This is not a sandbox: only use it with the test client.
 *
 * Cryptography is deliberately independent of phpseclib: curve25519-sha256 key exchange
 * (sodium), aes128-ctr/aes256-ctr (OpenSSL) and hmac-sha2-256, with RSA or Ed25519 host keys.
 */
require_once __DIR__ . '/event-loop.php';

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

/** SFTP version 3 over an arbitrary byte stream, confined to a root directory. */
class Ssh_Sftp {
	private $root;
	private $buffer  = '';
	private $handles = array();
	private $counter = 0;

	public function __construct( $root ) {
		$this->root = $root;
	}

	public function feed( $data ) {
		$this->buffer .= $data;
		$out           = '';
		while ( strlen( $this->buffer ) >= 4 ) {
			$length = unpack( 'N', $this->buffer )[1];
			if ( $length > 4194304 ) {
				$this->buffer = '';
				break;
			}
			if ( strlen( $this->buffer ) < 4 + $length ) {
				break;
			}
			$packet       = substr( $this->buffer, 4, $length );
			$this->buffer = (string) substr( $this->buffer, 4 + $length );
			try {
				$out .= $this->handle( $packet );
			} catch ( Throwable $error ) {
				$out .= $this->status( strlen( $packet ) >= 5 ? unpack( 'N', substr( $packet, 1, 4 ) )[1] : 0, 4, $error->getMessage() );
			}
		}
		return $out;
	}

	public function close() {
		foreach ( $this->handles as $handle ) {
			if ( is_resource( $handle['stream'] ) ) {
				fclose( $handle['stream'] );
			}
		}
		$this->handles = array();
	}

	private function packet( $type, $body ) {
		return pack( 'N', 1 + strlen( $body ) ) . chr( $type ) . $body;
	}

	private function status( $id, $code, $message = '' ) {
		return $this->packet( 101, pack( 'NN', $id, $code ) . ssh_string( $message ) . ssh_string( 'en' ) );
	}

	/** Map the last PHP filesystem warning to an SFTP status code. */
	private function failure( $id ) {
		$message = (string) ( error_get_last()['message'] ?? '' );
		if ( false !== stripos( $message, 'permission denied' ) ) {
			return $this->status( $id, 3, 'Permission denied' );
		}
		if ( false !== stripos( $message, 'no such file' ) ) {
			return $this->status( $id, 2, 'No such file' );
		}
		return $this->status( $id, 4, 'Failure' );
	}

	/** Join to the root and normalise lexically; anything outside the root is refused. */
	private function resolve( $path ) {
		$full  = '/' === substr( $path, 0, 1 ) ? $path : $this->root . '/' . $path;
		$parts = array();
		foreach ( explode( '/', $full ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				array_pop( $parts );
			} else {
				$parts[] = $part;
			}
		}
		$result = '/' . implode( '/', $parts );
		return $result === $this->root || 0 === strpos( $result, $this->root . '/' ) ? $result : false;
	}

	private function attributes( $stat ) {
		return pack( 'N', 0x0f ) . pack( 'J', $stat['size'] ) . pack( 'NN', $stat['uid'], $stat['gid'] ) . pack( 'N', $stat['mode'] ) . pack( 'NN', $stat['atime'], $stat['mtime'] );
	}

	/** Skip an ATTRS structure, returning the permissions if present. */
	private function skip_attributes( Ssh_Reader $reader ) {
		$flags = $reader->uint32();
		if ( $flags & 0x01 ) {
			$reader->uint64();
		}
		if ( $flags & 0x02 ) {
			$reader->uint32();
			$reader->uint32();
		}
		if ( $flags & 0x04 ) {
			$reader->uint32();
		}
		if ( $flags & 0x08 ) {
			$reader->uint32();
			$reader->uint32();
		}
		if ( $flags & 0x80000000 ) {
			for ( $count = $reader->uint32(); $count > 0; $count-- ) {
				$reader->string();
				$reader->string();
			}
		}
	}

	private function new_handle( $entry ) {
		$handle                   = pack( 'N', ++$this->counter );
		$this->handles[ $handle ] = $entry;
		return $handle;
	}

	private function handle( $packet ) {
		$reader = new Ssh_Reader( $packet );
		$type   = $reader->byte();
		if ( 1 === $type ) {
			return $this->packet( 2, pack( 'N', 3 ) );
		}
		$id = $reader->uint32();
		switch ( $type ) {
			case 3: // OPEN.
				$path  = $this->resolve( $reader->string() );
				$flags = $reader->uint32();
				if ( false === $path ) {
					return $this->status( $id, 3, 'Outside test directory' );
				}
				error_clear_last();
				$stream = $this->open_file( $path, $flags );
				return $stream ? $this->packet( 102, pack( 'N', $id ) . ssh_string( $this->new_handle( array( 'stream' => $stream ) ) ) ) : $this->failure( $id );
			case 4: // CLOSE.
				$handle = $reader->string();
				if ( isset( $this->handles[ $handle ] ) ) {
					if ( is_resource( $this->handles[ $handle ]['stream'] ?? null ) ) {
						fclose( $this->handles[ $handle ]['stream'] );
					}
					unset( $this->handles[ $handle ] );
				}
				return $this->status( $id, 0, 'OK' );
			case 5: // READ.
				$handle = $reader->string();
				$offset = $reader->uint64();
				$length = min( $reader->uint32(), 262144 );
				if ( ! isset( $this->handles[ $handle ]['stream'] ) ) {
					return $this->status( $id, 4, 'Invalid handle' );
				}
				$stream = $this->handles[ $handle ]['stream'];
				fseek( $stream, $offset );
				$data = $length > 0 ? fread( $stream, $length ) : '';
				return '' === $data || false === $data ? $this->status( $id, 1, 'EOF' ) : $this->packet( 103, pack( 'N', $id ) . ssh_string( $data ) );
			case 6: // WRITE.
				$handle = $reader->string();
				$offset = $reader->uint64();
				$data   = $reader->string();
				if ( ! isset( $this->handles[ $handle ]['stream'] ) ) {
					return $this->status( $id, 4, 'Invalid handle' );
				}
				$stream = $this->handles[ $handle ]['stream'];
				fseek( $stream, $offset );
				return strlen( $data ) === fwrite( $stream, $data ) ? $this->status( $id, 0, 'OK' ) : $this->status( $id, 4, 'Write failed' );
			case 7: // LSTAT.
			case 17: // STAT.
				$path = $this->resolve( $reader->string() );
				if ( false === $path ) {
					return $this->status( $id, 3, 'Outside test directory' );
				}
				$stat = 7 === $type ? @lstat( $path ) : @stat( $path );
				return $stat ? $this->packet( 105, pack( 'N', $id ) . $this->attributes( $stat ) ) : $this->status( $id, 2, 'No such file' );
			case 8: // FSTAT.
				$handle = $reader->string();
				$stat   = isset( $this->handles[ $handle ]['stream'] ) ? fstat( $this->handles[ $handle ]['stream'] ) : false;
				return $stat ? $this->packet( 105, pack( 'N', $id ) . $this->attributes( $stat ) ) : $this->status( $id, 4, 'Invalid handle' );
			case 11: // OPENDIR.
				$path = $this->resolve( $reader->string() );
				if ( false === $path ) {
					return $this->status( $id, 3, 'Outside test directory' );
				}
				$names = is_dir( $path ) ? @scandir( $path ) : false;
				if ( false === $names ) {
					return is_dir( $path ) ? $this->status( $id, 3, 'Permission denied' ) : $this->status( $id, 2, 'No such directory' );
				}
				return $this->packet(
					102,
					pack( 'N', $id ) . ssh_string(
						$this->new_handle(
							array(
								'stream'  => null,
								'path'    => $path,
								'entries' => array_values( array_diff( $names, array( '.', '..' ) ) ),
							)
						)
					)
				);
			case 12: // READDIR.
				$handle = $reader->string();
				if ( ! isset( $this->handles[ $handle ]['entries'] ) ) {
					return $this->status( $id, 4, 'Invalid handle' );
				}
				$names = array_splice( $this->handles[ $handle ]['entries'], 0, 100 );
				if ( ! $names ) {
					return $this->status( $id, 1, 'EOF' );
				}
				$body = pack( 'NN', $id, count( $names ) );
				foreach ( $names as $name ) {
					$stat  = @lstat( $this->handles[ $handle ]['path'] . '/' . $name );
					$body .= ssh_string( $name ) . ssh_string( $name ) . ( $stat ? $this->attributes( $stat ) : pack( 'N', 0 ) );
				}
				return $this->packet( 104, $body );
			case 13: // REMOVE.
				$path = $this->resolve( $reader->string() );
				if ( false === $path ) {
					return $this->status( $id, 3, 'Outside test directory' );
				}
				if ( ! file_exists( $path ) && ! is_link( $path ) ) {
					return $this->status( $id, 2, 'No such file' );
				}
				error_clear_last();
				return @unlink( $path ) ? $this->status( $id, 0, 'OK' ) : $this->failure( $id );
			case 16: // REALPATH.
				$path = $this->resolve( $reader->string() );
				return false === $path ? $this->status( $id, 4, 'Outside test directory' ) : $this->packet( 104, pack( 'NN', $id, 1 ) . ssh_string( $path ) . ssh_string( $path ) . pack( 'N', 0 ) );
		}
		// Like the original fixture, everything else (chmod, mkdir, rename, ...) is unsupported.
		return $this->status( $id, 8, 'Operation unsupported' );
	}

	private function open_file( $path, $flags ) {
		$read   = (bool) ( $flags & 1 );
		$write  = (bool) ( $flags & 2 );
		$append = (bool) ( $flags & 4 );
		$create = (bool) ( $flags & 8 );
		$trunc  = (bool) ( $flags & 16 );
		$excl   = (bool) ( $flags & 32 );
		if ( ! $write ) {
			return @fopen( $path, 'rb' );
		}
		if ( $append ) {
			$mode = $read ? 'a+b' : 'ab';
		} elseif ( $create && $excl ) {
			$mode = $read ? 'x+b' : 'xb';
		} elseif ( $create ) {
			$mode = $read ? 'c+b' : 'cb';
		} else {
			$mode = 'r+b';
		}
		$stream = @fopen( $path, $mode );
		if ( $stream && $trunc ) {
			ftruncate( $stream, 0 );
		}
		return $stream;
	}
}

class Ssh_Session {
	private $connection;
	private $loop;
	private $server;
	private $version       = null;
	private $server_ident  = 'SSH-2.0-BackupPlusTest';
	private $seq_in        = 0;
	private $seq_out       = 0;
	private $cipher_in     = null;
	private $cipher_out    = null;
	private $head          = null;
	private $kexinit_in    = '';
	private $kexinit_out   = '';
	private $negotiated    = array();
	private $session_id    = null;
	private $keys          = array();
	private $authenticated = false;
	private $channels      = array();
	private $next_channel  = 0;
	private $closed        = false;

	public function __construct( $loop, $stream, $server ) {
		$this->loop       = $loop;
		$this->server     = $server;
		$this->connection = new Integration_Connection( $loop, $stream, array( $this, 'receive' ), array( $this, 'closed' ) );
		$this->connection->send( $this->server_ident . "\r\n" );
		$this->send_kexinit();
	}

	private function send_kexinit() {
		$host_algorithms   = $this->server['host']->algorithms;
		$payload           = chr( SSH_MSG_KEXINIT ) . random_bytes( 16 ) .
			ssh_list( array( 'curve25519-sha256', 'curve25519-sha256@libssh.org' ) ) .
			ssh_list( $host_algorithms ) .
			ssh_list( array( 'aes128-ctr', 'aes256-ctr' ) ) . ssh_list( array( 'aes128-ctr', 'aes256-ctr' ) ) .
			ssh_list( array( 'hmac-sha2-256' ) ) . ssh_list( array( 'hmac-sha2-256' ) ) .
			ssh_list( array( 'none' ) ) . ssh_list( array( 'none' ) ) .
			ssh_list( array() ) . ssh_list( array() ) . "\0" . pack( 'N', 0 );
		$this->kexinit_out = $payload;
		$this->send_packet( $payload );
	}

	public function receive() {
		if ( null === $this->version ) {
			while ( false !== ( $newline = strpos( $this->connection->in, "\n" ) ) ) {
				$line                 = rtrim( substr( $this->connection->in, 0, $newline ), "\r" );
				$this->connection->in = (string) substr( $this->connection->in, $newline + 1 );
				if ( 0 === strpos( $line, 'SSH-' ) ) {
					$this->version = $line;
					break;
				}
			}
			if ( null === $this->version ) {
				return;
			}
		}
		while ( ! $this->closed ) {
			try {
				$payload = $this->read_packet();
				if ( null === $payload ) {
					return;
				}
				$this->dispatch( $payload );
			} catch ( Throwable $error ) {
				$this->server['state']->failures[] = $error->getMessage();
				$this->connection->close();
				return;
			}
		}
	}

	public function closed() {
		$this->closed = true;
		foreach ( $this->channels as $id => $channel ) {
			$this->release_channel( $id );
		}
	}

	private function crypt( &$state, $data ) {
		if ( '' === $data ) {
			return '';
		}
		$output           = openssl_encrypt( $data, $state['cipher'], $state['key'], OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $state['counter'] );
		$state['counter'] = ssh_counter_add( $state['counter'], strlen( $data ) / 16 );
		return $output;
	}

	private function read_packet() {
		$in = &$this->connection->in;
		if ( $this->cipher_in ) {
			if ( null === $this->head ) {
				if ( strlen( $in ) < 16 ) {
					return null;
				}
				$this->head = $this->crypt( $this->cipher_in, substr( $in, 0, 16 ) );
			}
			$length = unpack( 'N', $this->head )[1];
			if ( $length > 262144 || $length < 12 ) {
				throw new RuntimeException( 'Invalid SSH packet length' );
			}
			$total = 4 + $length;
			if ( strlen( $in ) < $total + 32 ) {
				return null;
			}
			$plain      = $this->head . $this->crypt( $this->cipher_in, (string) substr( $in, 16, $total - 16 ) );
			$mac        = substr( $in, $total, 32 );
			$in         = (string) substr( $in, $total + 32 );
			$this->head = null;
			if ( ! hash_equals( hash_hmac( 'sha256', pack( 'N', $this->seq_in ) . $plain, $this->cipher_in['mac'], true ), $mac ) ) {
				throw new RuntimeException( 'SSH MAC verification failed' );
			}
		} else {
			if ( strlen( $in ) < 5 ) {
				return null;
			}
			$length = unpack( 'N', $in )[1];
			if ( $length > 262144 || $length < 6 ) {
				throw new RuntimeException( 'Invalid SSH packet length' );
			}
			if ( strlen( $in ) < 4 + $length ) {
				return null;
			}
			$plain = substr( $in, 0, 4 + $length );
			$in    = (string) substr( $in, 4 + $length );
		}
		++$this->seq_in;
		return substr( $plain, 5, $length - ord( $plain[4] ) - 1 );
	}

	private function send_packet( $payload ) {
		$block   = $this->cipher_out ? 16 : 8;
		$padding = $block - ( ( 5 + strlen( $payload ) ) % $block );
		if ( $padding < 4 ) {
			$padding += $block;
		}
		$plain = pack( 'N', 1 + strlen( $payload ) + $padding ) . chr( $padding ) . $payload . random_bytes( $padding );
		if ( $this->cipher_out ) {
			$mac   = hash_hmac( 'sha256', pack( 'N', $this->seq_out ) . $plain, $this->cipher_out['mac'], true );
			$plain = $this->crypt( $this->cipher_out, $plain ) . $mac;
		}
		++$this->seq_out;
		$this->connection->send( $plain );
	}

	private function disconnect( $reason, $message ) {
		$this->send_packet( chr( SSH_MSG_DISCONNECT ) . pack( 'N', $reason ) . ssh_string( $message ) . ssh_string( '' ) );
		$this->connection->close_after_flush();
	}

	private function dispatch( $payload ) {
		$type   = ord( $payload[0] );
		$reader = new Ssh_Reader( substr( $payload, 1 ) );
		switch ( $type ) {
			case SSH_MSG_DISCONNECT:
				$this->connection->close();
				return;
			case SSH_MSG_IGNORE:
			case SSH_MSG_DEBUG:
			case SSH_MSG_UNIMPLEMENTED:
				return;
			case SSH_MSG_KEXINIT:
				$this->kexinit_in = $payload;
				$this->negotiate( $reader );
				return;
			case SSH_MSG_KEX_ECDH_INIT:
				$this->key_exchange( $reader->string() );
				return;
			case SSH_MSG_NEWKEYS:
				$this->cipher_in = $this->keys['in'];
				return;
			case SSH_MSG_SERVICE_REQUEST:
				$service = $reader->string();
				if ( 'ssh-userauth' === $service ) {
					$this->send_packet( chr( SSH_MSG_SERVICE_ACCEPT ) . ssh_string( $service ) );
				} else {
					$this->disconnect( 7, 'Service not available' );
				}
				return;
			case SSH_MSG_USERAUTH_REQUEST:
				$this->authenticate( $reader );
				return;
			case SSH_MSG_GLOBAL_REQUEST:
				$reader->string();
				if ( $reader->boolean() ) {
					$this->send_packet( chr( SSH_MSG_REQUEST_FAILURE ) );
				}
				return;
		}
		if ( $type >= SSH_MSG_CHANNEL_OPEN && $type <= SSH_MSG_CHANNEL_FAILURE && $this->authenticated ) {
			$this->channel_message( $type, $reader );
			return;
		}
		$this->send_packet( chr( SSH_MSG_UNIMPLEMENTED ) . pack( 'N', $this->seq_in - 1 ) );
	}

	private function negotiate( Ssh_Reader $reader ) {
		$reader->skip( 16 );
		$client = array();
		for ( $index = 0; $index < 10; $index++ ) {
			$client[] = $reader->names();
		}
		$server = array(
			array( 'curve25519-sha256', 'curve25519-sha256@libssh.org' ),
			$this->server['host']->algorithms,
			array( 'aes128-ctr', 'aes256-ctr' ),
			array( 'aes128-ctr', 'aes256-ctr' ),
			array( 'hmac-sha2-256' ),
			array( 'hmac-sha2-256' ),
			array( 'none' ),
			array( 'none' ),
		);
		$chosen = array();
		foreach ( $server as $index => $supported ) {
			$match = array_values( array_intersect( $client[ $index ], $supported ) );
			if ( ! $match ) {
				$this->disconnect( 3, 'No common algorithm' );
				return;
			}
			$chosen[] = $match[0];
		}
		$this->negotiated = $chosen;
	}

	private function key_exchange( $client_public ) {
		if ( 32 !== strlen( $client_public ) ) {
			throw new RuntimeException( 'Invalid curve25519 public key' );
		}
		$secret           = random_bytes( 32 );
		$public           = sodium_crypto_scalarmult_base( $secret );
		$shared           = ssh_mpint( sodium_crypto_scalarmult( $secret, $client_public ) );
		$host             = $this->server['host'];
		$hash_input       = ssh_string( $this->version ) . ssh_string( $this->server_ident ) . ssh_string( $this->kexinit_in ) . ssh_string( $this->kexinit_out ) . ssh_string( $host->blob ) . ssh_string( $client_public ) . ssh_string( $public ) . $shared;
		$exchange         = hash( 'sha256', $hash_input, true );
		$this->session_id = null === $this->session_id ? $exchange : $this->session_id;
		$this->send_packet( chr( SSH_MSG_KEX_ECDH_REPLY ) . ssh_string( $host->blob ) . ssh_string( $public ) . ssh_string( $host->sign( $exchange, $this->negotiated[1] ) ) );
		$derive     = function ( $letter, $length ) use ( $shared, $exchange ) {
			$output = hash( 'sha256', $shared . $exchange . $letter . $this->session_id, true );
			while ( strlen( $output ) < $length ) {
				$output .= hash( 'sha256', $shared . $exchange . $output, true );
			}
			return substr( $output, 0, $length );
		};
		$state      = function ( $name, $iv, $key, $mac ) use ( $derive ) {
			$size = 'aes256-ctr' === $name ? 32 : 16;
			return array(
				'cipher'  => 'aes256-ctr' === $name ? 'aes-256-ctr' : 'aes-128-ctr',
				'counter' => $derive( $iv, 16 ),
				'key'     => $derive( $key, $size ),
				'mac'     => $derive( $mac, 32 ),
			);
		};
		$this->keys = array(
			'in'  => $state( $this->negotiated[2], 'A', 'C', 'E' ),
			'out' => $state( $this->negotiated[3], 'B', 'D', 'F' ),
		);
		$this->send_packet( chr( SSH_MSG_NEWKEYS ) );
		$this->cipher_out = $this->keys['out'];
	}

	private function authenticate( Ssh_Reader $reader ) {
		$user    = $reader->string();
		$service = $reader->string();
		$method  = $reader->string();
		$state   = $this->server['state'];
		if ( 'ssh-connection' !== $service ) {
			$this->auth_failure();
			return;
		}
		if ( 'password' === $method ) {
			$reader->boolean();
			$password          = $reader->string();
			$state->attempts[] = array( 'password', $password );
			$this->auth_result( 'test' === $user && ' test password ' === $password );
			return;
		}
		if ( 'publickey' === $method ) {
			$signed            = $reader->boolean();
			$algorithm         = $reader->string();
			$blob              = $reader->string();
			$state->attempts[] = array( 'key', base64_encode( $blob ) );
			$known             = 'test' === $user && in_array( $blob, $this->server['keys'], true );
			if ( ! $signed ) {
				if ( $known ) {
					$this->send_packet( chr( SSH_MSG_USERAUTH_PK_OK ) . ssh_string( $algorithm ) . ssh_string( $blob ) );
				} else {
					$this->auth_failure();
				}
				return;
			}
			$data = ssh_string( $this->session_id ) . chr( SSH_MSG_USERAUTH_REQUEST ) . ssh_string( $user ) . ssh_string( $service ) . ssh_string( 'publickey' ) . "\1" . ssh_string( $algorithm ) . ssh_string( $blob );
			$this->auth_result( $known && ssh_verify_client( $blob, $reader->string(), $data ) );
			return;
		}
		$this->auth_failure();
	}

	private function auth_result( $success ) {
		if ( ! $success ) {
			$this->auth_failure();
			return;
		}
		$this->authenticated = true;
		$this->send_packet( chr( SSH_MSG_USERAUTH_SUCCESS ) );
	}

	private function auth_failure() {
		$this->send_packet( chr( SSH_MSG_USERAUTH_FAILURE ) . ssh_list( array( 'password', 'publickey' ) ) . "\0" );
	}

	private function channel_message( $type, Ssh_Reader $reader ) {
		if ( SSH_MSG_CHANNEL_OPEN === $type ) {
			$kind   = $reader->string();
			$remote = $reader->uint32();
			$window = $reader->uint32();
			$max    = $reader->uint32();
			if ( 'session' !== $kind ) {
				$this->send_packet( chr( SSH_MSG_CHANNEL_OPEN_FAILURE ) . pack( 'N', $remote ) . pack( 'N', 1 ) . ssh_string( 'Administratively prohibited' ) . ssh_string( '' ) );
				return;
			}
			$id                    = $this->next_channel++;
			$this->channels[ $id ] = array(
				'remote'        => $remote,
				'remote_window' => $window,
				'remote_max'    => max( 1, min( $max, 32768 ) ),
				'local_window'  => 2097152,
				'queue'         => array(),
				'sftp'          => null,
				'process'       => null,
				'exit'          => null,
				'eof_received'  => false,
				'close_sent'    => false,
				'client_closed' => false,
			);
			$this->send_packet( chr( SSH_MSG_CHANNEL_OPEN_CONFIRM ) . pack( 'NNNN', $remote, $id, 2097152, 32768 ) );
			return;
		}
		$id = $reader->uint32();
		if ( ! isset( $this->channels[ $id ] ) ) {
			return;
		}
		switch ( $type ) {
			case SSH_MSG_CHANNEL_WINDOW_ADJUST:
				$this->channels[ $id ]['remote_window'] += $reader->uint32();
				$this->channel_flush( $id );
				return;
			case SSH_MSG_CHANNEL_DATA:
				$data = $reader->string();
				$this->channel_input( $id, $data );
				return;
			case SSH_MSG_CHANNEL_EOF:
				$this->channels[ $id ]['eof_received'] = true;
				$this->process_input_done( $id );
				return;
			case SSH_MSG_CHANNEL_CLOSE:
				$this->channels[ $id ]['client_closed'] = true;
				$this->channels[ $id ]['eof_received']  = true;
				$this->channels[ $id ]['queue']         = array();
				// A running command is allowed to finish (it sees end of input); our CLOSE then follows.
				if ( $this->channels[ $id ]['process'] && null !== $this->channels[ $id ]['process']['handle'] ) {
					$this->process_input_done( $id );
					return;
				}
				$this->finish_channel( $id );
				return;
			case SSH_MSG_CHANNEL_REQUEST:
				$this->channel_request( $id, $reader );
				return;
		}
	}

	private function channel_request( $id, Ssh_Reader $reader ) {
		$kind  = $reader->string();
		$reply = $reader->boolean();
		$ok    = false;
		if ( 'subsystem' === $kind && ! $this->server['shell'] && 'sftp' === $reader->string() && null === $this->channels[ $id ]['sftp'] ) {
			$this->channels[ $id ]['sftp'] = new Ssh_Sftp( $this->server['root'] );
			$ok                            = true;
		} elseif ( 'exec' === $kind && $this->server['shell'] && null === $this->channels[ $id ]['process'] ) {
			$ok = $this->start_process( $id, $reader->string() );
		}
		if ( $reply ) {
			$this->send_packet( chr( $ok ? SSH_MSG_CHANNEL_SUCCESS : SSH_MSG_CHANNEL_FAILURE ) . pack( 'N', $this->channels[ $id ]['remote'] ) );
		}
	}

	private function channel_input( $id, $data ) {
		$channel                  = &$this->channels[ $id ];
		$channel['local_window'] -= strlen( $data );
		if ( $channel['local_window'] < 1048576 ) {
			$this->send_packet( chr( SSH_MSG_CHANNEL_WINDOW_ADJUST ) . pack( 'NN', $channel['remote'], 2097152 - $channel['local_window'] ) );
			$channel['local_window'] = 2097152;
		}
		if ( $channel['sftp'] ) {
			$output = $channel['sftp']->feed( $data );
			if ( '' !== $output ) {
				$channel['queue'][] = array( false, $output );
				$this->channel_flush( $id );
			}
		} elseif ( $channel['process'] ) {
			$channel['process']['input'] .= $data;
			$this->process_write( $id );
		}
	}

	/** Send queued channel output within the client's window and packet size. */
	private function channel_flush( $id ) {
		$channel = &$this->channels[ $id ];
		while ( $channel['queue'] && $channel['remote_window'] > 0 ) {
			list( $extended, $data ) = $channel['queue'][0];
			$size                    = min( strlen( $data ), $channel['remote_window'], $channel['remote_max'] );
			$this->send_packet(
				$extended ?
				chr( SSH_MSG_CHANNEL_EXTENDED_DATA ) . pack( 'NN', $channel['remote'], 1 ) . ssh_string( substr( $data, 0, $size ) ) :
				chr( SSH_MSG_CHANNEL_DATA ) . pack( 'N', $channel['remote'] ) . ssh_string( substr( $data, 0, $size ) )
			);
			$channel['remote_window'] -= $size;
			if ( strlen( $data ) > $size ) {
				$channel['queue'][0][1] = substr( $data, $size );
			} else {
				array_shift( $channel['queue'] );
			}
		}
		if ( ! $channel['queue'] && null !== $channel['exit'] && ! $channel['close_sent'] ) {
			$this->send_packet( chr( SSH_MSG_CHANNEL_REQUEST ) . pack( 'N', $channel['remote'] ) . ssh_string( 'exit-status' ) . "\0" . pack( 'N', $channel['exit'] ) );
			$this->send_packet( chr( SSH_MSG_CHANNEL_EOF ) . pack( 'N', $channel['remote'] ) );
			$this->send_packet( chr( SSH_MSG_CHANNEL_CLOSE ) . pack( 'N', $channel['remote'] ) );
			$channel['close_sent'] = true;
		}
	}

	private function start_process( $id, $command ) {
		ssh_log( 'exec: ' . $command );
		$process = proc_open(
			$command,
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$this->server['root']
		);
		if ( ! is_resource( $process ) ) {
			return false;
		}
		foreach ( $pipes as $pipe ) {
			stream_set_blocking( $pipe, false );
		}
		$this->channels[ $id ]['process'] = array(
			'handle' => $process,
			'stdin'  => $pipes[0],
			'out'    => $pipes[1],
			'err'    => $pipes[2],
			'input'  => '',
			'done'   => false,
		);
		foreach ( array( 1, 2 ) as $index ) {
			$this->loop->on_read(
				$pipes[ $index ],
				function ( $stream ) use ( $id, $index ) {
					$this->process_output( $id, $stream, 2 === $index );
				}
			);
		}
		return true;
	}

	private function process_output( $id, $stream, $extended ) {
		if ( ! isset( $this->channels[ $id ] ) ) {
			$this->loop->remove_reader( $stream );
			return;
		}
		$chunk = fread( $stream, 32768 );
		if ( '' !== $chunk && false !== $chunk ) {
			if ( $extended ) {
				ssh_log( 'stderr: ' . $chunk );
			}
			if ( ! $this->channels[ $id ]['client_closed'] ) {
				$this->channels[ $id ]['queue'][] = array( $extended, $chunk );
				$this->channel_flush( $id );
			}
			return;
		}
		if ( feof( $stream ) ) {
			$this->loop->remove_reader( $stream );
			$process = &$this->channels[ $id ]['process'];
			fclose( $stream );
			$process[ $extended ? 'err' : 'out' ] = null;
			if ( null === $process['out'] && null === $process['err'] ) {
				$this->process_finished( $id );
			}
		}
	}

	private function process_finished( $id ) {
		$process = &$this->channels[ $id ]['process'];
		$this->process_close_input( $id );
		$status                        = proc_close( $process['handle'] );
		$process['handle']             = null;
		$this->channels[ $id ]['exit'] = $status < 0 ? 255 : $status;
		ssh_log( 'exit ' . $status );
		if ( $this->channels[ $id ]['client_closed'] ) {
			$this->finish_channel( $id );
			return;
		}
		$this->channel_flush( $id );
	}

	private function process_write( $id ) {
		$process = &$this->channels[ $id ]['process'];
		if ( ! $process || ! is_resource( $process['stdin'] ) ) {
			return;
		}
		while ( '' !== $process['input'] ) {
			$written = @fwrite( $process['stdin'], $process['input'] );
			if ( ! $written ) {
				$this->loop->on_write(
					$process['stdin'],
					function () use ( $id ) {
						$this->process_write( $id );
					}
				);
				return;
			}
			$process['input'] = (string) substr( $process['input'], $written );
		}
		$this->loop->remove_writer( $process['stdin'] );
		$this->process_input_done( $id );
	}

	private function process_input_done( $id ) {
		$channel = $this->channels[ $id ];
		if ( $channel['process'] && $channel['eof_received'] && '' === $channel['process']['input'] ) {
			$this->process_close_input( $id );
		}
	}

	private function process_close_input( $id ) {
		$process = &$this->channels[ $id ]['process'];
		if ( $process && is_resource( $process['stdin'] ) ) {
			$this->loop->remove_writer( $process['stdin'] );
			fclose( $process['stdin'] );
		}
	}

	/** Answer the client's CLOSE if needed and forget the channel. */
	private function finish_channel( $id ) {
		if ( ! $this->channels[ $id ]['close_sent'] ) {
			$this->send_packet( chr( SSH_MSG_CHANNEL_CLOSE ) . pack( 'N', $this->channels[ $id ]['remote'] ) );
		}
		$this->release_channel( $id );
		unset( $this->channels[ $id ] );
	}

	private function release_channel( $id ) {
		$channel = $this->channels[ $id ] ?? null;
		if ( ! $channel ) {
			return;
		}
		if ( $channel['sftp'] ) {
			$channel['sftp']->close();
		}
		if ( $channel['process'] ) {
			foreach ( array( 'out', 'err', 'stdin' ) as $name ) {
				if ( is_resource( $channel['process'][ $name ] ) ) {
					$this->loop->remove_reader( $channel['process'][ $name ] );
					$this->loop->remove_writer( $channel['process'][ $name ] );
					fclose( $channel['process'][ $name ] );
				}
			}
			if ( is_resource( $channel['process']['handle'] ) ) {
				proc_terminate( $channel['process']['handle'] );
				proc_close( $channel['process']['handle'] );
			}
		}
		$this->channels[ $id ]['sftp']    = null;
		$this->channels[ $id ]['process'] = null;
	}
}

$config          = json_decode( $argv[1], true );
$loop            = new Integration_Loop();
$root            = realpath( $config['root'] );
$hosts           = array(
	'rsa'     => Ssh_Host_Key::rsa(),
	'ed25519' => Ssh_Host_Key::ed25519(),
);
$state           = new stdClass();
$state->attempts = array();
$state->failures = array();
$keys            = array();
foreach ( $config['keys'] as $path ) {
	$fields = explode( ' ', trim( file_get_contents( $path ) ) );
	$keys[] = base64_decode( $fields[1] );
}
$ports = array();
foreach ( $config['servers'] as $name => $server ) {
	$listener = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $error );
	if ( ! $listener ) {
		fwrite( STDERR, "Unable to listen: $error\n" );
		exit( 1 );
	}
	$ports[ $name ] = (int) substr( strrchr( stream_socket_get_name( $listener, false ), ':' ), 1 );
	$settings       = array(
		'root'  => $root,
		'keys'  => $keys,
		'shell' => $server['shell'],
		'host'  => $hosts[ $server['host'] ],
		'state' => $state,
	);
	$loop->on_read(
		$listener,
		function ( $listener ) use ( $loop, $settings ) {
			$client = @stream_socket_accept( $listener, 0 );
			if ( $client ) {
				new Ssh_Session( $loop, $client, $settings );
			}
		}
	);
}
$loop->run(
	array(
		'ports'        => $ports,
		'fingerprints' => array(
			'rsa'     => $hosts['rsa']->fingerprint,
			'ed25519' => $hosts['ed25519']->fingerprint,
		),
	),
	function () use ( $state ) {
		return array(
			'attempts' => $state->attempts,
			'failures' => $state->failures,
		);
	}
);
