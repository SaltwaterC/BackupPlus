<?php
/** The multi-instance format of remote storage settings, including settings saved without the version marker. */

namespace BackupPlus\Tests\Unit\Providers;

use BackupPlus_Storage_Methods_Interface;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class StorageOptionsFormatTest extends TestCase {

	protected function setUp(): void {
		define( 'ABSPATH', sys_get_temp_dir() . '/' );
		eval( 'function wp_rand() { return mt_rand(); }' );
		require_once dirname( __DIR__, 3 ) . '/includes/class-storage-methods-interface.php';
	}

	private const FIELDS = array(
		'host' => '192.168.0.50',
		'user' => 'backupplus',
	);

	public function test_form_settings_without_the_version_marker_keep_their_instances(): void {
		$wrapped = BackupPlus_Storage_Methods_Interface::wrap_remote_storage_options( array( 'settings' => array( 's-1' => self::FIELDS ) ) );
		$this->assertSame(
			array(
				'version'  => 1,
				'settings' => array( 's-1' => self::FIELDS ),
			),
			$wrapped
		);
	}

	public function test_a_flat_legacy_option_set_becomes_one_instance(): void {
		$wrapped = BackupPlus_Storage_Methods_Interface::wrap_remote_storage_options( self::FIELDS );
		$this->assertSame( 1, $wrapped['version'] );
		$this->assertSame( array( self::FIELDS ), array_values( $wrapped['settings'] ) );
	}

	public function test_instances_wrapped_twice_are_unwrapped(): void {
		$saved = array(
			'version'  => 1,
			'settings' => array(
				's-outer' => array(
					'settings'         => array( 's-inner' => self::FIELDS ),
					'instance_enabled' => 1,
				),
				's-plain' => self::FIELDS,
			),
		);
		$fixed = BackupPlus_Storage_Methods_Interface::unwrap_nested_instances( $saved );
		$this->assertSame(
			array(
				'version'  => 1,
				'settings' => array(
					's-inner' => self::FIELDS + array( 'instance_enabled' => 1 ),
					's-plain' => self::FIELDS,
				),
			),
			$fixed
		);
		$this->assertSame( $fixed, BackupPlus_Storage_Methods_Interface::unwrap_nested_instances( $fixed ) );
	}
}
