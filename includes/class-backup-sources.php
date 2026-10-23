<?php
/** Built-in local sources and exact database coverage. */
if ( ! defined( 'ABSPATH' ) ) { die( 'No direct access allowed' ); }

class BackupPlus_Backup_Sources {
	private static $run_sources = null;
	private static $run_paths   = null;

	/** Temporary source map; the engine persists this with the job for resumptions. */
	public static function set_run_sources( $sources, $paths = null ) {
		self::$run_sources = $sources;
		self::$run_paths   = $paths;
	}

	/** Apply the resolved registry from the first execution to retries. */
	public static function frozen_entities( $entities, $full_info ) {
		global $backupplus;
		$paths = is_array( self::$run_paths ) ? self::$run_paths : $backupplus->jobdata_get( 'file_entity_paths', null );
		if ( ! is_array( $paths ) ) { return $entities; }
		if ( ! array_key_exists( 'others', $entities ) ) { unset( $paths['others'] ); }
		if ( ! $full_info ) { return $paths; }
		foreach ( $paths as $id => $path ) {
			$entities[ $id ] = array_merge( isset( $entities[ $id ] ) ? $entities[ $id ] : array( 'description' => $id ), array( 'path' => $path ) );
		}
		return array_intersect_key( $entities, $paths );
	}

	public static function register() {
		add_filter( 'backupplus_backupable_file_entities_final', array( __CLASS__, 'frozen_entities' ), PHP_INT_MAX, 2 );
		add_filter( 'backupplus_backupable_file_entities_final', array( __CLASS__, 'entities' ), 5, 2 );
		add_filter( 'backupplus_backupnow_options', array( __CLASS__, 'request_options' ), 5, 2 );
		add_filter( 'backupplus_exclude_directory', array( __CLASS__, 'exclude' ), 20, 2 );
		add_filter( 'backupplus_exclude_file', array( __CLASS__, 'exclude' ), 20, 2 );
	}

	public static function custom( $entity ) {
		return is_string( $entity ) && (bool) preg_match( '/^custom-[a-p]{16}$/D', $entity );
	}

	public static function absolute( $path ) {
		return is_string( $path ) && '' !== $path && ! preg_match( '#[\x00-\x1f]|(^|[\\\\/])\.\.?([\\\\/]|$)#', $path ) && ( '/' === $path[0] || preg_match( '#^[A-Za-z]:[\\\\/]#', $path ) );
	}

	public static function paths( $paths ) {
		if ( is_string( $paths ) ) { $paths = preg_split( '/\r?\n/', $paths ); }
		$result = array();
		foreach ( (array) $paths as $path ) {
			if ( ! is_string( $path ) || '' === trim( $path ) ) { continue; }
			$path = rtrim( str_replace( '\\', '/', trim( $path ) ), '/' );
			if ( ! self::absolute( $path ) ) { throw new InvalidArgumentException( 'Additional sources require absolute local paths without dot segments.' ); }
			$result[] = $path;
		}
		return array_values( array_unique( $result ) );
	}

	public static function source_id( $path ) {
		// Letters keep the legacy archive parser's numeric split suffix unambiguous.
		return 'custom-' . strtr( substr( hash( 'sha256', $path ), 0, 16 ), '0123456789', 'ghijklmnop' );
	}

	public static function sources() {
		global $backupplus;
		if ( is_array( self::$run_sources ) ) { return self::$run_sources; }
		$frozen = $backupplus->jobdata_get( 'file_sources', null );
		if ( is_array( $frozen ) ) { return $frozen; }
		$sources = array();
		foreach ( self::paths( BackupPlus_Options::get_backupplus_option( 'backupplus_include_more_path', array() ) ) as $path ) {
			$sources[ self::source_id( $path ) ] = array(
				'path' => $path,
				'kind' => is_file( $path ) ? 'file' : 'directory',
			);
		}
		return $sources;
	}

	public static function entities( $entities, $full_info ) {
		$entities['wpcore'] = $full_info ? array(
			'path'        => untrailingslashit( ABSPATH ),
			'description' => __( 'WordPress core', 'backupplus' ),
		) : untrailingslashit( ABSPATH );
		add_filter( 'backupplus_defaultoption_include_wpcore', '__return_false' );
		foreach ( self::sources() as $id => $source ) {
			$entities[ $id ] = $full_info ? array(
				'path'        => $source['path'],
				'description' => __( 'Additional files', 'backupplus' ) . ': ' . $source['path'],
			) : $source['path'];
		}
		return $entities;
	}

