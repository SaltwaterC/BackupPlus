<?php

/** Exercise real write exclusion on the disposable native source. */
$admin->query( "CREATE USER IF NOT EXISTS 'pitr_application'@'192.0.2.55' IDENTIFIED BY 'unused-fixture' ACCOUNT LOCK; CREATE USER IF NOT EXISTS 'pitr_recovery_weak'@'%' IDENTIFIED BY 'fixture-weak'; GRANT PROCESS ON *.* TO 'pitr_recovery_weak'@'%'", true );
$weak_config                       = $config;
$weak_config['restore_connection'] = pitr_client_file( $directory . '/weak-recovery.cnf', $engine, $source_port, 'pitr_recovery_weak', 'fixture-weak' );
try {
	new BackupPlus_PITR_Isolation( $weak_config, $work, $directory );
	throw new LogicException( 'Insufficient account administration privileges were accepted.' );
} catch ( RuntimeException $error ) { pitr_check( false !== strpos( $error->getMessage(), 'CREATE USER' ), 'The missing account-lock privilege did not produce a specific setup error.' ); }
$documented_config                    = $config;
$documented_config['source_config']   = $config['restore_connection'];
$documented_config['capture_account'] = false;
$documented                           = new BackupPlus_PITR_Database( $documented_config, $work );
pitr_failure(
	function () use ( $documented ) {
		$documented->query( 'SELECT COUNT(*) FROM mysql.servers' );
	},
	'The documented restore grants expose mysql tables beyond the grant tables.'
);
// Every host variant of the application username must be scoped to this database without write-bypass.
foreach ( array(
	'GRANT SELECT ON `pitr_neighbor_scope`.* TO %s',
	'GRANT SELECT ON `' . DB_NAME . '`.pitr_a TO %s',
	'GRANT ' . ( 'mysql' === $engine ? 'CONNECTION_ADMIN' : 'CONNECTION ADMIN' ) . ' ON *.* TO %s',
	'CREATE ROLE pitr_shared_role; GRANT pitr_shared_role TO %s',
) as $shared_grant ) {
	$admin->query( "CREATE USER 'pitr_application'@'192.0.2.56' IDENTIFIED BY 'unused-fixture' ACCOUNT LOCK; " . sprintf( $shared_grant, "'pitr_application'@'192.0.2.56'" ), true );
	try {
		try { new BackupPlus_PITR_Isolation( $config, $work, $directory );
			throw new LogicException( 'An application username with out-of-scope grants was accepted: ' . $shared_grant ); }
		catch ( RuntimeException $error ) { pitr_check( false !== strpos( $error->getMessage(), 'shared with another database' ), 'Out-of-scope application grants were not identified: ' . $error->getMessage() ); }
	} finally { $admin->query( "DROP USER 'pitr_application'@'192.0.2.56'; DROP ROLE IF EXISTS pitr_shared_role", true ); }
}
// An unregistered binary-log consumer would miss unlogged replacement just like a replica; the capture account can stream.
$stream_directory = BackupPlus_PITR_Process::directory( $work . '/binlog-stream' );
$binary_logs      = $source->query( 'SHOW BINARY LOGS' );
$stream           = proc_open( $source->command( 'binlog', array( '--read-from-remote-server', '--stop-never', '--raw', '--result-file=' . $stream_directory . '/', end( $binary_logs )[0] ) ), array( array( 'file', '/dev/null', 'r' ), array( 'file', '/dev/null', 'w' ), array( 'file', $work . '/binlog-stream.err', 'w' ) ), $stream_pipes );
try {
	$stream_deadline = microtime( true ) + 10;
	do {
		$dumping = $observer->query( "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE COMMAND LIKE 'Binlog Dump%'" )[0][0];
		if ( '0' !== $dumping ) { break; }
		usleep( 100000 );
	} while ( microtime( true ) < $stream_deadline );
	pitr_check( '0' !== $dumping, 'The disposable binary-log stream did not start: ' . file_get_contents( $work . '/binlog-stream.err' ) );
	try { new BackupPlus_PITR_Isolation( $config, $work, $directory );
		throw new LogicException( 'Restore protection accepted a server with a binary-log streaming client.' ); }
	catch ( RuntimeException $error ) { pitr_check( false !== strpos( $error->getMessage(), 'replicated database server' ), 'A binary-log streaming client was not identified: ' . $error->getMessage() ); }
} finally {
	proc_terminate( $stream );
	proc_close( $stream );
	$stream_deadline = microtime( true ) + 10;
	while ( '0' !== $observer->query( "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE COMMAND LIKE 'Binlog Dump%'" )[0][0] && microtime( true ) < $stream_deadline ) { usleep( 100000 ); }
	array_map( 'unlink', glob( $stream_directory . '/*' ) );
	unlink( $work . '/binlog-stream.err' );
	rmdir( $stream_directory );
}
if ( 'mariadb' === $engine ) {
	$admin->query( 'SET GLOBAL read_only=1' );
	try {
		try { new BackupPlus_PITR_Isolation( $config, $work, $directory );
			throw new LogicException( 'Restore protection accepted a read-only MariaDB server.' ); }
		catch ( RuntimeException $error ) { pitr_check( false !== strpos( $error->getMessage(), 'read-only' ), 'A read-only MariaDB server was not identified: ' . $error->getMessage() ); }
	} finally { $admin->query( 'SET GLOBAL read_only=0' ); }
}
$before_value = $observer->query( 'SELECT value FROM `' . DB_NAME . '`.pitr_b' )[0][0];
$admin->query( "CREATE USER 'pitr_other_writer'@'%' IDENTIFIED BY 'fixture-other'; GRANT UPDATE ON `" . DB_NAME . "`.* TO 'pitr_other_writer'@'%'", true );
$other_writer_check = function () use ( $config, $work, $directory ) {
	new BackupPlus_PITR_Isolation( $config, $work, $directory );
};
try {
	pitr_failure( $other_writer_check, 'An additional disconnected writer was accepted.' );
} finally {
	$admin->query( "DROP USER 'pitr_other_writer'@'%'", true );
}
$admin->query( "CREATE USER 'pitr_global_writer'@'%' IDENTIFIED BY 'fixture-global'; GRANT UPDATE ON *.* TO 'pitr_global_writer'@'%'", true );
try {
	pitr_failure( $other_writer_check, 'An unconnected ordinary account with global write grants was accepted.' );
} finally {
	$admin->query( "DROP USER 'pitr_global_writer'@'%'", true );
}
$admin->query( "CREATE DATABASE pitr_live_neighbor; CREATE USER 'pitr_live_neighbor'@'%' IDENTIFIED BY 'fixture-neighbor'; GRANT ALL PRIVILEGES ON pitr_live_neighbor.* TO 'pitr_live_neighbor'@'%'", true );
$neighbor_options = array(
	'host'     => '127.0.0.1',
	'port'     => (string) $source_port,
	'user'     => 'pitr_live_neighbor',
	'password' => 'fixture-neighbor',
);
if ( 'mariadb' === $engine ) { $neighbor_options['ssl'] = '0'; }
$neighbor_config                  = $config;
$neighbor_config['source_config'] = BackupPlus_PITR_Process::memory_connection( $neighbor_options );
$neighbor                         = new BackupPlus_PITR_Database( $neighbor_config, $work );
$neighbor->query( 'CREATE TABLE pitr_live_neighbor.values_test (value int) ENGINE=InnoDB; INSERT INTO pitr_live_neighbor.values_test VALUES(0)' );

