<?php
/**
 * Disposable loopback FTP/FTPS server.
 *
 * Usage: php server-ftp.php '{"NAME": {"root": "/dir", "certificate": "/c.pem", "key": "/c.key"}, ...}'
 * A server with a certificate is explicit FTPS and requires TLS on both the control and
 * data channels; a server without one answers AUTH as unknown. Every login starts in
 * /login, a directory different from the virtual root. Transfers are blocking: the
 * fixtures serve one client at a time.
 */
require_once __DIR__ . '/event-loop.php';

const FTP_USER     = 'backup+user@example.test';
const FTP_PASSWORD = 'p@ss:/?#% word';

$loop   = new Integration_Loop();
$config = json_decode( $argv[1], true );
$ports  = array();

/** Resolve a client path against the working directory without leaving the root. */
function ftp_virtual_path( $session, $argument ) {
	$path  = '' === $argument ? $session['cwd'] : ( '/' === $argument[0] ? $argument : $session['cwd'] . '/' . $argument );
	$parts = array();
	foreach ( explode( '/', $path ) as $part ) {
		if ( '' === $part || '.' === $part ) {
			continue;
		}
		if ( '..' === $part ) {
			array_pop( $parts );
		} else {
			$parts[] = $part;
		}
	}
	return '/' . implode( '/', $parts );
}

function ftp_real_path( $session, $virtual ) {
	return rtrim( $session['root'], '/' ) . $virtual;
}

function ftp_log( $line ) {
	if ( getenv( 'BACKUPPLUS_TEST_SERVER_DEBUG' ) ) {
		fwrite( STDERR, $line . "\n" );
	}
}

function ftp_send( $connection, $line ) {
	ftp_log( '<< ' . $line );
	$connection->send( $line . "\r\n" );
}

/** Write queued control replies synchronously so they precede data-channel activity. */
function ftp_send_now( $connection, $line ) {
	ftp_log( '<< ' . $line );
	$connection->out .= $line . "\r\n";
	stream_set_blocking( $connection->stream, true );
	while ( '' !== $connection->out && ! $connection->closed ) {
		$written = @fwrite( $connection->stream, $connection->out );
		if ( false === $written || 0 === $written ) {
			$connection->close();
			return;
		}
		$connection->out = (string) substr( $connection->out, $written );
	}
	stream_set_blocking( $connection->stream, false );
}

function ftp_enable_tls( $stream, $session ) {
	stream_set_blocking( $stream, true );
	stream_set_timeout( $stream, 10 );
	stream_context_set_option( $stream, 'ssl', 'local_cert', $session['certificate'] );
	stream_context_set_option( $stream, 'ssl', 'local_pk', $session['key'] );
	stream_context_set_option( $stream, 'ssl', 'verify_peer', false );
	return @stream_socket_enable_crypto( $stream, true, STREAM_CRYPTO_METHOD_TLS_SERVER );
}

/** Establish the data connection; the 150 reply must already have been sent. */
function ftp_data_connection( &$session ) {
	$stream = false;
	if ( $session['passive'] ) {
		$stream = @stream_socket_accept( $session['passive'], 10 );
		fclose( $session['passive'] );
	} elseif ( $session['active'] ) {
		$stream = @stream_socket_client( 'tcp://' . $session['active'], $errno, $error, 10 );
	}
	$session['passive'] = null;
	$session['active']  = null;
	if ( ! $stream ) {
		return false;
	}
	if ( $session['protect'] && ! ftp_enable_tls( $stream, $session ) ) {
		@fclose( $stream );
		return false;
	}
	stream_set_blocking( $stream, true );
	stream_set_timeout( $stream, 30 );
	return $stream;
}

function ftp_listing( $real, $names_only ) {
	$lines = array();
	$items = is_dir( $real ) ? array_diff( scandir( $real ), array( '.', '..' ) ) : array( basename( $real ) );
	foreach ( $items as $item ) {
		$path = is_dir( $real ) ? $real . '/' . $item : $real;
		if ( $names_only ) {
			$lines[] = $item;
			continue;
		}
		$stat    = lstat( $path );
		$lines[] = sprintf( '%s 1 ftp ftp %d %s %s', ( is_dir( $path ) ? 'd' : '-' ) . 'rwxr-xr-x', $stat['size'], date( 'M d H:i', $stat['mtime'] ), $item );
	}
	return $lines ? implode( "\r\n", $lines ) . "\r\n" : '';
}

