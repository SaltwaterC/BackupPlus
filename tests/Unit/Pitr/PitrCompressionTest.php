<?php
/** Lossless representation, legacy compatibility, bounded decoding and corruption failures. */
namespace BackupPlus\Tests\Unit\Pitr;

use BackupPlus\Tests\Support\PitrTestCase;
use BackupPlus_PITR_Compression as Compression;
use BackupPlus_PITR_Storage as Storage;
use BackupPlus_PITR_Process as Process;
use BackupPlus_PITR_Binlog as Binlog;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class PitrCompressionTest extends PitrTestCase {
	private Storage $storage;
	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/pitr-compression-stubs.php';
		require_once dirname( __DIR__, 3 ) . '/includes/class-pitr-process.php';
		require_once dirname( __DIR__, 3 ) . '/includes/class-pitr-manifest.php';
		require_once dirname( __DIR__, 3 ) . '/includes/class-pitr-binlog.php';
		require_once dirname( __DIR__, 3 ) . '/includes/class-pitr-storage.php';
		require_once dirname( __DIR__, 3 ) . '/includes/pitr/trait-recovery-plan.php';
		$GLOBALS['pitr_test_zstd'] = null;
		$GLOBALS['backupplus']     = new class( $this->directory ) {
			public function __construct( public string $directory ) {}
			public function backups_dir_location() {
				return $this->directory; }
		};
		$this->storage             = new Storage( $this->directory, array(), array() );
		$this->assertNotFalse( Compression::zstd_binary(), 'The test prerequisites require zstd.' );
	}
	private function artifact_name( string $extension ): string {
		return 'backup_pitr_' . str_repeat( 'a', 32 ) . '_' . str_repeat( 'b', 32 ) . '_log0.bin.' . $extension;
	}
	private function input(): string {
		$path   = $this->directory . '/input';
		$handle = fopen( $path, 'wb' );
		$block  = random_bytes( 65536 );
		for ( $i = 0; $i < 64; ++$i ) { fwrite( $handle, $block ); }
		fclose( $handle );
		return $path;
	}
	private function unpack( array $artifact ): string {
		$worker = new class( $this->directory ) {
			use \BackupPlus_PITR_Orchestrator_Recovery_Plan { unpack as public decode; }
			public function __construct( public string $work ) {}
		};
		return $worker->decode( $this->directory . '/' . $artifact['name'], $artifact );
	}
	public function test_long_range_compression_retains_exact_bytes_with_a_smaller_archive(): void {
		$input = $this->input();
		$gzip  = $this->storage->artifact( $input, $this->artifact_name( 'gz' ) );
		$zstd  = $this->storage->artifact( $input, $this->artifact_name( 'zst' ) );
		$this->assertSame( 'zstd', $zstd['compression'] );
		$this->assertLessThan( $gzip['size'] / 20, $zstd['size'] );
		foreach ( array( $gzip, $zstd ) as $artifact ) {
			$this->assertSame( hash_file( 'sha256', $input ), hash_file( 'sha256', $this->unpack( $artifact ) ) );
			$this->assertSame( filesize( $input ), $artifact['raw_size'] );
			$this->assertTrue( $this->storage->verify_file( $artifact ) );
		}
	}
	public function test_decoding_preserves_log_checksums_coordinates_and_ancestry(): void {
		$source   = $this->standard_log();
		$artifact = $this->storage->artifact( $source['path'], $this->artifact_name( 'zst' ) );
		$restored = $this->unpack( $artifact );
		$this->assertSame( Binlog::inspect( $source['path'], $source['start'], 'wordpress', 'mariadb' ), Binlog::inspect( $restored, $source['start'], 'wordpress', 'mariadb' ) );
		$this->assertSame( Binlog::prefix_hash( $source['path'], $source['committed'] ), Binlog::prefix_hash( $restored, $source['committed'] ) );
	}
	public function test_legacy_archives_still_restore_when_zstandard_is_unavailable(): void {
		$input                     = $this->input();
		$gzip                      = $this->storage->artifact( $input, $this->artifact_name( 'gz' ) );
		$zstd                      = $this->storage->artifact( $input, $this->artifact_name( 'zst' ) );
		$GLOBALS['pitr_test_zstd'] = false;
		$this->assertSame( 'gz', Compression::binlog_extension() );
		$this->assertSame( $gzip['raw_sha256'], hash_file( 'sha256', $this->unpack( $gzip ) ) );
		$error = $this->assertRejected( fn() => $this->unpack( $zstd ), 'Missing zstd must not attempt a partial restore.' );
		$this->assertStringContainsString( 'Install zstd', $error->getMessage() );
	}
	public function test_truncated_zstandard_archive_is_rejected(): void {
		$input    = $this->input();
		$artifact = $this->storage->artifact( $input, $this->artifact_name( 'zst' ) );
		$path     = $this->directory . '/' . $artifact['name'];
		$handle   = fopen( $path, 'r+b' );
		ftruncate( $handle, filesize( $path ) - 1 );
		fclose( $handle );
		$this->assertRejected( fn() => $this->unpack( $artifact ), 'A truncated frame must fail.' );
		$this->assertSame( array(), glob( $this->directory . '/unpacked-*' ) );
	}
	public function test_output_cannot_exceed_its_declared_size(): void {
		$artifact             = $this->storage->artifact( $this->input(), $this->artifact_name( 'zst' ) );
		$artifact['raw_size'] = 1;
		$error                = $this->assertRejected( fn() => $this->unpack( $artifact ), 'Expansion must remain bounded.' );
		$this->assertStringContainsString( 'exceeded its declared size', $error->getMessage() );
		$this->assertSame( array(), glob( $this->directory . '/unpacked-*' ) );
	}
	public function test_raw_hash_mismatch_is_rejected_after_lossless_decoding(): void {
		$artifact               = $this->storage->artifact( $this->input(), $this->artifact_name( 'zst' ) );
		$artifact['raw_sha256'] = str_repeat( '0', 64 );
		$error                  = $this->assertRejected( fn() => $this->unpack( $artifact ), 'An unexpected raw hash must fail.' );
		$this->assertStringContainsString( 'verification failed', $error->getMessage() );
	}
	public function test_decoder_rejects_frames_requiring_an_excessive_window(): void {
		$input = $this->input();
		$path  = $this->directory . '/large-window.zst';
		Process::run( array( Compression::zstd_binary(), '--long=27', '--single-thread', '--stdout' ), $this->directory, $input, $path );
		$this->assertRejected( fn() => Compression::decompress_zstd( $path, $this->directory . '/too-large', $this->directory, filesize( $input ) ), 'A frame outside the decoder memory limit must fail.' );
		$this->assertFileDoesNotExist( $this->directory . '/too-large' );
	}
}
