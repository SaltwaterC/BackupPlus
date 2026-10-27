<?php
/** Selected-table dump validation must finish before database changes. */
namespace BackupPlus\Tests\Unit\Restore;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class SelectedTableTest extends TestCase {
	public function test_binary_log_and_external_table_requests_are_rejected(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/selected-table-stubs.php';
		$options = array(
			'tables_to_restore'          => array( 'wp_posts' ),
			'include_unspecified_tables' => false,
		);
		$chain   = array(
			'pitr' => array(
				'points' => array(
					array(
						'backup_id' => 'log',
						'increment' => 1,
					),
				),
			),
		);
		foreach ( array(
			function () use ( $options, $chain ) {
				\BackupPlus_Source_Restore::preflight( array( 'db' => 'db' ), $chain, $options + array( 'backupplus_pitr_restore_point' => 'log' ) ); },
			function () use ( $options ) {
				\BackupPlus_Source_Restore::preflight( array( 'db2' => 'db2' ), array(), $options ); },
			function () {
				\BackupPlus_PITR_Restore::request( array( 'table' => 'wp_posts' ) ); },
		) as $request ) {
			try { $request();
				$this->fail( 'Unsafe selected-table request was accepted.' ); }
			catch ( \RuntimeException $error ) { $this->assertNotSame( '', $error->getMessage() ); }
		}
	}

	public function test_plain_and_compressed_dumps_require_the_exact_selected_table(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/selected-table-stubs.php';
		$path = tempnam( sys_get_temp_dir(), 'backupplus-table-' );
		$dump = "CREATE TABLE `wp_posts` (id int);\nINSERT INTO `wp_posts` VALUES (1);\nCREATE TABLE `wp_options` (id int);\n";
		try {
			file_put_contents( $path, $dump );
			$reader = new \Selected_Table_Reader( array( 'wp_options' ) );
			$this->assertTrue( $reader->validate( $path, true ) );
			$this->assertInstanceOf( \WP_Error::class, ( new \Selected_Table_Reader( array( 'wp_option' ) ) )->validate( $path, true ) );
			file_put_contents( $path, gzencode( $dump ) );
			$this->assertTrue( $reader->validate( $path, false ) );
			$this->assertInstanceOf( \WP_Error::class, ( new \Selected_Table_Reader( array( 'wp_missing' ) ) )->validate( $path, false ) );
		} finally { unlink( $path ); }
	}
}
