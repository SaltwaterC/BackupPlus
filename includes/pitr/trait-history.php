<?php

/** Chain history, summaries, rescanning remote storage and the status panel. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_PITR_Orchestrator_History {

	private function history( $id ) {
		global $backupplus;
		$chain = &$this->state['chains'][ $id ];
		// The log of the latest job that published into this chain backs the 'View Log' button.
		if ( isset( $backupplus->logfile_handle, $backupplus->nonce ) && is_resource( $backupplus->logfile_handle ) && preg_match( '/^[0-9a-f]{12}$/', (string) $backupplus->nonce ) ) {
			$chain['log'] = $backupplus->nonce;
		}
		$full    = $chain['entries'][0]['manifest'];
		$history = BackupPlus_Backup_History::get_history();
		if ( ! isset( $chain['history_time'] ) ) {
			$time = $full['created_at'];
			while ( isset( $history[ $time ] ) ) {
				++$time;
			} $chain['history_time'] = $time;
		}
		if ( isset( $history[ $chain['history_time'] ] ) && ( empty( $history[ $chain['history_time'] ]['pitr']['chain_id'] ) || $id !== $history[ $chain['history_time'] ]['pitr']['chain_id'] ) ) {
			$time = max( time(), $chain['history_time'] + 1 );
			while ( isset( $history[ $time ] ) ) {
				++$time;
			} $chain['history_time'] = $time;
		}
		$time      = $chain['history_time'];
		$artifact  = $full['artifacts'][0];
		$services  = array();
		$instances = array();
		foreach ( $chain['destinations'] as $destination ) {
			$services[]                             = $destination['service'];
			$instances[ $destination['service'] ][] = $destination['instance'];
		}
		$history[ $time ] = array_merge(
			isset( $history[ $time ] ) ? $history[ $time ] : ( ! empty( $chain['pinned'] ) ? array( 'always_keep' => true ) : array() ),
			array(
				'db'                   => $artifact['name'],
				'db-size'              => $artifact['size'],
				'nonce'                => substr( $full['backup_id'], 0, 12 ),
				'log'                  => isset( $chain['log'] ) ? $chain['log'] : ( isset( $history[ $time ]['log'] ) ? $history[ $time ]['log'] : '' ),
				'native'               => ! $chain['imported'],
				'service'              => $services ? array_values( array_unique( $services ) ) : array( 'none' ),
				'service_instance_ids' => $instances,
				'pitr'                 => self::chain_metadata( $id, $chain ),
				'checksums'            => array( 'sha256' => array( 'db0' . ( '.crypt' === substr( $artifact['name'], -6 ) ? '.crypt' : '' ) => $artifact['sha256'] ) ),
			)
		);
		BackupPlus_Backup_History::save_history( $history );
		$this->save();
	}

	/** Republish verified local index metadata without decrypting or changing backup artifacts. */
	public function rebuild_history() {
		$ids = array();
		foreach ( $this->state['chains'] as $id => $chain ) {
			if ( 'deleting' === $chain['state'] || empty( $chain['entries'] ) ) { continue; }
			self::chain_metadata( $id, $chain );
			$ids[] = $id;
		}
		foreach ( $ids as $id ) { $this->history( $id ); }
		return count( $ids );
	}

	/** Recovery endpoints are identified by immutable backup IDs, never rounded timestamps. */
	public static function chain_metadata( $id, $chain ) {
		$cursor = BackupPlus_PITR_Manifest::chain( $chain['entries'] );
		if ( $cursor !== $chain['cursor'] ) {
			throw new RuntimeException( 'The PITR chain index has an inconsistent endpoint.' );
		}
		$points    = array();
		$downloads = array();
		$encrypted = false;
		foreach ( $chain['entries'] as $index => $entry ) {
			$manifest = $entry['manifest'];
			foreach ( $manifest['artifacts'] as $artifact ) {
				$encrypted = $encrypted || '.crypt' === substr( $artifact['name'], -6 );
			}
			// Keep the full dump at index zero for ordinary full-database restores.
			// Include the metadata needed to discover and recover a downloaded chain.
			$locator = json_encode( $entry['locator'], JSON_UNESCAPED_SLASHES );
			$files   = array_merge(
				$manifest['artifacts'],
				array(
					$entry['locator']['manifest'],
					array(
						'name' => 'backup_pitr_' . $id . '_' . $manifest['backup_id'] . '_locator.json',
						'size' => strlen( $locator ),
					),
				)
			);
			foreach ( $files as $file ) {
				$downloads[] = array(
					'name'      => $file['name'],
					'size'      => $file['size'],
					'increment' => $index,
					'timestamp' => $manifest['created_at'],
				);
			}
			$points[] = array(
				'backup_id'  => $manifest['backup_id'],
				'timestamp'  => $manifest['created_at'],
				'coordinate' => 0 === $index ? $manifest['snapshot_coordinate'] : $manifest['end_coordinate'],
				'increment'  => $index,
				'encrypted'  => $encrypted,
			);
		}
		return array(
			'chain_id'            => $id,
			'snapshot_coordinate' => $chain['entries'][0]['manifest']['snapshot_coordinate'],
			'end_coordinate'      => $cursor,
			'state'               => $chain['state'],
			'points'              => $points,
			'downloads'           => $downloads,
		);
	}

	/** Enrich older history rows from the atomically written index without starting a worker. */
	public static function decorate_history( $history ) {
		$has_chains = false;
		foreach ( $history as $backup ) {
			$has_chains = $has_chains || ! empty( $backup['pitr'] );
		}
		if ( ! $has_chains ) { return $history; }
		global $backupplus;
		$path  = $backupplus->backups_dir_location() . '/pitr/state.php';
		$state = null;
		if ( ! is_link( $path ) && is_file( $path ) && filesize( $path ) < 67108864 ) {
			$raw = file_get_contents( $path );
			if ( 0 === strpos( $raw, '<?php exit; ?>' ) ) {
				$state = json_decode( substr( $raw, strlen( '<?php exit; ?>' ) ), true );
			}
		}
		foreach ( $history as &$backup ) {
			if ( empty( $backup['pitr'] ) ) { continue; }
			$id = isset( $backup['pitr']['chain_id'] ) ? $backup['pitr']['chain_id'] : '';
			try {
				if ( ! is_array( $state ) || ! isset( $state['version'], $state['chains'][ $id ] ) || 1 !== $state['version'] ) {
					throw new RuntimeException( 'The PITR chain index is unavailable.' );
				}
				$backup['pitr'] = self::chain_metadata( $id, $state['chains'][ $id ] );
			} catch ( Throwable $error ) {
				$backup['pitr']['state']  = 'unavailable';
				$backup['pitr']['points'] = array();
				unset( $backup['pitr']['downloads'] );
			}
		}
		unset( $backup );
		return $history;
	}

	public function summary( $include_boundaries = false ) {
		$result = array(
			'enabled'          => ! empty( $this->config['enabled'] ),
			'interval_minutes' => isset( $this->config['interval'] ) ? $this->config['interval'] : null,
			'pending'          => ! empty( $this->state['pending'] ),
			'error'            => isset( $this->state['error'] ) ? $this->state['error'] : null,
			'chains'           => array(),
		);
		foreach ( $this->state['chains'] as $id => $chain ) {
			$full       = $chain['entries'][0]['manifest'];
			$boundaries = array();
			foreach ( $include_boundaries ? $chain['entries'] : array() as $entry ) {
				foreach ( isset( $entry['manifest']['segments'] ) ? $entry['manifest']['segments'] : array() as $segment ) {
					foreach ( $segment['boundaries'] as $boundary ) {
						$boundaries[] = array(
							'file'        => $segment['start']['file'],
							'position'    => $boundary['position'],
							'recorded_at' => $boundary['timestamp'],
						);
					}
				}
			}
			$result['chains'][] = array(
				'chain_id'            => $id,
				'active'              => $id === $this->state['active'],
				'state'               => $chain['state'],
				'baseline_at'         => $full['created_at'],
				'snapshot_coordinate' => $full['snapshot_coordinate'],
				'end_coordinate'      => $chain['cursor'],
				'last_capture'        => isset( $chain['last_capture'] ) ? $chain['last_capture'] : null,
				'increments'          => count( $chain['entries'] ) - 1,
				'boundaries'          => $boundaries,
				'destinations'        => $chain['destinations'],
			);
		}
		return $result;
	}

	public function rescan( $reader ) {
		$this->job();
		$storage = new BackupPlus_PITR_Storage( $this->work, array(), null, $reader );
		$groups  = array();
		foreach ( $storage->discover() as $locator ) {
			$path = $storage->plain( $locator['manifest'] );
			if ( filesize( $path ) > 16777216 ) {
				throw new RuntimeException( 'PITR manifest exceeds its limit.' );
			}
			$manifest = BackupPlus_PITR_Manifest::validate( json_decode( file_get_contents( $path ), true ) );
			if ( $manifest['chain_id'] !== $locator['chain_id'] || $manifest['backup_id'] !== $locator['backup_id'] ) {
				throw new RuntimeException( 'PITR discovery binding mismatch.' );
			}
			$groups[ $manifest['chain_id'] ][ $manifest['backup_id'] ] = array(
				'manifest' => $manifest,
				'sha256'   => $locator['manifest']['sha256'],
				'locator'  => $locator,
			);
		}
		foreach ( $groups as $id => $entries ) {
			$ordered = array();
			foreach ( $entries as $key => $entry ) {
				if ( 'database-full' === $entry['manifest']['kind'] ) {
					$ordered[] = $entry;
					unset( $entries[ $key ] );
					break;
				}
			}
			if ( ! $ordered ) {
				throw new RuntimeException( 'Discovered PITR chain is missing its full backup.' );
			}
			while ( $entries ) {
				$last    = end( $ordered );
				$matches = array();
				foreach ( $entries as $key => $entry ) {
					if ( isset( $entry['manifest']['previous_backup_id'] ) && $entry['manifest']['previous_backup_id'] === $last['manifest']['backup_id'] ) {
						$matches[ $key ] = $entry;
					}
				}
				if ( 1 !== count( $matches ) ) {
					throw new RuntimeException( 'Discovered PITR chain contains a gap or a fork.' );
				}
				$key       = key( $matches );
				$ordered[] = $entries[ $key ];
				unset( $entries[ $key ] );
			}
			$cursor = BackupPlus_PITR_Manifest::chain( $ordered );
			foreach ( $ordered as $entry ) {
				foreach ( $entry['manifest']['artifacts'] as $artifact ) {
					$storage->fetch( $artifact );
				}
			}
			if ( ! isset( $this->state['chains'][ $id ] ) ) {
				$this->state['chains'][ $id ] = array(
					'entries'      => $ordered,
					'cursor'       => $cursor,
					'destinations' => BackupPlus_PITR_Storage::destinations(),
					'fingerprint'  => '',
					'state'        => 'complete',
					'imported'     => true,
				);
				$this->history( $id );
			} else {
				$known = $this->state['chains'][ $id ];
				if ( 'deleting' === $known['state'] || array_column( $known['entries'], 'sha256' ) !== array_column( $ordered, 'sha256' ) ) { throw new RuntimeException( 'Rescan cannot rebind an incomplete or changed local chain.' ); }
				// Successful discovery and payload verification prove that these current
				// storage settings hold the same complete chain (including after credential rotation).
				$this->state['chains'][ $id ]['destinations'] = array_values(
					array_filter(
						BackupPlus_PITR_Storage::destinations(),
						function ( $destination ) use ( $known ) {
							$key = $destination['service'] . ':' . $destination['instance'];
							return empty( $known['storage_removed'][ $key ] ) && empty( $known['storage_deleting'][ $key ] );
						}
					)
				);
			}
		}
		$this->save();
		$this->rebuild_history();
		return $this->summary();
	}

	public static function render() {
		$status = get_option( self::STATUS, array() );
		echo '<tr><th scope="row">' . esc_html__( 'Database point-in-time recovery', 'backupplus' ) . '</th><td>';
		$config = self::config();
		BackupPlus_PITR_Admin::controls( $config );
		if ( ! empty( $config['enabled'] ) && ! empty( $status['error'] ) ) {
			echo '<p role="alert">' . esc_html( $status['error'] ) . '</p>';
		}
		echo '</td></tr>';
	}
}
