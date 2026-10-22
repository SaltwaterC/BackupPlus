<?php
/**
 * BackupPlus Provider: Google Cloud
 * Provider ID: googlecloud
 * Provider Class: BackupPlus_BackupModule_googlecloud
 */

if (!defined('ABSPATH')) die('No direct access.');

require_once __DIR__.'/googlecloud/admin.php';

if (class_exists('BackupPlus_BackupModule_googlecloud')) return;

if (version_compare(PHP_VERSION, '5.2.4', '>=')) {

	if (class_exists('BackupPlus_Addons_RemoteStorage_googlecloud')) {
		class BackupPlus_BackupModule_googlecloud extends BackupPlus_Addons_RemoteStorage_googlecloud {
			use BackupPlus_googlecloud_Admin;
			public function __construct() {
				parent::__construct('googlecloud', 'Google Cloud', '5.2.4', 'googlecloud.png');
			}
		}
	} else {
		backupplus_try_include_file('methods/addon-not-yet-present.php', 'include_once');
		/**
		 * N.B. BackupPlus_BackupModule_AddonNotYetPresent extends BackupPlus_BackupModule
		 */
		class BackupPlus_BackupModule_googlecloud extends BackupPlus_BackupModule_AddonNotYetPresent {
			use BackupPlus_googlecloud_Admin;
			public function __construct() {
				parent::__construct('googlecloud', 'Google Cloud', '5.2.4', 'googlecloud.png');
			}
		}
	}
} else {
	backupplus_try_include_file('methods/insufficient.php', 'include_once');
	class BackupPlus_BackupModule_googlecloud extends BackupPlus_BackupModule_insufficientphp {
		use BackupPlus_googlecloud_Admin;
		public function __construct() {
			parent::__construct('googlecloud', 'Google Cloud', '5.2.4', 'googlecloud.png');
		}
	}
}
