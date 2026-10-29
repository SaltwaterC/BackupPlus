<?php
/** A portable, uncompressed container for one PITR point's already compressed members. */
if ( ! defined( 'ABSPATH' ) ) { die( 'No direct access allowed' ); }
require_once __DIR__ . '/class-tar.php';

class BackupPlus_PITR_Archive {

	/** Create the container in private staging. The caller publishes it atomically. */
	public static function create( $path, $files ) {
		if ( file_exists( $path ) || is_link( $path ) ) { throw new RuntimeException( 'PITR point archive already exists.' ); }
		$tar  = new BackupPlus_Tar();
		$mask = umask( 0077 );
		try {
			if ( ! $tar->open( $path ) ) { throw new RuntimeException( 'Could not create PITR point archive.' ); }
			if ( ! chmod( $path, 0600 ) ) { throw new RuntimeException( 'Could not protect PITR point archive.' ); }
			foreach ( $files as $file ) {
				BackupPlus_PITR_Manifest::filename( $file['name'] );
				if ( is_link( $file['path'] ) || ! is_file( $file['path'] ) || filesize( $file['path'] ) !== $file['size'] || ! hash_equals( $file['sha256'], hash_file( 'sha256', $file['path'] ) ) ) { throw new RuntimeException( 'PITR point member changed before archiving.' ); }
				$tar->addFile( $file['path'], $file['name'] );
			}
			$tar->close();
			$entries = self::inventory( $path );
			$input   = fopen( $path, 'rb' );
			try {
				if ( ! $input ) { throw new RuntimeException( 'Could not verify PITR point archive.' ); }
				foreach ( $files as $file ) {
					if ( ! isset( $entries[ $file['name'] ] ) || $entries[ $file['name'] ]['size'] !== $file['size'] || 0 !== fseek( $input, $entries[ $file['name'] ]['offset'] ) ) { throw new RuntimeException( 'PITR point archive inventory changed.' ); }
					$hash = hash_init( 'sha256' );
					if ( hash_update_stream( $hash, $input, $file['size'] ) !== $file['size'] || ! hash_equals( $file['sha256'], hash_final( $hash ) ) ) { throw new RuntimeException( 'PITR point archive member verification failed.' ); }
				}
			} finally { if ( $input ) { fclose( $input ); }
			}
		} catch ( Throwable $error ) {
			$tar->close();
			if ( is_file( $path ) && ! is_link( $path ) ) { unlink( $path ); }
			throw $error;
		} finally { umask( $mask ); }
	}

	/** Reject ambiguous names, foreign members and incomplete containers. */
	public static function inventory( $path ) {
		$name = basename( $path );
		if ( ! preg_match( '/\A(backup_pitr_[a-f0-9]{32}_[a-f0-9]{32}_)point\.tar\z/D', $name, $match ) || is_link( $path ) || ! is_file( $path ) || filesize( $path ) < 1024 ) { throw new RuntimeException( 'Invalid PITR point archive.' ); }
		$input = fopen( $path, 'rb' );
		try {
			if ( ! $input || "\x28\xb5\x2f\xfd" === fread( $input, 4 ) || 0 !== fseek( $input, -1024, SEEK_END ) || fread( $input, 1024 ) !== str_repeat( "\0", 1024 ) ) { throw new RuntimeException( 'Incomplete or invalid PITR point archive.' ); }
		} finally { if ( $input ) { fclose( $input ); }
		}
		$tar = new BackupPlus_Tar();
		if ( ! $tar->open( $path ) ) { throw new RuntimeException( 'Invalid PITR point archive: ' . $tar->last_error ); }
		try {
			if ( $tar->numFiles < 3 || $tar->numFiles > 100000 ) { throw new RuntimeException( 'Invalid PITR point inventory.' ); }
			$entries = array();
			for ( $index = 0; $index < $tar->numFiles; ++$index ) {
				$entry = $tar->statIndex( $index );
				BackupPlus_PITR_Manifest::filename( $entry['name'] );
				if ( 0 !== strpos( $entry['name'], $match[1] ) || $entry['name'] === $name || isset( $entries[ $entry['name'] ] ) || $entry['size'] < 1 ) { throw new RuntimeException( 'Invalid PITR point member.' ); }
				$entries[ $entry['name'] ] = $entry;
			}
			return $entries;
		} finally { $tar->close(); }
	}

