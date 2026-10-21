<?php
/**
 * BackupPlus Provider: OpenStack (Swift)
 * Provider ID: openstack
 * Provider Class: BackupPlus_BackupModule_openstack
 */

if (!defined('ABSPATH')) die('No direct access.');

// Necessary to place the code in a separate file, because it uses namespaces, which cause a fatal error in PHP 5.2
if (version_compare(phpversion(), '5.3.3', '>=')) {
	backupplus_try_include_file('methods/openstack2.php', 'include_once');
} else {
	backupplus_try_include_file('methods/insufficient.php', 'include_once');
	class BackupPlus_BackupModule_openstack extends BackupPlus_BackupModule_insufficientphp {
		public function __construct() {
			parent::__construct('openstack', 'OpenStack', '5.3.3');
		}
	}
}
