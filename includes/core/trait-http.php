<?php

/** HTTP API options and outgoing connection details. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Core_Http {

	/**
	 * WP action http_api_curl
	 *
	 * @param Resource $handle A curl handle returned by curl_init()
	 *
	 * @return the handle (having potentially had some options set upon it)
	 */
	public function http_api_curl( $handle ) {
		if ( defined( 'BACKUPPLUS_IPV4_ONLY' ) && BACKUPPLUS_IPV4_ONLY ) {
			curl_setopt( $handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
		}
		return $handle;
	}

	/**
	 * Used as a central location (to avoid repetition) to register or de-register hooks into the WP HTTP API
	 *
	 * @param Boolean $register - true to register, false to de-register
	 */
	public function register_wp_http_option_hooks( $register = true ) {
		if ( $register ) {
			add_filter( 'http_request_args', array( $this, 'modify_http_options' ) );
			add_action( 'http_api_curl', array( $this, 'http_api_curl' ) );
		} else {
			remove_filter( 'http_request_args', array( $this, 'modify_http_options' ) );
			remove_action( 'http_api_curl', array( $this, 'http_api_curl' ) );
		}
	}

	/**
	 * Used as a WordPress options filter (http_request_args)
	 *
	 * @param Array $opts - existing options
	 *
	 * @return Array - modified options
	 */
	public function modify_http_options( $opts ) {

		if ( ! is_array( $opts ) ) {
			return $opts;
		}

		if ( ! BackupPlus_Options::get_backupplus_option( 'backupplus_ssl_useservercerts' ) ) {
			$opts['sslcertificates'] = BACKUPPLUS_DIR . '/includes/cacert.pem';
		}

		$opts['sslverify'] = BackupPlus_Options::get_backupplus_option( 'backupplus_ssl_disableverify' ) ? false : true;

		return $opts;
	}

	/**
	 * Get the current outgoing IP address. Use this wisely; of course, it's not guaranteed to always be the same.
	 *
	 * @param Boolean $use_ipv6_service True to check the IP address using the IPv6 service with IPv4 fallback, false to use the IPv4 service only
	 * @return String|Boolean - returns false upon failure
	 */
	public function get_outgoing_ip_address( $use_ipv6_service = false ) {
		$urls = array( 'https://ipvigilante.com/json' );
		if ( $use_ipv6_service ) {
			array_unshift( $urls, 'http://ip6.me/api' );
		}
		$urls = apply_filters( 'backupplus_get_outgoing_ip_address', $urls );
		foreach ( $urls as $url ) {
			$ip_lookup = wp_remote_get( $url, array( 'timeout' => 6 ) );
			if ( 200 === wp_remote_retrieve_response_code( $ip_lookup ) ) {
				$body = wp_remote_retrieve_body( $ip_lookup );
				$info = json_decode( $body, true );
				if ( is_array( $info ) ) {
					if ( ! empty( $info['status'] ) && ! empty( $info['data'] ) && 'success' === $info['status'] ) {
					}
					if ( ! empty( $info['data']['ipv4'] ) ) {
						return $info['data']['ipv4'];
					}
					if ( ! empty( $info['data']['ipv6'] ) ) {
						return $info['data']['ipv6'];
					}
				} elseif ( preg_match_all( '/([^"\',]+|"(?:[^"]|")*?"|\'(?:[^\']|\')*?\')?(?:,|$)/is', $body, $matches ) ) { // https://regex101.com/r/Q8XjT4/1/
					$matches[1][0] = strtolower( trim( $matches[1][0], ',\'" ' ) );
					if ( ( 'ipv4' === $matches[1][0] || 'ipv6' === $matches[1][0] ) && ! empty( $matches[1][1] ) ) {
						return trim( $matches[1][1], ',\'" ' );
					}
				}
			}
		}
		return false;
	}
}
