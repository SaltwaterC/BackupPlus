<?php
/** WP-CLI commands and the synchronous controller for resumable backups. */
if ( ! defined( 'ABSPATH' ) ) { die( 'No direct access allowed' ); }

class BackupPlus_CLI {
	const RESULT  = 'backupplus_cli_result_';
	const REQUEST = 'backupplus_cli_request_';

	public static function register() {
		// Record cron completions too: a CLI worker may have handed a retry to WP-Cron.
		add_action( 'backupplus_backup_finished', array( __CLASS__, 'finished' ), 20, 4 );
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { return; }
		// A network is managed as a whole, from its main site
		WP_CLI::add_hook(
			'before_run_command',
			function ( $args ) {
				if ( isset( $args[0] ) && 'backupplus' === $args[0] && ! BackupPlus_Multisite::available() ) {
					WP_CLI::error( is_multisite() && ! BackupPlus_Multisite::network_active() ? 'On a WordPress network, BackupPlus must be network-activated.' : 'BackupPlus manages the whole network from its main site: run this command without --url, or with the main site\'s URL.' );
				}
			}
		);
		WP_CLI::add_command( 'backupplus', 'BackupPlus_CLI_Command' );
		require_once BACKUPPLUS_DIR . '/includes/class-cli-restore.php';
		require_once BACKUPPLUS_DIR . '/includes/class-cli-updates.php';
		BackupPlus_CLI_Updates::register();
		require_once BACKUPPLUS_DIR . '/includes/class-cli-diagnostics.php';
		require_once BACKUPPLUS_DIR . '/includes/class-cli-download.php';
		require_once BACKUPPLUS_DIR . '/includes/class-cli-prune.php';
		require_once BACKUPPLUS_DIR . '/includes/class-cli-jobs.php';
		WP_CLI::add_command( 'backupplus jobs', 'BackupPlus_CLI_Jobs' );
		require_once BACKUPPLUS_DIR . '/includes/class-cli-remote.php';
		WP_CLI::add_command( 'backupplus remote', 'BackupPlus_CLI_Remote' );
		require_once BACKUPPLUS_DIR . '/includes/class-cli-config.php';
		WP_CLI::add_command( 'backupplus settings', 'BackupPlus_CLI_Settings' );
		WP_CLI::add_command( 'backupplus databases', 'BackupPlus_CLI_Databases' );
		WP_CLI::add_command( 'backupplus notify', 'BackupPlus_CLI_Notify' );
		WP_CLI::add_command( 'backupplus storage-limits', 'BackupPlus_CLI_Storage_Limits' );
	}

	/** The wp-admin controller, which holds the history, job and storage operations the screens use. */
	public static function admin() {
		global $backupplus_admin;
		if ( ! is_a( $backupplus_admin, 'BackupPlus_Admin' ) ) {
			require_once BACKUPPLUS_DIR . '/admin.php';
			$backupplus_admin = new BackupPlus_Admin();
		}
		return $backupplus_admin;
	}

	/** Refresh both ordinary options and network job data across subprocesses. */
	public static function fresh( $name, $network = false ) {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		if ( is_multisite() ) {
			$id = function_exists( 'get_current_network_id' ) ? get_current_network_id() : 1;
			wp_cache_delete( $id . ':' . $name, 'site-options' );
			wp_cache_delete( $id . ':notoptions', 'site-options' );
		}
		return $network ? get_site_option( $name ) : get_option( $name );
	}

	public static function finished( $nonce, $outcome, $job, $diagnostics = null ) {
		if ( ! get_site_option( self::REQUEST . $nonce ) ) { return; }
		$result = array(
			'outcome'     => $outcome,
			'timestamp'   => isset( $job['incremental_base_time'] ) ? $job['incremental_base_time'] : $job['backup_time'],
			'diagnostics' => $diagnostics,
		);
		update_site_option( self::RESULT . $nonce, $result );
	}

