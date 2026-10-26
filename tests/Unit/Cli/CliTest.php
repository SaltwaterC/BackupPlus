<?php
/** CLI selection, worker evidence and targeted event tests without WordPress. */

namespace BackupPlus\Tests\Unit\Cli;

use BackupPlus\Tests\Support\CliTestCase;
use BackupPlus_Backup_Sources;
use BackupPlus_CLI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use WP_CLI;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class CliTest extends CliTestCase {

	private const NONCE = 'abcdefabcdef';

	private string $selection_file = '';

	protected function setUp(): void {
		parent::setUp();
		$this->selection_file = $this->directory . '/selection.json';
		file_put_contents( $this->directory . '/source.txt', 'standalone' );
		$this->options['backupplus_include_more_path']  = array( $this->directory . '/source.txt' );
		$this->options['backupplus_include_more']       = true;
		$this->options['backupplus_database_selection'] = array(
			'wp' => array(
				'enabled' => true,
				'mode'    => 'selected',
				'tables'  => array( 'wp_options' ),
			),
		);
	}

	public function test_default_table_coverage_uses_saved_selections(): void {
		$plan = BackupPlus_CLI::selection( array() );
		$this->assertSame( array( 'wp_options' ), $plan['options']['database_plan'][1]['wp']['tables'], 'Default table coverage uses saved selections' );
	}

	public function test_explicit_files_and_tables_override_the_saved_selection_without_writing_settings(): void {
		$before = $this->options;
		$plan   = BackupPlus_CLI::selection(
			array(
				'files'  => 'wpcore,custom',
				'tables' => 'wp_posts',
			)
		);
		$this->assertCount( 2, $plan['entities'], 'Core and standalone custom sources expand' );
		$this->assertContains( 'wpcore', $plan['entities'], 'Core and standalone custom sources expand' );
		$this->assertSame( array( 'wp_posts' ), $plan['options']['database_plan'][1]['wp']['tables'], 'Exact table restriction overrides only WordPress' );
		$this->assertSame( $before, $this->options, 'Per-run selection never writes settings' );
	}

	public static function invalid_selectors(): iterable {
		foreach ( array(
			array( 'type' => 'invalid' ),
			array(
				'type'        => 'database',
				'incremental' => true,
			),
			array(
				'type'   => 'files',
				'tables' => 'wp_posts',
			),
			array(
				'type'  => 'database',
				'files' => 'plugins',
			),
			array( 'files' => 'unknown' ),
			array( 'files' => 'plugins,' ),
			array( 'tables' => '' ),
			array( 'tables' => 'missing' ),
			array(
				'selection' => 'selection.json',
				'files'     => 'plugins',
			),
		) as $invalid ) {
			yield json_encode( $invalid ) => array( $invalid );
		}
	}

	#[DataProvider( 'invalid_selectors' )]
	public function test_invalid_selectors_fail( array $invalid ): void {
		$message = 'Invalid selectors must fail: ' . json_encode( $invalid );
		if ( isset( $invalid['selection'] ) ) {
			$invalid['selection'] = $this->selection_file;
		}
		$this->assertRejected(
			function () use ( $invalid ) {
				BackupPlus_CLI::selection( $invalid );
			},
			$message
		);
	}

	public static function invalid_selection_files(): iterable {
		foreach ( array(
			array( 'unknown' => true ),
			array( 'files' => 'plugins' ),
			array( 'custom_paths' => array( '' ) ),
			array( 'custom_paths' => array( '/missing-source' ) ),
			array( 'custom_paths' => array( '/a/../b' ) ),
			array(
				'database_selection' => array(
					'wp' => array(
						'mode'   => 'selected',
						'tables' => array(),
					),
				),
			),
			array( 'database_selection' => array( '4' => array( 'enabled' => true ) ) ),
		) as $invalid ) {
			yield json_encode( $invalid ) => array( $invalid );
		}
	}

	#[DataProvider( 'invalid_selection_files' )]
	public function test_invalid_selection_json_fails( array $invalid ): void {
		file_put_contents( $this->selection_file, json_encode( $invalid ) );
		$this->assertRejected(
			function () {
				BackupPlus_CLI::selection( array( 'selection' => $this->selection_file ) );
			},
			'Invalid selection JSON must fail'
		);
	}

	/** The plan of an incremental files backup whose ad hoc custom path is the standalone source. */
	private function ad_hoc_plan(): array {
		file_put_contents(
			$this->selection_file,
			json_encode(
				array(
					'files'        => array( 'wpcore' ),
					'custom_paths' => array( $this->directory . '/source.txt' ),
				)
			)
		);
		return BackupPlus_CLI::selection(
			array(
				'selection'   => $this->selection_file,
				'type'        => 'files',
				'incremental' => true,
			)
		);
	}

	public function test_ad_hoc_paths_join_the_selected_files(): void {
		$plan = $this->ad_hoc_plan();
		$this->assertCount( 2, $plan['entities'], 'Ad hoc paths join the selected files' );
		$this->assertTrue( $plan['options']['incremental'], 'Ad hoc paths join the selected files' );
	}

	public function test_retry_source_paths_and_registry_stay_frozen(): void {
		$plan                  = $this->ad_hoc_plan();
		$this->backupplus->job = array(
			'file_sources'      => $plan['options']['file_sources'],
			'file_entity_paths' => $plan['options']['file_entity_paths'],
		);
		$this->options['backupplus_include_more_path'] = array( '/changed-after-start' );
		$this->assertSame( BackupPlus_Backup_Sources::sources(), $plan['options']['file_sources'], 'Retry source paths stay frozen' );
		$this->assertSame( $this->backupplus->get_backupable_file_entities(), $plan['options']['file_entity_paths'], 'Retry registry stays frozen' );
	}

	/** A cron event as WordPress stores it. */
	private function event( string $hook, array $args ): array {
		return array( $hook => array( md5( serialize( $args ) ) => array( 'args' => $args ) ) );
	}

	/** The job has an open request, so finished() records its result. */
	private function request_job(): void {
		$this->options[ BackupPlus_CLI::REQUEST . self::NONCE ] = array( 'test' => true );
	}

	/** Records the terminal evidence of the job as the worker would. */
	private function finish( string $outcome, array $diagnostics ): void {
		BackupPlus_CLI::finished( self::NONCE, $outcome, array( 'backup_time' => 10 ), $diagnostics );
	}

	public function test_only_the_requested_job_events_are_driven(): void {
		$this->cron = array( time() - 1 => array_merge( $this->event( 'backupplus_backup_resume', array( 1, self::NONCE ) ), $this->event( 'unrelated', array() ) ) );
		$this->request_job();
		$this->assertCount( 1, BackupPlus_CLI::events( self::NONCE ), 'Only requested job events are driven' );
	}

	public function test_a_retry_completes_using_the_canonical_base_timestamp(): void {
		$this->cron = array( time() - 1 => array_merge( $this->event( 'backupplus_backup_resume', array( 1, self::NONCE ) ), $this->event( 'unrelated', array() ) ) );
		$this->request_job();
		$result = BackupPlus_CLI::wait( self::NONCE );
		$this->assertSame( 7, $result['timestamp'], 'A retry completes using the canonical base timestamp' );
		$this->assertSame( 1, WP_CLI::$runs, 'A retry completes using the canonical base timestamp' );
	}

	public function test_ordinary_warning_only_completion_succeeds(): void {
		$this->request_job();
		$this->finish(
			'failed',
			array(
				'errors'   => 0,
				'warnings' => 1,
				'messages' => array(),
			)
		);
		$this->assertSame( 10, BackupPlus_CLI::wait( self::NONCE )['timestamp'], 'Ordinary warning-only completion succeeds' );
	}

	public function test_terminal_errors_fail(): void {
		$this->request_job();
		$this->finish(
			'failed',
			array(
				'errors'   => 1,
				'warnings' => 0,
			)
		);
		$this->assertRejected(
			function () {
				BackupPlus_CLI::wait( self::NONCE );
			},
			'Terminal errors must fail'
		);
	}

	public function test_aborts_fail(): void {
		$this->request_job();
		$this->finish(
			'aborted',
			array(
				'errors'   => 0,
				'warnings' => 0,
			)
		);
		$this->assertRejected(
			function () {
				BackupPlus_CLI::wait( self::NONCE );
			},
			'Aborts must fail'
		);
	}

	public function test_a_successful_worker_exit_without_evidence_does_not_succeed(): void {
		$this->request_job();
		$this->assertRejected(
			function () {
				BackupPlus_CLI::wait( self::NONCE );
			},
			'A successful worker exit without evidence must not succeed'
		);
	}

	public function test_batch_finalization_is_specific_to_the_requested_batch(): void {
		$this->cron = array( time() => array_merge( $this->event( 'backupplus_pre_update_finalize', array( self::NONCE, 'success', array( 'pre_update_batch' => 'batch-a' ) ) ), $this->event( 'backupplus_pre_update_run', array( 'batch-b' ) ) ) );
		$this->assertCount( 1, BackupPlus_CLI::events( self::NONCE, 'batch-a' ), 'Batch finalization is specific to the requested batch' );
	}

	public function test_invalid_submissions_cannot_partially_write_options(): void {
		$before = $this->options;
		$this->assertRejected(
			function () {
				BackupPlus_CLI::save_settings(
					array(
						'backupplus_interval'             => 'weekly',
						'backupplus_schedule_exact_files' => 1,
						'backupplus_starttime_files'      => '25:00',
						'backupplus_retain'               => 9,
					)
				);
			},
			'Invalid proposed settings must fail'
		);
		$this->assertSame( $before, $this->options, 'Invalid submissions cannot partially write options' );
	}
}
