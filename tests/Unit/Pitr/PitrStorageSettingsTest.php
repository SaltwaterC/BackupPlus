<?php
/** The recorded hash of a destination's settings must survive changes to bookkeeping fields (enabled flag, display label) only. */

namespace BackupPlus\Tests\Unit\Pitr;

use BackupPlus_PITR_Storage;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class PitrStorageSettingsTest extends TestCase {

	private const SETTINGS = array(
		'host' => '192.168.0.50',
		'user' => 'backupplus',
		'pass' => 'secret',
		'path' => '',
	);

	protected function setUp(): void {
		define( 'ABSPATH', sys_get_temp_dir() . '/' );
		require_once dirname( __DIR__, 3 ) . '/includes/class-pitr-storage.php';
	}

	public function test_the_enabled_flag_does_not_change_the_recorded_hash(): void {
		$recorded = BackupPlus_PITR_Storage::settings_hash( self::SETTINGS );
		$this->assertSame( $recorded, BackupPlus_PITR_Storage::settings_hash( self::SETTINGS + array( 'instance_enabled' => 1 ) ) );
		$this->assertTrue( BackupPlus_PITR_Storage::settings_match( $recorded, self::SETTINGS + array( 'instance_enabled' => 0 ) ) );
	}

	public function test_renaming_the_destination_does_not_change_the_recorded_hash(): void {
		$recorded = BackupPlus_PITR_Storage::settings_hash( self::SETTINGS );
		$this->assertTrue( BackupPlus_PITR_Storage::settings_match( $recorded, self::SETTINGS + array( 'instance_label' => 'Pi' ) ) );
		$this->assertTrue(
			BackupPlus_PITR_Storage::settings_match(
				$recorded,
				self::SETTINGS + array(
					'instance_label'   => 'Renamed',
					'instance_enabled' => 1,
				)
			)
		);
	}

	public function test_hashes_recorded_with_the_flag_still_match_with_or_without_it(): void {
		$flagged = self::SETTINGS + array( 'instance_enabled' => 1 );
		$legacy  = hash( 'sha256', serialize( $flagged ) );
		$this->assertTrue( BackupPlus_PITR_Storage::settings_match( $legacy, $flagged ) );
		$this->assertTrue( BackupPlus_PITR_Storage::settings_match( $legacy, self::SETTINGS ) );
	}

	public function test_hashes_recorded_with_the_label_still_match_while_it_is_unchanged(): void {
		$labelled = self::SETTINGS + array( 'instance_label' => 'Pi' );
		$legacy   = hash( 'sha256', serialize( $labelled ) );
		$this->assertTrue( BackupPlus_PITR_Storage::settings_match( $legacy, $labelled ) );
		$this->assertTrue( BackupPlus_PITR_Storage::settings_match( $legacy, $labelled + array( 'instance_enabled' => 1 ) ) );
	}

	public function test_a_real_change_of_destination_is_still_refused(): void {
		$recorded = BackupPlus_PITR_Storage::settings_hash( self::SETTINGS );
		foreach ( array(
			'host' => '10.0.0.9',
			'user' => 'other',
			'pass' => 'changed',
			'path' => '/elsewhere',
		) as $field => $value ) {
			$this->assertFalse( BackupPlus_PITR_Storage::settings_match( $recorded, array_merge( self::SETTINGS, array( $field => $value ) ) ), $field );
		}
		$this->assertFalse( BackupPlus_PITR_Storage::settings_match( $recorded, null ) );
	}
}
