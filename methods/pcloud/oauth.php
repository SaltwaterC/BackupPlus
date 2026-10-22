<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}

/** pCloud app consent, with a provider-owned region check and one-use state. */
trait BackupPlus_Pcloud_OAuth {
	public function oauth_redirect_uri() {
		return BackupPlus_Options::admin_page_url() . '?action=backupplusmethod-pcloud-auth&page=backupplus'; }
	public function authorization_url() {
		$options = $this->get_options();
		if ( empty( $options['client_id'] ) || empty( $options['client_secret'] ) ) {
			throw new RuntimeException( 'Save a pCloud application ID and secret before connecting.' );
		}
		$state                  = bin2hex( random_bytes( 32 ) ) . ':' . $this->get_instance_id();
		$options['oauth_state'] = array(
			'hash'    => hash( 'sha256', $state ),
			'user'    => get_current_user_id(),
			'expires' => time() + 600,
		);
		$this->set_options( $options, true );
		return 'https://my.pcloud.com/oauth2/authorize?' . http_build_query(
			array(
				'client_id'     => $options['client_id'],
				'response_type' => 'code',
				'redirect_uri'  => $this->oauth_redirect_uri(),
				'state'         => $state,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}
	public function complete_oauth( $state, $code, $location ) {
		$options = $this->get_options();
		$stored  = $options['oauth_state'] ?? array();
		if ( ! is_string( $state ) || ! is_string( $code ) || '' === $code || empty( $stored['hash'] ) || ( $stored['user'] ?? 0 ) !== get_current_user_id() || ( $stored['expires'] ?? 0 ) < time() || ! hash_equals( $stored['hash'], hash( 'sha256', $state ) ) ) {
			throw new RuntimeException( 'Invalid or expired pCloud authorization state.' );
		}
		if ( ! in_array( (string) $location, array( '1', '2' ), true ) ) {
			throw new RuntimeException( 'Invalid pCloud authorization region.' );
		}
		unset( $options['oauth_state'] );
		$this->set_options( $options, true );
		$http     = new BackupPlus_Remote_Storage_HTTP();
		$url      = 'https://' . ( '1' === (string) $location ? 'api.pcloud.com' : 'eapi.pcloud.com' ) . '/oauth2_token';
		$response = $http->request(
			'POST',
			$url,
			array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			http_build_query(
				array(
					'client_id'     => $options['client_id'],
					'client_secret' => $options['client_secret'],
					'code'          => $code,
				),
				'',
				'&',
				PHP_QUERY_RFC3986
			)
		);
		$data     = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || 0 !== ( $data['result'] ?? -1 ) || empty( $data['access_token'] ) ) {
			throw new RuntimeException( 'pCloud did not authorize the application.' );
		}
		$options['pclauth']  = $data['access_token'];
		$options['location'] = (string) $location;
		$this->set_options( $options, true );
		return true;
	}
	public function action_auth() {
		if ( ! BackupPlus_Options::user_can_manage() ) {
			throw new RuntimeException( 'Permission denied.' );
		}
		if ( isset( $_GET['state'] ) ) {
			$this->complete_oauth( wp_unslash( $_GET['state'] ), wp_unslash( $_GET['code'] ?? '' ), wp_unslash( $_GET['locationid'] ?? '' ) );
			wp_safe_redirect( BackupPlus_Options::admin_page_url() . '?page=backupplus' );
		} else {
			if ( ! wp_verify_nonce( $_GET['nonce'] ?? '', 'storage_auth_nonce' ) ) {
				throw new RuntimeException( 'Invalid authorization nonce.' );
			}
			wp_redirect( $this->authorization_url() );
		}
		exit;
	}
}
