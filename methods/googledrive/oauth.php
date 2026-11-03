<?php

/** Connecting the Google account: the OAuth redirect flow, token refresh and revocation, and the connected-account admin output. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_GoogleDrive_OAuth {

	/** Seconds for which a started sign-in can be completed. */
	private static $oauth_state_lifetime = 600;

	/**
	 * Handles the URL actions: doit starts a sign-in and deauth removes it (both nonce-checked), state=token is Google's return
	 * from a sign-in this site started, and state=success shows the result of that sign-in once (nonce-checked).
	 */
	public function action_auth() {
		$state                      = BackupPlus_Manipulation_Functions::fetch_superglobal( 'get', 'state' );
		$backupplus_googledriveauth = BackupPlus_Manipulation_Functions::fetch_superglobal( 'get', 'backupplus_googledriveauth' );
		if ( is_string( $state ) ) {

			$parts = explode( ':', $state );

			if ( 'success' === $parts[0] ) {
				// gdrive_auth_token() redirects here after saving the token, so that the notice is not shown with the code in the URL.
				$nonce = BackupPlus_Manipulation_Functions::fetch_superglobal( 'get', 'nonce' );
				if ( is_string( $nonce ) && wp_verify_nonce( $nonce, 'backupplus_googledrive_auth_success' ) ) {
					add_action( 'all_admin_notices', array( $this, 'show_authed_admin_success' ) );
				}
			} elseif ( 'token' === $parts[0] ) {
				$this->gdrive_auth_token( $state );
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
	 * The redirect URI registered for the site's OAuth client.
	 *
	 * @return String - the redirect URI
	 */
	private function redirect_uri() {
		return BackupPlus_Options::admin_page_url() . '?action=backupplusmethod-googledrive-auth';
	}

	/**
	 * Start a sign-in: store a new single-use state, bound to the current user and this instance, and return Google's consent address
	 *
	 * @param  String $instance_id - the instance id of the settings we want to authenticate
	 *
	 * @throws RuntimeException - if no OAuth client is saved
	 *
	 * @return String - the address
	 */
	public function authorization_url( $instance_id ) {
		$opts = $this->get_options();

		if ( empty( $opts['clientid'] ) || empty( $opts['secret'] ) ) {
			throw new RuntimeException( __( 'Enter your Google OAuth client ID and secret, and save your settings, before you sign in with Google.', 'backupplus' ) );
		}

		// The handler of the return reads the instance from the second field; the random part makes the state unguessable.
		$state                    = 'token:' . $instance_id . ':' . bin2hex( random_bytes( 32 ) );
		$opts['oauth_state']      = array(
			'hash'    => hash( 'sha256', $state ),
			'user'    => get_current_user_id(),
			'expires' => time() + self::$oauth_state_lifetime,
		);
		$opts['auth_in_progress'] = true;
		$this->set_options( $opts, true );

		// We require access to all Google Drive files (not just ones created by this app - scope https://www.googleapis.com/auth/drive.file) - because we need to be able to re-scan storage for backups uploaded by other installs, or manually by the user into their Google Drive. But, if you are happy to lose that capability, you can use the filter below to remove the drive.readonly scope.
		$params          = array(
			'response_type'           => 'code',
			'client_id'               => $opts['clientid'],
			'redirect_uri'            => $this->redirect_uri(),
			// Nov 2024 - https://www.googleapis.com/auth/drive.readonly temporarily removed for annual re-verification (for which we received no notification)
			'scope'                   => apply_filters( 'backupplus_googledrive_scope', 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/userinfo.profile' ),
			'access_type'             => 'offline',
			// 'approval_prompt' => 'force', // legacy and has been deprected. It can lead to conflicts when specified along with "prompt" param
			// Nov 2024 - changed from `true` for the same reason as a few lines earlier
			'include_granted_scopes'  => 'false',
			'enable_granular_consent' => 'true',
			'prompt'                  => 'select_account consent', // new option param as the replacement to 'approval_prompt'
		);
		$params          = apply_filters( 'backupplus_googledrive_auth_params', $params );
		$params['state'] = $state;
		return 'https://accounts.google.com/o/oauth2/auth?' . http_build_query( $params, '', '&' );
	}

	/**
	 * Consume the pending sign-in state. It is removed whether or not it matches, so that it can be used once only.
	 *
	 * @param  Mixed $state - the state Google returned
	 *
	 * @return Boolean - whether it is the state of a sign-in that the current user started for this instance, and has not expired
	 */
	private function consume_oauth_state( $state ) {
		$opts   = $this->get_options();
		$stored = ( isset( $opts['oauth_state'] ) && is_array( $opts['oauth_state'] ) ) ? $opts['oauth_state'] : array();
		if ( array_key_exists( 'oauth_state', $opts ) ) {
			unset( $opts['oauth_state'] );
			$this->set_options( $opts, true );
		}
		return is_string( $state ) && '' !== $state
			&& ! empty( $stored['hash'] ) && is_string( $stored['hash'] )
			&& isset( $stored['user'] ) && (int) $stored['user'] === (int) get_current_user_id() && 0 !== (int) get_current_user_id()
			&& isset( $stored['expires'] ) && (int) $stored['expires'] >= time()
			&& hash_equals( $stored['hash'], hash( 'sha256', $state ) );
	}

	/**
	 * Acquire single-use authorization code from Google via OAuth 2.0
	 *
	 * @param  String $instance_id - the instance id of the settings we want to authenticate
	 */
	public function do_authenticate_storage( $instance_id ) {
		$opts = $this->get_options();

		if ( empty( $opts['clientid'] ) || empty( $opts['secret'] ) ) {
			$this->log( __( 'Enter your Google OAuth client ID and secret, and save your settings, before you sign in with Google.', 'backupplus' ), 'error' );
			return;
		}

		// First, revoke any existing token, since Google doesn't appear to like issuing new ones
		if ( ! empty( $opts['token'] ) ) {
			$this->gdrive_auth_revoke();
		}

		$url = $this->authorization_url( $instance_id );

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
			header( 'Location: ' . $url );
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
	 * Complete a sign-in: check the state against the one this site stored when the current user started it, then exchange the code
	 *
	 * @param  Mixed $state - the state Google returned
	 * @param  Mixed $code  - the authorization code Google returned
	 *
	 * @throws RuntimeException - if the state is not valid, or Google does not issue a refresh token
	 *
	 * @return Boolean - true once the refresh token is saved
	 */
	public function complete_oauth( $state, $code ) {
		// Consumed first, so that a state is single use even when the exchange then fails
		if ( ! $this->consume_oauth_state( $state ) ) {
			$this->log( 'authorization return refused: the state does not match a sign-in started by this user for this instance, or it has expired' );
			throw new RuntimeException( __( 'The Google Drive sign-in was not started from this site by you, or it has expired.', 'backupplus' ) . ' ' . __( 'Sign in with Google again.', 'backupplus' ) );
		}
		if ( ! is_string( $code ) || '' === $code ) {
			/* translators: %s: Storage provider name */
			throw new RuntimeException( sprintf( __( '%s authorization failed', 'backupplus' ), 'Google Drive' ) );
		}

		$opts      = $this->get_options();
		$post_vars = array(
			'code'          => $code,
			'client_id'     => $opts['clientid'],
			'client_secret' => $opts['secret'],
			'redirect_uri'  => $this->redirect_uri(),
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
			$message = 'Bad response when contacting Google: ';
			foreach ( $result->get_error_messages() as $error_message ) {
				$this->log( 'authentication error: ' . $error_message );
				$message .= $error_message . '. ';
			}
			throw new RuntimeException( $message );
		}

		$json_values = json_decode( wp_remote_retrieve_body( $result ), true );
		if ( ! isset( $json_values['refresh_token'] ) ) {
			$msg = __( 'No refresh token was received from Google.', 'backupplus' ) . ' ' . __( 'This often means that you entered your client secret wrongly, or that you have not yet re-authenticated (below) since correcting it.', 'backupplus' ) . ' ' . __( 'Re-check it, then follow the link to authenticate again.', 'backupplus' ) . ' ' . __( 'Finally, if that does not work, then use expert mode to wipe all your settings, create a new Google client ID/secret, and start again.', 'backupplus' );

			if ( isset( $json_values['error'] ) && is_string( $json_values['error'] ) ) {
				/* translators: %s: Error message */
				$msg .= ' ' . sprintf( __( 'Error: %s', 'backupplus' ), $json_values['error'] );
			}
			throw new RuntimeException( $msg );
		}

		// Save token, replacing any connection left by the retired shared Google app
		$opts['token'] = $json_values['refresh_token'];
		unset( $opts['user_id'], $opts['expires_in'], $opts['scope'], $opts['auth_in_progress'], $opts['tmp_access_token'] );
		if ( isset( $json_values['access_token'] ) ) {
			$opts['tmp_access_token'] = $json_values['access_token'];
		}
		$this->set_options( $opts, true );
		return true;
	}

	/**
	 * Handle Google's return from a sign-in, then redirect so that the code is not left in the address
	 *
	 * @param String $state - the state Google returned
	 */
	public function gdrive_auth_token( $state ) {
		try {
			$this->complete_oauth( $state, BackupPlus_Manipulation_Functions::fetch_superglobal( 'get', 'code' ) );
		} catch ( RuntimeException $e ) {
			header( 'Location: ' . BackupPlus_Options::admin_page_url() . '?page=backupplus&error=' . urlencode( $e->getMessage() ) );
			return;
		}
		// We do this to clear the GET parameters, otherwise WordPress sticks them in the _wp_referer in the form and brings them back, leading to confusion + errors
		header( 'Location: ' . BackupPlus_Options::admin_page_url() . '?action=backupplusmethod-googledrive-auth&page=backupplus&state=success:' . urlencode( $this->get_instance_id() ) . '&nonce=' . urlencode( wp_create_nonce( 'backupplus_googledrive_auth_success' ) ) );
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
					$message .= esc_html( $storage->get_error_message( $error_code ) . ' (' . $error_code . ')' );
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
			$final_message .= sprintf( __( 'Name: %s.', 'backupplus' ), esc_html( $username ) ) . ' ';
		}
		$final_message .= $message;

		if ( $return_instead_of_echo ) {
			return "<div class='backupplusmessage updated'><p>{$final_message}</p></div>";
		} else {
			$backupplus_admin->show_admin_warning( $final_message );
		}
	}
}
