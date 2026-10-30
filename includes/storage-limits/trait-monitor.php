<?php
/** Provider adapters, measurement, durable deletion progress and serialized monitoring. */
if ( ! defined( 'ABSPATH' ) ) { die( 'No direct access allowed' ); }
trait BackupPlus_Storage_Limits_Monitor {
	private static function active() {
		return BackupPlus_Retention_Cleanup::backup_active() || (bool) get_site_option( 'backupplus_restore_in_progress', false ); }

	/** What a person calls a destination: the label given to its instance, else the provider name, never the internal "service:instance" key. */
	public static function label( $service, $id ) {
		global $backupplus;
		if ( 'local' === $service ) {
			return __( 'Local storage', 'backupplus' );
		}
		$name = is_object( $backupplus ) && isset( $backupplus->backup_methods[ $service ] ) ? $backupplus->backup_methods[ $service ] : $service;
		if ( '' === (string) $id ) {
			return $name;
		}
		$storage   = BackupPlus_Storage_Methods_Interface::get_storage_objects_and_ids( array( $service ) );
		$instances = isset( $storage[ $service ]['instance_settings'] ) && is_array( $storage[ $service ]['instance_settings'] ) ? $storage[ $service ]['instance_settings'] : array();
		if ( ! isset( $instances[ $id ] ) ) {
			/* translators: %s: Remote storage provider */
			return sprintf( __( '%s (removed)', 'backupplus' ), $name );
		}
		$label = isset( $instances[ $id ]['instance_label'] ) ? trim( (string) $instances[ $id ]['instance_label'] ) : '';
		if ( '' === $label ) {
			$position = array_search( (string) $id, array_map( 'strval', array_keys( $instances ) ), true );
			return $name . ( count( $instances ) > 1 ? ' (' . ( $position + 1 ) . ')' : '' );
		}
		return false === stripos( $label, $name ) ? $label . ' (' . $name . ')' : $label;
	}

	public static function destinations() {
		$result   = array(
			'local:' => array(
				'service'  => 'local',
				'instance' => '',
			),
		);
		$services = (array) BackupPlus_Options::get_backupplus_option( 'backupplus_service', array() );
		foreach ( self::limits() as $service => $instances ) {
			foreach ( $instances as $id => $limits ) { $result[ $service . ':' . $id ] = array(
				'service'  => $service,
				'instance' => (string) $id,
			); }
		}
		foreach ( $services as $service ) {
			if ( ! $service || in_array( $service, array( 'none', 'email' ), true ) ) { continue; }
			$storage = BackupPlus_Storage_Methods_Interface::get_enabled_storage_objects_and_ids( array( $service ) );
			if ( empty( $storage[ $service ]['object'] ) ) {
				$result[ $service . ':' ] = array(
					'service'  => $service,
					'instance' => '',
				);
				continue;
			}
			$object = $storage[ $service ]['object'];
			$ids    = $object->supports_feature( 'multi_options' ) ? array_keys( $storage[ $service ]['instance_settings'] ) : array( '' );
			foreach ( $ids as $id ) { $result[ $service . ':' . $id ] = array(
				'service'  => $service,
				'instance' => (string) $id,
			); }
		}
		return $result;
	}

	public static function provider( $destination ) {
		$service = $destination['service'];
		$id      = $destination['instance'];
		$storage = BackupPlus_Storage_Methods_Interface::get_storage_objects_and_ids( array( $service ) );
		if ( empty( $storage[ $service ]['object'] ) ) { throw new RuntimeException( 'Storage provider unavailable.' ); }
		$object = $storage[ $service ]['object'];
		if ( $object->supports_feature( 'multi_options' ) ) {
			if ( ! isset( $storage[ $service ]['instance_settings'][ $id ] ) ) { throw new RuntimeException( 'Storage instance unavailable.' ); }
			$object->set_options( $storage[ $service ]['instance_settings'][ $id ], false, $id );
		}
		return $object;
	}

