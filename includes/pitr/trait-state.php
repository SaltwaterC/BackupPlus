<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename -- Backup archives, logs, keys and temporary files are streamed, locked and given permissions directly, which WP_Filesystem cannot do.

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

	/**
	 * The index (state.php) names one file per chain under chains/ that holds its recovery points, the bulk of the state.
	 * A save rewrites only the chains whose points changed, each file has its own size limit, and renaming the index stays
	 * the single commit point. A chain file is named after its chain and the SHA-256 of its contents, so the index cannot be
	 * paired with a file it did not write. Version 1 indexes held the points inline; they load as they are and the next save
	 * splits them.
	 */
	private $persisted = array();

	/** The decoded index, or null when it is absent or unreadable; it is validated by its readers. */
	private static function read_index( $root ) {
		$path = $root . '/state.php';
		if ( is_link( $path ) || ! is_file( $path ) || filesize( $path ) > 67108864 ) {
			return null;
		}
		$raw = file_get_contents( $path );
		return is_string( $raw ) && 0 === strpos( $raw, '<?php exit; ?>' ) ? json_decode( substr( $raw, strlen( '<?php exit; ?>' ) ), true ) : null;
	}

	/** The chain of the index with its recovery points, and the file and records they came from. */
	private static function load_chain( $root, $state, $id ) {
		$chain = $state['chains'][ $id ];
		if ( 1 === $state['version'] || ! array_key_exists( 'entries', $chain ) ) {
			return array( $chain, null, null );
		}
		$file = isset( $chain['entries'] ) ? $chain['entries'] : null;
		if ( ! is_string( $file ) || ! is_string( $id ) || ! preg_match( '/\A([a-f0-9]{32})-([a-f0-9]{64})\.php\z/D', $file, $name ) || $id !== $name[1] ) {
			throw new RuntimeException( 'PITR chain index is corrupt; rescan remote artifacts before continuing.' );
		}
		$path    = $root . '/chains/' . $file;
		$raw     = ! is_link( $path ) && is_file( $path ) && filesize( $path ) <= 67108864 ? file_get_contents( $path ) : false;
		$records = is_string( $raw ) && hash_equals( $name[2], hash( 'sha256', $raw ) ) && 0 === strpos( $raw, '<?php exit; ?>' ) ? json_decode( substr( $raw, strlen( '<?php exit; ?>' ) ), true ) : null;
		if ( ! is_array( $records ) || ! isset( $records['entries'] ) || ! is_array( $records['entries'] ) ) {
			throw new RuntimeException( 'PITR chain index is corrupt; rescan remote artifacts before continuing.' );
		}
		foreach ( $records as $key => $list ) {
			if ( ! in_array( $key, array( 'entries', 'abandoned_entries' ), true ) || ! is_array( $list ) || ! isset( $chain[ $key ] ) || $file !== $chain[ $key ] ) {
				throw new RuntimeException( 'PITR chain index is corrupt; rescan remote artifacts before continuing.' );
			}
			$chain[ $key ] = $list;
		}
		if ( isset( $chain['abandoned_entries'] ) && ! is_array( $chain['abandoned_entries'] ) ) {
			throw new RuntimeException( 'PITR chain index is corrupt; rescan remote artifacts before continuing.' );
		}
		return array( $chain, $file, $records );
	}

	private function read_state() {
		$path = $this->root . '/state.php';
		if ( ! is_file( $path ) ) {
			return array(
				'version' => 2,
				'chains'  => array(),
				'active'  => null,
				'pending' => null,
			);
		}
		if ( is_link( $path ) || filesize( $path ) > 67108864 ) {
			throw new RuntimeException( 'Invalid PITR chain index.' );
		}
		$state = self::read_index( $this->root );
		if ( ! is_array( $state ) || ! isset( $state['version'], $state['chains'] ) || ! in_array( $state['version'], array( 1, 2 ), true ) || ! is_array( $state['chains'] ) ) {
			throw new RuntimeException( 'PITR chain index is corrupt; rescan remote artifacts before continuing.' );
		}
		foreach ( array_keys( $state['chains'] ) as $id ) {
			list( $state['chains'][ $id ], $file, $records ) = self::load_chain( $this->root, $state, $id );
			if ( null !== $file ) {
				$this->persisted[ $id ] = array(
					'file'    => $file,
					'records' => $records,
				);
			}
		}
		$state['version'] = 2;
		return $state;
	}

	private function save() {
		$directory = BackupPlus_PITR_Process::directory( $this->root . '/chains' );
		$chains    = array();
		$persisted = array();
		foreach ( $this->state['chains'] as $id => $chain ) {
			BackupPlus_PITR_Manifest::id( $id );
			$chains[ $id ] = $chain;
			if ( ! isset( $chain['entries'] ) || ! is_array( $chain['entries'] ) ) {
				continue;
			}
			$records = array( 'entries' => $chain['entries'] );
			if ( isset( $chain['abandoned_entries'] ) ) {
				$records['abandoned_entries'] = $chain['abandoned_entries'];
			}
			// Unchanged points share their arrays with the loaded copy, so this comparison is cheap for the chains a save does not touch.
			if ( isset( $this->persisted[ $id ] ) && $this->persisted[ $id ]['records'] === $records ) {
				$file = $this->persisted[ $id ]['file'];
			} else {
				$json = json_encode( $records, JSON_UNESCAPED_SLASHES );
				if ( false === $json || strlen( $json ) + strlen( '<?php exit; ?>' ) > 67108864 ) {
					throw new RuntimeException( 'Could not encode the recovery points of a PITR chain within their supported size limit.' );
				}
				$contents = '<?php exit; ?>' . $json;
				$file     = $id . '-' . hash( 'sha256', $contents ) . '.php';
				self::replace_file( $directory, $file, $contents );
			}
			// Built anew: callers hold references into the state, and a copy of the state would write through them.
			$chains[ $id ] = array();
			foreach ( $chain as $key => $value ) {
				$chains[ $id ][ $key ] = isset( $records[ $key ] ) ? $file : $value;
			}
			$persisted[ $id ] = array(
				'file'    => $file,
				'records' => $records,
			);
		}
		$index = array();
		foreach ( $this->state as $key => $value ) {
			$index[ $key ] = 'chains' === $key ? $chains : $value;
		}
		$index['version'] = 2;
		$json             = json_encode( $index, JSON_UNESCAPED_SLASHES );
		if ( false === $json || strlen( $json ) + strlen( '<?php exit; ?>' ) > 67108864 ) {
			throw new RuntimeException( 'Could not encode PITR chain state within its supported size limit.' );
		}
		self::replace_file( $this->root, 'state.php', '<?php exit; ?>' . $json );
		$this->persisted = $persisted;
		// Only the committed index names live files: the rest are superseded points, deleted chains and interrupted writes.
		$live = array_column( $persisted, 'file' );
		foreach ( scandir( $directory ) as $name ) {
			$path = $directory . '/' . $name;
			if ( '.' !== $name && '..' !== $name && ! in_array( $name, $live, true ) && ( is_link( $path ) || is_file( $path ) ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/** Written beside its final name and renamed over it, so a reader sees the old or the new file, never part of one. */
	private static function replace_file( $directory, $name, $contents ) {
		$path = $directory . '/' . $name . '-' . bin2hex( random_bytes( 8 ) ) . '.tmp';
		BackupPlus_PITR_Process::private_file( $path, $contents );
		if ( ! rename( $path, $directory . '/' . $name ) ) {
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
		// Named by an administrator, so the PHP user may own it, but not every user may write to it (BPS-20).
		if ( false === BackupPlus_Executables::trusted_directory( $config['bin_directory'], true ) ) {
			throw new RuntimeException( 'The native client directory must be an absolute folder that not every user can write to.' );
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
		$row = $wpdb->get_row( 'SELECT @@version, @@server_id, @@hostname, @@port', ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the database server's current state, which has no WordPress API and must not be cached.
		if ( ! is_array( $row ) || 4 !== count( $row ) || ( 'mariadb' === $source['engine'] ) !== ( false !== stripos( $row[0], 'mariadb' ) ) ) {
			throw new RuntimeException( 'The native source is not this WordPress database server.' );
		}
		$identity = 'mysql' === $source['engine'] ? $wpdb->get_var( 'SELECT @@server_uuid' ) : implode( ':', array_slice( $row, 1 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the database server's current state, which has no WordPress API and must not be cached.
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
		// The WordPress connection moved from source.cnf to the guarded source.php (BPS-09). Its options are hashed below, so the move keeps the chain and any pending backup.
		require_once dirname( __DIR__ ) . '/class-pitr-admin.php';
		if ( isset( $configuration['source_config'] ) && is_string( $configuration['source_config'] ) && $this->root . '/' . BackupPlus_PITR_Admin::SOURCE_FILE === $configuration['source_config'] ) {
			$configuration['source_config'] = $this->root . '/' . BackupPlus_PITR_Admin::LEGACY_SOURCE_FILE;
		}
		$configuration['snapshot_policy'] = 'transactional-ddl-guard-v1';
		return hash( 'sha256', serialize( array( $configuration, $destinations, $encryption, $options, hash( 'sha256', serialize( BackupPlus_PITR_Process::connection_options( $this->config['source_config'] ) ) ) ) ) );
	}
}
