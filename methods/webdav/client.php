<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}

/** Streaming WebDAV transport. Credentials are never included in request URLs. */
class BackupPlus_WebDAV_Client {

	private $url;
	private $options;
	private $tls;

	/** @return BackupPlus_WebDAV_Client|WP_Error */
	public static function open( $options, $tls = array() ) {
		if ( ! function_exists( 'curl_init' ) || ! function_exists( 'curl_exec' ) ) {
			return new WP_Error( 'webdav_missing_curl', __( 'WebDAV requires the PHP cURL extension.', 'backupplus' ) );
		}
		if ( ! class_exists( 'DOMDocument' ) ) {
			return new WP_Error( 'webdav_missing_xml', __( 'WebDAV requires the PHP DOM XML extension.', 'backupplus' ) );
		}
		$options = self::normalise_options( $options );
		$scheme  = str_replace( array( 'webdavs', 'webdav', '://' ), array( 'https', 'http', '' ), strtolower( $options['webdav'] ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'webdav_invalid_scheme', __( 'Choose HTTP or HTTPS for WebDAV.', 'backupplus' ) );
		}
		if ( '' === $options['host'] ) {
			return new WP_Error( 'no_settings', __( 'No WebDAV settings were found.', 'backupplus' ) );
		}
		$host = parse_url( $scheme . '://' . $options['host'] );
		if ( ! $host || empty( $host['host'] ) || isset( $host['user'] ) || isset( $host['pass'] ) || isset( $host['path'] ) || isset( $host['query'] ) || isset( $host['fragment'] ) || preg_match( '/[\s\x00-\x1f\x7f\\\\]/', $options['host'] ) ) {
			return new WP_Error( 'webdav_invalid_host', __( 'Enter a WebDAV hostname without a URL, path or credentials.', 'backupplus' ) );
		}
		$port = '' !== $options['port'] ? $options['port'] : ( isset( $host['port'] ) ? (string) $host['port'] : '' );
		if ( '' !== $port && ( ! ctype_digit( $port ) || (int) $port < 1 || (int) $port > 65535 ) ) {
			return new WP_Error( 'webdav_invalid_port', __( 'Enter a valid WebDAV port (1–65535).', 'backupplus' ) );
		}
		if ( preg_match( '/[\x00-\x1f\x7f:]/', $options['user'] ) || preg_match( '/[\x00-\x1f\x7f]/', $options['pass'] ) ) {
			return new WP_Error( 'webdav_invalid_credentials', __( 'Invalid WebDAV credentials.', 'backupplus' ) );
		}
		$path     = trim( $options['path'], '/' );
		$segments = '' === $path ? array() : explode( '/', $path );
		foreach ( $segments as $segment ) {
			if ( ! self::valid_filename( $segment ) ) {
				return new WP_Error( 'webdav_invalid_path', __( 'Enter a WebDAV directory without empty, dot or parent path segments.', 'backupplus' ) );
			}
		}
		if ( 'https' === $scheme && ! empty( $tls['nossl'] ) ) {
			return new WP_Error( 'webdav_ssl_disabled', __( 'HTTPS WebDAV requires SSL/TLS. Enable SSL in the expert settings.', 'backupplus' ) );
		}
		$version = curl_version();
		if ( ! in_array( $scheme, $version['protocols'], true ) ) {
			return new WP_Error( 'webdav_missing_protocol', __( 'The PHP cURL extension does not support the selected WebDAV protocol.', 'backupplus' ) );
		}
		$client      = new self();
		$client->url = $scheme . '://' . $host['host'] . ( '' === $port ? '' : ':' . $port ) . '/';
		if ( $segments ) {
			$client->url .= implode( '/', array_map( 'rawurlencode', $segments ) ) . '/';
		}
		$client->options = $options;
		$client->tls     = $tls;
		return $client;
	}

	/** Preserve component settings and accept legacy URL-only settings. */
	public static function normalise_options( $options ) {
		$options  = is_array( $options ) ? $options : array();
		$defaults = array(
			'webdav' => 'https://',
			'host'   => '',
			'port'   => '',
			'user'   => '',
			'pass'   => '',
			'path'   => '',
		);
		foreach ( $defaults as $key => $default ) {
			$options[ $key ] = isset( $options[ $key ] ) && is_scalar( $options[ $key ] ) ? (string) $options[ $key ] : $default;
			if ( ! in_array( $key, array( 'user', 'pass' ), true ) ) {
				$options[ $key ] = trim( $options[ $key ] );
			}
		}
		if ( '' === $options['host'] && ! empty( $options['url'] ) && is_string( $options['url'] ) ) {
			$url   = preg_replace( '#^webdavs://#i', 'https://', preg_replace( '#^webdav://#i', 'http://', $options['url'] ) );
			$parts = parse_url( $url );
			if ( $parts && isset( $parts['scheme'], $parts['host'] ) && ! isset( $parts['query'] ) && ! isset( $parts['fragment'] ) ) {
				$options['webdav'] = $parts['scheme'] . '://';
				$options['host']   = $parts['host'];
				$options['port']   = isset( $parts['port'] ) ? (string) $parts['port'] : '';
				$options['path']   = isset( $parts['path'] ) ? rawurldecode( $parts['path'] ) : '';
				foreach ( array( 'user', 'pass' ) as $key ) {
					if ( isset( $parts[ $key ] ) ) {
						$options[ $key ] = rawurldecode( $parts[ $key ] );
					}
				}
			}
		}
		return $options;
	}

