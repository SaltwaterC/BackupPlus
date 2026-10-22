<?php
/**
 * BackupPlus Provider: Microsoft OneDrive
 * Provider ID: onedrive
 * Provider Class: BackupPlus_BackupModule_onedrive
 */
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}
require_once BACKUPPLUS_DIR . '/includes/class-remote-storage-module.php';
require_once __DIR__ . '/onedrive/admin.php';
require_once __DIR__ . '/onedrive/client.php';
require_once __DIR__ . '/onedrive/oauth.php';

class BackupPlus_BackupModule_onedrive extends BackupPlus_Remote_Storage_Module {
	use BackupPlus_onedrive_Admin;
	use BackupPlus_OneDrive_OAuth;

	protected $fields       = array(
		'client_id'     => array( 'Microsoft application (client) ID', '' ),
		'client_secret' => array( 'Application client secret', '', 'password' ),
		'tenant'        => array( 'Tenant ID or common', 'common' ),
		'refresh_token' => array( 'Refresh token (or connect after saving)', '', 'password' ),
		'driveid'       => array( 'Drive ID (optional; default: your OneDrive)', '' ),
		'path'          => array( 'Existing folder path (empty for root)', 'BackupPlus' ),
	);
	protected $instructions = 'Register your own Microsoft web application with delegated Files.ReadWrite and offline_access permissions. Save the application settings, then connect your account. The destination folder must exist.';
	public function options_exist( $options ) {
		return ! empty( $options['client_id'] ) && ! empty( $options['client_secret'] ) && ! empty( $options['refresh_token'] ); }
	protected function create_client( $options ) {
		return new BackupPlus_OneDrive_Client(
			$options,
			null,
			function ( $token, $original ) {
				$current = BackupPlus_Options::get_backupplus_option( 'backupplus_onedrive', array() );
				$id      = $this->get_instance_id();
				$saved   = $current['settings'][ $id ] ?? array();
				foreach ( array( 'client_id', 'client_secret', 'tenant', 'refresh_token' ) as $key ) {
					if ( ( $saved[ $key ] ?? '' ) !== ( $original[ $key ] ?? '' ) ) {
						return;
					}
				}
				$current['settings'][ $id ]['refresh_token'] = $token;
				BackupPlus_Options::update_backupplus_option( 'backupplus_onedrive', $current );
				$verified = BackupPlus_Options::get_backupplus_option( 'backupplus_onedrive', array() );
				if ( ( $verified['settings'][ $id ]['refresh_token'] ?? '' ) !== $token ) {
					throw new RuntimeException( 'Cannot save the renewed OneDrive refresh token.' );
				}
			}
		);
	}
	public function get_configuration_template() {
		return parent::get_configuration_template() . '<tr class="{{get_template_css_classes true}}"><td colspan="2">' . $this->get_authentication_link( false ) . '</td></tr>';
	}
}
