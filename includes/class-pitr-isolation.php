<?php

/** Enforce a write barrier while replacing the source database. */
if ( ! defined( 'ABSPATH' ) ) { die( 'No direct access allowed' ); }
require_once __DIR__ . '/class-pitr-setup.php';

class BackupPlus_PITR_Recovery_WPDB extends wpdb {
	private $connection_options;

	public function __construct( $options, $original ) {
		$this->connection_options = $options;
		parent::__construct( $options['user'], isset( $options['password'] ) ? $options['password'] : '', $original->dbname, $options['host'] );
		$this->set_prefix( $original->base_prefix );
		$this->set_blog_id( $original->blogid, $original->siteid );
	}

	/** Preserve the native option file's verified TLS settings in the PHP connection. */
	public function db_connect( $allow_bail = true ) {
		try { $this->dbh = BackupPlus_PITR_Process::mysqli( $this->connection_options ); }
		catch ( RuntimeException $error ) { throw new RuntimeException( 'Could not open the protected recovery connection. ' . $error->getMessage() ); }
		$this->init_charset();
		$this->set_charset( $this->dbh );
		$this->ready = true;
		$this->set_sql_mode();
		$this->select( $this->dbname, $this->dbh );
		$this->query( 'SET SESSION sql_log_bin=0' );
		return true;
	}
}

class BackupPlus_PITR_Isolation {
	public $adapter;
	private $original;
	private $recovery;
	private $receipt;
	private $path;
	private $root;
	private $control;
	private $last_check = 0;
	private $allowed_users;
	private $owns_maintenance = false;
	private $engine;
	private $application;

