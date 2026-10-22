<?php
/** Daily deletion of backups beyond the retention settings, without creating a backup. */
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

class BackupPlus_Retention_Cleanup {
	const STATUS      = 'backupplus_retention_cleanup_status';
	const FAILURES    = 'backupplus_retention_cleanup_failures';
	const RETRY_DELAY = 300;

	private static $instance;
	private $last_check = 0;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action( BackupPlus_Backup_Schedule::HOOKS['cleanup'], array( $this, 'run' ) );
		add_action( BackupPlus_Backup_Schedule::RETRY_HOOK, array( $this, 'run' ) );
	}

	/** In daily mode, backups leave retention pruning to the cleanup job. */
	public static function deferred() {
		return 'daily' === BackupPlus_Options::get_backupplus_option( 'backupplus_retention_cleanup_mode', 'after_backup' );
	}

	public function status() {
		$status = get_option( self::STATUS, array() );
		return is_array( $status ) ? $status : array();
	}

	private function save_status( $status ) {
		update_option( self::STATUS, $status, false );
	}

	/** Deletions that failed, kept until a later cleanup succeeds. */
	public function failures() {
		$failures = get_option( self::FAILURES, array() );
		return is_array( $failures ) ? array_values( $failures ) : array();
	}

	/** Read an option written by another request, bypassing this request's option cache. Job data are network options. */
	private static function fresh_option( $name, $network_option = true ) {
		if ( is_multisite() ) {
			$network = function_exists( 'get_current_network_id' ) ? get_current_network_id() : 1;
			wp_cache_delete( $network . ':' . $name, 'site-options' );
			wp_cache_delete( $network . ':notoptions', 'site-options' );
		}
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return $network_option ? get_site_option( $name ) : get_option( $name );
	}

	/** Job IDs with a pending resumption, after refreshing the cron schedule from the database. */
	private static function resumable_jobs() {
		self::fresh_option( 'cron', false );
		$jobs = array();
		foreach ( (array) _get_cron_array() as $hooks ) {
			if ( ! empty( $hooks['backupplus_backup_resume'] ) && is_array( $hooks['backupplus_backup_resume'] ) ) {
				foreach ( $hooks['backupplus_backup_resume'] as $event ) {
					if ( isset( $event['args'][1] ) && is_string( $event['args'][1] ) ) {
						$jobs[] = $event['args'][1];
					}
				}
			}
		}
		return array_unique( $jobs );
	}

	private static function unfinished( $job, $types ) {
		return is_array( $job ) && isset( $job['job_type'] ) && in_array( $job['job_type'], $types, true ) && ( ! isset( $job['jobstatus'] ) || 'finished' !== $job['jobstatus'] );
	}

	/** Whether a backup is in progress: an unfinished backup awaiting resumption, or a running one-shot backup. */
	public static function backup_active() {
		$jobs   = self::resumable_jobs();
		$jobs[] = self::fresh_option( 'backupplus_oneshotnonce' );
		foreach ( array_filter( array_unique( $jobs ), 'is_string' ) as $job ) {
			if ( self::unfinished( self::fresh_option( 'backupplus_jobdata_' . $job ), array( 'backup', 'incremental' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** Called between backup sets during pruning: yield to a backup that has started, checking at most every ten seconds. */
	public function should_yield() {
		if ( time() - $this->last_check < 10 ) {
			return false;
		}
		$this->last_check = time();
		return self::backup_active();
	}

	/** Entry point for the daily event and for retries while a backup was running. */
	public function run() {
		if ( ! self::deferred() ) {
			return;
		}
		$status = $this->status();
		// An interrupted cleanup continues through its own scheduled resumption.
		if ( ! empty( $status['job'] ) && in_array( $status['job'], self::resumable_jobs(), true ) && self::unfinished( self::fresh_option( 'backupplus_jobdata_' . $status['job'] ), array( 'cleanup' ) ) ) {
			return;
		}
		if ( self::backup_active() ) {
			$this->wait();
			return;
		}
		$this->start();
	}

	/** Try again in five minutes; the daily event keeps its time. */
	private function wait() {
		if ( ! wp_next_scheduled( BackupPlus_Backup_Schedule::RETRY_HOOK ) ) {
			wp_schedule_single_event( time() + self::RETRY_DELAY, BackupPlus_Backup_Schedule::RETRY_HOOK );
		}
		$status          = $this->status();
		$status['state'] = 'waiting';
		unset( $status['job'] );
		$this->save_status( $status );
	}

	/** Start a cleanup job. It runs through the backup resumption machinery but creates no archives. */
	private function start() {
		global $backupplus;
		$backupplus->backup_time_nonce();
		// Several cron events can run in one request; do not inherit another job's state.
		$backupplus->jobdata_reset();
		$backupplus->errors             = array();
		$backupplus->current_resumption = 0;
		$services                       = array_values( $backupplus->get_canonical_service_list( BackupPlus_Options::get_backupplus_option( 'backupplus_service' ) ) );
		$backupplus->jobdata_set_multi(
			array(
				'job_type'           => 'cleanup',
				'jobstatus'          => 'begun',
				'backup_time'        => $backupplus->backup_time,
				'job_time_ms'        => $backupplus->job_time_ms,
				'resume_interval'    => 300,
				'service'            => $services,
				'backup_files'       => 'no',
				'backup_database'    => 'no',
				'one_shot'           => false,
				'uploaded_lastreset' => 9,
				'last_sapi'          => PHP_SAPI,
			)
		);
		$status           = $this->status();
		$status['state']  = 'running';
		$status['job']    = $backupplus->nonce;
		$status['logs']   = isset( $status['logs'] ) ? (array) $status['logs'] : array();
		$status['logs'][] = $backupplus->nonce;
		$this->save_status( $status );
		$backupplus->logfile_open( $backupplus->nonce );
		$backupplus->log( 'Retention cleanup: deleting backups beyond the retention settings (no backup is created by this job)' );
		$backupplus->backup_resume( 0, $backupplus->nonce );
	}

	/**
	 * One run of a cleanup job, called from BackupPlus::backup_resume() after the next resumption
	 * has been scheduled. An interrupted run is repeated by that resumption; pruning picks up
	 * from the saved history, and deletions already made are recorded in the job.
	 *
	 * @param BackupPlus_Backup $backupplus_backup
	 */
	public function run_job( $backupplus_backup ) {
		global $backupplus;
		$this->last_check = time();
		if ( self::backup_active() ) {
			$backupplus->log( 'Retention cleanup: a backup is running; trying again in five minutes' );
			$this->finish( 'waiting' );
			return;
		}
		if ( 'finished' !== $backupplus->jobdata_get( 'retention_retry' ) ) {
			$this->retry_failures();
			$backupplus->jobdata_set( 'retention_retry', 'finished' );
		}
		if ( 'finished' !== $backupplus->jobdata_get( 'prune' ) ) {
			$services = $this->prune_services();
			if ( ! $services ) {
				$backupplus->log( __( 'Retention cleanup could not load the configured remote storage, so no backups were deleted.', 'backupplus' ), 'error' );
			} elseif ( false === $backupplus_backup->prune_retained_backups( $services ) ) {
				$this->finish( 'waiting' );
				return;
			}
		}
		$this->finish( 'complete' );
	}

	/** Called by the resumption machinery when the job repeatedly fails to make progress. */
	public function abandon() {
		global $backupplus;
		$backupplus->log( __( 'The retention cleanup was stopped because it repeatedly failed to make progress.', 'backupplus' ), 'error' );
		$this->finish( 'complete' );
	}

	/** The job's enabled storage destinations, or local storage when none is configured; empty if any cannot be loaded. */
	protected function prune_services() {
		global $backupplus;
		$configured = (array) $backupplus->jobdata_get( 'service', array() );
		if ( ! $configured ) {
			return array( 'none' => array( 'all' => array( null, null ) ) );
		}
		$storage  = BackupPlus_Storage_Methods_Interface::get_enabled_storage_objects_and_ids( $configured );
		$services = array();
		foreach ( $configured as $service ) {
			// Deleting from only some destinations would leave the rest untracked, so prune nothing.
			if ( empty( $storage[ $service ]['object'] ) || ! is_object( $storage[ $service ]['object'] ) ) {
				$backupplus->log( "Could not load storage service $service, or it has no enabled destinations", 'warning' );
				return array();
			}
			$object = $storage[ $service ]['object'];
			if ( $object->supports_feature( 'multi_options' ) ) {
				foreach ( array_keys( (array) $storage[ $service ]['instance_settings'] ) as $instance_id ) {
					$services[ $service ][ $instance_id ] = array( $object, null );
				}
			} else {
				$services[ $service ]['all'] = array( $object, null );
			}
		}
		return $services;
	}

	/** Merge failed deletions into the retry queue, one entry per destination. */
	private function queue_failures( $failures ) {
		$queue = array();
		foreach ( array_merge( $this->failures(), $failures ) as $item ) {
			$key = $item['service'] . '|' . $item['instance'];
			if ( ! isset( $queue[ $key ] ) ) {
				$queue[ $key ] = array_merge(
					$item,
					array(
						'files' => array(),
						'sizes' => array(),
					)
				);
			}
			foreach ( array_values( $item['files'] ) as $index => $file ) {
				if ( ! in_array( $file, $queue[ $key ]['files'], true ) ) {
					$queue[ $key ]['files'][] = $file;
					$queue[ $key ]['sizes'][] = isset( $item['sizes'][ $index ] ) ? $item['sizes'][ $index ] : null;
				}
			}
			$queue[ $key ]['attempts'] = max( isset( $queue[ $key ]['attempts'] ) ? $queue[ $key ]['attempts'] : 0, isset( $item['attempts'] ) ? $item['attempts'] : 0 );
			$queue[ $key ]['failed']   = isset( $item['failed'] ) ? $item['failed'] : time();
		}
		update_option( self::FAILURES, array_values( $queue ), false );
	}

	/** Retry earlier failed deletions before pruning; the queue is saved after each destination. */
	private function retry_failures() {
		global $backupplus;
		$queue = $this->failures();
		if ( ! $queue ) {
			return;
		}
		$backupplus->log( 'Retention cleanup: retrying earlier failed deletions (destinations: ' . count( $queue ) . ')' );
		foreach ( $queue as $index => $item ) {
			$remaining = $this->delete_queued( $item );
			$deleted   = array_diff( $item['files'], $remaining );
			$this->record_deleted( $deleted );
			if ( $remaining ) {
				$sizes = array();
				foreach ( $remaining as $file ) {
					$position = array_search( $file, $item['files'], true );
					$sizes[]  = isset( $item['sizes'][ $position ] ) ? $item['sizes'][ $position ] : null;
				}
				$queue[ $index ] = array_merge(
					$item,
					array(
						'files'    => $remaining,
						'sizes'    => $sizes,
						'attempts' => ( isset( $item['attempts'] ) ? $item['attempts'] : 0 ) + 1,
						'failed'   => time(),
					)
				);
				$backupplus->jobdata_set( 'retention_retry_failed', (int) $backupplus->jobdata_get( 'retention_retry_failed', 0 ) + count( $remaining ) );
				$backupplus->log( 'Retention cleanup: deletion failed again (' . $item['service'] . '): ' . implode( ', ', $remaining ), 'warning' );
			} else {
				unset( $queue[ $index ] );
				$backupplus->log( 'Retention cleanup: completed an earlier failed deletion (' . $item['service'] . ', files: ' . count( $deleted ) . ')' );
			}
			update_option( self::FAILURES, array_values( $queue ), false );
		}
	}

	/** Returns the files that could not be deleted. A failed batch is retried one file at a time. */
	protected function delete_queued( $item ) {
		global $backupplus;
		if ( 'local' === $item['service'] ) {
			$remaining = array();
			foreach ( $item['files'] as $file ) {
				$path = $backupplus->backups_dir_location() . '/' . basename( $file );
				if ( file_exists( $path ) && ! @unlink( $path ) ) { // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- The failure is recorded for a later retry.
					$remaining[] = $file;
				}
			}
			return $remaining;
		}
		$storage = $this->storage( $item['service'], $item['instance'] );
		if ( false === $storage ) {
			$backupplus->log( 'Retention cleanup: storage instance ' . $item['service'] . '/' . $item['instance'] . ' is no longer configured; forgetting its failed deletions' );
			return array();
		}
		if ( null === $storage ) {
			return $item['files'];
		}
		if ( true === $storage->delete( $item['files'], null, $item['sizes'] ) ) {
			return array();
		}
		if ( count( $item['files'] ) < 2 ) {
			return $item['files'];
		}
		$remaining = array();
		foreach ( $item['files'] as $index => $file ) {
			if ( true !== $storage->delete( array( $file ), null, array( isset( $item['sizes'][ $index ] ) ? $item['sizes'][ $index ] : null ) ) ) {
				$remaining[] = $file;
			}
		}
		return $remaining;
	}

	/**
	 * The provider for a queued deletion: null while it is unavailable, false once its storage
	 * instance has been removed from the settings.
	 */
	protected function storage( $service, $instance ) {
		$storage = BackupPlus_Storage_Methods_Interface::get_storage_objects_and_ids( array( $service ) );
		if ( empty( $storage[ $service ]['object'] ) || ! is_object( $storage[ $service ]['object'] ) ) {
			return null;
		}
		$object = $storage[ $service ]['object'];
		if ( $object->supports_feature( 'multi_options' ) ) {
			if ( ! isset( $storage[ $service ]['instance_settings'][ $instance ] ) ) {
				return false;
			}
			$object->set_options( $storage[ $service ]['instance_settings'][ $instance ], false, $instance );
		}
		return $object;
	}

	/** Record files whose deletion completed, for the cleanup summary. */
	public function record_deleted( $files ) {
		global $backupplus;
		if ( ! $files ) {
			return;
		}
		$deleted = (array) $backupplus->jobdata_get( 'retention_deleted', array() );
		foreach ( $files as $file ) {
			$deleted[ basename( $file ) ] = true;
		}
		$backupplus->jobdata_set( 'retention_deleted', $deleted );
	}

	/** Record files that a destination failed to delete; they are queued when the job ends. */
	public function record_failed( $service, $instance, $files, $sizes ) {
		global $backupplus;
		$failures   = (array) $backupplus->jobdata_get( 'retention_failures', array() );
		$failures[] = array(
			'service'  => (string) $service,
			'instance' => (string) $instance,
			'files'    => array_values( $files ),
			'sizes'    => array_values( $sizes ),
			'failed'   => time(),
		);
		$backupplus->jobdata_set( 'retention_failures', $failures );
		$backupplus->log( "Retention cleanup: deletion failed ($service" . ( '' === (string) $instance ? '' : "/$instance" ) . '): ' . implode( ', ', $files ) . '; it will be retried at the next cleanup', 'warning' );
	}

	/**
	 * End the job and record its outcome. Results of runs that stopped for a backup are
	 * carried into the run that completes.
	 *
	 * @param String $state - 'complete', or 'waiting' to try again after five minutes
	 */
	private function finish( $state ) {
		global $backupplus;
		$failed = (int) $backupplus->jobdata_get( 'retention_retry_failed', 0 );
		foreach ( (array) $backupplus->jobdata_get( 'retention_failures', array() ) as $failure ) {
			$failed += count( $failure['files'] );
		}
		$this->queue_failures( (array) $backupplus->jobdata_get( 'retention_failures', array() ) );
		$status            = $this->status();
		$carry             = isset( $status['carry'] ) ? $status['carry'] : array(
			'deleted' => 0,
			'failed'  => 0,
			'errors'  => 0,
		);
		$carry['deleted'] += count( (array) $backupplus->jobdata_get( 'retention_deleted', array() ) );
		$carry['failed']  += $failed;
		$carry['errors']  += $backupplus->error_count();
		unset( $status['job'] );
		if ( 'waiting' === $state ) {
			$status['state'] = 'waiting';
			$status['carry'] = $carry;
		} else {
			$pending = 0;
			foreach ( $this->failures() as $item ) {
				$pending += count( $item['files'] );
			}
			// Keep only this run's log.
			foreach ( isset( $status['logs'] ) ? (array) $status['logs'] : array() as $job ) {
				if ( $job !== $backupplus->nonce && is_file( $backupplus->get_logfile_name( $job ) ) ) {
					unlink( $backupplus->get_logfile_name( $job ) );
				}
			}
			$status = array(
				'state' => 'idle',
				'logs'  => array( $backupplus->nonce ),
				'last'  => array(
					'time'    => time(),
					'outcome' => $carry['failed'] || $carry['errors'] ? 'failed' : 'success',
					'deleted' => $carry['deleted'],
					'failed'  => $carry['failed'],
					'pending' => $pending,
					'log'     => $backupplus->nonce,
				),
			);
		}
		$this->save_status( $status );
		$backupplus->log( sprintf( 'Retention cleanup %s (files deleted: %d, failed deletions: %d, errors: %d)', 'waiting' === $state ? 'paused' : 'finished', $carry['deleted'], $carry['failed'], $carry['errors'] ) );
		$backupplus->end_cleanup_job();
		if ( 'waiting' === $state ) {
			$this->wait();
		}
	}

	/** Next cleanup time and the latest result, for the Backup / Restore tab and settings saves. */
	public function summary_html() {
		$status = $this->status();
		$html   = '';
		if ( ! self::deferred() ) {
			$html .= '<span>' . esc_html__( 'After each backup', 'backupplus' ) . '</span>';
		} else {
			$next  = BackupPlus_Backup_Schedule::describe_next( BackupPlus_Backup_Schedule::HOOKS['cleanup'] );
			$html .= $next ? '<span class="backupplus_next_scheduled_date_time">' . esc_html( $next ) . '</span>' : '<span>' . esc_html__( 'Nothing currently scheduled', 'backupplus' ) . '</span>';
			if ( isset( $status['state'] ) && 'waiting' === $status['state'] ) {
				$html .= '<br><span>' . esc_html__( 'Waiting for a running backup to finish.', 'backupplus' ) . '</span>';
			} elseif ( isset( $status['state'] ) && 'running' === $status['state'] ) {
				$html .= '<br><span>' . esc_html__( 'Cleanup in progress.', 'backupplus' ) . '</span>';
			}
		}
		if ( ! empty( $status['last']['time'] ) ) {
			$last = $status['last'];
			/* translators: 1: Date and time, 2: Number of files */
			$text = sprintf( _n( 'Last cleanup: %1$s, %2$d file deleted.', 'Last cleanup: %1$s, %2$d files deleted.', $last['deleted'], 'backupplus' ), BackupPlus_Backup_Schedule::format_date( $last['time'] ), $last['deleted'] );
			if ( 'success' !== $last['outcome'] ) {
				$text .= ' ' . __( 'Some deletions failed.', 'backupplus' );
			}
			if ( ! empty( $last['pending'] ) ) {
				/* translators: %d: Number of files */
				$text .= ' ' . sprintf( _n( '%d file will be retried at the next cleanup.', '%d files will be retried at the next cleanup.', $last['pending'], 'backupplus' ), $last['pending'] );
			}
			$html .= '<br><span class="backupplus-cleanup-result">' . esc_html( $text ) . '</span>';
			global $backupplus;
			if ( ! empty( $last['log'] ) && is_file( $backupplus->get_logfile_name( $last['log'] ) ) ) {
				$html .= ' <a href="#" class="backupplus-log-link" data-jobid="' . esc_attr( $last['log'] ) . '">' . esc_html__( 'View Log', 'backupplus' ) . '</a>';
			}
		}
		return $html;
	}
}
