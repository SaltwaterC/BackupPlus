<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Transfers to remote storage stream files with resume and progress callbacks, which the WordPress HTTP API cannot do.

/** HTTP API options. */

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
}
