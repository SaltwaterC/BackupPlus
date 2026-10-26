<?php

/** Building the Google API client from the stored options. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_GoogleDrive_Client {

	public function bootstrap( $access_token = false ) {
		$storage = $this->get_storage();

		if ( ! empty( $storage ) && is_object( $storage ) && is_a( $storage, 'UDP_Google_Service_Drive' ) ) {
			return $storage;
		}

		$opts = $this->get_options();

		$use_master = $this->use_master( $opts );

		$curl_exists = function_exists( 'curl_version' ) && function_exists( 'curl_exec' );

		if ( ! $use_master ) {
			if ( empty( $opts['token'] ) || empty( $opts['clientid'] ) || empty( $opts['secret'] ) ) {
				$this->log( 'this account is not authorised' );
				$this->log( __( 'Account is not authorized.', 'backupplus' ), 'error', 'googledrivenotauthed' );
				return new WP_Error( 'not_authorized', __( 'Account is not authorized.', 'backupplus' ) . ' (Google Drive)' );
			}

			if ( empty( $access_token ) ) {
				$access_token = $this->access_token( $opts['token'], $opts['clientid'], $opts['secret'] );
			}
		} else {

			if ( empty( $opts['user_id'] ) ) {
				$this->log( 'this account is not authorised' );
				$this->log( __( 'Account is not authorized.', 'backupplus' ), 'error', 'googledrivenotauthed' );
				return new WP_Error( 'not_authorized', __( 'Account is not authorized.', 'backupplus' ) );
			}

			if ( ! isset( $opts['expires_in'] ) || $opts['expires_in'] < time() ) {

				$user_id = empty( $opts['user_id'] ) ? '' : $opts['user_id'];

				$args = array(
					'code'    => 'ud_googledrive_code',
					'user_id' => $user_id,
				);

				$result = wp_remote_post(
					$this->callback_url,
					array(
						'timeout' => 60,
						'headers' => apply_filters( 'backupplus_auth_headers', '' ),
						'body'    => $args,
					)
				);

				if ( is_wp_error( $result ) ) {

					$body = array(
						'result'            => 'error',
						'error'             => $result->get_error_code(),
						'error_description' => $result->get_error_message(),
					);

				} else {
					$response_code = wp_remote_retrieve_response_code( $result );

					if ( $response_code < 200 || $response_code >= 300 ) {
						$body = array(
							'result'            => 'error',
							'error'             => $response_code,
							'error_description' => sprintf(
								/* translators: 1: Error message, 2: Callback URL */
								__( '%1$s for %2$s', 'backupplus' ),
								wp_remote_retrieve_response_message( $result ),
								$this->callback_url
							),
						);
					} else {
						$body_json = wp_remote_retrieve_body( $result );
						$body      = json_decode( $body_json, true );
					}
				}

				if ( ! empty( $body['result'] ) && 'error' == $body['result'] ) {

					$access_token = new WP_Error( $body['error'], empty( $body['error_description'] ) ? __( 'Have not yet obtained an access token from Google - you need to authorise or re-authorise your connection to Google Drive.', 'backupplus' ) : $body['error_description'] );

				} else {

					$result_body_json = base64_decode( $body[0] );
					$result_body      = json_decode( $result_body_json );

					if ( isset( $result_body->access_token ) ) {
						$access_token = array(
							'access_token'  => $result_body->access_token,
							'created'       => time(),
							'expires_in'    => $result_body->expires_in,
							'refresh_token' => '',
						);

						$opts['tmp_access_token'] = $access_token;
						$opts['expires_in']       = $access_token['created'] + $access_token['expires_in'] - 30;
						$this->set_options( $opts, true );
					} else {
						$access_token = '';
					}
				}
			} else {
				$access_token = $opts['tmp_access_token'];
			}
		}

		// Do we have an access token?
		if ( empty( $access_token ) || is_wp_error( $access_token ) ) {
			$message = 'ERROR: Have not yet obtained an access token from Google (has the user authorised?)';
			$extra   = '';
			if ( is_wp_error( $access_token ) ) {
				$message .= ' (' . $access_token->get_error_message() . ') (' . $access_token->get_error_code() . ')';
				$extra    = ' (' . $access_token->get_error_message() . ') (' . $access_token->get_error_code() . ')';
			}
			$this->log( $message );
			$this->log( __( 'Have not yet obtained an access token from Google - you need to authorise or re-authorise your connection to Google Drive.', 'backupplus' ) . $extra, 'error' );
			return $access_token;
		}

		$spl = spl_autoload_functions();
		if ( is_array( $spl ) ) {
			// Workaround for Google Drive CDN plugin's autoloader
			if ( in_array( 'wpbgdc_autoloader', $spl ) ) {
				spl_autoload_unregister( 'wpbgdc_autoloader' );
			}
			// http://www.wpdownloadmanager.com/download/google-drive-explorer/ - but also others, since this is the default function name used by the Google SDK
			if ( in_array( 'google_api_php_client_autoload', $spl ) ) {
				spl_autoload_unregister( 'google_api_php_client_autoload' );
			}
		}

		if ( ( ! class_exists( 'UDP_Google_Config' ) || ! class_exists( 'UDP_Google_Client' ) || ! class_exists( 'UDP_Google_Service_Drive' ) || ! class_exists( 'UDP_Google_Http_Request' ) ) && ! function_exists( 'google_api_php_client_autoload_backupplus' ) ) {
			backupplus_try_include_file( 'methods/googledrive/Google/autoload.php', 'include_once' );
		}

		if ( ! class_exists( 'BackupPlus_Google_Http_MediaFileUpload' ) ) {
			backupplus_try_include_file( 'methods/googledrive/google-extensions.php', 'include_once' );
		}

		$config = new UDP_Google_Config();
		$config->setClassConfig( 'UDP_Google_IO_Abstract', 'request_timeout_seconds', 60 );
		// In our testing, $storage->about->get() fails if gzip is not disabled when using the stream wrapper
		if ( ! $curl_exists || ( defined( 'BACKUPPLUS_GOOGLEDRIVE_DISABLEGZIP' ) && BACKUPPLUS_GOOGLEDRIVE_DISABLEGZIP ) ) {
			$config->setClassConfig( 'UDP_Google_Http_Request', 'disable_gzip', true );
		}

		if ( ! $use_master ) {
			$client_id     = $opts['clientid'];
			$client_secret = $opts['secret'];
		} else {
			$client_id     = $this->client_id;
			$client_secret = '';
		}

		$proxy  = new WP_HTTP_Proxy();
		$client = new UDP_Google_Client( $config );

		$is_proxy_enabled = false;
		if ( $proxy->is_enabled() && $proxy->send_through_proxy( $client->getBasePath() ) ) {
			$is_proxy_enabled = true;
			if ( $curl_exists && ! defined( 'CURLOPT_PROXY' ) ) {
				$this->log( 'cURL transports couldn\'t be used because a proxy is set but the installed cURL version doesn\'t support proxy connections. Stream/socket transports (UDP_Google_IO_Stream) are being used instead.' );
				$config->setIoClass( 'UDP_Google_IO_Stream' );
				// Redeclare the client to use UDP_Google_IO_Stream
				$client = new UDP_Google_Client( $config );
			}
		}

		$client->setClientId( $client_id );
		$client->setClientSecret( $client_secret );
		// $client->setUseObjects(true);

		if ( ! $use_master ) {
			$client->setAccessToken(
				json_encode(
					array(
						'access_token'  => $access_token,
						'refresh_token' => $opts['token'],
					)
				)
			);
		} else {
			$client->setAccessToken( json_encode( $access_token ) );
		}

		$io      = $client->getIo();
		$setopts = array();

		if ( is_a( $io, 'UDP_Google_IO_Curl' ) ) {
			$setopts[ CURLOPT_SSL_VERIFYPEER ] = BackupPlus_Options::get_backupplus_option( 'backupplus_ssl_disableverify' ) ? false : true;
			if ( ! BackupPlus_Options::get_backupplus_option( 'backupplus_ssl_useservercerts' ) ) {
				$setopts[ CURLOPT_CAINFO ] = BACKUPPLUS_DIR . '/includes/cacert.pem';
			}
			// Raise the timeout from the default of 15
			$setopts[ CURLOPT_TIMEOUT ]        = 60;
			$setopts[ CURLOPT_CONNECTTIMEOUT ] = 15;
			if ( defined( 'BACKUPPLUS_IPV4_ONLY' ) && BACKUPPLUS_IPV4_ONLY ) {
				$setopts[ CURLOPT_IPRESOLVE ] = CURL_IPRESOLVE_V4;
			}
			$setopts[ CURLOPT_HTTP_VERSION ] = defined( 'BACKUPPLUS_GDRIVE_CURL_HTTP_VERSION' ) ? BACKUPPLUS_GDRIVE_CURL_HTTP_VERSION : CURL_HTTP_VERSION_1_1;

			if ( $is_proxy_enabled ) {
				$port = (int) $proxy->port();
				if ( empty( $port ) ) {
					$port = 8080;
				}

				$setopts[ CURLOPT_PROXY ]     = $proxy->host();
				$setopts[ CURLOPT_PROXYPORT ] = $port;
				$setopts[ CURLOPT_PROXYTYPE ] = CURLPROXY_HTTP;
			}
		} elseif ( is_a( $io, 'UDP_Google_IO_Stream' ) ) {
			$setopts['timeout'] = 60;
			// We had to modify the SDK to support this
			// https://wiki.php.net/rfc/tls-peer-verification - before PHP 5.6, there is no default CA file
			if ( ! BackupPlus_Options::get_backupplus_option( 'backupplus_ssl_useservercerts' ) || ( version_compare( PHP_VERSION, '5.6.0', '<' ) ) ) {
				$setopts['cafile'] = BACKUPPLUS_DIR . '/includes/cacert.pem';
			}
			if ( BackupPlus_Options::get_backupplus_option( 'backupplus_ssl_disableverify' ) ) {
				$setopts['disable_verify_peer'] = true;
			}

			if ( $is_proxy_enabled ) {
				$port = (int) $proxy->port();
				if ( empty( $port ) ) {
					$port = 8080;
				}

				$setopts['proxy'] = $proxy->host() . ':' . $port;
			}
		}

		$io->setOptions( $setopts );

		$storage      = new UDP_Google_Service_Drive( $client );
		$this->client = $client;
		$this->set_storage( $storage );

		try {
			// Get the folder name, if not previously known (this is for the legacy situation where an id, not a name, was stored)
			if ( ! empty( $opts['parentid'] ) && ( ! is_array( $opts['parentid'] ) || empty( $opts['parentid']['name'] ) ) ) {
				$rootid   = $this->root_id();
				$title    = '';
				$parentid = is_array( $opts['parentid'] ) ? $opts['parentid']['id'] : $opts['parentid'];
				while ( ( ! empty( $parentid ) && $parentid != $rootid ) ) {
					$resource = $storage->files->get( $parentid );
					$title    = ( $title ) ? $resource->getTitle() . '/' . $title : $resource->getTitle();
					$parents  = $resource->getParents();
					if ( is_array( $parents ) && count( $parents ) > 0 ) {
						$parent   = array_shift( $parents );
						$parentid = is_a( $parent, 'UDP_Google_Service_Drive_ParentReference' ) ? $parent->getId() : false;
					} else {
						$parentid = false;
					}
				}
				if ( ! empty( $title ) ) {
					$opts['parentid'] = array(
						'id'   => ( is_array( $opts['parentid'] ) ? $opts['parentid']['id'] : $opts['parentid'] ),
						'name' => $title,
					);
					$this->set_options( $opts, true );
				}
			}
		} catch ( Exception $e ) {
			$this->log( 'failed to obtain name of parent folder: ' . $e->getMessage() . ' (line: ' . $e->getLine() . ', file: ' . $e->getFile() . ')' );
		}

		return $storage;
	}

	/**
	 * Acts as a WordPress options filter
	 *
	 * @param  Array $google - An array of Google Drive options
	 * @return Array - the returned array can either be the set of updated Google Drive settings or a WordPress error array
	 */
	public function options_filter( $google ) {

		global $backupplus;

		// Get the current options (and possibly update them to the new format)
		$opts = BackupPlus_Storage_Methods_Interface::update_remote_storage_options_format( 'googledrive' );

		if ( is_wp_error( $opts ) ) {
			if ( 'recursion' !== $opts->get_error_code() ) {
				$msg = '(' . $opts->get_error_code() . '): ' . $opts->get_error_message();
				$this->log( $msg );
				BackupPlus_Manipulation_Functions::error_log( "BackupPlus: Google Drive: $msg" );
			}
			// The saved options had a problem; so, return the new ones
			return $google;
		}
		// $opts = BackupPlus_Options::get_backupplus_option('backupplus_googledrive');
		if ( ! is_array( $google ) ) {
			return $opts;
		}

		// Remove instances that no longer exist
		foreach ( $opts['settings'] as $instance_id => $storage_options ) {
			if ( ! isset( $google['settings'][ $instance_id ] ) ) {
				unset( $opts['settings'][ $instance_id ] );
			}
		}

		if ( empty( $google['settings'] ) ) {
			return $opts;
		}

		foreach ( $google['settings'] as $instance_id => $storage_options ) {
			if ( empty( $opts['settings'][ $instance_id ]['user_id'] ) ) {
				$old_client_id = ( empty( $opts['settings'][ $instance_id ]['clientid'] ) ) ? '' : $opts['settings'][ $instance_id ]['clientid'];
				if ( ! empty( $opts['settings'][ $instance_id ]['token'] ) && $old_client_id != $storage_options['clientid'] ) {
					backupplus_try_include_file( 'methods/googledrive.php', 'include_once' );
					$backupplus->register_wp_http_option_hooks();
					$googledrive = new BackupPlus_BackupModule_googledrive();
					$googledrive->gdrive_auth_revoke( false );
					$backupplus->register_wp_http_option_hooks( false );
					$opts['settings'][ $instance_id ]['token'] = '';
					unset( $opts['settings'][ $instance_id ]['ownername'] );
				}
			}

			foreach ( $storage_options as $key => $value ) {
				// Trim spaces - I got support requests from users who didn't spot the spaces they introduced when copy/pasting
				$opts['settings'][ $instance_id ][ $key ] = ( 'clientid' == $key || 'secret' == $key ) ? trim( $value ) : $value;
			}
			if ( isset( $opts['settings'][ $instance_id ]['folder'] ) ) {
				$opts['settings'][ $instance_id ]['folder'] = apply_filters( 'backupplus_options_googledrive_foldername', 'BackupPlus', $opts['settings'][ $instance_id ]['folder'] );
				unset( $opts['settings'][ $instance_id ]['parentid'] );
			}
		}
		return $opts;
	}

	/**
	 * This function checks if the user has any options for Google Drive saved or if they have defined to use a custom app and if they have we will not use the master Google Drive app and allow them to enter their own client ID and secret
	 *
	 * @param  Array $opts - the Google Drive options array
	 * @return Bool        - a bool value to indicate if we should use the master app or not
	 */
	protected function use_master( $opts ) {
		if ( ( ! empty( $opts['clientid'] ) && ! empty( $opts['secret'] ) ) || ( defined( 'BACKUPPLUS_CUSTOM_GOOGLEDRIVE_APP' ) && BACKUPPLUS_CUSTOM_GOOGLEDRIVE_APP ) ) {
			return false;
		}
		return true;
	}
}
