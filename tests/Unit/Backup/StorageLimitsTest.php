<?php
namespace BackupPlus\Tests\Unit\Backup;

use BackupPlus_Storage_Limits as Limits;
use BackupPlus_Options as Options;
use BackupPlus_Backup_History as History;
use BackupPlus_Storage_Methods_Interface as Storage;
use BackupPlus_Retention_Cleanup as Cleanup;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class StorageLimitsTest extends TestCase {
	private string $directory;
	protected function setUp(): void {
		require dirname( __DIR__, 2 ) . '/Support/storage-limits-stubs.php';
		require dirname( __DIR__, 3 ) . '/includes/class-storage-limits.php';
		$this->directory = sys_get_temp_dir() . '/backupplus-limits-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->directory );
		$GLOBALS['backupplus']    = new class( $this->directory ) {
			public function __construct( public string $directory ) {}
			public function backups_dir_location() {
				return $this->directory; }
			public function get_backupable_file_entities() {
				return array(
					'plugins' => true,
					'uploads' => true,
				); }
		};
		$GLOBALS['storage_mails'] = array();
		Storage::$provider        = new \Storage_Limits_Test_Provider();
		Options::$options         = array(
			'admin_email'        => 'admin@example.test',
			'backupplus_service' => array( 'fake' ),
		);
	}
	protected function tearDown(): void {
		foreach ( glob( $this->directory . '/*' ) as $path ) { unlink( $path ); }
		rmdir( $this->directory );
	}
	public function test_destinations_are_named_for_people_not_by_their_internal_key(): void {
		$this->assertSame( 'Local storage', Limits::label( 'local', '' ) );
		$this->assertSame( 'fake (1)', Limits::label( 'fake', 'a' ), 'Unlabelled instances of one type are numbered' );
		$this->assertSame( 'fake (2)', Limits::label( 'fake', 'b' ) );
		$this->assertSame( 'fake (removed)', Limits::label( 'fake', 'gone' ), 'A limit of a removed destination says so' );
		Storage::$labels = array( 'instance_label' => 'Offsite NAS' );
		$this->assertSame( 'Offsite NAS (fake)', Limits::label( 'fake', 'a' ), 'A given name is kept and the type is added' );
		Storage::$labels = array( 'instance_label' => 'fake in the cellar' );
		$this->assertSame( 'fake in the cellar', Limits::label( 'fake', 'a' ), 'The type is not repeated when the name has it' );
	}

	private function set( string $name, string $entity = 'plugins', int $size = 60 ): array {
		return array(
			$entity                => array( $name ),
			$entity . '-size'      => $size,
			'native'               => true,
			'service'              => array( 'fake' ),
			'service_instance_ids' => array( 'fake' => array( 'a', 'b' ) ),
		); }
	private function fixture(): void {
		History::$history = array(
			100 => $this->set( 'old.zip' ),
			200 => $this->set( 'new.zip' ),
		);
		foreach ( array( 'old.zip', 'new.zip' ) as $name ) {
			file_put_contents( $this->directory . '/' . $name, str_repeat( 'a', 60 ) );
			foreach ( array( 'a', 'b' ) as $id ) { Storage::$provider->files[ $id ][ $name ] = array(
				'name' => $name,
				'size' => 60,
			); }
		}
	}
	public function test_form_conversion_and_export_bytes_are_idempotent(): void {
		$config = Limits::sanitize(
			array(
				'_unit' => 'GB',
				'local' => array(
					'__default' => array(
						'alert' => '1.25',
						'quota' => '0',
					),
				),
				'fake'  => array( 'a' => array( 'quota' => '-1' ) ),
			)
		);
		$this->assertSame( 1250000000, $config['local']['']['alert'] );
		$this->assertSame( 0, $config['local']['']['quota'] );
		$this->assertSame( $config, Limits::sanitize( $config ) );
	}
	public function test_boundaries_jump_rearming_and_daily_reminders(): void {
		$state = array();
		$now   = 1000000;
		foreach ( array( array( 79, 0 ), array( 80, 80 ), array( 80, 0 ), array( 100, 100 ), array( 101, 0 ), array( 79, 0 ), array( 80, 80 ) ) as list( $usage, $notice ) ) {
			$result = Limits::alert_transition( $state, $usage, 100, true, $now++ );
			$this->assertSame( $notice, $result['notify'] );
			$state = $result['state'];
		}
		$this->assertSame( 0, Limits::alert_transition( $state, 100, 100, true, $now )['notify'] );
		$this->assertSame( 100, Limits::alert_transition( $state, 100, 100, true, $now + DAY_IN_SECONDS )['notify'] );
		$this->assertSame( 100, Limits::alert_transition( array(), 150, 100, false, $now )['notify'] );
		$state = Limits::alert_transition( array(), 150, 100, false, $now )['state'];
		$this->assertSame( 0, Limits::alert_transition( $state, 150, 100, false, $now + DAY_IN_SECONDS )['notify'] );
	}
	public function test_every_source_keeps_its_newest_complete_chain(): void {
		$history                   = array(
			1 => $this->set( 'db.gz', 'db' ),
			2 => $this->set( 'extra.gz', 'db7' ),
			3 => $this->set( 'extra.zip', 'source-custom' ),
			4 => $this->set( 'old.zip' ),
			5 => $this->set( 'full.zip' ),
			6 => $this->set( 'incomplete.zip' ),
		);
		$history[6]['incremental'] = array( 'complete' => false );
		$inventory                 = array_fill_keys( array( 'db.gz', 'extra.gz', 'extra.zip', 'old.zip', 'full.zip', 'incomplete.zip' ), 60 );
		$sets                      = Limits::recovery_sets( $history, $inventory, 'local:' );
		foreach ( array( 1, 2, 3, 5, 6 ) as $time ) { $this->assertTrue( $sets[ $time ]['protected'] ); }
		$this->assertFalse( $sets[4]['protected'] );
		unset( $inventory['full.zip'] );
		$this->assertTrue( Limits::recovery_sets( $history, $inventory, 'local:' )[4]['protected'] );
	}
	public function test_quota_evicts_only_selected_destination_at_exact_boundary(): void {
		$this->fixture();
		Options::$options[ Limits::OPTION ] = array( 'fake' => array( 'a' => array( 'quota' => 120 ) ) );
		Limits::run();
		$this->assertSame( array( array( 'a', 'old.zip' ) ), Storage::$provider->calls );
		$this->assertFileExists( $this->directory . '/old.zip' );
		$this->assertArrayHasKey( 'old.zip', Storage::$provider->files['b'] );
		$this->assertCount( 2, History::$history );
		$this->assertSame( array( 'b' ), Limits::discovery( History::$history )[100]['service_instance_ids']['fake'] );
		$this->assertCount( 1, $GLOBALS['storage_mails'] );
		$this->assertStringContainsString( 'Usage: 120', $GLOBALS['storage_mails'][0]['message'] );
		Limits::run();
		$this->assertCount( 1, $GLOBALS['storage_mails'] );
	}
	public function test_local_quota_overrides_delete_local_and_protected_excess_is_reported(): void {
		$this->fixture();
		Options::$options[ Limits::OPTION ]          = array( 'local' => array( '' => array( 'quota' => 50 ) ) );
		Options::$options['backupplus_delete_local'] = 0;
		Limits::run();
		$this->assertFileDoesNotExist( $this->directory . '/old.zip' );
		$this->assertFileExists( $this->directory . '/new.zip' );
		$this->assertArrayHasKey( 'old.zip', Storage::$provider->files['a'] );
		$this->assertStringContainsString( 'protect', Options::$options[ Limits::STATUS ]['local:']['outcome'] );
	}
	public function test_failed_listings_keep_alert_state_and_do_not_delete(): void {
		$this->fixture();
		Options::$options[ Limits::OPTION ] = array( 'fake' => array( 'a' => array( 'quota' => 50 ) ) );
		Options::$options[ Limits::STATUS ] = array(
			'fake:a' => array(
				'quota' => array( 'level' => 100 ),
				'usage' => 120,
			),
		);
		Storage::$provider->failed_listing  = true;
		Limits::run();
		$this->assertSame( array(), Storage::$provider->calls );
		$this->assertFalse( Options::$options[ Limits::STATUS ]['fake:a']['available'] );
		$this->assertSame( 100, Options::$options[ Limits::STATUS ]['fake:a']['quota']['level'] );
		$this->assertSame( 120, Options::$options[ Limits::STATUS ]['fake:a']['usage'] );
	}
	public function test_partial_deletion_retries_the_same_chain_below_quota(): void {
		$this->fixture();
		History::$history[100]['plugins'][]               = 'split.zip.crypt';
		History::$history[100]['plugins1-size']           = 60;
		Storage::$provider->files['a']['split.zip.crypt'] = array(
			'name' => 'split.zip.crypt',
			'size' => 60,
		);
		Storage::$provider->failed_delete                 = 'split.zip.crypt';
		Options::$options[ Limits::OPTION ]               = array( 'fake' => array( 'a' => array( 'quota' => 150 ) ) );
		Limits::run();
		$this->assertTrue( History::$history[100]['storage_deleting']['fake:a'] );
		$this->assertArrayNotHasKey( 'old.zip', Storage::$provider->files['a'] );
		Storage::$provider->failed_delete = '';
		Limits::run();
		$this->assertArrayNotHasKey( 'split.zip.crypt', Storage::$provider->files['a'] );
		$this->assertTrue( History::$history[100]['storage_removed']['fake:a'] );
		$this->assertSame( 1, count( array_filter( Storage::$provider->calls, fn( $call ) => 'old.zip' === $call[1] ) ) );
	}
	public function test_active_jobs_and_monitor_lock_prevent_eviction(): void {
		$this->fixture();
		Options::$options[ Limits::OPTION ] = array( 'local' => array( '' => array( 'quota' => 100 ) ) );
		Cleanup::$active                    = true;
		Limits::run();
		$this->assertFileExists( $this->directory . '/old.zip' );
		Cleanup::$active = false;
		$lock            = fopen( $this->directory . '/storage-limits.lock', 'c' );
		flock( $lock, LOCK_EX );
		try { Limits::run();
			$this->assertFileExists( $this->directory . '/old.zip' ); }
		finally { flock( $lock, LOCK_UN );
			fclose( $lock ); }
	}
	public function test_locks_and_imports_count_toward_usage_and_cannot_be_evicted(): void {
		$this->fixture();
		History::$history[100]['native']      = false;
		History::$history[200]['always_keep'] = true;
		Options::$options[ Limits::OPTION ]   = array( 'fake' => array( 'a' => array( 'quota' => 1 ) ) );
		Limits::run();
		$this->assertSame( array(), Storage::$provider->calls );
		$this->assertSame( 120, Options::$options[ Limits::STATUS ]['fake:a']['usage'] );
	}
	public function test_alert_threshold_does_not_evict_and_unknown_sizes_are_unavailable(): void {
		$this->fixture();
		Options::$options[ Limits::OPTION ] = array( 'fake' => array( 'a' => array( 'alert' => 100 ) ) );
		Limits::run( null, true );
		$this->assertSame( array(), Storage::$provider->calls );
		Storage::$provider->files['a']['backup_2026-01-01-0000_test_abcdef123456-db.gz'] = array( 'name' => 'backup_2026-01-01-0000_test_abcdef123456-db.gz' );
		Limits::run();
		$this->assertFalse( Options::$options[ Limits::STATUS ]['fake:a']['available'] );
	}
	public function test_unlimited_destinations_keep_independent_database_and_file_counts(): void {
		$this->fixture();
		History::$history[100]['db']                 = 'only-db.gz';
		History::$history[100]['db-size']            = 60;
		Storage::$provider->files['b']['only-db.gz'] = array(
			'name' => 'only-db.gz',
			'size' => 60,
		);
		Options::$options[ Limits::OPTION ]          = array( 'fake' => array( 'a' => array( 'quota' => 1000 ) ) );
		Options::$options['backupplus_retain']       = 1;
		Options::$options['backupplus_retain_db']    = 1;
		Limits::run( null, true );
		$this->assertArrayNotHasKey( 'old.zip', Storage::$provider->files['b'] );
		$this->assertArrayHasKey( 'only-db.gz', Storage::$provider->files['b'] );
		$this->assertArrayHasKey( 'old.zip', Storage::$provider->files['a'] );
		$this->assertEmpty( History::$history[100]['storage_removed']['fake:b'] ?? null );
	}

	public function test_daily_quota_reminders_stop_on_confirmed_resolution(): void {
		$this->fixture();
		History::$history[100]['always_keep'] = true;
		Options::$options[ Limits::OPTION ]   = array( 'fake' => array( 'a' => array( 'quota' => 50 ) ) );
		Limits::run();
		$this->assertCount( 1, $GLOBALS['storage_mails'] );
		Options::$options[ Limits::STATUS ]['fake:a']['quota']['critical_sent'] = time() - DAY_IN_SECONDS;
		Limits::run();
		$this->assertCount( 2, $GLOBALS['storage_mails'] );
		Storage::$provider->files['a'] = array();
		Options::$options[ Limits::STATUS ]['fake:a']['quota']['critical_sent'] = time() - DAY_IN_SECONDS;
		Limits::run();
		$this->assertCount( 2, $GLOBALS['storage_mails'] );
		$this->assertSame( 0, Options::$options[ Limits::STATUS ]['fake:a']['quota']['level'] );
	}

	public function test_local_accounting_deduplicates_and_excludes_working_files(): void {
		$this->fixture();
		History::$history[100]['plugins'][] = 'old.zip';
		file_put_contents( $this->directory . '/backup_2026-01-01-0000_test_abcdef123456-db.gz.crypt', 'encrypted' );
		file_put_contents( $this->directory . '/backup_2026-01-01-0000_test_abcdef123456-db.gz.tmp', 'temporary' );
		file_put_contents( $this->directory . '/log.abcdef123456.txt', 'log' );
		file_put_contents( $this->directory . '/backup_pitr_' . str_repeat( 'a', 32 ) . '_' . str_repeat( 'b', 32 ) . '_locator.json', '{}' );
		file_put_contents( $this->directory . '/backup_pitr_' . str_repeat( 'a', 32 ) . '_' . str_repeat( 'b', 32 ) . '_log0.bin.zst.crypt', 'zstd' );
		$inventory = Limits::measure(
			array(
				'service'  => 'local',
				'instance' => '',
			),
			History::$history
		);
		$this->assertSame( 135, array_sum( $inventory ) );
		$this->assertCount( 5, $inventory );
	}

	public function test_partial_database_coverage_cannot_replace_a_complete_database(): void {
		$history                                 = array(
			100 => $this->set( 'full.gz', 'db' ),
			200 => $this->set( 'partial.gz', 'db' ),
		);
		$history[200]['database_coverage']['wp'] = array(
			'partial' => true,
			'tables'  => array( 'wp_posts' ),
		);
		$sets                                    = Limits::recovery_sets(
			$history,
			array(
				'full.gz'    => 60,
				'partial.gz' => 60,
			),
			'local:'
		);
		$this->assertTrue( $sets[100]['protected'] );
		$this->assertTrue( $sets[200]['protected'] );
	}

	public function test_upload_deletion_defers_to_a_local_quota(): void {
		require dirname( __DIR__, 3 ) . '/includes/core/trait-remote-transfer.php';
		$this->fixture();
		$core                                        = new class( $this->directory ) {
			use \BackupPlus_Core_Remote_Transfer { delete_local as public delete_uploaded_copy; }
			public function __construct( public string $directory ) {}
			public function backups_dir_location() {
				return $this->directory; }
			public function log( $message ) {}
		};
		Options::$options['backupplus_delete_local'] = 1;
		Options::$options[ Limits::OPTION ]          = array( 'local' => array( '' => array( 'quota' => 120 ) ) );
		$core->delete_uploaded_copy( 'old.zip' );
		$this->assertFileExists( $this->directory . '/old.zip' );
		Options::$options[ Limits::OPTION ] = array();
		$core->delete_uploaded_copy( 'old.zip' );
		$this->assertFileDoesNotExist( $this->directory . '/old.zip' );
	}

	public function test_saved_crossings_survive_interrupted_eviction(): void {
		$this->fixture();
		Options::$options[ Limits::OPTION ] = array( 'fake' => array( 'a' => array( 'quota' => 1000 ) ) );
		Options::$options[ Limits::STATUS ] = array(
			'fake:a' => array(
				'quota'                 => array(
					'level'         => 100,
					'limit'         => 1000,
					'critical_sent' => time(),
				),
				'pending_notifications' => array(
					'quota' => array(
						'level' => 100,
						'limit' => 1000,
						'usage' => 1200,
						'time'  => time(),
					),
				),
			),
		);
		Limits::run();
		$this->assertCount( 1, $GLOBALS['storage_mails'] );
		$this->assertStringContainsString( 'Usage: 1200', $GLOBALS['storage_mails'][0]['message'] );
		$this->assertStringContainsString( 'after eviction: 120', $GLOBALS['storage_mails'][0]['message'] );
		Limits::run();
		$this->assertCount( 1, $GLOBALS['storage_mails'] );
	}

	public function test_remote_absence_resolves_a_lost_deletion_acknowledgement(): void {
		$this->fixture();
		Options::$options[ Limits::OPTION ]      = array( 'fake' => array( 'a' => array( 'quota' => 120 ) ) );
		Storage::$provider->lost_acknowledgement = true;
		Limits::run();
		$this->assertTrue( History::$history[100]['storage_removed']['fake:a'] );
		$this->assertEmpty( History::$history[100]['storage_deleting'] );
		$this->assertArrayNotHasKey( 'old.zip', Storage::$provider->files['a'] );
	}

}
