<?php
/** CLI selection, worker evidence and targeted event tests without WordPress. */
define( 'ABSPATH', __DIR__ . '/' );


define( 'BACKUPPLUS_DIR', dirname( __DIR__ ) );


define( 'WP_CONTENT_DIR', __DIR__ );


define( 'WP_PLUGIN_DIR', __DIR__ );


define( 'WPMU_PLUGIN_DIR', __DIR__ );


define( 'DAY_IN_SECONDS', 86400 );


define( 'HOUR_IN_SECONDS', 3600 );


$options = array();


$cron = array();


function add_action() {
}


function add_filter() {
}


function apply_filters( $name, $value ) {

	return $value;
}


function __( $text ) {

	return $text;
}


function get_option( $name, $default = false ) {

	global $options;

	return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
}


function get_site_option( $name, $default = false ) {

	return get_option( $name, $default );
}


function update_site_option( $name, $value ) {

	global $options;

	$options[ $name ] = $value;
}


function wp_cache_delete() {
}


function is_multisite() {

	return false;
}


function _get_cron_array() {

	global $cron;

	return $cron;
}


function is_wp_error( $value ) {

	return $value instanceof WP_Error;
}


function wp_get_schedules() {

	return array(
		'daily'  => array( 'interval' => 86400 ),
		'weekly' => array( 'interval' => 604800 ),
	);
}


class WP_Error {


	private $message;


	public function __construct( $code, $message ) {

		$this->message = $message;
	}


	public function get_error_message() {

		return $this->message;
	}


}


class BackupPlus_Options {


	public static function get_backupplus_option( $name, $default = null ) {

		return get_option( $name, $default );
	}


	public static function update_backupplus_option( $name, $value ) {

		update_site_option( $name, $value );
	}


}


class BackupPlus_Credential_Key_Store {
	const BACKUP_ENTITY = 'credential-keys';


	public static function exclude() {

		return false;
	}


}


class CLI_Test_DB {


	public $last_error = '';


	public function get_col( $query ) {

		return array( 'wp_posts', 'wp_options', 'other' );
	}


}


class CLI_Test_Backup {


	public $job = array();


	public $directory;


	public function jobdata_get( $key, $default = null ) {

		return isset( $this->job[ $key ] ) ? $this->job[ $key ] : $default;
	}


	public function backups_dir_location() {

		return $this->directory . '/archives';
	}


	public function get_table_prefix() {

		return 'wp_';
	}


	public function get_backupable_file_entities() {

		$entities = array(
			'plugins' => $this->directory,
			'wpcore'  => $this->directory,
		);

		foreach ( BackupPlus_Backup_Sources::sources() as $id => $source ) {

			$entities[ $id ] = $source['path'];

		}

		return BackupPlus_Backup_Sources::frozen_entities( $entities, false );
	}


}


class WP_CLI {


	public static $runs = 0;


	public static function get_config() {

		return false;
	}


	public static function launch_self( $command, $args, $flags ) {

		global $cron;

		++self::$runs;

		$cron = array();

		BackupPlus_CLI::finished(
			$args[0],
			'success',
			array(
				'backup_time'           => 10,
				'incremental_base_time' => 7,
			),
			array(
				'errors'   => 0,
				'warnings' => 0,
				'messages' => array(),
			)
		);

		return (object) array( 'return_code' => 0 );
	}


}


require BACKUPPLUS_DIR . '/includes/class-backup-sources.php';


require BACKUPPLUS_DIR . '/includes/class-backup-schedule.php';


require BACKUPPLUS_DIR . '/includes/class-cli.php';


function cli_expect( $condition, $message ) {

	if ( ! $condition ) {

		throw new RuntimeException( $message );

	}
}


function cli_reject( $callback, $message ) {

	try {

		$callback();

	}

	catch ( InvalidArgumentException $error ) {

		return;

	}

	catch ( RuntimeException $error ) {

		return;

	}

	throw new RuntimeException( $message );
}


$directory = sys_get_temp_dir() . '/backupplus-cli-unit-' . bin2hex( random_bytes( 6 ) );


mkdir( $directory );


mkdir( $directory . '/archives' );


