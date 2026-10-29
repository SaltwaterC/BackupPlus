<?php

/** Planning a recovery to a target time, authenticating the artifacts it needs and verifying it. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_PITR_Orchestrator_Recovery_Plan {

	/** A plan selects only complete transaction boundaries, never an arbitrary byte/time cutoff. */
	public function recovery_plan( $id, $target = null ) {
		BackupPlus_PITR_Manifest::id( $id );
		if ( ! isset( $this->state['chains'][ $id ] ) || 'deleting' === $this->state['chains'][ $id ]['state'] ) {
			throw new RuntimeException( 'PITR chain is unavailable for recovery.' );
		}
		$chain                 = $this->state['chains'][ $id ];
		$chain['destinations'] = array_values(
			array_filter(
				$chain['destinations'],
				function ( $destination ) use ( $chain ) {
					$key = $destination['service'] . ':' . $destination['instance'];
					return empty( $chain['storage_deleting'][ $key ] ) && empty( $chain['storage_removed'][ $key ] );
				}
			)
		);
		$entries               = $chain['entries'];
		$latest                = BackupPlus_PITR_Manifest::chain( $entries );
		$full                  = $entries[0]['manifest'];
		$target                = null === $target ? $latest : BackupPlus_PITR_Manifest::coordinate( $target );
		$selected              = array();
		$found                 = $target === $full['snapshot_coordinate'];
		$stop                  = $full['snapshot_coordinate']['position'];
		if ( ! $found ) {
			foreach ( array_slice( $entries, 1 ) as $entry ) {
				foreach ( $entry['manifest']['segments'] as $segment ) {
					$selected[] = array(
						'segment' => $segment,
						'entry'   => $entry,
					);
					if ( $target === $segment['next'] ) {
						$found = true;
						$stop  = $segment['end'];
						break 2;
					}
					if ( $target['file'] === $segment['start']['file'] ) {
						foreach ( $segment['boundaries'] as $boundary ) {
							if ( $target['position'] === $boundary['position'] ) {
								$found = true;
								$stop  = $target['position'];
								break 3;
							}
						}
					}
				}
			}
		}
		if ( ! $found ) {
			throw new RuntimeException( 'Requested coordinate is outside verified coverage or splits a transaction. Choose a listed boundary.' );
		}
		$payloads = $full['artifacts'];
		foreach ( $selected as $item ) {
			foreach ( $item['entry']['manifest']['artifacts'] as $artifact ) {
				if ( $artifact['name'] === $item['segment']['artifact'] ) {
					$payloads[] = $artifact;
				}
			}
		}
		$estimate = 0;
		foreach ( $payloads as $artifact ) {
			$estimate += $artifact['size'] + ( isset( $artifact['plain_size'] ) ? $artifact['plain_size'] : $artifact['size'] ) + 4 * ( isset( $artifact['raw_size'] ) ? $artifact['raw_size'] : $artifact['size'] );
		}
		$packed = array( $entries[0]['manifest']['backup_id'] => $entries[0] );
		foreach ( $selected as $item ) { $packed[ $item['entry']['manifest']['backup_id'] ] = $item['entry']; }
		foreach ( $packed as $entry ) { if ( isset( $entry['archive'] ) ) { $estimate += $entry['archive']['size']; }
		}
		return array(
			'chain_id'              => $id,
			'baseline_id'           => $full['backup_id'],
			'database'              => $full['database'],
			'source'                => $full['source'],
			'snapshot_coordinate'   => $full['snapshot_coordinate'],
			'target_coordinate'     => $target,
			'stop_position'         => $stop,
			'segments'              => $selected,
			'baseline'              => $entries[0],
			'destinations'          => $chain['destinations'],
			'verified'              => false,
			'estimated_local_bytes' => $estimate,
		);
	}

	private function unpack( $path, $artifact ) {
		$target = $this->work . '/unpacked-' . bin2hex( random_bytes( 8 ) );
		if ( isset( $artifact['compression'] ) && 'zstd' === $artifact['compression'] ) {
			BackupPlus_PITR_Compression::decompress_zstd( $path, $target, $this->work, isset( $artifact['raw_size'] ) ? $artifact['raw_size'] : 0 );
			$produced = filesize( $target );
		} else {
			BackupPlus_PITR_Process::private_file( $target );
			$input  = gzopen( $path, 'rb' );
			$output = fopen( $target, 'wb' );
			if ( ! $input || ! $output ) {
				throw new RuntimeException( 'Could not open compressed PITR artifact.' );
			}
			$produced = 0;
			try {
				while ( ! gzeof( $input ) ) {
					$data      = gzread( $input, 1048576 );
					$produced += strlen( false === $data ? '' : $data );
					if ( isset( $artifact['raw_size'] ) && $produced > $artifact['raw_size'] ) {
						throw new RuntimeException( 'PITR decompression exceeded its declared size.' );
					}
					if ( false === $data || ( '' === $data && ! gzeof( $input ) ) ) {
						throw new RuntimeException( 'Corrupt compressed PITR artifact.' );
					}
					$offset = 0;
					while ( $offset < strlen( $data ) ) {
						$written = fwrite( $output, substr( $data, $offset ) );
						if ( ! $written ) {
							throw new RuntimeException( 'PITR recovery disk is full.' );
						} $offset += $written;
					}
				}
			} finally {
				gzclose( $input );
				fclose( $output );
			}
		}
		if ( isset( $artifact['raw_size'] ) && ( $produced !== $artifact['raw_size'] || ! hash_equals( $artifact['raw_sha256'], hash_file( 'sha256', $target ) ) ) ) {
			throw new RuntimeException( 'PITR uncompressed artifact verification failed.' );
		}
		return $target;
	}

	private function authenticate( $plan, $reader ) {
		$storage = new BackupPlus_PITR_Storage( $this->work, array(), $plan['destinations'], $reader );
		$entries = array( $plan['baseline']['manifest']['backup_id'] => $plan['baseline'] );
		foreach ( $plan['segments'] as $selected ) {
			$entries[ $selected['entry']['manifest']['backup_id'] ] = $selected['entry'];
		}
		foreach ( $entries as $entry ) {
			$archive = isset( $entry['archive'] ) ? $entry['archive'] : null;
			if ( $archive ) { BackupPlus_PITR_Archive::validate( $storage->fetch( $archive ), $entry['manifest'], $entry['locator'] ); }
			$path = $storage->plain( $entry['locator']['manifest'], $archive );
			if ( filesize( $path ) > 16777216 || BackupPlus_PITR_Manifest::validate( json_decode( file_get_contents( $path ), true ) ) !== $entry['manifest'] ) {
				throw new RuntimeException( 'Persistent PITR manifest differs from its indexed metadata.' );
			}
		}
		$baseline = $this->unpack( $storage->plain( $plan['baseline']['manifest']['artifacts'][0], isset( $plan['baseline']['archive'] ) ? $plan['baseline']['archive'] : null ), $plan['baseline']['manifest']['artifacts'][0] );
		$paths    = array();
		foreach ( $plan['segments'] as $selected ) {
			$segment  = $selected['segment'];
			$artifact = null;
			foreach ( $selected['entry']['manifest']['artifacts'] as $candidate ) {
				if ( $candidate['name'] === $segment['artifact'] ) {
					$artifact = $candidate;
					break;
				}
			}
			if ( null === $artifact ) {
				throw new RuntimeException( 'PITR segment has no bound artifact.' );
			}
			$path       = $this->unpack( $storage->plain( $artifact, isset( $selected['entry']['archive'] ) ? $selected['entry']['archive'] : null ), $artifact );
			$inspection = $this->inspect_binlog( $path, $segment['start']['position'], $plan['database'], $plan['source']['engine'] );
			if ( $inspection['end'] !== $segment['end'] || $inspection['next'] !== $segment['next'] || $inspection['boundaries'] !== $segment['boundaries'] ) {
				throw new RuntimeException( 'PITR log contents do not match the recorded coordinates.' );
			}
			$paths[] = $path;
		}
		if ( BackupPlus_PITR_Manifest::snapshot_coordinate( $baseline ) !== $plan['snapshot_coordinate'] ) {
			throw new RuntimeException( 'Baseline SQL coordinate does not match its manifest.' );
		}
		return array( $baseline, $paths );
	}

	public function verify( $id, $target, $reader ) {
		$this->job();
		$plan = $this->recovery_plan( $id, $target );
		$this->authenticate( $plan, $reader );
		$plan['verified'] = true;
		return $plan;
	}

	public function verify_source_restore( $id, $target, $reader ) {
		require_once dirname( __DIR__ ) . '/class-pitr-isolation.php';
		$plan             = $this->verify( $id, $target, $reader );
		list( $baseline ) = $this->authenticate( $plan, $reader );
		$this->assert_no_replayed_events( $baseline, isset( $plan['baseline']['manifest']['database_events'] ) ? $plan['baseline']['manifest']['database_events'] : null );
		global $wpdb;
		if ( $plan['database'] !== DB_NAME || empty( $plan['baseline']['manifest']['wordpress_table_prefix'] ) || $plan['baseline']['manifest']['wordpress_table_prefix'] !== $wpdb->prefix ) { throw new RuntimeException( 'Source recovery requires the original database name and WordPress table prefix.' ); }
		$isolation = new BackupPlus_PITR_Isolation( $this->config, $this->work, $this->root );
		$this->direct_source_check( $isolation->adapter, $plan );
		return $plan;
	}

	/** Fetch exactly the dependencies the browser must authenticate and decrypt. */
	public function browser_manifest( $id, $target, $timestamp, $ticket = '' ) {
		$plan      = $this->recovery_plan( $id, $target );
		$entries   = array( $plan['baseline']['manifest']['backup_id'] => $plan['baseline'] );
		$artifacts = array( array( $plan['baseline']['manifest']['artifacts'][0], $plan['baseline'] ) );
		foreach ( $plan['segments'] as $selected ) {
			$entries[ $selected['entry']['manifest']['backup_id'] ] = $selected['entry'];
			foreach ( $selected['entry']['manifest']['artifacts'] as $artifact ) {
				if ( $artifact['name'] === $selected['segment']['artifact'] ) { $artifacts[] = array( $artifact, $selected['entry'] ); }
			}
		}
		foreach ( $entries as $entry ) { $artifacts[] = array( $entry['locator']['manifest'], $entry ); }
		$storage = new BackupPlus_PITR_Storage( $this->work, array(), $plan['destinations'] );
		$names   = array();
		foreach ( $artifacts as $item ) {
			list( $artifact, $entry ) = $item;
			if ( '.crypt' !== substr( $artifact['name'], -6 ) ) { continue; }
			$storage->member( $artifact, isset( $entry['archive'] ) ? $entry['archive'] : null, true );
			$names[ $artifact['name'] ] = $artifact['name'];
		}
		return BackupPlus_Age_Restore::manifest( array( 'db' => array_values( $names ) ), $timestamp, array( 'db' ), $ticket );
	}

	/** The ordinary database importer needs the full snapshot as a temporary standalone file. */
	public static function stage_baseline( $backup ) {
		if ( empty( $backup['pitr']['baseline_archive'] ) ) { return; }
		$id       = $backup['pitr']['chain_id'];
		$worker   = new self();
		$plan     = $worker->recovery_plan( $id );
		$entry    = $plan['baseline'];
		$artifact = $entry['manifest']['artifacts'][0];
		if ( empty( $entry['archive'] ) || $entry['archive'] !== $backup['pitr']['baseline_archive'] || $artifact['name'] !== $backup['db'] ) { throw new RuntimeException( 'PITR baseline archive does not match the restore selection.' ); }
		$storage = new BackupPlus_PITR_Storage( $worker->work, array(), $plan['destinations'] );
		BackupPlus_PITR_Archive::validate( $storage->fetch( $entry['archive'] ), $entry['manifest'], $entry['locator'] );
		$storage->member( $artifact, $entry['archive'], true );
	}
}
