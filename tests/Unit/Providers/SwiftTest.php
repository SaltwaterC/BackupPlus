<?php
/** OpenStack and Rackspace Cloud Files behavior without external accounts. */

namespace BackupPlus\Tests\Unit\Providers;

use BackupPlus\Tests\Support\ProviderTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Provider_Test_Swift_Container;
use Provider_Test_Swift_Job;

final class SwiftTest extends ProviderTestCase {

	/** The provider under test, a subclass that hands out the fake service. */
	private $provider;

	private Provider_Test_Swift_Container $container;

	private array $options;

	private string $name = 'backup_binary.zip';

	private string $contents = "Swift archive\0\xff";

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once __DIR__ . '/../../Support/swift-service.php';
	}

	public static function providers(): array {
		return array(
			'openstack'  => array( 'openstack', 'Provider_Test_OpenStack' ),
			'cloudfiles' => array( 'cloudfiles', 'Provider_Test_CloudFiles' ),
		);
	}

	/** The provider configured for the fake container, with a job that makes it use its small-file fallback. */
	private function configure( string $class ): void {
		$this->provider = new $class();
		$this->options  = array_merge(
			$this->provider->get_default_options(),
			array(
				'user'     => 'test-user',
				'password' => 'test-password',
				'apikey'   => 'test-key',
				'path'     => 'backups',
				'tenant'   => 'test-project',
			)
		);
		$this->provider->set_options( array(), false, 'test-instance' );
		$this->provider->set_options( $this->options );
		$this->container         = new Provider_Test_Swift_Container();
		$this->provider->service = $this->container;
		file_put_contents( $this->fixture . '/' . $this->name, $this->contents );
		$backup_methods            = $this->job->backup_methods;
		$this->job                 = new Provider_Test_Swift_Job();
		$this->job->backup_methods = $backup_methods;
		$GLOBALS['backupplus']     = $this->job;
	}

	/** Upload the archive through the small-file fallback and return the deletion context. */
	private function upload( string $id ) {
		$data = $this->provider->backup( array( $this->name ) );
		$this->assertSame( array( $this->name ), $this->job->uploaded, "$id must upload binary archives and record success" );
		return $data;
	}

	/** Upload one segment and assemble it with a manifest. */
	private function upload_segments( string $id ): void {
		$length = strlen( $this->contents );
		$fp     = fopen( 'php://temp', 'w+' );
		fwrite( $fp, $this->contents );
		rewind( $fp );
		$this->assertTrue( $this->provider->chunked_upload( $this->name, $fp, 1, $length, 0, $length - 1, $length ), "$id must upload a segment" );
		fclose( $fp );
		$this->assertTrue( $this->provider->chunked_upload_finish( $this->name ), "$id must assemble segments using a manifest" );
	}

	#[DataProvider( 'providers' )]
	public function test_listing_rejects_missing_credentials( string $id, string $class ): void {
		$provider = new $class();
		$provider->set_options( array(), false, 'test-instance' );
		$this->assertSame( 'no_settings', $provider->listfiles()->get_error_code(), "$id listing must reject missing credentials" );
	}

	#[DataProvider( 'providers' )]
	public function test_archives_are_uploaded( string $id, string $class ): void {
		$this->configure( $class );
		$data = $this->upload( $id );
		$this->assertSame( $this->contents, $this->container->objects[ $this->name ], "$id must upload binary archives and record success" );
		$this->assertSame( 'test-user', $this->provider->service_options['user'], "$id must pass instance credentials to the service" );
		$this->assertSame( $this->container, $data['object'], "$id must return the deletion context" );
		$this->assertSame( 'backups', $data['container'], "$id must return the deletion context" );
	}

	#[DataProvider( 'providers' )]
	public function test_listing( string $id, string $class ): void {
		$this->configure( $class );
		$this->upload( $id );
		$this->assertSame(
			array(
				array(
					'name' => $this->name,
					'size' => strlen( $this->contents ),
				),
			),
			$this->provider->listfiles(),
			"$id must return remote names and sizes"
		);
		$this->assertSame( array(), $this->provider->listfiles( 'unmatched' ), "$id must pass listing filters" );
	}

	#[DataProvider( 'providers' )]
	public function test_downloads( string $id, string $class ): void {
		$this->configure( $class );
		$this->upload( $id );
		$this->assertTrue( $this->provider->download( $this->name ), "$id must download using the remote object size" );
		$this->assertSame( strlen( $this->contents ), end( $this->job->downloads )['size'], "$id must download using the remote object size" );
		$this->assertFalse( $this->provider->download( 'backup_missing.zip' ), "$id must reject missing downloads" );
		$headers = array( 'Range' => 'bytes=0-5' );
		$this->assertSame( $this->contents, $this->provider->chunked_download( $this->name, $headers, $this->container ), "$id must forward download range headers" );
		$this->assertSame( $headers, $this->container->headers, "$id must forward download range headers" );
		$this->container->failure = 'download';
		$this->assertFalse( $this->provider->chunked_download( $this->name, array(), $this->container ), "$id must catch download errors" );
	}

	#[DataProvider( 'providers' )]
	public function test_failed_uploads_are_not_marked_complete( string $id, string $class ): void {
		$this->configure( $class );
		$this->container->failure = 'upload';
		$this->job->uploaded      = array();
		$this->provider->backup( array( $this->name ) );
		$this->assertSame( array(), $this->job->uploaded, "$id must not mark failed uploads complete" );
	}

	/** Check pagination past the service's 1000 object page boundary. */
	#[DataProvider( 'providers' )]
	public function test_listing_is_paginated( string $id, string $class ): void {
		$this->configure( $class );
		for ( $index = 0; $index < 1001; $index++ ) {
			$this->container->objects[ sprintf( 'backup_%04d.zip', $index ) ] = 'data';
		}
		$this->assertCount( 1001, $this->provider->listfiles(), "$id must fetch all listing pages" );
		$this->assertCount( 2, $this->container->lists, "$id must advance the pagination marker" );
		$this->assertSame( 'backup_0999.zip', $this->container->lists[1]['marker'], "$id must advance the pagination marker" );
	}

	#[DataProvider( 'providers' )]
	public function test_segments_are_assembled_with_a_manifest( string $id, string $class ): void {
		$this->configure( $class );
		// Segment uploads rely on the container the provider opened for the preceding small-file upload.
		$this->upload( $id );
		$this->container->objects = array();
		$this->upload_segments( $id );
		$this->assertSame( $this->contents, $this->container->objects[ $this->name ], "$id must assemble segments using a manifest" );
		$this->assertSame( 'backups/chunk-do-not-delete-' . $this->name . '_', $this->container->headers['X-Object-Manifest'], "$id manifest must identify its own container and segments" );
		$this->container->failure = 'manifest';
		$this->assertFalse( $this->provider->chunked_upload_finish( $this->name ), "$id must fail when manifest creation fails" );
	}

	#[DataProvider( 'providers' )]
	public function test_deletion( string $id, string $class ): void {
		$this->configure( $class );
		$data                     = $this->upload( $id );
		$this->container->objects = array();
		$this->upload_segments( $id );
		$this->container->failure = 'delete';
		$this->assertSame( 'file_delete_error', $this->provider->delete( $this->name ), "$id must report deletion errors" );
		$this->container->failure = null;
		$this->assertTrue( $this->provider->delete( array( $this->name ), $data ), "$id must delete both segments and manifest" );
		$this->assertSame( array(), $this->container->objects, "$id must delete both segments and manifest" );
	}

	#[DataProvider( 'providers' )]
	public function test_inaccessible_containers_are_reported( string $id, string $class ): void {
		$this->configure( $class );
		$this->container->failure = 'container';
		$this->assertSame( 'no_access', $this->provider->listfiles()->get_error_code(), "$id must report inaccessible containers" );
		$this->assertSame( 'container_access_error', $this->provider->delete( $this->name ), "$id must report inaccessible containers" );
	}

	#[DataProvider( 'providers' )]
	public function test_service_failures_are_handled_in_every_operation( string $id, string $class ): void {
		$this->configure( $class );
		$this->provider->service_error = true;
		$this->assertSame( 'no_access', $this->provider->listfiles()->get_error_code(), "$id must handle service failures in every operation" );
		$this->assertFalse( $this->provider->backup( array( $this->name ) ), "$id must handle service failures in every operation" );
		$this->assertFalse( $this->provider->download( $this->name ), "$id must handle service failures in every operation" );
		$this->assertSame( 'service_unavailable', $this->provider->delete( $this->name ), "$id must handle service failures in every operation" );
	}
}
