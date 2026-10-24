<?php
//phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- we try to reduce overhead by bypassing WP APIs and other extra layers; Some custom complex queries tailored specifically to our needs, giving us full control over the SQL commands and data manipulation
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_print_r -- print_r is intentionally used to convert an array into a readable string or for controlled logging purposes.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- some query operations need to always receive the most up-to-date or actual data directly from the database, reducing the risk of serving stale information.
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- we use the set_error_handler() function to provide a flexible way of handling PHP errors according to our needs; we centralises error handling in one place and customises certain errors based on their severity and context.
// phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged -- some functions, like set_time_limit() and ini_set(), are used to temporarily change PHP configuration values based on the script's needs (e.g., processing large datasets or performing long operations).
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

/*
	See class-commands.php for explanation about how these classes work.
*/

if ( ! class_exists( 'BackupPlus_Commands' ) ) {
	backupplus_try_include_file( 'includes/class-commands.php', 'require_once' );
}

/**
 * An extension, because commands available via wp-admin are a super-set of those which are available through all mechanisms
 */
class BackupPlus_WPAdmin_Commands extends BackupPlus_Commands {

	private $_uc_helper;

	private $_backupplus_admin;

	private $_backupplus;

	/**
	 * Constructor
	 *
	 * @param string $uc_helper The 'helper' needs to provide the method _backupplus_background_operation_started
	 */
	public function __construct( $uc_helper ) {
		$this->_uc_helper = $uc_helper;
		global $backupplus_admin, $backupplus;
		$this->_backupplus_admin = $backupplus_admin;
		$this->_backupplus       = $backupplus;
		parent::__construct( $uc_helper );
	}

	/**
	 * Forces a resumption of a backup where the resumption is overdue (so apparently cron is not working)
	 *
	 * @param Array $info - keys 'job_id' and 'resumption'
	 *
	 * @return Array - if there is an error. Otherwise, dies.
	 */
	public function forcescheduledresumption( $info ) {

		// Casting $resumption to int is absolutely necessary, as the WP cron system uses a hashed serialisation of the parameters for identifying jobs. Different type => different hash => does not match
		$resumption = (int) $info['resumption'];
		$job_id     = $info['job_id'];
		$get_cron   = $this->_backupplus_admin->get_cron( $job_id );
		if ( ! is_array( $get_cron ) ) {
			return array( 'r' => false );
		} else {
			$this->_backupplus->log( "Forcing resumption: job id=$job_id, resumption=$resumption" );
			wp_clear_scheduled_hook( 'backupplus_backup_resume', array( $resumption, $job_id ) );
			$this->_backupplus->close_browser_connection( json_encode( array( 'r' => true ) ) );
			$this->_backupplus->jobdata_set_from_array( $get_cron[1] );
			$this->_backupplus->backup_resume( $resumption, $job_id );
			// We don't want to return. The close_browser_connection call already returned a result.
			die;
		}
	}

	/**
	 * Calls a WordPress action and dies
	 *
	 * @param Array $data - must have at least the key 'wpaction' with a string value
	 *
	 * @return WP_Error if no command was included
	 */
	public function call_wordpress_action( $data ) {

		if ( empty( $data['wpaction'] ) ) {
			return new WP_Error( 'error', '', 'no command sent' );
		}

		$response = $this->_backupplus_admin->call_wp_action( $data, array( $this->_uc_helper, '_backupplus_background_operation_started' ) );// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Unused variable is for future use.

		die;

		// return array('response' => $response['response'], 'status' => $response['status'], 'log' => $response['log'] );
	}

	/** Backup-account credentials are accepted only through the protected admin dispatcher. */
	public function pitr_setup( $params ) {
		try {
			require_once __DIR__ . '/class-pitr-setup.php';
			return BackupPlus_PITR_Setup::request( $params );
		} catch ( Throwable $error ) { return array( 'error' => 'The backup-account check could not finish. Check that the private backup folder is writable, then retry.' ); }
	}

	/** Browser PITR restore accepts database credentials only for the current request. Private archive keys stay in the browser. */
	public function pitr_restore( $params ) {
		try {
			require_once __DIR__ . '/class-pitr-restore.php';
			return BackupPlus_PITR_Restore::request( $params );
		} catch ( Throwable $error ) { return array( 'error' => $error->getMessage() ); }
	}

	public function restore_decryption( $params ) {
		try {
			$operation = isset( $params['operation'] ) ? $params['operation'] : '';
			$ticket    = isset( $params['ticket'] ) ? $params['ticket'] : '';
			if ( 'manifest' === $operation ) {
				$timestamp = (int) $params['timestamp'];
				$backups   = BackupPlus_Backup_History::get_history();
				if ( ! isset( $backups[ $timestamp ] ) || ! isset( $params['entities'] ) || ! is_array( $params['entities'] ) ) {
					throw new \RuntimeException( 'Invalid backup selection.' );
				}
				if ( ! empty( $backups[ $timestamp ]['pitr'] ) ) {
					require_once __DIR__ . '/class-pitr-restore.php';
					return BackupPlus_PITR_Restore::manifest( $timestamp, isset( $params['point'] ) ? $params['point'] : '', $ticket );
				}
				return BackupPlus_Age_Restore::manifest( $backups[ $timestamp ], $timestamp, $params['entities'], $ticket );
			}
			if ( 'discard' === $operation ) {
				BackupPlus_Age_Restore::discard( $ticket );
				return array( 'discarded' => true );
			}
			if ( 'download' === $operation ) {
				$path = BackupPlus_Age_Restore::download( $ticket, $params['file'] );
				nocache_headers();
				header( 'Content-Type: application/octet-stream' );
				header( 'Content-Length: ' . filesize( $path ) );
				readfile( $path );
				die;
			}
			if ( 'upload' === $operation ) {
				if ( ! isset( $params['chunk'] ) || ! is_string( $params['chunk'] ) || strlen( $params['chunk'] ) > 699052 ) {
					throw new \RuntimeException( 'Invalid upload chunk.' );
				}
				$bytes = base64_decode( $params['chunk'], true );
				return BackupPlus_Age_Restore::upload( $ticket, $params['file'], $params['offset'], $bytes, isset( $params['size'] ) ? $params['size'] : null, isset( $params['hash'] ) ? $params['hash'] : '' );
			}
		} catch ( \Throwable $e ) {
			if ( ! empty( $params['point'] ) && $e instanceof \RuntimeException ) { return array( 'error' => $e->getMessage() ); }
			return array( 'error' => __( 'Could not transfer the restore archives. Retry the restore preparation.', 'backupplus' ) );
		}
		return array( 'error' => __( 'Invalid restore operation.', 'backupplus' ) );
	}

