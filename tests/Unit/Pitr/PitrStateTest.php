<?php
/** Publication, cursor fencing, durable retries and dependency-aware deletion. */

namespace BackupPlus\Tests\Unit\Pitr;

use BackupPlus\Tests\Support\PitrTestCase;
use BackupPlus_Backup_Deletion;
use BackupPlus_Backup_History;
use BackupPlus_Options;
use BackupPlus_PITR;
use BackupPlus_PITR_Storage;
use BackupPlus_Storage_Methods_Interface;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PITR_State_Provider;
use ReflectionMethod;
use ReflectionProperty;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class PitrStateTest extends PitrTestCase {

	private PITR_State_Provider $provider;

	private ?BackupPlus_PITR $worker = null;

	private BackupPlus_PITR_Storage $storage;

	private array $destination;

	private string $chain_id;

	private string $full_id;

	private string $increment_id;

	private array $start;

	private array $end;

	private array $full;

	private array $full_manifest;

	private array $full_locator;

	private array $log;

	private array $manifest;

	private array $locator_file;

	private array $pending;

	/** The state and the history right after the chain was published; the baseline of the deletion tests. */
	private array $retained_state = array();

	private array $retained_history = array();

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/pitr-state-stubs.php';
		mkdir( $this->directory . '/remote', 0700 );
		mkdir( $this->directory . '/transfer', 0700 );

		BackupPlus_Options::$values                     = array();
		BackupPlus_Backup_History::$history             = array();
		$GLOBALS['backupplus']                          = new class( $this->directory ) {
			private $directory;
			public function __construct( $directory ) {
				$this->directory = $directory;
			}
			public function backups_dir_location() {
				return $this->directory;
			}
		};
		$GLOBALS['backupplus_backup']                   = new \stdClass();
		$this->provider                                 = new PITR_State_Provider();
		$this->provider->local                          = $this->directory;
		$this->provider->remote                         = $this->directory . '/remote';
		BackupPlus_Storage_Methods_Interface::$provider = $this->provider;
		$this->destination                              = array(
			array(
				'service'  => 'fixture',
				'instance' => '',
			),
		);
		require_once dirname( __DIR__, 3 ) . '/includes/class-pitr.php';
		$this->build_chain();
	}

	protected function tearDown(): void {
		$this->worker = null;
		parent::tearDown();
	}

	private function property( object $object, string $name ) {
		return ( new ReflectionProperty( $object, $name ) )->getValue( $object );
	}

	private function set_property( object $object, string $name, $value ): void {
		( new ReflectionProperty( $object, $name ) )->setValue( $object, $value );
	}

	private function call( object $object, string $method, array $args = array() ) {
		return ( new ReflectionMethod( $object, $method ) )->invokeArgs( $object, $args );
	}

	/** Replace the worker; the previous one holds the chain lock until it is destroyed. */
	private function restart_worker( bool $enabled = true ): void {
		$this->worker = null;
		$this->worker = new BackupPlus_PITR( array( 'enabled' => $enabled ) );
	}

	/** A chain file in the backups directory. */
	private function make_file( string $id, string $suffix, string $contents ): array {
		$name = 'backup_pitr_' . $this->chain_id . '_' . $id . '_' . $suffix;
		file_put_contents( $this->directory . '/' . $name, $contents );
		return array(
			'name'         => $name,
			'size'         => strlen( $contents ),
			'sha256'       => hash( 'sha256', $contents ),
			'plain_sha256' => hash( 'sha256', $contents ),
		);
	}

	/** A full backup and one pending increment, saved as the worker state. */
	private function build_chain(): void {
		$this->chain_id      = str_repeat( 'a', 32 );
		$this->full_id       = str_repeat( 'b', 32 );
		$this->increment_id  = str_repeat( 'c', 32 );
		$this->start         = array(
			'file'     => 'binlog.000001',
			'position' => 100,
		);
		$this->end           = array(
			'file'     => 'binlog.000002',
			'position' => 4,
		);
		$source              = array(
			'engine'         => 'mysql',
			'server_version' => '8.4.0',
			'identity'       => str_repeat( 'd', 64 ),
			'log_epoch'      => str_repeat( 'e', 32 ),
		);
		$anchor              = array(
			'coordinate' => $this->start,
			'sha256'     => str_repeat( 'f', 64 ),
		);
		$full_artifact       = $this->make_file( $this->full_id, 'full.sql.gz', 'full' );
		$this->full          = array(
			'manifest_version'    => 1,
			'kind'                => 'database-full',
			'backup_id'           => $this->full_id,
			'chain_id'            => $this->chain_id,
			'source'              => $source,
			'database'            => 'wordpress',
			'created_at'          => 100,
			'snapshot_coordinate' => $this->start,
			'anchor'              => $anchor,
			'artifacts'           => array( $full_artifact ),
		);
		$this->full_manifest = $this->make_file( $this->full_id, 'manifest.json', json_encode( $this->full ) );
		$this->full_locator  = array(
			'manifest_version' => 1,
			'chain_id'         => $this->chain_id,
			'backup_id'        => $this->full_id,
			'manifest'         => $this->full_manifest,
		);
		$this->make_file( $this->full_id, 'locator.json', json_encode( $this->full_locator ) );
		$this->log          = $this->make_file( $this->increment_id, 'log0.bin.gz', 'log' );
		$increment          = array(
			'manifest_version'         => 1,
			'kind'                     => 'database-binlog',
			'backup_id'                => $this->increment_id,
			'chain_id'                 => $this->chain_id,
			'source'                   => $source,
			'database'                 => 'wordpress',
			'created_at'               => 200,
			'baseline_id'              => $this->full_id,
			'previous_backup_id'       => $this->full_id,
			'previous_manifest_sha256' => $this->full_manifest['sha256'],
			'start_coordinate'         => $this->start,
			'end_coordinate'           => $this->end,
			'anchor'                   => $anchor,
			'artifacts'                => array( $this->log ),
			'segments'                 => array(
				array(
					'start'      => $this->start,
					'end'        => 200,
					'next'       => $this->end,
					'artifact'   => $this->log['name'],
					'boundaries' => array(
						array(
							'position'  => 150,
							'timestamp' => 150,
						),
					),
				),
			),
		);
		$this->manifest     = $this->make_file( $this->increment_id, 'manifest.json', json_encode( $increment ) );
		$locator            = array(
			'manifest_version' => 1,
			'chain_id'         => $this->chain_id,
			'backup_id'        => $this->increment_id,
			'manifest'         => $this->manifest,
		);
		$this->locator_file = $this->make_file( $this->increment_id, 'locator.json', json_encode( $locator ) );
		$this->pending      = array(
			'entry'        => array(
				'manifest' => $increment,
				'sha256'   => $this->manifest['sha256'],
				'locator'  => $locator,
			),
			'files'        => array( $this->log, $this->manifest, $this->locator_file ),
			'receipts'     => array(),
			'fingerprint'  => 'test',
			'destinations' => $this->destination,
			'encryption'   => array(),
		);
		$this->restart_worker();
		$initial = array(
			'version' => 1,
			'chains'  => array(
				$this->chain_id => array(
					'entries'      => array(
						array(
							'manifest' => $this->full,
							'sha256'   => $this->full_manifest['sha256'],
							'locator'  => $this->full_locator,
						),
					),
					'destinations' => $this->destination,
					'cursor'       => $this->start,
					'state'        => 'complete',
					'imported'     => false,
				),
			),
			'active'  => $this->chain_id,
			'pending' => $this->pending,
		);
		$this->set_property( $this->worker, 'state', $initial );
		$this->call( $this->worker, 'save' );
		$this->storage = new BackupPlus_PITR_Storage( $this->directory . '/transfer', array(), $this->destination );
	}

	/** The state of the worker. */
	private function state(): array {
		return $this->property( $this->worker, 'state' );
	}

	/** The manifest upload fails: the chain must not advance. */
	private function fail_publication(): void {
		$this->provider->fail = $this->manifest['name'];
		$this->assertRejected(
			function () {
				$this->call( $this->worker, 'publish', array( $this->storage ) );
			},
			'Failed publication advanced the chain.'
		);
	}

	/** A failed publication, a worker restart and a successful retry. */
	private function publish_chain(): void {
		$this->fail_publication();
		$this->restart_worker();
		$this->provider->fail = null;
		$this->call( $this->worker, 'publish', array( $this->storage ) );
		$this->retained_state   = $this->state();
		$this->retained_history = BackupPlus_Backup_History::$history;
	}

	/** @return array<int, array> A history row of an ordinary backup. */
	private function ordinary_history(): array {
		return array(
			100 => array(
				'db'          => 'ordinary.sql.gz',
				'label'       => 'Ordinary backup',
				'always_keep' => true,
			),
		);
	}

	public function test_a_storage_setting_change_cannot_move_a_running_chain(): void {
		$this->provider->options = array( 'directory' => 'changed-during-dump' );
		$this->assertRejected(
			function () {
				$bound                       = $this->destination;
				$bound[0]['settings_sha256'] = hash( 'sha256', serialize( array( 'directory' => 'original' ) ) );
				new BackupPlus_PITR_Storage( $this->directory . '/transfer', array(), $bound );
			},
			'Changed storage settings were accepted without proving chain availability.'
		);
		$this->call( $this->storage, 'provider', array( $this->destination[0] ) );
		$this->assertSame( 'original', $this->provider->options['directory'], 'A destination setting change moved a running chain.' );
	}

	public function test_a_failed_publication_keeps_the_cursor_and_withholds_the_completion_locator(): void {
		$this->fail_publication();
		$this->assertSame( $this->start, $this->state()['chains'][ $this->chain_id ]['cursor'], 'Cursor advanced before the manifest was durable.' );
		$this->assertFileDoesNotExist( $this->provider->remote . '/' . $this->locator_file['name'], 'Completion locator appeared before all dependencies.' );
	}

	public function test_upload_progress_survives_a_worker_restart(): void {
		$this->fail_publication();
		$this->restart_worker();
		$this->assertNotEmpty( $this->state()['pending']['receipts'], 'Upload progress did not survive worker restart.' );
	}

	public function test_a_verified_retry_advances_the_cursor_without_repeating_uploads(): void {
		$this->publish_chain();
		$this->assertSame( $this->end, $this->state()['chains'][ $this->chain_id ]['cursor'], 'Verified publication did not advance the cursor.' );
		$this->assertSame( array( $this->log['name'], $this->manifest['name'], $this->locator_file['name'] ), $this->provider->uploads, 'Retry uploaded completed objects again or reordered publication.' );
	}

	public function test_history_exposes_stable_full_and_increment_recovery_endpoints(): void {
		$this->publish_chain();
		$metadata = BackupPlus_PITR::chain_metadata( $this->chain_id, $this->state()['chains'][ $this->chain_id ] );
		$this->assertSame( array( $this->full_id, $this->increment_id ), array_column( $metadata['points'], 'backup_id' ), 'History did not expose stable full/increment recovery endpoints.' );
		$this->assertSame( $this->end, $metadata['points'][1]['coordinate'], 'History did not expose stable full/increment recovery endpoints.' );
	}

	public function test_existing_history_acquires_recovery_endpoints_from_the_chain_index(): void {
		$this->publish_chain();
		$legacy    = array( 100 => array( 'pitr' => array( 'chain_id' => $this->chain_id ) ) );
		$decorated = BackupPlus_PITR::decorate_history( $legacy );
		$this->assertCount( 2, $decorated[100]['pitr']['points'], 'Existing history did not acquire recovery endpoints from its chain index.' );
	}

	public function test_database_downloads_include_the_complete_recovery_chain(): void {
		$this->publish_chain();
		$history   = BackupPlus_PITR::decorate_history( BackupPlus_Backup_History::$history );
		$backup    = reset( $history );
		$downloads = $backup['pitr']['downloads'];
		$this->assertSame( $this->full['artifacts'][0]['name'], $backup['db'], 'Download metadata must preserve the full-dump restore entity.' );
		$this->assertSame(
			array(
				$this->full['artifacts'][0]['name'],
				$this->full_manifest['name'],
				'backup_pitr_' . $this->chain_id . '_' . $this->full_id . '_locator.json',
				$this->log['name'],
				$this->manifest['name'],
				$this->locator_file['name'],
			),
			array_column( $downloads, 'name' )
		);
		$this->assertSame( array( 0, 0, 0, 1, 1, 1 ), array_column( $downloads, 'increment' ) );
		foreach ( $downloads as $file ) {
			$this->assertSame( filesize( $this->directory . '/' . $file['name'] ), $file['size'] );
		}
		require_once dirname( __DIR__, 3 ) . '/includes/admin/trait-downloads.php';
		$admin = new class {
			use \BackupPlus_Admin_Downloads;
		};
		$this->assertSame( $downloads, $admin->download_files( $backup, 'db' ) );
		$this->assertSame(
			array(
				array(
					'name' => 'ordinary.gz',
					'size' => 123,
				),
			),
			$admin->download_files(
				array(
					'db'      => 'ordinary.gz',
					'db-size' => 123,
				),
				'db'
			)
		);
		$this->assertSame(
			array( 0, 2 ),
			array_keys(
				$admin->download_files(
					array(
						'plugins' => array(
							0 => 'base.zip',
							2 => 'increment.zip',
						),
					),
					'plugins'
				)
			)
		);
	}

	public function test_unavailable_index_does_not_offer_stale_increment_downloads(): void {
		$this->publish_chain();
		unlink( $this->directory . '/pitr/state.php' );
		$history = BackupPlus_PITR::decorate_history( BackupPlus_Backup_History::$history );
		$backup  = reset( $history );
		$this->assertSame( 'unavailable', $backup['pitr']['state'] );
		$this->assertArrayNotHasKey( 'downloads', $backup['pitr'] );
	}

	public function test_download_requests_route_each_index_to_its_chain_file(): void {
		$this->publish_chain();
		require_once dirname( __DIR__, 3 ) . '/includes/admin/trait-downloads.php';
		define( 'BACKUPPLUS_SET_TIME_LIMIT', 30 );
		$admin                 = new class {
			use \BackupPlus_Admin_Downloads;
		};
		$GLOBALS['backupplus'] = new class( $this->directory ) {
			public $nonce;
			public $job_time_ms = 0;
			public $logfile_name;
			private $directory;
			private $jobs = array();
			public function __construct( $directory ) {
				$this->directory    = $directory;
				$this->logfile_name = $directory . '/unused.log';
			}
			public function backups_dir_location() {
				return $this->directory; }
			public function backup_time_nonce( $nonce ) {
				$this->nonce = $nonce; }
			public function jobdata_set( $key, $value ) {
				$this->jobs[ $this->nonce ][ $key ] = $value; }
			public function jobdata_get( $key ) {
				return $this->jobs[ $this->nonce ][ $key ] ?? null; }
			public function jobdata_reset() {}
			public function log( $message ) {}
			public function php_error() {}
			public function get_canonical_service_list( $services ) {
				return array(); }
			public function spool_file( $path ) {
				throw new \RuntimeException( $path ); }
		};
		$history               = BackupPlus_Backup_History::$history;
		$timestamp             = key( $history );
		$files                 = $history[ $timestamp ]['pitr']['downloads'];
		$result                = $admin->do_backupplus_download_backup( array_keys( $files ), 'db', $timestamp, '' );
		$this->assertSame( 'downloaded', $result['result'] );
		foreach ( $files as $index => $file ) {
			$status = $admin->get_download_statuses( array( 'uddlstatus_,' . $timestamp . ',db,' . $index ) );
			$this->assertSame( $this->directory . '/' . $file['name'], $status[0]['f'] );
			$this->assertSame( 100, $status[0]['p'] );
			$spooled = $this->assertRejected(
				function () use ( $admin, $timestamp, $index ) {
					$admin->do_backupplus_download_backup( $index, 'db', $timestamp, 2 );
				},
				'The download should reach the spooler.'
			);
			$this->assertSame( $this->directory . '/' . $file['name'], $spooled->getMessage() );
		}
		$this->assertSame( 'no_such_file', $admin->do_backupplus_download_backup( count( $files ), 'db', $timestamp, '' )['code'] );
	}

	public function test_index_recovery_republishes_the_chain_without_touching_an_ordinary_backup(): void {
		$this->publish_chain();
		$ordinary_history                   = $this->ordinary_history();
		BackupPlus_Backup_History::$history = $ordinary_history;
		$uploads_before                     = $this->provider->uploads;
		$this->assertSame( 1, $this->worker->rebuild_history(), 'Local index recovery did not republish its existing chain.' );
		$repaired      = BackupPlus_Backup_History::$history;
		$repaired_time = $this->state()['chains'][ $this->chain_id ]['history_time'];
		$this->assertSame( $ordinary_history[100], $repaired[100], 'Index recovery overwrote an ordinary backup at a colliding timestamp.' );
		$this->assertNotSame( 100, $repaired_time, 'Index recovery overwrote an ordinary backup at a colliding timestamp.' );
		$this->assertCount( 2, $repaired[ $repaired_time ]['pitr']['points'], 'Local index recovery lost the full dump or incremental points.' );
		$this->assertSame( $this->full['artifacts'][0]['name'], $repaired[ $repaired_time ]['db'], 'Local index recovery lost the full dump or incremental points.' );
		$this->assertSame( $uploads_before, $this->provider->uploads, 'Index recovery uploaded backup artifacts.' );
	}

	public function test_history_recovery_does_not_resurrect_a_chain_being_deleted(): void {
		$this->publish_chain();
		$ordinary_history                                     = $this->ordinary_history();
		$deleting_state                                       = $this->state();
		$deleting_state['chains'][ $this->chain_id ]['state'] = 'deleting';
		$this->set_property( $this->worker, 'state', $deleting_state );
		BackupPlus_Backup_History::$history = $ordinary_history;
		$this->assertSame( 0, $this->worker->rebuild_history(), 'History recovery resurrected a chain being deleted.' );
		$this->assertSame( $ordinary_history, BackupPlus_Backup_History::$history, 'History recovery resurrected a chain being deleted.' );
	}

	/** @return array{0: array, 1: array} The chain with a restore pin, and the same chain without it. */
	private function retention_chains(): array {
		$pinned_chain           = array( 'history_time' => 100 ) + $this->retained_state['chains'][ $this->chain_id ];
		$unpinned               = $pinned_chain;
		$pinned_chain['pinned'] = true;
		return array( $pinned_chain, $unpinned );
	}

	public function test_a_restore_pin_alone_protects_a_chain_without_a_history_row(): void {
		$this->publish_chain();
		list( $pinned_chain, $unpinned ) = $this->retention_chains();
		$this->assertTrue( $this->call( $this->worker, 'retention_locked', array( $pinned_chain, array() ) ), 'The pin alone must protect a chain without a history row.' );
		$this->assertFalse( $this->call( $this->worker, 'retention_locked', array( $unpinned, array() ) ), 'The pin alone must protect a chain without a history row.' );
	}

	public function test_unlocking_the_history_row_releases_a_restore_pin(): void {
		$this->publish_chain();
		list( $pinned_chain ) = $this->retention_chains();
		$this->assertFalse( $this->call( $this->worker, 'retention_locked', array( $pinned_chain, array( 100 => array( 'db' => 'x' ) ) ) ), 'Unlocking the history row must release a restore pin.' );
	}

	public function test_a_locked_history_row_protects_an_unpinned_chain(): void {
		$this->publish_chain();
		list( , $unpinned ) = $this->retention_chains();
		$this->assertTrue( $this->call( $this->worker, 'retention_locked', array( $unpinned, array( 100 => array( 'always_keep' => true ) ) ) ), 'A locked history row must protect an unpinned chain.' );
	}

	public function test_the_active_chain_can_be_deleted_while_recovery_stays_enabled(): void {
		$this->publish_chain();
		$state = $this->state();
		$this->assertSame( $this->chain_id, $state['active'], 'The published chain must be the active one.' );
		$this->call( $this->worker, 'delete', array( $this->chain_id ) );
		$state = $this->state();
		$this->assertArrayNotHasKey( $this->chain_id, $state['chains'], 'The active chain was not deleted.' );
		$this->assertNull( $state['active'], 'With no active chain the next scheduled run takes a new baseline.' );
		$this->assertNotContains( $this->chain_id, $state['protected_previous'] ?? array(), 'A deleted chain must not stay protected.' );
	}

	/** A published chain whose remote deletion fails; the worker that failed is returned replaced by a fresh one. */
	private function fail_remote_deletion(): void {
		$this->publish_chain();
		$this->restart_worker( false );
		// Simulate loss of an acknowledgement for an earlier deletion; absence is idempotent success.
		$this->provider->deletion_failure = true;
		$this->assertRejected(
			function () {
				$this->worker->delete( $this->chain_id );
			},
			'Remote deletion failure was ignored.'
		);
	}

	public function test_a_remote_deletion_failure_keeps_the_deletion_inventory(): void {
		$this->fail_remote_deletion();
		$this->assertSame( 'deleting', $this->state()['chains'][ $this->chain_id ]['state'], 'Deletion inventory was lost on failure.' );
	}

	public function test_a_partly_deleted_chain_cannot_be_selected_for_recovery(): void {
		$this->fail_remote_deletion();
		$this->assertRejected(
			function () {
				$this->worker->recovery_plan( $this->chain_id );
			},
			'Partly deleted chain was selectable for recovery.'
		);
	}

	public function test_a_deletion_retry_removes_the_complete_chain(): void {
		$this->fail_remote_deletion();
		$this->restart_worker( false );
		$this->provider->deletion_failure = false;
		$this->worker->delete( $this->chain_id );
		$this->assertSame( array(), $this->state()['chains'], 'Deletion retry did not remove the complete chain.' );
		$this->assertSame( array(), glob( $this->provider->remote . '/backup_pitr_*' ), 'Remote orphan artifacts remain after chain deletion.' );
	}

	/** The published chain, its files local and remote and its history row, with a worker that deletes. */
	private function restore_deletion_fixture(): BackupPlus_PITR {
		require_once dirname( __DIR__, 3 ) . '/includes/class-backup-deletion.php';
		$this->worker = null;
		$fixture      = new BackupPlus_PITR( array( 'enabled' => false ) );
		$this->set_property( $fixture, 'state', $this->retained_state );
		$this->call( $fixture, 'save' );
		BackupPlus_Backup_History::$history = $this->retained_history;
		$files                              = array_unique( $this->call( $fixture, 'chain_files', array( $this->retained_state['chains'][ $this->chain_id ] ) ) );
		foreach ( $files as $file ) {
			file_put_contents( $this->directory . '/' . $file, 'deletion fixture' );
			file_put_contents( $this->provider->remote . '/' . $file, 'deletion fixture' );
		}
		return $fixture;
	}

	/** Publish the chain and prepare the deletion fixture; the worker is released as the lock is shared. */
	private function prepare_deletion(): int {
		$this->publish_chain();
		$this->worker = $this->restore_deletion_fixture();
		$this->worker = null;
		return $this->retained_state['chains'][ $this->chain_id ]['history_time'];
	}

	private function selection_options( $timestamp ): array {
		return array(
			'backup_timestamp'    => (string) $timestamp,
			'delete_remote'       => true,
			'remote_delete_limit' => 1,
			'is_continuation'     => false,
		);
	}

	public function test_a_bounded_deletion_keeps_its_continuation_inventory(): void {
		$timestamp    = $this->prepare_deletion();
		$this->worker = $this->restore_deletion_fixture();
		$progress     = $this->worker->delete( $this->chain_id, true, microtime( true ) - 1 );
		$this->assertFalse( $progress['complete'], 'A bounded deletion lost its continuation inventory.' );
		$this->assertSame( 0, $progress['backup_remote'], 'A bounded deletion lost its continuation inventory.' );
		$this->assertArrayHasKey( $timestamp, BackupPlus_Backup_History::$history, 'A bounded deletion lost its continuation inventory.' );
		$this->assertRejected(
			function () {
				$this->worker->delete( $this->chain_id, false );
			},
			'A remote deletion silently changed to local-only midway through the chain.'
		);
	}

	public function test_the_shared_delete_action_resumes_and_removes_the_full_chain(): void {
		$timestamp    = $this->prepare_deletion();
		$this->worker = $this->restore_deletion_fixture();
		$this->worker->delete( $this->chain_id, true, microtime( true ) - 1 );
		$this->worker = null;
		$result       = BackupPlus_Backup_Deletion::delete(
			array(
				'backup_timestamp' => $timestamp,
				'delete_remote'    => true,
			)
		);
		$this->assertSame( 'success', $result['result'], 'The shared Delete action did not resume and remove the full PITR chain.' );
		$this->assertGreaterThan( 0, $result['backup_remote'], 'The shared Delete action did not resume and remove the full PITR chain.' );
		$this->assertGreaterThan( 0, $result['backup_local'], 'The shared Delete action did not resume and remove the full PITR chain.' );
		$this->assertEmpty( BackupPlus_Backup_History::$history, 'The shared Delete action did not resume and remove the full PITR chain.' );
	}

	public function test_a_local_only_deletion_leaves_the_remote_artifacts(): void {
		$timestamp     = $this->prepare_deletion();
		$remote_before = glob( $this->provider->remote . '/backup_pitr_*' );
		$result        = BackupPlus_Backup_Deletion::delete(
			array(
				'backup_timestamp' => $timestamp,
				'delete_remote'    => false,
			)
		);
		$this->assertSame( 'success', $result['result'], 'Local-only UI deletion removed remote artifacts or left local chain fragments.' );
		$this->assertSame( 0, $result['backup_remote'], 'Local-only UI deletion removed remote artifacts or left local chain fragments.' );
		$this->assertSame( $remote_before, glob( $this->provider->remote . '/backup_pitr_*' ), 'Local-only UI deletion removed remote artifacts or left local chain fragments.' );
		$this->assertEmpty( glob( $this->directory . '/backup_pitr_*' ), 'Local-only UI deletion removed remote artifacts or left local chain fragments.' );
	}

	public function test_an_admin_batch_yields_with_its_acknowledged_remote_deletion_preserved(): void {
		$timestamp                      = $this->prepare_deletion();
		$this->provider->deletion_delay = 1100000;
		$result                         = BackupPlus_Backup_Deletion::delete_selection( $this->selection_options( $timestamp ) );
		$this->assertSame( 'continue', $result['result'], 'The admin batch did not yield with its acknowledged remote deletion preserved.' );
		$this->assertSame( 1, $result['backup_remote'], 'The admin batch did not yield with its acknowledged remote deletion preserved.' );
		$this->assertSame( 0, $result['backup_sets'], 'The admin batch did not yield with its acknowledged remote deletion preserved.' );
		$this->assertSame( (string) $timestamp, $result['timestamps'], 'The admin batch did not yield with its acknowledged remote deletion preserved.' );
	}

	public function test_an_admin_batch_finishes_the_complete_chain_on_continuation(): void {
		$timestamp                      = $this->prepare_deletion();
		$options                        = $this->selection_options( $timestamp );
		$this->provider->deletion_delay = 1100000;
		BackupPlus_Backup_Deletion::delete_selection( $options );
		$this->provider->deletion_delay = 0;
		$result                         = BackupPlus_Backup_Deletion::delete_selection( $options );
		$this->assertSame( 'success', $result['result'], 'The admin batch failed to finish its complete recovery chain on continuation.' );
		$this->assertSame( 1, $result['backup_sets'], 'The admin batch failed to finish its complete recovery chain on continuation.' );
		$this->assertSame( (string) $timestamp, $result['deleted_timestamps'], 'The admin batch failed to finish its complete recovery chain on continuation.' );
	}

	/** An admin object that records the ordinary backups the shared batch delegates to it. */
	private function admin_recorder(): object {
		$GLOBALS['backupplus_admin'] = new class {
			public $calls = array();
			public function delete_set( $opts ) {
				$this->calls[] = $opts['backup_timestamp'];
				unset( BackupPlus_Backup_History::$history[ $opts['backup_timestamp'] ] );
				return array(
					'result'        => 'success',
					'backup_sets'   => 1,
					'backup_local'  => 1,
					'backup_remote' => 0,
				);
			}
		};
		return $GLOBALS['backupplus_admin'];
	}

	public function test_a_mixed_selection_delegates_ordinary_backups_and_deletes_the_chain_together(): void {
		$timestamp     = $this->prepare_deletion();
		$admin         = $this->admin_recorder();
		$ordinary_time = $timestamp + 1;
		BackupPlus_Backup_History::$history[ $ordinary_time ] = array( 'db' => 'ordinary.gz' );
		$options                     = $this->selection_options( $timestamp );
		$options['backup_timestamp'] = $ordinary_time . ',' . $timestamp;
		$options['delete_remote']    = false;
		$result                      = BackupPlus_Backup_Deletion::delete_selection( $options );
		$this->assertSame( 'success', $result['result'], 'Mixed selection did not delegate ordinary backups and delete the PITR chain together.' );
		$this->assertSame( 2, $result['backup_sets'], 'Mixed selection did not delegate ordinary backups and delete the PITR chain together.' );
		$this->assertSame( 0, $result['count_backups'], 'Mixed selection did not delegate ordinary backups and delete the PITR chain together.' );
		$this->assertSame( array( (string) $ordinary_time ), $admin->calls, 'Mixed selection did not delegate ordinary backups and delete the PITR chain together.' );
	}

	public function test_a_mixed_failure_keeps_completed_rows_and_a_recovery_chain_in_use(): void {
		$timestamp = $this->prepare_deletion();
		$this->admin_recorder();
		$ordinary_time               = $timestamp + 1;
		$options                     = $this->selection_options( $timestamp );
		$options['backup_timestamp'] = $ordinary_time . ',' . $timestamp;
		$options['delete_remote']    = false;
		BackupPlus_Options::$values[ BackupPlus_PITR::OPTION ] = array( 'enabled' => true );
		BackupPlus_Backup_History::$history[ $ordinary_time ]  = array( 'db' => 'ordinary.gz' );
		// A restore holds the chain, which is the one thing that still blocks deleting it.
		$this->restart_worker();
		$state                  = $this->state();
		$state['restore_chain'] = $this->chain_id;
		$this->set_property( $this->worker, 'state', $state );
		$this->call( $this->worker, 'save' );
		$this->worker = null;
		$result       = BackupPlus_Backup_Deletion::delete_selection( $options );
		$this->assertSame( 'error', $result['result'], 'A mixed failure lost completed rows or deleted the active recovery chain.' );
		$this->assertStringContainsString( 'restore', $result['message'], 'A mixed failure lost completed rows or deleted the active recovery chain.' );
		$this->assertSame( (string) $ordinary_time, $result['deleted_timestamps'], 'A mixed failure lost completed rows or deleted the active recovery chain.' );
		$this->assertArrayHasKey( $timestamp, BackupPlus_Backup_History::$history, 'A mixed failure lost completed rows or deleted the active recovery chain.' );
	}
	/** Quota deletion preserves both the authoritative inventory and all remaining artifacts. */
	public function test_destination_deletion_preserves_a_restorable_local_chain(): void {
		$this->publish_chain();
		$state           = $this->state();
		$state['active'] = null;
		$this->set_property( $this->worker, 'state', $state );
		$this->worker->delete_destination( $this->chain_id, $this->destination[0] );
		$this->assertSame( array(), glob( $this->provider->remote . '/backup_pitr_*' ) );
		$this->assertNotEmpty( BackupPlus_Backup_History::$history );
		$plan = $this->worker->recovery_plan( $this->chain_id );
		$this->assertSame( array(), $plan['destinations'] );
		$this->assertSame( $this->end, $plan['target_coordinate'] );
		foreach ( $this->worker->storage_state()['chains'][ $this->chain_id ]['entries'] as $entry ) {
			foreach ( $entry['manifest']['artifacts'] as $artifact ) { $this->assertTrue( $this->storage->verify_file( $artifact ) ); }
		}
	}

	public function test_destination_deletion_preserves_a_restorable_remote_chain(): void {
		$this->publish_chain();
		foreach ( $this->call( $this->worker, 'chain_files', array( $this->state()['chains'][ $this->chain_id ] ) ) as $file ) { copy( $this->directory . '/' . $file, $this->provider->remote . '/' . $file ); }
		$state           = $this->state();
		$state['active'] = null;
		$this->set_property( $this->worker, 'state', $state );
		$this->worker->delete_destination(
			$this->chain_id,
			array(
				'service'  => 'local',
				'instance' => '',
			)
		);
		$this->assertSame( array(), glob( $this->directory . '/backup_pitr_*' ) );
		$this->assertNotEmpty( glob( $this->provider->remote . '/backup_pitr_*' ) );
		$plan = $this->worker->recovery_plan( $this->chain_id );
		$this->assertSame( $this->destination, $plan['destinations'] );
		foreach ( $this->worker->storage_state()['chains'][ $this->chain_id ]['entries'] as $entry ) {
			foreach ( $entry['manifest']['artifacts'] as $artifact ) { $this->storage->fetch( $artifact );
				$this->assertTrue( $this->storage->verify_file( $artifact ) ); }
		}
	}

}
