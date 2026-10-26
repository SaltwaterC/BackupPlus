<?php

/**
 * Database point-in-time backup chains. Ordinary logical and file backups remain independent.
 *
 * The methods live in the traits in includes/pitr/, grouped by concern: scheduling and settings, chain state,
 * capture, history and status, recovery planning and verification, recovery and restore, and retention.
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}
require_once __DIR__ . '/class-pitr-process.php';
require_once __DIR__ . '/class-pitr-manifest.php';
require_once __DIR__ . '/class-pitr-binlog.php';
require_once __DIR__ . '/class-pitr-database.php';
require_once __DIR__ . '/class-pitr-storage.php';

require_once __DIR__ . '/pitr/trait-scheduling.php';
require_once __DIR__ . '/pitr/trait-state.php';
require_once __DIR__ . '/pitr/trait-capture.php';
require_once __DIR__ . '/pitr/trait-history.php';
require_once __DIR__ . '/pitr/trait-recovery-plan.php';
require_once __DIR__ . '/pitr/trait-recovery.php';
require_once __DIR__ . '/pitr/trait-retention.php';

class BackupPlus_PITR {
	use BackupPlus_PITR_Orchestrator_Scheduling;
	use BackupPlus_PITR_Orchestrator_State;
	use BackupPlus_PITR_Orchestrator_Capture;
	use BackupPlus_PITR_Orchestrator_History;
	use BackupPlus_PITR_Orchestrator_Recovery_Plan;
	use BackupPlus_PITR_Orchestrator_Recovery;
	use BackupPlus_PITR_Orchestrator_Retention;

	const OPTION = 'backupplus_pitr';
	const STATUS = 'backupplus_pitr_status';
	const HOOK   = 'backupplus_pitr_capture';
	const BASELINE_HOOK = 'backupplus_pitr_baseline_check';
	const SCHEDULE_STATE = 'backupplus_pitr_schedule_state';
	private $root;
	protected $work;
	private $lock;
	private $state;
	protected $config;

	public function __construct( $config = null ) {
		global $backupplus;
		$this->config = null === $config ? self::config() : $config;
		$this->root   = BackupPlus_PITR_Process::directory( $backupplus->backups_dir_location() . '/pitr' );
		if ( ! is_file( $this->root . '/.htaccess' ) ) {
			BackupPlus_PITR_Process::private_file( $this->root . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
		if ( ! is_file( $this->root . '/index.php' ) ) {
			BackupPlus_PITR_Process::private_file( $this->root . '/index.php', "<?php exit;\n" );
		}
		$mask = umask( 0077 );
		try {
			$this->lock = fopen( $this->root . '/lock', 'c+b' );
		} finally {
			umask( $mask );
		}
		if ( ! $this->lock || ! flock( $this->lock, LOCK_EX | LOCK_NB ) ) {
			throw new RuntimeException( 'A PITR capture, restore or deletion is already running.' );
		}
		$this->state = $this->read_state();
		$this->work  = BackupPlus_PITR_Process::directory( $this->root . '/work-' . bin2hex( random_bytes( 16 ) ) );
		$this->reap_work();
	}

	public function __destruct() {
		if ( $this->work && is_dir( $this->work ) ) {
			self::remove_work( $this->work );
		}
		if ( is_resource( $this->lock ) ) {
			flock( $this->lock, LOCK_UN );
			fclose( $this->lock );
		}
	}
}
