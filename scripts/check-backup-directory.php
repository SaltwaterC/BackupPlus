<?php
/**
 * BPS-07 in real WordPress: every write of the backup directory setting goes through BackupPlus_Backup_Directory::sanitize(),
 * which accepts only a single folder name inside wp-content that is not a WordPress, plugin, theme or uploads folder. The
 * resolver refuses such folders even when they were saved before the check, and the protection files it writes never replace
 * existing files. BACKUPPLUS_BACKUP_DIRECTORY is the only way to choose another location.
 *
 * The setting is restored afterwards; the disposable folders are removed.
 *
 * wp --skip-plugins=backupplus eval-file wp-content/plugins/backupplus/scripts/check-backup-directory.php
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || defined( 'BACKUPPLUS_DIR' ) ) {
	die( 'Use WP-CLI with --skip-plugins=backupplus.' );
}
function backup_directory_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	} }

require dirname( __DIR__ ) . '/backupplus.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
global $backupplus, $wp_settings_errors;

$token     = bin2hex( random_bytes( 8 ) );
$valid     = 'backupplus-directory-check-' . $token;
$foreign   = 'backupplus-directory-foreign-' . $token;
$outside   = get_temp_dir() . 'backupplus-directory-outside-' . $token;
$folders   = array( WP_CONTENT_DIR . '/' . $valid, WP_CONTENT_DIR . '/' . $foreign, $outside );
$missing   = new stdClass();
$saved     = get_option( 'backupplus_dir', $missing );
$sanitizer = array( 'BackupPlus_Backup_Directory', 'sanitize' );

// Folders WordPress loads or serves; none of them may receive protection files from this check.
$guarded = array( ABSPATH, WP_CONTENT_DIR, WP_PLUGIN_DIR, WPMU_PLUGIN_DIR, get_theme_root(), wp_upload_dir( null, false )['basedir'] );
$before  = array();
foreach ( $guarded as $folder ) {
	foreach ( array( 'index.html', '.htaccess', 'web.config' ) as $file ) {
		$before[ $folder . '/' . $file ] = is_file( $folder . '/' . $file ) ? md5_file( $folder . '/' . $file ) : null;
	}
}

$remove = function ( $folder ) {
	if ( ! is_dir( $folder ) || is_link( $folder ) ) {
		return;
	}
	foreach ( array_diff( scandir( $folder ), array( '.', '..' ) ) as $entry ) {
		unlink( $folder . '/' . $entry );
	}
	rmdir( $folder );
};
register_shutdown_function(
	function () use ( $saved, $missing, &$folders, $remove, $sanitizer ) {
		remove_filter( 'sanitize_option_backupplus_dir', $sanitizer );
		if ( $saved === $missing ) {
			delete_option( 'backupplus_dir' );
		} else {
			update_option( 'backupplus_dir', $saved );
		}
		array_map( $remove, $folders );
	}
);

/** Save through update_option(), as the settings page, the settings importer and wp option update do, and report errors. */
$save = function ( $value ) use ( &$wp_settings_errors ) {
	$wp_settings_errors = array();
	BackupPlus_Options::update_backupplus_option( 'backupplus_dir', $value );
	return array( get_option( 'backupplus_dir' ), wp_list_pluck( get_settings_errors( 'backupplus_dir' ), 'message' ) );
};
/** Store a value as a release before the fix would have. */
$store_unchecked = function ( $value ) use ( $sanitizer ) {
	remove_filter( 'sanitize_option_backupplus_dir', $sanitizer );
	update_option( 'backupplus_dir', $value );
	add_filter( 'sanitize_option_backupplus_dir', $sanitizer, 10, 2 );
};

backup_directory_expect( false !== has_filter( 'sanitize_option_backupplus_dir', $sanitizer ), 'The setting must be checked on every request, not only in wp-admin' );

list( $stored, $errors ) = $save( WP_CONTENT_DIR . '/' . $valid . '/' );
backup_directory_expect( $valid === $stored && ! $errors, 'A folder inside wp-content must be accepted and stored by name' );
$location = $backupplus->backups_dir_location( false );
backup_directory_expect( WP_CONTENT_DIR . '/' . $valid === $location, 'The accepted folder must be used' );
foreach ( array( 'index.html', '.htaccess', 'web.config' ) as $file ) {
	backup_directory_expect( is_file( $location . '/' . $file ), "The backup directory must get $file" );
}

