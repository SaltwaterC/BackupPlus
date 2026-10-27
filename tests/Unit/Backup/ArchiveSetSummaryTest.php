<?php
/** The tooltip of an entity must describe that entity's archives, not the chain of the whole backup. */

namespace BackupPlus\Tests\Unit\Backup;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class ArchiveSetSummaryTest extends TestCase {

	private function summary( array $backup, string $type, int $archives, int $size ): string {
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/' );
		}
		eval( 'function __( $text, $domain = null ) { return $text; }' );
		eval( 'class BackupPlus_Manipulation_Functions { public static function convert_numeric_size_to_text( $size ) { return $size . " B"; } }' );
		require_once dirname( __DIR__, 3 ) . '/includes/admin/trait-existing-backups.php';
		$admin  = new class() {
			use \BackupPlus_Admin_Existing_Backups;
		};
		$method = new ReflectionMethod( $admin, 'archive_set_summary' );
		return $method->invoke( $admin, $backup, $type, $archives, $size );
	}

	private function backup(): array {
		return array(
			'incremental'      => array( 'complete' => true ),
			'incremental_sets' => array(
				100 => array(
					'plugins' => array( 0 => 'full-plugins' ),
					'uploads' => array( 0 => 'full-uploads' ),
				),
				200 => array( 'plugins' => array( 0 => 'inc1-plugins' ) ),
				300 => array( 'plugins' => array( 0 => 'inc2-plugins' ) ),
			),
			'plugins-size'     => 10,
			'uploads-size'     => 3,
		);
	}

	public function test_an_entity_without_increments_is_not_described_as_a_chain(): void {
		$summary = $this->summary( $this->backup(), 'uploads', 1, 3 );
		$this->assertStringContainsString( 'in set', $summary );
		$this->assertStringNotContainsString( 'chain', $summary );
	}

	public function test_an_entity_with_increments_counts_only_its_own(): void {
		$summary = $this->summary( $this->backup(), 'plugins', 3, 30 );
		$this->assertStringContainsString( 'in chain', $summary );
		$this->assertStringContainsString( '2 incremental', $summary );
	}
}
