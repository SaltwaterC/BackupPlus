<?php
/** Focused completion checks; no WordPress installation or network is required. */

namespace BackupPlus\Tests\Unit\Backup;

use BackupPlus_Backup_History;
use BackupPlus_Options;
use BackupPlus_Pre_Update_Backups;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Pre_Update_Backup_Fixture;
use Pre_Update_Test_State;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class PreUpdateBackupsTest extends TestCase {

	/** Stands in for ABSPATH: the plugin requires a few WordPress admin files from it. */
	private string $compat_root = '';

	private BackupPlus_Pre_Update_Backups $coordinator;

	private Pre_Update_Backup_Fixture $fixture;

	private array $batch = array();

	private array $job = array();

	protected function setUp(): void {
		$this->compat_root = sys_get_temp_dir() . '/backupplus-lock-compat-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->compat_root . '/wp-admin/includes', 0700, true );
		foreach ( array( 'file.php', 'update.php', 'class-wp-upgrader.php' ) as $file ) {
			file_put_contents( $this->compat_root . '/wp-admin/includes/' . $file, '<?php' );
		}
		define( 'ABSPATH', $this->compat_root . '/' );
		require_once dirname( __DIR__, 2 ) . '/Support/pre-update-backups-stubs.php';
		require_once BACKUPPLUS_DIR . '/includes/class-pre-update-backups.php';
		Pre_Update_Test_State::$options = array();
		BackupPlus_Options::$options    = array();

		$this->fixture            = new Pre_Update_Backup_Fixture();
		$this->fixture->directory = sys_get_temp_dir() . '/backupplus-completion-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->fixture->directory );
		$GLOBALS['backupplus'] = $this->fixture;

		$this->coordinator = new BackupPlus_Pre_Update_Backups();
		$this->batch       = array(
			'id'           => 'batch',
			'job'          => '0123456789ab',
			'backup_time'  => time(),
			'entities'     => array( 'plugins' ),
			'destinations' => array(),
		);
		$this->job         = array(
			'pre_update_batch' => 'batch',
			'job_type'         => 'backup',
			'backup_files'     => 'finished',
			'backup_database'  => array( 'wp' => array( 'status' => 'finished' ) ),
		);

		BackupPlus_Backup_History::$history = array(
			'nonce'         => '0123456789ab',
			'db'            => 'database.gz',
			'db-size'       => 7,
			'plugins'       => array( 'plugins.zip', 'plugins2.zip' ),
			'plugins-size'  => 7,
			'plugins1-size' => 7,
		);
		foreach ( array( 'database.gz', 'plugins.zip', 'plugins2.zip' ) as $file ) {
			file_put_contents( $this->fixture->directory . '/' . $file, 'archive' );
		}
	}

	protected function tearDown(): void {
		foreach ( glob( $this->fixture->directory . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $this->fixture->directory );
		foreach ( glob( $this->compat_root . '/wp-admin/includes/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $this->compat_root . '/wp-admin/includes' );
		rmdir( $this->compat_root . '/wp-admin' );
		rmdir( $this->compat_root );
	}

	/** Two S3 instances and a default FTP instance, each holding every archive; the second split archive is gone locally. */
	private function use_remote_destinations(): void {
		unlink( $this->fixture->directory . '/plugins2.zip' );
		$this->batch['destinations'] = array(
			's3'  => array( 'first', 'second' ),
			'ftp' => array( '' ),
		);
		foreach ( array( 'database.gz', 'plugins.zip', 'plugins2.zip' ) as $file ) {
			foreach ( $this->batch['destinations'] as $service => $instances ) {
				foreach ( $instances as $instance ) {
					$this->job[ 'uploaded_' . $service . '-' . ( '' === $instance ? '' : $instance . '-' ) . md5( $file ) ] = 'yes';
				}
			}
		}
	}

	public function test_a_missing_setting_defaults_to_enabled(): void {
		$this->assertTrue( BackupPlus_Pre_Update_Backups::enabled(), 'Missing setting must default to enabled' );
	}

	public function test_a_saved_disabled_value_is_preserved(): void {
		BackupPlus_Options::$options['backupplus_autobackup_default'] = 0;
		$this->assertFalse( BackupPlus_Pre_Update_Backups::enabled(), 'Saved disabled value must be preserved' );
	}

	public function test_a_complete_local_backup_passes(): void {
		$this->assertTrue( $this->coordinator->verified( $this->batch, $this->job ), 'Complete local backup must pass' );
	}

	public function test_a_missing_split_archive_fails(): void {
		unlink( $this->fixture->directory . '/plugins2.zip' );
		$this->assertFalse( $this->coordinator->verified( $this->batch, $this->job ), 'Missing split archive must fail' );
	}

	public function test_every_remote_instance_can_substitute_for_deleted_local_archives(): void {
		$this->use_remote_destinations();
		$this->assertTrue( $this->coordinator->verified( $this->batch, $this->job ), 'Every remote instance can substitute for deleted local archives' );
	}

	public function test_a_missing_final_upload_receipt_fails(): void {
		$this->use_remote_destinations();
		unset( $this->job[ 'uploaded_s3-second-' . md5( 'plugins2.zip' ) ] );
		$this->assertFalse( $this->coordinator->verified( $this->batch, $this->job ), 'Missing final upload receipt must fail' );
	}

	public function test_same_size_archive_corruption_fails_checksum_verification(): void {
		$this->use_remote_destinations();
		BackupPlus_Backup_History::$history['checksums']['sha256']['plugins0'] = hash( 'sha256', 'archive' );
		$this->assertTrue( $this->coordinator->verified( $this->batch, $this->job ), 'An intact archive must pass checksum verification' );
		file_put_contents( $this->fixture->directory . '/plugins.zip', 'corrupt' );
		$this->assertFalse( $this->coordinator->verified( $this->batch, $this->job ), 'Same-size archive corruption must fail checksum verification' );
	}

	public function test_completion_is_bound_to_its_batch(): void {
		$this->use_remote_destinations();
		$this->assertTrue( $this->coordinator->verified( $this->batch, $this->job ), 'Completion must pass for its own batch' );
		$this->job['pre_update_batch'] = 'another-batch';
		$this->assertFalse( $this->coordinator->verified( $this->batch, $this->job ), 'Completion must be bound to its batch' );
	}

	public function test_incremental_jobs_cannot_authorize_updates(): void {
		$this->use_remote_destinations();
		$this->assertTrue( $this->coordinator->verified( $this->batch, $this->job ), 'Completion must pass for a full backup job' );
		$this->job['job_type'] = 'incremental';
		$this->assertFalse( $this->coordinator->verified( $this->batch, $this->job ), 'Incremental jobs cannot authorize updates' );
	}
}