	public function restore_alldownloaded( $params ) {

		$backups        = BackupPlus_Backup_History::get_history();
		$backupplus_dir = $this->_backupplus->backups_dir_location();

		$timestamp = (int) $params['timestamp'];
		if ( ! isset( $backups[ $timestamp ] ) ) {
			return array(
				'm' => '',
				'w' => '',
				'e' => __( 'No such backup set exists', 'backupplus' ),
			);
		}

		$mess = array();
		parse_str( stripslashes( $params['restoreopts'] ), $res );

		if ( isset( $res['backupplus_restore'] ) ) {

			$error_levels = version_compare( PHP_VERSION, '8.4.0', '>=' ) ? E_ALL : E_ALL & ~E_STRICT;
			set_error_handler( array( $this->_backupplus_admin, 'get_php_errors' ), $error_levels );

			$elements = array_flip( $res['backupplus_restore'] );

			$warn = array();
			$err  = array();

			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( BACKUPPLUS_SET_TIME_LIMIT );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			}           $max_execution_time = (int) @ini_get( 'max_execution_time' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.

			if ( $max_execution_time > 0 && $max_execution_time < 61 ) {
				/* translators: %s: Maximum execution time in seconds */
				$warn[] = sprintf( __( 'The PHP setup on this webserver allows only %s seconds for PHP to run, and does not allow this limit to be raised.', 'backupplus' ), $max_execution_time ) . ' ' .
				__( 'If you have a lot of data to import, and if the restore operation times out, then you will need to ask your web hosting company for ways to raise this limit (or attempt the restoration piece-by-piece).', 'backupplus' );
			}

			if ( isset( $backups[ $timestamp ]['native'] ) && false == $backups[ $timestamp ]['native'] ) {
				$warn[] = __( 'This backup set was not recorded as created by this WordPress installation.', 'backupplus' ) . ' ' . __( 'Make sure it is intended for this website before restoring it.', 'backupplus' );
			}

			$age_ticket = '';
			$info       = array();
			try {
				$age_ticket                   = BackupPlus_Age_Restore::prepare( $backups[ $timestamp ], $timestamp, isset( $res['backupplus_age_ticket'] ) ? $res['backupplus_age_ticket'] : '', array_keys( $elements ) );
				$res['backupplus_age_ticket'] = $age_ticket;
			} catch ( Exception $e ) {
				$err[] = __( 'Decrypt and upload the selected encrypted archives in your browser, then try again.', 'backupplus' );
			} catch ( Throwable $e ) {
				$err[] = __( 'Decrypt and upload the selected encrypted archives in your browser, then try again.', 'backupplus' );
			}
			unset( $res['backupplus_age_identity'], $res['backupplus_age_key_passphrase'] );
			if ( ! $err ) {
				$before_metadata = $backups[ $timestamp ];
				foreach ( $elements as $entity => $unused ) {
					foreach ( isset( $backups[ $timestamp ][ $entity ] ) ? (array) $backups[ $timestamp ][ $entity ] : array() as $file ) {
						$path = $backupplus_dir . '/' . basename( $file );
						if ( BackupPlus_Encryption::is_file_encrypted( $path ) ) {
							$prepared = BackupPlus_Age_Restore::file( $path, $age_ticket, '', $timestamp );
							if ( ! $prepared ) { continue; }
							$path = $prepared['fullpath'];
						}
						$backups[ $timestamp ] = BackupPlus_Backup_Sources::archive_metadata( $backups[ $timestamp ], $entity, $path );
					}
				}
				if ( $before_metadata !== $backups[ $timestamp ] ) { BackupPlus_Backup_History::save_history( $backups ); }
			}
			if ( isset( $elements['db'] ) ) {
				// Analyse the header of the database file + display results
				if ( ! $err ) {
					list ($mess2, $warn2, $err2, $info) = $this->_backupplus->analyse_db_file( $timestamp, $res );
				} else {
					$mess2 = $warn2 = $err2 = array();
					$info  = array(); }
				$mess = array_merge( $mess, $mess2 );
				$warn = array_merge( $warn, $warn2 );
				$err  = array_merge( $err, $err2 );

			}

			foreach ( isset( $backups[ $timestamp ]['file_sources'] ) ? $backups[ $timestamp ]['file_sources'] : array() as $id => $source ) {
				$info['source_defaults'][ $id ] = $source['path'];
				if ( empty( $res['backupplus_source_destinations'][ $id ] ) ) { $res['backupplus_source_destinations'][ $id ] = $source['path']; }
			}
			$backupable_entities = BackupPlus_Backup_Sources::restore_entities( $this->_backupplus->get_backupable_file_entities( true, true ), $backups[ $timestamp ] );
			try {
				BackupPlus_Source_Restore::preflight( $elements, $backups[ $timestamp ], $res );
				foreach ( $elements as $id => $unused ) {
					if ( BackupPlus_Backup_Sources::custom( $id ) ) { $mess[] = esc_html( 'Merge additional source into ' . $res['backupplus_source_destinations'][ $id ] ); }
					elseif ( preg_match( '/^db[1-9][0-9]*$/D', $id ) ) { $target = BackupPlus_Source_Restore::connection( $id, $res );
						$mess[] = esc_html( $id . ': replace tables present in the dump in ' . $target['host'] . '/' . $target['name'] . '; other tables remain untouched.' ); }
					elseif ( 'wpcore' === $id ) { $mess[] = empty( $res['backupplus_restorer_wpcore_includewpconfig'] ) ? esc_html__( 'The current wp-config.php will be preserved.', 'backupplus' ) : esc_html__( 'The current wp-config.php will be replaced.', 'backupplus' ); }
				}
			} catch ( Exception $e ) { $err[] = esc_html( $e->getMessage() ); }
			$backupable_plus_db       = $backupable_entities;
			$backupable_plus_db['db'] = array(
				'path'        => 'path-unused',
				'description' => __( 'Database', 'backupplus' ),
			);

			if ( ! empty( $backups[ $timestamp ]['meta_foreign'] ) ) {
				$foreign_known = apply_filters( 'backupplus_accept_archivename', array() );
				if ( ! is_array( $foreign_known ) || empty( $foreign_known[ $backups[ $timestamp ]['meta_foreign'] ] ) ) {
					/* translators: %s: Backup source information */
					$err[] = sprintf( __( 'Backup created by unknown source (%s) - cannot be restored.', 'backupplus' ), $backups[ $timestamp ]['meta_foreign'] );
				} else {
					// For some reason, on PHP 5.5 passing by reference in a single array stopped working with apply_filters_ref_array (though not with do_action_ref_array).
					$backupable_plus_db = apply_filters_ref_array( 'backupplus_importforeign_backupable_plus_db', array( $backupable_plus_db, array( $foreign_known[ $backups[ $timestamp ]['meta_foreign'] ], &$mess, &$warn, &$err ) ) );
				}
			}

			foreach ( $backupable_plus_db as $type => $entity_info ) {
				if ( ! isset( $elements[ $type ] ) || ( isset( $entity_info['restorable'] ) && ! $entity_info['restorable'] ) ) {
					continue;
				}
				$whatwegot = $backups[ $timestamp ][ $type ];
				if ( is_string( $whatwegot ) ) {
					$whatwegot = array( $whatwegot );
				}
				$expected_index = 0;
				$missing        = '';
				ksort( $whatwegot );
				$outof = false;
				foreach ( $whatwegot as $index => $file ) {
					if ( preg_match( '/\d+of(\d+)\.zip/', $file, $omatch ) ) {
						$outof = max( $omatch[1], 1 );
					}
					while ( $expected_index < $index ) {
						$missing .= ( '' == $missing ) ? ( 1 + $expected_index ) : ',' . ( 1 + $expected_index );
						++$expected_index;
					}
					if ( ! file_exists( $backupplus_dir . '/' . $file ) ) {
						/* translators: %s: File path */
						$err[] = sprintf( __( 'File not found (you need to upload it): %s', 'backupplus' ), $backupplus_dir . '/' . $file );
					} elseif ( filesize( $backupplus_dir . '/' . $file ) == 0 ) {
						/* translators: %s: File name */
						$err[] = sprintf( __( 'File was found, but is zero-sized (you need to re-upload it): %s', 'backupplus' ), $file );
					} else {
						$itext = ( 0 == $index ) ? '' : $index;
						if ( ! empty( $backups[ $timestamp ][ $type . $itext . '-size' ] ) && filesize( $backupplus_dir . '/' . $file ) != $backups[ $timestamp ][ $type . $itext . '-size' ] ) {
							if ( empty( $warn['doublecompressfixed'] ) ) {
								/* translators: 1: File name, 2: Actual file size, 3: Expected file size */
								$warn[] = sprintf( __( 'File (%1$s) was found, but has a different size (%2$s) from what was expected (%3$s) - it may be corrupt.', 'backupplus' ), $file, filesize( $backupplus_dir . '/' . $file ), $backups[ $timestamp ][ $type . $itext . '-size' ] );
							}
						}
						$check_path = $backupplus_dir . '/' . $file;
						if ( 'db' !== $type && BackupPlus_Encryption::is_file_encrypted( $file ) ) {
							$prepared = BackupPlus_Age_Restore::file( $check_path, $age_ticket, '', $timestamp );
							if ( ! $prepared ) {
								++$expected_index; // Preparation already reports the error; don't also report this archive as missing.
								continue;
							}
							$check_path = $prepared['fullpath'];
						}
						do_action_ref_array( "backupplus_checkzip_$type", array( $check_path, &$mess, &$warn, &$err ) );
					}
					++$expected_index;
				}
				do_action_ref_array( "backupplus_checkzip_end_$type", array( &$mess, &$warn, &$err ) );
				// Detect missing archives where they are missing from the end of the set
				if ( $outof > 0 && $expected_index < $outof ) {
					for ( $j = $expected_index; $j < $outof; $j++ ) {
						$missing .= ( '' == $missing ) ? ( 1 + $j ) : ',' . ( 1 + $j );
					}
				}
				if ( '' != $missing ) {
					/* translators: %s: List of missing archives with description */
					$warn[] = sprintf( __( 'This multi-archive backup set appears to have the following archives missing: %s', 'backupplus' ), $missing . ' (' . $entity_info['description'] . ')' );
				}
			}

			// Check this backup set has a incremental_sets array e.g may have been created before this array was introduced; the restore point is normally already chosen in the first step
			if ( isset( $backups[ $timestamp ]['incremental_sets'] ) && ! isset( $res['backupplus_incremental_restore_point'] ) ) {
				if ( isset( $elements['db'] ) && 1 === count( $elements ) ) {
					// Don't show the incremental dropdown if the user only selects 'database'
				} else {
					$incremental_sets = array_keys( $backups[ $timestamp ]['incremental_sets'] );
					// Check if there are more than one timestamp in the incremental set
					if ( 1 < count( $incremental_sets ) ) {
						$incremental_select_html  = '<div class="udp-notice backupplus-restore-option"><label>' . __( 'This backup set contains incremental backups of your files; please select the time you wish to restore your files to', 'backupplus' ) . ': </label>';
						$incremental_select_html .= '<select name="backupplus_incremental_restore_point" id="backupplus_incremental_restore_point">';
						$incremental_sets         = array_reverse( $incremental_sets );
						$first_timestamp          = $incremental_sets[0];

						foreach ( $incremental_sets as $set_timestamp ) {
							$pretty_date              = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $set_timestamp ), 'M d, Y G:i' );
							$esc_pretty_date          = esc_attr( $pretty_date );
							$incremental_select_html .= '<option value="' . $set_timestamp . '" ' . selected( $set_timestamp, $first_timestamp, false ) . '>' . $esc_pretty_date . '</option>';
						}

						$incremental_select_html .= '</select>';
						$incremental_select_html .= '</div>';
						$info['addui']            = empty( $info['addui'] ) ? $incremental_select_html : $info['addui'] . '<br>' . $incremental_select_html;
					}
				}
			}

