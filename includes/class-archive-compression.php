<?php
/** One Zstandard layer around tar archives and SQL, before optional encryption. */
if ( ! defined( 'ABSPATH' ) ) { die( 'No direct access allowed' ); }
require_once __DIR__ . '/class-pitr-process.php';
require_once __DIR__ . '/class-pitr-compression.php';
require_once __DIR__ . '/class-archive-io.php';

class BackupPlus_Archive_Compression {
	public static function enabled() {
		return function_exists( 'proc_open' ) && function_exists( 'proc_get_status' ) && function_exists( 'proc_close' ) && ( ! function_exists( 'apply_filters' ) || apply_filters( 'backupplus_archive_zstd', true ) ) && BackupPlus_PITR_Compression::zstd_binary();
	}

	public static function is_zstd( $path ) {
		$input = fopen( $path, 'rb' );
		if ( ! $input ) { return false; }
		try { return "\x28\xb5\x2f\xfd" === fread( $input, 4 ); }
		finally { fclose( $input ); }
	}

	/** Decode and validate the entire frame before any SQL or tar entry is consumed. */
	public static function decoded_file( $path, $directory = null ) {
		$directory = null === $directory ? dirname( $path ) : $directory;
		$output    = $directory . '/zstd-read-' . bin2hex( random_bytes( 16 ) ) . '.tmp';
		$available = disk_free_space( $directory );
		if ( false === $available || $available < 16777216 ) { throw new RuntimeException( 'Insufficient free disk space to decode the backup archive.' ); }
		BackupPlus_PITR_Compression::decompress_zstd( $path, $output, $directory, (int) min( PHP_INT_MAX, $available - 8388608 ), false );
		return $output;
	}

	/** The returned seekable stream also works with PHP's gzgets/gzseek readers. */
	public static function open_read( $path ) {
		if ( ! self::is_zstd( $path ) ) { return gzopen( $path, 'rb' ); }
		$decoded = self::decoded_file( $path );
		try { return fopen( $decoded, 'rb' ); }
		finally { unlink( $decoded ); }
	}

	/** Publish only after the compressor closes successfully; retry leaves the source intact. */
	public static function compress_file( $path ) {
		if ( is_link( $path ) || ! is_file( $path ) ) { throw new RuntimeException( 'The archive source is unavailable or is a symbolic link.' ); }
		$output    = $path . '.zst';
		$temporary = $output . '.' . bin2hex( random_bytes( 8 ) ) . '.tmp';
		try {
			BackupPlus_PITR_Compression::compress_zstd( $path, $temporary, dirname( $path ) );
			if ( ! rename( $temporary, $output ) ) { throw new RuntimeException( 'Could not publish the Zstandard archive.' ); }
			return $output;
		} finally { if ( is_file( $temporary ) ) { unlink( $temporary ); }
		}
	}
}

/** Streaming SQL output avoids a second gzip layer or an uncompressed SQL staging file. */
class BackupPlus_Zstd_Writer {
	public $handle;
	private $process;
	private $temporary;
	private $errors;
	private $output;

	public function __construct( $output ) {
		$binary = BackupPlus_PITR_Compression::zstd_binary();
		if ( ! $binary ) { throw new RuntimeException( 'Zstandard compression requires the zstd executable.' ); }
		$this->output    = $output;
		$this->temporary = $output . '.' . bin2hex( random_bytes( 8 ) ) . '.tmp';
		$this->errors    = $this->temporary . '.errors.tmp';
		BackupPlus_PITR_Process::private_file( $this->temporary );
		BackupPlus_PITR_Process::private_file( $this->errors );
		$this->process = BackupPlus_PITR_Process::open(
			array( $binary, '-6', '--long=25', '--single-thread', '--check', '--no-progress', '--stdout' ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'file', $this->temporary, 'wb' ),
				2 => array( 'file', $this->errors, 'wb' ),
			),
			$pipes,
			dirname( $output )
		);
		if ( ! is_resource( $this->process ) ) { throw new RuntimeException( 'Could not start Zstandard compression.' ); }
		$this->handle = $pipes[0];
	}

	public function finish() {
		fclose( $this->handle );
		$this->handle = null;
		$deadline     = microtime( true ) + 1800;
		do {
			$status = proc_get_status( $this->process );
			if ( ! $status['running'] ) { break; }
			if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'Zstandard compression timed out.' ); }
			usleep( 10000 );
		} while ( true );
		$closed        = proc_close( $this->process );
		$this->process = null;
		if ( 0 !== ( $status['exitcode'] < 0 ? $closed : $status['exitcode'] ) || ! rename( $this->temporary, $this->output ) ) { throw new RuntimeException( 'Could not finish the Zstandard archive.' ); }
		return true;
	}

	public function __destruct() {
		if ( is_resource( $this->handle ) ) { fclose( $this->handle ); }
		if ( is_resource( $this->process ) ) { proc_terminate( $this->process );
			usleep( 100000 );
			proc_terminate( $this->process, 9 );
			proc_close( $this->process ); }
		foreach ( array( $this->temporary, $this->errors ) as $path ) { if ( $path && is_file( $path ) ) { unlink( $path ); }
		}
	}
}
