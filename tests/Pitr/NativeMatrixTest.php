<?php
/** Point-in-time recovery against each MariaDB and MySQL image, with and without GTIDs. Each case starts its own database containers on random ports and runs in a test site of its own. */

namespace BackupPlus\Tests\Pitr;

use BackupPlus\Tests\Support\ScriptTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class NativeMatrixTest extends ScriptTestCase {

	/** The entries of the matrix in scripts/integration/check-pitr-native.php. */
	private const CASES = array(
		'MySQL, GTID off',
		'MySQL, GTID on',
		'MariaDB 10.11',
		'MariaDB 11.4',
	);

	protected static function concurrency(): int {
		return min( count( self::CASES ), count( self::sites() ) );
	}

	protected static function timeout(): int {
		return 3600;
	}

	protected static function commands(): array {
		$commands = array();
		foreach ( self::CASES as $index => $label ) {
			$commands[ $label ] = array( array( PHP_BINARY, 'scripts/integration/check-pitr-native.php' ), array_merge( self::site_environment(), array( 'PITR_CASE' => (string) $index ) ) );
		}
		return $commands;
	}

	#[DataProvider( 'scripts' )]
	public function test_native_matrix_passes( string $label ): void {
		$this->assertScriptPasses( $label );
	}
}