	/** Prevent local traversal and encode remote basenames exactly once. */
	public static function valid_filename( $file ) {
		return is_string( $file ) && '' !== $file && '.' !== $file && '..' !== $file && ! preg_match( '/[\x00-\x1f\x7f\/\\\\]/', $file );
	}

	/**
	 * Issue one request. Redirects are rejected so credentials and backup data stay
	 * at the configured endpoint. File bodies stream directly through cURL.
	 *
	 * @return Array|WP_Error HTTP status and body, or a transport error.
	 */
	private function request( $method, $file = '', $source = null, $destination = null, $body = '', $progress = null ) {
		if ( 'PROPFIND' !== $method && ! self::valid_filename( $file ) ) {
			return new WP_Error( 'webdav_invalid_filename', __( 'Invalid backup filename.', 'backupplus' ) );
		}
		$curl = curl_init();
		if ( false === $curl ) {
			return new WP_Error( 'webdav_curl_init', __( 'Unable to initialise WebDAV cURL.', 'backupplus' ) );
		}
		$status   = 0;
		$response = '';
		$headers  = array( 'Expect:' );
		$settings = array(
			CURLOPT_URL             => $this->url . ( '' === $file ? '' : rawurlencode( $file ) ),
			CURLOPT_CUSTOMREQUEST   => $method,
			CURLOPT_USERNAME        => $this->options['user'],
			CURLOPT_PASSWORD        => $this->options['pass'],
			CURLOPT_HTTPAUTH        => CURLAUTH_BASIC | CURLAUTH_DIGEST,
			CURLOPT_FOLLOWLOCATION  => false,
			CURLOPT_CONNECTTIMEOUT  => 20,
			CURLOPT_LOW_SPEED_LIMIT => 1,
			CURLOPT_LOW_SPEED_TIME  => 60,
			CURLOPT_SSL_VERIFYPEER  => empty( $this->tls['disableverify'] ),
			CURLOPT_SSL_VERIFYHOST  => empty( $this->tls['disableverify'] ) ? 2 : 0,
			CURLOPT_HEADERFUNCTION  => function ( $handle, $header ) use ( &$status, &$response ) {
				if ( preg_match( '#^HTTP/\S+ (\d{3})#', $header, $matches ) ) {
					$status = (int) $matches[1];
					$response = '';
				}
				return strlen( $header );
			},
			CURLOPT_WRITEFUNCTION   => function ( $handle, $data ) use ( &$response, &$status, $destination ) {
				if ( is_resource( $destination ) ) {
					return 200 === $status ? fwrite( $destination, $data ) : strlen( $data );
				}
				// Bound XML and error responses; backup archives never enter memory.
				if ( strlen( $response ) + strlen( $data ) > 16 * 1024 * 1024 ) {
					return 0;
				}
				$response .= $data;
				return strlen( $data );
			},
		);
		if ( defined( 'CURLOPT_PROTOCOLS_STR' ) ) {
			$settings[ CURLOPT_PROTOCOLS_STR ] = 'http,https';
		} else {
			$settings[ CURLOPT_PROTOCOLS ] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
		}
		$ca_file = empty( $this->tls['useservercerts'] ) ? BACKUPPLUS_DIR . '/includes/cacert.pem' : ini_get( 'curl.cainfo' );
		if ( $ca_file ) {
			$settings[ CURLOPT_CAINFO ] = $ca_file;
		}
		if ( is_resource( $source ) ) {
			$stat                           = fstat( $source );
			$settings[ CURLOPT_UPLOAD ]     = true;
			$settings[ CURLOPT_INFILE ]     = $source;
			$settings[ CURLOPT_INFILESIZE ] = $stat['size'];
			$headers[]                      = 'Content-Type: application/octet-stream';
		} elseif ( 'PROPFIND' === $method ) {
			$headers[]                      = 'Depth: 1';
			$headers[]                      = 'Content-Type: application/xml; charset=utf-8';
			$settings[ CURLOPT_POSTFIELDS ] = $body;
		}
		if ( is_callable( $progress ) ) {
			$settings[ CURLOPT_NOPROGRESS ]       = false;
			$settings[ CURLOPT_XFERINFOFUNCTION ] = function ( $handle, $download_size, $downloaded, $upload_size, $uploaded ) use ( $progress ) {
				call_user_func( $progress, $uploaded );
				return 0;
			};
		}
		$settings[ CURLOPT_HTTPHEADER ] = $headers;
		if ( ! curl_setopt_array( $curl, $settings ) ) {
			return new WP_Error( 'webdav_curl_options', __( 'Unable to configure the WebDAV request.', 'backupplus' ) );
		}
		$result = curl_exec( $curl );
		// PHP does not expose CURLOPT_SEEKFUNCTION. Digest negotiation can ask
		// libcurl to rewind a streamed PUT; repeat once with its cached challenge.
		// CURLE_SEND_FAIL_REWIND (65) is also missing from PHP's constants.
		if ( false === $result && 65 === curl_errno( $curl ) && is_resource( $source ) && rewind( $source ) ) {
			$result = curl_exec( $curl );
		}
		if ( false === $result ) {
			return new WP_Error( 'webdav_transport', sprintf( __( 'WebDAV transfer failed: %s', 'backupplus' ), curl_error( $curl ) ) );
		}
		return array(
			'status' => (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE ),
			'body'   => $response,
		);
	}

