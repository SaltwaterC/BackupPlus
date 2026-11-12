<?php
/**
 * BPS-20: every private folder gets one .htaccess that denies access on Apache 2.4 (mod_authz_core) and Apache 2.2, and the
 * files earlier versions wrote are replaced while a site's own rules are left alone.
 */

namespace BackupPlus\Tests\Unit\Backup;

use BackupPlus_Web_Guard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class WebGuardTest extends TestCase {
	private string $directory;

	protected function setUp(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', sys_get_temp_dir() . '/' );
		}
		require_once dirname( __DIR__, 3 ) . '/includes/class-web-guard.php';
		$this->directory = sys_get_temp_dir() . '/bp-web-guard-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->directory, 0700 );
	}

	protected function tearDown(): void {
		foreach ( (array) scandir( $this->directory ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				unlink( $this->directory . '/' . $entry );
			}
		}
		rmdir( $this->directory );
	}

	public function test_each_apache_version_reads_only_its_own_branch(): void {
		$this->assertSame(
			"<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n",
			BackupPlus_Web_Guard::HTACCESS
		);
		// No Order or Deny line outside the branch that only Apache 2.2 (no mod_authz_core) reads.
		$outside = preg_replace( '/<IfModule !mod_authz_core\.c>.*?<\/IfModule>/s', '', BackupPlus_Web_Guard::HTACCESS );
		$this->assertDoesNotMatchRegularExpression( '/^\s*(?:order|deny|allow)\b/im', $outside );
	}

	/** The writers use the shared contents rather than their own copies. */
	public function test_every_writer_uses_the_shared_contents(): void {
		foreach ( array( 'class-backup-directory.php', 'class-credential-key-store.php', 'class-pitr.php', 'class-pitr-admin.php' ) as $file ) {
			$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/' . $file );
			$this->assertStringContainsString( 'BackupPlus_Web_Guard::HTACCESS', $source, $file );
			$this->assertDoesNotMatchRegularExpression( '/["\'](?:deny from all|Require all denied)/i', $source, $file );
		}
	}

	public static function previous(): array {
		return array(
			'backup folder'                => array( 'deny from all' ),
			'backup folder with a newline' => array( "deny from all\n" ),
			'PITR folder'                  => array( "Require all denied\nDeny from all\n" ),
			'PITR client folder'           => array( "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ),
			'Windows line endings'         => array( "Require all denied\r\nDeny from all\r\n" ),
		);
	}

	#[DataProvider( 'previous' )]
	public function test_files_from_earlier_versions_are_replaced_with_their_permissions( string $contents ): void {
		$path = $this->directory . '/.htaccess';
		file_put_contents( $path, $contents );
		chmod( $path, 0640 );
		$this->assertTrue( BackupPlus_Web_Guard::upgrade_htaccess( $path ) );
		$this->assertSame( BackupPlus_Web_Guard::HTACCESS, file_get_contents( $path ) );
		clearstatcache();
		$this->assertSame( 0640, fileperms( $path ) & 0777 );
		$this->assertSame( array( '.', '..', '.htaccess' ), scandir( $this->directory ), 'No temporary file is left.' );
		$this->assertTrue( BackupPlus_Web_Guard::upgrade_htaccess( $path ), 'A current file is reported as current.' );
	}

	public static function foreign(): array {
		return array(
			'site rules'          => array( "RewriteEngine On\n" ),
			'deny with a comment' => array( "# Mine\ndeny from all\n" ),
			'deny and allow'      => array( "deny from all\nallow from 127.0.0.1\n" ),
			'empty'               => array( '' ),
		);
	}

	#[DataProvider( 'foreign' )]
	public function test_other_contents_are_left_alone( string $contents ): void {
		$path = $this->directory . '/.htaccess';
		file_put_contents( $path, $contents );
		$this->assertFalse( BackupPlus_Web_Guard::upgrade_htaccess( $path ) );
		$this->assertSame( $contents, file_get_contents( $path ) );
	}

	public function test_links_and_missing_files_are_left_alone(): void {
		$target = $this->directory . '/target';
		file_put_contents( $target, 'deny from all' );
		symlink( $target, $this->directory . '/.htaccess' );
		$this->assertFalse( BackupPlus_Web_Guard::upgrade_htaccess( $this->directory . '/.htaccess' ) );
		$this->assertTrue( is_link( $this->directory . '/.htaccess' ) );
		$this->assertSame( 'deny from all', file_get_contents( $target ) );
		$this->assertFalse( BackupPlus_Web_Guard::upgrade_htaccess( $this->directory . '/missing' ) );
		$this->assertFileDoesNotExist( $this->directory . '/missing' );
	}
}
