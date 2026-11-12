<?php
/**
 * BPS-08: the mysqldump option file is owner-only, holds exactly one password line and is always removed. Passwords with a
 * line break or NUL are refused before anything is written, and external database passwords with them are not saved.
 *
 * scripts/check-mysqldump-option-file.php checks the same file with the real mysqldump, in WordPress.
 */

namespace BackupPlus\Tests\Unit\Backup;

use BackupPlus_Backup_Sources;
use BackupPlus_Mysqldump_Option_File;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class MysqldumpOptionFileTest extends TestCase {

	private string $directory = '';

	protected function setUp(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/backup-sources-stubs.php';
		require_once dirname( __DIR__, 2 ) . '/Support/backup-directory-stubs.php';
		require_once BACKUPPLUS_DIR . '/includes/class-mysqldump-option-file.php';
		require_once BACKUPPLUS_DIR . '/includes/class-backup-sources.php';
		$this->directory = sys_get_temp_dir() . '/bp-mysqldump-' . bin2hex( random_bytes( 5 ) );
		mkdir( $this->directory );
	}

	protected function tearDown(): void {
		foreach ( array_diff( scandir( $this->directory ), array( '.', '..' ) ) as $entry ) {
			unlink( $this->directory . '/' . $entry );
		}
		rmdir( $this->directory );
	}

	private function entries(): array {
		return array_values( array_diff( scandir( $this->directory ), array( '.', '..' ) ) );
	}

	public function test_the_file_is_owner_only_whatever_the_umask(): void {
		$mask = umask( 0 );
		try {
			$path = BackupPlus_Mysqldump_Option_File::create( $this->directory, 'secret' );
		} finally {
			umask( $mask );
		}
		clearstatcache();
		$this->assertSame( 0600, fileperms( $path ) & 0777 );
		$this->assertSame( $this->directory, dirname( $path ) );
		$this->assertMatchesRegularExpression( '/\A[0-9a-f]{32}\.tmp\z/', basename( $path ) );
		$this->assertSame( "[mysqldump]\npassword=\"secret\"\n", file_get_contents( $path ) );
		BackupPlus_Mysqldump_Option_File::remove( $path );
		$this->assertSame( array(), $this->entries() );
	}

	public function test_quotes_and_backslashes_stay_inside_the_value(): void {
		$this->assertSame( "[mysqldump]\npassword=\"a\\\\\\\"b'c\\\\\"\n", BackupPlus_Mysqldump_Option_File::contents( 'a\\"b\'c\\' ) );
	}

	public static function refused(): array {
		return array(
			'line feed'       => array( "secret\nresult-file=/tmp/x" ),
			'carriage return' => array( "secret\rplugin-dir=/tmp" ),
			'NUL'             => array( "secret\0" ),
			'not a string'    => array( array( 'secret' ) ),
		);
	}

	#[DataProvider( 'refused' )]
	public function test_a_password_that_would_add_options_is_refused_before_any_file_exists( $password ): void {
		$this->assertFalse( BackupPlus_Mysqldump_Option_File::valid_password( $password ) );
		try {
			BackupPlus_Mysqldump_Option_File::create( $this->directory, $password );
			$this->fail( 'The password was accepted.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'line break or NUL', $e->getMessage() );
		}
		$this->assertSame( array(), $this->entries() );
	}

	public function test_files_left_by_an_interrupted_dump_are_removed_at_shutdown(): void {
		$first  = BackupPlus_Mysqldump_Option_File::create( $this->directory, 'one' );
		$second = BackupPlus_Mysqldump_Option_File::create( $this->directory, 'two' );
		BackupPlus_Mysqldump_Option_File::remove( $first );
		$this->assertSame( array( basename( $second ) ), $this->entries() );
		BackupPlus_Mysqldump_Option_File::remove_pending();
		$this->assertSame( array(), $this->entries() );
	}

	public function test_an_external_database_password_with_a_line_break_is_not_saved(): void {
		$saved = BackupPlus_Backup_Sources::sanitize_connections(
			array(
				'_present' => '1',
				3          => array(
					'host' => 'db.example',
					'user' => 'reader',
					'pass' => "secret\nresult-file=/var/www/html/x.php",
					'name' => 'shop',
				),
				4          => array(
					'host' => 'db.example',
					'user' => 'reader',
					'pass' => 'fine "quoted" \\ password',
					'name' => 'blog',
				),
			)
		);
		$this->assertSame( array( 3, 4 ), array_keys( $saved ) );
		$this->assertSame( '', $saved[3]['pass'] );
		$this->assertSame( 'shop', $saved[3]['name'] );
		$this->assertSame( 'fine "quoted" \\ password', $saved[4]['pass'] );
		$this->assertCount( 1, $GLOBALS['backup_directory_settings_errors'] );
		$this->assertSame( array( 'backupplus_extradbs', 'invalid_password' ), array_slice( $GLOBALS['backup_directory_settings_errors'][0], 0, 2 ) );
		$this->assertStringContainsString( 'shop@db.example', $GLOBALS['backup_directory_settings_errors'][0][2] );
	}
}