file_put_contents( $directory . '/source.txt', 'standalone' );


$backupplus = new CLI_Test_Backup();


$backupplus->directory = $directory;


$wpdb = new CLI_Test_DB();


$selection_file = $directory . '/selection.json';


try {


	$options['backupplus_include_more_path'] = array( $directory . '/source.txt' );


	$options['backupplus_include_more'] = true;


	$options['backupplus_database_selection'] = array(
		'wp' => array(
			'enabled' => true,
			'mode'    => 'selected',
			'tables'  => array( 'wp_options' ),
		),
	);


	$before = $options;


	$plan = BackupPlus_CLI::selection( array() );


	cli_expect( array( 'wp_options' ) === $plan['options']['database_plan'][1]['wp']['tables'], 'Default table coverage uses saved selections' );


	$plan = BackupPlus_CLI::selection(
		array(
			'files'  => 'wpcore,custom',
			'tables' => 'wp_posts',
		)
	);


	cli_expect( 2 === count( $plan['entities'] ) && in_array( 'wpcore', $plan['entities'], true ), 'Core and standalone custom sources expand' );


	cli_expect( array( 'wp_posts' ) === $plan['options']['database_plan'][1]['wp']['tables'], 'Exact table restriction overrides only WordPress' );


	cli_expect( $before === $options, 'Per-run selection never writes settings' );


	foreach ( array(
		array( 'type' => 'invalid' ),
		array(
			'type'        => 'database',
			'incremental' => true,
		),
		array(
			'type'   => 'files',
			'tables' => 'wp_posts',
		),
		array(
			'type'  => 'database',
			'files' => 'plugins',
		),
		array( 'files' => 'unknown' ),
		array( 'files' => 'plugins,' ),
		array( 'tables' => '' ),
		array( 'tables' => 'missing' ),
		array(
			'selection' => $selection_file,
			'files'     => 'plugins',
		),
	) as $invalid ) {

		cli_reject(
			function () use ( $invalid ) {

				BackupPlus_CLI::selection( $invalid );
			},
			'Invalid selectors must fail: ' . json_encode( $invalid )
		);

	}


	foreach ( array(
		array( 'unknown' => true ),
		array( 'files' => 'plugins' ),
		array( 'custom_paths' => array( '' ) ),
		array( 'custom_paths' => array( '/missing-source' ) ),
		array( 'custom_paths' => array( '/a/../b' ) ),
		array(
			'database_selection' => array(
				'wp' => array(
					'mode'   => 'selected',
					'tables' => array(),
				),
			),
		),
		array( 'database_selection' => array( '4' => array( 'enabled' => true ) ) ),
	) as $invalid ) {


		file_put_contents( $selection_file, json_encode( $invalid ) );


		cli_reject(
			function () use ( $selection_file ) {

				BackupPlus_CLI::selection( array( 'selection' => $selection_file ) );
			},
			'Invalid selection JSON must fail'
		);


	}


	file_put_contents(
		$selection_file,
		json_encode(
			array(
				'files'        => array( 'wpcore' ),
				'custom_paths' => array( $directory . '/source.txt' ),
			)
		)
	);


	$plan = BackupPlus_CLI::selection(
		array(
			'selection'   => $selection_file,
			'type'        => 'files',
			'incremental' => true,
		)
	);


	cli_expect( 2 === count( $plan['entities'] ) && $plan['options']['incremental'], 'Ad hoc paths join the selected files' );


	$backupplus->job = array(
		'file_sources'      => $plan['options']['file_sources'],
		'file_entity_paths' => $plan['options']['file_entity_paths'],
	);


	$options['backupplus_include_more_path'] = array( '/changed-after-start' );


	cli_expect( $plan['options']['file_sources'] === BackupPlus_Backup_Sources::sources(), 'Retry source paths stay frozen' );


	cli_expect( $plan['options']['file_entity_paths'] === $backupplus->get_backupable_file_entities(), 'Retry registry stays frozen' );


	$backupplus->job = array();



	$nonce = 'abcdefabcdef';


	$event = function ( $hook, $args ) {

		return array( $hook => array( md5( serialize( $args ) ) => array( 'args' => $args ) ) );
	};


	$cron = array( time() - 1 => array_merge( $event( 'backupplus_backup_resume', array( 1, $nonce ) ), $event( 'unrelated', array() ) ) );


	$options[ BackupPlus_CLI::REQUEST . $nonce ] = array( 'test' => true );


	cli_expect( 1 === count( BackupPlus_CLI::events( $nonce ) ), 'Only requested job events are driven' );


	$result = BackupPlus_CLI::wait( $nonce );


	cli_expect( 7 === $result['timestamp'] && 1 === WP_CLI::$runs, 'A retry completes using the canonical base timestamp' );


	BackupPlus_CLI::finished(
		$nonce,
		'failed',
		array( 'backup_time' => 10 ),
		array(
			'errors'   => 0,
			'warnings' => 1,
			'messages' => array(),
		)
	);


	cli_expect( 10 === BackupPlus_CLI::wait( $nonce )['timestamp'], 'Ordinary warning-only completion succeeds' );


	BackupPlus_CLI::finished(
		$nonce,
		'failed',
		array( 'backup_time' => 10 ),
		array(
			'errors'   => 1,
			'warnings' => 0,
		)
	);


	cli_reject(
		function () use ( $nonce ) {

			BackupPlus_CLI::wait( $nonce );
		},
		'Terminal errors must fail'
	);


	BackupPlus_CLI::finished(
		$nonce,
		'aborted',
		array( 'backup_time' => 10 ),
		array(
			'errors'   => 0,
			'warnings' => 0,
		)
	);


	cli_reject(
		function () use ( $nonce ) {

			BackupPlus_CLI::wait( $nonce );
		},
		'Aborts must fail'
	);


	unset( $options[ BackupPlus_CLI::RESULT . $nonce ] );


	cli_reject(
		function () use ( $nonce ) {

			BackupPlus_CLI::wait( $nonce );
		},
		'A successful worker exit without evidence must not succeed'
	);


	$cron = array( time() => array_merge( $event( 'backupplus_pre_update_finalize', array( $nonce, 'success', array( 'pre_update_batch' => 'batch-a' ) ) ), $event( 'backupplus_pre_update_run', array( 'batch-b' ) ) ) );


	cli_expect( 1 === count( BackupPlus_CLI::events( $nonce, 'batch-a' ) ), 'Batch finalization is specific to the requested batch' );


	$before = $options;


	cli_reject(
		function () {

			BackupPlus_CLI::save_settings(
				array(
					'backupplus_interval'             => 'weekly',
					'backupplus_schedule_exact_files' => 1,
					'backupplus_starttime_files'      => '25:00',
					'backupplus_retain'               => 9,
				)
			);
		},
		'Invalid proposed settings must fail'
	);


	cli_expect( $before === $options, 'Invalid submissions cannot partially write options' );


	echo "PASS: CLI selectors, source snapshots, terminal evidence, warning semantics, canonical timestamps and event isolation\n";


}

