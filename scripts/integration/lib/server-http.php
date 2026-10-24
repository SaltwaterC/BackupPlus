<?php
/**
 * Disposable loopback HTTP(S) fixtures.
 *
 * Usage: php server-http.php cloud|webdav certificate key
 * "cloud" is a TLS PUT endpoint that returns the size and SHA-256 of what it received.
 * "webdav" runs a Digest-authenticated HTTP server and a Basic-authenticated HTTPS server
 * that also return malformed/mixed XML and incomplete responses.
 */
require_once __DIR__ . '/event-loop.php';

const BACKUPPLUS_DAV_USER      = 'backup+user@example.test';
const BACKUPPLUS_DAV_PASSWORD  = ' secret:@/?#% ';
const BACKUPPLUS_DAV_DIRECTORY = '/dav/my backups +#?%/';

$mode        = $argv[1];
$certificate = $argv[2];
$private_key = $argv[3];
$loop        = new Integration_Loop();
$violations  = array();
$servers     = array();

/** Escape a path the way a URL path is percent-encoded, keeping slashes. */
function dav_quote( $path ) {
	return str_replace( '%2F', '/', rawurlencode( $path ) );
}

function ends_with( $value, $suffix ) {
	return substr( $value, -strlen( $suffix ) ) === $suffix;
}

function http_response( $status, $body = '', $headers = array(), $extra_length = 0 ) {
	return array( $status, $body, $headers, $extra_length );
}

function dav_digest_response( $server, $request ) {
	$authorization = isset( $request['headers']['authorization'] ) ? $request['headers']['authorization'] : '';
	if ( 0 !== strpos( $authorization, 'Digest ' ) ) {
		return false;
	}
	preg_match_all( '/(\w+)=(?:"((?:[^"\\\\]|\\\\.)*)"|([^,\s]*))/', substr( $authorization, 7 ), $matches, PREG_SET_ORDER );
	$parts = array();
	foreach ( $matches as $match ) {
		$parts[ $match[1] ] = isset( $match[3] ) ? $match[3] : stripslashes( $match[2] );
	}
	$ha1      = md5( BACKUPPLUS_DAV_USER . ':backupplus:' . BACKUPPLUS_DAV_PASSWORD );
	$ha2      = md5( $request['method'] . ':' . $request['target'] );
	$expected = md5( $ha1 . ':test-nonce:' . ( isset( $parts['nc'] ) ? $parts['nc'] : '' ) . ':' . ( isset( $parts['cnonce'] ) ? $parts['cnonce'] : '' ) . ':auth:' . $ha2 );
	return isset( $parts['username'], $parts['uri'], $parts['response'] ) && BACKUPPLUS_DAV_USER === $parts['username'] && $parts['uri'] === $request['target'] && $parts['response'] === $expected;
}

function dav_authenticated( $server, $request ) {
	if ( $server['digest'] ) {
		if ( dav_digest_response( $server, $request ) ) {
			return null;
		}
		$challenge = 'Digest realm="backupplus", nonce="test-nonce", algorithm=MD5, qop="auth"';
	} else {
		$authorization = isset( $request['headers']['authorization'] ) ? $request['headers']['authorization'] : '';
		if ( 'Basic ' . base64_encode( BACKUPPLUS_DAV_USER . ':' . BACKUPPLUS_DAV_PASSWORD ) === $authorization ) {
			return null;
		}
		$challenge = 'Basic realm="backupplus"';
	}
	return http_response( 401, 'authentication required', array( 'WWW-Authenticate' => $challenge ) );
}

