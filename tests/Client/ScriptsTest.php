<?php
/** Node tests of the code that runs in the browser: credential export, archive decryption and the SSH key WebAssembly bridge, the last also in Chromium on a page of its own. */

namespace BackupPlus\Tests\Client;

use BackupPlus\Tests\Support\ScriptTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ScriptsTest extends ScriptTestCase {

	protected static function concurrency(): int {
		return PHP_INT_MAX;
	}

	protected static function commands(): array {
		return array(
			'test-credential-export.mjs'   => array( array( 'node', 'scripts/test-credential-export.mjs' ) ),
			'test-client-decryption.mjs'   => array( array( 'node', 'scripts/test-client-decryption.mjs' ) ),
			'test-agessh-wasm.mjs'         => array( array( 'node', 'scripts/test-agessh-wasm.mjs' ) ),
			'test-agessh-wasm-browser.mjs' => array( array( 'node', 'scripts/test-agessh-wasm-browser.mjs' ) ),
		);
	}

	#[DataProvider( 'scripts' )]
	public function test_script_passes( string $label ): void {
		$this->assertScriptPasses( $label );
	}
}
