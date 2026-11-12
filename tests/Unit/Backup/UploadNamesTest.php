<?php
/**
 * BPS-06: only backup logs and archives with an archive extension may be uploaded into the backup directory.
 *
 * scripts/check-upload-names.php covers the same rules end to end, through real uploads to WordPress.
 */

namespace BackupPlus\Tests\Unit\Backup;

use BackupPlus_Manipulation_Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class UploadNamesTest extends TestCase {

	private const FOREIGN = array(
		array(
			'pattern' => '^other_backup_[0-9]+\.',
			'desc'    => 'Other Tool',
		),
	);

	protected function setUp(): void {
		define( 'ABSPATH', sys_get_temp_dir() . '/' );
		require dirname( __DIR__, 3 ) . '/includes/class-manipulation-functions.php';
	}

	public static function accepted(): array {
		return array(
			'log'                => array( 'log.abcdef012345.txt', 'log', '', '' ),
			'database'           => array( 'backup_2024-01-01-0000_My_Site_abcdef012345-db.gz', 'backup', 'db', '' ),
			'encrypted database' => array( 'backup_2024-01-01-0000_site_abcdef012345-db2.sql.zst.crypt', 'backup', 'db', '' ),
			'split archive'      => array( 'backup_2024-01-01-0000_site_abcdef012345-plugins3.tar.zst', 'backup', 'plugins', '' ),
			'encrypted archive'  => array( 'backup_2024-01-01-0000_site_abcdef012345-uploads.zip.crypt', 'backup', 'uploads', '' ),
			'foreign archive'    => array( 'other_backup_1700000000.tar.gz', 'foreign', '', 'Other Tool' ),
		);
	}

	public static function refused(): array {
		return array(
			'finding example'         => array( 'log.abcdef012345.txt.php' ),
			'log with another suffix' => array( 'log.abcdef012345.txt.phtml' ),
			'log with newline'        => array( "log.abcdef012345.txt\n" ),
			'archive then PHP'        => array( 'backup_2024-01-01-0000_site_abcdef012345-db.gz.php' ),
			'archive without suffix'  => array( 'backup_2024-01-01-0000_site_abcdef012345-db' ),
			'inner PHP part'          => array( 'backup_2024-01-01-0000_x.php.y_abcdef012345-db.gz' ),
			'inner PHAR part'         => array( 'backup_2024-01-01-0000_x.phar.y_abcdef012345-plugins.zip' ),
			'plain PHP'               => array( 'shell.php' ),
			'sub-folder'              => array( 'sub/backup_2024-01-01-0000_site_abcdef012345-db.gz' ),
			'traversal'               => array( '../backup_2024-01-01-0000_site_abcdef012345-db.gz' ),
			'NUL'                     => array( "backup_2024-01-01-0000_site_abcdef012345-db.gz\0.php" ),
			'foreign PHP'             => array( 'other_backup_1700000000.php' ),
			'foreign inner PHP'       => array( 'other_backup_1700000000.php.zip' ),
			'foreign without suffix'  => array( 'other_backup_1700000000.' ),
			'unrecognised archive'    => array( 'something.zip' ),
			'not a string'            => array( array( 'log.abcdef012345.txt' ) ),
			'missing'                 => array( null ),
			'htaccess'                => array( '.htaccess' ),
			'user.ini after a log'    => array( 'log.abcdef012345.txt.user.ini' ),
		);
	}

	#[DataProvider( 'accepted' )]
	public function test_upload_names_are_accepted( string $name, string $kind, string $entity, string $desc ): void {
		$this->assertSame(
			array(
				'kind'   => $kind,
				'entity' => $entity,
				'desc'   => $desc,
			),
			BackupPlus_Manipulation_Functions::upload_name( $name, self::FOREIGN )
		);
	}

	#[DataProvider( 'refused' )]
	public function test_upload_names_are_refused( $name ): void {
		$this->assertFalse( BackupPlus_Manipulation_Functions::upload_name( $name, self::FOREIGN ) );
	}

	public function test_foreign_archives_need_an_accept_entry(): void {
		$this->assertFalse( BackupPlus_Manipulation_Functions::upload_name( 'other_backup_1700000000.tar.gz' ) );
		$this->assertFalse( BackupPlus_Manipulation_Functions::upload_name( 'other_backup_1700000000.tar.gz', 'not an array' ) );
	}
}
