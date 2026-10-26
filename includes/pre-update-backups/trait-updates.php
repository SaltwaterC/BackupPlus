<?php

/** Holding back package downloads until the backup is done, including automatic updates. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Pre_Update_Backups_Updates {

	/** Bind a download to a verified batch and consume its target once per request. */
	public function pre_download( $reply, $package, $upgrader, $extra = array() ) {
		if ( ( ! self::enabled() && ! $this->cli_batch ) || is_a( $upgrader, 'Language_Pack_Upgrader' ) || ( ! $this->cli_batch && ( ( isset( $extra['action'] ) && 'update' !== $extra['action'] ) || is_a( $upgrader->skin, 'Plugin_Installer_Skin' ) || is_a( $upgrader->skin, 'Theme_Installer_Skin' ) ) ) ) {
			return $reply;
		}
		$type = is_a( $upgrader, 'Core_Upgrader' ) ? 'core' : ( is_a( $upgrader, 'Plugin_Upgrader' ) ? 'plugin' : ( is_a( $upgrader, 'Theme_Upgrader' ) ? 'theme' : '' ) );
		if ( isset( $extra['type'] ) && in_array( $extra['type'], array( 'core', 'plugin', 'theme' ), true ) ) {
			$type = $extra['type'];
		}
		if ( ! $type ) {
			return $reply;
		}
		// Older WordPress releases do not pass hook_extra to this hook.
		if ( ! isset( $extra[ $type ] ) && isset( $upgrader->skin->$type ) && is_string( $upgrader->skin->$type ) ) {
			$extra[ $type ] = $upgrader->skin->$type;
		}
		// Core rollback uses the same upgrader instance and must retain its native recovery path.
		$object = spl_object_hash( $upgrader );
		if ( 'core' === $type && isset( $this->claims[ $object ] ) && in_array( $package, $this->claims[ $object ]['packages'], true ) ) {
			return $reply;
		}
		$id = $this->cli_batch ? $this->cli_batch : ( $this->automatic_batch ? $this->automatic_batch : ( isset( $_REQUEST['backupplus_batch'] ) && is_string( $_REQUEST['backupplus_batch'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['backupplus_batch'] ) ) : '' ) );
		if ( ! $this->lock() ) {
			return $this->error( 'backupplus_busy' );
		}
		try {
			$batch = $this->batch();
			if ( ! $id || empty( $batch['id'] ) || $id !== $batch['id'] || ! in_array( $batch['state'], array( 'ready', 'updating' ), true ) || ( ! $this->automatic_batch && ! $this->can_manage( $batch ) ) || time() >= $batch['completed'] + HOUR_IN_SECONDS || ! $this->available( $batch ) ) {
				return $this->error();
			}
			if ( ! $this->request_id ) {
				$this->request_id = bin2hex( random_bytes( 16 ) );
			}
			if ( $batch['request'] && $batch['request'] !== $this->request_id ) {
				return $this->error( 'backupplus_busy' );
			}
			$offers = $this->cli_batch ? $this->cli_targets : $this->offers();
			foreach ( $batch['remaining'] as $key ) {
				$target = $batch['targets'][ $key ];
				if ( $type !== $target['type'] || ( isset( $extra[ $type ] ) && $extra[ $type ] !== $target['id'] ) || ! in_array( $package, $target['packages'], true ) || ! isset( $offers[ $key ] ) || $offers[ $key ]['version'] !== $target['version'] || $offers[ $key ]['packages'] !== $target['packages'] ) {
					continue;
				}
				if ( isset( $target['package_hashes'] ) ) {
					foreach ( $target['package_hashes'] as $path => $hash ) {
						if ( ! is_readable( $path ) || ! hash_equals( $hash, hash_file( 'sha256', $path ) ) ) { return $this->error( 'backupplus_update_changed' ); }
					}
				}
				$batch['state']           = 'updating';
				$batch['request']         = $this->request_id;
				$batch['request_started'] = time();
				$batch['remaining']       = array_values( array_diff( $batch['remaining'], array( $key ) ) );
				$this->claims[ $object ]  = $target;
				$this->save( $batch );
				return $reply;
			}
			return $this->error( 'backupplus_update_changed' );
		} finally {
			$this->unlock();
		}
	}

	public function release_claims() {
		if ( ! $this->request_id || ! $this->lock() ) {
			return;
		}
		try {
			$batch = $this->batch();
			if ( isset( $batch['request'] ) && $batch['request'] === $this->request_id ) {
				$batch['request']         = '';
				$batch['request_started'] = 0;
				$batch['state']           = $batch['remaining'] ? 'ready' : 'complete';
				$this->save( $batch );
			}
		} finally {
			$this->unlock();
		}
	}

	public function wrap_automatic_updates() {
		remove_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update' );
		add_action( 'wp_maybe_auto_update', array( $this, 'automatic' ) );
	}

	private function automatic_targets() {
		$this->load_upgrader();
		if ( ! is_main_site() || ! is_main_network() ) {
			return array();
		}
		$updater = new WP_Automatic_Updater();
		if ( $updater->is_disabled() ) {
			return array();
		}
		wp_update_plugins();
		wp_update_themes();
		wp_version_check();
		$core = find_core_auto_update();
		$keys = array();
		foreach ( $this->offers() as $key => $offer ) {
			$type = $offer['type'];
			if ( 'core' === $type && ( ! $core || $core->current !== $offer['version'] || $core->locale !== $offer['item']->locale ) ) {
				continue;
			}
			$context = 'core' === $type ? ABSPATH : ( 'plugin' === $type ? WP_PLUGIN_DIR : get_theme_root( $offer['id'] ) );
			if ( $updater->should_update( $type, $offer['item'], $context ) ) {
				$keys[] = $key;
			}
		}
		return $keys;
	}

	public function automatic() {
		if ( ! self::enabled() ) {
			wp_maybe_auto_update();
			return;
		}
		$keys = $this->automatic_targets();
		if ( ! $keys ) {
			// Eligibility can change when the native updater refreshes its offers.
			foreach ( array( 'core', 'plugin', 'theme' ) as $type ) {
				add_filter( 'auto_update_' . $type, '__return_false', PHP_INT_MAX );
			}
			try {
				wp_maybe_auto_update();
			} finally {
				foreach ( array( 'core', 'plugin', 'theme' ) as $type ) {
					remove_filter( 'auto_update_' . $type, '__return_false', PHP_INT_MAX );
				}
			}
			return;
		}
		// Failed/cancelled automatic batches require an explicit retry, including on later cron runs.
		$batch = $this->batch();
		if ( $batch && 'automatic' === $batch['mode'] && in_array( $batch['state'], array( 'failed', 'cancelled' ), true ) ) {
			return;
		}
		$result = $this->queue_batch( $keys, 'automatic' );
		if ( ! is_wp_error( $result ) && 'ready' === $result['state'] ) {
			$this->continue_automatic( $result['id'] );
		}
	}

	public function continue_automatic( $id ) {
		$batch = $this->batch();
		if ( ! self::enabled() || empty( $batch['id'] ) || $batch['id'] !== $id || 'automatic' !== $batch['mode'] || 'ready' !== $batch['state'] || time() >= $batch['completed'] + HOUR_IN_SECONDS ) {
			return;
		}
		$keys                  = $this->automatic_targets();
		$this->automatic_batch = $id;
		$filters               = array();
		foreach ( array( 'plugin', 'theme', 'core' ) as $type ) {
			$filters[ $type ] = function ( $allowed, $item ) use ( $type, $batch, $keys ) {
				$id       = 'core' === $type ? $item->current . ':' . $item->locale : $item->$type;
				$key      = $type . ':' . $id;
				$version  = 'core' === $type ? $item->current : $item->new_version;
				$packages = 'core' === $type ? array_values( array_filter( (array) $item->packages ) ) : array( $item->package );
				$current  = $this->batch();
				return $allowed && $current['id'] === $batch['id'] && in_array( $current['state'], array( 'ready', 'updating' ), true ) && in_array( $key, $keys, true ) && isset( $batch['targets'][ $key ] ) && $batch['targets'][ $key ]['version'] === (string) $version && $batch['targets'][ $key ]['packages'] === $packages;
			};
			add_filter( 'auto_update_' . $type, $filters[ $type ], PHP_INT_MAX, 2 );
		}
		try {
			wp_maybe_auto_update();
		} finally {
			foreach ( $filters as $type => $filter ) {
				remove_filter( 'auto_update_' . $type, $filter, PHP_INT_MAX );
			}
			$this->release_claims();
			$this->automatic_batch = '';
			if ( $this->lock() ) {
				try {
					$current = $this->batch();
					if ( $current['id'] === $id && 'ready' === $current['state'] && empty( $current['request'] ) ) {
						$current['state'] = 'complete';
						$this->save( $current );
					}
				} finally {
					$this->unlock();
				}
			}
		}
	}
}
