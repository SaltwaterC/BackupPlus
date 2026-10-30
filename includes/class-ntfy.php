<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

/** Publishes notifications to an ntfy topic, in addition to the report emails. */
class BackupPlus_Ntfy {

	const OPTION         = 'backupplus_ntfy';
	const DEFAULT_SERVER = 'https://ntfy.sh';
	// ntfy turns longer messages into attachments, which a report should never be.
	const MAX_MESSAGE = 4000;

	/**
	 * Settings sanitizer: a topic is what enables the notifications, so nothing is kept without a valid one.
	 *
	 * @param  mixed $input The submitted settings
	 * @return array The server, topic and token, or an empty array when ntfy is not configured
	 */
	public static function sanitize( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}
		$topic = isset( $input['topic'] ) && is_string( $input['topic'] ) ? trim( $input['topic'] ) : '';
		if ( ! preg_match( '/\A[A-Za-z0-9_-]{1,64}\z/D', $topic ) ) {
			return array();
		}
		$server = isset( $input['server'] ) && is_string( $input['server'] ) ? trim( $input['server'] ) : '';
		$parts  = '' === $server ? false : wp_parse_url( $server );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! isset( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			$server = self::DEFAULT_SERVER;
		}
		// The token travels in a header, so it can only be visible ASCII.
		$token = isset( $input['token'] ) && is_string( $input['token'] ) ? trim( $input['token'] ) : '';
		if ( ! preg_match( '/\A[\x21-\x7e]+\z/D', $token ) ) {
			$token = '';
		}
		return array(
			'server' => rtrim( $server, '/' ),
			'topic'  => $topic,
			'token'  => $token,
		);
	}

	/** The saved settings when they are usable, null otherwise. */
	public static function config() {
		$config = self::sanitize( BackupPlus_Options::get_backupplus_option( self::OPTION, array() ) );
		return $config ? $config : null;
	}

	public static function enabled() {
		return null !== self::config();
	}

	/**
	 * Publish one notification.
	 *
	 * @param  string   $title    Notification title
	 * @param  string   $message  Notification body; shortened to what ntfy accepts as a plain message
	 * @param  int      $priority ntfy priority, 1 (min) to 5 (max)
	 * @param  string[] $tags     ntfy tags or emoji short codes
	 * @param  string   $click    URL opened when the notification is tapped
	 * @return bool Whether the server accepted it; false as well when ntfy is not configured
	 */
	public static function notify( $title, $message, $priority = 3, $tags = array(), $click = '' ) {
		$config = self::config();
		if ( null === $config ) {
			return false;
		}
		$payload = array(
			'topic'    => $config['topic'],
			'title'    => $title,
			'message'  => self::shorten( $message ),
			'priority' => max( 1, min( 5, (int) $priority ) ),
			'tags'     => array_values( $tags ),
		);
		if ( '' !== $click ) {
			$payload['click'] = $click;
		}
		$headers = array( 'Content-Type' => 'application/json' );
		if ( '' !== $config['token'] ) {
			$headers['Authorization'] = 'Bearer ' . $config['token'];
		}
		$response = wp_remote_post(
			$config['server'],
			array(
				'timeout'     => 15,
				// A redirect would replay the token to whichever host the server names.
				'redirection' => 0,
				'headers'     => $headers,
				'body'        => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			self::log( 'ntfy delivery failed: ' . $response->get_error_message() );
			return false;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			self::log( 'ntfy delivery failed: HTTP ' . $code . ' - ' . wp_remote_retrieve_response_message( $response ) );
			return false;
		}
		return true;
	}

	private static function shorten( $message ) {
		$message = (string) $message;
		if ( strlen( $message ) <= self::MAX_MESSAGE ) {
			return $message;
		}
		$marker = "\n...";
		$cut    = function_exists( 'mb_strcut' ) ? mb_strcut( $message, 0, self::MAX_MESSAGE - strlen( $marker ), 'UTF-8' ) : substr( $message, 0, self::MAX_MESSAGE - strlen( $marker ) );
		return $cut . $marker;
	}

	private static function log( $line ) {
		global $backupplus;
		if ( is_object( $backupplus ) && is_callable( array( $backupplus, 'log' ) ) ) {
			$backupplus->log( $line );
		}
	}
}