mkdir( $outside, 0700 );
mkdir( WP_CONTENT_DIR . '/' . $foreign, 0700 );
file_put_contents( WP_CONTENT_DIR . '/' . $foreign . '/index.html', '<html><body>Site page</body></html>' );
$refused = array(
	'mu-plugins (the finding)'         => 'mu-plugins',
	'wp-content/mu-plugins'            => 'wp-content/mu-plugins',
	'the plugins folder'               => WP_PLUGIN_DIR,
	'a theme root'                     => get_theme_root(),
	'uploads'                          => 'uploads',
	'inside uploads'                   => wp_upload_dir( null, false )['basedir'] . '/backups',
	'the web root'                     => ABSPATH,
	'a relative web root'              => '..',
	'wp-content itself'                => WP_CONTENT_DIR,
	'outside wp-content'               => $outside,
	'traversal'                        => '../' . basename( $outside ),
	'nested'                           => $valid . '/nested',
	'a folder with its own index.html' => $foreign,
);
foreach ( $refused as $label => $value ) {
	list( $stored, $errors ) = $save( $value );
	backup_directory_expect( $valid === $stored, "Saving $label must keep the previous setting" );
	backup_directory_expect( 1 === count( $errors ), "Saving $label must report why it was refused" );
}
backup_directory_expect( '<html><body>Site page</body></html>' === file_get_contents( WP_CONTENT_DIR . '/' . $foreign . '/index.html' ), 'A refused folder must be left alone' );

// A value saved before the check leads to the default folder, and the refused folder gets nothing.
foreach ( array( 'mu-plugins', WP_PLUGIN_DIR, ABSPATH ) as $unsafe ) {
	$store_unchecked( $unsafe );
	$location = $backupplus->backups_dir_location( false );
	backup_directory_expect( 1 === preg_match( '#\A' . preg_quote( WP_CONTENT_DIR, '#' ) . '/backupplus(?:-[a-f0-9]{32})?\z#D', $location ), "An unsafe saved value ($unsafe) must lead to the default folder" );
	backup_directory_expect( basename( $location ) === get_option( 'backupplus_dir' ), 'The default folder must replace the unsafe saved value' );
}

// A value saved before the check that is safe, and that the settings form sends back unchanged, keeps working.
$store_unchecked( $outside );
list( $stored, $errors ) = $save( $outside . '/' );
backup_directory_expect( $outside === $stored && ! $errors, 'An unchanged saved value must not be refused' );
backup_directory_expect( $outside === $backupplus->backups_dir_location( false ), 'An unchanged safe value must still be used' );

// Protection files are created when missing and never replaced.
$store_unchecked( $valid );
file_put_contents( WP_CONTENT_DIR . '/' . $valid . '/index.html', 'kept' );
unlink( WP_CONTENT_DIR . '/' . $valid . '/web.config' );
$backupplus->backups_dir_location( false );
backup_directory_expect( 'kept' === file_get_contents( WP_CONTENT_DIR . '/' . $valid . '/index.html' ), 'An existing index.html must not be replaced' );
backup_directory_expect( is_file( WP_CONTENT_DIR . '/' . $valid . '/web.config' ), 'A missing web.config must be created' );

foreach ( $before as $path => $hash ) {
	backup_directory_expect( $hash === ( is_file( $path ) ? md5_file( $path ) : null ), "$path must not be created or changed" );
}

// The constant chooses any other location, and the setting can no longer change it.
define( 'BACKUPPLUS_BACKUP_DIRECTORY', $outside . '/configured' );
array_unshift( $folders, $outside . '/configured' );
backup_directory_expect( $outside . '/configured' === $backupplus->backups_dir_location( false ), 'BACKUPPLUS_BACKUP_DIRECTORY must set the location' );
list( $stored, $errors ) = $save( $foreign . '-other' );
backup_directory_expect( $valid === $stored && 1 === count( $errors ), 'The setting must not change while BACKUPPLUS_BACKUP_DIRECTORY is defined' );

WP_CLI::success( 'The backup directory setting accepts only safe folders and never replaces existing files.' );
