<?php
/** The pinned C2SP age test kit: every vector of the supported recipient types, none of them may release unauthenticated plaintext. */

namespace BackupPlus\Tests\Unit\Age;

use BackupPlus\Tests\Support\AgeTestCase;
use BackupPlus_Age_Crypto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Throwable;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class AgeTestkitTest extends AgeTestCase {

	protected static function runs_portable(): bool {
		return true;
	}

	private static function directory(): string {
		return self::root() . '/scripts/fixtures/age-testkit/testdata';
	}

	/** Metadata lines (key => values) and the ciphertext of a vector file, without validating either. */
	private static function split( string $raw ): array {
		$parts    = explode( "\n\n", $raw, 2 );
		$metadata = array();
		foreach ( explode( "\n", $parts[0] ) as $line ) {
			$separator = strpos( $line, ': ' );
			if ( false !== $separator ) {
				$metadata[ substr( $line, 0, $separator ) ][] = substr( $line, $separator + 2 );
			}
		}
		return array( $metadata, $parts[1] ?? '' );
	}

	/** The vectors this implementation supports. Armor, scrypt and post-quantum vectors are out of scope for PHP. */
	public static function vectors(): iterable {
		$paths = glob( self::directory() . '/*' );
		foreach ( is_array( $paths ) ? $paths : array() as $vector_path ) {
			list($metadata) = self::split( file_get_contents( $vector_path ) );
			if ( isset( $metadata['armored'] ) && 'yes' === $metadata['armored'][0] ) {
				continue;
			}
			if ( isset( $metadata['passphrase'] ) ) {
				continue;
			}
			foreach ( $metadata['identity'] ?? array() as $identity ) {
				if ( 0 === strpos( $identity, 'AGE-SECRET-KEY-PQ-' ) ) {
					continue 2;
				}
			}
			$name = basename( $vector_path );
			yield $name => array( $name );
		}
	}

	/** The metadata and ciphertext of a vector, validated. */
	private function vector( string $raw, string $name ): array {
		$parts = explode( "\n\n", $raw, 2 );
		$this->assertSame( 2, count( $parts ), 'Test kit vector must have metadata and ciphertext: ' . $name );
		$metadata = array();
		foreach ( explode( "\n", $parts[0] ) as $line ) {
			$separator = strpos( $line, ': ' );
			$this->assertNotFalse( $separator, 'Invalid test kit metadata in ' . $name );
			$key                = substr( $line, 0, $separator );
			$metadata[ $key ][] = substr( $line, $separator + 2 );
		}
		return array( $metadata, $parts[1] );
	}

	/** @return array{0: bool, 1: string} Whether decryption failed, and the plaintext that was released anyway. */
	private function decrypt( string $raw, string $identity ): array {
		$input  = fopen( 'php://temp', 'w+b' );
		$output = fopen( 'php://temp', 'w+b' );
		$this->assertTrue( is_resource( $input ) && is_resource( $output ), 'Could not create age test kit streams' );
		$this->assertSame( strlen( $raw ), fwrite( $input, $raw ), 'Could not stage age test kit vector' );
		rewind( $input );
		$failed = false;
		try {
			BackupPlus_Age_Crypto::decrypt( $input, $output, $identity );
		} catch ( Throwable $e ) {
			$failed = true;
		}
		rewind( $output );
		$plaintext = stream_get_contents( $output );
		fclose( $input );
		fclose( $output );
		return array( $failed, $plaintext );
	}

	public function test_the_pinned_test_kit_is_present(): void {
		$vectors = glob( self::directory() . '/*' );
		$this->assertTrue( is_array( $vectors ) && $vectors, 'The pinned age test kit must be present' );
		$this->assertNotEmpty( iterator_to_array( self::vectors() ), 'The pinned age test kit must contain supported vectors' );
	}

	#[DataProvider( 'vectors' )]
	public function test_vector_is_handled_as_the_kit_expects( string $name ): void {
		list($metadata, $ciphertext) = $this->vector( file_get_contents( self::directory() . '/' . $name ), $name );
		$expected                    = $metadata['expect'][0] ?? '';
		$identities                  = $metadata['identity'] ?? array( $this->fixture['keys']['native']['identity'] );
		if ( isset( $metadata['compressed'][0] ) && 'zlib' === $metadata['compressed'][0] ) {
			$ciphertext = gzuncompress( $ciphertext );
			$this->assertNotFalse( $ciphertext, 'Could not inflate age test kit vector ' . $name );
		}
		if ( 'success' === $expected ) {
			$decrypted = false;
			foreach ( $identities as $identity ) {
				list($failed, $output) = $this->decrypt( $ciphertext, $identity );
				if ( ! $failed ) {
					$this->assertTrue( isset( $metadata['payload'][0] ) && strtolower( $metadata['payload'][0] ) === hash( 'sha256', $output ), 'Age test kit plaintext hash mismatch: ' . $name );
					$decrypted = true;
					break;
				}
			}
			$this->assertTrue( $decrypted, 'Age test kit vector should decrypt: ' . $name );
		} elseif ( 'payload failure' === $expected ) {
			$partial_payload_matches = false;
			foreach ( $identities as $identity ) {
				list($failed, $output) = $this->decrypt( $ciphertext, $identity );
				$this->assertTrue( $failed, 'Age test kit payload failure must reject: ' . $name );
				if ( isset( $metadata['payload'][0] ) && strtolower( $metadata['payload'][0] ) === hash( 'sha256', $output ) ) {
					$partial_payload_matches = true;
				}
			}
			$this->assertTrue( $partial_payload_matches, 'Age test kit partial plaintext hash mismatch: ' . $name );
		} else {
			$this->assertContains( $expected, array( 'no match', 'HMAC failure', 'header failure' ), 'Unknown age test kit expectation: ' . $name );
			foreach ( $identities as $identity ) {
				list($failed, $output) = $this->decrypt( $ciphertext, $identity );
				$this->assertTrue( $failed, 'Age test kit failure must not release plaintext: ' . $name );
				$this->assertSame( '', $output, 'Age test kit failure must not release plaintext: ' . $name );
			}
		}
	}
}
