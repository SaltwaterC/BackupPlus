<?php
/** Copy the archives of a backup to a directory, fetching them from remote storage when they are not local. */
if ( ! defined( 'ABSPATH' ) ) { die( 'No direct access allowed' ); }

class BackupPlus_CLI_Download {

	const COMPONENT = '/^(db[0-9]*|plugins|themes|uploads|others|mu-plugins|wpcore|credential-keys|custom-[a-p]{16})$/D';

	/** Components of a history row that have archives, narrowed by --type and --components. */
	public static function entities( $backup, $args ) {
		$type = isset( $args['type'] ) ? $args['type'] : 'all';
		if ( ! in_array( $type, array( 'all', 'files', 'database' ), true ) ) { throw new InvalidArgumentException( 'Use --type=all, files or database.' ); }
		$entities = array();
		foreach ( $backup as $key => $value ) {
			if ( ! preg_match( self::COMPONENT, $key ) || ! $value ) { continue; }
			$database = (bool) preg_match( '/^db[0-9]*$/D', $key );
			if ( 'all' === $type || ( 'database' === $type ? $database : ! $database ) ) { $entities[] = $key; }
		}
		if ( isset( $args['components'] ) ) {
			$selected = BackupPlus_CLI::words( $args['components'] );
			if ( array_diff( $selected, $entities ) ) { throw new InvalidArgumentException( 'Unknown, unavailable or incompatible components.' ); }
			$entities = $selected;
		}
		if ( ! $entities ) { throw new InvalidArgumentException( 'No components selected for download.' ); }
		return $entities;
	}

	/** Stop a file chain at one of its recorded restore points. */
	public static function point( $backup, $point ) {
		if ( ! ctype_digit( (string) $point ) || ! isset( $backup['incremental_sets'][ $point ] ) ) { throw new InvalidArgumentException( 'The requested restore point does not belong to this backup chain.' ); }
		foreach ( $backup['incremental_sets'] as $later => $components ) {
			if ( $later <= (int) $point ) { continue; }
			foreach ( $components as $id => $archives ) {
				foreach ( $archives as $index => $file ) { unset( $backup[ $id ][ $index ] ); }
			}
		}
		return $backup;
	}

	/** @return string[] The paths of the copied archives. */
	public static function run( $timestamp, $args ) {
		global $backupplus;
		if ( ! ctype_digit( (string) $timestamp ) ) { throw new InvalidArgumentException( 'Use a backup history timestamp.' ); }
		$backup = BackupPlus_Backup_History::get_history( $timestamp );
		if ( ! $backup ) { throw new InvalidArgumentException( 'Backup timestamp not found.' ); }
		if ( empty( $args['to'] ) || ! is_string( $args['to'] ) ) { throw new InvalidArgumentException( 'Supply --to=<directory>.' ); }
		$entities = self::entities( $backup, $args );
		if ( isset( $args['point'] ) ) { $backup = self::point( $backup, $args['point'] ); }
		if ( ! is_dir( $args['to'] ) && ! mkdir( $args['to'], 0700, true ) ) { throw new RuntimeException( 'Cannot create the target directory.' ); }
		$target = realpath( $args['to'] );
		if ( false === $target || ! is_writable( $target ) ) { throw new RuntimeException( 'The target directory is not writable.' ); }
		$backup['timestamp'] = (int) $timestamp;
		$selected            = array_combine( $entities, $entities );
		try {
			$archives = BackupPlus_CLI_Restore::archives( $backup, $selected );
			$copied   = array();
			$local    = realpath( $backupplus->backups_dir_location() );
			foreach ( $archives as $path ) {
				$destination = $target . '/' . basename( $path );
				if ( $target === $local ) { $copied[] = $destination;
					continue; }
				if ( ( file_exists( $destination ) || is_link( $destination ) ) && ! isset( $args['force'] ) ) { throw new RuntimeException( 'Refusing to overwrite ' . $destination . '. Use --force.' ); }
				$partial = $destination . '.part';
				if ( ! copy( $path, $partial ) || filesize( $partial ) !== filesize( $path ) ) {
					@unlink( $partial );
					throw new RuntimeException( 'Cannot copy ' . basename( $path ) . '.' );
				}
				chmod( $partial, 0600 );
				if ( ! rename( $partial, $destination ) ) {
					@unlink( $partial );
					throw new RuntimeException( 'Cannot place ' . $destination . '.' );
				}
				$copied[] = $destination;
			}
			return $copied;
		} finally {
			if ( isset( $selected['db'] ) && ! empty( $backup['pitr']['baseline_archive'] ) && ! empty( $backup['db'] ) && class_exists( 'BackupPlus_PITR_Archive' ) ) {
				BackupPlus_PITR_Archive::discard_staged( $backupplus->backups_dir_location(), $backup['db'] );
			}
		}
	}
}
