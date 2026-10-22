<?php
/** Focused completion checks; no WordPress installation or network is required. */

$compat_root = sys_get_temp_dir() . '/backupplus-lock-compat-' . bin2hex( random_bytes( 6 ) );
mkdir( $compat_root . '/wp-admin/includes', 0700, true );
foreach ( array( 'file.php', 'update.php', 'class-wp-upgrader.php' ) as $file ) { file_put_contents( $compat_root . '/wp-admin/includes/' . $file, '<?php' ); }
define( 'ABSPATH', $compat_root . '/' );
register_shutdown_function(
	function () use ( $compat_root ) {
		foreach ( glob( $compat_root . '/wp-admin/includes/*' ) as $file ) { unlink( $file ); }
		rmdir( $compat_root . '/wp-admin/includes' );
		rmdir( $compat_root . '/wp-admin' );
		rmdir( $compat_root );
	}
);
class WP_Upgrader {}
$compat_options = array();
function get_option( $name, $default = false ) {
	global $compat_options;
	return isset( $compat_options[ $name ] ) ? $compat_options[ $name ] : $default; }
function add_option( $name, $value, $unused = '', $autoload = 'no' ) {
	global $compat_options;
	if ( isset( $compat_options[ $name ] ) ) { return false;
	} $compat_options[ $name ] = $value;
	return true; }
function delete_option( $name ) {
	global $compat_options;
	unset( $compat_options[ $name ] ); }
function wp_cache_delete() {}
class Pre_Update_Lock_DB {
	public $options = 'wp_options';
	public function prepare( $sql, $key, $old ) {
		return array( $key, $old ); }
	public function query( $args ) {
		global $compat_options;
		if ( isset( $compat_options[ $args[0] ] ) && $compat_options[ $args[0] ] === $args[1] ) { unset( $compat_options[ $args[0] ] ); } }
}
$wpdb = new Pre_Update_Lock_DB();
function add_action() {}
function add_filter() {}
class BackupPlus_Options {
	public static $options = array();
	public static function get_backupplus_option( $name, $default = null ) {
		return array_key_exists( $name, self::$options ) ? self::$options[ $name ] : $default; }
}
class BackupPlus_Backup_History {
	public static $history;
	public static function get_history( $time ) {
		return self::$history; }
}
class Pre_Update_Backup_Fixture {
	public $directory;
	public function backups_dir_location() {
		return $this->directory; }
}
require dirname( __DIR__ ) . '/includes/class-pre-update-backups.php';
function pre_update_assert( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
pre_update_assert( BackupPlus_Pre_Update_Backups::enabled(), 'Missing setting must default to enabled' );
BackupPlus_Options::$options['backupplus_autobackup_default'] = 0;
pre_update_assert( ! BackupPlus_Pre_Update_Backups::enabled(), 'Saved disabled value must be preserved' );
$backupplus            = new Pre_Update_Backup_Fixture();
$backupplus->directory = sys_get_temp_dir() . '/backupplus-completion-' . bin2hex( random_bytes( 6 ) );
mkdir( $backupplus->directory );
register_shutdown_function(
	function () use ( $backupplus ) {
		foreach ( scandir( $backupplus->directory ) as $file ) { if ( '.' !== $file && '..' !== $file ) { unlink( $backupplus->directory . '/' . $file ); }
		}
		rmdir( $backupplus->directory );
	}
);
$coordinator                        = new BackupPlus_Pre_Update_Backups();
$batch                              = array(
	'id'           => 'batch',
	'job'          => '0123456789ab',
	'backup_time'  => time(),
	'entities'     => array( 'plugins' ),
	'destinations' => array(),
);
$job                                = array(
	'pre_update_batch' => 'batch',
	'job_type'         => 'backup',
	'backup_files'     => 'finished',
	'backup_database'  => array( 'wp' => array( 'status' => 'finished' ) ),
);
BackupPlus_Backup_History::$history = array(
	'nonce'         => '0123456789ab',
	'db'            => 'database.gz',
	'db-size'       => 7,
	'plugins'       => array( 'plugins.zip', 'plugins2.zip' ),
	'plugins-size'  => 7,
	'plugins1-size' => 7,
);
foreach ( array( 'database.gz', 'plugins.zip', 'plugins2.zip' ) as $file ) { file_put_contents( $backupplus->directory . '/' . $file, 'archive' ); }
pre_update_assert( $coordinator->verified( $batch, $job ), 'Complete local backup must pass' );
unlink( $backupplus->directory . '/plugins2.zip' );
pre_update_assert( ! $coordinator->verified( $batch, $job ), 'Missing split archive must fail' );
$batch['destinations'] = array(
	's3'  => array( 'first', 'second' ),
	'ftp' => array( '' ),
);
foreach ( array( 'database.gz', 'plugins.zip', 'plugins2.zip' ) as $file ) {
	foreach ( $batch['destinations'] as $service => $instances ) {
		foreach ( $instances as $instance ) { $job[ 'uploaded_' . $service . '-' . ( '' === $instance ? '' : $instance . '-' ) . md5( $file ) ] = 'yes'; }
	}
}
pre_update_assert( $coordinator->verified( $batch, $job ), 'Every remote instance can substitute for deleted local archives' );
$missing = 'uploaded_s3-second-' . md5( 'plugins2.zip' );
unset( $job[ $missing ] );
pre_update_assert( ! $coordinator->verified( $batch, $job ), 'Missing final upload receipt must fail' );
$job[ $missing ] = 'yes';
BackupPlus_Backup_History::$history['checksums']['sha256']['plugins0'] = hash( 'sha256', 'archive' );
file_put_contents( $backupplus->directory . '/plugins.zip', 'corrupt' );
pre_update_assert( ! $coordinator->verified( $batch, $job ), 'Same-size archive corruption must fail checksum verification' );
file_put_contents( $backupplus->directory . '/plugins.zip', 'archive' );
$job['pre_update_batch'] = 'another-batch';
pre_update_assert( ! $coordinator->verified( $batch, $job ), 'Completion must be bound to its batch' );
$job['pre_update_batch'] = 'batch';
$job['job_type']         = 'incremental';
pre_update_assert( ! $coordinator->verified( $batch, $job ), 'Incremental jobs cannot authorize updates' );
echo "Pre-update completion and default-setting checks passed.\n";
