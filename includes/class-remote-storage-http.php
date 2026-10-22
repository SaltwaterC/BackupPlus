<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}

/** HTTP/file primitives. This class contains no provider or authentication policy. */
class BackupPlus_Remote_Storage_HTTP {
	private $tls;
	public function __construct( $tls = array() ) {
		$this->tls = $tls; }

	public static function valid_url( $url, $hosts ) {
		if ( ! is_string( $url ) || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
			return false;
		}
		$parts = parse_url( $url );
		if ( ! $parts || 'https' !== ( $parts['scheme'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) || ( isset( $parts['port'] ) && 443 !== $parts['port'] ) ) {
			return false;
		}
		$host = strtolower( $parts['host'] ?? '' );
		foreach ( $hosts as $allowed ) {
			if ( $host === $allowed || ( '.' === substr( $allowed, 0, 1 ) && strlen( $host ) > strlen( $allowed ) && substr( $host, -strlen( $allowed ) ) === $allowed ) ) {
				return true;
			}
		}
		return false;
	}

	/** No redirects: callers must explicitly validate any returned endpoint. */
	public function request( $method, $url, $headers = array(), $body = null, $destination = null ) {
		if ( ! empty( $this->tls['nossl'] ) ) {
			throw new RuntimeException( 'This storage service requires HTTPS. Enable SSL in Expert settings.' );
		}
		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'body'        => $body,
			'timeout'     => 120,
			'redirection' => 0,
			'sslverify'   => empty( $this->tls['disableverify'] ),
		);
		if ( empty( $this->tls['useservercerts'] ) ) {
			$args['sslcertificates'] = BACKUPPLUS_DIR . '/includes/cacert.pem';
		}
		if ( null !== $destination ) {
			$args['stream']   = true;
			$args['filename'] = $destination;
		} else {
			$args['limit_response_size'] = 8388608;
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'Storage network request failed. Check connectivity and certificate settings.' );
		}
		return $response;
	}

	/** WordPress's HTTP API cannot stream upload bodies; use cURL for this primitive. */
	public function upload( $url, $source, $headers, $progress = null ) {
		if ( ! function_exists( 'curl_init' ) ) {
			throw new RuntimeException( 'Streaming uploads require the PHP cURL extension.' );
		}
		if ( ! empty( $this->tls['nossl'] ) ) {
			throw new RuntimeException( 'This storage service requires HTTPS. Enable SSL in Expert settings.' );
		}
		$handle = fopen( $source, 'rb' );
		if ( ! $handle ) {
			throw new RuntimeException( 'Cannot open the local archive.' );
		}
		$curl             = curl_init( $url );
		$response_headers = array();
		$response_body    = '';
		try {
			$lines = array();
			foreach ( $headers as $key => $value ) {
				$lines[] = $key . ': ' . $value;
			}
			curl_setopt_array(
				$curl,
				array(
					CURLOPT_UPLOAD          => true,
					CURLOPT_INFILE          => $handle,
					CURLOPT_INFILESIZE      => filesize( $source ),
					CURLOPT_HTTPHEADER      => $lines,
					CURLOPT_FOLLOWLOCATION  => false,
					CURLOPT_CONNECTTIMEOUT  => 30,
					CURLOPT_TIMEOUT         => 0,
					CURLOPT_LOW_SPEED_LIMIT => 1,
					CURLOPT_LOW_SPEED_TIME  => 120,
					CURLOPT_SSL_VERIFYPEER  => empty( $this->tls['disableverify'] ),
					CURLOPT_SSL_VERIFYHOST  => empty( $this->tls['disableverify'] ) ? 2 : 0,
					CURLOPT_HEADERFUNCTION  => function ( $curl, $line ) use ( &$response_headers ) {
						if ( false !== strpos( $line, ':' ) ) {
							list($key, $value) = explode( ':', $line, 2 );
							$response_headers[ strtolower( trim( $key ) ) ] = trim( $value ); }
						return strlen( $line );
					},
					CURLOPT_WRITEFUNCTION   => function ( $curl, $data ) use ( &$response_body ) {
						if ( strlen( $response_body ) + strlen( $data ) > 1048576 ) {
							return 0;
						}
						$response_body .= $data;
						return strlen( $data );
					},
				)
			);
			if ( defined( 'CURLOPT_PROTOCOLS_STR' ) ) {
				curl_setopt( $curl, CURLOPT_PROTOCOLS_STR, 'HTTPS' );
			} else {
				curl_setopt( $curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS );
			}
			if ( empty( $this->tls['useservercerts'] ) ) {
				curl_setopt( $curl, CURLOPT_CAINFO, BACKUPPLUS_DIR . '/includes/cacert.pem' );
			} elseif ( ini_get( 'curl.cainfo' ) ) {
				curl_setopt( $curl, CURLOPT_CAINFO, ini_get( 'curl.cainfo' ) );
			}
			if ( class_exists( 'WP_HTTP_Proxy' ) ) {
				$proxy = new WP_HTTP_Proxy();
				if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
					curl_setopt( $curl, CURLOPT_PROXY, $proxy->host() . ':' . $proxy->port() );
					if ( $proxy->use_authentication() ) {
						curl_setopt( $curl, CURLOPT_PROXYUSERPWD, $proxy->authentication() );
					}
				}
			}
			if ( $progress ) {
				curl_setopt( $curl, CURLOPT_NOPROGRESS, false );
				curl_setopt(
					$curl,
					CURLOPT_XFERINFOFUNCTION,
					function ( $curl, $total, $received, $upload_total, $sent ) use ( $progress ) {
						$progress( $sent );
						return 0;
					}
				);
			}
			if ( false === curl_exec( $curl ) ) {
				throw new RuntimeException( 'Storage streaming upload failed (cURL ' . curl_errno( $curl ) . ').' );
			}
			return array(
				'response' => array( 'code' => curl_getinfo( $curl, CURLINFO_RESPONSE_CODE ) ),
				'headers'  => $response_headers,
				'body'     => $response_body,
			);
		} finally {
			fclose( $handle );
			if ( PHP_VERSION_ID < 80000 ) {
				curl_close( $curl );
			}
		}
	}
}

