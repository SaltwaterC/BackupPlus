<?php
/** Streaming POSIX tar/PAX archives; entries are stored without compression. */
if ( ! defined( 'ABSPATH' ) ) { die( 'No direct access allowed' ); }
require_once __DIR__ . '/class-archive-compression.php';

class BackupPlus_Tar {
	public $last_error = '';
	public $numFiles   = 0;
	private $handle;
	private $path;
	private $decoded;
	private $entries = array();
	private $end     = 0;
	private $writing = false;

	private static function name( $name ) {
		if ( ! is_string( $name ) || '' === $name || strlen( $name ) > 65536 || preg_match( '~[\\\\\x00]|^/|^[A-Za-z]:|(?:^|/)\.\.(?:/|$)~', $name ) ) { throw new RuntimeException( 'Unsafe tar entry path.' ); }
		return $name;
	}

	public function open( $path, $flags = 0 ) {
		try {
			$this->path = $path;
			if ( is_file( $path ) && BackupPlus_Archive_Compression::is_zstd( $path ) ) {
				$this->decoded = BackupPlus_Archive_Compression::decoded_file( $path );
				$this->path    = $this->decoded;
			}
			$this->handle = fopen( $this->path, is_file( $this->path ) ? 'rb' : 'x+b' );
			if ( ! $this->handle ) { throw new RuntimeException( 'Could not open tar archive.' ); }
			$this->scan();
			return true;
		} catch ( Throwable $error ) { $this->last_error = $error->getMessage();
			$this->close();
			return false; }
	}

	private function scan() {
		$this->entries = array();
		$this->end     = 0;
		$pax           = array();
		$terminated    = false;
		$total         = fstat( $this->handle )['size'];
		while ( $this->end < $total ) {
			fseek( $this->handle, $this->end );
			$header = BackupPlus_Archive_IO::read_up_to( $this->handle, 512 );
			if ( strlen( $header ) !== 512 ) { throw new RuntimeException( 'Truncated tar header.' ); }
			if ( str_repeat( "\0", 512 ) === $header ) {
				if ( BackupPlus_Archive_IO::read_up_to( $this->handle, 512 ) !== str_repeat( "\0", 512 ) ) { throw new RuntimeException( 'Incomplete tar terminator.' ); }
				$terminated = true;
				break;
			}
			$checksum = octdec( trim( substr( $header, 148, 8 ), " \0" ) );
			if ( array_sum( unpack( 'C*', substr_replace( $header, str_repeat( ' ', 8 ), 148, 8 ) ) ) !== $checksum ) { throw new RuntimeException( 'Invalid tar header checksum.' ); }
			$size   = octdec( trim( substr( $header, 124, 12 ), " \0" ) );
			$type   = $header[156];
			$offset = $this->end + 512;
			if ( 'x' !== $type && isset( $pax['size'] ) ) { $size = self::size( $pax['size'] ); }
			if ( ! is_int( $size ) || $size < 0 || $size > $total - $offset ) { throw new RuntimeException( 'Truncated or oversized tar entry.' ); }
			$this->end = $offset + (int) ( ceil( $size / 512 ) * 512 );
			if ( $this->end > $total ) { throw new RuntimeException( 'Truncated tar padding.' ); }
			if ( 'x' === $type ) {
				if ( $size > 131072 ) { throw new RuntimeException( 'Oversized tar metadata.' ); }
				$data = BackupPlus_Archive_IO::read_up_to( $this->handle, $size );
				while ( '' !== $data ) {
					if ( ! preg_match( '/\A([1-9][0-9]*) /', $data, $match ) ) { throw new RuntimeException( 'Invalid PAX metadata.' ); }
					$length = (int) $match[1];
					if ( $length <= strlen( $match[0] ) || $length > strlen( $data ) || "\n" !== $data[ $length - 1 ] ) { throw new RuntimeException( 'Invalid PAX record length.' ); }
					$record = explode( '=', substr( $data, strlen( $match[0] ), $length - strlen( $match[0] ) - 1 ), 2 );
					if ( count( $record ) !== 2 ) { throw new RuntimeException( 'Invalid PAX record.' ); }
					$pax[ $record[0] ] = $record[1];
					$data              = substr( $data, $length );
				}
				continue;
			}
			if ( ! in_array( $type, array( '0', "\0", '5' ), true ) ) { throw new RuntimeException( 'Unsupported tar entry type; only files and directories are allowed.' ); }
			$prefix = rtrim( substr( $header, 345, 155 ), "\0" );
			$name   = isset( $pax['path'] ) ? $pax['path'] : ( $prefix ? $prefix . '/' : '' ) . rtrim( substr( $header, 0, 100 ), "\0" );
			if ( '5' === $type ) { $name = rtrim( $name, '/' ) . '/'; }
			$this->entries[] = array(
				'name'   => self::name( $name ),
				'size'   => $size,
				'offset' => $offset,
				'mtime'  => octdec( trim( substr( $header, 136, 12 ), " \0" ) ),
			);
			$pax             = array();
		}
		if ( $pax || ( $this->decoded && ! $terminated ) ) { throw new RuntimeException( 'Incomplete tar archive.' ); }
		$this->numFiles = count( $this->entries );
	}

	private static function size( $text ) {
		if ( ! preg_match( '/\A(?:0|[1-9][0-9]*)\z/D', $text ) || strlen( $text ) > strlen( (string) PHP_INT_MAX ) || ( strlen( $text ) === strlen( (string) PHP_INT_MAX ) && strcmp( $text, (string) PHP_INT_MAX ) > 0 ) ) { throw new RuntimeException( 'Invalid tar size.' ); }
		return (int) $text;
	}

