<?php

if (!defined('ABSPATH')) die('No direct access allowed.');

if (class_exists('BackupPlus_Addons_RemoteStorage_sftp')) {

	// Migrate options to standard-style - April 2017. This then enables them to get picked up by the multi-options settings translation code
	if (!is_array(BackupPlus_Options::get_backupplus_option('backupplus_sftp')) && '' != BackupPlus_Options::get_backupplus_option('backupplus_sftp_settings', '')) {
		$opts = BackupPlus_Options::get_backupplus_option('backupplus_sftp_settings');
		BackupPlus_Options::update_backupplus_option('backupplus_sftp', $opts);
		BackupPlus_Options::delete_backupplus_option('backupplus_sftp_settings');
	}

	class BackupPlus_BackupModule_sftp extends BackupPlus_Addons_RemoteStorage_sftp {
		public function __construct() {
			parent::__construct('sftp', 'SFTP/SCP');
		}
	}
	
} else {

	backupplus_try_include_file('methods/addon-not-yet-present.php', 'include_once');
	
	/**
	 * N.B. BackupPlus_BackupModule_AddonNotYetPresent extends BackupPlus_BackupModule
	 */
	class BackupPlus_BackupModule_sftp extends BackupPlus_BackupModule_AddonNotYetPresent {
		public function __construct() {
			parent::__construct('sftp', 'SFTP and SCP');
		}
	}

}