			if ( 0 == count( $err ) && 0 == count( $warn ) ) {
				$mess_first = __( 'The backup archive files have been successfully processed.', 'backupplus' ) . ' ' . __( 'Now press Restore to proceed.', 'backupplus' );
			} elseif ( 0 == count( $err ) ) {
				$mess_first = __( 'The backup archive files have been processed, but with some warnings.', 'backupplus' ) . ' ' . __( 'If all is well, then press Restore to proceed.', 'backupplus' ) . ' ' . __( 'Otherwise, cancel and correct any problems first.', 'backupplus' );
			} else {
				$mess_first = __( 'The backup archive files have been processed, but with some errors.', 'backupplus' ) . ' ' . __( 'You will need to cancel and correct any problems before retrying.', 'backupplus' );
			}

			if ( count( $this->_backupplus_admin->logged ) > 0 ) {
				foreach ( $this->_backupplus_admin->logged as $lwarn ) {
					$warn[] = $lwarn;
				}
			}
			restore_error_handler();

			// Get the info if it hasn't already come from the DB scan
			if ( ! isset( $info ) || ! is_array( $info ) ) {
				$info = array();
			}
			if ( '' !== $age_ticket ) {
				$info['age_ticket'] = $age_ticket;
			}

			// Not all characters can be json-encoded, and we don't need this potentially-arbitrary user-supplied info.
			unset( $info['label'] );

