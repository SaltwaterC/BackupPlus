<?php
/** Real subprocess CLI checks with isolated history, cron, options and tiny file sources. */
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
$directory = get_temp_dir() . 'backupplus-cli-' . bin2hex( random_bytes( 6 ) );
mkdir( $directory );
$prefix               = 'backupplus_cli_test_' . bin2hex( random_bytes( 6 ) ) . '_';
$test_complete        = false;
$original_environment = getenv( 'BACKUPPLUS_CLI_TEST_DIR' );
$options              = array(
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
	'backupplus_autobackup_default'        => 1,
	'backupplus_include_more_path'         => array( $directory . '/custom.txt' ),
	'backupplus_include_more'              => 1,
	'backupplus_database_selection'        => array(
		'wp' => array(
			'enabled' => true,
			'mode'    => 'selected',
			'tables'  => array( $wpdb->posts ),
		),
	),
	'backupplus_extradbs'                  => array(),
	'backupplus_schedule_state'            => array(),
);
// Redirect bookkeeping as well as settings so subprocess fixtures leave site state intact.
foreach ( array( 'backupplus_last_backup', 'backupplus_lastmessage', 'backupplus_lastlogmessage', 'backupplus_oneshotnonce', 'backupplus_last_scheduled_f', 'backupplus_last_scheduled_d', 'backupplus_last_scheduled_fd', 'backupplus_restore_in_progress', 'backupplus_retention_cleanup_status', 'backupplus_retention_cleanup_failures' ) as $name ) {
	$options[ $name ] = get_option( $name, array() );
}
foreach ( array( 'archives', 'plugins', 'themes', 'uploads', 'mu-plugins', 'others', 'wpcore' ) as $id ) { mkdir( $directory . '/' . $id );
	file_put_contents( $directory . '/' . $id . '/test.txt', $id ); }
