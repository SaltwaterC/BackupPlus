<?php

/** Publication, cursor fencing, durable retries and dependency-aware deletion. */

define( 'ABSPATH', __DIR__ . '/' );
function __( $text, $domain = null ) {
	return $text; }
function is_wp_error( $value ) {
	return false;
}
class BackupPlus_Options {

	public static $values = array();
	public static function get_backupplus_option( $key, $default = null ) {
		return array_key_exists( $key, self::$values ) ? self::$values[ $key ] : $default;
	}
}
class BackupPlus_Backup_History {

	public static $history = array();
	public static function get_history( $timestamp = null ) {
		return null === $timestamp ? self::$history : ( isset( self::$history[ $timestamp ] ) ? self::$history[ $timestamp ] : array() );
	}
	public static function save_history( $history ) {
		self::$history = $history;
	}
}
class BackupPlus_Storage_Methods_Interface {

	public static $provider;
	public static function get_storage_objects_and_ids( $services ) {
		return array( 'fixture' => array( 'object' => self::$provider ) );
	}
}
class PITR_State_Provider {

	public $remote;
	public $local;
	public $fail             = null;
	public $deletion_failure = false;
	public $deletion_delay   = 0;
	public $uploads          = array();
	public $options          = array( 'directory' => 'original' );
	public function get_options() {
		return $this->options;
	}
	public function set_options( $options, $save = false, $id = null ) {
		$this->options = $options;
	}
	public function supports_feature() {
		return false;
	}
	public function backup( $files ) {
		foreach ( $files as $file ) {
			if ( $file === $this->fail ) {
				return false;
			} $this->uploads[] = $file;
			copy( $this->local . '/' . $file, $this->remote . '/' . $file );
		}
		return true;
	}
	public function download( $file ) {
		return copy( $this->remote . '/' . $file, $this->local . '/' . $file );
	}
	public function delete( $file ) {
		if ( $this->deletion_delay ) { usleep( $this->deletion_delay ); }
		return ! $this->deletion_failure && file_exists( $this->remote . '/' . $file ) ? unlink( $this->remote . '/' . $file ) : false;
	}
	public function listfiles( $prefix ) {
		return array_map(
			function ( $file ) {
				return array( 'name' => basename( $file ) );
			},
			glob( $this->remote . '/' . $prefix . '*' )
		);
	}
}
require dirname( __DIR__ ) . '/includes/class-pitr.php';
function state_expect( $value, $message ) {
	if ( ! $value ) {
		throw new RuntimeException( $message );
	}
}
function state_reject( $callback, $message ) {
	try {
		$callback();
	} catch ( RuntimeException $error ) {
		return;
	} throw new RuntimeException( $message );
}
function state_property( $worker, $name, $value = null ) {
	$property = new ReflectionProperty( $worker, $name );
	if ( PHP_VERSION_ID < 80100 ) {
		$property->setAccessible( true );
	}
	if ( 3 === func_num_args() ) {
		$property->setValue( $worker, $value );
	}
	return $property->getValue( $worker );
}
function state_call( $worker, $method, $args = array() ) {
	$reflection = new ReflectionMethod( $worker, $method );
	if ( PHP_VERSION_ID < 80100 ) {
		$reflection->setAccessible( true );
	}
	return $reflection->invokeArgs( $worker, $args );
}
$directory = sys_get_temp_dir() . '/backupplus-pitr-state-' . bin2hex( random_bytes( 8 ) );
mkdir( $directory, 0700 );
mkdir( $directory . '/remote', 0700 );
mkdir( $directory . '/transfer', 0700 );
$backupplus                                     = new class($directory) {
	private $directory;
	public function __construct( $directory ) {
		$this->directory = $directory;
	}
	public function backups_dir_location() {
		return $this->directory;
	}
};
$backupplus_backup                              = new stdClass();
$provider                                       = new PITR_State_Provider();
$provider->local                                = $directory;
$provider->remote                               = $directory . '/remote';
BackupPlus_Storage_Methods_Interface::$provider = $provider;
$destination                                    = array(
	array(
		'service'  => 'fixture',
		'instance' => '',
	),
);
$remove = function ( $path ) use ( &$remove ) {
	if ( is_dir( $path ) && ! is_link( $path ) ) {
		foreach ( new DirectoryIterator( $path ) as $entry ) {
			if ( ! $entry->isDot() ) {
				$remove( $entry->getPathname() );
			}
		} rmdir( $path );
	} else {
		unlink( $path );
	}
};
try {
	$chain_id      = str_repeat( 'a', 32 );
	$full_id       = str_repeat( 'b', 32 );
	$increment_id  = str_repeat( 'c', 32 );
	$start         = array(
		'file'     => 'binlog.000001',
		'position' => 100,
	);
	$end           = array(
		'file'     => 'binlog.000002',
		'position' => 4,
	);
	$source        = array(
		'engine'         => 'mysql',
		'server_version' => '8.4.0',
		'identity'       => str_repeat( 'd', 64 ),
		'log_epoch'      => str_repeat( 'e', 32 ),
	);
	$anchor        = array(
		'coordinate' => $start,
		'sha256'     => str_repeat( 'f', 64 ),
	);
	$make_file     = function ( $id, $suffix, $contents ) use ( $chain_id, $directory ) {
		$name = 'backup_pitr_' . $chain_id . '_' . $id . '_' . $suffix;
		file_put_contents( $directory . '/' . $name, $contents );
		return array(
			'name'         => $name,
			'size'         => strlen( $contents ),
			'sha256'       => hash( 'sha256', $contents ),
			'plain_sha256' => hash( 'sha256', $contents ),
		);
	};
	$full_artifact = $make_file( $full_id, 'full.sql.gz', 'full' );
	$full          = array(
		'manifest_version'    => 1,
		'kind'                => 'database-full',
		'backup_id'           => $full_id,
		'chain_id'            => $chain_id,
		'source'              => $source,
		'database'            => 'wordpress',
		'created_at'          => 100,
		'snapshot_coordinate' => $start,
		'anchor'              => $anchor,
		'artifacts'           => array( $full_artifact ),
	);
	$full_manifest = $make_file( $full_id, 'manifest.json', json_encode( $full ) );
	$full_locator  = array(
		'manifest_version' => 1,
		'chain_id'         => $chain_id,
		'backup_id'        => $full_id,
		'manifest'         => $full_manifest,
	);
	$make_file( $full_id, 'locator.json', json_encode( $full_locator ) );
	$log          = $make_file( $increment_id, 'log0.bin.gz', 'log' );
	$increment    = array(
		'manifest_version'         => 1,
		'kind'                     => 'database-binlog',
		'backup_id'                => $increment_id,
		'chain_id'                 => $chain_id,
		'source'                   => $source,
		'database'                 => 'wordpress',
		'created_at'               => 200,
		'baseline_id'              => $full_id,
		'previous_backup_id'       => $full_id,
		'previous_manifest_sha256' => $full_manifest['sha256'],
		'start_coordinate'         => $start,
		'end_coordinate'           => $end,
		'anchor'                   => $anchor,
		'artifacts'                => array( $log ),
		'segments'                 => array(
			array(
				'start'      => $start,
				'end'        => 200,
				'next'       => $end,
				'artifact'   => $log['name'],
				'boundaries' => array(
					array(
						'position'  => 150,
						'timestamp' => 150,
					),
				),
			),
		),
	);
	$manifest     = $make_file( $increment_id, 'manifest.json', json_encode( $increment ) );
	$locator      = array(
		'manifest_version' => 1,
		'chain_id'         => $chain_id,
		'backup_id'        => $increment_id,
		'manifest'         => $manifest,
	);
	$locator_file = $make_file( $increment_id, 'locator.json', json_encode( $locator ) );
	$pending      = array(
		'entry'        => array(
			'manifest' => $increment,
			'sha256'   => $manifest['sha256'],
			'locator'  => $locator,
		),
		'files'        => array( $log, $manifest, $locator_file ),
		'receipts'     => array(),
		'fingerprint'  => 'test',
		'destinations' => $destination,
		'encryption'   => array(),
	);
	$worker       = new BackupPlus_PITR( array( 'enabled' => true ) );
	$initial      = array(
		'version' => 1,
		'chains'  => array(
			$chain_id => array(
				'entries'      => array(
					array(
						'manifest' => $full,
						'sha256'   => $full_manifest['sha256'],
						'locator'  => $full_locator,
					),
				),
				'destinations' => $destination,
				'cursor'       => $start,
				'state'        => 'complete',
				'imported'     => false,
			),
		),
		'active'  => $chain_id,
		'pending' => $pending,
	);
	state_property( $worker, 'state', $initial );
	state_call( $worker, 'save' );
	$storage           = new BackupPlus_PITR_Storage( $directory . '/transfer', array(), $destination );
	$provider->options = array( 'directory' => 'changed-during-dump' );
	state_reject(
		function () use ( $directory, $destination ) {
			$bound                       = $destination;
			$bound[0]['settings_sha256'] = hash( 'sha256', serialize( array( 'directory' => 'original' ) ) );
			new BackupPlus_PITR_Storage( $directory . '/transfer', array(), $bound );
		},
		'Changed storage settings were accepted without proving chain availability.'
	);
	state_call( $storage, 'provider', array( $destination[0] ) );
	state_expect( 'original' === $provider->options['directory'], 'A destination setting change moved a running chain.' );
	$provider->fail = $manifest['name'];
	state_reject(
		function () use ( $worker, $storage ) {
			state_call( $worker, 'publish', array( $storage ) );
		},
		'Failed publication advanced the chain.'
	);
	state_expect( $start === state_property( $worker, 'state' )['chains'][ $chain_id ]['cursor'], 'Cursor advanced before the manifest was durable.' );
	state_expect( ! file_exists( $provider->remote . '/' . $locator_file['name'] ), 'Completion locator appeared before all dependencies.' );
	unset( $worker );
	$worker = new BackupPlus_PITR( array( 'enabled' => true ) );
	state_expect( ! empty( state_property( $worker, 'state' )['pending']['receipts'] ), 'Upload progress did not survive worker restart.' );
	$provider->fail = null;
	state_call( $worker, 'publish', array( $storage ) );
	state_expect( $end === state_property( $worker, 'state' )['chains'][ $chain_id ]['cursor'], 'Verified publication did not advance the cursor.' );
	$metadata = BackupPlus_PITR::chain_metadata( $chain_id, state_property( $worker, 'state' )['chains'][ $chain_id ] );
	state_expect( array( $full_id, $increment_id ) === array_column( $metadata['points'], 'backup_id' ) && $end === $metadata['points'][1]['coordinate'], 'History did not expose stable full/increment recovery endpoints.' );
	$legacy    = array( 100 => array( 'pitr' => array( 'chain_id' => $chain_id ) ) );
	$decorated = BackupPlus_PITR::decorate_history( $legacy );
	state_expect( 2 === count( $decorated[100]['pitr']['points'] ), 'Existing history did not acquire recovery endpoints from its chain index.' );
	state_expect( array( $log['name'], $manifest['name'], $locator_file['name'] ) === $provider->uploads, 'Retry uploaded completed objects again or reordered publication.' );
	$retained_state         = state_property( $worker, 'state' );
	$retained_history       = BackupPlus_Backup_History::$history;
	$pinned_chain           = array( 'history_time' => 100 ) + $retained_state['chains'][ $chain_id ];
	$unpinned               = $pinned_chain;
	$pinned_chain['pinned'] = true;
	state_expect( true === state_call( $worker, 'retention_locked', array( $pinned_chain, array() ) ) && false === state_call( $worker, 'retention_locked', array( $unpinned, array() ) ), 'The pin alone must protect a chain without a history row.' );
	state_expect( false === state_call( $worker, 'retention_locked', array( $pinned_chain, array( 100 => array( 'db' => 'x' ) ) ) ), 'Unlocking the history row must release a restore pin.' );
	state_expect( true === state_call( $worker, 'retention_locked', array( $unpinned, array( 100 => array( 'always_keep' => true ) ) ) ), 'A locked history row must protect an unpinned chain.' );
	state_reject(
		function () use ( $worker, $chain_id ) {
			$worker->delete( $chain_id );
		},
		'Active baseline was deleted.'
	);
	unset( $worker );
	$worker = new BackupPlus_PITR( array( 'enabled' => false ) );
	// Simulate loss of an acknowledgement for an earlier deletion; absence is idempotent success.
	$provider->deletion_failure = true;
	state_reject(
		function () use ( $worker, $chain_id ) {
			$worker->delete( $chain_id );
		},
		'Remote deletion failure was ignored.'
	);
	state_expect( 'deleting' === state_property( $worker, 'state' )['chains'][ $chain_id ]['state'], 'Deletion inventory was lost on failure.' );
	state_reject(
		function () use ( $worker, $chain_id ) {
			$worker->recovery_plan( $chain_id );
		},
		'Partly deleted chain was selectable for recovery.'
	);
	unset( $worker );
	$worker                     = new BackupPlus_PITR( array( 'enabled' => false ) );
	$provider->deletion_failure = false;
	$worker->delete( $chain_id );
	state_expect( array() === state_property( $worker, 'state' )['chains'], 'Deletion retry did not remove the complete chain.' );
	state_expect( array() === glob( $provider->remote . '/backup_pitr_*' ), 'Remote orphan artifacts remain after chain deletion.' );
	unset( $worker );

	require dirname( __DIR__ ) . '/includes/class-backup-deletion.php';
	$restore_deletion_fixture = function () use ( $retained_state, $retained_history, $chain_id, $directory, $provider ) {
		$fixture = new BackupPlus_PITR( array( 'enabled' => false ) );
		state_property( $fixture, 'state', $retained_state );
		state_call( $fixture, 'save' );
		BackupPlus_Backup_History::$history = $retained_history;
		$files                              = array_unique( state_call( $fixture, 'chain_files', array( $retained_state['chains'][ $chain_id ] ) ) );
		foreach ( $files as $file ) { file_put_contents( $directory . '/' . $file, 'deletion fixture' );
			file_put_contents( $provider->remote . '/' . $file, 'deletion fixture' ); }
		return $fixture;
	};
	$worker                   = $restore_deletion_fixture();
	$timestamp                = $retained_state['chains'][ $chain_id ]['history_time'];
	$progress                 = $worker->delete( $chain_id, true, microtime( true ) - 1 );
	state_expect( ! $progress['complete'] && 0 === $progress['backup_remote'] && isset( BackupPlus_Backup_History::$history[ $timestamp ] ), 'A bounded deletion lost its continuation inventory.' );
	state_reject(
		function () use ( $worker, $chain_id ) {
			$worker->delete( $chain_id, false );
		},
		'A remote deletion silently changed to local-only midway through the chain.'
	);
	unset( $worker );
	$result = BackupPlus_Backup_Deletion::delete(
		array(
			'backup_timestamp' => $timestamp,
			'delete_remote'    => true,
		)
	);
	state_expect( 'success' === $result['result'] && $result['backup_remote'] > 0 && $result['backup_local'] > 0 && empty( BackupPlus_Backup_History::$history ), 'The shared Delete action did not resume and remove the full PITR chain.' );
	$worker = $restore_deletion_fixture();
	unset( $worker );
	$remote_before = glob( $provider->remote . '/backup_pitr_*' );
	$result        = BackupPlus_Backup_Deletion::delete(
		array(
			'backup_timestamp' => $timestamp,
			'delete_remote'    => false,
		)
	);
	state_expect( 'success' === $result['result'] && 0 === $result['backup_remote'] && $remote_before === glob( $provider->remote . '/backup_pitr_*' ) && empty( glob( $directory . '/backup_pitr_*' ) ), 'Local-only UI deletion removed remote artifacts or left local chain fragments.' );

	$worker = $restore_deletion_fixture();
	unset( $worker );
	$provider->deletion_delay = 1100000;
	$options                  = array(
		'backup_timestamp'    => (string) $timestamp,
		'delete_remote'       => true,
		'remote_delete_limit' => 1,
		'is_continuation'     => false,
	);
	$result                   = BackupPlus_Backup_Deletion::delete_selection( $options );
	state_expect( 'continue' === $result['result'] && 1 === $result['backup_remote'] && 0 === $result['backup_sets'] && (string) $timestamp === $result['timestamps'], 'The admin batch did not yield with its acknowledged remote deletion preserved.' );
	$provider->deletion_delay = 0;
	$result                   = BackupPlus_Backup_Deletion::delete_selection( $options );
	state_expect( 'success' === $result['result'] && 1 === $result['backup_sets'] && (string) $timestamp === $result['deleted_timestamps'], 'The admin batch failed to finish its complete recovery chain on continuation.' );

	$backupplus_admin = new class {
		public $calls = array();
		public function delete_set( $opts ) {
			$this->calls[] = $opts['backup_timestamp'];
			unset( BackupPlus_Backup_History::$history[ $opts['backup_timestamp'] ] );
			return array(
				'result'        => 'success',
				'backup_sets'   => 1,
				'backup_local'  => 1,
				'backup_remote' => 0,
			);
		}
	};
	$worker           = $restore_deletion_fixture();
	unset( $worker );
	$ordinary_time                                        = $timestamp + 1;
	BackupPlus_Backup_History::$history[ $ordinary_time ] = array( 'db' => 'ordinary.gz' );
	$options['backup_timestamp']                          = $ordinary_time . ',' . $timestamp;
	$options['delete_remote']                             = false;
	$result = BackupPlus_Backup_Deletion::delete_selection( $options );
	state_expect( 'success' === $result['result'] && 2 === $result['backup_sets'] && 0 === $result['count_backups'] && array( (string) $ordinary_time ) === $backupplus_admin->calls, 'Mixed selection did not delegate ordinary backups and delete the PITR chain together.' );

	$worker = $restore_deletion_fixture();
	unset( $worker );
	BackupPlus_Options::$values[ BackupPlus_PITR::OPTION ] = array( 'enabled' => true );
	BackupPlus_Backup_History::$history[ $ordinary_time ]  = array( 'db' => 'ordinary.gz' );
	$result = BackupPlus_Backup_Deletion::delete_selection( $options );
	state_expect( 'error' === $result['result'] && false !== strpos( $result['message'], 'Settings' ) && (string) $ordinary_time === $result['deleted_timestamps'] && isset( BackupPlus_Backup_History::$history[ $timestamp ] ), 'A mixed failure lost completed rows or deleted the active recovery chain.' );
	echo "PITR durable publication, cursor, restart, protection and deletion-retry checks passed.\n";
} finally {
	unset( $worker );
	$remove( $directory );
}
