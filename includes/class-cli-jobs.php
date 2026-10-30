<?php
/** WP-CLI commands for running backup jobs: listing, cancelling, resuming and reading logs. */
if ( ! defined( 'ABSPATH' ) ) { die( 'No direct access allowed' ); }

/**
 * Inspect and control running backup jobs and read their logs.
 */
class BackupPlus_CLI_Jobs {

	private static function nonce( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^[a-f0-9]{12}$/D', $value ) ) { throw new InvalidArgumentException( 'Use a 12 character job ID from jobs list.' ); }
		return $value;
	}

	/** Jobs with a pending resumption, oldest first. */
	public static function rows() {
		global $backupplus;
		BackupPlus_CLI::fresh( 'cron' );
		$rows = array();
		foreach ( (array) _get_cron_array() as $time => $hooks ) {
			if ( ! isset( $hooks['backupplus_backup_resume'] ) ) { continue; }
			foreach ( $hooks['backupplus_backup_resume'] as $event ) {
				if ( ! isset( $event['args'][0], $event['args'][1] ) ) { continue; }
				$nonce   = $event['args'][1];
				$jobdata = $backupplus->jobdata_getarray( $nonce );
				$jobdata = is_array( $jobdata ) ? $jobdata : array();
				$started = isset( $jobdata['backup_time'] ) ? (int) $jobdata['backup_time'] : 0;
				$rows[]  = array(
					'nonce'        => $nonce,
					'type'         => isset( $jobdata['job_type'] ) ? $jobdata['job_type'] : 'backup',
					'status'       => empty( $jobdata['jobstatus'] ) ? 'unknown' : $jobdata['jobstatus'],
					'started'      => $started ? gmdate( 'Y-m-d H:i:s', $started ) : null,
					'resumption'   => (int) $event['args'][0],
					'next_run'     => (int) $time,
					'next_run_utc' => gmdate( 'Y-m-d H:i:s', (int) $time ),
					'overdue'      => (int) $time < time() ? 1 : 0,
					'label'        => isset( $jobdata['label'] ) ? $jobdata['label'] : '',
				);
			}
		}
		return $rows;
	}

	/**
	 * List jobs that have a scheduled resumption.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format: table, json or csv.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated output fields.
	 *
	 * @subcommand list
	 */
	public function list_jobs( $args, $assoc ) {
		try {
			BackupPlus_CLI::output( self::rows(), $assoc, array( 'nonce', 'type', 'status', 'started', 'resumption', 'next_run_utc', 'overdue', 'label' ), array( 'nonce', 'type', 'status', 'started', 'resumption', 'next_run', 'next_run_utc', 'overdue', 'label' ) );
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}

	/**
	 * Cancel a running job and remove its scheduled resumption.
	 *
	 * ## OPTIONS
	 *
	 * <nonce>
	 * : Job ID from jobs list.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 */
	public function cancel( $args, $assoc ) {
		try {
			$nonce = self::nonce( $args[0] );
			WP_CLI::confirm( 'Cancel backup job ' . $nonce . '?', $assoc );
			BackupPlus_CLI::fresh( 'cron' );
			$result = BackupPlus_CLI::admin()->activejobs_delete( $nonce );
			if ( 'Y' !== $result['ok'] ) { throw new RuntimeException( wp_strip_all_tags( $result['m'] ) ); }
			WP_CLI::success( 'Job cancelled: ' . $nonce );
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}

	/**
	 * Run the next resumption of a job now, for example when WP-Cron is not running.
	 *
	 * ## OPTIONS
	 *
	 * <nonce>
	 * : Job ID from jobs list.
	 *
	 * [--force]
	 * : Run the resumption even though it is not yet due.
	 */
	public function resume( $args, $assoc ) {
		try {
			$nonce  = self::nonce( $args[0] );
			$events = BackupPlus_CLI::events( $nonce );
			if ( ! $events ) { throw new RuntimeException( 'This job has no scheduled resumption.' ); }
			$event = $events[0];
			if ( $event['time'] > time() && ! isset( $assoc['force'] ) ) { throw new RuntimeException( 'The next resumption is due in ' . ( $event['time'] - time() ) . ' seconds. Use --force to run it now.' ); }
			if ( ! defined( 'BACKUPPLUS_FOLLOWUPS_ALLOWED' ) ) { define( 'BACKUPPLUS_FOLLOWUPS_ALLOWED', true ); }
			wp_unschedule_event( $event['time'], $event['hook'], $event['args'] );
			do_action_ref_array( $event['hook'], $event['args'] );
			$next = BackupPlus_CLI::events( $nonce );
			if ( $next ) { WP_CLI::success( 'Resumption ran; the job continues at ' . gmdate( 'Y-m-d H:i:s', $next[0]['time'] ) . ' UTC.' ); }
			else { WP_CLI::success( 'Resumption ran; the job has no further scheduled resumptions.' ); }
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}

	/**
	 * Print a job log; the most recently modified log when no job is given.
	 *
	 * ## OPTIONS
	 *
	 * [<nonce>]
	 * : Job ID from jobs list or backup history.
	 *
	 * [--tail=<lines>]
	 * : Only print this many trailing lines.
	 */
	public function log( $args, $assoc ) {
		global $backupplus;
		try {
			if ( isset( $args[0] ) ) { $nonce = self::nonce( $args[0] ); }
			else {
				$latest = $backupplus->last_modified_log();
				$nonce  = $latest[2];
				if ( '' === $nonce ) { throw new RuntimeException( 'There are no logs.' ); }
			}
			if ( isset( $assoc['tail'] ) && ! preg_match( '/^[1-9][0-9]{0,8}$/D', $assoc['tail'] ) ) { throw new InvalidArgumentException( '--tail must be a positive integer.' ); }
			$path = $backupplus->backups_dir_location() . '/log.' . $nonce . '.txt';
			if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) { throw new RuntimeException( 'The log file could not be read.' ); }
			if ( ! isset( $assoc['tail'] ) ) {
				$handle = fopen( $path, 'r' );
				if ( ! $handle ) { throw new RuntimeException( 'The log file could not be read.' ); }
				while ( false !== ( $buffer = fgets( $handle, 8192 ) ) ) { fwrite( STDOUT, $buffer ); }
				fclose( $handle );
				return;
			}
			// Keep only the requested trailing lines, never the whole file.
			$ring = array();
			$file = new SplFileObject( $path, 'r' );
			foreach ( $file as $line ) {
				if ( '' === $line ) { continue; }
				$ring[] = $line;
				if ( count( $ring ) > (int) $assoc['tail'] ) { array_shift( $ring ); }
			}
			fwrite( STDOUT, implode( '', $ring ) );
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}
}
