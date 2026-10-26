<?php
/**
 * SFTP version 3 handler for the disposable SSH server. Loaded by ../server-ssh.php.
 */

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
