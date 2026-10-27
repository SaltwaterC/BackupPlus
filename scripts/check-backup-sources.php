<?php
/** Real archive and incremental orchestration checks, using disposable WordPress data. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || defined( 'BACKUPPLUS_DIR' ) ) {
	die( 'Use WP-CLI with --skip-plugins=backupplus.' );
}
function sources_wp_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
define( 'BACKUPPLUS_SPLIT_MIN', 1 );
if ( ! empty( $args ) && in_array( 'pclzip', $args, true ) ) { define( 'BACKUPPLUS_PREFERPCLZIP', true );
	add_filter( 'backupplus_archive_zstd', '__return_false' ); }
$token     = bin2hex( random_bytes( 6 ) );
$directory = get_temp_dir() . 'backupplus-sources-' . $token;
mkdir( $directory );
mkdir( $directory . '/archives' );
mkdir( $directory . '/plugins' );
mkdir( $directory . '/plugins/nested' );
$test_history         = array();
$test_cron            = array();
$nonces               = array();
$encryption           = array( 'mode' => 'none' );
$original_bookkeeping = array();
$source_settings      = array();
$binary_export        = false;
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
	function ( $value, $name ) use ( &$encryption, &$source_settings ) {
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
		$overrides = array_replace( $overrides, $source_settings );
		return array_key_exists( $name, $overrides ) ? $overrides[ $name ] : $value;
	},
	100,
	2
);
add_filter(
	'backupplus_backupable_file_entities_final',
	function ( $entities, $full_info ) use ( $directory ) {
		$entities['wpcore'] = $full_info ? array(
			'path'        => $directory . '/core',
			'description' => 'Test core',
		) : $directory . '/core';
		return $entities;
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
class Sources_Test_BackupPlus extends BackupPlus {
	public function find_working_sqldump( $log_it = true, $cacheit = true ) {
		global $binary_export;
		return $binary_export ? parent::find_working_sqldump( $log_it, $cacheit ) : false;
	}
	public function get_semaphore_lock( $files, $database ) {
		return true; }
}
mkdir( $directory . '/core' );
mkdir( $directory . '/one' );
mkdir( $directory . '/two' );
mkdir( $directory . '/one/data' );
mkdir( $directory . '/two/data' );
file_put_contents( $directory . '/core/wp-config.php', 'archived config' );
file_put_contents( $directory . '/core/non-wp-root.txt', 'root custom file' );
file_put_contents( $directory . '/one/data/same.txt', 'one' );
file_put_contents( $directory . '/two/data/same.txt', 'two' );
file_put_contents( $directory . '/standalone.txt', 'standalone' );
$source_settings         = array(
	'backupplus_include_more_path'  => array( $directory . '/one/data', $directory . '/two/data', $directory . '/standalone.txt' ),
	'backupplus_include_more'       => true,
	'backupplus_include_wpcore'     => true,
	'backupplus_extradbs'           => array(),
	'backupplus_database_selection' => array(),
);
$ids                     = array_map( array( 'BackupPlus_Backup_Sources', 'source_id' ), $source_settings['backupplus_include_more_path'] );
$run                     = function ( $files, $db, $entities, $options = array() ) use ( &$backupplus, &$nonces ) {
	$backupplus = new Sources_Test_BackupPlus();
	$nonce      = bin2hex( random_bytes( 6 ) );
	$nonces[]   = $nonce;
	$result     = $backupplus->boot_backup( $files, $db, $entities, true, 'none', array_merge( array( 'use_nonce' => $nonce ), $options ) );
	return array( $result, $nonce );
};
$start                   = time() - 7200;
list( $ok, $full_nonce ) = $run(
	1,
	0,
	array_merge( array( 'wpcore' ), $ids ),
	array(
		'incremental'   => true,
		'use_timestamp' => $start,
	)
);
sources_wp_expect( $ok && ! $backupplus->error_count(), 'Core and multiple sources back up successfully: ' . json_encode( $backupplus->errors ) );
$backup = $test_history[ $start ];
$zip    = ( BackupPlus_Archive_Compression::enabled() ? new BackupPlus_Tar() : new ZipArchive() );
sources_wp_expect( true === $zip->open( $directory . '/archives/' . $backup['wpcore'][0] ), 'Core archive opens' );
sources_wp_expect( 'root custom file' === $zip->getFromName( 'non-wp-root.txt' ), 'Core includes non-WP root files without a wrapper' );
$zip->close();
foreach ( $ids as $id ) {
	sources_wp_expect( true === $zip->open( $directory . '/archives/' . $backup[ $id ][0] ), 'Additional source archive opens' );
	$metadata = json_decode( $zip->getFromName( 'backupplus-manifest.json' ), true );
	sources_wp_expect( isset( $metadata['source']['path'] ) && $id === $metadata['root'], 'Portable metadata records original source and namespace' );
	$zip->close();
}
unlink( $directory . '/one/data/same.txt' );
file_put_contents( $directory . '/one/data/new.txt', 'new data' );
file_put_contents( $directory . '/standalone.txt', 'changed standalone' );
unlink( $directory . '/core/non-wp-root.txt' );
file_put_contents( $directory . '/core/new-root.txt', 'new root' );
list( $ok ) = $run(
	1,
	0,
	array_merge( array( 'wpcore' ), $ids ),
	array(
		'incremental'   => true,
		'use_timestamp' => $start + 1,
	)
);
sources_wp_expect( $ok && 'incremental' === $backupplus->jobdata_get( 'job_type' ), 'Multiple sources use a compatible incremental chain' );
$backup = $test_history[ $start ];
require_once ABSPATH . 'wp-admin/includes/file.php';
WP_Filesystem();
require_once BACKUPPLUS_DIR . '/restorer.php';
$options  = array(
	'backupplus_source_destinations' => array(
		$ids[0] => $directory . '/restore-one',
		$ids[1] => $directory . '/restore-two',
		$ids[2] => $directory . '/renamed-file.txt',
	),
);
$restorer = new BackupPlus_Restorer( new BackupPlus_Restorer_Skin(), $backup, false, $options );
$pre      = new ReflectionMethod( $restorer, 'pre_restore_backup' );
$restore  = new ReflectionMethod( $restorer, 'restore_backup' );
foreach ( $ids as $id ) {
	$info = array( 'path' => $backup['file_sources'][ $id ]['path'] );
	$pre->invoke( $restorer, $backup[ $id ], $id, $info );
	foreach ( (array) $backup[ $id ] as $index => $file ) {
		$result = $restore->invoke( $restorer, $file, $id, $info, false, $index === array_key_last( $backup[ $id ] ) );
		sources_wp_expect( true === $result, 'Custom source restore succeeds: ' . ( is_wp_error( $result ) ? $result->get_error_message() : json_encode( $result ) ) );
	}
}
sources_wp_expect( ! file_exists( $directory . '/restore-one/same.txt' ) && 'new data' === file_get_contents( $directory . '/restore-one/new.txt' ), 'Custom incremental additions and deletions restore correctly' );
sources_wp_expect( 'two' === file_get_contents( $directory . '/restore-two/same.txt' ), 'Duplicate basenames stay separate' );
sources_wp_expect( 'changed standalone' === file_get_contents( $directory . '/renamed-file.txt' ), 'Standalone file restores into a destination filename' );
$core_destination = $directory . '/restore-core';
mkdir( $core_destination );
file_put_contents( $core_destination . '/wp-config.php', 'current config' );
$core_info = array( 'path' => $core_destination );
$pre->invoke( $restorer, $backup['wpcore'], 'wpcore', $core_info );
foreach ( $backup['wpcore'] as $index => $file ) { $result = $restore->invoke( $restorer, $file, 'wpcore', $core_info, false, $index === array_key_last( $backup['wpcore'] ) );
	sources_wp_expect( true === $result, 'Core restoration succeeds' ); }
sources_wp_expect( 'current config' === file_get_contents( $core_destination . '/wp-config.php' ), 'Core restoration preserves current wp-config.php' );
sources_wp_expect( ! file_exists( $core_destination . '/non-wp-root.txt' ) && 'new root' === file_get_contents( $core_destination . '/new-root.txt' ), 'Core incremental deletion stays within its destination' );

// Disposable tables exercise real external connections without changing the WordPress connection.
global $wpdb;
$table     = 'bp_source_' . $token;
$untouched = 'bp_untouched_' . $token;
$wpdb->query( "CREATE TABLE `$table` (id int PRIMARY KEY, value longtext)" );
$wpdb->query( "CREATE TABLE `$untouched` (id int PRIMARY KEY, value longtext)" );
register_shutdown_function(
	function () use ( $wpdb, $table, $untouched ) {
		$wpdb->query( "DROP TABLE IF EXISTS `$table`, `$untouched`" );
	}
);
$value = "http://source.example/wp_prefix serialized:a:1:{s:4:\"name\";s:4:\"same\";}\nsemicolon; quote' backslash\\";
$wpdb->insert(
	$table,
	array(
		'id'    => 1,
		'value' => $value,
	)
);
$wpdb->insert(
	$untouched,
	array(
		'id'    => 1,
		'value' => 'keep me',
	)
);
$connection                                       = array(
	'host'   => DB_HOST,
	'name'   => DB_NAME,
	'user'   => DB_USER,
	'pass'   => DB_PASSWORD,
	'prefix' => 'restricted_',
);
$source_settings['backupplus_extradbs']           = array( 7 => $connection );
$source_settings['backupplus_database_selection'] = array(
	'wp' => array( 'enabled' => false ),
	7    => array(
		'mode'   => 'selected',
		'tables' => array( $table ),
	),
);
foreach ( array( false, true ) as $binary_export ) {
	list( $ok, $nonce ) = $run( 0, 1, false );
	sources_wp_expect( $ok && ! $backupplus->error_count(), 'External-only exact selection exports with PHP and mysqldump: ' . json_encode( $backupplus->errors ) );
	$history = $test_history[ $backupplus->backup_time ];
	sources_wp_expect( ! isset( $history['db'] ) && isset( $history['db7'] ), 'Disabled WordPress database is omitted' );
	$dump   = $directory . '/archives/' . $history['db7'];
	$handle = BackupPlus_Archive_Compression::open_read( $dump );
	$sql    = '';
	while ( ! gzeof( $handle ) ) { $sql .= gzread( $handle, 1048576 );
	} gzclose( $handle );
	sources_wp_expect( false !== strpos( $sql, "CREATE TABLE `$table`" ) && false === strpos( $sql, "CREATE TABLE `$untouched`" ), 'Export selection precedes both exporters and bypasses imported prefix restriction' );
	sources_wp_expect( false !== strpos( $sql, '# BackupPlus database coverage:' ), 'Dump records intentional partial coverage' );
	$wpdb->query( "DELETE FROM `$table`" );
	$backupplus->initiate_restore_job();
	$nonces[] = $backupplus->nonce;
	$before   = $GLOBALS['wpdb'];
	$result   = BackupPlus_External_Importer::restore( $dump, 'db7', $connection );
	sources_wp_expect( true === $result, 'Dedicated external importer restores dump: ' . ( is_wp_error( $result ) ? $result->get_error_message() : json_encode( $result ) ) );
	sources_wp_expect( $before === $GLOBALS['wpdb'] && $value === $wpdb->get_var( "SELECT value FROM `$table` WHERE id=1" ), 'External restore preserves exact names and values and global connection' );
	sources_wp_expect( 'keep me' === $wpdb->get_var( "SELECT value FROM `$untouched` WHERE id=1" ), 'Unselected destination tables remain unchanged' );
	$recovered = BackupPlus_Backup_Sources::archive_metadata( array(), 'db7', $dump );
	sources_wp_expect( array( $table ) === $recovered['database_coverage'][7]['tables'], 'Rescanning recovers table coverage from dump headers' );
}

// Simulate interruption after an INSERT has committed but before any acknowledgement.
$backupplus->initiate_restore_job();
$nonces[]  = $backupplus->nonce;
$interrupt = function ( $id, $index ) use ( $wpdb, $table ) {
	if ( $index < 3 ) { return; }
	$previous = $wpdb->suppress_errors( true );
	$count    = $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );
	$wpdb->suppress_errors( $previous );
	if ( '1' === (string) $count ) { throw new RuntimeException( 'Intentional interruption after committed INSERT.' ); }
};
$wpdb->query( "DELETE FROM `$table`" );
add_action( 'backupplus_external_restore_statement', $interrupt, 10, 2 );
$result = BackupPlus_External_Importer::restore( $dump, 'db7', $connection );
remove_action( 'backupplus_external_restore_statement', $interrupt );
sources_wp_expect( is_wp_error( $result ), 'Interrupted external import stops with resumable progress' );
$result = BackupPlus_External_Importer::restore( $dump, 'db7', $connection );
sources_wp_expect( true === $result && '1' === (string) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" ), 'Resuming replays the interrupted table without duplicate rows' );
sources_wp_expect( 'keep me' === $wpdb->get_var( "SELECT value FROM `$untouched` WHERE id=1" ), 'Resuming leaves unselected tables unchanged' );
$bad         = $connection;
$bad['name'] = 'missing_database_' . $token;
try { BackupPlus_Backup_Sources::connect( $bad );
	throw new LogicException( 'Missing target database was accepted.' ); }
catch ( LogicException $e ) { throw $e; }
catch ( RuntimeException $e ) { sources_wp_expect( false === strpos( $e->getMessage(), DB_PASSWORD ), 'Connection failure does not disclose the password' ); }
$source_settings['backupplus_database_selection'] = array(
	'wp' => array(
		'mode'   => 'selected',
		'tables' => array( $table ),
	),
	7    => array( 'enabled' => false ),
);
list( $ok )                                       = $run( 0, 1, false );
sources_wp_expect( $ok && ! $backupplus->error_count( 'warning' ), 'Saved main-database exact selections omit WordPress core tables without failure warnings' );

$main_time                            = $backupplus->backup_time;
$main_backup                          = $test_history[ $main_time ];
list( $messages, $warnings, $errors ) = $backupplus->analyse_db_file( $main_time, array() );
sources_wp_expect( empty( $errors ), 'Partial WordPress dumps pass the existing restore verification even below the legacy size threshold: ' . json_encode( $errors ) );
$wpdb->query( "DELETE FROM `$table`" );
$backupplus->initiate_restore_job();
$nonces[]                       = $backupplus->nonce;
$main_restorer                  = new BackupPlus_Restorer( new BackupPlus_Restorer_Skin(), $main_backup );
$GLOBALS['backupplus_restorer'] = $main_restorer;
$pre->invoke( $main_restorer, $main_backup['db'], 'db', array() );
$result = $restore->invoke( $main_restorer, $main_backup['db'], 'db', array(), true, true );
sources_wp_expect( true === $result, 'Partial main database restore uses the existing WordPress importer successfully' );
sources_wp_expect( $value === $wpdb->get_var( "SELECT value FROM `$table` WHERE id=1" ), 'Main database selected non-prefixed table restores with its original name' );
sources_wp_expect( 'keep me' === $wpdb->get_var( "SELECT value FROM `$untouched` WHERE id=1" ), 'Main partial restore leaves unselected table unchanged' );
$source_settings['backupplus_database_selection']['wp']['tables'] = array( 'missing_' . $token );
list( $ok ) = $run( 0, 1, false );
sources_wp_expect( false === $ok && $backupplus->error_count(), 'Missing tables fail before export' );
list( $ok ) = $run(
	0,
	1,
	false,
	array(
		'database_selection' => array(
			'wp' => array(
				'mode'   => 'selected',
				'tables' => array( $table ),
			),
		),
	)
);
sources_wp_expect( $ok && array( 'missing_' . $token ) === $source_settings['backupplus_database_selection']['wp']['tables'], 'One-off overrides do not update scheduled selections' );

$source_settings['backupplus_database_selection']['wp']['tables'] = array();
list( $ok ) = $run( 0, 1, false );
sources_wp_expect( false === $ok, 'Empty exact selections fail before export' );
$source_settings['backupplus_include_more_path'][] = $directory . '/missing-source';
$missing_id                                        = BackupPlus_Backup_Sources::source_id( $directory . '/missing-source' );
list( $ok )                                        = $run( 1, 0, array( $missing_id ) );
sources_wp_expect( false === $ok, 'Missing selected file sources fail without creating successful archives' );
array_pop( $source_settings['backupplus_include_more_path'] );
$keys       = json_decode( file_get_contents( __DIR__ . '/fixtures/age-keys.json' ), true );
$encryption = array(
	'mode'       => 'age',
	'recipients' => array( $keys['keys']['native']['recipient'] ),
);
list( $ok ) = $run( 1, 0, array( $ids[0], 'wpcore' ), array( 'incremental' => true ) );
sources_wp_expect( $ok && 'backup' === $backupplus->jobdata_get( 'job_type' ), 'Encryption changes start a fresh core/custom chain' );
$encrypted_backup = $test_history[ $backupplus->backup_time ];
foreach ( array( $ids[0], 'wpcore' ) as $id ) {
	$file = $encrypted_backup[ $id ][0];
	sources_wp_expect( '.crypt' === substr( $file, -6 ), 'New core and custom archives use existing age encryption' );
	$plain = BackupPlus_Encryption::decrypt( $directory . '/archives/' . $file, $keys['keys']['native']['identity'] );
	sources_wp_expect( is_array( $plain ), 'Encrypted custom/core archive can be decrypted' );
	if ( BackupPlus_Backup_Sources::custom( $id ) ) {
		$recovered = BackupPlus_Backup_Sources::archive_metadata( array(), $id, $plain['fullpath'] );
		sources_wp_expect( $directory . '/one/data' === $recovered['file_sources'][ $id ]['path'], 'Encrypted imported metadata recovers original custom destination' );
	}
	unlink( $plain['fullpath'] );
}

add_filter(
	'backupplus_initial_jobdata',
	function ( $data ) {
		$data[ array_search( 'maxzipbatch', $data, true ) + 1 ] = 1048576;
		return $data;
	},
	100
);
$source_settings['backupplus_split_every'] = 1;
file_put_contents( $directory . '/one/data/large-a.bin', random_bytes( 1200000 ) );
file_put_contents( $directory . '/one/data/large-b.bin', random_bytes( 1200000 ) );
list( $ok )   = $run( 1, 0, array( $ids[0] ) );
$split_backup = $test_history[ $backupplus->backup_time ];
sources_wp_expect( $ok && count( $split_backup[ $ids[0] ] ) > 1, 'Custom sources use the existing encrypted split archive mechanism' );
foreach ( $split_backup[ $ids[0] ] as $index => $file ) {
	$plain = BackupPlus_Encryption::decrypt( $directory . '/archives/' . $file, $keys['keys']['native']['identity'] );
	sources_wp_expect( is_array( $plain ), 'Each encrypted split archive decrypts' );
	$working = $directory . '/split-stage-' . $index;
	mkdir( $working );
	sources_wp_expect( true === BackupPlus_Filesystem_Functions::unzip_file( $plain['fullpath'], $working ), 'Decrypted split archive extracts' );
	BackupPlus_Source_Restore::restore( $working, $ids[0], $split_backup, array( 'backupplus_source_destinations' => array( $ids[0] => $directory . '/split-restored' ) ), $index === array_key_last( $split_backup[ $ids[0] ] ) );
	unlink( $plain['fullpath'] );
}
foreach ( array( 'large-a.bin', 'large-b.bin' ) as $name ) { sources_wp_expect( hash_file( 'sha256', $directory . '/one/data/' . $name ) === hash_file( 'sha256', $directory . '/split-restored/' . $name ), 'Encrypted split round trip preserves custom file content' ); }
echo "WordPress backup source checks passed.\n";
