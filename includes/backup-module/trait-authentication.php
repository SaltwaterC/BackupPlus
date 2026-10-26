<?php

/** Authorising and deauthorising the provider account (OAuth links, manual authorisation, account warning). */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_BackupModule_Authentication {

	/**
	 * This method will either return or echo the constructed auth link for the remote storage method
	 *
	 * @param Boolean $echo_instead_of_return     - a boolean to indicate if the authentication link should be echo or returned
	 * @param Boolean $template_instead_of_notice - a boolean to indicate if the authentication link is for a template or a notice
	 * @return Void|String                        - returns a string or nothing depending on the parameters
	 */
	public function get_authentication_link( $echo_instead_of_return = true, $template_instead_of_notice = true ) {
		if ( ! $echo_instead_of_return ) {
			ob_start();
		}

		$account_warning = '';
		$description     = $this->get_description();

		if ( $this->output_account_warning() ) {
			$account_warning = __( 'Ensure you are logged into the correct account before continuing.', 'backupplus' );
		}

		if ( $template_instead_of_notice ) {
			$instance_id = '{{instance_id}}';
			/* translators: %s: Description */
			$text = sprintf( __( "<strong>After</strong> you have saved your settings (by clicking 'Save Changes' below), then come back here and follow this link to complete authentication with %s.", 'backupplus' ), $description );
		} else {
			$instance_id = $this->get_instance_id();
			/* translators: 1: Description or remote storage name (e.g. Google Drive, OneDrive, etc.), 2: Description or remote storage name */
			$text = sprintf( __( 'Follow this link to authorize access to your %1$s account (you will not be able to backup to %2$s without it).', 'backupplus' ), $description, $description );
		}

		echo esc_html( $account_warning ) . ' ' . wp_kses_post( $this->build_authentication_link( $instance_id, $text ) );

		if ( ! $echo_instead_of_return ) {
			return ob_get_clean();
		}
	}

	/**
	 * This function will build and return the authentication link
	 *
	 * @param String $instance_id - the instance id
	 * @param String $text        - the link text
	 *
	 * @return String - the authentication link
	 */
	public function build_authentication_link( $instance_id, $text ) {

		$id = $this->get_id();

		if ( '{{instance_id}}' !== $instance_id && ! preg_match( '/^[-A-Z0-9]+$/i', $instance_id ) ) {
			return '';
		}

		return '<a class="backupplus_authlink" href="' . BackupPlus_Options::admin_page_url() . '?&action=backupplusmethod-' . $id . '-auth&page=backupplus&backupplus_' . $id . 'auth=doit&nonce=' . wp_create_nonce( 'storage_auth_nonce' ) . '&backupplus_instance=' . $instance_id . '" data-instance_id="' . $instance_id . '" data-remote_method="' . $id . '">' . $text . '</a>';
	}

	/**
	 * Check the authentication is valid before proceeding to call the authentication method
	 */
	public function action_authenticate_storage() {
		$backupplus_auth     = BackupPlus_Manipulation_Functions::fetch_superglobal( 'get', 'BackupPlus_' . $this->get_id() . 'auth' );
		$backupplus_instance = BackupPlus_Manipulation_Functions::fetch_superglobal( 'get', 'backupplus_instance' );
		$nonce               = BackupPlus_Manipulation_Functions::fetch_superglobal( 'get', 'nonce' );

		if ( isset( $backupplus_auth ) && 'doit' == $backupplus_auth && ! empty( $backupplus_instance ) && preg_match( '/^[-A-Z0-9]+$/i', $backupplus_instance ) && isset( $nonce ) && wp_verify_nonce( $nonce, 'storage_auth_nonce' ) ) {
			$this->set_connection_status( false );
			$this->authenticate_storage( (string) $backupplus_instance );
		}
	}

	/**
	 * Authenticate the remote storage and save settings
	 *
	 * @param String $instance_id - The remote storage instance id
	 */
	public function authenticate_storage( $instance_id ) {
		if ( method_exists( $this, 'do_authenticate_storage' ) ) {
			$this->do_authenticate_storage( $instance_id );
		} else {
			BackupPlus_Manipulation_Functions::error_log( $this->get_id() . ': module does not have an authenticate storage method (coding bug)' );
		}
	}

	/**
	 * This method will either return or echo the constructed deauth link for the remote storage method
	 *
	 * @param  Boolean $echo_instead_of_return - a boolean to indicate if the deauthentication link should be echo or returned
	 * @return Void|String                     - returns a string or nothing depending on the parameters
	 */
	public function get_deauthentication_link( $echo_instead_of_return = true ) {
		if ( ! $echo_instead_of_return ) {
			ob_start();
		}

		$id          = $this->get_id();
		$description = $this->get_description();

		/* translators: %s: Description */
		echo ' <a class="backupplus_deauthlink" href="' . esc_url( BackupPlus_Options::admin_page_url() . '?action=backupplusmethod-' . $id . '-auth&page=backupplus&backupplus_' . $id . 'auth=deauth&nonce=' . wp_create_nonce( $id . '_deauth_nonce' ) . '&backupplus_instance={{instance_id}}' ) . '" data-instance_id="{{instance_id}}" data-remote_method="' . esc_attr( $id ) . '">' . esc_html( sprintf( __( 'Follow this link to remove these settings for %s.', 'backupplus' ), $description ) ) . '</a>';

		if ( ! $echo_instead_of_return ) {
			return ob_get_clean();
		}
	}

	/**
	 * Check the deauthentication is valid before proceeding to call the deauthentication method
	 */
	public function action_deauthenticate_storage() {
		$backupplus_auth     = BackupPlus_Manipulation_Functions::fetch_superglobal( 'get', 'BackupPlus_' . $this->get_id() . 'auth' );
		$backupplus_instance = BackupPlus_Manipulation_Functions::fetch_superglobal( 'get', 'backupplus_instance' );
		$nonce               = BackupPlus_Manipulation_Functions::fetch_superglobal( 'get', 'nonce' );

		if ( isset( $backupplus_auth ) && 'deauth' == $backupplus_auth && ! empty( $nonce ) && ! empty( $backupplus_instance ) && preg_match( '/^[-A-Z0-9]+$/i', $backupplus_instance ) && wp_verify_nonce( $nonce, $this->get_id() . '_deauth_nonce' ) ) {
			$this->deauthenticate_storage( $backupplus_instance );
		}
	}

	/**
	 * Deauthenticate the remote storage and remove the saved settings
	 *
	 * @param String $instance_id - The remote storage instance id
	 */
	public function deauthenticate_storage( $instance_id ) {
		if ( method_exists( $this, 'do_deauthenticate_storage' ) ) {
			$this->do_deauthenticate_storage( $instance_id );
		}
		$opts = $this->get_default_options();
		$this->set_options( $opts, true, $instance_id );
	}

	/**
	 * Get the manual authorisation template
	 *
	 * @return String - the template
	 */
	public function get_manual_authorisation_template() {

		$id          = $this->get_id();
		$description = $this->get_description();

		$template = "<div id='backupplus_manual_authorisation_template_{$id}'>";
		/* translators: %s: Description */
		$template .= '<strong>' . sprintf( __( '%s authentication:', 'backupplus' ), $description ) . '</strong>';
		/* translators: %s: Description */
		$template .= '<p>' . sprintf( __( 'If you are having problems authenticating with %s you can manually authorize here.', 'backupplus' ), $description ) . '</p>';
		$template .= '<p>' . __( 'To complete manual authentication, at the orange BackupPlus authentication screen select the "Having problems authenticating?" link, then copy and paste the code given here.', 'backupplus' ) . '</p>';
		/* translators: %s: Description */
		$template .= "<label for='backupplus_manual_authentication_data_{$id}'>" . sprintf( __( '%s authentication code:', 'backupplus' ), $description ) . "</label> <input type='text' id='backupplus_manual_authentication_data_{$id}' name='backupplus_manual_authentication_data_{$id}'>";
		$template .= "<p id='backupplus_manual_authentication_error_{$id}'></p>";
		$template .= "<button type='button' data-method='{$id}' class='button button-primary' id='backupplus_manual_authorisation_submit_{$id}'>" . __( 'Complete manual authentication', 'backupplus' ) . '</button>';
		$template .= '<span class="backupplus_spinner spinner">' . __( 'Processing', 'backupplus' ) . '...</span>';
		$template .= '</div>';

		return $template;
	}

	/**
	 * This will call the remote storage methods complete authentication function
	 *
	 * @param string $state - the remote storage authentication state
	 * @param string $code  - the remote storage authentication code
	 *
	 * @return String - returns a string response
	 */
	public function complete_authentication( $state, $code ) {
		if ( method_exists( $this, 'do_complete_authentication' ) ) {
			return $this->do_complete_authentication( $state, $code, true );
		} else {
			$message = $this->get_id() . ': module does not have an complete authentication method (coding bug)';
			BackupPlus_Manipulation_Functions::error_log( $message );
			return $message;
		}
	}

	/**
	 * Over-ride this to allow methods to output extra information about using the correct account for OAuth storage methods
	 *
	 * @return Boolean - return false so that no extra information is output
	 */
	public function output_account_warning() {
		return false;
	}
}
