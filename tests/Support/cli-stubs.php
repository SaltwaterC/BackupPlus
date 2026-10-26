<?php
/**
 * WordPress and WP-CLI shims for the CLI tests. Loaded once per process by CliTest.
 *
 * The options and the cron array live in $GLOBALS['options'] and $GLOBALS['cron'], which the test binds to properties.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'BACKUPPLUS_DIR', dirname( __DIR__, 2 ) );
define( 'WP_CONTENT_DIR', __DIR__ );
define( 'WP_PLUGIN_DIR', __DIR__ );
define( 'WPMU_PLUGIN_DIR', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

function add_action() {
}

function add_filter() {
}

function apply_filters( $name, $value ) {

	return $value;
}

function __( $text ) {

	return $text;
}

function get_option( $name, $default = false ) {

	global $options;

	return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
}

function get_site_option( $name, $default = false ) {

	return get_option( $name, $default );
}

function update_site_option( $name, $value ) {

	global $options;

	$options[ $name ] = $value;
}

function wp_cache_delete() {
}

function is_multisite() {

	return false;
}

function _get_cron_array() {

	global $cron;

	return $cron;
}

function is_wp_error( $value ) {

	return $value instanceof WP_Error;
}

function wp_get_schedules() {

	return array(
		'daily'  => array( 'interval' => 86400 ),
		'weekly' => array( 'interval' => 604800 ),
	);
}

class WP_Error {


	private $message;


	public function __construct( $code, $message ) {

		$this->message = $message;
	}


	public function get_error_message() {

		return $this->message;
	}


}

class BackupPlus_Options {


	public static function get_backupplus_option( $name, $default = null ) {

		return get_option( $name, $default );
	}


	public static function update_backupplus_option( $name, $value ) {

		update_site_option( $name, $value );
	}


}

class BackupPlus_Credential_Key_Store {
	const BACKUP_ENTITY = 'credential-keys';


	public static function exclude() {

		return false;
	}


}

class CLI_Test_DB {


	public $last_error = '';


	public function get_col( $query ) {

		return array( 'wp_posts', 'wp_options', 'other' );
	}


}

class CLI_Test_Backup {


	public $job = array();


	public $directory;


	public function jobdata_get( $key, $default = null ) {

		return isset( $this->job[ $key ] ) ? $this->job[ $key ] : $default;
	}


	public function backups_dir_location() {

		return $this->directory . '/archives';
	}


	public function get_table_prefix() {

		return 'wp_';
	}


	public function get_backupable_file_entities() {

		$entities = array(
			'plugins' => $this->directory,
			'wpcore'  => $this->directory,
		);

		foreach ( BackupPlus_Backup_Sources::sources() as $id => $source ) {

			$entities[ $id ] = $source['path'];

		}

		return BackupPlus_Backup_Sources::frozen_entities( $entities, false );
	}


}

class WP_CLI {


	public static $runs = 0;


	public static function get_config() {

		return false;
	}


	public static function launch_self( $command, $args, $flags ) {

		global $cron;

		++self::$runs;

		$cron = array();

		BackupPlus_CLI::finished(
			$args[0],
			'success',
			array(
				'backup_time'           => 10,
				'incremental_base_time' => 7,
			),
			array(
				'errors'   => 0,
				'warnings' => 0,
				'messages' => array(),
			)
		);

		return (object) array( 'return_code' => 0 );
	}


}

require BACKUPPLUS_DIR . '/includes/class-backup-sources.php';

require BACKUPPLUS_DIR . '/includes/class-backup-schedule.php';

require BACKUPPLUS_DIR . '/includes/class-cli.php';
