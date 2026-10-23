<?php
/** Explicit --require fixture used only by the disposable CLI integration checks. */
$cli_test_directory = getenv( 'BACKUPPLUS_CLI_TEST_DIR' );

if ( ! $cli_test_directory || ! is_file( $cli_test_directory . '/config.json' ) ) {
	return;
}

WP_CLI::add_hook(
	'after_wp_load',
	function () use ( $cli_test_directory ) {

		$config = json_decode( file_get_contents( $cli_test_directory . '/config.json' ), true );

		$prefix = $config['prefix'];

		foreach ( $config['options'] as $name => $default ) {

			add_filter(
				'pre_option_' . $name,
				function () use ( $prefix, $name, $default ) {

					wp_cache_delete( $prefix . $name, 'options' );

					wp_cache_delete( 'alloptions', 'options' );

					return get_option( $prefix . $name, $default );
				},
				100
			);

			add_filter(
				'pre_update_option_' . $name,
				function ( $value, $old ) use ( $prefix, $name ) {

					update_option( $prefix . $name, $value, false );

					return $old;
				},
				100,
				2
			);

		}

		add_filter(
			'pre_transient_doing_cron',
			function () {

				return microtime( true );
			}
		);

		if ( ! defined( 'BACKUPPLUS_DIR' ) ) {
			require dirname( __DIR__, 2 ) . '/backupplus.php';
		}

		add_filter(
			'backupplus_backupable_file_entities_final',
			function ( $entities, $full ) use ( $cli_test_directory ) {

				foreach ( $entities as $id => &$entity ) {

					if ( BackupPlus_Backup_Sources::custom( $id ) ) {
						continue;
					}

					$path = $cli_test_directory . '/' . $id;

					$entity = $full ? array(
						'path'        => $path,
						'description' => $id,
					) : $path;

				}

				unset( $entity );

				return $entities;
			},
			100,
			2
		);

		$mode = is_file( $cli_test_directory . '/mode' ) ? trim( file_get_contents( $cli_test_directory . '/mode' ) ) : '';

		if ( 'external' === $mode ) {

			add_filter(
				'backupplus_get_option',
				function ( $value, $name ) {

					if ( 'backupplus_extradbs' === $name ) {
						return array(
							1 => array(
								'host'   => DB_HOST,
								'name'   => DB_NAME,
								'user'   => DB_USER,
								'pass'   => DB_PASSWORD,
								'prefix' => '',
							),
						);
					}

					if ( 'backupplus_database_selection' === $name ) {
						global $wpdb;
						return array(
							'wp' => array(
								'mode'   => 'selected',
								'tables' => array( $wpdb->posts ),
							),
							1    => array(
								'mode'   => 'selected',
								'tables' => array( $wpdb->options ),
							),
						);
					}

					return $value;
				},
				100,
				2
			);

		}

		if ( 'failed-upload' === $mode ) {

			require_once BACKUPPLUS_DIR . '/methods/backup-module.php';

			if ( ! class_exists( 'CLI_Failed_Storage' ) ) {

				class CLI_Failed_Storage extends BackupPlus_BackupModule {

					public function __construct() {
					}

					public function get_id() {
						return 'ftp';
					}

					public function supports_feature( $feature ) {
						return false;
					}

					public function get_default_options() {
						return array();
					}

					public function backup( $files ) {

						global $backupplus;

						$backupplus->log( 'Synthetic upload failure', 'error' );

						$backupplus->jobdata_set( 'fail_on_resume', $backupplus->current_resumption + 1 );

						return false;
					}

				}

			}

			$objects = new ReflectionProperty( 'BackupPlus_Storage_Provider_Factory', 'objects' );

			if ( PHP_VERSION_ID < 80100 ) {
				$objects->setAccessible( true );
			}

			$objects->setValue( null, array_merge( $objects->getValue(), array( 'ftp' => new CLI_Failed_Storage() ) ) );

			add_filter(
				'backupplus_get_option',
				function ( $value, $name ) {
					return 'backupplus_service' === $name ? array( 'ftp' ) : $value;
				},
				100,
				2
			);

		}

		if ( 'change-package' === $mode ) {

			add_action(
				'backupplus_backup_finished',
				function () use ( $cli_test_directory ) {
					file_put_contents( $cli_test_directory . '/one.zip', 'changed package after backup' );
				},
				40
			);

		}

		add_action(
			'backupplus_resume_backup_backup',
			function () use ( $mode, $cli_test_directory ) {

				global $backupplus;

				if ( 'warning' === $mode ) {
					$backupplus->log( 'Synthetic CLI warning', 'warning' );
				}

				if ( 'exit' === $mode ) {
					$property = new ReflectionProperty( $backupplus, 'semaphore' );

					if ( PHP_VERSION_ID < 80100 ) {
						$property->setAccessible( true );
					}

					$property->getValue( $backupplus )->unlock();

					exit( 0 );
				}

				if ( 'retry' === $mode && 0 === $backupplus->current_resumption ) {

					wp_schedule_single_event( time(), 'backupplus_backup_resume', array( 1, $backupplus->nonce ) );

					wp_schedule_single_event( time(), 'backupplus_cli_unrelated', array() );

					$property = new ReflectionProperty( $backupplus, 'semaphore' );

					if ( PHP_VERSION_ID < 80100 ) {
						$property->setAccessible( true );
					}

					$property->getValue( $backupplus )->unlock();

					exit( 0 );

				}
			}
		);

		if ( 'finalize' === $mode ) {

			add_action(
				'backupplus_backup_finished',
				function () {

					WP_Upgrader::create_lock( 'backupplus_pre_update', 60 );
				},
				5
			);

			add_action(
				'backupplus_backup_finished',
				function () {

					WP_Upgrader::release_lock( 'backupplus_pre_update' );
				},
				30
			);

		}

		add_filter(
			'pre_site_transient_update_core',
			function () use ( $cli_test_directory ) {

				return (object) array(
					'last_checked'    => time(),
					'version_checked' => get_bloginfo( 'version' ),
					'updates'         => array(
						(object) array(
							'response'      => 'upgrade',
							'current'       => '99.0',
							'version'       => '99.0',
							'locale'        => 'en_US',
							'php_version'   => '7.2',
							'mysql_version' => '5.0',
							'download'      => $cli_test_directory . '/core.zip',
							'packages'      => (object) array(
								'partial'     => null,
								'new_bundled' => null,
								'no_content'  => null,
								'full'        => $cli_test_directory . '/core.zip',
							),
						),
					),
				);
			},
			100
		);

		$plugins = array();

		foreach ( array( 'one', 'two' ) as $slug ) {

			$id = 'backupplus-cli-' . $slug . '/plugin.php';

			$plugins[ $id ] = (object) array(
				'plugin'      => $id,
				'slug'        => 'backupplus-cli-' . $slug,
				'new_version' => '2.0',
				'package'     => $cli_test_directory . '/' . $slug . '.zip',
				'url'         => 'https://example.invalid',
			);

		}

		add_filter(
			'pre_site_transient_update_plugins',
			function () use ( $plugins, $mode ) {

				return (object) array(
					'last_checked' => time(),
					'response'     => 'no-updates' === $mode ? array() : $plugins,
					'no_update'    => array(),
					'checked'      => array(),
				);
			},
			100
		);

		add_filter(
			'pre_site_transient_update_themes',
			function () use ( $cli_test_directory ) {

				return (object) array(
					'last_checked' => time(),
					'response'     => array(
						'backupplus-cli-theme' => array(
							'theme'       => 'backupplus-cli-theme',
							'new_version' => '2.0',
							'package'     => $cli_test_directory . '/theme.zip',
						),
					),
					'no_update'    => array(),
					'checked'      => array(),
				);
			},
			100
		);

		add_filter(
			'plugins_api',
			function ( $result, $action, $args ) use ( $cli_test_directory ) {

				if ( 0 !== strpos( $args->slug, 'backupplus-cli-' ) ) {
					return $result;
				}

				$slug = substr( $args->slug, strlen( 'backupplus-cli-' ) );

				return (object) array(
					'name'          => $args->slug,
					'slug'          => $args->slug,
					'version'       => '2.0',
					'download_link' => $cli_test_directory . '/' . $slug . '.zip',
				);
			},
			100,
			3
		);

		add_filter(
			'upgrader_pre_download',
			function ( $reply, $package ) use ( $cli_test_directory, $mode ) {

				if ( 0 !== strpos( $package, $cli_test_directory . '/' ) ) {
					return $reply;
				}

				$batch = BackupPlus_Pre_Update_Backups::instance()->batch();

				$history = BackupPlus_Backup_History::get_history( $batch['backup_time'] );

				if ( ! in_array( $batch['state'], array( 'ready', 'updating' ), true ) || empty( $history['pre_update']['complete'] ) ) {
					throw new RuntimeException( 'An update started without verified backup completion.' );
				}

				if ( 'expire' === $mode ) {

					$batch['completed'] = time() - HOUR_IN_SECONDS - 1;

					update_option( BackupPlus_Pre_Update_Backups::OPTION, $batch, false );

				}

				file_put_contents( $cli_test_directory . '/downloads.log', basename( $package ) . "\n", FILE_APPEND );

				if ( 'core.zip' === basename( $package ) ) {
					return new WP_Error( 'cli_test_core_stop', 'CLI core guard verified; stopping before installed core changes.' );
				}

				// Leave the original local ZIP available for content-hash validation and later invocations.
				$copy = $cli_test_directory . '/download-' . bin2hex( random_bytes( 4 ) ) . '.zip';

				copy( $package, $copy );

				return $copy;
			},
			PHP_INT_MAX - 2,
			2
		);

		add_filter(
			'pre_http_request',
			function () {

				return new WP_Error( 'test_offline', 'External requests disabled in CLI integration tests.' );
			},
			100
		);
	}
);
