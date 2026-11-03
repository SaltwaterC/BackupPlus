<?php

/** Remote storage: connection tests, creating service users and authorisation. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Commands_Remote_Storage {

	/**
	 * Run a credentials test
	 *
	 * @param Array $test_data - test configuration
	 *
	 * @return WP_Error|Array - test results (keys: results, (optional)data), or an error
	 */
	public function test_storage_settings( $test_data ) {

		if ( false === ( $backupplus_admin = $this->_load_ud_admin() ) || false === ( $backupplus = $this->_load_ud() ) ) {
			return new WP_Error( 'no_backupplus' );// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Unused variable is for future use.
		}
		if ( ! BackupPlus_Options::user_can_manage() ) {
			return new WP_Error( 'backupplus_permission_denied' );
		}

		$results = $backupplus_admin->do_credentials_test( $test_data, true );

		return $results;
	}

	public function s3_newuser( $data ) {
		if ( false === ( $backupplus_admin = $this->_load_ud_admin() ) || false === ( $backupplus = $this->_load_ud() ) ) {
			return new WP_Error( 'no_backupplus' );// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Unused variable is for future use.
		}
		if ( ! BackupPlus_Options::user_can_manage() ) {
			return new WP_Error( 'backupplus_permission_denied' );
		}
		$results = apply_filters( 'backupplus_s3_newuser_go', array(), $data );

		return $results;
	}

	public function cloudfiles_newuser( $data ) {

		global $backupplus_addon_cloudfilesenhanced;
		if ( ! is_a( $backupplus_addon_cloudfilesenhanced, 'BackupPlus_Addon_CloudFilesEnhanced' ) ) {
			// translators: %s: The name of the missing add-on.
			$data = array(
				'e' => 1,
				'm' => sprintf( __( '%s add-on not found', 'backupplus' ), 'Rackspace Cloud Files' ),
			);
		} else {
			$data = $backupplus_addon_cloudfilesenhanced->create_api_user( $data );
		}

		if ( 0 === $data['e'] ) {
			return $data;
		} else {
			return new WP_Error( 'error', '', $data );
		}
	}

	/**
	 * A handler method to call the BackupPlus admin auth_remote_method
	 *
	 * @param Array - $data It consists of below key elements:
	 *                $remote_method - Remote storage service
	 *                $instance_id - Remote storage instance id
	 * @return Array An Array response to be sent back
	 */
	public function auth_remote_method( $data ) {
		if ( false === ( $backupplus_admin = $this->_load_ud_admin() ) || false === ( $backupplus = $this->_load_ud() ) ) {
			return new WP_Error( 'no_backupplus' );// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Unused variable is for future use.
		}       if ( ! BackupPlus_Options::user_can_manage() ) {
			return new WP_Error( 'backupplus_permission_denied' );
		}
		$response = $backupplus_admin->auth_remote_method( $data );
		return $response;
	}

	/**
	 * A handler method to call the BackupPlus admin deauth_remote_method
	 *
	 * @param Array - $data It consists of below key elements:
	 *                $remote_method - Remote storage service
	 *                $instance_id - Remote storage instance id
	 * @return Array An Array response to be sent back
	 */
	public function deauth_remote_method( $data ) {
		if ( false === ( $backupplus_admin = $this->_load_ud_admin() ) || false === ( $backupplus = $this->_load_ud() ) ) {
			return new WP_Error( 'no_backupplus' );// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Unused variable is for future use.
		}       if ( ! BackupPlus_Options::user_can_manage() ) {
			return new WP_Error( 'backupplus_permission_denied' );
		}
		$response = $backupplus_admin->deauth_remote_method( $data );
		return $response;
	}

	/**
	 * Complete a paste-code authorization: the administrator copied the code the provider displayed into the settings page.
	 *
	 * @param array $data - method, instance_id and code
	 *
	 * @return array - an array response to be sent back to the frontend
	 */
	public function manual_remote_storage_authentication( $data ) {
		$method      = isset( $data['method'] ) && is_string( $data['method'] ) ? $data['method'] : '';
		$instance_id = isset( $data['instance_id'] ) && is_string( $data['instance_id'] ) ? $data['instance_id'] : '';
		$code        = isset( $data['code'] ) && is_string( $data['code'] ) ? trim( $data['code'] ) : '';

		$enabled_services = '' === $method ? array() : BackupPlus_Storage_Methods_Interface::get_enabled_storage_objects_and_ids( array( $method ) );
		if ( empty( $enabled_services[ $method ]['object'] ) || ! $enabled_services[ $method ]['object']->supports_feature( 'manual_authentication' ) ) {
			return array(
				'result' => 'error',
				'data'   => __( 'This remote storage method does not take an authorization code.', 'backupplus' ),
			);
		}
		if ( '' === $instance_id || ! isset( $enabled_services[ $method ]['instance_settings'][ $instance_id ] ) ) {
			return array(
				'result' => 'error',
				'data'   => __( 'That remote storage connection no longer exists. Reload the settings page.', 'backupplus' ),
			);
		}
		if ( '' === $code ) {
			return array(
				'result' => 'error',
				'data'   => __( 'Paste the authorization code first.', 'backupplus' ),
			);
		}

		$backup_obj = $enabled_services[ $method ]['object'];
		$backup_obj->set_options( $enabled_services[ $method ]['instance_settings'][ $instance_id ], false, $instance_id );
		try {
			$result = $backup_obj->complete_authentication( $code );
		} catch ( Exception $e ) {
			return array(
				'result' => 'error',
				'data'   => esc_html( $e->getMessage() ),
			);
		}
		return array(
			'result' => 'success',
			'data'   => $result,
		);
	}
}
