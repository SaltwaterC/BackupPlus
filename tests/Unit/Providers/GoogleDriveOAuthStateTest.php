<?php
/** BPS-05: Google's sign-in return is accepted only with the single-use state this site issued to the same user for the same instance. */

namespace BackupPlus\Tests\Unit\Providers;

use BackupPlus\Tests\Support\ProviderTestCase;
use BackupPlus_BackupModule;
use BackupPlus_Options;

final class GoogleDriveOAuthStateTest extends ProviderTestCase {

	private BackupPlus_BackupModule $provider;

	private array $options = array(
		'clientid' => 'own-client',
		'secret'   => 'own-secret',
		'token'    => 'existing-refresh',
	);

	/** Bodies of the requests sent to Google's token endpoint. */
	private array $posts = array();

	protected function setUp(): void {
		parent::setUp();
		$this->provider = $this->create_provider( 'googledrive' );
		$this->provider->set_options( $this->options, false, 'test-instance' );
		BackupPlus_Options::$options['backupplus_googledrive'] = array(
			'version'  => 1,
			'settings' => array( 'test-instance' => $this->options ),
		);
		$this->posts              = array();
		$GLOBALS['cloud_user']    = 1;
		$GLOBALS['provider_post'] = function ( $url, $args ) {
			$this->assertSame( 'https://accounts.google.com/o/oauth2/token', $url );
			$this->posts[] = $args['body'];
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode(
					array(
						'refresh_token' => 'new-refresh',
						'access_token'  => 'new-access',
					)
				),
			);
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['provider_post'], $GLOBALS['cloud_user'], $_GET['state'], $_GET['nonce'] );
		parent::tearDown();
	}

	/** Start a sign-in and return the state Google would send back. */
	private function begin(): string {
		parse_str( (string) parse_url( $this->provider->authorization_url( 'test-instance' ), PHP_URL_QUERY ), $parameters );
		return $parameters['state'];
	}

	private function assert_refused( $state, string $why ): void {
		$this->thrown(
			function () use ( $state ) {
				$this->provider->complete_oauth( $state, 'attacker-code' );
			},
			$why
		);
		$this->assertSame( array(), $this->posts, 'A refused return must not send its code to Google: ' . $why );
		$this->assertSame( 'existing-refresh', $this->provider->get_options()['token'], 'A refused return must not change the connection: ' . $why );
	}

	public function test_the_state_is_random_bound_and_stored_only_as_a_hash(): void {
		$state  = $this->begin();
		$stored = $this->provider->get_options()['oauth_state'];
		$this->assertMatchesRegularExpression( '/^token:test-instance:[0-9a-f]{64}$/', $state );
		$this->assertNotSame( $state, $this->begin(), 'Every sign-in gets a new state' );
		$state = $this->begin();
		$this->assertSame( hash( 'sha256', $state ), $this->provider->get_options()['oauth_state']['hash'] );
		$this->assertSame( 1, $stored['user'] );
		$this->assertLessThanOrEqual( time() + 600, $stored['expires'] );
		$this->assertGreaterThan( time() + 590, $stored['expires'] );
		$this->assertStringNotContainsString( $state, serialize( BackupPlus_Options::$options ), 'The state itself is not stored' );
		$this->assertContains( 'oauth_state', $this->provider->filter_frontend_settings_keys() );
	}

	public function test_a_filter_cannot_drop_the_state(): void {
		add_filter(
			'backupplus_googledrive_auth_params',
			function ( $params ) {
				unset( $params['state'] );
				return $params;
			}
		);
		$this->assertStringStartsWith( 'token:test-instance:', $this->begin() );
	}

	public function test_a_valid_return_is_exchanged_once(): void {
		$state = $this->begin();
		$this->assertTrue( $this->provider->complete_oauth( $state, 'real-code' ) );
		$this->assertCount( 1, $this->posts );
		$this->assertSame( 'real-code', $this->posts[0]['code'] );
		$options = $this->provider->get_options();
		$this->assertSame( 'new-refresh', $options['token'] );
		$this->assertSame( 'new-access', $options['tmp_access_token'] );
		$this->assertArrayNotHasKey( 'oauth_state', $options );
		$this->assertArrayNotHasKey( 'auth_in_progress', $options );

		$this->posts = array();
		$this->provider->set_options( array_merge( $options, array( 'token' => 'existing-refresh' ) ), true );
		$this->assert_refused( $state, 'a reused state' );
	}

	public function test_an_empty_or_missing_state_is_refused(): void {
		$this->begin();
		$this->assert_refused( '', 'an empty state' );
		$this->begin();
		$this->assert_refused( null, 'a missing state' );
		$this->begin();
		$this->assert_refused( array( 'token:test-instance' ), 'a non-string state' );
	}

	public function test_a_return_without_a_started_sign_in_is_refused(): void {
		$this->assert_refused( 'token:test-instance', 'the old instance-only state' );
		$this->assert_refused( 'token:test-instance:' . str_repeat( '0', 64 ), 'a forged state' );
	}

	public function test_a_wrong_state_is_refused_and_ends_the_pending_sign_in(): void {
		$state = $this->begin();
		$this->assert_refused( $state . '0', 'a wrong state' );
		$this->assert_refused( $state, 'the right state after a wrong one' );
	}

	public function test_a_state_for_another_user_is_refused(): void {
		$state                 = $this->begin();
		$GLOBALS['cloud_user'] = 2;
		$this->assert_refused( $state, 'a state for another user' );
	}

	public function test_a_state_for_another_instance_is_refused(): void {
		$state = $this->begin();
		$this->assert_refused( str_replace( 'token:test-instance:', 'token:other-instance:', $state ), 'this instance\'s state replayed for another instance' );
	}

	public function test_an_expired_state_is_refused(): void {
		$state                             = $this->begin();
		$options                           = $this->provider->get_options();
		$options['oauth_state']['expires'] = time() - 1;
		$this->provider->set_options( $options, true );
		$this->assert_refused( $state, 'an expired state' );
	}

	public function test_a_valid_state_without_a_code_is_refused_and_consumed(): void {
		$state = $this->begin();
		$this->thrown(
			function () use ( $state ) {
				$this->provider->complete_oauth( $state, '' );
			},
			'a return without a code'
		);
		$this->assertArrayNotHasKey( 'oauth_state', $this->provider->get_options() );
		$this->assertSame( array(), $this->posts );
	}

	public function test_revoke_is_not_reachable_by_url(): void {
		$_GET['state'] = 'revoke:test-instance';
		$this->provider->action_auth();
		$this->assertSame( 'existing-refresh', $this->provider->get_options()['token'], 'state=revoke must not wipe the connection' );
	}

	public function test_the_success_notice_needs_a_nonce(): void {
		$_GET['state'] = 'success:test-instance';
		$this->provider->action_auth();
		$this->assertArrayNotHasKey( 'all_admin_notices', $this->filters );

		$_GET['nonce'] = 'test-nonce';
		$this->provider->action_auth();
		$this->assertArrayHasKey( 'all_admin_notices', $this->filters );
	}
}
