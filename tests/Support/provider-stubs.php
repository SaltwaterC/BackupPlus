<?php
/** Shared WordPress/job shims for the storage provider tests. Loaded once per process by ProviderTestCase. */
define( 'BACKUPPLUS_DIR', dirname( __DIR__, 2 ) );
define( 'BACKUPPLUS_PROVIDER_FIXTURE', sys_get_temp_dir() . '/backupplus-provider-' . bin2hex( random_bytes( 8 ) ) );
mkdir( BACKUPPLUS_PROVIDER_FIXTURE );
define( 'ABSPATH', BACKUPPLUS_PROVIDER_FIXTURE . '/' );
define( 'BACKUPPLUS_WARN_EMAIL_SIZE', 1024 );
register_shutdown_function(
	function () {
		foreach ( glob( BACKUPPLUS_PROVIDER_FIXTURE . '/{,.}*[!.]', GLOB_BRACE ) as $file ) {
			unlink( $file );
		}
		rmdir( BACKUPPLUS_PROVIDER_FIXTURE );
	}
);

function __( $text, $domain = null ) {
	return $text; }
function _x( $text, $context, $domain = null ) {
	return $text; }
function esc_html( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_attr( $text ) {
	return esc_html( $text ); }
function esc_html__( $text, $domain = null ) {
	return esc_html( $text ); }
function esc_html_e( $text, $domain = null ) {
	echo esc_html( $text ); }
function trailingslashit( $text ) {
	return rtrim( $text, '/' ) . '/'; }
function untrailingslashit( $text ) {
	return rtrim( $text, '/' ); }
function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, $args ); }
function wp_create_nonce( $action ) {
	return 'test-nonce'; }
function is_wp_error( $value ) {
	return $value instanceof WP_Error; }
function backupplus_try_include_file( $path, $mode ) {
	require_once BACKUPPLUS_DIR . '/' . $path; }
function get_file_data( $file, $headers ) {
	$content = file_get_contents( $file );
	foreach ( $headers as $key => $header ) {
		$headers[ $key ] = preg_match( '/' . preg_quote( $header, '/' ) . ': (.*)/', $content, $match ) ? trim( $match[1] ) : '';
	}
	return $headers;
}

function add_filter( $name, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['provider_filters'][ $name ][ $priority ][] = $callback;
}
function remove_filter( $name, $callback, $priority = 10 ) {
	foreach ( $GLOBALS['provider_filters'][ $name ][ $priority ] ?? array() as $key => $value ) {
		if ( $callback === $value ) {
			unset( $GLOBALS['provider_filters'][ $name ][ $priority ][ $key ] );
		}
	}
}
function apply_filters( $name, $value, ...$args ) {
	$priorities = $GLOBALS['provider_filters'][ $name ] ?? array();
	ksort( $priorities );
	foreach ( $priorities as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$value = $callback( $value, ...$args );
		}
	}
	return $value;
}
function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {
	add_filter( $name, $callback, $priority, $accepted_args ); }
function remove_action( $name, $callback, $priority = 10 ) {
	remove_filter( $name, $callback, $priority ); }
function site_url() {
	return 'https://example.test'; }
function get_bloginfo( $key ) {
	return 'Provider test site'; }
function get_date_from_gmt( $date, $format ) {
	return gmdate( $format, strtotime( $date . ' UTC' ) ); }
function wp_remote_post( $url, $args ) {
	if ( isset( $GLOBALS['provider_post'] ) ) {
		return $GLOBALS['provider_post']( $url, $args );
	}
	throw new RuntimeException( 'Unexpected network request: ' . $url ); }
function wp_parse_url( $url ) {
	return parse_url( $url ); }
function wp_json_encode( $value ) {
	return json_encode( $value ); }
function wp_remote_retrieve_response_message( $response ) {
	return $response['response']['message'] ?? ''; }
