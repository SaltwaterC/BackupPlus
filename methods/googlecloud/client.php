<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}
require_once BACKUPPLUS_DIR . '/includes/class-remote-storage-http.php';

/** Google Cloud Storage JSON API; independent of the Google Drive provider/SDK. */
class BackupPlus_GoogleCloud_Client extends BackupPlus_Remote_Storage_Client {
	protected $hosts = array( 'storage.googleapis.com', 'oauth2.googleapis.com' );
	private $access;
	private $expires = 0;
	private $base;
	public function __construct( $options, $http = null ) {
		parent::__construct( $options, $http );
		$this->required( array( 'bucket' ) );
		if ( ! preg_match( '/^[a-z0-9][a-z0-9._-]{1,220}[a-z0-9]$/', $options['bucket'] ) ) {
			throw new RuntimeException( 'Invalid Cloud Storage bucket name.' );
		}
		$this->base = 'https://storage.googleapis.com/storage/v1/b/' . rawurlencode( $options['bucket'] ) . '/o';
	}
	private static function base64url( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); }
	private function token() {
		if ( $this->access && time() < $this->expires ) {
			return $this->access;
		}
		if ( ! empty( $this->options['service_account'] ) ) {
			$key = is_array( $this->options['service_account'] ) ? $this->options['service_account'] : json_decode( $this->options['service_account'], true );
			if ( ! is_array( $key ) || 'service_account' !== ( $key['type'] ?? '' ) || empty( $key['client_email'] ) || ! is_string( $key['client_email'] ) || empty( $key['private_key'] ) || ! is_string( $key['private_key'] ) ) {
				throw new RuntimeException( 'Invalid Google service account JSON key.' );
			}
			$now    = time();
			$claims = array(
				'iss'   => $key['client_email'],
				'scope' => 'https://www.googleapis.com/auth/devstorage.read_write',
				'aud'   => 'https://oauth2.googleapis.com/token',
				'iat'   => $now,
				'exp'   => $now + 3600,
			);
			$jwt    = self::base64url(
				json_encode(
					array(
						'alg' => 'RS256',
						'typ' => 'JWT',
					)
				)
			) . '.' . self::base64url( json_encode( $claims ) );
			require_once BACKUPPLUS_DIR . '/vendor/autoload.php';
			try {
				$private = \phpseclib3\Crypt\PublicKeyLoader::loadPrivateKey( $key['private_key'] );
				if ( ! $private instanceof \phpseclib3\Crypt\RSA\PrivateKey ) {
					throw new RuntimeException( 'RSA key required' );
				}
				$signature = $private->withHash( 'sha256' )->withPadding( \phpseclib3\Crypt\RSA::SIGNATURE_PKCS1 )->sign( $jwt );
			} catch ( Exception $error ) {
				throw new RuntimeException( 'Cannot sign with the Google service account RSA key.' ); }
			$parameters = array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt . '.' . self::base64url( $signature ),
			);
		} else {
			$this->required( array( 'clientid', 'secret', 'token' ) );
			$parameters = array(
				'grant_type'    => 'refresh_token',
				'client_id'     => $this->options['clientid'],
				'client_secret' => $this->options['secret'],
				'refresh_token' => $this->options['token'],
			);
		}
		$data = $this->json( $this->send( 'POST', 'https://oauth2.googleapis.com/token', array( 'Content-Type' => 'application/x-www-form-urlencoded' ), self::query( $parameters ) ) );
		if ( empty( $data['access_token'] ) ) {
			throw new RuntimeException( 'Google did not return an access token.' );
		}
		$this->access  = $data['access_token'];
		$this->expires = time() + max( 1, (int) ( $data['expires_in'] ?? 3600 ) - 60 );
		return $this->access;
	}
	private function request( $method, $url, $body = null, $headers = array(), $codes = array( 200 ) ) {
		$headers['Authorization'] = 'Bearer ' . $this->token();
		$response                 = $this->send( $method, $url, $headers, $body, array_merge( $codes, array( 401 ) ) );
		if ( 401 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$this->access             = null;
			$headers['Authorization'] = 'Bearer ' . $this->token();
			$response                 = $this->send( $method, $url, $headers, $body, $codes );
		}
		return $response;
	}
	public function listing() {
		$result = array();
		$page   = '';
		$seen   = array();
		do {
			$data = $this->json(
				$this->request(
					'GET',
					$this->base . '?' . self::query(
						array(
							'prefix'    => $this->prefix,
							'pageToken' => $page,
						)
					)
				)
			);
			if ( isset( $data['items'] ) && ! is_array( $data['items'] ) ) {
				throw new RuntimeException( 'Google returned an invalid object listing.' );
			}
			foreach ( $data['items'] ?? array() as $item ) {
				$entry = $this->entry( $item['name'] ?? '', $item['size'] ?? null );
				if ( $entry ) {
					$result[] = $entry;
				}
			}
			$page = $data['nextPageToken'] ?? '';
			if ( $page && isset( $seen[ $page ] ) ) {
				throw new RuntimeException( 'Google repeated a listing continuation token.' );
			}
			$seen[ $page ] = true;
		} while ( $page );
		return $result;
	}
	public function upload( $name, $source, $progress = null ) {
		$key      = $this->key( $name );
		$size     = filesize( $source );
		$url      = 'https://storage.googleapis.com/upload/storage/v1/b/' . rawurlencode( $this->options['bucket'] ) . '/o?' . self::query(
			array(
				'uploadType' => 'resumable',
				'name'       => $key,
			)
		);
		$response = $this->request(
			'POST',
			$url,
			'{}',
			array(
				'Content-Type'            => 'application/json',
				'X-Upload-Content-Type'   => 'application/octet-stream',
				'X-Upload-Content-Length' => $size,
			)
		);
		$session  = wp_remote_retrieve_header( $response, 'location' );
		if ( ! BackupPlus_Remote_Storage_HTTP::valid_url( $session, array( 'storage.googleapis.com' ) ) ) {
			throw new RuntimeException( 'Google returned an untrusted upload endpoint.' );
		}
		$completed = false;
		$this->chunks(
			$source,
			8388608,
			function ( $chunk, $offset, $total ) use ( $session, $progress, &$completed ) {
				$length   = strlen( $chunk );
				$range    = $total ? 'bytes ' . $offset . '-' . ( $offset + $length - 1 ) . '/' . $total : 'bytes */0';
				$response = $this->request(
					'PUT',
					$session,
					$chunk,
					array(
						'Content-Type'   => 'application/octet-stream',
						'Content-Length' => $length,
						'Content-Range'  => $range,
					),
					array( 200, 201, 308 )
				);
				if ( 308 === (int) wp_remote_retrieve_response_code( $response ) ) {
					if ( $offset + $length >= $total || wp_remote_retrieve_header( $response, 'range' ) !== 'bytes=0-' . ( $offset + $length - 1 ) ) {
						throw new RuntimeException( 'Google did not confirm the uploaded byte range.' );
					}
				} else {
					$data = $this->json( $response );
					if ( $offset + $length !== $total || (int) ( $data['size'] ?? -1 ) !== $total ) {
						throw new RuntimeException( 'Google did not confirm the completed archive size.' );
					}
					$completed = true;
				}
				if ( $progress ) {
					$progress( $offset + $length );
				}
			}
		);
		if ( ! $completed ) {
			throw new RuntimeException( 'Google did not commit the uploaded archive.' );
		}
		return true;
	}
	public function download( $name, $destination ) {
		$url      = $this->base . '/' . rawurlencode( $this->key( $name ) );
		$metadata = $this->json( $this->request( 'GET', $url ) );
		if ( ! isset( $metadata['size'] ) || ! is_numeric( $metadata['size'] ) ) {
			throw new RuntimeException( 'Google returned no archive size.' );
		}
		return $this->receive( $url . '?alt=media', array( 'Authorization' => 'Bearer ' . $this->token() ), $destination, $metadata['size'] );
	}
	public function delete( $name ) {
		$this->request( 'DELETE', $this->base . '/' . rawurlencode( $this->key( $name ) ), null, array(), array( 204, 404 ) );
		return true;
	}
}
