<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fgets, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.WP.AlternativeFunctions.file_system_operations_fputs, WordPress.WP.AlternativeFunctions.file_system_operations_is_writeable, WordPress.WP.AlternativeFunctions.file_system_operations_chown, WordPress.WP.AlternativeFunctions.file_system_operations_chgrp, WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Native PHP fileystem function is used for direct control and performance because it can bypass additional layers of abstraction so that no overhead from the WordPress filesystem API's internal handling
// https://stackoverflow.com/questions/157318/resumable-downloads-when-using-php-to-send-the-file
// User: DaveRandom
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

if ( ! class_exists( 'BackupPlus_NonExistentFileException' ) ) :
	class BackupPlus_NonExistentFileException extends RuntimeException {}
endif;

if ( ! class_exists( 'BackupPlus_UnreadableFileException' ) ) :
	class BackupPlus_UnreadableFileException extends RuntimeException {}
endif;

if ( ! class_exists( 'BackupPlus_UnsatisfiableRangeException' ) ) :
	class BackupPlus_UnsatisfiableRangeException extends RuntimeException {}
endif;

if ( ! class_exists( 'BackupPlus_InvalidRangeHeaderException' ) ) :
	class BackupPlus_InvalidRangeHeaderException extends RuntimeException {}
endif;

class BackupPlus_RangeHeader {

	/**
	 * The first byte in the file to send (0-indexed), a null value indicates the last
	 * $end bytes
	 *
	 * @var int|null
	 */
	private $firstByte;

	/**
	 * The last byte in the file to send (0-indexed), a null value indicates $start to
	 * EOF
	 *
	 * @var int|null
	 */
	private $lastByte;

