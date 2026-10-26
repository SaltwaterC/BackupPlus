<?php

/** Advanced tools: HTTP checks, search and replace, and site information. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Commands_Tools {

	/**
	 * Get the Advanced Tools HTMl and return to Central
	 *
	 * @param  string $options Options for advanced settings
	 * @return string
	 */
	public function get_advanced_settings( $options ) {
		// load global backupplus and admin
		if ( false === ( $backupplus_admin = $this->_load_ud_admin() ) ) {
			return new WP_Error( 'no_backupplus' );
		}
		if ( false === ( $backupplus = $this->_load_ud() ) ) {
			return new WP_Error( 'no_backupplus' );// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Unused variable is for future use.
		}
		$html = $backupplus_admin->settings_advanced_tools( true, array( 'options' => $options ) );

		return $html;
	}

	/**
	 * This gets the http_get function from admin to grab information on a url
	 *
	 * @param  string $uri URL to be used
	 * @return array returns response from specific URL
	 */
	public function http_get( $uri ) {
		if ( false === ( $backupplus_admin = $this->_load_ud_admin() ) ) {
			return new WP_Error( 'no_backupplus' );
		}

		if ( empty( $uri ) ) {
			return new WP_Error( 'error', '', 'no_uri' );
		}

		$response        = $backupplus_admin->http_get( $uri, false );
		$response_decode = json_decode( $response );

		if ( isset( $response_decode->e ) ) {
			return new WP_Error( 'error', '', htmlspecialchars( $response_decode->e ) );
		}

		return array(
			'status'   => $response_decode->code,
			'response' => $response_decode->html_response,
		);
	}

	/**
	 * This gets the http_get function from admin to grab cURL information on a url
	 *
	 * @param  string $uri URL to be used
	 * @return array
	 */
	public function http_get_curl( $uri ) {
		if ( false === ( $backupplus_admin = $this->_load_ud_admin() ) ) {
			return new WP_Error( 'no_backupplus' );
		}

		if ( empty( $uri ) ) {
			return new WP_Error( 'error', '', 'no_uri' );
		}

		if ( ! function_exists( 'curl_exec' ) ) {
			return new WP_Error( 'error', '', 'no_curl' );
		}

		$response_encode = $backupplus_admin->http_get( $uri, true );
		$response_decode = json_decode( $response_encode );

		$response = 'Curl Info: ' . $response_decode->verb
					. 'Response: ' . $response_decode->response;

		if ( false === $response_decode->response ) {
			return new WP_Error(
				'error',
				'',
				array(
					'error'  => htmlspecialchars( $response_decode->e ),
					'status' => $response_decode->status,
					'log'    => htmlspecialchars( $response_decode->verb ),
				)
			);
		}

		return array(
			'response' => htmlspecialchars( substr( $response, 0, 2048 ) ),
			'status'   => $response_decode->status,
			'log'      => htmlspecialchars( $response_decode->verb ),
		);
	}

	public function search_replace( $query ) {

		if ( ! BackupPlus_Options::user_can_manage() ) {
			return new WP_Error( 'backupplus_permission_denied' );
		}

		$_POST = $query;

		ob_start();

		do_action( 'backupplus_adminaction_searchreplace', $query );

		$response = array( 'log' => ob_get_clean() );

		return $response;
	}

	/**
	 * This function is for updating site information
	 *
	 * @param array $params Parameters containing site info to update
	 * @return array|WP_Error Response or error
	 */
	public function update_site_info( $params ) {
		if ( false === $this->_load_ud_admin() ) {
			return new WP_Error( 'no_backupplus' );
		}

		if ( ! BackupPlus_Options::user_can_manage() ) {
			return new WP_Error( 'backupplus_permission_denied' );
		}

		// Update site title
		if ( isset( $params['site_title'] ) ) {
			$site_title = sanitize_text_field( $params['site_title'] );
			update_option( 'blogname', $site_title );
		}

		// Update tagline
		if ( isset( $params['tagline'] ) ) {
			$tagline = sanitize_text_field( $params['tagline'] );
			update_option( 'blogdescription', $tagline );
		}

		// Update admin email
		if ( isset( $params['admin_email'] ) ) {
			$admin_email = sanitize_email( $params['admin_email'] );

			// Validate email format
			if ( ! is_email( $admin_email ) ) {
				return new WP_Error( 'invalid_email', __( 'Invalid email address format', 'backupplus' ) );
			}

			update_option( 'admin_email', $admin_email );
		}

		return array(
			'message' => __( 'Site information updated successfully', 'backupplus' ),
		);
	}

	/**
	 * Get advanced tools data in a structured format
	 *
	 * @param array $param The form data.
	 *
	 * @return array|WP_Error Structured data or error
	 */
	public function get_structured_data( $param ) {
		if ( false === ( $backupplus_admin = $this->_load_ud_admin() ) ) {
			return new WP_Error( 'no_backupplus' );
		}

		$tool_type = $param['tool_type'];

		// Site Info
		if ( 'site-information' === $tool_type ) {
			$site_info                = $backupplus_admin->get_site_info_data();
			$site_info['site_title']  = get_bloginfo( 'name' );
			$site_info['tagline']     = get_bloginfo( 'description' );
			$site_info['admin_email'] = get_bloginfo( 'admin_email' );

			return array( 'site_info' => $site_info );
		}

		// Directory Sizes
		if ( 'site-size' === $tool_type ) {
			$site_size = array();
			if ( false !== ( $backupplus = $this->_load_ud() ) ) {
				$backupable_entities = $backupplus->get_backupable_file_entities( true, true );
				foreach ( $backupable_entities as $entity => $info ) {
					$size = BackupPlus_Filesystem_Functions::get_disk_space_used( $entity, 'numeric' );

					$size                 = is_numeric( $size ) ? $size : 0;
					$site_size[ $entity ] = array(
						'size'           => $size,
						'size_formatted' => size_format( $size ),
					);
				}
			}

			return array( 'site_size' => $site_size );
		}

		// Database Size Information
		if ( 'db-size' === $tool_type ) {
			backupplus_try_include_file( 'includes/class-wpadmin-commands.php', 'include_once' );

			$db_size = array( 'size' => '0 B' );
			if ( class_exists( 'BackupPlus_WPAdmin_Commands' ) ) {
				$wpadmin_commands = new BackupPlus_WPAdmin_Commands( $this->_uc_helper );
				$db_size_result   = $wpadmin_commands->db_size( true );
				if ( ! is_wp_error( $db_size_result ) ) {
					$db_size = $db_size_result;
				}
			}

			return array( 'db_size' => $db_size );
		}

		return new WP_Error( 'invalid_tool_type', __( 'Invalid tool type', 'backupplus' ) );
	}
}
