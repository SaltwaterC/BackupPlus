<?php
/** Google Drive behavior through the bundled SDK with a fake HTTP transport. */

namespace BackupPlus\Tests\Unit\Providers;

use BackupPlus\Tests\Support\ProviderTestCase;
use BackupPlus_BackupModule;
use Provider_Test_Google_IO;
use ReflectionProperty;
use RuntimeException;
use UDP_Google_Client;
use UDP_Google_IO_Exception;
use UDP_Google_Service_Drive;
use WP_Error;

final class GoogleDriveTest extends ProviderTestCase {

	private BackupPlus_BackupModule $provider;

	private array $options;

	private UDP_Google_Client $client;

	private Provider_Test_Google_IO $io;

	private string $name;

	private string $contents;

	/** Which service call is currently made to fail: upload, download, delete or none. */
	private ?string $failure;

	/** Bytes of the archive the upload session has received. */
	private string $upload;

	private array $deleted;

	private array $listing_pages;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once __DIR__ . '/../../Support/googledrive-io.php';
	}

	protected function setUp(): void {
		parent::setUp();
		$this->provider      = $this->create_provider( 'googledrive' );
		$this->options       = array(
			'clientid' => 'test-client',
			'secret'   => 'test-secret',
			'token'    => 'test-refresh',
			'parentid' => 'test-folder',
		);
		$this->name          = 'backup_binary.zip';
		$this->contents      = "Google Drive archive\0\xff";
		$this->failure       = null;
		$this->upload        = '';
		$this->deleted       = array();
		$this->listing_pages = array();
	}

	/** Authenticate the provider against the fake SDK transport, as the bootstrap would with real credentials. */
	private function connect(): void {
		$this->provider->set_options( $this->options );
		$this->client = new UDP_Google_Client();
		$this->client->setAccessToken(
			json_encode(
				array(
					'access_token' => 'test-access',
					'created'      => time(),
					'expires_in'   => 3600,
				)
			)
		);
		$this->io = new Provider_Test_Google_IO( $this->client );
		$this->client->setIo( $this->io );
		$this->io->handler = array( $this, 'serve' );
		$service           = new UDP_Google_Service_Drive( $this->client );
		$this->provider->set_storage( $service );
		// bootstrap normally sets both fields. Inject the client alongside the cached
		// SDK service so the actual private upload and download engines are exercised.
		$property = new ReflectionProperty( $this->provider, 'client' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( $this->provider, $this->client );
	}

	/** The service: about, resumable upload sessions, downloads with ranges, paginated listing and deletion. */
	public function serve( $request ) {
		$url     = $request->getUrl();
		$method  = $request->getRequestMethod();
		$headers = array( 'content-type' => 'application/json' );
		if ( false !== strpos( $url, '/about' ) ) {
			return array(
				200,
				$headers,
				json_encode(
					array(
						'quotaBytesTotal' => '10485760',
						'quotaBytesUsed'  => '0',
					)
				),
			);
		}
		if ( 'POST' === $method && false !== strpos( $url, '/upload/' ) ) {
			$metadata = json_decode( $request->getPostBody(), true );
			$this->assertSame( $this->name, $metadata['title'], 'Google Drive uploads must use the archive name and configured parent folder' );
			$this->assertSame( 'test-folder', $metadata['parents'][0]['id'], 'Google Drive uploads must use the archive name and configured parent folder' );
			return array( 200, array( 'location' => 'https://upload.example.test/session' ), '' );
		}
		if ( 'PUT' === $method && 'https://upload.example.test/session' === $url ) {
			if ( 'upload' === $this->failure ) {
				throw new UDP_Google_IO_Exception( 'Upload unavailable' );
			}
			if ( 0 === strpos( $request->getRequestHeader( 'content-range' ), 'bytes */' ) ) {
				return array( 308, array( 'range' => 'bytes=0-' . ( strlen( $this->upload ) - 1 ) ), '' );
			}
			$this->assertStringStartsWith( 'bytes ' . strlen( $this->upload ) . '-', $request->getRequestHeader( 'content-range' ), 'Google Drive must upload from the server-reported offset' );
			$this->upload .= $request->getPostBody();
			return array(
				200,
				$headers,
				json_encode(
					array(
						'id'    => 'file-id',
						'title' => $this->name,
					)
				),
			);
		}
		if ( 'GET' === $method && 'https://download.example.test/file' === $url ) {
			if ( 'download' === $this->failure ) {
				return array( 403, $headers, '{}' );
			}
			$range = $request->getRequestHeader( 'range' );
			if ( $range && preg_match( '/bytes=(\d+)-(\d+)/', $range, $matches ) ) {
				return array( 206, array(), substr( $this->contents, $matches[1], $matches[2] - $matches[1] + 1 ) );
			}
			return array( 200, array(), $this->contents );
		}
		if ( 'DELETE' === $method ) {
			if ( 'delete' === $this->failure ) {
				return array(
					403,
					$headers,
					json_encode(
						array(
							'error' => array(
								'code'    => 403,
								'message' => 'Deletion denied',
							),
						)
					),
				);
			}
			$this->deleted[] = basename( parse_url( $url, PHP_URL_PATH ) );
			return array( 204, array(), '' );
		}
		if ( 'GET' === $method && false !== strpos( $url, '/files' ) ) {
			parse_str( parse_url( $url, PHP_URL_QUERY ), $query );
			$this->assertStringContainsString( '"test-folder" in parents and trashed = false', $query['q'], 'Google Drive listing must constrain the parent folder and exclude trash' );
			$this->listing_pages[] = $query['pageToken'] ?? '';
			if ( empty( $query['pageToken'] ) ) {
				return array(
					200,
					$headers,
					json_encode(
						array(
							'items'         => array(
								array(
									'id'          => 'file-id',
									'title'       => $this->name,
									'fileSize'    => (string) strlen( $this->contents ),
									'downloadUrl' => 'https://download.example.test/file',
								),
								array(
									'id'       => 'unmatched',
									'title'    => 'unrelated.zip',
									'fileSize' => '12',
								),
							),
							'nextPageToken' => 'page-two',
						)
					),
				);
			}
			$this->assertSame( 'page-two', $query['pageToken'], 'Google Drive must send the server pagination token' );
			return array(
				200,
				$headers,
				json_encode(
					array(
						'items' => array(
							array(
								'id'       => 'second-id',
								'title'    => 'backup_second.zip',
								'fileSize' => '42',
							),
						),
					)
				),
			);
		}
		throw new RuntimeException( 'Unexpected Google request: ' . $method . ' ' . $url );
	}

	public function test_operations_without_authentication_fail_cleanly(): void {
		$this->assertSame( 'no_settings', $this->provider->listfiles()->get_error_code(), 'Google Drive listing must reject missing authentication' );
		$this->assertInstanceOf( WP_Error::class, $this->provider->backup( array( 'backup.zip' ) ), 'Google Drive operations must handle missing authentication' );
		$this->assertFalse( $this->provider->download( 'backup.zip' ), 'Google Drive operations must handle missing authentication' );
		$this->assertSame( 'service_unavailable', $this->provider->delete( 'backup.zip' ), 'Google Drive operations must handle missing authentication' );
	}

	public function test_listing_collects_every_page_and_honors_the_filename_match(): void {
		$this->connect();
		$files = $this->provider->listfiles();
		$this->assertSame(
			array(
				array(
					'name' => $this->name,
					'size' => (string) strlen( $this->contents ),
				),
				array(
					'name' => 'backup_second.zip',
					'size' => '42',
				),
			),
			$files,
			'Google Drive must collect every listing page and filter filenames'
		);
		$this->assertSame( array( '', 'page-two' ), $this->listing_pages, 'Google Drive listing must traverse both pages' );
		$this->assertSame( array(), $this->provider->listfiles( 'missing' ), 'Google Drive must honor a custom filename match' );
	}

	public function test_binary_archives_are_uploaded_through_the_resumable_engine(): void {
		$this->connect();
		file_put_contents( $this->fixture . '/' . $this->name, $this->contents );
		$this->provider->backup( array( $this->name ) );
		$this->assertSame( $this->contents, $this->upload, 'Google Drive must upload binary contents through its resumable engine and record success' );
		$this->assertSame( array( $this->name ), $this->job->uploaded, 'Google Drive must upload binary contents through its resumable engine and record success' );
		$this->assertNull( $this->provider->jobdata_get( 'resume_' . md5( $this->fixture . '/' . $this->name ) ), 'Google Drive must clear completed upload state' );
		$this->assertContains( 100.0, $this->job->progress, 'Google Drive must record progress and restore the SDK request mode' );
		$this->assertFalse( $this->client->shouldDefer(), 'Google Drive must record progress and restore the SDK request mode' );
	}

	public function test_interrupted_uploads_resume_from_the_server_offset(): void {
		$this->connect();
		file_put_contents( $this->fixture . '/' . $this->name, $this->contents );
		$this->upload = substr( $this->contents, 0, 5 );
		$resume_key   = 'resume_' . md5( $this->fixture . '/' . $this->name );
		$this->provider->jobdata_set( $resume_key, array( 'https://upload.example.test/session', 2 ) );
		$this->provider->backup( array( $this->name ) );
		$this->assertSame( $this->contents, $this->upload, 'Google Drive must recover an interrupted upload using the server offset and clear its session' );
		$this->assertSame( array( $this->name ), $this->job->uploaded, 'Google Drive must recover an interrupted upload using the server offset and clear its session' );
		$this->assertNull( $this->provider->jobdata_get( $resume_key ), 'Google Drive must recover an interrupted upload using the server offset and clear its session' );
	}

	public function test_failed_uploads_are_not_marked_complete(): void {
		$this->connect();
		$this->failure = 'upload';
		file_put_contents( $this->fixture . '/' . $this->name, $this->contents );
		$this->provider->backup( array( $this->name ) );
		$this->assertSame( array(), $this->job->uploaded, 'Google Drive must not mark failed uploads complete and must reset deferred mode' );
		$this->assertFalse( $this->client->shouldDefer(), 'Google Drive must not mark failed uploads complete and must reset deferred mode' );
	}

	public function test_downloads_restore_fresh_and_partial_archives(): void {
		$this->connect();
		// Exercise the restore engine with both a fresh file and an existing prefix.
		$this->assertTrue( $this->provider->download( $this->name ), 'Google Drive must download the complete archive' );
		$this->assertSame( $this->contents, file_get_contents( $this->fixture . '/' . $this->name ), 'Google Drive must download the complete archive' );
		file_put_contents( $this->fixture . '/' . $this->name, substr( $this->contents, 0, 5 ) );
		clearstatcache();
		$this->assertTrue( $this->provider->download( $this->name ), 'Google Drive must resume a partial archive' );
		$this->assertSame( $this->contents, file_get_contents( $this->fixture . '/' . $this->name ), 'Google Drive must resume a partial archive' );
		$request_count = count( $this->io->requests );
		$this->assertTrue( $this->provider->download( $this->name ), 'An already downloaded Google Drive archive must need only metadata listing requests' );
		$this->assertSame( $request_count + 2, count( $this->io->requests ), 'An already downloaded Google Drive archive must need only metadata listing requests' );
	}

	public function test_missing_and_rejected_downloads_fail(): void {
		$this->connect();
		$this->assertFalse( $this->provider->download( 'backup_missing.zip' ), 'Google Drive must fail a missing archive' );
		$this->failure = 'download';
		$this->assertFalse( $this->provider->download( $this->name ), 'Google Drive must fail rejected HTTP downloads' );
	}

	public function test_deletion_failures_are_reported_and_only_the_requested_file_is_deleted(): void {
		$this->connect();
		$this->failure = 'delete';
		$this->assertSame( 'file_delete_error', $this->provider->delete( $this->name ), 'Google Drive must report deletion failures' );
		$this->failure = null;
		$this->assertTrue( $this->provider->delete( array( $this->name ) ), 'Google Drive must delete only the requested file ID' );
		$this->assertSame( array( 'file-id' ), $this->deleted, 'Google Drive must delete only the requested file ID' );
	}

	public function test_changing_instances_clears_the_cached_service(): void {
		$this->connect();
		$this->provider->set_options( array_merge( $this->options, array( 'parentid' => 'other-folder' ) ) );
		$this->assertEmpty( $this->provider->get_storage(), 'Google Drive must clear its cached service when changing instances' );
	}
}
