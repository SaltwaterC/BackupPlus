<?php
/** Dropbox storage operations with fake API responses (token crypto is tested separately). */

namespace BackupPlus\Tests\Unit\Providers;

use BackupPlus\Tests\Support\ProviderTestCase;
use BackupPlus_BackupModule;
use PHPUnit\Framework\Attributes\DataProvider;
use Provider_Test_Dropbox_API;

final class DropboxTest extends ProviderTestCase {

	private BackupPlus_BackupModule $provider;

	private Provider_Test_Dropbox_API $api;

	private string $name = 'backup_binary.zip';

	private string $contents = "Dropbox archive\0\xff";

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/Support/dropbox-api.php';
	}

	protected function setUp(): void {
		parent::setUp();
		$this->provider = $this->create_provider( 'dropbox' );
		$this->api      = new Provider_Test_Dropbox_API();
	}

	/** Give the provider a token, the fake API and the hook of Dropbox's optional folder support. */
	private function authenticate(): void {
		$this->provider->set_options( array( 'tk_access_token' => 'test-token' ) );
		$this->provider->set_storage( $this->api );
		add_filter(
			'backupplus_dropbox_modpath',
			function ( $file ) {
				return 'site/' . $file;
			}
		);
	}

	private function paginated_search_results(): void {
		$this->api->pages = array(
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
	}

	public function test_unauthenticated_operations_fail(): void {
		$this->assertSame( 'no_settings', $this->provider->listfiles()->get_error_code(), 'Unauthenticated Dropbox listing must return a settings error' );
		$this->assertFalse( $this->provider->backup( array( 'backup.zip' ) ), 'Unauthenticated Dropbox operations must fail' );
		$this->assertFalse( $this->provider->download( 'backup.zip' ), 'Unauthenticated Dropbox operations must fail' );
		$this->assertSame( 'authentication_fail', $this->provider->delete( 'backup.zip' ), 'Unauthenticated Dropbox operations must fail' );
	}

	public function test_listing_paginates_and_downloads_select_the_exact_file(): void {
		$this->authenticate();
		$this->paginated_search_results();
		$this->assertSame(
			array(
				array(
					'name' => 'backup_first.zip',
					'size' => 15,
				),
				array(
					'name' => 'backup_second.zip',
					'size' => 20,
				),
			),
			$this->provider->listfiles(),
			'Dropbox listing must support both metadata formats, paginate, and exclude directories, empty files and other paths'
		);
		$this->assertSame( array( 'backup_', '/site', 1000, 'page-two' ), $this->api->searches[1], 'Dropbox must use the returned cursor on the next page' );
		$this->assertTrue( $this->provider->download( 'backup_second.zip' ), 'Dropbox download must select the exact listed filename and size' );
		$this->assertSame( 20, end( $this->job->downloads )['size'], 'Dropbox download must select the exact listed filename and size' );
		$this->assertFalse( $this->provider->download( 'backup_missing.zip' ), 'Dropbox must fail a missing download' );
	}

	public function test_search_failures_are_returned(): void {
		$this->authenticate();
		$this->paginated_search_results();
		$this->api->failure = 'search';
		$this->assertSame( 'response_error', $this->provider->listfiles()->get_error_code(), 'Dropbox must return API search failures' );
	}

	public function test_uploads_resume_from_the_saved_session(): void {
		$this->authenticate();
		file_put_contents( $this->fixture . '/' . $this->name, $this->contents );
		$hash = md5( $this->name );
		$this->provider->jobdata_set( 'upload_id_' . $hash, 'saved-session' );
		$this->provider->jobdata_set( 'upload_offset_' . $hash, 3 );
		$this->provider->backup( array( $this->name ) );
		$this->assertSame( array( array( 'site/' . $this->name, true, 3, 'saved-session' ) ), $this->api->uploads, 'Dropbox must resume using its saved session, offset and configured path' );
		$this->assertSame( $this->contents, $this->api->objects[ 'site/' . $this->name ], 'Dropbox must record completed binary uploads and progress' );
		$this->assertSame( array( $this->name ), $this->job->uploaded, 'Dropbox must record completed binary uploads and progress' );
		$this->assertContains( 100.0, $this->job->progress, 'Dropbox must record completed binary uploads and progress' );
		$this->assertNull( $this->provider->jobdata_get( 'upload_id_' . $hash ), 'Dropbox must clear resume state after success' );
		$this->assertNull( $this->provider->jobdata_get( 'upload_offset_' . $hash ), 'Dropbox must clear resume state after success' );
	}

	public function test_chunked_downloads_pass_ranges_and_write_to_the_handle(): void {
		$this->authenticate();
		$this->api->objects[ 'site/' . $this->name ] = $this->contents;
		$handle                                      = fopen( 'php://temp', 'w+' );
		$headers                                     = array( 'Range' => 'bytes=3-7' );
		$this->assertTrue( $this->provider->chunked_download( $this->name, $headers, null, $handle ), 'Dropbox must pass byte ranges to the API' );
		$this->assertSame( array( 'headers' => $headers ), $this->api->headers, 'Dropbox must pass byte ranges to the API' );
		rewind( $handle );
		$this->assertSame( $this->contents, stream_get_contents( $handle ), 'Dropbox must write downloaded bytes to the supplied handle' );
		$this->api->failure = 'download';
		$this->assertFalse( $this->provider->chunked_download( $this->name, array(), null, $handle ), 'Dropbox must catch download exceptions' );
		fclose( $handle );
	}

	public function test_rejected_uploads_are_not_marked_complete_and_refresh_the_token(): void {
		$this->authenticate();
		file_put_contents( $this->fixture . '/' . $this->name, $this->contents );
		$this->api->failure = 'upload';
		$this->provider->backup( array( $this->name ) );
		$this->assertSame( array(), $this->job->uploaded, 'Dropbox must not mark rejected uploads complete and must refresh expired tokens' );
		$this->assertSame( 1, $this->api->refreshes, 'Dropbox must not mark rejected uploads complete and must refresh expired tokens' );
	}

	public function test_deletion_uses_the_configured_path_and_reports_failures(): void {
		$this->authenticate();
		$this->api->failure = 'delete';
		$this->assertSame( 'file_delete_error', $this->provider->delete( $this->name ), 'Dropbox must report a failed deletion' );
		$this->api->failure = null;
		$this->assertTrue( $this->provider->delete( array( $this->name ) ), 'Dropbox must delete from its configured path' );
		$this->assertSame( array( 'site/' . $this->name ), $this->api->deleted, 'Dropbox must delete from its configured path' );
	}

	public static function repair_values(): array {
		return array(
			'archive'     => array( 'archive', 'archive' ),
			'archivenull' => array( 'archivenull', 'archive' ),
			'nul'         => array( 'nul', 'nul' ),
		);
	}

	#[DataProvider( 'repair_values' )]
	public function test_historical_repair_strips_only_a_trailing_null_suffix( string $value, string $expected ): void {
		file_put_contents( $this->fixture . '/repair', $value );
		clearstatcache();
		$handle = fopen( $this->fixture . '/repair', 'r+' );
		$this->provider->download_complete( $handle, $this->fixture . '/repair' );
		fclose( $handle );
		$this->assertSame( $expected, file_get_contents( $this->fixture . '/repair' ), 'Dropbox historical repair must strip only a trailing null suffix' );
	}
}
