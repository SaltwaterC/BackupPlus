<?php
/** Decryption must fail closed: wrong keys, any changed byte, truncation, reordering and unknown formats release nothing. */

namespace BackupPlus\Tests\Unit\Age;

use BackupPlus\Tests\Support\AgeTestCase;
use BackupPlus_Age_Crypto;
use BackupPlus_Encryption;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class AgeTamperTest extends AgeTestCase {

	private string $reference = '';

	protected function setUp(): void {
		parent::setUp();
		$this->reference = base64_decode( $this->native['ciphertext'] );
	}

	public function test_wrong_identities_fail(): void {
		$this->assertRejected( $this->reference, $this->fixture['keys']['ed25519']['identity'], $this->password, 'Wrong identities must fail' );
	}

	public function test_wrong_pem_passwords_fail(): void {
		$this->assertRejected( $this->reference, $this->native['encrypted_pem'], 'wrong password sentinel', 'Wrong PEM passwords must fail' );
	}

	public function test_wrong_openssh_passwords_fail(): void {
		$this->assertRejected( base64_decode( $this->fixture['keys']['ed25519']['ciphertext'] ), $this->fixture['keys']['ed25519']['identity'], 'wrong password sentinel', 'Wrong OpenSSH passwords must fail' );
	}

	/** Heavy: one decryption for every byte of the payload, plus a few in the header. */
	public function test_any_changed_header_nonce_ciphertext_or_tag_byte_fails(): void {
		$payload_start = strpos( $this->reference, "\n", strpos( $this->reference, '--- ' ) ) + 1;
		foreach ( array_merge( array( 0, 17, 19, 24, 40, 80, $payload_start - 10 ), range( $payload_start, strlen( $this->reference ) - 1 ) ) as $offset ) {
			$changed            = $this->reference;
			$changed[ $offset ] = chr( ord( $changed[ $offset ] ) ^ 1 );
			$this->assertRejected( $changed, $this->native['identity'], '', 'Header/nonce/ciphertext/tag changes must fail at byte ' . $offset );
		}
	}

	public function test_truncation_fails(): void {
		$this->assertRejected( substr( $this->reference, 0, -1 ), $this->native['identity'], '', 'Truncation must fail' );
	}

	public function test_trailing_data_fails(): void {
		$this->assertRejected( $this->reference . 'extra', $this->native['identity'], '', 'Trailing data must fail' );
	}

	public function test_oversized_headers_fail(): void {
		$this->assertRejected( BackupPlus_Age_Crypto::MAGIC . str_repeat( 'x', 70000 ), $this->native['identity'], '', 'Oversized headers must fail' );
	}

	public function test_unknown_versions_fail(): void {
		$this->assertRejected( str_replace( "/v1\n", "/v2\n", $this->reference ), $this->native['identity'], '', 'Unknown versions must fail' );
	}

	/** Four chunks: the header, then chunks of 65536 bytes plus the 16 byte tag. */
	public static function reordered_chunks(): iterable {
		yield 'swapped'              => array( array( 1, 0, 2, 3 ), false );
		yield 'duplicated'           => array( array( 0, 0, 1, 2, 3 ), false );
		yield 'removed'              => array( array( 0, 2, 3 ), false );
		yield 'final chunk removed'  => array( array( 0, 1, 2 ), true );
	}

	#[DataProvider( 'reordered_chunks' )]
	public function test_chunk_order_duplication_removal_and_completion_authenticate( array $order, bool $cut_at_chunk_boundary ): void {
		$source = $this->directory . '/database.sql';
		file_put_contents( $source, str_repeat( 'x', 196608 ) . 'last' );
		$encrypted = BackupPlus_Encryption::encrypt( $source, array_column( $this->fixture['keys'], 'recipient' ), 'age' );
		$this->assertNotFalse( $encrypted, 'Multi-recipient encryption must succeed' );
		$raw    = file_get_contents( $encrypted );
		$start  = strpos( $raw, "\n", strpos( $raw, '--- ' ) ) + 1 + 16;
		$header = substr( $raw, 0, $start );
		$chunks = str_split( substr( $raw, $start ), 65552 );
		$this->assertCount( 4, $chunks, 'The tampering vectors need four chunks' );
		$changed = $cut_at_chunk_boundary ? substr( $raw, 0, -strlen( $chunks[3] ) ) : $header . implode( '', array_map( fn( $index ) => $chunks[ $index ], $order ) );
		$this->assertRejected( $changed, $this->native['identity'], '', 'Chunk order, duplication, removal and completion must authenticate' );
	}
}