	public function __construct( $config, $directory, $root ) {
		global $wpdb;
		$this->original = $wpdb;
		$this->root     = $root;
		$this->path     = BackupPlus_PITR_Setup::connection_file( $config, $directory );
		if ( ! is_string( $this->path ) || '' === $this->path ) {
			throw new RuntimeException( 'Enter the restore account username and password in the restore screen.' );
		}
		$this->engine              = $config['engine'];
		$capture_config            = $config;
		$config['source_config']   = $this->path;
		$config['capture_account'] = false;
		$this->adapter             = new BackupPlus_PITR_Database( $config, $directory );
		$app                       = $wpdb->get_var( 'SELECT CURRENT_USER()' );
		$recovery_user             = $this->adapter->query( 'SELECT CURRENT_USER()' )[0][0];
		$app_name                  = $app ? substr( $app, 0, strrpos( $app, '@' ) ) : '';
		$recovery_name             = substr( $recovery_user, 0, strrpos( $recovery_user, '@' ) );
		if ( ! preg_match( '/\A[A-Za-z0-9_.$-]+\z/D', $app_name ) || $app_name === $recovery_name ) { throw new RuntimeException( 'Automatic restore protection requires separate WordPress and recovery database usernames.' ); }
		foreach ( $wpdb->get_col( 'SHOW GRANTS FOR CURRENT_USER()' ) as $grant ) {
			if ( ! preg_match( '/^GRANT (.+) ON (.+) TO /', $grant, $m ) || false !== strpos( $m[1], 'PROXY' ) || ( '*.*' === $m[2] && preg_match( '/ALL PRIVILEGES|SUPER|CONNECTION[_ ]ADMIN|SYSTEM_VARIABLES_ADMIN|READ_ONLY ADMIN/', $m[1] ) ) ) {
				throw new RuntimeException( 'The WordPress database account can bypass write protection or has unverified roles. Ask your hosting provider to use an application account without administrative write-bypass privileges or roles.' );
			}
		}
		$privileges = array();
		foreach ( $this->adapter->query( 'SHOW GRANTS FOR CURRENT_USER()' ) as $grant ) {
			if ( preg_match( '/^GRANT (.+) ON \*\.\* TO /', $grant[0], $m ) ) { foreach ( preg_split( '/\s*,\s*/', $m[1] ) as $privilege ) { $privileges[ $privilege ] = true; }
			}
		}
		$all = isset( $privileges['ALL PRIVILEGES'] );
		if ( ! $all && ! isset( $privileges['CREATE USER'] ) ) { throw new RuntimeException( 'Automatic restore protection cannot temporarily lock application logins. Ask your hosting provider to grant CREATE USER to the separate recovery account.' ); }
		if ( ! $all && ! isset( $privileges['SUPER'] ) && '0' === $this->adapter->query( 'SELECT @@global.log_bin_trust_function_creators' )[0][0] ) { throw new RuntimeException( 'The restore account needs SUPER to recreate functions/triggers with log_bin_trust_function_creators=0. Supply a suitably privileged restore account; the global setting will not be changed.' ); }
		if ( ! $all && ! isset( $privileges['PROCESS'] ) ) { throw new RuntimeException( 'The recovery account needs PROCESS permission to inspect and disconnect in-flight application sessions.' ); }
		if ( ! $all && ! isset( $privileges['SUPER'] ) && ! isset( $privileges[ 'mysql' === $config['engine'] ? 'CONNECTION_ADMIN' : 'CONNECTION ADMIN' ] ) ) { throw new RuntimeException( 'The restore account needs connection administration permission to stop application sessions.' ); }

		$accounts          = array();
		$this->application = $app_name;
		try {
			foreach ( $this->account_state() as $account ) {
				if ( ! preg_match( '/\A[A-Za-z0-9_.:%\/-]+\z/D', $account[0] ) ) { throw new RuntimeException( 'Unsupported application account host pattern.' ); }
				$accounts[] = array(
					'host'   => $account[0],
					'locked' => '1' === $account[1],
				);
			}
		} catch ( Throwable $error ) { throw new RuntimeException( 'The recovery account cannot inspect application login protection. Ask your hosting provider to grant the documented SELECT privileges on the mysql grant tables and CREATE USER to the recovery-only account. ' . $error->getMessage() ); }
		if ( ! $accounts ) { throw new RuntimeException( 'Could not identify the application database accounts to protect.' ); }
		foreach ( $accounts as $account ) {
			if ( ! $this->database_specific( $account ) ) { throw new RuntimeException( 'The WordPress username is shared with another database or has unverified privileges. Use a database-specific application username before restoring.' ); }
		}
		$capture      = new BackupPlus_PITR_Database( $capture_config, $directory );
		$capture_name = explode( '@', $capture->query( 'SELECT CURRENT_USER()' )[0][0] )[0];
		$trusted      = array_unique( array( $app_name, $capture_name, $recovery_name ) );
		if ( $this->adapter->prepared( 'SELECT User FROM mysql.proxies_priv WHERE Proxied_user=?', array(), array( $app_name ) ) ) { throw new RuntimeException( 'A proxy login can impersonate the application account. Ask the host to remove or isolate that access before restoring.' ); }
		$writers = $this->adapter->prepared( "SELECT DISTINCT User FROM mysql.db WHERE ? LIKE Db AND (Insert_priv='Y' OR Update_priv='Y' OR Delete_priv='Y' OR Create_priv='Y' OR Drop_priv='Y' OR Alter_priv='Y' OR Trigger_priv='Y' OR Event_priv='Y' OR Execute_priv='Y') UNION SELECT User FROM mysql.tables_priv WHERE Db=? AND Table_priv<>'' UNION SELECT User FROM mysql.procs_priv WHERE Db=? AND Proc_priv<>''", array(), array( DB_NAME, DB_NAME, DB_NAME ) );
		$writers = array_merge( $writers, $this->adapter->prepared( "SELECT DISTINCT User FROM mysql.columns_priv WHERE Db=? AND (FIND_IN_SET('Insert',Column_priv)>0 OR FIND_IN_SET('Update',Column_priv)>0) UNION SELECT User FROM mysql.user WHERE (Insert_priv='Y' OR Update_priv='Y' OR Delete_priv='Y' OR Create_priv='Y' OR Drop_priv='Y' OR Alter_priv='Y') AND Super_priv<>'Y' AND Create_user_priv<>'Y'", array(), array( DB_NAME ) ) );
		foreach ( $writers as $writer ) { if ( ! in_array( $writer[0], $trusted, true ) ) { throw new RuntimeException( 'Another account or role can write this database. Ask the host to isolate that writer before PITR recovery; no replacement was started.' ); }
		}

		$source = $this->adapter->source( DB_NAME );
		if ( 'mysql' === $config['engine'] && '1' === $this->adapter->query( 'SELECT @@global.super_read_only' )[0][0] ) { throw new RuntimeException( 'The database server has administrator-enforced super-read-only protection. Ask your hosting provider to resolve that state before restoring.' ); }
		// MariaDB read_only binds the restore account too unless it holds READ_ONLY ADMIN; refuse instead of requiring that bypass.
		if ( 'mariadb' === $config['engine'] && '1' === $this->adapter->query( 'SELECT @@global.read_only' )[0][0] ) { throw new RuntimeException( 'The database server is read-only. Ask your hosting provider to resolve that state before restoring.' ); }
		$active = $wpdb->get_row( 'SELECT @@hostname, @@port, @@server_id', ARRAY_N );
		if ( $active !== $this->adapter->query( 'SELECT @@hostname, @@port, @@server_id' )[0] ) { throw new RuntimeException( 'The recovery account connects to a different database server.' ); }
		if ( 'mysql' === $config['engine'] && $wpdb->get_var( 'SELECT @@server_uuid' ) !== $this->adapter->query( 'SELECT @@server_uuid' )[0][0] ) { throw new RuntimeException( 'The recovery account connects to a different database server.' ); }
		if ( $this->adapter->replicated() ) { throw new RuntimeException( 'Automatic recovery cannot isolate a replicated database server. A database administrator must coordinate replica recovery.' ); }
		$this->adapter->query( 'SELECT @@session.sql_log_bin', true );
		$options             = BackupPlus_PITR_Process::connection_options( $this->adapter->defaults );
		$options['host']     = isset( $options['host'] ) ? $options['host'] : 'localhost';
		$this->recovery      = new BackupPlus_PITR_Recovery_WPDB( $options, $wpdb );
		$this->receipt       = array(
			'protection'       => 'application-accounts-v1',
			'events'           => $this->adapter->prepared( 'SELECT EVENT_NAME, STATUS, DEFINER FROM information_schema.EVENTS WHERE EVENT_SCHEMA=? ORDER BY EVENT_NAME', array(), array( DB_NAME ) ),
			'connection'       => array_diff_key(
				$options,
				array(
					'user'     => true,
					'password' => true,
				)
			),
			'application_user' => substr( $app, 0, strrpos( $app, '@' ) ),
			'source'           => $source,
			'database'         => DB_NAME,
			'maintenance'      => ABSPATH . '.maintenance',
			'bin_directory'    => $config['bin_directory'],
			'accounts'         => $accounts,
		);
		$this->allowed_users = array( $this->receipt['application_user'], substr( $recovery_user, 0, strrpos( $recovery_user, '@' ) ) );
		if ( $this->other_clients() ) { throw new RuntimeException( 'Another database account is connected to this database. Stop its writers before restoring. Other databases may remain online.' ); }
	}

