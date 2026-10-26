<?php
/** Chromium tests of the real WordPress admin screens. Each test leases a whole test site while it runs, so with several sites they run side by side. */

namespace BackupPlus\Tests\Browser;

use BackupPlus\Tests\Support\ScriptTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ScriptsTest extends ScriptTestCase {

	/** The four point-in-time recovery cases take their own sites, whatever is left runs these. */
	protected static function concurrency(): int {
		return max( 1, count( self::sites() ) - 4 );
	}

	protected static function commands(): array {
		$commands = array();
		foreach ( array( 'test-backup-sources-browser.mjs', 'test-pre-update-browser.mjs', 'test-backup-schedule-browser.mjs', 'test-client-decryption-browser.mjs' ) as $script ) {
			$commands[ $script ] = array( array( 'node', 'scripts/' . $script ), self::site_environment() );
		}
		return $commands;
	}

	#[DataProvider( 'scripts' )]
	public function test_script_passes( string $label ): void {
		$this->assertScriptPasses( $label );
	}
}
