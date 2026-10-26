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
	 * A handler method to call the relevant remote storage manual authentication methods and return the authentication result
	 *
	 * @param array $data - an array of authentication data, normally includes the state and auth code
	 *
	 * @return array - an array response to be sent back to the frontend
	 */
	public function manual_remote_storage_authentication( $data ) {
		if ( false === ( $backupplus_admin = $this->_load_ud_admin() ) || false === ( $backupplus = $this->_load_ud() ) ) {
			return new WP_Error( 'no_backupplus' );// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Unused variable is for future use.
		}
		$response = array(
			'result' => 'success',
		);

		$method = $data['method'];

		$enabled_services = BackupPlus_Storage_Methods_Interface::get_enabled_storage_objects_and_ids( array( $method ) );

		if ( empty( $enabled_services[ $method ]['object'] ) || empty( $enabled_services[ $method ]['instance_settings'] ) || ! $enabled_services[ $method ]['object']->supports_feature( 'manual_authentication' ) ) {
			$response['result'] = 'error';
			$response['data']   = __( 'Manual authentication is not available for this remote storage method', 'backupplus' ) . '(' . $method . ')';
			return $response;
		}

		$backup_obj = $enabled_services[ $method ]['object'];

		$auth_data   = json_decode( base64_decode( $data['auth_data'] ), true );
		$instance_id = '';

		$state = isset( $auth_data['state'] ) ? urldecode( $auth_data['state'] ) : '';
		$code  = isset( $auth_data['code'] ) ? urldecode( $auth_data['code'] ) : '';

		if ( empty( $code ) && isset( $auth_data['access_token'] ) && isset( $auth_data['user_id'] ) ) {
			// If there is no code, but the access_token and user_id is set then this is for Google Drive so create a code array using these values
			$access_token = urldecode( $auth_data['access_token'] );
			$user_id      = urldecode( $auth_data['user_id'] );
			$code         = array(
				'access_token' => $access_token,
				'user_id'      => $user_id,
			);
		} elseif ( empty( $code ) && isset( $auth_data['token'] ) ) {
			// If there is no code, but a token is set then this is for OneDrive so assign token to code
			$encoded_token = stripslashes( $auth_data['token'] );
			$token         = json_decode( $encoded_token );
			$code          = $token;
		}

		if ( empty( $state ) || empty( $code ) ) {
			$response['result'] = 'error';
			$response['data']   = __( 'Missing authentication data:', 'backupplus' ) . " ({$state}) ({$code})";
			return $response;
		}

		if ( false !== strpos( $state, ':' ) ) {
			$parts       = explode( ':', $state );
			$instance_id = $parts[1];
		}

		if ( empty( $instance_id ) ) {
			$response['result'] = 'error';
			$response['data']   = __( 'Missing instance id:', 'backupplus' ) . " ($state)";
			return $response;
		}

		if ( isset( $enabled_services[ $method ]['instance_settings'][ $instance_id ] ) ) {
			$opts = $enabled_services[ $method ]['instance_settings'][ $instance_id ];
			$backup_obj->set_options( $opts, false, $instance_id );
		}

		$result = $backup_obj->complete_authentication( $state, $code, true );

		$response['data'] = $result;

		return $response;
	}
}