/** Run a transfer: $source returns bytes to send, or $sink receives bytes. */
function ftp_transfer( $connection, &$session, $direction, $payload, $handle = null ) {
	if ( $session['tls_data_required'] && ! $session['protect'] ) {
		ftp_send( $connection, '521 SSL/TLS required on the data channel.' );
		return;
	}
	if ( ! $session['passive'] && ! $session['active'] ) {
		ftp_send( $connection, '425 Use PORT or PASV first.' );
		return;
	}
	ftp_send_now( $connection, '150 File status okay; about to open data connection.' );
	$data = ftp_data_connection( $session );
	if ( ! $data ) {
		ftp_send( $connection, "425 Can't open data connection." );
		return;
	}
	if ( 'send' === $direction ) {
		ftp_log( 'payload ' . strlen( $payload ) . ' bytes' );
		$length = strlen( $payload );
		$offset = 0;
		while ( $offset < $length ) {
			$written = @fwrite( $data, substr( $payload, $offset, 65536 ) );
			if ( ! $written ) {
				break;
			}
			$offset += $written;
		}
		$complete = $offset === $length;
	} else {
		while ( ! feof( $data ) ) {
			$chunk = @fread( $data, 65536 );
			if ( false === $chunk ) {
				break;
			}
			if ( '' !== $chunk ) {
				fwrite( $handle, $chunk );
			}
		}
		fclose( $handle );
		$complete = true;
	}
	@fclose( $data );
	ftp_send( $connection, $complete ? '226 Transfer complete.' : '426 Connection closed; transfer aborted.' );
}

