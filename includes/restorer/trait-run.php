<?php

/** The restore run: downloading missing archives, performing each entity and cleaning up. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Restorer_Run {

	/**
	 * Restore has been completed - clean some things up
	 *
	 * @param Boolean|WP_Error $successful      - if the restore was successful (true) or not (false or WP_Error). If not, then only a minimum of necessary clean-up things is done.
	 * @param Boolean          $browser_context - if true, then extra messages will be echo-ed
	 *
	 * @uses BackupPlus::log()
	 */
	public function post_restore_clean_up( $successful = true, $browser_context = true ) {

		global $backupplus, $backupplus_admin;

		$backupplus->log_restore_update(
			array(
				'type'  => 'state',
				'stage' => 'cleaning',
				'data'  => array(),
			)
		);

		if ( is_wp_error( $successful ) ) {
			foreach ( $successful->get_error_codes() as $code ) {
				if ( 'already_exists' == $code ) {
					if ( $browser_context ) {
						global $backupplus_admin;
						$backupplus_admin->print_delete_old_dirs_form( false );
					} else {
						$backupplus->log( __( 'Your WordPress install has old directories from its state before you restored/migrated (technical information: these are suffixed with -old).', 'backupplus' ) );
					}
				}
				$data = $successful->get_error_data( $code );
				if ( ! empty( $data ) ) {
					$pdata = is_string( $data ) ? $data : serialize( $data );
					$backupplus->log( __( 'Error data:', 'backupplus' ) . ' ' . $pdata, 'warning-restore' );
					if ( false !== strpos( $pdata, 'PCLZIP_ERR_BAD_FORMAT (-10)' ) ) {
						if ( $browser_context ) {
							echo '<strong>' . esc_html__( 'Check that the archive is complete and is a valid ZIP file, then try the restore again.', 'backupplus' ) . '</strong><br>';
						} else {
							$backupplus->log( __( 'Check that the archive is complete and is a valid ZIP file, then try the restore again.', 'backupplus' ) );
						}
					}
				}
			}
			$successful = false;
		}

		// From this point on, $successful is a boolean
		if ( $successful ) {
			// All done - remove the intermediate marker
			delete_site_option( 'backupplus_restore_in_progress' );

			foreach ( array( 'template', 'stylesheet', 'template_root', 'stylesheet_root' ) as $opt ) {
				add_filter( 'pre_option_' . $opt, array( $this, 'option_filter_' . $opt ) );
			}

			// Clear any cached pages after the restore
			$this->clear_caches();

			// Have seen a case where the current theme in the DB began with a capital, but not on disk - and this breaks migrating from Windows to a case-sensitive system
			$template = get_option( 'template' );
			if ( ! empty( $template ) && WP_DEFAULT_THEME != $template && strtolower( $template ) != $template ) {

				$theme_root = get_theme_root( $template );

				if ( ! file_exists( "$theme_root/$template/style.css" ) && file_exists( "$theme_root/" . strtolower( $template ) . '/style.css' ) ) {
					$backupplus->log_e( 'Theme directory (%s) not found, but lower-case version exists; updating database option accordingly', $template );
					update_option( 'template', strtolower( $template ) );
				}
			}

			if ( ! function_exists( 'validate_current_theme' ) ) {
				include_once ABSPATH . WPINC . '/themes';
			}

			if ( ! validate_current_theme() ) {
				if ( $browser_context ) {
					echo '<strong>';
				}
				$backupplus->log_e( 'The current theme was not found; to prevent this stopping the site from loading, your theme has been reverted to the default theme' );
				if ( $browser_context ) {
					echo '</strong>';
				}
			}

			do_action( 'backupplus_restore_completed' );
		}

		if ( $browser_context ) {
			echo '</div>'; // Close the backupplus_restore_progress div
		}

		restore_error_handler();
	}

	/**
	 * Ensure that needed files are present locally, and return data for the next step (plus do some internal configuration)
	 *
	 * @param Array $entities_to_restore
	 * @param Array $backupable_entities - list of entities
	 * @param Array $services            - list of services that the backup can be found at
	 *
	 * @uses self::pre_restore_backup() (and some other internal properties)
	 * @uses BackupPlus::log()
	 *
	 * @return Boolean|Array|WP_Error - a sorted array (of entity types and files for each entity type) or false or a WP_Error if there was an error
	 */
	private function ensure_restore_files_present( $entities_to_restore, $backupable_entities, $services ) {

		global $backupplus;

		// First loop: make sure that files are present + readable; and populate array for second loop
		$second_loop = $this->check_file_availability_and_log( $entities_to_restore, $backupable_entities, $services );

		// A WP_Error or boolean indicates that the restore cannot proceed
		if ( ! is_array( $second_loop ) ) {
			return $second_loop;
		}

		$this->delete_archives_upon_restoration = BackupPlus_Options::get_backupplus_option( 'backupplus_delete_local', 1 ) ? true : false;
		if ( empty( $services ) || array( 'email' ) === $services || ! empty( $this->ud_foreign ) ) {
			if ( $this->delete_archives_upon_restoration ) {
				$backupplus->log_e( 'Will not delete any archives after unpacking them, because there was no cloud storage for this backup' );
			}
			$this->delete_archives_upon_restoration = false;
		}

		if ( ! empty( $this->ud_foreign ) ) {
			$backupplus->log( 'Foreign backup; created by: ' . $this->ud_foreign );
		}

		// Second loop: prepare to launch the restoration
		uksort( $second_loop, array( 'BackupPlus_Manipulation_Functions', 'sort_restoration_entities' ) );

		// If continuing, then prune those already done
		if ( is_array( $this->continuation_data ) && isset( $this->continuation_data['second_loop_entities'] ) ) {
			foreach ( $second_loop as $type => $files ) {
				if ( isset( $this->continuation_data['second_loop_entities'][ $type ] ) ) {
					$second_loop[ $type ] = $this->continuation_data['second_loop_entities'][ $type ];
				} else {
					$backupplus->log_restore_update(
						array(
							'type'  => 'state',
							'stage' => 'files',
							'data'  => array(
								'entity'       => $type,
								'index'        => 0,
								'file'         => '',
								'fileindex'    => 0,
								'size_written' => 0,
								'total_files'  => 0,
							),
						)
					);
					unset( $second_loop[ $type ] );
				}
			}
		}

		return $second_loop;
	}

	/**
	 * Check that the files in the backup set in self::$backup_set are available and as expected; log the results; return a processed array with information
	 *
	 * @param Array $entities_to_restore
	 * @param Array $backupable_entities - list of entities
	 * @param Array $services            - services to fetch from if files are not available locally
	 *
	 * @uses self::$backup_set
	 * @uses self::$ud_backup_is_multisite
	 * @uses self::$continuation_data
	 * @uses self::$been_restored
	 * @uses self::$ud_foreign
	 *
	 * @return Array|WP_Error|Boolean - if successful, then an array for the second loop; otherwise, an error returned (either a WP_Error or boolean false)
	 */
	private function check_file_availability_and_log( $entities_to_restore, $backupable_entities, $services ) {

		global $backupplus;
		$backupplus_dir = $backupplus->backups_dir_location();
		$foreign_known  = apply_filters( 'backupplus_accept_archivename', array() );

		$entities_to_download = $this->get_entities_to_download( $entities_to_restore );

		$backup_set = $this->ud_backup_set;
		$timestamp  = $backup_set['timestamp'];

		$second_loop = array();

		foreach ( $backup_set as $type => $files ) {

			// All restorable entities must be given explicitly, as we can store other arbitrary data in the history array
			if ( ! isset( $backupable_entities[ $type ] ) && 'db' != $type ) {
				continue;
			}

			if ( isset( $backupable_entities[ $type ]['restorable'] ) && false == $backupable_entities[ $type ]['restorable'] ) {
				continue;
			}

			if ( ! isset( $entities_to_download[ $type ] ) ) {
				continue;
			}

			if ( 'wpcore' == $type && is_multisite() && 0 === $this->ud_backup_is_multisite ) {
				$backupplus->log( 'wpcore: ' . __( 'Skipping restoration of WordPress core when importing a single site into a multisite installation.', 'backupplus' ) . ' ' . __( 'If you had anything necessary in your WordPress directory then you will need to re-add it manually from the zip file.', 'backupplus' ), 'notice-restore' );
				// TODO
				// $backupplus->log_e('Skipping restoration of WordPress core when importing a single site into a multisite installation. If you had anything necessary in your WordPress directory then you will need to re-add it manually from the zip file.');
				continue;
			}

			if ( is_string( $files ) ) {
				$files = array( $files );
			}

			foreach ( $files as $ind => $file ) {

				$fullpath = $backupplus_dir . '/' . $file;
				/* translators: 1: Entity type, 2: File name */
				$backupplus->log( sprintf( __( 'Looking for %1$s archive: file name: %2$s', 'backupplus' ), $type, $file ), 'notice-restore' );

				if ( is_array( $this->continuation_data ) && isset( $this->continuation_data['second_loop_entities'][ $type ] ) && ! in_array( $file, $this->continuation_data['second_loop_entities'][ $type ] ) ) {
					$backupplus->log( __( 'Skipping: this archive was already restored.', 'backupplus' ), 'notice-restore' );
					// Set the marker so that the existing directory isn't moved out of the way
					$this->been_restored[ $type ] = true;
					continue;
				}

				if ( ! is_readable( $fullpath ) || 0 == filesize( $fullpath ) ) {
					BackupPlus_Storage_Methods_Interface::get_remote_file( $services, $file, $timestamp, true );
				}

				$index = ( 0 == $ind ) ? '' : $ind;
				// If a file size is stored in the backup data, then verify correctness of the local file
				if ( isset( $backup_set[ $type . $index . '-size' ] ) ) {
					$fs            = $backup_set[ $type . $index . '-size' ];
					$print_message = __( 'Archive is expected to be size:', 'backupplus' ) . ' ' . round( $fs / 1024, 1 ) . ' KB: ';
					$as            = @filesize( $fullpath );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- the ensuing code handles any issues
					if ( $as == $fs ) {
						$backupplus->log( $print_message . __( 'OK', 'backupplus' ), 'notice-restore' );
					} else {
						$backupplus->log( $print_message . __( 'Error:', 'backupplus' ) . ' ' . __( 'file is size:', 'backupplus' ) . ' ' . round( $as / 1024 ) . " ($fs, $as)", 'warning-restore' );
					}
				} else {
					$backupplus->log( __( 'The backup records do not contain information about the proper size of this file.', 'backupplus' ), 'notice-restore' );
				}
				if ( ! is_readable( $fullpath ) ) {
					$backupplus->log( __( 'Could not read one of the files for restoration', 'backupplus' ) . " ($file)", 'warning-restore' );
					$backupplus->log( "$file: " . __( 'Could not read one of the files for restoration', 'backupplus' ), 'error' );
					return false;
				}
			}

			if ( empty( $this->ud_foreign ) ) {
				$types = array( $type );
			} else {
				if ( 'db' != $type || empty( $foreign_known[ $this->ud_foreign ]['separatedb'] ) ) {
					$types = array( 'wpcore' );
				} else {
					$types = array( 'db' );
				}
			}

			foreach ( $types as $check_type ) {
				$info = isset( $backupable_entities[ $check_type ] ) ? $backupable_entities[ $check_type ] : array();
				$val  = $this->pre_restore_backup( $files, $check_type, $info );
				if ( is_wp_error( $val ) ) {
					$backupplus->log_wp_error( $val );
					foreach ( $val->get_error_messages() as $msg ) {
						$backupplus->log( __( 'Error:', 'backupplus' ) . ' ' . $msg, 'warning-restore' );
					}
					return $val;
				} elseif ( false === $val ) {
					return false;
				}
			}

			foreach ( $entities_to_restore as $entity => $via ) {
				if ( $via == $type ) {
					if ( 'wpcore' == $via && 'db' == $entity && count( $files ) > 1 ) {
						$second_loop[ $entity ] = apply_filters( 'backupplus_select_wpcore_file_with_db', $files, $this->ud_foreign );
					} else {
						$second_loop[ $entity ] = $files;
					}
				}
			}
		}

		return $second_loop;
	}

	/**
	 * Perform the restoration. No code here (or called) should assume anything about the method used to call it (e.g. wp-admin or WP-CLI); it should be independent of how it is being called.
	 *
	 * The path through this class is: perform_restore() -> restore_backup() -> unpack_package_(archive|database) and then (for standard UD archives) BackupPlus_Filesystem_Functions::unzip_file()
	 *
	 * @param Array $entities_to_restore - entities to restore
	 * @param Array $restore_options     - restoration options
	 *
	 * @uses the WordPress action backupplus_restoration_title, allowing the title to be printed
	 *
	 * @return Boolean|WP_Error
	 */
	public function perform_restore( $entities_to_restore, $restore_options ) {
		global $backupplus;

		$backupplus->log_restore_update(
			array(
				'type'  => 'state',
				'stage' => 'verifying',
				'data'  => implode( ', ', array_flip( $entities_to_restore ) ),
			)
		);

		// Now log. We first remove any encryption passphrase from the log data.
		$copy_restore_options = $restore_options;
		unset( $copy_restore_options['backupplus_external_targets'] );
		if ( ! empty( $copy_restore_options['backupplus_encryptionphrase'] ) ) {
			$copy_restore_options['backupplus_encryptionphrase'] = '***';
		}
		$backupplus->log( 'Restore job started. Entities to restore: ' . implode( ', ', array_flip( $entities_to_restore ) ) . '. Restore options: ' . json_encode( $copy_restore_options ) );

		do_action( 'backupplus_restoration_title', __( 'Final checks', 'backupplus' ) );
		add_action( 'backupplus_unzip_progress_restore_info', array( $this, 'unzip_progress_restore_info' ), 10, 4 );
		$backup_set = $this->ud_backup_set;

		$services = isset( $backup_set['service'] ) ? $backupplus->get_canonical_service_list( $backup_set['service'] ) : array();

		$backupable_entities = BackupPlus_Backup_Sources::restore_entities( $backupplus->get_backupable_file_entities( true, true ), $backup_set );
		try { BackupPlus_Source_Restore::preflight( $entities_to_restore, $backup_set, $restore_options ); }
		catch ( Exception $e ) { return new WP_Error( 'source_preflight_failed', $e->getMessage() ); }

		$remove_zip = isset( $restore_options['delete_during_restore'] ) ? $restore_options['delete_during_restore'] : false;

		if ( ! empty( $restore_options['dummy_db_restore'] ) ) {
			$this->is_dummy_db_restore = true;
			add_filter( 'backupplus_restore_table_prefix', array( $this, 'backupplus_random_restore_table_prefix' ) );
		}

		// Allow add-ons to adjust the restore directory (but only in the case of restore - otherwise, they could just use the filter built into BackupPlus::get_backupable_file_entities)
		$backupable_entities = apply_filters( 'backupplus_backupable_file_entities_on_restore', $backupable_entities, $restore_options, $backup_set );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( BACKUPPLUS_SET_TIME_LIMIT );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}
		// Get an ordered list of things to restore
		// This requires the global $backupplus_restorer to be set up
		$second_loop = $this->ensure_restore_files_present( $entities_to_restore, $backupable_entities, $services );

		if ( ! is_array( $second_loop ) ) {
			return $second_loop;
		}

		// External databases precede the WordPress database, whose options may change this installation.
		uksort(
			$second_loop,
			function ( $a, $b ) {
				$rank = function ( $type ) {
					return preg_match( '/^db[1-9][0-9]*$/D', $type ) ? 0 : ( 'db' === $type ? 2 : 1 );
				};
				return $rank( $a ) - $rank( $b );
			}
		);
		$timestamp = $backup_set['timestamp'];

		$backupplus->jobdata_set( 'second_loop_entities', $second_loop );
		$backupplus->jobdata_set( 'backup_timestamp', $timestamp );

		// Use a site option, as otherwise on multisite when all the array of options is updated via BackupPlus_Options::update_site_option(), it will over-write any restored UD options from the backup
		update_site_option( 'backupplus_restore_in_progress', $backupplus->nonce );

		// Now process the actual restoration of the entities
		foreach ( $second_loop as $type => $files ) {

			$this->current_type = $type;

			// Types: uploads, themes, plugins, others, db
			$info = isset( $backupable_entities[ $type ] ) ? $backupable_entities[ $type ] : array();

			$restoration_title = ( 'db' == $type ) ? __( 'Database', 'backupplus' ) : $info['description'];

			// Indicate the type changed
			$backupplus->log_restore_update(
				array(
					'type'  => 'state_change',
					'stage' => $type,
					'data'  => array(),
				)
			);

			do_action( 'backupplus_restoration_title', $restoration_title );

			$backupplus->log( 'Entity: ' . $type );

			if ( is_string( $files ) ) {
				$files = array( $files );
			}

			// Don't assume that the caller pre-sorted the array. We do need it sorted, so that incremental zips get restored in the right order
			ksort( $files );

			foreach ( $files as $fkey => $file ) {
				$this->current_index = $fkey;
				$last_one            = ( 1 == count( $second_loop ) && 1 == count( $files ) );
				$last_entity         = ( 1 == count( $files ) );
				try {
					do_action( 'backupplus_pre_entity_restoration', $type, $fkey );
					// Returns a boolean or WP_Error
					$restore_result = $this->restore_backup( $file, $type, $info, $last_one, $last_entity );
				} catch ( Exception $e ) {
					$log_message = 'Exception (' . get_class( $e ) . ') occurred during restore: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
					/* translators: 1: Class name, 2: Exception message */
					$display_log_message = sprintf( __( 'A PHP exception (%1$s) has occurred: %2$s', 'backupplus' ), get_class( $e ), $e->getMessage() );
					BackupPlus_Manipulation_Functions::error_log( $log_message );
					// @codingStandardsIgnoreLine
					if ( function_exists( 'wp_debug_backtrace_summary' ) ) {
						$log_message .= ' Backtrace: ' . str_replace( array( ABSPATH, "\n" ), array( '', ', ' ), $e->getTraceAsString() );
					}
					$backupplus->log( $display_log_message, 'notice-restore' );
					die();
				} catch ( Error $e ) { //phpcs:ignore PHPCompatibility.Classes.NewClasses.errorFound -- This Error class will only get triggered during runtime but we don't explicitly throw this class in our code; so we only catch it when PHP throws it.
					$log_message = 'PHP Fatal error (' . get_class( $e ) . ') has occurred. Error Message: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
					BackupPlus_Manipulation_Functions::error_log( $log_message );
					// @codingStandardsIgnoreLine
					if ( function_exists( 'wp_debug_backtrace_summary' ) ) {
						$log_message .= ' Backtrace: ' . str_replace( array( ABSPATH, "\n" ), array( '', ', ' ), $e->getTraceAsString() );
					}
					/* translators: 1: Class name, 2: Exception message */
					$display_log_message = sprintf( __( 'A PHP fatal error (%1$s) has occurred: %2$s', 'backupplus' ), get_class( $e ), $e->getMessage() );
					$backupplus->log( $display_log_message, 'notice-restore' );
					die();
				}

				if ( is_wp_error( $restore_result ) ) {
					$codes = $restore_result->get_error_codes();
					if ( is_array( $codes ) && in_array( 'not_found', $codes ) && ! empty( $this->ud_foreign ) && apply_filters( 'backupplus_foreign_allow_missing_entity', false, $type, $this->ud_foreign ) ) {
						$backupplus->log( 'Entity to move not found in this zip - but this is possible with this foreign backup type' );
					} else {
						$backupplus->log_e( $restore_result );
						foreach ( $restore_result->get_error_messages() as $msg ) {
							$backupplus->log( __( 'Error message', 'backupplus' ) . ': ' . $msg, 'notice-restore' );
						}
						return $restore_result;
					}
				} elseif ( false === $restore_result ) {
					return false;
				} elseif ( $restore_result && $remove_zip ) {
					$deleted = unlink( $backupplus->backups_dir_location() . '/' . $file );
					$backupplus->log( "Delete zip during restore active; removing backup file: $file: " . ( $deleted ? 'OK' : 'Failed' ) );
				}

				unset( $files[ $fkey ] );
				$second_loop[ $type ] = $files;
				$backupplus->jobdata_set_multi(
					array(
						'second_loop_entities' => $second_loop,
						'backup_timestamp'     => $timestamp,
					)
				);

				do_action( 'backupplus_restored_archive', $file, $type, $restore_result, $fkey, $timestamp );

			}

			// Update the job data each time we go round the loop, so that if it aborts, it can be resumed from the correct point
			unset( $second_loop[ $type ] );
			update_site_option( 'backupplus_restore_in_progress', $backupplus->nonce );
			$backupplus->jobdata_set_multi(
				array(
					'second_loop_entities' => $second_loop,
					'backup_timestamp'     => $timestamp,
				)
			);
		}

		// If the database was restored, then check active plugins and make sure they all exist; otherwise, the site may go down
		if ( null !== $this->final_import_table_prefix ) {
			$this->check_active_plugins( $this->final_import_table_prefix );
		}

		return true;
	}

	/**
	 * Calculate the entities to download for a given backup set
	 *
	 * @param Array $entities_to_restore - entities to restore, in the format returned by BackupPlus_Admin::get_entities_to_restore
	 *
	 * @return Array - keys are entities, and values are 0|1
	 */
	public function get_entities_to_download( $entities_to_restore ) {

		$backup_set = $this->ud_backup_set;

		$foreign_known = apply_filters( 'backupplus_accept_archivename', array() );

		if ( empty( $backup_set['meta_foreign'] ) ) {
			return $entities_to_restore;
		}

		if ( empty( $foreign_known[ $backup_set['meta_foreign'] ]['separatedb'] ) ) {
			return array( 'wpcore' => 1 );
		}

		$entities_to_download = array();

		if ( in_array( 'db', $entities_to_restore ) ) {
			$entities_to_download['db'] = 1;
		}

		if ( count( $entities_to_restore ) > 1 || ! in_array( 'db', $entities_to_restore ) ) {
			$entities_to_download['wpcore'] = 1;
		}

		return $entities_to_download;
	}

	/**
	 * Logs a line from the restore process, being called from BackupPlus::log(). Currently, this means adding it to the browser output log file and either (depending on the constant WP_CLI) echoing it or passing it to a WPCLI method.
	 * Hooks the WordPress filter backupplus_logline
	 * In future, this can get more sophisticated. For now, things are funnelled through here, giving the future possibility.
	 *
	 * @param String         $line        the line to be logged
	 * @param String         $nonce       the job ID of the restore job
	 * @param String         $level       the level of the log notice
	 * @param String|Boolean $uniq_id     a unique ID for the log if it should only be logged once; or false otherwise
	 * @param String         $destination the type of job ongoing. If it is not 'restore', then we will skip the logging.
	 * @return The filtered value. If set to false, then BackupPlus::log() will stop processing the log line.
	 */
	public function backupplus_logline( $line, $nonce, $level, $uniq_id, $destination ) {
		if ( 'restore' != $destination ) {
			return $line;
		}

		global $backupplus;
		static $logfile_handle;

		if ( empty( $logfile_handle ) ) {
			$logfile_name   = $backupplus->backups_dir_location() . "/log.$nonce-browser.txt";
			$logfile_handle = fopen( $logfile_name, 'a' );
		}

		if ( ! empty( $logfile_handle ) ) {
			$rtime = microtime( true ) - $backupplus->job_time_ms;
			fwrite( $logfile_handle, sprintf( '%08.03f', round( $rtime, 3 ) ) . ' (R) ' . '[' . $level . '] ' . $line . "\n" );
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			switch ( $level ) {
				case 'error':
				case 'warning':
					// WP_CLI::error() displays message with the prefix "Error: ", We don't like message which are double prefixed like the "Error: Error: ".
					if ( 0 === stripos( $line, 'Error: ' ) ) {
						$log_line = substr( $line, 7 );
					} else {
						$log_line = $line;
					}
					WP_CLI::error( $log_line, false );
					break;
				case 'notice':
				default:
					WP_CLI::log( $line, false );
					break;
			}
		} else {
			if ( 'warning' == $level || 'error' == $level || $uniq_id ) {
				$line = '<strong>' . htmlspecialchars( $line ) . '</strong>';
			} else {
				$line = htmlspecialchars( $line );
			}

			$backupplus->output_to_browser( $line . '<br>' );
		}
		return false;
	}

	/**
	 * Configures self::$strings
	 */
	private function backup_strings() {
		$this->strings['not_possible']            = __( 'BackupPlus is not able to directly restore this kind of entity.', 'backupplus' ) . ' ' . __( 'It must be restored manually.', 'backupplus' );
		$this->strings['no_package']              = __( 'Backup file not available.', 'backupplus' );
		$this->strings['copy_failed']             = __( 'Copying this entity failed.', 'backupplus' );
		$this->strings['unpack_package']          = __( 'Unpacking backup...', 'backupplus' );
		$this->strings['decrypt_database']        = __( 'Decrypting database (can take a while)...', 'backupplus' );
		$this->strings['decrypted_database']      = __( 'Database successfully decrypted.', 'backupplus' );
		$this->strings['moving_old']              = __( 'Moving old data out of the way...', 'backupplus' );
		$this->strings['moving_backup']           = __( 'Moving unpacked backup into place...', 'backupplus' );
		$this->strings['restore_database']        = __( 'Restoring the database (on a large site this can take a long time - if it times out (which can happen if your web hosting company has configured your hosting to limit resources) then you should use a different method, such as phpMyAdmin)...', 'backupplus' );
		$this->strings['cleaning_up']             = __( 'Cleaning up rubbish...', 'backupplus' );
		$this->strings['old_move_failed']         = __( 'Could not move old files out of the way.', 'backupplus' ) . ' ' . __( 'You should check the file ownerships and permissions in your WordPress installation', 'backupplus' );
		$this->strings['old_delete_failed']       = __( 'Could not delete old path.', 'backupplus' );
		$this->strings['new_move_failed']         = __( 'Could not move new files into place.', 'backupplus' ) . ' ' . __( 'Check your wp-content/upgrade folder.', 'backupplus' );
		$this->strings['move_failed']             = __( 'Could not move the files into place.', 'backupplus' ) . ' ' . __( 'Check your file permissions.', 'backupplus' );
		$this->strings['delete_failed']           = __( 'Failed to delete working directory after restoring.', 'backupplus' );
		$this->strings['multisite_error']         = __( 'You are running on WordPress multisite - but your backup is not of a multisite site.', 'backupplus' );
		$this->strings['unpack_failed']           = __( 'Failed to unpack the archive', 'backupplus' );
		$this->strings['read_manifest_failed']    = __( 'Failed to read the manifest file from backup.', 'backupplus' );
		$this->strings['manifest_not_found']      = __( 'Failed to find a manifest file in the backup.', 'backupplus' );
		$this->strings['read_working_dir_failed'] = __( 'Failed to read from the working directory.', 'backupplus' );
	}

	/**
	 * This function will build the unzip progress restore info array ready to be output to the js
	 *
	 * @param String  $filepath     - the current file we are working on
	 * @param Integer $fileindex    - how far into the zip we got
	 * @param Integer $size_written - net total number of bytes thus far
	 * @param Integer $num_files    - the total number of files (i.e. one more than the the maximum value of $fileindex)
	 *
	 * @return void
	 */
	public function unzip_progress_restore_info( $filepath, $fileindex, $size_written, $num_files ) {

		global $backupplus;

		$index     = $this->current_index;
		$file_type = $this->current_type;

		$backupplus->log_restore_update(
			array(
				'type'  => 'state',
				'stage' => 'files',
				'data'  => array(
					'entity'       => $file_type,
					'index'        => $index,
					'file'         => basename( $filepath ),
					'fileindex'    => $fileindex,
					'size_written' => $size_written,
					'total_files'  => $num_files,
				),
			)
		);
	}

	/**
	 * Callback function that will be triggered when the script execution ends. Reset log_bin_trust_function_creators variable's value to its original value
	 */
	public function on_shutdown() {

		global $backupplus;

		if ( ! empty( $this->stored_routine_supported ) && is_array( $this->stored_routine_supported ) && $this->stored_routine_supported['is_binary_logging_enabled'] ) {
			// if this condition is met, it means that db server binary logging is enabled and the value of the log_bin_trust_function_system_variable has previously been set to ON (1), and now the value must be changed back to what it originally was
			if ( isset( $this->continuation_data['old_log_bin_trust_function_creators'] ) ) {
				$old_log_bin_trust_function_creators = $this->continuation_data['old_log_bin_trust_function_creators'];
			} else {
				$old_log_bin_trust_function_creators = $backupplus->jobdata_get( 'old_log_bin_trust_function_creators' );
			}
			if ( is_string( $old_log_bin_trust_function_creators ) && '' !== $old_log_bin_trust_function_creators ) {
				$this->set_log_bin_trust_function_creators( $old_log_bin_trust_function_creators );
			}
		}
	}
}
