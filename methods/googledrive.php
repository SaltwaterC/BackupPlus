<?php
/**
 * BackupPlus Provider: Google Drive
 * Provider ID: googledrive
 * Provider Class: BackupPlus_BackupModule_googledrive
 */
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fgets, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.WP.AlternativeFunctions.file_system_operations_fputs, WordPress.WP.AlternativeFunctions.file_system_operations_is_writeable, WordPress.WP.AlternativeFunctions.file_system_operations_chown, WordPress.WP.AlternativeFunctions.file_system_operations_chgrp, WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Native PHP fileystem function is used for direct control and performance because it can bypass additional layers of abstraction so that no overhead from the WordPress filesystem API's internal handling
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

// The provider's concerns live in traits in its own directory, methods/googledrive/.
require_once __DIR__ . '/googledrive/admin.php';
require_once __DIR__ . '/googledrive/oauth.php';
require_once __DIR__ . '/googledrive/client.php';
require_once __DIR__ . '/googledrive/listing-retention.php';
require_once __DIR__ . '/googledrive/transfers.php';
require_once __DIR__ . '/googledrive/settings-templates.php';

// Converted to multi-options (Feb 2017-) and previous options conversion removed: Yes

if ( ! class_exists( 'BackupPlus_BackupModule' ) ) {
	backupplus_try_include_file( 'methods/backup-module.php', 'require_once' );
}

class BackupPlus_BackupModule_googledrive extends BackupPlus_BackupModule {
	use BackupPlus_GoogleDrive_OAuth;
	use BackupPlus_GoogleDrive_Client;
	use BackupPlus_GoogleDrive_Listing_Retention;
	use BackupPlus_GoogleDrive_Transfers;
	use BackupPlus_GoogleDrive_Settings_Templates;
	use BackupPlus_googledrive_Admin;

	private $client;

	private $ids_from_paths = array();

	private $client_id;

	private $callback_url;

	private $multi_directories = array();

	private $registered_prune = array();

	/**
	 * Input and option field mappings with default values and supported contexts.
	 *
	 * "parentid" is deprecated since April 2014; it should not be in the default options
	 * (its presence is used to detect an upgraded-from-previous-SDK situation).
	 * For the same reason, 'folder' is also unset;
	 * which enables us to know whether new-style settings have ever been set.
	 *
	 * @var array
	 */
	protected $input_option_field_mappings = array(
		'clientid' => array(
			'default_value' => '',
			'contexts'      => array( 'option' ),
		),
		'secret'   => array(
			'default_value' => '',
			'contexts'      => array( 'option' ),
		),
		'token'    => array(
			'default_value' => '',
			'contexts'      => array( 'option' ),
		),
		'folder'   => array(
			'default_value' => 'BackupPlus',
			'contexts'      => array( 'input' ),
		),
	);

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->client_id    = defined( 'BACKUPPLUS_GOOGLEDRIVE_CLIENT_ID' ) ? BACKUPPLUS_GOOGLEDRIVE_CLIENT_ID : '916618189494-u3ehb1fl7u3meb63nb2b4fqi0r9pcfe2.apps.googleusercontent.com';
		$this->callback_url = defined( 'BACKUPPLUS_GOOGLEDRIVE_CALLBACK_URL' ) ? BACKUPPLUS_GOOGLEDRIVE_CALLBACK_URL : 'https://auth.backupplusplus.com/auth/googledrive';
	}

	/**
	 * This method overrides the parent method and lists the supported features of this remote storage option.
	 *
	 * @return Array - an array of supported features (any features not mentioned are asuumed to not be supported)
	 */
	public function get_supported_features() {
		// This options format is handled via only accessing options via $this->get_options()
		return array( 'multi_options', 'config_templates', 'multi_storage', 'conditional_logic', 'manual_authentication' );
	}

	/**
	 * Check whether options have been set up by the user, or not
	 *
	 * @param Array $opts - the potential options
	 *
	 * @return Boolean
	 */
	public function options_exist( $opts ) {
		if ( is_array( $opts ) && ( ! empty( $opts['user_id'] ) || ! empty( $opts['token'] ) ) ) {
			return true;
		}
		return false;
	}
}
