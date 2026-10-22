<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}
require_once BACKUPPLUS_DIR . '/includes/class-remote-storage-http.php';

/** Backblaze B2 Native API, including large files and version-aware retention. */
class BackupPlus_Backblaze_Client extends BackupPlus_Remote_Storage_Client {
	protected $hosts = array( 'api.backblazeb2.com', '.backblazeb2.com', '.backblaze.com' );
	private $auth;
	private $api;
	private $download_url;
	private function authorize() {
		if ( $this->auth ) {
			return;
		}
		$this->required( array( 'application_key_id', 'application_key', 'bucketid' ) );
		$data    = $this->json( $this->send( 'GET', 'https://api.backblazeb2.com/b2api/v2/b2_authorize_account', array( 'Authorization' => 'Basic ' . base64_encode( $this->options['application_key_id'] . ':' . $this->options['application_key'] ) ) ) );
		$storage = $data['apiInfo']['storageApi'] ?? $data;
		if ( empty( $data['authorizationToken'] ) || empty( $storage['apiUrl'] ) || empty( $storage['downloadUrl'] ) ) {
			throw new RuntimeException( 'B2 returned an invalid authorization response.' );
		}
		if ( ! BackupPlus_Remote_Storage_HTTP::valid_url( $storage['apiUrl'], $this->hosts ) || ! BackupPlus_Remote_Storage_HTTP::valid_url( $storage['downloadUrl'], $this->hosts ) ) {
			throw new RuntimeException( 'B2 returned an untrusted service endpoint.' );
		}
		$this->auth         = $data['authorizationToken'];
		$this->api          = rtrim( $storage['apiUrl'], '/' ) . '/b2api/v2/';
		$this->download_url = rtrim( $storage['downloadUrl'], '/' );
	}
	private function call( $operation, $parameters ) {
		$this->authorize();
		return $this->json(
			$this->send(
				'POST',
				$this->api . $operation,
				array(
					'Authorization' => $this->auth,
					'Content-Type'  => 'application/json',
				),
				json_encode( $parameters )
			)
		);
	}
	private function files( $versions = false, $prefix = null ) {
		$parameters = array(
			'bucketId'     => $this->options['bucketid'] ?? '',
			'prefix'       => null === $prefix ? $this->prefix : $prefix,
			'maxFileCount' => 1000,
		);
		$files      = array();
		$seen       = array();
		do {
			$data = $this->call( $versions ? 'b2_list_file_versions' : 'b2_list_file_names', $parameters );
			if ( ! isset( $data['files'] ) || ! is_array( $data['files'] ) ) {
				throw new RuntimeException( 'B2 returned an invalid file listing.' );
			}
			$files = array_merge( $files, $data['files'] );
			$next  = $data['nextFileName'] ?? null;
			if ( null !== $next ) {
				$marker = $next . ':' . ( $data['nextFileId'] ?? '' );
				if ( isset( $seen[ $marker ] ) ) {
					throw new RuntimeException( 'B2 repeated a listing continuation marker.' );
				}
				$seen[ $marker ]             = true;
				$parameters['startFileName'] = $next;
				if ( $versions ) {
					$parameters['startFileId'] = $data['nextFileId'];
				}
			}
		} while ( null !== $next );
		return $files;
	}
	public function listing() {
		$result = array();
		foreach ( $this->files() as $file ) {
			if ( 'upload' !== ( $file['action'] ?? 'upload' ) ) {
				continue;
			}
			$entry = $this->entry( $file['fileName'] ?? '', $file['contentLength'] ?? null );
			if ( $entry ) {
				$result[] = $entry;
			}
		}
		return $result;
	}
	public function upload( $name, $source, $progress = null ) {
		$key  = $this->key( $name );
		$size = filesize( $source );
		if ( $size <= 8388608 ) {
			$target = $this->call( 'b2_get_upload_url', array( 'bucketId' => $this->options['bucketid'] ) );
			$this->chunks(
				$source,
				8388608,
				function ( $data ) use ( $target, $key, $progress, $size ) {
					$result = $this->json(
						$this->send(
							'POST',
							$target['uploadUrl'],
							array(
								'Authorization'     => $target['authorizationToken'],
								'X-Bz-File-Name'    => rawurlencode( $key ),
								'X-Bz-Content-Sha1' => sha1( $data ),
								'Content-Type'      => 'application/octet-stream',
								'Content-Length'    => strlen( $data ),
							),
							$data
						)
					);
					if ( ( $result['contentLength'] ?? null ) !== $size || ( $result['contentSha1'] ?? '' ) !== sha1( $data ) ) {
						throw new RuntimeException( 'B2 did not confirm the uploaded archive checksum and size.' );
					}
					if ( $progress ) {
						$progress( $size );
					}
				}
			);
			return true;
		}
		if ( $size > 8388608 * 10000 ) {
			throw new RuntimeException( 'Split this backup into archives smaller than 80 GiB.' );
		}
		$large = $this->call(
			'b2_start_large_file',
			array(
				'bucketId'    => $this->options['bucketid'],
				'fileName'    => $key,
				'contentType' => 'application/octet-stream',
			)
		);
		$id    = $large['fileId'] ?? '';
		if ( ! $id ) {
			throw new RuntimeException( 'B2 returned no large-file ID.' );
		}
		try {
			$target = $this->call( 'b2_get_upload_part_url', array( 'fileId' => $id ) );
			$hashes = array();
			$this->chunks(
				$source,
				8388608,
				function ( $data, $offset ) use ( $target, &$hashes, $progress ) {
					$hash   = sha1( $data );
					$result = $this->json(
						$this->send(
							'POST',
							$target['uploadUrl'],
							array(
								'Authorization'     => $target['authorizationToken'],
								'X-Bz-Part-Number'  => count( $hashes ) + 1,
								'X-Bz-Content-Sha1' => $hash,
								'Content-Type'      => 'application/octet-stream',
								'Content-Length'    => strlen( $data ),
							),
							$data
						)
					);
					if ( ( $result['contentSha1'] ?? '' ) !== $hash ) {
						throw new RuntimeException( 'B2 returned an incorrect part checksum.' );
					}
					$hashes[] = $hash;
					if ( $progress ) {
						$progress( $offset + strlen( $data ) );
					}
				}
			);
			$result = $this->call(
				'b2_finish_large_file',
				array(
					'fileId'        => $id,
					'partSha1Array' => $hashes,
				)
			);
			if ( ( $result['contentLength'] ?? null ) !== $size ) {
				throw new RuntimeException( 'B2 returned an incorrect completed file size.' );
			}
		} catch ( Exception $error ) {
			try {
				$this->call( 'b2_cancel_large_file', array( 'fileId' => $id ) ); } catch ( Exception $ignored ) {
				}
				throw $error;
		}
		return true;
	}
	public function download( $name, $destination ) {
		$key = $this->key( $name );
		foreach ( $this->files( false, $key ) as $file ) {
			if ( ( $file['fileName'] ?? '' ) !== $key || 'upload' !== ( $file['action'] ?? 'upload' ) ) {
				continue;
			}
			$url = $this->download_url . '/b2api/v2/b2_download_file_by_id?' . self::query( array( 'fileId' => $file['fileId'] ) );
			return $this->receive( $url, array( 'Authorization' => $this->auth ), $destination, $file['contentLength'] );
		}
		throw new RuntimeException( 'The requested B2 archive was not found.' );
	}
	public function delete( $name ) {
		$key = $this->key( $name );
		// Enumerate before deleting so pagination remains stable as versions disappear.
		foreach ( $this->files( true, $key ) as $file ) {
			if ( ( $file['fileName'] ?? '' ) === $key ) {
				$this->call(
					'b2_delete_file_version',
					array(
						'fileName' => $key,
						'fileId'   => $file['fileId'],
					)
				);
			}
		}
		return true;
	}
}
