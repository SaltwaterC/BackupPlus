<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'BACKUPPLUS_DIR', dirname( __DIR__, 2 ) );
define( 'WP_PLUGIN_DIR', '/wordpress/wp-content/plugins' );
class WP_Error {
	public function __construct( ...$args ) {} }
function is_wp_error( $value ) {
	return $value instanceof WP_Error; }
function trailingslashit( $value ) {
	return rtrim( $value, '/' ) . '/'; }
function untrailingslashit( $value ) {
	return rtrim( $value, '/' ); }
function apply_filters( $name, $value, ...$args ) {
	return 'backupplus_restore_move_old_mode' === $name ? -1 : $value;
}
class BackupPlus_Encryption {
	public static function is_file_encrypted( $file ) {
		return '.crypt' === substr( $file, -6 ); } }
class BackupPlus_Age_Restore {
	public static $call;
	public static function file( $path, $ticket, $job, $timestamp ) {
		self::$call = array( $ticket, $job, $timestamp );
		return 'valid' === $ticket ? array( 'fullpath' => dirname( $path ) . '/prepared.zip' ) : false;
	}
}
class Restore_Inventory_Backup {
	private $directory;
	public function __construct( $directory ) {
		$this->directory = $directory; }
	public function backups_dir_location() {
		return $this->directory; }
	public function log( ...$args ) {}
	public function log_e( ...$args ) {}
}
class Restore_Inventory_Move_Filesystem extends Restore_Inventory_Filesystem {
	public function exists( $path ) {
		return false; }
}
class Restore_Inventory_Filesystem {
	public $deleted = array();
	public function exists( $path ) {
		return true; }
	public function delete( $path, $recursive ) {
		$this->deleted[] = $path;
		return true; }
	public function dirlist( $path ) {
		return array(
			'alpha' => array(),
			'beta'  => array(),
		); }
	public function is_dir( $path ) {
		return true; }
}
require BACKUPPLUS_DIR . '/includes/class-restore-inventory.php';
require BACKUPPLUS_DIR . '/includes/restorer/trait-file-restore.php';
require BACKUPPLUS_DIR . '/includes/restorer/trait-entity-restore.php';
class Restore_Inventory_Harness {
	use BackupPlus_Restorer_File_Restore, BackupPlus_Restorer_Entity_Restore;
	private $plugins_to_skip             = array();
	private $plugins_to_restore          = array( 'alpha' );
	private $include_unspecified_plugins = false;
	private $themes_to_skip              = array();
	private $themes_to_restore           = array();
	private $include_unspecified_themes  = false;
	private $restore_options             = array();
	private $strings                     = array( 'moving_old' => 'Moving old files' );
	private $skin;
	public function __construct() {
		$this->skin = new class {
			public function feedback( $message ) {}
		};
	}
	public function delete_paths( $manifest, $destination ) {
		return $this->incremental_restore_deleted_paths( $manifest, 'plugins', $destination ); }
	public function scan_paths( $destination ) {
		return $this->incremental_restore_scan_dir(
			$destination . '/',
			$destination . '/plugins',
			1,
			array(
				'listed_levels' => 2,
				'contents'      => array(
					'directories' => array(),
					'files'       => array(),
				),
			),
			'plugins'
		); }
	public function move_old( $filesystem, $destination ) {
		$this->move_existing_to_old( 'plugins', $destination, $filesystem, $destination );
	}
}
