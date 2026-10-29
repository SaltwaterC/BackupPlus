<?php
/** Recovery point listing, paging and the single archive that replaces one download per file. */

namespace BackupPlus\Tests\Unit\Backup;

use BackupPlus\Tests\Support\PitrTestCase;
use BackupPlus_Chain_Downloads;
use BackupPlus_Stored_Zip;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use ZipArchive;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class ChainDownloadsTest extends PitrTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( '__' ) ) {
			eval( 'function __( $text, $domain = null ) { return $text; } function get_date_from_gmt( $date, $format ) { return gmdate( $format, strtotime( $date . " UTC" ) ); }' );
		}
		eval( 'class BackupPlus_Encryption { public static function is_file_encrypted( $file ) { return preg_match( "/\\.crypt$/i", $file ); } }' );
		eval( 'class BackupPlus_Manipulation_Functions { public static function convert_numeric_size_to_text( $size ) { return $size . " B"; } }' );
		require_once dirname( __DIR__, 3 ) . '/includes/class-chain-downloads.php';
		require_once dirname( __DIR__, 3 ) . '/includes/class-stored-zip.php';
	}

	/** An older chain of one full backup and many increments, each stored as three files. */
	private function chain( int $points ): array {
		$downloads = array();
		$stored    = array();
		for ( $increment = 0; $increment < $points; $increment++ ) {
			$timestamp = 1793000000 + $increment * 60;
			$stored[]  = array(
				'timestamp' => $timestamp,
				'increment' => $increment,
			);
			foreach ( array( 'log', 'manifest', 'locator' ) as $kind ) {
				$downloads[] = array(
					'name'      => 'backup_pitr_c_' . $increment . '_' . $kind,
					'size'      => 10,
					'increment' => $increment,
					'timestamp' => $timestamp,
				);
			}
		}
		return array(
			'pitr' => array(
				'points'    => $stored,
				'downloads' => $downloads,
			),
		);
	}

	public function test_points_group_the_files_of_each_recovery_point_newest_first(): void {
		$points = BackupPlus_Chain_Downloads::points( $this->chain( 300 ), 'db' );
		$this->assertCount( 300, $points );
		$this->assertSame( 299, $points[0]['id'] );
		$this->assertStringEndsWith( '(latest)', $points[0]['label'] );
		$this->assertSame( array( 897, 898, 899 ), $points[0]['files'] );
		$this->assertSame( 30, $points[0]['size'] );
		$this->assertStringContainsString( 'full database backup', end( $points )['label'] );
	}

	public function test_a_page_lists_only_its_rows_but_every_matching_id(): void {
		$page = BackupPlus_Chain_Downloads::page( $this->chain( 300 ), 'db', '', 50, 50 );
		$this->assertSame( 300, $page['total'] );
		$this->assertCount( 300, $page['ids'] );
		$this->assertCount( 50, $page['rows'] );
		$this->assertSame( 249, $page['rows'][0]['id'] );
		$this->assertSame( 3, $page['rows'][0]['files'] );
	}

	public function test_new_pitr_points_list_one_file_and_keep_the_encryption_indicator(): void {
		$chain                      = $this->chain( 2 );
		$chain['pitr']['downloads'] = array(
			array(
				'name'      => 'full_point.tar',
				'size'      => 120,
				'increment' => 0,
				'encrypted' => true,
			),
			array(
				'name'      => 'increment_point.tar',
				'size'      => 80,
				'increment' => 1,
				'encrypted' => true,
			),
		);
		$page                       = BackupPlus_Chain_Downloads::page( $chain, 'db', '', 0, 50 );
		$this->assertSame( array( 1, 1 ), array_column( $page['rows'], 'files' ) );
		$this->assertTrue( $page['encrypted'] );
		$this->assertSame( array( '80 B', '120 B' ), array_column( $page['rows'], 'size' ) );
	}

	public function test_one_pitr_point_downloads_its_archive_directly_and_detects_corruption(): void {
		eval( 'class BackupPlus_Backup_History { public static $history; public static function get_history() { return self::$history; } }' );
		$GLOBALS['backupplus'] = new class( $this->directory ) {
			public function __construct( private string $directory ) {}
			public function backups_dir_location() {
				return $this->directory; }
			public function get_canonical_service_list( $services ) {
				return array(); }
		};
		$chain                 = $this->chain( 2 );
		$prefix                = 'backup_pitr_' . str_repeat( 'a', 32 ) . '_';
		$first                 = $prefix . str_repeat( 'b', 32 ) . '_point.tar';
		$second                = $prefix . str_repeat( 'c', 32 ) . '_point.tar';
		file_put_contents( $this->directory . '/' . $first, 'first' );
		file_put_contents( $this->directory . '/' . $second, 'second' );
		$chain['pitr']['downloads']          = array(
			array(
				'name'      => $first,
				'size'      => 5,
				'sha256'    => hash( 'sha256', 'first' ),
				'increment' => 0,
			),
			array(
				'name'      => $second,
				'size'      => 6,
				'sha256'    => hash( 'sha256', 'second' ),
				'increment' => 1,
			),
		);
		\BackupPlus_Backup_History::$history = array( 123 => $chain );
		$one                                 = BackupPlus_Chain_Downloads::prepare( 123, 'db', array( 0 ) );
		$this->assertSame(
			array(
				'files' => 1,
				'size'  => '5 B',
				'name'  => $first,
			),
			$one
		);
		$both = BackupPlus_Chain_Downloads::prepare( 123, 'db', array( 0, 1 ) );
		$this->assertSame( 2, $both['files'] );
		$this->assertStringEndsWith( '.zip', $both['name'] );
		$script  = $this->directory . '/send.php';
		$source  = <<<'PHP'
<?php
define( 'ABSPATH', __DIR__ );
function __( $text ) { return $text; }
function get_date_from_gmt( $date, $format ) { return gmdate( $format, strtotime( $date . ' UTC' ) ); }
function nocache_headers() {}
class BackupPlus_Backup_History { public static $history; public static function get_history() { return self::$history; } }
class BackupPlus_Encryption { public static function is_file_encrypted( $name ) { return false; } }
class DownloadFixture {
  public function backups_dir_location() { return __DIR__; }
  public function get_canonical_service_list( $services ) { return array(); }
}
PHP;
		$source .= "\n" . '$GLOBALS[\'backupplus\'] = new DownloadFixture();';
		$source .= "\n" . 'BackupPlus_Backup_History::$history = ' . var_export( array( 123 => $chain ), true ) . ';';
		$source .= "\n" . 'require ' . var_export( dirname( __DIR__, 3 ) . '/includes/class-chain-downloads.php', true ) . ';';
		$source .= "\n" . 'BackupPlus_Chain_Downloads::send(123, \'db\', array(0));';
		file_put_contents( $script, $source );
		$process = proc_open(
			array( PHP_BINARY, $script ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		$this->assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] );
		$error  = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $error );
		$this->assertSame( 'first', $output, 'A single point must stream the tar itself.' );
		file_put_contents( $this->directory . '/' . $first, 'wrong' );
		$this->assertRejected(
			function () {
				BackupPlus_Chain_Downloads::prepare( 123, 'db', array( 0 ) );
			},
			'A modified point archive must not be downloaded.'
		);
	}

	public function test_the_filter_matches_local_dates_and_labels(): void {
		$chain = $this->chain( 300 );
		$date  = gmdate( 'Y-m-d H:i', 1793000000 + 120 * 60 );
		$page  = BackupPlus_Chain_Downloads::page( $chain, 'db', $date, 0, 50 );
		$this->assertSame( array( 120 ), $page['ids'] );
		$this->assertSame( array( 120 ), array_column( $page['rows'], 'id' ) );
		$this->assertCount( 300, $page['all'] );
		$this->assertSame( array( 299 ), BackupPlus_Chain_Downloads::page( $chain, 'db', 'INCREMENTAL 299', 0, 50 )['ids'] );
		$this->assertSame( 0, BackupPlus_Chain_Downloads::page( $chain, 'db', 'no such point', 0, 50 )['total'] );
	}

	/** A files backup whose entity has one archive per increment, the newest increment also split in two. */
	private function files_chain( bool $encrypted ): array {
		$suffix = $encrypted ? '.tar.zst.crypt' : '.tar.zst';
		$names  = array();
		$sets   = array();
		foreach ( array( 0, 1, 2 ) as $position ) {
			$names[ $position ]                  = 'plugins' . ( $position ? $position + 1 : '' ) . $suffix;
			$sets[ 1793000000 + $position * 60 ] = array( 'plugins' => array( $names[ $position ] ) );
		}
		return array(
			'plugins'          => $names,
			'plugins-size'     => 100,
			'plugins1-size'    => 10,
			'plugins2-size'    => 20,
			'incremental'      => array( 'complete' => true ),
			'incremental_sets' => $sets,
		);
	}

	public function test_file_entities_are_listed_per_increment_and_only_plain_archives_can_be_browsed(): void {
		$plain = BackupPlus_Chain_Downloads::page( $this->files_chain( false ), 'plugins', '', 0, 50 );
		$this->assertSame( array( 2, 1, 0 ), $plain['ids'] );
		$this->assertSame( array( 2, 1, 0 ), array_column( $plain['rows'], 'browse' ) );
		$this->assertStringContainsString( 'full backup', $plain['rows'][2]['label'] );
		$this->assertStringContainsString( 'incremental backup 2 (latest)', $plain['rows'][0]['label'] );
		$this->assertFalse( $plain['encrypted'] );

		$locked = BackupPlus_Chain_Downloads::page( $this->files_chain( true ), 'plugins', '', 0, 50 );
		$this->assertSame( array( null, null, null ), array_column( $locked['rows'], 'browse' ) );
		$this->assertTrue( $locked['encrypted'] );
	}

	public function test_a_single_archive_or_an_unchained_entity_keeps_the_plain_downloader(): void {
		$chain = $this->files_chain( false );
		$this->assertTrue( BackupPlus_Chain_Downloads::supported( $chain, 'plugins' ) );
		$this->assertFalse( BackupPlus_Chain_Downloads::supported( $chain, 'themes' ) );
		$this->assertFalse( BackupPlus_Chain_Downloads::supported( $chain, 'plugins-size' ) );
		$chain['plugins'] = array( 'plugins.tar.zst' );
		$this->assertFalse( BackupPlus_Chain_Downloads::supported( $chain, 'plugins' ) );
		$this->assertFalse( BackupPlus_Chain_Downloads::supported( array( 'db' => 'db.sql.zst' ), 'db' ) );
	}

	public function test_a_history_row_without_recovery_points_lists_nothing(): void {
		$this->assertSame( array(), BackupPlus_Chain_Downloads::points( array(), 'db' ) );
	}

	public function test_the_archive_holds_every_file_intact_with_a_known_length(): void {
		$files = array();
		foreach ( array(
			'one.bin'     => "first\n",
			'two.json'    => '',
			'three.crypt' => str_repeat( 'x', 3000000 ),
		) as $name => $content ) {
			file_put_contents( $this->directory . '/' . $name, $content );
			$files[] = array(
				'name' => $name,
				'path' => $this->directory . '/' . $name,
			);
		}
		$zip    = new BackupPlus_Stored_Zip( $files );
		$target = $this->directory . '/out.zip';
		$out    = fopen( $target, 'wb' );
		$zip->write( $out );
		fclose( $out );
		$this->assertSame( $zip->length(), filesize( $target ) );
		if ( ! class_exists( ZipArchive::class ) ) {
			$this->markTestIncomplete( 'ZipArchive is needed to read the archive back.' );
		}
		$archive = new ZipArchive();
		$this->assertTrue( $archive->open( $target, ZipArchive::CHECKCONS ) );
		$this->assertSame( 3, $archive->numFiles );
		$this->assertSame( "first\n", $archive->getFromName( 'one.bin' ) );
		$this->assertSame( '', $archive->getFromName( 'two.json' ) );
		$this->assertSame( str_repeat( 'x', 3000000 ), $archive->getFromName( 'three.crypt' ) );
	}

	public function test_a_missing_file_is_rejected_before_anything_is_sent(): void {
		$this->assertRejected(
			function () {
				new BackupPlus_Stored_Zip(
					array(
						array(
							'name' => 'gone',
							'path' => $this->directory . '/gone',
						),
					)
				);
			},
			'A missing file was accepted.'
		);
	}
}
