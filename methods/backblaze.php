<?php
/**
 * BackupPlus Provider: Backblaze
 * Provider ID: backblaze
 * Provider Class: BackupPlus_BackupModule_backblaze
 */
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}
require_once BACKUPPLUS_DIR . '/includes/class-remote-storage-module.php';
require_once __DIR__ . '/backblaze/client.php';

class BackupPlus_BackupModule_backblaze extends BackupPlus_Remote_Storage_Module {
	protected $fields       = array(
		'application_key_id' => array( 'Application key ID', '' ),
		'application_key'    => array( 'Application key', '', 'password' ),
		'bucketid'           => array( 'Bucket ID (not bucket name)', '' ),
		'path'               => array( 'Folder prefix (optional)', '' ),
	);
	protected $instructions = 'Use a B2 application key with listFiles, readFiles, writeFiles and deleteFiles access to an existing bucket. Retention removes all versions of each selected backup.';
	public function options_exist( $options ) {
		return ! empty( $options['application_key_id'] ) && ! empty( $options['application_key'] ) && ! empty( $options['bucketid'] ); }
	protected function create_client( $options ) {
		return new BackupPlus_Backblaze_Client( $options ); }
}
