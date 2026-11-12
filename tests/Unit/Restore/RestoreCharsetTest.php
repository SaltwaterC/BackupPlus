<?php
/** The restore character set and collation must be ones the server lists before they reach DDL (BPS-17). */
namespace BackupPlus\Tests\Unit\Restore;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class RestoreCharsetTest extends TestCase {
	private function server(): array {
		$charsets   = array(
			'utf8mb4' => (object) array( 'Charset' => 'utf8mb4' ),
			'latin1'  => (object) array( 'Charset' => 'latin1' ),
		);
		$collations = array(
			'utf8mb4_unicode_ci' => (object) array(
				'Collation' => 'utf8mb4_unicode_ci',
				'Charset'   => 'utf8mb4',
			),
		);
		return array( $charsets, $collations );
	}

	private function check( array $options ) {
		require_once dirname( __DIR__, 2 ) . '/Support/restore-charset-stubs.php';
		$checker = new \Restore_Charset_Checker( $options );
		$result  = $checker->validate( ...$this->server() );
		return array( $result, $checker->restore_options );
	}

	public function test_listed_values_are_replaced_by_the_server_names(): void {
		list( $result, $options ) = $this->check(
			array(
				'backupplus_restorer_charset' => 'UTF8MB4',
				'backupplus_restorer_collate' => 'utf8mb4_UNICODE_ci',
			)
		);
		$this->assertTrue( $result );
		$this->assertSame( 'utf8mb4', $options['backupplus_restorer_charset'] );
		$this->assertSame( 'utf8mb4_unicode_ci', $options['backupplus_restorer_collate'] );
	}

	public function test_absent_values_and_the_per_table_default_are_kept(): void {
		list( $result, $options ) = $this->check( array( 'backupplus_restorer_collate' => 'choose_a_default_for_each_table' ) );
		$this->assertTrue( $result );
		$this->assertSame( array( 'backupplus_restorer_collate' => 'choose_a_default_for_each_table' ), $options );
		list( $result, $options ) = $this->check(
			array(
				'backupplus_restorer_charset' => '',
				'backupplus_restorer_collate' => '',
			)
		);
		$this->assertTrue( $result );
		$this->assertSame( '', $options['backupplus_restorer_charset'] );
	}

	public function test_unlisted_or_crafted_values_stop_the_restore(): void {
		foreach ( array(
			array( 'backupplus_restorer_charset' => 'utf8mb4 ENGINE=InnoDB; DROP TABLE wp_users; --' ),
			array( 'backupplus_restorer_charset' => 'klingon' ),
			array( 'backupplus_restorer_charset' => array( 'utf8mb4' ) ),
			array(
				'backupplus_restorer_charset' => 'utf8mb4',
				'backupplus_restorer_collate' => "utf8mb4_unicode_ci, password VARCHAR(9) DEFAULT 'x'",
			),
			array( 'backupplus_restorer_collate' => 'latin1' ),
		) as $options ) {
			list( $result ) = $this->check( $options );
			$this->assertInstanceOf( \WP_Error::class, $result, json_encode( $options ) );
			$this->assertSame( 'unsupported_restore_charset', $result->code );
		}
	}
}
