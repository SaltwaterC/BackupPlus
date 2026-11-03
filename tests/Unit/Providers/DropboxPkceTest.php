<?php
/** BPS-01: Dropbox authorization with PKCE and a pasted code, directly with Dropbox and without an app secret. */

namespace BackupPlus\Tests\Unit\Providers;

use BackupPlus\Tests\Support\ProviderTestCase;
use BackupPlus_BackupModule;
use BackupPlus_Dropbox_PKCE;
use Dropbox_Curl;
use Dropbox_Exception;
use Dropbox_StorageInterface;
use stdClass;

final class DropboxPkceTest extends ProviderTestCase {

	/** Requests the wp_remote_post() shim received: url and body. */
	private array $posts = array();

	/** Response the wp_remote_post() shim returns: status and JSON body. */
	private array $reply = array();

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$dir = dirname( __DIR__, 3 ) . '/methods/dropbox/Dropbox2/';
		foreach ( array( 'API.php', 'Exception.php', 'OAuth/Consumer/ConsumerAbstract.php', 'OAuth/Storage/StorageInterface.php', 'OAuth/Consumer/Curl.php' ) as $file ) {
			require_once $dir . $file;
		}
	}

	protected function setUp(): void {
		parent::setUp();
		$this->create_provider( 'dropbox' );
		$this->posts              = array();
		$this->reply              = array( 200, array() );
		$GLOBALS['provider_post'] = function ( $url, $args ) {
			$this->posts[] = array(
				'url'  => $url,
				'body' => $args['body'],
			);
			return array(
				'response' => array( 'code' => $this->reply[0] ),
				'body'     => json_encode( $this->reply[1] ),
			);
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['provider_post'] );
		parent::tearDown();
	}

	private function pending( int $expires_in = 600 ): stdClass {
		return (object) array(
			'verifier'  => str_repeat( 'v', 43 ),
			'client_id' => 'site-app-key',
			'expires'   => time() + $expires_in,
		);
	}

	private function dropbox_error( callable $callback ): string {
		try {
			$callback();
		} catch ( Dropbox_Exception $error ) {
			return $error->getMessage();
		}
		$this->fail( 'A Dropbox_Exception was expected' );
	}

	public function test_start_builds_an_s256_request_without_redirect_or_secret(): void {
		list( $pending, $url ) = BackupPlus_Dropbox_PKCE::start( 'site-app-key' );
		$parts                 = parse_url( $url );
		parse_str( $parts['query'], $query );

		$this->assertSame( 'https://www.dropbox.com/oauth2/authorize', $parts['scheme'] . '://' . $parts['host'] . $parts['path'], 'Authorization must go to Dropbox itself' );
		$this->assertSame( 'site-app-key', $query['client_id'] );
		$this->assertSame( 'S256', $query['code_challenge_method'] );
		$this->assertSame( 'offline', $query['token_access_type'], 'A refresh token is needed for unattended backups' );
		$this->assertArrayNotHasKey( 'redirect_uri', $query, 'The code is pasted, so no redirect is registered' );
		$this->assertArrayNotHasKey( 'state', $query );
		$this->assertMatchesRegularExpression( '/\A[A-Za-z0-9_-]{43}\z/', $pending->verifier, 'The verifier is 32 random bytes in base64url' );
		$this->assertSame( rtrim( strtr( base64_encode( hash( 'sha256', $pending->verifier, true ) ), '+/', '-_' ), '=' ), $query['code_challenge'] );
		$this->assertSame( 'site-app-key', $pending->client_id );
		$this->assertEqualsWithDelta( time() + BackupPlus_Dropbox_PKCE::LIFETIME, $pending->expires, 2 );

		list( $other ) = BackupPlus_Dropbox_PKCE::start( 'site-app-key' );
		$this->assertNotSame( $pending->verifier, $other->verifier, 'Every authorization needs a fresh verifier' );
	}

	public function test_exchange_sends_the_verifier_and_returns_a_renewable_token(): void {
		$this->reply = array(
			200,
			array(
				'access_token'  => 'short-lived',
				'refresh_token' => 'long-lived',
				'expires_in'    => 14400,
				'token_type'    => 'bearer',
				'account_id'    => 'dbid:account',
				'uid'           => '12345',
			),
		);
		$token       = BackupPlus_Dropbox_PKCE::exchange( $this->pending(), 'pasted-code-0123' );

		$this->assertCount( 1, $this->posts );
		$this->assertSame( 'https://api.dropboxapi.com/oauth2/token', $this->posts[0]['url'] );
		$this->assertSame(
			array(
				'code'          => 'pasted-code-0123',
				'grant_type'    => 'authorization_code',
				'code_verifier' => str_repeat( 'v', 43 ),
				'client_id'     => 'site-app-key',
			),
			$this->posts[0]['body'],
			'PKCE exchanges carry neither an app secret nor a redirect URI'
		);
		$this->assertSame( 'short-lived', $token->oauth_token );
		$this->assertSame( 'long-lived', $token->refresh_token );
		$this->assertSame( 'bearer', $token->token_type );
		$this->assertSame( 'site-app-key', $token->client_id, 'The app key is recorded so that renewal needs no secret' );
		$this->assertEqualsWithDelta( time() + 14400 - 30, $token->expires_in, 2 );
	}

	public function test_exchange_rejects_missing_or_expired_authorizations_and_malformed_codes_offline(): void {
		foreach ( array( false, $this->pending( -1 ), (object) array( 'client_id' => 'site-app-key' ) ) as $pending ) {
			$this->assertStringContainsString( 'expired or was not started', $this->dropbox_error( fn() => BackupPlus_Dropbox_PKCE::exchange( $pending, 'pasted-code-0123' ) ) );
		}
		foreach ( array( '', 'short', "code\nwith-newline", 'code with spaces', str_repeat( 'a', 513 ), array( 'code' ) ) as $code ) {
			$this->assertStringContainsString( 'not a Dropbox authorization code', $this->dropbox_error( fn() => BackupPlus_Dropbox_PKCE::exchange( $this->pending(), $code ) ) );
		}
		$this->assertSame( array(), $this->posts, 'Nothing is sent to Dropbox for an invalid attempt' );
	}

	public function test_exchange_reports_dropbox_errors_and_requires_a_refresh_token(): void {
		$this->reply = array(
			400,
			array(
				'error'             => 'invalid_grant',
				'error_description' => 'code doesn\'t exist or has expired',
			),
		);
		$this->assertSame( "Dropbox refused the request: code doesn't exist or has expired", $this->dropbox_error( fn() => BackupPlus_Dropbox_PKCE::exchange( $this->pending(), 'pasted-code-0123' ) ) );

		$this->reply = array(
			200,
			array(
				'access_token' => 'short-lived',
				'expires_in'   => 14400,
			),
		);
		$this->assertStringContainsString( 'long-lived token', $this->dropbox_error( fn() => BackupPlus_Dropbox_PKCE::exchange( $this->pending(), 'pasted-code-0123' ) ) );
	}

	public function test_expired_pkce_tokens_are_renewed_without_a_secret(): void {
		$storage     = $this->token_storage( 'pkce-app-key' );
		$this->reply = array(
			200,
			array(
				'access_token' => 'renewed',
				'expires_in'   => 14400,
			),
		);
		new Dropbox_Curl( 'configured-key', 'stale-secret', $storage );

		$this->assertCount( 1, $this->posts );
		$this->assertSame(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => 'long-lived',
				'client_id'     => 'pkce-app-key',
			),
			$this->posts[0]['body'],
			'The token is renewed for the app it was issued to, without a secret'
		);
		$this->assertSame( 'renewed', $storage->tokens['access_token']->oauth_token );
		$this->assertGreaterThan( time(), $storage->tokens['access_token']->expires_in );
	}

	public function test_tokens_from_before_pkce_are_renewed_with_their_own_app_key_and_secret(): void {
		$storage     = $this->token_storage( null );
		$this->reply = array(
			200,
			array(
				'access_token' => 'renewed',
				'expires_in'   => 14400,
			),
		);
		new Dropbox_Curl( 'own-app-key', 'own-app-secret', $storage );

		$this->assertSame( 'own-app-key', $this->posts[0]['body']['client_id'] );
		$this->assertSame( 'own-app-secret', $this->posts[0]['body']['client_secret'] );
		$this->assertSame( 'https://api.dropboxapi.com/oauth2/token', $this->posts[0]['url'], 'Renewal goes to Dropbox, never to a relay' );
	}

	public function test_oauth1_tokens_must_be_authorized_again(): void {
		$storage                         = $this->token_storage( null );
		$storage->tokens['access_token'] = (object) array(
			'oauth_token'        => 'v1',
			'oauth_token_secret' => 'v1-secret',
		);
		$this->assertStringContainsString( 're-authenticate', $this->dropbox_error( fn() => new Dropbox_Curl( 'key', 'secret', $storage ) ) );
		$this->assertSame( array(), $this->posts );
	}

	public function test_connections_through_the_shared_upstream_app_need_reconnecting(): void {
		$provider = $this->create_provider( 'dropbox' );
		$this->assertTrue( $provider->authentication_required( array() ) );
		$this->assertTrue( $provider->authentication_required( array( 'tk_access_token' => 'old' ) ), 'A token without its app was issued to the shared upstream app' );
		$this->assertFalse(
			$provider->authentication_required(
				array(
					'tk_access_token' => 'old',
					'appkey'          => 'own-app',
				)
			),
			'Tokens of the site\'s own app keep working'
		);
		$this->assertFalse(
			$provider->authentication_required(
				array(
					'tk_access_token' => 'new',
					'token_app'       => 'pkce-app-key',
				)
			)
		);

		$provider->set_options( array( 'tk_access_token' => 'old' ), false, 'legacy' );
		$this->assertFalse( $provider->bootstrap(), 'Shared-app tokens are never sent anywhere' );
		$this->assertStringContainsString( 'app that BackupPlus no longer uses', end( $this->job->logs )[0] );
		$this->assertSame( array(), $this->posts );
	}

	public function test_app_key_defaults_to_the_built_in_one_and_strips_the_full_dropbox_marker(): void {
		$provider = $this->create_provider( 'dropbox' );
		$this->assertSame( BackupPlus_Dropbox_PKCE::default_app_key(), $provider->app_key( array() ) );
		$this->assertSame( 'own-app', $provider->app_key( array( 'appkey' => 'own-app' ) ) );
		$this->assertSame( 'own-app', $provider->app_key( array( 'appkey' => 'dropbox:own-app' ) ) );
	}

	/** In-memory token storage holding a renewable token that has expired. */
	private function token_storage( ?string $client_id ): Dropbox_StorageInterface {
		$storage = new class() implements Dropbox_StorageInterface {
			public array $tokens = array();

			public function get( $type ) {
				return $this->tokens[ $type ] ?? false;
			}

			public function set( $token, $type ) {
				$this->tokens[ $type ] = $token;
			}

			public function delete() {
				$this->tokens = array();
			}
		};
		$token   = (object) array(
			'oauth_token'        => 'expired',
			'oauth_token_secret' => 'expired',
			'token_type'         => 'bearer',
			'refresh_token'      => 'long-lived',
			'expires_in'         => time() - 10,
		);
		if ( null !== $client_id ) {
			$token->client_id = $client_id;
		}
		$storage->tokens['access_token'] = $token;
		return $storage;
	}
}
