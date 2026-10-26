<?php
/** Google Cloud Storage wire protocol, refresh tokens and service-account JWTs. */

namespace BackupPlus\Tests\Unit\Providers;

use BackupPlus\Tests\Support\CloudProviderTestCase;
use BackupPlus_BackupModule;
use BackupPlus_GoogleCloud_Client;
use Cloud_Test_HTTP;
use phpseclib3\Crypt\RSA;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class GoogleCloudTest extends CloudProviderTestCase {

	private BackupPlus_BackupModule $provider;

	private array $options;

	private array $objects;

	private array $sessions;

	private int $tokens;

	private bool $once_unauthorized;

	private bool $bad_endpoint;

	private bool $bad_commit;

	/** Public half of the service account key, used to verify the RS256 assertion. */
	private $public_key;

	private BackupPlus_GoogleCloud_Client $client;

	protected function setUp(): void {
		parent::setUp();
		$this->provider          = $this->create_provider( 'googlecloud' );
		$this->options           = array_merge(
			$this->provider->get_default_options(),
			array(
				'bucket'   => 'test-bucket',
				'path'     => 'site/backups',
				'clientid' => 'test-client',
				'secret'   => 'test-secret',
				'token'    => 'refresh-token',
			)
		);
		$this->objects           = array();
		$this->sessions          = array();
		$this->tokens            = 0;
		$this->once_unauthorized = false;
		$this->bad_endpoint      = false;
		$this->bad_commit        = false;
		$this->public_key        = null;
		$this->http->handler     = array( $this, 'serve' );
		$this->client            = new BackupPlus_GoogleCloud_Client( $this->options, $this->http );
	}

	/** The service: token endpoint for both grants, resumable upload sessions, listing with pagination, objects. */
	public function serve( $method, $url, $headers, $body ) {
		$path = parse_url( $url, PHP_URL_PATH );
		parse_str( parse_url( $url, PHP_URL_QUERY ) ?? '', $query );
		if ( 'oauth2.googleapis.com' === parse_url( $url, PHP_URL_HOST ) ) {
			parse_str( $body, $parameters );
			if ( 'refresh_token' === $parameters['grant_type'] ) {
				$this->assertSame( 'test-client', $parameters['client_id'], 'Google must exchange the configured OAuth refresh credentials' );
				$this->assertSame( 'test-secret', $parameters['client_secret'], 'Google must exchange the configured OAuth refresh credentials' );
				$this->assertSame( 'refresh-token', $parameters['refresh_token'], 'Google must exchange the configured OAuth refresh credentials' );
			} else {
				$this->assertSame( 'urn:ietf:params:oauth:grant-type:jwt-bearer', $parameters['grant_type'], 'Google service accounts must use the JWT bearer grant' );
				list($header, $claims, $signature) = explode( '.', $parameters['assertion'] );
				$decode                            = function ( $text ) {
					return base64_decode( strtr( $text, '-_', '+/' ) );
				};
				$payload                           = json_decode( $decode( $claims ), true );
				$this->assertSame( 'service@example.iam.gserviceaccount.com', $payload['iss'], 'Google JWTs must bind issuer, fixed audience and expiry' );
				$this->assertSame( 'https://oauth2.googleapis.com/token', $payload['aud'], 'Google JWTs must bind issuer, fixed audience and expiry' );
				$this->assertSame( 3600, $payload['exp'] - $payload['iat'], 'Google JWTs must bind issuer, fixed audience and expiry' );
				$this->assertTrue( $this->public_key->withHash( 'sha256' )->withPadding( RSA::SIGNATURE_PKCS1 )->verify( $header . '.' . $claims, $decode( $signature ) ), 'Google must issue an independently verifiable RS256 assertion' );
			}
			return Cloud_Test_HTTP::response(
				200,
				array(
					'access_token' => 'access-' . ( ++$this->tokens ),
					'expires_in'   => 3600,
				)
			);
		}
		$this->assertStringStartsWith( 'Bearer access-', $headers['authorization'] ?? '', 'Cloud Storage operations must carry a bearer token' );
		if ( $this->once_unauthorized ) {
			$this->once_unauthorized = false;
			return Cloud_Test_HTTP::response( 401 ); }
		if ( 'POST' === $method && 0 === strpos( $path, '/upload/storage/' ) ) {
			$id                    = count( $this->sessions ) + 1;
			$this->sessions[ $id ] = array(
				'name'  => $query['name'],
				'bytes' => '',
			);
			return Cloud_Test_HTTP::response( 200, '', array( 'location' => $this->bad_endpoint ? 'https://evil.test/upload' : 'https://storage.googleapis.com/session/' . $id ) );
		}
		if ( 'PUT' === $method && 0 === strpos( $path, '/session/' ) ) {
			$id     = (int) basename( $path );
			$offset = strlen( $this->sessions[ $id ]['bytes'] );
			$range  = $headers['content-range'];
			$this->assertTrue( 'bytes */0' === $range || 0 === strpos( $range, 'bytes ' . $offset . '-' ), 'Google upload fragments must be sequential' );
			$this->sessions[ $id ]['bytes'] .= $body;
			$total                           = (int) substr( strrchr( $range, '/' ), 1 );
			if ( strlen( $this->sessions[ $id ]['bytes'] ) < $total || $this->bad_commit ) {
				return Cloud_Test_HTTP::response( 308, '', array( 'range' => 'bytes=0-' . ( strlen( $this->sessions[ $id ]['bytes'] ) - 1 ) ) );
			}
			$this->objects[ $this->sessions[ $id ]['name'] ] = $this->sessions[ $id ]['bytes'];
			return Cloud_Test_HTTP::response( 200, array( 'size' => (string) $total ) );
		}
		$prefix = '/storage/v1/b/test-bucket/o';
		$this->assertStringStartsWith( $prefix, $path, 'Google requests must use the configured bucket' );
		if ( $path === $prefix ) {
			$items = array();
			foreach ( $this->objects as $name => $contents ) {
				$items[] = array(
					'name' => $name,
					'size' => (string) strlen( $contents ),
				);
			}
			$items[] = array(
				'name' => 'other-site/backup_other.zip',
				'size' => '5',
			);
			$items[] = array(
				'name' => 'site/backups/nested/backup_nested.zip',
				'size' => '5',
			);
			$offset  = (int) ( $query['pageToken'] ?? 0 );
			$result  = array( 'items' => array_slice( $items, $offset, 1 ) );
			if ( $offset + 1 < count( $items ) ) {
				$result['nextPageToken'] = (string) ( $offset + 1 );
			}
			return Cloud_Test_HTTP::response( 200, $result );
		}
		$key = rawurldecode( substr( $path, strlen( $prefix ) + 1 ) );
		if ( 'DELETE' === $method ) {
			$existed = isset( $this->objects[ $key ] );
			unset( $this->objects[ $key ] );
			return Cloud_Test_HTTP::response( $existed ? 204 : 404 ); }
		if ( ! isset( $this->objects[ $key ] ) ) {
			return Cloud_Test_HTTP::response( 404 );
		}
		return 'media' === ( $query['alt'] ?? '' ) ? Cloud_Test_HTTP::response( 200, $this->objects[ $key ] ) : Cloud_Test_HTTP::response(
			200,
			array(
				'name' => $key,
				'size' => (string) strlen( $this->objects[ $key ] ),
			)
		);
	}

	/** An archive that crosses the 8 MiB chunk boundary, written to the fixture. */
	private function write_large_archive(): string {
		$large = str_repeat( 'G', 8388608 ) . 'last chunk';
		file_put_contents( $this->fixture . '/large.zip', $large );
		return $large;
	}

	/** A fresh process: the contract includes a check that the provider loads no other provider's files. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_storage_contract(): void {
		$this->assert_storage_contract( 'googlecloud', $this->client, $this->options );
		$this->assertSame( array(), $this->objects, 'Google credential tests must remove their remote file' );
	}

	public function test_expired_authorization_refreshes_the_token_and_retries_once(): void {
		$this->client->listing();
		$before                  = $this->tokens;
		$this->once_unauthorized = true;
		$this->client->listing();
		$this->assertSame( $before + 1, $this->tokens, 'Google must refresh and retry once after HTTP 401' );
	}

	public function test_uploads_complete_across_the_chunk_boundary(): void {
		$large = $this->write_large_archive();
		$this->client->upload( 'backup_large.zip', $this->fixture . '/large.zip' );
		$this->assertSame( $large, $this->objects['site/backups/backup_large.zip'], 'Google must complete uploads across the 8 MiB chunk boundary' );
	}

	public function test_incomplete_final_upload_responses_are_rejected(): void {
		$this->write_large_archive();
		$this->bad_commit = true;
		$this->thrown(
			function () {
				$this->client->upload( 'backup_bad_commit.zip', $this->fixture . '/large.zip' );
			},
			'Google must reject an incomplete final upload response'
		);
	}

	public function test_foreign_upload_session_endpoints_are_rejected(): void {
		$this->write_large_archive();
		$this->bad_endpoint = true;
		$this->thrown(
			function () {
				$this->client->upload( 'backup_bad_host.zip', $this->fixture . '/large.zip' );
			},
			'Google must reject foreign upload session endpoints'
		);
	}

	public function test_service_accounts_use_a_verifiable_jwt_and_need_no_interactive_login(): void {
		require_once BACKUPPLUS_DIR . '/vendor/autoload.php';
		$private_key                        = RSA::createKey( 2048 );
		$this->public_key                   = $private_key->getPublicKey();
		$service_options                    = $this->options;
		$service_options['service_account'] = json_encode(
			array(
				'type'         => 'service_account',
				'client_email' => 'service@example.iam.gserviceaccount.com',
				'private_key'  => $private_key->toString( 'PKCS8' ),
				'token_uri'    => 'https://evil.test/token',
			)
		);
		$service_client                     = new BackupPlus_GoogleCloud_Client( $service_options, $this->http );
		$service_client->listing();
		$this->assertGreaterThan( 0, $this->tokens, 'A service account must exchange its JWT assertion for an access token' );
		$this->assertFalse( $this->provider->authentication_required( $service_options ), 'A service account must not request an interactive Google login' );
	}
}