function wp_mail( $to, $subject, $message, $headers, $attachments ) {
	$GLOBALS['provider_mail'][] = compact( 'to', 'subject', 'message', 'headers', 'attachments' );
	return $GLOBALS['provider_mail_success'];
}

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message; }
	public function get_error_code() {
		return $this->code; }
	public function get_error_message() {
		return $this->message; }
}
class WP_HTTP_Proxy {
	public function is_enabled() {
		return false; }
}
class BackupPlus_Options {
	public static $options = array();
	public static function get_backupplus_option( $key, $default = false ) {
		return self::$options[ $key ] ?? $default; }
	public static function update_backupplus_option( $key, $value ) {
		self::$options[ $key ] = $value; }
	public static function delete_backupplus_option( $key ) {
		unset( self::$options[ $key ] ); }
	public static function admin_page_url() {
		return 'https://example.test/wp-admin/options-general.php'; }
	public static function user_can_manage() {
		return true; }
}
class Provider_Test_Job {
	public $backup_methods;
	public $uploaded           = array();
	public $logs               = array();
	public $progress           = array();
	public $downloads          = array();
	public $data               = array();
	public $version            = 'test';
	public $backup_time        = 1700000000;
	public $current_resumption = 0;
	public function backups_dir_location() {
		return rtrim( ABSPATH, '/' ); }
	public function get_settings_keys() {
		return array_map(
			function ( $id ) {
				return 'backupplus_' . $id;
			},
			array_keys( $this->backup_methods )
		); }
	public function log( $message, ...$args ) {
		$this->logs[] = array( $message, $args[0] ?? 'notice' ); }
	public function log_wp_error( $error, ...$args ) {
		$this->log( $error->get_error_message(), 'error' );
		return false; }
	public function uploaded_file( $file ) {
		$this->uploaded[] = $file; }
	public function record_uploaded_chunk( $percent, ...$args ) {
		$this->progress[] = $percent; }
	public function jobdata_get( $key, $default = null ) {
		return $this->data[ $key ] ?? $default; }
	public function jobdata_set( $key, $value ) {
		$this->data[ $key ] = $value; }
	public function jobdata_delete( $key ) {
		unset( $this->data[ $key ] ); }
	public function chunked_download( $file, $provider, $size, $ranges = false, $storage = null, $chunk_size = null ) {
		$this->downloads[] = compact( 'file', 'size', 'ranges', 'storage', 'chunk_size' );
		return true;
	}
	public function verify_free_memory( $bytes ) {
		return false; }
	public function just_one_email( $email, $multiple = false ) {
		return $email; }
	public function get_email_from_header() {
		return 'backups@example.test'; }
	public function get_email_from_name_header() {
		return 'Backup sender'; }
	public function log_remove_warning( $key ) {
		$this->data['removed_warnings'][] = $key; }
}

require_once BACKUPPLUS_DIR . '/includes/class-storage-provider-factory.php';
require_once BACKUPPLUS_DIR . '/includes/class-storage-methods-interface.php';

function wp_tempnam( $name ) {
	return tempnam( ABSPATH, 'cloud-test-' ); }
function wp_kses_post( $html ) {
	return $html; }
function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $response ) {
	return $response['body'] ?? ''; }
function wp_remote_retrieve_header( $response, $name ) {
	$headers = array_change_key_case( $response['headers'] ?? array(), CASE_LOWER );
	return $headers[ strtolower( $name ) ] ?? '';
}
function wp_remote_request( $url, $args ) {
	$GLOBALS['cloud_request_args'][] = $args;
	return $GLOBALS['cloud_http']->request( $args['method'], $url, $args['headers'], $args['body'], $args['filename'] ?? null );
}
function get_current_user_id() {
	return $GLOBALS['cloud_user'] ?? 1; }
function do_action( $name, ...$args ) {
	foreach ( $GLOBALS['provider_filters'][ $name ] ?? array() as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$callback( ...$args );
		}
	}
}

/** Fake only the network boundary; clients still build and interpret wire messages. */
class Cloud_Test_HTTP {
	public $handler;
	public $calls    = array();
	public $fail     = false;
	public $truncate = false;
	public function request( $method, $url, $headers = array(), $body = null, $destination = null ) {
		$this->calls[] = compact( 'method', 'url', 'headers', 'body', 'destination' );
		if ( $this->fail ) {
			return self::response( 503, array( 'error' => 'unavailable' ) );
		}
		$response = ( $this->handler )( $method, $url, array_change_key_case( $headers, CASE_LOWER ), $body );
		if ( null !== $destination ) {
			file_put_contents( $destination, $this->truncate ? substr( $response['body'], 0, max( 0, strlen( $response['body'] ) - 1 ) ) : $response['body'] );
			$response['body'] = '';
		}
		return $response;
	}
	public function upload( $url, $source, $headers, $progress = null ) {
		$response = $this->request( 'PUT', $url, $headers, file_get_contents( $source ) );
		if ( $progress ) {
			$progress( filesize( $source ) );
		}
		return $response;
	}
	public static function response( $code, $data = '', $headers = array() ) {
		return array(
			'response' => array( 'code' => $code ),
			'headers'  => $headers,
			'body'     => is_array( $data ) ? json_encode( $data ) : $data,
		);
	}
}
