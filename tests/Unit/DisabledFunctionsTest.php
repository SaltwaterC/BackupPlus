<?php
/** Unit tests that need PHP functions disabled (missing cURL, OpenSSL, sodium or sockets), run in nested PHPUnit processes side by side. */

namespace BackupPlus\Tests\Unit;

use BackupPlus\Tests\Support\ScriptTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class DisabledFunctionsTest extends ScriptTestCase {

	protected static function concurrency(): int {
		return PHP_INT_MAX;
	}

	/** Each entry runs the tests that are meant for the variant again, which cannot be done from inside a running PHPUnit. */
	protected static function commands(): array {
		$commands                                = array();
		$no_sodium_aead                          = 'sodium_crypto_aead_chacha20poly1305_ietf_encrypt,sodium_crypto_aead_chacha20poly1305_ietf_decrypt';
		$commands['portable: backup encryption'] = self::phpunit_command( 'openssl_encrypt,openssl_decrypt', 'BackupEncryptionTest' );
		$commands['portable: dropbox credential encryption'] = self::phpunit_command( 'openssl_encrypt,openssl_decrypt,' . $no_sodium_aead, '/DropboxCredentialEncryptionTest::(?!test_the_envelope_interoperates)/' );
		$commands['portable: age']                           = self::phpunit_command(
			'openssl_encrypt,openssl_decrypt,' . $no_sodium_aead . ',sodium_crypto_scalarmult,sodium_crypto_box_publickey_from_secretkey',
			'Age(Testkit|Crypto)Test',
			array( 'BACKUPPLUS_TEST_PORTABLE' => '1' )
		);
		$commands['no curl: ftp']                            = self::phpunit_command( 'curl_init', '/\\\\FtpTest::(?!test_live_)/' );
		$commands['no sockets: sftp']                        = self::phpunit_command( 'fsockopen', 'SftpTest::test_(?!live_)' );
		$commands['no curl: webdav']                         = self::phpunit_command( 'curl_init', '/WebDavTest::(?!test_live_|\w*_with_curl)/' );
		$commands['no curl: cloud http']                     = self::phpunit_command( 'curl_init', 'CloudHttpTest::test_streaming_uploads_fail_clearly_without_curl' );
		return $commands;
	}

	/**
	 * A PHPUnit test that needs PHP functions disabled, which cannot be done from inside a running PHPUnit. The test must not skip.
	 * The filter must not match the labels of this class, or the nested run would start its own nested runs.
	 */
	private static function phpunit_command( string $disabled_functions, string $filter, array $environment = array() ): array {
		return array(
			array( PHP_BINARY, self::root() . '/.cache/php-tools/vendor/bin/paratest', '--configuration', self::root() . '/phpunit.xml.dist', '--testsuite', 'unit', '--functional', '--processes', '4', '--passthru-php', "'-d' 'disable_functions=" . $disabled_functions . "'", '--filter', $filter, '--exclude-group', 'live', '--fail-on-skipped' ),
			array_merge( array( 'BACKUPPLUS_NESTED_PHPUNIT' => '1' ), $environment ),
		);
	}

	#[DataProvider( 'scripts' )]
	public function test_script_passes( string $label ): void {
		if ( getenv( 'BACKUPPLUS_NESTED_PHPUNIT' ) ) {
			$this->markTestSkipped( 'A nested run only executes the tests it was filtered to.' );
		}
		$this->assertScriptPasses( $label );
	}
}
