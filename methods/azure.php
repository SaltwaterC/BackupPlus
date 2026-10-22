<?php
/**
 * BackupPlus Provider: Microsoft Azure
 * Provider ID: azure
 * Provider Class: BackupPlus_BackupModule_azure
 */
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}
require_once BACKUPPLUS_DIR . '/includes/class-remote-storage-module.php';
require_once __DIR__ . '/azure/admin.php';
require_once __DIR__ . '/azure/client.php';

class BackupPlus_BackupModule_azure extends BackupPlus_Remote_Storage_Module {
	use BackupPlus_azure_Admin;

	protected $fields       = array(
		'account'    => array( 'Storage account name', '' ),
		'accountkey' => array( 'Storage account key (base64)', '', 'password' ),
		'container'  => array( 'Blob container', '' ),
		'path'       => array( 'Folder prefix (optional)', '' ),
		'endpoint'   => array( 'Blob endpoint suffix', 'blob.core.windows.net' ),
	);
	protected $instructions = 'Use an existing private Blob container and an account key. Endpoint suffixes support Azure Global, US Government and China.';
	public function options_exist( $options ) {
		return ! empty( $options['account'] ) && ! empty( $options['accountkey'] ) && ! empty( $options['container'] ); }
	protected function create_client( $options ) {
		return new BackupPlus_Azure_Client( $options ); }
}