	private function check_response( $response, $accepted ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( in_array( $response['status'], $accepted, true ) ) {
			return true;
		}
		return new WP_Error( 'webdav_http_error', sprintf( __( 'WebDAV server returned HTTP %d. Check the endpoint, credentials and permissions.', 'backupplus' ), $response['status'] ) );
	}

	/** PUT is restarted in full on retry; partial PUT is not portable WebDAV. */
	public function upload( $file, $local, $progress = null ) {
		$source = @fopen( $local, 'rb' );
		if ( ! $source ) {
			return new WP_Error( 'webdav_local_read', __( 'Unable to read the local backup file.', 'backupplus' ) );
		}
		try {
			return $this->check_response( $this->request( 'PUT', $file, $source, null, '', $progress ), array( 200, 201, 204 ) );
		} finally {
			fclose( $source );
		}
	}

	/** A failed transfer must never replace an existing local archive. */
	public function download( $file, $local ) {
		$temp = tempnam( dirname( $local ), '.webdav-' );
		if ( false === $temp ) {
			return new WP_Error( 'webdav_local_write', __( 'Unable to create a local download file.', 'backupplus' ) );
		}
		$destination = @fopen( $temp, 'wb' );
		try {
			if ( ! $destination ) {
				return new WP_Error( 'webdav_local_write', __( 'Unable to write the local download file.', 'backupplus' ) );
			}
			$result = $this->check_response( $this->request( 'GET', $file, null, $destination ), array( 200 ) );
			fclose( $destination );
			$destination = null;
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( ! rename( $temp, $local ) ) {
				return new WP_Error( 'webdav_local_rename', __( 'Unable to save the downloaded backup file.', 'backupplus' ) );
			}
			return true;
		} finally {
			if ( is_resource( $destination ) ) {
				fclose( $destination );
			}
			if ( is_file( $temp ) ) {
				unlink( $temp );
			}
		}
	}

	public function delete( $file ) {
		return $this->check_response( $this->request( 'DELETE', $file ), array( 200, 204, 404, 410 ) );
	}

	/** Parse namespace-aware DAV properties, accepting only immediate child files. */
	public function listing() {
		$body     = '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/><d:getcontentlength/></d:prop></d:propfind>';
		$response = $this->request( 'PROPFIND', '', null, null, $body );
		$result   = $this->check_response( $response, array( 207 ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$xml      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		try {
			// Do not expand entities, load DTDs or make XML-triggered network calls.
			$valid = '' !== $response['body'] && $xml->loadXML( $response['body'], LIBXML_NONET );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		if ( ! $valid || $xml->doctype || 'DAV:' !== $xml->documentElement->namespaceURI || 'multistatus' !== $xml->documentElement->localName ) {
			return new WP_Error( 'webdav_invalid_xml', __( 'Invalid WebDAV directory response.', 'backupplus' ) );
		}
		$xpath = new DOMXPath( $xml );
		$xpath->registerNamespace( 'd', 'DAV:' );
		$base  = rawurldecode( parse_url( $this->url, PHP_URL_PATH ) );
		$files = array();
		foreach ( $xpath->query( '/d:multistatus/d:response' ) as $entry ) {
			$href = $xpath->evaluate( 'string(d:href)', $entry );
			$path = parse_url( $href, PHP_URL_PATH );
			if ( ! is_string( $path ) ) {
				continue;
			}
			$path = rawurldecode( $path );
			// RFC 4918 hrefs are absolute URIs or absolute paths.
			if ( 0 !== strpos( $path, $base ) ) {
				continue;
			}
			$name = substr( $path, strlen( $base ) );
			if ( ! self::valid_filename( $name ) ) {
				continue;
			}
			$file    = array( 'name' => $name );
			$is_file = false;
			foreach ( $xpath->query( 'd:propstat', $entry ) as $propstat ) {
				if ( ! preg_match( '#^HTTP/\S+ 200(?:\s|$)#', $xpath->evaluate( 'string(d:status)', $propstat ) ) ) {
					continue;
				}
				if ( $xpath->query( 'd:prop/d:resourcetype', $propstat )->length ) {
					$is_file = ! $xpath->query( 'd:prop/d:resourcetype/d:collection', $propstat )->length;
				}
				$size = $xpath->evaluate( 'string(d:prop/d:getcontentlength)', $propstat );
				if ( ctype_digit( $size ) ) {
					$file['size'] = (int) $size;
				}
			}
			if ( $is_file ) {
				$files[ $name ] = $file;
			}
		}
		return array_values( $files );
	}
}
