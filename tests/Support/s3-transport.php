<?php
/** Fake S3 transports for the S3, S3-compatible and DreamObjects provider tests. Non-namespaced, loaded once per process. */

require_once BACKUPPLUS_DIR . '/methods/backup-module.php';
require_once BACKUPPLUS_DIR . '/methods/s3/S3.php';
require_once BACKUPPLUS_DIR . '/methods/s3generic/S3.php';
require_once BACKUPPLUS_DIR . '/methods/dreamobjects/S3.php';

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
		\PHPUnit\Framework\Assert::assertSame( array_map( 'md5', array_values( $this->parts ) ), $parts, 'Multipart completion must use ordered part ETags' );
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
