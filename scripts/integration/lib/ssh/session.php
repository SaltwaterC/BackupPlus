<?php
/**
 * One SSH connection of the disposable SSH server: state, packet receive loop and message
 * dispatch. The transport, authentication, channel and exec methods live in the traits
 * below. Loaded by ../server-ssh.php.
 */

require_once __DIR__ . '/session-transport.php';
require_once __DIR__ . '/session-auth.php';
require_once __DIR__ . '/session-channels.php';
require_once __DIR__ . '/session-exec.php';

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

	use Ssh_Session_Transport;
	use Ssh_Session_Auth;
	use Ssh_Session_Channels;
	use Ssh_Session_Exec;

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
}
