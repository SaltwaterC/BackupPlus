<?php
/** Job listing, backup selection extras, download selection and JSON input of the management commands. */

namespace BackupPlus\Tests\Unit\Cli;

use BackupPlus\Tests\Support\CliTestCase;
use BackupPlus_CLI;
use BackupPlus_CLI_Download;
use BackupPlus_CLI_Input;
use BackupPlus_CLI_Jobs;
use BackupPlus_CLI_Prune;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class CliManageTest extends CliTestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once BACKUPPLUS_DIR . '/includes/class-cli-download.php';
		require_once BACKUPPLUS_DIR . '/includes/class-cli-jobs.php';
		require_once BACKUPPLUS_DIR . '/includes/class-cli-prune.php';
		require_once BACKUPPLUS_DIR . '/includes/class-cli-config.php';
		$this->options['backupplus_database_selection'] = array(
			'wp' => array(
				'enabled' => true,
				'mode'    => 'selected',
				'tables'  => array( 'wp_options' ),
			),
		);
		$this->backupplus->backup_methods               = array(
			'none'  => 'None',
			'email' => 'Email',
			's3'    => 'Amazon S3',
		);
	}

	public function test_label_and_services_reach_the_request(): void {
		$plan = BackupPlus_CLI::selection(
			array(
				'type'     => 'database',
				'label'    => 'Before the migration',
				'services' => 's3',
			)
		);
		$this->assertSame( 'Before the migration', $plan['options']['label'], 'The label reaches the engine' );
		$this->assertSame( array( 's3' ), $plan['services'], 'The services reach the worker' );
	}

	public static function invalid_extras(): iterable {
		yield 'empty label' => array( array( 'label' => ' ' ) );
		yield 'control character in the label' => array( array( 'label' => "a\nb" ) );
		yield 'oversized label' => array( array( 'label' => str_repeat( 'a', 201 ) ) );
		yield 'unknown service' => array( array( 'services' => 'nowhere' ) );
		yield 'none is not a service' => array( array( 'services' => 'none' ) );
		yield 'unknown service after a valid one' => array( array( 'services' => 's3,nowhere' ) );
		yield 'services with local' => array(
			array(
				'services' => 's3',
				'local'    => true,
			),
		);
	}

	#[DataProvider( 'invalid_extras' )]
	public function test_invalid_extras_are_rejected( array $args ): void {
		$this->assertRejected(
			function () use ( $args ) {
				BackupPlus_CLI::selection( array_merge( array( 'type' => 'database' ), $args ) );
			},
			'Invalid label or services are rejected before a job starts'
		);
	}

	public function test_jobs_list_pending_resumptions(): void {
		$this->cron                             = array(
			2000000000 => array(
				'backupplus_backup_resume' => array(
					'key' => array(
						'schedule' => false,
						'args'     => array( 3, 'aaaabbbbcccc' ),
					),
				),
				'unrelated_hook'           => array(
					'key' => array(
						'schedule' => false,
						'args'     => array( 1, 'ddddeeeeffff' ),
					),
				),
			),
		);
		$GLOBALS['cron']                        = &$this->cron;
		$this->backupplus->jobs['aaaabbbbcccc'] = array(
			'backup_time' => 1700000000,
			'jobstatus'   => 'filescreating',
			'label'       => 'Nightly',
		);
		$rows                                   = BackupPlus_CLI_Jobs::rows();
		$this->assertCount( 1, $rows, 'Only resumptions of this plugin are jobs' );
		$this->assertSame( 'aaaabbbbcccc', $rows[0]['nonce'], 'The job is identified by its nonce' );
		$this->assertSame( 'filescreating', $rows[0]['status'], 'The saved stage is reported' );
		$this->assertSame( 3, $rows[0]['resumption'], 'The pending resumption number is reported' );
		$this->assertSame( 0, $rows[0]['overdue'], 'A future resumption is not overdue' );
		$this->assertSame( 'Nightly', $rows[0]['label'], 'The label is reported' );
	}

	public function test_download_components_follow_type_and_selection(): void {
		$backup = array(
			'plugins' => array( 'p.zip' ),
			'db'      => 'd.gz',
			'db2'     => 'e.gz',
			'nonce'   => 'aaaabbbbcccc',
			'themes'  => '',
		);
		$this->assertSame( array( 'plugins', 'db', 'db2' ), BackupPlus_CLI_Download::entities( $backup, array() ), 'Components without archives are skipped' );
		$this->assertSame( array( 'db', 'db2' ), BackupPlus_CLI_Download::entities( $backup, array( 'type' => 'database' ) ), 'Database type selects dumps' );
		$this->assertSame( array( 'plugins' ), BackupPlus_CLI_Download::entities( $backup, array( 'type' => 'files' ) ), 'Files type selects file archives' );
		$this->assertSame( array( 'db2' ), BackupPlus_CLI_Download::entities( $backup, array( 'components' => 'db2' ) ), 'Components select exact IDs' );
		$this->assertRejected(
			function () use ( $backup ) {
				BackupPlus_CLI_Download::entities( $backup, array( 'components' => 'themes' ) );
			},
			'A component without archives cannot be downloaded'
		);
		$this->assertRejected(
			function () use ( $backup ) {
				BackupPlus_CLI_Download::entities( $backup, array( 'type' => 'everything' ) );
			},
			'An unknown type is rejected'
		);
	}

	public function test_download_point_drops_later_increments(): void {
		$backup = array(
			'plugins'          => array( 'base.zip', 'one.zip', 'two.zip' ),
			'incremental_sets' => array(
				100 => array( 'plugins' => array( 0 => 'base.zip' ) ),
				200 => array( 'plugins' => array( 1 => 'one.zip' ) ),
				300 => array( 'plugins' => array( 2 => 'two.zip' ) ),
			),
		);
		$result = BackupPlus_CLI_Download::point( $backup, '200' );
		$this->assertSame(
			array(
				0 => 'base.zip',
				1 => 'one.zip',
			),
			$result['plugins'],
			'Increments after the point are not downloaded'
		);
		$this->assertRejected(
			function () use ( $backup ) {
				BackupPlus_CLI_Download::point( $backup, '250' );
			},
			'A point outside the chain is rejected'
		);
	}

	public function test_input_accepts_only_json_objects(): void {
		$file = $this->directory . '/input.json';
		file_put_contents( $file, '{"host":"db"}' );
		$this->assertSame( array( 'host' => 'db' ), BackupPlus_CLI_Input::object( $file ), 'An object is read' );
		foreach ( array( '[1,2]', '"text"', '{broken', '' ) as $contents ) {
			file_put_contents( $file, $contents );
			$this->assertRejected(
				function () use ( $file ) {
					BackupPlus_CLI_Input::object( $file );
				},
				'Only a JSON object is accepted: ' . $contents
			);
		}
		$this->assertRejected(
			function () {
				BackupPlus_CLI_Input::object( $this->directory . '/missing.json' );
			},
			'A missing file is rejected'
		);
	}

	public function test_written_files_are_private_and_never_overwritten_silently(): void {
		$file = $this->directory . '/export.json';
		BackupPlus_CLI_Input::write( $file, 'first', false );
		$this->assertSame( '0600', substr( sprintf( '%o', fileperms( $file ) ), -4 ), 'Exports are owner-only' );
		$this->assertRejected(
			function () use ( $file ) {
				BackupPlus_CLI_Input::write( $file, 'second', false );
			},
			'An existing file is not overwritten without --force'
		);
		$this->assertSame( 'first', file_get_contents( $file ), 'The refused write leaves the file intact' );
		BackupPlus_CLI_Input::write( $file, 'second', true );
		$this->assertSame( 'second', file_get_contents( $file ), '--force replaces the file' );
		$this->assertFileDoesNotExist( $file . '.part', 'No partial file remains' );
	}

	private function history(): array {
		$now = 1800000000;
		return array(
			$now - 1 * 86400  => array(),
			$now - 5 * 86400  => array(),
			$now - 10 * 86400 => array( 'always_keep' => true ),
			$now - 20 * 86400 => array(),
			$now - 30 * 86400 => array(),
		);
	}

	public function test_bulk_delete_selects_by_age_and_count_without_touching_protected_chains(): void {
		$now       = 1800000000;
		$history   = $this->history();
		$never     = function () {
			return false;
		};
		$protected = $now - 20 * 86400;
		$busy      = function ( $timestamp ) use ( $protected ) {
			return (int) $timestamp === $protected;
		};

		$plan = BackupPlus_CLI_Prune::select( $history, array(), array( 'older-than' => '7' ), $now, $never );
		$this->assertSame( array( (string) ( $now - 20 * 86400 ), (string) ( $now - 30 * 86400 ) ), $plan['delete'], 'Age selects older chains, newest first' );
		$this->assertSame( array( (string) ( $now - 10 * 86400 ) => 'kept with keep' ), $plan['skipped'], 'A protected chain is skipped, not deleted' );

		$plan = BackupPlus_CLI_Prune::select( $history, array(), array( 'keep' => '3' ), $now, $never );
		$this->assertSame( array( (string) ( $now - 20 * 86400 ), (string) ( $now - 30 * 86400 ) ), $plan['delete'], 'Count keeps the newest chains' );

		$plan = BackupPlus_CLI_Prune::select(
			$history,
			array(),
			array(
				'older-than' => '7',
				'keep'       => '4',
			),
			$now,
			$never
		);
		$this->assertSame( array( (string) ( $now - 30 * 86400 ) ), $plan['delete'], 'Both filters must match' );

		$plan = BackupPlus_CLI_Prune::select( $history, array(), array( 'older-than' => '7' ), $now, $busy );
		$this->assertSame( array( (string) ( $now - 30 * 86400 ) ), $plan['delete'], 'An active chain is not selected' );
		$this->assertArrayHasKey( (string) $protected, $plan['skipped'], 'An active chain is reported as skipped' );
	}

	public function test_bulk_delete_rejects_ambiguous_or_invalid_selection(): void {
		$never   = function () {
			return false;
		};
		$history = $this->history();
		$known   = (string) array_key_first( $history );
		foreach ( array(
			'nothing to select'     => array( array(), array() ),
			'timestamps and filter' => array( array( $known ), array( 'keep' => '1' ) ),
			'unknown timestamp'     => array( array( '123' ), array() ),
			'zero keep'             => array( array(), array( 'keep' => '0' ) ),
			'negative age'          => array( array(), array( 'older-than' => '-1' ) ),
			'fractional age'        => array( array(), array( 'older-than' => '1.5' ) ),
		) as $label => $case ) {
			$this->assertRejected(
				function () use ( $history, $case, $never ) {
					BackupPlus_CLI_Prune::select( $history, $case[0], $case[1], 1800000000, $never );
				},
				$label
			);
		}
		$plan = BackupPlus_CLI_Prune::select( $history, array( $known, $known ), array(), 1800000000, $never );
		$this->assertSame( array( $known ), $plan['delete'], 'Explicit timestamps are unique' );
	}
}