	private function header( $name, $size, $mode, $mtime, $type ) {
		$header = str_pad( substr( $name, 0, 100 ), 100, "\0" ) . sprintf( "%07o\0%07o\0%07o\0%011o\0%011o\0", $mode & 0777, 0, 0, $size, $mtime ) . str_repeat( ' ', 8 ) . $type . str_repeat( "\0", 100 ) . "ustar\00000";
		$header = str_pad( $header, 512, "\0" );
		return substr_replace( $header, sprintf( "%06o\0 ", array_sum( unpack( 'C*', $header ) ) ), 148, 8 );
	}

	private function append( $name, $size, $mode, $mtime, $type, $input = null ) {
		self::name( $name );
		if ( $this->decoded ) { throw new RuntimeException( 'Compressed tar archives are read-only.' ); }
		if ( ! $this->writing ) {
			fclose( $this->handle );
			$this->handle = fopen( $this->path, 'r+b' );
			if ( ! $this->handle || ! ftruncate( $this->handle, $this->end ) || 0 !== fseek( $this->handle, $this->end ) ) { throw new RuntimeException( 'Could not append to tar archive.' ); }
			$this->writing = true;
		}
		$pax = array();
		if ( strlen( $name ) > 100 ) { $pax['path'] = $name; }
		if ( $size > 8589934591 ) { $pax['size'] = (string) $size; }
		if ( $pax ) {
			$data = '';
			foreach ( $pax as $key => $value ) {
				$record = $key . '=' . $value . "\n";
				$length = strlen( $record ) + 2;
				while ( $length !== strlen( (string) $length ) + 1 + strlen( $record ) ) { $length = strlen( (string) $length ) + 1 + strlen( $record ); }
				$data .= $length . ' ' . $record;
			}
			BackupPlus_Archive_IO::write_all( $this->handle, $this->header( 'PaxHeaders/entry', strlen( $data ), 0600, $mtime, 'x' ) . str_pad( $data, (int) ( ceil( strlen( $data ) / 512 ) * 512 ), "\0" ) );
		}
		BackupPlus_Archive_IO::write_all( $this->handle, $this->header( $name, $size > 8589934591 ? 0 : $size, $mode, $mtime, $type ) );
		$offset = ftell( $this->handle );
		if ( $input && stream_copy_to_stream( $input, $this->handle, $size ) !== $size ) { throw new RuntimeException( 'Could not copy the complete source into tar archive.' ); }
		$padding = ( 512 - $size % 512 ) % 512;
		if ( $padding ) { BackupPlus_Archive_IO::write_all( $this->handle, str_repeat( "\0", $padding ) ); }
		$this->end       = ftell( $this->handle );
		$this->entries[] = array(
			'name'   => $name,
			'size'   => $size,
			'offset' => $offset,
			'mtime'  => $mtime,
		);
		$this->numFiles  = count( $this->entries );
	}

	public function addFile( $path, $name ) {
		$input = fopen( $path, 'rb' );
		if ( ! $input ) { throw new RuntimeException( 'Could not open tar source.' ); }
		try { $stat = fstat( $input );
			$this->append( $name, $stat['size'], $stat['mode'], $stat['mtime'], '0', $input ); }
		finally { fclose( $input ); }
		return true;
	}
	public function addEmptyDir( $name ) {
		$this->append( rtrim( $name, '/' ) . '/', 0, 0755, time(), '5' );
		return true; }
	public function statIndex( $index ) {
		return isset( $this->entries[ $index ] ) ? $this->entries[ $index ] : false; }
	public function locateName( $name ) {
		for ( $i = $this->numFiles - 1; $i >= 0; --$i ) { if ( $name === $this->entries[ $i ]['name'] ) { return $i;
		}
		} return false; }
	public function getFromIndex( $index, $length = 0 ) {
		$entry = $this->statIndex( $index );
		if ( ! $entry || 0 !== fseek( $this->handle, $entry['offset'] ) ) { return false; }
		return BackupPlus_Archive_IO::read_up_to( $this->handle, $length ? min( $length, $entry['size'] ) : $entry['size'] );
	}
	public function getFromName( $name ) {
		$index = $this->locateName( $name );
		return false === $index ? false : $this->getFromIndex( $index ); }
	public function getStream( $name ) {
		$index = $this->locateName( $name );
		if ( false === $index ) { return false; }
		$entry  = $this->entries[ $index ];
		$output = tmpfile();
		if ( ! $output || 0 !== fseek( $this->handle, $entry['offset'] ) || stream_copy_to_stream( $this->handle, $output, $entry['size'] ) !== $entry['size'] ) { if ( is_resource( $output ) ) { fclose( $output );
		} return false; }
		rewind( $output );
		return $output;
	}
	public function close() {
		if ( is_resource( $this->handle ) ) {
			if ( $this->writing ) { BackupPlus_Archive_IO::write_all( $this->handle, str_repeat( "\0", 1024 ) ); }
			fclose( $this->handle );
			$this->handle = null;
		}
		if ( $this->decoded && is_file( $this->decoded ) ) { unlink( $this->decoded ); }
		$this->decoded = null;
		$this->writing = false;
		return true;
	}
	public function __destruct() {
		$this->close(); }
}
