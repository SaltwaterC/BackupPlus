<?php
/** Backups which must finish before WordPress is allowed to update installed code. Methods are grouped by concern in includes/pre-update-backups/. */
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

require_once __DIR__ . '/pre-update-backups/trait-batch.php';
require_once __DIR__ . '/pre-update-backups/trait-run.php';
require_once __DIR__ . '/pre-update-backups/trait-updates.php';
require_once __DIR__ . '/pre-update-backups/trait-interface.php';

class BackupPlus_Pre_Update_Backups {
	use BackupPlus_Pre_Update_Backups_Batch;
	use BackupPlus_Pre_Update_Backups_Run;
	use BackupPlus_Pre_Update_Backups_Updates;
	use BackupPlus_Pre_Update_Backups_Interface;

	const OPTION = 'backupplus_pre_update_batch';
	private static $instance;
	private $automatic_batch = '';
	private $request_id      = '';
	private $claims          = array();
	private $cli_targets     = array();
	private $cli_batch       = '';

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action( 'init', array( $this, 'wrap_automatic_updates' ), 100 );
		add_action( 'backupplus_pre_update_run', array( $this, 'run_backup' ) );
		add_action( 'backupplus_pre_update_continue', array( $this, 'continue_automatic' ) );
		add_action( 'backupplus_pre_update_prune', array( $this, 'prune' ) );
		add_action( 'backupplus_pre_update_finalize', array( $this, 'backup_finished' ), 10, 3 );
		add_action( 'backupplus_backup_finished', array( $this, 'backup_finished' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'pre_download' ), PHP_INT_MAX, 4 );
		add_action( 'shutdown', array( $this, 'release_claims' ) );
		add_action( 'wp_ajax_backupplus_pre_update', array( $this, 'ajax' ) );
		add_action( 'admin_post_backupplus_pre_update_log', array( $this, 'download_log' ) );
		add_action( 'admin_notices', array( $this, 'recovery_notice' ) );
		add_action( 'network_admin_notices', array( $this, 'recovery_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'load-update.php', array( $this, 'classic' ) );
		add_action( 'load-update-core.php', array( $this, 'classic' ) );
	}

	public static function enabled() {
		return (bool) BackupPlus_Options::get_backupplus_option( 'backupplus_autobackup_default', 1 );
	}
}
