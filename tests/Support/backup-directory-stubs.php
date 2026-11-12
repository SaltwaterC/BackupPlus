<?php
/** WordPress shims for BackupPlus_Backup_Directory, on top of storage-directory-stubs.php. */
$GLOBALS['backup_directory_settings_errors'] = array();
function __( $text, $domain = 'default' ) {
	return $text;
}
function add_settings_error( $setting, $code, $message, $type = 'error' ) {
	$GLOBALS['backup_directory_settings_errors'][] = array( $setting, $code, $message, $type );
}
