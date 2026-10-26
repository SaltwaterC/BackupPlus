<?php

/** Saving, importing and wiping settings and authorising remote storage. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Admin_Settings_Storage {

	/**
	 * This method will setup the storage object and get the authentication link ready to be output with the notice
	 *
	 * @param  String $method - the remote storage method
	 */
	public function get_method_auth_link( $method ) {
		$storage_objects_and_ids = BackupPlus_Storage_Methods_Interface::get_storage_objects_and_ids( array( $method ) );

		if ( empty( $storage_objects_and_ids[ $method ]['object'] ) ) {
			return;
		}
		$object = $storage_objects_and_ids[ $method ]['object'];

		foreach ( $this->auth_instance_ids[ $method ] ?? array() as $instance_id ) {

			$object->set_instance_id( $instance_id );

			$this->show_admin_warning( '<strong>' . __( 'BackupPlus notice:', 'backupplus' ) . '</strong> ' . $object->get_authentication_link( false, false ), 'updated backupplus_authenticate_' . $method );
		}
	}

	/**
	 * This method handles the imported json settings it will convert them into a readable format for the existing save settings function, it will also update some of the options to match the new remote storage options format (Apr 2017)
	 *
	 * @param  Array $settings               - The settings from the imported json file
	 * @param  Bool  $return_instead_of_echo - Whether to return the result instead of echoing it
	 */
	public function import_settings( $settings, $return_instead_of_echo = false ) {
		global $backupplus;

		if ( ! isset( $settings['settings'] ) || ! is_string( $settings['settings'] ) ) {
			$posted_settings = array();
		} else {
			$posted_settings = BackupPlus_Settings_Importer::parse_settings_payload( $settings['settings'] );
		}
		if ( empty( $posted_settings ) || ! is_array( $posted_settings ) ) {
			$response = array(
				'saved'         => false,
				'error_message' => __( 'The export did not contain any settings to import.', 'backupplus' ),
			);
			if ( $return_instead_of_echo ) {
				return $response;
			}
			echo json_encode( $response );
			die;
		}

		$known_services     = array_keys( $backupplus->backup_methods );
		$available_services = array();
		foreach ( $known_services as $service ) {
			$storage_object = BackupPlus_Storage_Methods_Interface::get_storage_object( $service );
			if ( is_wp_error( $storage_object ) || is_a( $storage_object, 'BackupPlus_BackupModule_AddonNotYetPresent' ) ) {
				continue;
			}
			$posted_settings      = $storage_object->normalize_imported_settings( $posted_settings );
			$available_services[] = $service;
		}
		$normalized      = BackupPlus_Settings_Importer::normalize_legacy_settings( $posted_settings, $known_services, $backupplus->get_settings_keys(), $available_services );
		$posted_settings = $normalized['settings'];

		// Convert flat provider options to the structured option used by current modules.
		foreach ( $known_services as $service ) {
			if ( 'email' === $service ) {
				continue;
			}
			$backupplus_key = 'backupplus_' . $service;
			if ( ! isset( $posted_settings[ $backupplus_key ] ) ) {
				continue;
			}
			$storage_options = $posted_settings[ $backupplus_key ];
			if ( is_array( $storage_options ) && empty( $storage_options['settings'] ) ) {
				$storage_options = BackupPlus_Storage_Methods_Interface::wrap_remote_storage_options( $storage_options );
			}
			$posted_settings[ $backupplus_key ] = $storage_options;
		}

		if ( ! empty( $settings['backupplus_version'] ) ) {
			$posted_settings['backupplus_version'] = $settings['backupplus_version'];
		}

		$allowed_settings = array_flip( $backupplus->get_settings_keys() );
		$skipped_settings = $normalized['skipped_settings'];
		$metadata_keys    = array( 'backupplus_version', 'updraftplus_version', 'updraft_version' );
		foreach ( array_keys( $posted_settings ) as $key ) {
			if ( in_array( $key, $metadata_keys, true ) || isset( $allowed_settings[ $key ] ) ) {
				continue;
			}
			$skipped_settings[] = $key;
			unset( $posted_settings[ $key ] );
		}
		$skipped_settings = array_values( array_unique( $skipped_settings ) );

		$response = $this->save_settings( $posted_settings );
		if ( ! empty( $skipped_settings ) ) {
			$response['import_skipped_settings'] = $skipped_settings;
		}

		if ( $return_instead_of_echo ) {
			return $response;
		} else {
			echo json_encode( $response );
			die;
		}
	}

	/**
	 * This method works through the passed in settings array and saves the settings to the database clearing old data and setting up a return array with content to update the page via ajax
	 *
	 * @param  array $settings An array of settings taking from the admin page ready to be saved to the database
	 * @return array           An array response containing the status of the update along with content to be used to update the admin page.
	 */
	public function save_settings( $settings ) {

		global $backupplus;

		// Make sure that settings filters are registered
		BackupPlus_Options::admin_init();

		$more_files_path_updated = false;

		$version_matches = isset( $settings['backupplus_version'] ) && $backupplus->version == $settings['backupplus_version'];
		// Nothing is saved when the scheduling fields are invalid.
		$schedule_check = $version_matches ? BackupPlus_Backup_Schedule::instance()->validate( $settings ) : true;

		if ( is_wp_error( $schedule_check ) ) {
			$return_array = array(
				'saved'         => false,
				'error_message' => $schedule_check->get_error_message(),
			);
		} elseif ( $version_matches ) {

			$return_array = array( 'saved' => true );

			$add_to_post_keys = array( 'backupplus_interval', 'backupplus_interval_database', 'backupplus_interval_increments', 'backupplus_starttime_files', 'backupplus_starttime_db', 'backupplus_startday_files', 'backupplus_startday_db', 'backupplus_schedule_exact_files', 'backupplus_schedule_exact_db', 'backupplus_schedule_exact_increments', 'backupplus_starttime_increments', 'backupplus_retention_cleanup_mode', 'backupplus_retention_cleanup_time' );

			foreach ( $add_to_post_keys as $key ) {
				// For add-ons that look at $_POST to find saved settings, add the relevant keys to $_POST so that they find them there
				if ( isset( $settings[ $key ] ) ) {
					$_POST[ $key ] = $settings[ $key ];
				}
			}

			// Check if backupplus_include_more_path is set, if it is then we need to update the page, if it's not set but there's content already in the database that is cleared down below so again we should update the page.
			$more_files_path_updated = false;

			// i.e. If an option has been set, or if it was currently active in the settings
			if ( isset( $settings['backupplus_include_more_path'] ) || BackupPlus_Options::get_backupplus_option( 'backupplus_include_more_path' ) ) {
				$more_files_path_updated = true;
			}

			// Wipe the extra retention rules, as they are not saved correctly if the last one is deleted
			BackupPlus_Options::update_backupplus_option( 'backupplus_retain_extrarules', array() );
			BackupPlus_Options::update_backupplus_option( 'backupplus_email', array() );
			BackupPlus_Options::update_backupplus_option( 'backupplus_report_warningsonly', array() );
			BackupPlus_Options::update_backupplus_option( 'backupplus_report_wholebackup', array() );
			BackupPlus_Options::update_backupplus_option( 'backupplus_extradbs', array() );
			BackupPlus_Options::update_backupplus_option( 'backupplus_include_more_path', array() );

			$relevant_keys = $backupplus->get_settings_keys();

			if ( isset( $settings['backupplus_auto_updates'] ) && in_array( 'backupplus_auto_updates', $relevant_keys ) ) {
				$backupplus->set_automatic_updates( $settings['backupplus_auto_updates'] );
				unset( $settings['backupplus_auto_updates'] ); // unset the key and its value to prevent being processed the second time
			}

			if ( method_exists( 'BackupPlus_Options', 'mass_options_update' ) ) {
				$original_settings = $settings;
				$settings          = BackupPlus_Options::mass_options_update( $settings );
				$mass_updated      = true;
			}

			foreach ( $settings as $key => $value ) {

				if ( in_array( $key, $relevant_keys ) ) {
					if ( 'backupplus_service' == $key && is_array( $value ) ) {
						foreach ( $value as $subkey => $subvalue ) {
							if ( '0' == $subvalue ) {
								unset( $value[ $subkey ] );
							}
						}
					}

					// This flag indicates that either the stored database option was changed, or that the supplied option was changed before being stored. It isn't comprehensive - it's only used to update some UI elements with invalid input.
					$updated = empty( $mass_updated ) ? ( is_string( $value ) && BackupPlus_Options::get_backupplus_option( $key ) != $value ) : ( is_string( $value ) && ( ! isset( $original_settings[ $key ] ) || $original_settings[ $key ] != $value ) );

					if ( empty( $mass_updated ) ) {
						BackupPlus_Options::update_backupplus_option( $key, $value );
					}

					// Add information on what has changed to array to loop through to update links etc.
					// Restricting to strings for now, to prevent any unintended leakage (since this is just used for UI updating)
					if ( $updated ) {
						$value = BackupPlus_Options::get_backupplus_option( $key );
						if ( is_string( $value ) ) {
							$return_array['changed'][ $key ] = $value;
						}
					}
					// @codingStandardsIgnoreLine
				} else {
					// This section is ignored by CI otherwise it will complain the ELSE is empty.

					// When last active, it was catching: option_page, action, _wpnonce, _wp_http_referer, backupplus_s3_endpoint, backupplus_dreamobjects_endpoint. The latter two are empty; probably don't need to be in the page at all.
					// error_log("Non-UD key when saving from POSTed data: ".$key);
				}
			}

			// Schedule from the complete set of saved settings
			BackupPlus_Backup_Schedule::instance()->reconcile();
		} else {
			$return_array = array(
				'saved'         => false,
				'error_message' => sprintf(
					/* translators: %s: BackupPlus version */
					__( 'BackupPlus seems to have been updated to version (%s), which is different to the version running when this settings page was loaded.', 'backupplus' ),
					$backupplus->version
				) . ' ' . __( 'Please reload the settings page before trying to save settings.', 'backupplus' ),
			);
		}

		// Checking for various possible messages
		$backupplus_dir     = $backupplus->backups_dir_location( false );
		$really_is_writable = BackupPlus_Filesystem_Functions::really_is_writable( $backupplus_dir );
		$dir_info           = $this->really_writable_message( $really_is_writable, $backupplus_dir );
		$button_title       = esc_attr( __( 'This button is disabled because your backup directory is not writable (see the settings).', 'backupplus' ) );

		$return_array['backup_now_message'] = $this->backup_now_remote_message();

		$return_array['backup_dir'] = array(
			'writable'     => $really_is_writable,
			'message'      => $dir_info,
			'button_title' => $button_title,
		);

		// Check if $more_files_path_updated is true, is so then there's a change and we should update the backup modal
		if ( $more_files_path_updated ) {
			$return_array['backupplus_include_more_path'] = $this->files_selector_widgetry( 'backupnow_files_', false, 'sometimes' );
		}

		// Because of the single AJAX call, we need to remove the existing UD messages from the 'all_admin_notices' action
		remove_all_actions( 'all_admin_notices' );

		// Moving from 2 to 1 ajax call
		ob_start();

		$service = BackupPlus_Options::get_backupplus_option( 'backupplus_service' );

		$this->setup_all_admin_notices_global( $service );
		$this->setup_all_admin_notices_udonly( $service );

		do_action( 'all_admin_notices' );

		if ( ! $really_is_writable ) { // Check if writable
			$this->show_admin_warning_unwritable();
		}

		if ( $return_array['saved'] ) { //
			$this->show_admin_warning( __( 'Your settings have been saved.', 'backupplus' ), 'updated fade' );
		} else {
			if ( isset( $return_array['error_message'] ) ) {
				$this->show_admin_warning( $return_array['error_message'], 'error' );
			} else {
				$this->show_admin_warning( __( 'Your settings failed to save.', 'backupplus' ) . ' ' . __( 'Please refresh the settings page and try again', 'backupplus' ), 'error' );
			}
		}

		$messages_output = ob_get_contents();

		ob_clean();

		// Backup schedule output
		$this->next_scheduled_backups_output( 'line' );

		$scheduled_output = ob_get_clean();

		$return_array['messages']              = $messages_output;
		$return_array['scheduled']             = $scheduled_output;
		$return_array['files_scheduled']       = $this->next_scheduled_files_backups_output( true );
		$return_array['incremental_scheduled'] = $this->next_scheduled_incremental_backups_output( true );
		$return_array['database_scheduled']    = $this->next_scheduled_database_backups_output( true );
		$return_array['pitr_scheduled']        = $this->next_scheduled_pitr_backups_output( true );
		$return_array['cleanup_scheduled']     = BackupPlus_Retention_Cleanup::instance()->summary_html();

		// Add the updated options to the return message, so we can update on screen
		return $return_array;
	}

	/**
	 * Authenticate remote storage instance
	 *
	 * @param array - $data It consists of below key elements:
	 *                $remote_method - Remote storage service
	 *                $instance_id - Remote storage instance id
	 * @return array An array response containing the status of the authentication
	 */
	public function auth_remote_method( $data ) {
		global $backupplus;

		$response = array();

		if ( isset( $data['remote_method'] ) && isset( $data['instance_id'] ) ) {
			$response['result'] = 'success';
			$remote_method      = $data['remote_method'];
			$instance_id        = $data['instance_id'];

			$storage_objects_and_ids = BackupPlus_Storage_Methods_Interface::get_storage_objects_and_ids( array( $remote_method ) );

			try {
				if ( empty( $storage_objects_and_ids[ $remote_method ]['object'] ) ) {
					return array(
						'result'  => 'error',
						'message' => __( 'Remote storage provider is unavailable.', 'backupplus' ),
					);
				}
				$storage_objects_and_ids[ $remote_method ]['object']->authenticate_storage( $instance_id );
			} catch ( Exception $e ) {
				$response['result']  = 'error';
				$response['message'] = $backupplus->backup_methods[ $remote_method ] . ' ' . __( 'authentication error', 'backupplus' ) . ' ' . $e->getMessage();
			}
		} else {
			$response['result']  = 'error';
			$response['message'] = __( 'Remote storage method and instance id are required for authentication.', 'backupplus' );
		}

		return $response;
	}

	/**
	 * Deauthenticate remote storage instance
	 *
	 * @param array - $data It consists of below key elements:
	 *                $remote_method - Remote storage service
	 *                $instance_id - Remote storage instance id
	 * @return array An array response containing the status of the deauthentication
	 */
	public function deauth_remote_method( $data ) {
		global $backupplus;

		$response = array();

		if ( isset( $data['remote_method'] ) && isset( $data['instance_id'] ) ) {
			$response['result'] = 'success';
			$remote_method      = $data['remote_method'];
			$instance_id        = $data['instance_id'];

			$storage_objects_and_ids = BackupPlus_Storage_Methods_Interface::get_storage_objects_and_ids( array( $remote_method ) );

			try {
				if ( empty( $storage_objects_and_ids[ $remote_method ]['object'] ) ) {
					return array(
						'result'  => 'error',
						'message' => __( 'Remote storage provider is unavailable.', 'backupplus' ),
					);
				}
				$storage_objects_and_ids[ $remote_method ]['object']->deauthenticate_storage( $instance_id );
			} catch ( Exception $e ) {
				$response['result']  = 'error';
				$response['message'] = $backupplus->backup_methods[ $remote_method ] . ' deauthentication error ' . $e->getMessage();
			}
		} else {
			$response['result']  = 'error';
			$response['message'] = 'Remote storage method and instance id are required for deauthentication.';
		}

		return $response;
	}

	/**
	 * A method to remove BackupPlus settings from the options table.
	 *
	 * @return boolean
	 */
	public function wipe_settings() {

		global $backupplus;

		$settings = $backupplus->get_settings_keys();

		foreach ( $settings as $s ) {
			BackupPlus_Options::delete_backupplus_option( $s );
		}

		if ( is_multisite() ) {
			$backupplus->wipe_state_data( true, 'sitemeta' );
		}
		$backupplus->wipe_state_data( true );

		$site_options = array( 'backupplus_oneshotnonce' );
		foreach ( $site_options as $s ) {
			delete_site_option( $s );
		}

		BackupPlus_Backup_Schedule::instance()->reset();
		delete_option( BackupPlus_Retention_Cleanup::STATUS );
		delete_option( BackupPlus_Retention_Cleanup::FAILURES );

		$this->show_admin_warning( __( 'Your settings have been wiped.', 'backupplus' ) );

		return true;
	}
}
