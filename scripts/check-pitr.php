<?php

/** Real native snapshots, log rotation, encrypted metadata and isolated recovery. */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || defined( 'BACKUPPLUS_DIR' ) ) {
	die( 'Use WP-CLI with --skip-plugins=backupplus.' );
}
// Every importer in this fixture must use the redirected disposable DB handle.
define( 'BACKUPPLUS_USE_WPDB', true );
function pitr_check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function pitr_failure( $callback, $message ) {
	try {
		$callback();
	} catch ( RuntimeException $error ) {
		return;
	} throw new RuntimeException( $message );
}
/**
 * Fixture provisioning with the grant sets in docs/binary-log-backups.md; the plugin never grants privileges.
 * Roles: 'backup' (capture), 'restore' (source replacement) and 'recovery' (isolated reconstruction server).
 */
function pitr_grants( $engine, $database, $principal, $role = 'backup' ) {
	if ( ! preg_match( '/\A[A-Za-z0-9_$-]+\z/D', $database ) || ! preg_match( "/\A'[A-Za-z0-9_.$-]+'@'[A-Za-z0-9_.:%\/-]+'\z/D", $principal ) || ! in_array( $role, array( 'backup', 'restore', 'recovery' ), true ) ) { throw new RuntimeException( 'Unsupported database account, database name or role.' ); }
	if ( 'recovery' === $role ) {
		// Reconstruction imports, replays and exports one database on an empty server; SHOW DATABASES proves it is empty.
		$sql = array( 'GRANT ALL PRIVILEGES ON `' . $database . '`.* TO ' . $principal );
		if ( 'mariadb' === $engine ) {
			$sql[] = 'GRANT SHOW DATABASES, REPLICA MONITOR, BINLOG REPLAY, SET USER ON *.* TO ' . $principal;
			$sql[] = 'GRANT SELECT ON mysql.proc TO ' . $principal;
		} elseif ( 'mysql' === $engine ) {
			$sql[] = 'GRANT SHOW DATABASES, REPLICATION CLIENT ON *.* TO ' . $principal;
			$sql[] = 'GRANT REPLICATION_APPLIER, SESSION_VARIABLES_ADMIN, SET_ANY_DEFINER, SHOW_ROUTINE ON *.* TO ' . $principal;
		} else { throw new RuntimeException( 'Unsupported database engine.' ); }
		return implode( ";\n", $sql );
	}
	$restore = 'restore' === $role;
	$sql     = array( 'GRANT ' . ( $restore ? 'ALL PRIVILEGES' : 'SELECT, SHOW VIEW, TRIGGER, EVENT' ) . ' ON `' . $database . '`.* TO ' . $principal );
	// Restore protection reads account state and writers from these grant tables only.
	$grant_tables = array( 'user', 'db', 'tables_priv', 'columns_priv', 'procs_priv', 'proxies_priv' );
	if ( 'mariadb' === $engine ) {
		$sql[]        = 'GRANT RELOAD, REPLICATION SLAVE, BINLOG MONITOR' . ( $restore ? ', SUPER, PROCESS, CREATE USER, CONNECTION ADMIN, SET USER, BINLOG ADMIN, BINLOG REPLAY, REPLICA MONITOR' : '' ) . ' ON *.* TO ' . $principal;
		$sql[]        = 'GRANT SELECT ON mysql.proc TO ' . $principal;
		$grant_tables = array_merge( array( 'global_priv' ), $grant_tables, array( 'roles_mapping' ) );
	} elseif ( 'mysql' === $engine ) {
		$sql[] = 'GRANT RELOAD, REPLICATION CLIENT, REPLICATION SLAVE' . ( $restore ? ', PROCESS, CREATE USER, SUPER' : '' ) . ' ON *.* TO ' . $principal;
		$sql[] = 'GRANT BACKUP_ADMIN, SHOW_ROUTINE' . ( $restore ? ', REPLICATION_APPLIER, CONNECTION_ADMIN, SESSION_VARIABLES_ADMIN, SET_ANY_DEFINER' : '' ) . ' ON *.* TO ' . $principal;
	} else { throw new RuntimeException( 'Unsupported database engine.' ); }
	foreach ( $restore ? $grant_tables : array() as $table ) { $sql[] = 'GRANT SELECT ON mysql.' . $table . ' TO ' . $principal; }
	return implode( ";\n", $sql );
}
function pitr_client_file( $path, $engine, $port, $user, $password = '' ) {
	BackupPlus_PITR_Process::private_file( $path, "[client]\nuser=" . $user . ( '' === $password ? '' : "\npassword=" . $password ) . "\nhost=127.0.0.1\nport=" . $port . "\n" . ( 'mariadb' === $engine ? "ssl=0\n" : '' ) );
	return $path;
}
$engine           = isset( $args[0] ) ? $args[0] : 'mariadb';
$source_port      = isset( $args[1] ) ? (int) $args[1] : ( 'mysql' === $engine ? 23309 : 23307 );
$recovery_port    = isset( $args[2] ) ? (int) $args[2] : $source_port + 1;
$binary_directory = isset( $args[3] ) ? $args[3] : ( 'mysql' === $engine ? '/tmp/backupplus-mysql-clients' : '/usr/bin' );
$directory        = get_temp_dir() . 'backupplus-pitr-' . bin2hex( random_bytes( 8 ) );
mkdir( $directory, 0700 );
mkdir( $directory . '/archives', 0700 );
$history       = array();
$encryption    = array( 'mode' => 'none' );
$test_cron     = array();
$saved_options = array();
foreach ( array( 'backupplus_pitr_status', 'backupplus_last_backup', 'backupplus_lastmessage', 'backupplus_lastlogmessage', 'backupplus_oneshotnonce', 'backupplus_last_scheduled_f' ) as $name ) {
	$saved_options[ $name ] = get_option( $name, null );
}
add_filter(
	'pre_option_backupplus_backup_history',
	function () use ( &$history ) {
		return $history;
	},
	100
);
add_filter(
	'pre_update_option_backupplus_backup_history',
	function ( $value, $old ) use ( &$history ) {
		$history = $value;
		return $old;
	},
	100,
	2
);
add_filter(
	'pre_option_backupplus_dir',
	function () use ( $directory ) {
		return $directory . '/archives';
	},
	100
);
add_filter(
	'pre_option_cron',
	function () use ( &$test_cron ) {
		return $test_cron;
	},
	100
);
add_filter(
	'pre_update_option_cron',
	function ( $value, $old ) use ( &$test_cron ) {
		$test_cron = $value;
		return $old;
	},
	100,
	2
);
add_filter(
	'backupplus_get_option',
	function ( $value, $name ) use ( &$encryption ) {
		$overrides = array(
			'backupplus_service'                => array(),
			'backupplus_database_encryption'    => $encryption,
			'backupplus_debug_mode'             => false,
			'backupplus_retain_db'              => 20,
			'backupplus_retention_cleanup_mode' => 'after_backup',
		);
		return array_key_exists( $name, $overrides ) ? $overrides[ $name ] : $value;
	},
	100,
	2
);
$remove = function ( $path ) use ( &$remove ) {
	if ( is_dir( $path ) && ! is_link( $path ) ) {
		foreach ( new DirectoryIterator( $path ) as $entry ) {
			if ( ! $entry->isDot() ) {
				$remove( $entry->getPathname() );
			}
		} rmdir( $path );
	} elseif ( file_exists( $path ) || is_link( $path ) ) {
		unlink( $path );
	}
};
register_shutdown_function(
	function () use ( $saved_options ) {
		foreach ( $saved_options as $name => $value ) {
			null === $value ? delete_option( $name ) : update_option( $name, $value );
		}
	}
);
try {
	require dirname( __DIR__ ) . '/backupplus.php';
	$config = array(
		'enabled'       => true,
		'engine'        => $engine,
		'source_config' => $directory . '/source.cnf',
		'database'      => DB_NAME,
		'bin_directory' => $binary_directory,
		'interval'      => 15,
	);
	$work   = BackupPlus_PITR_Process::directory( $directory . '/native' );
	// Root only provisions accounts, changes server settings, injects faults and tears down. Everything under test
	// connects as an account holding the documented grants: the site, capture, restore and recovery accounts.
	$adapter        = function ( $path ) use ( $config, $work ) {
		$adapter_config                  = $config;
		$adapter_config['source_config'] = $path;
		return new BackupPlus_PITR_Database( $adapter_config, $work );
	};
	$admin          = $adapter( pitr_client_file( $directory . '/admin.cnf', $engine, $source_port, 'root' ) );
	$recovery_admin = $adapter( pitr_client_file( $directory . '/recovery-admin.cnf', $engine, $recovery_port, 'root' ) );
	$fixture_users  = array(
		'pitr_application' => array( 'fixture-app', "GRANT ALL PRIVILEGES ON `" . DB_NAME . "`.* TO 'pitr_application'@'%'" ),
		'pitr_backup'      => array( 'fixture-backup', pitr_grants( $engine, DB_NAME, "'pitr_backup'@'%'" ) ),
		// Assertions read data and sessions; observing never writes.
		'pitr_observer'    => array( 'fixture-observer', "GRANT SELECT ON `" . DB_NAME . "`.* TO 'pitr_observer'@'%'; GRANT PROCESS ON *.* TO 'pitr_observer'@'%'" ),
	);
	foreach ( $fixture_users as $user => $account ) {
		$admin->query( "DROP USER IF EXISTS '" . $user . "'@'%'; CREATE USER '" . $user . "'@'%' IDENTIFIED BY '" . $account[0] . "'; " . $account[1], true );
	}
	// Recovered views, routines, triggers and events keep the site account as definer, so it exists, locked, on the recovery server.
	$recovery_admin->query( "DROP USER IF EXISTS 'pitr_reconstruct'@'%', 'pitr_application'@'%'; CREATE USER 'pitr_reconstruct'@'%' IDENTIFIED BY 'fixture-reconstruct'; " . pitr_grants( $engine, DB_NAME, "'pitr_reconstruct'@'%'", 'recovery' ) . "; CREATE USER 'pitr_application'@'%' ACCOUNT LOCK; GRANT ALL PRIVILEGES ON `" . DB_NAME . "`.* TO 'pitr_application'@'%'" );
	pitr_client_file( $config['source_config'], $engine, $source_port, 'pitr_backup', 'fixture-backup' );
	pitr_client_file( $directory . '/recovery.cnf', $engine, $recovery_port, 'pitr_reconstruct', 'fixture-reconstruct' );
	$source      = new BackupPlus_PITR_Database( $config, $work );
	$application = $adapter( pitr_client_file( $directory . '/application.cnf', $engine, $source_port, 'pitr_application', 'fixture-app' ) );
	$observer    = $adapter( pitr_client_file( $directory . '/observer.cnf', $engine, $source_port, 'pitr_observer', 'fixture-observer' ) );
	global $wpdb;
	// With binary logging on, an account without SUPER can create triggers only once the DBA trusts routine creators.
	$trust_creators = (int) $admin->query( 'SELECT @@global.log_bin_trust_function_creators' )[0][0];
	$admin->query( 'SET GLOBAL log_bin_trust_function_creators=1' );
	try {
		$application->query( 'CREATE TABLE IF NOT EXISTS `' . DB_NAME . '`.`' . $wpdb->options . '` (option_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name varchar(191) NOT NULL UNIQUE, option_value longtext NOT NULL, autoload varchar(20) NOT NULL) ENGINE=InnoDB' );
		$application->query( 'INSERT INTO `' . DB_NAME . '`.`' . $wpdb->options . '` (option_name, option_value, autoload) VALUES (\'blogname\',\'PITR Fixture\',\'off\'), (\'siteurl\',\'http://localhost:8080\',\'off\'), (\'home\',\'http://localhost:8080\',\'off\') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)' );
		$application->query( 'DROP TABLE IF EXISTS `' . DB_NAME . '`.pitr_a, `' . DB_NAME . '`.pitr_b, `' . DB_NAME . '`.pitr_future, `' . DB_NAME . '`.pitr_consistency_a, `' . DB_NAME . '`.pitr_consistency_b' );
		$application->query( 'CREATE TABLE `' . DB_NAME . '`.pitr_a (id int PRIMARY KEY, value varbinary(100)) ENGINE=InnoDB; CREATE TABLE `' . DB_NAME . '`.pitr_b (id int PRIMARY KEY, value int) ENGINE=InnoDB; INSERT INTO `' . DB_NAME . '`.pitr_a VALUES (1, 0x62617365); INSERT INTO `' . DB_NAME . '`.pitr_b VALUES (1, 0)' );
		$application->query( 'USE `' . DB_NAME . '`; CREATE OR REPLACE VIEW pitr_view AS SELECT value FROM pitr_b; DROP PROCEDURE IF EXISTS pitr_proc; CREATE PROCEDURE pitr_proc() SELECT value FROM pitr_b; DROP EVENT IF EXISTS pitr_event; CREATE EVENT pitr_event ON SCHEDULE EVERY 1 DAY DISABLE DO SET @pitr_event=1; CREATE TRIGGER pitr_trigger BEFORE UPDATE ON pitr_b FOR EACH ROW SET @pitr_trigger=1' );
		$application->query( 'USE `' . DB_NAME . '`; CREATE TABLE pitr_consistency_a (value int) ENGINE=InnoDB; CREATE TABLE pitr_consistency_b (value int) ENGINE=InnoDB; INSERT INTO pitr_consistency_a VALUES (0); INSERT INTO pitr_consistency_b VALUES (0); DROP PROCEDURE IF EXISTS pitr_writer' );
		$writer_definition = $work . '/writer-definition.sql';
		BackupPlus_PITR_Process::private_file( $writer_definition, "DELIMITER //\nCREATE PROCEDURE pitr_writer() BEGIN DECLARE n INT DEFAULT 0; WHILE n < 60 DO START TRANSACTION; UPDATE pitr_consistency_a SET value=n; DO SLEEP(0.02); UPDATE pitr_consistency_b SET value=n; COMMIT; SET n=n+1; END WHILE; END//\nDELIMITER ;\n" );
		$application->import( $writer_definition, DB_NAME );
	} finally { $admin->query( 'SET GLOBAL log_bin_trust_function_creators=' . $trust_creators ); }
	$capabilities = $source->source( DB_NAME );
	echo 'PITR native server: ' . $capabilities['server_version'] . ".\n";
	// Restore protection and direct replay run with exactly the documented restore grants.
	$restore_login      = null;
	$documented_restore = function ( $user, $password ) use ( $admin, $engine, $directory, $source_port, &$restore_login ) {
		$admin->query( "CREATE USER '" . $user . "'@'%' IDENTIFIED BY '" . $password . "'", true );
		$admin->query( pitr_grants( $engine, DB_NAME, "'" . $user . "'@'%'", 'restore' ), true );
		$restore_login = array( $user, $password );
		return pitr_client_file( $directory . '/' . $user . '.cnf', $engine, $source_port, $user, $password );
	};
	$development_wpdb   = $wpdb;
	$wpdb               = new wpdb( 'pitr_application', 'fixture-app', DB_NAME, '127.0.0.1:' . $source_port );
	$wpdb->set_prefix( $development_wpdb->prefix );
	wp_cache_flush();
	if ( ! in_array( 'isolation-only', $args, true ) ) {
		require __DIR__ . '/check-pitr-external.php';
		if ( in_array( 'external-only', $args, true ) ) { return; }
	}
	if ( in_array( 'isolation-only', $args, true ) ) {
		require_once BACKUPPLUS_DIR . '/includes/class-pitr-isolation.php';
		$config['restore_connection'] = $documented_restore( 'pitr_recovery_meta', 'fixture-meta' );
		require __DIR__ . '/check-pitr-setup.php';
		require __DIR__ . '/check-pitr-isolation.php';
		return;
	}
	require __DIR__ . '/check-pitr-online.php';
	require __DIR__ . '/check-pitr-manual.php';
	$full   = $manual_capture();
	$worker = new BackupPlus_PITR( $config );
	pitr_check( 'database-full' === $full['kind'] && isset( $full['snapshot_coordinate']['file'], $full['snapshot_coordinate']['position'] ), 'Full snapshot coordinates were not persisted.' );
	$id = $full['chain_id'];
	echo "PITR coordinated baseline created; testing concurrent writers.\n";
	$writer_input = $work . '/writer.sql';
	BackupPlus_PITR_Process::private_file( $writer_input, 'CALL pitr_writer();' );
	BackupPlus_PITR_Process::private_file( $work . '/writer.out' );
	BackupPlus_PITR_Process::private_file( $work . '/writer.err' );
	$writer = proc_open(
		'exec ' . implode( ' ', array_map( 'escapeshellarg', $application->command( 'client', array( '--binary-mode', '--database=' . DB_NAME ) ) ) ),
		array(
			0 => array( 'file', $writer_input, 'r' ),
			1 => array( 'file', $work . '/writer.out', 'w' ),
			2 => array( 'file', $work . '/writer.err', 'w' ),
		),
		$pipes
	);
	try {
		$concurrent_coordinate = $source->dump( DB_NAME, $work . '/concurrent.sql' );
	} finally {
		pitr_check( 0 === proc_close( $writer ), 'Concurrent writer failed.' );
	}
	$concurrent_config                  = $config;
	$concurrent_config['source_config'] = $directory . '/recovery.cnf';
	$concurrent_recovery                = new BackupPlus_PITR_Database( $concurrent_config, $work );
	$concurrent_recovery->query( 'DROP DATABASE IF EXISTS `' . DB_NAME . '`' );
	$concurrent_recovery->import( $work . '/concurrent.sql', null );
	$pairs = $concurrent_recovery->query( 'SELECT a.value, b.value FROM `' . DB_NAME . '`.pitr_consistency_a a CROSS JOIN `' . DB_NAME . '`.pitr_consistency_b b' )[0];
	pitr_check( $pairs[0] === $pairs[1], 'The full dump mixed states from concurrently updated tables.' );
	$source->query( 'FLUSH BINARY LOGS' );
	$concurrent_names = array_keys( $source->logs() );
	$concurrent_paths = array();
	$next             = $concurrent_coordinate;
	$last_end         = null;
	$begin            = array_search( $concurrent_coordinate['file'], $concurrent_names, true );
	for ( $i = $begin; $i < count( $concurrent_names ) - 1; ++$i ) {
		$path               = $source->fetch_log( $concurrent_names[ $i ] );
		$inspection         = BackupPlus_PITR_Binlog::inspect( $path, $next['position'], DB_NAME, $engine );
		$concurrent_paths[] = $path;
		$next               = $inspection['next'];
		$last_end           = $inspection['end'];
	}
	$concurrent_recovery->replay( $concurrent_paths, $concurrent_coordinate['position'], $last_end, DB_NAME );
	$pairs = $concurrent_recovery->query( 'SELECT a.value, b.value FROM `' . DB_NAME . '`.pitr_consistency_a a CROSS JOIN `' . DB_NAME . '`.pitr_consistency_b b' )[0];
	pitr_check( array( '59', '59' ) === $pairs, 'Snapshot coordinates skipped or duplicated concurrent transactions during replay.' );
	$application->query( 'USE `' . DB_NAME . '`; BEGIN; UPDATE pitr_a SET value=0x666972737400ff WHERE id=1; UPDATE pitr_b SET value=1 WHERE id=1; COMMIT' );
	echo "PITR capturing and verifying native log ranges.\n";
	unset( $worker );
	$increment = $manual_capture( true );
	$worker    = new BackupPlus_PITR( $config );
	pitr_check( 'database-binlog' === $increment['kind'], 'Manual capture waited for its interval instead of capturing now.' );
	echo "PITR queued manual baseline, immediate increment and file-stage routing passed.\n";
	pitr_check( $increment['start_coordinate'] === $full['snapshot_coordinate'], 'First increment did not start at the snapshot coordinate.' );
	pitr_check( $increment['end_coordinate']['file'] !== $increment['start_coordinate']['file'], 'Capture did not close and rotate the binary log.' );
	$first_end         = $increment['end_coordinate'];
	$last_segment      = end( $increment['segments'] );
	$first_transaction = array(
		'file'     => $last_segment['start']['file'],
		'position' => end( $last_segment['boundaries'] )['position'],
	);
	$application->query( 'USE `' . DB_NAME . '`; BEGIN; UPDATE pitr_a SET value=0x7365636f6e64 WHERE id=1; UPDATE pitr_b SET value=2 WHERE id=1; COMMIT; ALTER TABLE pitr_a ADD note varchar(20) DEFAULT \'added\'' );
	$second = $worker->run( 'capture' );
	pitr_check( $second['start_coordinate'] === $first_end, 'Second increment has a coordinate gap.' );
	pitr_check( $second['previous_backup_id'] === $increment['backup_id'], 'Increment predecessor was not saved.' );
	$verified = $worker->verify( $id, null, null );
	pitr_check( $verified['verified'], 'Real native chain failed verification.' );
	pitr_failure(
		function () use ( $worker, $id, $first_transaction ) {
			$bad = $first_transaction;
			--$bad['position'];
			$worker->recovery_plan( $id, $bad );
		},
		'Mid-transaction recovery accepted.'
	);
	pitr_failure(
		function () use ( $worker, $id ) {
			$worker->delete( $id );
		},
		'Active chain was deleted.'
	);
	$recovery_config                  = $config;
	$recovery_config['source_config'] = $directory . '/recovery.cnf';
	$recovery                         = new BackupPlus_PITR_Database( $recovery_config, $work );
	$recovery->query( 'DROP DATABASE IF EXISTS `' . DB_NAME . '`' );
	$result = $worker->recover( $id, $first_transaction, $recovery_config['source_config'], array(), null, true );
	echo "PITR first historical reconstruction completed.\n";
	pitr_check( '666972737400FF' === $recovery->query( 'SELECT HEX(value) FROM `' . DB_NAME . '`.pitr_a' )[0][0] && '1' === $recovery->query( 'SELECT value FROM `' . DB_NAME . '`.pitr_b' )[0][0], 'Historical replay did not restore both tables at the committed boundary.' );
	pitr_check( count( $recovery->query( 'SHOW COLUMNS FROM `' . DB_NAME . '`.pitr_a' ) ) === 2, 'Later DDL leaked into an earlier recovery point.' );
	pitr_check( '1' === $recovery->query( 'SELECT value FROM `' . DB_NAME . '`.pitr_view' )[0][0] && '1' === $recovery->query( 'CALL `' . DB_NAME . '`.pitr_proc()' )[0][0], 'Views or routines were omitted from the coordinated snapshot.' );
	pitr_check( '1' === $recovery->query( "SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA='" . DB_NAME . "' AND EVENT_NAME='pitr_event'" )[0][0] && '1' === $recovery->query( "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='" . DB_NAME . "' AND TRIGGER_NAME='pitr_trigger'" )[0][0], 'Events or triggers were omitted from the coordinated snapshot.' );
	$recovery->query( 'DROP DATABASE `' . DB_NAME . '`' );
	$selected = $worker->recover( $id, null, $recovery_config['source_config'], array( 'pitr_a' ), null, true );
	pitr_check( '7365636F6E64' === $recovery->query( 'SELECT HEX(value) FROM `' . DB_NAME . '`.pitr_a' )[0][0] && 'added' === $recovery->query( 'SELECT note FROM `' . DB_NAME . '`.pitr_a' )[0][0], 'Latest recovery lost row or schema changes.' );
	$h   = gzopen( $directory . '/archives/' . $selected['archive'], 'rb' );
	$sql = '';
	while ( ! gzeof( $h ) ) {
		$sql .= gzread( $h, 65536 );
	} gzclose( $h );
	pitr_check( false !== strpos( $sql, 'CREATE TABLE `pitr_a`' ) && false === strpos( $sql, 'CREATE TABLE `pitr_b`' ), 'Historical table export included unselected tables.' );
	$log   = $directory . '/archives/' . $increment['artifacts'][0]['name'];
	$bytes = file_get_contents( $log );
	file_put_contents( $log, substr( $bytes, 0, -1 ) );
	pitr_failure(
		function () use ( $worker, $id ) {
			$worker->verify( $id, null, null );
		},
		'Truncated off-host dependency did not block recovery.'
	);
	file_put_contents( $log, $bytes );
	unset( $worker );
	// New installation: only portable artifacts survive, not the original PHP state/history.
	unlink( $directory . '/archives/pitr/state.php' );
	$history   = array();
	$worker    = new BackupPlus_PITR( $config );
	$rescanned = $worker->rescan( null );
	pitr_check( 1 === count( $rescanned['chains'] ) && 2 === $rescanned['chains'][0]['increments'], 'Remote-style rescan could not reconstruct portable coordinate metadata.' );
	unset( $worker );
	$fixture        = json_decode( file_get_contents( __DIR__ . '/fixtures/age-keys.json' ), true );
	$key            = $fixture['keys']['native'];
	$encryption     = array(
		'mode'       => 'age',
		'recipients' => array( $key['recipient'] ),
	);
	$worker         = new BackupPlus_PITR( $config );
	$encrypted_full = $worker->run( 'full' );
	$application->query( 'USE `' . DB_NAME . '`; UPDATE pitr_b SET value=3 WHERE id=1' );
	$encrypted_increment = $worker->run( 'capture' );
	pitr_check( '.crypt' === substr( $encrypted_full['artifacts'][0]['name'], -6 ) && '.crypt' === substr( $encrypted_increment['artifacts'][0]['name'], -6 ), 'Configured age encryption did not cover every payload.' );
	$reader = function ( $message, $multiline ) use ( $key ) {
		return $multiline ? $key['identity'] : '';
	};
	$worker->verify( $encrypted_full['chain_id'], null, $reader );
	$recovery->query( 'DROP DATABASE `' . DB_NAME . '`' );
	$worker->recover( $encrypted_full['chain_id'], null, $recovery_config['source_config'], array(), $reader, true );
	pitr_check( '3' === $recovery->query( 'SELECT value FROM `' . DB_NAME . '`.pitr_b' )[0][0], 'Encrypted full-plus-log recovery failed.' );
	$wrong = function ( $message, $multiline ) {
		return $multiline ? 'AGE-SECRET-KEY-INVALID' : '';
	};
	pitr_failure(
		function () use ( $worker, $encrypted_full, $wrong ) {
			$worker->verify( $encrypted_full['chain_id'], null, $wrong );
		},
		'Wrong identity did not block recovery.'
	);
	$old_cursor = $encrypted_increment['end_coordinate'];
	$number     = (int) substr( $old_cursor['file'], strrpos( $old_cursor['file'], '.' ) + 1 );
	if ( 'mysql' === $engine ) {
		$admin->query( 'RESET BINARY LOGS AND GTIDS TO ' . $number );
	} else {
		$admin->query( 'RESET MASTER' ); for ( $rotation = 1; $rotation < $number; ++$rotation ) {
			$admin->query( 'FLUSH BINARY LOGS' );
		}
	}
	pitr_failure(
		function () use ( $worker ) {
			$worker->run( 'capture' );
		},
		'Source log reset with reused filenames was silently accepted.'
	);
	$worker->verify( $encrypted_full['chain_id'], null, $reader );
	$replacement = $worker->run();
	pitr_check( 'database-full' === $replacement['kind'] && $replacement['chain_id'] !== $encrypted_full['chain_id'], 'A broken chain was extended instead of starting a new coordinated baseline.' );
	// Exercise production replacement against the disposable source, with WordPress's DB handle
	// redirected there. The development site's actual database is never a restore target.
	$original_wpdb = $wpdb;
	echo "PITR encrypted and reset checks completed; testing native replacement.\n";
	$maintenance_existed = file_exists( ABSPATH . '.maintenance' );
	pitr_check( ! $maintenance_existed, 'Resolve existing development maintenance mode before restore tests.' );
	try {
		require_once BACKUPPLUS_DIR . '/includes/class-pitr-isolation.php';
		$config['restore_connection'] = $directory . '/application.cnf';
		pitr_failure(
			function () use ( $config, $work, $directory ) {
				new BackupPlus_PITR_Isolation( $config, $work, $directory ); },
			'Automatic isolation accepted the same application and recovery account.'
		);
		$config['restore_connection'] = $documented_restore( 'pitr_recovery_documented', 'fixture-documented' );
		unset( $worker );
		require __DIR__ . '/check-pitr-setup.php';
		$worker = new BackupPlus_PITR( $config );
		require __DIR__ . '/check-pitr-isolation.php';
		$application->query( 'USE `' . DB_NAME . '`; UPDATE pitr_b SET value=99; CREATE TABLE pitr_future (id int) ENGINE=InnoDB' );
		$direct = $worker->restore( $id, $first_end, '', array(), null, false, true );
		pitr_check( '1' === $observer->query( 'SELECT value FROM `' . DB_NAME . '`.pitr_b' )[0][0] && '666972737400FF' === $observer->query( 'SELECT HEX(value) FROM `' . DB_NAME . '`.pitr_a' )[0][0], 'Direct source replay missed the selected historical transaction.' );
		pitr_check( 2 === count( $observer->query( 'SHOW COLUMNS FROM `' . DB_NAME . '`.pitr_a' ) ) && ! $observer->query( 'SHOW TABLES FROM `' . DB_NAME . '` LIKE \'pitr_future\'' ), 'Direct source replay included later schema changes.' );
		pitr_check( ! file_exists( ABSPATH . '.maintenance' ) && $direct['capture_enabled'] && ! empty( BackupPlus_PITR::config()['enabled'] ) && wp_next_scheduled( BackupPlus_PITR::HOOK ) && ! empty( $direct['pre_restore_chain'] ), 'Direct source recovery did not retain protection and exit maintenance safely.' );
		unset( $worker );
		$worker = new BackupPlus_PITR( $config );
		$application->query( 'USE `' . DB_NAME . '`; UPDATE pitr_b SET value=99; CREATE TABLE pitr_future (id int) ENGINE=InnoDB' );
		$recovery->query( 'DROP DATABASE `' . DB_NAME . '`' );
		$restored = $worker->restore( $encrypted_full['chain_id'], $encrypted_increment['end_coordinate'], $recovery_config['source_config'], array(), $reader, true );
		pitr_check( '3' === $observer->query( 'SELECT value FROM `' . DB_NAME . '`.pitr_b' )[0][0], 'Whole-database replacement did not restore the historical row value.' );
		pitr_check( array() === $observer->query( 'SHOW TABLES FROM `' . DB_NAME . '` LIKE \'pitr_future\'' ), 'Whole-database replacement left a future table behind.' );
		pitr_check( ! file_exists( ABSPATH . '.maintenance' ) && $restored['capture_enabled'] && ! empty( BackupPlus_PITR::config()['enabled'] ) && wp_next_scheduled( BackupPlus_PITR::HOOK ), 'Successful replacement left maintenance active or reused the old capture cursor.' );
		unset( $worker );
		$application->query( 'USE `' . DB_NAME . '`; UPDATE pitr_b SET value=99' );
		require __DIR__ . '/check-pitr-web.php';
		$worker = new BackupPlus_PITR( $config );
		$application->query( 'USE `' . DB_NAME . '`; UPDATE pitr_a SET value=0x6c697665; UPDATE pitr_b SET value=100; CREATE TABLE pitr_future (id int) ENGINE=InnoDB' );
		$recovery->query( 'DROP DATABASE `' . DB_NAME . '`' );
		$worker->restore( $encrypted_full['chain_id'], $encrypted_increment['end_coordinate'], $recovery_config['source_config'], array( 'pitr_a' ), $reader, true );
		pitr_check( '7365636F6E64' === $observer->query( 'SELECT HEX(value) FROM `' . DB_NAME . '`.pitr_a' )[0][0] && '100' === $observer->query( 'SELECT value FROM `' . DB_NAME . '`.pitr_b' )[0][0], 'Selected historical replacement changed an unselected table.' );
		pitr_check( 1 === count( $observer->query( 'SHOW TABLES FROM `' . DB_NAME . '` LIKE \'pitr_future\'' ) ), 'Selected historical replacement removed an unselected future table.' );
		// A coordinated baseline remains usable by the existing table-selective importer, running as the site account.
		// Like any account without SUPER on a binary-logging server, it can recreate triggers only with trusted creators.
		$wpdb = $original_wpdb;
		$wpdb->check_connection( false );
		wp_cache_flush();
		$admin->query( 'SET GLOBAL log_bin_trust_function_creators=1' );
		require_once BACKUPPLUS_DIR . '/restorer.php';
		WP_Filesystem();
		$backupplus->initiate_restore_job();
		$baseline_set                   = array(
			'db'     => $full['artifacts'][0]['name'],
			'native' => true,
		);
		$ordinary                       = new BackupPlus_Restorer(
			new BackupPlus_Restorer_Skin(),
			$baseline_set,
			false,
			array(
				'include_unspecified_tables' => false,
				'tables_to_restore'          => array( 'pitr_a' ),
			)
		);
		$GLOBALS['backupplus_restorer'] = $ordinary;
		$pre                            = new ReflectionMethod( $ordinary, 'pre_restore_backup' );
		$import                         = new ReflectionMethod( $ordinary, 'restore_backup' );
		if ( PHP_VERSION_ID < 80100 ) {
			$pre->setAccessible( true );
			$import->setAccessible( true );
		}
		$pre->invoke( $ordinary, $baseline_set['db'], 'db', array() );
		$imported = $import->invoke( $ordinary, $baseline_set['db'], 'db', array(), true, true );
		pitr_check( true === $imported, 'The ordinary importer rejected a PITR baseline: ' . ( is_wp_error( $imported ) ? $imported->get_error_message() : json_encode( $imported ) ) );
		$errors = new ReflectionProperty( $ordinary, 'errors' );
		if ( PHP_VERSION_ID < 80100 ) {
			$errors->setAccessible( true );
		}
		pitr_check( ! $errors->getValue( $ordinary ), 'The ordinary baseline importer reported SQL errors.' );
		pitr_check( '62617365' === $observer->query( 'SELECT HEX(value) FROM `' . DB_NAME . '`.pitr_a' )[0][0] && '100' === $observer->query( 'SELECT value FROM `' . DB_NAME . '`.pitr_b' )[0][0], 'Ordinary baseline table selection changed an unselected table or lost snapshot data.' );
		$backupplus->initiate_restore_job();
		$trigger_importer               = new BackupPlus_Restorer(
			new BackupPlus_Restorer_Skin(),
			$baseline_set,
			false,
			array(
				'include_unspecified_tables' => false,
				'tables_to_restore'          => array( 'pitr_b' ),
			)
		);
		$GLOBALS['backupplus_restorer'] = $trigger_importer;
		$pre->invoke( $trigger_importer, $baseline_set['db'], 'db', array() );
		$imported = $import->invoke( $trigger_importer, $baseline_set['db'], 'db', array(), true, true );
		pitr_check( true === $imported && ! $errors->getValue( $trigger_importer ), 'Importing the native trigger owner failed.' );
		pitr_check( '0' === $observer->query( 'SELECT value FROM `' . DB_NAME . '`.pitr_b' )[0][0] && '1' === $application->query( "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='" . DB_NAME . "' AND TRIGGER_NAME='pitr_trigger' AND EVENT_OBJECT_TABLE='pitr_b'" )[0][0], 'Native trigger was not preserved on its restored table.' );
		$backupplus->initiate_restore_job();
		$whole_importer                 = new BackupPlus_Restorer( new BackupPlus_Restorer_Skin(), $baseline_set );
		$GLOBALS['backupplus_restorer'] = $whole_importer;
		$pre->invoke( $whole_importer, $baseline_set['db'], 'db', array() );
		$imported = $import->invoke( $whole_importer, $baseline_set['db'], 'db', array(), true, true );
		pitr_check( true === $imported && ! $errors->getValue( $whole_importer ), 'The ordinary whole-database importer reported SQL errors for a native baseline.' );
		pitr_check( '0' === $observer->query( 'SELECT value FROM `' . DB_NAME . '`.pitr_view' )[0][0] && '0' === $application->query( 'CALL `' . DB_NAME . '`.pitr_proc()' )[0][0], 'Ordinary whole-baseline import lost views or routines.' );
	} finally {
		$admin->query( 'SET GLOBAL log_bin_trust_function_creators=' . $trust_creators );
		$wpdb = $original_wpdb;
		wp_cache_flush();
		if ( ! $maintenance_existed && is_file( ABSPATH . '.maintenance' ) && '<?php $upgrading = time();' === file_get_contents( ABSPATH . '.maintenance' ) ) {
			unlink( ABSPATH . '.maintenance' );
		}
	}
	echo 'PITR native snapshot, coordinates, rotations, DDL, historical/selected-table recovery, age, corruption and rescan checks passed for ' . $engine . ".\n";
	unset( $worker );
} finally {
	if ( isset( $development_wpdb ) ) {
		$wpdb = $development_wpdb;
		wp_cache_flush();
	}
	if ( isset( $backupplus ) && $backupplus->nonce ) {
		delete_site_option( 'backupplus_jobdata_' . $backupplus->nonce );
	}
	if ( isset( $admin, $recovery_admin ) ) {
		$admin->query( "DROP USER IF EXISTS 'pitr_application'@'%', 'pitr_backup'@'%', 'pitr_observer'@'%', 'pitr_recovery_meta'@'%', 'pitr_recovery_documented'@'%'", true );
		$recovery_admin->query( "DROP USER IF EXISTS 'pitr_reconstruct'@'%', 'pitr_application'@'%'" );
	}
	$remove( $directory );
}
