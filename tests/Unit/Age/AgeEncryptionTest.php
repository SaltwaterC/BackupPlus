<?php
/** Multi-recipient files, chunk boundaries and fresh randomness of the age writer. */

namespace BackupPlus\Tests\Unit\Age;

use BackupPlus\Tests\Support\AgeTestCase;
use BackupPlus_Encryption;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class AgeEncryptionTest extends AgeTestCase {

	public static function chunk_boundaries(): iterable {
		yield 'empty'                      => array( '' );
		yield 'exactly one chunk'          => array( str_repeat( 'x', 65536 ) );
		yield 'one byte over a chunk'      => array( str_repeat( 'x', 65537 ) );
		yield 'several chunks and a short last one' => array( str_repeat( 'x', 196608 ) . 'last' );
	}

	#[DataProvider( 'chunk_boundaries' )]
	public function test_each_recipient_independently_decrypts_exact_chunk_boundaries( string $bytes ): void {
		$source = $this->directory . '/database.sql';
		file_put_contents( $source, $bytes );
		$encrypted = BackupPlus_Encryption::encrypt( $source, array_column( $this->fixture['keys'], 'recipient' ), 'age' );
		$this->assertNotFalse( $encrypted, 'Multi-recipient encryption must succeed' );
		foreach ( $this->fixture['keys'] as $key ) {
			$result = BackupPlus_Encryption::decrypt( $encrypted, $key['encrypted_pem'], true, $this->password );
			$this->assertIsArray( $result, 'Each recipient must independently decrypt exact chunk boundaries' );
			$this->assertSame( $bytes, file_get_contents( $result['fullpath'] ), 'Each recipient must independently decrypt exact chunk boundaries' );
			unlink( $result['fullpath'] );
		}
	}

	public function test_retries_and_repeated_encryption_generate_fresh_file_keys_and_nonces(): void {
		$source     = $this->directory . '/database.sql';
		$recipients = array_column( $this->fixture['keys'], 'recipient' );
		file_put_contents( $source, $this->plain );
		$encrypted = BackupPlus_Encryption::encrypt( $source, $recipients, 'age' );
		$first_raw = file_get_contents( $encrypted );
		$this->assertNotFalse( BackupPlus_Encryption::encrypt( $source, $recipients, 'age' ), 'Retries and repeated encryption must generate fresh file keys and nonces' );
		$this->assertNotSame( $first_raw, file_get_contents( $encrypted ), 'Retries and repeated encryption must generate fresh file keys and nonces' );
	}
}
