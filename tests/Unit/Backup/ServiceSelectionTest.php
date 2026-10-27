<?php
/** Every selected remote storage service is kept; none is dropped to a single one. */

namespace BackupPlus\Tests\Unit\Backup;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class ServiceSelectionTest extends TestCase {

	private function services( $input ) {
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/' );
		}
		require_once dirname( __DIR__, 3 ) . '/includes/core/trait-notifications.php';
		$object = new class() {
			use \BackupPlus_Core_Notifications;
		};
		return $object->sanitize_services( $input );
	}

	public function test_several_services_are_all_kept_once_and_in_order(): void {
		$this->assertSame( array( 's3', 'sftp' ), $this->services( array( 's3', 'sftp', 's3' ) ) );
	}

	public function test_a_comma_separated_legacy_value_is_split(): void {
		$this->assertSame( array( 'dropbox', 'sftp' ), $this->services( 'dropbox,sftp' ) );
	}

	public function test_nothing_selected_means_none(): void {
		$this->assertSame( 'none', $this->services( array( '0', '', 'none' ) ) );
		$this->assertSame( 'none', $this->services( null ) );
	}

	public function test_values_that_are_not_service_identifiers_are_dropped(): void {
		$this->assertSame( array( 'sftp' ), $this->services( array( '../x', array( 'a' ), 'sftp' ) ) );
	}
}
