<?php
/** WordPress shims for BackupPlus_Restore_Continuation and the job ID generator: site options kept in memory. */
define( 'ABSPATH', sys_get_temp_dir() . '/' );
define( 'BACKUPPLUS_DIR', dirname( __DIR__, 2 ) );
$GLOBALS['restore_continuation_options'] = array();
function get_site_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['restore_continuation_options'] ) ? $GLOBALS['restore_continuation_options'][ $name ] : $default;
}
function update_site_option( $name, $value ) {
	$GLOBALS['restore_continuation_options'][ $name ] = $value;
	return true;
}
function delete_site_option( $name ) {
	unset( $GLOBALS['restore_continuation_options'][ $name ] );
	return true;
}
function apply_filters( $hook, $value ) {
	return $value;
}
