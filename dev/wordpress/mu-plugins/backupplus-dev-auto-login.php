<?php
/**
 * Plugin Name: BackupPlus Development Auto-login
 * Description: Provides a token-protected local administrator login for the Podman development site.
 * License: GPL-3.0-only
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

add_filter(
	'http_request_args',
	static function ( $args, $url ) {
		$ca_bundle  = '/etc/ssl/certs/host-ca-certificates.crt';
		$url_scheme = is_string( $url ) ? parse_url( $url, PHP_URL_SCHEME ) : null;

		if ( is_array( $args ) && is_string( $url_scheme ) && 'https' === strtolower( $url_scheme ) && is_readable( $ca_bundle ) ) {
			$args['sslcertificates'] = $ca_bundle;
		}

		return $args;
	},
	10,
	2
);

if ( 'local' !== wp_get_environment_type() ) {
	return;
}

add_action(
	'init',
	function () {
		if ( ! isset( $_GET['backupplus_dev_login'] ) || ! is_string( $_GET['backupplus_dev_login'] ) ) {
			return;
		}

		$token          = getenv( 'DEV_LOGIN_TOKEN' );
		$provided_token = wp_unslash( $_GET['backupplus_dev_login'] );
		if ( ! is_string( $token ) || '' === $token || ! hash_equals( $token, $provided_token ) ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}

		$admin_login = getenv( 'WP_ADMIN_USER' );
		$user        = get_user_by( 'login', is_string( $admin_login ) && '' !== $admin_login ? $admin_login : 'admin' );
		if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
			status_header( 403 );
			nocache_headers();
			exit;
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true, is_ssl() );
		wp_safe_redirect( admin_url() );
		exit;
	},
	1
);
