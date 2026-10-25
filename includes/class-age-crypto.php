<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}
require_once __DIR__ . '/class-age-keys.php';
require_once __DIR__ . '/class-archive-io.php';

/** Binary age v1 files: bounded header parsing and RFC 8439 STREAM payloads. */
class BackupPlus_Age_Crypto {
	const MAGIC      = "age-encryption.org/v1\n";
	const CHUNK_SIZE = 65536;
	const MAX_HEADER = 65536;

	public static function encrypt( $input, $output, $recipients ) {
		if ( ! is_array( $recipients ) || ! $recipients || count( $recipients ) > 100 ) {
			throw new \RuntimeException( 'Configure between one and 100 age recipients.' );
		}
		$file_key = \phpseclib3\Crypt\Random::string( 16 );
		$header   = self::MAGIC;
		foreach ( $recipients as $text ) {
			$stanza  = BackupPlus_Age_Keys::wrap( BackupPlus_Age_Keys::recipient( $text ), $file_key );
			$header .= '-> ' . $stanza['type'] . ' ' . implode( ' ', $stanza['args'] ) . "\n";
			$encoded = BackupPlus_Age_Keys::base64( $stanza['body'] );
			$header .= chunk_split( $encoded, 64, "\n" );
			if ( 0 === strlen( $encoded ) % 64 ) {
				$header .= "\n";
			}
			if ( strlen( $header ) > self::MAX_HEADER - 100 ) {
				throw new \RuntimeException( 'Too many age recipients.' );
			}
		}
		$header .= '---';
		$mac     = hash_hmac( 'sha256', $header, BackupPlus_Age_Keys::hkdf( $file_key, '', 'header' ), true );
		$nonce   = \phpseclib3\Crypt\Random::string( 16 );
		BackupPlus_Archive_IO::write_all( $output, $header . ' ' . BackupPlus_Age_Keys::base64( $mac ) . "\n" . $nonce );
		self::payload( $input, $output, BackupPlus_Age_Keys::hkdf( $file_key, $nonce, 'payload' ), false );
	}

	/** Only return after header, chunk sequence and final chunk authenticate. */
	public static function decrypt( $input, $output, $identity, $passphrase = '' ) {
		list($header, $mac, $stanzas) = self::header( $input );
		$identity                     = BackupPlus_Age_Keys::identity( $identity, $passphrase );
		$file_key                     = false;
		foreach ( $stanzas as $stanza ) {
			try {
				$candidate = BackupPlus_Age_Keys::unwrap( $identity, $stanza );
			} catch ( \Exception $e ) {
				continue; }
			if ( false === $candidate || 16 !== strlen( $candidate ) ) {
				continue;
			}
			$expected = hash_hmac( 'sha256', $header, BackupPlus_Age_Keys::hkdf( $candidate, '', 'header' ), true );
			if ( hash_equals( $expected, $mac ) ) {
				$file_key = $candidate;
				break; }
		}
		if ( false === $file_key ) {
			throw new \RuntimeException( 'The age identity could not authenticate this archive.' );
		}
		$nonce = BackupPlus_Archive_IO::read_up_to( $input, 16 );
		if ( 16 !== strlen( $nonce ) ) {
			throw new \RuntimeException( 'Truncated age nonce.' );
		}
		self::payload( $input, $output, BackupPlus_Age_Keys::hkdf( $file_key, $nonce, 'payload' ), true );
	}

	private static function line( $input, &$size ) {
		$line = fgets( $input, 4098 );
		if ( false === $line || substr( $line, -1 ) !== "\n" || strlen( $line ) > 4096 ) {
			throw new \RuntimeException( 'Invalid age header line.' );
		}
		$size += strlen( $line );
		if ( $size > self::MAX_HEADER ) {
			throw new \RuntimeException( 'Age header is too large.' );
		}
		return $line;
	}