			if ( ! isset( $info['created_by_version'] ) && ! empty( $backups[ $timestamp ]['created_by_version'] ) ) {
				$info['created_by_version'] = $backups[ $timestamp ]['created_by_version'];
			}

			if ( ! isset( $info['multisite'] ) && ! empty( $backups[ $timestamp ]['is_multisite'] ) ) {
				$info['multisite'] = $backups[ $timestamp ]['is_multisite'];
			}

			do_action_ref_array( 'backupplus_restore_all_downloaded_postscan', array( $backups, $timestamp, $elements, &$info, &$mess, &$warn, &$err ) );

			if ( 0 == count( $err ) && 0 == count( $warn ) ) {
				$mess_first = __( 'The backup archive files have been successfully processed.', 'backupplus' ) . ' ' . __( 'Now press Restore again to proceed.', 'backupplus' );
			} elseif ( 0 == count( $err ) ) {
				$mess_first = __( 'The backup archive files have been processed, but with some warnings.', 'backupplus' ) . ' ' . __( 'If all is well, then now press Restore again to proceed.', 'backupplus' ) . ' ' . __( 'Otherwise, cancel and correct any problems first.', 'backupplus' );
			} else {
				$mess_first = __( 'The backup archive files have been processed, but with some errors.', 'backupplus' ) . ' ' . __( 'You will need to cancel and correct any problems before retrying.', 'backupplus' );
			}

			$warn_result = '';
			foreach ( $warn as $warning ) {
				if ( ! $warn_result ) {
					$warn_result = '<ul id="backupplus_restore_warnings">';
				}
				$warn_result .= '<li>' . $warning . '</li>';
			}
			if ( $warn_result ) {
				$warn_result .= '</ul>';
			}