finally {


	@unlink( $selection_file );


	unlink( $directory . '/source.txt' );


	rmdir( $directory . '/archives' );


	rmdir( $directory );


}


// Strict deletion must preserve a complete retry inventory after local or remote failure.
class BackupPlus_Backup_History {

	public static $history = array();

	public static function get_history( $timestamp = false ) {
		return $timestamp ? ( isset( self::$history[ $timestamp ] ) ? self::$history[ $timestamp ] : array() ) : self::$history;
	}

	public static function save_history( $history ) {
		self::$history = $history;
	}

}

class BackupPlus_Pre_Update_Backups {

	public static $batch = array();

	public static function instance() {
		return new self();
	}

	public function batch() {
		return self::$batch;
	}

}

class CLI_Delete_Provider {

	public $fail = true;

	public $calls = 0;

	public function supports_feature() {
		return false;
	}

	public function delete( $file ) {
		++$this->calls;
		return ! $this->fail;
	}

}

class BackupPlus_Storage_Methods_Interface {

	public static $provider;

	public static function get_storage_objects_and_ids( $services ) {
		return array( 'fixture' => array( 'object' => self::$provider ) );
	}

}

require BACKUPPLUS_DIR . '/includes/class-backup-deletion.php';

$directory = sys_get_temp_dir() . '/backupplus-cli-delete-' . bin2hex( random_bytes( 6 ) );

