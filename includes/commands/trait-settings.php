<?php

/** Reading, saving and importing settings, the settings page fragments and dismissible admin warnings. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Commands_Settings {

	public function get_settings( $options ) {
		global $backupplus;
		if ( false === ( $backupplus_admin = $this->_load_ud_admin() ) || false === ( $backupplus = $this->_load_ud() ) ) {
			return new WP_Error( 'no_backupplus' );
		}

		if ( ! BackupPlus_Options::user_can_manage() ) {
			return new WP_Error( 'backupplus_permission_denied' );
		}

		ob_start();
		$backupplus_admin->settings_formcontents( $options );
		$output = ob_get_contents();
		ob_end_clean();

		$remote_storage_options_and_templates = BackupPlus_Storage_Methods_Interface::get_remote_storage_options_and_templates();

		return array(
			'settings'                         => $output,
			'remote_storage_options'           => $remote_storage_options_and_templates['options'],
			'remote_storage_templates'         => $remote_storage_options_and_templates['templates'],
			'remote_storage_partial_templates' => $remote_storage_options_and_templates['partial_templates'],
			'meta'                             => apply_filters( 'backupplus_get_settings_meta', array() ),
			'backupplus_version'               => $backupplus->version,
			'backup_methods'                   => $backupplus->backup_methods,
			'active_instances'                 => $backupplus_admin->get_active_remote_storages(),
		);
	}

	/**
	 * Perform a connection test on a database
	 *
	 * @param Array $info - test parameters
	 *
	 * @return Array - test results
	 */
	public function extradb_testconnection( $info ) {

		if ( false === ( $backupplus_admin = $this->_load_ud_admin() ) || false === ( $backupplus = $this->_load_ud() ) ) {
			return new WP_Error( 'no_backupplus' );// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Unused variable is for future use.
		}
		if ( ! BackupPlus_Options::user_can_manage() ) {
			return new WP_Error( 'backupplus_permission_denied' );
		}

		$results = apply_filters( 'backupplus_extradb_testconnection_go', array(), $info );

		return $results;
	}

	/**
	 * A handler method to call the BackupPlus admin save settings method. It will check if the settings passed to it are in the format of a string if so it converts it to an array otherwise just pass the array
	 *
		 * @param  String/Array $settings Settings to be saved to BackupPlus either in the form of a string ready to be converted to an array or already an array ready to be passed to the settings handler.
	 * @return Array An Array response to be sent back
	 */
	public function save_settings( $settings ) {

		if ( false === ( $backupplus_admin = $this->_load_ud_admin() ) || false === ( $backupplus = $this->_load_ud() ) ) {
			return new WP_Error( 'no_backupplus' );// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Unused variable is for future use.
		}
		if ( ! BackupPlus_Options::user_can_manage() ) {
			return new WP_Error( 'backupplus_permission_denied' );
		}

		if ( ! empty( $settings ) ) {

			if ( is_string( $settings ) ) {
				parse_str( $settings, $settings_as_array );
			} elseif ( is_array( $settings ) ) {
				$settings_as_array = $settings;
			} else {
				return new WP_Error( 'invalid_settings' );
			}
		}

		$results = $backupplus_admin->save_settings( $settings_as_array );

		return $results;
	}

	/**
	 * Get an HTML fragment
	 *
	 * @param String|Array $fragment - what fragment to fetch. If an array, the fragment identifier is in 'fragment' (and 'data' is associated data)
	 *
	 * @return Array|WP_Error
	 */
	public function get_fragment( $fragment ) {

		if ( false === ( $backupplus_admin = $this->_load_ud_admin() ) || false === ( $backupplus = $this->_load_ud() ) ) {
			return new WP_Error( 'no_backupplus' );
		}

		if ( ! BackupPlus_Options::user_can_manage() ) {
			return new WP_Error( 'backupplus_permission_denied' );
		}

		if ( is_array( $fragment ) ) {
			$data     = $fragment['data'];
			$fragment = $fragment['fragment'];
		}

		$error = false;

		switch ( $fragment ) {

			case 'last_backup_html':
					$output = $backupplus_admin->last_backup_html();
				break;

			case 's3_new_api_user_form':
				ob_start();
				do_action( 'backupplus_s3_print_new_api_user_form', false );
				$output = ob_get_contents();
				ob_end_clean();
				break;

			case 'cloudfiles_new_api_user_form':
				global $backupplus_addon_cloudfilesenhanced;
				if ( ! is_a( $backupplus_addon_cloudfilesenhanced, 'BackupPlus_Addon_CloudFilesEnhanced' ) ) {
						$error  = true;
						$output = 'cloudfiles_addon_not_found';
				} else {
					$output = array(
						'accounts' => $backupplus_addon_cloudfilesenhanced->account_options(),
						'regions'  => $backupplus_addon_cloudfilesenhanced->region_options(),
					);
				}
				break;

			case 'backupnow_modal_contents':
				$backupplus_dir = $backupplus->backups_dir_location();
				if ( ! BackupPlus_Filesystem_Functions::really_is_writable( $backupplus_dir ) ) {
					$output = array(
						'error' => true,
						'html'  => __( "The 'Backup Now' button is disabled as your backup directory is not writable (go to the 'Settings' tab and find the relevant option).", 'backupplus' ),
					);
				} else {
					$output = array(
						'html'                    => $backupplus_admin->backupnow_modal_contents(),
						'backupnow_file_entities' => apply_filters( 'backupplus_backupnow_file_entities', array() ),
						'incremental_installed'   => apply_filters( 'backupplus_incremental_addon_installed', false ),
					);
				}
				break;

			case 'panel_download_and_restore':
				$backup_history = BackupPlus_Backup_History::get_history();
				$output         = $backupplus_admin->settings_downloading_and_restoring( $backup_history, true, $data );
				break;

			case 'disk_usage':
				$output = BackupPlus_Filesystem_Functions::get_disk_space_used( $data );
				break;
			default:
				// We just return a code - translation is done on the other side
				$output = 'ud_get_fragment_could_not_return';
				$error  = true;
				break;
		}

		if ( ! $error ) {
			return array(
				'output' => $output,
			);
		} else {
			return new WP_Error( 'get_fragment_error', '', $output );
		}
	}

	/**
	 * This function will add backupplus_dismiss_admin_warning_litespeed option to hide litespeed admin warning after dismissed
	 *
	 * @return array - an empty array
	 */
	public function dismiss_admin_warning_litespeed() {
		BackupPlus_Options::update_backupplus_option( 'backupplus_dismiss_admin_warning_litespeed', true );
		return array();
	}

	/**
	 * This function will add backupplus_dismiss_admin_warning_pclzip option to hide pclzip admin warning after dismissed
	 *
	 * @return array - an empty array
	 */
	public function dismiss_admin_warning_pclzip() {
		BackupPlus_Options::update_backupplus_option( 'backupplus_dismiss_admin_warning_pclzip', true );
		return array();
	}

	/**
	 * This function is for importing settings via RPC
	 *
	 * @param  Array $settings - The settings data to be imported
	 * @return Array An Array response to be sent back
	 */
	public function import_settings( $settings ) {
		if ( false === ( $backupplus_admin = $this->_load_ud_admin() ) || false === ( $backupplus = $this->_load_ud() ) ) {
			return new WP_Error( 'no_backupplus' );
		}

		if ( ! BackupPlus_Options::user_can_manage() ) {
			return new WP_Error( 'backupplus_permission_denied' );
		}

		if ( empty( $settings ) || ! is_array( $settings ) ) {
			return new WP_Error( 'invalid_settings', 'Invalid settings data provided' );
		}

		try {
			$result = $backupplus_admin->import_settings( $settings, true );

			if ( is_array( $result ) ) {
				if ( isset( $result['saved'] ) && ! $result['saved'] && ! empty( $result['error_message'] ) ) {
					return new WP_Error( 'import_failed', $result['error_message'] );
				}

				return $result;
			} else {
				return new WP_Error( 'unexpected_response', 'Unexpected response format from import_settings' );
			}
		} catch ( Exception $e ) {
			return new WP_Error( 'import_failed', $e->getMessage() );
		}
	}
}
