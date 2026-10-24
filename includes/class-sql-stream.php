<?php
/** Shared bounded SQL input, plus a transformation-free external importer. */
if ( ! defined( 'ABSPATH' ) ) { die( 'No direct access allowed' ); }
class BackupPlus_SQL_Stream {
	public static function line( $handle, $plain ) {
		return $plain ? fgets( $handle, 1048576 ) : gzgets( $handle, 1048576 );
	}

	/** Yield statements without changing quoted values, comments or delimiters in stored programs. */
	public static function statements( $path ) {
		$handle = gzopen( $path, 'rb' ); // zlib also reads uncompressed SQL.
		if ( false === $handle ) { throw new RuntimeException( 'Could not open SQL dump.' ); }
		$sql       = '';
		$quote     = '';
		$escape    = false;
		$block     = false;
		$delimiter = ';';
		try {
			while ( ! gzeof( $handle ) ) {
				$line = self::line( $handle, false );
				if ( false === $line ) { throw new RuntimeException( 'Could not read SQL dump.' ); }
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
		$db          = BackupPlus_Backup_Sources::connect( $connection );
		$progress    = $backupplus->jobdata_get( 'external_restore_progress', array() );
		$fingerprint = hash_file( 'sha256', $path );
		$destination = hash( 'sha256', serialize( $connection ) );
		$state       = isset( $progress[ $id ] ) ? $progress[ $id ] : array(
			'start'       => 0,
			'complete'    => false,
			'fingerprint' => $fingerprint,
			'destination' => $destination,
		);
		if ( $fingerprint !== $state['fingerprint'] || $destination !== $state['destination'] ) { return new WP_Error( 'external_changed', 'The SQL dump or target connection changed during restoration.' ); }
		if ( $state['complete'] ) { return true; }
		$index = 0;
		try {
			// Dump statements run verbatim on the target's own connection.
			$target = new BackupPlus_SQL( $db->dbh );
			foreach ( BackupPlus_SQL_Stream::statements( $path ) as $sql ) {
				++$index;
				// Session settings must be replayed on every new connection.
				$session = (bool) preg_match( '#^(?:SET\s|/\*![0-9]+\s+SET\s)#i', $sql );
				if ( $index < $state['start'] && ! $session ) { continue; }
				if ( preg_match( '/^(?:CREATE|DROP|ALTER)\s+DATABASE\b|^USE\s/i', $sql ) ) { throw new RuntimeException( 'Database switching is not permitted in an external restore.' ); }
				if ( preg_match( '/^DROP\s+(?:TABLE|VIEW)\s/i', $sql ) ) {
					// Restart at this table's DROP after interruption, including a lost acknowledgement of an INSERT.
					$state['start']  = $index;
					$progress[ $id ] = $state;
					$backupplus->jobdata_set( 'external_restore_progress', $progress );
				}
				if ( false === $target->script( $sql ) ) { throw new RuntimeException( 'External database restore failed at statement ' . $index . '. Check the target permissions and database compatibility, then resume.' ); }
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
