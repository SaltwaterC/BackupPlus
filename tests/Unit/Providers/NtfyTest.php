<?php
/** ntfy notifications; the HTTP request is captured and never leaves the process. */

namespace BackupPlus\Tests\Unit\Providers;

use BackupPlus\Tests\Support\ProviderTestCase;
use BackupPlus_Ntfy;
use BackupPlus_Options;
use WP_Error;

final class NtfyTest extends ProviderTestCase {

	/** Arguments of every request that reached the wp_remote_post() shim. */
	private array $posts = array();

	/** What the shim answers with. */
	private $answer;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once BACKUPPLUS_DIR . '/includes/class-ntfy.php';
	}

	protected function setUp(): void {
		parent::setUp();
		$this->posts                                    = array();
		$this->answer                                   = array( 'response' => array( 'code' => 200 ) );
		$GLOBALS['provider_post']                       = function ( $url, $args ) {
			$this->posts[] = compact( 'url', 'args' );
			return $this->answer;
		};
		BackupPlus_Options::$options['backupplus_ntfy'] = array(
			'server' => 'https://ntfy.example.test',
			'topic'  => 'backups',
			'token'  => 'tk_secret',
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['provider_post'] );
	}

	public function test_a_notification_is_published_as_json_with_the_token(): void {
		$this->assertTrue( BackupPlus_Ntfy::notify( 'Backed up: Café', 'All good', 4, array( 'warning' ), 'https://example.test/log' ) );
		$this->assertCount( 1, $this->posts );
		$this->assertSame( 'https://ntfy.example.test', $this->posts[0]['url'], 'ntfy JSON publishing goes to the server root' );
		$this->assertSame( 'Bearer tk_secret', $this->posts[0]['args']['headers']['Authorization'] );
		$this->assertSame( 0, $this->posts[0]['args']['redirection'], 'A redirect must never replay the token' );
		$this->assertSame(
			array(
				'topic'    => 'backups',
				'title'    => 'Backed up: Café',
				'message'  => 'All good',
				'priority' => 4,
				'tags'     => array( 'warning' ),
				'click'    => 'https://example.test/log',
			),
			json_decode( $this->posts[0]['args']['body'], true ),
			'Non-ASCII titles must travel in the body, not in headers'
		);
	}

	public function test_no_token_sends_no_authorization_header(): void {
		BackupPlus_Options::$options['backupplus_ntfy']['token'] = '';
		BackupPlus_Ntfy::notify( 'Title', 'Body' );
		$this->assertArrayNotHasKey( 'Authorization', $this->posts[0]['args']['headers'] );
		$this->assertArrayNotHasKey( 'click', json_decode( $this->posts[0]['args']['body'], true ) );
	}

	public function test_nothing_is_sent_without_a_topic(): void {
		BackupPlus_Options::$options['backupplus_ntfy'] = array(
			'server' => 'https://ntfy.example.test',
			'topic'  => '',
		);
		$this->assertFalse( BackupPlus_Ntfy::enabled() );
		$this->assertFalse( BackupPlus_Ntfy::notify( 'Title', 'Body' ) );
		$this->assertSame( array(), $this->posts );
	}

	public function test_long_messages_are_cut_on_a_character_boundary(): void {
		BackupPlus_Ntfy::notify( 'Title', str_repeat( 'é', 5000 ) );
		$message = json_decode( $this->posts[0]['args']['body'], true )['message'];
		$this->assertLessThanOrEqual( BackupPlus_Ntfy::MAX_MESSAGE, strlen( $message ) );
		$this->assertTrue( mb_check_encoding( $message, 'UTF-8' ), 'Shortening must not split a multibyte character' );
		$this->assertStringEndsWith( '...', $message );
	}

	public function test_failures_are_logged_without_the_topic_or_token(): void {
		$this->answer = array(
			'response' => array(
				'code'    => 403,
				'message' => 'Forbidden',
			),
		);
		$this->assertFalse( BackupPlus_Ntfy::notify( 'Title', 'Body' ) );
		$this->answer = new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );
		$this->assertFalse( BackupPlus_Ntfy::notify( 'Title', 'Body' ) );
		$this->assertCount( 2, $this->job->logs );
		$this->assertStringContainsString( 'HTTP 403', $this->job->logs[0][0] );
		foreach ( $this->job->logs as $log ) {
			$this->assertStringNotContainsString( 'tk_secret', $log[0] );
			$this->assertStringNotContainsString( 'backups', $log[0] );
		}
	}

	public function test_sanitize_rejects_bad_input(): void {
		$this->assertSame( array(), BackupPlus_Ntfy::sanitize( 'topic' ) );
		$this->assertSame( array(), BackupPlus_Ntfy::sanitize( array( 'topic' => 'has space' ) ) );
		$this->assertSame( array(), BackupPlus_Ntfy::sanitize( array( 'topic' => str_repeat( 'a', 65 ) ) ) );

		$clean = BackupPlus_Ntfy::sanitize(
			array(
				'server' => 'javascript:alert(1)',
				'topic'  => ' site-backups ',
				'token'  => "bad\r\nX-Injected: 1",
			)
		);
		$this->assertSame( BackupPlus_Ntfy::DEFAULT_SERVER, $clean['server'], 'Only http(s) servers are accepted' );
		$this->assertSame( 'site-backups', $clean['topic'] );
		$this->assertSame( '', $clean['token'], 'A token that cannot be a header value is dropped' );

		$this->assertSame(
			'http://10.0.0.5:8080',
			BackupPlus_Ntfy::sanitize(
				array(
					'server' => 'http://10.0.0.5:8080/',
					'topic'  => 'a',
				)
			)['server'],
			'Self-hosted servers may use plain http and a port'
		);
		$this->assertSame(
			BackupPlus_Ntfy::DEFAULT_SERVER,
			BackupPlus_Ntfy::sanitize(
				array(
					'server' => 'https://user:pw@ntfy.example.test',
					'topic'  => 'a',
				)
			)['server'],
			'Credentials in the server URL are refused'
		);
	}
}