	private function event_status( $event, $status ) {
		if ( ! preg_match( '/\A([^@]+)@(.+)\z/D', $event[2], $m ) ) { throw new RuntimeException( 'Unsupported database event identity. No replacement was started.' ); }
		$parts = array( array( $m[1], $m[2] ), array( DB_NAME, $event[0] ) );
		try {
			if ( 'ENABLE' === $status ) { $this->adapter->query( BackupPlus_SQL::render( 'ALTER DEFINER=%a EVENT %i ENABLE', $parts ), true ); }
			else { $this->adapter->query( BackupPlus_SQL::render( 'ALTER DEFINER=%a EVENT %i DISABLE', $parts ), true ); }
		} catch ( InvalidArgumentException $error ) { throw new RuntimeException( 'Unsupported database event identity. No replacement was started.' ); }
	}

	/**
	 * Every host variant of the application username may hold grants only on this database and no write-bypass.
	 * MySQL's SHOW GRANTS FOR needs only SELECT on mysql.user; MariaDB's needs SELECT on the whole mysql schema
	 * (including replication and federated-server credentials), so MariaDB reads the individual grant tables instead.
	 */
	private function database_specific( $account ) {
		$names = array( $this->application, $account['host'] );
		if ( 'mysql' === $this->engine ) {
			foreach ( $this->adapter->query( BackupPlus_SQL::render( 'SHOW GRANTS FOR %a', array( $names ) ) ) as $grant ) {
				if ( ! preg_match( '/^GRANT (.+) ON (.+) TO /', $grant[0], $m ) || false !== strpos( $m[1], 'PROXY' ) || ( '*.*' !== $m[2] && BackupPlus_SQL::identifier( DB_NAME ) . '.*' !== str_replace( array( '\\_', '\\%' ), array( '_', '%' ), $m[2] ) ) || ( '*.*' === $m[2] && preg_match( '/ALL PRIVILEGES|SUPER|CONNECTION_ADMIN|SYSTEM_VARIABLES_ADMIN/', $m[1] ) ) ) { return false; }
			}
			return true;
		}
		foreach ( $this->adapter->prepared( 'SELECT Db FROM mysql.db WHERE User=? AND Host=?', array(), $names ) as $database ) {
			if ( DB_NAME !== str_replace( array( '\\_', '\\%' ), array( '_', '%' ), $database[0] ) ) { return false; }
		}
		// SUPER (1<<15 = 32768, also part of ALL PRIVILEGES), CONNECTION ADMIN (1<<32) and READ_ONLY ADMIN (1<<33) bypass write protection.
		return '0' === $this->adapter->prepared( "SELECT (SELECT COUNT(*) FROM mysql.tables_priv WHERE User=? AND Host=?) + (SELECT COUNT(*) FROM mysql.columns_priv WHERE User=? AND Host=?) + (SELECT COUNT(*) FROM mysql.procs_priv WHERE User=? AND Host=?) + (SELECT COUNT(*) FROM mysql.proxies_priv WHERE User=? AND Host=?) + (SELECT COUNT(*) FROM mysql.roles_mapping WHERE User=? AND Host=?) + (SELECT COUNT(*) FROM mysql.global_priv WHERE User=? AND Host=? AND CAST(JSON_VALUE(Priv, '$.access') AS UNSIGNED) & (32768 | 4294967296 | 8589934592) <> 0)", array(), array_merge( $names, $names, $names, $names, $names, $names ) )[0][0];
	}

