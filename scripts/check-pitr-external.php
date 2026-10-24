<?php

/** Shared-server capture uses a separate account; neither app nor backup can read its neighbor. */
require_once BACKUPPLUS_DIR . '/includes/class-pitr-setup.php';

( function () use ( $config, $source, $application, $admin, $adapter, $directory, $work, $source_port, $engine ) {
	$neighbor = 'pitr_neighbor';
	$before   = $application->query( 'SELECT value FROM `' . DB_NAME . '`.pitr_b' )[0][0];
	$recovery = null;
	try {
		// Another tenant on the shared server owns its own database through its own account.
		$admin->query( 'CREATE DATABASE `' . $neighbor . "`; CREATE USER 'pitr_neighbor'@'%' IDENTIFIED BY 'fixture-neighbor'; GRANT ALL PRIVILEGES ON `" . $neighbor . "`.* TO 'pitr_neighbor'@'%'", true );
		$tenant = $adapter( pitr_client_file( $directory . '/neighbor.cnf', $engine, $source_port, 'pitr_neighbor', 'fixture-neighbor' ) );
		$tenant->query( 'CREATE TABLE `' . $neighbor . '`.private_data (id int PRIMARY KEY, value varchar(100)) ENGINE=InnoDB; INSERT INTO `' . $neighbor . "`.private_data VALUES(1,'foreign-private-value')" );
		pitr_check( array( array( $before ) ) === $application->query( 'SELECT value FROM `' . DB_NAME . '`.pitr_b' ), 'Ordinary schema-scoped application access failed.' );
		pitr_failure(
			function () use ( $application ) {
				$application->query( 'SHOW BINARY LOGS' );
			},
			'The application account unexpectedly has native binlog access.'
		);
		pitr_failure(
			function () use ( $application, $neighbor ) {
				$application->query( 'SELECT * FROM `' . $neighbor . '`.private_data' );
			},
			'The application can read the neighboring database.'
		);
		$source->source( DB_NAME );
		pitr_failure(
			function () use ( $source, $neighbor ) {
				$source->query( 'SELECT * FROM `' . $neighbor . '`.private_data' );
			},
			'The capture account can query unrelated application data.'
		);
		pitr_failure(
			function () use ( $source ) {
				$source->query( 'SELECT * FROM mysql.user' );
			},
			'The capture account can read server account metadata.'
		);
		pitr_failure(
			function () use ( $source ) {
				$source->query( 'SET GLOBAL read_only=OFF' );
			},
			'The capture account can administer the global write barrier.'
		);

		global $wpdb, $backupplus;
		$prior_directory  = $backupplus->backups_dir_location();
		$scoped_directory = $directory . '/scoped-archives';
		mkdir( $scoped_directory, 0700 );
		$directory_property = new ReflectionProperty( $backupplus, 'backup_dir' );
		if ( PHP_VERSION_ID < 80100 ) { $directory_property->setAccessible( true ); }
		$directory_property->setValue( $backupplus, $scoped_directory );
		try {
			$scoped_worker = new BackupPlus_PITR( $config );
			$scoped_full   = $scoped_worker->run( 'full' );
			pitr_check( 'database-full' === $scoped_full['kind'], 'Minimum-grant capture failed to create a real PITR baseline.' );
			$wpdb->query( 'UPDATE pitr_b SET value=31' );
			$scoped_increment = $scoped_worker->run( 'capture' );
			pitr_check( 'database-binlog' === $scoped_increment['kind'], 'Minimum-grant capture failed to archive transactions.' );
			unset( $scoped_worker );
			$minute_configuration = function ( $value, $name ) use ( $config ) {
				if ( BackupPlus_PITR::OPTION !== $name ) { return $value; }
				$config['interval'] = 1;
				return $config;
			};
			add_filter( 'backupplus_get_option', $minute_configuration, 250, 2 );
			try {
				$wpdb->query( 'UPDATE pitr_b SET value=32' );
				BackupPlus_PITR::scheduled();
			} finally { remove_filter( 'backupplus_get_option', $minute_configuration, 250 ); }
			$scoped_worker = new BackupPlus_PITR( $config );
			pitr_check( 2 === $scoped_worker->summary()['chains'][0]['increments'], 'The one-minute scheduled tick was skipped because the previous capture finished recently.' );
			$scoped_worker->verify( $scoped_full['chain_id'], null, null );
		} finally {
			unset( $scoped_worker );
			$scoped_history = BackupPlus_Backup_History::get_history();
			foreach ( $scoped_history as $point_time => $point_set ) { if ( ! empty( $scoped_full['chain_id'] ) && isset( $point_set['pitr']['chain_id'] ) && $point_set['pitr']['chain_id'] === $scoped_full['chain_id'] ) { unset( $scoped_history[ $point_time ] ); }
			}
			BackupPlus_Backup_History::save_history( $scoped_history );
			$directory_property->setValue( $backupplus, $prior_directory );
			wp_cache_flush();
			$application->query( 'UPDATE `' . DB_NAME . '`.pitr_b SET value=' . (int) $before );
		}
		$baseline   = $work . '/scoped-baseline.sql';
		$coordinate = $source->dump( DB_NAME, $baseline );
		$application->query( 'UPDATE `' . DB_NAME . '`.pitr_b SET value=1' );
		$first = $source->status();
		$tenant->query( 'UPDATE `' . $neighbor . "`.private_data SET value='neighbor-remains-writable'" );
		$application->query( 'UPDATE `' . DB_NAME . '`.pitr_b SET value=2' );
		$source->query( 'FLUSH BINARY LOGS' );
		$logs  = array_keys( $source->logs() );
		$begin = array_search( $coordinate['file'], $logs, true );
		$last  = array_search( $first['file'], $logs, true );
		pitr_check( false !== $begin && false !== $last && $last < count( $logs ) - 1, 'The shared-server interval is missing a closed log.' );
		$raw  = array();
		$next = $coordinate;
		for ( $i = $begin; $i < count( $logs ) - 1; ++$i ) {
			$path       = $source->fetch_log( $logs[ $i ] );
			$inspection = $source->inspect_log( $path, $next['position'], DB_NAME );
			$next       = $inspection['next'];
			if ( $i <= $last ) { $raw[] = $path; }
		}
		$replay = $work . '/scoped-replay.sql';
		$source->filtered_replay_sql( $raw, $coordinate['position'], $first['position'], DB_NAME, $replay );
		$recovery_config                  = $config;
		$recovery_config['source_config'] = $directory . '/recovery.cnf';
		$recovery                         = new BackupPlus_PITR_Database( $recovery_config, $work );
		$recovery->import( $baseline, null );
		$recovery->import( $replay, DB_NAME );
		pitr_check( array( array( '1' ) ) === $recovery->query( 'SELECT value FROM `' . DB_NAME . '`.pitr_b' ), 'Isolated shared-server replay lost the overwritten intermediate value.' );
		pitr_check( ! $recovery->query( "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='" . $neighbor . "'" ), 'Isolated replay imported the neighboring database.' );
		pitr_check( array( array( 'neighbor-remains-writable' ) ) === $tenant->query( 'SELECT value FROM `' . $neighbor . '`.private_data' ), 'Shared capture disturbed the neighboring database.' );
		echo "PITR in-plugin capture, database-only application grants, shared-server isolation and intermediate-transaction reconstruction passed.\n";
	} finally {
		if ( $recovery ) { $recovery->query( 'DROP DATABASE IF EXISTS `' . DB_NAME . '`' ); }
		$admin->query( 'DROP DATABASE IF EXISTS `' . $neighbor . "`; DROP USER IF EXISTS 'pitr_neighbor'@'%'", true );
		$application->query( 'UPDATE `' . DB_NAME . '`.pitr_b SET value=' . (int) $before );
	}
} )();
