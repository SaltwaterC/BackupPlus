<?php
/** WordPress and storage shims for the PITR state tests. Loaded once per process by PitrStateTest. */

function __( $text, $domain = null ) {
	return $text; }
function is_wp_error( $value ) {
	return false;
}
class BackupPlus_Options {

	public static $values = array();
	public static function get_backupplus_option( $key, $default = null ) {
		return array_key_exists( $key, self::$values ) ? self::$values[ $key ] : $default;
	}
}
class BackupPlus_Backup_History {

	public static $history = array();
	public static function get_history( $timestamp = null ) {
		return null === $timestamp ? self::$history : ( isset( self::$history[ $timestamp ] ) ? self::$history[ $timestamp ] : array() );
	}
	public static function save_history( $history ) {
		self::$history = $history;
	}
}
class BackupPlus_Storage_Methods_Interface {

	public static $provider;
	public static function get_storage_objects_and_ids( $services ) {
		return array( 'fixture' => array( 'object' => self::$provider ) );
	}
}
class PITR_State_Provider {

	public $remote;
	public $local;
	public $fail             = null;
	public $deletion_failure = false;
	public $deletion_delay   = 0;
	public $uploads          = array();
	public $options          = array( 'directory' => 'original' );
	public function get_options() {
		return $this->options;
	}
	public function set_options( $options, $save = false, $id = null ) {
		$this->options = $options;
	}
	public function supports_feature() {
		return false;
	}
	public function backup( $files ) {
		foreach ( $files as $file ) {
			if ( $file === $this->fail ) {
				return false;
			} $this->uploads[] = $file;
			copy( $this->local . '/' . $file, $this->remote . '/' . $file );
		}
		return true;
	}
	public function download( $file ) {
		return copy( $this->remote . '/' . $file, $this->local . '/' . $file );
	}
	public function delete( $file ) {
		if ( $this->deletion_delay ) { usleep( $this->deletion_delay ); }
		return ! $this->deletion_failure && file_exists( $this->remote . '/' . $file ) ? unlink( $this->remote . '/' . $file ) : false;
	}
	public function listfiles( $prefix ) {
		return array_map(
			function ( $file ) {
				return array( 'name' => basename( $file ) );
			},
			glob( $this->remote . '/' . $prefix . '*' )
		);
	}
}