	/** Only published artifacts and known imported archives; never work trees, logs or temporary files. */
	public static function measure( $destination, $history ) {
		global $backupplus;
		$known = array();
		foreach ( $history as $set ) { $known = array_replace( $known, self::artifacts( $set ) ); }
		$inventory = array();
		if ( 'local' === $destination['service'] ) {
			$directory = $backupplus->backups_dir_location();
			$names     = scandir( $directory );
			if ( false === $names ) { throw new RuntimeException( 'Local archive directory unavailable.' ); }
			$listing = array();
			foreach ( $names as $name ) {
				if ( ! self::artifact_name( $name, $known ) ) { continue; }
				$path = $directory . '/' . $name;
				if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) { throw new RuntimeException( 'Local archive cannot be measured.' ); }
				clearstatcache( true, $path );
				$listing[] = array(
					'name' => $name,
					'size' => filesize( $path ),
				);
			}
		} else {
			$object  = self::provider( $destination );
			$listing = $object->listfiles();
			if ( ! is_array( $listing ) || is_wp_error( $listing ) ) { throw new RuntimeException( 'Remote archive listing unavailable or incomplete.' ); }
			// Imported archives can have names outside the providers' default backup_ prefix.
			foreach ( $known as $name => $unused ) {
				if ( 0 === strpos( $name, 'backup_' ) ) { continue; }
				$extra = $object->listfiles( $name );
				if ( ! is_array( $extra ) || is_wp_error( $extra ) ) { throw new RuntimeException( 'Imported archive listing unavailable or incomplete.' ); }
				$listing = array_merge( $listing, $extra );
			}
		}
		foreach ( $listing as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['name'] ) ) { throw new RuntimeException( 'Incomplete archive listing.' ); }
			$name = $item['name'];
			if ( ! self::artifact_name( $name, $known ) ) { continue; }
			$size = isset( $item['size'] ) && false !== $item['size'] ? $item['size'] : ( isset( $known[ $name ]['size'] ) ? $known[ $name ]['size'] : null );
			if ( ! is_numeric( $size ) || $size < 0 || floor( (float) $size ) != $size ) { throw new RuntimeException( 'Archive sizes are unavailable; no eviction is authorized.' ); }
			$inventory[ $name ] = (int) $size;
		}
		return $inventory;
	}

	private static function artifact_name( $name, $known ) {
		if ( ! is_string( $name ) || basename( $name ) !== $name || preg_match( '/\.(tmp|part|log)$/i', $name ) ) { return false; }
		if ( preg_match( '/\A(backup_pitr_[a-f0-9]{32}_[a-f0-9]{32}_)(?:full\.sql\.(?:gz|zst)|log[0-9]+\.bin\.(?:gz|zst)|manifest\.json)(?:\.crypt)?\z/D', $name, $match ) && isset( $known[ $match[1] . 'point.tar' ] ) ) { return false; }
		return isset( $known[ $name ] ) || (bool) preg_match( '/\Abackup_(?:pitr_[a-f0-9_]+_(?:full\.sql\.(?:gz|zst)|log[0-9]+\.bin\.(?:gz|zst)|manifest\.json|locator\.json|point\.tar)|[0-9]{4}-[0-9]{2}-[0-9]{2}-[0-9]{4}_.+\.(?:zip|gz|tar\.zst|sql\.zst))(?:\.crypt)?\z/D', $name );
	}

	/** Destinations without quotas retain independent database/file count policies. */
	private static function count_candidates( $sets ) {
		$counters   = array();
		$candidates = array();
		foreach ( array_reverse( $sets, true ) as $time => $set ) {
			if ( ! empty( $set['imported'] ) ) { continue; }
			$eligible = array();
			foreach ( $set['sources'] as $source ) {
				$family           = $set['pitr'] ? 'pitr:' : ( $set['pre_update'] ? 'update:' : 'ordinary:' );
				$key              = $family . $source;
				$counters[ $key ] = isset( $counters[ $key ] ) ? $counters[ $key ] + 1 : 1;
				$keep             = max( 1, (int) BackupPlus_Options::get_backupplus_option( 0 === strpos( $source, 'db' ) ? 'backupplus_retain_db' : 'backupplus_retain', 2 ) );
				if ( $set['pre_update'] ) { $keep = max( $keep, (int) BackupPlus_Options::get_backupplus_option( 'backupplus_retain', 2 ), (int) BackupPlus_Options::get_backupplus_option( 'backupplus_retain_db', 2 ) ); }
				if ( $counters[ $key ] > $keep && empty( $set['newest_sources'][ $source ] ) ) { $eligible[ $source ] = true; }
			}
			if ( $set['hard_protected'] || ( ( $set['pitr'] || $set['pre_update'] ) && count( $eligible ) !== count( $set['sources'] ) ) ) { continue; }
			$files = array_filter(
				$set['files'],
				function ( $info ) use ( $eligible ) {
					return isset( $eligible[ $info['source'] ] );
				}
			);
			if ( $files ) { $candidates[ $time ] = $files; }
		}
		return $candidates;
	}

	/** Persist before each destructive call. A restart resumes this same recovery set. */
	public static function delete_destination( $time, $destination, $files, $worker = null ) {
		global $backupplus;
		$key     = $destination['service'] . ':' . $destination['instance'];
		$history = BackupPlus_Backup_History::get_history();
		if ( ! isset( $history[ $time ] ) ) { throw new RuntimeException( 'Backup history changed; retry measurement.' ); }
		if ( ! empty( $history[ $time ]['always_keep'] ) ) { throw new RuntimeException( 'Backup was locked during cleanup.' ); }
		$history[ $time ]['storage_deleting'][ $key ] = true;
		$files                                        = array_replace( isset( $history[ $time ]['storage_delete_queue'][ $key ] ) ? $history[ $time ]['storage_delete_queue'][ $key ] : array(), $files );
		$history[ $time ]['storage_delete_queue'][ $key ] = $files;
		BackupPlus_Backup_History::save_history( $history );
		if ( ! empty( $history[ $time ]['pitr']['chain_id'] ) ) {
			if ( ! $worker ) { throw new RuntimeException( 'PITR worker unavailable; retry pending deletion.' ); }
			$worker->delete_destination( $history[ $time ]['pitr']['chain_id'], $destination );
		} else {
			$object = 'local' === $destination['service'] ? null : self::provider( $destination );
			foreach ( $files as $file => $info ) {
				if ( self::active() ) { throw new RuntimeException( 'Waiting for an active backup or restore.' ); }
				$history = BackupPlus_Backup_History::get_history();
				if ( ! empty( $history[ $time ]['always_keep'] ) ) { throw new RuntimeException( 'Backup was locked during cleanup.' ); }
				if ( ! empty( $history[ $time ]['storage_deleted_files'][ $key ][ $file ] ) ) { continue; }
				if ( $object ) {
					$deleted = true === $object->delete( array( $file ), null, array( $info['size'] ) );
					if ( ! $deleted ) {
						$listed  = $object->listfiles( $file );
						$deleted = is_array( $listed );
						foreach ( is_array( $listed ) ? $listed : array() as $item ) {
							if ( ! is_array( $item ) || ! isset( $item['name'] ) || $file === $item['name'] ) { $deleted = false;
								break; }
						}
					}
				} else {
					$path    = $backupplus->backups_dir_location() . '/' . basename( $file );
					$deleted = ! file_exists( $path ) || unlink( $path );
				}
				if ( ! $deleted ) { throw new RuntimeException( 'Artifact deletion failed; progress retained for retry: ' . $file ); }
				$history[ $time ]['storage_deleted_files'][ $key ][ $file ] = true;
				BackupPlus_Backup_History::save_history( $history );
			}
		}
		$history = BackupPlus_Backup_History::get_history();
		if ( ! array_diff_key( self::artifacts( $history[ $time ] ), isset( $history[ $time ]['storage_deleted_files'][ $key ] ) ? $history[ $time ]['storage_deleted_files'][ $key ] : array() ) || ! empty( $history[ $time ]['pitr'] ) ) { $history[ $time ]['storage_removed'][ $key ] = true; }
		unset( $history[ $time ]['storage_deleting'][ $key ], $history[ $time ]['storage_delete_queue'][ $key ] );
		BackupPlus_Backup_History::save_history( $history );
	}

	public static function run( $worker = null, $count_retention = false, $only_local = false ) {
		global $backupplus;
		if ( ! self::enabled() ) { return; }
		// A kernel lock cannot expire during slow listings and is released after a killed request.
		$lock = fopen( $backupplus->backups_dir_location() . '/storage-limits.lock', 'c' );
		if ( ! $lock ) { self::retry();
			return; }
		if ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) { fclose( $lock );
			self::retry();
			return; }
		try {
			BackupPlus_Backup_History::always_get_from_db();
			$history = BackupPlus_Backup_History::get_history();
			$pitr    = array();
			foreach ( $history as $set ) {
				if ( ! empty( $set['pitr'] ) ) {
					try { if ( ! $worker ) { $worker = new BackupPlus_PITR();
					} $pitr = $worker->storage_state(); }
					catch ( Throwable $error ) { $pitr = array(); }
					break;
				}
			}
			wp_cache_delete( self::STATUS, 'options' );
			$status       = get_option( self::STATUS, array() );
			$limits       = self::limits();
			$destinations = $only_local ? array(
				'local:' => array(
					'service'  => 'local',
					'instance' => '',
				),
			) : self::destinations();
			foreach ( $destinations as $key => $destination ) {
				$config = isset( $limits[ $destination['service'] ][ $destination['instance'] ] ) ? $limits[ $destination['service'] ][ $destination['instance'] ] : array(
					'alert' => 0,
					'quota' => 0,
				);
				if ( ! $config['alert'] && ! $config['quota'] && ! self::managed() ) { continue; }
				$entry = isset( $status[ $key ] ) ? $status[ $key ] : array();
				try {
					$inventory          = self::measure( $destination, $history );
					$before             = array_sum( $inventory );
					$entry['usage']     = $before;
					$entry['measured']  = time();
					$entry['available'] = true;
					$entry['outcome']   = '';
					$notifications      = isset( $entry['pending_notifications'] ) ? $entry['pending_notifications'] : array();
					foreach ( array( 'alert', 'quota' ) as $kind ) {
						$transition     = self::alert_transition( isset( $entry[ $kind ] ) ? $entry[ $kind ] : array(), $before, $config[ $kind ], 'quota' === $kind, time() );
						$entry[ $kind ] = $transition['state'];
						if ( ! $config[ $kind ] ) { unset( $notifications[ $kind ] ); }
						if ( $transition['notify'] ) { $notifications[ $kind ] = array(
							'level' => $transition['notify'],
							'usage' => $before,
							'limit' => $config[ $kind ],
							'time'  => time(),
						); }
					}
					$entry['pending_notifications'] = $notifications;
					// Commit crossings before eviction or email, while holding the monitor lock.
					$status[ $key ] = $entry;
					update_option( self::STATUS, $status, false );
					$sets       = self::recovery_sets( $history, $inventory, $key, $pitr );
					$candidates = self::count_candidates( $sets );
					$active     = self::active();
					$count      = self::managed() && $count_retention && ! $config['quota'] && ( 'local' !== $destination['service'] || BackupPlus_Options::get_backupplus_option( 'backupplus_delete_local', 0 ) );
					$deadline   = microtime( true ) + 20;
					foreach ( $sets as $time => $set ) {
						$pending = ! empty( $history[ $time ]['storage_deleting'][ $key ] );
						$over    = $config['quota'] && $entry['usage'] >= $config['quota'];
						if ( ! $pending && ! $over && ! ( $count && isset( $candidates[ $time ] ) ) ) { continue; }
						if ( ( $over || $pending ) && $set['protected'] ) { continue; }
						$files = $pending && ! empty( $history[ $time ]['storage_delete_queue'][ $key ] ) ? $history[ $time ]['storage_delete_queue'][ $key ] : ( $over ? self::artifacts( $history[ $time ] ) : ( isset( $candidates[ $time ] ) ? $candidates[ $time ] : array() ) );
						if ( $active || self::active() || microtime( true ) >= $deadline ) { $entry['outcome'] = 'Waiting for active backup/restore or next cleanup slice.';
							self::retry();
							break; }
						try {
							self::delete_destination( $time, $destination, $files, $worker );
							$inventory        = self::measure( $destination, $history );
							$entry['usage']   = array_sum( $inventory );
							$entry['outcome'] = 'Eligible recovery sets deleted from this destination.';
						} catch ( Throwable $error ) { $entry['outcome'] = $error->getMessage();
							$entry['available']                          = false;
							self::retry();
							break; }
					}
					if ( $config['quota'] && $entry['usage'] >= $config['quota'] && ( ! $entry['outcome'] || 'Eligible recovery sets deleted from this destination.' === $entry['outcome'] ) ) { $entry['outcome'] = 'Quota exceeded: remaining chains protect the newest restorable copy of a source, locked/imported backups or active dependencies.'; }
					foreach ( $notifications as $kind => $notice ) {
						if ( ! is_array( $notice ) ) { $notice = array(
							'level' => $notice,
							'usage' => $before,
							'limit' => $config[ $kind ],
							'time'  => time(),
						); }
						$level   = $notice['level'];
						$message = sprintf( 'BackupPlus %s %d%%: %s. Usage: %s bytes. Limit: %s bytes. Usage after eviction: %s bytes. %s', $kind, $level, self::label( $destination['service'], $destination['instance'] ), $notice['usage'], $notice['limit'], $entry['usage'], $entry['outcome'] );
						unset( $entry['pending_notifications'][ $kind ] );
						$status[ $key ] = $entry;
						update_option( self::STATUS, $status, false );
						$subject = sprintf( 'BackupPlus storage %s: %s', 100 === $level ? 'critical' : 'warning', self::label( $destination['service'], $destination['instance'] ) );
						$mailed  = wp_mail( get_option( 'admin_email' ), $subject, $message );
						$pushed  = BackupPlus_Ntfy::notify( $subject, $message, 100 === $level ? 4 : 3, array( 100 === $level ? 'rotating_light' : 'warning' ) );
						// One working channel is a delivered alert; retrying would only repeat it on the other.
						if ( ! $mailed && ! $pushed ) { $entry['pending_notifications'][ $kind ] = $notice;
							self::retry(); }
						$entry['notification']      = $message;
						$entry['notification_time'] = $notice['time'];
					}
					// Rearm immediately on a successful post-eviction measurement.
					foreach ( array( 'alert', 'quota' ) as $kind ) {
						$transition     = self::alert_transition( $entry[ $kind ], $entry['usage'], $config[ $kind ], 'quota' === $kind, time() );
						$entry[ $kind ] = $transition['state'];
					}
					$history = BackupPlus_Backup_History::get_history();
				} catch ( Throwable $error ) {
					$entry['available'] = false;
					$entry['outcome']   = $error->getMessage();
					self::retry();
				}
				$status[ $key ] = $entry;
				update_option( self::STATUS, $status, false );
			}
		} finally { flock( $lock, LOCK_UN );
			fclose( $lock ); }
	}
}