function dav_propfind( &$server, $request, $path ) {
	global $violations;
	if ( '/malformed/' === $path ) {
		return http_response( 207, '<not-xml' );
	}
	if ( '/empty-xml/' === $path ) {
		return http_response( 207 );
	}
	if ( '/xxe/' === $path ) {
		return http_response( 207, '<!DOCTYPE multistatus [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><multistatus xmlns="DAV:">&xxe;</multistatus>' );
	}
	if ( '/redirect/' === $path ) {
		return http_response( 301, '', array( 'Location' => '/' ) );
	}
	if ( '/' !== $path && BACKUPPLUS_DAV_DIRECTORY !== $path ) {
		return http_response( 404 );
	}
	$requested = new DOMDocument();
	$valid     = @$requested->loadXML( $request['body'], LIBXML_NONET );
	$xpath     = $valid ? new DOMXPath( $requested ) : null;
	if ( $xpath ) {
		$xpath->registerNamespace( 'd', 'DAV:' );
	}
	if ( ! isset( $request['headers']['depth'] ) || '1' !== $request['headers']['depth'] || ! $xpath || 0 === $xpath->query( '//d:prop/d:resourcetype' )->length || 0 === $xpath->query( '//d:prop/d:getcontentlength' )->length ) {
		$violations[] = 'PROPFIND request lacked Depth: 1 or the expected properties';
		return http_response( 400 );
	}
	$xml   = '<?xml version=\'1.0\' encoding=\'utf-8\'?>' . "\n" . '<multistatus xmlns="DAV:">';
	$entry = function ( $href, $size = 0, $collection = false, $status = '200 OK' ) use ( &$xml ) {
		$xml .= '<response><href>' . htmlspecialchars( $href, ENT_XML1 ) . '</href><propstat><prop><resourcetype>' . ( $collection ? '<collection />' : '' ) . '</resourcetype><getcontentlength>' . $size . '</getcontentlength></prop><status>HTTP/1.1 ' . $status . '</status></propstat></response>';
	};
	$entry( dav_quote( $path ), 0, true );
	$entry( dav_quote( $path . 'backup_directory' ), 0, true );
	$entry( dav_quote( $path . 'backup_denied.zip' ), 0, false, '403 Forbidden' );
	$entry( dav_quote( $path . 'nested/backup_nested.zip' ) );
	$entry( dav_quote( $path . '../backup_escape.zip' ) );
	$entry( dav_quote( $path ) . 'backup_encoded%2Fslash.zip' );
	foreach ( $server['files'] as $name => $data ) {
		if ( substr( $name, 0, (int) strrpos( $name, '/' ) ) . '/' === $path ) {
			// Exercise both absolute URI and absolute path href formats.
			$entry( $server['digest'] ? dav_quote( $name ) : 'https://' . $request['headers']['host'] . dav_quote( $name ), strlen( $data ) );
		}
	}
	return http_response( 207, $xml . '</multistatus>', array( 'Content-Type' => 'application/xml' ) );
}

function webdav_handle( &$server, $request ) {
	$denied = dav_authenticated( $server, $request );
	if ( $denied ) {
		return $denied;
	}
	$target = $request['target'];
	$cut    = strcspn( $target, '?#' );
	$path   = rawurldecode( substr( $target, 0, $cut ) );
	switch ( $request['method'] ) {
		case 'PUT':
			$directory = substr( $path, 0, (int) strrpos( $path, '/' ) ) . '/';
			if ( '/' !== $directory && BACKUPPLUS_DAV_DIRECTORY !== $directory ) {
				return http_response( 409 );
			}
			// Store precisely what arrived; short or repeated bodies fail the size and content checks.
			$status                   = isset( $server['files'][ $path ] ) ? 204 : 201;
			$server['files'][ $path ] = $request['body'];
			return http_response( $status );
		case 'GET':
			if ( ends_with( $path, 'backup_truncated.zip' ) ) {
				return http_response( 200, 'partial archive', array(), 100 );
			}
			if ( ends_with( $path, 'backup_redirect.zip' ) ) {
				return http_response( 302, '', array( 'Location' => '/redirect-target' ) );
			}
			return isset( $server['files'][ $path ] ) ? http_response( 200, $server['files'][ $path ] ) : http_response( 404, 'not found' );
		case 'DELETE':
			if ( ends_with( $path, 'backup_forbidden.zip' ) ) {
				return http_response( 403 );
			}
			if ( ! isset( $server['files'][ $path ] ) ) {
				return http_response( 404 );
			}
			unset( $server['files'][ $path ] );
			return http_response( 204 );
		case 'PROPFIND':
			return dav_propfind( $server, $request, $path );
	}
	return http_response( 501, 'Unsupported method' );
}

function cloud_handle( &$server, $request ) {
	global $violations;
	if ( 'PUT' !== $request['method'] ) {
		return http_response( 501, 'Unsupported method' );
	}
	$path = $request['target'];
	if ( '/redirect' === $path ) {
		return http_response( 302, '', array( 'Location' => '/must-not-follow' ) );
	}
	if ( '/must-not-follow' === $path ) {
		$violations[] = 'Upload redirect was followed';
		return http_response( 500 );
	}
	$body = '/oversized' === $path ? str_repeat( 'X', 1048577 ) : json_encode(
		array(
			'size'   => strlen( $request['body'] ),
			'sha256' => hash( 'sha256', $request['body'] ),
		)
	);
	return http_response( 200, $body, array( 'Content-Type' => 'application/json' ) );
}

