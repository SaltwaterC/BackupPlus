<?php
/** Component inventory and deletion isolation for selective restores. */
namespace BackupPlus\Tests\Unit\Restore;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class RestoreInventoryTest extends TestCase {
	private string $directory;

	protected function setUp(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/restore-inventory-stubs.php';
		$this->directory = sys_get_temp_dir() . '/backupplus-inventory-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->directory );
		$GLOBALS['backupplus'] = new \Restore_Inventory_Backup( $this->directory );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->directory . '/*' ) as $file ) { unlink( $file ); }
		rmdir( $this->directory );
	}

	private function archive( string $name, array $entries ): void {
		$zip = new \ZipArchive();
		$this->assertTrue( $zip->open( $this->directory . '/' . $name, \ZipArchive::CREATE ) );
		foreach ( $entries as $path => $contents ) { $zip->addFromString( $path, $contents ); }
		$zip->close();
	}

	public function test_combines_split_archives_and_applies_cumulative_deletions(): void {
		$this->archive(
			'base.zip',
			array(
				'plugins/alpha/main.php' => 'a',
				'plugins/single.php'     => 'b',
			)
		);
		$this->archive(
			'increment.zip',
			array(
				'plugins/beta/main.php'    => 'c',
				'backupplus-manifest.json' => json_encode(
					array(
						'version' => 2,
						'root'    => 'plugins',
						'deleted' => array( 'plugins/alpha', 'plugins/single.php/child' ),
					)
				),
			)
		);
		$this->assertSame( array( 'beta', 'single.php' ), \BackupPlus_Restore_Inventory::components( array( 'base.zip', 'increment.zip' ) ) );
	}

	public function test_fails_closed_for_missing_and_unreadable_archives(): void {
		$this->assertInstanceOf( \WP_Error::class, \BackupPlus_Restore_Inventory::components( array( 'missing.zip' ) ) );
		file_put_contents( $this->directory . '/invalid.zip', 'not a zip' );
		$this->assertInstanceOf( \WP_Error::class, \BackupPlus_Restore_Inventory::components( array( 'invalid.zip' ) ) );
	}

	public function test_reads_prepared_encrypted_archive_with_ticket_binding(): void {
		$this->archive( 'prepared.zip', array( 'plugins/alpha/main.php' => 'a' ) );
		file_put_contents( $this->directory . '/archive.zip.crypt', 'ciphertext' );
		$this->assertSame( array( 'alpha' ), \BackupPlus_Restore_Inventory::components( array( 'archive.zip.crypt' ), 'valid', '', 'plugins', 123 ) );
		$this->assertSame( array( 'valid', '', 123 ), \BackupPlus_Age_Restore::$call );
		$this->assertInstanceOf( \WP_Error::class, \BackupPlus_Restore_Inventory::components( array( 'archive.zip.crypt' ), 'invalid' ) );
	}

	public function test_reads_zstandard_tar_archive(): void {
		require_once BACKUPPLUS_DIR . '/includes/class-tar.php';
		if ( ! \BackupPlus_Archive_Compression::enabled() ) { $this->markTestSkipped( 'zstd is unavailable.' ); }
		file_put_contents( $this->directory . '/source', 'a' );
		$tar = new \BackupPlus_Tar();
		$this->assertTrue( $tar->open( $this->directory . '/archive.tar' ) );
		$tar->addFile( $this->directory . '/source', 'plugins/alpha/main.php' );
		$tar->close();
		\BackupPlus_Archive_Compression::compress_file( $this->directory . '/archive.tar' );
		$this->assertSame( array( 'alpha' ), \BackupPlus_Restore_Inventory::components( array( 'archive.tar.zst' ) ) );
	}

	public function test_incremental_deletions_only_affect_selected_plugin(): void {
		$restorer                 = new \Restore_Inventory_Harness();
		$GLOBALS['wp_filesystem'] = new \Restore_Inventory_Filesystem();
		$result                   = $restorer->delete_paths(
			array(
				'version' => 2,
				'type'    => 'plugins',
				'root'    => 'plugins',
				'deleted' => array( 'plugins/alpha/old.php', 'plugins/beta/old.php' ),
			),
			$this->directory
		);
		$this->assertTrue( $result );
		$this->assertSame( array( $this->directory . '/alpha/old.php' ), $GLOBALS['wp_filesystem']->deleted );
	}

	public function test_legacy_manifest_pruning_only_affects_selected_plugin(): void {
		$restorer                 = new \Restore_Inventory_Harness();
		$GLOBALS['wp_filesystem'] = new \Restore_Inventory_Filesystem();
		$this->assertTrue( $restorer->scan_paths( $this->directory ) );
		$this->assertSame( array( $this->directory . '/plugins/alpha' ), $GLOBALS['wp_filesystem']->deleted );
	}

	public function test_move_old_deletion_fallback_preserves_unselected_plugins(): void {
		$restorer   = new \Restore_Inventory_Harness();
		$filesystem = new \Restore_Inventory_Move_Filesystem();
		$restorer->move_old( $filesystem, $this->directory . '/plugins' );
		$this->assertSame( array( $this->directory . '/plugins/alpha' ), $filesystem->deleted );
	}
}
