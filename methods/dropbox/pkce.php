<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed.' );
}

require_once __DIR__ . '/Dropbox2/Exception.php';

/**
 * Dropbox authorization with PKCE and no redirect: Dropbox shows the code to the administrator, who pastes it into
 * BackupPlus, and the site exchanges it with Dropbox directly. No app secret, callback or relay service is involved.
 */
class BackupPlus_Dropbox_PKCE {
	/** Key of the BackupPlus Dropbox app. While it is empty, each connection needs its own app key. */
	const APP_KEY = '';

	const AUTHORIZE_URL = 'https://www.dropbox.com/oauth2/authorize';

	const TOKEN_URL = 'https://api.dropboxapi.com/oauth2/token';

	/** Seconds a started authorization can be completed in. */
	const LIFETIME = 600;

	const SCOPES = 'account_info.read files.content.write files.content.read files.metadata.read';

	/** The built-in app key, which BACKUPPLUS_DROPBOX_CLIENT_ID replaces. */
	public static function default_app_key() {
		return defined( 'BACKUPPLUS_DROPBOX_CLIENT_ID' ) ? (string) BACKUPPLUS_DROPBOX_CLIENT_ID : self::APP_KEY;
	}

	/**
	 * Start an authorization.
	 *
	 * @param String $app_key - the Dropbox app key
	 * @return Array - the pending authorization to store encrypted, and the Dropbox address that shows the code
	 */
	public static function start( $app_key ) {
		$verifier = self::base64url( random_bytes( 32 ) );
		$pending  = (object) array(
			'verifier'  => $verifier,
			'client_id' => $app_key,
			'expires'   => time() + self::LIFETIME,
		);
		$query    = array(
			'client_id'             => $app_key,
			'response_type'         => 'code',
			'code_challenge'        => self::base64url( hash( 'sha256', $verifier, true ) ),
			'code_challenge_method' => 'S256',
			'token_access_type'     => 'offline',
			'scope'                 => self::SCOPES,
		);
		return array( $pending, self::AUTHORIZE_URL . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) );
	}

	/**
	 * Exchange the pasted code for a token in the format the Dropbox token storage keeps.
	 *
	 * @param stdClass|false $pending - the pending authorization from start()
	 * @param String         $code    - the code Dropbox displayed
	 * @return stdClass - the token
	 */
	public static function exchange( $pending, $code ) {
		if ( ! $pending instanceof stdClass || empty( $pending->verifier ) || empty( $pending->client_id ) || empty( $pending->expires ) || $pending->expires < time() ) {
			throw new Dropbox_Exception( __( 'This Dropbox authorization has expired or was not started. Follow the link to connect Dropbox, then paste the new code.', 'backupplus' ) );
		}
		if ( ! is_string( $code ) || ! preg_match( '/\A[A-Za-z0-9._~-]{8,512}\z/D', $code ) ) {
			throw new Dropbox_Exception( __( 'That is not a Dropbox authorization code. Copy the whole code that Dropbox displays.', 'backupplus' ) );
		}
		$body = self::post(
			array(
				'code'          => $code,
				'grant_type'    => 'authorization_code',
				'code_verifier' => $pending->verifier,
				'client_id'     => $pending->client_id,
			)
		);
		if ( empty( $body['access_token'] ) || empty( $body['refresh_token'] ) || ! isset( $body['expires_in'] ) ) {
			throw new Dropbox_Exception( __( 'Dropbox did not return a long-lived token.', 'backupplus' ) );
		}
		$token = new stdClass();
		// The OAuth 1 field names are kept because the rest of the bundled client reads them.
		$token->oauth_token        = $body['access_token'];
		$token->oauth_token_secret = $body['access_token'];
		$token->token_type         = isset( $body['token_type'] ) ? $body['token_type'] : 'bearer';
		$token->account_id         = isset( $body['account_id'] ) ? $body['account_id'] : '';
		$token->uid                = isset( $body['uid'] ) ? $body['uid'] : '';
		$token->refresh_token      = $body['refresh_token'];
		$token->expires_in         = time() + (int) $body['expires_in'] - 30;
		$token->client_id          = $pending->client_id;
		return $token;
	}

	/**
	 * Renew the access token of a stored token.
	 *
	 * @param stdClass $token     - the stored token
	 * @param String   $client_id - the app key the token was issued to
	 * @param String   $secret    - the app secret, only for connections authorized before PKCE
	 * @return stdClass - the token with its new access token and expiry
	 */
	public static function refresh( $token, $client_id, $secret = '' ) {
		$params = array(
			'grant_type'    => 'refresh_token',
			'refresh_token' => $token->refresh_token,
			'client_id'     => $client_id,
		);
		if ( '' !== $secret ) {
			$params['client_secret'] = $secret;
		}
		$body = self::post( $params );
		if ( empty( $body['access_token'] ) || ! isset( $body['expires_in'] ) ) {
			throw new Dropbox_Exception( 'Dropbox did not return a new access token.' );
		}
		$token->oauth_token        = $body['access_token'];
		$token->oauth_token_secret = $body['access_token'];
		$token->expires_in         = time() + (int) $body['expires_in'] - 30;
		return $token;
	}

	private static function post( $params ) {
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'body'        => $params,
			)
		);
		if ( is_wp_error( $response ) ) {
			/* translators: %s: Error message */
			throw new Dropbox_Exception( sprintf( __( 'Could not contact Dropbox: %s', 'backupplus' ), $response->get_error_message() ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $status || ! is_array( $body ) ) {
			$reason = 'HTTP ' . $status;
			foreach ( array( 'error_description', 'error' ) as $field ) {
				if ( is_array( $body ) && ! empty( $body[ $field ] ) && is_string( $body[ $field ] ) ) {
					$reason = $body[ $field ];
					break;
				}
			}
			/* translators: %s: Error reported by Dropbox */
			throw new Dropbox_Exception( sprintf( __( 'Dropbox refused the request: %s', 'backupplus' ), $reason ) );
		}
		return $body;
	}

	private static function base64url( $bytes ) {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}
}
