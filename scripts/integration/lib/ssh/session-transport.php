<?php
/**
 * Packet layer and key exchange of Ssh_Session: AES-CTR with HMAC, packet read/write,
 * algorithm negotiation and curve25519 key exchange. Loaded by session.php.
 */

trait Ssh_Session_Transport {
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
}
