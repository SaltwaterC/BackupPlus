<?php
/** Graph uploads/downloads and OneDrive's administrator-bound consent flow. */

namespace BackupPlus\Tests\Unit\Providers;

use BackupPlus\Tests\Support\CloudProviderTestCase;
use BackupPlus_BackupModule;
use BackupPlus_OneDrive_Client;
use BackupPlus_Options;
use Cloud_Test_HTTP;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class OneDriveTest extends CloudProviderTestCase {

	private BackupPlus_BackupModule $provider;

	private array $options;

	private array $objects;

	private array $sessions;

	private int $tokens;

	private bool $once_unauthorized;

	private bool $bad_endpoint;

	private array $rotated;

	private BackupPlus_OneDrive_Client $client;

	protected function setUp(): void {
		parent::setUp();
		$this->provider          = $this->create_provider( 'onedrive' );
		$this->options           = array_merge(
			$this->provider->get_default_options(),
			array(
				'client_id'     => 'test-client',
				'client_secret' => 'test-secret',
				'refresh_token' => 'refresh-token',
				'path'          => 'site/backups',
			)
		);
		$this->objects           = array();
		$this->sessions          = array();
		$this->tokens            = 0;
		$this->once_unauthorized = false;
		$this->bad_endpoint      = false;
		$this->rotated           = array();
		$GLOBALS['cloud_user']   = 1;
		$this->http->handler     = array( $this, 'serve' );
		$this->client            = new BackupPlus_OneDrive_Client(
			$this->options,
			$this->http,
			function ( $token, $original ) {
				$this->rotated[] = $token;
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['cloud_user'] );
	}

	/** The service: Microsoft's token endpoint, Graph, preauthorized upload sessions and the content host. */
	public function serve( $method, $url, $headers, $body ) {
		$host = parse_url( $url, PHP_URL_HOST );
		$path = parse_url( $url, PHP_URL_PATH );
		if ( 'login.microsoftonline.com' === $host ) {
			parse_str( $body, $parameters );
			$this->assertSame( 'test-client', $parameters['client_id'], 'OneDrive must exchange its own application credentials' );
			$this->assertSame( 'test-secret', $parameters['client_secret'], 'OneDrive must exchange its own application credentials' );
			$this->assertContains( $parameters['grant_type'], array( 'refresh_token', 'authorization_code' ), 'OneDrive must use an OAuth grant' );
			return Cloud_Test_HTTP::response(
				200,
				array(
					'access_token'  => 'access-' . ( ++$this->tokens ),
					'expires_in'    => 3600,
					'refresh_token' => 'rotated-refresh',
				)
			);
		}
		if ( 'up.1drv.com' === $host ) {
			$this->assertFalse( isset( $headers['authorization'] ), 'OneDrive must not send Graph bearer credentials to preauthorized upload URLs' );
			$id = (int) basename( $path );
			if ( 'DELETE' === $method ) {
				unset( $this->sessions[ $id ] );
				return Cloud_Test_HTTP::response( 204 ); }
			$offset = strlen( $this->sessions[ $id ]['bytes'] );
			$this->assertStringStartsWith( 'bytes ' . $offset . '-', $headers['content-range'], 'OneDrive upload fragments must use sequential byte ranges' );
			if ( $offset ) {
				$this->assertSame( 0, $offset % 327680, 'OneDrive non-final fragments must be multiples of 320 KiB' );
			}
			$this->sessions[ $id ]['bytes'] .= $body;
			$total                           = (int) substr( strrchr( $headers['content-range'], '/' ), 1 );
			if ( strlen( $this->sessions[ $id ]['bytes'] ) < $total ) {
				return Cloud_Test_HTTP::response( 202, array( 'nextExpectedRanges' => array( strlen( $this->sessions[ $id ]['bytes'] ) . '-' ) ) );
			}
			$this->objects[ $this->sessions[ $id ]['name'] ] = $this->sessions[ $id ]['bytes'];
			return Cloud_Test_HTTP::response(
				201,
				array(
					'size' => $total,
					'id'   => 'file-id',
				)
			);
		}
		if ( 'tenant.sharepoint.com' === $host ) {
			$this->assertFalse( isset( $headers['authorization'] ), 'OneDrive must not leak Graph bearer tokens to content hosts' );
			$key = rawurldecode( substr( $path, strlen( '/content/' ) ) );
			return Cloud_Test_HTTP::response( 200, $this->objects[ $key ] );
		}
		$this->assertSame( 'graph.microsoft.com', $host, 'Drive operations must authenticate only to Microsoft Graph' );
		$this->assertStringStartsWith( 'Bearer access-', $headers['authorization'] ?? '', 'Drive operations must authenticate only to Microsoft Graph' );
		if ( $this->once_unauthorized ) {
			$this->once_unauthorized = false;
			return Cloud_Test_HTTP::response( 401 ); }
		$prefix = '/v1.0/me/drive/root:/';
		$this->assertStringStartsWith( $prefix, $path, 'OneDrive must address the configured drive and folder' );
		$key = rawurldecode( substr( $path, strlen( $prefix ) ) );
		if ( ':/children' === substr( $key, -10 ) ) {
			parse_str( parse_url( $url, PHP_URL_QUERY ) ?? '', $query );
			$items = array();
			foreach ( $this->objects as $name => $contents ) {
				$items[] = array(
					'name' => basename( $name ),
					'size' => strlen( $contents ),
					'file' => array(),
				);
			}
			$items[] = array(
				'name'   => 'directory',
				'folder' => array(),
				'size'   => 0,
			);
			$items[] = array(
				'name'       => 'shortcut',
				'file'       => array(),
				'remoteItem' => array(),
				'size'       => 1,
			);
			$offset  = (int) ( $query['page'] ?? 0 );
			$data    = array( 'value' => array_slice( $items, $offset, 1 ) );
			if ( $offset + 1 < count( $items ) ) {
				$data['@odata.nextLink'] = 'https://graph.microsoft.com' . $path . '?page=' . ( $offset + 1 );
			}
			return Cloud_Test_HTTP::response( 200, $data );
		}
		if ( ':/createUploadSession' === substr( $key, -21 ) ) {
			$key  = substr( $key, 0, -21 );
			$data = json_decode( $body, true );
			$this->assertSame( 'replace', $data['item']['@microsoft.graph.conflictBehavior'], 'OneDrive retries must replace the same remote backup' );
			$id                    = count( $this->sessions ) + 1;
			$this->sessions[ $id ] = array(
				'name'  => $key,
				'bytes' => '',
			);
			return Cloud_Test_HTTP::response( 200, array( 'uploadUrl' => $this->bad_endpoint ? 'https://evil.test/upload' : 'https://up.1drv.com/session/' . $id ) );
		}
		if ( ':/content' === substr( $key, -9 ) ) {
			$key = substr( $key, 0, -9 );
			$this->assertSame( 'PUT', $method, 'OneDrive empty files must use a zero-byte content PUT' );
			$this->assertSame( '', $body, 'OneDrive empty files must use a zero-byte content PUT' );
			$this->objects[ $key ] = '';
			return Cloud_Test_HTTP::response( 201, array( 'size' => 0 ) );
		}
		if ( 'DELETE' === $method ) {
			$existed = isset( $this->objects[ $key ] );
			unset( $this->objects[ $key ] );
			return Cloud_Test_HTTP::response( $existed ? 204 : 404 ); }
		if ( ! isset( $this->objects[ $key ] ) ) {
			return Cloud_Test_HTTP::response( 404 );
		}
		return Cloud_Test_HTTP::response(
			200,
			array(
				'name'                         => basename( $key ),
				'size'                         => strlen( $this->objects[ $key ] ),
				'file'                         => array(),
				'@microsoft.graph.downloadUrl' => 'https://tenant.sharepoint.com/content/' . rawurlencode( $key ),
			)
		);
	}

	/** Start a consent flow on a provider whose saved settings hold the instance, and return its authorization parameters. */
	private function begin_consent(): array {
		$oauth = $this->create_provider( 'onedrive' );
		$oauth->set_options( $this->options );
		BackupPlus_Options::$options['backupplus_onedrive'] = array(
			'version'  => 1,
			'settings' => array( 'test-instance' => $this->options ),
		);
		$url = $oauth->authorization_url();
		parse_str( parse_url( $url, PHP_URL_QUERY ), $parameters );
		return array( $oauth, $parameters );
	}

	public function test_settings_template_has_a_connect_link(): void {
		$this->assertStringContainsString( 'action=backupplusmethod-onedrive-auth', $this->provider->get_configuration_template(), 'The OneDrive settings template must contain a working per-instance connect link' );
	}

	/** A fresh process: the contract includes a check that the provider loads no other provider's files. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_storage_contract(): void {
		$this->assert_storage_contract( 'onedrive', $this->client, $this->options );
		$this->assertSame( array( 'rotated-refresh' ), $this->rotated, 'OneDrive must report refresh-token rotation to its owning provider' );
	}

	public function test_rejected_access_tokens_are_refreshed_and_retried(): void {
		$this->client->listing();
		$before                  = $this->tokens;
		$this->once_unauthorized = true;
		$this->client->listing();
		$this->assertSame( $this->tokens, $before + 1, 'OneDrive must refresh and retry after a rejected access token' );
	}

	public function test_multi_fragment_archives_are_committed(): void {
		$large = str_repeat( 'O', 10485760 ) . 'last fragment';
		file_put_contents( $this->fixture . '/large.zip', $large );
		$this->client->upload( 'backup_large.zip', $this->fixture . '/large.zip' );
		$this->assertSame( $large, $this->objects['site/backups/backup_large.zip'], 'OneDrive must commit multi-fragment archives' );
	}

	public function test_untrusted_upload_endpoints_are_rejected(): void {
		file_put_contents( $this->fixture . '/large.zip', str_repeat( 'O', 10485760 ) . 'last fragment' );
		$this->bad_endpoint = true;
		$this->thrown(
			function () {
				$this->client->upload( 'backup_bad_host.zip', $this->fixture . '/large.zip' );
			},
			'OneDrive must reject untrusted upload endpoints'
		);
		foreach ( $this->http->calls as $call ) {
			// An assertion failing inside the fake service surfaces as a RuntimeException, so also prove no request was sent.
			$this->assertNotSame( 'evil.test', parse_url( $call['url'], PHP_URL_HOST ), 'No request may reach an untrusted upload endpoint' );
		}
	}

	// Consent callbacks must reject replay, another user, a bad state and expiry.

	public function test_consent_requests_offline_file_access_and_the_registered_redirect(): void {
		list( $oauth, $parameters ) = $this->begin_consent();
		$this->assertSame( 'offline_access Files.ReadWrite', $parameters['scope'], 'OneDrive consent must request offline file access and the registered redirect URI' );
		$this->assertSame( $oauth->oauth_redirect_uri(), $parameters['redirect_uri'], 'OneDrive consent must request offline file access and the registered redirect URI' );
	}

	public function test_consent_rejects_an_invalid_state(): void {
		list( $oauth ) = $this->begin_consent();
		$this->thrown(
			function () use ( $oauth ) {
				$oauth->complete_oauth( 'wrong-state', 'code' );
			},
			'OneDrive must reject an invalid state'
		);
	}

	public function test_consent_rejects_a_callback_for_another_administrator(): void {
		list( $oauth, $parameters ) = $this->begin_consent();
		$GLOBALS['cloud_user']      = 2;
		$this->thrown(
			function () use ( $oauth, $parameters ) {
				$oauth->complete_oauth( $parameters['state'], 'code' );
			},
			'OneDrive must reject a callback for another administrator'
		);
	}

	public function test_consent_rejects_expired_state(): void {
		list( $oauth, $parameters )        = $this->begin_consent();
		$valid                             = $oauth->get_options();
		$expired                           = $valid;
		$expired['oauth_state']['expires'] = 0;
		$oauth->set_options( $expired );
		$this->thrown(
			function () use ( $oauth, $parameters ) {
				$oauth->complete_oauth( $parameters['state'], 'code' );
			},
			'OneDrive must reject expired authorization state'
		);
	}

	public function test_consent_saves_the_refresh_token_once(): void {
		list( $oauth, $parameters ) = $this->begin_consent();
		$this->assertTrue( $oauth->complete_oauth( $parameters['state'], 'code' ), 'OneDrive consent must save its refresh token' );
		$this->assertSame( 'rotated-refresh', $oauth->get_options()['refresh_token'], 'OneDrive consent must save its refresh token' );
		$this->thrown(
			function () use ( $oauth, $parameters ) {
				$oauth->complete_oauth( $parameters['state'], 'code' );
			},
			'OneDrive must reject a replayed callback'
		);
	}
}
