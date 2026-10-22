<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}
require_once BACKUPPLUS_DIR . '/includes/class-remote-storage-http.php';

/** Microsoft Graph driveItem API. No dependency on another storage provider. */
class BackupPlus_OneDrive_Client extends BackupPlus_Remote_Storage_Client {
	protected $hosts = array( 'login.microsoftonline.com', 'graph.microsoft.com', '.1drv.com', '.onedrive.com', '.sharepoint.com', '.sharepoint-df.com' );
	private $access;
	private $expires = 0;
	private $base;
	private $save_token;
	public function __construct( $options, $http = null, $save_token = null ) {
		// Preserve the former add-on's folder setting.
		if ( ! array_key_exists( 'path', $options ) && isset( $options['folder'] ) ) {
			$options['path'] = $options['folder'];
		}
		parent::__construct( $options, $http );
		$this->save_token = $save_token;
		$this->required( array( 'client_id', 'client_secret', 'refresh_token' ) );
		$tenant = $options['tenant'] ?? 'common';
		if ( ! is_string( $tenant ) || ! preg_match( '/^[a-zA-Z0-9.-]+$/', $tenant ) ) {
			throw new RuntimeException( 'Invalid Microsoft tenant.' );
		}
		if ( isset( $options['driveid'] ) && ! is_string( $options['driveid'] ) ) {
			throw new RuntimeException( 'Invalid OneDrive drive ID.' );
		}
		$this->base = 'https://graph.microsoft.com/v1.0/' . ( ! empty( $options['driveid'] ) ? 'drives/' . rawurlencode( $options['driveid'] ) : 'me/drive' );
	}
	private function token() {
		if ( $this->access && time() < $this->expires ) {
			return $this->access;
		}
		$parameters = array(
			'grant_type'    => 'refresh_token',
			'client_id'     => $this->options['client_id'],
			'client_secret' => $this->options['client_secret'],
			'refresh_token' => $this->options['refresh_token'],
		);
		$url        = 'https://login.microsoftonline.com/' . rawurlencode( $this->options['tenant'] ?? 'common' ) . '/oauth2/v2.0/token';
		$data       = $this->json( $this->send( 'POST', $url, array( 'Content-Type' => 'application/x-www-form-urlencoded' ), self::query( $parameters ) ) );
		if ( empty( $data['access_token'] ) ) {
			throw new RuntimeException( 'Microsoft did not return an access token.' );
		}
		$this->access  = $data['access_token'];
		$this->expires = time() + max( 1, (int) ( $data['expires_in'] ?? 3600 ) - 60 );
		if ( ! empty( $data['refresh_token'] ) && $data['refresh_token'] !== $this->options['refresh_token'] ) {
			if ( $this->save_token ) {
				( $this->save_token )( $data['refresh_token'], $this->options );
			}
			$this->options['refresh_token'] = $data['refresh_token'];
		}
		return $this->access;
	}
	private function request( $method, $url, $body = null, $headers = array(), $codes = array( 200 ) ) {
		// A Graph bearer token may only be sent to the Graph origin.
		if ( ! BackupPlus_Remote_Storage_HTTP::valid_url( $url, array( 'graph.microsoft.com' ) ) ) {
			throw new RuntimeException( 'Untrusted Microsoft Graph endpoint.' );
		}
		$headers['Authorization'] = 'Bearer ' . $this->token();
		$response                 = $this->send( $method, $url, $headers, $body, array_merge( $codes, array( 401 ) ) );
		if ( 401 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$this->access             = null;
			$headers['Authorization'] = 'Bearer ' . $this->token();
			$response                 = $this->send( $method, $url, $headers, $body, $codes );
		}
		return $response;
	}
	private function item_url( $name ) {
		$key = $this->key( $name );
		if ( preg_match( '/[:*?"<>|]/', $key ) ) {
			throw new RuntimeException( 'The archive path contains characters OneDrive does not support.' );
		}
		return $this->base . '/root:/' . implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );
	}
	public function listing() {
		$folder = trim( $this->prefix, '/' );
		$url    = $this->base . ( $folder ? '/root:/' . implode( '/', array_map( 'rawurlencode', explode( '/', $folder ) ) ) . ':/children' : '/root/children' );
		$result = array();
		$seen   = array();
		do {
			if ( isset( $seen[ $url ] ) ) {
				throw new RuntimeException( 'OneDrive repeated a listing continuation URL.' );
			}
			$seen[ $url ] = true;
			$data         = $this->json( $this->request( 'GET', $url ) );
			if ( ! isset( $data['value'] ) || ! is_array( $data['value'] ) ) {
				throw new RuntimeException( 'OneDrive returned an invalid folder listing.' );
			}
			foreach ( $data['value'] as $item ) {
				if ( ! isset( $item['file'] ) || isset( $item['remoteItem'] ) || isset( $item['folder'] ) ) {
					continue;
				}
				$entry = $this->entry( $this->prefix . ( $item['name'] ?? '' ), $item['size'] ?? null );
				if ( $entry ) {
					$result[] = $entry;
				}
			}
			$url = $data['@odata.nextLink'] ?? '';
		} while ( $url );
		return $result;
	}
	public function upload( $name, $source, $progress = null ) {
		$url  = $this->item_url( $name );
		$size = filesize( $source );
		if ( 0 === $size ) {
			$this->request( 'PUT', $url . ':/content', '', array( 'Content-Type' => 'application/octet-stream' ), array( 200, 201 ) );
			if ( $progress ) {
				$progress( 0 );
			}
			return true;
		}
		$body    = json_encode(
			array(
				'item' => array(
					'@microsoft.graph.conflictBehavior' => 'replace',
					'name'                              => $name,
				),
			)
		);
		$data    = $this->json( $this->request( 'POST', $url . ':/createUploadSession', $body, array( 'Content-Type' => 'application/json' ) ) );
		$session = $data['uploadUrl'] ?? '';
		if ( ! BackupPlus_Remote_Storage_HTTP::valid_url( $session, $this->hosts ) || 'login.microsoftonline.com' === parse_url( $session, PHP_URL_HOST ) ) {
			throw new RuntimeException( 'OneDrive returned an untrusted upload endpoint.' );
		}
		$completed = false;
		try {
			// 10 MiB is an exact multiple of Graph's required 320 KiB fragment size.
			$this->chunks(
				$source,
				10485760,
				function ( $chunk, $offset, $total ) use ( $session, &$completed, $progress ) {
					$length   = strlen( $chunk );
					$headers  = array(
						'Content-Length' => $length,
						'Content-Range'  => 'bytes ' . $offset . '-' . ( $offset + $length - 1 ) . '/' . $total,
						'Content-Type'   => 'application/octet-stream',
					);
					$response = $this->send( 'PUT', $session, $headers, $chunk, array( 200, 201, 202 ) );
					$data     = $this->json( $response );
					if ( 202 === (int) wp_remote_retrieve_response_code( $response ) ) {
						if ( $offset + $length >= $total || ( $data['nextExpectedRanges'][0] ?? '' ) !== ( $offset + $length ) . '-' ) {
							throw new RuntimeException( 'OneDrive did not confirm the uploaded byte range.' );
						}
					} else {
						if ( $offset + $length !== $total || (int) ( $data['size'] ?? -1 ) !== $total ) {
							throw new RuntimeException( 'OneDrive did not confirm the completed archive size.' );
						}
						$completed = true;
					}
					if ( $progress ) {
						$progress( $offset + $length );
					}
				}
			);
		} catch ( Exception $error ) {
			try {
				$this->send( 'DELETE', $session, array(), null, array( 204, 404 ) ); } catch ( Exception $ignored ) {
				}
				throw $error;
		}
		if ( ! $completed ) {
			throw new RuntimeException( 'OneDrive did not commit the uploaded archive.' );
		}
		return true;
	}
	public function download( $name, $destination ) {
		$url  = $this->item_url( $name );
		$data = $this->json( $this->request( 'GET', $url ) );
		if ( ! isset( $data['file'] ) || ! isset( $data['size'] ) ) {
			throw new RuntimeException( 'The requested OneDrive item is not a file.' );
		}
		$link = $data['@microsoft.graph.downloadUrl'] ?? '';
		if ( ! $link ) {
			$response = $this->request( 'GET', $url . ':/content', null, array(), array( 302 ) );
			$link     = wp_remote_retrieve_header( $response, 'location' );
		}
		$headers = 'graph.microsoft.com' === parse_url( $link, PHP_URL_HOST ) ? array( 'Authorization' => 'Bearer ' . $this->token() ) : array();
		return $this->receive( $link, $headers, $destination, $data['size'] );
	}
	public function delete( $name ) {
		$this->request( 'DELETE', $this->item_url( $name ), null, array(), array( 204, 404 ) );
		return true;
	}
}
