<?php
/** Fake Dropbox API client for the Dropbox provider tests (token crypto is tested separately). */

class Provider_Test_Dropbox_API {
	public $pages    = array();
	public $searches = array();
	public $uploads  = array();
	public $deleted  = array();
	public $objects  = array();
	public $failure;
	public $refreshes = 0;
	public $chunk_size;
	public $headers;
	public function setChunkSize( $size ) {
		$this->chunk_size = $size; }
	public function quotaInfo() {
		return array(
			'code' => 200,
			'body' => (object) array(
				'allocation' => (object) array( 'allocated' => 10485760 ),
				'used'       => 0,
			),
		);
	}
	public function refreshAccessToken() {
		++$this->refreshes; }
	public function search( $match, $path, $limit, $cursor ) {
		$this->searches[] = array( $match, $path, $limit, $cursor );
		if ( 'search' === $this->failure ) {
			return array(
				'code' => 503,
				'body' => 'unavailable',
			);
		}
		return $this->pages[ $cursor ];
	}
	public function chunkedUpload( $path, $unused, $remote, $overwrite, $offset, $upload_id, $callback ) {
		$this->uploads[] = array( $remote, $overwrite, $offset, $upload_id );
		if ( 'upload' === $this->failure ) {
			return array( 'code' => 401 );
		}
		$this->objects[ $remote ] = file_get_contents( $path );
		$callback( filesize( $path ), 'upload-session', $path );
		return array( 'code' => 200 );
	}
	public function delete( $file ) {
		if ( 'delete' === $this->failure ) {
			throw new RuntimeException( 'Deletion denied' );
		}
		$this->deleted[] = $file;
		unset( $this->objects[ $file ] );
	}
	public function download( $file, $handle, $options ) {
		if ( 'download' === $this->failure ) {
			throw new RuntimeException( 'Download denied' );
		}
		$this->headers = $options;
		fwrite( $handle, $this->objects[ $file ] );
		return true;
	}
}
function provider_dropbox_item( $name, $size = 10, $tag = 'file', $v2 = true ) {
	$metadata = (object) array(
		'path_display' => $name,
		'size'         => $size,
		'.tag'         => $tag,
	);
	return (object) array( 'metadata' => $v2 ? (object) array( 'metadata' => $metadata ) : $metadata );
}
