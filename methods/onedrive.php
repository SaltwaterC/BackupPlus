<?php
/**
 * BackupPlus Provider: Microsoft OneDrive
 * Provider ID: onedrive
 * Provider Class: BackupPlus_BackupModule_onedrive
 */

if (!defined('ABSPATH')) die('No direct access allowed');

if (version_compare(phpversion(), '5.3.3', '>=')) {

	if (class_exists('BackupPlus_Addons_RemoteStorage_onedrive')) {

		class BackupPlus_BackupModule_onedrive extends BackupPlus_Addons_RemoteStorage_onedrive {
			public function __construct() {
				parent::__construct('onedrive', 'Microsoft OneDrive', '5.3.3', 'onedrive.png');
			}
		}
		
	} else {
	
		backupplus_try_include_file('methods/addon-not-yet-present.php', 'include_once');
		/**
		 * N.B. BackupPlus_BackupModule_AddonNotYetPresent extends BackupPlus_BackupModule
		 */
		class BackupPlus_BackupModule_onedrive extends BackupPlus_BackupModule_AddonNotYetPresent {
			public function __construct() {
				parent::__construct('onedrive', 'Microsoft OneDrive', '5.3.3', 'onedrive.png');
			}
		}
	
	}
	
} else {
	backupplus_try_include_file('methods/insufficient.php', 'include_once');
	class BackupPlus_BackupModule_onedrive extends BackupPlus_BackupModule_insufficientphp {
		public function __construct() {
			parent::__construct('onedrive', 'Microsoft OneDrive', '5.3.3', 'onedrive.png');
		}
	}
}
