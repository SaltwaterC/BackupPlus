<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}
require_once BACKUPPLUS_DIR . '/includes/class-remote-storage-http.php';

/** pCloud HTTP/JSON API with streamed uploads and US/EU data residency. */
class BackupPlus_Pcloud_Client extends BackupPlus_Remote_Storage_Client {
	protected $hosts = array( 'api.pcloud.com', 'eapi.pcloud.com', '.pcloud.com' );
	private $base;
	private $folder_id;
	public function __construct( $options, $http = null ) {
		if ( ! array_key_exists( 'path', $options ) && isset( $options['folder'] ) ) {
			$options['path'] = $options['folder'];
		}
		parent::__construct( $options, $http );
		$this->required( array( 'pclauth' ) );
		$location = (string) ( $options['location'] ?? '1' );
		if ( ! in_array( $location, array( '1', '2' ), true ) ) {
			throw new RuntimeException( 'pCloud location must be 1 (US) or 2 (Europe).' );
		}
		$this->base = 'https://' . ( '1' === $location ? 'api.pcloud.com' : 'eapi.pcloud.com' );
	}
	private function call( $method, $parameters, $allowed = array( 0 ) ) {
		$parameters['access_token'] = $this->options['pclauth'];
		$data                       = $this->json( $this->send( 'POST', $this->base . '/' . $method, array( 'Content-Type' => 'application/x-www-form-urlencoded' ), self::query( $parameters ) ) );
		if ( ! isset( $data['result'] ) || ! in_array( (int) $data['result'], $allowed, true ) ) {
			throw new RuntimeException( 'pCloud request failed (API ' . (int) ( $data['result'] ?? -1 ) . ').' );
		}
		return $data;
	}
	private function folder_id() {
		if ( null !== $this->folder_id ) {
			return $this->folder_id;
		}
		$id = 0;
		foreach ( array_filter( explode( '/', trim( $this->prefix, '/' ) ), 'strlen' ) as $folder ) {
			$data = $this->call(
				'createfolderifnotexists',
				array(
					'folderid' => $id,
					'name'     => $folder,
				)
			);
			if ( ! isset( $data['metadata']['folderid'] ) ) {
				throw new RuntimeException( 'pCloud returned no folder ID.' );
			}
			$id = $data['metadata']['folderid'];
		}
		$this->folder_id = $id;
		return $id;
	}
	public function listing() {
		$data = $this->call(
			'listfolder',
			array(
				'path'      => '/' . trim( $this->prefix, '/' ),
				'recursive' => 0,
			),
			array( 0, 2005 )
		);
		if ( 2005 === (int) $data['result'] ) {
			return array();
		}
		if ( ! isset( $data['metadata']['contents'] ) || ! is_array( $data['metadata']['contents'] ) ) {
			throw new RuntimeException( 'pCloud returned an invalid folder listing.' );
		}
		$result = array();
		foreach ( $data['metadata']['contents'] as $item ) {
			if ( ! empty( $item['isfolder'] ) ) {
				continue;
			}
			$entry = $this->entry( $this->prefix . ( $item['name'] ?? '' ), $item['size'] ?? null );
			if ( $entry ) {
				$result[] = $entry;
			}
		}
		return $result;
	}
	public function upload( $name, $source, $progress = null ) {
		$this->key( $name );
		$parameters = array(
			'access_token' => $this->options['pclauth'],
			'folderid'     => $this->folder_id(),
			'filename'     => $name,
			'nopartial'    => 1,
		);
		$url        = $this->base . '/uploadfile?' . self::query( $parameters );
		$response   = $this->http->upload( $url, $source, array( 'Content-Type' => 'application/octet-stream' ), $progress );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			throw new RuntimeException( 'pCloud streaming upload failed.' );
		}
		$data = $this->json( $response );
		if ( 0 !== ( $data['result'] ?? -1 ) || ( $data['metadata'][0]['name'] ?? '' ) !== $name || (int) ( $data['metadata'][0]['size'] ?? -1 ) !== filesize( $source ) ) {
			throw new RuntimeException( 'pCloud did not confirm the uploaded archive name and size.' );
		}
		if ( $progress ) {
			$progress( filesize( $source ) );
		}
		return true;
	}
	public function download( $name, $destination ) {
		$metadata = $this->call( 'stat', array( 'path' => '/' . $this->key( $name ) ) );
		$file     = $metadata['metadata'] ?? array();
		if ( ! empty( $file['isfolder'] ) || ! isset( $file['fileid'] ) || ! isset( $file['size'] ) ) {
			throw new RuntimeException( 'The requested pCloud item is not a file.' );
		}
		$data = $this->call(
			'getfilelink',
			array(
				'fileid'        => $file['fileid'],
				'forcedownload' => 1,
			)
		);
		$host = $data['hosts'][0] ?? '';
		$path = $data['path'] ?? '';
		if ( ! is_string( $path ) || '/' !== substr( $path, 0, 1 ) ) {
			throw new RuntimeException( 'pCloud returned an invalid download path.' );
		}
		return $this->receive( 'https://' . $host . $path, array(), $destination, $file['size'] );
	}
	public function delete( $name ) {
		$this->call( 'deletefile', array( 'path' => '/' . $this->key( $name ) ), array( 0, 2009 ) );
		return true;
	}
}
