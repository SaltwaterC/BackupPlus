<?php
/** Host-side integration scripts that start disposable SSH, FTP, WebDAV and TLS servers on random ports and private temp directories. */

namespace BackupPlus\Tests\Integration;

use BackupPlus\Tests\Support\ScriptTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ScriptsTest extends ScriptTestCase {

	protected static function concurrency(): int {
		return PHP_INT_MAX;
	}

	protected static function commands(): array {
		$commands = array();
		foreach ( glob( self::root() . '/scripts/integration/test-*.php' ) as $path ) {
			$commands[ basename( $path ) ] = array( array( PHP_BINARY, 'scripts/integration/' . basename( $path ) ) );
		}
		// The SSH checks take a minute one after the other. Each driver run has servers, keys and a login directory of its own, so
		// the groups of tests run side by side. The last group takes whatever the others do not, including tests added later.
		unset( $commands['test-sftp.php'] );
		$shards = array(
			'transfers over SFTP'                => '/SftpTest::test_live_transfers_resumption\w* with data set "sftp,/',
			'transfers over SCP, absolute paths' => '/SftpTest::test_live_transfers_resumption\w* with data set "scp, absolute/',
			'transfers over SCP, relative paths' => '/SftpTest::test_live_transfers_resumption\w* with data set "scp, relative/',
			'transfers over SCP, other paths'    => '/SftpTest::test_live_transfers_resumption\w* with data set "scp, (?!absolute|relative)/',
			'key files over SFTP'                => '/SftpTest::test_live_key_authentication\w* with data set "sftp,/',
			'key files over SCP'                 => '/SftpTest::test_live_key_authentication\w* with data set "scp,/',
			'all other checks'                   => '/SftpTest::test_live_(?!transfers_resumption|key_authentication)/',
		);
		foreach ( $shards as $label => $filter ) {
			$commands[ 'test-sftp.php: ' . $label ] = array( array( PHP_BINARY, 'scripts/integration/test-sftp.php' ), array( 'SSH_TEST_FILTER' => $filter ) );
		}
		// The same for the age interoperability: one run per chunk size and one for the portable output.
		unset( $commands['test-age.php'] );
		foreach ( array( '0', '1', '65536', '65537', '196625', 'portable' ) as $part ) {
			$commands[ 'test-age.php: ' . $part ] = array( array( PHP_BINARY, 'scripts/integration/test-age.php' ), array( 'AGE_PART' => $part ) );
		}
		return $commands;
	}

	#[DataProvider( 'scripts' )]
	public function test_script_passes( string $label ): void {
		$this->assertScriptPasses( $label );
	}
}
