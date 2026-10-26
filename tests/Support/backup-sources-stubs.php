<?php
/** WordPress and plugin shims for the backup source tests. Loaded once per test process. */
define( 'ABSPATH', '/wordpress/' );
define( 'BACKUPPLUS_DIR', dirname( __DIR__, 2 ) );

class BackupPlus_Options {
	public static function get_backupplus_option( $name, $default = false ) {
		return $default; } }
class BackupPlus_Credential_Key_Store {
	const BACKUP_ENTITY = 'credential-keys';
	public static function exclude( $excluded, $path ) {
		return $excluded; } }
$GLOBALS['backupplus'] = new class {
	public function backups_dir_location() {
		return '/no-backups-here';
	}
	public function jobdata_get( $name, $default = false ) {
		return $default;
	}
};
