<?php

/** Reconstructing a database at a target time and restoring it, including abandoning an interrupted restore. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_PITR_Orchestrator_Recovery {

	private function reconstruct( $id, $target, $recovery_config, $tables, $reader, $isolated ) {
		if ( ! $isolated ) {
			throw new RuntimeException( 'Supply --isolated to confirm that the recovery connection is an empty, isolated disposable server instance.' );
		}
		$this->job();
		$plan = $this->recovery_plan( $id, $target );
		$free = disk_free_space( $this->work );
		if ( false !== $free && $free < $plan['estimated_local_bytes'] ) {
			throw new RuntimeException( 'Insufficient local space for the recovery plan staging estimate.' );
		}
		list( $baseline, $logs )   = $this->authenticate( $plan, $reader );
		$config                    = $this->config;
		$config['engine']          = $plan['source']['engine'];
		$config['source_config']   = $recovery_config;
		$config['capture_account'] = false;
		if ( empty( $config['bin_directory'] ) ) {
			$config['bin_directory'] = '/usr/bin';
		}
		$adapter  = $this->database( $config, $this->work );
		$server   = $adapter->query( 'SELECT @@version, @@server_id, @@hostname, @@port, @@event_scheduler, @@log_bin' )[0];
		$identity = 'mysql' === $config['engine'] ? $adapter->query( 'SELECT @@server_uuid' )[0][0] : implode( ':', array_slice( $server, 1, 3 ) );
		if ( hash_equals( $plan['source']['identity'], hash( 'sha256', $identity ) ) || preg_replace( '/-log$/', '', $plan['source']['server_version'] ) !== preg_replace( '/-log$/', '', $server[0] ) || ! in_array( $server[4], array( 'OFF', 'DISABLED' ), true ) || '0' !== $server[5] ) {
			throw new RuntimeException( 'Recovery requires a different server of the same version with binary logging and the event scheduler disabled.' );
		}
		foreach ( $adapter->query( 'SHOW DATABASES' ) as $row ) {
			if ( ! in_array( $row[0], array( 'mysql', 'sys', 'information_schema', 'performance_schema' ), true ) ) {
				throw new RuntimeException( 'Recovery server is not empty. Recreate the disposable instance before retrying.' );
			}
		}
		if ( $adapter->query( 'mysql' === $config['engine'] ? 'SHOW REPLICA STATUS' : 'SHOW ALL SLAVES STATUS' ) ) {
			throw new RuntimeException( 'Recovery server must not have replication channels configured.' );
		}
		$db = $plan['database'];
		$adapter->import( $baseline, null );
		$adapter->replay( $logs, $plan['snapshot_coordinate']['position'], $plan['stop_position'], $db );
		$available = array_map(
			function ( $row ) {
				return $row[0];
			},
			$adapter->prepared( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=?', array(), array( $db ) )
		);
		if ( array_diff( $tables, $available ) ) {
			throw new RuntimeException( 'A selected table did not exist at the requested historical point.' );
		}
		if ( $tables ) {
			$this->table_dependencies( $adapter, $db, $tables );
		}
		$export = $this->work . '/recovered-' . bin2hex( random_bytes( 8 ) ) . '.sql';
		$adapter->dump( $db, $export, false, $tables );
		return array( $this->sql_header( $export, $baseline ), $plan, $tables );
	}

	private function table_dependencies( $adapter, $database, $tables ) {
		$rows = $adapter->prepared( 'SELECT TABLE_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND REFERENCED_TABLE_NAME IS NOT NULL', array(), array( $database ) );
		foreach ( $rows as $row ) {
			if ( in_array( $row[0], $tables, true ) !== in_array( $row[1], $tables, true ) ) {
				throw new RuntimeException( 'Selected tables omit a foreign-key dependency. Recover the related tables together.' );
			}
		}
	}

	/** Recover to a logical artifact; no production database is changed. */
	public function recover( $id, $target, $recovery_config, $tables, $reader, $isolated ) {
		list( $sql, $plan ) = $this->reconstruct( $id, $target, $recovery_config, $tables, $reader, $isolated );
		$storage            = new BackupPlus_PITR_Storage( $this->work, $this->encryption(), array() );
		$nonce              = bin2hex( random_bytes( 6 ) );
		$artifact           = $storage->artifact( $sql, 'backup_' . gmdate( 'Y-m-d-Hi' ) . '_PITRRecovered_' . $nonce . '-db.gz' );
		$history            = BackupPlus_Backup_History::get_history();
		$time               = time();
		while ( isset( $history[ $time ] ) ) {
			++$time;
		}
		$history[ $time ] = array(
			'db'             => $artifact['name'],
			'db-size'        => $artifact['size'],
			'service'        => array( 'none' ),
			'native'         => true,
			'nonce'          => $nonce,
			'recovered_from' => array(
				'chain_id'   => $id,
				'coordinate' => $plan['target_coordinate'],
				'tables'     => $tables,
			),
			'checksums'      => array( 'sha256' => array( 'db0' . ( '.crypt' === substr( $artifact['name'], -6 ) ? '.crypt' : '' ) => $artifact['sha256'] ) ),
		);
		BackupPlus_Backup_History::save_history( $history );
		return array(
			'timestamp'  => $time,
			'archive'    => $artifact['name'],
			'artifact'   => $artifact,
			'coordinate' => $plan['target_coordinate'],
			'tables'     => $tables,
		);
	}

	private function direct_source_check( $adapter, $plan ) {
		$current = $adapter->source( DB_NAME );
		$this->assert_wordpress_source( $current, DB_NAME );
		if ( $current['engine'] !== $plan['source']['engine'] || preg_replace( '/-log$/', '', $current['server_version'] ) !== preg_replace( '/-log$/', '', $plan['source']['server_version'] ) ) { throw new RuntimeException( 'Direct recovery requires the same database vendor and server version as the recorded source.' ); }
		if ( $adapter->replicated() ) {
			throw new RuntimeException( 'Direct recovery requires a standalone source with no replication channels, connected replicas or binary-log streaming clients.' );
		}
		$adapter->query( 'SELECT @@session.sql_log_bin', true );
		$replay_privilege = false;
		foreach ( $adapter->query( 'SHOW GRANTS FOR CURRENT_USER()' ) as $grant ) {
			// MySQL runs replayed BINLOG statements with either privilege; REPLICATION_APPLIER is the narrower one.
			if ( preg_match( '/^GRANT (.+) ON \*\.\* TO /', $grant[0], $matches ) && preg_match( 'mysql' === $adapter->engine ? '/(?:^|,)\s*(?:ALL PRIVILEGES|REPLICATION_APPLIER|BINLOG_ADMIN)\s*(?:,|$)/' : '/(?:^|,)\s*(?:ALL PRIVILEGES|BINLOG REPLAY)\s*(?:,|$)/', $matches[1] ) ) { $replay_privilege = true; }
		}
		if ( ! $replay_privilege ) { throw new RuntimeException( 'Direct source recovery requires REPLICATION_APPLIER (or BINLOG_ADMIN) on MySQL or BINLOG REPLAY on MariaDB, plus permission to disable session binary logging.' ); }
	}

	private function assert_no_replayed_events( $sql, $events = null ) {
		if ( null !== $events ) {
			foreach ( $events as $event ) { if ( ! isset( $event[1] ) || ! in_array( $event[1], array( 'DISABLED', 'SLAVESIDE_DISABLED' ), true ) ) { throw new RuntimeException( 'This recovery point contains an enabled database event. Direct source recovery cannot yet install it safely; use an isolated historical export. No replacement was started.' ); }
			}
			return;
		}
		$handle = fopen( $sql, 'rb' );
		try {
			while ( false !== ( $line = fgets( $handle, 1048576 ) ) ) {
				if ( preg_match( '/^\s*(?:\/\*![0-9]+\s*)?(?:(?:CREATE|ALTER)\s+(?:DEFINER\s*=.*?)?EVENT|EVENT\s+`)/i', $line ) || preg_match( '/\/\*![0-9]+\s+EVENT\s+`/i', $line ) ) { throw new RuntimeException( 'Direct PITR recovery of historical database events is not supported yet. No database replacement was started; use an isolated historical export.' ); }
			}
		} finally { fclose( $handle ); }
	}

	/** Exact replacement on the configured source; an interrupted import leaves maintenance active. */
	public function restore( $id, $target, $recovery_config, $tables, $reader, $isolated, $direct = false, $writers_stopped = false ) {
		if ( empty( $this->config['source_config'] ) || $this->config['database'] !== DB_NAME ) {
			throw new RuntimeException( 'PITR restore requires the configured source to be this WordPress database.' );
		}
		if ( $direct && $tables ) {
			throw new RuntimeException( 'Direct source recovery restores the entire database. Selected historical tables require a staged reconstruction.' );
		}
		if ( file_exists( ABSPATH . '.maintenance' ) ) {
			throw new RuntimeException( 'WordPress is already in maintenance mode; resolve the existing operation first.' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		global $backupplus;
		if ( BackupPlus_Retention_Cleanup::backup_active() || $backupplus->check_restore_progress( 1 ) || ! WP_Upgrader::create_lock( 'backupplus_cli_restore', HOUR_IN_SECONDS ) ) {
			throw new RuntimeException( 'Another backup or restore is active.' );
		}
		try {
			return $this->restore_locked( $id, $target, $recovery_config, $tables, $reader, $isolated, $direct );
		} finally {
			WP_Upgrader::release_lock( 'backupplus_cli_restore' );
		}
	}

	private function restore_locked( $id, $target, $recovery_config, $tables, $reader, $isolated, $direct = false ) {
		if ( $direct ) { require_once dirname( __DIR__ ) . '/class-pitr-isolation.php'; }
		global $backupplus, $wpdb;
		if ( $direct ) {
			$this->job();
			$plan               = $this->recovery_plan( $id, $target );
			list( $sql, $logs ) = $this->authenticate( $plan, $reader );
			$this->assert_no_replayed_events( $sql, isset( $plan['baseline']['manifest']['database_events'] ) ? $plan['baseline']['manifest']['database_events'] : null );
			$replay_config                    = $this->config;
			$replay_config['source_config']   = BackupPlus_PITR_Setup::connection_file( $this->config, $this->work );
			$replay_config['capture_account'] = false;
			$replay_adapter                   = $this->database( $replay_config, $this->work );
			$replay_sql                       = $this->work . '/source-replay-' . bin2hex( random_bytes( 8 ) ) . '.sql';
			if ( $logs ) { $replay_adapter->filtered_replay_sql( $logs, $plan['snapshot_coordinate']['position'], $plan['stop_position'], DB_NAME, $replay_sql, true );
				$this->assert_no_replayed_events( $replay_sql ); }
		} else {
			list( $sql, $plan ) = $this->reconstruct( $id, $target, $recovery_config, $tables, $reader, $isolated );
		}
		if ( $plan['database'] !== DB_NAME ) {
			throw new RuntimeException( 'Native PITR replacement requires the original database name. Use the recovered export with the ordinary importer for migration.' );
		}
		if ( ! isset( $plan['baseline']['manifest']['wordpress_table_prefix'] ) || $plan['baseline']['manifest']['wordpress_table_prefix'] !== $wpdb->prefix ) {
			throw new RuntimeException( 'Native PITR replacement requires the original WordPress table prefix. Use a recovered export for migration.' );
		}
		$settings = array();
		foreach ( $backupplus->get_settings_keys() as $key ) {
			$value = BackupPlus_Options::get_backupplus_option( $key, null );
			if ( null !== $value ) {
				$settings[ $key ] = $value;
			}
		}
		$credential_directory = get_site_option( BackupPlus_Credential_Key_Store::OPTION, '' );
		$adapter              = $this->database( $this->config, $this->work );
		$this->assert_wordpress_source( $adapter->source( DB_NAME ), DB_NAME );
		if ( ! $direct && ! empty( $this->config['restore_connection'] ) ) {
			$target_config                    = $this->config;
			$target_config['source_config']   = $this->config['restore_connection'];
			$target_config['capture_account'] = false;
			$adapter                          = $this->database( $target_config, $this->work );
			$this->assert_wordpress_source( $adapter->source( DB_NAME ), DB_NAME );
		}
		$isolation           = null;
		$replacement_started = false;
		if ( $direct ) {
			$isolation = new BackupPlus_PITR_Isolation( $this->config, $this->work, $this->root );
			$adapter   = $isolation->adapter;
			$this->direct_source_check( $adapter, $plan );
			$isolation->start();
		}
		try {
			if ( $tables ) {
				$this->table_dependencies( $adapter, DB_NAME, $tables );
			}
			$this->state['restore_chain'] = $id;
			if ( $direct && is_array( $reader ) && isset( $reader['authenticated'] ) && is_callable( $reader['authenticated'] ) ) { call_user_func( $reader['authenticated'] ); }
			$this->save();
			// A new durable full backup protects the state immediately before replacement.
			$original_source_config = $this->config['source_config'];
			$original_capture       = ! empty( $this->config['capture_account'] );
			$original_enabled       = ! empty( $this->config['enabled'] );
			try {
				$this->config['enabled'] = true;
				if ( $isolation ) { $this->config['source_config'] = $isolation->adapter->defaults;
					$this->config['capture_account']               = false; }
				$this->run( 'full' );
			} catch ( Throwable $error ) {
				unset( $this->state['restore_chain'] );
				$this->save();
				throw $error;
			} finally { $this->config['source_config'] = $original_source_config;
				$this->config['capture_account']       = $original_capture; }
			$before                                     = $this->state['active'];
			$this->state['chains'][ $before ]['pinned'] = true;
			$this->save();
			$history = BackupPlus_Backup_History::get_history();
			$history[ $this->state['chains'][ $before ]['history_time'] ]['always_keep'] = true;
			BackupPlus_Backup_History::save_history( $history );
			$config            = $this->config;
			$config['enabled'] = false;
			self::save_configuration( $config );
			$this->config = $config;
			self::deactivate();
			if ( ! $direct ) { BackupPlus_PITR_Process::private_file( ABSPATH . '.maintenance', '<?php $upgrading = time();' ); }
			$this->state['restore_state'] = 'importing';
			$this->save();
			try {
				if ( $isolation ) { $isolation->check( true ); }
				$replacement_started = true;
				if ( ! $tables ) {
					$adapter->query( BackupPlus_SQL::render( 'DROP DATABASE %i', array( DB_NAME ) ), $direct );
				}
				$adapter->import( $sql, $tables ? DB_NAME : null, $direct );
				if ( $direct ) {
					if ( $logs ) { $adapter->import( $replay_sql, DB_NAME, true ); }
					$isolation->check( true );
				}
				global $wpdb;
				$wpdb->check_connection( false );
				wp_cache_flush();
				if ( $credential_directory ) {
					update_site_option( BackupPlus_Credential_Key_Store::OPTION, $credential_directory );
				}
				foreach ( $settings as $key => $value ) {
					if ( self::OPTION === $key ) { self::save_configuration( $value ); }
					else { BackupPlus_Options::update_backupplus_option( $key, $value ); }
				}
				BackupPlus_Backup_History::save_history( $history );
				// Capture resumes with its original setting; the next run finds no active chain and starts a new baseline.
				$config['enabled'] = $original_enabled;
				self::save_configuration( $config );
				$this->config = $config;
				self::reconcile_schedule();
				$this->state['chains'][ $before ]['state'] = 'broken';
				$this->state['active']                     = null;
				$this->state['restore_state']              = 'complete';
				unset( $this->state['restore_chain'] );
				$this->save();
				foreach ( array_keys( $this->state['chains'] ) as $chain_id ) {
					$this->history( $chain_id );
				}
				update_option( self::STATUS, $this->summary(), false );
				if ( $isolation ) { $isolation->release( false );
					$isolation = null; }
				else { unlink( ABSPATH . '.maintenance' ); }
				return array(
					'coordinate'        => $plan['target_coordinate'],
					'tables'            => $tables,
					'pre_restore_chain' => $before,
					'capture_enabled'   => $original_enabled,
				);
			} catch ( Throwable $error ) {
				$this->state['restore_state'] = 'failed';
				$this->save();
				throw new RuntimeException( 'Source database recovery failed: ' . $error->getMessage() . ' Maintenance mode remains active; recover the pinned pre-restore chain ' . $before . '.' );
			}
		} finally {
			if ( $isolation && ! $replacement_started ) { $isolation->release(); }
		}
	}

	public function abandon_pending() {
		if ( ! $this->state['pending'] ) {
			return array( 'abandoned' => false );
		}
		$pending = $this->state['pending'];
		$entry   = $pending['entry'];
		$id      = $entry['manifest']['chain_id'];
		if ( 'database-full' === $entry['manifest']['kind'] ) {
			$this->state['chains'][ $id ] = array(
				'entries'      => array( $entry ),
				'cursor'       => $entry['manifest']['snapshot_coordinate'],
				'destinations' => $pending['destinations'],
				'fingerprint'  => $pending['fingerprint'],
				'state'        => 'abandoned',
				'imported'     => true,
			);
		} else {
			$this->state['chains'][ $id ]['abandoned_entries'][] = $entry;
			$this->state['chains'][ $id ]['state']               = 'broken';
		}
		if ( ! empty( $this->state['active'] ) ) {
			$this->state['chains'][ $this->state['active'] ]['state'] = 'broken';
		}
		$this->state['pending'] = null;
		$this->state['error']   = 'Unpublished backup was abandoned. The next worker run must create a new full chain.';
		$this->save();
		return array(
			'abandoned'           => true,
			'backup_id'           => $entry['manifest']['backup_id'],
			'artifacts_preserved' => true,
		);
	}
}