$inflight = new BackupPlus_PITR_Session( $application->command( 'client', array( '--batch', '--raw', '--skip-column-names', '--unbuffered', '--skip-reconnect' ) ), $work );
$inflight->query( 'START TRANSACTION; UPDATE `' . DB_NAME . '`.pitr_b SET value=888' );
$admin->query( 'SET GLOBAL event_scheduler=ON' );
$application->query( 'CREATE EVENT `' . DB_NAME . "`.pitr_isolation_event ON SCHEDULE AT CURRENT_TIMESTAMP + INTERVAL 1 SECOND ON COMPLETION PRESERVE DO SET @pitr_event_wait=GET_LOCK('pitr_isolation_event',0)+SLEEP(30)" );
$event_deadline = microtime( true ) + 5;
do {
	$event_thread = $observer->query( "SELECT IS_USED_LOCK('pitr_isolation_event')" )[0][0];
	if ( 'NULL' !== $event_thread ) { break; }
	usleep( 100000 );
} while ( microtime( true ) < $event_deadline );
pitr_check( 'NULL' !== $event_thread, 'The disposable scheduled event did not start.' );
$isolation_root = BackupPlus_PITR_Process::directory( $directory . '/isolation-fixture' );
$barrier        = new BackupPlus_PITR_Isolation( $config, $work, $isolation_root );
try {
	$barrier->start();
	$neighbor->query( 'UPDATE pitr_live_neighbor.values_test SET value=value+1' );
	pitr_check( (int) $neighbor->query( 'SELECT value FROM pitr_live_neighbor.values_test' )[0][0] > 0, 'Restoration blocked neighboring application writes.' );
	pitr_check( ! $observer->query( 'SELECT ID FROM information_schema.PROCESSLIST WHERE ID=' . (int) $event_thread ), 'An already-running scheduled database event survived isolation.' );
	pitr_check( array( array( '0', 'ON' ) ) === $observer->query( 'SELECT @@global.read_only, @@global.event_scheduler' ), 'Application protection changed global server write/scheduler settings.' );
	pitr_check( $before_value === $observer->query( 'SELECT value FROM `' . DB_NAME . '`.pitr_b' )[0][0], 'In-flight application work was not disconnected and rolled back.' );
	pitr_failure(
		function () use ( $application ) {
			$application->query( 'UPDATE `' . DB_NAME . '`.pitr_b SET value=999' );
		},
		'A reconnecting application client wrote through the recovery barrier.'
	);
	pitr_failure(
		function () use ( $application ) {
			$application->query( 'SELECT 1' );
		},
		'An application client reconnected while its login should be locked.'
	);
	pitr_check( file_exists( ABSPATH . '.maintenance' ) && file_exists( $isolation_root . '/isolation.php' ), 'Recovery did not retain persistent protection records.' );
	// Fault injection: an administrator reopens the locked login mid-recovery.
	$admin->query( "ALTER USER 'pitr_application'@'%' ACCOUNT UNLOCK", true );
	usleep( 550000 );
	pitr_failure(
		function () use ( $barrier ) {
			$barrier->check();
		},
		'Loss of write protection was not detected.'
	);
} finally {
	$admin->query( 'DROP EVENT IF EXISTS `' . DB_NAME . '`.pitr_isolation_event' );
	$barrier->release();
	$inflight->close();
}
pitr_check( array( array( '0', 'ON' ) ) === $observer->query( 'SELECT @@global.read_only, @@global.event_scheduler' ) && ! file_exists( ABSPATH . '.maintenance' ) && ! file_exists( $isolation_root . '/isolation.php' ), 'Recovery did not restore the original server state after a pre-replacement failure.' );
pitr_check( array( array( '1' ) ) === $application->query( 'SELECT 1' ), 'The application login did not reopen after protection ended.' );
$locked_definition = $admin->query( "SHOW CREATE USER 'pitr_application'@'192.0.2.55'" );
pitr_check( (bool) preg_match( '/\bACCOUNT LOCK\b/', end( $locked_definition[0] ) ), 'A previously locked application account was unlocked by recovery cleanup.' );
$admin->query( 'SET GLOBAL event_scheduler=OFF' );
$barrier = new BackupPlus_PITR_Isolation( $config, $work, $isolation_root );
try {
	$barrier->start();
	$bad_sql = $work . '/invalid-recovery.sql';
	BackupPlus_PITR_Process::private_file( $bad_sql, 'THIS IS NOT VALID SQL;' );
	pitr_failure(
		function () use ( $barrier, $bad_sql ) {
			$barrier->adapter->import( $bad_sql, DB_NAME, true );
		},
		'Invalid recovery SQL unexpectedly succeeded.'
	);
	pitr_check( '0' === $observer->query( 'SELECT @@global.read_only' )[0][0] && file_exists( ABSPATH . '.maintenance' ), 'An import failure reopened the application database.' );
	$receipt = file_get_contents( $isolation_root . '/isolation.php' );
	pitr_check( false === strpos( $receipt, 'password' ) && false === strpos( $receipt, 'recovery_config' ) && false === strpos( $receipt, 'managed_recovery' ), 'The persistent recovery receipt includes a restore credential reference.' );
} finally { $barrier->release(); }
$admin->query( "DROP DATABASE pitr_live_neighbor; DROP USER 'pitr_live_neighbor'@'%'", true );
BackupPlus_PITR_Process::forget_connection( $neighbor_config['source_config'] );
echo "PITR automatic isolation, transaction rollback, reconnecting-writer exclusion, protection loss and cleanup passed.\n";
