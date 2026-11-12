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

	public static function probes(): array {
		return array(
			's3'           => array( 'Provider_Test_Amazon_Probe', 'Provider_Test_Amazon_Transport', 'BackupPlus_S3' ),
			's3generic'    => array( 'Provider_Test_Generic_Probe', 'Provider_Test_Generic_Transport', 'BackupPlus_S3Generic' ),
			'dreamobjects' => array( 'Provider_Test_Dream_Probe', 'Provider_Test_Dream_Transport', 'BackupPlus_DreamObjects' ),
		);
	}

	/** A provider of the probe class, configured like the factory-built one. */
	private function probe( string $probe_class ): BackupPlus_BackupModule {
		$provider = new $probe_class();
		$provider->set_options( $provider->get_default_options(), false, 'test-instance' );
		return $provider;
	}

	/** BPS-16: without a TLS-capable transport, no client is built unless the administrator chose nossl. */
	#[DataProvider( 'probes' )]
	public function test_missing_tls_transport_is_an_error_unless_nossl( string $probe_class, string $transport_class, string $internal ): void {
		foreach ( array(
			'no cURL'          => array( false, false ),
			'cURL without SSL' => array( true, false ),
		) as $case => list( $curl, $curl_tls ) ) {
			$provider             = $this->probe( $probe_class );
			$provider->curl       = $curl;
			$provider->curl_tls   = $curl_tls;
			$provider->stream_tls = false;
			$refused              = $provider->getS3( 'test-key', 'test-secret', false, false, false );
			$this->assertTrue( is_wp_error( $refused ), "$probe_class with $case must refuse to connect" );
			$this->assertSame( 's3_no_tls', $refused->get_error_code(), "$probe_class with $case must refuse to connect" );
		}
		$provider           = $this->probe( $probe_class );
		$provider->curl_tls = false;
		$this->assertFalse( is_wp_error( $provider->getS3( 'test-key', 'test-secret', false, false, true ) ), "$probe_class must honour an explicit nossl preference" );
	}

	/** BPS-16: only the AWS SDK path may use Guzzle's stream handler, and only when cURL is absent. */
	#[DataProvider( 'probes' )]
	public function test_tls_transport_selection( string $probe_class, string $transport_class, string $internal ): void {
		$provider = $this->probe( $probe_class );
		$compat   = $internal . '_Compat';
		$this->assertTrue( $provider->probe_tls_transport( $internal ), "$probe_class must accept cURL with SSL" );
		$provider->curl_tls = false;
		$this->assertFalse( $provider->probe_tls_transport( $internal ), "$probe_class internal library must require cURL SSL" );
		$this->assertFalse( $provider->probe_tls_transport( $compat ), "$probe_class AWS SDK path must not use cURL without SSL" );
		$provider->curl = false;
		$this->assertFalse( $provider->probe_tls_transport( $internal ), "$probe_class internal library must require cURL" );
		$this->assertTrue( $provider->probe_tls_transport( $compat ), "$probe_class AWS SDK path must use TLS through the stream handler" );
		$provider->stream_tls = false;
		$this->assertFalse( $provider->probe_tls_transport( $compat ), "$probe_class AWS SDK path must require an HTTPS stream wrapper" );
	}

	/** BPS-16: bucket access never downgrades a client to plain HTTP, even if cURL SSL support is not detected. */
	#[DataProvider( 'probes' )]
	public function test_bucket_access_does_not_downgrade_to_http( string $probe_class, string $transport_class, string $internal ): void {
		$provider = $this->probe( $probe_class );
		$provider->set_options(
			array_merge(
				$provider->get_default_options(),
				array(
					'accesskey' => 'test-key',
					'secretkey' => 'test-secret',
					'path'      => 'test-bucket/site/backups',
					'endpoint'  => 's3.us-east-005.dream.io',
				)
			)
		);
		$provider->curl_tls = false;
		$this->assertTrue( is_wp_error( $provider->getS3( 'test-key', 'test-secret', false, false, false ) ), "$probe_class must refuse to connect without TLS" );
		$transport = new $transport_class( 'test-key', 'test-secret' );
		$provider->set_storage( $transport );
		file_put_contents( $this->fixture . '/' . $this->name, $this->contents );
		$this->job->uploaded = array();
		$provider->backup( array( 'plugins' => $this->name ) );
		$this->assertTrue( $transport->useSSL, "$probe_class must keep HTTPS during bucket access" );
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

	/** BPS-02: keys below the configured prefix must be plain basenames, or they would become paths outside the backup directory. */
	#[DataProvider( 'providers' )]
	public function test_listing_below_a_prefix_drops_names_that_are_not_basenames( string $id, string $transport_class ): void {
		$this->configure( $id, $transport_class );
		$legit = 'backup_2024-01-01-0000_site_abcdef012345-db.gz';
		foreach ( array(
			$legit,
			'backup_2024-01-01-0000_x/../../../../var/www/html/wp-content/plugins/x/y.php._abcdef012345-db.gz',
			'backup_2024-01-01-0000_x..y_abcdef012345-db.gz',
			'backup_2024-01-01-0000_x\\..\\y_abcdef012345-db.gz',
			"backup_2024-01-01-0000_x\0_abcdef012345-db.gz",
			'nested/backup_2024-01-01-0000_site_abcdef012345-db.gz',
		) as $name ) {
			$this->transport->objects['test-bucket'][ 'site/backups/' . $name ] = 'archive';
		}
		$this->assertSame( array( $legit ), array_column( $this->provider->listfiles(), 'name' ), "$id must drop listed keys that are not basenames" );
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

	/** Upload through the fake transport with the given S3-compatible settings and return the signature version it was given. */
	private function generic_signature( array $settings ): string {
		$this->configure( 's3generic', 'Provider_Test_Generic_Transport' );
		$this->provider->set_options( array_merge( $this->options, $settings ), false, 's-saved' );
		$this->provider->set_storage( $this->transport );
		$this->transport->signVer = 'unset';
		$this->upload( 's3generic' );
		return $this->transport->signVer;
	}

	/** BPS-20: S3-compatible settings saved before the signature version setting existed use SigV4, not SigV2. */
	public function test_s3generic_without_a_saved_signature_version_uses_v4(): void {
		$this->assertSame( 'v4', $this->generic_signature( array() ), 'A missing signature version must default to SigV4' );
		$notices = array_filter(
			array_column( $this->job->logs, 0 ),
			function ( $line ) {
				return false !== strpos( $line, 'uses SigV4' );
			}
		);
		$this->assertCount( 1, $notices, 'A destination without a saved signature version must say how to select SigV2' );
		$this->assertStringContainsString( 'select SigV2', reset( $notices ) );

		$saved = array(
			'instance_id' => 's-saved',
			'accesskey'   => 'test-key',
			'secretkey'   => 'test-secret',
			'path'        => 'test-bucket',
			'endpoint'    => 'storage.example.test',
		);
		$this->assertSame( 'v4', $this->provider->transform_options_for_template( $saved )['signature_version'], 'The settings page must show SigV4 for such a destination' );
	}

	/** BPS-20: an explicitly selected signature version is kept, so servers that only support SigV2 keep working. */
	public function test_s3generic_keeps_an_explicit_signature_version(): void {
		$this->assertSame( 'v2', $this->generic_signature( array( 'signature_version' => 'v2' ) ), 'An explicit SigV2 must be kept' );
		$this->assertSame( array(), preg_grep( '/uses SigV4/', array_column( $this->job->logs, 0 ) ), 'An explicit choice needs no notice' );
		$this->assertSame( 'v4', $this->generic_signature( array( 'signature_version' => 'v4' ) ), 'An explicit SigV4 must be kept' );
		$saved = array(
			'instance_id'       => 's-saved',
			'accesskey'         => 'test-key',
			'secretkey'         => 'test-secret',
			'path'              => 'test-bucket',
			'endpoint'          => 'storage.example.test',
			'signature_version' => 'v2',
		);
		$this->assertSame( 'v2', $this->provider->transform_options_for_template( $saved )['signature_version'], 'The settings page must show the saved SigV2' );
	}

	/** BPS-20: every S3 client starts with SigV4; DreamObjects no longer starts with SigV2. */
	#[DataProvider( 'probes' )]
	public function test_clients_start_with_v4( string $probe_class, string $transport_class, string $internal ): void {
		$storage = $this->probe( $probe_class )->getS3( 'test-key', 'test-secret', false, false, false );
		$this->assertFalse( is_wp_error( $storage ), "$probe_class transport must initialize" );
		if ( ! property_exists( $storage, 'signVer' ) ) {
			$this->markTestSkipped( get_class( $storage ) . ' signs requests through the AWS SDK.' );
		}
		$this->assertSame( 'v4', $storage->signVer, "$probe_class must start with SigV4" );
	}
}