	public static function included( $entity ) {
		if ( 'wpcore' === $entity ) { return (bool) BackupPlus_Options::get_backupplus_option( 'backupplus_include_wpcore', false ); }
		if ( self::custom( $entity ) ) { return (bool) BackupPlus_Options::get_backupplus_option( 'backupplus_include_more', false ); }
		return (bool) BackupPlus_Options::get_backupplus_option( 'backupplus_include_' . $entity, apply_filters( 'backupplus_defaultoption_include_' . $entity, true ) );
	}

	public static function within( $path, $root ) {
		$root = rtrim( str_replace( '\\', '/', $root ), '/' );
		$path = str_replace( '\\', '/', $path );
		return $root && ( $root === $path || 0 === strpos( $path, $root . '/' ) );
	}

	public static function exclude( $excluded, $path ) {
		global $backupplus;
		if ( $excluded || self::within( $path, realpath( $backupplus->backups_dir_location() ) ?: $backupplus->backups_dir_location() ) ) { return true; }
		$status = $backupplus->jobdata_get( 'filecreating_substatus', array() );
		if ( isset( $status['e'] ) && 'wpcore' === $status['e'] ) {
			$uploads = $backupplus->wp_upload_dir();
			foreach ( array( WP_CONTENT_DIR, WP_PLUGIN_DIR, WPMU_PLUGIN_DIR, $uploads['basedir'] ) as $root ) {
				if ( self::within( $path, realpath( $root ) ?: $root ) ) { return true; }
			}
		}
		return false;
	}

	public static function validate_sources( $entities, $sources ) {
		foreach ( $entities as $entity => $unused ) {
			if ( ! isset( $sources[ $entity ] ) ) { continue; }
			$path = $sources[ $entity ]['path'];
			if ( ! is_readable( $path ) || ( ! is_file( $path ) && ! is_dir( $path ) ) || self::exclude( false, realpath( $path ) ?: $path ) || BackupPlus_Credential_Key_Store::exclude( false, realpath( $path ) ?: $path ) ) {
				throw new RuntimeException( 'Selected source is missing, unreadable or excluded: ' . $path );
			}
		}
	}

	public static function connections( $value = null ) {
		$value  = null === $value ? BackupPlus_Options::get_backupplus_option( 'backupplus_extradbs', array() ) : $value;
		$result = array();
		foreach ( (array) $value as $id => $connection ) {
			if ( ! ctype_digit( (string) $id ) || (int) $id < 1 || ! is_array( $connection ) ) { continue; }
			if ( isset( $connection['dbinfo'] ) ) { $connection = $connection['dbinfo']; }
			if ( empty( $connection['host'] ) && empty( $connection['name'] ) ) { continue; }
			$result[ (int) $id ] = array_merge(
				array(
					'host'    => '',
					'user'    => '',
					'pass'    => '',
					'name'    => '',
					'prefix'  => '',
					'enabled' => true,
				),
				$connection
			);
		}
		return $result;
	}

	public static function connect( $info ) {
		require_once BACKUPPLUS_DIR . '/backup.php';
		foreach ( array( 'host', 'name', 'user', 'pass' ) as $field ) {
			if ( ! isset( $info[ $field ] ) || ! is_string( $info[ $field ] ) || ( 'pass' !== $field && '' === $info[ $field ] ) ) { throw new RuntimeException( 'Enter a host, existing database, username and password.' ); }
		}
		$db = new BackupPlus_WPDB_OtherDB( $info['user'], $info['pass'], $info['name'], $info['host'] );
		$db->suppress_errors( true );
		if ( ! empty( $db->error ) || ! $db->ready || $db->get_var( 'SELECT DATABASE()' ) !== $info['name'] ) { throw new RuntimeException( 'Database connection failed. Check the connection details and that the target database exists.' ); }
		return $db;
	}

	public static function selection( $selection ) {
		if ( ! is_array( $selection ) ) { throw new InvalidArgumentException( 'Invalid database selection.' ); }
		$result = array();
		foreach ( $selection as $id => $entry ) {
			if ( ( 'wp' !== $id && ( ! ctype_digit( (string) $id ) || (int) $id < 1 ) ) || ! is_array( $entry ) ) { throw new InvalidArgumentException( 'Invalid database selection ID.' ); }
			$mode = isset( $entry['mode'] ) ? $entry['mode'] : 'all';
			if ( ! in_array( $mode, array( 'all', 'selected' ), true ) ) { throw new InvalidArgumentException( 'Invalid table selection mode.' ); }
			$tables = isset( $entry['tables'] ) ? $entry['tables'] : array();
			if ( ! is_array( $tables ) ) { throw new InvalidArgumentException( 'Table names must be an array.' ); }
			foreach ( $tables as $table ) {
				if ( ! is_string( $table ) || '' === $table || strlen( $table ) > 64 || preg_match( '#[\x00-\x1f/\\\\]#', $table ) ) { throw new InvalidArgumentException( 'Invalid table name.' ); }
			}
			$enabled = ! isset( $entry['enabled'] ) || ! empty( $entry['enabled'] );
			if ( $enabled && 'selected' === $mode && ! $tables ) { throw new InvalidArgumentException( 'Choose at least one table in selected mode.' ); }
			$result[ $id ] = array(
				'enabled' => $enabled,
				'mode'    => $mode,
				'tables'  => array_values( array_unique( $tables ) ),
			);
		}
		return $result;
	}

