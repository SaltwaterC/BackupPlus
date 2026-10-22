<?php
/** S3, S3-compatible and DreamObjects behavior with fake remote transports. */
require __DIR__ . '/fixtures/provider-test-bootstrap.php';
require BACKUPPLUS_DIR . '/methods/s3/S3.php';
require BACKUPPLUS_DIR . '/methods/s3generic/S3.php';
require BACKUPPLUS_DIR . '/methods/dreamobjects/S3.php';

// Keep the real providers, configuration and bucket access logic. Only the
// transport methods that would issue remote requests are replaced.
trait Provider_Test_S3_Transport {
	public $objects = array();
	public $calls   = array();
	public $failure;
	public $parts = array();
	public function getBucketLocation( $bucket ) {
		return 'us-east-1'; }
	public function putBucket( $bucket, $acl = 'private', $location = false ) {
		return true; }
	public function getBucket( $bucket, $prefix = null, $marker = null, $maxKeys = null, $delimiter = null, $returnCommonPrefixes = false ) {
		$this->calls[] = array( 'list', $bucket, $prefix );
		if ( 'list' === $this->failure ) {
			return false;
		}
		$result = array();
		foreach ( $this->objects[ $bucket ] ?? array() as $name => $contents ) {
			if ( 0 === strpos( $name, (string) $prefix ) ) {
				$result[] = array(
					'name' => $name,
					'size' => strlen( $contents ),
				);
			}
		}
		return $result;
	}
	public function putObjectFile( $file, $bucket, $uri, $acl = 'private', $metaHeaders = array(), $contentType = null, $storageClass = 'STANDARD' ) {
		$this->calls[] = array( 'upload', $bucket, $uri, $acl, $storageClass );
		if ( 'upload' === $this->failure ) {
			return false;
		}
		$this->objects[ $bucket ][ $uri ] = file_get_contents( $file );
		return true;
	}
	public function deleteObject( $bucket, $uri ) {
		$this->calls[] = array( 'delete', $bucket, $uri );
		if ( 'delete' === $this->failure ) {
			throw new RuntimeException( 'Deletion denied' );
		}
		unset( $this->objects[ $bucket ][ $uri ] );
		return true;
	}
	public function getObject( $bucket, $uri, $saveTo = false, $resume = false ) {
		$this->calls[] = array( 'download', $bucket, $uri, $resume );
		if ( 'download' === $this->failure ) {
			return false;
		}
		$contents = $this->objects[ $bucket ][ $uri ];
		if ( $resume && preg_match( '/bytes=(\d+)-(\d+)/', $resume, $matches ) ) {
			$contents = substr( $contents, $matches[1], $matches[2] - $matches[1] + 1 );
		}
		fwrite( $saveTo, $contents );
		return true;
	}
	public function initiateMultipartUpload( $bucket, $uri, $acl = 'private', $metaHeaders = array(), $requestHeaders = array(), $storageClass = 'STANDARD' ) {
		$this->calls[] = array( 'initiate', $bucket, $uri );
		return 'multipart-id';
	}
	public function uploadPart( $bucket, $uri, $uploadId, $filePath, $partNumber, $partSize = 5242880 ) {
		$this->calls[] = array( 'part', $partNumber, $uploadId );
		if ( 'part' === $this->failure && 2 === $partNumber ) {
			return false;
		}
		$this->parts[ $partNumber ] = file_get_contents( $filePath, false, null, ( $partNumber - 1 ) * $partSize, $partSize );
		return md5( $this->parts[ $partNumber ] );
	}
	public function completeMultipartUpload( $bucket, $uri, $uploadId, $parts ) {
		$this->calls[] = array( 'complete', $parts );
		provider_expect( array_map( 'md5', array_values( $this->parts ) ) === $parts, 'Multipart completion must use ordered part ETags' );
		$this->objects[ $bucket ][ $uri ] = implode( '', $this->parts );
		return true;
	}
}
class Provider_Test_Amazon_Transport extends BackupPlus_S3 {
	use Provider_Test_S3_Transport;
}
class Provider_Test_Generic_Transport extends BackupPlus_S3Generic {
	use Provider_Test_S3_Transport;
}
class Provider_Test_Dream_Transport extends BackupPlus_DreamObjects {
	use Provider_Test_S3_Transport;
}