	/** Host and lock state of every login sharing the application username, over the control connection once it exists. */
	private function account_state() {
		if ( 'mariadb' === $this->engine ) {
			return $this->adapter->prepared( "SELECT Host, IF(JSON_EXTRACT(Priv,'$.account_locked') IN ('true','1'),1,0) FROM mysql.global_priv WHERE User=? ORDER BY Host", array(), array( $this->application ), $this->control );
		}
		return $this->adapter->prepared( "SELECT Host, IF(account_locked='Y',1,0) FROM mysql.user WHERE User=? ORDER BY Host", array(), array( $this->application ), $this->control );
	}

	/** Whether any account other than the application and recovery logins is using this database. */
	private function other_clients() {
		return '0' !== $this->adapter->prepared( "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE USER NOT IN (?) AND DB=? AND COMMAND<>'Connect'", array(), array( $this->allowed_users, DB_NAME ), $this->control )[0][0];
	}

	public function start() {
		global $wpdb;
		if ( file_exists( $this->root . '/isolation.php' ) ) { throw new RuntimeException( 'A previous recovery protection record exists. Resolve that recovery before starting another restore.' ); }
		BackupPlus_PITR_Process::private_file( $this->root . '/isolation.php', '<?php exit; ?>' . json_encode( $this->receipt ) );
		try {
			BackupPlus_PITR_Process::private_file( ABSPATH . '.maintenance', '<?php $upgrading = time();' );
			$this->owns_maintenance = true;
			$wpdb                   = $this->recovery;
			$this->original->close();
			foreach ( $this->receipt['events'] as $event ) { $this->event_status( $event, 'DISABLE' ); }

			foreach ( $this->receipt['accounts'] as $account ) { $this->adapter->query( BackupPlus_SQL::render( 'ALTER USER %a ACCOUNT LOCK', array( array( $this->application, $account['host'] ) ) ), true ); }
			// Disconnect in-flight application requests; the write barrier also covers reconnecting jobs.
			$user = $this->receipt['application_user'];
			foreach ( $this->adapter->prepared( 'SELECT ID FROM information_schema.PROCESSLIST WHERE USER=? AND ID<>CONNECTION_ID()', array(), array( $user ) ) as $connection ) {
				try { $this->adapter->query( BackupPlus_SQL::render( 'KILL CONNECTION %d', array( $connection[0] ) ), true ); }
				catch ( RuntimeException $error ) {
					if ( $this->adapter->prepared( 'SELECT ID FROM information_schema.PROCESSLIST WHERE ID=?', array(), array( (int) $connection[0] ) ) ) { throw new RuntimeException( 'Could not stop an active application session. Ask your hosting provider to grant connection administration permissions to the recovery account.' ); }
				}
			}
			// Existing event workers can outlive the scheduler; their Connect command distinguishes them from client queries.
			foreach ( $this->adapter->prepared( "SELECT ID FROM information_schema.PROCESSLIST WHERE COMMAND='Connect' AND USER<>'system user' AND DB=? AND ID<>CONNECTION_ID()", array(), array( DB_NAME ) ) as $connection ) {
				try { $this->adapter->query( BackupPlus_SQL::render( 'KILL CONNECTION %d', array( $connection[0] ) ), true ); }
				catch ( RuntimeException $error ) {
					if ( $this->adapter->prepared( 'SELECT ID FROM information_schema.PROCESSLIST WHERE ID=?', array(), array( (int) $connection[0] ) ) ) { throw new RuntimeException( 'Could not stop a running database event. Ask your hosting provider to grant the recovery account permission to terminate event workers.' ); }
				}
			}
			$deadline = microtime( true ) + 10;
			do {
				$remaining = $this->adapter->prepared( "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE USER=? OR (COMMAND='Connect' AND USER<>'system user' AND DB=?)", array(), array( $user, DB_NAME ) )[0][0];
				if ( '0' === $remaining ) { break; }
				usleep( 100000 );
			} while ( microtime( true ) < $deadline );
			if ( '0' !== $remaining ) { throw new RuntimeException( 'Application transactions or scheduled database jobs did not stop within ten seconds. No database replacement was started; retry after those jobs have ended.' ); }
			// A dedicated connection without reconnect: losing it stops recovery rather than silently reopening.
			$this->control = $this->adapter->connection();
			$this->adapter->prepared( 'SET SESSION wait_timeout=86400', array(), array(), $this->control );
			$this->check();
			$this->adapter->restore_monitor = array( $this, 'check' );
		} catch ( Throwable $error ) {
			if ( $this->owns_maintenance ) { $this->release(); }
			else { unlink( $this->root . '/isolation.php' ); }
			throw new RuntimeException( 'Could not establish automatic restore protection; the database was not replaced. ' . $error->getMessage() );
		}
	}

