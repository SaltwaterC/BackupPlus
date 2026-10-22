<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}

/** Bounded archive stream I/O shared by age and legacy backup readers. */
class BackupPlus_Archive_IO {
	/** Read until the requested size or true EOF; a short read is not EOF. */
	public static function read_up_to( $handle, $length ) {
		$data = '';
		while ( strlen( $data ) < $length && ! feof( $handle ) ) {
			$part = fread( $handle, $length - strlen( $data ) );
			if ( false === $part || ( '' === $part && ! feof( $handle ) ) ) {
				throw new \RuntimeException( 'Archive read failed.' );
			}
			$data .= $part;
		}
		return $data;
	}

	/** Handle short writes and refuse zero-byte writes instead of looping forever. */
	public static function write_all( $handle, $data ) {
		$offset = 0;
		$length = strlen( $data );
		while ( $offset < $length ) {
			$written = fwrite( $handle, substr( $data, $offset ) );
			if ( false === $written || 0 === $written ) {
				throw new \RuntimeException( 'Archive write failed.' );
			}
			$offset += $written;
		}
	}
}
