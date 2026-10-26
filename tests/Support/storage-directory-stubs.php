<?php
/** WordPress option shims for testing the actual backup directory resolver. */
define( 'BACKUPPLUS_DIR', dirname( __DIR__, 2 ) );

$GLOBALS['storage_directory_options'] = array();
$GLOBALS['wpdb']                      = (object) array( 'options' => 'wp_options' );
function is_multisite() {
	return false;
}
function wp_cache_delete( $key, $group ) {}
function get_option( $name, $default = false ) {
	return $GLOBALS['storage_directory_options'][ $name ] ?? $default;
}
class BackupPlus_SQL {
	public static function wpdb() {
		return new self();
	}
	public function execute( $template, $parts, $values ) {
		list( $name, $value ) = $values;
		if ( isset( $GLOBALS['storage_directory_options'][ $name ] ) ) {
			return 0;
		}
		$GLOBALS['storage_directory_options'][ $name ] = $value;
		return 1;
	}
}
function untrailingslashit( $path ) {
	return rtrim( $path, '/\\' );
}
function trailingslashit( $path ) {
	return untrailingslashit( $path ) . '/';
}
class BackupPlus_Options {
	public static function get_backupplus_option( $name ) {
		return get_option( $name );
	}
	public static function update_backupplus_option( $name, $value ) {
		$GLOBALS['storage_directory_options'][ $name ] = $value;
		return true;
	}
}
