<?php

if (!defined('ABSPATH')) die('No direct access.');

if (version_compare(phpversion(), '5.3.3', '>=')) {

	if (class_exists('BackupPlus_Addons_RemoteStorage_backblaze')) {
		class BackupPlus_BackupModule_backblaze extends BackupPlus_Addons_RemoteStorage_backblaze {
			public function __construct() {
				parent::__construct('backblaze', 'Backblaze', true, true);
			}
		}
		
	} else {
	
		backupplus_try_include_file('methods/addon-not-yet-present.php', 'include_once');
		/**
		 * N.B. BackupPlus_BackupModule_AddonNotYetPresent extends BackupPlus_BackupModule
		 */
		class BackupPlus_BackupModule_backblaze extends BackupPlus_BackupModule_AddonNotYetPresent {
			public function __construct() {
				parent::__construct('backblaze', 'Backblaze', '5.3.3', 'backblaze.png');
			}
		}
		
	}
	
} else {

	backupplus_try_include_file('methods/insufficient.php', 'include_once');
	/**
	 * N.B. BackupPlus_BackupModule_insufficientphp extends BackupPlus_BackupModule
	 */
	class BackupPlus_BackupModule_backblaze extends BackupPlus_BackupModule_insufficientphp {
		public function __construct() {
			parent::__construct('backblaze', 'Backblaze', '5.3.3', 'backblaze.png');
		}
	}
	
}
