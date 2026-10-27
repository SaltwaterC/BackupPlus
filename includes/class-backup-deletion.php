<?php
/** Strict deletion keeps complete inventories until every requested deletion succeeds. */
if ( ! defined( 'ABSPATH' ) ) { die( 'No direct access allowed' ); }

class BackupPlus_Backup_Deletion {
	public static function delete( $opts ) {
		global $backupplus;
		$timestamp = $opts['backup_timestamp'];
		$backup    = BackupPlus_Backup_History::get_history( $timestamp );
		$errors    = array();
		if ( ! empty( $backup['pitr'] ) ) {
			try {
				$worker   = new BackupPlus_PITR();
				$progress = $worker->delete( $backup['pitr']['chain_id'], ! empty( $opts['delete_remote'] ), isset( $opts['deadline'] ) ? $opts['deadline'] : null );
				return array_merge(
					$progress,
					array(
						'result'  => $progress['complete'] ? 'success' : 'continue',
						'message' => 'Database recovery chain deleted.',
						'errors'  => array(),
					)
				);
			} catch ( RuntimeException $error ) { return array(
				'result'  => 'error',
				'message' => $error->getMessage(),
				'errors'  => array( array( 'code' => 'pitr_chain' ) ),
			); }
		}
		if ( ! $backup ) { return array(
			'result'  => 'error',
			'message' => 'Backup set not found.',
			'errors'  => array( array( 'code' => 'not_found' ) ),
		); }
		if ( self::protected_set( $timestamp, $backup ) ) { return array(
			'result'  => 'error',
			'message' => 'This backup chain is active or authorizes pending updates.',
			'errors'  => array( array( 'code' => 'protected' ) ),
		); }
		$entities       = BackupPlus_Backup_Sources::restore_entities( $backupplus->get_backupable_file_entities( true, true ), $backup );
		$entities['db'] = array();
		$files          = array();
		foreach ( $entities as $entity => $unused ) {
			foreach ( isset( $backup[ $entity ] ) ? (array) $backup[ $entity ] : array() as $file ) {
				if ( ! is_string( $file ) || basename( $file ) !== $file ) { $errors[] = array(
					'code'   => 'invalid_archive',
					'entity' => $entity,
				);
				continue; }
				$files[] = $file;
			}
		}
		if ( $errors ) { return array(
			'result'  => 'error',
			'message' => 'Invalid archive inventory.',
			'errors'  => $errors,
		); }
		$files = array_values( array_unique( $files ) );
		if ( ! empty( $opts['delete_remote'] ) ) {
			foreach ( isset( $backup['service'] ) ? (array) $backup['service'] : array() as $service ) {
				if ( ! $service || in_array( $service, array( 'none', 'email' ), true ) ) { continue; }
				$storage = BackupPlus_Storage_Methods_Interface::get_storage_objects_and_ids( array( $service ) );
				if ( empty( $storage[ $service ]['object'] ) ) { $errors[] = array(
					'code'    => 'provider_unavailable',
					'service' => $service,
				);
				continue; }
				$provider  = $storage[ $service ]['object'];
				$instances = isset( $backup['pre_update']['destinations'][ $service ] ) ? $backup['pre_update']['destinations'][ $service ] : ( isset( $backup['service_instance_ids'][ $service ] ) ? $backup['service_instance_ids'][ $service ] : array() );
				if ( ! $provider->supports_feature( 'multi_options' ) ) { $instances = array( '' ); }
				elseif ( ! $instances ) { $instances = array_keys( $storage[ $service ]['instance_settings'] ); }
				if ( ! $instances ) { $errors[] = array(
					'code'    => 'instance_unavailable',
					'service' => $service,
				); }
				foreach ( $instances as $instance ) {
					if ( '' !== $instance ) {
						if ( ! isset( $storage[ $service ]['instance_settings'][ $instance ] ) ) { $errors[] = array(
							'code'     => 'instance_unavailable',
							'service'  => $service,
							'instance' => $instance,
						);
						continue; }
						$provider->set_options( $storage[ $service ]['instance_settings'][ $instance ], false, $instance );
					}
					foreach ( $files as $file ) {
						if ( ! empty( $backup['storage_deleted_files'][ $service . ':' . $instance ][ $file ] ) ) { continue; }
						$key = hash( 'sha256', $service . ':' . $instance . ':' . $file );
						if ( ! empty( $backup['cli_deletions'][ $key ] ) ) { continue; }
						try { $deleted = $provider->delete( $file );
						} catch ( Throwable $error ) { $deleted = false; }
						if ( true === $deleted ) { $backup['cli_deletions'][ $key ] = true; }
						else { $errors[] = array(
							'code'     => 'remote_delete_failed',
							'service'  => $service,
							'instance' => $instance,
							'file'     => $file,
						); }
					}
				}
			}
		}
		$directory = $backupplus->backups_dir_location();
		foreach ( $files as $file ) {
			$path = $directory . '/' . $file;
			if ( file_exists( $path ) && ( ! is_file( $path ) || ! @unlink( $path ) ) ) { $errors[] = array(
				'code' => 'local_delete_failed',
				'file' => $file,
			); }
		}
		$current = BackupPlus_Backup_History::get_history();
		if ( isset( $current[ $timestamp ] ) ) { $current[ $timestamp ]['cli_deletions'] = isset( $backup['cli_deletions'] ) ? $backup['cli_deletions'] : array(); }
		if ( ! $errors ) {
			$snapshots = isset( $backup['incremental']['snapshots'] ) ? $backup['incremental']['snapshots'] : array();
			$snapshots = array_merge( $snapshots, isset( $backup['pre_update']['snapshots'] ) ? $backup['pre_update']['snapshots'] : array() );
			foreach ( $snapshots as $snapshot ) {
				if ( is_string( $snapshot ) && preg_match( '/^incremental_[a-f0-9]{12}_[a-z-]+\.php$/D', $snapshot ) && file_exists( $directory . '/' . $snapshot ) && ! @unlink( $directory . '/' . $snapshot ) ) { $errors[] = array(
					'code' => 'inventory_delete_failed',
					'file' => $snapshot,
				); }
			}
			if ( ! $errors ) { unset( $current[ $timestamp ] ); }
		}
		BackupPlus_Backup_History::save_history( $current );
		return array(
			'result'  => $errors ? 'error' : 'success',
			'errors'  => $errors,
			'message' => $errors ? 'Some deletions failed; backup history was retained for retry: ' . json_encode( $errors ) : 'Backup deleted.',
		);
	}