	public static function words( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) { throw new InvalidArgumentException( 'Selections must not be empty.' ); }
		$items = explode( ',', $value );
		foreach ( $items as $item ) { if ( '' === trim( $item ) ) { throw new InvalidArgumentException( 'Selections must not contain empty items.' ); }
		}
		return array_values( array_unique( array_map( 'trim', $items ) ) );
	}

	/** Resolve and validate a per-run selection without writing saved settings. */
	public static function selection( $args ) {
		global $backupplus;
		$type = isset( $args['type'] ) ? $args['type'] : 'all';
		if ( ! in_array( $type, array( 'all', 'files', 'database' ), true ) ) { throw new InvalidArgumentException( 'Use --type=all, files or database.' ); }
		if ( 'database' === $type && isset( $args['incremental'] ) ) { throw new InvalidArgumentException( 'Incremental backups require files.' ); }
		$selection = array();
		if ( isset( $args['selection'] ) ) {
			if ( isset( $args['files'] ) || isset( $args['tables'] ) ) { throw new InvalidArgumentException( '--selection cannot be combined with --files or --tables.' ); }
			if ( ! is_readable( $args['selection'] ) || ! is_file( $args['selection'] ) ) { throw new InvalidArgumentException( 'Selection file is not readable.' ); }
			$raw       = file_get_contents( $args['selection'] );
			$selection = json_decode( $raw, true );
			if ( ! is_array( $selection ) || '{' !== substr( ltrim( $raw ), 0, 1 ) || array_diff( array_keys( $selection ), array( 'files', 'custom_paths', 'database_selection' ) ) ) { throw new InvalidArgumentException( 'Invalid selection JSON object or unknown selection keys.' ); }
		} else {
			if ( isset( $args['files'] ) ) { $selection['files'] = self::words( $args['files'] ); }
			if ( isset( $args['tables'] ) ) { $selection['database_selection'] = array(
				'wp' => array(
					'mode'   => 'selected',
					'tables' => self::words( $args['tables'] ),
				),
			); }
		}
		if ( 'database' === $type && ( array_key_exists( 'files', $selection ) || array_key_exists( 'custom_paths', $selection ) ) ) { throw new InvalidArgumentException( 'File selectors require --type=all or files.' ); }
		if ( 'files' === $type && array_key_exists( 'database_selection', $selection ) ) { throw new InvalidArgumentException( 'Table selectors require --type=all or database.' ); }
		$sources = BackupPlus_Backup_Sources::sources();
		if ( array_key_exists( 'custom_paths', $selection ) ) {
			if ( ! is_array( $selection['custom_paths'] ) ) { throw new InvalidArgumentException( 'custom_paths must be an array.' ); }
			foreach ( $selection['custom_paths'] as $path ) { if ( ! is_string( $path ) || '' === trim( $path ) ) { throw new InvalidArgumentException( 'Invalid custom path.' ); }
			}
			$sources = array();
			foreach ( BackupPlus_Backup_Sources::paths( $selection['custom_paths'] ) as $path ) {
				$sources[ BackupPlus_Backup_Sources::source_id( $path ) ] = array(
					'path' => $path,
					'kind' => is_file( $path ) ? 'file' : 'directory',
				);
			}
		}
		BackupPlus_Backup_Sources::set_run_sources( $sources );
		try {
			$entities = $backupplus->get_backupable_file_entities( true );
			$files    = array();
			if ( array_key_exists( 'files', $selection ) ) {
				if ( ! is_array( $selection['files'] ) ) { throw new InvalidArgumentException( 'files must be an array.' ); }
				foreach ( $selection['files'] as $id ) {
					if ( 'custom' === $id ) { $files = array_merge( $files, array_keys( $sources ) ); }
					elseif ( ! is_string( $id ) || ! isset( $entities[ $id ] ) ) { throw new InvalidArgumentException( 'Unknown file source: ' . ( is_scalar( $id ) ? $id : '(invalid ID)' ) ); }
					else { $files[] = $id; }
				}
			} else {
				foreach ( $entities as $id => $path ) { if ( BackupPlus_Backup_Sources::included( $id ) ) { $files[] = $id; }
				}
			}
			if ( array_key_exists( 'custom_paths', $selection ) ) { $files = array_merge( $files, array_keys( $sources ) ); }
			$files = array_values( array_unique( $files ) );
			if ( 'database' !== $type ) {
				foreach ( $files as $id ) {
					$path = $entities[ $id ];
					if ( ! is_readable( $path ) || ( ! is_dir( $path ) && ! is_file( $path ) ) ) {
						// Optional built-in directories often do not exist on new installations.
						if ( ! array_key_exists( 'files', $selection ) && ! BackupPlus_Backup_Sources::custom( $id ) ) { $files = array_values( array_diff( $files, array( $id ) ) );
							continue; }
						throw new RuntimeException( 'Selected source is missing or unreadable: ' . $path );
					}
				}
				BackupPlus_Backup_Sources::validate_sources( array_fill_keys( $files, true ), $sources );
			}
			$options = array(
				'file_sources'      => $sources,
				'file_entity_paths' => $entities,
				'incremental'       => isset( $args['incremental'] ),
			);
			if ( array_key_exists( 'database_selection', $selection ) ) {
				$options['database_selection'] = BackupPlus_Backup_Sources::selection( $selection['database_selection'] );
				$connections                   = BackupPlus_Backup_Sources::connections();
				foreach ( $options['database_selection'] as $id => $unused ) {
					if ( 'wp' !== $id && ! isset( $connections[ $id ] ) ) { throw new InvalidArgumentException( 'Unknown database source: ' . $id ); }
				}
			}
			if ( isset( $args['label'] ) ) {
				if ( ! is_string( $args['label'] ) || '' === trim( $args['label'] ) || strlen( $args['label'] ) > 200 || preg_match( '/[\x00-\x1f\x7f]/', $args['label'] ) ) { throw new InvalidArgumentException( 'Use a label of up to 200 printable characters.' ); }
				$options['label'] = $args['label'];
			}
			$services = array();
			if ( isset( $args['services'] ) ) {
				if ( isset( $args['local'] ) ) { throw new InvalidArgumentException( '--services and --local are exclusive.' ); }
				$services = self::words( $args['services'] );
				foreach ( $services as $service ) { if ( 'none' === $service || ! isset( $backupplus->backup_methods[ $service ] ) ) { throw new InvalidArgumentException( 'Unknown remote storage service: ' . $service ); }
				}
			}
			if ( 'files' !== $type ) { $options['database_plan'] = BackupPlus_Backup_Sources::plan_databases( $options ); }
			if ( 'files' === $type && ! $files ) { throw new InvalidArgumentException( 'No file sources selected.' ); }
			return array(
				'files'    => 'database' !== $type && (bool) $files,
				'database' => 'files' !== $type,
				'entities' => $files,
				'local'    => isset( $args['local'] ),
				'services' => $services,
				'options'  => $options,
			);
		} finally { BackupPlus_Backup_Sources::set_run_sources( null ); }
	}

	/** Only this job's retries and this batch's finalizers are eligible. */
	public static function events( $nonce, $batch = '' ) {
		self::fresh( 'cron' );
		$result = array();
		foreach ( (array) _get_cron_array() as $time => $hooks ) {
			foreach ( $hooks as $hook => $events ) {
				foreach ( $events as $event ) {
					$args = $event['args'];
					if ( ( 'backupplus_backup_resume' === $hook && isset( $args[1] ) && $nonce === $args[1] ) || ( $batch && 'backupplus_pre_update_run' === $hook && isset( $args[0] ) && $batch === $args[0] ) || ( $batch && 'backupplus_pre_update_finalize' === $hook && isset( $args[0], $args[2]['pre_update_batch'] ) && $nonce === $args[0] && $batch === $args[2]['pre_update_batch'] ) ) {
						$result[] = array(
							'time' => (int) $time,
							'hook' => $hook,
							'args' => $args,
						);
					}
				}
			}
		}
		usort(
			$result,
			function ( $a, $b ) {
				return $a['time'] <=> $b['time'];
			}
		);
		return $result;
	}

	public static function worker( $nonce, $operation ) {
		$flags = array( 'operation' => $operation );
		// The parent CLI already passed its root check; launch_self does not retain the environment override.
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) { $flags['allow-root'] = true; }
		foreach ( array( 'skip-plugins', 'skip-themes', 'require', 'context', 'exec' ) as $key ) {
			$value = WP_CLI::get_config( $key );
			if ( null !== $value && false !== $value && '' !== $value && array() !== $value ) { $flags[ $key ] = $value; }
		}
		return WP_CLI::launch_self( 'backupplus _worker', array( $nonce ), $flags, false, true );
	}

	public static function wait( $nonce, $batch = '' ) {
		for ( ;; ) {
			$result = self::fresh( self::RESULT . $nonce, true );
			if ( $batch ) {
				self::fresh( BackupPlus_Pre_Update_Backups::OPTION );
				$current = BackupPlus_Pre_Update_Backups::instance()->batch();
				if ( empty( $current['id'] ) || $current['id'] !== $batch || ! in_array( $current['state'], array( 'queued', 'backing_up', 'ready' ), true ) ) { throw new RuntimeException( 'Pre-update backup failed or is unavailable. Updates remain paused.' ); }
				if ( 'ready' === $current['state'] ) { return $result; }
			} elseif ( is_array( $result ) ) {
				$diagnostics = $result['diagnostics'];
				if ( 'success' !== $result['outcome'] && ( 'failed' !== $result['outcome'] || ! is_array( $diagnostics ) || 0 !== $diagnostics['errors'] ) ) { throw new RuntimeException( 'Backup failed. Review log.' . $nonce . '.txt.' ); }
				return $result;
			}
			$events = self::events( $nonce, $batch );
			if ( ! $events && (int) self::fresh( 'backupplus_lock_udp_backupjob_' . $nonce ) > time() ) {
				// Another cron process may have consumed the event and still own this job.
				usleep( 200000 );
				continue;
			}
			if ( ! $events ) { throw new RuntimeException( 'Backup worker stopped without terminal completion evidence or a scheduled retry. Review log.' . $nonce . '.txt.' ); }
			$delay = $events[0]['time'] - time();
			if ( $delay > 0 ) { sleep( min( 5, $delay ) );
				continue; }
			self::worker( $nonce, 'event' );
		}
	}

	public static function output( $rows, $args, $defaults, $available = null ) {
		$format = isset( $args['format'] ) ? $args['format'] : 'table';
		if ( ! in_array( $format, array( 'table', 'json', 'csv' ), true ) ) { WP_CLI::error( 'Use --format=table, json or csv.' ); }
		$fields    = isset( $args['fields'] ) ? self::words( $args['fields'] ) : $defaults;
		$available = null !== $available ? $available : ( $rows ? array_keys( reset( $rows ) ) : $defaults );
		if ( array_diff( $fields, $available ) ) { WP_CLI::error( 'Unknown output field.' ); }
		\WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	public static function schedules() {
		$rows     = array();
		$schedule = BackupPlus_Backup_Schedule::instance();
		foreach ( BackupPlus_Backup_Schedule::HOOKS as $which => $hook ) {
			$definition = $schedule->definition( $which );
			$next       = wp_next_scheduled( $hook );
			if ( 'db' === $which && $schedule->same_schedule() ) { $next = wp_next_scheduled( BackupPlus_Backup_Schedule::HOOKS['files'] ); }
			$rows[] = array(
				'type'         => strtr(
					$which,
					array(
						'db'         => 'database',
						'increments' => 'incremental',
					)
				),
				'interval'     => $definition ? $definition['interval'] : 'disabled',
				'exact'        => $definition ? (int) $definition['exact'] : 0,
				'time'         => $definition ? $definition['time'] : null,
				'day'          => $definition ? $definition['day'] : null,
				'timezone'     => BackupPlus_Backup_Schedule::timezone()->getName(),
				'next_run'     => $next ?: null,
				'next_run_utc' => $next ? gmdate( 'Y-m-d H:i:s', $next ) : null,
			);
		}
		return $rows;
	}

	public static function save_settings( $settings ) {
		$valid = BackupPlus_Backup_Schedule::instance()->validate( $settings );
		if ( is_wp_error( $valid ) ) { throw new InvalidArgumentException( $valid->get_error_message() ); }
		foreach ( $settings as $key => $value ) { BackupPlus_Options::update_backupplus_option( $key, $value ); }
		BackupPlus_Backup_Schedule::instance()->reconcile();
	}
}