/** Generic bounded I/O, validation and error handling for provider-owned clients. */
abstract class BackupPlus_Remote_Storage_Client {
	protected $options;
	protected $http;
	protected $hosts = array();
	protected $prefix;
	public function __construct( $options, $http = null ) {
		$this->options = $options;
		$this->http    = $http ?: new BackupPlus_Remote_Storage_HTTP( $options );
		if ( isset( $options['path'] ) && ! is_string( $options['path'] ) ) {
			throw new RuntimeException( 'The remote folder or prefix must be text.' );
		}
		$this->prefix = trim( $options['path'] ?? '', '/' );
		if ( '' !== $this->prefix ) {
			foreach ( explode( '/', $this->prefix ) as $part ) {
				if ( ! self::valid_filename( $part ) ) {
					throw new RuntimeException( 'Invalid remote folder or prefix.' );
				}
			}
			$this->prefix .= '/';
		}
	}
	public static function valid_filename( $name ) {
		return is_string( $name ) && '' !== $name && ! in_array( $name, array( '.', '..' ), true ) && ! preg_match( '/[\/\\\\\x00-\x1f\x7f]/', $name );
	}
	protected function key( $name ) {
		if ( ! self::valid_filename( $name ) ) {
			throw new RuntimeException( 'Invalid backup filename.' );
		}
		return $this->prefix . $name;
	}
	protected static function query( $values ) {
		return http_build_query( $values, '', '&', PHP_QUERY_RFC3986 ); }
	protected function send( $method, $url, $headers = array(), $body = null, $codes = array( 200 ), $destination = null ) {
		if ( ! BackupPlus_Remote_Storage_HTTP::valid_url( $url, $this->hosts ) ) {
			throw new RuntimeException( 'Storage returned an untrusted endpoint.' );
		}
		$response = $this->http->request( $method, $url, $headers, $body, $destination );
		$code     = (int) wp_remote_retrieve_response_code( $response );
		if ( ! in_array( $code, $codes, true ) ) {
			throw new RuntimeException( 'Storage request failed (HTTP ' . $code . ').' );
		}
		return $response;
	}
	protected function json( $response ) {
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'Storage returned an invalid JSON response.' );
		}
		return $data;
	}
	protected function required( $keys ) {
		foreach ( $keys as $key ) {
			if ( ! isset( $this->options[ $key ] ) || ! is_scalar( $this->options[ $key ] ) || '' === trim( (string) $this->options[ $key ] ) ) {
				throw new RuntimeException( 'Missing storage setting: ' . $key . '.' );
			}
		}
	}
	protected function entry( $key, $size ) {
		if ( ! is_string( $key ) || 0 !== strpos( $key, $this->prefix ) ) {
			return null;
		}
		$name = substr( $key, strlen( $this->prefix ) );
		return self::valid_filename( $name ) && is_numeric( $size ) && $size >= 0 ? array(
			'name' => $name,
			'size' => (int) $size,
		) : null;
	}
	protected function chunks( $source, $size, $callback ) {
		$handle = fopen( $source, 'rb' );
		if ( ! $handle ) {
			throw new RuntimeException( 'Cannot read the local archive.' );
		}
		try {
			$total  = filesize( $source );
			$offset = 0;
			do {
				$data = '';
				while ( strlen( $data ) < $size && ! feof( $handle ) ) {
					$part = fread( $handle, $size - strlen( $data ) );
					if ( false === $part || ( '' === $part && ! feof( $handle ) ) ) {
						throw new RuntimeException( 'Failed to read the local archive.' );
					}
					$data .= $part;
				}
				if ( '' === $data && $offset < $total ) {
					throw new RuntimeException( 'Local archive was truncated during upload.' );
				}
				$callback( $data, $offset, $total );
				$offset += strlen( $data );
			} while ( $offset < $total );
		} finally {
			fclose( $handle ); }
	}
	/** Download to a sibling temporary file and publish only a complete response. */
	protected function receive( $url, $headers, $destination, $size ) {
		if ( ! ( is_int( $size ) || ( is_string( $size ) && ctype_digit( $size ) ) ) || $size < 0 || $size > PHP_INT_MAX ) {
			throw new RuntimeException( 'Storage returned an invalid archive size.' );
		}
		$headers['Accept-Encoding'] = 'identity';
		$temporary                  = tempnam( dirname( $destination ), '.backupplus-download-' );
		if ( false === $temporary ) {
			throw new RuntimeException( 'Cannot create a local download file.' );
		}
		try {
			$response = $this->send( 'GET', $url, $headers, null, array( 200 ), $temporary );
			clearstatcache( true, $temporary );
			if ( filesize( $temporary ) !== (int) $size ) {
				throw new RuntimeException( 'Storage returned an incomplete archive.' );
			}
			if ( ! rename( $temporary, $destination ) ) {
				throw new RuntimeException( 'Cannot publish the downloaded archive.' );
			}
			return true;
		} finally {
			if ( file_exists( $temporary ) ) {
				unlink( $temporary );
			}
		}
	}
	abstract public function listing();
	abstract public function upload( $name, $source, $progress = null );
	abstract public function download( $name, $destination );
	abstract public function delete( $name );
}
