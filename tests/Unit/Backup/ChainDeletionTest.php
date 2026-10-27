<?php
/** Deleting recovery points from this server and from remote storage as separate actions. */

namespace BackupPlus\Tests\Unit\Backup;

use BackupPlus\Tests\Support\PitrTestCase;
use BackupPlus_Chain_Downloads;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class ChainDeletionTest extends PitrTestCase {

	protected function setUp(): void {
		parent::setUp();
		eval(
			'function __( $text, $domain = null ) { return $text; }
			function get_date_from_gmt( $date, $format ) { return gmdate( $format, strtotime( $date . " UTC" ) ); }
			function backupplus_try_include_file( $file, $mode ) {}
			class BackupPlus_Encryption { public static function is_file_encrypted( $file ) { return false; } }
			class BackupPlus_Manipulation_Functions { public static function convert_numeric_size_to_text( $size ) { return $size . " B"; } }
			class BackupPlus_Backup_Deletion { public static $protected = false; public static function protected_set( $timestamp, $backup ) { return self::$protected; } }
			class BackupPlus_Incremental_Backups { public static $discarded = array(); public static function discard_snapshots( $names ) { self::$discarded = array_merge( self::$discarded, $names ); } }
			class BackupPlus_Backup_History {
				public static $history = array();
				public static function get_history( $timestamp = 0 ) { return $timestamp ? self::$history[ $timestamp ] : self::$history; }
				public static function save_history( $history ) { self::$history = $history; }
			}
			class Chain_Test_Provider {
				public $deleted = array(); public $fail = false;
				public function supports_feature( $feature ) { return false; }
				public function delete( $file ) { if ( $this->fail ) { return false; } $this->deleted[] = $file; return true; }
			}
			class BackupPlus_Storage_Methods_Interface {
				public static $provider;
				public static function get_storage_objects_and_ids( $services ) { return array( "sftp" => array( "object" => self::$provider ) ); }
			}'
		);
		require_once dirname( __DIR__, 3 ) . '/includes/class-chain-downloads.php';
		\BackupPlus_Storage_Methods_Interface::$provider = new \Chain_Test_Provider();
		$directory                                       = $this->directory;
		$GLOBALS['backupplus']                           = new class( $directory ) {
			public $services = array( 'none' );
			public function __construct( private string $directory ) {}
			public function get_canonical_service_list( $services ) {
				return $this->services; }
			public function backups_dir_location() {
				return $this->directory; }
			public function log( $message ) {}
		};
	}

	/** Three archives, the second and third being increments, each also stored on this server. */
	private function chain( array $services ): array {
		$GLOBALS['backupplus']->services = $services;
		$names                           = array();
		$sets                            = array();
		foreach ( array( 0, 1, 2 ) as $position ) {
			$names[ $position ] = 'plugins' . ( $position ? $position + 1 : '' ) . '.tar.zst';
			file_put_contents( $this->directory . '/' . $names[ $position ], 'archive' );
			$sets[ 1793000000 + $position * 60 ] = array( 'plugins' => array( $position => $names[ $position ] ) );
		}
		\BackupPlus_Backup_History::$history = array(
			1793000000 => array(
				'plugins'          => $names,
				'plugins-size'     => 7,
				'plugins1-size'    => 7,
				'plugins2-size'    => 7,
				'service'          => $services,
				'incremental'      => array(
					'complete'  => true,
					'snapshots' => array( 'plugins' => 'incremental_abcdef012345_plugins.php' ),
				),
				'incremental_sets' => $sets,
			),
		);
		return $names;
	}

	private function row(): array {
		return \BackupPlus_Backup_History::$history[1793000000];
	}

	public function test_without_remote_storage_a_deleted_point_takes_every_later_point_with_it(): void {
		$names  = $this->chain( array( 'none' ) );
		$result = BackupPlus_Chain_Downloads::delete( 1793000000, 'plugins', '1', 'local' );
		$this->assertSame( array( 1, 2 ), $result['removed'] );
		$this->assertSame( 2, $result['deleted'] );
		$this->assertFileExists( $this->directory . '/' . $names[0] );
		$this->assertFileDoesNotExist( $this->directory . '/' . $names[1] );
		$this->assertFileDoesNotExist( $this->directory . '/' . $names[2] );
		$this->assertSame( array( $names[0] ), array_values( $this->row()['plugins'] ) );
		$this->assertArrayNotHasKey( 'plugins2-size', $this->row() );
		$this->assertArrayNotHasKey( 'plugins', $this->row()['incremental']['snapshots'] );
		$this->assertSame( array( 'incremental_abcdef012345_plugins.php' ), \BackupPlus_Incremental_Backups::$discarded );
	}

	public function test_deleting_from_the_server_keeps_remote_copies_and_the_history(): void {
		$names  = $this->chain( array( 'sftp' ) );
		$result = BackupPlus_Chain_Downloads::delete( 1793000000, 'plugins', '1', 'local' );
		$this->assertSame( array(), $result['removed'] );
		$this->assertSame( 1, $result['deleted'] );
		$this->assertFileDoesNotExist( $this->directory . '/' . $names[1] );
		$this->assertFileExists( $this->directory . '/' . $names[2] );
		$this->assertSame( $names, $this->row()['plugins'] );
		$this->assertSame( array(), \BackupPlus_Storage_Methods_Interface::$provider->deleted );
	}

	public function test_deleting_from_remote_storage_takes_later_points_there_and_keeps_local_copies(): void {
		$names  = $this->chain( array( 'sftp' ) );
		$result = BackupPlus_Chain_Downloads::delete( 1793000000, 'plugins', '1', 'remote' );
		$this->assertSame( array( $names[1], $names[2] ), \BackupPlus_Storage_Methods_Interface::$provider->deleted );
		$this->assertSame( 2, $result['remote_deleted'] );
		$this->assertSame( array(), $result['removed'] );
		$this->assertSame( 0, $result['deleted'] );
		$this->assertFileExists( $this->directory . '/' . $names[1] );
		$this->assertSame( $names, $this->row()['plugins'] );
		$this->assertSame(
			array(
				$names[1] => true,
				$names[2] => true,
			),
			$this->row()['storage_deleted_files']['sftp:']
		);
	}

	public function test_a_point_left_without_any_copy_removes_the_points_after_it_everywhere(): void {
		$names = $this->chain( array( 'sftp' ) );
		BackupPlus_Chain_Downloads::delete( 1793000000, 'plugins', '1', 'remote' );
		$result = BackupPlus_Chain_Downloads::delete( 1793000000, 'plugins', '1', 'local' );
		$this->assertSame( array( 1, 2 ), $result['removed'] );
		$this->assertFileDoesNotExist( $this->directory . '/' . $names[2] );
		$this->assertSame( array( $names[0] ), array_values( $this->row()['plugins'] ) );
		$this->assertArrayNotHasKey( $names[1], $this->row()['storage_deleted_files']['sftp:'] );
	}

	public function test_the_dry_run_reports_what_each_action_involves_without_deleting(): void {
		$names = $this->chain( array( 'sftp' ) );
		$this->assertSame(
			array(
				'local'  => 1,
				'remote' => 0,
				'lost'   => 0,
			),
			BackupPlus_Chain_Downloads::delete( 1793000000, 'plugins', '1', 'local', true )
		);
		$this->assertSame(
			array(
				'local'  => 0,
				'remote' => 2,
				'lost'   => 0,
			),
			BackupPlus_Chain_Downloads::delete( 1793000000, 'plugins', '1', 'remote', true )
		);
		$this->assertFileExists( $this->directory . '/' . $names[1] );
		$this->assertSame( array(), \BackupPlus_Storage_Methods_Interface::$provider->deleted );
	}

	public function test_a_failed_remote_deletion_stops_and_keeps_what_was_recorded(): void {
		$this->chain( array( 'sftp' ) );
		\BackupPlus_Storage_Methods_Interface::$provider->fail = true;
		$error = $this->assertRejected(
			function () {
				BackupPlus_Chain_Downloads::delete( 1793000000, 'plugins', '1', 'remote' );
			},
			'The deletion must be refused'
		);
		$this->assertStringContainsString( 'could not be deleted from remote storage', $error->getMessage() );
		$this->assertArrayNotHasKey( 'storage_deleted_files', $this->row() );
		$this->assertCount( 3, $this->row()['plugins'] );
	}

	public function test_remote_deletion_needs_remote_storage_and_an_idle_backup(): void {
		$this->chain( array( 'none' ) );
		$error = $this->assertRejected(
			function () {
				BackupPlus_Chain_Downloads::delete( 1793000000, 'plugins', '1', 'remote' );
			},
			'The deletion must be refused'
		);
		$this->assertStringContainsString( 'not in remote storage', $error->getMessage() );
		\BackupPlus_Backup_Deletion::$protected = true;
		$error                                  = $this->assertRejected(
			function () {
				BackupPlus_Chain_Downloads::delete( 1793000000, 'plugins', '1', 'local' );
			},
			'The deletion must be refused'
		);
		$this->assertStringContainsString( 'active', $error->getMessage() );
		$this->assertCount( 3, $this->row()['plugins'] );
	}
}
