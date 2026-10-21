<?php

if (!defined('ABSPATH')) die('No direct access allowed.');

if (class_exists('BackupPlus_Addons_RemoteStorage_webdav')) {

	// Migrate options to new-style storage - April 2017
	if (!is_array(BackupPlus_Options::get_backupplus_option('backupplus_webdav')) && '' != BackupPlus_Options::get_backupplus_option('backupplus_webdav_settings', '')) {
		$opts = BackupPlus_Options::get_backupplus_option('backupplus_webdav_settings');
		BackupPlus_Options::update_backupplus_option('backupplus_webdav', $opts);
		BackupPlus_Options::delete_backupplus_option('backupplus_webdav_settings');
	}
	
	class BackupPlus_BackupModule_webdav extends BackupPlus_Addons_RemoteStorage_webdav {
		public function __construct() {
			parent::__construct('webdav', 'WebDAV');
		}
	}
	
} else {

	backupplus_try_include_file('methods/addon-not-yet-present.php', 'include_once');
	
	/**
	 * N.B. BackupPlus_BackupModule_AddonNotYetPresent extends BackupPlus_BackupModule
	 */
	class BackupPlus_BackupModule_webdav extends BackupPlus_BackupModule_AddonNotYetPresent {
		public function __construct() {
			parent::__construct('webdav', 'WebDAV');
		}
	}

}
