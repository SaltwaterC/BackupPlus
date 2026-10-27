<?php
/** Isolated storage monitor fixtures; no live files or providers are touched. */
define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
class BackupPlus_Options {
	public static $options = array();
	public static function get_backupplus_option( $name, $default = false ) {
		return isset( self::$options[ $name ] ) ? self::$options[ $name ] : $default; }
}
function get_option( $name, $default = false ) {
	return BackupPlus_Options::get_backupplus_option( $name, $default ); }
function update_option( $name, $value ) {
	BackupPlus_Options::$options[ $name ] = $value;
	return true; }
function delete_option( $name ) {
	unset( BackupPlus_Options::$options[ $name ] ); }
function is_wp_error( $value ) {
	return $value instanceof WP_Error; }
function wp_cache_delete() {}
function __( $text ) {
	return $text;
}
function wp_next_scheduled() {
	return false; }
function wp_schedule_single_event() {}
function wp_mail( $to, $subject, $message ) {
	$GLOBALS['storage_mails'][] = compact( 'to', 'subject', 'message' );
	return true; }
class WP_Error {}
class BackupPlus_Backup_Sources {
	public static function custom( $id ) {
		return 0 === strpos( $id, 'source-' ); }
}
class BackupPlus_Retention_Cleanup {
	public static $active = false;
	public static function backup_active() {
		return self::$active; }
}
class BackupPlus_Backup_History {
	public static $history = array();
	public static function get_history() {
		return self::$history; }
	public static function save_history( $history ) {
		self::$history = $history; }
	public static function always_get_from_db() {}
}
class BackupPlus_Storage_Methods_Interface {
	public static $provider;
	public static $labels = array();
	public static function get_storage_objects_and_ids() {
		return array(
			'fake' => array(
				'object'            => self::$provider,
				'instance_settings' => array(
					'a' => array( 'id' => 'a' ) + self::$labels,
					'b' => array( 'id' => 'b' ),
				),
			),
		); }
	public static function get_enabled_storage_objects_and_ids() {
		return self::get_storage_objects_and_ids(); }
}
class Storage_Limits_Test_Provider {
	public $lost_acknowledgement = false;
	public $id                   = 'a';
	public $files                = array(
		'a' => array(),
		'b' => array(),
	);
	public $failed_listing       = false;
	public $failed_delete        = '';
	public $calls                = array();
	public function supports_feature() {
		return true; }
	public function set_options( $options ) {
		$this->id = $options['id']; }
	public function listfiles() {
		return $this->failed_listing ? new WP_Error() : array_values( $this->files[ $this->id ] ); }
	public function delete( $files ) {
		foreach ( $files as $name ) {
			$this->calls[] = array( $this->id, $name );
			if ( $this->failed_delete === $name ) { return false; }
			unset( $this->files[ $this->id ][ $name ] );
		}
		return ! $this->lost_acknowledgement;
	}
}

function get_site_option( $name, $default = false ) {
	return get_option( $name, $default ); }
