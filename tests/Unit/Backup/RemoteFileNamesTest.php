<?php
/**
 * BPS-02: names from backup history reach the backup directory only as plain archive basenames.
 *
 * Remote listings feed backup history, and history names are joined to the backup directory to download, restore and delete
 * archives. scripts/check-remote-file-names.php covers the same rules end to end in WordPress.
 */

namespace BackupPlus\Tests\Unit\Backup;

use BackupPlus_Manipulation_Functions;
use BackupPlus_PITR_Manifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class RemoteFileNamesTest extends TestCase {

	protected function setUp(): void {
		define( 'ABSPATH', sys_get_temp_dir() . '/' );
		require dirname( __DIR__, 3 ) . '/includes/class-manipulation-functions.php';
		require dirname( __DIR__, 3 ) . '/includes/class-pitr-manifest.php';
	}

	public static function archives(): array {
		return array(
			'database'           => array( 'backup_2024-01-01-0000_My_Site_abcdef012345-db.gz' ),
			'encrypted database' => array( 'backup_2024-01-01-0000_site_abcdef012345-db2.sql.zst.crypt' ),
			'split archive'      => array( 'backup_2024-01-01-0000_site_abcdef012345-plugins3.tar.zst' ),
			'site name spaces'   => array( 'backup_2024-01-01-0000_my site #1_abcdef012345-uploads.zip' ),
			'PITR baseline'      => array( 'backup_pitr_' . str_repeat( 'a', 32 ) . '_' . str_repeat( 'b', 32 ) . '_full.sql.zst.crypt' ),
			'PITR point'         => array( 'backup_pitr_' . str_repeat( 'a', 32 ) . '_' . str_repeat( 'b', 32 ) . '_point.tar' ),
		);
	}

	public static function traversals(): array {
		return array(
			'finding example' => array( 'backup_2024-01-01-0000_x/../../../../var/www/html/wp-content/plugins/x/y.php._abcdef012345-db.gz' ),
			'sub-folder'      => array( 'sub/backup_2024-01-01-0000_site_abcdef012345-db.gz' ),
			'absolute'        => array( '/backup_2024-01-01-0000_site_abcdef012345-db.gz' ),
			'backslash'       => array( 'backup_2024-01-01-0000_x\\..\\..\\y_abcdef012345-db.gz' ),
			'dot dot'         => array( 'backup_2024-01-01-0000_x.._abcdef012345-db.gz' ),
			'NUL'             => array( "backup_2024-01-01-0000_site_abcdef012345-db.gz\0.php" ),
			'newline'         => array( "backup_2024-01-01-0000_site\n_abcdef012345-db.gz" ),
			'parent'          => array( '..' ),
			'current'         => array( '.' ),
			'empty'           => array( '' ),
			'not a string'    => array( array( 'backup_2024-01-01-0000_site_abcdef012345-db.gz' ) ),
		);
	}

	#[DataProvider( 'archives' )]
	public function test_archive_names_are_accepted( string $name ): void {
		$this->assertTrue( BackupPlus_Manipulation_Functions::is_safe_basename( $name ), "$name must be a safe basename" );
		$this->assertTrue( BackupPlus_Manipulation_Functions::is_backup_basename( $name ), "$name must be a backup archive name" );
	}

	#[DataProvider( 'traversals' )]
	public function test_names_that_are_not_basenames_are_rejected( $name ): void {
		$this->assertFalse( BackupPlus_Manipulation_Functions::is_safe_basename( $name ), 'Names that are not plain basenames must be rejected' );
		$this->assertFalse( BackupPlus_Manipulation_Functions::is_backup_basename( $name ), 'Names that are not plain basenames must be rejected' );
	}

	public function test_other_basenames_are_safe_but_not_fetched_from_remote_storage(): void {
		foreach ( array( 'log.abcdef012345.txt', 'backup_2024-01-01-0000_site_abcdef012345-db.gz.php', 'wordpress-database.sql.gz' ) as $name ) {
			$this->assertTrue( BackupPlus_Manipulation_Functions::is_safe_basename( $name ), "$name is a plain basename" );
			$this->assertFalse( BackupPlus_Manipulation_Functions::is_backup_basename( $name ), "$name is not a backup archive name" );
		}
	}

	/** The PITR pattern is duplicated so that the helper does not need the PITR classes; keep the two in step. */
	public function test_pitr_pattern_matches_the_manifest_rule(): void {
		$prefix = 'backup_pitr_' . str_repeat( 'c', 32 ) . '_' . str_repeat( 'd', 32 ) . '_';
		foreach ( array( 'full.sql.gz', 'full.sql.zst.crypt', 'log12.bin.zst', 'log1.bin.gz.crypt', 'manifest.json', 'manifest.json.crypt', 'locator.json', 'point.tar', 'locator.json.crypt', 'full.sql', 'point.tar/x', 'log.bin.gz' ) as $suffix ) {
			try {
				BackupPlus_PITR_Manifest::filename( $prefix . $suffix );
				$manifest = true;
			} catch ( RuntimeException $e ) {
				$manifest = false;
			}
			$this->assertSame( $manifest, (bool) preg_match( BackupPlus_Manipulation_Functions::PITR_NAME_PATTERN, $prefix . $suffix ), "The PITR patterns must agree on $suffix" );
		}
	}
}
