<?php

/** Running a batch backup, verifying its result and pruning old ones. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Pre_Update_Backups_Run {

	public function run_backup( $id ) {
		global $backupplus;
		if ( ! $this->lock() ) {
			wp_schedule_single_event( time() + 30, 'backupplus_pre_update_run', array( $id ) );
			return;
		}
		try {
			$batch = $this->batch();
			if ( empty( $batch['id'] ) || $id !== $batch['id'] || 'queued' !== $batch['state'] ) {
				return;
			}
			$batch['state'] = 'backing_up';
			$this->save( $batch );
		} finally {
			$this->unlock();
		}
		$destinations = $this->destinations();
		$entities     = array();
		foreach ( $backupplus->get_backupable_file_entities( true ) as $entity => $path ) {
			if ( 'others' === $entity || BackupPlus_Backup_Sources::custom( $entity ) || is_dir( $path ) || is_file( $path ) ) {
				$entities[] = $entity;
			}
		}
		// Persist requirements before boot_backup can finish in this same request.
		if ( ! $this->lock() ) {
			$this->fail( $id );
			return;
		}
		try {
			$batch = $this->batch();
			if ( $batch['id'] !== $id || 'backing_up' !== $batch['state'] ) {
				return;
			}
			$batch['entities']     = $entities;
			$batch['destinations'] = is_wp_error( $destinations ) ? array() : $destinations;
			$batch['backup_time']  = time();
			// Backup history is keyed by seconds; do not overwrite an existing restore point.
			while ( BackupPlus_Backup_History::get_history( $batch['backup_time'] ) ) {
				++$batch['backup_time'];
			}
			$this->save( $batch );
		} finally {
			$this->unlock();
		}
		if ( is_wp_error( $destinations ) ) {
			$this->fail( $id );
			return;
		}
		$options = array(
			'use_nonce'                => $batch['job'],
			'use_timestamp'            => $batch['backup_time'],
			'pre_update_batch'         => $id,
			'incremental'              => false,
			'label'                    => __( 'Pre-update backup', 'backupplus' ),
			'remote_storage_instances' => array_filter(
				$destinations,
				function ( $ids ) {
					return array( '' ) !== $ids;
				}
			),
		);
		if ( 'cli' === $batch['mode'] ) {
			// CLI protection always includes all eligible WordPress tables, even if saved coverage is disabled.
			$options['database_selection'] = array(
				'wp' => array(
					'enabled' => true,
					'mode'    => 'all',
					'tables'  => array(),
				),
			);
		}
		try {
			$result = $backupplus->boot_backup( 1, 1, $entities, false, array_keys( $destinations ), $options );
			if ( false === $result ) {
				$this->fail( $id );
			}
		} catch ( Throwable $error ) {
			$this->fail( $id );
		}
	}

	private function fail( $id ) {
		if ( ! $this->lock() ) {
			return;
		}
		try {
			$batch = $this->batch();
			if ( ! empty( $batch['id'] ) && $batch['id'] === $id && in_array( $batch['state'], array( 'queued', 'backing_up' ), true ) ) {
				$batch['state'] = 'failed';
				$this->save( $batch );
			}
		} finally {
			$this->unlock();
		}
	}

	/** Require every archive and every per-destination receipt, including split archives. */
	public function verified( $batch, $job ) {
		global $backupplus;
		if ( empty( $job['pre_update_batch'] ) || $job['pre_update_batch'] !== $batch['id'] || 'backup' !== $job['job_type'] || 'finished' !== $job['backup_files'] || empty( $job['backup_database']['wp'] ) || ! in_array( is_array( $job['backup_database']['wp'] ) ? $job['backup_database']['wp']['status'] : $job['backup_database']['wp'], array( 'finished', 'encrypted' ), true ) ) {
			return false;
		}
		$history = BackupPlus_Backup_History::get_history( $batch['backup_time'] );
		if ( empty( $history['nonce'] ) || $history['nonce'] !== $batch['job'] ) {
			return false;
		}
		$databases = array();
		foreach ( $job['backup_database'] as $database => $info ) {
			$status = is_array( $info ) ? $info['status'] : $info;
			if ( ! in_array( $status, array( 'finished', 'encrypted' ), true ) ) {
				return false;
			}
			$databases[] = 'wp' === $database ? 'db' : 'db' . $database;
		}
		foreach ( array_merge( $databases, $batch['entities'] ) as $entity ) {
			if ( empty( $history[ $entity ] ) ) {
				return false;
			}
			foreach ( (array) $history[ $entity ] as $index => $file ) {
				$path = $backupplus->backups_dir_location() . '/' . basename( $file );
				$size = $entity . ( $index ? $index : '' ) . '-size';
				if ( empty( $history[ $size ] ) ) {
					return false;
				}
				if ( file_exists( $path ) ) {
					if ( ! is_readable( $path ) || filesize( $path ) !== (int) $history[ $size ] ) {
						return false;
					}
					foreach ( isset( $history['checksums'] ) ? $history['checksums'] : array() as $algorithm => $checksums ) {
						$key = $entity . $index . ( '.crypt' === substr( $file, -6 ) ? '.crypt' : '' );
						if ( isset( $checksums[ $key ] ) && in_array( $algorithm, hash_algos(), true ) && ! hash_equals( $checksums[ $key ], hash_file( $algorithm, $path ) ) ) {
							return false;
						}
					}
				} elseif ( ! $batch['destinations'] ) {
					return false;
				}
				foreach ( $batch['destinations'] as $service => $instances ) {
					foreach ( $instances as $instance ) {
						$key = $service . '-' . ( '' === $instance ? '' : $instance . '-' ) . md5( $file );
						if ( empty( $job[ 'uploaded_' . $key ] ) || 'yes' !== $job[ 'uploaded_' . $key ] ) {
							return false;
						}
					}
				}
			}
		}
		return true;
	}

	/** A ready authorization cannot outlive the restore point it was created for. */
	private function available( $batch ) {
		global $backupplus;
		if ( empty( $batch['backup_time'] ) ) {
			return false;
		}
		$backup = BackupPlus_Backup_History::get_history( $batch['backup_time'] );
		if ( empty( $backup['pre_update']['complete'] ) || $backup['pre_update']['batch'] !== $batch['id'] || $backup['nonce'] !== $batch['job'] ) {
			return false;
		}
		foreach ( $backup['pre_update']['entities'] as $entity ) {
			if ( empty( $backup[ $entity ] ) ) {
				return false;
			}
			foreach ( (array) $backup[ $entity ] as $index => $file ) {
				$path = $backupplus->backups_dir_location() . '/' . basename( $file );
				$size = $entity . ( $index ? $index : '' ) . '-size';
				if ( is_file( $path ) ) {
					if ( ! is_readable( $path ) || empty( $backup[ $size ] ) || filesize( $path ) !== (int) $backup[ $size ] ) {
						return false;
					}
				} elseif ( empty( $batch['destinations'] ) ) {
					return false;
				}
			}
		}
		return true;
	}

	public function backup_finished( $job_id, $outcome, $job ) {
		if ( empty( $job['pre_update_batch'] ) ) {
			return;
		}
		if ( ! $this->lock() ) {
			// Cleanup may immediately remove the job. Persist only completion evidence,
			// excluding cached storage and external database credentials, for a later attempt.
			$evidence = array_intersect_key( $job, array_flip( array( 'pre_update_batch', 'job_type', 'backup_files', 'incremental_snapshots' ) ) );
			foreach ( isset( $job['backup_database'] ) ? $job['backup_database'] : array() as $database => $info ) {
				$evidence['backup_database'][ $database ] = is_array( $info ) ? $info['status'] : $info;
			}
			foreach ( $job as $key => $value ) {
				if ( 0 === strpos( $key, 'uploaded_' ) && 'yes' === $value ) {
					$evidence[ $key ] = $value;
				}
			}
			wp_schedule_single_event( time() + 5, 'backupplus_pre_update_finalize', array( $job_id, $outcome, $evidence ) );
			return;
		}
		try {
			$batch = $this->batch();
			if ( empty( $batch['job'] ) || $batch['job'] !== $job_id || 'backing_up' !== $batch['state'] ) {
				return;
			}
			$batch['state'] = 'success' === $outcome && $this->verified( $batch, $job ) ? 'ready' : 'failed';
			if ( 'ready' === $batch['state'] ) {
				$batch['completed']                             = time();
				$history                                        = BackupPlus_Backup_History::get_history();
				$history[ $batch['backup_time'] ]['pre_update'] = array(
					'batch'        => $batch['id'],
					'complete'     => true,
					'destinations' => $batch['destinations'],
				);
				$history[ $batch['backup_time'] ]['pre_update']['entities'] = array_merge( array( 'db' ), $batch['entities'] );
				foreach ( $job['backup_database'] as $database => $unused ) {
					if ( 'wp' !== $database ) {
						$history[ $batch['backup_time'] ]['pre_update']['entities'][] = 'db' . $database;
					}
				}
				$history[ $batch['backup_time'] ]['pre_update']['snapshots'] = isset( $job['incremental_snapshots'] ) ? $job['incremental_snapshots'] : array();
				BackupPlus_Backup_History::save_history( $history );
				wp_schedule_single_event( time(), 'backupplus_pre_update_prune' );
				if ( 'automatic' === $batch['mode'] ) {
					wp_schedule_single_event( time(), 'backupplus_pre_update_continue', array( $batch['id'] ) );
				}
			}
			$this->save( $batch );
		} finally {
			$this->unlock();
		}
	}

	/** Complete pre-update sets have an independent, whole-set retention policy. */
	public function prune() {
		if ( class_exists( 'BackupPlus_Storage_Limits' ) && BackupPlus_Storage_Limits::managed() ) {
			BackupPlus_Storage_Limits::run( null, ! BackupPlus_Retention_Cleanup::deferred() );
			return;
		}
		global $backupplus;
		$history = BackupPlus_Backup_History::get_history();
		$keep    = max( 1, (int) BackupPlus_Options::get_backupplus_option( 'backupplus_retain', 2 ), (int) BackupPlus_Options::get_backupplus_option( 'backupplus_retain_db', 2 ) );
		$count   = 0;
		foreach ( $history as $timestamp => $backup ) {
			if ( empty( $backup['pre_update']['complete'] ) ) {
				continue;
			}
			++$count;
			if ( $count <= $keep || ! empty( $backup['always_keep'] ) ) {
				continue;
			}
			$batch = $this->batch();
			if ( ! empty( $batch['backup_time'] ) && (int) $timestamp === (int) $batch['backup_time'] && in_array( $batch['state'], array( 'queued', 'backing_up', 'ready', 'updating' ), true ) ) {
				continue;
			}
			$files = array();
			foreach ( isset( $backup['pre_update']['entities'] ) ? $backup['pre_update']['entities'] : array_merge( array( 'db' ), array_keys( $backupplus->get_backupable_file_entities( true ) ) ) as $entity ) {
				if ( ! empty( $backup[ $entity ] ) ) {
					$files = array_merge( $files, (array) $backup[ $entity ] );
				}
			}
			foreach ( $backup['pre_update']['destinations'] as $service => $instances ) {
				$storage = BackupPlus_Storage_Methods_Interface::get_storage_objects_and_ids( array( $service ) );
				if ( empty( $storage[ $service ]['object'] ) ) {
					continue 2;
				}
				$provider = $storage[ $service ]['object'];
				foreach ( $instances as $instance ) {
					if ( '' !== $instance ) {
						if ( ! isset( $storage[ $service ]['instance_settings'][ $instance ] ) ) {
							continue 3;
						}
						$provider->set_options( $storage[ $service ]['instance_settings'][ $instance ], false, $instance );
					}
					if ( true !== $provider->delete( $files ) ) {
						continue 3;
					}
				}
			}
			foreach ( $files as $file ) {
				$path = $backupplus->backups_dir_location() . '/' . basename( $file );
				if ( is_file( $path ) && ! unlink( $path ) ) {
					continue 2;
				}
			}
			BackupPlus_Incremental_Backups::discard_snapshots( isset( $backup['pre_update']['snapshots'] ) ? $backup['pre_update']['snapshots'] : array() );
			// Re-read before removal so concurrent backups are preserved.
			$current = BackupPlus_Backup_History::get_history();
			unset( $current[ $timestamp ] );
			BackupPlus_Backup_History::save_history( $current );
		}
	}
}
