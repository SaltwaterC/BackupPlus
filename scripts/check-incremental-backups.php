<?php
/** Real archive and incremental orchestration checks, using disposable WordPress data. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || defined( 'BACKUPPLUS_DIR' ) ) {
	die( 'Use WP-CLI with --skip-plugins=backupplus.' );
}
function incremental_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
$token     = bin2hex( random_bytes( 6 ) );
$directory = get_temp_dir() . 'backupplus-incremental-' . $token;
mkdir( $directory );
mkdir( $directory . '/archives' );
mkdir( $directory . '/plugins' );
mkdir( $directory . '/plugins/nested' );
$test_history         = array();
$test_cron            = array();
$nonces               = array();
$encryption           = array( 'mode' => 'none' );
$original_bookkeeping = array();
foreach ( array( 'backupplus_last_backup', 'backupplus_lastmessage', 'backupplus_lastlogmessage', 'backupplus_oneshotnonce', 'backupplus_last_scheduled_f', 'backupplus_restore_in_progress' ) as $name ) {
	$original_bookkeeping[ $name ] = get_option( $name, null );
}

add_filter(
	'pre_option_backupplus_backup_history',
	function () use ( &$test_history ) {
		return $test_history;
	},
	100
);
add_filter(
	'pre_update_option_backupplus_backup_history',
	function ( $value, $old ) use ( &$test_history ) {
		$test_history = $value;
		return $old;
	},
	100,
	2
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
	'pre_option_backupplus_dir',
	function () use ( $directory ) {
		return $directory . '/archives';
	},
	100
);
add_filter(
	'backupplus_get_option',
	function ( $value, $name ) use ( &$encryption ) {
		$overrides = array(
			'backupplus_service'             => array(),
			'backupplus_delete_local'        => 0,
			'backupplus_debug_mode'          => false,
			'backupplus_email'               => '',
			'backupplus_database_encryption' => $encryption,
			'backupplus_interval'            => 'manual',
			'backupplus_interval_database'   => 'manual',
			'backupplus_retain'              => 2,
		);
		return array_key_exists( $name, $overrides ) ? $overrides[ $name ] : $value;
	},
	100,
	2
);
add_filter(
	'backupplus_backupable_file_entities',
	function ( $entities, $full_info ) use ( $directory ) {
		return array(
			'plugins' => $full_info ? array(
				'path'        => $directory . '/plugins',
				'description' => 'Test plugins',
			) : $directory . '/plugins',
		);
	},
	100,
	2
);
$remove_tree = function ( $path ) use ( &$remove_tree ) {
	if ( is_file( $path ) || is_link( $path ) ) { unlink( $path );
		return; }
	foreach ( scandir( $path ) as $entry ) {
		if ( '.' !== $entry && '..' !== $entry ) { $remove_tree( $path . '/' . $entry ); }
	}
	rmdir( $path );
};
register_shutdown_function(
	function () use ( $directory, &$nonces, $remove_tree, $original_bookkeeping ) {
		foreach ( $nonces as $nonce ) { delete_site_option( 'backupplus_jobdata_' . $nonce ); }
		foreach ( $original_bookkeeping as $name => $value ) {
			if ( null === $value ) { delete_option( $name );
			} else { update_option( $name, $value ); }
		}
		$remove_tree( $directory );
	}
);
global $backupplus;
require dirname( __DIR__ ) . '/backupplus.php';
require_once BACKUPPLUS_DIR . '/backup.php';
class Incremental_Test_BackupPlus extends BackupPlus {
	public function get_semaphore_lock( $files, $database ) {
		return true; }
}
file_put_contents( $directory . '/plugins/unchanged.txt', 'unchanged' );
file_put_contents( $directory . '/plugins/changed.txt', 'before' );
file_put_contents( $directory . '/plugins/nested/deleted.txt', 'deleted' );
$run        = function ( $incremental, $timestamp ) use ( &$backupplus, &$nonces, &$test_history ) {
	$backupplus = new Incremental_Test_BackupPlus();
	$nonce      = bin2hex( random_bytes( 6 ) );
	$nonces[]   = $nonce;
	$ok         = $backupplus->boot_backup(
		1,
		0,
		array( 'plugins' ),
		true,
		'none',
		array(
			'incremental'   => $incremental,
			'use_nonce'     => $nonce,
			'use_timestamp' => $timestamp,
		)
	);
	incremental_expect( $ok, 'Backup must run successfully' );
	incremental_expect( 0 === $backupplus->error_count(), 'Backup must have no errors: ' . json_encode( $backupplus->errors ) );
	$base_time = $backupplus->jobdata_get( 'incremental_base_time', $timestamp );
	incremental_expect( ! empty( $test_history[ $base_time ]['incremental']['complete'] ), 'Completed backup must publish its inventory: ' . json_encode( $test_history ) );
	return $nonce;
};
$base_time  = time() - 3600;
$base_nonce = $run( true, $base_time );
incremental_expect( 'backup' === $backupplus->jobdata_get( 'job_type' ), 'First incremental request must create a full backup' );
$mtime = filemtime( $directory . '/plugins/changed.txt' );
file_put_contents( $directory . '/plugins/changed.txt', 'after!' );
touch( $directory . '/plugins/changed.txt', $mtime );
file_put_contents( $directory . '/plugins/new.jpg', 'new image' );
unlink( $directory . '/plugins/nested/deleted.txt' );
rmdir( $directory . '/plugins/nested' );
$run( true, $base_time + 1 );
incremental_expect( 'incremental' === $backupplus->jobdata_get( 'job_type' ), 'Second backup must be incremental' );
incremental_expect( $base_nonce === $backupplus->file_nonce && 1 === count( $test_history ), 'Increment must retain its base chain' );
$files = $test_history[ $base_time ]['plugins'];
incremental_expect( 2 === count( $files ), 'Base and increment must both remain in history' );
$zip = new ZipArchive();
incremental_expect( true === $zip->open( $directory . '/archives/' . $files[1] ), 'Increment archive must be readable' );
incremental_expect( false === $zip->locateName( 'plugins/unchanged.txt' ), 'Unchanged content must not be archived again' );
incremental_expect( 'after!' === $zip->getFromName( 'plugins/changed.txt' ), 'Same-size changes with preserved timestamps must be archived' );
incremental_expect( 'new image' === $zip->getFromName( 'plugins/new.jpg' ), 'New image must be archived' );
$manifest = json_decode( $zip->getFromName( 'backupplus-manifest.json' ), true );
incremental_expect( in_array( 'plugins/nested/deleted.txt', $manifest['deleted'], true ), 'Deleted nested file must be recorded' );
$zip->close();
$run( true, $base_time + 2 );
incremental_expect( 3 === count( $test_history[ $base_time ]['plugins'] ), 'No-change increment must preserve the chain and restore manifest' );

// Exercise the real restorer against an isolated destination, including cumulative deletions.
require_once ABSPATH . 'wp-admin/includes/file.php';
WP_Filesystem();
require_once BACKUPPLUS_DIR . '/restorer.php';
$restore_path = $directory . '/restored';
mkdir( $restore_path );
$restorer    = new BackupPlus_Restorer( new BackupPlus_Restorer_Skin(), $test_history[ $base_time ] );
$pre_restore = new ReflectionMethod( $restorer, 'pre_restore_backup' );
$restore     = new ReflectionMethod( $restorer, 'restore_backup' );
$info        = array( 'path' => $restore_path );
$result      = $pre_restore->invoke( $restorer, $test_history[ $base_time ]['plugins'], 'plugins', $info );
incremental_expect( true === $result, 'Restore preflight must succeed: ' . ( is_wp_error( $result ) ? $result->get_error_message() : json_encode( $result ) ) );
ob_start();
foreach ( $test_history[ $base_time ]['plugins'] as $index => $file ) {
	( new ReflectionProperty( $restorer, 'current_index' ) )->setValue( $restorer, $index );
	( new ReflectionProperty( $restorer, 'current_type' ) )->setValue( $restorer, 'plugins' );
	$last   = $index === count( $test_history[ $base_time ]['plugins'] ) - 1;
	$result = $restore->invoke( $restorer, $file, 'plugins', $info, $last, $last );
	incremental_expect( true === $result, 'Archive restore must succeed: ' . ( is_wp_error( $result ) ? $result->get_error_message() : json_encode( $result ) ) );
}
ob_end_clean();
incremental_expect( 'after!' === file_get_contents( $restore_path . '/changed.txt' ), 'Restored content must include the incremental change' );
incremental_expect( 'unchanged' === file_get_contents( $restore_path . '/unchanged.txt' ), 'Restore must keep unchanged content from the base' );
incremental_expect( 'new image' === file_get_contents( $restore_path . '/new.jpg' ), 'Restore must include the new image' );
incremental_expect( ! file_exists( $restore_path . '/nested' ), 'Latest manifest must remove cumulative nested deletions' );
incremental_expect( ! file_exists( $restore_path . '/backupplus-manifest.json' ), 'Restore metadata must not be copied into site files' );
$delete = new ReflectionMethod( $restorer, 'incremental_restore_deleted_paths' );
foreach ( array( '../escape', 'plugins/../../escape', '/absolute', 'plugins/dir\\escape', '' ) as $unsafe ) {
	$result = $delete->invoke(
		$restorer,
		array(
			'version' => 2,
			'type'    => 'plugins',
			'root'    => 'plugins',
			'deleted' => array( $unsafe ),
		),
		'plugins',
		$restore_path
	);
	incremental_expect( is_wp_error( $result ), 'Unsafe deletion paths must be rejected' );
}
remove_filter( 'backupplus_logline', array( $restorer, 'backupplus_logline' ), 10 );

$configuration = $test_history[ $base_time ]['incremental']['configuration'];
incremental_expect( false !== BackupPlus_Incremental_Backups::find_base( array( 'plugins' ), $configuration, $base_time + 7 * DAY_IN_SECONDS - 1 ), 'Base remains valid just before seven days' );
incremental_expect( false === BackupPlus_Incremental_Backups::find_base( array( 'plugins' ), $configuration, $base_time + 7 * DAY_IN_SECONDS ), 'Base expires at exactly seven days, regardless of increments' );
$test_history[ $base_time ]['incremental']['complete'] = false;
incremental_expect( false === BackupPlus_Incremental_Backups::find_base( array( 'plugins' ), $configuration ), 'Incomplete or aborted chains must not be reused' );
$test_history[ $base_time ]['incremental']['complete'] = true;

incremental_expect( false === BackupPlus_Incremental_Backups::find_base( array( 'plugins' ), 'different configuration' ), 'A changed source, destination, exclusion or encryption configuration requires a full backup' );
$base_archive        = $directory . '/archives/' . $files[0];
$original_archive    = file_get_contents( $base_archive );
$damaged_archive     = $original_archive;
$damaged_archive[10] = chr( ord( $damaged_archive[10] ) ^ 1 );
file_put_contents( $base_archive, $damaged_archive );
clearstatcache();
incremental_expect( false === BackupPlus_Incremental_Backups::find_base( array( 'plugins' ), $configuration ), 'Checksum corruption must reject a base even when its size is unchanged' );
file_put_contents( $base_archive, $original_archive );
$snapshot_name = $test_history[ $base_time ]['incremental']['snapshots']['plugins'];
$snapshot_path = $directory . '/archives/' . $snapshot_name;
rename( $snapshot_path, $snapshot_path . '.unavailable' );
incremental_expect( false === BackupPlus_Incremental_Backups::find_base( array( 'plugins' ), $configuration ), 'Unavailable inventories must force a full backup' );
rename( $snapshot_path . '.unavailable', $snapshot_path );
unlink( $directory . '/archives/' . $files[0] );
incremental_expect( false === BackupPlus_Incremental_Backups::find_base( array( 'plugins' ), $configuration ), 'Missing local base must force a full backup' );
$run( true, time() );
incremental_expect( 'backup' === $backupplus->jobdata_get( 'job_type' ), 'Missing base must promote the actual job to a full backup' );

$recent_time                   = $backupplus->backup_time;
$expired_time                  = time() - 7 * DAY_IN_SECONDS;
$test_history[ $expired_time ] = $test_history[ $recent_time ];
unset( $test_history[ $recent_time ] );
$run( true, time() + 1 );
incremental_expect( 'backup' === $backupplus->jobdata_get( 'job_type' ), 'Expired chain must promote the actual job to a full backup' );
incremental_expect( 'weekly' === $backupplus->schedule_backup( 'weekly' ), 'Full backups must still support a weekly schedule' );
incremental_expect( 'daily' === $backupplus->schedule_backup_increments( 'monthly' ), 'Increment schedules longer than one day must be capped to daily' );
$event = wp_get_scheduled_event( 'backupplus_backup_increments' );
incremental_expect( $event && 'daily' === $event->schedule, 'Validated incremental schedule must have a runnable cron event' );
$backupplus->schedule_backup( 'manual' );
incremental_expect( false !== wp_next_scheduled( 'backupplus_backup_increments' ), 'Incremental scheduling must work independently of a manual full-backup schedule' );
$backupplus->schedule_backup_increments( 'none' );
incremental_expect( false === wp_next_scheduled( 'backupplus_backup_increments' ), 'Disabling increments must remove their cron event' );
incremental_expect( false !== has_action( 'backupplus_backup_increments', array( $backupplus, 'backup_increments' ) ), 'Cron must have an incremental backup callback' );

// Changing encryption starts a new base; subsequent encrypted increments keep prior ciphertext intact.
$keys           = json_decode( file_get_contents( __DIR__ . '/fixtures/age-keys.json' ), true );
$encryption     = array(
	'mode'       => 'age',
	'recipients' => array( $keys['keys']['native']['recipient'] ),
);
$encrypted_time = time() + 2;
$run( true, $encrypted_time );
incremental_expect( 'backup' === $backupplus->jobdata_get( 'job_type' ), 'Changing encryption requires a full backup' );
$cipher_base = $test_history[ $encrypted_time ]['plugins'][0];
$cipher_hash = hash_file( 'sha256', $directory . '/archives/' . $cipher_base );
file_put_contents( $directory . '/plugins/new.jpg', 'encrypted image change' );
// Use a past timestamp so find_base can consider it without mocking the real clock.
$encrypted_past                  = time() - 10;
$test_history[ $encrypted_past ] = $test_history[ $encrypted_time ];
unset( $test_history[ $encrypted_time ] );
$run( true, time() + 3 );
incremental_expect( 'incremental' === $backupplus->jobdata_get( 'job_type' ), 'Encrypted chains must support increments' );
incremental_expect( $cipher_hash === hash_file( 'sha256', $directory . '/archives/' . $cipher_base ), 'Prior encrypted archives must remain unchanged' );
$cipher_increment = $test_history[ $encrypted_past ]['plugins'][1];
incremental_expect( '.crypt' === substr( $cipher_increment, -6 ), 'Increment archive must be encrypted' );
$decrypted     = $directory . '/decrypted.zip';
$cipher_stream = fopen( $directory . '/archives/' . $cipher_increment, 'rb' );
$plain_stream  = fopen( $decrypted, 'wb' );
BackupPlus_Age_Crypto::decrypt( $cipher_stream, $plain_stream, $keys['keys']['native']['identity'], $keys['passphrase'] );
fclose( $cipher_stream );
fclose( $plain_stream );
$zip = new ZipArchive();
incremental_expect( true === $zip->open( $decrypted ), 'Encrypted increment must decrypt to a valid ZIP' );
incremental_expect( 'encrypted image change' === $zip->getFromName( 'plugins/new.jpg' ), 'Decrypted increment must contain the changed file' );
incremental_expect( false === $zip->locateName( 'plugins/unchanged.txt' ), 'Encrypted increment must skip unchanged files' );
$zip->close();

// An inventory is fixed for its job, so a resumed scan cannot hide an edit made after archiving.
$inventory_name   = $backupplus->jobdata_get( 'incremental_snapshots' )['plugins'];
$inventory_before = BackupPlus_Incremental_Backups::read_snapshot( $inventory_name );
file_put_contents( $directory . '/plugins/new.jpg', 'changed during resumption' );
$scan_files   = array( $directory . '/plugins/new.jpg' => 'plugins/new.jpg' );
$scan_skipped = array();
$backupplus->jobdata_set( 'incremental_previous_snapshots', array( 'plugins' => $inventory_name ) );
BackupPlus_Incremental_Backups::scan( 'plugins', $scan_files, array( 'plugins' ), $scan_skipped );
incremental_expect( $inventory_before === BackupPlus_Incremental_Backups::read_snapshot( $inventory_name ), 'Resumed scans must keep the original fingerprint inventory' );

// Metadata for the content-directory component must remain in the working directory for pruning.
$other_destination = $directory . '/other-restored';
$other_working     = $directory . '/other-working';
mkdir( $other_destination );
mkdir( $other_working );
file_put_contents( $other_destination . '/deleted.txt', 'old content' );
$other_manifest = array(
	'version' => 2,
	'type'    => 'others',
	'root'    => 'wp-content',
	'deleted' => array( 'deleted.txt' ),
);
file_put_contents( $other_working . '/backupplus-manifest.json', json_encode( $other_manifest ) );
$result = $restorer->move_backup_in( $other_working, trailingslashit( $other_destination ), BackupPlus_Restorer::MOVEIN_COPY_IN_CONTENTS, array( 'backupplus-manifest.json' ), 'others' );
incremental_expect( true === $result && file_exists( $other_working . '/backupplus-manifest.json' ), 'Content-directory restore must keep its manifest for deletion processing' );
$result = ( new ReflectionMethod( $restorer, 'incremental_restore_prune_files' ) )->invoke( $restorer, $other_working, 'others', $other_destination );
incremental_expect( true === $result && ! file_exists( $other_destination . '/deleted.txt' ), 'Content-directory deletions must use the selected destination' );
echo "WordPress incremental backup checks passed.\n";
