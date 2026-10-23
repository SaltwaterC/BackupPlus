<?php
/**
 * BackupPlus Provider: pCloud
 * Provider ID: pcloud
 * Provider Class: BackupPlus_BackupModule_pcloud
 */
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}
require_once BACKUPPLUS_DIR . '/includes/class-remote-storage-module.php';
require_once __DIR__ . '/pcloud/admin.php';
require_once __DIR__ . '/pcloud/client.php';
require_once __DIR__ . '/pcloud/oauth.php';

class BackupPlus_BackupModule_pcloud extends BackupPlus_Remote_Storage_Module {
	use BackupPlus_pcloud_Admin;
	use BackupPlus_Pcloud_OAuth;

	protected $fields       = array(
		'pclauth'       => array( 'OAuth access token (or connect after saving)', '', 'password' ),
		'location'      => array(
			'Data region',
			'1',
			'select',
			array(
				'1' => 'United States',
				'2' => 'Europe',
			),
		),
		'path'          => array( 'Backup folder path', 'BackupPlus' ),
		'client_id'     => array( 'OAuth application ID (optional with an access token)', '' ),
		'client_secret' => array( 'OAuth application secret', '', 'password' ),
	);
	protected $instructions = 'Enter an existing OAuth token, or save your own pCloud application ID and secret and connect the account. The backup folder is created when uploading. Streaming uploads require PHP cURL.';
	public function options_exist( $options ) {
		return ! empty( $options['pclauth'] ); }
	protected function create_client( $options ) {
		return new BackupPlus_Pcloud_Client( $options ); }
	public function get_configuration_template() {
		return parent::get_configuration_template() . '<tr class="{{get_template_css_classes true}}"><td colspan="2">' . $this->get_authentication_link( false ) . '</td></tr>';
	}
}
