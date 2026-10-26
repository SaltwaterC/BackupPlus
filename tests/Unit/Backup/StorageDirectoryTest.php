<?php
namespace BackupPlus\Tests\Unit\Backup;

use BackupPlus_Core_Entities;
use BackupPlus_Options;
use BackupPlus_Storage_Directory;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class StorageDirectoryTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/bp-storage-' . bin2hex( random_bytes( 8 ) );
		define( 'ABSPATH', $this->root . '/' );
		define( 'WP_CONTENT_DIR', $this->root . '/wp-content' );
		mkdir( WP_CONTENT_DIR, 0700, true );
		require_once dirname( __DIR__, 2 ) . '/Support/storage-directory-stubs.php';
		require_once BACKUPPLUS_DIR . '/includes/core/trait-entities.php';
		require_once BACKUPPLUS_DIR . '/includes/class-storage-directory.php';
	}

	protected function tearDown(): void {
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $files as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->root );
	}

	private function resolver(): object {
		return new class {
			use BackupPlus_Core_Entities;
			public $backup_dir;
		};
	}

	public function test_first_installation_saves_a_randomized_folder_and_reuses_it(): void {
		$path = $this->resolver()->backups_dir_location();
		$this->assertMatchesRegularExpression( '#^' . preg_quote( WP_CONTENT_DIR, '#' ) . '/backupplus-[a-f0-9]{32}$#', $path );
		$this->assertSame( basename( $path ), BackupPlus_Options::get_backupplus_option( 'backupplus_dir' ) );
		foreach ( array( 'index.html', '.htaccess', 'web.config' ) as $guard ) {
			$this->assertFileExists( $path . '/' . $guard );
		}
		$this->assertSame( $path, $this->resolver()->backups_dir_location( false ) );
		BackupPlus_Options::update_backupplus_option( 'backupplus_dir', '' );
		$this->assertSame( $path, $this->resolver()->backups_dir_location(), 'Resetting to the default must reuse the installation folder.' );
		$this->assertDirectoryDoesNotExist( WP_CONTENT_DIR . '/backupplus' );
	}

	public function test_existing_legacy_folder_remains_the_default(): void {
		mkdir( WP_CONTENT_DIR . '/backupplus' );
		file_put_contents( WP_CONTENT_DIR . '/backupplus/backup.zip', 'existing backup' );
		$this->assertSame( WP_CONTENT_DIR . '/backupplus', $this->resolver()->backups_dir_location() );
		$this->assertSame( 'existing backup', file_get_contents( WP_CONTENT_DIR . '/backupplus/backup.zip' ) );
	}

	public function test_a_surviving_randomized_backup_folder_is_reused_without_metadata(): void {
		$path                                 = $this->resolver()->backups_dir_location();
		$GLOBALS['storage_directory_options'] = array();
		$this->assertSame( $path, $this->resolver()->backups_dir_location() );
	}

	public function test_configured_folders_are_preserved(): void {
		BackupPlus_Options::update_backupplus_option( 'backupplus_dir', 'custom-backups' );
		$this->assertSame( WP_CONTENT_DIR . '/custom-backups', $this->resolver()->backups_dir_location() );
		BackupPlus_Options::update_backupplus_option( 'backupplus_dir', $this->root . '/private-backups' );
		$this->assertSame( $this->root . '/private-backups', $this->resolver()->backups_dir_location() );
	}

	public function test_each_folder_has_its_own_persistent_random_name(): void {
		$backup  = BackupPlus_Storage_Directory::name( 'backupplus' );
		$private = BackupPlus_Storage_Directory::name( 'backupplus-private' );
		$this->assertNotSame( substr( $backup, -32 ), substr( $private, -32 ) );
		$this->assertSame( $backup, BackupPlus_Storage_Directory::name( 'backupplus' ) );
		$this->assertSame( $private, BackupPlus_Storage_Directory::name( 'backupplus-private' ) );
	}

	public function test_an_invalid_saved_name_is_rejected_before_creating_a_folder(): void {
		$GLOBALS['storage_directory_options']['backupplus_storage_directory_backupplus'] = 'backupplus-predictable';
		$this->expectException( \RuntimeException::class );
		$this->resolver()->backups_dir_location();
	}
}
