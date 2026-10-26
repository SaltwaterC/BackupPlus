<?php
/** Strict deletion must preserve a complete retry inventory after local or remote failure. */

namespace BackupPlus\Tests\Unit\Cli;

use BackupPlus\Tests\Support\CliTestCase;
use BackupPlus_Backup_Deletion;
use BackupPlus_Backup_History;
use BackupPlus_Backup_Sources;
use BackupPlus_Pre_Update_Backups;
use BackupPlus_Storage_Methods_Interface;
use CLI_Delete_Provider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class CliDeletionTest extends CliTestCase {

	private CLI_Delete_Provider $provider;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/cli-deletion-stubs.php';
		BackupPlus_Backup_History::$history             = array();
		BackupPlus_Pre_Update_Backups::$batch           = array();
		$this->provider                                 = new CLI_Delete_Provider();
		BackupPlus_Storage_Methods_Interface::$provider = $this->provider;
	}

	/** @return array The history row of a backup with core, custom and external database archives and an increment. */
	private function backup_with_archives(): array {
		$source                             = BackupPlus_Backup_Sources::source_id( '/removed-source.txt' );
		$backup                             = array(
			'nonce'       => 'aaaabbbbcccc',
			'service'     => array( 'fixture' ),
			'wpcore'      => array( 'core.zip', 'core2.zip' ),
			$source       => 'custom.zip',
			'db2'         => 'external.gz',
			'incremental' => array( 'snapshots' => array( 'wpcore' => 'incremental_aaaabbbbcccc_wpcore.php' ) ),
		);
		BackupPlus_Backup_History::$history = array( 123 => $backup );
		foreach ( array( 'core.zip', 'core2.zip', 'custom.zip', 'external.gz', 'incremental_aaaabbbbcccc_wpcore.php' ) as $name ) {
			file_put_contents( $this->directory . '/archives/' . $name, 'fixture' );
		}
		return $backup;
	}

	private function delete_remote(): array {
		return BackupPlus_Backup_Deletion::delete(
			array(
				'backup_timestamp' => 123,
				'delete_remote'    => true,
			)
		);
	}

	/** A history row whose archive cannot be unlinked because it is a directory. */
	private function undeletable_backup(): void {
		BackupPlus_Backup_History::$history = array(
			123 => array(
				'nonce'   => 'aaaabbbbcccc',
				'plugins' => 'cannot-delete.zip',
			),
		);
		mkdir( $this->directory . '/archives/cannot-delete.zip' );
	}

	public function test_failed_remote_deletion_reports_each_archive_and_keeps_the_complete_chain(): void {
		$backup = $this->backup_with_archives();
		$result = $this->delete_remote();
		$this->assertSame( 'error', $result['result'], 'Failed remote deletion reports each archive' );
		$this->assertCount( 4, $result['errors'], 'Failed remote deletion reports each archive' );
		$this->assertSame( 4, $this->provider->calls, 'Failed remote deletion reports each archive' );
		$this->assertSame( $backup['wpcore'], BackupPlus_Backup_History::$history[123]['wpcore'], 'Partial deletion preserves complete chain and inventory metadata' );
		$this->assertFileExists( $this->directory . '/archives/incremental_aaaabbbbcccc_wpcore.php', 'Partial deletion preserves complete chain and inventory metadata' );
	}

	public function test_a_retry_deletes_removed_custom_core_external_database_archives_and_inventories(): void {
		$this->backup_with_archives();
		$this->delete_remote();
		$this->provider->fail = false;
		$result               = $this->delete_remote();
		$this->assertSame( 'success', $result['result'], 'Retry deletes removed custom, core, external DB archives and inventories' );
		$this->assertEmpty( BackupPlus_Backup_History::$history, 'Retry deletes removed custom, core, external DB archives and inventories' );
		$this->assertFileDoesNotExist( $this->directory . '/archives/incremental_aaaabbbbcccc_wpcore.php', 'Retry deletes removed custom, core, external DB archives and inventories' );
	}

	public function test_a_failed_local_deletion_retains_the_history(): void {
		$this->undeletable_backup();
		$result = BackupPlus_Backup_Deletion::delete( array( 'backup_timestamp' => 123 ) );
		$this->assertSame( 'error', $result['result'], 'Failed local deletion retains history' );
		$this->assertArrayHasKey( 123, BackupPlus_Backup_History::$history, 'Failed local deletion retains history' );
	}

	public function test_pending_update_restore_points_cannot_be_deleted(): void {
		$this->undeletable_backup();
		BackupPlus_Pre_Update_Backups::$batch = array(
			'backup_time' => 123,
			'state'       => 'ready',
		);
		$this->assertTrue( BackupPlus_Backup_Deletion::protected_set( 123, BackupPlus_Backup_History::$history[123] ), 'Pending update restore points cannot be deleted' );
	}

	public function test_an_active_increment_protects_its_whole_chain(): void {
		$this->undeletable_backup();
		$this->options['backupplus_oneshotnonce']         = 'ddddeeeeffff';
		$this->options['backupplus_jobdata_ddddeeeeffff'] = array(
			'job_type'              => 'incremental',
			'jobstatus'             => 'begun',
			'incremental_base_time' => 123,
		);
		$this->assertTrue( BackupPlus_Backup_Deletion::protected_set( 123, BackupPlus_Backup_History::$history[123] ), 'An active increment protects its whole chain' );
	}
}
