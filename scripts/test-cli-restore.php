<?php
/** Restore selection and archive verification checks without WordPress. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CLI', true );
define( 'BACKUPPLUS_DIR', dirname( __DIR__ ) );
function __( $text ) {
	return $text; }
function is_wp_error( $value ) {
	return false; }
class BackupPlus_CLI {
	public static function words( $value ) {
		return explode( ',', $value ); }
}
class BackupPlus_Backup_History {
	public static $backup;
	public static function get_history( $timestamp ) {
		return '123' === (string) $timestamp ? self::$backup : array(); }
}
class BackupPlus_Source_Restore {
	public static $options;
	public static function preflight( $entities, $backup, $options ) {
		self::$options = $options; }
}
class BackupPlus_Encryption {
	public static function is_file_encrypted( $path ) {
		return '.crypt' === substr( $path, -6 ); }
}
class BackupPlus_Storage_Methods_Interface {
	public static $calls = 0;
	public static function get_remote_file( $services, $file ) {
		global $backupplus;
		++self::$calls;
		file_put_contents( $backupplus->directory . '/' . $file, 'remote archive' );
	}
}
class CLI_Restore_Backup {
	public $directory;
	public function get_backupable_file_entities() {
		return array(
			'plugins' => array( 'path' => '/plugins' ),
			'wpcore'  => array( 'path' => '/core' ),
		); }
	public function get_canonical_service_list( $services ) {
		return $services; }
	public function backups_dir_location() {
		return $this->directory; }
}
require BACKUPPLUS_DIR . '/includes/class-backup-sources.php';
require_once BACKUPPLUS_DIR . '/includes/class-credential-key-store.php';
require BACKUPPLUS_DIR . '/includes/class-cli-restore.php';
function restore_cli_expect( $value, $message ) {
	if ( ! $value ) { throw new RuntimeException( $message ); } }
function restore_cli_reject( $callback, $message ) {
	try { $callback();
	} catch ( RuntimeException $error ) { return;
	} catch ( InvalidArgumentException $error ) { return; }
	throw new RuntimeException( $message );
}
$backupplus                          = new CLI_Restore_Backup();
$custom                              = BackupPlus_Backup_Sources::source_id( '/historical/custom.txt' );
BackupPlus_Backup_History::$backup   = array(
	'plugins'          => array( 'plugins.zip', 'plugins2.zip' ),
	'wpcore'           => array( 'core.zip' ),
	$custom            => array( 'custom.zip' ),
	'db'               => 'db.gz',
	'db2'              => 'db2.gz',
	'incremental_sets' => array(
		100 => array( 'plugins' => array( 0 => 'plugins.zip' ) ),
		200 => array( 'plugins' => array( 1 => 'plugins2.zip' ) ),
	),
);
list( $backup, $entities, $options ) = BackupPlus_CLI_Restore::selection( 123, array() );
restore_cli_expect( 5 === count( $entities ) && isset( $entities[ $custom ] ), 'Default restores include historical custom files and external databases' );
restore_cli_expect( array( 'saved' => '2' ) === $options['backupplus_external_targets'][2], 'External restore targets use existing saved IDs' );
list( $backup, $entities ) = BackupPlus_CLI_Restore::selection(
	123,
	array(
		'type'       => 'files',
		'components' => 'plugins',
		'point'      => '100',
	)
);
restore_cli_expect( array( 'plugins' => 'plugins' ) === $entities && array( 'plugins.zip' ) === $backup['plugins'], 'Historical points prune later archives before decryption' );
list( $backup, $entities ) = BackupPlus_CLI_Restore::selection( 123, array( 'type' => 'database' ) );
restore_cli_expect( 2 === count( $entities ) && isset( $entities['db'], $entities['db2'] ), 'Database-only restores exclude files' );
foreach ( array(
	array( 'type' => 'invalid' ),
	array( 'components' => 'missing' ),
	array(
		'type'       => 'files',
		'components' => 'db',
	),
	array( 'point' => '199' ),
) as $invalid ) {
	restore_cli_reject(
		function () use ( $invalid ) {
			BackupPlus_CLI_Restore::selection( 123, $invalid );
		},
		'Invalid restore selectors must fail'
	);
}
restore_cli_reject(
	function () {
		BackupPlus_CLI_Restore::selection( 999, array() );
	},
	'Missing backups must fail'
);
$directory = sys_get_temp_dir() . '/backupplus-cli-restore-unit-' . bin2hex( random_bytes( 6 ) );
mkdir( $directory );
$backupplus->directory = $directory;
try {
	file_put_contents( $directory . '/plugins.zip', 'archive' );
	$backup = array(
		'timestamp'    => 123,
		'plugins'      => array( 'plugins.zip' ),
		'plugins-size' => 7,
		'checksums'    => array( 'sha256' => array( 'plugins0' => hash( 'sha256', 'archive' ) ) ),
	);
	restore_cli_expect( array( $directory . '/plugins.zip' ) === BackupPlus_CLI_Restore::archives( $backup, array( 'plugins' => 'plugins' ) ), 'Valid archives pass size and checksum verification' );
	file_put_contents( $directory . '/plugins.zip', 'changed' );
	restore_cli_reject(
		function () use ( $backup ) {
			BackupPlus_CLI_Restore::archives( $backup, array( 'plugins' => 'plugins' ) );
		},
		'Changed same-sized archives fail before restoring'
	);
	$backup['plugins'] = array( '../outside.zip' );
	restore_cli_reject(
		function () use ( $backup ) {
			BackupPlus_CLI_Restore::archives( $backup, array( 'plugins' => 'plugins' ) );
		},
		'Unsafe history archive names must fail'
	);
	$backup = array(
		'timestamp' => 123,
		'plugins'   => array( 'remote.zip' ),
		'service'   => array( 'fixture' ),
	);
	BackupPlus_CLI_Restore::archives( $backup, array( 'plugins' => 'plugins' ) );
	restore_cli_expect( 1 === BackupPlus_Storage_Methods_Interface::$calls && is_file( $directory . '/remote.zip' ), 'Missing archives are fetched from recorded storage' );
	$value = 'disposable-secret';
	BackupPlus_CLI_Secret::forget( $value );
	restore_cli_expect( '' === $value, 'Secret variables are discarded after use' );
	echo "PASS: CLI restore selectors, historical chains, external targets, remote retrieval and archive verification\n";
} finally {
	BackupPlus_CLI_Restore::cleanup();
	foreach ( glob( $directory . '/*' ) as $path ) { unlink( $path ); }
	rmdir( $directory );
}
