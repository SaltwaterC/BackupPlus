<?php
/**
 * BackupPlus Provider: Microsoft Azure
 * Provider ID: azure
 * Provider Class: BackupPlus_BackupModule_azure
 */

if (!defined('ABSPATH')) die('No direct access.');

if (version_compare(phpversion(), '5.6', '>=')) {

	if (class_exists('BackupPlus_Addons_RemoteStorage_azure')) {
		class BackupPlus_BackupModule_azure extends BackupPlus_Addons_RemoteStorage_azure {
			public function __construct() {
				parent::__construct('azure', 'Microsoft Azure', true, true);
			}
		}
		
	} else {
	
		backupplus_try_include_file('methods/addon-not-yet-present.php', 'include_once');
		/**
		 * N.B. BackupPlus_BackupModule_AddonNotYetPresent extends BackupPlus_BackupModule
		 */
		class BackupPlus_BackupModule_azure extends BackupPlus_BackupModule_AddonNotYetPresent {
			public function __construct() {
				parent::__construct('azure', 'Microsoft Azure', '5.6', 'azure.png');
			}
		}
		
	}
	
} else {

	backupplus_try_include_file('methods/insufficient.php', 'include_once');
	/**
	 * N.B. BackupPlus_BackupModule_insufficientphp extends BackupPlus_BackupModule
	 */
	class BackupPlus_BackupModule_azure extends BackupPlus_BackupModule_insufficientphp {
		public function __construct() {
			parent::__construct('azure', 'Microsoft Azure', '5.6', 'azure.png');
		}
	}
	
}
