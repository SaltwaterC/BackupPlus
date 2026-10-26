<?php

/** Capturing the full snapshot and the incremental binary log artifacts, and publishing them to the destinations. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_PITR_Orchestrator_Capture {

	/** Bind source history to a random, logged plugin option, including across filename reuse. */
	private function source_anchor( $adapter, $minimum ) {
		global $wpdb;
		$before = $adapter->status();
		$token  = bin2hex( random_bytes( 32 ) );
		// The application writes its own ancestry marker; the backup account stays data-read-only.
		$was_logged = $wpdb->get_var( 'SELECT @@session.sql_log_bin' );
		if ( '0' === $was_logged && false === $wpdb->query( 'SET SESSION sql_log_bin=1' ) ) { throw new RuntimeException( 'Could not log the PITR source marker.' ); }
		try {
			$sql = BackupPlus_SQL::wpdb();
			if ( false === $sql->execute( "INSERT INTO %i (option_name, option_value, autoload) VALUES ('backupplus_pitr_source_anchor', ?, 'off') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)", array( array( $this->config['database'], $wpdb->options ) ), array( $token ) ) ) { throw new RuntimeException( 'WordPress could not write its PITR source marker. ' . $sql->last_error() ); }
		} finally { if ( '0' === $was_logged ) { $wpdb->query( 'SET SESSION sql_log_bin=0' ); }
		}
		$names = array_keys( $adapter->logs() );
		$start = array_search( $before['file'], $names, true );
		if ( false === $start || false === array_search( $minimum['file'], $names, true ) ) {
			throw new RuntimeException( 'Source log was reset or purged while anchoring the backup.' );
		}
		foreach ( array_slice( $names, $start ) as $file ) {
			$path   = $adapter->fetch_log( $file );
			$handle = fopen( $path, 'rb' );
			$tail   = '';
			$found  = false;
			try {
				while ( ! feof( $handle ) ) {
					$chunk = fread( $handle, 1048576 );
					if ( false === $chunk ) {
						throw new RuntimeException( 'Could not read source-log proof.' );
					}
					if ( false !== strpos( $tail . $chunk, $token ) ) {
						$found = true;
						break;
					}
					$tail = substr( $chunk, -63 );
				}
			} finally {
				fclose( $handle );
			}
			if ( $found ) {
				$position = filesize( $path );
				if ( $minimum['file'] === $file && $position < $minimum['position'] ) {
					throw new RuntimeException( 'Source-log proof precedes the snapshot.' );
				}
				return array(
					'coordinate' => array(
						'file'     => $file,
						'position' => $position,
					),
					'sha256'     => BackupPlus_PITR_Binlog::prefix_hash( $path, $position ),
				);
			}
		}
		throw new RuntimeException( 'The source did not log the PITR ancestry marker. Check log filters and compression.' );
	}

	private function sql_header( $input, $original = null ) {
		global $wpdb;
		$path    = $this->work . '/wordpress-' . bin2hex( random_bytes( 8 ) ) . '.sql';
		$header  = "# WordPress MySQL database backup\n# Created by BackupPlus PITR\n";
		$uploads = wp_upload_dir();
		foreach (
			array(
				'Backup of'             => untrailingslashit( site_url() ),
				'Home URL'              => untrailingslashit( home_url() ),
				'Content URL'           => untrailingslashit( content_url() ),
				'Uploads URL'           => $uploads['baseurl'],
				'Table prefix'          => $wpdb->prefix,
				'Filtered table prefix' => $wpdb->prefix,
				'ABSPATH'               => ABSPATH,
				'Site info'             => 'multisite=' . ( is_multisite() ? '1' : '0' ),
			) as $key => $value
		) {
			$header .= '# ' . $key . ': ' . str_replace( array( "\r", "\n" ), '', $value ) . "\n";
		}
		if ( null !== $original ) {
			$handle = fopen( $original, 'rb' );
			$header = '';
			try {
				while ( false !== ( $line = fgets( $handle, 65536 ) ) && '#' === substr( $line, 0, 1 ) && strlen( $header ) < 65536 ) {
					$header .= $line;
				}
			} finally {
				fclose( $handle );
			}
			if ( '' === $header ) {
				throw new RuntimeException( 'Original WordPress snapshot metadata is missing.' );
			}
		}
		BackupPlus_PITR_Process::private_file( $path, $header );
		$in  = fopen( $input, 'rb' );
		$out = fopen( $path, 'ab' );
		try {
			if ( false === stream_copy_to_stream( $in, $out ) ) {
				throw new RuntimeException( 'Could not prepare WordPress dump metadata.' );
			}
		} finally {
			fclose( $in );
				fclose( $out );
		}
		return $path;
	}

	private function stage_manifest( $manifest, $storage, $fingerprint, $destinations, $encryption ) {
		BackupPlus_PITR_Manifest::validate( $manifest );
		$prefix  = 'backup_pitr_' . $manifest['chain_id'] . '_' . $manifest['backup_id'] . '_';
		$json    = $this->work . '/manifest-' . bin2hex( random_bytes( 8 ) ) . '.json';
		$encoded = json_encode( $manifest, JSON_UNESCAPED_SLASHES );
		if ( false === $encoded || strlen( $encoded ) > 16777216 ) {
			throw new RuntimeException( 'PITR manifest exceeds the supported limit; shorten the capture interval.' );
		}
		BackupPlus_PITR_Process::private_file( $json, $encoded );
		$artifact     = $storage->artifact( $json, $prefix . 'manifest.json', false );
		$locator      = array(
			'manifest_version' => 1,
			'chain_id'         => $manifest['chain_id'],
			'backup_id'        => $manifest['backup_id'],
			'manifest'         => array_intersect_key( $artifact, array_flip( array( 'name', 'sha256', 'size' ) ) ),
		);
		$locator_path = $storage->path( $prefix . 'locator.json' );
		BackupPlus_PITR_Process::private_file( $locator_path, json_encode( $locator, JSON_UNESCAPED_SLASHES ) );
		$locator_file           = array(
			'name'   => basename( $locator_path ),
			'sha256' => hash_file( 'sha256', $locator_path ),
			'size'   => filesize( $locator_path ),
		);
		$this->state['pending'] = array(
			'entry'        => array(
				'manifest' => $manifest,
				'sha256'   => $artifact['sha256'],
				'locator'  => $locator,
			),
			'files'        => array_merge( $manifest['artifacts'], array( $artifact, $locator_file ) ),
			'fingerprint'  => $fingerprint,
			'destinations' => $destinations,
			'encryption'   => $encryption,
			'receipts'     => array(),
		);
		$this->save();
	}

	private function full( $adapter, $storage, $fingerprint, $destinations, $encryption ) {
		global $wpdb;
		$source = $adapter->source( $this->config['database'] );
		$this->assert_wordpress_source( $source, $this->config['database'] );
		$source['log_epoch'] = bin2hex( random_bytes( 16 ) );
		$id                  = bin2hex( random_bytes( 16 ) );
		$chain               = bin2hex( random_bytes( 16 ) );
		$created             = time();
		$sql                 = $this->work . '/full-' . bin2hex( random_bytes( 8 ) ) . '.sql';
		$before_anchor       = $this->source_anchor( $adapter, $adapter->status() );
		$coordinate          = $adapter->dump( $this->config['database'], $sql );
		$after               = $adapter->source( $this->config['database'] );
		foreach ( $after as $key => $value ) {
			if ( $source[ $key ] !== $value ) {
				throw new RuntimeException( 'Database source changed during the full snapshot.' );
			}
		}
		$anchor = $this->source_anchor( $adapter, $coordinate );
		$proof  = $adapter->fetch_log( $before_anchor['coordinate']['file'] );
		if ( ! hash_equals( $before_anchor['sha256'], BackupPlus_PITR_Binlog::prefix_hash( $proof, $before_anchor['coordinate']['position'] ) ) ) {
			throw new RuntimeException( 'Source binary logs were reset during the full snapshot.' );
		}
		$artifact = $storage->artifact( $this->sql_header( $sql ), 'backup_pitr_' . $chain . '_' . $id . '_full.sql.gz' );
		$old_id   = $this->state['active'];
		if ( $old_id && isset( $this->state['chains'][ $old_id ] ) && 'complete' === $this->state['chains'][ $old_id ]['state'] && $fingerprint === $this->state['chains'][ $old_id ]['fingerprint'] ) {
			try {
				if ( $this->capture( $adapter, $storage, $fingerprint, $destinations, $encryption ) ) {
					$this->publish( $storage );
				}
			} catch ( Throwable $error ) {
				if ( $this->state['pending'] ) {
					throw $error;
				} $this->state['chains'][ $old_id ]['state'] = 'broken';
				$this->save();
			}
		}
		// Old-chain capture closes logs across the new snapshot, so daily rollover leaves no historical hole.
		$proof      = $adapter->fetch_log( $before_anchor['coordinate']['file'] );
		$post_proof = $adapter->fetch_log( $anchor['coordinate']['file'] );
		if ( ! hash_equals( $before_anchor['sha256'], BackupPlus_PITR_Binlog::prefix_hash( $proof, $before_anchor['coordinate']['position'] ) ) || ! hash_equals( $anchor['sha256'], BackupPlus_PITR_Binlog::prefix_hash( $post_proof, $anchor['coordinate']['position'] ) ) ) {
			throw new RuntimeException( 'Source log history changed while preparing the new baseline.' );
		}
		$manifest = array(
			'manifest_version'       => 1,
			'kind'                   => 'database-full',
			'backup_id'              => $id,
			'chain_id'               => $chain,
			'source'                 => $source,
			'database'               => $this->config['database'],
			'created_at'             => $created,
			'snapshot_coordinate'    => $coordinate,
			'database_events'        => $adapter->snapshot_events,
			'anchor'                 => $anchor,
			'snapshot_gtid_state'    => null,
			'artifacts'              => array( $artifact ),
			'snapshot_mode'          => 'transactional-ddl-guard',
			'wordpress_table_prefix' => $wpdb->prefix,
		);
		$this->stage_manifest( $manifest, $storage, $fingerprint, $destinations, $encryption );
	}

	private function capture( $adapter, $storage, $fingerprint, $destinations, $encryption ) {
		$chain   = $this->state['chains'][ $this->state['active'] ];
		$entries = $chain['entries'];
		$first   = $entries[0]['manifest'];
		$last    = end( $entries );
		$cursor  = BackupPlus_PITR_Manifest::chain( $entries );
		$source  = $adapter->source( $first['database'] );
		$this->assert_wordpress_source( $source, $first['database'] );
		foreach ( $source as $key => $value ) {
			if ( $first['source'][ $key ] !== $value ) {
				throw new RuntimeException( 'PITR source changed; create a new full snapshot.' );
			}
		}
		$adapter->query( 'FLUSH BINARY LOGS' );
		$logs        = $adapter->logs();
		$names       = array_keys( $logs );
		$current     = end( $names );
		$start_index = array_search( $cursor['file'], $names, true );
		if ( false === $start_index ) {
			throw new RuntimeException( 'Required source binary log was purged; the historical chain cannot be extended.' );
		}
		$id              = bin2hex( random_bytes( 16 ) );
		$prefix          = 'backup_pitr_' . $first['chain_id'] . '_' . $id . '_';
		$segments        = array();
		$artifacts       = array();
		$next            = $cursor;
		$anchor          = $last['manifest']['anchor'];
		$anchor_verified = false;
		for ( $i = $start_index; $i < count( $names ) - 1; ++$i ) {
			if ( $next['file'] !== $names[ $i ] ) {
				throw new RuntimeException( 'Source binary-log rotation chain has a gap.' );
			}
			$path = $adapter->fetch_log( $names[ $i ] );
			if ( filesize( $path ) !== $logs[ $names[ $i ] ] ) {
				throw new RuntimeException( 'Closed source binary-log size changed.' );
			}
			if ( $anchor['coordinate']['file'] === $names[ $i ] ) {
				if ( ! hash_equals( $anchor['sha256'], BackupPlus_PITR_Binlog::prefix_hash( $path, $anchor['coordinate']['position'] ) ) ) {
					throw new RuntimeException( 'Source binary-log ancestry changed or filenames were reused.' );
				}
				$anchor_verified = true;
			}
			$inspection = $this->inspect_binlog( $path, $next['position'], $first['database'], $source['engine'] );
			if ( $inspection['next']['file'] !== $names[ $i + 1 ] ) {
				throw new RuntimeException( 'Source binary-log successor is missing.' );
			}
			$artifact    = $storage->artifact( $path, $prefix . 'log' . count( $segments ) . '.bin.gz' );
			$segments[]  = array_merge(
				array(
					'start'    => $next,
					'artifact' => $artifact['name'],
				),
				$inspection
			);
			$artifacts[] = $artifact;
			$next        = $inspection['next'];
		}
		if ( ! $segments ) {
			return false;
		}
		if ( ! $anchor_verified ) {
			throw new RuntimeException( 'The source binary-log ancestry proof is missing.' );
		}
		$new_anchor = $this->source_anchor( $adapter, $next );
		$proof      = $adapter->fetch_log( $anchor['coordinate']['file'] );
		if ( ! hash_equals( $anchor['sha256'], BackupPlus_PITR_Binlog::prefix_hash( $proof, $anchor['coordinate']['position'] ) ) ) {
			throw new RuntimeException( 'Source binary logs were reset during incremental capture.' );
		}
		$manifest = array(
			'manifest_version'         => 1,
			'kind'                     => 'database-binlog',
			'backup_id'                => $id,
			'chain_id'                 => $first['chain_id'],
			'baseline_id'              => $first['backup_id'],
			'previous_backup_id'       => $last['manifest']['backup_id'],
			'previous_manifest_sha256' => $last['sha256'],
			'source'                   => $first['source'],
			'database'                 => $first['database'],
			'created_at'               => time(),
			'start_coordinate'         => $cursor,
			'end_coordinate'           => $next,
			'segments'                 => $segments,
			'anchor'                   => $new_anchor,
			'artifacts'                => $artifacts,
		);
		$this->stage_manifest( $manifest, $storage, $fingerprint, $destinations, $encryption );
		return true;
	}

	private function publish( $storage ) {
		$pending  = $storage->publish(
			$this->state['pending'],
			function ( $pending ) {
				$this->state['pending'] = $pending;
				$this->save();
			}
		);
		$entry    = $pending['entry'];
		$manifest = $entry['manifest'];
		$id       = $manifest['chain_id'];
		if ( ! isset( $this->state['chains'][ $id ] ) ) {
			$this->state['chains'][ $id ] = array(
				'entries'      => array(),
				'destinations' => $pending['destinations'],
				'fingerprint'  => $pending['fingerprint'],
				'state'        => 'complete',
				'imported'     => false,
			);
		}
		$chain              = &$this->state['chains'][ $id ];
		$chain['entries'][] = $entry;
		BackupPlus_PITR_Manifest::chain( $chain['entries'] );
		$chain['cursor']       = 'database-full' === $manifest['kind'] ? $manifest['snapshot_coordinate'] : $manifest['end_coordinate'];
		$chain['last_capture'] = time();
		if ( 'database-full' === $manifest['kind'] && ! empty( $this->state['active'] ) && $this->state['active'] !== $id ) {
			$this->state['protected_previous'][] = $this->state['active'];
		}
		if ( 'database-binlog' === $manifest['kind'] ) {
			$this->state['protected_previous'] = array();
		}
		$this->state['active']  = $id;
		$this->state['pending'] = null;
		$this->save();
		$this->history( $id );
		return $manifest;
	}

	public function run( $mode = 'run', $nonce = false ) {
		global $backupplus;
		if ( empty( $this->config['enabled'] ) ) {
			throw new RuntimeException( 'Database point-in-time recovery is disabled.' );
		}
		$this->job( $nonce );
		try {
			$destinations = BackupPlus_PITR_Storage::destinations();
			$encryption   = $this->encryption();
			$fingerprint  = $this->fingerprint( $destinations, $encryption );
			$storage      = new BackupPlus_PITR_Storage( $this->work, $encryption, $destinations );
			if ( $this->state['pending'] ) {
				if ( $fingerprint !== $this->state['pending']['fingerprint'] ) {
					throw new RuntimeException( 'PITR configuration changed with an unpublished backup pending; restore the original configuration before retrying.' );
				}
				$result = $this->publish( $storage );
			} else {
				$adapter = $this->database( $this->config, $this->work );
				$active  = ! empty( $this->state['active'] ) && isset( $this->state['chains'][ $this->state['active'] ] ) ? $this->state['chains'][ $this->state['active'] ] : null;
				$due     = ! $active || 'complete' !== $active['state'] || $active['fingerprint'] !== $fingerprint || time() - $active['entries'][0]['manifest']['created_at'] >= 23 * 3600;
				if ( 'full' === $mode || $due ) {
					// Preserve old-chain coverage up to rollover when the same source/configuration is still usable.
					if ( $active && 'complete' === $active['state'] && $active['fingerprint'] === $fingerprint ) {
						try {
							if ( $this->capture( $adapter, $storage, $fingerprint, $destinations, $encryption ) ) {
								$this->publish( $storage );
							}
						} catch ( Throwable $error ) {
							if ( $this->state['pending'] ) {
								throw $error;
							} $this->state['chains'][ $this->state['active'] ]['state'] = 'broken';
							$this->save();
						}
					}
					$this->full( $adapter, $storage, $fingerprint, $destinations, $encryption );
				} elseif ( 'capture' === $mode || time() - $active['last_capture'] >= $this->config['interval'] * 60 ) {
					try {
						$this->capture( $adapter, $storage, $fingerprint, $destinations, $encryption );
					} catch ( Throwable $error ) {
						$this->state['chains'][ $this->state['active'] ]['state'] = 'broken';
						$this->save();
						throw $error;
					}
				}
				$result = $this->state['pending'] ? $this->publish( $storage ) : array( 'kind' => 'not-due' );
			}
			$this->state['error']        = null;
			$this->state['last_success'] = time();
			$this->save();
			update_option( self::STATUS, $this->summary(), false );
			$backupplus->jobdata_set( 'jobstatus', 'finished' );
			if ( ! BackupPlus_Retention_Cleanup::deferred() ) {
				$this->prune();
			}
			return $result;
		} catch ( Throwable $error ) {
			$this->state['error'] = $error->getMessage();
			$this->save();
			update_option( self::STATUS, $this->summary(), false );
			$backupplus->jobdata_set( 'jobstatus', 'finished' );
			throw $error;
		}
	}
}
