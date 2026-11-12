<?php
/** Exact selections, streaming SQL, namespace stability and confined local restores. */

namespace BackupPlus\Tests\Unit\Backup;

use BackupPlus_Backup_Sources;
use BackupPlus_Source_Restore;
use BackupPlus_SQL_Stream;
use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class BackupSourcesTest extends TestCase {

	private string $directory = '';

	protected function setUp(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/backup-sources-stubs.php';
		require_once BACKUPPLUS_DIR . '/includes/class-backup-sources.php';
		require_once BACKUPPLUS_DIR . '/includes/class-sql-stream.php';
		require_once BACKUPPLUS_DIR . '/includes/class-source-restore.php';
		$this->directory = sys_get_temp_dir() . '/bp-sources-' . bin2hex( random_bytes( 5 ) );
		mkdir( $this->directory );
	}

	protected function tearDown(): void {
		BackupPlus_Source_Restore::remove( $this->directory );
	}

	/** The callback must throw. */
	private function assertRejects( callable $callback, string $message ): void {
		try {
			$callback();
		} catch ( Exception $e ) {
			$this->addToAssertionCount( 1 );
			return;
		}
		$this->fail( $message );
	}

	public function test_the_plugins_own_tooling_directory_is_never_backed_up(): void {
		$tooling = BACKUPPLUS_DIR . '/.cache';
		$created = ! is_dir( $tooling ) && mkdir( $tooling );
		try {
			$this->assertTrue( BackupPlus_Backup_Sources::exclude( false, realpath( $tooling ) ) );
			mkdir( $tooling . '/bp-probe' );
			$this->assertTrue( BackupPlus_Backup_Sources::exclude( false, realpath( $tooling . '/bp-probe' ) ) );
			$this->assertFalse( BackupPlus_Backup_Sources::exclude( false, BACKUPPLUS_DIR . '/includes' ) );
			$this->assertFalse( BackupPlus_Backup_Sources::exclude( false, BACKUPPLUS_DIR . '/.cache-other' ) );
		} finally {
			@rmdir( $tooling . '/bp-probe' );
			if ( $created ) {
				rmdir( $tooling );
			}
		}
	}

	private function tables(): array {
		return array( 'wp_posts', 'wp_options', 'unrelated', 'WP_posts' );
	}

	/** A point in time recovery backup with a full snapshot and one incremental recovery point. */
	private function pitr_backup(): array {
		return array(
			'db'   => 'backup_pitr_full.sql.gz',
			'pitr' => array(
				'state'  => 'active',
				'points' => array(
					array(
						'backup_id' => str_repeat( 'a', 32 ),
						'increment' => 0,
					),
					array(
						'backup_id' => str_repeat( 'b', 32 ),
						'increment' => 1,
					),
				),
			),
		);
	}

	public function test_main_eligibility_preserves_the_exact_prefix(): void {
		$this->assertSame( array( 'wp_posts', 'wp_options' ), BackupPlus_Backup_Sources::resolve( array( 'mode' => 'all' ), $this->tables(), 'wp_' ), 'Main eligibility preserves the exact prefix.' );
	}

	public function test_external_tables_are_eligible_without_a_prefix(): void {
		$this->assertSame( $this->tables(), BackupPlus_Backup_Sources::resolve( array( 'mode' => 'all' ), $this->tables(), '' ), 'External tables are eligible without a prefix.' );
	}

	public function test_all_eligible_mode_keeps_the_legacy_case_insensitive_prefix_default(): void {
		$this->assertSame( array( 'WP_only' ), BackupPlus_Backup_Sources::resolve( array( 'mode' => 'all' ), array( 'WP_only' ), 'wp_' ), 'All-eligible mode keeps the legacy case-insensitive prefix default when no case-distinct duplicates exist.' );
	}

	public function test_exact_selection_bypasses_the_prefix(): void {
		$this->assertSame(
			array( 'unrelated' ),
			BackupPlus_Backup_Sources::resolve(
				array(
					'mode'   => 'selected',
					'tables' => array( 'unrelated' ),
				),
				$this->tables(),
				'wp_'
			),
			'Exact selection bypasses prefix and never includes related/core tables.'
		);
	}

	public function test_disabled_databases_can_retain_empty_selections(): void {
		$this->assertSame(
			array(),
			BackupPlus_Backup_Sources::resolve(
				array(
					'enabled' => false,
					'mode'    => 'selected',
				),
				$this->tables(),
				''
			),
			'Disabled databases can retain empty selections.'
		);
	}

	public function test_an_empty_selection_fails(): void {
		$this->assertRejects(
			function () {
				BackupPlus_Backup_Sources::resolve( array( 'mode' => 'selected' ), $this->tables(), '' );
			},
			'Empty selection must fail.'
		);
	}

	public function test_a_missing_selection_fails(): void {
		$this->assertRejects(
			function () {
				BackupPlus_Backup_Sources::resolve(
					array(
						'mode'   => 'selected',
						'tables' => array( 'missing' ),
					),
					$this->tables(),
					''
				);
			},
			'Missing selection must fail.'
		);
	}

	public function test_identical_basenames_have_separate_namespaces(): void {
		$this->assertNotSame( BackupPlus_Backup_Sources::source_id( '/b/data' ), BackupPlus_Backup_Sources::source_id( '/a/data' ), 'Identical basenames have separate namespaces.' );
		$this->assertTrue( (bool) BackupPlus_Backup_Sources::custom( BackupPlus_Backup_Sources::source_id( '/a/data' ) ), 'Namespaces remain compatible with the archive filename parser.' );
	}

	public function test_pitr_preflight_accepts_a_full_recovery_point(): void {
		BackupPlus_Source_Restore::preflight( array( 'db' => true ), $this->pitr_backup(), array( 'backupplus_pitr_restore_point' => str_repeat( 'a', 32 ) ) );
		$this->addToAssertionCount( 1 );
	}

	public static function rejected_pitr_selections(): array {
		return array(
			'missing'     => array( array() ),
			'incremental' => array( array( 'backupplus_pitr_restore_point' => str_repeat( 'b', 32 ) ) ),
			'forged'      => array( array( 'backupplus_pitr_restore_point' => str_repeat( 'c', 32 ) ) ),
		);
	}

	#[DataProvider( 'rejected_pitr_selections' )]
	public function test_ordinary_pitr_preflight_rejects_missing_incremental_and_forged_recovery_points( array $options ): void {
		$pitr_backup = $this->pitr_backup();
		$this->assertRejects(
			function () use ( $pitr_backup, $options ) {
				BackupPlus_Source_Restore::preflight( array( 'db' => true ), $pitr_backup, $options );
			},
			'Ordinary PITR preflight must reject missing, incremental and forged recovery-point selections.'
		);
	}

	#[DataProvider( 'unavailable_pitr_states' )]
	public function test_an_unavailable_pitr_chain_cannot_bypass_preflight_through_its_full_snapshot( string $state ): void {
		$pitr_backup                  = $this->pitr_backup();
		$pitr_backup['pitr']['state'] = $state;
		$this->assertRejects(
			function () use ( $pitr_backup ) {
				BackupPlus_Source_Restore::preflight( array( 'db' => true ), $pitr_backup, array( 'backupplus_pitr_restore_point' => str_repeat( 'a', 32 ) ) );
			},
			'An unavailable PITR chain must not bypass preflight through its full snapshot.'
		);
	}

	public static function unavailable_pitr_states(): array {
		return array(
			'deleting'    => array( 'deleting' ),
			'unavailable' => array( 'unavailable' ),
		);
	}

	public function test_dot_traversal_is_rejected(): void {
		$this->assertRejects(
			function () {
				BackupPlus_Backup_Sources::paths( array( '/a/../secret' ) );
			},
			'Dot traversal rejected.'
		);
	}

	public function test_remote_wrappers_are_rejected(): void {
		$this->assertRejects(
			function () {
				BackupPlus_Backup_Sources::paths( array( 'https://example.test/file' ) );
			},
			'Remote wrappers rejected.'
		);
	}

	public function test_foreign_drive_letter_paths_require_a_local_restore_mapping_on_unix(): void {
		if ( '/' !== DIRECTORY_SEPARATOR ) {
			$this->markTestSkipped( 'Drive-letter paths are local paths outside Unix.' );
		}
		$this->assertRejects(
			function () {
				BackupPlus_Source_Restore::destination( 'C:/foreign/windows/path', 'directory' );
			},
			'Foreign drive-letter paths require a local restore mapping on Unix.'
		);
	}

	/** A dump with quoted semicolons, a multi-line value and a trigger under a custom delimiter. */
	private function write_dumps(): string {
		$sql = "# Header\nDROP TABLE IF EXISTS `unrelated`;\nCREATE TABLE `unrelated` (`value` text);\nINSERT INTO `unrelated` VALUES ('semi;colon'),('quote\\' and newline\nkept'),('doubled''quote');\n/*!40101 SET NAMES utf8mb4 */;\nDELIMITER ;;\nCREATE TRIGGER `test` BEFORE INSERT ON `unrelated` FOR EACH ROW BEGIN SET @x=';;'; SET @y=2; END;;\nDELIMITER ;\n";
		file_put_contents( $this->directory . '/dump.sql', $sql );
		$handle = gzopen( $this->directory . '/dump.gz', 'wb' );
		gzwrite( $handle, $sql );
		gzclose( $handle );
		return $sql;
	}

	public function test_plain_and_gzip_statement_readers_preserve_delimiters_quoted_semicolons_and_triggers(): void {
		$this->write_dumps();
		$plain      = iterator_to_array( BackupPlus_SQL_Stream::statements( $this->directory . '/dump.sql' ) );
		$compressed = iterator_to_array( BackupPlus_SQL_Stream::statements( $this->directory . '/dump.gz' ) );
		$this->assertSame( $compressed, $plain, 'Plain/gzip statement readers preserve delimiters, quoted semicolons and triggers.' );
		$this->assertCount( 5, $plain, 'Plain/gzip statement readers preserve delimiters, quoted semicolons and triggers.' );
		$this->assertStringContainsString( "newline\nkept", $plain[2], 'Newlines in values are preserved.' );
	}

	public function test_truncated_sql_is_rejected(): void {
		file_put_contents( $this->directory . '/bad.sql', "INSERT INTO t VALUES ('unterminated" );
		$this->assertRejects(
			function () {
				iterator_to_array( BackupPlus_SQL_Stream::statements( $this->directory . '/bad.sql' ) );
			},
			'Truncated SQL rejected.'
		);
	}

	public function test_directory_merging_preserves_unselected_content(): void {
		mkdir( $this->directory . '/source' );
		mkdir( $this->directory . '/target' );
		file_put_contents( $this->directory . '/source/replaced', 'new' );
		file_put_contents( $this->directory . '/target/keep', 'unselected' );
		BackupPlus_Source_Restore::merge( $this->directory . '/source', $this->directory . '/target' );
		$this->assertSame( 'new', file_get_contents( $this->directory . '/target/replaced' ), 'Directory merging preserves unselected content.' );
		$this->assertSame( 'unselected', file_get_contents( $this->directory . '/target/keep' ), 'Directory merging preserves unselected content.' );
	}

	public function test_restore_cannot_follow_destination_symlinks(): void {
		mkdir( $this->directory . '/source' );
		mkdir( $this->directory . '/target' );
		mkdir( $this->directory . '/outside' );
		file_put_contents( $this->directory . '/source/replaced', 'new' );
		symlink( $this->directory . '/outside', $this->directory . '/target/link' );
		$this->assertRejects(
			function () {
				BackupPlus_Source_Restore::merge( $this->directory . '/source', $this->directory . '/target/link' );
			},
			'Restore cannot follow destination symlinks.'
		);
		$this->assertFileDoesNotExist( $this->directory . '/outside/replaced', 'Restore cannot follow destination symlinks.' );
	}

	/** A staged custom source archive whose manifest names $path as the source directory. */
	private function stage( string $id, string $path ): string {
		$working = $this->directory . '/stage-' . bin2hex( random_bytes( 3 ) );
		mkdir( $working . '/' . $id, 0775, true );
		file_put_contents( $working . '/' . $id . '/payload.txt', 'restored' );
		$manifest = array(
			'source'  => array(
				'path' => $path,
				'kind' => 'directory',
			),
			'version' => 2,
			'type'    => $id,
			'deleted' => array(),
		);
		file_put_contents( $working . '/backupplus-manifest.json', json_encode( $manifest ) );
		return $working;
	}

	/** History for one custom source recorded at $path. */
	private function history( string $id, string $path ): array {
		return array(
			'file_sources' => array(
				$id => array(
					'path' => $path,
					'kind' => 'directory',
				),
			),
		);
	}

	public function test_restore_writes_to_the_destination_recorded_in_history(): void {
		$recorded = $this->directory . '/recorded';
		$id       = BackupPlus_Backup_Sources::source_id( $recorded );
		BackupPlus_Source_Restore::restore( $this->stage( $id, $recorded . '/' ), $id, $this->history( $id, $recorded ), array(), false );
		$this->assertSame( 'restored', file_get_contents( $recorded . '/payload.txt' ), 'A manifest matching history restores to the recorded destination.' );
	}

	public function test_restore_aborts_when_the_archive_names_a_different_destination(): void {
		$recorded = $this->directory . '/recorded';
		$forged   = $this->directory . '/forged';
		$id       = BackupPlus_Backup_Sources::source_id( $recorded );
		$working  = $this->stage( $id, $forged );
		foreach ( array( array(), array( 'backupplus_source_destinations' => array( $id => $this->directory . '/override' ) ) ) as $options ) {
			$this->assertRejects(
				function () use ( $working, $id, $recorded, $options ) {
					BackupPlus_Source_Restore::restore( $working, $id, $this->history( $id, $recorded ), $options, false );
				},
				'A manifest path that differs from history aborts the restore.'
			);
		}
		foreach ( array( $forged, $recorded, $this->directory . '/override' ) as $path ) {
			$this->assertFileDoesNotExist( $path, 'An aborted restore writes nothing.' );
		}
	}

	public function test_restore_honours_the_administrator_override(): void {
		$recorded = $this->directory . '/recorded';
		$override = $this->directory . '/override';
		$id       = BackupPlus_Backup_Sources::source_id( $recorded );
		BackupPlus_Source_Restore::restore( $this->stage( $id, $recorded ), $id, $this->history( $id, $recorded ), array( 'backupplus_source_destinations' => array( $id => $override ) ), false );
		$this->assertSame( 'restored', file_get_contents( $override . '/payload.txt' ), 'The override replaces the recorded destination.' );
		$this->assertFileDoesNotExist( $recorded, 'The recorded destination is untouched when overridden.' );
	}

	public function test_restore_without_recorded_source_requires_an_override(): void {
		$forged  = $this->directory . '/forged';
		$id      = BackupPlus_Backup_Sources::source_id( $forged );
		$working = $this->stage( $id, $forged );
		$this->assertRejects(
			function () use ( $working, $id ) {
				BackupPlus_Source_Restore::restore( $working, $id, array(), array(), false );
			},
			'An archive alone cannot choose the destination.'
		);
		$this->assertRejects(
			function () use ( $id ) {
				BackupPlus_Source_Restore::preflight( array( $id => true ), array(), array() );
			},
			'Preflight requires a recorded destination or an override.'
		);
		$this->assertFileDoesNotExist( $forged, 'Nothing is written without a recorded destination or an override.' );
	}

	public function test_archive_metadata_does_not_replace_a_recorded_source(): void {
		$recorded = $this->directory . '/recorded';
		$id       = BackupPlus_Backup_Sources::source_id( $recorded );
		$archive  = $this->directory . '/archive.zip';
		$zip      = new \ZipArchive();
		$zip->open( $archive, \ZipArchive::CREATE );
		$zip->addFromString(
			'backupplus-manifest.json',
			json_encode(
				array(
					'type'   => $id,
					'source' => array(
						'path' => $this->directory . '/forged',
						'kind' => 'directory',
					),
				)
			)
		);
		$zip->close();
		$history = $this->history( $id, $recorded );
		$this->assertSame( $history, BackupPlus_Backup_Sources::archive_metadata( $history, $id, $archive ), 'A downloaded archive cannot rewrite the recorded source.' );
		$recovered = BackupPlus_Backup_Sources::archive_metadata( array(), $id, $archive );
		$this->assertSame( $this->directory . '/forged', $recovered['archive_sources'][ $id ]['path'], 'An unrecorded source is still recovered from the archive, as a suggestion.' );
		$this->assertArrayNotHasKey( 'file_sources', $recovered, 'A source read from the archive is never taken as recorded.' );
	}
}
