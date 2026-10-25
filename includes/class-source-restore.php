<?php
/** Destination validation and confined merging of additional local sources. */
if ( ! defined( 'ABSPATH' ) ) { die( 'No direct access allowed' ); }
class BackupPlus_Source_Restore {
	public static function destination( $path, $kind ) {
		if ( ! BackupPlus_Backup_Sources::absolute( $path ) || ( '/' === DIRECTORY_SEPARATOR && '/' !== $path[0] ) ) { throw new RuntimeException( 'Restore destinations require an absolute local path without dot segments.' ); }
		$path   = rtrim( str_replace( '\\', '/', $path ), '/' );
		$parent = $path;
		while ( ! file_exists( $parent ) ) {
			if ( is_link( $parent ) ) { throw new RuntimeException( 'Restore destination traverses a symbolic link.' ); }
			if ( $parent === dirname( $parent ) ) { throw new RuntimeException( 'Restore destination is unavailable.' ); }
			$parent = dirname( $parent );
		}
		for ( $check = $path; '/' !== $check && '.' !== $check; $check = dirname( $check ) ) {
			if ( is_link( $check ) ) { throw new RuntimeException( 'Restore destination traverses a symbolic link.' ); }
			if ( $check === dirname( $check ) ) { break; }
		}
		if ( ( $parent !== $path && ! is_dir( $parent ) ) || ! is_writable( $parent ) || ( file_exists( $path ) && ( ( 'file' === $kind && ! is_file( $path ) ) || ( 'directory' === $kind && ! is_dir( $path ) ) ) ) ) { throw new RuntimeException( 'Restore destination has the wrong type or is not writable.' ); }
		if ( BackupPlus_Backup_Sources::exclude( false, $path ) || BackupPlus_Credential_Key_Store::exclude( false, $path ) ) { throw new RuntimeException( 'Restore destination is a protected backup or credential directory.' ); }
		return $path;
	}

	public static function connection( $id, $options ) {
		$mapping = isset( $options['backupplus_external_targets'][ $id ] ) ? $options['backupplus_external_targets'][ $id ] : array();
		if ( ! empty( $mapping['saved'] ) ) {
			$connections = BackupPlus_Backup_Sources::connections();
			if ( ! isset( $connections[ $mapping['saved'] ] ) ) { throw new RuntimeException( 'Select an existing target database connection.' ); }
			return $connections[ $mapping['saved'] ];
		}
		if ( empty( $mapping['connection'] ) ) { throw new RuntimeException( 'Map every selected external dump to a database connection.' ); }
		return $mapping['connection'];
	}

	public static function preflight( $entities, $backup, $options ) {
		if ( ! empty( $backup['pitr'] ) ) {
			if ( isset( $backup['pitr']['state'] ) && in_array( $backup['pitr']['state'], array( 'deleting', 'unavailable' ), true ) ) { throw new RuntimeException( 'This database recovery chain is unavailable.' ); }
			$point    = isset( $options['backupplus_pitr_restore_point'] ) ? $options['backupplus_pitr_restore_point'] : '';
			$baseline = false;
			foreach ( isset( $backup['pitr']['points'] ) ? $backup['pitr']['points'] : array() as $candidate ) {
				if ( $candidate['backup_id'] === $point && 0 === $candidate['increment'] ) { $baseline = true;
					break; }
			}
			if ( ! $baseline ) { throw new RuntimeException( 'Select the full database snapshot to use the ordinary importer. Incremental recovery points require binary-log replay.' ); }
		}
		foreach ( $entities as $id => $unused ) {
			if ( BackupPlus_Backup_Sources::custom( $id ) ) {
				$source      = isset( $backup['file_sources'][ $id ] ) ? $backup['file_sources'][ $id ] : array(
					'path' => '',
					'kind' => 'directory',
				);
				$destination = isset( $options['backupplus_source_destinations'][ $id ] ) ? $options['backupplus_source_destinations'][ $id ] : $source['path'];
				self::destination( $destination, $source['kind'] );
			} elseif ( preg_match( '/^db[1-9][0-9]*$/D', $id ) ) {
				BackupPlus_Backup_Sources::connect( self::connection( $id, $options ) );
			}
		}
	}

