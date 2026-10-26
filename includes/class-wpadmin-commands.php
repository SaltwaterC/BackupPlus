<?php
//phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- we try to reduce overhead by bypassing WP APIs and other extra layers; Some custom complex queries tailored specifically to our needs, giving us full control over the SQL commands and data manipulation
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_print_r -- print_r is intentionally used to convert an array into a readable string or for controlled logging purposes.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- some query operations need to always receive the most up-to-date or actual data directly from the database, reducing the risk of serving stale information.
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- we use the set_error_handler() function to provide a flexible way of handling PHP errors according to our needs; we centralises error handling in one place and customises certain errors based on their severity and context.
// phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged -- some functions, like set_time_limit() and ini_set(), are used to temporarily change PHP configuration values based on the script's needs (e.g., processing large datasets or performing long operations).
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

/*
	See class-commands.php for explanation about how these classes work.
	The wp-admin-only commands live in traits grouped by concern, in includes/wpadmin-commands/: restore, file browser tree, notices and diagnostics/scheduling. This file keeps the constructor and the three commands that load sibling files of this directory with __DIR__ (pitr_setup, pitr_restore, restore_decryption), which a trait in a subdirectory could not do without changing the path.
*/

if ( ! class_exists( 'BackupPlus_Commands' ) ) {
	backupplus_try_include_file( 'includes/class-commands.php', 'require_once' );
}

require_once __DIR__ . '/wpadmin-commands/trait-restore.php';
require_once __DIR__ . '/wpadmin-commands/trait-jstree.php';
require_once __DIR__ . '/wpadmin-commands/trait-notices.php';
require_once __DIR__ . '/wpadmin-commands/trait-diagnostics.php';

/**
 * An extension, because commands available via wp-admin are a super-set of those which are available through all mechanisms
 */
class BackupPlus_WPAdmin_Commands extends BackupPlus_Commands {
	use BackupPlus_WPAdmin_Commands_Restore;
	use BackupPlus_WPAdmin_Commands_Jstree;
	use BackupPlus_WPAdmin_Commands_Notices;
	use BackupPlus_WPAdmin_Commands_Diagnostics;

	private $_uc_helper;

	private $_backupplus_admin;

	private $_backupplus;

	/**
	 * Constructor
	 *
	 * @param string $uc_helper The 'helper' needs to provide the method _backupplus_background_operation_started
	 */
	public function __construct( $uc_helper ) {
		$this->_uc_helper = $uc_helper;
		global $backupplus_admin, $backupplus;
		$this->_backupplus_admin = $backupplus_admin;
		$this->_backupplus       = $backupplus;
		parent::__construct( $uc_helper );
	}

	/** Backup-account credentials are accepted only through the protected admin dispatcher. */
	public function pitr_setup( $params ) {
		try {
			require_once __DIR__ . '/class-pitr-setup.php';
			return BackupPlus_PITR_Setup::request( $params );
		} catch ( Throwable $error ) { return array( 'error' => 'The backup-account check could not finish. Check that the private backup folder is writable, then retry.' ); }
	}

	/** Browser PITR restore accepts database credentials only for the current request. Private archive keys stay in the browser. */
	public function pitr_restore( $params ) {
		try {
			require_once __DIR__ . '/class-pitr-restore.php';
			return BackupPlus_PITR_Restore::request( $params );
		} catch ( Throwable $error ) { return array( 'error' => $error->getMessage() ); }
	}

	public function restore_decryption( $params ) {
		try {
			$operation = isset( $params['operation'] ) ? $params['operation'] : '';
			$ticket    = isset( $params['ticket'] ) ? $params['ticket'] : '';
			if ( 'manifest' === $operation ) {
				$timestamp = (int) $params['timestamp'];
				$backups   = BackupPlus_Backup_History::get_history();
				if ( ! isset( $backups[ $timestamp ] ) || ! isset( $params['entities'] ) || ! is_array( $params['entities'] ) ) {
					throw new \RuntimeException( 'Invalid backup selection.' );
				}
				if ( ! empty( $backups[ $timestamp ]['pitr'] ) ) {
					require_once __DIR__ . '/class-pitr-restore.php';
					return BackupPlus_PITR_Restore::manifest( $timestamp, isset( $params['point'] ) ? $params['point'] : '', $ticket );
				}
				return BackupPlus_Age_Restore::manifest( $backups[ $timestamp ], $timestamp, $params['entities'], $ticket );
			}
			if ( 'discard' === $operation ) {
				BackupPlus_Age_Restore::discard( $ticket );
				return array( 'discarded' => true );
			}
			if ( 'download' === $operation ) {
				$path = BackupPlus_Age_Restore::download( $ticket, $params['file'] );
				nocache_headers();
				header( 'Content-Type: application/octet-stream' );
				header( 'Content-Length: ' . filesize( $path ) );
				readfile( $path );
				die;
			}
			if ( 'upload' === $operation ) {
				if ( ! isset( $params['chunk'] ) || ! is_string( $params['chunk'] ) || strlen( $params['chunk'] ) > 699052 ) {
					throw new \RuntimeException( 'Invalid upload chunk.' );
				}
				$bytes = base64_decode( $params['chunk'], true );
				return BackupPlus_Age_Restore::upload( $ticket, $params['file'], $params['offset'], $bytes, isset( $params['size'] ) ? $params['size'] : null, isset( $params['hash'] ) ? $params['hash'] : '' );
			}
		} catch ( \Throwable $e ) {
			if ( ! empty( $params['point'] ) && $e instanceof \RuntimeException ) { return array( 'error' => $e->getMessage() ); }
			return array( 'error' => __( 'Could not transfer the restore archives. Retry the restore preparation.', 'backupplus' ) );
		}
		return array( 'error' => __( 'Invalid restore operation.', 'backupplus' ) );
	}
}
