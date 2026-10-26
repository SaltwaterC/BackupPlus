<?php

/** Connecting and disconnecting the Dropbox account and the connected-account admin output. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Dropbox_OAuth {

	/**
	 * Over-rides the parent to allow this method to output extra information about using the correct account for OAuth authentication
	 *
	 * @return [boolean] - return false so that no extra information is output
	 */
	public function output_account_warning() {
		return true;
	}

	/**
	 * Handles various URL actions, as indicated by the backupplus_dropboxauth URL parameter
	 *
	 * @return null
	 */
	public function action_auth() {
		$backupplus_dropboxauth = BackupPlus_Manipulation_Functions::fetch_superglobal( 'get', 'backupplus_dropboxauth' );
		$request_state          = BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'state' );
		if ( isset( $backupplus_dropboxauth ) ) {
			if ( 'doit' == $backupplus_dropboxauth ) {
				$this->action_authenticate_storage();
				return;
			} elseif ( 'deauth' == $backupplus_dropboxauth ) {
				$this->action_deauthenticate_storage();
				return;
			}
		} elseif ( isset( $request_state ) ) {
			if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' == $_SERVER['REQUEST_METHOD'] ) {
				$auth_state = BackupPlus_Manipulation_Functions::fetch_superglobal( 'post', 'state', '' );
				$raw_state  = urldecode( $auth_state );
				$raw_code   = urldecode( BackupPlus_Manipulation_Functions::fetch_superglobal( 'post', 'code', '' ) );
			} else {
				$raw_state = BackupPlus_Manipulation_Functions::fetch_superglobal( 'get', 'state', '' );
				$raw_code  = BackupPlus_Manipulation_Functions::fetch_superglobal( 'get', 'code', '' );
			}

			if ( ! empty( $raw_code ) ) {
				$this->do_complete_authentication( $raw_state, $raw_code );
			}
		}
		try {
			$this->auth_request();
		} catch ( Exception $e ) {
			$this->log(
				sprintf(
					/* translators: 1: Service name, 2: Error message */
					__( '%1$s error: %2$s', 'backupplus' ),
					/* translators: %s: Service name */
					sprintf( __( '%s authentication', 'backupplus' ), 'Dropbox' ),
					$e->getMessage()
				),
				'error'
			);
		}
	}

	/**
	 * This function will complete the oAuth flow, if return_instead_of_echo is true then add the action to display the authed admin notice, otherwise echo this notice to page.
	 *
	 * @param string  $raw_state              - the state
	 * @param string  $raw_code               - the oauth code
	 * @param boolean $return_instead_of_echo - a boolean to indicate if we should return the result or echo it
	 *
	 * @return void|string - returns the authentication message if return_instead_of_echo is true
	 */
	public function do_complete_authentication( $raw_state, $raw_code, $return_instead_of_echo = false ) {
		// Get the CSRF from setting and check it matches the one returned if it does no CSRF attack has happened
		$opts  = $this->get_options();
		$csrf  = $opts['CSRF'];
		$state = stripslashes( $raw_state );
		// Check the state to see if an instance_id has been attached and if it has then extract the state
		$parts = explode( ':', $state );
		$state = $parts[0];

		if ( strcmp( $csrf, $state ) == 0 ) {
			$opts['CSRF'] = '';
			if ( isset( $raw_code ) ) {
				// set code so it can be accessed in the next authentication step
				$opts['code'] = stripslashes( $raw_code );
				// remove our flag so we know this authentication is complete
				if ( isset( $opts['auth_in_progress'] ) ) {
					unset( $opts['auth_in_progress'] );
				}
				$this->set_options( $opts, true );
				$this->set_connection_status( true );
				$auth_result = $this->auth_token( $return_instead_of_echo );
				if ( $return_instead_of_echo ) {
					return $auth_result;
				}
			}
		} else {
			BackupPlus_Manipulation_Functions::error_log( "BackupPlus: CSRF comparison failure: $csrf != $state" );
		}
	}

	/**
	 * This method will reset any saved options and start the bootstrap process for an authentication
	 *
	 * @param  String $instance_id - the instance id of the settings we want to authenticate
	 */
	public function do_authenticate_storage( $instance_id ) {
		try {
			// Clear out the existing credentials
			$opts                    = $this->get_options();
			$opts['tk_access_token'] = '';
			unset( $opts['tk_request_token'] );
			$opts['ownername'] = '';
			// Set a flag so we know this authentication is in progress
			$opts['auth_in_progress'] = true;
			$this->set_options( $opts, true );

			$this->set_instance_id( $instance_id );
			$this->bootstrap( false );
		} catch ( Exception $e ) {
			$this->log(
				sprintf(
					/* translators: 1: Service name, 2: Error message */
					__( '%1$s error: %2$s', 'backupplus' ),
					/* translators: %s: Service name */
					sprintf(
						__( '%s authentication', 'backupplus' ),
						'Dropbox'
					),
					$e->getMessage()
				),
				'error'
			);
		}
	}

	/**
	 * This method will start the bootstrap process for a de-authentication
	 *
	 * @param  String $instance_id - the instance id of the settings we want to de-authenticate
	 */
	public function do_deauthenticate_storage( $instance_id ) {
		try {
			$this->set_instance_id( $instance_id );
			$this->bootstrap( true );
		} catch ( Exception $e ) {
			$this->log(
				sprintf(
					/* translators: 1: Service name, 2: Error message */
					__( '%1$s error: %2$s', 'backupplus' ),
					/* translators: %s: Service name */
					sprintf( __( '%s de-authentication', 'backupplus' ), 'Dropbox' ),
					$e->getMessage()
				),
				'error'
			);
		}
	}

	/**
	 * This method will setup the authenticated admin warning, it can either return this or echo it
	 *
	 * @param boolean $return_instead_of_echo - a boolean to indicate if we should return the result or echo it
	 *
	 * @return void|string - returns the authentication message if return_instead_of_echo is true
	 */
	public function show_authed_admin_warning( $return_instead_of_echo ) {
		global $backupplus_admin;

		$dropbox = $this->bootstrap();
		if ( false === $dropbox ) {
			return false;
		}

		try {
			$account_info = $dropbox->accountInfo();
		} catch ( Exception $e ) {
			/* translators: 1: Service name, 2: Error message */
			$accountinfo_err = sprintf( __( '%1$s error: %2$s', 'backupplus' ), 'Dropbox', $e->getMessage() ) . ' (' . $e->getCode() . ')';
		}

		$message = '<strong>' . __( 'Success:', 'backupplus' ) . '</strong> ' .
			/* translators: %s: Service name */
			sprintf( __( 'you have authenticated your %s account', 'backupplus' ), 'Dropbox' );
		// We log, because otherwise people get confused by the most recent log message of 'Parameter not found: oauth_token' and raise support requests
		$this->log(
			__( 'Success:', 'backupplus' ) . ' ' .
			/* translators: %s: Service name */
			sprintf( __( 'you have authenticated your %s account', 'backupplus' ), 'Dropbox' )
		);

		if ( empty( $account_info['code'] ) || '200' != $account_info['code'] ) {
			$message .= ' (' . __( 'though part of the returned information was not as expected - whether this indicates a real problem cannot be determined', 'backupplus' ) . ') ' . $account_info['code'];
			if ( ! empty( $accountinfo_err ) ) {
				$message .= '<br>' . htmlspecialchars( $accountinfo_err );
			}
		} else {
			$body  = $account_info['body'];
			$name  = '';
			$email = '';
			if ( isset( $body->display_name ) ) {
				$name = $body->display_name;
			} else {
				$name = $body->name->display_name;
			}
			if ( isset( $body->email ) ) {
				$email = $body->email;
			}

			$opts                 = $this->get_options();
			$opts['ownername']    = $name;
			$opts['email']        = $email;
			$ownername_with_email = $this->generate_ownername_with_email( $opts );
			/* translators: 1: Service name, 2: Account holder's name with email */
			$message .= '. <br>' . sprintf( __( 'Your %1$s account name: %2$s', 'backupplus' ), 'Dropbox', htmlspecialchars( $ownername_with_email ) );
			$this->set_options( $opts, true );

			try {
				/**
				 * Quota information is no longer provided with account information a new call to qoutaInfo must be made to get this information. The timeout is because we've seen cases where it returned after 180 seconds (apparently a faulty outgoing proxy), and we may as well wait as cause an error leading to user confusion.
				 */
				$quota_info = $dropbox->quotaInfo( array( 'timeout' => 190 ) );

				if ( empty( $quota_info['code'] ) || '200' != $quota_info['code'] ) {
					$message .= ' (' . __( 'though part of the returned information was not as expected - whether this indicates a real problem cannot be determined', 'backupplus' ) . ')' . $quota_info['code'];
					if ( ! empty( $accountinfo_err ) ) {
						$message .= '<br>' . htmlspecialchars( $accountinfo_err );
					}
				} else {
					$body = $quota_info['body'];
					if ( isset( $body->quota_info ) ) {
						$quota_info      = $body->quota_info;
						$total_quota     = max( $quota_info->quota, 1 );
						$normal_quota    = $quota_info->normal;
						$shared_quota    = $quota_info->shared;
						$available_quota = $total_quota - ( $normal_quota + $shared_quota );
						$used_perc       = round( ( $normal_quota + $shared_quota ) * 100 / $total_quota, 1 );
						/* translators: 1: Service name, 2: Percentage used, 3: Available space */
						$message .= ' <br>' . sprintf( __( 'Your %1$s quota usage: %2$s %% used, %3$s available', 'backupplus' ), 'Dropbox', $used_perc, round( $available_quota / 1048576, 1 ) . ' MB' );
					} else {
						$total_quota = max( $body->allocation->allocated, 1 );
						$used        = $body->used;
						/* check here to see if the account is a team account and if so use the other used value
						This will give us their total usage including their individual account and team account */
						if ( isset( $body->allocation->used ) ) {
							$used = $body->allocation->used;
						}
						$available_quota = $total_quota - $used;
						$used_perc       = round( $used * 100 / $total_quota, 1 );
						/* translators: 1: Service name, 2: Percentage of quota used, 3: Available quota in MB */
						$message .= ' <br>' . sprintf( __( 'Your %1$s quota usage: %2$s%% used, %3$s available', 'backupplus' ), 'Dropbox', $used_perc, round( $available_quota / 1048576, 1 ) . ' MB' );
					}
				}
			} catch ( Exception $e ) {
				// Catch
			}
		}
		if ( $return_instead_of_echo ) {
			return "<div class='backupplusmessage updated'><p>{$message}</p></div>";
		} else {
			$backupplus_admin->show_admin_warning( $message );
		}
	}
}
