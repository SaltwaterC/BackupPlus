<?php
/** S3, S3-compatible and DreamObjects behavior with fake remote transports. */

namespace BackupPlus\Tests\Unit\Providers;

use BackupPlus\Tests\Support\ProviderTestCase;
use BackupPlus_BackupModule;
use PHPUnit\Framework\Attributes\DataProvider;

final class S3Test extends ProviderTestCase {

	private BackupPlus_BackupModule $provider;

	/** The fake transport; the class differs per provider. */
	private $transport;

	private array $options;

	private string $name = 'backup_binary +#.zip';

	private string $contents = "archive\0\xff\ncontents";

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once __DIR__ . '/../../Support/s3-transport.php';
	}

	public static function provider_ids(): array {
		return array(
			's3'           => array( 's3' ),
			's3generic'    => array( 's3generic' ),
			'dreamobjects' => array( 'dreamobjects' ),
		);
	}

	public static function providers(): array {
		return array(
			's3'           => array( 's3', 'Provider_Test_Amazon_Transport' ),
			's3generic'    => array( 's3generic', 'Provider_Test_Generic_Transport' ),
			'dreamobjects' => array( 'dreamobjects', 'Provider_Test_Dream_Transport' ),
		);
	}

	/** The provider configured for a bucket and prefix, with the fake transport installed as its storage. */
	private function configure( string $id, string $transport_class ): void {
		$this->provider = $this->create_provider( $id );
		$this->options  = array_merge(
			$this->provider->get_default_options(),
			array(
				'accesskey' => 'test-key',
				'secretkey' => 'test-secret',
				'path'      => 'test-bucket/site/backups',
				'endpoint'  => 's3.us-east-005.dream.io',
			)
		);
		$this->provider->set_options( $this->options );
		// Initialize the real transport configuration without sending a request.
		$this->assertFalse( is_wp_error( $this->provider->getS3( 'test-key', 'test-secret', false, false, false ) ), "$id transport must initialize" );
		$this->transport = new $transport_class( 'test-key', 'test-secret' );
		$this->provider->set_storage( $this->transport );
		file_put_contents( $this->fixture . '/' . $this->name, $this->contents );
	}

	/** Upload the archive and return the deletion context. */
	private function upload( string $id ) {
		$this->job->uploaded = array();
		$data                = $this->provider->backup( array( 'plugins' => $this->name ) );
		$this->assertSame( array( $this->name ), $this->job->uploaded, "$id must upload bytes to its configured bucket and prefix" );
		return $data;
	}

	#[DataProvider( 'provider_ids' )]
	public function test_missing_credentials_are_rejected( string $id ): void {
		$provider = $this->create_provider( $id );
		$missing  = $provider->getS3( '', '', false, false, false );
		$this->assertTrue( is_wp_error( $missing ), "$id must reject missing credentials" );
		$this->assertSame( 'no_settings', $missing->get_error_code(), "$id must reject missing credentials" );
	}

	#[DataProvider( 'providers' )]
	public function test_archives_are_uploaded_to_the_bucket_and_prefix( string $id, string $transport_class ): void {
		$this->configure( $id, $transport_class );
		$data = $this->upload( $id );
		$this->assertSame( $this->contents, $this->transport->objects['test-bucket'][ 'site/backups/' . $this->name ], "$id must upload bytes to its configured bucket and prefix" );
		$this->assertSame( 'test-bucket/site/backups', $data['s3_orig_bucket_name'], "$id upload must retain its deletion context" );
		$this->assertContains( array( 'upload', 'test-bucket', 'site/backups/' . $this->name, 'private', 'STANDARD' ), $this->transport->calls, "$id archives must use private ACLs" );
	}

	#[DataProvider( 'providers' )]
	public function test_listing( string $id, string $transport_class ): void {
		$this->configure( $id, $transport_class );
		$this->upload( $id );
		$this->transport->objects['test-bucket']['another-site/backup_other.zip'] = 'other';
		$this->transport->objects['test-bucket']['site/backups/backup_empty.zip'] = '';
		$this->assertSame(
			array(
				array(
					'name' => $this->name,
					'size' => strlen( $this->contents ),
				),
			),
			$this->provider->listfiles(),
			"$id listing must strip the prefix and omit empty objects and other sites"
		);
		$this->assertSame( array(), $this->provider->listfiles( 'unmatched' ), "$id listing must pass the requested match" );
		$this->transport->failure = 'list';
		$this->assertSame( array(), $this->provider->listfiles(), "$id must handle failed listing responses" );
	}

	#[DataProvider( 'providers' )]
	public function test_downloads( string $id, string $transport_class ): void {
		$this->configure( $id, $transport_class );
		$this->upload( $id );
		$this->assertTrue( $this->provider->download( $this->name ), "$id download must use the listed remote size" );
		$this->assertSame( strlen( $this->contents ), end( $this->job->downloads )['size'], "$id download must use the listed remote size" );
		$this->assertFalse( $this->provider->download( 'backup_missing.zip' ), "$id must fail a missing download" );
		$handle = fopen( 'php://temp', 'w+' );
		$this->provider->chunked_download( $this->name, array( 'Range' => 'bytes=2-6' ), $this->transport, $handle );
		rewind( $handle );
		$this->assertSame( substr( $this->contents, 2, 5 ), stream_get_contents( $handle ), "$id must forward byte ranges and the remote prefix" );
		$this->transport->failure = 'download';
		$this->assertFalse( $this->provider->chunked_download( $this->name, array(), $this->transport, $handle ), "$id must report transport download failures" );
		fclose( $handle );
	}

	#[DataProvider( 'providers' )]
	public function test_failed_uploads_are_not_marked_complete( string $id, string $transport_class ): void {
		$this->configure( $id, $transport_class );
		$this->transport->failure = 'upload';
		$this->job->uploaded      = array();
		$this->provider->backup( array( 'plugins' => $this->name ) );
		$this->assertSame( array(), $this->job->uploaded, "$id must not mark a failed upload complete" );
	}

	#[DataProvider( 'providers' )]
	public function test_deletion( string $id, string $transport_class ): void {
		$this->configure( $id, $transport_class );
		$data = $this->upload( $id );
		$this->transport->objects['test-bucket']['another-site/backup_other.zip'] = 'other';
		$this->transport->failure = 'delete';
		$this->assertSame( 'file_delete_error', $this->provider->delete( $this->name ), "$id must propagate deletion exceptions" );
		$this->transport->failure = null;
		$this->assertTrue( $this->provider->delete( array( $this->name ), $data ), "$id must delete using the upload context" );
		$this->assertFalse( isset( $this->transport->objects['test-bucket'][ 'site/backups/' . $this->name ] ), "$id must delete using the upload context" );
		$this->assertTrue( $this->provider->delete( $this->name ), "$id deletion must also accept a single filename" );
		$this->assertTrue( isset( $this->transport->objects['test-bucket']['another-site/backup_other.zip'] ), "$id deletion must preserve other prefixes" );
		$this->assertFalse( $this->transport->useExceptions, "$id must reset transport exception mode after operations" );
	}

	/** A failed second part must resume with the existing upload ID and first ETag. */
	#[DataProvider( 'providers' )]
	public function test_multipart_uploads_resume( string $id, string $transport_class ): void {
		$this->configure( $id, $transport_class );
		$large          = 'backup_multipart.zip';
		$large_contents = str_repeat( 'A', 5242880 ) . 'last part';
		file_put_contents( $this->fixture . '/' . $large, $large_contents );
		$this->transport->failure = 'part';
		$this->job->uploaded      = array();
		$this->provider->backup( array( 'plugins' => $large ) );
		$this->assertSame( array(), $this->job->uploaded, "$id incomplete multipart upload must not be marked complete" );
		$this->assertFalse( isset( $this->transport->objects['test-bucket'][ 'site/backups/' . $large ] ), "$id incomplete multipart upload must not be marked complete" );
		$this->transport->failure = null;
		$this->transport->calls   = array();
		$this->provider->backup( array( 'plugins' => $large ) );
		$this->assertSame( array( $large ), $this->job->uploaded, "$id must complete a resumed multipart upload" );
		$this->assertSame( $large_contents, $this->transport->objects['test-bucket'][ 'site/backups/' . $large ], "$id must complete a resumed multipart upload" );
		$this->assertSame(
			array( array( 'part', 2, 'multipart-id' ) ),
			array_values(
				array_filter(
					$this->transport->calls,
					function ( $call ) {
						return in_array( $call[0], array( 'part', 'initiate' ), true );
					}
				)
			),
			"$id resumption must skip the completed first part and reuse its upload ID"
		);
		$this->assertFalse( $this->transport->useExceptions, "$id must reset transport exception mode after operations" );
	}

	#[DataProvider( 'providers' )]
	public function test_root_listings_and_instance_switching( string $id, string $transport_class ): void {
		$this->configure( $id, $transport_class );
		$this->transport->objects['root-bucket'] = array(
			'backup_root.zip'             => 'root',
			'subfolder/backup_nested.zip' => 'nested',
		);
		$this->assertSame(
			array(
				array(
					'name' => 'backup_root.zip',
					'size' => 4,
				),
			),
			$this->provider->listfiles_with_path( 'root-bucket', '' ),
			"$id root listing must exclude subfolders by default"
		);
		$this->assertCount( 2, $this->provider->listfiles_with_path( 'root-bucket', '', true ), "$id must allow callers to include subfolders explicitly" );
		$this->provider->set_options( array_merge( $this->options, array( 'path' => 'second-bucket' ) ) );
		$this->assertEmpty( $this->provider->get_storage(), "$id switching instances must clear the cached transport" );
	}
}
