<?php
/** WordPress shims for the backup schedule tests. Loaded once per test process. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'BACKUPPLUS_DIR', dirname( __DIR__, 2 ) );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
function add_action() {}
function add_filter() {}
function __( $text ) {
	return $text;
}
function wp_timezone() {
	return new DateTimeZone( 'Europe/London' );
}
function wp_get_schedules() {
	$schedules = array();
	foreach ( array(
		'every15minutes' => 900,
		'everyhour'      => 3600,
		'every2hours'    => 7200,
		'every4hours'    => 14400,
		'every8hours'    => 28800,
		'every90minutes' => 5400,
		'every7hours'    => 25200,
		'twicedaily'     => 43200,
		'daily'          => 86400,
		'weekly'         => 604800,
		'fortnightly'    => 1209600,
		'monthly'        => 2592000,
	) as $name => $interval ) {
		$schedules[ $name ] = array( 'interval' => $interval );
	}
	return $schedules;
}
class WP_Error {
	public $message;
	public function __construct( $code, $message ) {
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
}
class BackupPlus_Options {
	public static $options = array();
	public static function get_backupplus_option( $name, $default = null ) {
		return array_key_exists( $name, self::$options ) ? self::$options[ $name ] : $default;
	}
}
