<?php

/** The stored batch of pre-update backups: locking, offers and queueing. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Pre_Update_Backups_Batch {

	private function load_upgrader() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	}

	/** Short database mutex; never held while creating archives or making network requests. */
	private function lock() {
		$this->load_upgrader();
		if ( method_exists( 'WP_Upgrader', 'create_lock' ) ) {
			return WP_Upgrader::create_lock( 'backupplus_pre_update', 60 );
		}
		// WordPress 3.9-4.4 predate the public upgrader lock helpers.
		$key = 'backupplus_pre_update.lock';
		if ( add_option( $key, time(), '', 'no' ) ) {
			return true;
		}
		$old = get_option( $key );
		if ( ! $old || $old > time() - 60 ) {
			return false;
		}
		global $wpdb;
		BackupPlus_SQL::wpdb()->execute( 'DELETE FROM %i WHERE option_name = ? AND option_value = ?', array( $wpdb->options ), array( $key, (string) $old ) );
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return add_option( $key, time(), '', 'no' );
	}

	private function unlock() {
		if ( method_exists( 'WP_Upgrader', 'release_lock' ) ) {
			WP_Upgrader::release_lock( 'backupplus_pre_update' );
		} else {
			delete_option( 'backupplus_pre_update.lock' );
		}
	}

	public function batch() {
		$batch = get_option( self::OPTION, array() );
		return is_array( $batch ) ? $batch : array();
	}

	private function save( $batch ) {
		update_option( self::OPTION, $batch, false );
	}

	private function error( $code = 'backupplus_backup_required' ) {
		return new WP_Error( $code, __( 'Updates are waiting for a complete pre-update backup. Open the update screen to prepare or retry the backup.', 'backupplus' ) );
	}

	/** Offers are frozen by identity, version and package, never by a client-supplied URL. */
	public function offers() {
		$offers = array();
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			$updates = get_site_transient( 'update_' . $type . 's' );
			foreach ( $updates && ! empty( $updates->response ) ? $updates->response : array() as $id => $item ) {
				$item = (object) $item;
				if ( empty( $item->new_version ) || empty( $item->package ) ) {
					continue;
				}
				$offers[ $type . ':' . $id ] = array(
					'type'     => $type,
					'id'       => (string) $id,
					'version'  => (string) $item->new_version,
					'packages' => array( $item->package ),
					'item'     => $item,
				);
			}
		}
		$core = get_site_transient( 'update_core' );
		foreach ( $core && ! empty( $core->updates ) ? $core->updates : array() as $item ) {
			if ( empty( $item->response ) || ! in_array( $item->response, array( 'upgrade', 'autoupdate', 'latest', 'reinstall' ), true ) ) {
				continue;
			}
			$locale = isset( $item->locale ) ? $item->locale : get_locale();
			$offers[ 'core:' . $item->current . ':' . $locale ] = array(
				'type'     => 'core',
				'id'       => $item->current . ':' . $locale,
				'version'  => (string) $item->current,
				'packages' => array_values( array_filter( (array) $item->packages ) ),
				'item'     => $item,
			);
		}
		return $offers;
	}

	/** Only in-process WP-CLI code may establish this context; HTTP inputs cannot. */
	public function prepare_cli( $targets ) {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! $targets ) { return $this->error( 'backupplus_forbidden' ); }
		$this->cli_targets = $targets;
		$batch             = $this->queue_batch( array_keys( $targets ), 'cli' );
		if ( ! is_wp_error( $batch ) ) { $this->cli_batch = $batch['id']; }
		return $batch;
	}

	public function finish_cli() {
		$this->release_claims();
		if ( $this->cli_batch && $this->lock() ) {
			try {
				$batch = $this->batch();
				if ( isset( $batch['id'] ) && $batch['id'] === $this->cli_batch && in_array( $batch['state'], array( 'ready', 'updating' ), true ) ) {
					$batch['state'] = 'complete';
					$this->save( $batch );
				}
			} finally { $this->unlock(); }
		}
		$this->cli_batch   = '';
		$this->cli_targets = array();
	}

	public function can_manage( $batch ) {
		if ( 'cli' === $batch['mode'] ) {
			return $this->cli_batch && $this->cli_batch === $batch['id'] && $this->cli_targets === $batch['targets'];
		}

		if ( empty( $batch['targets'] ) ) {
			return false;
		}
		foreach ( $batch['targets'] as $target ) {
			if ( ! current_user_can( 'core' === $target['type'] ? 'update_core' : 'update_' . $target['type'] . 's' ) ) {
				return false;
			}
		}
		return 'automatic' === $batch['mode'] || get_current_user_id() === $batch['user'];
	}

	/** Queue exactly one standalone full backup for a selected batch. */
	public function queue_batch( $keys, $mode = 'manual' ) {
		$offers  = 'cli' === $mode ? $this->cli_targets : $this->offers();
		$targets = array();
		foreach ( array_unique( (array) $keys ) as $key ) {
			if ( ! is_string( $key ) || ! isset( $offers[ $key ] ) ) {
				return $this->error( 'backupplus_update_changed' );
			}
			$targets[ $key ] = $offers[ $key ];
			unset( $targets[ $key ]['item'] );
		}
		if ( ! $targets ) {
			return $this->error();
		}
		ksort( $targets );
		$batch = array(
			'id'              => bin2hex( random_bytes( 16 ) ),
			'user'            => get_current_user_id(),
			'mode'            => $mode,
			'targets'         => $targets,
			'state'           => 'queued',
			'created'         => time(),
			'job'             => substr( md5( bin2hex( random_bytes( 16 ) ) ), 0, 12 ),
			'entities'        => array(),
			'destinations'    => array(),
			'remaining'       => array_keys( $targets ),
			'request'         => '',
			'request_started' => 0,
			'completed'       => 0,
		);
		if ( 'manual' === $mode && ! $this->can_manage( $batch ) ) {
			return $this->error( 'backupplus_forbidden' );
		}
		if ( ! $this->lock() ) {
			return $this->error( 'backupplus_busy' );
		}
		try {
			$old = $this->batch();
			if ( $old && in_array( $old['state'], array( 'queued', 'backing_up', 'ready', 'updating' ), true ) ) {
				$expired = $old['completed'] && time() >= $old['completed'] + HOUR_IN_SECONDS && ( empty( $old['request'] ) || time() >= ( isset( $old['request_started'] ) ? $old['request_started'] : $old['completed'] ) + HOUR_IN_SECONDS );
				if ( ! $expired ) {
					if ( 'cli' !== $mode && $old['targets'] === $targets && $old['mode'] === $mode && $old['user'] === $batch['user'] ) {
						return $old;
					}
					return $this->error( 'backupplus_busy' );
				}
			}
			$this->save( $batch );
			wp_schedule_single_event( time(), 'backupplus_pre_update_run', array( $batch['id'] ) );
		} finally {
			$this->unlock();
		}
		if ( 'cli' !== $mode ) { spawn_cron(); }
		return $batch;
	}

	/** Snapshot all configured destinations before the engine can omit unavailable providers. */
	private function destinations() {
		global $backupplus;
		$destinations = array();
		foreach ( $backupplus->get_canonical_service_list() as $service ) {
			$provider = BackupPlus_Storage_Methods_Interface::get_storage_object( $service );
			if ( is_wp_error( $provider ) || is_a( $provider, 'BackupPlus_BackupModule_AddonNotYetPresent' ) ) {
				return $this->error( 'backupplus_storage_unavailable' );
			}
			if ( ! $provider->supports_feature( 'multi_options' ) ) {
				$destinations[ $service ] = array( '' );
				continue;
			}
			$settings = BackupPlus_Storage_Methods_Interface::get_enabled_storage_objects_and_ids( array( $service ) );
			if ( empty( $settings[ $service ]['instance_settings'] ) ) {
				return $this->error( 'backupplus_storage_unavailable' );
			}
			$destinations[ $service ] = array_keys( $settings[ $service ]['instance_settings'] );
		}
		return $destinations;
	}
}
