<?php

/** Building the Dropbox API client from the stored options and its authentication requests. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Dropbox_Client {

	/**
	 * Acts as a WordPress options filter
	 *
	 * @param  Array $dropbox - An array of Dropbox options
	 * @return Array - the returned array can either be the set of updated Dropbox settings or a WordPress error array
	 */
	public function options_filter( $dropbox ) {

		// Get the current options (and possibly update them to the new format)
		$opts = BackupPlus_Storage_Methods_Interface::update_remote_storage_options_format( 'dropbox' );

		if ( is_wp_error( $opts ) ) {
			if ( 'recursion' !== $opts->get_error_code() ) {
				$msg = '(' . $opts->get_error_code() . '): ' . $opts->get_error_message();
				$this->log( $msg );
				BackupPlus_Manipulation_Functions::error_log( "BackupPlus: $msg" );
			}
			// The saved options had a problem; so, return the new ones
			return $dropbox;
		}

		// If the input is not as expected, then return the current options
		if ( ! is_array( $dropbox ) ) {
			return $opts;
		}

		// Remove instances that no longer exist
		foreach ( $opts['settings'] as $instance_id => $storage_options ) {
			if ( ! isset( $dropbox['settings'][ $instance_id ] ) ) {
				unset( $opts['settings'][ $instance_id ] );
			}
		}

		// Dropbox has a special case where the settings could be empty so we should check for this before
		if ( ! empty( $dropbox['settings'] ) ) {

			foreach ( $dropbox['settings'] as $instance_id => $storage_options ) {
				if ( ! empty( $opts['settings'][ $instance_id ]['tk_access_token'] ) ) {

					$current_app_key = empty( $opts['settings'][ $instance_id ]['appkey'] ) ? false : $opts['settings'][ $instance_id ]['appkey'];
					$new_app_key     = empty( $storage_options['appkey'] ) ? false : $storage_options['appkey'];

					// If a different app key is being used, then wipe the stored token as it cannot belong to the new app
					if ( $current_app_key !== $new_app_key ) {
						unset( $opts['settings'][ $instance_id ]['tk_access_token'] );
						unset( $opts['settings'][ $instance_id ]['ownername'] );
						unset( $opts['settings'][ $instance_id ]['CSRF'] );
					}
				}

				// Now loop over the new options, and replace old options with them
				foreach ( $storage_options as $key => $value ) {
					if ( null === $value ) {
						unset( $opts['settings'][ $instance_id ][ $key ] );
					} else {
						if ( ! isset( $opts['settings'][ $instance_id ] ) ) {
							$opts['settings'][ $instance_id ] = array();
						}
						$opts['settings'][ $instance_id ][ $key ] = $value;
					}
				}

				if ( ! empty( $opts['settings'][ $instance_id ]['folder'] ) && preg_match( '#^https?://(www.)dropbox\.com/home/Apps/BackupPlus(.Com)?([^/]*)/(.*)$#i', $opts['settings'][ $instance_id ]['folder'], $matches ) ) {
					$opts['settings'][ $instance_id ]['folder'] = $matches[3];
				}

				// check if we have the dummy nosave option and remove it so that it doesn't get saved
				if ( isset( $opts['settings'][ $instance_id ]['dummy-nosave'] ) ) {
					unset( $opts['settings'][ $instance_id ]['dummy-nosave'] );
				}
			}
		}

		return $opts;
	}

	/**
	 * Bootstrap and check token, can also return the authentication method if return_instead_of_echo is true
	 *
	 * @param boolean $return_instead_of_echo - a boolean to indicate if we should return the result or echo it
	 *
	 * @return void|string - returns the authentication message if return_instead_of_echo is true
	 */
	public function auth_token( $return_instead_of_echo ) {
		$this->bootstrap();
		$opts = $this->get_options();
		if ( ! empty( $opts['tk_access_token'] ) ) {
			if ( $return_instead_of_echo ) {
				return $this->show_authed_admin_warning( $return_instead_of_echo );
			} else {
				add_action( 'all_admin_notices', array( $this, 'show_authed_admin_warning' ) );
			}
		}
	}

	/**
	 * Acquire single-use authorization code
	 */
	public function auth_request() {
		$this->bootstrap();
	}

	/**
	 * This basically reproduces the relevant bits of bootstrap.php from the SDK
	 *
	 * @param  Boolean $deauthenticate indicates if we should bootstrap for a deauth or auth request
	 * @return object
	 */
	public function bootstrap( $deauthenticate = false ) {

		$storage = $this->get_storage();

		if ( ! empty( $storage ) && ! is_wp_error( $storage ) ) {
			return $storage;
		}

		// Dropbox APIv1 is dead, but we'll keep the variable in case v3 is ever announced
		$dropbox_api = 'Dropbox2';

		backupplus_try_include_file( 'methods/dropbox/' . $dropbox_api . '/API.php', 'include_once' );
		backupplus_try_include_file( 'methods/dropbox/' . $dropbox_api . '/Exception.php', 'include_once' );
		backupplus_try_include_file( 'methods/dropbox/' . $dropbox_api . '/OAuth/Consumer/ConsumerAbstract.php', 'include_once' );
		backupplus_try_include_file( 'methods/dropbox/' . $dropbox_api . '/OAuth/Storage/StorageInterface.php', 'include_once' );
		backupplus_try_include_file( 'methods/dropbox/' . $dropbox_api . '/OAuth/Storage/Encrypter.php', 'include_once' );
		backupplus_try_include_file( 'methods/dropbox/' . $dropbox_api . '/OAuth/Storage/WordPress.php', 'include_once' );
		backupplus_try_include_file( 'methods/dropbox/' . $dropbox_api . '/OAuth/Consumer/Curl.php', 'include_once' );
		// backupplus_try_include_file('methods/dropbox/'.$dropbox_api.'/OAuth/Consumer/WordPress.php', 'require_once');

		$opts = $this->get_options();

		$key = empty( $opts['secret'] ) ? '' : $opts['secret'];
		$sec = empty( $opts['appkey'] ) ? '' : $opts['appkey'];

		$oauth2_id = defined( 'BACKUPPLUS_DROPBOX_CLIENT_ID' ) ? BACKUPPLUS_DROPBOX_CLIENT_ID : base64_decode( 'dzQxM3o0cWhqejY1Nm5l' );

		// Set the callback URL
		$callbackhome = BackupPlus_Options::admin_page_url() . '?page=backupplus&action=backupplusmethod-dropbox-auth';
		$callback     = defined( 'BACKUPPLUS_DROPBOX_AUTH_RETURN_URL' ) ? BACKUPPLUS_DROPBOX_AUTH_RETURN_URL : 'https://auth.backupplusplus.com/auth/dropbox/';

		if ( defined( 'BACKUPPLUS_CUSTOM_DROPBOX_APP' ) && BACKUPPLUS_CUSTOM_DROPBOX_APP ) {
			$callback = $callbackhome;
		}

		$instance_id = $this->get_instance_id();
		// Instantiate the Encryptor and storage objects
		try {
			$encrypter = Dropbox_Encrypter::from_config( $instance_id );
		} catch ( Exception $e ) {
			$this->log( $e->getMessage(), 'error' );
			return false;
		}

		// Instantiate the storage
		$dropbox_storage = new Dropbox_WordPress( $encrypter, 'tk_', 'backupplus_dropbox', $this );

		// WordPress consumer does not yet work
		// $oauth = new Dropbox_ConsumerWordPress($sec, $key, $dropbox_storage, $callback);

		// Get the DropBox API access details
		list($d2, $d1) = $this->defaults();
		if ( empty( $sec ) ) {
			$sec = base64_decode( $d1 );
		}

		if ( empty( $key ) ) {
			$key = base64_decode( $d2 );
		}

		$root = 'sandbox';
		if ( 'dropbox:' == substr( $sec, 0, 8 ) ) {
			$sec  = substr( $sec, 8 );
			$root = 'dropbox';
		}

		try {
			$oauth = new Dropbox_Curl( $sec, $oauth2_id, $key, $dropbox_storage, $callback, $callbackhome, $deauthenticate, $instance_id );
		} catch ( Exception $e ) {
			$this->log( 'Curl error: ' . $e->getMessage() );
			/* translators: 1: Service name, 2: Error message with exception details */
			$this->log( sprintf( __( '%1$s error: %2$s', 'backupplus' ), 'Dropbox/Curl', $e->getMessage() . ' (' . get_class( $e ) . ') (line: ' . $e->getLine() . ', file: ' . $e->getFile() ) . ')', 'error' );
			return false;
		}

		if ( $deauthenticate ) {
			return true;
		}

		$storage = new BackupPlus_Dropbox_API( $oauth, $root );

		$this->set_storage( $storage );

		return $storage;
	}
}
