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

					// If a different app key is being used, then wipe the stored token and secret as they cannot belong to the new app
					if ( $current_app_key !== $new_app_key ) {
						unset( $opts['settings'][ $instance_id ]['tk_access_token'] );
						unset( $opts['settings'][ $instance_id ]['tk_pkce'] );
						unset( $opts['settings'][ $instance_id ]['token_app'] );
						unset( $opts['settings'][ $instance_id ]['secret'] );
						unset( $opts['settings'][ $instance_id ]['ownername'] );
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
	 * The app key of the connection: its own, or the built-in one. A "dropbox:" prefix marks a full Dropbox app.
	 *
	 * @param Array|null $opts - the instance options, or null for the current ones
	 * @return String
	 */
	public function app_key( $opts = null ) {
		$opts = null === $opts ? $this->get_options() : $opts;
		$key  = empty( $opts['appkey'] ) ? BackupPlus_Dropbox_PKCE::default_app_key() : $opts['appkey'];
		return 'dropbox:' === substr( $key, 0, 8 ) ? substr( $key, 8 ) : $key;
	}

	/**
	 * The encrypted token storage of the current instance
	 *
	 * @return Dropbox_WordPress
	 */
	public function token_storage() {
		foreach ( array( 'API.php', 'Exception.php', 'OAuth/Consumer/ConsumerAbstract.php', 'OAuth/Storage/StorageInterface.php', 'OAuth/Storage/Encrypter.php', 'OAuth/Storage/WordPress.php', 'OAuth/Consumer/Curl.php' ) as $file ) {
			backupplus_try_include_file( 'methods/dropbox/Dropbox2/' . $file, 'include_once' );
		}
		return new Dropbox_WordPress( Dropbox_Encrypter::from_config( $this->get_instance_id() ), 'tk_', 'backupplus_dropbox', $this );
	}

	/**
	 * Build the API client from the stored token, or revoke the token
	 *
	 * @param  Boolean $deauthenticate indicates if we should revoke the token instead
	 * @return object|Boolean
	 */
	public function bootstrap( $deauthenticate = false ) {

		$storage = $this->get_storage();

		if ( ! empty( $storage ) && ! is_wp_error( $storage ) ) {
			return $storage;
		}

		$opts = $this->get_options();

		if ( ! $deauthenticate && $this->authentication_required( $opts ) && ! empty( $opts['tk_access_token'] ) ) {
			$this->log( __( 'This Dropbox connection was made through an app that BackupPlus no longer uses. Connect Dropbox again in BackupPlus Settings.', 'backupplus' ), 'error' );
			return false;
		}

		try {
			$token_storage = $this->token_storage();
		} catch ( Exception $e ) {
			$this->log( $e->getMessage(), 'error' );
			return false;
		}

		$root = ( ! empty( $opts['appkey'] ) && 'dropbox:' === substr( $opts['appkey'], 0, 8 ) ) ? 'dropbox' : 'sandbox';

		try {
			$oauth = new Dropbox_Curl( $this->app_key( $opts ), empty( $opts['secret'] ) ? '' : $opts['secret'], $token_storage, $deauthenticate );
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
