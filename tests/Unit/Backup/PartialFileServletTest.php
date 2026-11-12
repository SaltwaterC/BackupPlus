<?php
/**
 * BPS-20: byte ranges of backup downloads follow RFC 9110. bytes=-N is the last N bytes (the whole file when N is larger than
 * the file, unsatisfiable when N is 0), a last position past the end means the rest of the file, and a range that starts after
 * the end is unsatisfiable.
 */

namespace BackupPlus\Tests\Unit\Backup;

use BackupPlus_PartialFileServlet;
use BackupPlus_RangeHeader;
use BackupPlus_UnsatisfiableRangeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class PartialFileServletTest extends TestCase {

	private const CONTENT = '0123456789';

	private string $file = '';

	protected function setUp(): void {
		define( 'ABSPATH', sys_get_temp_dir() . '/' );
		require dirname( __DIR__, 3 ) . '/includes/class-partialfileservlet.php';
		$this->file = tempnam( sys_get_temp_dir(), 'backupplus-range-' );
		file_put_contents( $this->file, self::CONTENT );
	}

	protected function tearDown(): void {
		if ( '' !== $this->file && is_file( $this->file ) ) {
			unlink( $this->file );
		}
	}

	/** Header => the bytes sent and the Content-Range, for a 10-byte file. */
	public static function satisfiable(): array {
		return array(
			'suffix'                   => array( 'bytes=-3', '789', 'bytes 7-9/10' ),
			'suffix of one byte'       => array( 'bytes=-1', '9', 'bytes 9-9/10' ),
			'suffix of the whole file' => array( 'bytes=-10', self::CONTENT, 'bytes 0-9/10' ),
			'suffix longer than file'  => array( 'bytes=-25', self::CONTENT, 'bytes 0-9/10' ),
			'open-ended'               => array( 'bytes=4-', '456789', 'bytes 4-9/10' ),
			'closed'                   => array( 'bytes=2-5', '2345', 'bytes 2-5/10' ),
			'last byte past the end'   => array( 'bytes=8-99', '89', 'bytes 8-9/10' ),
			'single byte'              => array( 'bytes=0-0', '0', 'bytes 0-0/10' ),
		);
	}

	#[DataProvider( 'satisfiable' )]
	public function test_satisfiable_ranges( string $header, string $expected, string $content_range ): void {
		$range = BackupPlus_RangeHeader::createFromHeaderString( $header );
		$this->assertSame( strlen( $expected ), $range->getLength( 10 ) );
		$this->assertSame( $content_range, $range->getContentRangeHeader( 10 ) );
		$this->assertGreaterThanOrEqual( 0, $range->getStartPosition( 10 ) );

		ob_start();
		( new BackupPlus_PartialFileServlet( $range ) )->sendFile( $this->file );
		$this->assertSame( $expected, ob_get_clean() );
	}

	public static function unsatisfiable(): array {
		return array(
			'empty suffix'         => array( 'bytes=-0', 10 ),
			'start at the end'     => array( 'bytes=10-', 10 ),
			'start past the end'   => array( 'bytes=20-30', 10 ),
			'suffix of empty file' => array( 'bytes=-5', 0 ),
			'start of empty file'  => array( 'bytes=0-', 0 ),
		);
	}

	#[DataProvider( 'unsatisfiable' )]
	public function test_unsatisfiable_ranges( string $header, int $size ): void {
		$range = BackupPlus_RangeHeader::createFromHeaderString( $header );
		foreach ( array( 'getStartPosition', 'getEndPosition', 'getLength', 'getContentRangeHeader' ) as $method ) {
			try {
				$range->$method( $size );
				$this->fail( $method . ' must refuse ' . $header . ' for a ' . $size . '-byte file' );
			} catch ( BackupPlus_UnsatisfiableRangeException $e ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	public function test_unsatisfiable_range_sends_no_data(): void {
		file_put_contents( $this->file, '' );
		ob_start();
		try {
			( new BackupPlus_PartialFileServlet( BackupPlus_RangeHeader::createFromHeaderString( 'bytes=-0' ) ) )->sendFile( $this->file );
			$this->fail( 'An empty suffix must be refused' );
		} catch ( BackupPlus_UnsatisfiableRangeException $e ) {
			$this->assertSame( '', ob_get_clean() );
		}
	}
}
