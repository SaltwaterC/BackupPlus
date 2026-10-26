<?php
/**
 * WordPress and plugin shims for the pre-update backup tests. Loaded once per test process, after the test defined ABSPATH.
 * The state they keep is held by Pre_Update_Test_State, which the test resets.
 */
define( 'BACKUPPLUS_DIR', dirname( __DIR__, 2 ) );
class WP_Upgrader {}
class Pre_Update_Test_State {
	/** The in-memory options table. */
	public static $options = array();
}
function get_option( $name, $default = false ) {
	return isset( Pre_Update_Test_State::$options[ $name ] ) ? Pre_Update_Test_State::$options[ $name ] : $default; }
function add_option( $name, $value, $unused = '', $autoload = 'no' ) {
	if ( isset( Pre_Update_Test_State::$options[ $name ] ) ) {
		return false;
	}
	Pre_Update_Test_State::$options[ $name ] = $value;
	return true; }
function delete_option( $name ) {
	unset( Pre_Update_Test_State::$options[ $name ] ); }
function wp_cache_delete() {}
class Pre_Update_Lock_DB {
	public $options = 'wp_options';
}
$GLOBALS['wpdb'] = new Pre_Update_Lock_DB();
// No database here: the stale-lock DELETE acts on the in-memory options.
class BackupPlus_SQL {
	public static function wpdb() {
		return new self(); }
	public function execute( $template, $parts = array(), $values = array() ) {
		if ( isset( Pre_Update_Test_State::$options[ $values[0] ] ) && (string) Pre_Update_Test_State::$options[ $values[0] ] === $values[1] ) {
			unset( Pre_Update_Test_State::$options[ $values[0] ] );
			return 1;
		}
		return 0; }
}
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