mkdir( $directory );

mkdir( $directory . '/archives' );

$backupplus->directory = $directory;

$cron = array();

$provider = new CLI_Delete_Provider();

BackupPlus_Storage_Methods_Interface::$provider = $provider;

$source = BackupPlus_Backup_Sources::source_id( '/removed-source.txt' );

$backup = array(
	'nonce'       => 'aaaabbbbcccc',
	'service'     => array( 'fixture' ),
	'wpcore'      => array( 'core.zip', 'core2.zip' ),
	$source       => 'custom.zip',
	'db2'         => 'external.gz',
	'incremental' => array( 'snapshots' => array( 'wpcore' => 'incremental_aaaabbbbcccc_wpcore.php' ) ),
);

BackupPlus_Backup_History::$history = array( 123 => $backup );

foreach ( array( 'core.zip', 'core2.zip', 'custom.zip', 'external.gz', 'incremental_aaaabbbbcccc_wpcore.php' ) as $name ) {
	file_put_contents( $directory . '/archives/' . $name, 'fixture' );
}

try {

	$result = BackupPlus_Backup_Deletion::delete(
		array(
			'backup_timestamp' => 123,
			'delete_remote'    => true,
		)
	);

	cli_expect( 'error' === $result['result'] && 4 === count( $result['errors'] ) && 4 === $provider->calls, 'Failed remote deletion reports each archive' );

	cli_expect( $backup['wpcore'] === BackupPlus_Backup_History::$history[123]['wpcore'] && file_exists( $directory . '/archives/incremental_aaaabbbbcccc_wpcore.php' ), 'Partial deletion preserves complete chain and inventory metadata' );

	$provider->fail = false;

	$result = BackupPlus_Backup_Deletion::delete(
		array(
			'backup_timestamp' => 123,
			'delete_remote'    => true,
		)
	);

	cli_expect( 'success' === $result['result'] && ! BackupPlus_Backup_History::$history && ! file_exists( $directory . '/archives/incremental_aaaabbbbcccc_wpcore.php' ), 'Retry deletes removed custom, core, external DB archives and inventories' );

	BackupPlus_Backup_History::$history = array(
		123 => array(
			'nonce'   => 'aaaabbbbcccc',
			'plugins' => 'cannot-delete.zip',
		),
	);

	mkdir( $directory . '/archives/cannot-delete.zip' );

	$result = BackupPlus_Backup_Deletion::delete( array( 'backup_timestamp' => 123 ) );

	cli_expect( 'error' === $result['result'] && isset( BackupPlus_Backup_History::$history[123] ), 'Failed local deletion retains history' );

	BackupPlus_Pre_Update_Backups::$batch = array(
		'backup_time' => 123,
		'state'       => 'ready',
	);

	cli_expect( BackupPlus_Backup_Deletion::protected_set( 123, BackupPlus_Backup_History::$history[123] ), 'Pending update restore points cannot be deleted' );

	BackupPlus_Pre_Update_Backups::$batch = array();

	$options['backupplus_oneshotnonce'] = 'ddddeeeeffff';

	$options['backupplus_jobdata_ddddeeeeffff'] = array(
		'job_type'              => 'incremental',
		'jobstatus'             => 'begun',
		'incremental_base_time' => 123,
	);

	cli_expect( BackupPlus_Backup_Deletion::protected_set( 123, BackupPlus_Backup_History::$history[123] ), 'An active increment protects its whole chain' );

	echo "PASS: strict deletion inventories, historical sources, failure recovery and protected chains\n";

}
finally {

	foreach ( array_diff( scandir( $directory . '/archives' ), array( '.', '..' ) ) as $name ) {
		$path = $directory . '/archives/' . $name;
		if ( is_dir( $path ) ) {
			rmdir( $path );
		}
		else {
			unlink( $path );
		}
	}

	rmdir( $directory . '/archives' );

	rmdir( $directory );

}