	public function check( $force = false ) {
		if ( ! $force && microtime( true ) - $this->last_check < 0.5 ) { return; }
		if ( $this->adapter->prepared( "SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA=? AND STATUS='ENABLED'", array(), array( DB_NAME ), $this->control ) ) { throw new RuntimeException( 'A database event was enabled during recovery. Recovery stopped and application logins remain locked.' ); }

		if ( $this->other_clients() ) { throw new RuntimeException( 'An unexpected database account connected during recovery. Recovery stopped and the site remains protected; ask the database administrator to isolate that client.' ); }
		$expected = array_map(
			function ( $account ) {
				return array( $account['host'], '1' );
			},
			$this->receipt['accounts']
		);
		if ( $expected !== $this->account_state() ) { throw new RuntimeException( 'Application login protection was lost. Recovery stopped and the site remains in maintenance mode.' ); }
		$this->last_check = microtime( true );
	}

	/** Only call after success, or before database replacement has begun. */
	public function release( $restore_events = true ) {
		global $wpdb;
		$this->adapter->restore_monitor = null;
		$this->close_control();
		foreach ( $restore_events ? $this->receipt['events'] : array() as $event ) {
			if ( 'ENABLED' === $event[1] && $this->adapter->prepared( 'SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA=? AND EVENT_NAME=?', array(), array( DB_NAME, $event[0] ) ) ) { $this->event_status( $event, 'ENABLE' ); }
		}

		foreach ( $this->receipt['accounts'] as $account ) { if ( ! $account['locked'] ) { $this->adapter->query( BackupPlus_SQL::render( 'ALTER USER %a ACCOUNT UNLOCK', array( array( $this->application, $account['host'] ) ) ), true ); }
		}
		if ( $this->owns_maintenance && file_exists( ABSPATH . '.maintenance' ) ) { unlink( ABSPATH . '.maintenance' ); }
		if ( file_exists( $this->root . '/isolation.php' ) ) { unlink( $this->root . '/isolation.php' ); }
		$wpdb = $this->original;
		$wpdb->check_connection( false );
		$this->recovery->close();
	}

	private function close_control() {
		if ( $this->control ) { mysqli_close( $this->control->link() ); }
		$this->control = null;
	}

	/** Closing a preflight connection never removes persistent recovery protection. */
	public function __destruct() {
		$this->close_control();
		global $wpdb;
		if ( $this->recovery && $wpdb !== $this->recovery ) { $this->recovery->close(); }
	}
}
