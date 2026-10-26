<?php

/** Connecting the Google account: the OAuth redirect flow, token refresh and revocation, and the connected-account admin output. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_GoogleDrive_OAuth {

	public function action_auth() {
		$state                      = BackupPlus_Manipulation_Functions::fetch_superglobal( 'get', 'state' );
		$backupplus_googledriveauth = BackupPlus_Manipulation_Functions::fetch_superglobal( 'get', 'backupplus_googledriveauth' );
		if ( isset( $state ) ) {

			$parts = explode( ':', $state );
			$state = $parts[0];

			if ( 'success' == $state ) {
				$user_id      = BackupPlus_Manipulation_Functions::fetch_superglobal( 'get', 'user_id' );
				$access_token = BackupPlus_Manipulation_Functions::fetch_superglobal( 'get', 'access_token' );

				if ( isset( $user_id ) && isset( $access_token ) ) {
					$code = array(
						'user_id'      => $user_id,
						'access_token' => $access_token,
					);

					$scope = BackupPlus_Manipulation_Functions::fetch_superglobal( 'get', 'scope' );
					if ( isset( $scope ) ) {
						$scope         = $scope;
						$code['scope'] = explode( ' ', $scope );
					}
				} else {
					$code = array();
				}

				$this->do_complete_authentication( $state, $code );

			} elseif ( 'token' == $state ) {
				$this->gdrive_auth_token();
			} elseif ( 'revoke' == $state ) {
				$this->gdrive_auth_revoke();
			}
		} elseif ( isset( $backupplus_googledriveauth ) ) {
			if ( 'doit' == $backupplus_googledriveauth ) {
				$this->action_authenticate_storage();
			} elseif ( 'deauth' == $backupplus_googledriveauth ) {
				$this->action_deauthenticate_storage();
			}
		}
	}

	/**
	 * Get a Google account access token using the refresh token
	 *
	 * @param  String $refresh_token Specify refresh token
	 * @param  String $client_id     Specify Client ID
	 * @param  String $client_secret Specify client secret
	 * @return Boolean
	 */
	private function access_token( $refresh_token, $client_id, $client_secret ) {

		$this->log( "requesting access token: client_id=$client_id" );

		$query_body = array(
			'refresh_token' => $refresh_token,
			'client_id'     => $client_id,
			'client_secret' => $client_secret,
			'grant_type'    => 'refresh_token',
		);

		$result = wp_remote_post(
			'https://accounts.google.com/o/oauth2/token',
			array(
				'timeout' => '20',
				'method'  => 'POST',
				'body'    => $query_body,
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->log( 'error when requesting access token' );
			foreach ( $result->get_error_messages() as $msg ) {
				$this->log( "Error message: $msg" );
			}
			return false;
		} else {
			$json_values = json_decode( wp_remote_retrieve_body( $result ), true );
			if ( isset( $json_values['access_token'] ) ) {
				$this->log( 'successfully obtained access token' );
				return $json_values['access_token'];
			} else {
				$response = json_decode( $result['body'], true );
				if ( ! empty( $response['error'] ) && 'deleted_client' == $response['error'] ) {
					$this->log( __( 'The client has been deleted from the Google Drive API console.', 'backupplus' ) . ' ' . __( 'Please create a new Google Drive project and reconnect with BackupPlus.', 'backupplus' ), 'error' );
				}
				$error_code = empty( $response['error'] ) ? 'no error code' : $response['error'];
				$this->log( "error ($error_code) when requesting access token: response does not contain access_token. Response: " . ( is_string( $result['body'] ) ? str_replace( "\n", '', $result['body'] ) : json_encode( $result['body'] ) ) );
				return false;
			}
		}
	}

	/**
	 * This method will return a redirect URL depending on the parameter passed. It will either return the redirect for the user's site or the auth server.
	 *
	 * @param  Boolean $master - indicate whether we want the master redirect URL
	 * @return String          - a redirect URL
	 */
	private function redirect_uri( $master = false ) {
		if ( $master ) {
			return $this->callback_url;
		} else {
			return BackupPlus_Options::admin_page_url() . '?action=backupplusmethod-googledrive-auth';
		}
	}

	/**
	 * Acquire single-use authorization code from Google via OAuth 2.0
	 *
	 * @param  String $instance_id - the instance id of the settings we want to authenticate
	 */
	public function do_authenticate_storage( $instance_id ) {
		$opts = $this->get_options();

		$use_master = $this->use_master( $opts );

		// First, revoke any existing token, since Google doesn't appear to like issuing new ones
		if ( ! empty( $opts['token'] ) && ! $use_master ) {
			$this->gdrive_auth_revoke();
		}

		// Set a flag so we know this authentication is in progress
		$opts['auth_in_progress'] = true;
		$this->set_options( $opts, true );

		$prefixed_instance_id = ':' . $instance_id;

		// We use 'force' here for the approval_prompt, not 'auto', as that deals better with messy situations where the user authenticated, then changed settings

		if ( $use_master ) {
			$client_id = $this->client_id;
			$token     = 'token' . $prefixed_instance_id;
			$token    .= $this->redirect_uri();
		} else {
			$client_id = $opts['clientid'];
			$token     = 'token' . $prefixed_instance_id;
		}
		// We require access to all Google Drive files (not just ones created by this app - scope https://www.googleapis.com/auth/drive.file) - because we need to be able to re-scan storage for backups uploaded by other installs, or manually by the user into their Google Drive. But, if you are happy to lose that capability, you can use the filter below to remove the drive.readonly scope.
		$params = array(
			'response_type'           => 'code',
			'client_id'               => $client_id,
			'redirect_uri'            => $this->redirect_uri( $use_master ),
			// Nov 2024 - https://www.googleapis.com/auth/drive.readonly temporarily removed for annual re-verification (for which we received no notification)
			'scope'                   => apply_filters( 'backupplus_googledrive_scope', 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/userinfo.profile' ),
			'state'                   => $token,
			'access_type'             => 'offline',
			// 'approval_prompt' => 'force', // legacy and has been deprected. It can lead to conflicts when specified along with "prompt" param
			// Nov 2024 - changed from `true` for the same reason as a few lines earlier
			'include_granted_scopes'  => 'false',
			'enable_granular_consent' => 'true',
			'prompt'                  => 'select_account consent', // new option param as the replacement to 'approval_prompt'
		);
		$params = apply_filters( 'backupplus_googledrive_auth_params', $params );
		if ( headers_sent() ) {
			$this->log(
				sprintf(
					/* translators: %s: Storage provider name */
					__( 'The %s authentication could not go ahead, because something else on your site is breaking it.', 'backupplus' ),
					'Google Drive'
				) . ' ' .
				__( 'Try disabling your other plugins and switching to a default theme.', 'backupplus' ) . ' (' .
				__( 'Specifically, you are looking for the component that sends output (most likely PHP warnings/errors) before the page begins.', 'backupplus' ) . ' ' .
				__( 'Turning off any debugging settings may also help).', 'backupplus' ) . ')',
				'error'
			);
		} else {
			header( 'Location: https://accounts.google.com/o/oauth2/auth?' . http_build_query( $params, '', '&' ) );
		}
	}

	/**
	 * This function will complete the oAuth flow, if return_instead_of_echo is true then add the action to display the authed admin notice, otherwise echo this notice to page.
	 *
	 * @param string  $state                  - the state
	 * @param string  $code                   - the oauth code
	 * @param boolean $return_instead_of_echo - a boolean to indicate if we should return the result or echo it
	 *
	 * @return void|string - returns the authentication message if return_instead_of_echo is true
	 */
	public function do_complete_authentication( $state, $code, $return_instead_of_echo = false ) {

		// If these are set then this is a request from our master app and the auth server has returned these to be saved.
		if ( isset( $code['user_id'] ) && isset( $code['access_token'] ) ) {
			$opts                     = $this->get_options();
			$opts['user_id']          = base64_decode( $code['user_id'] );
			$opts['tmp_access_token'] = base64_decode( $code['access_token'] );
			if ( isset( $opts['auth_in_progress'] ) ) {
				$opts['scope'] = $code['scope'];
			}
			// Unset this value if it is set as this is a fresh auth we will set this value in the next step
			if ( isset( $opts['expires_in'] ) ) {
				unset( $opts['expires_in'] );
			}
			// remove our flag so we know this authentication is complete
			if ( isset( $opts['auth_in_progress'] ) ) {
				unset( $opts['auth_in_progress'] );
			}
			$this->set_options( $opts, true );
			$this->set_connection_status( true );
		}

		if ( $return_instead_of_echo ) {
			return $this->show_authed_admin_success( $return_instead_of_echo );
		} else {
			add_action( 'all_admin_notices', array( $this, 'show_authed_admin_success' ) );
		}
	}

	/**
	 * Revoke a Google account refresh token
	 * Returns the parameter fed in, so can be used as a WordPress options filter
	 * Can be called statically from BackupPlus::googledrive_clientid_checkchange()
	 *
	 * @param  Boolean $unsetopt unset options is set to true unless otherwise specified
	 */
	public function gdrive_auth_revoke( $unsetopt = true ) {
		$opts   = $this->get_options();
		$result = wp_remote_get( 'https://accounts.google.com/o/oauth2/revoke?token=' . $opts['token'] );

		// If the call to revoke the token fails, we try again one more time
		if ( is_wp_error( $result ) || 200 != wp_remote_retrieve_response_code( $result ) ) {
			wp_remote_get( 'https://accounts.google.com/o/oauth2/revoke?token=' . $opts['token'] );
		}

		if ( $unsetopt ) {
			$opts['token'] = '';
			unset( $opts['ownername'] );
			$this->set_options( $opts, true );
		}
	}

	/**
	 * Get a Google account refresh token using the code received from do_authenticate_storage
	 */
	public function gdrive_auth_token() {
		$opts = $this->get_options();
		$code = BackupPlus_Manipulation_Functions::fetch_superglobal( 'get', 'code' );
		if ( isset( $code ) ) {
			$post_vars = array(
				'code'          => $code,
				'client_id'     => $opts['clientid'],
				'client_secret' => $opts['secret'],
				'redirect_uri'  => BackupPlus_Options::admin_page_url() . '?action=backupplusmethod-googledrive-auth',
				'grant_type'    => 'authorization_code',
			);

			$result = wp_remote_post(
				'https://accounts.google.com/o/oauth2/token',
				array(
					'timeout' => 25,
					'method'  => 'POST',
					'body'    => $post_vars,
				)
			);

			if ( is_wp_error( $result ) ) {
				$add_to_url = 'Bad response when contacting Google: ';
				foreach ( $result->get_error_messages() as $message ) {
					$this->log( 'authentication error: ' . $message );
					$add_to_url .= $message . '. ';
				}
				header( 'Location: ' . BackupPlus_Options::admin_page_url() . '?page=backupplus&error=' . urlencode( $add_to_url ) );
			} else {
				$json_values = json_decode( wp_remote_retrieve_body( $result ), true );
				if ( isset( $json_values['refresh_token'] ) ) {

					// Save token
					$opts['token'] = $json_values['refresh_token'];
					$this->set_options( $opts, true );

					if ( isset( $json_values['access_token'] ) ) {
						$opts['tmp_access_token'] = $json_values['access_token'];
						$this->set_options( $opts, true );
						// We do this to clear the GET parameters, otherwise WordPress sticks them in the _wp_referer in the form and brings them back, leading to confusion + errors
						header( 'Location: ' . BackupPlus_Options::admin_page_url() . '?action=backupplusmethod-googledrive-auth&page=backupplus&state=success:' . urlencode( $this->get_instance_id() ) );
					}
				} else {

					$msg = __( 'No refresh token was received from Google.', 'backupplus' ) . ' ' . __( 'This often means that you entered your client secret wrongly, or that you have not yet re-authenticated (below) since correcting it.', 'backupplus' ) . ' ' . __( 'Re-check it, then follow the link to authenticate again.', 'backupplus' ) . ' ' . __( 'Finally, if that does not work, then use expert mode to wipe all your settings, create a new Google client ID/secret, and start again.', 'backupplus' );

					/* translators: %s: Error message */
					if ( isset( $json_values['error'] ) ) {
						$msg .= ' ' . sprintf( __( 'Error: %s', 'backupplus' ), $json_values['error'] );
					}

					header( 'Location: ' . BackupPlus_Options::admin_page_url() . '?page=backupplus&error=' . urlencode( $msg ) );
				}
			}
		} else {
			/* translators: %s: Storage provider name */
			header( 'Location: ' . BackupPlus_Options::admin_page_url() . '?page=backupplus&error=' . urlencode( sprintf( __( '%s authorization failed', 'backupplus' ), 'Google Drive' ) ) );
		}
	}

	/**
	 * This method will setup the authenticated admin warning, it can either return this or echo it
	 *
	 * @param boolean $return_instead_of_echo - a boolean to indicate if we should return the result or echo it
	 *
	 * @return void|string - returns the authentication message if return_instead_of_echo is true
	 */
	public function show_authed_admin_success( $return_instead_of_echo ) {

		global $backupplus_admin;

		$opts = $this->get_options();

		if ( empty( $opts['tmp_access_token'] ) ) {
			return;
		}
		$backupplus_tmp_access_token = $opts['tmp_access_token'];

		$message = '';
		try {
			$storage = $this->bootstrap( $backupplus_tmp_access_token );
			if ( false != $storage && ! is_wp_error( $storage ) ) {

				$about              = $storage->about->get();
				$quota_total        = max( $about->getQuotaBytesTotal(), 1 );
				$quota_used         = $about->getQuotaBytesUsed();
				$username           = $about->getName();
				$get_user           = $about->getUser();
				$email              = is_object( $get_user ) ? $get_user->emailAddress : '';
				$opts['ownername']  = $username;
				$opts['owneremail'] = $email;

				if ( is_numeric( $quota_total ) && is_numeric( $quota_used ) ) {
					$available_quota = $quota_total - $quota_used;
					$used_perc       = round( $quota_used * 100 / $quota_total, 1 );
					/* translators: 1: Storage provider name, 2: Percentage used, 3: Available quota in MB */
					$message .= sprintf( __( 'Your %1$s quota usage: %2$s %% used, %3$s available', 'backupplus' ), 'Google Drive', $used_perc, round( $available_quota / 1048576, 1 ) . ' MB' );
				}
			} elseif ( is_wp_error( $storage ) ) {
				$message    .= __( 'However, subsequent access attempts failed:', 'backupplus' );
				$error_codes = $storage->get_error_codes();
				$message    .= '<ul style="list-style: disc inside;">';
				foreach ( $error_codes as $error_code ) {
					$message .= '<li>';
					$message .= $storage->get_error_message( $error_code ) . ' (' . $error_code . ')';
					$message .= '</li>';
				}
				$message .= '</ul>';
			}
		} catch ( Exception $e ) {
			if ( is_a( $e, 'UDP_Google_Service_Exception' ) ) {
				$errs     = $e->getErrors();
				$message .= __( 'However, subsequent access attempts failed:', 'backupplus' );
				if ( is_array( $errs ) ) {
					$message .= '<ul style="list-style: disc inside;">';
					foreach ( $errs as $err ) {
						$message .= '<li>';
						if ( ! empty( $err['reason'] ) ) {
							$message .= '<strong>' . htmlspecialchars( $err['reason'] ) . ':</strong> ';
						}
						if ( ! empty( $err['message'] ) ) {
							$message .= htmlspecialchars( $err['message'] );
						} else {
							$message .= htmlspecialchars( serialize( $err ) );
						}
						$message .= '</li>';
					}
					$message .= '</ul>';
				} else {
					$message .= htmlspecialchars( serialize( $errs ) );
				}
			}
		}

		unset( $opts['tmp_access_token'] );
		$this->set_options( $opts, true );

		$final_message  = __( 'Success', 'backupplus' ) . ': ';
		$final_message .= sprintf(
			/* translators: %s: Storage provider name */
			__( 'you have authenticated your %s account.', 'backupplus' ),
			__( 'Google Drive', 'backupplus' )
		) . ' ';
		if ( ! empty( $username ) ) {
			/* translators: %s: Username */
			$final_message .= sprintf( __( 'Name: %s.', 'backupplus' ), $username ) . ' ';
		}
		$final_message .= $message;

		if ( $return_instead_of_echo ) {
			return "<div class='backupplusmessage updated'><p>{$final_message}</p></div>";
		} else {
			$backupplus_admin->show_admin_warning( $final_message );
		}
	}
}
