<?php
/** pCloud test seams: the provider's bootstrap hooks, captured once per process, and a module bound to the fake HTTP client. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}

// Load the provider class (the factory knows where it lives) before extending it.
BackupPlus_Storage_Provider_Factory::create( 'pcloud', false );

/** The filters registered by methods/pcloud/bootstrap.php; the file may only be loaded once, the shims are reset for every test. */
class Pcloud_Test_Bootstrap {

	public static $filters = array();

	public static function load() {
		$GLOBALS['provider_filters'] = array();
		require_once BACKUPPLUS_DIR . '/methods/pcloud/bootstrap.php';
		self::$filters               = $GLOBALS['provider_filters'];
		$GLOBALS['provider_filters'] = array();
	}
}

Pcloud_Test_Bootstrap::load();

class Cloud_Test_Pcloud_Module extends BackupPlus_BackupModule_pcloud {
	public function get_id() {
		return 'pcloud'; }
	protected function create_client( $options ) {
		return new BackupPlus_Pcloud_Client( $options, $GLOBALS['cloud_http'] ); }
}
