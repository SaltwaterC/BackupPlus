<?php

/** MySQL/MariaDB native adapters with transactional snapshots and a persistent DDL guard. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}
require_once __DIR__ . '/class-pitr-session.php';

class BackupPlus_PITR_Database {

	public $engine;
	public $directory;
	public $defaults;
	public $restore_monitor;
	public $snapshot_events = array();
	private $tools;
	private $snapshot_guard;
	private $last_guard_ping = 0;
	private $sql;

	public function __construct( $config, $directory, $engine = null ) {
		$this->directory = $directory;
		$this->engine    = null === $engine ? $config['engine'] : $engine;
		if ( ! in_array( $this->engine, array( 'mysql', 'mariadb' ), true ) ) {
			throw new RuntimeException( 'Choose mysql or mariadb explicitly.' );
		}
		$prefix      = 'mariadb' === $this->engine ? 'mariadb' : 'mysql';
		$this->tools = array();
		foreach (
			array(
				'client' => $prefix,
				'dump'   => $prefix . 'dump',
				'binlog' => $prefix . 'binlog',
			) as $key => $name
		) {
			if ( 'mariadb' === $this->engine && 'client' !== $key ) {
				$name = $prefix . '-' . $key;
			}
			$this->tools[ $key ] = BackupPlus_PITR_Process::binary( $name, $config['bin_directory'] );
			$version             = BackupPlus_PITR_Process::run( array( $this->tools[ $key ], '--no-defaults', '--version' ), $directory );
			if ( ( 'mariadb' === $this->engine ) !== ( false !== stripos( $version, 'mariadb' ) ) ) {
				throw new RuntimeException( 'Native client vendor does not match the selected engine.' );
			}
		}
		if ( ! empty( $config['capture_account'] ) ) {
			require_once __DIR__ . '/class-pitr-setup.php';
			$config['source_config'] = BackupPlus_PITR_Setup::capture_connection( $config );
		}
		$this->defaults = BackupPlus_PITR_Process::connection_file( $config['source_config'], $directory );
	}

	public function command( $tool, $arguments ) {
		$connection = array( $this->tools[ $tool ], '--defaults-file=' . $this->defaults );
		if ( 'mysql' === $this->engine ) {
			$connection[] = '--no-login-paths';
		}
		return array_merge( $connection, $arguments );
	}

	/** A native-protocol connection with this adapter's credentials and verified TLS settings. */
	public function connection() {
		return new BackupPlus_SQL( BackupPlus_PITR_Process::mysqli( BackupPlus_PITR_Process::connection_options( $this->defaults ) ) );
	}

	/** Lists of strings or null, over the request-lifetime connection unless another is given; failures throw. */
	public function prepared( $template, $parts = array(), $values = array(), $sql = null ) {
		if ( null === $sql ) {
			if ( ! $this->sql ) { $this->sql = $this->connection(); }
			$sql = $this->sql;
		}
		$rows = $sql->rows( $template, $parts, $values, ARRAY_N );
		if ( false === $rows ) { throw new RuntimeException( 'Database query failed. ' . BackupPlus_PITR_Process::failure_detail( 'ERROR ' . $sql->last_errno() . ': ' . $sql->last_error() ) ); }
		return $rows;
	}

	public function __destruct() {
		if ( $this->sql ) { mysqli_close( $this->sql->link() ); }
	}

	public function query( $sql, $unlogged = false ) {
		$arguments = array( '--batch', '--raw', '--skip-column-names', '--execute=' . $sql );
		if ( $unlogged ) { $arguments[] = '--init-command=SET SESSION sql_log_bin=0'; }
		$output = BackupPlus_PITR_Process::run( $this->command( 'client', $arguments ), $this->directory, null, null, 60, $this->guard_monitor() );
		$rows   = array();
		foreach ( explode( "\n", rtrim( $output, "\n" ) ) as $line ) {
			if ( '' !== $line ) {
				$rows[] = explode( "\t", $line );
			}
		}
		return $rows;
	}

	public function source( $database ) {
		$rows = $this->query( 'SELECT @@version, @@server_id, @@hostname, @@port, @@log_bin, @@binlog_format, @@binlog_checksum, @@global.binlog_expire_logs_seconds' );
		if ( 1 !== count( $rows ) || 8 !== count( $rows[0] ) ) {
			throw new RuntimeException( 'Could not inspect database binary logging.' );
		}
		$r = $rows[0];
		if ( ( 'mariadb' === $this->engine ) !== ( false !== stripos( $r[0], 'mariadb' ) ) || ( 'mysql' === $this->engine && ! preg_match( '/^8\.4\./', $r[0] ) ) || ( 'mariadb' === $this->engine && ! preg_match( '/^(?:10\.11|11\.4|11\.8)\./', $r[0] ) ) ) {
			throw new RuntimeException( 'Unsupported server version; use MySQL 8.4 or MariaDB 10.11/11.4/11.8 with matching native clients.' );
		}
		if ( '1' !== $r[4] || 'ROW' !== $r[5] || 'CRC32' !== $r[6] || (int) $r[1] < 1 || ( (int) $r[7] > 0 && (int) $r[7] < 172800 ) ) {
			throw new RuntimeException( 'PITR requires binary logging, a nonzero server ID, ROW format, CRC32 and at least two days of source-log retention (or no automatic expiry).' );
		}
		$grants = $this->query( 'SHOW GRANTS FOR CURRENT_USER()' );
		$this->status();
		foreach ( $this->query( "SHOW GLOBAL VARIABLES WHERE Variable_name IN ('binlog_transaction_compression', 'log_bin_compress', 'binlog_row_value_options')" ) as $setting ) {
			if ( in_array( strtoupper( $setting[1] ), array( 'ON', '1' ), true ) || ( 'binlog_row_value_options' === $setting[0] && '' !== $setting[1] ) ) {
				throw new RuntimeException( 'Compressed and partial-JSON binary-log event formats are not supported by this PITR implementation.' );
			}
		}
		$this->source_scope( $database, $grants );
		if ( $this->prepared( 'SELECT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND REFERENCED_TABLE_SCHEMA IS NOT NULL AND REFERENCED_TABLE_SCHEMA<>?', array(), array( $database, $database ) ) ) {
			throw new RuntimeException( 'Cross-schema foreign-key dependencies are outside the PITR backup scope.' );
		}
		foreach ( $this->prepared( "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE='BASE TABLE'", array(), array( $database ) ) as $table ) {
			if ( 'InnoDB' !== $table[1] ) {
				throw new RuntimeException( 'PITR currently supports InnoDB application tables only.' );
			}
		}
		if ( '0' !== $this->query( 'SELECT @@lower_case_table_names' )[0][0] ) { throw new RuntimeException( 'PITR currently requires case-sensitive database names (lower_case_table_names=0).' ); }
		$identity = 'mysql' === $this->engine ? $this->query( 'SELECT @@server_uuid' )[0][0] : implode( ':', array_slice( $r, 1, 3 ) );
		return array(
			'engine'         => $this->engine,
			'server_version' => $r[0],
			'identity'       => hash( 'sha256', $identity ),
		);
	}

	protected function source_scope( $database, $grants ) {
		if ( ! is_string( $database ) || ! preg_match( '/\A[A-Za-z0-9_$-]+\z/D', $database ) ) { throw new RuntimeException( 'Invalid PITR database scope.' ); }
		$found = $this->prepared( 'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?', array(), array( $database ) );
		if ( array( array( $database ) ) !== $found ) { throw new RuntimeException( 'The capture account cannot access the configured application database.' ); }
		$database_scope = '`' . $database . '`.*';
		$readable       = false;
		foreach ( $grants as $grant ) {
			if ( ! preg_match( '/^GRANT (.+) ON (.+) TO /', $grant[0], $m ) ) { continue; }
			if ( in_array( str_replace( array( '\\_', '\\%' ), array( '_', '%' ), $m[2] ), array( '*.*', $database_scope ), true ) && preg_match( '/(?:^|, )(?:ALL PRIVILEGES|SELECT)(?:, |$)/', $m[1] ) ) { $readable = true; }
		}
		if ( ! $readable ) { throw new RuntimeException( 'The backup account needs SELECT on the application database.' ); }
	}

	public function inspect_log( $path, $start, $database, $reject_ddl = false, $monitor = null ) {
		return BackupPlus_PITR_Binlog::inspect( $path, $start, $database, $this->engine, $reject_ddl, $monitor, true );
	}

	public function logs() {
		$logs = array();
		foreach ( $this->query( 'SHOW BINARY LOGS' ) as $row ) {
			BackupPlus_PITR_Manifest::coordinate(
				array(
					'file'     => $row[0],
					'position' => 4,
				)
			);
			$logs[ $row[0] ] = (int) $row[1];
		}
		if ( ! $logs ) {
			throw new RuntimeException( 'Source binary logs are unavailable.' );
		}
		return $logs;
	}

	public function status() {
		$rows = $this->query( 'mysql' === $this->engine ? 'SHOW BINARY LOG STATUS' : 'SHOW MASTER STATUS' );
		if ( 1 !== count( $rows ) || count( $rows[0] ) < 4 || '' !== $rows[0][2] || '' !== $rows[0][3] ) {
			throw new RuntimeException( 'PITR requires unfiltered source binary logging.' );
		}
		return BackupPlus_PITR_Manifest::coordinate(
			array(
				'file'     => $rows[0][0],
				'position' => (int) $rows[0][1],
			)
		);
	}

	/**
	 * Unlogged replacement must not run where replica channels or binary-log consumers would diverge from it.
	 * Connected replicas and streaming consumers hold a Binlog Dump thread, visible with PROCESS; unlike
	 * SHOW REPLICAS/SHOW SLAVE HOSTS this needs no replication-administration privilege.
	 */
	public function replicated() {
		return $this->query( 'mysql' === $this->engine ? 'SHOW REPLICA STATUS' : 'SHOW ALL SLAVES STATUS' ) || '0' !== $this->query( "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE COMMAND LIKE 'Binlog Dump%'" )[0][0];
	}

	public function fetch_log( $file ) {
		BackupPlus_PITR_Manifest::coordinate(
			array(
				'file'     => $file,
				'position' => 4,
			)
		);
		$directory = BackupPlus_PITR_Process::directory( $this->directory . '/raw-' . bin2hex( random_bytes( 8 ) ) );
		BackupPlus_PITR_Process::run( $this->command( 'binlog', array( '--read-from-remote-server', '--raw', '--verify-binlog-checksum', '--result-file=' . $directory . '/', $file ) ), $this->directory, null, null, 1800, $this->guard_monitor() );
		$path = $directory . '/' . $file;
		if ( ! is_file( $path ) || is_link( $path ) || ! chmod( $path, 0600 ) ) {
			throw new RuntimeException( 'Native client did not produce the requested binary log.' );
		}
		return $path;
	}

	public function dump( $database, $path, $snapshot = true, $tables = array() ) {
		$arguments = array( '--quick', '--hex-blob', '--default-character-set=utf8mb4', '--triggers' );
		if ( $snapshot ) {
			$arguments = array_merge( $arguments, array( '--single-transaction', '--flush-logs', 'mysql' === $this->engine ? '--source-data=2' : '--master-data=2', '--routines', '--events' ) );
			if ( 'mysql' === $this->engine ) {
				$arguments[] = '--init-command=SET SESSION lock_wait_timeout=5';
			}
		} else {
			$arguments[] = '--single-transaction';
			if ( ! $tables ) {
				$arguments[] = '--routines';
				$arguments[] = '--events';
			}
		}
		if ( 'mysql' === $this->engine ) {
			$arguments[] = '--set-gtid-purged=OFF';
			$arguments[] = '--no-tablespaces';
		}
		if ( ! $tables ) {
			$arguments[] = '--databases';
		}
		$arguments[] = '--';
		$arguments[] = $database;
		foreach ( $tables as $table ) {
			$arguments[] = $table;
		}
		$guard = null;
		try {
			if ( $snapshot ) {
				$guard                = $this->guard();
				$this->snapshot_guard = $guard;
				// Recheck engines and database scope while schema changes are excluded.
				$this->source( $database );
				$before                = $this->status();
				$this->snapshot_events = $this->prepared( 'SELECT EVENT_NAME, STATUS FROM information_schema.EVENTS WHERE EVENT_SCHEMA=? ORDER BY EVENT_NAME', array(), array( $database ) );
			}
			BackupPlus_PITR_Process::run( $this->command( 'dump', $arguments ), $this->directory, null, $path, 1800, $snapshot ? $this->dump_monitor( $guard, $path ) : null );
			if ( $snapshot ) {
				$guard->ping();
				$this->verify_snapshot_schema( $database, $before );
				$guard->ping();
			}
			$size = filesize( $path );
			$h    = fopen( $path, 'rb' );
			fseek( $h, max( 0, $size - 4096 ) );
			$tail = stream_get_contents( $h );
			fclose( $h );
			if ( false === strpos( $tail, '-- Dump completed on ' ) ) {
				throw new RuntimeException( 'Native dump is incomplete.' );
			}
			return $snapshot ? BackupPlus_PITR_Manifest::snapshot_coordinate( $path ) : null;
		} catch ( Throwable $error ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
			throw $error;
		} finally {
			$this->snapshot_guard = null;
			if ( $guard ) {
				$guard->close();
			}
		}
	}

	public function guard() {
		$guard = new BackupPlus_PITR_Session( $this->command( 'client', array( '--batch', '--raw', '--skip-column-names', '--unbuffered', '--skip-reconnect', '--connect-timeout=5' ) ), $this->directory, $this->engine );
		try {
			$guard->query( 'SET SESSION lock_wait_timeout=5, wait_timeout=60' );
			$guard->query( 'mysql' === $this->engine ? 'LOCK INSTANCE FOR BACKUP' : 'BACKUP STAGE START; BACKUP STAGE BLOCK_DDL', 7 );
			return $guard;
		} catch ( Throwable $error ) {
			$guard->close();
			throw new RuntimeException( 'Online database snapshots require ' . ( 'mysql' === $this->engine ? 'BACKUP_ADMIN' : 'RELOAD' ) . ' backup-lock privileges and an available schema guard. ' . $error->getMessage() );
		}
	}

	protected function dump_monitor( $guard, $path ) {
		$deadline = microtime( true ) + 10;
		$ready    = false;
		$checked  = 0;
		return function () use ( $guard, $path, $deadline, &$ready, &$checked ) {
			if ( microtime( true ) - $checked < 0.25 ) {
				return;
			}
			$guard->ping();
			$checked = microtime( true );
			if ( ! $ready ) {
				clearstatcache( true, $path );
				try {
					BackupPlus_PITR_Manifest::snapshot_coordinate( $path );
					$ready = true;
				} catch ( RuntimeException $error ) {
					if ( microtime( true ) > $deadline ) {
						throw new RuntimeException( 'Online snapshot startup exceeded ten seconds; the dump was cancelled to bound the initial write pause.' );
					}
				}
			}
		};
	}

	private function guard_monitor() {
		if ( ! $this->snapshot_guard ) {
			return null;
		}
		return function () {
			if ( microtime( true ) - $this->last_guard_ping >= 0.5 ) {
				$this->snapshot_guard->ping();
				$this->last_guard_ping = microtime( true );
			}
		};
	}

	/** Backup locks protect table DDL. Also reject any logged schema-object changes
	 * (including routines/events/views) which the engine may allow under that lock. */
	private function verify_snapshot_schema( $database, $before ) {
		$this->query( 'FLUSH BINARY LOGS' );
		$names = array_keys( $this->logs() );
		$begin = array_search( $before['file'], $names, true );
		if ( false === $begin ) {
			throw new RuntimeException( 'Source logs disappeared during the online snapshot.' );
		}
		$next = $before;
		for ( $index = $begin; $index < count( $names ) - 1; ++$index ) {
			if ( $names[ $index ] !== $next['file'] ) {
				throw new RuntimeException( 'Source log continuity changed during the online snapshot.' );
			}
			$path   = $this->fetch_log( $names[ $index ] );
			$result = $this->inspect_log( $path, $next['position'], $database, true, $this->guard_monitor() );
			$next   = $result['next'];
		}
		if ( $next['file'] !== end( $names ) ) {
			throw new RuntimeException( 'Online snapshot log verification was incomplete.' );
		}
	}

	public function import( $path, $database, $unlogged = false ) {
		$arguments = array( '--binary-mode' );
		if ( $unlogged ) {
			$arguments[] = '--init-command=SET SESSION sql_log_bin=0';
			$arguments[] = '--skip-reconnect';
		}
		if ( null !== $database ) {
			$arguments[] = '--database=' . $database;
		}
		BackupPlus_PITR_Process::run( $this->command( 'client', $arguments ), $this->directory, $path, null, 1800, $this->restore_monitor );
	}

	public function filtered_replay_sql( $paths, $start, $end, $database, $output, $reject_events = false ) {
		if ( ! preg_match( '/\A[A-Za-z0-9_$-]+\z/D', $database ) || ! $paths ) { throw new RuntimeException( 'Invalid database replay scope.' ); }
		$args   = array( '--verify-binlog-checksum', '--database=' . $database, '--start-position=' . $start, '--stop-position=' . $end );
		$args[] = 'mysql' === $this->engine ? '--skip-gtids' : '--skip-annotate-row-events';
		$copies = array();
		try {
			foreach ( $paths as $path ) { $copies[] = BackupPlus_PITR_Binlog::scoped_replay_copy( $path, $this->directory . '/replay-copy-' . bin2hex( random_bytes( 12 ) ) . '.bin', $database, $reject_events ); }
			BackupPlus_PITR_Process::run( $this->command( 'binlog', array_merge( $args, $copies ) ), $this->directory, null, $output, 1800 );
		} finally { foreach ( $copies as $copy ) { if ( is_file( $copy ) ) { unlink( $copy ); }
		}
		}
	}

	public function replay( $paths, $start, $end, $database, $unlogged = false ) {
		if ( ! $paths ) {
			return;
		}
		$sql = $this->directory . '/replay-' . bin2hex( random_bytes( 8 ) ) . '.sql';
		$this->filtered_replay_sql( $paths, $start, $end, $database, $sql );
		// Every file in one invocation/connection preserves session state across rotations.
		$this->import( $sql, $database, $unlogged );
		unlink( $sql );
	}
}
