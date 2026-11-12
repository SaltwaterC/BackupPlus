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

	/** The source path and kind recorded in history for a custom source, or null when the history has none. */
	public static function recorded( $id, $backup ) {
		$source = isset( $backup['file_sources'][ $id ] ) ? $backup['file_sources'][ $id ] : null;
		if ( ! is_array( $source ) || ! isset( $source['path'], $source['kind'] ) || ! is_string( $source['path'] ) || '' === $source['path'] || ! in_array( $source['kind'], array( 'file', 'directory' ), true ) ) { return null; }
		return array(
			'path' => $source['path'],
			'kind' => $source['kind'],
		);
	}

	/** The destination the administrator entered for a custom source, or null when none was entered. */
	public static function override( $id, $options ) {
		if ( ! isset( $options['backupplus_source_destinations'][ $id ] ) || '' === $options['backupplus_source_destinations'][ $id ] ) { return null; }
		if ( ! is_string( $options['backupplus_source_destinations'][ $id ] ) ) { throw new RuntimeException( 'Restore destinations require an absolute local path without dot segments.' ); }
		return $options['backupplus_source_destinations'][ $id ];
	}

	private static function normalise( $path ) {
		return rtrim( str_replace( '\\', '/', $path ), '/' );
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
		if ( ( ! empty( $options['tables_to_restore'] ) && empty( $options['include_unspecified_tables'] ) ) || ! empty( $options['tables_to_skip'] ) ) {
			if ( ! isset( $entities['db'] ) ) { throw new RuntimeException( 'Selected-table restores require the WordPress database.' ); }
			foreach ( array( 'tables_to_restore', 'tables_to_skip' ) as $field ) {
				if ( ! isset( $options[ $field ] ) ) { continue; }
				if ( ! is_array( $options[ $field ] ) ) { throw new RuntimeException( 'Use exact source database table names.' ); }
				foreach ( $options[ $field ] as $table ) {
					if ( ! is_string( $table ) || '' === $table || preg_match( '/[\x00-\x1f\x7f]/', $table ) ) { throw new RuntimeException( 'Use exact source database table names.' ); }
				}
			}
			foreach ( $entities as $id => $unused ) {
				if ( preg_match( '/^db[1-9][0-9]*$/D', $id ) ) { throw new RuntimeException( 'Selected-table restores do not support external databases.' ); }
			}
		}
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
				$recorded = self::recorded( $id, $backup );
				$override = self::override( $id, $options );
				if ( null === $recorded && null === $override ) { throw new RuntimeException( 'This backup has no recorded destination for an additional source. Enter an absolute destination to restore it.' ); }
				self::destination( null !== $override ? $override : $recorded['path'], null !== $recorded ? $recorded['kind'] : 'directory' );
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
		$source = $manifest['source'];
		if ( ! is_array( $source ) || ! isset( $source['path'], $source['kind'] ) || ! is_string( $source['path'] ) || ! in_array( $source['kind'], array( 'file', 'directory' ), true ) ) { throw new RuntimeException( 'Custom source metadata is missing from the archive.' ); }
		$recorded = self::recorded( $id, $backup );
		$override = self::override( $id, $options );
		if ( null !== $recorded ) {
			// The archive must describe the source the history recorded; a replaced archive must not choose where it is written.
			if ( self::normalise( $source['path'] ) !== self::normalise( $recorded['path'] ) || $source['kind'] !== $recorded['kind'] ) { throw new RuntimeException( 'The archive describes a different source than the backup history records. Restore aborted.' ); }
			$source = $recorded;
		} elseif ( null === $override ) {
			throw new RuntimeException( 'This backup has no recorded destination for an additional source. Enter an absolute destination to restore it.' );
		}
		$destination = self::destination( null !== $override ? $override : $source['path'], $source['kind'] );
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