	public static function sanitize_selection( $value ) {
		try { return self::selection( is_string( $value ) ? json_decode( $value, true ) : $value ); }
		catch ( Exception $e ) {
			add_settings_error( 'backupplus_database_selection', 'invalid_selection', $e->getMessage() );
			return BackupPlus_Options::get_backupplus_option( 'backupplus_database_selection', array() );
		}
	}

	public static function sanitize_paths( $value ) {
		try { return self::paths( $value ); }
		catch ( Exception $e ) {
			add_settings_error( 'backupplus_include_more_path', 'invalid_path', $e->getMessage() );
			return BackupPlus_Options::get_backupplus_option( 'backupplus_include_more_path', array() );
		}
	}

	public static function resolve( $entry, $tables, $prefix ) {
		$entry = self::selection( array( 'wp' => $entry ) )['wp'];
		if ( ! $entry['enabled'] ) { return array(); }
		if ( 'selected' === $entry['mode'] ) {
			$missing = array_diff( $entry['tables'], $tables );
			if ( $missing ) { throw new RuntimeException( 'Selected tables no longer exist: ' . implode( ', ', $missing ) ); }
			return $entry['tables'];
		}
		$case_distinct = count( array_unique( array_map( 'strtolower', $tables ) ) ) !== count( $tables );
		return array_values(
			array_filter(
				$tables,
				function ( $table ) use ( $prefix, $case_distinct ) {
					return '' === $prefix || ( $case_distinct ? 0 === strpos( $table, $prefix ) : 0 === stripos( $table, $prefix ) );
				}
			)
		);
	}

	public static function plan_databases( $options ) {
		global $backupplus, $wpdb;
		$selection   = self::selection( array_replace( (array) BackupPlus_Options::get_backupplus_option( 'backupplus_database_selection', array() ), isset( $options['database_selection'] ) ? $options['database_selection'] : array() ) );
		$connections = self::connections();
		foreach ( $selection as $id => $entry ) {
			if ( 'wp' !== $id && ! isset( $connections[ $id ] ) && $entry['enabled'] ) { throw new RuntimeException( 'Selected database connection no longer exists.' ); }
		}
		$dbs = apply_filters( 'backupplus_backup_databases', array( 'wp' => 'begun' ) );
		foreach ( $connections as $id => $info ) { $dbs[ $id ] = array(
			'status' => 'begun',
			'dbinfo' => $info,
		); }
		$coverage = array();
		foreach ( $dbs as $id => $database ) {
			$info  = 'wp' === $id ? array() : $database['dbinfo'];
			$entry = isset( $selection[ $id ] ) ? $selection[ $id ] : array(
				'enabled' => 'wp' === $id || ! isset( $info['enabled'] ) || ! empty( $info['enabled'] ),
				'mode'    => 'all',
				'tables'  => array(),
			);
			if ( ! $entry['enabled'] ) { unset( $dbs[ $id ] );
				continue; }
			$db     = 'wp' === $id ? $wpdb : self::connect( $info );
			$tables = $db->get_col( 'SHOW TABLES' );
			if ( $db->last_error ) { throw new RuntimeException( 'Could not discover database tables.' ); }
			$prefix   = 'wp' === $id ? ( BackupPlus_Options::get_backupplus_option( 'backupplus_backupdb_nonwp', false ) ? '' : $backupplus->get_table_prefix( true ) ) : ( isset( $info['prefix'] ) ? $info['prefix'] : '' );
			$resolved = self::resolve( $entry, $tables, $prefix );
			if ( 'all' === $entry['mode'] ) {
				$resolved = array_values(
					array_filter(
						$resolved,
						function ( $table ) use ( $prefix, $id, $info ) {
							return apply_filters( 'backupplus_backup_table', true, $table, $prefix, $id, $info );
						}
					)
				);
			}
			$coverage[ $id ] = array(
				'mode'            => $entry['mode'],
				'tables'          => $resolved,
				'partial'         => count( $resolved ) !== count( $tables ),
				'eligible_prefix' => $prefix,
			);
		}
		if ( ! $dbs ) { throw new RuntimeException( 'No databases are enabled for this backup.' ); }
		return array( $dbs, $coverage );
	}

