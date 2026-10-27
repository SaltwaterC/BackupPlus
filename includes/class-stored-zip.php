<?php

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

/**
 * Streams local files as one uncompressed ZIP without building it on disk.
 *
 * Backup artifacts are already compressed or encrypted, so entries are stored. CRCs and sizes are known up front, which
 * allows a Content-Length and plain local headers. Archives needing ZIP64 are refused instead of written incorrectly.
 */
class BackupPlus_Stored_Zip {

	const MAX_ENTRIES = 65535;
	const MAX_BYTES   = 4294967295;
	const CHUNK       = 1048576;

	private $entries = array();

	private $length = 0;

	/**
	 * @param array $files List of array( 'name' => entry name, 'path' => absolute file path ).
	 * @throws RuntimeException When a file cannot be read or the archive would need ZIP64.
	 */
	public function __construct( $files ) {
		if ( count( $files ) > self::MAX_ENTRIES ) {
			throw new RuntimeException( 'Too many files for one archive. Select fewer recovery points.' );
		}
		$offset  = 0;
		$central = 0;
		foreach ( $files as $file ) {
			$size = is_file( $file['path'] ) && is_readable( $file['path'] ) ? filesize( $file['path'] ) : false;
			$crc  = false === $size ? false : hash_file( 'crc32b', $file['path'] );
			if ( false === $size || false === $crc ) {
				throw new RuntimeException( 'A backup file is missing or unreadable.' );
			}
			$entry           = array(
				'name'   => $file['name'],
				'path'   => $file['path'],
				'size'   => $size,
				'crc'    => hexdec( $crc ),
				'dos'    => self::dos_time( (int) filemtime( $file['path'] ) ),
				'offset' => $offset,
			);
			$offset         += 30 + strlen( $file['name'] ) + $size;
			$central        += 46 + strlen( $file['name'] );
			$this->entries[] = $entry;
		}
		$this->length = $offset + $central + 22;
		if ( $this->length > self::MAX_BYTES ) {
			throw new RuntimeException( 'The selected recovery points are too large for one archive. Select fewer recovery points.' );
		}
	}

	public function length() {
		return $this->length;
	}

	/**
	 * Write the archive to a stream.
	 *
	 * @param resource $out Writable stream.
	 * @throws RuntimeException When a file changes while it is being read.
	 */
	public function write( $out ) {
		$central = '';
		foreach ( $this->entries as $entry ) {
			$name = $entry['name'];
			$time = $entry['dos'];
			self::put( $out, pack( 'VvvvvvVVVvv', 0x04034b50, 10, 0x0800, 0, $time[0], $time[1], $entry['crc'], $entry['size'], $entry['size'], strlen( $name ), 0 ) . $name );
			$this->copy( $out, $entry );
			$central .= pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 10, 0x0800, 0, $time[0], $time[1], $entry['crc'], $entry['size'], $entry['size'], strlen( $name ), 0, 0, 0, 0, 0, $entry['offset'] ) . $name;
		}
		self::put( $out, $central . pack( 'VvvvvVVv', 0x06054b50, 0, 0, count( $this->entries ), count( $this->entries ), strlen( $central ), $this->length - 22 - strlen( $central ), 0 ) );
	}

	/** Send the archive to the browser as a download. */
	public function send( $filename ) {
		while ( ob_get_level() ) {
			@ob_end_clean();// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise when no buffer is active.
		}
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . str_replace( array( '"', '\\', "\r", "\n" ), '', $filename ) . '"' );
		header( 'Content-Length: ' . $this->length );
		$out = fopen( 'php://output', 'wb' );
		$this->write( $out );
		fclose( $out );
	}

	private function copy( $out, $entry ) {
		$in = fopen( $entry['path'], 'rb' );
		if ( false === $in ) {
			throw new RuntimeException( 'A backup file is missing or unreadable.' );
		}
		$left = $entry['size'];
		while ( $left > 0 ) {
			$data = fread( $in, min( self::CHUNK, $left ) );
			if ( false === $data || '' === $data ) {
				fclose( $in );
				throw new RuntimeException( 'A backup file changed while it was being archived.' );
			}
			self::put( $out, $data );
			$left -= strlen( $data );
		}
		fclose( $in );
	}

	private static function put( $out, $data ) {
		if ( strlen( $data ) !== fwrite( $out, $data ) ) {
			throw new RuntimeException( 'The archive could not be written.' );
		}
	}

	private static function dos_time( $timestamp ) {
		$year = max( 1980, (int) gmdate( 'Y', $timestamp ) );
		return array(
			( (int) gmdate( 'G', $timestamp ) << 11 ) | ( (int) gmdate( 'i', $timestamp ) << 5 ) | ( (int) gmdate( 's', $timestamp ) >> 1 ),
			( ( $year - 1980 ) << 9 ) | ( (int) gmdate( 'n', $timestamp ) << 5 ) | (int) gmdate( 'j', $timestamp ),
		);
	}
}
