<?php

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

/*
	- A container for all the remote commands implemented. Commands map exactly onto method names (and hence this class should not implement anything else, beyond the constructor, and private methods)
	- Return format is either to return data (boolean, string, array), or an WP_Error object
	Commands are not allowed to begin with an underscore. So, any private methods can be prefixed with an underscore.
	The commands themselves live in traits grouped by concern, in includes/commands/: backups (jobs and existing sets), settings, remote storage and advanced tools. This file keeps the constructor and the private helpers.
	TODO: Many of these just verify input, and then call back into a relevant method in BackupPlus_Admin. Once all commands have been ported over to go via this class, those methods in BackupPlus_Admin can generally be folded into the relevant method in here, and removed from BackupPlus_Admin. (Since this class is intended to become the official way of performing actions). As a bonus, we then won't need so much _load_ud(_admin) boilerplate.
*/

if ( class_exists( 'BackupPlus_Commands' ) ) {
	return;
}

require_once __DIR__ . '/commands/trait-backups.php';
require_once __DIR__ . '/commands/trait-settings.php';
require_once __DIR__ . '/commands/trait-remote-storage.php';
require_once __DIR__ . '/commands/trait-tools.php';

class BackupPlus_Commands {
	use BackupPlus_Commands_Backups;
	use BackupPlus_Commands_Settings;
	use BackupPlus_Commands_Remote_Storage;
	use BackupPlus_Commands_Tools;

	private $_uc_helper;

	/**
	 * Constructor
	 *
	 * @param Class $uc_helper The 'helper' needs to provide the method _backupplus_background_operation_started
	 */
	public function __construct( $uc_helper ) {
		$this->_uc_helper = $uc_helper;
	}

	private function _load_ud() {
		global $backupplus;
		return is_a( $backupplus, 'BackupPlus' ) ? $backupplus : false;
	}

	private function _load_ud_admin() {
		if ( ! defined( 'BACKUPPLUS_DIR' ) || ! is_file( BACKUPPLUS_DIR . '/admin.php' ) ) {
			return false;
		}
		backupplus_try_include_file( 'admin.php', 'include_once' );
		global $backupplus_admin;
		return $backupplus_admin;
	}
}