/** Parse as many complete requests as the connection holds and answer them. */
function http_serve( $connection, &$server, $handler, $version ) {
	static $reasons = array(
		100 => 'Continue',
		200 => 'OK',
		201 => 'Created',
		204 => 'No Content',
		207 => 'Multi-Status',
		301 => 'Moved Permanently',
		302 => 'Found',
		400 => 'Bad Request',
		401 => 'Unauthorized',
		403 => 'Forbidden',
		404 => 'Not Found',
		409 => 'Conflict',
		500 => 'Internal Server Error',
		501 => 'Not Implemented',
	);
	while ( ! $connection->closing ) {
		$end = strpos( $connection->in, "\r\n\r\n" );
		if ( false === $end ) {
			return;
		}
		$lines   = explode( "\r\n", substr( $connection->in, 0, $end ) );
		$request = explode( ' ', array_shift( $lines ) );
		$headers = array();
		foreach ( $lines as $line ) {
			$pair = explode( ':', $line, 2 );
			if ( 2 === count( $pair ) ) {
				$headers[ strtolower( trim( $pair[0] ) ) ] = trim( $pair[1] );
			}
		}
		$length = isset( $headers['content-length'] ) ? (int) $headers['content-length'] : 0;
		if ( ! $connection->continued && isset( $headers['expect'] ) && '100-continue' === strtolower( $headers['expect'] ) && strlen( $connection->in ) < $end + 4 + $length ) {
			$connection->continued = true;
			$connection->send( $version . " 100 Continue\r\n\r\n" );
		}
		if ( strlen( $connection->in ) < $end + 4 + $length ) {
			return;
		}
		$body                                       = substr( $connection->in, $end + 4, $length );
		$connection->in                             = (string) substr( $connection->in, $end + 4 + $length );
		$connection->continued                      = false;
		list( $status, $content, $extra, $surplus ) = call_user_func_array(
			$handler,
			array(
				&$server,
				array(
					'method'  => $request[0],
					'target'  => isset( $request[1] ) ? $request[1] : '/',
					'headers' => $headers,
					'body'    => $body,
				),
			)
		);
		$response                                   = $version . ' ' . $status . ' ' . ( isset( $reasons[ $status ] ) ? $reasons[ $status ] : 'Status' ) . "\r\n" . 'Content-Length: ' . ( strlen( $content ) + $surplus ) . "\r\n";
		foreach ( $extra as $name => $value ) {
			$response .= $name . ': ' . $value . "\r\n";
		}
		$connection->send( $response . "\r\n" . $content );
		$keep = 'HTTP/1.1' === $version && ( ! isset( $headers['connection'] ) || 'close' !== strtolower( $headers['connection'] ) );
		if ( ! $keep ) {
			// HTTP/1.0 fixtures close after each response, which also ends an incomplete body.
			$connection->close_after_flush();
		}
	}
}

function http_listen( $loop, $secure, $handler, $version, $certificate, $private_key, $digest = false ) {
	$context = stream_context_create(
		array(
			'ssl' => array(
				'local_cert'  => $certificate,
				'local_pk'    => $private_key,
				'verify_peer' => false,
			),
		)
	);
	$socket  = stream_socket_server( ( $secure ? 'tls' : 'tcp' ) . '://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context );
	if ( ! $socket ) {
		fwrite( STDERR, "Unable to listen: $error\n" );
		exit( 1 );
	}
	$server                       = array(
		'files'  => array(),
		'digest' => $digest,
		'socket' => $socket,
	);
	$index                        = count( $GLOBALS['servers'] );
	$GLOBALS['servers'][ $index ] = $server;
	$loop->on_read(
		$socket,
		function ( $listener ) use ( $loop, $index, $handler, $version ) {
			$client = @stream_socket_accept( $listener, 10 );
			if ( $client ) {
				new Integration_Connection(
					$loop,
					$client,
					function ( $connection ) use ( $index, $handler, $version ) {
						http_serve( $connection, $GLOBALS['servers'][ $index ], $handler, $version );
					}
				);
			}
		}
	);
	return (int) substr( strrchr( stream_socket_get_name( $socket, false ), ':' ), 1 );
}

if ( 'cloud' === $mode ) {
	$ports = array( 'tls' => http_listen( $loop, true, 'cloud_handle', 'HTTP/1.1', $certificate, $private_key ) );
} else {
	$ports = array(
		'plain' => http_listen( $loop, false, 'webdav_handle', 'HTTP/1.0', $certificate, $private_key, true ),
		'tls'   => http_listen( $loop, true, 'webdav_handle', 'HTTP/1.0', $certificate, $private_key ),
	);
}
$loop->run(
	array( 'ports' => $ports ),
	function () use ( &$violations ) {
		$files = array();
		foreach ( $GLOBALS['servers'] as $server ) {
			$files[] = array_keys( $server['files'] );
		}
		return array(
			'files'      => $files,
			'violations' => $violations,
		);
	}
);
