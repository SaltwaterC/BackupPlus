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
	 * Handles the backupplus_dropboxauth URL actions: doit starts an authorization and deauth removes it
	 */
	public function action_auth() {
		$backupplus_dropboxauth = BackupPlus_Manipulation_Functions::fetch_superglobal( 'get', 'backupplus_dropboxauth' );
		if ( 'doit' === $backupplus_dropboxauth ) {
			$this->action_authenticate_storage();
		} elseif ( 'deauth' === $backupplus_dropboxauth ) {
			$this->action_deauthenticate_storage();
		}
	}

	/**
	 * Start an authorization: store its PKCE verifier encrypted and return the Dropbox address that displays the code
	 *
	 * @return String - the address
	 */
	public function start_authorisation() {
		$app_key = $this->app_key();
		if ( '' === $app_key ) {
			throw new Dropbox_Exception( __( 'Enter the app key of your Dropbox app and save your settings before you connect Dropbox.', 'backupplus' ) );
		}
		list( $pending, $url ) = BackupPlus_Dropbox_PKCE::start( $app_key );
		$this->token_storage()->set( $pending, 'pkce' );
		return $url;
	}

	/**
	 * Send the browser to Dropbox, which displays the code to paste into the settings page. The existing connection is kept until a new code is accepted.
	 *
	 * @param String $instance_id - the instance id of the settings we want to authenticate
	 */
	public function do_authenticate_storage( $instance_id ) {
		$this->set_instance_id( $instance_id );
		try {
			$url = $this->start_authorisation();
		} catch ( Exception $e ) {
			/* translators: 1: Service name, 2: Error message */
			$this->log( sprintf( __( '%1$s error: %2$s', 'backupplus' ), sprintf( __( '%s authentication', 'backupplus' ), 'Dropbox' ), $e->getMessage() ), 'error' );
			return;
		}
		if ( headers_sent() ) {
			/* translators: %s: Authentication service name (e.g., Dropbox) */
			$this->log( sprintf( __( 'The %s authentication could not go ahead, because something else on your site is breaking it.', 'backupplus' ), 'Dropbox' ) . ' ' . __( 'Try disabling your other plugins and switching to a default theme.', 'backupplus' ), 'error' );
			return;
		}
		header( 'Location: ' . $url );
		exit;
	}

	/**
	 * Complete the authorization with the code Dropbox displayed
	 *
	 * @param String $code - the code
	 *
	 * @return String - the success message
	 */
	public function do_complete_authentication( $code ) {
		$storage = $this->token_storage();
		$token   = BackupPlus_Dropbox_PKCE::exchange( $storage->get( 'pkce' ), trim( (string) $code ) );
		$storage->set( $token, 'access_token' );
		$storage->do_unset( 'pkce' );
		$opts              = $this->get_options();
		$opts['token_app'] = $token->client_id;
		// A token authorized with PKCE is renewed without the app secret, so a saved one is no longer needed
		unset( $opts['secret'], $opts['CSRF'], $opts['code'], $opts['auth_in_progress'], $opts['upgraded'] );
		$this->set_options( $opts, true );
		$this->set_connection_status( true );
		return $this->show_authed_admin_warning( true );
	}

	/**
	 * The dashboard notice links to the settings page, where the code Dropbox displays is pasted
	 *
	 * @param Boolean $echo_instead_of_return     - a boolean to indicate if the link should be echoed or returned
	 * @param Boolean $template_instead_of_notice - unused; Dropbox templates build their own controls
	 * @return Void|String
	 */
	public function get_authentication_link( $echo_instead_of_return = true, $template_instead_of_notice = true ) {
		$html = esc_html__( 'Dropbox is not connected, so backups cannot be sent to it.', 'backupplus' ) . ' <a href="' . esc_url( BackupPlus_Options::admin_page_url() . '?page=backupplus&tab=settings' ) . '">' . esc_html__( 'Connect Dropbox in the BackupPlus settings.', 'backupplus' ) . '</a>';
		if ( ! $echo_instead_of_return ) {
			return $html;
		}
		echo $html;
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
