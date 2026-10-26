<?php
/** WordPress, history and storage shims for the CLI restore tests. Loaded once per process by CliRestoreTest. */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CLI', true );
define( 'BACKUPPLUS_DIR', dirname( __DIR__, 2 ) );
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