	public static function merge( $from, $destination ) {
		self::destination( $destination, is_dir( $from ) ? 'directory' : 'file' );
		if ( is_link( $from ) || is_link( $destination ) ) { throw new RuntimeException( 'Archive restore cannot traverse symbolic links.' ); }
		if ( is_dir( $from ) ) {
			if ( ! is_dir( $destination ) && ! mkdir( $destination, 0775, true ) ) { throw new RuntimeException( 'Could not create restore directory.' ); }
			foreach ( new DirectoryIterator( $from ) as $entry ) {
				if ( $entry->isDot() ) { continue; }
				self::merge( $entry->getPathname(), $destination . '/' . $entry->getFilename() );
			}
		} elseif ( is_file( $from ) ) {
			if ( ! is_dir( dirname( $destination ) ) && ! mkdir( dirname( $destination ), 0775, true ) ) { throw new RuntimeException( 'Could not create restore parent directory.' ); }
			if ( ! copy( $from, $destination ) ) { throw new RuntimeException( 'Could not restore file.' ); }
		} else { throw new RuntimeException( 'Archive source is missing.' ); }
	}

	public static function restore( $working, $id, $backup, $options, $last ) {
		$manifest_path = $working . '/backupplus-manifest.json';
		$manifest      = is_file( $manifest_path ) ? json_decode( file_get_contents( $manifest_path ), true ) : null;
		if ( ! is_array( $manifest ) || $id !== $manifest['type'] || empty( $manifest['source'] ) ) { throw new RuntimeException( 'Custom source metadata is missing from the archive.' ); }
		$source      = $manifest['source'];
		$destination = isset( $options['backupplus_source_destinations'][ $id ] ) ? $options['backupplus_source_destinations'][ $id ] : $source['path'];
		$destination = self::destination( $destination, $source['kind'] );
		$from        = $working . '/' . $id . ( 'file' === $source['kind'] ? '/payload' : '' );
		if ( file_exists( $from ) ) { self::merge( $from, $destination ); }
		if ( $last ) {
			foreach ( $manifest['deleted'] as $relative ) {
				if ( ! is_string( $relative ) || 0 !== strpos( $relative, $id . '/' ) || preg_match( '#[\\\\:\x00]|(^|/)\.\.?(/|$)|//#', $relative ) ) { throw new RuntimeException( 'Unsafe custom source deletion.' ); }
				$suffix = substr( $relative, strlen( $id ) + 1 );
				if ( 'file' === $source['kind'] ) { if ( 'payload' !== $suffix ) { throw new RuntimeException( 'Invalid file deletion.' );
				} $target = $destination;
				} else { if ( '' === $suffix ) { continue;
				} $target = $destination . '/' . $suffix; }
				self::destination( $target, is_dir( $target ) ? 'directory' : 'file' );
				global $backupplus;
				foreach ( array( $backupplus->backups_dir_location(), BackupPlus_Credential_Key_Store::directory() ) as $protected ) {
					if ( $protected && BackupPlus_Backup_Sources::within( $protected, $target ) ) { throw new RuntimeException( 'Incremental deletion contains a protected directory.' ); }
				}
				self::remove( $target );
			}
		}
		self::remove( $working );
		return true;
	}

	public static function remove( $path ) {
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			foreach ( new DirectoryIterator( $path ) as $entry ) { if ( ! $entry->isDot() ) { self::remove( $entry->getPathname() ); }
			}
			if ( ! rmdir( $path ) ) { throw new RuntimeException( 'Could not remove restored staging directory or deleted path.' ); }
		} elseif ( file_exists( $path ) || is_link( $path ) ) {
			if ( ! unlink( $path ) ) { throw new RuntimeException( 'Could not remove deleted path.' ); }
		}
	}
}
