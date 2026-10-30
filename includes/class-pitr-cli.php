<?php

/** Database binary-log worker, chain inspection and isolated recovery commands. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

class BackupPlus_PITR_CLI {

	/** Pretty JSON is the default; table and csv list rows, or the flattened key and value pairs of a nested result. */
	private function output( $callback, $args = array() ) {
		try {
			$format = isset( $args['format'] ) ? $args['format'] : 'json';
			if ( ! in_array( $format, array( 'json', 'table', 'csv' ), true ) ) {
				throw new InvalidArgumentException( 'Use --format=json, table or csv.' );
			}
			$result = call_user_func( $callback );
			if ( 'json' === $format ) {
				WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				return;
			}
			$rows = self::rows( $result );
			BackupPlus_CLI::output( $rows, array( 'format' => $format ), $rows ? array_keys( reset( $rows ) ) : array( 'key', 'value' ) );
		} catch ( Throwable $error ) {
			WP_CLI::error( $error->getMessage() );
		}
	}

	/** A list of flat records is already rows; anything else becomes one row per leaf, keyed by its dotted path. */
	public static function rows( $result ) {
		if ( is_array( $result ) && $result && array_is_list( $result ) ) {
			$flat = true;
			foreach ( $result as $row ) {
				$flat = $flat && is_array( $row ) && ! array_is_list( $row ) && array() === array_filter( $row, 'is_array' );
			}
			if ( $flat ) {
				return array_map(
					function ( $row ) {
						return array_map( array( __CLASS__, 'cell' ), $row );
					},
					$result
				);
			}
		}
		return self::flatten( $result, '' );
	}

	private static function cell( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	private static function flatten( $value, $prefix ) {
		if ( ! is_array( $value ) || ! $value ) {
			return array(
				array(
					'key'   => $prefix,
					'value' => is_array( $value ) ? '[]' : self::cell( $value ),
				),
			);
		}
		$rows = array();
		foreach ( $value as $key => $item ) {
			$rows = array_merge( $rows, self::flatten( $item, '' === $prefix ? (string) $key : $prefix . '.' . $key ) );
		}
		return $rows;
	}

	private function configuration( $args ) {
		$current = BackupPlus_PITR::config();
		return array(
			'engine'        => isset( $args['engine'] ) ? $args['engine'] : ( isset( $current['engine'] ) ? $current['engine'] : '' ),
			'source_config' => isset( $args['source-config'] ) ? $args['source-config'] : ( isset( $current['source_config'] ) ? $current['source_config'] : '' ),
			'bin_directory' => isset( $args['bin-directory'] ) ? $args['bin-directory'] : ( isset( $current['bin_directory'] ) ? $current['bin_directory'] : '/usr/bin' ),
			'database'      => DB_NAME,
			'interval'      => isset( $args['interval'] ) ? (int) $args['interval'] : ( isset( $current['interval'] ) ? $current['interval'] : 15 ),
		);
	}

	/**
	 * Inspect native clients, logging and the application database scope.
	 *
	 * ## OPTIONS
	 * [--engine=<engine>]
	 * [--source-config=<file>]
	 * [--bin-directory=<directory>]
	 * [--format=<format>]
	 * : Output format: json (default), table or csv.
	 */
	public function capabilities( $unused, $args ) {
		$this->output(
			function () use ( $args ) {
				$worker = new BackupPlus_PITR( $this->configuration( $args ) );
				return $worker->capabilities();
			},
			$args
		);
	}

	/**
	 * Enable coordinated full backups and closed binary-log capture.
	 *
	 * ## OPTIONS
	 * --engine=<engine>
	 * --source-config=<file>
	 * [--interval=<minutes>]
	 * [--bin-directory=<directory>]
	 * [--format=<format>]
	 * : Output format: json (default), table or csv.
	 */
	public function enable( $unused, $args ) {
		$this->output(
			function () use ( $args ) {
				$worker = new BackupPlus_PITR();
				$worker->configure( $this->configuration( $args ) );
				return $worker->run( 'full' );
			},
			$args
		);
	}

	/**
	 * Disable capture and keep all existing artifacts.
	 *
	 * ## OPTIONS
	 * [--format=<format>]
	 * : Output format: json (default), table or csv.
	 */
	public function disable( $unused, $args ) {
		$this->output(
			function () {
				$config            = BackupPlus_PITR::config();
				$config['enabled'] = false;
				BackupPlus_PITR::save_configuration( $config );
				BackupPlus_PITR::deactivate();
				return array( 'enabled' => false );
			},
			$args
		);
	}

	/**
	 * Run due capture, renew overdue baselines, or retry unpublished uploads.
	 *
	 * ## OPTIONS
	 * [--format=<format>]
	 * : Output format: json (default), table or csv.
	 */
	public function run( $unused, $args ) {
		$this->output(
			function () {
				$worker = new BackupPlus_PITR();
				return $worker->run();
			},
			$args
		);
	}

	/**
	 * Create a new coordinated full backup.
	 *
	 * ## OPTIONS
	 * [--format=<format>]
	 * : Output format: json (default), table or csv.
	 */
	public function full( $unused, $args ) {
		$this->output(
			function () {
				$worker = new BackupPlus_PITR();
				return $worker->run( 'full' );
			},
			$args
		);
	}

	/**
	 * Capture all closed source logs through a newly created rotation boundary.
	 *
	 * ## OPTIONS
	 * [--format=<format>]
	 * : Output format: json (default), table or csv.
	 */
	public function capture( $unused, $args ) {
		$this->output(
			function () {
				$worker = new BackupPlus_PITR();
				return $worker->run( 'capture' );
			},
			$args
		);
	}

	/**
	 * Show full/incremental coordinates and destination status.
	 *
	 * ## OPTIONS
	 * [--points]
	 * [--format=<format>]
	 * : Output format: json (default), table or csv.
	 */
	public function status( $unused, $args ) {
		$this->output(
			function () use ( $args ) {
				$worker = new BackupPlus_PITR();
				return $worker->summary( isset( $args['points'] ) );
			},
			$args
		);
	}

	private function coordinate( $args ) {
		if ( isset( $args['at'] ) ) {
			throw new RuntimeException( 'Wall-clock cutoffs are not supported yet: event timestamps do not always identify commit time. Select an exact coordinate from status.' );
		}
		if ( ! isset( $args['coordinate'] ) ) {
			return null;
		}
		if ( ! preg_match( '/\A([^:]+):([0-9]+)\z/D', $args['coordinate'], $m ) ) {
			throw new RuntimeException( 'Use --coordinate=binlog-filename:byte-position.' );
		}
		return BackupPlus_PITR_Manifest::coordinate(
			array(
				'file'     => $m[1],
				'position' => (int) $m[2],
			)
		);
	}

	/**
	 * Produce a recovery plan without changing a database.
	 *
	 * ## OPTIONS
	 * <chain>
	 * [--coordinate=<file:position>]
	 * [--format=<format>]
	 * : Output format: json (default), table or csv.
	 */
	public function plan( $positional, $args ) {
		$this->output(
			function () use ( $positional, $args ) {
				$worker = new BackupPlus_PITR();
				return $worker->recovery_plan( $positional[0], $this->coordinate( $args ) );
			},
			$args
		);
	}

	/**
	 * Fetch, authenticate and verify every dependency through a recovery point.
	 *
	 * ## OPTIONS
	 * <chain>
	 * [--coordinate=<file:position>]
	 * [--format=<format>]
	 * : Output format: json (default), table or csv.
	 */
	public function verify( $positional, $args ) {
		$this->output(
			function () use ( $positional, $args ) {
				$worker = new BackupPlus_PITR();
				return $worker->verify( $positional[0], $this->coordinate( $args ), array( 'BackupPlus_CLI_Secret', 'read' ) );
			},
			$args
		);
	}

	/**
	 * Rescan local/remote portable manifests and rebuild missing history.
	 *
	 * ## OPTIONS
	 * [--format=<format>]
	 * : Output format: json (default), table or csv.
	 */
	public function rescan( $unused, $args ) {
		$this->output(
			function () {
				$worker = new BackupPlus_PITR();
				return $worker->rescan( array( 'BackupPlus_CLI_Secret', 'read' ) );
			},
			$args
		);
	}

	/**
	 * Reconstruct a historical database on an empty isolated server and export a restore artifact.
	 *
	 * ## OPTIONS
	 * <chain>
	 * --recovery-config=<file>
	 * --isolated
	 * [--coordinate=<file:position>]
	 * [--tables=<tables>]
	 * [--format=<format>]
	 * : Output format: json (default), table or csv.
	 */
	public function recover( $positional, $args ) {
		$this->output(
			function () use ( $positional, $args ) {
				$worker = new BackupPlus_PITR();
				return $worker->recover( $positional[0], $this->coordinate( $args ), $args['recovery-config'], isset( $args['tables'] ) ? BackupPlus_CLI::words( $args['tables'] ) : array(), array( 'BackupPlus_CLI_Secret', 'read' ), isset( $args['isolated'] ) );
			},
			$args
		);
	}

	/**
	 * Replace this database or selected tables from an isolated historical reconstruction.
	 *
	 * Direct restores manage application isolation automatically; staged restores require stopped writers.
	 *
	 * ## OPTIONS
	 * <chain>
	 * [--writers-stopped]
	 * [--direct]
	 * [--recovery-config=<file>]
	 * [--isolated]
	 * [--coordinate=<file:position>]
	 * [--tables=<tables>]
	 * [--yes]
	 * [--format=<format>]
	 * : Output format: json (default), table or csv.
	 */
	public function restore( $positional, $args ) {
		$this->output(
			function () use ( $positional, $args ) {
				if ( ! isset( $args['direct'] ) && ! isset( $args['writers-stopped'] ) ) {
					throw new RuntimeException( 'Stop all writers and supply --writers-stopped before production replacement.' );
				}
				$direct = isset( $args['direct'] );
				require_once __DIR__ . '/class-pitr-setup.php';
				$config                       = BackupPlus_PITR::config();
				$connection                   = BackupPlus_PITR_Setup::supplied_connection( $config, BackupPlus_CLI_Secret::read( 'Restore database username: ' ), BackupPlus_CLI_Secret::read( 'Restore database password: ' ) );
				$config['restore_connection'] = $connection;
				try {

					if ( ! $direct && ! isset( $args['recovery-config'], $args['isolated'] ) ) { throw new RuntimeException( 'Choose --direct for whole-database source recovery, or supply --recovery-config and --isolated for staged recovery.' ); }
					$worker = new BackupPlus_PITR( $config );
					$plan   = $worker->recovery_plan( $positional[0], $this->coordinate( $args ) );
					WP_CLI::confirm( 'Replace current database contents at ' . $plan['target_coordinate']['file'] . ':' . $plan['target_coordinate']['position'] . '? A separate pre-restore backup will be retained.', $args );
					return $worker->restore( $positional[0], $this->coordinate( $args ), isset( $args['recovery-config'] ) ? $args['recovery-config'] : '', isset( $args['tables'] ) ? BackupPlus_CLI::words( $args['tables'] ) : array(), array( 'BackupPlus_CLI_Secret', 'read' ), isset( $args['isolated'] ), $direct, true );
				} finally { BackupPlus_PITR_Process::forget_connection( $connection ); }
			},
			$args
		);
	}

	/**
	 * Delete a whole inactive chain, including all remote artifacts.
	 *
	 * ## OPTIONS
	 * <chain>
	 * [--yes]
	 * [--format=<format>]
	 * : Output format: json (default), table or csv.
	 */
	public function delete( $positional, $args ) {
		$this->output(
			function () use ( $positional, $args ) {
				$worker = new BackupPlus_PITR();
				WP_CLI::confirm( 'Delete this complete PITR chain and all its recovery coverage?', $args );
				$worker->delete( $positional[0] );
				return array( 'deleted' => $positional[0] );
			},
			$args
		);
	}

	/**
	 * Abandon an unpublished attempt after configuration changes; preserve its artifacts.
	 *
	 * ## OPTIONS
	 * [--yes]
	 * [--format=<format>]
	 * : Output format: json (default), table or csv.
	 */
	public function abandon( $unused, $args ) {
		$this->output(
			function () use ( $args ) {
				$worker = new BackupPlus_PITR();
				WP_CLI::confirm( 'Abandon pending publication and require a new full chain? Existing artifacts are preserved.', $args );
				return $worker->abandon_pending();
			},
			$args
		);
	}

	/**
	 * Apply database retention counts to complete inactive PITR chains.
	 *
	 * ## OPTIONS
	 * [--format=<format>]
	 * : Output format: json (default), table or csv.
	 */
	public function prune( $unused, $args ) {
		$this->output(
			function () {
				$worker = new BackupPlus_PITR();
				$worker->prune();
				return $worker->summary();
			},
			$args
		);
	}
}
