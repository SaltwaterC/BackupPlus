<?php
/** Real subprocess checks of the management commands: jobs, history, remote storage, settings, notifications and diagnostics. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || defined( 'BACKUPPLUS_DIR' ) ) { die( 'Use WP-CLI with --skip-plugins=backupplus.' ); }
set_exception_handler(
	function ( $error ) {
		fwrite( STDERR, $error->getMessage() . "\n" . $error->getTraceAsString() . "\n" );
		exit( 1 );
	}
);
global $wpdb;
function cli_wp_expect( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); } }
$directory = get_temp_dir() . 'backupplus-cli-manage-' . bin2hex( random_bytes( 6 ) );
mkdir( $directory );
$prefix               = 'backupplus_cli_manage_' . bin2hex( random_bytes( 6 ) ) . '_';
$test_complete        = false;
$original_environment = getenv( 'BACKUPPLUS_CLI_TEST_DIR' );
// Every option these commands write is redirected, so the site keeps its own settings.
$options = array(
	'backupplus_backup_history'            => array(),
	'cron'                                 => array(),
	'backupplus_pre_update_batch'          => array(),
	'backupplus_dir'                       => $directory . '/archives',
	'backupplus_service'                   => array( 'none' ),
	'backupplus_database_encryption'       => array( 'mode' => 'none' ),
	'backupplus_delete_local'              => 0,
	'backupplus_debug_mode'                => 0,
	'backupplus_email'                     => '',
	'backupplus_interval'                  => 'manual',
	'backupplus_interval_database'         => 'manual',
	'backupplus_interval_increments'       => 'none',
	'backupplus_schedule_exact_files'      => 0,
	'backupplus_schedule_exact_db'         => 0,
	'backupplus_schedule_exact_increments' => 0,
	'backupplus_starttime_files'           => '',
	'backupplus_starttime_db'              => '',
	'backupplus_starttime_increments'      => '',
	'backupplus_retention_cleanup_mode'    => 'after_backup',
	'backupplus_retention_cleanup_time'    => '',
	'backupplus_retain'                    => 20,
	'backupplus_retain_db'                 => 20,
	'backupplus_retain_extrarules'         => array(),
	'backupplus_report_warningsonly'       => array(),
	'backupplus_report_wholebackup'        => array(),
	'backupplus_autobackup_default'        => 1,
	'backupplus_include_more_path'         => array(),
	'backupplus_include_more'              => 0,
	'backupplus_database_selection'        => array(
		'wp' => array(
			'enabled' => true,
			'mode'    => 'selected',
			'tables'  => array( $wpdb->options ),
		),
	),
	'backupplus_extradbs'                  => array(),
	'backupplus_database_next_id'          => 1,
	'backupplus_s3generic'                 => array(),
	'backupplus_ntfy'                      => array(),
	'backupplus_storage_limits'            => array(),
	'backupplus_storage_limits_status'     => array(),
	'backupplus_schedule_state'            => array(),
);
foreach ( array( 'backupplus_last_backup', 'backupplus_lastmessage', 'backupplus_lastlogmessage', 'backupplus_oneshotnonce', 'backupplus_last_scheduled_f', 'backupplus_last_scheduled_d', 'backupplus_last_scheduled_fd', 'backupplus_restore_in_progress', 'backupplus_retention_cleanup_status', 'backupplus_retention_cleanup_failures' ) as $name ) {
	$options[ $name ] = get_option( $name, array() );
}
foreach ( array( 'archives', 'plugins', 'themes', 'uploads', 'mu-plugins', 'others', 'wpcore' ) as $id ) { mkdir( $directory . '/' . $id );
	file_put_contents( $directory . '/' . $id . '/test.txt', $id ); }
file_put_contents(
	$directory . '/config.json',
	json_encode(
		array(
			'prefix'  => $prefix,
			'options' => $options,
		)
	)
);
$remove_tree = function ( $path ) use ( &$remove_tree ) {
	if ( is_dir( $path ) && ! is_link( $path ) ) { foreach ( array_diff( scandir( $path ), array( '.', '..' ) ) as $file ) { $remove_tree( $path . '/' . $file );
	} rmdir( $path ); }
	elseif ( file_exists( $path ) || is_link( $path ) ) { unlink( $path ); }
};
register_shutdown_function(
	function () use ( &$test_complete, $prefix, $directory, $remove_tree, $original_environment ) {
		global $wpdb;
		foreach ( glob( $directory . '/archives/log.*.txt' ) as $log ) {
			if ( preg_match( '/log\.([a-f0-9]{12})\.txt$/D', $log, $match ) ) { foreach ( array( 'backupplus_jobdata_', 'backupplus_cli_request_', 'backupplus_cli_result_' ) as $key ) { delete_site_option( $key . $match[1] ); }
			}
		}
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
		foreach ( $names as $name ) { delete_option( $name ); }
		$remove_tree( $directory );
		putenv( false === $original_environment ? 'BACKUPPLUS_CLI_TEST_DIR' : 'BACKUPPLUS_CLI_TEST_DIR=' . $original_environment );
		if ( ! $test_complete ) { fwrite( STDERR, "Management CLI checks did not reach completion.\n" );
			register_shutdown_function(
				function () {
					exit( 1 );
				}
			); }
	}
);
putenv( 'BACKUPPLUS_CLI_TEST_DIR=' . $directory );
$run    = function ( $command, $expected = 0 ) use ( $directory, $prefix, $options ) {
	$result = WP_CLI::launch_self(
		$command,
		array(),
		array(
			'allow-root'   => true,
			'skip-plugins' => 'backupplus',
			'require'      => array( __DIR__ . '/fixtures/cli.php' ),
			'exec'         => array( 'putenv(' . var_export( 'BACKUPPLUS_CLI_TEST_DIR=' . $directory, true ) . ');' ),
		),
		false,
		true
	);
	if ( $expected !== $result->return_code ) {
		$logs = glob( $directory . '/archives/log.*.txt' );
		usort(
			$logs,
			function ( $a, $b ) {
				return filemtime( $b ) <=> filemtime( $a );
			}
		);
		foreach ( array_slice( $logs, 0, 1 ) as $log ) { fwrite( STDERR, implode( '', array_slice( file( $log ), -18 ) ) ); }
	}
	cli_wp_expect( $expected === $result->return_code, $command . ' [' . $result->command . ']: ' . $result->stdout . $result->stderr );
	foreach ( $options as $name => $unused ) { wp_cache_delete( $prefix . $name, 'options' ); }
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	return $result;
};
$json   = function ( $command, $expected = 0 ) use ( $run ) {
	$decoded = json_decode( $run( $command, $expected )->stdout, true );
	cli_wp_expect( is_array( $decoded ), $command . ' must print JSON' );
	return $decoded;
};
$option = function ( $name ) use ( $prefix, $options ) {
	wp_cache_delete( $prefix . $name, 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	return get_option( $prefix . $name, $options[ $name ] );
};

// Diagnostics and jobs with nothing running.
cli_wp_expect( array() === $json( 'backupplus jobs list --format=json' ), 'No job is listed on an idle site' );
$run( 'backupplus jobs cancel aaaabbbbcccc --yes', 1 );
$run( 'backupplus jobs resume aaaabbbbcccc', 1 );
$run( 'backupplus jobs log aaaabbbbcccc', 1 );
$run( 'backupplus jobs log not-a-job', 1 );
$checks = $json( 'backupplus doctor --format=json' );
cli_wp_expect( in_array( 'backup directory', wp_list_pluck( $checks, 'check' ), true ), 'Doctor reports the backup directory' );
$tables = $json( 'backupplus db-size --format=json' );
cli_wp_expect( in_array( $wpdb->options, wp_list_pluck( $tables, 'name' ), true ), 'db-size lists WordPress tables' );
$json( 'backupplus cron --format=json' );

// Settings.
$run( 'backupplus settings set backupplus_retain 7' );
cli_wp_expect( '7' === trim( $run( 'backupplus settings get backupplus_retain' )->stdout ), 'A saved setting reads back' );
$run( 'backupplus settings set backupplus_ntfy topic', 1 );
$run( 'backupplus settings set backupplus_s3generic x', 1 );
$run( 'backupplus settings get backupplus_extradbs', 1 );
$export = $directory . '/export.json';
$run( 'backupplus settings export --file=' . $export );
$run( 'backupplus settings export --file=' . $export, 1 );
cli_wp_expect( '0600' === substr( sprintf( '%o', fileperms( $export ) ), -4 ), 'Exports are owner-only' );
$exported = json_decode( file_get_contents( $export ), true );
cli_wp_expect( 7 === (int) $exported['data']['backupplus_retain'], 'The export holds the saved setting' );
foreach ( array( 'backupplus_s3generic', 'backupplus_ntfy', 'backupplus_extradbs' ) as $secret ) { cli_wp_expect( ! array_key_exists( $secret, $exported['data'] ), 'The export leaves out ' . $secret ); }
$run( 'backupplus settings set backupplus_retain 3' );
$run( 'backupplus settings import ' . $export . ' --yes' );
cli_wp_expect( '7' === trim( $run( 'backupplus settings get backupplus_retain' )->stdout ), 'Import restores the exported setting' );
file_put_contents( $directory . '/not-an-export.json', '{"nothing":true}' );
$run( 'backupplus settings import ' . $directory . '/not-an-export.json --yes', 1 );

// Remote storage.
$remote = $directory . '/remote.json';
file_put_contents(
	$remote,
	json_encode(
		array(
			'accesskey' => 'AKIACLITEST',
			'secretkey' => 'cli-test-secret-value',
			'path'      => 'bucket/dir',
			'endpoint'  => '127.0.0.1:1',
		)
	)
);
$run( 'backupplus remote set s3generic ' . $remote . ' --instance=clitest --label=CLI --select' );
$listed = $json( 'backupplus remote list --format=json' );
cli_wp_expect( array( 'clitest' ) === wp_list_pluck( $listed, 'instance' ) && 1 === (int) $listed[0]['configured'] && 1 === (int) $listed[0]['selected'], 'The destination is listed as configured and selected' );
cli_wp_expect( false === strpos( serialize( $option( 'backupplus_s3generic' ) ), 'cli-test-secret-value' ), 'The secret key is encrypted at rest' );
cli_wp_expect( false === strpos( $run( 'backupplus remote list --all --format=json' )->stdout, 'cli-test-secret-value' ), 'Listings never print credentials' );
$run( 'backupplus remote test s3generic --instance=clitest', 1 );
$run( 'backupplus remote set s3generic ' . $directory . '/missing.json', 1 );
$run( 'backupplus remote set nowhere ' . $remote, 1 );
$run( 'backupplus remote set s3generic ' . $remote . ' --enabled=2', 1 );
$run( 'backupplus remote remove s3generic clitest --yes' );
cli_wp_expect( array() === $json( 'backupplus remote list --format=json' ), 'A removed destination is no longer listed' );
$run( 'backupplus remote remove s3generic clitest --yes', 1 );

// External databases, with the site's own account.
$database = $directory . '/database.json';
file_put_contents(
	$database,
	json_encode(
		array(
			'host' => DB_HOST,
			'name' => DB_NAME,
			'user' => DB_USER,
			'pass' => DB_PASSWORD,
		)
	)
);
$run( 'backupplus databases test --file=' . $database );
$run( 'backupplus databases set ' . $database );
$connections = $json( 'backupplus databases list --format=json' );
cli_wp_expect( array( '1' ) === array_map( 'strval', wp_list_pluck( $connections, 'id' ) ), 'The connection is listed' );
cli_wp_expect( false === strpos( $run( 'backupplus databases list --format=json' )->stdout, DB_PASSWORD ), 'Listings never print passwords' );
$run( 'backupplus databases test 1' );
file_put_contents( $directory . '/bad.json', '{"bogus":1}' );
$run( 'backupplus databases set ' . $directory . '/bad.json --skip-test', 1 );
$run( 'backupplus databases remove 1 --yes' );
cli_wp_expect( array() === $json( 'backupplus databases list --format=json' ), 'A removed connection is no longer listed' );

// Notifications.
$run( 'backupplus notify test', 1 );
$run( 'backupplus notify set', 1 );
file_put_contents( $directory . '/ntfy.json', '{"server":"http://127.0.0.1:9","topic":"cli-check-topic","token":"tk_clicheck"}' );
$run( 'backupplus notify set --ntfy=' . $directory . '/ntfy.json --email=0' );
$status = $run( 'backupplus notify status --format=json' )->stdout;
cli_wp_expect( false !== strpos( $status, '"ntfy"' ) && false === strpos( $status, 'cli-check-topic' ) && false === strpos( $status, 'tk_clicheck' ), 'Status shows ntfy without its topic or token' );
$run( 'backupplus notify test', 1 );
file_put_contents( $directory . '/ntfy.json', '{"server":"http://127.0.0.1:9","topic":"bad topic"}' );
$run( 'backupplus notify set --ntfy=' . $directory . '/ntfy.json', 1 );
$run( 'backupplus notify set --disable-ntfy' );
cli_wp_expect( array() === $json( 'backupplus notify status --format=json' ), 'Disabled channels are not listed' );

// Storage limits.
$run( 'backupplus storage-limits set local --alert=1 --quota=2' );
$limits = $json( 'backupplus storage-limits status --format=json' );
cli_wp_expect( 1000000000 === (int) $limits[0]['alert'] && 2000000000 === (int) $limits[0]['quota'], 'Limits are saved as decimal gigabytes' );
$run( 'backupplus storage-limits set local --alert=3 --quota=2', 1 );
$run( 'backupplus storage-limits set local --alert=abc', 1 );
$run( 'backupplus storage-limits set local --instance=x --quota=1', 1 );
$run( 'backupplus storage-limits run --yes' );
$limits = $json( 'backupplus storage-limits status --format=json' );
cli_wp_expect( null !== $limits[0]['usage'] && '' !== (string) $limits[0]['measured'], 'A run records measured usage' );
$run( 'backupplus storage-limits set local --alert=0 --quota=0' );
$run( 'backupplus storage-limits run --yes', 1 );

// History: backups to download, protect and delete.
$first  = trim( $run( 'backupplus backup --type=database --local --porcelain' )->stdout );
$second = trim( $run( 'backupplus backup --type=database --local --porcelain --label="Second backup"' )->stdout );
cli_wp_expect( ctype_digit( $first ) && ctype_digit( $second ) && $first !== $second, 'Two backups exist' );
$history = $option( 'backupplus_backup_history' );
cli_wp_expect( 'Second backup' === $history[ $second ]['label'], 'The label reaches history' );
$plan = $json( 'backupplus restore ' . $first . ' --dry-run --format=json' );
cli_wp_expect( 'db' === $plan[0]['component'] && 1 === (int) $plan[0]['local'], 'The restore dry run lists local archives' );
$download = $directory . '/download';
$paths    = array_filter( explode( "\n", trim( $run( 'backupplus download ' . $first . ' --to=' . $download . ' --type=database --porcelain' )->stdout ) ) );
cli_wp_expect( 1 === count( $paths ) && is_file( reset( $paths ) ) && '0600' === substr( sprintf( '%o', fileperms( reset( $paths ) ) ), -4 ), 'Download copies owner-only archives' );
cli_wp_expect( hash_file( 'sha256', reset( $paths ) ) === hash_file( 'sha256', $directory . '/archives/' . basename( reset( $paths ) ) ), 'The copy is identical' );
$run( 'backupplus download ' . $first . ' --to=' . $download . ' --type=database', 1 );
$run( 'backupplus download ' . $first . ' --to=' . $download . ' --type=database --force' );
$run( 'backupplus download ' . $first . ' --to=' . $download . ' --components=wpcore', 1 );
$run( 'backupplus download 1 --to=' . $download, 1 );
$run( 'backupplus rescan' );
$run( 'backupplus keep ' . $first );
cli_wp_expect( ! empty( $option( 'backupplus_backup_history' )[ $first ]['always_keep'] ), 'keep protects a backup' );
$run( 'backupplus keep 1', 1 );
$run( 'backupplus delete', 1 );
$run( 'backupplus delete ' . $first . ' --keep=1', 1 );
$run( 'backupplus delete --keep=0', 1 );
$run( 'backupplus delete --keep=1', 1 );
$run( 'backupplus keep --off ' . $first );
$planned = $json( 'backupplus delete --keep=1 --dry-run --format=json' );
cli_wp_expect( array( (string) $first ) === array_map( 'strval', wp_list_pluck( $planned, 'timestamp' ) ), 'The dry run lists only the older chain' );
cli_wp_expect( 2 === count( $option( 'backupplus_backup_history' ) ), 'A dry run deletes nothing' );
$run( 'backupplus delete --keep=1 --yes' );
cli_wp_expect( array( (string) $second ) === array_map( 'strval', array_keys( $option( 'backupplus_backup_history' ) ) ), 'Bulk deletion removes the older chain only' );
$run( 'backupplus delete --older-than=1 --dry-run', 1 );

// Retention on demand needs the daily cleanup mode.
$run( 'backupplus retention run --yes', 1 );
$run( 'backupplus retention set --mode=daily --time=04:00' );
$run( 'backupplus retention run --yes' );
$run( 'backupplus retention set --mode=after_backup' );

// Recovery commands share the format option.
$run( 'backupplus pitr status --format=bogus', 1 );
$run( 'backupplus delete ' . $second . ' --yes' );
cli_wp_expect( ! $option( 'backupplus_backup_history' ), 'Deleting by timestamp still works' );
$test_complete = true;
WP_CLI::success( 'Management CLI commands passed.' );