	public static function request_options( $options, $request ) {
		if ( isset( $request['database_selection'] ) ) {
			$selection                     = is_string( $request['database_selection'] ) ? json_decode( $request['database_selection'], true ) : $request['database_selection'];
			$options['database_selection'] = self::selection( $selection );
		} elseif ( isset( $request['onlythesetableentities'] ) && is_array( $request['onlythesetableentities'] ) ) {
			$selection = array(
				'wp' => array(
					'enabled' => false,
					'mode'    => 'selected',
					'tables'  => array(),
				),
			);
			foreach ( self::connections() as $id => $info ) { $selection[ $id ] = $selection['wp']; }
			foreach ( $request['onlythesetableentities'] as $row ) {
				if ( ! isset( $row['name'], $row['value'] ) || ! preg_match( '/^backupplus_include_tables_(wp|[1-9][0-9]*)(?:\[\])?$/D', $row['name'], $m ) ) { throw new InvalidArgumentException( 'Invalid legacy table selection.' ); }
				$selection[ $m[1] ]['enabled']  = true;
				$selection[ $m[1] ]['mode']     = 'selected';
				$selection[ $m[1] ]['tables'][] = $row['value'];
			}
			$options['database_selection'] = self::selection( $selection );
		}
		return $options;
	}

	/** Include imported source IDs in restore/history interfaces even when no longer configured. */
	public static function restore_entities( $entities, $backup ) {
		foreach ( $backup as $id => $value ) {
			if ( self::custom( $id ) ) {
				$source          = isset( $backup['file_sources'][ $id ] ) ? $backup['file_sources'][ $id ] : array(
					'path' => '',
					'kind' => 'directory',
				);
				$entities[ $id ] = array(
					'path'        => $source['path'],
					'kind'        => $source['kind'],
					'description' => __( 'Additional files', 'backupplus' ) . ': ' . ( $source['path'] ?: $id ),
				);
			} elseif ( preg_match( '/^db[1-9][0-9]*$/D', (string) $id ) ) {
				$entities[ $id ] = array(
					'path'        => '',
					'description' => __( 'External database', 'backupplus' ) . ' ' . substr( $id, 2 ),
				);
			}
		}
		return $entities;
	}
	/** Recover portable coverage and original destinations from locally available archives. */
	public static function archive_metadata( $backup, $id, $path ) {
		if ( ! is_readable( $path ) || BackupPlus_Encryption::is_file_encrypted( $path ) ) { return $backup; }
		if ( self::custom( $id ) && class_exists( 'ZipArchive' ) ) {
			$zip = new ZipArchive();
			if ( true === $zip->open( $path ) ) {
				$index    = $zip->locateName( 'backupplus-manifest.json' );
				$stat     = false !== $index ? $zip->statIndex( $index ) : false;
				$manifest = $stat && $stat['size'] < 4194304 ? json_decode( $zip->getFromIndex( $index ), true ) : null;
				$zip->close();
				if ( is_array( $manifest ) && isset( $manifest['type'], $manifest['source']['path'], $manifest['source']['kind'] ) && $id === $manifest['type'] && self::absolute( $manifest['source']['path'] ) && in_array( $manifest['source']['kind'], array( 'file', 'directory' ), true ) ) {
					$backup['file_sources'][ $id ] = array_intersect_key( $manifest['source'], array_flip( array( 'path', 'kind' ) ) );
				}
			}
		} elseif ( preg_match( '/^db([1-9][0-9]*)?$/D', $id ) ) {
			$stream = gzopen( $path, 'rb' );
			if ( $stream ) {
				for ( $line = 0; $line < 100 && ! gzeof( $stream ); ++$line ) {
					$text   = gzgets( $stream, 1048576 );
					$prefix = '# BackupPlus database coverage: ';
					if ( 0 === strpos( $text, $prefix ) ) {
						$coverage = json_decode( substr( $text, strlen( $prefix ) ), true );
						if ( is_array( $coverage ) && isset( $coverage['mode'], $coverage['tables'], $coverage['partial'] ) && is_array( $coverage['tables'] ) ) {
							$backup['database_coverage'][ 'db' === $id ? 'wp' : substr( $id, 2 ) ] = array_intersect_key( $coverage, array_flip( array( 'mode', 'tables', 'partial', 'eligible_prefix' ) ) );
						}
						break;
					}
				}
				gzclose( $stream );
			}
		}
		return $backup;
	}

}