function ftp_command( $connection, &$session, $line ) {
	ftp_log( '>> ' . $line );
	$parts    = explode( ' ', $line, 2 );
	$command  = strtoupper( $parts[0] );
	$argument = isset( $parts[1] ) ? $parts[1] : '';
	$secure   = null !== $session['certificate'];
	if ( $secure && ! $session['encrypted'] && in_array( $command, array( 'USER', 'PASS' ), true ) ) {
		ftp_send( $connection, '550 SSL/TLS required on the control channel.' );
		return;
	}
	if ( ! $session['authenticated'] && ! in_array( $command, array( 'USER', 'PASS', 'AUTH', 'PBSZ', 'PROT', 'QUIT', 'FEAT', 'OPTS', 'NOOP', 'SYST' ), true ) ) {
		ftp_send( $connection, '530 Log in with USER and PASS first.' );
		return;
	}
	switch ( $command ) {
		case 'AUTH':
			if ( ! $secure || ! in_array( strtoupper( $argument ), array( 'TLS', 'TLS-C', 'SSL' ), true ) ) {
				ftp_send( $connection, '502 Command not implemented.' );
				return;
			}
			ftp_send_now( $connection, '234 AUTH TLS successful.' );
			if ( ! ftp_enable_tls( $connection->stream, $session ) ) {
				$connection->close();
				return;
			}
			stream_set_blocking( $connection->stream, false );
			$session['encrypted'] = true;
			return;
		case 'PBSZ':
			ftp_send( $connection, $secure && $session['encrypted'] ? '200 PBSZ=0 successful.' : '503 PBSZ requires a TLS control channel.' );
			return;
		case 'PROT':
			if ( ! $secure || ! $session['encrypted'] ) {
				ftp_send( $connection, '503 PROT requires a TLS control channel.' );
			} elseif ( 'P' === strtoupper( $argument ) ) {
				$session['protect'] = true;
				ftp_send( $connection, '200 Protection set to Private' );
			} elseif ( 'C' === strtoupper( $argument ) ) {
				$session['protect'] = false;
				ftp_send( $connection, '200 Protection set to Clear' );
			} else {
				ftp_send( $connection, '504 Unsupported protection level.' );
			}
			return;
		case 'USER':
			$session['user'] = $argument;
			ftp_send( $connection, '331 Username ok, send password.' );
			return;
		case 'PASS':
			if ( FTP_USER === $session['user'] && FTP_PASSWORD === $argument ) {
				$session['authenticated'] = true;
				// Exercise a login directory different from the server's root.
				$session['cwd'] = '/login';
				ftp_send( $connection, '230 Login successful.' );
			} else {
				$session['user'] = null;
				ftp_send( $connection, '530 Authentication failed.' );
			}
			return;
		case 'QUIT':
			ftp_send( $connection, '221 Goodbye.' );
			$connection->close_after_flush();
			return;
		case 'NOOP':
			ftp_send( $connection, '200 I successfully done nothin\'.' );
			return;
		case 'SYST':
			ftp_send( $connection, '215 UNIX Type: L8' );
			return;
		case 'FEAT':
			ftp_send( $connection, "211-Features supported:\r\n SIZE\r\n REST STREAM\r\n MDTM\r\n" . ( $secure ? " AUTH TLS\r\n PBSZ\r\n PROT\r\n" : '' ) . '211 End FEAT.' );
			return;
		case 'OPTS':
			ftp_send( $connection, '200 Command okay.' );
			return;
		case 'TYPE':
		case 'MODE':
		case 'STRU':
			ftp_send( $connection, '200 Command okay.' );
			return;
		case 'PWD':
		case 'XPWD':
			ftp_send( $connection, '257 "' . str_replace( '"', '""', $session['cwd'] ) . '" is the current directory.' );
			return;
		case 'CWD':
		case 'XCWD':
		case 'CDUP':
			$target = 'CDUP' === $command ? ftp_virtual_path( $session, '..' ) : ftp_virtual_path( $session, $argument );
			if ( is_dir( ftp_real_path( $session, $target ) ) ) {
				$session['cwd'] = $target;
				ftp_send( $connection, '250 "' . $target . '" is the current directory.' );
			} else {
				ftp_send( $connection, '550 No such directory.' );
			}
			return;
		case 'EPSV':
		case 'PASV':
			ftp_discard_data_channel( $session );
			$listener = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $error );
			if ( ! $listener ) {
				ftp_send( $connection, '425 Cannot listen.' );
				return;
			}
			$port               = (int) substr( strrchr( stream_socket_get_name( $listener, false ), ':' ), 1 );
			$session['passive'] = $listener;
			ftp_send( $connection, 'EPSV' === $command ? '229 Entering extended passive mode (|||' . $port . '|).' : '227 Entering passive mode (127,0,0,1,' . ( $port >> 8 ) . ',' . ( $port & 255 ) . ').' );
			return;
		case 'PORT':
		case 'EPRT':
			ftp_discard_data_channel( $session );
			if ( 'PORT' === $command ) {
				$numbers = explode( ',', $argument );
				$valid   = 6 === count( $numbers );
				$target  = $valid ? implode( '.', array_slice( $numbers, 0, 4 ) ) . ':' . ( ( (int) $numbers[4] << 8 ) + (int) $numbers[5] ) : '';
			} else {
				$fields = explode( $argument[0], $argument );
				$valid  = 6 === count( $fields ) && '1' === $fields[1];
				$target = $valid ? $fields[2] . ':' . $fields[3] : '';
			}
			if ( ! $valid || strtok( $target, ':' ) !== $session['peer'] ) {
				ftp_send( $connection, '501 Invalid data address.' );
				return;
			}
			$session['active'] = $target;
			ftp_send( $connection, '200 Active data connection established.' );
			return;
		case 'SIZE':
			$real = ftp_real_path( $session, ftp_virtual_path( $session, $argument ) );
			ftp_send( $connection, is_file( $real ) ? '213 ' . filesize( $real ) : '550 Not a regular file.' );
			return;
		case 'MDTM':
			$real = ftp_real_path( $session, ftp_virtual_path( $session, $argument ) );
			ftp_send( $connection, is_file( $real ) ? '213 ' . gmdate( 'YmdHis', filemtime( $real ) ) : '550 Not a regular file.' );
			return;
		case 'REST':
			if ( ctype_digit( $argument ) ) {
				$session['rest'] = (int) $argument;
				ftp_send( $connection, '350 Restarting at position ' . $argument . '.' );
			} else {
				ftp_send( $connection, '501 Invalid REST parameter.' );
			}
			return;
		case 'LIST':
		case 'NLST':
			$argument = preg_replace( '/^-[a-zA-Z]+\s*/', '', $argument );
			$real     = ftp_real_path( $session, ftp_virtual_path( $session, $argument ) );
			if ( ! file_exists( $real ) ) {
				ftp_send( $connection, '550 No such file or directory.' );
				return;
			}
			ftp_transfer( $connection, $session, 'send', ftp_listing( $real, 'NLST' === $command ) );
			return;
		case 'RETR':
			$real = ftp_real_path( $session, ftp_virtual_path( $session, $argument ) );
			if ( ! is_file( $real ) || ! is_readable( $real ) ) {
				ftp_send( $connection, '550 No such file.' );
				return;
			}
			$payload         = (string) substr( file_get_contents( $real ), $session['rest'] );
			$session['rest'] = 0;
			ftp_transfer( $connection, $session, 'send', $payload );
			return;
		case 'STOR':
		case 'APPE':
			$real            = ftp_real_path( $session, ftp_virtual_path( $session, $argument ) );
			$offset          = $session['rest'];
			$session['rest'] = 0;
			if ( is_dir( $real ) ) {
				ftp_send( $connection, '550 Is a directory.' );
				return;
			}
			if ( 'APPE' === $command ) {
				$handle = @fopen( $real, 'ab' );
			} elseif ( $offset > 0 ) {
				$handle = @fopen( $real, 'c+b' );
				if ( $handle ) {
					fseek( $handle, $offset );
				}
			} else {
				$handle = @fopen( $real, 'wb' );
			}
			if ( ! $handle ) {
				ftp_send( $connection, '550 Cannot open the file for writing.' );
				return;
			}
			ftp_transfer( $connection, $session, 'receive', '', $handle );
			return;
		case 'DELE':
			$real = ftp_real_path( $session, ftp_virtual_path( $session, $argument ) );
			ftp_send( $connection, is_file( $real ) && @unlink( $real ) ? '250 File removed.' : '550 Cannot delete the file.' );
			return;
		case 'MKD':
		case 'XMKD':
			$virtual = ftp_virtual_path( $session, $argument );
			ftp_send( $connection, @mkdir( ftp_real_path( $session, $virtual ) ) ? '257 "' . str_replace( '"', '""', $virtual ) . '" directory created.' : '550 Cannot create the directory.' );
			return;
		case 'RMD':
		case 'XRMD':
			ftp_send( $connection, @rmdir( ftp_real_path( $session, ftp_virtual_path( $session, $argument ) ) ) ? '250 Directory removed.' : '550 Cannot remove the directory.' );
			return;
		case 'RNFR':
			$real = ftp_real_path( $session, ftp_virtual_path( $session, $argument ) );
			if ( file_exists( $real ) ) {
				$session['rename'] = $real;
				ftp_send( $connection, '350 Ready for destination name.' );
			} else {
				ftp_send( $connection, '550 No such file or directory.' );
			}
			return;
		case 'RNTO':
			if ( ! $session['rename'] ) {
				ftp_send( $connection, '503 Bad sequence of commands.' );
				return;
			}
			$from              = $session['rename'];
			$session['rename'] = null;
			ftp_send( $connection, @rename( $from, ftp_real_path( $session, ftp_virtual_path( $session, $argument ) ) ) ? '250 Renaming ok.' : '550 Cannot rename.' );
			return;
		case 'SITE':
			$fields = explode( ' ', $argument, 3 );
			if ( 'CHMOD' === strtoupper( $fields[0] ) && 3 === count( $fields ) && preg_match( '/^[0-7]{3,4}$/', $fields[1] ) ) {
				$real = ftp_real_path( $session, ftp_virtual_path( $session, $fields[2] ) );
				ftp_send( $connection, file_exists( $real ) && @chmod( $real, octdec( $fields[1] ) ) ? '200 SITE CHMOD successful.' : '550 Cannot change permissions.' );
			} else {
				ftp_send( $connection, '501 Unsupported SITE command.' );
			}
			return;
	}
	ftp_send( $connection, '500 Command "' . $command . '" not understood.' );
}

