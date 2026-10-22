<?php
/**
 * BackupPlus Provider: pCloud
 * Provider ID: pcloud
 * Provider Class: BackupPlus_BackupModule_pcloud
 */

if (!defined('ABSPATH')) die('No direct access allowed');

require_once __DIR__.'/pcloud/admin.php';

if (class_exists('BackupPlus_Addons_RemoteStorage_pcloud')) {

	class BackupPlus_BackupModule_pcloud extends BackupPlus_Addons_RemoteStorage_pcloud {
		use BackupPlus_pcloud_Admin;
		public function __construct() {
			parent::__construct('pcloud', 'pCloud', false, 'pcloud-logo.png');
		}
	}
	
} else {

	backupplus_try_include_file('methods/addon-not-yet-present.php', 'include_once');
	/**
	 * N.B. BackupPlus_BackupModule_AddonNotYetPresent extends BackupPlus_BackupModule
	 */
	class BackupPlus_BackupModule_pcloud extends BackupPlus_BackupModule_AddonNotYetPresent {
		use BackupPlus_pcloud_Admin;
		public function __construct() {
			parent::__construct('pcloud', 'pCloud', false, 'pcloud-logo.png');
		}
	}

}