	/** Share the normal admin deletion response for PITR, ordinary and mixed selections. */
	public static function delete_selection( $opts ) {
		global $backupplus_admin;
		$timestamps = array_values( array_unique( explode( ',', (string) $opts['backup_timestamp'] ) ) );
		$history    = BackupPlus_Backup_History::get_history();
		foreach ( $timestamps as $timestamp ) {
			if ( ! ctype_digit( $timestamp ) || ! isset( $history[ $timestamp ] ) ) { return array(
				'result'  => 'error',
				'message' => 'Backup set not found.',
			); }
		}
		$deadline = microtime( true ) + max( 1, isset( $opts['remote_delete_limit'] ) ? (int) $opts['remote_delete_limit'] : 20 );
		$response = array(
			'result'                 => 'success',
			'backup_local'           => 0,
			'backup_remote'          => 0,
			'backup_sets'            => 0,
			'deleted_timestamps'     => '',
			'timestamps'             => '',
			'processed_instance_ids' => array(),
			'set_message'            => __( 'Backup sets removed:', 'backupplus' ),
			'local_message'          => __( 'Local files deleted:', 'backupplus' ),
			'remote_message'         => __( 'Remote files deleted:', 'backupplus' ),
			'error_messages'         => '',
		);
		$deleted  = array();
		foreach ( $timestamps as $index => $timestamp ) {
			$single                        = $opts;
			$single['backup_timestamp']    = $timestamp;
			$single['deadline']            = $deadline;
			$single['remote_delete_limit'] = max( 1, (int) ceil( $deadline - microtime( true ) ) );
			if ( ! empty( $history[ $timestamp ]['pitr'] ) ) {
				$result = self::delete( $single );
				if ( 'success' === $result['result'] ) { $result['backup_sets'] = 1; }
			} else { $result = $backupplus_admin->delete_set( $single ); }
			foreach ( array( 'backup_local', 'backup_remote', 'backup_sets' ) as $counter ) { $response[ $counter ] += isset( $result[ $counter ] ) ? $result[ $counter ] : 0; }
			if ( 'success' === $result['result'] ) { $deleted[] = $timestamp; }
			else {
				$response['result']                 = $result['result'];
				$response['message']                = isset( $result['message'] ) ? $result['message'] : '';
				$response['processed_instance_ids'] = isset( $result['processed_instance_ids'] ) ? $result['processed_instance_ids'] : array();
				break;
			}
			if ( microtime( true ) >= $deadline && $index < count( $timestamps ) - 1 ) { $response['result'] = 'continue';
				break; }
		}
		$response['deleted_timestamps'] = implode( ',', $deleted );
		$response['timestamps']         = implode( ',', array_diff( $timestamps, $deleted ) );
		$response['count_backups']      = count( BackupPlus_Backup_History::get_history() );
		return $response;
	}

	public static function protected_set( $timestamp, $backup ) {
		$batch = BackupPlus_Pre_Update_Backups::instance()->batch();
		if ( ! empty( $batch['backup_time'] ) && (int) $timestamp === (int) $batch['backup_time'] && in_array( $batch['state'], array( 'queued', 'backing_up', 'ready', 'updating' ), true ) ) { return true; }
		$jobs = array( BackupPlus_CLI::fresh( 'backupplus_oneshotnonce', true ), isset( $backup['nonce'] ) ? $backup['nonce'] : '' );
		BackupPlus_CLI::fresh( 'cron' );
		foreach ( (array) _get_cron_array() as $hooks ) {
			foreach ( isset( $hooks['backupplus_backup_resume'] ) ? $hooks['backupplus_backup_resume'] : array() as $event ) { if ( isset( $event['args'][1] ) ) { $jobs[] = $event['args'][1]; }
			}
		}
		foreach ( array_unique( array_filter( $jobs ) ) as $id ) {
			$job = BackupPlus_CLI::fresh( 'backupplus_jobdata_' . $id, true );
			if ( ! is_array( $job ) || ! empty( $job['terminal_outcome'] ) || ! isset( $job['job_type'] ) || ! in_array( $job['job_type'], array( 'backup', 'incremental' ), true ) || ( isset( $job['jobstatus'] ) && 'finished' === $job['jobstatus'] ) ) { continue; }
			if ( ( isset( $job['incremental_base_time'] ) && (int) $timestamp === (int) $job['incremental_base_time'] ) || ( isset( $backup['nonce'] ) && $id === $backup['nonce'] ) ) { return true; }
		}
		return false;
	}
}
