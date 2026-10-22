<?php
/** SSH storage transport using the bundled phpseclib. */
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SCP;
use phpseclib3\Net\SFTP;

class BackupPlus_SFTP_Client {
	private $ssh;
	private $scp;
	private $options;

	/** Validate and verify the server before sending any authentication material. */
	public static function open( $options ) {
		if ( '' === $options['host'] || '' === $options['user'] ) {
			return new WP_Error( 'no_settings', __( 'No SFTP/SCP server or username was given.', 'backupplus' ) );
		}
		if ( ! in_array( $options['protocol'], array( 'sftp', 'scp' ), true ) ) {
			return new WP_Error( 'sftp_protocol', __( 'Unsupported SSH storage protocol.', 'backupplus' ) );
		}
		if ( ! ctype_digit( (string) $options['port'] ) || $options['port'] < 1 || $options['port'] > 65535 ) {
			return new WP_Error( 'sftp_port', __( 'The SSH port must be between 1 and 65535.', 'backupplus' ) );
		}
		if ( preg_match( '/[\s\/\\\\@?#\x00-\x1f\x7f]/', $options['host'] ) || preg_match( '/[\x00-\x1f\x7f]/', $options['path'] ) ) {
			return new WP_Error( 'sftp_address', __( 'Invalid SSH host or remote path.', 'backupplus' ) );
		}
		if ( false !== strpos( $options['host'], ':' ) && ! filter_var( $options['host'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return new WP_Error( 'sftp_address', __( 'Invalid SSH host or remote path.', 'backupplus' ) );
		}
		if ( '' !== $options['host_key'] && ! preg_match( '/^SHA256:[A-Za-z0-9+\/]{43}=?$/', $options['host_key'] ) ) {
			return new WP_Error( 'sftp_host_key', __( 'Enter a valid SHA256 server host key fingerprint.', 'backupplus' ) );
		}
		if ( '' === $options['pass'] && '' === $options['key'] ) {
			return new WP_Error( 'no_settings', __( 'Enter an SSH password or a private key (RSA or Ed25519).', 'backupplus' ) );
		}
		if ( ! function_exists( 'fsockopen' ) ) {
			return new WP_Error( 'sftp_support', __( 'SFTP/SCP requires the PHP fsockopen function.', 'backupplus' ) );
		}
		if ( PHP_VERSION_ID < 50601 ) {
			return new WP_Error( 'sftp_support', __( 'SFTP/SCP requires PHP 5.6.1 or newer.', 'backupplus' ) );
		}
		$autoload = BACKUPPLUS_DIR . '/vendor/autoload.php';
		if ( ! is_readable( $autoload ) ) {
			return new WP_Error( 'sftp_support', __( 'The bundled SSH library is missing. Reinstall BackupPlus.', 'backupplus' ) );
		}
		require_once $autoload;
		$client          = new self();
		$client->options = $options;
		try {
			$host        = false !== strpos( $options['host'], ':' ) ? '[' . $options['host'] . ']' : $options['host'];
			$client->ssh = 'scp' === $options['protocol'] ? new SCP( $host, (int) $options['port'], 30 ) : new SFTP( $host, (int) $options['port'], 30 );
			$key         = $client->ssh->getServerPublicHostKey();
			if ( ! $key ) {
				return new WP_Error( 'sftp_connect', __( 'Unable to connect to the SSH server or obtain its host key.', 'backupplus' ) );
			}
			$parts       = explode( ' ', $key );
			$fingerprint = 'SHA256:' . rtrim( base64_encode( hash( 'sha256', base64_decode( $parts[1] ), true ) ), '=' );
			if ( '' === $options['host_key'] ) {
				/* translators: %s: SHA256 SSH host key fingerprint. */
				return new WP_Error( 'sftp_host_key_required', sprintf( __( 'Server fingerprint: %s. Verify this with your server administrator, then enter it in the server host key fingerprint field.', 'backupplus' ), $fingerprint ) );
			}
			if ( ! hash_equals( $fingerprint, rtrim( $options['host_key'], '=' ) ) ) {
				return new WP_Error( 'sftp_host_key_mismatch', __( 'SSH server host key verification failed. Check the configured fingerprint with your server administrator.', 'backupplus' ) );
			}
			$credential = $options['pass'];
			if ( '' !== $options['key'] ) {
				try {
					$credential = PublicKeyLoader::loadPrivateKey( $options['key'], '' === $options['key_passphrase'] ? false : $options['key_passphrase'] );
				} catch ( Exception $e ) {
					return new WP_Error( 'sftp_private_key', __( 'Unable to load the SSH private key. Check its format and passphrase.', 'backupplus' ) );
				}
			}
			if ( ! $client->ssh->login( $options['user'], $credential ) ) {
				return new WP_Error( 'sftp_login', __( 'SSH authentication failed. Check your username and password or private key.', 'backupplus' ) );
			}
			if ( 'scp' === $options['protocol'] ) {
				$client->scp = $client->ssh;
				if ( false === $client->command( 'pwd' ) ) {
					return new WP_Error( 'sftp_path', __( 'Unable to access the remote directory using the SSH shell.', 'backupplus' ) );
				}
			} elseif ( ! $client->ssh->chdir( '' === $options['path'] ? '.' : $options['path'] ) ) {
				return new WP_Error( 'sftp_path', __( 'Unable to access the remote directory.', 'backupplus' ) );
			}
			return $client;
		} catch ( Exception $e ) {
			return new WP_Error( 'sftp_connect', __( 'The SSH connection failed. Check the server address, port and SSH configuration.', 'backupplus' ) );
		}
	}

	/** Prefix relative paths so remote tools cannot treat them as options. */
	private function remote_path( $name = '' ) {
		$path = '' === $this->options['path'] ? '.' : $this->options['path'];
		if ( '/' !== substr( $path, 0, 1 ) ) {
			$path = './' . $path;
		}
		return '' === $name ? $path : rtrim( $path, '/' ) . '/' . $name;
	}

	/** Commands use a POSIX shell, literal paths, and checked exit status. */
	private function command( $command ) {
		$output = $this->ssh->exec( 'cd ' . escapeshellarg( $this->remote_path() ) . ' && ' . $command );
		return false !== $output && 0 === $this->ssh->getExitStatus() ? $output : false;
	}

	private function size( $name ) {
		if ( ! $this->scp ) {
			$this->ssh->clearStatCache();
			$stat = $this->ssh->lstat( $name );
			return is_array( $stat ) && isset( $stat['type'], $stat['size'] ) && 1 === $stat['type'] ? $stat['size'] : false;
		}
		$file   = escapeshellarg( './' . $name );
		$output = $this->command( 'if [ -f ' . $file . ' ] && [ ! -L ' . $file . ' ]; then wc -c < ' . $file . '; else exit 1; fi' );
		return false !== $output && ctype_digit( trim( $output ) ) ? (int) trim( $output ) : false;
	}

	public function listing() {
		$files = array();
		if ( ! $this->scp ) {
			$this->ssh->clearStatCache();
			$list = $this->ssh->rawlist( '.', false );
			if ( false === $list ) {
				return false;
			}
			foreach ( $list as $name => $stat ) {
				if ( isset( $stat['type'], $stat['size'] ) && 1 === $stat['type'] ) {
					$files[] = array(
						'name' => (string) $name,
						'size' => $stat['size'],
					);
				}
			}
		} else {
			// NUL delimiters preserve spaces, quotes and other literal filename characters.
			$list = $this->command( 'for file in ./* ./.[!.]* ./..?*; do if [ -f "$file" ] && [ ! -L "$file" ]; then size=$(wc -c < "$file") || exit 1; printf \'%s\\000%s\\000\' "${file#./}" "$size"; fi; done' );
			if ( false === $list ) {
				return false;
			}
			$entries = explode( "\0", $list );
			array_pop( $entries );
			if ( count( $entries ) % 2 ) {
				return false;
			}
			for ( $i = 0; $i < count( $entries ); $i += 2 ) {
				if ( ! ctype_digit( trim( $entries[ $i + 1 ] ) ) ) {
					return false;
				}
				$files[] = array(
					'name' => $entries[ $i ],
					'size' => (int) trim( $entries[ $i + 1 ] ),
				);
			}
		}
		return $files;
	}

	public function upload( $name, $local, $resume = false, $progress = null ) {
		$size = filesize( $local );
		if ( $this->scp ) {
			$file = escapeshellarg( './' . $name );
			if ( false === $this->command( '[ ! -L ' . $file . ' ] && { [ ! -e ' . $file . ' ] || [ -f ' . $file . ' ]; }' ) ) {
				return false;
			}
			$result = $this->scp->put( $this->remote_path( $name ), $local, SCP::SOURCE_LOCAL_FILE, $progress );
		} else {
			$this->ssh->clearStatCache();
			$stat = $this->ssh->lstat( $name );
			if ( is_array( $stat ) && ( ! isset( $stat['type'] ) || 1 !== $stat['type'] ) ) {
				return false;
			}
			$offset = $resume ? $this->size( $name ) : false;
			if ( false === $offset || $offset >= $size ) {
				$offset = 0;
			}
			$callback = is_callable( $progress ) ? function ( $sent ) use ( $progress, $offset ) {
				call_user_func( $progress, $sent + $offset );
			} : null;
			$result   = $this->ssh->put( $name, $local, SFTP::SOURCE_LOCAL_FILE, $offset ? $offset : -1, $offset ? $offset : -1, $callback );
		}
		return $result && $size === $this->size( $name );
	}

	public function download( $name, $local, $resume = true ) {
		$size = $this->size( $name );
		if ( false === $size || is_link( $local ) ) {
			return false;
		}
		if ( $this->scp ) {
			$result = $this->scp->get( $this->remote_path( $name ), $local );
		} else {
			clearstatcache( true, $local );
			$offset = $resume && is_file( $local ) ? filesize( $local ) : 0;
			if ( $resume && is_file( $local ) && $offset === $size ) {
				return true;
			}
			if ( $offset > $size ) {
				$offset = 0;
			}
			$stream = fopen( $local, $offset ? 'ab' : 'wb' );
			if ( ! $stream ) {
				return false;
			}
			try {
				$result = $this->ssh->get( $name, $stream, $offset );
			} finally {
				fclose( $stream );
			}
		}
		clearstatcache( true, $local );
		return $result && is_file( $local ) && $size === filesize( $local );
	}

	public function delete( $name ) {
		if ( $this->scp ) {
			return false !== $this->command( 'rm -f ' . escapeshellarg( './' . $name ) );
		}
		$this->ssh->clearStatCache();
		$stat = $this->ssh->lstat( $name );
		if ( false === $stat ) {
			return 0 === strpos( $this->ssh->getLastSFTPError(), 'NET_SFTP_STATUS_NO_SUCH_FILE' );
		}
		// Retention must never recursively remove a directory or follow a symlink.
		return isset( $stat['type'] ) && 1 === $stat['type'] && $this->ssh->delete( $name, false );
	}
}
