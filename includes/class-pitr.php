<?php

/** Database point-in-time backup chains. Ordinary logical and file backups remain independent. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}
require_once __DIR__ . '/class-pitr-process.php';
require_once __DIR__ . '/class-pitr-manifest.php';
require_once __DIR__ . '/class-pitr-binlog.php';
require_once __DIR__ . '/class-pitr-database.php';
require_once __DIR__ . '/class-pitr-storage.php';

class BackupPlus_PITR {

	const OPTION = 'backupplus_pitr';
	const STATUS = 'backupplus_pitr_status';
	const HOOK   = 'backupplus_pitr_capture';
	private $root;
	protected $work;
	private $lock;
	private $state;
	protected $config;

	public static function register() {
		require_once __DIR__ . '/class-pitr-admin.php';
		BackupPlus_PITR_Admin::register();
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'scheduled' ) );
		add_action( 'init', array( __CLASS__, 'reconcile_schedule' ) );
		add_action( 'backupplus_pitr_status', array( __CLASS__, 'render' ) );
		add_action( 'backupplus_clean_temporary_files', array( __CLASS__, 'clean_temporary' ) );
		register_deactivation_hook( BACKUPPLUS_DIR . '/backupplus.php', array( __CLASS__, 'deactivate' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once __DIR__ . '/class-pitr-cli.php';
			WP_CLI::add_command( 'backupplus pitr', 'BackupPlus_PITR_CLI' );
		}
	}

	public static function schedules( $schedules ) {
		foreach ( self::intervals() as $minutes ) {
			$schedules[ 'backupplus_pitr_' . $minutes ] = array(
				'interval' => $minutes * 60,
				'display'  => 1 === $minutes ? __( 'Database log capture every minute', 'backupplus' ) : sprintf( __( 'Database log capture every %d minutes', 'backupplus' ), $minutes ),
			);
		}
		return $schedules;
	}

	public static function intervals() {
		return array( 1, 5, 10, 15, 30, 60, 120, 180, 240, 360, 480, 720 );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function scheduled() {
		@ignore_user_abort( true );
		@set_time_limit( 0 );
		try {
			$worker = new self();
			// Short intervals already have their own cron cadence; dump/upload time must not skip the next tick.
			$interval = isset( $worker->config['interval'] ) ? (int) $worker->config['interval'] : 15;
			$worker->run( $interval <= 60 ? 'capture' : 'run' );
		} catch ( Throwable $error ) {
			global $backupplus;
			$backupplus->log( $error->getMessage(), 'error' );
		}
	}

	public static function config() {
		$config = BackupPlus_Options::get_backupplus_option( self::OPTION, array( 'enabled' => false ) );
		return is_array( $config ) ? $config : array( 'enabled' => false );
	}

	/** Persist internal state without treating it as the one-field admin form. */
	public static function save_configuration( $config ) {
		unset( $config['restore_connection'] );
		$callback = array( 'BackupPlus_PITR_Admin', 'sanitize' );
		$hook     = 'sanitize_option_' . self::OPTION;
		$priority = has_filter( $hook, $callback );
		if ( false !== $priority ) { remove_filter( $hook, $callback, $priority ); }
		try { return BackupPlus_Options::update_backupplus_option( self::OPTION, $config ); }
		finally { if ( false !== $priority ) { add_filter( $hook, $callback, $priority ); }
		}
	}

	/** Capture now from the queued admin request, preserving its progress/log identifier. */
	public static function manual_capture( $options ) {
		global $backupplus;
		$nonce = $backupplus->backup_time_nonce( isset( $options['use_nonce'] ) ? $options['use_nonce'] : false );
		$backupplus->jobdata_set_from_array(
			array(
				'job_type'    => 'pitr',
				'jobstatus'   => 'pitrcreating',
				'backup_time' => time(),
			)
		);
		$backupplus->current_resumption = 0;
		$backupplus->logfile_open( $nonce );
		try {
			$backupplus->log( 'Capturing a PITR database recovery point using saved encryption and storage settings.' );
			$worker = new self();
			if ( ! empty( $worker->state['pending'] ) ) {
				// Finish the interrupted publication, then capture changes through this request.
				$worker->run( 'capture', $nonce );
			}
			$result = $worker->run( 'capture', $nonce );
			$backupplus->log( 'PITR database recovery point captured.' );
			return $result;
		} catch ( Throwable $error ) {
			$backupplus->log( $error->getMessage(), 'error' );
			$backupplus->log( 'PITR database recovery point failed: ' . $error->getMessage() );
			$backupplus->jobdata_set( 'pitr_error', $error->getMessage() );
			$status          = get_option( self::STATUS, array() );
			$status['error'] = $error->getMessage();
			update_option( self::STATUS, $status, false );
			return false;
		} finally {
			$backupplus->jobdata_set( 'jobstatus', 'finished' );
			if ( is_resource( $backupplus->logfile_handle ) ) {
				fclose( $backupplus->logfile_handle );
				$backupplus->logfile_handle = null;
			}
		}
	}

	public static function reconcile_schedule() {
		$config = self::config();
		if ( empty( $config['enabled'] ) ) {
			if ( wp_next_scheduled( self::HOOK ) ) {
				self::deactivate();
			} return;
		}
		if ( ! isset( $config['interval'] ) || ! in_array( (int) $config['interval'], self::intervals(), true ) ) {
			return;
		}
		// Renew daily baselines even when incremental capture is only twice a day.
		$recurrence = 'backupplus_pitr_' . min( 60, (int) $config['interval'] );
		if ( wp_get_schedule( self::HOOK ) !== $recurrence ) {
			self::deactivate();
			wp_schedule_event( time(), $recurrence, self::HOOK );
		}
	}

	public function __construct( $config = null ) {
		global $backupplus;
		$this->config = null === $config ? self::config() : $config;
		$this->root   = BackupPlus_PITR_Process::directory( $backupplus->backups_dir_location() . '/pitr' );
		if ( ! is_file( $this->root . '/.htaccess' ) ) {
			BackupPlus_PITR_Process::private_file( $this->root . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
		if ( ! is_file( $this->root . '/index.php' ) ) {
			BackupPlus_PITR_Process::private_file( $this->root . '/index.php', "<?php exit;\n" );
		}
		$mask = umask( 0077 );
		try {
			$this->lock = fopen( $this->root . '/lock', 'c+b' );
		} finally {
			umask( $mask );
		}
		if ( ! $this->lock || ! flock( $this->lock, LOCK_EX | LOCK_NB ) ) {
			throw new RuntimeException( 'A PITR capture, restore or deletion is already running.' );
		}
		$this->state = $this->read_state();
		$this->work  = BackupPlus_PITR_Process::directory( $this->root . '/work-' . bin2hex( random_bytes( 16 ) ) );
		$this->reap_work();
	}

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
		unset( $configuration['enabled'], $configuration['interval'], $configuration['server_cron'], $configuration['restore_connection'] );
		if ( ! empty( $configuration['capture_account'] ) ) { $configuration['capture_credentials'] = get_option( 'backupplus_pitr_capture', null ); }
		$configuration['snapshot_policy'] = 'transactional-ddl-guard-v1';
		return hash( 'sha256', serialize( array( $configuration, $destinations, $encryption, $options, hash( 'sha256', serialize( BackupPlus_PITR_Process::connection_options( $this->config['source_config'] ) ) ) ) ) );
	}

	/** Bind source history to a random, logged plugin option, including across filename reuse. */
	private function source_anchor( $adapter, $minimum ) {
		global $wpdb;
		$before = $adapter->status();
		$token  = bin2hex( random_bytes( 32 ) );
		// The application writes its own ancestry marker; the backup account stays data-read-only.
		$was_logged = $wpdb->get_var( 'SELECT @@session.sql_log_bin' );
		if ( '0' === $was_logged && false === $wpdb->query( 'SET SESSION sql_log_bin=1' ) ) { throw new RuntimeException( 'Could not log the PITR source marker.' ); }
		try {
			$sql = BackupPlus_SQL::wpdb();
			if ( false === $sql->execute( "INSERT INTO %i (option_name, option_value, autoload) VALUES ('backupplus_pitr_source_anchor', ?, 'off') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)", array( array( $this->config['database'], $wpdb->options ) ), array( $token ) ) ) { throw new RuntimeException( 'WordPress could not write its PITR source marker. ' . $sql->last_error() ); }
		} finally { if ( '0' === $was_logged ) { $wpdb->query( 'SET SESSION sql_log_bin=0' ); }
		}
		$names = array_keys( $adapter->logs() );
		$start = array_search( $before['file'], $names, true );
		if ( false === $start || false === array_search( $minimum['file'], $names, true ) ) {
			throw new RuntimeException( 'Source log was reset or purged while anchoring the backup.' );
		}
		foreach ( array_slice( $names, $start ) as $file ) {
			$path   = $adapter->fetch_log( $file );
			$handle = fopen( $path, 'rb' );
			$tail   = '';
			$found  = false;
			try {
				while ( ! feof( $handle ) ) {
					$chunk = fread( $handle, 1048576 );
					if ( false === $chunk ) {
						throw new RuntimeException( 'Could not read source-log proof.' );
					}
					if ( false !== strpos( $tail . $chunk, $token ) ) {
						$found = true;
						break;
					}
					$tail = substr( $chunk, -63 );
				}
			} finally {
				fclose( $handle );
			}
			if ( $found ) {
				$position = filesize( $path );
				if ( $minimum['file'] === $file && $position < $minimum['position'] ) {
					throw new RuntimeException( 'Source-log proof precedes the snapshot.' );
				}
				return array(
					'coordinate' => array(
						'file'     => $file,
						'position' => $position,
					),
					'sha256'     => BackupPlus_PITR_Binlog::prefix_hash( $path, $position ),
				);
			}
		}
		throw new RuntimeException( 'The source did not log the PITR ancestry marker. Check log filters and compression.' );
	}

	private function sql_header( $input, $original = null ) {
		global $wpdb;
		$path    = $this->work . '/wordpress-' . bin2hex( random_bytes( 8 ) ) . '.sql';
		$header  = "# WordPress MySQL database backup\n# Created by BackupPlus PITR\n";
		$uploads = wp_upload_dir();
		foreach (
			array(
				'Backup of'             => untrailingslashit( site_url() ),
				'Home URL'              => untrailingslashit( home_url() ),
				'Content URL'           => untrailingslashit( content_url() ),
				'Uploads URL'           => $uploads['baseurl'],
				'Table prefix'          => $wpdb->prefix,
				'Filtered table prefix' => $wpdb->prefix,
				'ABSPATH'               => ABSPATH,
				'Site info'             => 'multisite=' . ( is_multisite() ? '1' : '0' ),
			) as $key => $value
		) {
			$header .= '# ' . $key . ': ' . str_replace( array( "\r", "\n" ), '', $value ) . "\n";
		}
		if ( null !== $original ) {
			$handle = fopen( $original, 'rb' );
			$header = '';
			try {
				while ( false !== ( $line = fgets( $handle, 65536 ) ) && '#' === substr( $line, 0, 1 ) && strlen( $header ) < 65536 ) {
					$header .= $line;
				}
			} finally {
				fclose( $handle );
			}
			if ( '' === $header ) {
				throw new RuntimeException( 'Original WordPress snapshot metadata is missing.' );
			}
		}
		BackupPlus_PITR_Process::private_file( $path, $header );
		$in  = fopen( $input, 'rb' );
		$out = fopen( $path, 'ab' );
		try {
			if ( false === stream_copy_to_stream( $in, $out ) ) {
				throw new RuntimeException( 'Could not prepare WordPress dump metadata.' );
			}
		} finally {
			fclose( $in );
				fclose( $out );
		}
		return $path;
	}

	private function stage_manifest( $manifest, $storage, $fingerprint, $destinations, $encryption ) {
		BackupPlus_PITR_Manifest::validate( $manifest );
		$prefix  = 'backup_pitr_' . $manifest['chain_id'] . '_' . $manifest['backup_id'] . '_';
		$json    = $this->work . '/manifest-' . bin2hex( random_bytes( 8 ) ) . '.json';
		$encoded = json_encode( $manifest, JSON_UNESCAPED_SLASHES );
		if ( false === $encoded || strlen( $encoded ) > 16777216 ) {
			throw new RuntimeException( 'PITR manifest exceeds the supported limit; shorten the capture interval.' );
		}
		BackupPlus_PITR_Process::private_file( $json, $encoded );
		$artifact     = $storage->artifact( $json, $prefix . 'manifest.json', false );
		$locator      = array(
			'manifest_version' => 1,
			'chain_id'         => $manifest['chain_id'],
			'backup_id'        => $manifest['backup_id'],
			'manifest'         => array_intersect_key( $artifact, array_flip( array( 'name', 'sha256', 'size' ) ) ),
		);
		$locator_path = $storage->path( $prefix . 'locator.json' );
		BackupPlus_PITR_Process::private_file( $locator_path, json_encode( $locator, JSON_UNESCAPED_SLASHES ) );
		$locator_file           = array(
			'name'   => basename( $locator_path ),
			'sha256' => hash_file( 'sha256', $locator_path ),
			'size'   => filesize( $locator_path ),
		);
		$this->state['pending'] = array(
			'entry'        => array(
				'manifest' => $manifest,
				'sha256'   => $artifact['sha256'],
				'locator'  => $locator,
			),
			'files'        => array_merge( $manifest['artifacts'], array( $artifact, $locator_file ) ),
			'fingerprint'  => $fingerprint,
			'destinations' => $destinations,
			'encryption'   => $encryption,
			'receipts'     => array(),
		);
		$this->save();
	}

	private function full( $adapter, $storage, $fingerprint, $destinations, $encryption ) {
		global $wpdb;
		$source = $adapter->source( $this->config['database'] );
		$this->assert_wordpress_source( $source, $this->config['database'] );
		$source['log_epoch'] = bin2hex( random_bytes( 16 ) );
		$id                  = bin2hex( random_bytes( 16 ) );
		$chain               = bin2hex( random_bytes( 16 ) );
		$created             = time();
		$sql                 = $this->work . '/full-' . bin2hex( random_bytes( 8 ) ) . '.sql';
		$before_anchor       = $this->source_anchor( $adapter, $adapter->status() );
		$coordinate          = $adapter->dump( $this->config['database'], $sql );
		$after               = $adapter->source( $this->config['database'] );
		foreach ( $after as $key => $value ) {
			if ( $source[ $key ] !== $value ) {
				throw new RuntimeException( 'Database source changed during the full snapshot.' );
			}
		}
		$anchor = $this->source_anchor( $adapter, $coordinate );
		$proof  = $adapter->fetch_log( $before_anchor['coordinate']['file'] );
		if ( ! hash_equals( $before_anchor['sha256'], BackupPlus_PITR_Binlog::prefix_hash( $proof, $before_anchor['coordinate']['position'] ) ) ) {
			throw new RuntimeException( 'Source binary logs were reset during the full snapshot.' );
		}
		$artifact = $storage->artifact( $this->sql_header( $sql ), 'backup_pitr_' . $chain . '_' . $id . '_full.sql.gz' );
		$old_id   = $this->state['active'];
		if ( $old_id && isset( $this->state['chains'][ $old_id ] ) && 'complete' === $this->state['chains'][ $old_id ]['state'] && $fingerprint === $this->state['chains'][ $old_id ]['fingerprint'] ) {
			try {
				if ( $this->capture( $adapter, $storage, $fingerprint, $destinations, $encryption ) ) {
					$this->publish( $storage );
				}
			} catch ( Throwable $error ) {
				if ( $this->state['pending'] ) {
					throw $error;
				} $this->state['chains'][ $old_id ]['state'] = 'broken';
				$this->save();
			}
		}
		// Old-chain capture closes logs across the new snapshot, so daily rollover leaves no historical hole.
		$proof      = $adapter->fetch_log( $before_anchor['coordinate']['file'] );
		$post_proof = $adapter->fetch_log( $anchor['coordinate']['file'] );
		if ( ! hash_equals( $before_anchor['sha256'], BackupPlus_PITR_Binlog::prefix_hash( $proof, $before_anchor['coordinate']['position'] ) ) || ! hash_equals( $anchor['sha256'], BackupPlus_PITR_Binlog::prefix_hash( $post_proof, $anchor['coordinate']['position'] ) ) ) {
			throw new RuntimeException( 'Source log history changed while preparing the new baseline.' );
		}
		$manifest = array(
			'manifest_version'       => 1,
			'kind'                   => 'database-full',
			'backup_id'              => $id,
			'chain_id'               => $chain,
			'source'                 => $source,
			'database'               => $this->config['database'],
			'created_at'             => $created,
			'snapshot_coordinate'    => $coordinate,
			'database_events'        => $adapter->snapshot_events,
			'anchor'                 => $anchor,
			'snapshot_gtid_state'    => null,
			'artifacts'              => array( $artifact ),
			'snapshot_mode'          => 'transactional-ddl-guard',
			'wordpress_table_prefix' => $wpdb->prefix,
		);
		$this->stage_manifest( $manifest, $storage, $fingerprint, $destinations, $encryption );
	}

	private function capture( $adapter, $storage, $fingerprint, $destinations, $encryption ) {
		$chain   = $this->state['chains'][ $this->state['active'] ];
		$entries = $chain['entries'];
		$first   = $entries[0]['manifest'];
		$last    = end( $entries );
		$cursor  = BackupPlus_PITR_Manifest::chain( $entries );
		$source  = $adapter->source( $first['database'] );
		$this->assert_wordpress_source( $source, $first['database'] );
		foreach ( $source as $key => $value ) {
			if ( $first['source'][ $key ] !== $value ) {
				throw new RuntimeException( 'PITR source changed; create a new full snapshot.' );
			}
		}
		$adapter->query( 'FLUSH BINARY LOGS' );
		$logs        = $adapter->logs();
		$names       = array_keys( $logs );
		$current     = end( $names );
		$start_index = array_search( $cursor['file'], $names, true );
		if ( false === $start_index ) {
			throw new RuntimeException( 'Required source binary log was purged; the historical chain cannot be extended.' );
		}
		$id              = bin2hex( random_bytes( 16 ) );
		$prefix          = 'backup_pitr_' . $first['chain_id'] . '_' . $id . '_';
		$segments        = array();
		$artifacts       = array();
		$next            = $cursor;
		$anchor          = $last['manifest']['anchor'];
		$anchor_verified = false;
		for ( $i = $start_index; $i < count( $names ) - 1; ++$i ) {
			if ( $next['file'] !== $names[ $i ] ) {
				throw new RuntimeException( 'Source binary-log rotation chain has a gap.' );
			}
			$path = $adapter->fetch_log( $names[ $i ] );
			if ( filesize( $path ) !== $logs[ $names[ $i ] ] ) {
				throw new RuntimeException( 'Closed source binary-log size changed.' );
			}
			if ( $anchor['coordinate']['file'] === $names[ $i ] ) {
				if ( ! hash_equals( $anchor['sha256'], BackupPlus_PITR_Binlog::prefix_hash( $path, $anchor['coordinate']['position'] ) ) ) {
					throw new RuntimeException( 'Source binary-log ancestry changed or filenames were reused.' );
				}
				$anchor_verified = true;
			}
			$inspection = $this->inspect_binlog( $path, $next['position'], $first['database'], $source['engine'] );
			if ( $inspection['next']['file'] !== $names[ $i + 1 ] ) {
				throw new RuntimeException( 'Source binary-log successor is missing.' );
			}
			$artifact    = $storage->artifact( $path, $prefix . 'log' . count( $segments ) . '.bin.gz' );
			$segments[]  = array_merge(
				array(
					'start'    => $next,
					'artifact' => $artifact['name'],
				),
				$inspection
			);
			$artifacts[] = $artifact;
			$next        = $inspection['next'];
		}
		if ( ! $segments ) {
			return false;
		}
		if ( ! $anchor_verified ) {
			throw new RuntimeException( 'The source binary-log ancestry proof is missing.' );
		}
		$new_anchor = $this->source_anchor( $adapter, $next );
		$proof      = $adapter->fetch_log( $anchor['coordinate']['file'] );
		if ( ! hash_equals( $anchor['sha256'], BackupPlus_PITR_Binlog::prefix_hash( $proof, $anchor['coordinate']['position'] ) ) ) {
			throw new RuntimeException( 'Source binary logs were reset during incremental capture.' );
		}
		$manifest = array(
			'manifest_version'         => 1,
			'kind'                     => 'database-binlog',
			'backup_id'                => $id,
			'chain_id'                 => $first['chain_id'],
			'baseline_id'              => $first['backup_id'],
			'previous_backup_id'       => $last['manifest']['backup_id'],
			'previous_manifest_sha256' => $last['sha256'],
			'source'                   => $first['source'],
			'database'                 => $first['database'],
			'created_at'               => time(),
			'start_coordinate'         => $cursor,
			'end_coordinate'           => $next,
			'segments'                 => $segments,
			'anchor'                   => $new_anchor,
			'artifacts'                => $artifacts,
		);
		$this->stage_manifest( $manifest, $storage, $fingerprint, $destinations, $encryption );
		return true;
	}

	private function publish( $storage ) {
		$pending  = $storage->publish(
			$this->state['pending'],
			function ( $pending ) {
				$this->state['pending'] = $pending;
				$this->save();
			}
		);
		$entry    = $pending['entry'];
		$manifest = $entry['manifest'];
		$id       = $manifest['chain_id'];
		if ( ! isset( $this->state['chains'][ $id ] ) ) {
			$this->state['chains'][ $id ] = array(
				'entries'      => array(),
				'destinations' => $pending['destinations'],
				'fingerprint'  => $pending['fingerprint'],
				'state'        => 'complete',
				'imported'     => false,
			);
		}
		$chain              = &$this->state['chains'][ $id ];
		$chain['entries'][] = $entry;
		BackupPlus_PITR_Manifest::chain( $chain['entries'] );
		$chain['cursor']       = 'database-full' === $manifest['kind'] ? $manifest['snapshot_coordinate'] : $manifest['end_coordinate'];
		$chain['last_capture'] = time();
		if ( 'database-full' === $manifest['kind'] && ! empty( $this->state['active'] ) && $this->state['active'] !== $id ) {
			$this->state['protected_previous'][] = $this->state['active'];
		}
		if ( 'database-binlog' === $manifest['kind'] ) {
			$this->state['protected_previous'] = array();
		}
		$this->state['active']  = $id;
		$this->state['pending'] = null;
		$this->save();
		$this->history( $id );
		return $manifest;
	}

	private function history( $id ) {
		$chain   = &$this->state['chains'][ $id ];
		$full    = $chain['entries'][0]['manifest'];
		$history = BackupPlus_Backup_History::get_history();
		if ( ! isset( $chain['history_time'] ) ) {
			$time = $full['created_at'];
			while ( isset( $history[ $time ] ) ) {
				++$time;
			} $chain['history_time'] = $time;
		}
		if ( isset( $history[ $chain['history_time'] ] ) && ( empty( $history[ $chain['history_time'] ]['pitr']['chain_id'] ) || $id !== $history[ $chain['history_time'] ]['pitr']['chain_id'] ) ) {
			$time = max( time(), $chain['history_time'] + 1 );
			while ( isset( $history[ $time ] ) ) {
				++$time;
			} $chain['history_time'] = $time;
		}
		$time      = $chain['history_time'];
		$artifact  = $full['artifacts'][0];
		$services  = array();
		$instances = array();
		foreach ( $chain['destinations'] as $destination ) {
			$services[]                             = $destination['service'];
			$instances[ $destination['service'] ][] = $destination['instance'];
		}
		$history[ $time ] = array_merge(
			isset( $history[ $time ] ) ? $history[ $time ] : ( ! empty( $chain['pinned'] ) ? array( 'always_keep' => true ) : array() ),
			array(
				'db'                   => $artifact['name'],
				'db-size'              => $artifact['size'],
				'nonce'                => substr( $full['backup_id'], 0, 12 ),
				'native'               => ! $chain['imported'],
				'service'              => $services ? array_values( array_unique( $services ) ) : array( 'none' ),
				'service_instance_ids' => $instances,
				'pitr'                 => self::chain_metadata( $id, $chain ),
				'checksums'            => array( 'sha256' => array( 'db0' . ( '.crypt' === substr( $artifact['name'], -6 ) ? '.crypt' : '' ) => $artifact['sha256'] ) ),
			)
		);
		BackupPlus_Backup_History::save_history( $history );
		$this->save();
	}

	/** Republish verified local index metadata without decrypting or changing backup artifacts. */
	public function rebuild_history() {
		$ids = array();
		foreach ( $this->state['chains'] as $id => $chain ) {
			if ( 'deleting' === $chain['state'] || empty( $chain['entries'] ) ) { continue; }
			self::chain_metadata( $id, $chain );
			$ids[] = $id;
		}
		foreach ( $ids as $id ) { $this->history( $id ); }
		return count( $ids );
	}

	/** Recovery endpoints are identified by immutable backup IDs, never rounded timestamps. */
	public static function chain_metadata( $id, $chain ) {
		$cursor = BackupPlus_PITR_Manifest::chain( $chain['entries'] );
		if ( $cursor !== $chain['cursor'] ) {
			throw new RuntimeException( 'The PITR chain index has an inconsistent endpoint.' );
		}
		$points    = array();
		$encrypted = false;
		foreach ( $chain['entries'] as $index => $entry ) {
			$manifest = $entry['manifest'];
			foreach ( $manifest['artifacts'] as $artifact ) {
				$encrypted = $encrypted || '.crypt' === substr( $artifact['name'], -6 );
			}
			$points[] = array(
				'backup_id'  => $manifest['backup_id'],
				'timestamp'  => $manifest['created_at'],
				'coordinate' => 0 === $index ? $manifest['snapshot_coordinate'] : $manifest['end_coordinate'],
				'increment'  => $index,
				'encrypted'  => $encrypted,
			);
		}
		return array(
			'chain_id'            => $id,
			'snapshot_coordinate' => $chain['entries'][0]['manifest']['snapshot_coordinate'],
			'end_coordinate'      => $cursor,
			'state'               => $chain['state'],
			'points'              => $points,
		);
	}

	/** Enrich older history rows from the atomically written index without starting a worker. */
	public static function decorate_history( $history ) {
		$has_chains = false;
		foreach ( $history as $backup ) {
			$has_chains = $has_chains || ! empty( $backup['pitr'] );
		}
		if ( ! $has_chains ) { return $history; }
		global $backupplus;
		$path  = $backupplus->backups_dir_location() . '/pitr/state.php';
		$state = null;
		if ( ! is_link( $path ) && is_file( $path ) && filesize( $path ) < 67108864 ) {
			$raw = file_get_contents( $path );
			if ( 0 === strpos( $raw, '<?php exit; ?>' ) ) {
				$state = json_decode( substr( $raw, strlen( '<?php exit; ?>' ) ), true );
			}
		}
		foreach ( $history as &$backup ) {
			if ( empty( $backup['pitr'] ) ) { continue; }
			$id = isset( $backup['pitr']['chain_id'] ) ? $backup['pitr']['chain_id'] : '';
			try {
				if ( ! is_array( $state ) || ! isset( $state['version'], $state['chains'][ $id ] ) || 1 !== $state['version'] ) {
					throw new RuntimeException( 'The PITR chain index is unavailable.' );
				}
				$backup['pitr'] = self::chain_metadata( $id, $state['chains'][ $id ] );
			} catch ( Throwable $error ) {
				$backup['pitr']['state']  = 'unavailable';
				$backup['pitr']['points'] = array();
			}
		}
		unset( $backup );
		return $history;
	}

	public function run( $mode = 'run', $nonce = false ) {
		global $backupplus;
		if ( empty( $this->config['enabled'] ) ) {
			throw new RuntimeException( 'Database point-in-time recovery is disabled.' );
		}
		$this->job( $nonce );
		try {
			$destinations = BackupPlus_PITR_Storage::destinations();
			$encryption   = $this->encryption();
			$fingerprint  = $this->fingerprint( $destinations, $encryption );
			$storage      = new BackupPlus_PITR_Storage( $this->work, $encryption, $destinations );
			if ( $this->state['pending'] ) {
				if ( $fingerprint !== $this->state['pending']['fingerprint'] ) {
					throw new RuntimeException( 'PITR configuration changed with an unpublished backup pending; restore the original configuration before retrying.' );
				}
				$result = $this->publish( $storage );
			} else {
				$adapter = $this->database( $this->config, $this->work );
				$active  = ! empty( $this->state['active'] ) && isset( $this->state['chains'][ $this->state['active'] ] ) ? $this->state['chains'][ $this->state['active'] ] : null;
				$due     = ! $active || 'complete' !== $active['state'] || $active['fingerprint'] !== $fingerprint || time() - $active['entries'][0]['manifest']['created_at'] >= 23 * 3600;
				if ( 'full' === $mode || $due ) {
					// Preserve old-chain coverage up to rollover when the same source/configuration is still usable.
					if ( $active && 'complete' === $active['state'] && $active['fingerprint'] === $fingerprint ) {
						try {
							if ( $this->capture( $adapter, $storage, $fingerprint, $destinations, $encryption ) ) {
								$this->publish( $storage );
							}
						} catch ( Throwable $error ) {
							if ( $this->state['pending'] ) {
								throw $error;
							} $this->state['chains'][ $this->state['active'] ]['state'] = 'broken';
							$this->save();
						}
					}
					$this->full( $adapter, $storage, $fingerprint, $destinations, $encryption );
				} elseif ( 'capture' === $mode || time() - $active['last_capture'] >= $this->config['interval'] * 60 ) {
					try {
						$this->capture( $adapter, $storage, $fingerprint, $destinations, $encryption );
					} catch ( Throwable $error ) {
						$this->state['chains'][ $this->state['active'] ]['state'] = 'broken';
						$this->save();
						throw $error;
					}
				}
				$result = $this->state['pending'] ? $this->publish( $storage ) : array( 'kind' => 'not-due' );
			}
			$this->state['error']        = null;
			$this->state['last_success'] = time();
			$this->save();
			update_option( self::STATUS, $this->summary(), false );
			$backupplus->jobdata_set( 'jobstatus', 'finished' );
			if ( ! BackupPlus_Retention_Cleanup::deferred() ) {
				$this->prune();
			}
			return $result;
		} catch ( Throwable $error ) {
			$this->state['error'] = $error->getMessage();
			$this->save();
			update_option( self::STATUS, $this->summary(), false );
			$backupplus->jobdata_set( 'jobstatus', 'finished' );
			throw $error;
		}
	}

	public function summary( $include_boundaries = false ) {
		$result = array(
			'enabled'          => ! empty( $this->config['enabled'] ),
			'interval_minutes' => isset( $this->config['interval'] ) ? $this->config['interval'] : null,
			'pending'          => ! empty( $this->state['pending'] ),
			'error'            => isset( $this->state['error'] ) ? $this->state['error'] : null,
			'chains'           => array(),
		);
		foreach ( $this->state['chains'] as $id => $chain ) {
			$full       = $chain['entries'][0]['manifest'];
			$boundaries = array();
			foreach ( $include_boundaries ? $chain['entries'] : array() as $entry ) {
				foreach ( isset( $entry['manifest']['segments'] ) ? $entry['manifest']['segments'] : array() as $segment ) {
					foreach ( $segment['boundaries'] as $boundary ) {
						$boundaries[] = array(
							'file'        => $segment['start']['file'],
							'position'    => $boundary['position'],
							'recorded_at' => $boundary['timestamp'],
						);
					}
				}
			}
			$result['chains'][] = array(
				'chain_id'            => $id,
				'active'              => $id === $this->state['active'],
				'state'               => $chain['state'],
				'baseline_at'         => $full['created_at'],
				'snapshot_coordinate' => $full['snapshot_coordinate'],
				'end_coordinate'      => $chain['cursor'],
				'last_capture'        => isset( $chain['last_capture'] ) ? $chain['last_capture'] : null,
				'increments'          => count( $chain['entries'] ) - 1,
				'boundaries'          => $boundaries,
				'destinations'        => $chain['destinations'],
			);
		}
		return $result;
	}

	public function rescan( $reader ) {
		$this->job();
		$storage = new BackupPlus_PITR_Storage( $this->work, array(), null, $reader );
		$groups  = array();
		foreach ( $storage->discover() as $locator ) {
			$path = $storage->plain( $locator['manifest'] );
			if ( filesize( $path ) > 16777216 ) {
				throw new RuntimeException( 'PITR manifest exceeds its limit.' );
			}
			$manifest = BackupPlus_PITR_Manifest::validate( json_decode( file_get_contents( $path ), true ) );
			if ( $manifest['chain_id'] !== $locator['chain_id'] || $manifest['backup_id'] !== $locator['backup_id'] ) {
				throw new RuntimeException( 'PITR discovery binding mismatch.' );
			}
			$groups[ $manifest['chain_id'] ][ $manifest['backup_id'] ] = array(
				'manifest' => $manifest,
				'sha256'   => $locator['manifest']['sha256'],
				'locator'  => $locator,
			);
		}
		foreach ( $groups as $id => $entries ) {
			$ordered = array();
			foreach ( $entries as $key => $entry ) {
				if ( 'database-full' === $entry['manifest']['kind'] ) {
					$ordered[] = $entry;
					unset( $entries[ $key ] );
					break;
				}
			}
			if ( ! $ordered ) {
				throw new RuntimeException( 'Discovered PITR chain is missing its full backup.' );
			}
			while ( $entries ) {
				$last    = end( $ordered );
				$matches = array();
				foreach ( $entries as $key => $entry ) {
					if ( isset( $entry['manifest']['previous_backup_id'] ) && $entry['manifest']['previous_backup_id'] === $last['manifest']['backup_id'] ) {
						$matches[ $key ] = $entry;
					}
				}
				if ( 1 !== count( $matches ) ) {
					throw new RuntimeException( 'Discovered PITR chain contains a gap or a fork.' );
				}
				$key       = key( $matches );
				$ordered[] = $entries[ $key ];
				unset( $entries[ $key ] );
			}
			$cursor = BackupPlus_PITR_Manifest::chain( $ordered );
			foreach ( $ordered as $entry ) {
				foreach ( $entry['manifest']['artifacts'] as $artifact ) {
					$storage->fetch( $artifact );
				}
			}
			if ( ! isset( $this->state['chains'][ $id ] ) ) {
				$this->state['chains'][ $id ] = array(
					'entries'      => $ordered,
					'cursor'       => $cursor,
					'destinations' => BackupPlus_PITR_Storage::destinations(),
					'fingerprint'  => '',
					'state'        => 'complete',
					'imported'     => true,
				);
				$this->history( $id );
			} else {
				$known = $this->state['chains'][ $id ];
				if ( 'deleting' === $known['state'] || array_column( $known['entries'], 'sha256' ) !== array_column( $ordered, 'sha256' ) ) { throw new RuntimeException( 'Rescan cannot rebind an incomplete or changed local chain.' ); }
				// Successful discovery and payload verification prove that these current
				// storage settings hold the same complete chain (including after credential rotation).
				$this->state['chains'][ $id ]['destinations'] = BackupPlus_PITR_Storage::destinations();
			}
		}
		$this->save();
		$this->rebuild_history();
		return $this->summary();
	}

	/** A plan selects only complete transaction boundaries, never an arbitrary byte/time cutoff. */
	public function recovery_plan( $id, $target = null ) {
		BackupPlus_PITR_Manifest::id( $id );
		if ( ! isset( $this->state['chains'][ $id ] ) || 'deleting' === $this->state['chains'][ $id ]['state'] ) {
			throw new RuntimeException( 'PITR chain is unavailable for recovery.' );
		}
		$chain    = $this->state['chains'][ $id ];
		$entries  = $chain['entries'];
		$latest   = BackupPlus_PITR_Manifest::chain( $entries );
		$full     = $entries[0]['manifest'];
		$target   = null === $target ? $latest : BackupPlus_PITR_Manifest::coordinate( $target );
		$selected = array();
		$found    = $target === $full['snapshot_coordinate'];
		$stop     = $full['snapshot_coordinate']['position'];
		if ( ! $found ) {
			foreach ( array_slice( $entries, 1 ) as $entry ) {
				foreach ( $entry['manifest']['segments'] as $segment ) {
					$selected[] = array(
						'segment' => $segment,
						'entry'   => $entry,
					);
					if ( $target === $segment['next'] ) {
						$found = true;
						$stop  = $segment['end'];
						break 2;
					}
					if ( $target['file'] === $segment['start']['file'] ) {
						foreach ( $segment['boundaries'] as $boundary ) {
							if ( $target['position'] === $boundary['position'] ) {
								$found = true;
								$stop  = $target['position'];
								break 3;
							}
						}
					}
				}
			}
		}
		if ( ! $found ) {
			throw new RuntimeException( 'Requested coordinate is outside verified coverage or splits a transaction. Choose a listed boundary.' );
		}
		$payloads = $full['artifacts'];
		foreach ( $selected as $item ) {
			foreach ( $item['entry']['manifest']['artifacts'] as $artifact ) {
				if ( $artifact['name'] === $item['segment']['artifact'] ) {
					$payloads[] = $artifact;
				}
			}
		}
		$estimate = 0;
		foreach ( $payloads as $artifact ) {
			$estimate += $artifact['size'] + ( isset( $artifact['plain_size'] ) ? $artifact['plain_size'] : $artifact['size'] ) + 4 * ( isset( $artifact['raw_size'] ) ? $artifact['raw_size'] : $artifact['size'] );
		}
		return array(
			'chain_id'              => $id,
			'baseline_id'           => $full['backup_id'],
			'database'              => $full['database'],
			'source'                => $full['source'],
			'snapshot_coordinate'   => $full['snapshot_coordinate'],
			'target_coordinate'     => $target,
			'stop_position'         => $stop,
			'segments'              => $selected,
			'baseline'              => $entries[0],
			'destinations'          => $chain['destinations'],
			'verified'              => false,
			'estimated_local_bytes' => $estimate,
		);
	}

	private function unpack( $path, $artifact ) {
		$target = $this->work . '/unpacked-' . bin2hex( random_bytes( 8 ) );
		BackupPlus_PITR_Process::private_file( $target );
		$input  = gzopen( $path, 'rb' );
		$output = fopen( $target, 'wb' );
		if ( ! $input || ! $output ) {
			throw new RuntimeException( 'Could not open compressed PITR artifact.' );
		}
		$produced = 0;
		try {
			while ( ! gzeof( $input ) ) {
				$data      = gzread( $input, 1048576 );
				$produced += strlen( false === $data ? '' : $data );
				if ( isset( $artifact['raw_size'] ) && $produced > $artifact['raw_size'] ) {
					throw new RuntimeException( 'PITR decompression exceeded its declared size.' );
				}
				if ( false === $data || ( '' === $data && ! gzeof( $input ) ) ) {
					throw new RuntimeException( 'Corrupt compressed PITR artifact.' );
				}
				$offset = 0;
				while ( $offset < strlen( $data ) ) {
					$written = fwrite( $output, substr( $data, $offset ) );
					if ( ! $written ) {
						throw new RuntimeException( 'PITR recovery disk is full.' );
					} $offset += $written;
				}
			}
		} finally {
			gzclose( $input );
			fclose( $output );
		}
		if ( isset( $artifact['raw_size'] ) && ( $produced !== $artifact['raw_size'] || ! hash_equals( $artifact['raw_sha256'], hash_file( 'sha256', $target ) ) ) ) {
			throw new RuntimeException( 'PITR uncompressed artifact verification failed.' );
		}
		return $target;
	}

	private function authenticate( $plan, $reader ) {
		$storage = new BackupPlus_PITR_Storage( $this->work, array(), $plan['destinations'], $reader );
		$entries = array( $plan['baseline']['manifest']['backup_id'] => $plan['baseline'] );
		foreach ( $plan['segments'] as $selected ) {
			$entries[ $selected['entry']['manifest']['backup_id'] ] = $selected['entry'];
		}
		foreach ( $entries as $entry ) {
			$path = $storage->plain( $entry['locator']['manifest'] );
			if ( filesize( $path ) > 16777216 || BackupPlus_PITR_Manifest::validate( json_decode( file_get_contents( $path ), true ) ) !== $entry['manifest'] ) {
				throw new RuntimeException( 'Persistent PITR manifest differs from its indexed metadata.' );
			}
		}
		$baseline = $this->unpack( $storage->plain( $plan['baseline']['manifest']['artifacts'][0] ), $plan['baseline']['manifest']['artifacts'][0] );
		$paths    = array();
		foreach ( $plan['segments'] as $selected ) {
			$segment  = $selected['segment'];
			$artifact = null;
			foreach ( $selected['entry']['manifest']['artifacts'] as $candidate ) {
				if ( $candidate['name'] === $segment['artifact'] ) {
					$artifact = $candidate;
					break;
				}
			}
			if ( null === $artifact ) {
				throw new RuntimeException( 'PITR segment has no bound artifact.' );
			}
			$path       = $this->unpack( $storage->plain( $artifact ), $artifact );
			$inspection = $this->inspect_binlog( $path, $segment['start']['position'], $plan['database'], $plan['source']['engine'] );
			if ( $inspection['end'] !== $segment['end'] || $inspection['next'] !== $segment['next'] || $inspection['boundaries'] !== $segment['boundaries'] ) {
				throw new RuntimeException( 'PITR log contents do not match the recorded coordinates.' );
			}
			$paths[] = $path;
		}
		if ( BackupPlus_PITR_Manifest::snapshot_coordinate( $baseline ) !== $plan['snapshot_coordinate'] ) {
			throw new RuntimeException( 'Baseline SQL coordinate does not match its manifest.' );
		}
		return array( $baseline, $paths );
	}

	public function verify( $id, $target, $reader ) {
		$this->job();
		$plan = $this->recovery_plan( $id, $target );
		$this->authenticate( $plan, $reader );
		$plan['verified'] = true;
		return $plan;
	}

	public function verify_source_restore( $id, $target, $reader ) {
		require_once __DIR__ . '/class-pitr-isolation.php';
		$plan             = $this->verify( $id, $target, $reader );
		list( $baseline ) = $this->authenticate( $plan, $reader );
		$this->assert_no_replayed_events( $baseline, isset( $plan['baseline']['manifest']['database_events'] ) ? $plan['baseline']['manifest']['database_events'] : null );
		global $wpdb;
		if ( $plan['database'] !== DB_NAME || empty( $plan['baseline']['manifest']['wordpress_table_prefix'] ) || $plan['baseline']['manifest']['wordpress_table_prefix'] !== $wpdb->prefix ) { throw new RuntimeException( 'Source recovery requires the original database name and WordPress table prefix.' ); }
		$isolation = new BackupPlus_PITR_Isolation( $this->config, $this->work, $this->root );
		$this->direct_source_check( $isolation->adapter, $plan );
		return $plan;
	}

	/** Fetch exactly the dependencies the browser must authenticate and decrypt. */
	public function browser_manifest( $id, $target, $timestamp, $ticket = '' ) {
		$plan      = $this->recovery_plan( $id, $target );
		$entries   = array( $plan['baseline']['manifest']['backup_id'] => $plan['baseline'] );
		$artifacts = array( $plan['baseline']['manifest']['artifacts'][0] );
		foreach ( $plan['segments'] as $selected ) {
			$entries[ $selected['entry']['manifest']['backup_id'] ] = $selected['entry'];
			foreach ( $selected['entry']['manifest']['artifacts'] as $artifact ) {
				if ( $artifact['name'] === $selected['segment']['artifact'] ) { $artifacts[] = $artifact; }
			}
		}
		foreach ( $entries as $entry ) { $artifacts[] = $entry['locator']['manifest']; }
		$storage = new BackupPlus_PITR_Storage( $this->work, array(), $plan['destinations'] );
		$names   = array();
		foreach ( $artifacts as $artifact ) { $storage->fetch( $artifact );
			$names[ $artifact['name'] ] = $artifact['name']; }
		return BackupPlus_Age_Restore::manifest( array( 'db' => array_values( $names ) ), $timestamp, array( 'db' ), $ticket );
	}

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
		if ( $direct ) { require_once __DIR__ . '/class-pitr-isolation.php'; }
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

	private function chain_files( $chain ) {
		$payloads = array();
		$metadata = array();
		foreach ( array_merge( $chain['entries'], isset( $chain['abandoned_entries'] ) ? $chain['abandoned_entries'] : array() ) as $entry ) {
			foreach ( $entry['manifest']['artifacts'] as $artifact ) {
				$payloads[] = $artifact['name'];
			}
			$metadata[] = $entry['locator']['manifest']['name'];
			$metadata[] = 'backup_pitr_' . $entry['manifest']['chain_id'] . '_' . $entry['manifest']['backup_id'] . '_locator.json';
		}
		return array_merge( $payloads, $metadata );
	}

	public function delete( $id, $delete_remote = true, $deadline = null ) {
		BackupPlus_PITR_Manifest::id( $id );
		if ( ! isset( $this->state['chains'][ $id ] ) ) {
			throw new RuntimeException( 'PITR chain was not found.' );
		}
		if ( ( isset( $this->state['restore_chain'] ) && $id === $this->state['restore_chain'] ) || ( $this->state['pending'] && $id === $this->state['pending']['entry']['manifest']['chain_id'] ) ) {
			throw new RuntimeException( 'A backup or restore is still using this database recovery chain. Complete that operation before deleting it.' );
		}
		if ( ! empty( $this->config['enabled'] ) && ( $id === $this->state['active'] || in_array( $id, isset( $this->state['protected_previous'] ) ? $this->state['protected_previous'] : array(), true ) ) ) {
			throw new RuntimeException( 'This recovery chain is needed for scheduled database backups. Disable Database point-in-time recovery in Settings before deleting it. Re-enable it afterwards to start a new chain.' );
		}
		$chain = &$this->state['chains'][ $id ];
		if ( 'deleting' === $chain['state'] && $delete_remote !== ( isset( $chain['delete_remote'] ) ? $chain['delete_remote'] : true ) ) { throw new RuntimeException( 'This deletion is already in progress. Retry with the same "Also delete from remote storage" choice to finish removing the complete chain.' ); }
		$chain['delete_remote'] = $delete_remote;
		$chain['state']         = 'deleting';
		$this->save();
		$this->history( $id );
		$storage         = new BackupPlus_PITR_Storage( $this->work, array(), $delete_remote ? $chain['destinations'] : array() );
		$files           = array_values( array_unique( $this->chain_files( $chain ) ) );
		$is_local        = function ( $file ) use ( $storage ) {
			return is_file( $storage->path( $file ) );
		};
		$local_before    = count( array_filter( $files, $is_local ) );
		$receipts_before = isset( $chain['delete_receipts'] ) ? $chain['delete_receipts'] : array();
		$complete        = true;
		try {
			$storage->delete(
				$files,
				$receipts_before,
				function ( $receipts ) use ( $id ) {
					$this->state['chains'][ $id ]['delete_receipts'] = $receipts;
					$this->save();
				},
				$deadline
			);
		} catch ( BackupPlus_PITR_Delete_Pending $pending ) { $complete = false; }
		$progress = array(
			'complete'      => $complete,
			'backup_local'  => $local_before - count( array_filter( $files, $is_local ) ),
			'backup_remote' => array_sum( array_map( 'count', isset( $chain['delete_receipts'] ) ? $chain['delete_receipts'] : array() ) ) - array_sum( array_map( 'count', $receipts_before ) ),
		);
		if ( ! $complete ) { return $progress; }
		$history = BackupPlus_Backup_History::get_history();
		if ( isset( $chain['history_time'] ) ) {
			unset( $history[ $chain['history_time'] ] );
			BackupPlus_Backup_History::save_history( $history );
		}
		unset( $this->state['chains'][ $id ] );
		if ( $id === $this->state['active'] ) {
			$this->state['active'] = null;
		} $this->save();
		return $progress;
	}

	/** A restore's pin holds only while its history row stays locked, so unlocking in the backups list needs no worker lock. Without a row the pin alone applies. */
	private function retention_locked( $chain, $history ) {
		if ( isset( $chain['history_time'], $history[ $chain['history_time'] ] ) ) {
			return ! empty( $history[ $chain['history_time'] ]['always_keep'] );
		}
		return ! empty( $chain['pinned'] );
	}

	public function prune() {
		$history = BackupPlus_Backup_History::get_history();
		$keep    = max( 1, (int) BackupPlus_Options::get_backupplus_option( 'backupplus_retain_db', 2 ) );
		$chains  = $this->state['chains'];
		uasort(
			$chains,
			function ( $a, $b ) {
				return $b['entries'][0]['manifest']['created_at'] <=> $a['entries'][0]['manifest']['created_at'];
			}
		);
		$count = 0;
		foreach ( $chains as $id => $chain ) {
			if ( ! empty( $chain['imported'] ) ) {
				continue;
			}
			if ( empty( $chain['destinations'] ) && ! BackupPlus_Options::get_backupplus_option( 'backupplus_delete_local', 1 ) ) {
				continue;
			}
			++$count;
			if ( $count <= $keep || $id === $this->state['active'] || in_array( $id, isset( $this->state['protected_previous'] ) ? $this->state['protected_previous'] : array(), true ) || $this->retention_locked( $chain, $history ) || ( isset( $this->state['restore_chain'] ) && $id === $this->state['restore_chain'] ) ) {
				continue;
			}
			$this->delete( $id );
		}
	}

	public static function cleanup_retention() {
		try {
			$worker = new self();
			$worker->prune();
		} catch ( Throwable $error ) {
			global $backupplus;
			$backupplus->log( $error->getMessage(), 'warning' );
		}
	}

	public static function render() {
		$status = get_option( self::STATUS, array() );
		echo '<tr><th scope="row">' . esc_html__( 'Database point-in-time recovery', 'backupplus' ) . '</th><td>';
		$config = self::config();
		BackupPlus_PITR_Admin::controls( $config );
		if ( ! empty( $config['enabled'] ) && ! empty( $status['error'] ) ) {
			echo '<p role="alert">' . esc_html( $status['error'] ) . '</p>';
		}
		echo '</td></tr>';
	}

	public static function clean_temporary() {
		global $backupplus;
		if ( ! is_dir( $backupplus->backups_dir_location() . '/pitr' ) ) {
			return;
		}
		try {
			$worker = new self();
		} catch ( Throwable $error ) {
			return;
		}
	}

	private function reap_work() {
		$keep = array();
		foreach ( ! empty( $this->state['pending']['files'] ) ? $this->state['pending']['files'] : array() as $file ) {
			$keep[ 'verify-' . hash( 'sha256', $file['name'] ) ] = $file;
		}
		foreach ( glob( $this->root . '/work-*' ) as $path ) {
			if ( $path === $this->work || is_link( $path ) || ! is_dir( $path ) || filemtime( $path ) > time() - 43200 ) {
				continue;
			}
			$retained = false;
			foreach ( new DirectoryIterator( $path ) as $entry ) {
				if ( $entry->isDot() ) {
					continue;
				}
				$child = $entry->getPathname();
				$name  = $entry->getFilename();
				if ( isset( $keep[ $name ] ) && $entry->isFile() && ! $entry->isLink() && hash_equals( $keep[ $name ]['sha256'], hash_file( 'sha256', $child ) ) ) {
					$retained = true;
					continue;
				}
				if ( $entry->isDir() && ! $entry->isLink() ) {
					self::remove_work( $child );
				} else {
					unlink( $child );
				}
			}
			if ( ! $retained ) {
				rmdir( $path );
			}
		}
	}

	public function __destruct() {
		if ( $this->work && is_dir( $this->work ) ) {
			self::remove_work( $this->work );
		}
		if ( is_resource( $this->lock ) ) {
			flock( $this->lock, LOCK_UN );
			fclose( $this->lock );
		}
	}

	private static function remove_work( $path ) {
		foreach ( new DirectoryIterator( $path ) as $entry ) {
			if ( $entry->isDot() ) {
				continue;
			}
			$child = $entry->getPathname();
			if ( $entry->isDir() && ! $entry->isLink() ) {
				self::remove_work( $child );
			} else {
				unlink( $child );
			}
		}
		rmdir( $path );
	}
}
