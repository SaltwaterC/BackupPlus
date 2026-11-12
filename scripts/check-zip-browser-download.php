<?php
/**
 * BPS-20 in real WordPress: a file the zip browser extracts for download gets a random name that no web server runs, and the
 * download stages accept only such a file, extracted for the same user, never another file of the backup directory.
 *
 * wp --skip-plugins=backupplus eval-file wp-content/plugins/backupplus/scripts/check-zip-browser-download.php
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || defined( 'BACKUPPLUS_DIR' ) ) { die( 'Use WP-CLI with --skip-plugins=backupplus.' ); }
function zip_browser_expect( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); } }

$root      = get_temp_dir() . 'backupplus-zip-browser-' . bin2hex( random_bytes( 8 ) );
$directory = $root . '/backups';
mkdir( $directory, 0700, true );
$history = array();
foreach ( array(
	'backupplus_dir'            => function () use ( $directory ) {
		return $directory;
	},
	'backupplus_backup_history' => function () use ( &$history ) {
		return $history;
	},
	'backupplus_debug_mode'     => function () {
		return 0;
	},
) as $option => $callback ) {
	add_filter( 'pre_option_' . $option, $callback, 100 );
}

global $backupplus, $backupplus_admin;
require dirname( __DIR__ ) . '/backupplus.php';
require_once BACKUPPLUS_DIR . '/admin.php';
backupplus_try_include_file( 'includes/class-wpadmin-commands.php', 'require_once' );

$timestamp = 1704067200;
$archive   = 'backup_2024-01-01-0000_Zip_Browser_abcdef012345-uploads.zip';
$job_name  = 'backupplus_jobdata_' . dechex( $timestamp ) . '0' . substr( md5( 'uploads' ), 0, 3 );
$other     = 0;
$extracted = array();
try {
	$zip = new ZipArchive();
	zip_browser_expect( true === $zip->open( $directory . '/' . $archive, ZipArchive::CREATE ), 'The test archive must be created' );
	$zip->addFromString( 'uploads/shell.php', '<?php echo "ran";' );
	$zip->close();
	$history[ $timestamp ] = array(
		'uploads'      => array( $archive ),
		'uploads-size' => filesize( $directory . '/' . $archive ),
		'nonce'        => 'abcdef012345',
	);

	$admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	wp_set_current_user( (int) $admins[0] );
	$commands = new BackupPlus_WPAdmin_Commands( $backupplus_admin );
	$params   = array(
		'timestamp' => $timestamp,
		'type'      => 'uploads',
		'findex'    => 0,
	);
	$parent   = ( new ReflectionMethod( $commands, '_backupplus_zip_archive' ) )->invoke( $commands, $params );
	$result   = $commands->get_zipfile_download( $params + array( 'path' => $parent['parent_name'] . DIRECTORY_SEPARATOR . 'uploads/shell.php' ) );
	zip_browser_expect( isset( $result['path'] ) && preg_match( '/\Aextract-[a-f0-9]{32}\.tmp\z/D', $result['path'] ), 'The extracted copy must have a random .tmp name: ' . json_encode( $result ) );
	$extracted[] = $result['path'];
	zip_browser_expect( '<?php echo "ran";' === file_get_contents( $directory . '/' . $result['path'] ), 'The extracted copy must hold the entry' );
	zip_browser_expect( array() === preg_grep( '/\.php/i', scandir( $directory ) ), 'No file in the backup directory may carry the entry\'s .php name' );

	$refused = function ( $response ) {
		return is_array( $response ) && isset( $response['result'] ) && 'deleted' !== $response['result'];
	};
	// Any other file of the backup directory, by name, is refused, and is still there afterwards
	foreach ( array( $archive, 'extract-' . str_repeat( 'a', 32 ) . '.tmp', '../backups/' . $archive ) as $file_path ) {
		zip_browser_expect( $refused( $backupplus_admin->do_backupplus_download_backup( array( 0 ), 'uploads', $timestamp, 'delete', false, $file_path ) ), "The delete stage must refuse $file_path" );
	}
	zip_browser_expect( is_file( $directory . '/' . $archive ), 'A refused file path must not delete the backup archive' );

	// Another administrator cannot fetch or delete this user's extraction
	$other = wp_insert_user(
		array(
			'user_login' => 'zip-browser-' . bin2hex( random_bytes( 4 ) ),
			'user_pass'  => wp_generate_password( 32 ),
			'role'       => 'administrator',
		)
	);
	zip_browser_expect( ! is_wp_error( $other ), 'The second administrator must be created' );
	wp_set_current_user( $other );
	zip_browser_expect( $refused( $backupplus_admin->do_backupplus_download_backup( array( 0 ), 'uploads', $timestamp, 'delete', false, $result['path'] ) ), 'Another user\'s extraction must be refused' );
	zip_browser_expect( is_file( $directory . '/' . $result['path'] ), 'A refused extraction must be left in place' );

	wp_set_current_user( (int) $admins[0] );
	$deleted = $backupplus_admin->do_backupplus_download_backup( array( 0 ), 'uploads', $timestamp, 'delete', false, $result['path'] );
	zip_browser_expect( isset( $deleted['result'] ) && 'deleted' === $deleted['result'] && ! file_exists( $directory . '/' . $result['path'] ), 'The user\'s own extraction must be removable: ' . json_encode( $deleted ) );
	echo "PASS: zip browser extractions are random .tmp files, and the download stages accept only the user's own extraction\n";
} finally {
	foreach ( $extracted as $name ) {
		delete_transient( 'backupplus_extract_' . substr( $name, 8, 32 ) );
	}
	delete_site_option( $job_name );
	if ( $other && ! is_wp_error( $other ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $other );
	}
	foreach ( array_diff( scandir( $directory ), array( '.', '..' ) ) as $entry ) {
		unlink( $directory . '/' . $entry );
	}
	rmdir( $directory );
	rmdir( $root );
}