	/**
	 * Create a new instance from a Range header string
	 *
	 * @param string $header
	 * @return BackupPlus_RangeHeader
	 */
	public static function createFromHeaderString( $header ) {
		if ( $header === null ) {
			return null;
		}
		if ( ! preg_match( '/^\s*([A-Za-z]+)\s*=\s*(\d*)\s*-\s*(\d*)\s*(?:,|$)/', $header, $info ) ) {
			throw new BackupPlus_InvalidRangeHeaderException( 'Invalid header format' );
		} elseif ( strtolower( $info[1] ) !== 'bytes' ) {
			throw new BackupPlus_InvalidRangeHeaderException( 'Unknown range unit: ' . $info[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error messages should be escaped when caught and printed.
		}

		return new self(
			$info[2] === '' ? null : $info[2],
			$info[3] === '' ? null : $info[3]
		);
	}

	/**
	 * @param int|null $firstByte
	 * @param int|null $lastByte
	 * @throws BackupPlus_InvalidRangeHeaderException
	 */
	public function __construct( $firstByte, $lastByte ) {
		$this->firstByte = $firstByte === null ? $firstByte : (int) $firstByte;
		$this->lastByte  = $lastByte === null ? $lastByte : (int) $lastByte;

		if ( $this->firstByte === null && $this->lastByte === null ) {
			throw new BackupPlus_InvalidRangeHeaderException(
				'Both start and end position specifiers empty'
			);
		} elseif ( $this->firstByte < 0 || $this->lastByte < 0 ) {
			throw new BackupPlus_InvalidRangeHeaderException(
				'Position specifiers cannot be negative'
			);
		} elseif ( $this->lastByte !== null && $this->lastByte < $this->firstByte ) {
			throw new BackupPlus_InvalidRangeHeaderException(
				'Last byte cannot be less than first byte'
			);
		}
	}

	/**
	 * Get the start position when this range is applied to a file of the specified size (RFC 9110, section 14.1.2)
	 *
	 * @param int $fileSize
	 * @return int
	 * @throws BackupPlus_UnsatisfiableRangeException
	 */
	public function getStartPosition( $fileSize ) {
		$size = (int) $fileSize;

		if ( $this->firstByte === null ) {
			// bytes=-N is the last N bytes; N is $this->lastByte. A suffix longer than the file selects all of it, and an empty suffix (or file) selects nothing.
			if ( $this->lastByte <= 0 || $size <= 0 ) {
				throw new BackupPlus_UnsatisfiableRangeException(
					'Empty suffix range'
				);
			}
			return max( 0, $size - $this->lastByte );
		}

		if ( $size <= $this->firstByte ) {
			throw new BackupPlus_UnsatisfiableRangeException(
				'Start position is after the end of the file'
			);
		}

		return $this->firstByte;
	}

	/**
	 * Get the end position when this range is applied to a file of the specified size. A last position at or beyond the end of
	 * the file means the rest of the file (RFC 9110, section 14.1.2).
	 *
	 * @param int $fileSize
	 * @return int
	 * @throws BackupPlus_UnsatisfiableRangeException
	 */
	public function getEndPosition( $fileSize ) {
		$size = (int) $fileSize;

		// Applies the same checks as the start position, so that an unsatisfiable range is refused whichever is asked first
		$this->getStartPosition( $size );

		if ( $this->firstByte === null || $this->lastByte === null ) {
			return $size - 1;
		}

		return min( $this->lastByte, $size - 1 );
	}

	/**
	 * Get the length when this range is applied to a file of the specified size
	 *
	 * @param int $fileSize
	 * @return int
	 * @throws BackupPlus_UnsatisfiableRangeException
	 */
	public function getLength( $fileSize ) {
		$size = (int) $fileSize;

		return $this->getEndPosition( $size ) - $this->getStartPosition( $size ) + 1;
	}

	/**
	 * Get a Content-Range header corresponding to this Range and the specified file
	 * size
	 *
	 * @param int $fileSize
	 * @return string
	 */
	public function getContentRangeHeader( $fileSize ) {
		return 'bytes ' . $this->getStartPosition( $fileSize ) . '-'
			. $this->getEndPosition( $fileSize ) . '/' . $fileSize;
	}
}

class BackupPlus_PartialFileServlet {

	/**
	 * The range header on which the data transmission will be based
	 *
	 * @var BackupPlus_RangeHeader|null
	 */
	private $range;

	/**
	 * @param BackupPlus_RangeHeader $range Range header on which the transmission will be based
	 */
	public function __construct( $range = null ) {
		$this->range = $range;
	}

	/**
	 * Send part of the data in a seekable stream resource to the output buffer
	 *
	 * @param resource $fp Stream resource to read data from
	 * @param int $start Position in the stream to start reading
	 * @param int $length Number of bytes to read
	 * @param int $chunkSize Maximum bytes to read from the file in a single operation
	 */
	private function sendDataRange( $fp, $start, $length, $chunkSize = 2097152 ) {
		if ( $start > 0 ) {
			fseek( $fp, $start, SEEK_SET );
		}

		while ( $length ) {
			$read    = ( $length > $chunkSize ) ? $chunkSize : $length;
			$length -= $read;
			echo fread( $fp, $read ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw output intended.
		}
	}

	/**
	 * Send the headers that are included regardless of whether a range was requested
	 *
	 * @param string $fileName
	 * @param int $contentLength
	 * @param string $contentType
	 */
	private function sendDownloadHeaders( $fileName, $contentLength, $contentType ) {
		header( 'Content-Type: ' . $contentType );
		header( 'Content-Length: ' . $contentLength );
		header( 'Content-Disposition: attachment; filename="' . $fileName . '"' );
		header( 'Accept-Ranges: bytes' );
	}

	/**
	 * Send data from a file based on the current Range header
	 *
	 * @param string $path Local file system path to serve
	 * @param string $contentType MIME type of the data stream
	 * @param string|null $downloadName The file name for the download; null for the name of the file served
	 */
	public function sendFile( $path, $contentType = 'application/octet-stream', $downloadName = null ) {
		// Make sure the file exists and is a file, otherwise we are wasting our time
		$localPath = realpath( $path );
		if ( $localPath === false || ! is_file( $localPath ) ) {
			throw new BackupPlus_NonExistentFileException(
				$path . ' does not exist or is not a file' // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error messages should be escaped when caught and printed.
			);
		}

		// Make sure we can open the file for reading
		if ( ! $fp = fopen( $localPath, 'r' ) ) {
			throw new BackupPlus_UnreadableFileException(
				'Failed to open ' . $localPath . ' for reading' // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error messages should be escaped when caught and printed.
			);
		}

		$fileSize = filesize( $localPath );

		if ( $this->range != null ) {
			// Work out the range before any header is sent, so that an unsatisfiable range is answered with 416 alone
			try {
				$start  = $this->range->getStartPosition( $fileSize );
				$length = $this->range->getLength( $fileSize );
			} catch ( BackupPlus_UnsatisfiableRangeException $e ) {
				fclose( $fp );
				header( 'Content-Range: bytes */' . $fileSize );
				throw $e;
			}
		}

		if ( $this->range == null ) {
			// No range requested, just send the whole file
			header( 'HTTP/1.1 200 OK' );
			$this->sendDownloadHeaders( null === $downloadName ? basename( $localPath ) : $downloadName, $fileSize, $contentType );

			fpassthru( $fp );
		} else {
			// Send the request range
			header( 'HTTP/1.1 206 Partial Content' );
			header( 'Content-Range: ' . $this->range->getContentRangeHeader( $fileSize ) );
			$this->sendDownloadHeaders(
				null === $downloadName ? basename( $localPath ) : $downloadName,
				$length,
				$contentType
			);

			$this->sendDataRange( $fp, $start, $length );
		}

		fclose( $fp );
	}
}
