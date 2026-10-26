<?php
/** SEC-002: original UpdraftPlus CBC reads, age-only writes and failure preservation. */

namespace BackupPlus\Tests\Unit\Encryption;

use BackupPlus_Age_Crypto;
use BackupPlus_Crypto;
use BackupPlus_Encryption;
use Composer\InstalledVersions;
use CryptoShortIO;
use CryptoTestBackupPlus;
use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class BackupEncryptionTest extends TestCase {

	private CryptoTestBackupPlus $backupplus;

	private string $fixture;

	/** @var array<string, mixed> */
	private array $vectors;

	/** @var array<string, mixed> */
	private array $age;

	protected function setUp(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/encryption-stubs.php';
		CryptoShortIO::reset();
		$this->backupplus      = new CryptoTestBackupPlus();
		$GLOBALS['backupplus'] = $this->backupplus;
		$this->backupplus->ensure_phpseclib();
		$this->vectors = self::vectors();
		$this->age     = json_decode( file_get_contents( BACKUPPLUS_DIR . '/scripts/fixtures/age-keys.json' ), true );
		$this->fixture = sys_get_temp_dir() . '/backupplus-crypto-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->fixture );
	}

	protected function tearDown(): void {
		if ( in_array( 'cryptoshort', stream_get_wrappers(), true ) ) {
			stream_wrapper_unregister( 'cryptoshort' );
		}
		foreach ( glob( $this->fixture . '/*' ) as $file ) {
			if ( is_dir( $file ) ) {
				rmdir( $file );
			} else {
				unlink( $file );
			}
		}
		rmdir( $this->fixture );
	}

	/** These vectors were created with v2 before its removal, rather than by the code under test. */
	private static function vectors(): array {
		return json_decode( file_get_contents( dirname( __DIR__, 3 ) . '/scripts/fixtures/phpseclib2-compat.json' ), true );
	}

	/** @return iterable<string, array{0: int}> */
	public static function database_vectors(): iterable {
		foreach ( array_keys( self::vectors()['database'] ) as $index ) {
			yield 'vector ' . $index => array( $index );
		}
	}

	/** The saved large legacy fixture, reconstructed. */
	private function legacy_cipher() {
		$cipher = BackupPlus_Crypto::rijndael( $this->vectors['chunked']['key'] );
		$cipher->disablePadding();
		return $cipher;
	}

	public function test_the_shared_loader_provides_only_phpseclib_3(): void {
		$this->assertTrue( $this->backupplus->ensure_phpseclib(), 'The shared crypto loader must load phpseclib 3' );
		$this->assertSame( '3.0.57', InstalledVersions::getPrettyVersion( 'phpseclib/phpseclib' ), 'Composer runtime metadata must identify the bundled phpseclib version' );
		$this->assertFalse( class_exists( 'phpseclib\\Crypt\\RSA' ), 'phpseclib 2 and its aliases must be absent' );
		$this->assertFalse( class_exists( 'phpseclib_Crypt_RSA' ), 'phpseclib 2 and its aliases must be absent' );
		$installed = json_decode( file_get_contents( BACKUPPLUS_DIR . '/vendor/composer/installed.json' ), true );
		foreach ( $installed['packages'] as $package ) {
			if ( 'phpseclib/phpseclib' === $package['name'] ) {
				$this->assertStringStartsWith( '3.', $package['version'], 'Only phpseclib 3 may be bundled' );
			}
		}
	}

	public function test_backup_writing_accepts_only_age_public_recipients(): void {
		$source = $this->fixture . '/source.sql';
		file_put_contents( $source, '-- source must survive' );
		$this->assertFalse( BackupPlus_Encryption::encrypt( $source, 'removed passphrase' ), 'The default writer must not accept a passphrase' );
		$this->assertFalse( BackupPlus_Encryption::encrypt( $source, 'removed passphrase', 'password' ), 'Explicit password writes must be rejected' );
		$this->assertFalse( file_exists( $source . '.crypt' ), 'Rejected password writes must not create an archive' );
	}

	#[DataProvider( 'database_vectors' )]
	public function test_pre_migration_database_ciphertext_decrypts( int $index ): void {
		$vector = $this->vectors['database'][ $index ];
		$key    = base64_decode( $vector['key'] );
		$plain  = base64_decode( $vector['plaintext'] );
		$path   = $this->fixture . '/database-' . $index . '.sql';
		file_put_contents( $path . '.crypt', base64_decode( $vector['ciphertext'] ) );
		$decrypted = BackupPlus_Encryption::decrypt( $path . '.crypt', $key, true );
		$this->assertIsArray( $decrypted, 'Old encrypted backups must decrypt for key length ' . strlen( $key ) );
		$this->assertSame( $plain, file_get_contents( $decrypted['fullpath'] ), 'Old encrypted backups must decrypt for key length ' . strlen( $key ) );
		unlink( $decrypted['fullpath'] );
	}

	public function test_the_large_legacy_fixture_restores_exactly(): void {
		$legacy_plain = str_repeat( "-- chunked database fixture\n", 90000 ) . 'trailing data';
		$padding      = ( 16 - strlen( $legacy_plain ) % 16 ) % 16;
		$legacy_raw   = $this->legacy_cipher()->encrypt( $legacy_plain . str_repeat( chr( $padding ), $padding ) );
		$this->assertSame( $this->vectors['chunked']['ciphertext_sha256'], hash( 'sha256', $legacy_raw ), 'Reconstructed legacy fixture must match the saved pre-migration hash' );
		$path = $this->fixture . '/legacy-chunked.sql.crypt';
		file_put_contents( $path, $legacy_raw );
		$decrypted = BackupPlus_Encryption::decrypt( $path, $this->vectors['chunked']['key'], true );
		$this->assertIsArray( $decrypted, 'The large pre-migration backup must restore exactly' );
		$this->assertSame( $this->vectors['chunked']['plaintext_sha256'], hash_file( 'sha256', $decrypted['fullpath'] ), 'The large pre-migration backup must restore exactly' );
		unlink( $decrypted['fullpath'] );
	}

	/** @return iterable<string, array{0: string}> */
	public static function legacy_archive_signatures(): iterable {
		yield 'gzip' => array( "\x1f\x8b" . str_repeat( 'g', 30 ) );
		yield 'zip local header' => array( "PK\x03\x04" . str_repeat( 'z', 28 ) );
		yield 'zip end of directory' => array( "PK\x05\x06" . str_repeat( 'z', 28 ) );
	}

	/** Legacy ZIP signatures and gzip both remain readable, including aligned data. */
	#[DataProvider( 'legacy_archive_signatures' )]
	public function test_legacy_compressed_archive_signatures_restore( string $plain ): void {
		$path = $this->fixture . '/legacy-chunked.sql.crypt';
		file_put_contents( $path, $this->legacy_cipher()->encrypt( $plain ) );
		$decrypted = BackupPlus_Encryption::decrypt( $path, $this->vectors['chunked']['key'], true );
		$this->assertIsArray( $decrypted, 'Legacy compressed archive signatures must restore' );
		$this->assertSame( $plain, file_get_contents( $decrypted['fullpath'] ), 'Legacy compressed archive signatures must restore' );
		unlink( $decrypted['fullpath'] );
	}

	/** @return iterable<string, array{0: string, 1: bool}> */
	public static function invalid_legacy_inputs(): iterable {
		foreach ( array( 'wrong passphrase', 'truncated', 'empty' ) as $case ) {
			foreach ( array( false, true ) as $temporary ) {
				yield $case . ( $temporary ? ' to a temporary file' : ' to the destination' ) => array( $case, $temporary );
			}
		}
	}

	/** Failed legacy reads preserve destinations and never publish partial plaintext. */
	#[DataProvider( 'invalid_legacy_inputs' )]
	public function test_failed_legacy_reads_preserve_the_destination( string $case, bool $temporary ): void {
		$legacy       = $this->vectors['database'][8];
		$legacy_key   = base64_decode( $legacy['key'] );
		$legacy_bytes = base64_decode( $legacy['ciphertext'] );
		$raw          = array(
			'wrong passphrase' => $legacy_bytes,
			'truncated'        => substr( $legacy_bytes, 0, -1 ),
			'empty'            => '',
		)[ $case ];
		$bad_path     = $this->fixture . '/rejected.sql.crypt';
		file_put_contents( $bad_path, $raw );
		file_put_contents( $this->fixture . '/rejected.sql', 'preserve destination' );
		$this->assertFalse( BackupPlus_Encryption::decrypt( $bad_path, 'wrong passphrase' === $case ? 'wrong' : $legacy_key, $temporary ), 'Invalid legacy input must fail' );
		$this->assertSame( 'preserve destination', file_get_contents( $this->fixture . '/rejected.sql' ), 'A failure must preserve the destination' );
		$this->assertSame( array(), glob( $this->fixture . '/decrypt_*.tmp' ), 'A failed read must remove staged plaintext' );
	}

	/** Opens the short I/O streams, which return and accept only a few bytes at a time. */
	private function short_streams(): array {
		if ( ! in_array( 'cryptoshort', stream_get_wrappers(), true ) ) {
			stream_wrapper_register( 'cryptoshort', 'CryptoShortIO' );
		}
		return array( fopen( 'cryptoshort://input', 'rb' ), fopen( 'cryptoshort://output', 'wb' ) );
	}

	/** @return array{0: string, 1: string} The plaintext and its age ciphertext, written through the short streams. */
	private function short_ciphertext(): array {
		CryptoShortIO::$input   = str_repeat( "binary\0data", 100 );
		$plain                  = CryptoShortIO::$input;
		list( $input, $output ) = $this->short_streams();
		BackupPlus_Age_Crypto::encrypt( $input, $output, array( $this->age['keys']['native']['recipient'] ) );
		fclose( $input );
		fclose( $output );
		return array( $plain, CryptoShortIO::$output );
	}

	public function test_short_reads_and_writes_preserve_the_whole_stream(): void {
		list( $plain, $ciphertext ) = $this->short_ciphertext();
		CryptoShortIO::$input       = $ciphertext;
		CryptoShortIO::$output      = '';
		list( $input, $output )     = $this->short_streams();
		BackupPlus_Age_Crypto::decrypt( $input, $output, $this->age['keys']['native']['identity'] );
		$this->assertSame( $plain, CryptoShortIO::$output, 'Short reads and writes must preserve the whole stream' );
		fclose( $input );
		fclose( $output );
	}

	/** @return iterable<string, array{0: string}> */
	public static function io_failures(): iterable {
		yield 'read failure' => array( 'fail_read' );
		yield 'write failure' => array( 'fail_write' );
	}

	#[DataProvider( 'io_failures' )]
	public function test_io_failures_fail_instead_of_completing_or_looping( string $failure ): void {
		list( , $ciphertext )      = $this->short_ciphertext();
		CryptoShortIO::$input      = $ciphertext;
		CryptoShortIO::$output     = '';
		CryptoShortIO::${$failure} = true;
		list( $input, $output )    = $this->short_streams();
		$thrown                    = false;
		try {
			BackupPlus_Age_Crypto::decrypt( $input, $output, $this->age['keys']['native']['identity'] );
		} catch ( Exception $e ) {
			$thrown = true; }
		$this->assertTrue( $thrown, 'I/O failures must fail instead of completing or looping' );
		fclose( $input );
		fclose( $output );
	}

	/** A failed publication must preserve both the source and an existing destination. */
	public function test_failed_publication_preserves_the_source_and_destination(): void {
		$path = $this->fixture . '/publication.sql';
		file_put_contents( $path, '-- source must survive' );
		mkdir( $path . '.crypt' );
		set_error_handler(
			function () {
				return true;
			}
		);
		try {
			$this->assertFalse( BackupPlus_Encryption::encrypt( $path, array( $this->age['keys']['native']['recipient'] ) ), 'Failed age publication must return false' );
		} finally {
			restore_error_handler(); }
		$this->assertSame( '-- source must survive', file_get_contents( $path ), 'Failed publication must preserve source and destination' );
		$this->assertTrue( is_dir( $path . '.crypt' ), 'Failed publication must preserve source and destination' );
		rmdir( $path . '.crypt' );
		$this->assertSame( array(), glob( $this->fixture . '/encrypt_*.tmp' ), 'Failed publication must remove its staging file' );
	}

	/** @return iterable<string, array{0: string}> */
	public static function standalone_cases(): iterable {
		yield 'valid' => array( 'valid' );
		yield 'corrupt' => array( 'corrupt' );
	}

	/** Standalone age decryption withholds all plaintext on authentication failure. */
	#[DataProvider( 'standalone_cases' )]
	public function test_standalone_age_decryption_publishes_only_verified_plaintext( string $case ): void {
		$identity  = $this->age['keys']['native']['identity'];
		$reference = base64_decode( $this->age['keys']['native']['ciphertext'] );
		$raw       = 'valid' === $case ? $reference : substr( $reference, 0, -1 ) . chr( ord( substr( $reference, -1 ) ) ^ 1 );
		$path      = $this->fixture . '/standalone.sql.crypt';
		file_put_contents( $path, $raw );
		$code    = 'require ' . var_export( BACKUPPLUS_DIR . '/example-decrypt.php', true ) . '; try { rijndael_decrypt_file(' . var_export( $path, true ) . ', ' . var_export( $identity, true ) . '); } catch (Exception $e) { exit(1); }';
		$process = proc_open(
			array( PHP_BINARY, '-d', 'error_reporting=24575', '-r', $code ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		$this->assertIsResource( $process, 'Standalone decryptor process must start' );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$status = proc_close( $process );
		if ( 'valid' === $case ) {
			$this->assertSame( 0, $status, 'Standalone decryption must publish only verified age plaintext' );
			$this->assertSame( base64_decode( $this->age['plaintext'] ), $stdout, 'Standalone decryption must publish only verified age plaintext' );
		} else {
			$this->assertSame( 1, $status, 'Standalone decryption must publish only verified age plaintext' );
			$this->assertSame( '', $stdout, 'Standalone decryption must publish only verified age plaintext' );
		}
		$this->assertStringNotContainsString( $identity, $stderr, 'Standalone diagnostics must not contain the identity' );
	}

	/** The original passphrase format is also accepted by the standalone dispatcher. */
	public function test_the_original_passphrase_format_remains_readable(): void {
		$legacy = $this->vectors['database'][8];
		$path   = $this->fixture . '/standalone.sql.crypt';
		file_put_contents( $path, base64_decode( $legacy['ciphertext'] ) );
		$decrypted = BackupPlus_Encryption::decrypt( $path, base64_decode( $legacy['key'] ), true );
		$this->assertIsArray( $decrypted, 'The original passphrase format must remain readable' );
		$this->assertSame( base64_decode( $legacy['plaintext'] ), file_get_contents( $decrypted['fullpath'] ), 'The original passphrase format must remain readable' );
		unlink( $decrypted['fullpath'] );
	}
}
