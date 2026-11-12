<?php
/** Shared bounded SQL input, plus a transformation-free external importer. */
if ( ! defined( 'ABSPATH' ) ) { die( 'No direct access allowed' ); }
require_once __DIR__ . '/class-tar.php';
require_once __DIR__ . '/class-external-sql-guard.php';

class BackupPlus_SQL_Stream {
	public static function line( $handle, $plain ) {
		return $plain ? fgets( $handle, 1048576 ) : gzgets( $handle, 1048576 );
	}

	/** Yield statements without changing quoted values, comments or delimiters in stored programs. */
	public static function statements( $path ) {
		$handle = BackupPlus_Archive_Compression::open_read( $path ); // zlib also reads uncompressed SQL.
		if ( false === $handle ) { throw new RuntimeException( 'Could not open SQL dump.' ); }
		$sql       = '';
		$quote     = '';
		$escape    = false;
		$block     = false;
		$delimiter = ';';
		try {
			while ( ! gzeof( $handle ) ) {
				$line = self::line( $handle, false );
				if ( false === $line ) { if ( gzeof( $handle ) ) { break;
				} throw new RuntimeException( 'Could not read SQL dump.' ); }
				if ( '' === trim( $sql ) && '' === $quote && ! $block ) {
					if ( preg_match( '/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $m ) ) { $delimiter = $m[1];
						continue; }
					if ( preg_match( '/^\s*(#|--(?:\s|$))/', $line ) ) { continue; }
				}
				$length = strlen( $line );
				for ( $i = 0; $i < $length; ++$i ) {
					$c    = $line[ $i ];
					$next = $i + 1 < $length ? $line[ $i + 1 ] : '';
					if ( $quote ) {
						$sql .= $c;
						if ( $escape ) { $escape = false; }
						elseif ( '\\' === $c && '`' !== $quote ) { $escape = true; }
						elseif ( $c === $quote ) {
							if ( $next === $quote ) { $sql .= $next;
								++$i;
							} else { $quote = ''; }
						}
						continue;
					}
					if ( $block ) { $sql .= $c;
						if ( '*' === $c && '/' === $next ) { $sql .= '/';
							++$i;
							$block = false;
						} continue; }
					if ( '/' === $c && '*' === $next ) { $block = true;
						$sql                                   .= '/*';
						++$i;
						continue; }
					if ( '#' === $c || ( '-' === $c && '-' === $next && ( $i + 2 >= $length || ctype_space( $line[ $i + 2 ] ) ) ) ) { $sql .= "\n";
						break; }
					if ( in_array( $c, array( "'", '"', '`' ), true ) ) { $quote = $c;
						$sql .= $c;
						continue; }
					if ( substr( $line, $i, strlen( $delimiter ) ) === $delimiter ) {
						if ( '' !== trim( $sql ) ) { yield trim( $sql ); }
						$sql = '';
						$i  += strlen( $delimiter ) - 1;
					} else { $sql .= $c; }
				}
			}
			if ( $quote || $block || '' !== trim( $sql ) ) { throw new RuntimeException( 'Incomplete SQL statement in dump.' ); }
		} finally { gzclose( $handle ); }
	}
}

class BackupPlus_External_Importer {
	public static function restore( $path, $id, $connection ) {
		global $backupplus;
		// With multiple statements enabled the server would run whatever follows a statement it was given.
		if ( defined( 'MYSQL_CLIENT_FLAGS' ) && defined( 'MYSQLI_CLIENT_MULTI_STATEMENTS' ) && ( MYSQL_CLIENT_FLAGS & MYSQLI_CLIENT_MULTI_STATEMENTS ) ) { return new WP_Error( 'external_import_failed', 'External database restore is not available while MYSQL_CLIENT_FLAGS enables multiple statements.' ); }
		$db          = BackupPlus_Backup_Sources::connect( $connection );
		$progress    = $backupplus->jobdata_get( 'external_restore_progress', array() );
		$fingerprint = hash_file( 'sha256', $path );
		// The target is identified without its password: job data is not encrypted, and an unsalted hash of a password could be
		// attacked offline (BPS-10)
		$destination = hash( 'sha256', serialize( array_diff_key( (array) $connection, array( 'pass' => true ) ) ) );
		$state       = isset( $progress[ $id ] ) ? $progress[ $id ] : array(
			'start'       => 0,
			'complete'    => false,
			'fingerprint' => $fingerprint,
			'destination' => $destination,
		);
		// A restore that began before this change recorded the connection with its password
		if ( isset( $state['destination'] ) && is_string( $state['destination'] ) && hash_equals( $state['destination'], hash( 'sha256', serialize( $connection ) ) ) ) {
			$state['destination'] = $destination;
		}
		if ( $fingerprint !== $state['fingerprint'] || $destination !== $state['destination'] ) { return new WP_Error( 'external_changed', 'The SQL dump or target connection changed during restoration.' ); }
		if ( $state['complete'] ) { return true; }
		$index = 0;
		try {
			// Dump statements run verbatim on the target's own connection, once the guard has read them as the server will (BPS-18).
			$target = new BackupPlus_SQL( $db->dbh );
			$guard  = BackupPlus_External_SQL_Guard::for_connection( $target, $connection['name'] );
			foreach ( BackupPlus_SQL_Stream::statements( $path ) as $sql ) {
				++$index;
				try {
					$kind = $guard->inspect( $sql );
				} catch ( RuntimeException $e ) {
					throw new RuntimeException( 'External database restore refused statement ' . $index . ': ' . $e->getMessage() );
				}
				// Session settings must be replayed on every new connection.
				if ( $index < $state['start'] && 'set' !== $kind ) { continue; }
				if ( 'drop table' === $kind || 'drop view' === $kind ) {
					// Restart at this table's DROP after interruption, including a lost acknowledgement of an INSERT.
					$state['start']  = $index;
					$progress[ $id ] = $state;
					$backupplus->jobdata_set( 'external_restore_progress', $progress );
				}
				if ( false === $target->script( $sql ) ) { throw new RuntimeException( 'External database restore failed at statement ' . $index . '. Check the target permissions and database compatibility, then resume.' ); }
				// A SET can change how the server reads quotes in the statements that follow.
				if ( 'set' === $kind ) { $guard->refresh( $target ); }
				do_action( 'backupplus_external_restore_statement', $id, $index );
				if ( 0 === $index % 100 ) { $backupplus->log( 'External database ' . $id . ': restored ' . $index . ' statements.' ); }
			}
			$state['complete'] = true;
			$progress[ $id ]   = $state;
			$backupplus->jobdata_set( 'external_restore_progress', $progress );
			return true;
		} catch ( Exception $e ) { return new WP_Error( 'external_import_failed', $e->getMessage() ); }
	}
}
