<?php
/** Lossless binary-log compression; source bytes, coordinates and ancestry hashes stay unchanged. */
if ( ! defined( 'ABSPATH' ) ) { die( 'No direct access allowed' ); }

class BackupPlus_PITR_Compression {
	/** Optional on existing installations. A false filter value deliberately keeps gzip. */
	public static function zstd_binary() {
		$configured = function_exists( 'apply_filters' ) ? apply_filters( 'backupplus_pitr_zstd_binary', null ) : null;
		$paths      = null === $configured ? array( '/usr/bin/zstd', '/usr/local/bin/zstd', '/bin/zstd' ) : array( $configured );
		foreach ( $paths as $path ) {
			if ( is_string( $path ) && '/' === substr( $path, 0, 1 ) && is_file( $path ) && is_executable( $path ) ) { return $path; }
		}
		return false;
	}

	public static function binlog_extension() {
		return self::zstd_binary() ? 'zst' : 'gz'; }

	/** Keep the history window at 32 MiB and one compression thread, independently of PHP memory. */
	public static function compress_zstd( $input, $output, $directory ) {
		$binary = self::zstd_binary();
		if ( ! $binary ) { throw new RuntimeException( 'Zstandard compression requires the zstd executable.' ); }
		return BackupPlus_PITR_Process::run( array( $binary, '-6', '--long=25', '--single-thread', '--check', '--no-progress', '--stdout', '--', $input ), $directory, null, $output );
	}

	/** Stream into a bounded output file; malformed frames cannot exceed the declared raw size. */
	public static function decompress_zstd( $input, $output, $directory, $maximum, $exact = true ) {
		$binary = self::zstd_binary();
		if ( ! $binary ) { throw new RuntimeException( 'This PITR archive uses Zstandard. Install zstd to restore it.' ); }
		if ( ! is_int( $maximum ) || $maximum < 1 ) { throw new RuntimeException( 'Zstandard PITR archives require a valid uncompressed size.' ); }
		BackupPlus_PITR_Process::private_file( $output );
		$errors = $directory . '/zstd-error-' . bin2hex( random_bytes( 8 ) );
		BackupPlus_PITR_Process::private_file( $errors );
		$target   = fopen( $output, 'wb' );
		$process  = false;
		$produced = 0;
		$exit     = -1;
		try {
			$process = BackupPlus_PITR_Process::open(
				array( $binary, '--decompress', '--stdout', '--no-progress', '--memory=64MB', '--', $input ),
				array(
					0 => array( 'file', '/dev/null', 'r' ),
					1 => array( 'pipe', 'w' ),
					2 => array( 'file', $errors, 'w' ),
				),
				$pipes,
				$directory
			);
			if ( ! $target || ! is_resource( $process ) ) { throw new RuntimeException( 'Could not start Zstandard decompression.' ); }
			stream_set_blocking( $pipes[1], false );
			$deadline = microtime( true ) + 1800;
			while ( ! feof( $pipes[1] ) ) {
				$read  = array( $pipes[1] );
				$write = $except = null;
				if ( false === stream_select( $read, $write, $except, 0, 100000 ) ) { throw new RuntimeException( 'Could not read Zstandard output.' ); }
				$data = fread( $pipes[1], 1048576 );
				if ( false === $data ) { throw new RuntimeException( 'Could not read Zstandard output.' ); }
				$produced += strlen( $data );
				if ( $produced > $maximum ) { throw new RuntimeException( 'PITR decompression exceeded its declared size.' ); }
				$offset = 0;
				while ( $offset < strlen( $data ) ) {
					$written = fwrite( $target, substr( $data, $offset ) );
					if ( ! $written ) { throw new RuntimeException( 'PITR recovery disk is full.' ); }
					$offset += $written;
				}
				if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'Zstandard decompression timed out.' ); }
			}
			fclose( $pipes[1] );
			unset( $pipes[1] );
			do {
				$status = proc_get_status( $process );
				if ( ! $status['running'] ) { $exit = $status['exitcode'];
					break; }
				if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'Zstandard decompression timed out.' ); }
				usleep( 1000 );
			} while ( true );
			$closed  = proc_close( $process );
			$process = false;
			if ( 0 !== ( $exit < 0 ? $closed : $exit ) || ( $exact && $produced !== $maximum ) ) { throw new RuntimeException( 'Corrupt or incomplete Zstandard PITR archive.' ); }
		} catch ( Throwable $error ) {
			if ( is_file( $output ) ) { unlink( $output ); }
			throw $error;
		} finally {
			if ( isset( $pipes[1] ) && is_resource( $pipes[1] ) ) { fclose( $pipes[1] ); }
			if ( is_resource( $process ) ) { proc_terminate( $process );
				usleep( 100000 );
				proc_terminate( $process, 9 );
				proc_close( $process ); }
			if ( is_resource( $target ) ) { fclose( $target ); }
			unlink( $errors );
		}
		return $output;
	}
}