	private static function header( $input ) {
		$size   = 0;
		$header = self::line( $input, $size );
		if ( self::MAGIC !== $header ) {
			throw new \RuntimeException( 'Unsupported age version.' );
		}
		$stanzas = array();
		while ( true ) {
			$line = self::line( $input, $size );
			if ( preg_match( '/\A--- ([A-Za-z0-9+\/]{43})\n\z/D', $line, $parts ) ) {
				if ( ! $stanzas ) {
					throw new \RuntimeException( 'Age file has no recipients.' );
				}
				return array( $header . '---', BackupPlus_Age_Keys::unbase64( $parts[1] ), $stanzas );
			}
			if ( count( $stanzas ) >= 100 || ! preg_match( '/\A-> ([!-~]+)((?: [!-~]+)*)\n\z/D', $line, $parts ) ) {
				throw new \RuntimeException( 'Invalid age recipient header.' );
			}
			$stanza  = array(
				'type' => $parts[1],
				'args' => '' === $parts[2] ? array() : explode( ' ', substr( $parts[2], 1 ) ),
			);
			$header .= $line;
			$body    = '';
			do {
				$line = self::line( $input, $size );
				if ( ! preg_match( '/\A[A-Za-z0-9+\/]{0,64}\n\z/D', $line ) ) {
					throw new \RuntimeException( 'Invalid age recipient body.' );
				}
				$header .= $line;
				$body   .= substr( $line, 0, -1 );
			} while ( 65 === strlen( $line ) );
			$stanza['body'] = BackupPlus_Age_Keys::unbase64( $body );
			self::validate_stanza( $stanza );
			$stanzas[] = $stanza;
		}
	}

	private static function validate_stanza( $stanza ) {
		$type = $stanza['type'];
		$args = $stanza['args'];
		if ( ! in_array( $type, array( 'X25519', 'ssh-rsa', 'ssh-ed25519' ), true ) ) {
			return;
		}
		$count = 'ssh-ed25519' === $type ? 2 : 1;
		if ( $count !== count( $args ) ) {
			throw new \RuntimeException( 'Invalid age recipient arguments.' );
		}
		if ( 'X25519' !== $type && 4 !== strlen( BackupPlus_Age_Keys::unbase64( $args[0] ) ) ) {
			throw new \RuntimeException( 'Invalid SSH key hint.' );
		}
		if ( 'ssh-rsa' !== $type && ( 32 !== strlen( BackupPlus_Age_Keys::unbase64( $args[ $count - 1 ] ) ) || 32 !== strlen( $stanza['body'] ) ) ) {
			throw new \RuntimeException( 'Invalid age recipient key size.' );
		}
	}

	private static function payload( $input, $output, $key, $decrypt ) {
		$counter = str_repeat( "\0", 11 );
		$first   = true;
		$carry   = '';
		do {
			$capacity = self::CHUNK_SIZE + ( $decrypt ? 16 : 0 );
			$data     = $carry . BackupPlus_Archive_IO::read_up_to( $input, $capacity - strlen( $carry ) );
			$carry    = BackupPlus_Archive_IO::read_up_to( $input, 1 );
			$final    = '' === $carry;
			if ( ( $decrypt && strlen( $data ) < 16 ) || ( ! $first && strlen( $data ) === ( $decrypt ? 16 : 0 ) ) ) {
				throw new \RuntimeException( 'Truncated age payload.' );
			}
			$nonce = $counter . ( $final ? "\x01" : "\x00" );
			try {
				$plaintext = BackupPlus_Age_Keys::aead( $data, $key, $nonce, $decrypt );
			} catch ( \Throwable $e ) {
				if ( $decrypt && self::CHUNK_SIZE + 16 === strlen( $data ) ) {
					// Full chunks are ambiguous until their tag is checked: a valid
					// final chunk can have trailing bytes, and a non-final chunk can
					// be the last available chunk in a truncated stream.
					$alternate_nonce = $counter . ( $final ? "\x00" : "\x01" );
					$plaintext       = BackupPlus_Age_Keys::aead( $data, $key, $alternate_nonce, true );
					BackupPlus_Archive_IO::write_all( $output, $plaintext );
					throw new \RuntimeException( $final ? 'Truncated age payload.' : 'Trailing data after final age payload chunk.' );
				}
				throw $e;
			}
			BackupPlus_Archive_IO::write_all( $output, $plaintext );
			$first = false;
			for ( $i = 10; $i >= 0; $i-- ) {
				$counter[ $i ] = chr( ( ord( $counter[ $i ] ) + 1 ) & 255 );
				if ( "\0" !== $counter[ $i ] ) {
					break;
				}
			}
			if ( $i < 0 && ! $final ) {
				throw new \RuntimeException( 'Age payload counter exhausted.' );
			}
		} while ( ! $final );
	}
}
