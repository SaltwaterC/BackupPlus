<?php
/** Binary log coordinates, chain continuity and dump coordinates without a database. */

namespace BackupPlus\Tests\Unit\Pitr;

use BackupPlus\Tests\Support\PitrTestCase;
use BackupPlus_PITR_Binlog;
use BackupPlus_PITR_Manifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class PitrManifestTest extends PitrTestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 3 ) . '/includes/class-pitr-process.php';
		require_once dirname( __DIR__, 3 ) . '/includes/class-pitr-manifest.php';
		require_once dirname( __DIR__, 3 ) . '/includes/class-pitr-binlog.php';
	}

	public static function unsafe_coordinates(): iterable {
		yield 'path traversal' => array(
			array(
				'file'     => '../binlog.000001',
				'position' => 4,
			),
		);
		yield 'string position' => array(
			array(
				'file'     => 'binlog.000001',
				'position' => '4',
			),
		);
		yield 'position inside the log header' => array(
			array(
				'file'     => 'binlog.000001',
				'position' => 3,
			),
		);
		yield 'missing position' => array( array( 'file' => 'binlog.000001' ) );
	}

	#[DataProvider( 'unsafe_coordinates' )]
	public function test_unsafe_coordinates_are_rejected( array $bad ): void {
		$this->assertRejected(
			function () use ( $bad ) {
				BackupPlus_PITR_Manifest::coordinate( $bad );
			},
			'Unsafe coordinate accepted.'
		);
	}

	/**
	 * A valid portable chain of a full backup and one binary log increment.
	 *
	 * @return array{0: array, 1: array} The entries and the inspection of the increment's log.
	 */
	private function chain_entries(): array {
		$log        = $this->standard_log();
		$start      = $log['start'];
		$inspection = BackupPlus_PITR_Binlog::inspect( $log['path'], $start, 'wordpress', 'mariadb' );

		$full_id          = str_repeat( 'a', 32 );
		$chain_id         = str_repeat( 'b', 32 );
		$increment_id     = str_repeat( 'c', 32 );
		$full_file        = 'backup_pitr_' . $chain_id . '_' . $full_id . '_full.sql.gz';
		$log_file         = 'backup_pitr_' . $chain_id . '_' . $increment_id . '_log0.bin.gz';
		$source           = array(
			'engine'         => 'mariadb',
			'server_version' => '11.4.0-MariaDB',
			'identity'       => str_repeat( 'd', 64 ),
			'log_epoch'      => str_repeat( 'e', 32 ),
		);
		$coordinate       = array(
			'file'     => 'binlog.000001',
			'position' => $start,
		);
		$anchor           = array(
			'coordinate' => $coordinate,
			'sha256'     => str_repeat( 'f', 64 ),
		);
		$artifact         = array(
			'name'         => $full_file,
			'sha256'       => str_repeat( '1', 64 ),
			'plain_sha256' => str_repeat( '2', 64 ),
			'size'         => 100,
		);
		$full             = array(
			'manifest_version'    => 1,
			'kind'                => 'database-full',
			'backup_id'           => $full_id,
			'chain_id'            => $chain_id,
			'source'              => $source,
			'database'            => 'wordpress',
			'created_at'          => 1,
			'snapshot_coordinate' => $coordinate,
			'anchor'              => $anchor,
			'artifacts'           => array( $artifact ),
		);
		$artifact['name'] = $log_file;
		$increment        = array(
			'manifest_version'         => 1,
			'kind'                     => 'database-binlog',
			'backup_id'                => $increment_id,
			'chain_id'                 => $chain_id,
			'source'                   => $source,
			'database'                 => 'wordpress',
			'created_at'               => 2,
			'baseline_id'              => $full_id,
			'previous_backup_id'       => $full_id,
			'previous_manifest_sha256' => str_repeat( '3', 64 ),
			'start_coordinate'         => $coordinate,
			'end_coordinate'           => $inspection['next'],
			'anchor'                   => $anchor,
			'artifacts'                => array( $artifact ),
			'segments'                 => array(
				array_merge(
					array(
						'start'    => $coordinate,
						'artifact' => $log_file,
					),
					$inspection
				),
			),
		);
		return array(
			array(
				array(
					'manifest' => $full,
					'sha256'   => str_repeat( '3', 64 ),
				),
				array(
					'manifest' => $increment,
					'sha256'   => str_repeat( '4', 64 ),
				),
			),
			$inspection,
		);
	}

	public function test_a_valid_portable_chain_is_continuous(): void {
		list( $entries, $inspection ) = $this->chain_entries();
		$this->assertSame( $inspection['next'], BackupPlus_PITR_Manifest::chain( $entries ), 'Valid portable chain failed continuity.' );
	}

	public static function broken_chains(): iterable {
		yield 'coordinate' => array( 'coordinate' );
		yield 'source' => array( 'source' );
		yield 'hash' => array( 'hash' );
		yield 'baseline' => array( 'baseline' );
	}

	#[DataProvider( 'broken_chains' )]
	public function test_a_chain_with_a_mismatch_is_rejected( string $case ): void {
		list( $bad ) = $this->chain_entries();
		if ( 'coordinate' === $case ) {
			++$bad[1]['manifest']['start_coordinate']['position'];
		}
		if ( 'source' === $case ) {
			$bad[1]['manifest']['source']['log_epoch'] = str_repeat( '9', 32 );
		}
		if ( 'hash' === $case ) {
			$bad[1]['manifest']['previous_manifest_sha256'] = str_repeat( '9', 64 );
		}
		if ( 'baseline' === $case ) {
			$bad[1]['manifest']['baseline_id'] = str_repeat( '9', 32 );
		}
		$this->assertRejected(
			function () use ( $bad ) {
				BackupPlus_PITR_Manifest::chain( $bad );
			},
			'Chain with mismatched ' . $case . ' accepted.'
		);
	}

	public function test_a_mariadb_dump_coordinate_is_parsed(): void {
		$sql = $this->directory . '/full.sql';
		file_put_contents( $sql, "-- CHANGE MASTER TO MASTER_LOG_FILE='binlog.000001', MASTER_LOG_POS=789;\n" );
		$this->assertSame( 789, BackupPlus_PITR_Manifest::snapshot_coordinate( $sql )['position'], 'MariaDB dump coordinate not parsed.' );
	}

	public function test_a_mysql_dump_coordinate_is_parsed(): void {
		$sql = $this->directory . '/full.sql';
		file_put_contents( $sql, "-- CHANGE REPLICATION SOURCE TO SOURCE_LOG_FILE='binlog.000001', SOURCE_LOG_POS=987;\n" );
		$this->assertSame( 987, BackupPlus_PITR_Manifest::snapshot_coordinate( $sql )['position'], 'MySQL dump coordinate not parsed.' );
	}

	public function test_executable_replication_setup_is_not_a_baseline(): void {
		$sql = $this->directory . '/full.sql';
		file_put_contents( $sql, "CHANGE MASTER TO MASTER_LOG_FILE='binlog.000001', MASTER_LOG_POS=789;\n" );
		$this->assertRejected(
			function () use ( $sql ) {
				BackupPlus_PITR_Manifest::snapshot_coordinate( $sql );
			},
			'Executable replication setup accepted as a baseline.'
		);
	}
}
