<?php

/** Included by check-pitr.php; every connection is to its disposable source. */

class PITR_Online_Probe extends BackupPlus_PITR_Database {

	public $control;
	public $application;
	public $mode;
	public $exercised = false;
	public $changed   = false;

	public function command( $tool, $arguments ) {
		if ( 'dump' === $tool ) {
			array_splice( $arguments, array_search( '--', $arguments, true ), 0, array( '--where=SLEEP(0.015)=0' ) );
		}
		return parent::command( $tool, $arguments );
	}

	protected function dump_monitor( $guard, $path ) {
		$monitor = parent::dump_monitor( $guard, $path );
		return function ( $status ) use ( $monitor, $guard ) {
			$monitor( $status );
			$running = "SELECT ID FROM information_schema.PROCESSLIST WHERE ID<>CONNECTION_ID() AND INFO LIKE 'SELECT%SLEEP(0.015)%' AND INFO LIKE '%pitr_guard_rows%'";
			if ( $this->exercised || ! $this->control->query( $running ) ) {
				return;
			}
			$this->exercised = true;
			if ( 'lost' === $this->mode ) {
				// The capture account may end its own sessions.
				$this->query( 'KILL ' . (int) $guard->id() );
				$guard->ping();
			} elseif ( 'objects' === $this->mode ) {
				try {
					$this->application->query( 'SET SESSION lock_wait_timeout=1; ALTER VIEW `' . DB_NAME . '`.pitr_guard_view AS SELECT value+1 AS value FROM `' . DB_NAME . '`.pitr_guard_rows' );
					$this->changed = true;
				} catch ( RuntimeException $error ) {
					/* A guard which blocks this DDL is also safe. */
				}
			} else {
				$start = microtime( true );
				$this->application->query( 'UPDATE `' . DB_NAME . '`.pitr_guard_rows SET value=value+1 WHERE id=1' );
				pitr_check( microtime( true ) - $start < 1 && $this->control->query( $running ), 'Writes did not commit while the dump was reading its snapshot.' );
				foreach ( array( 'CREATE TABLE pitr_guard_new (id int) ENGINE=InnoDB', 'ALTER TABLE pitr_zz_guard_target ADD note int', 'DROP TABLE pitr_zz_guard_target', 'RENAME TABLE pitr_zz_guard_target TO pitr_guard_renamed', 'TRUNCATE TABLE pitr_zz_guard_target' ) as $ddl ) {
					pitr_failure(
						function () use ( $ddl ) {
							$this->application->query( 'SET SESSION lock_wait_timeout=1; USE `' . DB_NAME . '`; ' . $ddl );
						},
						'DDL was permitted while the snapshot schema guard was held: ' . $ddl
					);
				}
			}
			$monitor( $status );
		};
	}
}

$application->query( 'USE `' . DB_NAME . '`; CREATE TABLE pitr_guard_rows (id int PRIMARY KEY, value int) ENGINE=InnoDB; CREATE TABLE pitr_zz_guard_target (id int) ENGINE=InnoDB; CREATE VIEW pitr_guard_view AS SELECT value FROM pitr_guard_rows' );
$values = array();
for ( $row = 1; $row <= 200; ++$row ) {
	$values[] = '(' . $row . ',0)';
}
$application->query( 'INSERT INTO `' . DB_NAME . '`.pitr_guard_rows VALUES ' . implode( ',', $values ) );
$control = new BackupPlus_PITR_Session( $observer->command( 'client', array( '--batch', '--raw', '--skip-column-names', '--unbuffered', '--skip-reconnect' ) ), $work );
try {
	foreach ( array( 'writes', 'lost', 'objects' ) as $mode ) {
		$probe              = new PITR_Online_Probe( $config, $work );
		$probe->control     = $control;
		$probe->application = $application;
		$probe->mode        = $mode;
		$path               = $work . '/online-' . $mode . '.sql';
		$failure            = null;
		try {
			$probe->dump( DB_NAME, $path );
		} catch ( RuntimeException $error ) {
			$failure = $error;
		}
		pitr_check( $probe->exercised, 'The online snapshot test did not observe the native dumper reading rows.' );
		$must_fail = 'lost' === $mode || $probe->changed;
		pitr_check( $must_fail === ( null !== $failure ), 'Online snapshot guard/failure result was incorrect: ' . $mode . ( $failure ? ': ' . $failure->getMessage() : '' ) );
		pitr_check( $must_fail ? ! file_exists( $path ) : is_file( $path ), 'A failed online snapshot left a usable dump artifact.' );
	}
	// Backup grants without the backup-lock privilege.
	$admin->query( "CREATE USER 'pitr_without_guard'@'%'; GRANT SELECT, SHOW VIEW, TRIGGER, EVENT ON `" . DB_NAME . "`.* TO 'pitr_without_guard'@'%'", true );
	try {
		$restricted                  = $config;
		$restricted['source_config'] = pitr_client_file( $work . '/restricted.cnf', $engine, $source_port, 'pitr_without_guard' );
		$unprivileged                = new BackupPlus_PITR_Database( $restricted, $work );
		pitr_failure(
			function () use ( $unprivileged, $work ) {
				$unprivileged->dump( DB_NAME, $work . '/unprivileged.sql' );
			},
			'A missing backup-lock privilege silently downgraded the snapshot.'
		);
		pitr_check( ! file_exists( $work . '/unprivileged.sql' ), 'A missing guard privilege produced a baseline.' );
	} finally {
		$admin->query( "DROP USER 'pitr_without_guard'@'%'", true );
	}
	echo "PITR online writes, DDL exclusion, guard loss, schema-object changes and missing privileges passed.\n";
} finally {
	$control->close();
	$application->query( 'USE `' . DB_NAME . '`; DROP VIEW pitr_guard_view; DROP TABLE pitr_guard_rows, pitr_zz_guard_target' );
}
