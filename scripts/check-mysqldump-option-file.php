<?php
/**
 * BPS-08 in real WordPress, with the real mysqldump: the option file that carries the database password is owner-only,
 * mysqldump reads exactly one password from it whatever quotes and backslashes the password has, and the file is gone once
 * the probe for a working mysqldump has finished. A password with a line break never reaches a file, and an external
 * database password with one is not saved, whether through the settings or WP-CLI.
 *
 * backupplus_extradbs is restored afterwards.
 *
 * wp --skip-plugins=backupplus eval-file wp-content/plugins/backupplus/scripts/check-mysqldump-option-file.php
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || defined( 'BACKUPPLUS_DIR' ) ) {
	die( 'Use WP-CLI with --skip-plugins=backupplus.' );
}
function mysqldump_option_file_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	} }

require dirname( __DIR__ ) . '/backupplus.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
global $backupplus, $wp_settings_errors;

$directory = $backupplus->backups_dir_location();
$missing   = new stdClass();
$saved     = get_option( 'backupplus_extradbs', $missing );
register_shutdown_function(
	function () use ( $saved, $missing ) {
		if ( $saved === $missing ) {
			delete_option( 'backupplus_extradbs' );
		} else {
			update_option( 'backupplus_extradbs', $saved );
		}
	}
);
$option_files = function () use ( $directory ) {
	return preg_grep( '/\A[0-9a-f]{32}\.tmp\z/', scandir( $directory ) );
};
$before       = $option_files();
$mysqldump    = '';
foreach ( explode( ',', BACKUPPLUS_MYSQLDUMP_EXECUTABLE ) as $candidate ) {
	if ( is_executable( $candidate ) ) {
		$mysqldump = $candidate;
		break;
	}
}
mysqldump_option_file_expect( '' !== $mysqldump, 'The test site has a mysqldump binary.' );

// mysqldump --print-defaults shows what the option file gives it, without connecting.
$printed  = function ( $path ) use ( $mysqldump ) {
	exec( escapeshellarg( $mysqldump ) . ' --defaults-file=' . escapeshellarg( $path ) . ' --print-defaults 2>&1', $output, $status );
	mysqldump_option_file_expect( 0 === $status, 'mysqldump reads the option file: ' . implode( "\n", $output ) );
	return trim( preg_replace( '/\A.*would have been started with the following arguments:\s*/s', '', implode( "\n", $output ) ) );
};
$mask     = umask( 0022 );
$password = 'q"uo\\te\'s \\" #;x';
try {
	$path = BackupPlus_Mysqldump_Option_File::create( $directory, $password );
} finally {
	umask( $mask );
}
try {
	clearstatcache();
	mysqldump_option_file_expect( 0600 === ( fileperms( $path ) & 0777 ), 'The option file is 0600 under a 0022 umask.' );
	mysqldump_option_file_expect( '--password=' . $password === $printed( $path ), 'mysqldump reads the password unchanged and no other option.' );
} finally {
	BackupPlus_Mysqldump_Option_File::remove( $path );
}
mysqldump_option_file_expect( ! file_exists( $path ), 'remove() deletes the option file.' );

// The old addslashes() file let a line break add options such as result-file.
foreach ( array( "secret\nresult-file=" . $directory . '/injected.php', "secret\rplugin-dir=/tmp", "secret\0" ) as $refused ) {
	try {
		BackupPlus_Mysqldump_Option_File::create( $directory, $refused );
		mysqldump_option_file_expect( false, 'A password with a line break or NUL is refused.' );
	} catch ( InvalidArgumentException $e ) {
		mysqldump_option_file_expect( false !== strpos( $e->getMessage(), 'line break or NUL' ), 'The refusal says why.' );
	}
}
mysqldump_option_file_expect( $before === $option_files(), 'A refused password leaves no file.' );

// The real probe dumps the options table with DB_PASSWORD from the file, then removes it.
$found = $backupplus->find_working_sqldump( false, false );
mysqldump_option_file_expect( is_string( $found ) && '' !== $found, 'mysqldump works with DB_PASSWORD from the option file.' );
mysqldump_option_file_expect( $before === $option_files(), 'The probe leaves no option file behind.' );

// Saving an external database with such a password keeps the connection without the password, and says so.
BackupPlus_Options::admin_init();
$wp_settings_errors = array();
BackupPlus_Options::update_backupplus_option(
	'backupplus_extradbs',
	array(
		'_present' => '1',
		5          => array(
			'host' => 'db.invalid',
			'user' => 'reader',
			'pass' => "secret\nresult-file=/tmp/x",
			'name' => 'injected',
		),
		6          => array(
			'host' => 'db.invalid',
			'user' => 'reader',
			'pass' => $password,
			'name' => 'quoted',
		),
	)
);
$stored = BackupPlus_Backup_Sources::connections();
mysqldump_option_file_expect( isset( $stored[5], $stored[6] ) && 'injected' === $stored[5]['name'], 'Both connections are saved.' );
mysqldump_option_file_expect( '' === $stored[5]['pass'], 'The password with a line break is not saved.' );
mysqldump_option_file_expect( $password === $stored[6]['pass'], 'A password with quotes and backslashes is saved unchanged.' );
$errors = get_settings_errors( 'backupplus_extradbs' );
mysqldump_option_file_expect( 1 === count( $errors ) && 'invalid_password' === $errors[0]['code'] && false !== strpos( $errors[0]['message'], 'injected@db.invalid' ), 'The settings report the refused password.' );

WP_CLI::success( 'The mysqldump option file is private, removed and cannot carry extra options.' );