file_put_contents( $directory . '/custom.txt', 'custom source' );
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
		foreach ( array( 'one', 'two' ) as $slug ) { $remove_tree( WP_PLUGIN_DIR . '/backupplus-cli-' . $slug ); }
		$remove_tree( get_theme_root() . '/backupplus-cli-theme' );
		$remove_tree( $directory );
		putenv( false === $original_environment ? 'BACKUPPLUS_CLI_TEST_DIR' : 'BACKUPPLUS_CLI_TEST_DIR=' . $original_environment );
		if ( ! $test_complete ) { fwrite( STDERR, "CLI integration checks did not reach completion.\n" );
			register_shutdown_function(
				function () {
					exit( 1 );
				}
			); }
	}
);
putenv( 'BACKUPPLUS_CLI_TEST_DIR=' . $directory );
$run  = function ( $command, $expected = 0 ) use ( $directory, $prefix, $options ) {
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
$rows = json_decode( $run( 'backupplus sources --format=json' )->stdout, true );
cli_wp_expect( is_array( $rows ) && in_array( 'wpcore', wp_list_pluck( $rows, 'id' ), true ), 'Sources must include core' );
$run( 'backupplus backup --type=database --incremental', 1 );
$run( 'backupplus backup --type=database --tables=missing_cli_table', 1 );
$before    = get_option( $prefix . 'backupplus_database_selection', $options['backupplus_database_selection'] );
$timestamp = trim( $run( 'backupplus backup --type=database --local --porcelain --tables=' . $wpdb->options )->stdout );
cli_wp_expect( ctype_digit( $timestamp ), 'Porcelain output must contain only the history timestamp' );
cli_wp_expect( $before === get_option( $prefix . 'backupplus_database_selection', $options['backupplus_database_selection'] ), 'CLI table selectors leave settings unchanged' );
$history = get_option( $prefix . 'backupplus_backup_history' );
cli_wp_expect( array( $wpdb->options ) === $history[ $timestamp ]['database_coverage']['wp']['tables'], 'Table restriction must reach the engine' );
$selection = $directory . '/selection.json';
file_put_contents(
	$selection,
	json_encode(
		array(
			'files'        => array( 'wpcore' ),
			'custom_paths' => array( $directory . '/custom.txt' ),
		)
	)
);
$files_at = trim( $run( 'backupplus backup --type=files --local --porcelain --selection=' . escapeshellarg( $selection ) )->stdout );
$history  = get_option( $prefix . 'backupplus_backup_history' );
cli_wp_expect( ! empty( $history[ $files_at ]['wpcore'] ) && ! empty( $history[ $files_at ]['file_sources'] ), 'Core and standalone custom archives must be recorded' );
file_put_contents( $directory . '/custom.txt', 'changed custom source' );
$increment = trim( $run( 'backupplus backup --type=files --local --incremental --porcelain --selection=' . escapeshellarg( $selection ) )->stdout );
cli_wp_expect( $files_at === $increment, 'Increment porcelain output must use the chain base timestamp' );

$source_ids = array_keys( $history[ $files_at ]['file_sources'] );
$custom_id  = $source_ids[0];
file_put_contents( $directory . '/custom.txt', 'live file before restore' );
$run( 'backupplus restore ' . $files_at . ' --components=' . $custom_id );
cli_wp_expect( 'live file before restore' === file_get_contents( $directory . '/custom.txt' ), 'Restore requires confirmation' );
$run( 'backupplus restore ' . $files_at . ' --components=missing --yes', 1 );
$run( 'backupplus restore ' . $files_at . ' --components=' . $custom_id . ' --yes' );
cli_wp_expect( 'changed custom source' === file_get_contents( $directory . '/custom.txt' ), 'CLI restores complete custom-file incremental chains' );
$points = json_decode( $run( 'backupplus restore-points ' . $files_at . ' --format=json' )->stdout, true );
$run( 'backupplus restore ' . $files_at . ' --components=' . $custom_id . ' --point=' . $points[0]['timestamp'] . ' --yes' );
cli_wp_expect( 'changed custom source' === file_get_contents( $directory . '/custom.txt' ), 'CLI restores recorded incremental points' );

file_put_contents( $directory . '/mode', 'external' );
$external_at = trim( $run( 'backupplus backup --type=database --local --porcelain --tables=' . $wpdb->posts )->stdout );
$history     = get_option( $prefix . 'backupplus_backup_history' );
cli_wp_expect( isset( $history[ $external_at ]['db1'] ) && array( $wpdb->options ) === $history[ $external_at ]['database_coverage'][1]['tables'], 'WordPress table restrictions preserve saved external database selections' );
unlink( $directory . '/mode' );
$run( 'backupplus schedule set files --interval=weekly --time=03:15 --day=2 --format=json' );
$queued = get_option( $prefix . 'cron' );
$run( 'backupplus schedule set files --interval=weekly --time=03:15 --day=2 --format=json' );
cli_wp_expect( $queued === get_option( $prefix . 'cron' ), 'Unchanged schedules retain queued events' );
$run( 'backupplus schedule set files --interval=monthly --time=03:15 --day=29', 1 );
$run( 'backupplus schedule set files --interval=daily --time=03:15 --no-exact', 1 );
cli_wp_expect( $queued === get_option( $prefix . 'cron' ), 'Invalid schedules cannot partially write' );
$run( 'backupplus retention set --files=3 --database=4 --mode=daily --time=04:00 --format=json' );
$run( 'backupplus retention set --files=0', 1 );
$run( 'backupplus retention status --format=csv' );


file_put_contents( $directory . '/mode', 'retry' );
$retry = trim( $run( 'backupplus backup --type=database --local --porcelain' )->stdout );
cli_wp_expect( ctype_digit( $retry ), 'Resumptions must finish in a new process' );
$cron      = get_option( $prefix . 'cron' );
$unrelated = false;
foreach ( $cron as $hooks ) { $unrelated = $unrelated || isset( $hooks['backupplus_cli_unrelated'] ); }
cli_wp_expect( $unrelated, 'CLI must leave unrelated due cron events independent' );
file_put_contents( $directory . '/mode', 'warning' );
$warned = $run( 'backupplus backup --type=database --local --porcelain' );
cli_wp_expect( ctype_digit( trim( $warned->stdout ) ) && false !== strpos( $warned->stderr, 'Synthetic CLI warning' ), 'Warnings exit zero, remain on stderr and preserve porcelain output' );
file_put_contents( $directory . '/mode', 'exit' );
$run( 'backupplus backup --type=database --local --porcelain', 1 );
unlink( $directory . '/mode' );

// Native extension commands select real installed fixtures and install actual local ZIP packages.
foreach ( array( 'one', 'two' ) as $slug ) {
	$plugin = WP_PLUGIN_DIR . '/backupplus-cli-' . $slug;
	cli_wp_expect( ! file_exists( $plugin ), 'Disposable plugin fixture must not already exist' );
	mkdir( $plugin );
	file_put_contents( $plugin . '/plugin.php', "<?php\n/* Plugin Name: CLI fixture $slug\nVersion: 1.0\n*/\n" );
	$zip = new ZipArchive();
	$zip->open( $directory . '/' . $slug . '.zip', ZipArchive::CREATE );
	$zip->addFromString( 'backupplus-cli-' . $slug . '/plugin.php', "<?php\n/* Plugin Name: CLI fixture $slug\nVersion: 2.0\n*/\n" );
	$zip->close();
}
$plugin_count = count( get_option( $prefix . 'backupplus_backup_history' ) );
$run( 'plugin update backupplus-cli-one backupplus-cli-two --dry-run' );
cli_wp_expect( $plugin_count === count( get_option( $prefix . 'backupplus_backup_history' ) ), 'Dry runs create no backup' );
file_put_contents( $directory . '/mode', 'finalize' );
$run( 'plugin update backupplus-cli-one backupplus-cli-two --exclude=backupplus-cli-two' );
unlink( $directory . '/mode' );
$batch = get_option( $prefix . 'backupplus_pre_update_batch' );
cli_wp_expect( 1 === count( $batch['targets'] ) && isset( $batch['targets']['plugin:backupplus-cli-one/plugin.php'] ), 'Native exclusions freeze only actual update targets' );
cli_wp_expect( false !== strpos( file_get_contents( WP_PLUGIN_DIR . '/backupplus-cli-one/plugin.php' ), 'Version: 2.0' ), 'Native plugin update must install after verified completion' );
$history = get_option( $prefix . 'backupplus_backup_history' );
cli_wp_expect( $plugin_count + 1 === count( $history ), 'One standalone restore point per invocation' );
cli_wp_expect( count( $history[ $batch['backup_time'] ]['database_coverage']['wp']['tables'] ) > 1, 'CLI pre-update backups override partial WordPress coverage' );
$run( 'plugin update backupplus-cli-two --version=2.0' );
cli_wp_expect( false !== strpos( file_get_contents( WP_PLUGIN_DIR . '/backupplus-cli-two/plugin.php' ), 'Version: 2.0' ), 'Explicit versions through native install paths must be protected' );
$theme = get_theme_root() . '/backupplus-cli-theme';
cli_wp_expect( ! file_exists( $theme ), 'Disposable theme must not already exist' );
mkdir( $theme );
file_put_contents( $theme . '/style.css', "/* Theme Name: CLI fixture\nVersion: 1.0\n*/" );
file_put_contents( $theme . '/index.php', '<?php // fixture' );
$zip = new ZipArchive();
$zip->open( $directory . '/theme.zip', ZipArchive::CREATE );
$zip->addFromString( 'backupplus-cli-theme/style.css', "/* Theme Name: CLI fixture\nVersion: 2.0\n*/" );
$zip->addFromString( 'backupplus-cli-theme/index.php', '<?php // updated fixture' );
$zip->close();
$run( 'theme update backupplus-cli-theme' );
$run( 'backupplus pre-update status --format=json' );
$count = count( get_option( $prefix . 'backupplus_backup_history' ) );
$run( 'plugin update backupplus-cli-one --version=2.0 --dry-run' );
cli_wp_expect( $count === count( get_option( $prefix . 'backupplus_backup_history' ) ), 'Explicit-version dry runs cannot install or create backups' );
$run( 'plugin upgrade backupplus-cli-one backupplus-cli-two --version=2.0' );
$batch = get_option( $prefix . 'backupplus_pre_update_batch' );
cli_wp_expect( 2 === count( $batch['targets'] ), 'Forced explicit-version bulk updates share one restore point' );
file_put_contents( $directory . '/core.zip', 'core guard fixture' );
$core_hash = hash_file( 'sha256', ABSPATH . 'wp-includes/version.php' );
$run( 'core update --force', 1 );
$run( 'core upgrade ' . escapeshellarg( $directory . '/core.zip' ) . ' --version=99.0 --force', 1 );
cli_wp_expect( $core_hash === hash_file( 'sha256', ABSPATH . 'wp-includes/version.php' ), 'Core guard tests stop before installed file changes' );
file_put_contents( $directory . '/mode', 'warning' );
$before_downloads = file_get_contents( $directory . '/downloads.log' );
$run( 'plugin update backupplus-cli-one --version=2.0', 1 );
cli_wp_expect( $before_downloads === file_get_contents( $directory . '/downloads.log' ), 'Warned pre-update backups must prevent native updates' );
unlink( $directory . '/mode' );

foreach ( array( 'expire', 'change-package', 'failed-upload' ) as $mode ) {
	$original_package = file_get_contents( $directory . '/one.zip' );
	file_put_contents( $directory . '/mode', $mode );
	$original_plugin = file_get_contents( WP_PLUGIN_DIR . '/backupplus-cli-one/plugin.php' );
	$run( 'plugin update backupplus-cli-one --version=2.0', 1 );
	cli_wp_expect( $original_plugin === file_get_contents( WP_PLUGIN_DIR . '/backupplus-cli-one/plugin.php' ), 'Updates must stop for ' . $mode );
	file_put_contents( $directory . '/one.zip', $original_package );
	unlink( $directory . '/mode' );
}
file_put_contents( $directory . '/mode', 'no-updates' );
$count = count( get_option( $prefix . 'backupplus_backup_history' ) );
$run( 'plugin update backupplus-cli-one' );
cli_wp_expect( $count === count( get_option( $prefix . 'backupplus_backup_history' ) ), 'Invocations with no updates create no backup' );
unlink( $directory . '/mode' );
$history = get_option( $prefix . 'backupplus_backup_history' );
foreach ( array_keys( $history ) as $at ) { $run( 'backupplus delete ' . $at . ' --yes' ); }
cli_wp_expect( ! get_option( $prefix . 'backupplus_backup_history' ), 'Strict deletion removes all completed chains and history' );
$test_complete = true;
WP_CLI::success( 'CLI subprocess backups, archives, increments, scheduling, retention, native updates and deletion passed.' );
