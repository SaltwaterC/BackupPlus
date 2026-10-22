<?php
/**
 * BackupPlus Provider: Google Cloud
 * Provider ID: googlecloud
 * Provider Class: BackupPlus_BackupModule_googlecloud
 */
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}
require_once BACKUPPLUS_DIR . '/includes/class-remote-storage-module.php';
require_once __DIR__ . '/googlecloud/admin.php';
require_once __DIR__ . '/googlecloud/client.php';

class BackupPlus_BackupModule_googlecloud extends BackupPlus_Remote_Storage_Module {
	use BackupPlus_googlecloud_Admin;

	protected $fields       = array(
		'bucket'          => array( 'Cloud Storage bucket', '' ),
		'path'            => array( 'Folder prefix (optional)', '' ),
		'service_account' => array( 'Service account JSON key (recommended)', '', 'textarea' ),
		'clientid'        => array( 'OAuth client ID (alternative)', '' ),
		'secret'          => array( 'OAuth client secret', '', 'password' ),
		'token'           => array( 'OAuth refresh token', '', 'password' ),
	);
	protected $instructions = 'Use an existing bucket and a service account JSON key with Storage Object Admin access, or your own OAuth application and refresh token with devstorage.read_write scope.';
	public function get_supported_features() {
		return array_merge( parent::get_supported_features(), array( 'phpseclib' ) ); }
	public function options_exist( $options ) {
		return ! empty( $options['bucket'] ) && ( ! empty( $options['service_account'] ) || ( ! empty( $options['clientid'] ) && ! empty( $options['secret'] ) && ! empty( $options['token'] ) ) ); }
	protected function create_client( $options ) {
		return new BackupPlus_GoogleCloud_Client( $options ); }
	public function get_authentication_link( $echo_instead_of_return = true, $template_instead_of_notice = true ) {
		$text = __( 'Enter a service account JSON key or OAuth credentials in the Google Cloud destination settings, then test the connection.', 'backupplus' );
		if ( $echo_instead_of_return ) {
			echo esc_html( $text );
		} else {
			return esc_html( $text );
		}
	}
}
