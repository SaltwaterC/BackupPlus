<?php

/** HTTP helpers for the admin side. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Admin_Http {

	/**
	 * Used by the WP filter http_allowed_safe_ports_allow. It will include any found port as allowed.
	 *
	 * @param Array  $ports
	 * @param String $host
	 * @param String $url
	 *
	 * @return Array - filtered result
	 */
	public function http_allowed_safe_ports_allow( $ports, $host, $url ) {
		$port = parse_url( $url, PHP_URL_PORT );
		if ( is_integer( $port ) ) {
			$ports[] = $port;
		}
		return $ports;
	}

	/**
	 * http_get will allow the HTTP Fetch execute available in advanced tools
	 *
	 * @param  String  $uri  Specific URL to fetch
	 * @param  Boolean $curl Whether cURL is to be used directly, rather than WP's HTTP API
	 *
	 * @return String - JSON encoded results
	 */
	public function http_get( $uri = '', $curl = false ) {

		if ( ! preg_match( '/^https?:/', $uri ) ) {
			return json_encode( array( 'e' => 'Non-http(s) URL specified' ) );
		}

		// It is only internal destinations that we check for here - non-standard ports on Internet destinations are not of concern
		add_filter( 'http_allowed_safe_ports', array( $this, 'http_allowed_safe_ports_allow' ), 10, 3 );

		$url_allowed = true;

		if ( function_exists( 'wp_http_validate_url' ) && ! wp_http_validate_url( $uri ) && ( ! defined( 'BACKUPPLUS_ALLOW_GET_UNSAFE_URLS' ) || ! BACKUPPLUS_ALLOW_GET_UNSAFE_URLS ) ) {
			// This debugging tool is available as an administrator tool (on multisite, super-administrator). Theoretically, in the case of a malicious administrator on a heavily (non-default) locked-down site, the server administrator might not wish to allow the (super-)administrator to access local network addresses. He really should block that at a network/firewall level; if trying at the PHP level, then there are many likely loopholes. But if he has blocked the administrator from being able to write to all of these executable locations, then this both a) indicates intent and b) blocks off a trivial route through which the administrator could run arbitrary code anyway (which would render any other attempts moot). So, we test it to indicate that intent, and to avoid pointless blocking of what is already possible through other routes.

			$url_allowed = false;

			global $backupplus;
			$entity_dirs = $backupplus->get_backupable_file_entities( false );

			$dirs = array_values( array_intersect_key( $entity_dirs, array_flip( array( 'plugins', 'themes', 'mu-plugins' ) ) ) );

			foreach ( $dirs as $dir ) {
				if ( file_exists( $dir ) && BackupPlus_Filesystem_Functions::really_is_writable( $dir ) ) {
					$url_allowed = true;
					break;
				}
			}
		}

		remove_filter( 'http_allowed_safe_ports', array( $this, 'http_allowed_safe_ports_allow' ), 10, 3 );

		if ( ! $url_allowed ) {
			return json_encode( array( 'e' => 'Because it uses an internal host, and because this WordPress install is locked-down, you must enable the constant BACKUPPLUS_ALLOW_GET_UNSAFE_URLS before the given URL can be fetched.' ) );
		}

		if ( $curl ) {
			if ( ! function_exists( 'curl_exec' ) ) {
				return json_encode( array( 'e' => 'No Curl installed' ) );
				die;
			}
			$ch = curl_init();
			curl_setopt( $ch, CURLOPT_URL, $uri );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
			curl_setopt( $ch, CURLOPT_FAILONERROR, true );
			curl_setopt( $ch, CURLOPT_HEADER, false );
			curl_setopt( $ch, CURLOPT_VERBOSE, true );
			curl_setopt( $ch, CURLOPT_STDERR, $output = fopen( 'php://temp', 'w+' ) );
			$response = curl_exec( $ch );
			$error    = curl_error( $ch );
			$getinfo  = curl_getinfo( $ch );
			if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
				curl_close( $ch );
			} else {
				unset( $ch ); // On PHP 8+, curl_close() is a no-op (deprecated in 8.5); unset the handle instead.
			}
			rewind( $output );
			$verb = stream_get_contents( $output );

			$resp = array();
			if ( false === $response ) {
				$resp['e'] = htmlspecialchars( $error );
			}
			$resp['r'] = ( empty( $response ) ) ? '' : htmlspecialchars( substr( $response, 0, 2048 ) );

			if ( ! empty( $verb ) ) {
				$resp['r'] = htmlspecialchars( $verb ) . "\n\n" . $resp['r'];
			}

			return json_encode( $resp );
		} else {
			$response = wp_remote_get( $uri, array( 'timeout' => 10 ) );
			if ( is_wp_error( $response ) ) {
				return json_encode( array( 'e' => htmlspecialchars( $response->get_error_message() ) ) );
			}
			return json_encode(
				array(
					'r'             => wp_remote_retrieve_response_code( $response ) . ': ' . htmlspecialchars( substr( wp_remote_retrieve_body( $response ), 0, 2048 ) ),
					'code'          => wp_remote_retrieve_response_code( $response ),
					'html_response' => htmlspecialchars( substr( wp_remote_retrieve_body( $response ), 0, 2048 ) ),
					'response'      => $response,
				)
			);
		}
	}
}
