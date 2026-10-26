<?php
/**
 * BackupPlus Provider: Dropbox
 * Provider ID: dropbox
 * Provider Class: BackupPlus_BackupModule_dropbox
 */
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fgets, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.WP.AlternativeFunctions.file_system_operations_fputs, WordPress.WP.AlternativeFunctions.file_system_operations_is_writeable, WordPress.WP.AlternativeFunctions.file_system_operations_chown, WordPress.WP.AlternativeFunctions.file_system_operations_chgrp, WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Native PHP fileystem function is used for direct control and performance because it can bypass additional layers of abstraction so that no overhead from the WordPress filesystem API's internal handling
/**
 * https://www.dropbox.com/developers/apply?cont=/developers/apps
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

// The provider's concerns live in traits in its own directory, methods/dropbox/.
require_once __DIR__ . '/dropbox/admin.php';
require_once __DIR__ . '/dropbox/transfers.php';
require_once __DIR__ . '/dropbox/listing-retention.php';
require_once __DIR__ . '/dropbox/client.php';
require_once __DIR__ . '/dropbox/oauth.php';
require_once __DIR__ . '/dropbox/settings-templates.php';

// Converted to multi-options (Feb 2017-) and previous options conversion removed: Yes

if ( ! class_exists( 'BackupPlus_BackupModule' ) ) {
	backupplus_try_include_file( 'methods/backup-module.php', 'require_once' );
}

// Fix a potential problem for users who had the short-lived 1.12.35-1.12.38 free versions (see: https://wordpress.org/support/topic/1-12-37-dropbox-auth-broken/page/2/#post-8981457)
// Can be removed after a few months
$potential_options = BackupPlus_Options::get_backupplus_option( 'backupplus_dropbox' );
if ( is_array( $potential_options ) && isset( $potential_options['version'] ) && isset( $potential_options['settings'] ) && array() === $potential_options['settings'] ) {
	// Wipe it, which will force its re-creation in proper format
	BackupPlus_Options::delete_backupplus_option( 'backupplus_dropbox' );
}

class BackupPlus_BackupModule_dropbox extends BackupPlus_BackupModule {
	use BackupPlus_Dropbox_Transfers;
	use BackupPlus_Dropbox_Listing_Retention;
	use BackupPlus_Dropbox_Client;
	use BackupPlus_Dropbox_OAuth;
	use BackupPlus_Dropbox_Settings_Templates;
	use BackupPlus_dropbox_Admin;

	/** Convert UpdraftPlus tokens only during an explicit configuration import. */
	public function normalize_imported_settings( $settings ) {
		if ( isset( $settings['updraft_dropbox'] ) && ! isset( $settings['backupplus_dropbox'] ) ) {
			$options = $settings['updraft_dropbox'];
			if ( is_array( $options ) && empty( $options['settings'] ) ) {
				$options = BackupPlus_Storage_Methods_Interface::wrap_remote_storage_options( $options );
			}
			$settings['updraft_dropbox'] = BackupPlus_Dropbox_Token_Migration::import_options( $options );
		}
		return $settings;
	}

	/** Repair files written by the historical Dropbox upload bug. */
	public function download_complete( $handle, $path ) {
		if ( filesize( $path ) < 4 ) {
			return;
		}
		fseek( $handle, -4, SEEK_END );
		if ( 'null' === fread( $handle, 4 ) ) {
			ftruncate( $handle, filesize( $path ) - 4 );
		}
	}

	private $current_file_hash;

	private $current_file_size;

	private $uploaded_offset;

	private $upload_tick;

	/**
	 * Input and option field mappings with default values and supported contexts.
	 *
	 * @var array
	 */
	protected $input_option_field_mappings = array(
		'appkey'          => array(
			'default_value' => '',
			'contexts'      => array( 'option' ),
		),
		'secret'          => array(
			'default_value' => '',
			'contexts'      => array( 'option' ),
		),
		'tk_access_token' => array(
			'default_value' => '',
			'contexts'      => array( 'option' ),
		),
		'folder'          => array(
			'default_value' => '',
			'contexts'      => array( 'option', 'input' ),
		),
	);

	/**
	 * Supported features
	 *
	 * @return Array
	 */
	public function get_supported_features() {
		// This options format is handled via only accessing options via $this->get_options()
		return array( 'phpseclib', 'multi_options', 'config_templates', 'multi_storage', 'conditional_logic', 'manual_authentication' );
	}

	/**
	 * Check whether options have been set up by the user, or not
	 *
	 * @param Array $opts - the potential options
	 *
	 * @return Boolean
	 */
	public function options_exist( $opts ) {
		if ( is_array( $opts ) && ! empty( $opts['tk_access_token'] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Identification of Dropbox app
	 *
	 * @return Array
	 */
	private function defaults() {
		return apply_filters( 'backupplus_dropbox_defaults', array( 'Z3Q3ZmkwbnplNHA0Zzlx', 'bTY0bm9iNmY4eWhjODRt' ) );
	}
}
