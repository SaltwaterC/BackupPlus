<?php
/** Choose which backup chains a bulk delete removes. */
if ( ! defined( 'ABSPATH' ) ) { die( 'No direct access allowed' ); }

class BackupPlus_CLI_Prune {

	private static function count( $value, $name ) {
		if ( ! is_string( $value ) || ! preg_match( '/^[1-9][0-9]{0,8}$/D', $value ) ) { throw new InvalidArgumentException( '--' . $name . ' must be a positive integer.' ); }
		return (int) $value;
	}

	/**
	 * Resolve explicit timestamps, or the age and count filters, to the chains to delete.
	 *
	 * Filters never select a chain that is protected from retention or that an active job or pending update needs; those
	 * are returned as skipped. Both filters must match: older than the given days and beyond the newest chains to keep.
	 *
	 * @param array    $history   History rows by timestamp.
	 * @param string[] $explicit  Timestamps named by the caller.
	 * @param array    $args      Command options.
	 * @param int      $now       Current time.
	 * @param callable $protected Receives a timestamp and its row; true when deletion must not touch it.
	 * @return array The timestamps to delete, newest first, and the skipped ones with their reason.
	 */
	public static function select( $history, $explicit, $args, $now, $protected ) {
		$filtered = isset( $args['older-than'] ) || isset( $args['keep'] );
		if ( $explicit && $filtered ) { throw new InvalidArgumentException( 'Name timestamps or use --older-than and --keep, not both.' ); }
		if ( ! $explicit && ! $filtered ) { throw new InvalidArgumentException( 'Name timestamps, or use --older-than and --keep.' ); }
		if ( $explicit ) {
			$result = array();
			foreach ( $explicit as $timestamp ) {
				if ( ! ctype_digit( $timestamp ) || ! isset( $history[ $timestamp ] ) ) { throw new InvalidArgumentException( 'Backup timestamp not found: ' . $timestamp ); }
				$result[] = (string) $timestamp;
			}
			return array(
				'delete'  => array_values( array_unique( $result ) ),
				'skipped' => array(),
			);
		}
		$days = isset( $args['older-than'] ) ? self::count( $args['older-than'], 'older-than' ) : null;
		$keep = isset( $args['keep'] ) ? self::count( $args['keep'], 'keep' ) : null;
		$all  = array_map( 'strval', array_keys( $history ) );
		rsort( $all, SORT_NUMERIC );
		$delete  = array();
		$skipped = array();
		foreach ( $all as $position => $timestamp ) {
			if ( null !== $keep && $position < $keep ) { continue; }
			if ( null !== $days && (int) $timestamp >= $now - $days * 86400 ) { continue; }
			if ( ! empty( $history[ $timestamp ]['always_keep'] ) ) { $skipped[ $timestamp ] = 'kept with keep';
				continue; }
			if ( call_user_func( $protected, $timestamp, $history[ $timestamp ] ) ) { $skipped[ $timestamp ] = 'active or needed by a pending update';
				continue; }
			$delete[] = $timestamp;
		}
		return array(
			'delete'  => $delete,
			'skipped' => $skipped,
		);
	}

	/**
	 * Run the daily cleanup job now, in this process, through all its resumptions.
	 *
	 * @return array The cleanup status once the job has finished.
	 * @throws RuntimeException When cleanup is not deferred, or a backup is running.
	 */
	public static function cleanup() {
		if ( ! BackupPlus_Retention_Cleanup::deferred() ) { throw new RuntimeException( 'Retention is applied after each backup. Use retention set --mode=daily to run cleanup on demand.' ); }
		if ( ! defined( 'BACKUPPLUS_FOLLOWUPS_ALLOWED' ) ) { define( 'BACKUPPLUS_FOLLOWUPS_ALLOWED', true ); }
		$retention = BackupPlus_Retention_Cleanup::instance();
		$retention->run();
		for ( ;; ) {
			$status = $retention->status();
			if ( empty( $status['job'] ) ) { break; }
			$events = BackupPlus_CLI::events( $status['job'] );
			if ( ! $events ) { break; }
			$delay = $events[0]['time'] - time();
			if ( $delay > 0 ) { sleep( min( 5, $delay ) );
				continue; }
			wp_unschedule_event( $events[0]['time'], $events[0]['hook'], $events[0]['args'] );
			do_action_ref_array( $events[0]['hook'], $events[0]['args'] );
		}
		$status = $retention->status();
		if ( isset( $status['state'] ) && 'waiting' === $status['state'] ) { throw new RuntimeException( 'A backup is running, so cleanup was deferred and will retry in five minutes.' ); }
		return $status;
	}
}
