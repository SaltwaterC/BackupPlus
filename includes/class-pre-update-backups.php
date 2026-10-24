<?php
/** Backups which must finish before WordPress is allowed to update installed code. */
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

class BackupPlus_Pre_Update_Backups {
	const OPTION = 'backupplus_pre_update_batch';
	private static $instance;
	private $automatic_batch = '';
	private $request_id      = '';
	private $claims          = array();
	private $cli_targets     = array();
	private $cli_batch       = '';

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action( 'init', array( $this, 'wrap_automatic_updates' ), 100 );
		add_action( 'backupplus_pre_update_run', array( $this, 'run_backup' ) );
		add_action( 'backupplus_pre_update_continue', array( $this, 'continue_automatic' ) );
		add_action( 'backupplus_pre_update_prune', array( $this, 'prune' ) );
		add_action( 'backupplus_pre_update_finalize', array( $this, 'backup_finished' ), 10, 3 );
		add_action( 'backupplus_backup_finished', array( $this, 'backup_finished' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'pre_download' ), PHP_INT_MAX, 4 );
		add_action( 'shutdown', array( $this, 'release_claims' ) );
		add_action( 'wp_ajax_backupplus_pre_update', array( $this, 'ajax' ) );
		add_action( 'admin_post_backupplus_pre_update_log', array( $this, 'download_log' ) );
		add_action( 'admin_notices', array( $this, 'recovery_notice' ) );
		add_action( 'network_admin_notices', array( $this, 'recovery_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'load-update.php', array( $this, 'classic' ) );
		add_action( 'load-update-core.php', array( $this, 'classic' ) );
	}

	public static function enabled() {
		return (bool) BackupPlus_Options::get_backupplus_option( 'backupplus_autobackup_default', 1 );
	}

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

	/** Safe public state; logs use their own authenticated, batch-bound endpoint. */
	public function status( $batch ) {
		global $backupplus;
		try {
			$job = $backupplus->jobdata_getarray( $batch['job'] );
		} catch ( Throwable $error ) {
			$job = array();
		}
		$stage    = isset( $job['jobstatus'] ) ? $job['jobstatus'] : '';
		$progress = array(
			'begun'          => 5,
			'filescreating'  => 15,
			'filescreated'   => 40,
			'dbcreating'     => 45,
			'dbcreated'      => 60,
			'clouduploading' => 70,
			'pruning'        => 95,
			'finished'       => 100,
		);
		return array(
			'id'       => $batch['id'],
			'state'    => $batch['state'],
			'progress' => in_array( $batch['state'], array( 'ready', 'complete' ), true ) ? 100 : ( isset( $progress[ $stage ] ) ? $progress[ $stage ] : ( 'queued' === $batch['state'] ? 0 : 10 ) ),
			'message'  => in_array( $batch['state'], array( 'failed', 'cancelled', 'expired' ), true ) ? __( 'The backup did not complete. Updates remain paused. Review the log, then retry.', 'backupplus' ) : __( 'Backing up the database and site files, including remote uploads. Keep this screen open; updates will continue automatically.', 'backupplus' ),
			'log'      => wp_nonce_url( admin_url( 'admin-post.php?action=backupplus_pre_update_log&batch=' . rawurlencode( $batch['id'] ) ), 'backupplus_pre_update_log_' . $batch['id'] ),
		);
	}

	public function ajax() {
		check_ajax_referer( 'backupplus_pre_update', 'nonce' );
		$operation = isset( $_POST['operation'] ) && is_string( $_POST['operation'] ) ? sanitize_key( $_POST['operation'] ) : '';
		if ( ! self::enabled() ) {
			wp_send_json_error( array( 'message' => __( 'Pre-update backups are disabled. Reload the update screen.', 'backupplus' ) ), 409 );
		}
		if ( 'prepare' === $operation ) {
			$keys   = isset( $_POST['targets'] ) && is_array( $_POST['targets'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['targets'] ) ) : array();
			$result = $this->queue_batch( $keys );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ), 409 );
			}
			wp_send_json_success( $this->status( $result ) );
		}
		if ( ! $this->lock() ) {
			wp_send_json_error( array( 'message' => $this->error()->get_error_message() ), 409 );
		}
		$result = null;
		try {
			$batch = $this->batch();
			$id    = isset( $_POST['batch'] ) && is_string( $_POST['batch'] ) ? sanitize_text_field( wp_unslash( $_POST['batch'] ) ) : '';
			if ( ! $id || empty( $batch['id'] ) || $batch['id'] !== $id || ! $this->can_manage( $batch ) ) {
				$result = $this->error( 'backupplus_forbidden' );
			} elseif ( 'cancel' === $operation && empty( $batch['request'] ) ) {
				$batch['state'] = 'cancelled';
				wp_clear_scheduled_hook( 'backupplus_pre_update_run', array( $id ) );
				wp_clear_scheduled_hook( 'backupplus_pre_update_continue', array( $id ) );
				$this->save( $batch );
			} elseif ( 'retry' === $operation && in_array( $batch['state'], array( 'failed', 'cancelled', 'expired' ), true ) ) {
				$offers = $this->offers();
				foreach ( $batch['targets'] as $key => $target ) {
					if ( ! isset( $offers[ $key ] ) || $offers[ $key ]['version'] !== $target['version'] || $offers[ $key ]['packages'] !== $target['packages'] ) {
						$result = $this->error( 'backupplus_update_changed' );
					}
				}
				if ( ! $result ) {
					$batch['state']     = 'queued';
					$batch['job']       = substr( md5( bin2hex( random_bytes( 16 ) ) ), 0, 12 );
					$batch['completed'] = 0;
					$batch['created']   = time();
					$batch['remaining'] = array_keys( $batch['targets'] );
					$this->save( $batch );
					wp_schedule_single_event( time(), 'backupplus_pre_update_run', array( $id ) );
				}
			} elseif ( 'status' !== $operation ) {
				$result = $this->error();
			}
			if ( ! $result && 'status' === $operation ) {
				if ( $batch['completed'] && time() >= $batch['completed'] + HOUR_IN_SECONDS && ( empty( $batch['request'] ) || time() >= ( isset( $batch['request_started'] ) ? $batch['request_started'] : $batch['completed'] ) + HOUR_IN_SECONDS ) && in_array( $batch['state'], array( 'ready', 'updating' ), true ) ) {
					$batch['state']           = 'expired';
					$batch['request']         = '';
					$batch['request_started'] = 0;
					$this->save( $batch );
				} elseif ( ( 'backing_up' === $batch['state'] && $this->stalled( $batch ) ) || ( 'ready' === $batch['state'] && ! $this->available( $batch ) ) ) {
					// A killed worker must not leave the interface waiting forever.
					$batch['state'] = 'failed';
					$this->save( $batch );
				}
			}
		} finally {
			$this->unlock();
		}
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 403 );
		}
		spawn_cron();
		wp_send_json_success( $this->status( $batch ) );
	}

	private function stalled( $batch ) {
		global $backupplus;
		try {
			$job = $backupplus->jobdata_getarray( $batch['job'] );
		} catch ( Throwable $error ) {
			return true;
		}
		$last = $batch['created'];
		foreach ( isset( $job['runs_started'] ) ? $job['runs_started'] : array() as $run => $start ) {
			$last = max( $last, $start + ( isset( $job['run_times'][ $run ] ) ? $job['run_times'][ $run ] : 0 ) );
		}
		$log = $backupplus->backups_dir_location() . '/log.' . $batch['job'] . '.txt';
		if ( is_file( $log ) ) {
			$last = max( $last, filemtime( $log ) );
		}
		$scheduled = false;
		foreach ( _get_cron_array() as $events ) {
			foreach ( isset( $events['backupplus_backup_resume'] ) ? $events['backupplus_backup_resume'] : array() as $event ) {
				if ( isset( $event['args'][1] ) && $batch['job'] === $event['args'][1] ) {
					$scheduled = true;
				}
			}
		}
		return time() > $last + ( $scheduled ? DAY_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
	}

	public function download_log() {
		global $backupplus;
		$batch = $this->batch();
		$id    = isset( $_GET['batch'] ) && is_string( $_GET['batch'] ) ? sanitize_text_field( wp_unslash( $_GET['batch'] ) ) : '';
		check_admin_referer( 'backupplus_pre_update_log_' . $id );
		if ( ! $id || empty( $batch['id'] ) || $id !== $batch['id'] || ! $this->can_manage( $batch ) ) {
			wp_die( esc_html__( 'You cannot access this backup log.', 'backupplus' ), '', array( 'response' => 403 ) );
		}
		$path = $backupplus->backups_dir_location() . '/log.' . $batch['job'] . '.txt';
		if ( ! is_readable( $path ) ) {
			wp_die( esc_html__( 'The log is not available yet.', 'backupplus' ) );
		}
		nocache_headers();
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="pre-update-backup.log"' );
		readfile( $path );
		exit;
	}

	public function recovery_notice() {
		$batch = $this->batch();
		if ( self::enabled() && ! empty( $batch['mode'] ) && 'automatic' === $batch['mode'] && in_array( $batch['state'], array( 'queued', 'backing_up', 'ready', 'failed', 'cancelled', 'expired' ), true ) && $this->can_manage( $batch ) ) {
			echo '<div id="backupplus-pre-update-recovery" data-batch="' . esc_attr( $batch['id'] ) . '"></div>';
		}
	}

	public function enqueue() {
		if ( ! self::enabled() || ( ! current_user_can( 'update_plugins' ) && ! current_user_can( 'update_themes' ) && ! current_user_can( 'update_core' ) ) ) {
			return;
		}
		wp_enqueue_script( 'backupplus-pre-update', BACKUPPLUS_URL . '/js/pre-update-backups.js', wp_script_is( 'updates', 'registered' ) ? array( 'jquery', 'updates' ) : array( 'jquery' ), '3.0.0', true );
		$classic_links = array();
		if ( version_compare( get_bloginfo( 'version' ), '4.6', '<' ) ) {
			foreach ( $this->offers() as $key => $offer ) {
				if ( 'core' !== $offer['type'] ) {
					$action                = 'upgrade-' . $offer['type'];
					$classic_links[ $key ] = html_entity_decode( wp_nonce_url( self_admin_url( 'update.php?action=' . $action . '&' . $offer['type'] . '=' . rawurlencode( $offer['id'] ) ), $action . '_' . $offer['id'] ), ENT_QUOTES, 'UTF-8' );
				}
			}
		}
		wp_localize_script(
			'backupplus-pre-update',
			'backupplusPreUpdate',
			array(
				'url'          => admin_url( 'admin-ajax.php' ),
				'classicLinks' => $classic_links,
				'nonce'        => wp_create_nonce( 'backupplus_pre_update' ),
				'title'        => __( 'Pre-update backup', 'backupplus' ),
				'retry'        => __( 'Retry backup', 'backupplus' ),
				'cancel'       => __( 'Cancel updates', 'backupplus' ),
				'log'          => __( 'Download backup log', 'backupplus' ),
				'error'        => __( 'The backup could not be verified. Updates remain paused. Retry when the connection is available.', 'backupplus' ),
			)
		);
	}

	/** Classic and iframe update screens stop before maintenance mode and retain native nonce checks. */
	public function classic() {
		global $pagenow;
		if ( ! self::enabled() ) {
			return;
		}
		$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : '';
		$routes = array(
			'upgrade-plugin'         => 'plugin',
			'update-selected'        => 'plugin',
			'upgrade-theme'          => 'theme',
			'update-selected-themes' => 'theme',
			'do-plugin-upgrade'      => 'plugin',
			'do-theme-upgrade'       => 'theme',
			'do-core-upgrade'        => 'core',
			'do-core-reinstall'      => 'core',
		);
		if ( ! isset( $routes[ $action ] ) ) {
			return;
		}
		$type = $routes[ $action ];
		if ( 'core' === $type && empty( $_POST['upgrade'] ) ) {
			return;
		}
		if ( ! current_user_can( 'core' === $type ? 'update_core' : 'update_' . $type . 's' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform these updates.', 'backupplus' ), '', array( 'response' => 403 ) );
		}
		$single = in_array( $action, array( 'upgrade-plugin', 'upgrade-theme' ), true );
		$ids    = array();
		if ( $single ) {
			$ids = isset( $_REQUEST[ $type ] ) && is_string( $_REQUEST[ $type ] ) ? array( wp_unslash( $_REQUEST[ $type ] ) ) : array();
		} elseif ( 'core' !== $type ) {
			$plural = $type . 's';
			$ids    = isset( $_GET[ $plural ] ) && is_string( $_GET[ $plural ] ) ? explode( ',', wp_unslash( $_GET[ $plural ] ) ) : ( isset( $_POST['checked'] ) && is_array( $_POST['checked'] ) ? wp_unslash( $_POST['checked'] ) : array() );
		}
		$native_nonce = 'update-core.php' === $pagenow ? 'upgrade-core' : ( $single ? $action . '_' . reset( $ids ) : 'bulk-update-' . $type . 's' );
		check_admin_referer( $native_nonce );
		$keys = array();
		if ( 'core' === $type ) {
			$version = isset( $_POST['version'] ) && is_string( $_POST['version'] ) ? wp_unslash( $_POST['version'] ) : '';
			$locale  = isset( $_POST['locale'] ) && is_string( $_POST['locale'] ) ? wp_unslash( $_POST['locale'] ) : get_locale();
			$keys[]  = 'core:' . $version . ':' . $locale;
		} else {
			foreach ( $ids as $id ) {
				if ( is_string( $id ) ) {
					$keys[] = $type . ':' . urldecode( $id );
				}
			}
		}
		if ( ! $keys ) {
			return;
		}
		$batch = $this->batch();
		$token = isset( $_REQUEST['backupplus_batch'] ) && is_string( $_REQUEST['backupplus_batch'] ) ? wp_unslash( $_REQUEST['backupplus_batch'] ) : '';
		if ( $token && ! empty( $batch['id'] ) && $token === $batch['id'] && $this->can_manage( $batch ) && 'ready' === $batch['state'] && time() < $batch['completed'] + HOUR_IN_SECONDS && ! array_diff( $keys, $batch['remaining'] ) ) {
			return;
		}
		// Render a native admin page; only original update fields are carried into the resume form.
		$title = __( 'Pre-update backup', 'backupplus' );
		$this->enqueue();
		require ABSPATH . 'wp-admin/admin-header.php';
		echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1><div id="backupplus-pre-update-classic" data-targets="' . esc_attr( json_encode( $keys ) ) . '"></div>';
		$resume = array(
			'action'   => $action,
			'_wpnonce' => wp_create_nonce( $native_nonce ),
		);
		$path   = $pagenow;
		if ( 'core' === $type ) {
			$resume['upgrade'] = '1';
			$resume['version'] = $version;
			$resume['locale']  = $locale;
		} elseif ( 'update-core.php' === $pagenow ) {
			// The native core screen otherwise creates another iframe and loses the batch identity.
			$path               = 'update.php';
			$resume['action']   = 'plugin' === $type ? 'update-selected' : 'update-selected-themes';
			$resume['_wpnonce'] = wp_create_nonce( 'bulk-update-' . $type . 's' );
			$resume['checked']  = $ids;
		} elseif ( $single ) {
			$resume[ $type ] = reset( $ids );
		} else {
			$resume['checked'] = $ids;
		}
		echo '<form id="backupplus-pre-update-resume" method="post" action="' . esc_url( self_admin_url( $path . '?action=' . $resume['action'] ) ) . '">';
		foreach ( $resume as $name => $value ) {
			foreach ( (array) $value as $entry ) {
				echo '<input type="hidden" name="' . esc_attr( $name . ( is_array( $value ) ? '[]' : '' ) ) . '" value="' . esc_attr( $entry ) . '">';
			}
		}
		echo '</form><noscript>' . esc_html__( 'Enable JavaScript to prepare a backup and continue these updates.', 'backupplus' ) . '</noscript></div>';
		require ABSPATH . 'wp-admin/admin-footer.php';
		exit;
	}

	/** Complete pre-update sets have an independent, whole-set retention policy. */
	public function prune() {
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
