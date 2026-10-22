<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}

/** OneDrive's administrator consent flow; state is bound to user and instance. */
trait BackupPlus_OneDrive_OAuth {
	public function oauth_redirect_uri() {
		return BackupPlus_Options::admin_page_url() . '?action=backupplusmethod-onedrive-auth&page=backupplus'; }
	public function authorization_url() {
		$options = $this->get_options();
		if ( empty( $options['client_id'] ) || empty( $options['client_secret'] ) ) {
			throw new RuntimeException( 'Save a Microsoft application ID and secret before connecting.' );
		}
		$tenant = $options['tenant'] ?? 'common';
		if ( ! preg_match( '/^[a-zA-Z0-9.-]+$/', $tenant ) ) {
			throw new RuntimeException( 'Invalid Microsoft tenant.' );
		}
		$state                  = bin2hex( random_bytes( 32 ) ) . ':' . $this->get_instance_id();
		$options['oauth_state'] = array(
			'hash'    => hash( 'sha256', $state ),
			'user'    => get_current_user_id(),
			'expires' => time() + 600,
		);
		$this->set_options( $options, true );
		return 'https://login.microsoftonline.com/' . rawurlencode( $tenant ) . '/oauth2/v2.0/authorize?' . http_build_query(
			array(
				'client_id'     => $options['client_id'],
				'response_type' => 'code',
				'response_mode' => 'query',
				'redirect_uri'  => $this->oauth_redirect_uri(),
				'scope'         => 'offline_access Files.ReadWrite',
				'state'         => $state,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}
	public function complete_oauth( $state, $code ) {
		$options = $this->get_options();
		$stored  = $options['oauth_state'] ?? array();
		if ( ! is_string( $state ) || ! is_string( $code ) || '' === $code || empty( $stored['hash'] ) || ( $stored['user'] ?? 0 ) !== get_current_user_id() || ( $stored['expires'] ?? 0 ) < time() || ! hash_equals( $stored['hash'], hash( 'sha256', $state ) ) ) {
			throw new RuntimeException( 'Invalid or expired OneDrive authorization state.' );
		}
		unset( $options['oauth_state'] );
		$this->set_options( $options, true );
		$http     = new BackupPlus_Remote_Storage_HTTP();
		$url      = 'https://login.microsoftonline.com/' . rawurlencode( $options['tenant'] ?? 'common' ) . '/oauth2/v2.0/token';
		$response = $http->request(
			'POST',
			$url,
			array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			http_build_query(
				array(
					'grant_type'    => 'authorization_code',
					'client_id'     => $options['client_id'],
					'client_secret' => $options['client_secret'],
					'redirect_uri'  => $this->oauth_redirect_uri(),
					'code'          => $code,
				),
				'',
				'&',
				PHP_QUERY_RFC3986
			)
		);
		$data     = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || empty( $data['refresh_token'] ) ) {
			throw new RuntimeException( 'Microsoft did not authorize offline access. Reconnect the account.' );
		}
		$options['refresh_token'] = $data['refresh_token'];
		$this->set_options( $options, true );
		return true;
	}
	public function action_auth() {
		if ( ! BackupPlus_Options::user_can_manage() ) {
			throw new RuntimeException( 'Permission denied.' );
		}
		if ( isset( $_GET['state'] ) ) {
			$this->complete_oauth( wp_unslash( $_GET['state'] ), wp_unslash( $_GET['code'] ?? '' ) );
			wp_safe_redirect( BackupPlus_Options::admin_page_url() . '?page=backupplus' );
		} else {
			if ( ! wp_verify_nonce( $_GET['nonce'] ?? '', 'storage_auth_nonce' ) ) {
				throw new RuntimeException( 'Invalid authorization nonce.' );
			}
			wp_redirect( $this->authorization_url() );
		}
		exit;
	}
	public function do_deauthenticate_storage( $instance_id ) {
		$options = $this->get_options();
		unset( $options['refresh_token'], $options['oauth_state'] );
		$this->set_options( $options, true, $instance_id );
	}
}