function ftp_discard_data_channel( &$session ) {
	if ( $session['passive'] ) {
		fclose( $session['passive'] );
	}
	$session['passive'] = null;
	$session['active']  = null;
}

foreach ( $config as $name => $server ) {
	$listener = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $error );
	if ( ! $listener ) {
		fwrite( STDERR, "Unable to listen: $error\n" );
		exit( 1 );
	}
	$ports[ $name ] = (int) substr( strrchr( stream_socket_get_name( $listener, false ), ':' ), 1 );
	$loop->on_read(
		$listener,
		function ( $listener ) use ( $loop, $server ) {
			$client = @stream_socket_accept( $listener, 0 );
			if ( ! $client ) {
				return;
			}
			$session    = array(
				'root'              => $server['root'],
				'certificate'       => isset( $server['certificate'] ) ? $server['certificate'] : null,
				'key'               => isset( $server['key'] ) ? $server['key'] : null,
				'tls_data_required' => isset( $server['certificate'] ),
				'peer'              => strtok( stream_socket_get_name( $client, true ), ':' ),
				'encrypted'         => false,
				'protect'           => false,
				'authenticated'     => false,
				'user'              => null,
				'cwd'               => '/',
				'rest'              => 0,
				'rename'            => null,
				'passive'           => null,
				'active'            => null,
			);
			$connection = new Integration_Connection(
				$loop,
				$client,
				function ( $connection ) use ( &$session ) {
					while ( ! $connection->closed && false !== ( $newline = strpos( $connection->in, "\n" ) ) ) {
						$line           = rtrim( substr( $connection->in, 0, $newline ), "\r" );
						$connection->in = (string) substr( $connection->in, $newline + 1 );
						ftp_command( $connection, $session, $line );
					}
				},
				function () use ( &$session ) {
					ftp_discard_data_channel( $session );
				}
			);
			ftp_send( $connection, '220 Disposable FTP server ready.' );
		}
	);
}
$loop->run(
	array( 'ports' => $ports ),
	function () {
		return array();
	}
);
