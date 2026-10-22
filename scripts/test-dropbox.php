<?php
/** Dropbox storage operations with fake API responses (token crypto is tested separately). */
require __DIR__ . '/fixtures/provider-test-bootstrap.php';

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

$provider = provider_create( 'dropbox' );
provider_expect( 'no_settings' === $provider->listfiles()->get_error_code(), 'Unauthenticated Dropbox listing must return a settings error' );
provider_expect( false === $provider->backup( array( 'backup.zip' ) ) && false === $provider->download( 'backup.zip' ) && 'authentication_fail' === $provider->delete( 'backup.zip' ), 'Unauthenticated Dropbox operations must fail' );
$provider->set_options( array( 'tk_access_token' => 'test-token' ) );
$api = new Provider_Test_Dropbox_API();
$provider->set_storage( $api );
// Use the same hook as Dropbox's optional folder support.
add_filter(
	'backupplus_dropbox_modpath',
	function ( $file ) {
		return 'site/' . $file;
	}
);
$api->pages = array(
	''         => array(
		'code' => 200,
		'body' => (object) array(
			'matches'  => array(
				provider_dropbox_item( '/site/backup_first.zip', 15 ),
				provider_dropbox_item( '/site/nested/backup_other.zip' ),
				provider_dropbox_item( '/elsewhere/backup_other.zip' ),
				provider_dropbox_item( '/site/backup_folder', 10, 'folder' ),
				provider_dropbox_item( '/site/backup_empty.zip', 0 ),
			),
			'has_more' => true,
			'cursor'   => 'page-two',
		),
	),
	'page-two' => array(
		'code' => 200,
		'body' => (object) array(
			'matches'  => array( provider_dropbox_item( '/site/backup_second.zip', 20, 'file', false ) ),
			'has_more' => false,
		),
	),
);
provider_expect(
	array(
		array(
			'name' => 'backup_first.zip',
			'size' => 15,
		),
		array(
			'name' => 'backup_second.zip',
			'size' => 20,
		),
	) === $provider->listfiles(),
	'Dropbox listing must support both metadata formats, paginate, and exclude directories, empty files and other paths'
);
provider_expect( array( 'backup_', '/site', 1000, 'page-two' ) === $api->searches[1], 'Dropbox must use the returned cursor on the next page' );
provider_expect( $provider->download( 'backup_second.zip' ) && 20 === end( $backupplus->downloads )['size'], 'Dropbox download must select the exact listed filename and size' );
provider_expect( false === $provider->download( 'backup_missing.zip' ), 'Dropbox must fail a missing download' );
$api->failure = 'search';
provider_expect( 'response_error' === $provider->listfiles()->get_error_code(), 'Dropbox must return API search failures' );
$api->failure = null;
$name         = 'backup_binary.zip';
$contents     = "Dropbox archive\0\xff";
file_put_contents( $fixture . '/' . $name, $contents );
$hash = md5( $name );
$provider->jobdata_set( 'upload_id_' . $hash, 'saved-session' );
$provider->jobdata_set( 'upload_offset_' . $hash, 3 );
$provider->backup( array( $name ) );
provider_expect( array( array( 'site/' . $name, true, 3, 'saved-session' ) ) === $api->uploads, 'Dropbox must resume using its saved session, offset and configured path' );
provider_expect( $contents === $api->objects[ 'site/' . $name ] && array( $name ) === $backupplus->uploaded && in_array( 100.0, $backupplus->progress, true ), 'Dropbox must record completed binary uploads and progress' );
provider_expect( null === $provider->jobdata_get( 'upload_id_' . $hash ) && null === $provider->jobdata_get( 'upload_offset_' . $hash ), 'Dropbox must clear resume state after success' );
$handle  = fopen( 'php://temp', 'w+' );
$headers = array( 'Range' => 'bytes=3-7' );
provider_expect( true === $provider->chunked_download( $name, $headers, null, $handle ) && array( 'headers' => $headers ) === $api->headers, 'Dropbox must pass byte ranges to the API' );
rewind( $handle );
provider_expect( $contents === stream_get_contents( $handle ), 'Dropbox must write downloaded bytes to the supplied handle' );
$api->failure = 'download';
provider_expect( false === $provider->chunked_download( $name, array(), null, $handle ), 'Dropbox must catch download exceptions' );
fclose( $handle );
$api->failure         = 'upload';
$backupplus->uploaded = array();
$provider->backup( array( $name ) );
provider_expect( array() === $backupplus->uploaded && 1 === $api->refreshes, 'Dropbox must not mark rejected uploads complete and must refresh expired tokens' );
$api->failure = 'delete';
provider_expect( 'file_delete_error' === $provider->delete( $name ), 'Dropbox must report a failed deletion' );
$api->failure = null;
provider_expect( true === $provider->delete( array( $name ) ) && array( 'site/' . $name ) === $api->deleted, 'Dropbox must delete from its configured path' );
foreach ( array( 'archive', 'archivenull', 'nul' ) as $value ) {
	file_put_contents( $fixture . '/repair', $value );
	clearstatcache();
	$handle = fopen( $fixture . '/repair', 'r+' );
	$provider->download_complete( $handle, $fixture . '/repair' );
	fclose( $handle );
	provider_expect( ( 'archivenull' === $value ? 'archive' : $value ) === file_get_contents( $fixture . '/repair' ), 'Dropbox historical repair must strip only a trailing null suffix' );
}
echo "PASS: Dropbox authentication, paginated search, upload resumption, downloads, deletion and failures\n";
