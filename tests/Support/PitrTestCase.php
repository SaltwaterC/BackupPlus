<?php
/**
 * Base class for the point in time recovery tests: a private temporary directory and the helpers shared by the
 * binary log, manifest, process and state tests.
 */

namespace BackupPlus\Tests\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;

abstract class PitrTestCase extends TestCase {

	protected string $directory = '';

	protected function setUp(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', __DIR__ . '/' );
		}
		$this->directory = sys_get_temp_dir() . '/backupplus-pitr-unit-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->directory, 0700 );
	}

	protected function tearDown(): void {
		$this->remove( $this->directory );
	}

	protected function remove( string $path ): void {
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			foreach ( new \DirectoryIterator( $path ) as $entry ) {
				if ( ! $entry->isDot() ) {
					$this->remove( $entry->getPathname() );
				}
			}
			rmdir( $path );
		} elseif ( file_exists( $path ) || is_link( $path ) ) {
			unlink( $path );
		}
	}

	/** The callback must throw a RuntimeException; its message is returned for further checks. */
	protected function assertRejected( callable $callback, string $message ): RuntimeException {
		$thrown = null;
		try {
			$callback();
		} catch ( RuntimeException $error ) {
			$thrown = $error;
		}
		$this->assertInstanceOf( RuntimeException::class, $thrown, $message );
		return $thrown;
	}

	/** One binary log event of the given type with a valid header and checksum; $position advances past it. */
	protected static function event( int $type, string $body, int &$position, int $timestamp = 100 ): string {
		$size      = 23 + strlen( $body );
		$event     = pack( 'VCVVVv', $timestamp, $type, 7, $size, $position + $size, 0 ) . $body;
		$position += $size;
		return $event . strrev( hash( 'crc32b', $event, true ) );
	}

	/** The body of a query event. */
	protected static function query_body( string $sql, string $database = 'wordpress' ): string {
		return pack( 'VVCvv', 1, 0, strlen( $database ), 0, 0 ) . $database . "\x00" . $sql;
	}

	/** The format description event of a MariaDB 11.4 log and the first position after it. */
	protected function format_event( ?int &$start = null ): string {
		$position = 4;
		$format   = self::event( 15, pack( 'v', 4 ) . str_pad( '11.4.0-MariaDB', 50, "\x00" ) . pack( 'VC', 100, 19 ) . str_repeat( "\x00", 40 ) . "\x01", $position );
		$start    = $position;
		return $format;
	}

	/**
	 * One committed transaction followed by a rotation, written to binlog.000001.
	 *
	 * @return array{format: string, start: int, data: string, committed: int, path: string}
	 */
	protected function standard_log(): array {
		$format    = $this->format_event( $start );
		$position  = $start;
		$data      = "\xfebin" . $format;
		$data     .= self::event( 162, str_repeat( "\x00", 13 ), $position );
		$data     .= self::event( 19, str_repeat( "\x00", 8 ) . chr( 9 ) . "wordpress\x00", $position );
		$data     .= self::event( 30, str_repeat( "\x00", 12 ), $position );
		$data     .= self::event( 16, str_repeat( "\x00", 8 ), $position );
		$committed = $position;
		$data     .= self::event( 4, pack( 'VV', 4, 0 ) . 'binlog.000002', $position );
		$path      = $this->directory . '/binlog.000001';
		file_put_contents( $path, $data );
		return array(
			'format'    => $format,
			'start'     => $start,
			'data'      => $data,
			'committed' => $committed,
			'path'      => $path,
		);
	}
}
