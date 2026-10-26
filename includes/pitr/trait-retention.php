<?php

/** Deleting and pruning chains, retention cleanup and the temporary work directories. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_PITR_Orchestrator_Retention {

	private function chain_files( $chain ) {
		$payloads = array();
		$metadata = array();
		foreach ( array_merge( $chain['entries'], isset( $chain['abandoned_entries'] ) ? $chain['abandoned_entries'] : array() ) as $entry ) {
			foreach ( $entry['manifest']['artifacts'] as $artifact ) {
				$payloads[] = $artifact['name'];
			}
			$metadata[] = $entry['locator']['manifest']['name'];
			$metadata[] = 'backup_pitr_' . $entry['manifest']['chain_id'] . '_' . $entry['manifest']['backup_id'] . '_locator.json';
		}
		return array_merge( $payloads, $metadata );
	}

	public function delete( $id, $delete_remote = true, $deadline = null ) {
		BackupPlus_PITR_Manifest::id( $id );
		if ( ! isset( $this->state['chains'][ $id ] ) ) {
			throw new RuntimeException( 'PITR chain was not found.' );
		}
		if ( ( isset( $this->state['restore_chain'] ) && $id === $this->state['restore_chain'] ) || ( $this->state['pending'] && $id === $this->state['pending']['entry']['manifest']['chain_id'] ) ) {
			throw new RuntimeException( 'A backup or restore is still using this database recovery chain. Complete that operation before deleting it.' );
		}
		if ( ! empty( $this->config['enabled'] ) && ( $id === $this->state['active'] || in_array( $id, isset( $this->state['protected_previous'] ) ? $this->state['protected_previous'] : array(), true ) ) ) {
			throw new RuntimeException( 'This recovery chain is needed for scheduled database backups. Disable Database point-in-time recovery in Settings before deleting it. Re-enable it afterwards to start a new chain.' );
		}
		$chain = &$this->state['chains'][ $id ];
		if ( 'deleting' === $chain['state'] && $delete_remote !== ( isset( $chain['delete_remote'] ) ? $chain['delete_remote'] : true ) ) { throw new RuntimeException( 'This deletion is already in progress. Retry with the same "Also delete from remote storage" choice to finish removing the complete chain.' ); }
		$chain['delete_remote'] = $delete_remote;
		$chain['state']         = 'deleting';
		$this->save();
		$this->history( $id );
		$storage         = new BackupPlus_PITR_Storage( $this->work, array(), $delete_remote ? $chain['destinations'] : array() );
		$files           = array_values( array_unique( $this->chain_files( $chain ) ) );
		$is_local        = function ( $file ) use ( $storage ) {
			return is_file( $storage->path( $file ) );
		};
		$local_before    = count( array_filter( $files, $is_local ) );
		$receipts_before = isset( $chain['delete_receipts'] ) ? $chain['delete_receipts'] : array();
		$complete        = true;
		try {
			$storage->delete(
				$files,
				$receipts_before,
				function ( $receipts ) use ( $id ) {
					$this->state['chains'][ $id ]['delete_receipts'] = $receipts;
					$this->save();
				},
				$deadline
			);
		} catch ( BackupPlus_PITR_Delete_Pending $pending ) { $complete = false; }
		$progress = array(
			'complete'      => $complete,
			'backup_local'  => $local_before - count( array_filter( $files, $is_local ) ),
			'backup_remote' => array_sum( array_map( 'count', isset( $chain['delete_receipts'] ) ? $chain['delete_receipts'] : array() ) ) - array_sum( array_map( 'count', $receipts_before ) ),
		);
		if ( ! $complete ) { return $progress; }
		$history = BackupPlus_Backup_History::get_history();
		if ( isset( $chain['history_time'] ) ) {
			unset( $history[ $chain['history_time'] ] );
			BackupPlus_Backup_History::save_history( $history );
		}
		unset( $this->state['chains'][ $id ] );
		if ( $id === $this->state['active'] ) {
			$this->state['active'] = null;
		} $this->save();
		return $progress;
	}

	/** A restore's pin holds only while its history row stays locked, so unlocking in the backups list needs no worker lock. Without a row the pin alone applies. */
	private function retention_locked( $chain, $history ) {
		if ( isset( $chain['history_time'], $history[ $chain['history_time'] ] ) ) {
			return ! empty( $history[ $chain['history_time'] ]['always_keep'] );
		}
		return ! empty( $chain['pinned'] );
	}

	public function prune() {
		$history = BackupPlus_Backup_History::get_history();
		$keep    = max( 1, (int) BackupPlus_Options::get_backupplus_option( 'backupplus_retain_db', 2 ) );
		$chains  = $this->state['chains'];
		uasort(
			$chains,
			function ( $a, $b ) {
				return $b['entries'][0]['manifest']['created_at'] <=> $a['entries'][0]['manifest']['created_at'];
			}
		);
		$count = 0;
		foreach ( $chains as $id => $chain ) {
			if ( ! empty( $chain['imported'] ) ) {
				continue;
			}
			if ( empty( $chain['destinations'] ) && ! BackupPlus_Options::get_backupplus_option( 'backupplus_delete_local', 1 ) ) {
				continue;
			}
			++$count;
			if ( $count <= $keep || $id === $this->state['active'] || in_array( $id, isset( $this->state['protected_previous'] ) ? $this->state['protected_previous'] : array(), true ) || $this->retention_locked( $chain, $history ) || ( isset( $this->state['restore_chain'] ) && $id === $this->state['restore_chain'] ) ) {
				continue;
			}
			$this->delete( $id );
		}
	}

	public static function cleanup_retention() {
		try {
			$worker = new self();
			$worker->prune();
		} catch ( Throwable $error ) {
			global $backupplus;
			$backupplus->log( $error->getMessage(), 'warning' );
		}
	}

	public static function clean_temporary() {
		global $backupplus;
		if ( ! is_dir( $backupplus->backups_dir_location() . '/pitr' ) ) {
			return;
		}
		try {
			$worker = new self();
		} catch ( Throwable $error ) {
			return;
		}
	}

	private function reap_work() {
		$keep = array();
		foreach ( ! empty( $this->state['pending']['files'] ) ? $this->state['pending']['files'] : array() as $file ) {
			$keep[ 'verify-' . hash( 'sha256', $file['name'] ) ] = $file;
		}
		foreach ( glob( $this->root . '/work-*' ) as $path ) {
			if ( $path === $this->work || is_link( $path ) || ! is_dir( $path ) || filemtime( $path ) > time() - 43200 ) {
				continue;
			}
			$retained = false;
			foreach ( new DirectoryIterator( $path ) as $entry ) {
				if ( $entry->isDot() ) {
					continue;
				}
				$child = $entry->getPathname();
				$name  = $entry->getFilename();
				if ( isset( $keep[ $name ] ) && $entry->isFile() && ! $entry->isLink() && hash_equals( $keep[ $name ]['sha256'], hash_file( 'sha256', $child ) ) ) {
					$retained = true;
					continue;
				}
				if ( $entry->isDir() && ! $entry->isLink() ) {
					self::remove_work( $child );
				} else {
					unlink( $child );
				}
			}
			if ( ! $retained ) {
				rmdir( $path );
			}
		}
	}

	private static function remove_work( $path ) {
		foreach ( new DirectoryIterator( $path ) as $entry ) {
			if ( $entry->isDot() ) {
				continue;
			}
			$child = $entry->getPathname();
			if ( $entry->isDir() && ! $entry->isLink() ) {
				self::remove_work( $child );
			} else {
				unlink( $child );
			}
		}
		rmdir( $path );
	}
}
