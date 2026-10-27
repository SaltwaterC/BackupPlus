<?php

/** Restoring downloaded backup sets and working out which downloads a restore needs. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_WPAdmin_Commands_Restore {

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

			// List the plugin components actually present in the selected backup chain.
			if ( isset( $elements['plugins'] ) && empty( $backups[ $timestamp ]['meta_foreign'] ) && ! $err ) {
				require_once BACKUPPLUS_DIR . '/includes/class-restore-inventory.php';
				$plugin_files = (array) $backups[ $timestamp ]['plugins'];
				if ( isset( $res['backupplus_incremental_restore_point'] ) && (int) $res['backupplus_incremental_restore_point'] > 0 && ! empty( $backups[ $timestamp ]['incremental_sets'] ) ) {
					foreach ( $backups[ $timestamp ]['incremental_sets'] as $point => $components ) {
						if ( (int) $point > (int) $res['backupplus_incremental_restore_point'] && ! empty( $components['plugins'] ) ) {
							foreach ( $components['plugins'] as $index => $file ) { unset( $plugin_files[ $index ] ); }
						}
					}
				}
				$plugins = BackupPlus_Restore_Inventory::components( $plugin_files, $age_ticket, '', 'plugins', $timestamp );
				if ( is_wp_error( $plugins ) ) {
					$warn[] = esc_html__( 'The plugin list could not be read from this backup. The complete plugins component can still be restored.', 'backupplus' );
				} elseif ( $plugins ) {
					$plugin_ui  = '<div class="udp-notice below-h2 backupplus-restore-option"><p>' . esc_html__( 'Choose which plugins to restore.', 'backupplus' ) . ' (<a href="#" id="backupplus_restore_plugins_showmoreoptions">...</a>)</p>';
					$plugin_ui .= '<div class="backupplus_restore_plugins_options_container" style="display:none;">';
					$plugin_ui .= '<p><a href="#" class="backupplus_restore_select_all_plugins">' . esc_html__( 'Select All', 'backupplus' ) . '</a> | <a href="#" class="backupplus_restore_deselect_all_plugins">' . esc_html__( 'Deselect All', 'backupplus' ) . '</a></p>';
					foreach ( $plugins as $index => $plugin ) {
						$id         = 'backupplus_restore_plugin_' . $index;
						$plugin_ui .= '<input class="backupplus_restore_plugins_options" id="' . esc_attr( $id ) . '" checked="checked" type="checkbox" name="backupplus_restore_plugins_options[]" value="' . esc_attr( $plugin ) . '"> <label for="' . esc_attr( $id ) . '">' . esc_html( $plugin ) . '</label><br>';
					}
					$plugin_ui    .= '</div></div>';
					$info['addui'] = empty( $info['addui'] ) ? $plugin_ui : $info['addui'] . '<br>' . $plugin_ui;
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
}
