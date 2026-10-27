<?php
/** Read component names from local backup archives before a selective restore. */
if ( ! defined( 'ABSPATH' ) ) { die( 'No direct access allowed' ); }

class BackupPlus_Restore_Inventory {
	/**
	 * @param array  $files Archive basenames, in restore order.
	 * @param string $ticket Prepared age archive ticket.
	 * @param string $job Restore job nonce, when bound to a job.
	 * @param string $type Entity type (plugins or themes).
	 * @param int|null $timestamp Backup timestamp for an unclaimed age ticket.
	 * @return array|WP_Error Component names, or an error when inventory is unavailable.
	 */
	public static function components( $files, $ticket = '', $job = '', $type = 'plugins', $timestamp = null ) {
		global $backupplus;
		if ( ! in_array( $type, array( 'plugins', 'themes' ), true ) ) { return new WP_Error( 'invalid_inventory_type', 'Unsupported component type.' ); }
		require_once BACKUPPLUS_DIR . '/includes/class-zip.php';
		require_once BACKUPPLUS_DIR . '/includes/class-tar.php';
		$components = array();
		ksort( $files );
		foreach ( $files as $file ) {
			if ( ! is_string( $file ) || $file !== basename( $file ) ) { return new WP_Error( 'invalid_archive', 'Invalid archive filename.' ); }
			$path = $backupplus->backups_dir_location() . '/' . $file;
			if ( BackupPlus_Encryption::is_file_encrypted( $file ) ) {
				$prepared = BackupPlus_Age_Restore::file( $path, $ticket, $job, $timestamp );
				if ( ! $prepared ) { return new WP_Error( 'decrypt_failed', 'Prepare the encrypted archive again before restoring.' ); }
				$path = $prepared['fullpath'];
			}
			if ( ! is_file( $path ) || ! is_readable( $path ) ) { return new WP_Error( 'archive_unavailable', 'The archive is unavailable for component selection.' ); }
			if ( BackupPlus_Archive_Compression::is_zstd( $path ) ) {
				$archive = new BackupPlus_Tar();
			} elseif ( preg_match( '/\.tar(?:\.gz|\.bz2)?$/i', $file ) ) {
				return new WP_Error( 'unsupported_inventory', 'Selective restores are unavailable for this archive format.' );
			} else {
				$archive = class_exists( 'ZipArchive' ) ? new BackupPlus_ZipArchive() : new BackupPlus_PclZip();
			}
			if ( true !== $archive->open( $path, 4 ) || false === $archive->numFiles ) { return new WP_Error( 'invalid_archive', 'Could not read the archive for component selection.' ); }
			$manifest = null;
			for ( $i = 0; $i < $archive->numFiles; ++$i ) {
				$entry = $archive->statIndex( $i );
				if ( ! is_array( $entry ) || ! isset( $entry['name'] ) ) { $archive->close();
					return new WP_Error( 'invalid_archive', 'Could not inspect the archive contents.' ); }
				$name = $entry['name'];
				if ( 'backupplus-manifest.json' === $name ) {
					$manifest = json_decode( $archive->getFromIndex( $i ), true );
					continue;
				}
				$parts = explode( '/', trim( $name, '/' ) );
				$roots = array( $type );
				if ( 'plugins' === $type ) { $roots[] = basename( WP_PLUGIN_DIR ); }
				if ( count( $parts ) >= 2 && in_array( $parts[0], $roots, true ) && '' !== $parts[1] && ! in_array( $parts[1], array( '.', '..' ), true ) && ! preg_match( '/[\\\\\x00]/', $parts[1] ) ) {
					$components[ $parts[1] ] = true;
				}
			}
			$archive->close();
			// Version 2 manifests contain cumulative deletions for the restore point.
			if ( is_array( $manifest ) && isset( $manifest['version'], $manifest['root'], $manifest['deleted'] ) && 2 === $manifest['version'] && is_array( $manifest['deleted'] ) ) {
				foreach ( $manifest['deleted'] as $deleted ) {
					$prefix = $manifest['root'] . '/';
					if ( is_string( $deleted ) && 0 === strpos( $deleted, $prefix ) ) {
						$component = substr( $deleted, strlen( $prefix ) );
						if ( '' !== $component && false === strpos( $component, '/' ) ) { unset( $components[ $component ] ); }
					}
				}
			}
		}
		$names = array_keys( $components );
		sort( $names );
		return $names;
	}
}
