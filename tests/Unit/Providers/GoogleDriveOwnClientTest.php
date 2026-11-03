<?php
/** BPS-01: Google Drive works only with the site's own OAuth client; no shared client or relay remains. */

namespace BackupPlus\Tests\Unit\Providers;

use BackupPlus\Tests\Support\ProviderTestCase;
use BackupPlus_BackupModule;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class GoogleDriveOwnClientTest extends ProviderTestCase {

	private BackupPlus_BackupModule $provider;

	protected function setUp(): void {
		parent::setUp();
		$this->provider = $this->create_provider( 'googledrive' );
	}

	public function test_sign_in_is_offered_only_once_an_own_client_is_saved(): void {
		$this->assertFalse( $this->provider->authentication_required( array() ), 'Without a client there is nothing to sign in with' );
		$this->assertFalse( $this->provider->authentication_required( array( 'clientid' => 'own' ) ) );
		$this->assertTrue(
			$this->provider->authentication_required(
				array(
					'clientid' => 'own',
					'secret'   => 'own-secret',
				)
			)
		);
		$this->assertFalse(
			$this->provider->authentication_required(
				array(
					'clientid' => 'own',
					'secret'   => 'own-secret',
					'token'    => 'refresh',
				)
			)
		);
	}

	public function test_shared_client_connections_get_a_notice_until_signed_in_with_an_own_client(): void {
		$notices = $this->provider->get_admin_notices(
			array(
				'user_id' => 'shared-app-user',
				'token'   => 'shared-app-token',
			)
		);
		$this->assertCount( 1, $notices );
		$this->assertSame( 'error', $notices[0]['class'] );
		$this->assertStringContainsString( 'requires your own Google OAuth client', $notices[0]['message'] );

		$this->assertSame( array(), $this->provider->get_admin_notices( array() ) );
		$this->assertSame(
			array(),
			$this->provider->get_admin_notices(
				array(
					'user_id'  => 'shared-app-user',
					'clientid' => 'own',
				)
			)
		);
	}

	public function test_shared_client_tokens_are_never_used(): void {
		$this->provider->set_options(
			array(
				'user_id' => 'shared-app-user',
				'token'   => 'shared-app-token',
			),
			false,
			'legacy'
		);
		$this->assertSame( 'not_authorized', $this->provider->bootstrap()->get_error_code() );
		$this->assertSame( 'no_settings', $this->provider->listfiles()->get_error_code() );
		$this->assertSame( array(), $this->requests, 'Nothing is sent to Google or to a relay' );
		$this->assertTrue( $this->provider->options_exist( array( 'token' => 'shared-app-token' ) ), 'The connection stays listed so that its notice is shown' );
	}

	public function test_redirect_returns_to_the_site_itself(): void {
		$redirect = ( fn() => $this->redirect_uri() )->call( $this->provider );
		$this->assertStringStartsWith( \BackupPlus_Options::admin_page_url() . '?', $redirect );
		$this->assertStringEndsWith( 'action=backupplusmethod-googledrive-auth', $redirect );
	}

	/** The relay hostname and the upstream apps' client identifiers must not come back. */
	public function test_no_relay_or_shared_oauth_client_remains_in_the_plugin(): void {
		$root      = dirname( __DIR__, 3 );
		$forbidden = array(
			'backupplusplus',
			'auth.updraftplus.com',
			'updraftplus.com/auth',
			'916618189494-',
			'w413z4qhjz656ne',
			'dzQxM3o0cWhqejY1Nm5l',
			'BACKUPPLUS_GOOGLEDRIVE_CALLBACK_URL',
			'BACKUPPLUS_DROPBOX_AUTH_RETURN_URL',
		);
		$found     = array();
		foreach ( array( 'methods', 'includes', 'admin.php', 'class-backupplus.php', 'backupplus.php', 'options.php' ) as $path ) {
			$path = $root . '/' . $path;
			if ( ! file_exists( $path ) ) {
				continue;
			}
			$files = is_dir( $path ) ? new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ) ) : array( new \SplFileInfo( $path ) );
			foreach ( $files as $file ) {
				if ( ! in_array( $file->getExtension(), array( 'php', 'js' ), true ) ) {
					continue;
				}
				$contents = file_get_contents( $file->getPathname() );
				foreach ( $forbidden as $needle ) {
					if ( false !== stripos( $contents, $needle ) ) {
						$found[] = substr( $file->getPathname(), strlen( $root ) + 1 ) . ': ' . $needle;
					}
				}
			}
		}
		$this->assertSame( array(), $found );
	}
}