foreach ( array(
	's3'           => 'Provider_Test_Amazon_Transport',
	's3generic'    => 'Provider_Test_Generic_Transport',
	'dreamobjects' => 'Provider_Test_Dream_Transport',
) as $id => $transport_class ) {
	$provider = provider_create( $id );
	$missing  = $provider->getS3( '', '', false, false, false );
	provider_expect( is_wp_error( $missing ) && 'no_settings' === $missing->get_error_code(), "$id must reject missing credentials" );
	$options = array_merge(
		$provider->get_default_options(),
		array(
			'accesskey' => 'test-key',
			'secretkey' => 'test-secret',
			'path'      => 'test-bucket/site/backups',
			'endpoint'  => 's3.us-east-005.dream.io',
		)
	);
	$provider->set_options( $options );
	// Initialize the real transport configuration without sending a request.
	provider_expect( ! is_wp_error( $provider->getS3( 'test-key', 'test-secret', false, false, false ) ), "$id transport must initialize" );
	$transport = new $transport_class( 'test-key', 'test-secret' );
	$provider->set_storage( $transport );
	$backupplus->uploaded = array();
	$name                 = 'backup_binary +#.zip';
	$contents             = "archive\0\xff\ncontents";
	file_put_contents( $fixture . '/' . $name, $contents );
	$data = $provider->backup( array( 'plugins' => $name ) );
	provider_expect( array( $name ) === $backupplus->uploaded && $contents === $transport->objects['test-bucket'][ 'site/backups/' . $name ], "$id must upload bytes to its configured bucket and prefix" );
	provider_expect( 'test-bucket/site/backups' === $data['s3_orig_bucket_name'], "$id upload must retain its deletion context" );
	provider_expect( in_array( array( 'upload', 'test-bucket', 'site/backups/' . $name, 'private', 'STANDARD' ), $transport->calls, true ), "$id archives must use private ACLs" );
	$transport->objects['test-bucket']['another-site/backup_other.zip'] = 'other';
	$transport->objects['test-bucket']['site/backups/backup_empty.zip'] = '';
	provider_expect(
		array(
			array(
				'name' => $name,
				'size' => strlen( $contents ),
			),
		) === $provider->listfiles(),
		"$id listing must strip the prefix and omit empty objects and other sites"
	);
	provider_expect( array() === $provider->listfiles( 'unmatched' ), "$id listing must pass the requested match" );
	$transport->failure = 'list';
	provider_expect( array() === $provider->listfiles(), "$id must handle failed listing responses" );
	$transport->failure = null;
	provider_expect( $provider->download( $name ) && strlen( $contents ) === end( $backupplus->downloads )['size'], "$id download must use the listed remote size" );
	provider_expect( false === $provider->download( 'backup_missing.zip' ), "$id must fail a missing download" );
	$handle = fopen( 'php://temp', 'w+' );
	$provider->chunked_download( $name, array( 'Range' => 'bytes=2-6' ), $transport, $handle );
	rewind( $handle );
	provider_expect( substr( $contents, 2, 5 ) === stream_get_contents( $handle ), "$id must forward byte ranges and the remote prefix" );
	$transport->failure = 'download';
	provider_expect( false === $provider->chunked_download( $name, array(), $transport, $handle ), "$id must report transport download failures" );
	fclose( $handle );
	$transport->failure   = 'upload';
	$backupplus->uploaded = array();
	$provider->backup( array( 'plugins' => $name ) );
	provider_expect( array() === $backupplus->uploaded, "$id must not mark a failed upload complete" );
	$transport->failure = 'delete';
	provider_expect( 'file_delete_error' === $provider->delete( $name ), "$id must propagate deletion exceptions" );
	$transport->failure = null;
	provider_expect( true === $provider->delete( array( $name ), $data ) && ! isset( $transport->objects['test-bucket'][ 'site/backups/' . $name ] ), "$id must delete using the upload context" );
	provider_expect( true === $provider->delete( $name ), "$id deletion must also accept a single filename" );
	provider_expect( isset( $transport->objects['test-bucket']['another-site/backup_other.zip'] ), "$id deletion must preserve other prefixes" );

	// A failed second part must resume with the existing upload ID and first ETag.
	$large          = 'backup_multipart.zip';
	$large_contents = str_repeat( 'A', 5242880 ) . 'last part';
	file_put_contents( $fixture . '/' . $large, $large_contents );
	$transport->failure = 'part';
	$provider->backup( array( 'plugins' => $large ) );
	provider_expect( array() === $backupplus->uploaded && ! isset( $transport->objects['test-bucket'][ 'site/backups/' . $large ] ), "$id incomplete multipart upload must not be marked complete" );
	$transport->failure = null;
	$transport->calls   = array();
	$provider->backup( array( 'plugins' => $large ) );
	provider_expect( array( $large ) === $backupplus->uploaded && $large_contents === $transport->objects['test-bucket'][ 'site/backups/' . $large ], "$id must complete a resumed multipart upload" );
	provider_expect(
		array( array( 'part', 2, 'multipart-id' ) ) === array_values(
			array_filter(
				$transport->calls,
				function ( $call ) {
					return in_array( $call[0], array( 'part', 'initiate' ), true );
				}
			)
		),
		"$id resumption must skip the completed first part and reuse its upload ID"
	);
	provider_expect( false === $transport->useExceptions, "$id must reset transport exception mode after operations" );
	$transport->objects['root-bucket'] = array(
		'backup_root.zip'             => 'root',
		'subfolder/backup_nested.zip' => 'nested',
	);
	provider_expect(
		array(
			array(
				'name' => 'backup_root.zip',
				'size' => 4,
			),
		) === $provider->listfiles_with_path(
			'root-bucket',
			''
		),
		"$id root listing must exclude subfolders by default"
	);
	provider_expect( 2 === count( $provider->listfiles_with_path( 'root-bucket', '', true ) ), "$id must allow callers to include subfolders explicitly" );
	$provider->set_options( array_merge( $options, array( 'path' => 'second-bucket' ) ) );
	provider_expect( empty( $provider->get_storage() ), "$id switching instances must clear the cached transport" );
	echo "PASS: $id upload, listing, ranged download, deletion, failures and multipart resumption\n";
}
