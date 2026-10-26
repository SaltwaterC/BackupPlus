<?php
/** pCloud regional API, streaming uploads and app consent without a real account. */

namespace BackupPlus\Tests\Unit\Providers;

use BackupPlus\Tests\Support\CloudProviderTestCase;
use BackupPlus_BackupModule;
use BackupPlus_Options;
use BackupPlus_Pcloud_Client;
use BackupPlus_Storage_Provider_Factory;
use Cloud_Test_Pcloud_Module;
use Cloud_Test_HTTP;
use Pcloud_Test_Bootstrap;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;

final class PcloudTest extends CloudProviderTestCase {

	private BackupPlus_BackupModule $provider;

	private array $options;

	private array $objects;

	private array $folders;

	private bool $invalid_result;

	private bool $bad_endpoint;

	private bool $invalid_size;

	private BackupPlus_Pcloud_Client $client;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once __DIR__ . '/../../Support/pcloud-module.php';
	}

	protected function setUp(): void {
		parent::setUp();
		$this->filters = Pcloud_Test_Bootstrap::$filters;
		$this->create_provider( 'pcloud' );
		$this->provider = new Cloud_Test_Pcloud_Module();
		$this->provider->set_options( $this->provider->get_default_options(), false, 'test-instance' );
		$this->options         = array_merge(
			$this->provider->get_default_options(),
			array(
				'pclauth'       => 'test-token',
				'location'      => '2',
				'path'          => 'site/backups',
				'client_id'     => 'test-client',
				'client_secret' => 'test-secret',
			)
		);
		$this->objects         = array();
		$this->folders         = array( 0 => '' );
		$this->invalid_result  = false;
		$this->bad_endpoint    = false;
		$this->invalid_size    = false;
		$GLOBALS['cloud_user'] = 1;
		$this->http->handler   = array( $this, 'serve' );
		$this->client          = new BackupPlus_Pcloud_Client( $this->options, $this->http );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['cloud_user'] );
	}

	/** The service: the regional API, folder tree, streamed uploads and the content host. */
	public function serve( $method, $url, $headers, $body ) {
		$host      = parse_url( $url, PHP_URL_HOST );
		$operation = basename( parse_url( $url, PHP_URL_PATH ) );
		if ( 'c1.pcloud.com' === $host ) {
			$this->assertFalse( isset( $headers['authorization'] ), 'pCloud downloads must not send account credentials to the content server' );
			$key = rawurldecode( substr( parse_url( $url, PHP_URL_PATH ), strlen( '/content/' ) ) );
			return Cloud_Test_HTTP::response( 200, $this->objects[ $key ] );
		}
		$this->assertSame( 'eapi.pcloud.com', $host, 'pCloud must use the configured EU region' );
		if ( 'PUT' === $method ) {
			parse_str( parse_url( $url, PHP_URL_QUERY ), $parameters );
		} else {
			parse_str( $body, $parameters );
		}
		if ( 'oauth2_token' === $operation ) {
			$this->assertSame( 'test-client', $parameters['client_id'], 'pCloud must exchange an authorization code with its own app credentials' );
			$this->assertSame( 'test-secret', $parameters['client_secret'], 'pCloud must exchange an authorization code with its own app credentials' );
			$this->assertSame( 'code', $parameters['code'], 'pCloud must exchange an authorization code with its own app credentials' );
			return Cloud_Test_HTTP::response(
				200,
				array(
					'result'       => 0,
					'access_token' => 'authorized-token',
				)
			);
		}
		$this->assertSame( 'test-token', $parameters['access_token'], 'pCloud API calls must carry the configured token' );
		if ( $this->invalid_result ) {
			return Cloud_Test_HTTP::response(
				200,
				array(
					'result' => 2000,
					'error'  => 'Login failed',
				)
			);
		}
		if ( 'createfolderifnotexists' === $operation ) {
			$folder = trim( $this->folders[ $parameters['folderid'] ] . '/' . $parameters['name'], '/' );
			$id     = array_search( $folder, $this->folders, true );
			if ( false === $id ) {
				$id                   = count( $this->folders );
				$this->folders[ $id ] = $folder; }
			return Cloud_Test_HTTP::response(
				200,
				array(
					'result'   => 0,
					'metadata' => array( 'folderid' => $id ),
				)
			);
		}
		if ( 'uploadfile' === $operation ) {
			$this->assertSame( 'PUT', $method, 'pCloud must stream raw bytes and reject partial uploads' );
			$this->assertSame( 1, (int) $parameters['nopartial'], 'pCloud must stream raw bytes and reject partial uploads' );
			$key                   = $this->folders[ $parameters['folderid'] ] . '/' . $parameters['filename'];
			$this->objects[ $key ] = $body;
			return Cloud_Test_HTTP::response(
				200,
				array(
					'result'   => 0,
					'metadata' => array(
						array(
							'name' => $parameters['filename'],
							'size' => strlen( $body ),
						),
					),
				)
			);
		}
		if ( 'listfolder' === $operation ) {
			$items = array();
			foreach ( $this->objects as $key => $contents ) {
				$items[] = array(
					'name'     => basename( $key ),
					'size'     => strlen( $contents ),
					'isfolder' => false,
				);
			}
			$items[] = array(
				'name'     => 'folder',
				'isfolder' => true,
			);
			return Cloud_Test_HTTP::response(
				200,
				array(
					'result'   => 0,
					'metadata' => array( 'contents' => $items ),
				)
			);
		}
		$key = ltrim( $parameters['path'] ?? '', '/' );
		if ( 'deletefile' === $operation ) {
			$exists = isset( $this->objects[ $key ] );
			unset( $this->objects[ $key ] );
			return Cloud_Test_HTTP::response( 200, array( 'result' => $exists ? 0 : 2009 ) ); }
		if ( 'stat' === $operation ) {
			return isset( $this->objects[ $key ] ) ? Cloud_Test_HTTP::response(
				200,
				array(
					'result'   => 0,
					'metadata' => array(
						'size'     => $this->invalid_size ? 'unknown' : strlen( $this->objects[ $key ] ),
						'fileid'   => $key,
						'isfolder' => false,
					),
				)
			) : Cloud_Test_HTTP::response( 200, array( 'result' => 2009 ) );
		}
		if ( 'getfilelink' === $operation ) {
			return Cloud_Test_HTTP::response(
				200,
				array(
					'result' => 0,
					'hosts'  => array( $this->bad_endpoint ? 'evil.test' : 'c1.pcloud.com' ),
					'path'   => '/content/' . rawurlencode( $parameters['fileid'] ),
				)
			);
		}
		throw new RuntimeException( 'Unexpected pCloud operation: ' . $operation );
	}

	/** Start a consent flow on a provider whose saved settings hold the instance, and return its authorization URL and parameters. */
	private function begin_consent(): array {
		$oauth = $this->create_provider( 'pcloud' );
		$oauth->set_options( $this->options );
		BackupPlus_Options::$options['backupplus_pcloud'] = array(
			'version'  => 1,
			'settings' => array( 'test-instance' => $this->options ),
		);
		$url = $oauth->authorization_url();
		parse_str( parse_url( $url, PHP_URL_QUERY ), $parameters );
		return array( $oauth, $parameters, $url );
	}

	/** An assertion failing inside the fake service surfaces as a RuntimeException, so also prove no request was sent to the host. */
	private function assertNoRequestTo( string $host ): void {
		foreach ( $this->http->calls as $call ) {
			$this->assertNotSame( $host, parse_url( $call['url'], PHP_URL_HOST ), 'No request may reach ' . $host );
		}
	}

	public function test_settings_template_has_a_connect_link(): void {
		$this->assertStringContainsString( 'action=backupplusmethod-pcloud-auth', BackupPlus_Storage_Provider_Factory::create( 'pcloud' )->get_configuration_template(), 'The pCloud settings template must contain a working per-instance connect link' );
	}

	/** A fresh process: the contract includes a check that the provider loads no other provider's files. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_storage_contract(): void {
		$this->assert_storage_contract( 'pcloud', $this->client, $this->options, $this->provider );
		$this->assertSame( array(), $this->objects, 'pCloud credential tests must remove their remote file' );
		$this->assertContains( 'site/backups', $this->folders, 'pCloud must create every folder component in its own account' );
	}

	public function test_api_errors_are_rejected_even_with_http_200(): void {
		$this->invalid_result = true;
		$this->thrown(
			function () {
				$this->client->listing();
			},
			'pCloud must reject API errors even when HTTP status is 200'
		);
	}

	public function test_invalid_remote_sizes_preserve_the_local_archive(): void {
		file_put_contents( $this->fixture . '/file.zip', 'archive' );
		$this->client->upload( 'backup_file.zip', $this->fixture . '/file.zip' );
		$this->invalid_size = true;
		file_put_contents( $this->fixture . '/download.zip', 'preserve this archive' );
		$this->thrown(
			function () {
				$this->client->download( 'backup_file.zip', $this->fixture . '/download.zip' );
			},
			'Storage downloads must reject invalid remote sizes'
		);
		$this->assertSame( 'preserve this archive', file_get_contents( $this->fixture . '/download.zip' ), 'Invalid remote metadata must not replace the local archive' );
	}

	public function test_untrusted_content_hosts_are_rejected(): void {
		file_put_contents( $this->fixture . '/file.zip', 'archive' );
		$this->client->upload( 'backup_file.zip', $this->fixture . '/file.zip' );
		file_put_contents( $this->fixture . '/download.zip', 'preserve this archive' );
		$this->bad_endpoint = true;
		$this->thrown(
			function () {
				$this->client->download( 'backup_file.zip', $this->fixture . '/download.zip' );
			},
			'pCloud must reject untrusted content hosts'
		);
		$this->assertNoRequestTo( 'evil.test' );
	}

	public function test_token_is_registered_for_encryption_only_for_its_own_option(): void {
		$this->assertContains( 'pclauth', apply_filters( 'backupplus_credential_secret_fields', array(), 'backupplus_pcloud' ), 'pCloud must register its historical token name with generic credential encryption only for its own option' );
		$this->assertSame( array(), apply_filters( 'backupplus_credential_secret_fields', array(), 'backupplus_other' ), 'pCloud must register its historical token name with generic credential encryption only for its own option' );
	}

	public function test_consent_uses_the_registered_app_redirect(): void {
		list( $oauth, $parameters, $url ) = $this->begin_consent();
		$this->assertSame( 'my.pcloud.com', parse_url( $url, PHP_URL_HOST ), 'pCloud must use its own registered app consent redirect' );
		$this->assertSame( $parameters['redirect_uri'], $oauth->oauth_redirect_uri(), 'pCloud must use its own registered app consent redirect' );
	}

	public function test_consent_rejects_an_invalid_state(): void {
		list( $oauth ) = $this->begin_consent();
		$this->thrown(
			function () use ( $oauth ) {
				$oauth->complete_oauth( 'bad-state', 'code', '2' );
			},
			'pCloud must reject invalid state'
		);
	}

	public function test_consent_rejects_a_callback_for_another_administrator(): void {
		list( $oauth, $parameters ) = $this->begin_consent();
		$GLOBALS['cloud_user']      = 2;
		$this->thrown(
			function () use ( $oauth, $parameters ) {
				$oauth->complete_oauth( $parameters['state'], 'code', '2' );
			},
			'pCloud must reject a callback for another administrator'
		);
	}

	public function test_consent_rejects_injected_regions(): void {
		list( $oauth, $parameters ) = $this->begin_consent();
		$this->thrown(
			function () use ( $oauth, $parameters ) {
				$oauth->complete_oauth( $parameters['state'], 'code', 'https://evil.test' );
			},
			'pCloud must reject injected authorization regions'
		);
	}

	public function test_consent_saves_the_token_and_region_once(): void {
		list( $oauth, $parameters ) = $this->begin_consent();
		$this->assertTrue( $oauth->complete_oauth( $parameters['state'], 'code', '2' ), 'pCloud must save the authorized token and data region' );
		$this->assertSame( 'authorized-token', $oauth->get_options()['pclauth'], 'pCloud must save the authorized token and data region' );
		$this->assertSame( '2', $oauth->get_options()['location'], 'pCloud must save the authorized token and data region' );
		$this->thrown(
			function () use ( $oauth, $parameters ) {
				$oauth->complete_oauth( $parameters['state'], 'code', '2' );
			},
			'pCloud must reject replayed consent callbacks'
		);
	}
}
