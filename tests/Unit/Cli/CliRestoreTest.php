<?php
/** Restore selection and archive verification checks without WordPress. */

namespace BackupPlus\Tests\Unit\Cli;

use BackupPlus_Backup_History;
use BackupPlus_Backup_Sources;
use BackupPlus_CLI_Restore;
use BackupPlus_CLI_Secret;
use BackupPlus_Storage_Methods_Interface;
use CLI_Restore_Backup;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class CliRestoreTest extends TestCase {

	private string $directory = '';

	private CLI_Restore_Backup $backupplus;

	/** The id of a custom source that no longer exists on the site. */
	private string $custom = '';

	protected function setUp(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/cli-restore-stubs.php';
		$this->directory = sys_get_temp_dir() . '/backupplus-cli-restore-unit-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->directory );
		$this->backupplus                            = new CLI_Restore_Backup();
		$this->backupplus->directory                 = $this->directory;
		$GLOBALS['backupplus']                       = $this->backupplus;
		BackupPlus_Storage_Methods_Interface::$calls = 0;
		$this->custom                                = BackupPlus_Backup_Sources::source_id( '/historical/custom.txt' );
		BackupPlus_Backup_History::$backup           = array(
			'plugins'          => array( 'plugins.zip', 'plugins2.zip' ),
			'wpcore'           => array( 'core.zip' ),
			$this->custom      => array( 'custom.zip' ),
			'db'               => 'db.gz',
			'db2'              => 'db2.gz',
			'incremental_sets' => array(
				100 => array( 'plugins' => array( 0 => 'plugins.zip' ) ),
				200 => array( 'plugins' => array( 1 => 'plugins2.zip' ) ),
			),
		);
	}

	protected function tearDown(): void {
		BackupPlus_CLI_Restore::cleanup();
		foreach ( glob( $this->directory . '/*' ) as $path ) {
			unlink( $path );
		}
		rmdir( $this->directory );
	}

	/** The callback must fail with a runtime error or an invalid argument. */
	private function assertRejected( callable $callback, string $message ): void {
		$thrown = null;
		try {
			$callback();
		} catch ( RuntimeException | InvalidArgumentException $error ) {
			$thrown = $error;
		}
		$this->assertNotNull( $thrown, $message );
	}

	public function test_default_restores_include_historical_custom_files_and_external_databases(): void {
		list( $backup, $entities, $options ) = BackupPlus_CLI_Restore::selection( 123, array() );
		$this->assertCount( 5, $entities, 'Default restores include historical custom files and external databases' );
		$this->assertArrayHasKey( $this->custom, $entities, 'Default restores include historical custom files and external databases' );
		$this->assertSame( array( 'saved' => '2' ), $options['backupplus_external_targets'][2], 'External restore targets use existing saved IDs' );
	}

	public function test_historical_points_prune_later_archives_before_decryption(): void {
		list( $backup, $entities ) = BackupPlus_CLI_Restore::selection(
			123,
			array(
				'type'       => 'files',
				'components' => 'plugins',
				'point'      => '100',
			)
		);
		$this->assertSame( array( 'plugins' => 'plugins' ), $entities, 'Historical points prune later archives before decryption' );
		$this->assertSame( array( 'plugins.zip' ), $backup['plugins'], 'Historical points prune later archives before decryption' );
	}

	public function test_database_only_restores_exclude_files(): void {
		list( $backup, $entities ) = BackupPlus_CLI_Restore::selection( 123, array( 'type' => 'database' ) );
		$this->assertCount( 2, $entities, 'Database-only restores exclude files' );
		$this->assertArrayHasKey( 'db', $entities, 'Database-only restores exclude files' );
		$this->assertArrayHasKey( 'db2', $entities, 'Database-only restores exclude files' );
	}

	public function test_partial_restore_selects_only_requested_plugin(): void {
		list( $backup, $entities, $options ) = BackupPlus_CLI_Restore::selection( 123, array( 'plugin' => 'akismet' ) );
		$this->assertSame( array( 'plugins' => 'plugins' ), $entities );
		$this->assertSame( array( 'akismet' ), $options['plugins_to_restore'] );
		$this->assertFalse( $options['include_unspecified_plugins'] );
	}

	public function test_partial_restore_selects_only_requested_table_and_database(): void {
		list( $backup, $entities, $options ) = BackupPlus_CLI_Restore::selection( 123, array( 'table' => 'wp_options' ) );
		$this->assertSame( array( 'db' => 'db' ), $entities );
		$this->assertSame( array( 'wp_options' ), $options['tables_to_restore'] );
		$this->assertFalse( $options['include_unspecified_tables'] );
	}

	public function test_pitr_chain_selects_full_snapshot_for_table_restore(): void {
		BackupPlus_Backup_History::$backup['pitr'] = array(
			'points' => array(
				array(
					'backup_id' => 'full',
					'increment' => 0,
				),
				array(
					'backup_id' => 'log',
					'increment' => 1,
				),
			),
		);
		list( $backup, $entities, $options )       = BackupPlus_CLI_Restore::selection( 123, array( 'table' => 'wp_options' ) );
		$this->assertSame( 'full', $options['backupplus_pitr_restore_point'] );
	}

	public function test_network_restores_select_one_site_from_its_database_and_uploads(): void {
		$GLOBALS['cli_restore_multisite']    = true;
		list( $backup, $entities, $options ) = BackupPlus_CLI_Restore::selection( 123, array( 'site' => '2' ) );
		$this->assertSame( array( 'db' => 'db' ), $entities, 'Only the database and uploads of a site are restored' );
		$this->assertSame( 2, $options['backupplus_restore_ms_whichsites'] );
		list( $backup, $entities, $options ) = BackupPlus_CLI_Restore::selection(
			123,
			array(
				'import-into' => 'new',
				'slug'        => 'imported',
			)
		);
		$this->assertSame( array( 'db' => 'db' ), $entities, 'Only the database and uploads of a single site are imported' );
		$this->assertSame( 'new', $options['backupplus_restore_ms_import'] );
		$this->assertSame( 'imported', $options['backupplus_restore_ms_import_slug'] );
		list( $backup, $entities, $options ) = BackupPlus_CLI_Restore::selection( 123, array( 'import-into' => '3' ) );
		$this->assertSame( 3, $options['backupplus_restore_ms_import'] );
	}

	public static function invalid_network_selectors(): iterable {
		foreach ( array(
			array( 'site' => 'second' ),
			array( 'import-into' => 'other' ),
			array( 'import-into' => 'new' ),
			array(
				'site'        => '2',
				'import-into' => '3',
			),
			array(
				'site'   => '2',
				'plugin' => 'akismet',
			),
		) as $invalid ) {
			yield json_encode( $invalid ) => array( $invalid );
		}
	}

	#[DataProvider( 'invalid_network_selectors' )]
	public function test_invalid_network_selectors_fail( array $invalid ): void {
		$GLOBALS['cli_restore_multisite'] = true;
		$this->assertRejected(
			function () use ( $invalid ) {
				BackupPlus_CLI_Restore::selection( 123, $invalid );
			},
			'Invalid network restore selectors must fail'
		);
	}

	public function test_network_selectors_fail_on_a_single_site(): void {
		foreach ( array( array( 'site' => '1' ), array( 'import-into' => '2' ) ) as $selector ) {
			$this->assertRejected(
				function () use ( $selector ) {
					BackupPlus_CLI_Restore::selection( 123, $selector );
				},
				'Network restore selectors must fail on a single site'
			);
		}
	}

	public static function invalid_selectors(): iterable {
		foreach ( array(
			array( 'type' => 'invalid' ),
			array( 'components' => 'missing' ),
			array(
				'type'       => 'files',
				'components' => 'db',
			),
			array( 'point' => '199' ),
			array( 'plugin' => '../other' ),
			array( 'plugin' => '' ),
			array( 'table' => '' ),
			array(
				'table'      => 'external_table',
				'components' => 'db2',
			),
			array(
				'table'  => 'wp_options',
				'plugin' => 'akismet',
			),
			array(
				'plugin' => 'akismet',
				'type'   => 'database',
			),
			array(
				'table'      => 'wp_options',
				'components' => 'plugins,db',
			),
			array(
				'plugin'     => 'akismet',
				'components' => 'db',
			),
		) as $invalid ) {
			yield json_encode( $invalid ) => array( $invalid );
		}
	}

	#[DataProvider( 'invalid_selectors' )]
	public function test_invalid_restore_selectors_fail( array $invalid ): void {
		$this->assertRejected(
			function () use ( $invalid ) {
				BackupPlus_CLI_Restore::selection( 123, $invalid );
			},
			'Invalid restore selectors must fail'
		);
	}

	public function test_missing_backups_fail(): void {
		$this->assertRejected(
			function () {
				BackupPlus_CLI_Restore::selection( 999, array() );
			},
			'Missing backups must fail'
		);
	}

	/** A history row of one plugins archive that exists locally. */
	private function local_backup(): array {
		file_put_contents( $this->directory . '/plugins.zip', 'archive' );
		return array(
			'timestamp'    => 123,
			'plugins'      => array( 'plugins.zip' ),
			'plugins-size' => 7,
			'checksums'    => array( 'sha256' => array( 'plugins0' => hash( 'sha256', 'archive' ) ) ),
		);
	}

	public function test_valid_archives_pass_size_and_checksum_verification(): void {
		$backup = $this->local_backup();
		$this->assertSame( array( $this->directory . '/plugins.zip' ), BackupPlus_CLI_Restore::archives( $backup, array( 'plugins' => 'plugins' ) ), 'Valid archives pass size and checksum verification' );
	}

	public function test_changed_same_sized_archives_fail_before_restoring(): void {
		$backup = $this->local_backup();
		file_put_contents( $this->directory . '/plugins.zip', 'changed' );
		$this->assertRejected(
			function () use ( $backup ) {
				BackupPlus_CLI_Restore::archives( $backup, array( 'plugins' => 'plugins' ) );
			},
			'Changed same-sized archives fail before restoring'
		);
	}

	public function test_unsafe_history_archive_names_fail(): void {
		$backup            = $this->local_backup();
		$backup['plugins'] = array( '../outside.zip' );
		$this->assertRejected(
			function () use ( $backup ) {
				BackupPlus_CLI_Restore::archives( $backup, array( 'plugins' => 'plugins' ) );
			},
			'Unsafe history archive names must fail'
		);
	}

	public function test_missing_archives_are_fetched_from_recorded_storage(): void {
		$backup = array(
			'timestamp' => 123,
			'plugins'   => array( 'remote.zip' ),
			'service'   => array( 'fixture' ),
		);
		BackupPlus_CLI_Restore::archives( $backup, array( 'plugins' => 'plugins' ) );
		$this->assertSame( 1, BackupPlus_Storage_Methods_Interface::$calls, 'Missing archives are fetched from recorded storage' );
		$this->assertFileExists( $this->directory . '/remote.zip', 'Missing archives are fetched from recorded storage' );
	}

	public function test_secret_variables_are_discarded_after_use(): void {
		$value = 'disposable-secret';
		BackupPlus_CLI_Secret::forget( $value );
		$this->assertSame( '', $value, 'Secret variables are discarded after use' );
	}
}
