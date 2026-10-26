<?php

/** The persisted chain state, configuration checks, job and encryption setup and the configuration fingerprint. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_PITR_Orchestrator_State {

	/** Execution backends select adapters without changing native safety defaults. */
	protected function database( $config, $directory ) {
		return new BackupPlus_PITR_Database( $config, $directory );
	}

	protected function inspect_binlog( $path, $start, $database, $engine ) {
		return BackupPlus_PITR_Binlog::inspect( $path, $start, $database, $engine, false, null, true );
	}

	private function read_state() {
		$path = $this->root . '/state.php';
		if ( ! is_file( $path ) ) {
			return array(
				'version' => 1,
				'chains'  => array(),
				'active'  => null,
				'pending' => null,
			);
		}
		if ( is_link( $path ) || filesize( $path ) > 67108864 ) {
			throw new RuntimeException( 'Invalid PITR chain index.' );
		}
		$raw   = file_get_contents( $path );
		$state = 0 === strpos( $raw, '<?php exit; ?>' ) ? json_decode( substr( $raw, strlen( '<?php exit; ?>' ) ), true ) : null;
		if ( ! is_array( $state ) || ! isset( $state['version'], $state['chains'] ) || 1 !== $state['version'] ) {
			throw new RuntimeException( 'PITR chain index is corrupt; rescan remote artifacts before continuing.' );
		}
		return $state;
	}

	private function save() {
		$path = $this->root . '/state-' . bin2hex( random_bytes( 8 ) ) . '.tmp';
		$json = json_encode( $this->state, JSON_UNESCAPED_SLASHES );
		if ( false === $json || strlen( $json ) + strlen( '<?php exit; ?>' ) > 67108864 ) {
			throw new RuntimeException( 'Could not encode PITR chain state within its supported size limit.' );
		}
		BackupPlus_PITR_Process::private_file( $path, '<?php exit; ?>' . $json );
		if ( ! rename( $path, $this->root . '/state.php' ) ) {
			throw new RuntimeException( 'Could not checkpoint PITR chain state.' );
		}
	}

	public function configure( $config ) {
		if ( ! isset( $config['engine'], $config['source_config'], $config['database'], $config['bin_directory'], $config['interval'] ) || ! in_array( $config['engine'], array( 'mysql', 'mariadb' ), true ) || ! in_array( $config['interval'], self::intervals(), true ) || ! preg_match( '/\A[A-Za-z0-9_$-]+\z/D', $config['database'] ) ) {
			throw new RuntimeException( 'Choose a supported engine and capture interval.' );
		}
		unset( $config['global_read_lock'] );
		$config['source_config'] = realpath( $config['source_config'] );
		$config['bin_directory'] = realpath( $config['bin_directory'] );
		if ( false === $config['source_config'] || false === $config['bin_directory'] ) {
			throw new RuntimeException( 'Source configuration and native client directory must exist.' );
		}
		$adapter = $this->database( $config, $this->work );
		$source  = $adapter->source( $config['database'] );
		$this->assert_wordpress_source( $source, $config['database'] );
		$guard = $adapter->guard();
		$guard->close();
		BackupPlus_PITR_Storage::destinations();
		$config['enabled'] = true;
		self::save_configuration( $config );
		$this->config = $config;
		wp_clear_scheduled_hook( self::HOOK );
		self::reconcile_schedule();
		return $source;
	}

	public function capabilities() {
		$adapter = $this->database( $this->config, $this->work );
		$source  = $adapter->source( $this->config['database'] );
		$guard   = $adapter->guard();
		$guard->close();
		return $source;
	}

	private function assert_wordpress_source( $source, $database ) {
		global $wpdb;
		$row = $wpdb->get_row( 'SELECT @@version, @@server_id, @@hostname, @@port', ARRAY_N );
		if ( ! is_array( $row ) || 4 !== count( $row ) || ( 'mariadb' === $source['engine'] ) !== ( false !== stripos( $row[0], 'mariadb' ) ) ) {
			throw new RuntimeException( 'The native source is not this WordPress database server.' );
		}
		$identity = 'mysql' === $source['engine'] ? $wpdb->get_var( 'SELECT @@server_uuid' ) : implode( ':', array_slice( $row, 1 ) );
		if ( $database !== DB_NAME || ! hash_equals( $source['identity'], hash( 'sha256', $identity ) ) ) {
			throw new RuntimeException( 'The PITR backup connection must point to this WordPress database server.' );
		}
	}

	protected function job( $nonce = false ) {
		global $backupplus, $backupplus_backup;
		require_once BACKUPPLUS_DIR . '/backup.php';
		$backupplus->backup_time_nonce( $nonce );
		$backupplus->jobdata_set_from_array(
			array(
				'job_type'     => 'pitr',
				'jobstatus'    => 'pitrcreating',
				'backup_time'  => time(),
				'service'      => array(),
				'option_cache' => array( 'backupplus_delete_local' => false ),
			)
		);
		$backupplus_backup                        = new BackupPlus_Backup( 'no' );
		$backupplus_backup->last_storage_instance = false;
	}

	protected function encryption() {
		$encryption = BackupPlus_Options::get_backupplus_option( 'backupplus_database_encryption', array( 'mode' => 'none' ) );
		if ( ! isset( $encryption['mode'] ) || ! in_array( $encryption['mode'], array( 'none', 'age' ), true ) || ( 'age' === $encryption['mode'] && empty( $encryption['recipients'] ) ) ) {
			throw new RuntimeException( 'Save a valid backup encryption configuration before enabling PITR.' );
		}
		return $encryption;
	}

	private function fingerprint( $destinations, $encryption ) {
		$options = array();
		foreach ( $destinations as $destination ) {
			$options[ $destination['service'] ] = BackupPlus_Options::get_backupplus_option( 'backupplus_' . $destination['service'] );
		}
		$configuration = $this->config;
		// Ignore the legacy external-worker flag so its removal does not force a new baseline.
		unset( $configuration['enabled'], $configuration['interval'], $configuration['exact'], $configuration['time'], $configuration['server_cron'], $configuration['restore_connection'] );
		if ( ! empty( $configuration['capture_account'] ) ) { $configuration['capture_credentials'] = get_option( 'backupplus_pitr_capture', null ); }
		$configuration['snapshot_policy'] = 'transactional-ddl-guard-v1';
		return hash( 'sha256', serialize( array( $configuration, $destinations, $encryption, $options, hash( 'sha256', serialize( BackupPlus_PITR_Process::connection_options( $this->config['source_config'] ) ) ) ) ) );
	}
}