	public static function validate( $path, $manifest, $locator ) {
		$expected   = array_column( $manifest['artifacts'], 'name' );
		$expected[] = $locator['manifest']['name'];
		$expected[] = 'backup_pitr_' . $manifest['chain_id'] . '_' . $manifest['backup_id'] . '_locator.json';
		$actual     = array_keys( self::inventory( $path ) );
		sort( $expected );
		sort( $actual );
		if ( $actual !== $expected ) { throw new RuntimeException( 'PITR point archive does not match its manifest.' ); }
	}

	/** The small discovery record is read before its manifest binding is known. */
	public static function contents( $path, $name, $limit ) {
		$entries = self::inventory( $path );
		if ( ! isset( $entries[ $name ] ) || $entries[ $name ]['size'] > $limit ) { throw new RuntimeException( 'Invalid PITR discovery record.' ); }
		$input = fopen( $path, 'rb' );
		try {
			if ( ! $input || 0 !== fseek( $input, $entries[ $name ]['offset'] ) ) { throw new RuntimeException( 'Could not read PITR discovery record.' ); }
			$data = BackupPlus_Archive_IO::read_up_to( $input, $entries[ $name ]['size'] );
			if ( strlen( $data ) !== $entries[ $name ]['size'] ) { throw new RuntimeException( 'Incomplete PITR discovery record.' ); }
			return $data;
		} finally { if ( $input ) { fclose( $input ); }
		}
	}

	/** Remove only an extracted member whose point archive is still present. */
	public static function discard_staged( $directory, $name ) {
		if ( ! is_string( $name ) || ! preg_match( '/\A(backup_pitr_[a-f0-9]{32}_[a-f0-9]{32}_)(?:full\.sql\.(?:gz|zst)|log[0-9]+\.bin\.(?:gz|zst)|manifest\.json)(?:\.crypt)?\z/D', $name, $match ) ) { return; }
		$archive = $directory . '/' . $match[1] . 'point.tar';
		$path    = $directory . '/' . $name;
		if ( ! is_link( $archive ) && is_file( $archive ) && ! is_link( $path ) && is_file( $path ) ) { unlink( $path ); }
	}

	/** Copy one bounded member and authenticate it before exposing it to a restore. */
	public static function extract( $path, $artifact, $target ) {
		BackupPlus_PITR_Manifest::filename( $artifact['name'] );
		BackupPlus_PITR_Manifest::hash( $artifact['sha256'] );
		if ( ! isset( $artifact['size'] ) || ! is_int( $artifact['size'] ) || $artifact['size'] < 1 ) { throw new RuntimeException( 'Invalid PITR member size.' ); }
		$entries = self::inventory( $path );
		if ( ! isset( $entries[ $artifact['name'] ] ) || $entries[ $artifact['name'] ]['size'] !== $artifact['size'] ) { throw new RuntimeException( 'PITR point member is missing or has the wrong size.' ); }
		if ( is_link( $target ) ) { throw new RuntimeException( 'PITR staging cannot use a symbolic link.' ); }
		if ( is_file( $target ) && filesize( $target ) === $artifact['size'] && hash_equals( $artifact['sha256'], hash_file( 'sha256', $target ) ) ) { return $target; }
		$temp = $target . '.' . bin2hex( random_bytes( 8 ) ) . '.tmp';
		BackupPlus_PITR_Process::private_file( $temp );
		try {
			$input  = fopen( $path, 'rb' );
			$output = fopen( $temp, 'wb' );
			try {
				if ( ! $input || ! $output || 0 !== fseek( $input, $entries[ $artifact['name'] ]['offset'] ) || stream_copy_to_stream( $input, $output, $artifact['size'] ) !== $artifact['size'] || ! fflush( $output ) ) { throw new RuntimeException( 'Could not extract PITR point member.' ); }
			} finally { if ( $input ) { fclose( $input );
			} if ( $output ) { fclose( $output ); }
			}
			if ( ! hash_equals( $artifact['sha256'], hash_file( 'sha256', $temp ) ) ) { throw new RuntimeException( 'PITR point member checksum failed.' ); }
			if ( is_link( $target ) || ! rename( $temp, $target ) ) { throw new RuntimeException( 'Could not stage PITR point member.' ); }
			return $target;
		} finally { if ( is_file( $temp ) && ! is_link( $temp ) ) { unlink( $temp ); }
		}
	}
}