			return array(
				'm' => '<p>' . $mess_first . '</p>' . implode( '<br>', $mess ),
				'w' => $warn_result,
				'e' => implode( '<br>', $err ),
				'i' => json_encode( $info ),
			);
		}
	}

	/**
	 * The purpose of this is to detect brokenness caused by extra line feeds in plugins/themes - before it breaks other AJAX operations and leads to support requests
	 *
	 * @return string
	 */
	public function ping() {
		return 'pong';
	}

	/**
	 * This function is called via ajax and will update the autobackup notice dismiss time
	 *
	 * @return array - an empty array
	 */
	public function dismissautobackup() {
		BackupPlus_Options::update_backupplus_option( 'backupplus_dismissedautobackup', time() + 84 * 86400 );
		return array();
	}

	/**
	 * This function is called via ajax and will update the general notice dismiss time
	 *
	 * @return array - an empty array
	 */
	public function dismiss_notice() {
		BackupPlus_Options::update_backupplus_option( 'dismissed_general_notices_until', time() + 84 * 86400 );
		return array();
	}

	/**
	 * This function is called via ajax and will update the review notice dismiss time
	 *
	 * @param array $data - an array that contains the dismiss notice for time
	 *
	 * @return array - an empty array
	 */
	public function dismiss_review_notice( $data ) {
		if ( empty( $data['dismiss_forever'] ) ) {
			BackupPlus_Options::update_backupplus_option( 'dismissed_review_notice', time() + 84 * 86400 );
		} else {
			BackupPlus_Options::update_backupplus_option( 'dismissed_review_notice', 100 * ( 365.25 * 86400 ) );
		}
		return array();
	}

	/**
	 * This function is called via ajax and will update the season notice dismiss time
	 *
	 * @return array - an empty array
	 */
	public function dismiss_season() {
		BackupPlus_Options::update_backupplus_option( 'dismissed_season_notices_until', time() + 366 * 86400 );
		return array();
	}

	/**
	 * Update and set dismiss_phpseclib_notice option name to the minimum recommended PHP version
	 *
	 * @return array - an associative array containing a key named 'success' with 1 value which indicates the successful of updating the option
	 */
	public function dismiss_phpseclib_notice() {
		// Store the minimum recommended PHP version dismissed for (previously was boolean `true`), so a later version bump re-shows the notice.
		BackupPlus_Options::update_backupplus_option( 'backupplus_dismiss_phpseclib_notice', BACKUPPLUS_PHPSECLIB_MIN_PHP_VERSION );
		return array( 'success' => 1 );
	}

	public function set_autobackup_default( $params ) {
		$default = empty( $params['default'] ) ? 0 : 1;
		BackupPlus_Options::update_backupplus_option( 'backupplus_autobackup_default', $default );
		return array();
	}

	public function dismissexpiry() {
		BackupPlus_Options::update_backupplus_option( 'backupplus_dismissedexpiry', time() + 14 * 86400 );
		return array();
	}

	public function dismissdashnotice() {
		BackupPlus_Options::update_backupplus_option( 'backupplus_dismisseddashnotice', time() + 366 * 86400 );
		return array();
	}

	/**
	 * Dismiss the Azure Legacy Storage notice.
	 *
	 * This function updates the option to indicate that the user has dismissed
	 * the Azure legacy storage notice, preventing it from being shown again.
	 *
	 * @return array Associative array with a 'success' key indicating the operation status.
	 */
	public function dismiss_azure_legacy_storage_notice() {
		BackupPlus_Options::update_backupplus_option( 'backupplus_dismiss_azure_legacy_storage_notice', 1 );
		return array( 'success' => 1 );
	}

	public function rawbackuphistory() {
		// This is used for iframe source; hence, returns a string
		$show_raw_data = $this->_backupplus_admin->show_raw_backups();
		return $show_raw_data['html'];
	}

	/**
	 * N.B. This differs from the phpinfo output in WordPress core.
	 * Returns a string, as it is directly fetched as the source of an iframe
	 *
	 * @return String - returns the resulting HTML
	 */
	public function phpinfo() {

		ob_start();

		if ( function_exists( 'phpinfo' ) ) {
			phpinfo( INFO_ALL ^ ( INFO_CREDITS | INFO_LICENSE ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_phpinfo -- we call the phpinfo() function to display PHP information in the advanced tools.
		}
		echo '<h3 id="ud-debuginfo-constants">' . esc_html__( 'Constants', 'backupplus' ) . '</h3>';
		$opts = @get_defined_constants();// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		ksort( $opts );
		echo '<table><thead></thead><tbody>';
		foreach ( $opts as $key => $opt ) {
			// Administrators can already read these in other ways, but we err on the side of caution
			if ( is_string( $opt ) && false !== stripos( $opt, 'api_key' ) ) {
				$opt = '***';
			}
			echo '<tr><td>' . esc_html( $key ) . '</td><td>' . esc_html( print_r( $opt, true ) ) . '</td>';
		}
		echo '</tbody></table>';

		$ret = ob_get_contents();
		ob_end_clean();

		return $ret;
	}

	/**
	 * Return messages if there are more than 4 overdue cron jobs
	 *
	 * @return Array - the messages are stored in an associative array and are indexed with key 'm'
	 */
	public function check_overdue_crons() {
		$messages         = array();
		$how_many_overdue = $this->_backupplus_admin->howmany_overdue_crons();
		if ( $how_many_overdue >= 4 ) {
			$messages['m']   = array();
			$messages['m'][] = $this->_backupplus_admin->show_admin_warning_overdue_crons( $how_many_overdue );
			if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON && ( ! defined( 'BACKUPPLUS_DISABLE_WP_CRON_NOTICE' ) || ! BACKUPPLUS_DISABLE_WP_CRON_NOTICE ) ) {
				$messages['m'][] = $this->_backupplus_admin->show_admin_warning_disabledcron();
			}
		}
		return $messages;
	}

	public function whichdownloadsneeded( $params ) {
		// The purpose of this is to look at the list of indicated downloads, and indicate which are not already fully downloaded. i.e. Which need further action.
		$send_back           = array();
		$backup              = BackupPlus_Backup_History::get_history( $params['timestamp'] );
		$backupplus_dir      = $this->_backupplus->backups_dir_location();
		$backupable_entities = BackupPlus_Backup_Sources::restore_entities( $this->_backupplus->get_backupable_file_entities(), $backup );

		if ( empty( $backup ) ) {
			return array( 'result' => 'asyouwere' );
		}

		foreach ( $params['downloads'] as $i => $download ) {
			if ( is_array( $download ) && 2 == count( $download ) && isset( $download[0] ) && isset( $download[1] ) ) {
				$entity = $download[0];
				if ( ( 'db' == $entity || isset( $backupable_entities[ $entity ] ) ) && isset( $backup[ $entity ] ) ) {
					$indexes       = explode( ',', $download[1] );
					$retain_string = '';
					foreach ( $indexes as $index ) {
						$retain = true; // default
						$findex = ( 0 == $index ) ? '' : (string) $index;
						$files  = $backup[ $entity ];
						if ( ! is_array( $files ) ) {
							$files = array( $files );
						}
						$size_key = $entity . $findex . '-size';
						if ( isset( $files[ $index ] ) && isset( $backup[ $size_key ] ) ) {
							$file = $backupplus_dir . '/' . $files[ $index ];
							if ( file_exists( $file ) && filesize( $file ) >= $backup[ $size_key ] ) {
								$retain = false;
							}
						}
						if ( $retain ) {
							$retain_string     .= ( '' === $retain_string ) ? $index : ',' . $index;
							$send_back[ $i ][0] = $entity;
							$send_back[ $i ][1] = $retain_string;
						}
					}
				} else {
					$send_back[ $i ][0] = $entity;
					$send_back[ $i ][1] = $download[ $i ][1];
				}
			} else {
				// Format not understood. Just send it back as-is.
				$send_back[ $i ] = $download[ $i ];
			}
		}
		// Finally, renumber the keys (to usual PHP style - 0, 1, ...). Otherwise, in order to preserve the indexes, json_encode() will create an object instead of an array in the case where $send_back only has one element (and is indexed with an index > 0)
		$send_back = array_values( $send_back );
		return array( 'downloads' => $send_back );
	}

	/**
	 * This is an handler function that checks what entity has been specified in the $params and calls the required method
	 *
	 * @param  [array] $params this is an array of parameters sent via ajax it can include various things depending on what has called this method, this method only cares about the entity parameter which is used to call the correct method and return tree nodes based on that
	 * @return [array] returns an array of jstree nodes
	 */
	public function get_jstree_directory_nodes( $params ) {

		if ( 'filebrowser' == $params['entity'] ) {
			$node_array = $this->_backupplus_jstree_directory( $params );
		} elseif ( 'zipbrowser' == $params['entity'] ) {
			$node_array = $this->_backupplus_jstree_zip( $params );
		} else {
			$node_array = apply_filters( 'backupplus_jstree_' . $params['entity'], array(), $params );
		}
		return empty( $node_array['error'] ) ? array( 'nodes' => $node_array ) : $node_array;
	}

	/**
	 * This creates an array of nodes, built from either ABSPATH or the given directory ready to be returned to the jstree object.
	 *
	 * @param  [array] $params this is an array of parameters sent via ajax it can include the following:
	 * node - this is a jstree node object containing information about the selected node
	 * path - this is a path if provided this will be used to build the tree otherwise ABSPATH is used
	 * drop_directory - this is a boolean that if set to true will drop one directory level off the path this is used so that you can move above the current root directory
	 * @return [array] returns an array of jstree nodes
	 */
	private function _backupplus_jstree_directory( $params ) {
		$node_array = array();

		// # is the root node if it's the root node then this is the first call so create a parent node otherwise it's a child node and we should get the path from the node id
		if ( '#' == $params['node']['id'] ) {
			$path = ABSPATH;

			if ( ! empty( $params['path'] ) && is_dir( $params['path'] ) && is_readable( $params['path'] ) ) {
				$path = $params['path'];
			}
			$one_dir_up = dirname( $path );

			if ( ! empty( $params['drop_directory'] ) && true == $params['drop_directory'] && is_readable( $one_dir_up ) ) {
				$path = $one_dir_up;
			}
			if ( empty( $params['skip_root_node'] ) ) {
				$node_array[] = array(
					'text'     => basename( $path ),
					'children' => true,
					'id'       => $path,
					'icon'     => 'jstree-folder',
					'state'    => array(
						'opened' => true,
					),
				);
			}
		} else {
			$path = $params['node']['id'];
		}

		$page = empty( $params['page'] ) ? '' : $params['page'];

		if ( $dh = opendir( $path ) ) {
			$path = rtrim( $path, DIRECTORY_SEPARATOR );

			$skip_paths = array( '.', '..' );

			while ( ( $value = readdir( $dh ) ) !== false ) {
				if ( ! in_array( $value, $skip_paths ) ) {
					if ( is_dir( $path . DIRECTORY_SEPARATOR . $value ) ) {
						$node_array[] = array(
							'text'     => $value,
							'children' => true,
							'id'       => BackupPlus_Manipulation_Functions::wp_normalize_path( $path . DIRECTORY_SEPARATOR . $value ),
							'icon'     => 'jstree-folder',
						);
					} elseif ( empty( $params['directories_only'] ) && 'restore' != $page && is_file( $path . DIRECTORY_SEPARATOR . $value ) ) {
						$node_array[] = array(
							'text'     => $value,
							'children' => false,
							'id'       => BackupPlus_Manipulation_Functions::wp_normalize_path( $path . DIRECTORY_SEPARATOR . $value ),
							'type'     => 'file',
							'icon'     => 'jstree-file',
						);
					}
				}
			}
		} else {
			/* translators: %s: Directory path */
			$node_array['error'] = sprintf( __( 'Failed to open directory: %s.', 'backupplus' ), $path ) . ' ' .
			__( 'This is normally caused by file permissions.', 'backupplus' );
		}

		return $node_array;
	}

	/**
	 * This creates an array of nodes, built from a unzipped zip file structure.
	 *
	 * @param  [array] $params this is an array of parameters sent via ajax it can include the following:
	 * node - this is a jstree node object containing information about the selected node
	 * timestamp - this is the backup timestamp and is used to get the backup archive
	 * type - this is the type of backup and is used to get the backup archive
	 * findex - this is the index used to get the correct backup archive if theres more than one of a single archive type
	 * @return [array] returns an array of jstree nodes
	 */
	private function _backupplus_jstree_zip( $params ) {

		$backupplus = $this->_backupplus;

		$node_array = array();

		$zip_object = $backupplus->get_zip_object_name();

		// Retrieve the information from our backup history
		$backup_history = BackupPlus_Backup_History::get_history();

		if ( ! isset( $backup_history[ $params['timestamp'] ][ $params['type'] ] ) ) {
			return array( 'error' => __( 'Backup set not found', 'backupplus' ) );
		}

		// Base name
		$file = $backup_history[ $params['timestamp'] ][ $params['type'] ];

		// Get date in human readable form
		$pretty_date = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $params['timestamp'] ), 'M d, Y G:i' );

		$backupable_entities = $backupplus->get_backupable_file_entities( true, true );

		// Check the file type and set the name in a more friendly way
		$archive_name = isset( $backupable_entities[ $params['type'] ]['description'] ) ? $backupable_entities[ $params['type'] ]['description'] : $params['type'];

		if ( substr( $params['type'], 0, 2 ) === 'db' ) {
			$archive_name = __( 'Extra database', 'backupplus' ) . ' ' . substr( $params['type'], 3, 1 );
		}
		if ( 'db' == $params['type'] ) {
			$archive_name = __( 'Database', 'backupplus' );
		}
		if ( 'more' == $params['type'] ) {
			$archive_name = $backupable_entities[ $params['type'] ]['shortdescription'];
		}
		if ( 'wpcore' == $params['type'] ) {
			$archive_name = __( 'WordPress Core', 'backupplus' );
		}

		$archive_set = ( $params['findex'] + 1 ) . '/' . sizeof( $file );

		if ( '1/1' == $archive_set ) {
			$archive_set = '';
		}

		$parent_name = $archive_name . ' ' . __( 'archive', 'backupplus' ) . ' ' . $archive_set . ' ' . $pretty_date;

		// Deal with multi-archive sets
		if ( is_array( $file ) ) {
			$file = $file[ $params['findex'] ];
		}

		// Where it should end up being downloaded to
		$fullpath = $backupplus->backups_dir_location() . '/' . $file;

		if ( file_exists( $fullpath ) && is_readable( $fullpath ) && filesize( $fullpath ) > 0 ) {

			$node_array[] = array(
				'text'    => $parent_name,
				'parent'  => '#',
				'id'      => $parent_name,
				'icon'    => 'jstree-folder',
				'state'   => array( 'opened' => true ),
				'li_attr' => array( 'path' => $parent_name ),
			);

			$zip = new $zip_object();

			$zip_opened = $zip->open( $fullpath );

			if ( true !== $zip_opened ) {
				return array( 'error' => 'BackupPlus: opening zip (' . $fullpath . '): failed to open this zip file (object=' . $zip_object . ', code: ' . $zip_opened . ')' );
			} else {

				$numfiles = $zip->numFiles;

				if ( false === $numfiles ) {
					return array( 'error' => 'BackupPlus: reading zip: ' . $zip->last_error );
				}

				for ( $i = 0; $i < $numfiles; $i++ ) {
					$si = $zip->statIndex( $i );

					// Fix for windows being unable to build jstree due to different directory separators being used
					$si['name'] = str_replace( '/', DIRECTORY_SEPARATOR, $si['name'] );

					// if it's a dot then we don't want to append this as it will break the ids and the tree structure
					if ( '.' == dirname( $si['name'] ) ) {
						$node_id = $parent_name;
					} else {
						$node_id = $parent_name . DIRECTORY_SEPARATOR . dirname( $si['name'] ) . DIRECTORY_SEPARATOR;
					}

					$extension = substr( strrchr( $si['name'], '.' ), 1 );

					if ( 0 == $si['size'] && empty( $extension ) ) {
						$node_array[] = array(
							'text'    => basename( $si['name'] ),
							'parent'  => $node_id,
							'id'      => $parent_name . DIRECTORY_SEPARATOR . $si['name'],
							'icon'    => 'jstree-folder',
							'li_attr' => array(
								'path' => $parent_name . DIRECTORY_SEPARATOR . $si['name'],
							),
						);
					} else {
						$node_array[] = array(
							'text'    => basename( $si['name'] ),
							'parent'  => $node_id,
							'id'      => $parent_name . DIRECTORY_SEPARATOR . $si['name'],
							'type'    => 'file',
							'icon'    => 'jstree-file',
							'li_attr' => array(
								'path' => $parent_name . DIRECTORY_SEPARATOR . $si['name'],
								'size' => BackupPlus_Manipulation_Functions::convert_numeric_size_to_text( $si['size'] ),
							),
						);
					}
				}

				// check if this is an upload archive if it is add a 'uploads' folder so that the children can attach to it
				if ( 'uploads' == $params['type'] ) {
					$node_array[] = array(
						'text'    => 'uploads',
						'parent'  => $parent_name,
						'id'      => $parent_name . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR,
						'icon'    => 'jstree-folder',
						'li_attr' => array(
							'path' => $parent_name . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR,
						),
					);
				}

				@$zip->close();// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.
			}
		}

		return $node_array;
	}

	/**
	 * Return information on the zipfile download
	 *
	 * @param Array $params - details on the download; keys: type, findex, path, timestamp
	 *
	 * @return Array
	 */
	public function get_zipfile_download( $params ) {
		return apply_filters( 'backupplus_command_get_zipfile_download', array( 'error' => 'BackupPlus: command (get_zipfile_download) not installed (are you missing an add-on?)' ), $params );
	}

	/**
	 * Dismiss the notice  which will if .htaccess have any old migrated site reference.
	 *
	 * @return Boolean Return true if migration notice is dismissed
	 */
	public function dismiss_migration_notice_for_old_site_reference() {
		delete_site_option( 'backupplus_migrated_site_domain' );
		return true;
	}

	/**
	 * When character set and collate both are unsupported at restoration time and if user change anyone substitution dropdown from both, Other substitution select box value should be change respectively. To achieve this functionality, Ajax calls comes here.
	 *
	 * @param  Array $params this is an array of parameters sent via ajax it can include the following:
	 * collate_change_on_charset_selection_data - It is data in serialize form which is need for choose other dropdown option value. It contains below elements data:
	 *  db_supported_collations - All collations supported by current database. This is result of 'SHOW COLLATION' query
	 *  db_unsupported_collate_unique - Unsupported collates unique array
	 *  db_collates_found - All collates found in database backup file
	 * event_source_elem - Dropdown elemtn id which trigger the ajax request
	 * backupplus_restorer_charset - Charset dropdown selected value option
	 * backupplus_restorer_collate - Collate dropdown selected value option
	 *
	 * @return array - $action_data which contains following data:
	 * is_action_required - 1 or 0 Whether or not change other dropdown value
	 * elem_id - Dropdown element id which value need to change. The other dropdown element id
	 * elem_val - Dropdown element value which should be selected for other drodown
	 */
	public function collate_change_on_charset_selection( $params ) {
		$collate_change_on_charset_selection_data = json_decode( BackupPlus_Manipulation_Functions::wp_unslash( $params['collate_change_on_charset_selection_data'] ), true );
		$backupplus_restorer_collate              = $params['backupplus_restorer_collate'];
		$backupplus_restorer_charset              = $params['backupplus_restorer_charset'];

		$db_supported_collations       = $collate_change_on_charset_selection_data['db_supported_collations'];
		$db_unsupported_collate_unique = $collate_change_on_charset_selection_data['db_unsupported_collate_unique'];
		$db_collates_found             = $collate_change_on_charset_selection_data['db_collates_found'];

		$action_data = array(
			'is_action_required' => 0,
		);
		// No need to change other dropdown value
		if ( isset( $db_supported_collations[ $backupplus_restorer_collate ]->Charset ) && $backupplus_restorer_charset == $db_supported_collations[ $backupplus_restorer_collate ]->Charset ) {
			return $action_data;
		}
		$similar_type_collate = $this->_backupplus->get_similar_collate_related_to_charset( $db_supported_collations, $db_unsupported_collate_unique, $backupplus_restorer_charset );
		if ( empty( $similar_type_collate ) ) {
			$similar_type_collate = $this->_backupplus->get_similar_collate_based_on_ocuurence_count( $db_collates_found, $db_supported_collations, $backupplus_restorer_collate );
		}
		// Default collation for changed character set
		if ( empty( $similar_type_collate ) ) {
			$charset_row = BackupPlus_SQL::wpdb()->row( 'SELECT DEFAULT_COLLATE_NAME AS `Default collation` FROM information_schema.CHARACTER_SETS WHERE CHARACTER_SET_NAME = ?', array(), array( $backupplus_restorer_charset ) );
			if ( is_object( $charset_row ) && ! empty( $charset_row->{'Default collation'} ) ) {
				$similar_type_collate = $charset_row->{'Default collation'};
			}
		}
		if ( empty( $similar_type_collate ) ) {
			foreach ( $db_supported_collations as $db_supported_collation => $db_supported_collation_info ) {
				if ( isset( $db_supported_collation_info->Charset ) && $backupplus_restorer_charset == $db_supported_collation_info->Charset ) {
					$similar_type_collate = $db_supported_collation;
					break;
				}
			}
		}
		if ( ! empty( $similar_type_collate ) ) {
			$action_data['is_action_required']   = 1;
			$action_data['similar_type_collate'] = $similar_type_collate;
		}
		return $action_data;
	}

	/**
	 * Set the Tour status
	 *
	 * @param array $params - the $_REQUEST. We're looking for 'current_step'
	 * @return bool
	 */
	public function set_tour_status( $params ) {
		return class_exists( 'BackupPlus_Tour' ) ? BackupPlus_Tour::get_instance()->set_tour_status( $params ) : false;
	}

	/**
	 * Resets the tour status
	 *
	 * @return bool
	 */
	public function reset_tour_status() {
		return class_exists( 'BackupPlus_Tour' ) ? BackupPlus_Tour::get_instance()->reset_tour_status() : false;
	}

	/**
	 * Return the database information
	 *
	 * @param bool $raw_data_only If true, returns array of data; if false, returns HTML
	 * @return array If $raw_data_only is true, returns array with 'tables' and 'size'. If false, returns array with 'size' and 'html'
	 */
	public function db_size( $raw_data_only = false ) {
		global $wpdb;

		$db_table_res  = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
		$db_table_size = 0;
		$db_size_info  = array();

		if ( $wpdb->num_rows > 0 ) {
			$sql = BackupPlus_SQL::wpdb();

			foreach ( $db_table_res as $row ) {
				// Try search from transient
				$rows_count = get_transient( 'wpo_' . $row['Name'] . '_count' );
				if ( false === $rows_count ) {
					// If not found, try search primary key first
					$primary_key = $sql->row( "SHOW COLUMNS FROM %i WHERE `Key` = 'PRI'", array( $row['Name'] ), array(), ARRAY_A );
					if ( $primary_key ) {
						// Count rows by primary key
						$rows_count = $sql->value( 'SELECT COUNT(%i) FROM %i', array( $primary_key['Field'], $row['Name'] ) );
					}

					if ( is_null( $rows_count ) || false === $rows_count ) {
						$rows_count = $sql->value( 'SELECT COUNT(*) FROM %i', array( $row['Name'] ) );
						// No count is reported as null, as before
						$rows_count = false === $rows_count ? null : $rows_count;
					}
				}

				$data_length  = isset( $row['Data_length'] ) ? (int) $row['Data_length'] : 0;
				$index_length = isset( $row['Index_length'] ) ? (int) $row['Index_length'] : 0;

				$db_size_info[] = array(
					'name'         => $row['Name'],
					'records'      => $rows_count,
					'data_length'  => $data_length,
					'index_length' => $index_length,
					'type'         => $row['Engine'],
				);

				$db_table_size += $data_length + $index_length;
			}
		}

		$total_size_formatted = size_format( (int) $db_table_size, 2 );

		// Return raw data if requested
		if ( $raw_data_only ) {
			return array(
				'tables' => $db_size_info,
				'size'   => $total_size_formatted,
			);
		}

		// Otherwise, construct HTML from the collected data
		$db_table_html = '';
		foreach ( $db_size_info as $table_info ) {
			$db_table_html .= '<tr>';
			$db_table_html .= sprintf( '<td>%s</td>', esc_html( $table_info['name'] ) );
			$db_table_html .= sprintf( '<td>%s</td>', esc_html( $table_info['records'] ) );
			$db_table_html .= sprintf( '<td>%s</td>', esc_html( $table_info['data_size'] ) );
			$db_table_html .= sprintf( '<td>%s</td>', esc_html( $table_info['index_size'] ) );
			$db_table_html .= sprintf( '<td>%s</td>', esc_html( $table_info['type'] ) );
			$db_table_html .= '</tr>';
		}

		return array(
			'size' => $total_size_formatted,
			'html' => $db_table_html,
		);
	}

	/**
	 * Retrieves the scheduled BackupPlus cron events.
	 *
	 * This function fetches all cron events and filters those related to BackupPlus.
	 * It then formats the schedule information, calculates the time difference,
	 * and returns a structured array of cron details.
	 *
	 * @return array[] An array of cron event details, where each event contains:
	 *                - `overdue` (int): Whether the event is overdue (1) or not (0).
	 *                - `hook` (string): The name of the cron hook.
	 *                - `name` (string): The display name of the schedule.
	 *                - `time` (string): The formatted execution time.
	 *                - `interval` (string): The time remaining or overdue duration.
	 */
	public function get_cron_events() {
		$data = array();

		$schedules = wp_get_schedules();
		$cron      = _get_cron_array();

		$date_format = get_option( 'date_format' );
		$time_format = get_option( 'time_format' );

		// Loop through the cron schedules
		foreach ( $cron as $timestamp => $cron_hooks ) {
			foreach ( $cron_hooks as $hook => $events ) {
				// Every BackupPlus hook shares this prefix, so new schedules (PITR capture, pre-update backups) are listed too.
				if ( 0 !== strpos( $hook, 'backupplus_' ) ) {
					continue;
				}

				sort( $events );

				$schedule      = $events[0]['schedule'];
				$schedule_name = isset( $schedules[ $schedule ] ) ? $schedules[ $schedule ]['display'] : __( 'Non-repeating', 'backupplus' );
				// wp_date() is available on WP 5.3+, it performs locale translation.
				$formatted_date = function_exists( 'wp_date' ) ? wp_date( $date_format . ' ' . $time_format, $timestamp ) : get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ), $date_format . ' ' . $time_format );

				$difference            = $timestamp - time();
				$difference_in_seconds = abs( $difference );
				$overdue               = $difference < 0 ? 1 : 0;

				$hours   = floor( $difference_in_seconds / 3600 );
				$minutes = floor( ( $difference_in_seconds % 3600 ) / 60 );

				if ( $overdue ) {
					/* translators: 1: Number of hours, 2: Number of minutes */
					$interval = sprintf( __( '%1$d hours and %2$d minutes ago', 'backupplus' ), $hours, $minutes );
				} else {
					/* translators: 1: Number of hours, 2: Number of minutes */
					$interval = sprintf( __( '%1$d hours and %2$d minutes', 'backupplus' ), $hours, $minutes );
				}

				$data[] = array(
					'overdue'  => $overdue,
					'hook'     => $hook,
					'name'     => $schedule_name,
					'time'     => $formatted_date,
					'interval' => $interval,
				);
			}
		}

		return $data;
	}
}
