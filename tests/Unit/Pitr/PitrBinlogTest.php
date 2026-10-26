<?php
/** Binary log inspection, scoped replay copies, anchors and statement classification without a database. */

namespace BackupPlus\Tests\Unit\Pitr;

use BackupPlus\Tests\Support\PitrTestCase;
use BackupPlus_PITR_Binlog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class PitrBinlogTest extends PitrTestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 3 ) . '/includes/class-pitr-process.php';
		require_once dirname( __DIR__, 3 ) . '/includes/class-pitr-manifest.php';
		require_once dirname( __DIR__, 3 ) . '/includes/class-pitr-binlog.php';
	}

	/** A log with an account change and a neighboring schema's transaction, as a shared server captures it. */
	private function shared_log( string $format ): string {
		$position    = 4 + strlen( $format );
		$shared      = "\xfebin" . $format;
		$shared     .= self::event( 162, str_repeat( "\x00", 12 ) . "\x01", $position );
		$shared     .= self::event( 2, self::query_body( "CREATE USER 'outside'@'%' IDENTIFIED BY 'opaque'" ), $position );
		$shared     .= self::event( 162, str_repeat( "\x00", 12 ) . "\x01", $position );
		$shared     .= self::event( 2, self::query_body( "CREATE PROCEDURE unrelated() SELECT 'wordpress.example'", 'neighbor' ), $position );
		$shared     .= self::event( 4, pack( 'VV', 4, 0 ) . 'binlog.000002', $position );
		$shared_path = $this->directory . '/shared.bin';
		file_put_contents( $shared_path, $shared );
		return $shared_path;
	}

	public function test_commit_boundaries_and_rotation_are_read(): void {
		$log        = $this->standard_log();
		$inspection = BackupPlus_PITR_Binlog::inspect( $log['path'], $log['start'], 'wordpress', 'mariadb' );
		$this->assertSame(
			array(
				array(
					'position'  => $log['committed'],
					'timestamp' => 100,
				),
			),
			$inspection['boundaries'],
			'Commit boundary differs from XID end position.'
		);
		$this->assertSame(
			array(
				'file'     => 'binlog.000002',
				'position' => 4,
			),
			$inspection['next'],
			'Rotation coordinates were lost.'
		);
	}

	public function test_replay_cannot_start_inside_an_event(): void {
		$log = $this->standard_log();
		$this->assertRejected(
			function () use ( $log ) {
				BackupPlus_PITR_Binlog::inspect( $log['path'], $log['start'] + 1, 'wordpress', 'mariadb' );
			},
			'Mid-event replay accepted.'
		);
	}

	public function test_row_events_of_another_schema_are_rejected(): void {
		$log = $this->standard_log();
		$this->assertRejected(
			function () use ( $log ) {
				BackupPlus_PITR_Binlog::inspect( $log['path'], $log['start'], 'other', 'mariadb' );
			},
			'Cross-schema row event accepted.'
		);
	}

	public function test_shared_capture_keeps_control_and_neighboring_transaction_boundaries(): void {
		$log           = $this->standard_log();
		$shared_path   = $this->shared_log( $log['format'] );
		$shared_result = BackupPlus_PITR_Binlog::inspect( $shared_path, $log['start'], 'wordpress', 'mariadb', false, null, true );
		$this->assertCount( 2, $shared_result['boundaries'], 'Shared capture lost control or neighboring transaction boundaries.' );
	}

	public function test_the_native_backend_rejects_a_shared_server_scope(): void {
		$log         = $this->standard_log();
		$shared_path = $this->shared_log( $log['format'] );
		$this->assertRejected(
			function () use ( $shared_path, $log ) {
				BackupPlus_PITR_Binlog::inspect( $shared_path, $log['start'], 'wordpress', 'mariadb' );
			},
			'The native backend accepted an shared-server scope.'
		);
	}

	public function test_scoped_replay_copy_drops_account_administration_and_keeps_checksums(): void {
		$log         = $this->standard_log();
		$shared_path = $this->shared_log( $log['format'] );
		$shared      = file_get_contents( $shared_path );
		$copy_path   = $this->directory . '/replay-copy.bin';
		BackupPlus_PITR_Binlog::scoped_replay_copy( $shared_path, $copy_path, 'wordpress' );
		$copy = file_get_contents( $copy_path );
		$this->assertSame( strlen( $shared ), strlen( $copy ), 'Account administration was replayed or source coordinates changed.' );
		$this->assertStringNotContainsString( 'CREATE USER', $copy, 'Account administration was replayed or source coordinates changed.' );
		$this->assertStringContainsString( 'DO 0', $copy, 'Account administration was replayed or source coordinates changed.' );
		for ( $event_offset = 4; $event_offset < strlen( $copy ); ) {
			$event_size  = unpack( 'V', substr( $copy, $event_offset + 9, 4 ) )[1];
			$event_bytes = substr( $copy, $event_offset, $event_size - 4 );
			$this->assertSame( strrev( hash( 'crc32b', $event_bytes, true ) ), substr( $copy, $event_offset + $event_size - 4, 4 ), 'Isolated account-event rewriting broke a native checksum.' );
			$event_offset += $event_size;
		}
	}

	public function test_a_multiline_event_activation_is_rejected_for_direct_source_replay(): void {
		$format         = $this->format_event();
		$event_position = 4 + strlen( $format );
		$event_log      = "\xfebin" . $format;
		$event_log     .= self::event( 162, str_repeat( "\x00", 12 ) . "\x01", $event_position );
		$event_log     .= self::event( 2, self::query_body( "ALTER\nEVENT\npitr_event ENABLE" ), $event_position );
		$event_path     = $this->directory . '/event-change.bin';
		file_put_contents( $event_path, $event_log );
		$this->assertRejected(
			function () use ( $event_path ) {
				BackupPlus_PITR_Binlog::scoped_replay_copy( $event_path, $this->directory . '/event-copy.bin', 'wordpress', true );
			},
			'A multiline event activation was accepted for direct source replay.'
		);
	}

	public function test_a_cross_schema_transaction_is_not_split_into_tenant_only_history(): void {
		$log            = $this->standard_log();
		$mixed_position = 4 + strlen( $log['format'] );
		$mixed          = "\xfebin" . $log['format'] . self::event( 162, str_repeat( "\x00", 13 ), $mixed_position );
		foreach ( array( 'wordpress', 'neighbor' ) as $scope ) {
			$mixed .= self::event( 19, str_repeat( "\x00", 8 ) . chr( strlen( $scope ) ) . $scope . "\x00", $mixed_position );
			$mixed .= self::event( 30, str_repeat( "\x00", 12 ), $mixed_position );
		}
		$mixed     .= self::event( 16, str_repeat( "\x00", 8 ), $mixed_position );
		$mixed     .= self::event( 4, pack( 'VV', 4, 0 ) . 'binlog.000002', $mixed_position );
		$mixed_path = $this->directory . '/mixed.bin';
		file_put_contents( $mixed_path, $mixed );
		$this->assertRejected(
			function () use ( $mixed_path, $log ) {
				BackupPlus_PITR_Binlog::inspect( $mixed_path, $log['start'], 'wordpress', 'mariadb', false, null, true );
			},
			'A cross-schema transaction was silently split into a tenant-only history.'
		);
	}

	public function test_truncated_logs_are_rejected(): void {
		$log = $this->standard_log();
		file_put_contents( $log['path'], substr( $log['data'], 0, -1 ) );
		$this->assertRejected(
			function () use ( $log ) {
				BackupPlus_PITR_Binlog::inspect( $log['path'], $log['start'], 'wordpress', 'mariadb' );
			},
			'Truncation accepted.'
		);
	}

	public function test_corrupt_checksums_are_rejected(): void {
		$log          = $this->standard_log();
		$corrupt      = $log['data'];
		$corrupt[120] = chr( ord( $corrupt[120] ) ^ 1 );
		file_put_contents( $log['path'], $corrupt );
		$this->assertRejected(
			function () use ( $log ) {
				BackupPlus_PITR_Binlog::inspect( $log['path'], $log['start'], 'wordpress', 'mariadb' );
			},
			'Corrupt checksum accepted.'
		);
	}

	public function test_the_source_anchor_ignores_mutable_format_flags_and_checksum(): void {
		$log                   = $this->standard_log();
		$start                 = $log['start'];
		$hash                  = BackupPlus_PITR_Binlog::prefix_hash( $log['path'], $start );
		$changed               = $log['data'];
		$changed[21]           = "\x01";
		$changed[ $start - 1 ] = "\xff";
		file_put_contents( $log['path'], $changed );
		$this->assertSame( $hash, BackupPlus_PITR_Binlog::prefix_hash( $log['path'], $start ), 'Mutable FDE flags/checksum changed the source anchor.' );
	}

	public static function safe_ddl(): iterable {
		yield 'create table' => array( 'CREATE TABLE `wordpress`.`new` (id int) ENGINE=InnoDB' );
		yield 'alter table with a foreign name in a default' => array( 'ALTER TABLE `new` ADD `note` varchar(30) DEFAULT \'other.table\'' );
		yield 'drop table' => array( 'DROP TABLE `new`' );
	}

	#[DataProvider( 'safe_ddl' )]
	public function test_safe_ddl_is_accepted( string $sql ): void {
		$this->assertSame( 'DDL', BackupPlus_PITR_Binlog::query( self::query_body( $sql ), 'wordpress' ), 'Safe DDL rejected.' );
	}

	public static function unsafe_queries(): iterable {
		foreach ( array( 'CREATE TABLE `other`.`new` (id int)', 'CREATE TABLE new AS SELECT * FROM old', 'INSERT INTO new VALUES (1)', 'CREATE TABLE new (id int) ENGINE=MyISAM', 'CREATE TABLE new (id int) DATA DIRECTORY=\'/tmp\'', '/*!50000 DROP DATABASE wordpress */' ) as $sql ) {
			yield $sql => array( $sql );
		}
	}

	#[DataProvider( 'unsafe_queries' )]
	public function test_unsafe_or_unsupported_queries_are_rejected( string $sql ): void {
		$this->assertRejected(
			function () use ( $sql ) {
				BackupPlus_PITR_Binlog::query( self::query_body( $sql ), 'wordpress' );
			},
			'Unsafe or unsupported query accepted: ' . $sql
		);
	}
}