/** Manage backups, sources, exact schedules, update protection and credential keys. */
class BackupPlus_CLI_Command {
	/**
	 * Rotate disk-managed keys or generate the next environment key-ring value.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Use rotate to rotate the active credential key.
	 *
	 * @subcommand credential-keys
	 */
	public function credential_keys( $args, $assoc ) {
		if ( ! isset( $args[0] ) || 'rotate' !== $args[0] ) {
			WP_CLI::error( 'Use wp backupplus credential-keys rotate.' );
		}
		try {
			if ( BackupPlus_Credential_Key_Store::environment_managed() ) {
				// This value is a secret; keep stdout to the payload for safe capture by automation.
				WP_CLI::line( BackupPlus_Credential_Key_Store::environment_rotation_value() );
				return;
			}
			BackupPlus_Credential_Key_Rotation::rotate_manually();
			WP_CLI::success( 'Credential key rotated and saved credentials were re-encrypted.' );
		} catch ( Throwable $error ) {
			WP_CLI::error( $error->getMessage() );
		}
	}

	/**
	 * Create a backup and wait through all retries.
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : all, files or database. Default: all.
	 *
	 * [--files=<ids>]
	 * : Comma-separated entity IDs; custom expands configured additional sources.
	 *
	 * [--tables=<names>]
	 * : Exact WordPress table names, separated by commas.
	 *
	 * [--selection=<file>]
	 * : JSON selection; exclusive with --files and --tables.
	 *
	 * [--local]
	 * : Skip remote uploads.
	 *
	 * [--services=<ids>]
	 * : Upload only to these services from remote list; exclusive with --local.
	 *
	 * [--label=<label>]
	 * : Label shown in backup history.
	 *
	 * [--incremental]
	 * : Append changed files to a compatible chain, falling back to full.
	 *
	 * [--porcelain]
	 * : Print only the history timestamp (the base timestamp for an increment).
	 */
	public function backup( $args, $assoc ) {
		try {
			$request   = BackupPlus_CLI::selection( $assoc );
			$nonce     = substr( bin2hex( random_bytes( 8 ) ), 0, 12 );
			$timestamp = time();
			while ( BackupPlus_Backup_History::get_history( $timestamp ) ) { ++$timestamp; }
			$request['options']['use_nonce']     = $nonce;
			$request['options']['use_timestamp'] = $timestamp;
			update_site_option( BackupPlus_CLI::REQUEST . $nonce, $request );
			try {
				BackupPlus_CLI::worker( $nonce, 'start' );
				$result = BackupPlus_CLI::wait( $nonce );
			} finally {
				delete_site_option( BackupPlus_CLI::REQUEST . $nonce );
				delete_site_option( BackupPlus_CLI::RESULT . $nonce );
			}
			foreach ( isset( $result['diagnostics']['messages'] ) ? $result['diagnostics']['messages'] : array() as $message ) {
				if ( 'warning' === $message['level'] ) { WP_CLI::warning( $message['message'] ); }
			}
			if ( isset( $assoc['porcelain'] ) ) { WP_CLI::line( (string) $result['timestamp'] ); }
			else { WP_CLI::success( 'Backup completed: ' . $result['timestamp'] ); }
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}

	/**
	 * List recorded file restore points for a backup chain, newest first.
	 *
	 * ## OPTIONS
	 *
	 * <timestamp>
	 * : Backup history timestamp.
	 *
	 * [--format=<format>]
	 * : Output format: table, json or csv.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated output fields.
	 *
	 * @subcommand restore-points
	 */
	public function restore_points( $args, $assoc ) {
		$backup = BackupPlus_Backup_History::get_history( $args[0] );
		if ( ! $backup ) { WP_CLI::error( 'Backup timestamp not found.' ); }
		$points = isset( $backup['incremental_sets'] ) ? $backup['incremental_sets'] : array();
		krsort( $points );
		$rows = array();
		foreach ( $points as $timestamp => $entities ) { $rows[] = array(
			'timestamp'  => $timestamp,
			'date'       => gmdate( 'Y-m-d H:i:s', $timestamp ),
			'components' => implode( ',', array_keys( $entities ) ),
		); }
		BackupPlus_CLI::output( $rows, $assoc, array( 'timestamp', 'date', 'components' ) );
	}

	/**
	 * Restore a backup; encrypted archives prompt for secrets in the terminal.
	 *
	 * ## OPTIONS
	 *
	 * <timestamp>
	 * : History timestamp of the backup chain to restore.
	 *
	 * [--type=<type>]
	 * : all (default), files or database.
	 *
	 * [--components=<ids>]
	 * : Comma-separated component IDs from backup history, such as plugins,db,wpcore.
	 *
	 * [--plugin=<name>]
	 * : Restore only this top-level plugin directory or PHP filename from the plugins component.
	 *
	 * [--table=<name>]
	 * : Restore only this exact source table name from a full database dump.
	 *
	 * [--point=<timestamp>]
	 * : Restore the file chain through this recorded incremental restore point.
	 *
	 * [--include-wp-config]
	 * : Restore wp-config.php in place during a core restore.
	 *
	 * [--site=<blog-id>]
	 * : On a network, restore only this site of a network backup: its tables and uploads.
	 *
	 * [--import-into=<site>]
	 * : On a network, import a single-site backup into a new site ("new", named by --slug) or over this blog ID.
	 *
	 * [--slug=<slug>]
	 * : Sub-directory or sub-domain of the site that --import-into=new creates.
	 *
	 * [--dry-run]
	 * : List the archives the restore would use and whether they are local, then stop.
	 *
	 * [--format=<format>]
	 * : Output format of --dry-run: table, json or csv.
	 *
	 * [--yes]
	 * : Skip overwrite confirmation. Encrypted restores still prompt for secrets.
	 */
	public function restore( $args, $assoc ) {
		if ( isset( $assoc['dry-run'] ) ) {
			try { BackupPlus_CLI::output( BackupPlus_CLI_Restore::plan( $args[0], $assoc ), $assoc, array( 'component', 'file', 'size', 'encrypted', 'local' ) ); }
			catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
			return;
		}
		try { BackupPlus_CLI_Restore::run( $args[0], $assoc ); }
		catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}

	/**
	 * Internal subprocess worker.
	 *
	 * ## OPTIONS
	 *
	 * <nonce>
	 * : Internal backup job identifier.
	 *
	 * [--operation=<operation>]
	 * : Internal worker action: start or event.
	 *
	 * @subcommand _worker
	 */
	public function worker( $args, $assoc ) {
		global $backupplus;
		$nonce = $args[0];
		if ( ! preg_match( '/^[a-f0-9]{12}$/D', $nonce ) ) { WP_CLI::error( 'Invalid job ID.' ); }
		$request = get_site_option( BackupPlus_CLI::REQUEST . $nonce );
		if ( ! is_array( $request ) ) { WP_CLI::error( 'No CLI request exists for this job.' ); }
		if ( ! defined( 'BACKUPPLUS_FOLLOWUPS_ALLOWED' ) ) { define( 'BACKUPPLUS_FOLLOWUPS_ALLOWED', true ); }
		if ( 'start' === $assoc['operation'] ) {
			$backupplus->boot_backup( $request['files'] ? 1 : 0, $request['database'] ? 1 : 0, $request['entities'], false, $request['local'] ? array( 'none' ) : ( ! empty( $request['services'] ) ? $request['services'] : false ), $request['options'] );
		} else {
			$events = BackupPlus_CLI::events( $nonce, isset( $request['batch'] ) ? $request['batch'] : '' );
			if ( $events && $events[0]['time'] <= time() ) {
				$event = $events[0];
				wp_unschedule_event( $event['time'], $event['hook'], $event['args'] );
				do_action_ref_array( $event['hook'], $event['args'] );
			}
		}
	}

	/**
	 * List saved backup history, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format: table, json or csv.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated output fields.
	 *
	 * @subcommand list
	 */
	public function list_backups( $args, $assoc ) {
		$rows = array();
		foreach ( BackupPlus_Backup_History::get_history() as $timestamp => $backup ) {
			$components = array();
			foreach ( $backup as $key => $value ) {
				if ( preg_match( '/^(db[0-9]*|plugins|themes|uploads|others|mu-plugins|wpcore|credential-keys|custom-[a-p]{16})$/D', $key ) && $value ) { $components[] = $key; }
			}
			$partial = false;
			foreach ( isset( $backup['database_coverage'] ) ? $backup['database_coverage'] : array() as $coverage ) { $partial = $partial || ! empty( $coverage['partial'] ); }
			$rows[] = array(
				'timestamp'        => $timestamp,
				'date'             => gmdate( 'Y-m-d H:i:s', $timestamp ),
				'nonce'            => isset( $backup['nonce'] ) ? $backup['nonce'] : '',
				'label'            => isset( $backup['label'] ) ? $backup['label'] : '',
				'components'       => implode( ',', $components ),
				'services'         => implode( ',', isset( $backup['service'] ) ? (array) $backup['service'] : array() ),
				'pre_update'       => ! empty( $backup['pre_update'] ),
				'partial_database' => $partial,
			);
		}
		BackupPlus_CLI::output( $rows, $assoc, array( 'timestamp', 'date', 'nonce', 'label', 'components', 'services' ), array( 'timestamp', 'date', 'nonce', 'label', 'components', 'services', 'pre_update', 'partial_database' ) );
	}

	/**
	 * Delete entire backup chains and their local archives.
	 *
	 * Name timestamps, or select by age and count. Chains protected with keep, active chains and restore points that
	 * authorise pending updates are never selected by the filters.
	 *
	 * ## OPTIONS
	 *
	 * [<timestamp>...]
	 * : History timestamps identifying whole chains.
	 *
	 * [--older-than=<days>]
	 * : Select chains older than this many days.
	 *
	 * [--keep=<count>]
	 * : Select all but the newest chains; with --older-than both must match.
	 *
	 * [--remote]
	 * : Also delete remote copies.
	 *
	 * [--dry-run]
	 * : List the chains that would be deleted, then stop.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * [--format=<format>]
	 * : Output format of --dry-run: table, json or csv.
	 */
	public function delete( $args, $assoc ) {
		try {
			require_once BACKUPPLUS_DIR . '/includes/class-backup-deletion.php';
			$history = BackupPlus_Backup_History::get_history();
			$plan    = BackupPlus_CLI_Prune::select( $history, $args, $assoc, time(), array( 'BackupPlus_Backup_Deletion', 'protected_set' ) );
			foreach ( $plan['skipped'] as $timestamp => $reason ) { WP_CLI::warning( 'Skipping ' . $timestamp . ': ' . $reason . '.' ); }
			if ( ! $plan['delete'] ) { WP_CLI::error( 'No backup chains match.' ); }
			if ( isset( $assoc['dry-run'] ) ) {
				$rows = array();
				foreach ( $plan['delete'] as $timestamp ) { $rows[] = array(
					'timestamp' => $timestamp,
					'date'      => gmdate( 'Y-m-d H:i:s', (int) $timestamp ),
					'label'     => isset( $history[ $timestamp ]['label'] ) ? $history[ $timestamp ]['label'] : '',
				); }
				BackupPlus_CLI::output( $rows, $assoc, array( 'timestamp', 'date', 'label' ) );
				return;
			}
			WP_CLI::confirm( 1 === count( $plan['delete'] ) ? 'Delete backup chain ' . $plan['delete'][0] . '?' : 'Delete ' . count( $plan['delete'] ) . ' backup chains (' . implode( ', ', $plan['delete'] ) . ')?', $assoc );
			$admin  = BackupPlus_CLI::admin();
			$failed = array();
			foreach ( $plan['delete'] as $timestamp ) {
				$result = $admin->delete_set(
					array(
						'backup_timestamp' => $timestamp,
						'delete_remote'    => isset( $assoc['remote'] ),
						'is_continuation'  => false,
						'strict'           => true,
					)
				);
				if ( ! empty( $result['errors'] ) || 'error' === $result['result'] ) {
					$failed[] = $timestamp;
					WP_CLI::warning( $timestamp . ': ' . ( isset( $result['message'] ) ? $result['message'] : json_encode( $result['errors'] ) ) );
				} else { WP_CLI::log( 'Deleted ' . $timestamp . '.' ); }
			}
			if ( $failed ) { WP_CLI::error( count( $failed ) . ' of ' . count( $plan['delete'] ) . ' backup chain(s) could not be deleted: ' . implode( ', ', $failed ) . '.' ); }
			WP_CLI::success( 1 === count( $plan['delete'] ) ? 'Backup deleted.' : count( $plan['delete'] ) . ' backups deleted.' );
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}

	/**
	 * Rescan local storage, and optionally remote storage, for backups missing from history.
	 *
	 * ## OPTIONS
	 *
	 * [--remote]
	 * : Also scan remote storage.
	 */
	public function rescan( $args, $assoc ) {
		try {
			BackupPlus_Backup_History::always_get_from_db();
			foreach ( (array) BackupPlus_Backup_History::rebuild( isset( $assoc['remote'] ) ) as $message ) {
				if ( is_string( $message ) ) { WP_CLI::log( wp_strip_all_tags( $message ) );
					continue; }
				if ( ! empty( $message['code'] ) && 'file-listing' === $message['code'] ) { continue; }
				if ( ! empty( $message['message'] ) ) { WP_CLI::log( wp_strip_all_tags( ( empty( $message['desc'] ) ? '' : $message['desc'] . ': ' ) . $message['message'] ) ); }
			}
			WP_CLI::success( 'History rebuilt: ' . count( BackupPlus_Backup_History::get_history() ) . ' backups.' );
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}

	/**
	 * Protect backups from retention, or release them.
	 *
	 * ## OPTIONS
	 *
	 * <timestamp>...
	 * : History timestamps.
	 *
	 * [--off]
	 * : Release the protection instead of setting it.
	 */
	public function keep( $args, $assoc ) {
		foreach ( $args as $timestamp ) { if ( ! ctype_digit( $timestamp ) ) { WP_CLI::error( 'Use backup history timestamps.' ); }
		}
		$result = BackupPlus_Backup_History::set_always_keep( $args, ! isset( $assoc['off'] ) );
		foreach ( $result['errors'] as $timestamp => $message ) { WP_CLI::warning( $timestamp . ': ' . $message ); }
		if ( ! $result['locked'] ) { WP_CLI::error( 'No backups were changed.' ); }
		WP_CLI::success( ( isset( $assoc['off'] ) ? 'Released ' : 'Protected ' ) . count( $result['locked'] ) . ' backup(s).' );
		if ( $result['errors'] ) { exit( 1 ); }
	}

	/**
	 * Copy a backup's archives to a directory, fetching missing ones from remote storage.
	 *
	 * ## OPTIONS
	 *
	 * <timestamp>
	 * : Backup history timestamp.
	 *
	 * --to=<directory>
	 * : Target directory; created when it does not exist.
	 *
	 * [--type=<type>]
	 * : all (default), files or database.
	 *
	 * [--components=<ids>]
	 * : Comma-separated history component IDs.
	 *
	 * [--point=<timestamp>]
	 * : Stop a file chain at this recorded restore point.
	 *
	 * [--force]
	 * : Overwrite existing files.
	 *
	 * [--porcelain]
	 * : Print only the copied paths.
	 */
	public function download( $args, $assoc ) {
		try {
			$paths = BackupPlus_CLI_Download::run( $args[0], $assoc );
			if ( isset( $assoc['porcelain'] ) ) { foreach ( $paths as $path ) { WP_CLI::line( $path );
			}
			}
			else { WP_CLI::success( 'Copied ' . count( $paths ) . ' archive(s) to ' . dirname( reset( $paths ) ) . '.' ); }
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}

	/**
	 * Check the environment backups depend on; fails when a check fails.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format: table, json or csv.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated output fields.
	 */
	public function doctor( $args, $assoc ) {
		try {
			$rows = BackupPlus_CLI_Diagnostics::checks();
			BackupPlus_CLI::output( $rows, $assoc, array( 'check', 'status', 'detail' ) );
			if ( in_array( 'fail', wp_list_pluck( $rows, 'status' ), true ) ) { WP_CLI::halt( 1 ); }
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}

	/**
	 * List the plugin's scheduled events, soonest first.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format: table, json or csv.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated output fields.
	 */
	public function cron( $args, $assoc ) {
		try {
			BackupPlus_CLI::output( BackupPlus_CLI_Diagnostics::events(), $assoc, array( 'hook', 'next_run_utc', 'overdue', 'schedule', 'args' ), array( 'hook', 'next_run', 'next_run_utc', 'overdue', 'schedule', 'args' ) );
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}

	/**
	 * List the WordPress database tables with their size in bytes.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format: table, json or csv.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated output fields.
	 *
	 * @subcommand db-size
	 */
	public function db_size( $args, $assoc ) {
		try {
			BackupPlus_CLI::output( BackupPlus_CLI_Diagnostics::tables(), $assoc, array( 'name', 'engine', 'data', 'index', 'total' ) );
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}

	/**
	 * Show file sources and saved database selections without credentials.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format: table, json or csv.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated output fields.
	 */
	public function sources( $args, $assoc ) {
		global $backupplus;
		$rows = array();
		foreach ( $backupplus->get_backupable_file_entities( true ) as $id => $path ) { $rows[] = array(
			'type'    => 'files',
			'id'      => $id,
			'path'    => $path,
			'enabled' => (int) BackupPlus_Backup_Sources::included( $id ),
			'mode'    => '',
			'tables'  => '',
		); }
		$selection = BackupPlus_Backup_Sources::selection( BackupPlus_Options::get_backupplus_option( 'backupplus_database_selection', array() ) );
		$dbs       = array( 'wp' => array( 'enabled' => true ) ) + BackupPlus_Backup_Sources::connections();
		foreach ( $dbs as $id => $info ) {
			$entry  = isset( $selection[ $id ] ) ? $selection[ $id ] : array(
				'enabled' => $info['enabled'],
				'mode'    => 'all',
				'tables'  => array(),
			);
			$rows[] = array(
				'type'    => 'database',
				'id'      => $id,
				'path'    => '',
				'enabled' => (int) $entry['enabled'],
				'mode'    => $entry['mode'],
				'tables'  => implode( ',', $entry['tables'] ),
			);
		}
		BackupPlus_CLI::output( $rows, $assoc, array( 'type', 'id', 'path', 'enabled', 'mode', 'tables' ) );
	}

	/**
	 * Inspect or change schedules.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list or set.
	 *
	 * [<type>]
	 * : files, database or incremental.
	 *
	 * [--interval=<interval>]
	 * : Registered WordPress interval; manual disables full backups, none disables increments.
	 *
	 * [--time=<time>]
	 * : Site-local time in HH:MM format.
	 *
	 * [--day=<day>]
	 * : Sunday 0 through Saturday 6; monthly day 1 through 28.
	 *
	 * [--no-exact]
	 * : Use interval-only scheduling while preserving saved timing fields.
	 *
	 * [--format=<format>]
	 * : Output format: table, json or csv.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated output fields.
	 */
	public function schedule( $args, $assoc ) {
		try {
			if ( 'set' === $args[0] ) {
				$types = array(
					'files'       => 'files',
					'database'    => 'db',
					'incremental' => 'increments',
				);
				if ( ! isset( $args[1], $types[ $args[1] ], $assoc['interval'] ) ) { throw new InvalidArgumentException( 'schedule set requires a source type and --interval.' ); }
				$which = $types[ $args[1] ];
				if ( isset( $assoc['time'] ) && isset( $assoc['no-exact'] ) ) { throw new InvalidArgumentException( '--time and --no-exact are exclusive.' ); }
				$settings = array( ( 'files' === $which ? 'backupplus_interval' : ( 'db' === $which ? 'backupplus_interval_database' : 'backupplus_interval_increments' ) ) => $assoc['interval'] );
				if ( isset( $assoc['time'] ) ) {
					if ( ! BackupPlus_Backup_Schedule::valid_time( $assoc['time'] ) ) { throw new InvalidArgumentException( 'Use a valid HH:MM time.' ); }
					$settings[ 'backupplus_starttime_' . $which ]      = $assoc['time'];
					$settings[ 'backupplus_schedule_exact_' . $which ] = 1;
				}
				if ( isset( $assoc['no-exact'] ) ) { $settings[ 'backupplus_schedule_exact_' . $which ] = 0; }
				if ( isset( $assoc['day'] ) ) {
					$range = BackupPlus_Backup_Schedule::day_range( $assoc['interval'] );
					if ( ! $range || ! preg_match( '/^[0-9]{1,2}$/D', $assoc['day'] ) || $assoc['day'] < $range[0] || $assoc['day'] > $range[1] ) { throw new InvalidArgumentException( 'Invalid day for this interval.' ); }
					$settings[ 'backupplus_startday_' . $which ] = (int) $assoc['day'];
				}
				BackupPlus_CLI::save_settings( $settings );
			} elseif ( 'list' !== $args[0] ) { throw new InvalidArgumentException( 'Use schedule list or schedule set.' ); }
			BackupPlus_CLI::output( BackupPlus_CLI::schedules(), $assoc, array( 'type', 'interval', 'exact', 'time', 'day', 'timezone', 'next_run', 'next_run_utc' ) );
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}

	/**
	 * Inspect or change retained counts and cleanup timing.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : status, set or run. run needs daily cleanup mode and deletes backups beyond the counts.
	 *
	 * [--files=<count>]
	 * : Number of file backups to retain (positive integer).
	 *
	 * [--database=<count>]
	 * : Number of database backups to retain (positive integer).
	 *
	 * [--mode=<mode>]
	 * : after_backup or daily.
	 *
	 * [--time=<time>]
	 * : Site-local time in HH:MM format.
	 *
	 * [--yes]
	 * : Skip the confirmation of run.
	 *
	 * [--format=<format>]
	 * : Output format: table, json or csv.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated output fields.
	 */
	public function retention( $args, $assoc ) {
		try {
			if ( 'set' === $args[0] ) {
				$settings = array();
				foreach ( array(
					'files'    => 'backupplus_retain',
					'database' => 'backupplus_retain_db',
				) as $flag => $option ) {
					if ( isset( $assoc[ $flag ] ) ) {
						if ( ! preg_match( '/^[1-9][0-9]*$/D', $assoc[ $flag ] ) || strlen( $assoc[ $flag ] ) > strlen( (string) PHP_INT_MAX ) || ( strlen( $assoc[ $flag ] ) === strlen( (string) PHP_INT_MAX ) && strcmp( $assoc[ $flag ], (string) PHP_INT_MAX ) > 0 ) ) { throw new InvalidArgumentException( 'Retention counts must be positive integers.' ); }
						$settings[ $option ] = (int) $assoc[ $flag ];
					}
				}
				foreach ( array( 'mode', 'time' ) as $flag ) { if ( isset( $assoc[ $flag ] ) ) { $settings[ 'backupplus_retention_cleanup_' . $flag ] = $assoc[ $flag ]; }
				}
				if ( isset( $assoc['time'] ) && ! BackupPlus_Backup_Schedule::valid_time( $assoc['time'] ) ) { throw new InvalidArgumentException( 'Use a valid HH:MM time.' ); }
				if ( ! $settings ) { throw new InvalidArgumentException( 'Supply at least one retention setting.' ); }
				BackupPlus_CLI::save_settings( $settings );
			} elseif ( 'run' === $args[0] ) {
				if ( ! BackupPlus_Retention_Cleanup::deferred() ) { throw new InvalidArgumentException( 'Retention is applied after each backup. Use retention set --mode=daily to run cleanup on demand.' ); }
				WP_CLI::confirm( 'Delete backups beyond the retention counts now?', $assoc );
				BackupPlus_CLI_Prune::cleanup();
				WP_CLI::success( 'Cleanup finished.' );
			} elseif ( 'status' !== $args[0] ) { throw new InvalidArgumentException( 'Use retention status, set or run.' ); }
			$status = array(
				'files'         => BackupPlus_Options::get_backupplus_option( 'backupplus_retain', 2 ),
				'database'      => BackupPlus_Options::get_backupplus_option( 'backupplus_retain_db', 2 ),
				'mode'          => BackupPlus_Options::get_backupplus_option( 'backupplus_retention_cleanup_mode', 'after_backup' ),
				'time'          => BackupPlus_Options::get_backupplus_option( 'backupplus_retention_cleanup_time', '' ),
				'timezone'      => BackupPlus_Backup_Schedule::timezone()->getName(),
				'next_run'      => wp_next_scheduled( BackupPlus_Backup_Schedule::HOOKS['cleanup'] ) ?: null,
				'latest_result' => json_encode( BackupPlus_Retention_Cleanup::instance()->status() ),
			);
			BackupPlus_CLI::output( array( $status ), $assoc, array_keys( $status ) );
		} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }
	}

	/**
	 * Inspect or enable pre-update protection.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : status or set.
	 *
	 * [--enabled=<enabled>]
	 * : 0 or 1.
	 *
	 * [--format=<format>]
	 * : Output format: table, json or csv.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated output fields.
	 *
	 * @subcommand pre-update
	 */
	public function pre_update( $args, $assoc ) {
		if ( 'set' === $args[0] ) {
			if ( ! isset( $assoc['enabled'] ) || ! in_array( $assoc['enabled'], array( '0', '1' ), true ) ) { WP_CLI::error( 'Supply --enabled=0 or 1.' ); }
			BackupPlus_Options::update_backupplus_option( 'backupplus_autobackup_default', (int) $assoc['enabled'] );
		} elseif ( 'status' !== $args[0] ) { WP_CLI::error( 'Use pre-update status or pre-update set.' ); }
		$batch = BackupPlus_Pre_Update_Backups::instance()->batch();
		$row   = array(
			'enabled'     => (int) BackupPlus_Pre_Update_Backups::enabled(),
			'batch'       => isset( $batch['id'] ) ? $batch['id'] : '',
			'state'       => isset( $batch['state'] ) ? $batch['state'] : '',
			'job'         => isset( $batch['job'] ) ? $batch['job'] : '',
			'backup_time' => isset( $batch['backup_time'] ) ? $batch['backup_time'] : null,
		);
		BackupPlus_CLI::output( array( $row ), $assoc, array_keys( $row ) );
	}
}
