<?php

/** Rebuilding the backup history from the backups directory and, optionally, remote storage. */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct $wpdb query is required for this operation.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- some query operations need to always receive the most up-to-date or actual data directly from the database, reducing the risk of serving stale information.
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Backup_History_Rebuild {

	/**
	 * This function examines the BackupPlus directory to see if any new archives have been uploaded. If so, it adds them to the backup set. (Non-present items are also removed, only if the service is 'none').
	 *
	 * N.B. The logic is a bit more subtle than it needs to be, because of backups being keyed by backup time, instead of backup nonce, and the subsequent introduction of the possibility of incremental backup sets taken at different times. This could be cleaned up to reduce the amount of code and make it simpler in places.
	 *
	 * @param Boolean      $remote_scan        - scan not only local, but also remote storage
	 * @param Array|String $only_add_this_file - if set to an array (with keys 'file' and (optionally) 'label'), then a file will only be taken notice of if the filename matches the 'file' key (and the label will be associated with the backup set)
	 * @param Boolean      $debug              - include debugging messages. These will be keyed with keys beginning 'debug-' so that they can be distinguished.
	 *
	 * @return Array - an array of messages which the caller may wish to display to the user. N.B. Messages are not necessarily just strings, but may be.
	 */
	public static function rebuild( $remote_scan = false, $only_add_this_file = false, $debug = false ) {

		global $backupplus;

		$messages   = array();
		$gmt_offset = get_option( 'gmt_offset' );

		// Array of nonces keyed by filename
		$backup_nonces_by_filename = array();
		// Array of backup times keyed by nonce
		$backup_times_by_nonce = array();

		$changes = false;

		$backupable_entities = $backupplus->get_backupable_file_entities( true, false );

		$backup_history = self::get_history();

		$backupplus_dir = $backupplus->backups_dir_location();
		if ( ! is_dir( $backupplus_dir ) ) {
			return array( "Internal directory path does not indicate a directory ($backupplus_dir)" );
		}

		$accept = apply_filters( 'backupplus_accept_archivename', array() );

		// First, process the database backup history to get a record of what is already known there. This means populating the arrays $backup_nonces_by_filename and $backup_times_by_nonce
		foreach ( $backup_history as $btime => $bdata ) {
			// PITR inventory and deletion are managed by the chain index. Its archive names
			// do not follow the ordinary backup format checked below.
			if ( ! empty( $bdata['pitr'] ) ) {
				continue;
			}
			$found_file = false;
			foreach ( $bdata as $key => $values ) {
				// make sure we also handle multiple databases, which has a different array structure compared to other entities (e.g. plugins, themes, etc.)
				// we don't do strict comparison using identical operator (===) here because we want to catch boolean false or a non-boolean value which evaluates to false, hence we use equal operator (==)
				if ( false == preg_match( '/^db[0-9]*$/i', $key ) && ! BackupPlus_Backup_Sources::custom( $key ) && BackupPlus_Credential_Key_Store::BACKUP_ENTITY !== $key && ! isset( $backupable_entities[ $key ] ) ) {
					continue;
				}
				// Record which set this file is found in
				if ( ! is_array( $values ) ) {
					$values = array( $values );
				}
				foreach ( $values as $filename ) {
					if ( ! is_string( $filename ) ) {
						continue;
					}
					if ( preg_match( '/^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-[\-a-z]+([0-9]+)?+(\.(zip|zip\.crypt|gz|gz\.crypt))?$/i', $filename, $matches ) ) {
						$nonce = $matches[2];
						if ( isset( $bdata['service'] ) && ( 'none' === $bdata['service'] || ( is_array( $bdata['service'] ) && ( array( 'none' ) === $bdata['service'] || ( 1 == count( $bdata['service'] ) && isset( $bdata['service'][0] ) && empty( $bdata['service'][0] ) ) ) ) ) && ! is_file( $backupplus_dir . '/' . $filename ) ) {
							// File without remote storage is no longer locally present
						} else {
							$found_file                             = true;
							$backup_nonces_by_filename[ $filename ] = $nonce;
							if ( empty( $backup_times_by_nonce[ $nonce ] ) || $backup_times_by_nonce[ $nonce ] < 100 ) {
								$backup_times_by_nonce[ $nonce ] = $btime;
							} elseif ( $btime < $backup_times_by_nonce[ $nonce ] ) {
								$backup_times_by_nonce[ $nonce ] = $btime;
							}
						}
					} else {
						$accepted = false;
						foreach ( $accept as $fkey => $acc ) {
							if ( preg_match( '/' . $acc['pattern'] . '/i', $filename ) ) {
								$accepted = $fkey;
							}
						}
						if ( ! empty( $accepted ) && ( false != ( $btime = apply_filters( 'backupplus_foreign_gettime', false, $accepted, $filename ) ) ) && $btime > 0 ) {
							$found_file = true;
							// Generate a nonce; this needs to be deterministic and based on the filename only
							$nonce                                  = substr( md5( $filename ), 0, 12 );
							$backup_nonces_by_filename[ $filename ] = $nonce;
							if ( empty( $backup_times_by_nonce[ $nonce ] ) || $backup_times_by_nonce[ $nonce ] < 100 ) {
								$backup_times_by_nonce[ $nonce ] = $btime;
							} elseif ( $btime < $backup_times_by_nonce[ $nonce ] ) {
								$backup_times_by_nonce[ $nonce ] = $btime;
							}
						}
					}
				}
			}
			if ( ! $found_file ) {
				// File recorded as being without remote storage is no longer present. It may in fact exist in remote storage, and this will be picked up later (when we scan the remote storage).
				unset( $backup_history[ $btime ] );
				$changes = true;
			}
		}

		// Secondly, scan remote storage and get back lists of files and their sizes

		// $remote_files has a key for each filename (basename), and the value is a list of remote destinations (e.g. 'dropbox', 's3') in which the file was found
		$remote_files = array();

		// A list of nonces found remotely (to help with handling sets split across destinations)
		$remote_nonces_found = array();

		$remote_sizes = array();

		if ( $remote_scan ) {

			$backupplus->register_wp_http_option_hooks( true );

			$storage_objects_and_ids = BackupPlus_Storage_Methods_Interface::get_storage_objects_and_ids( array_keys( $backupplus->backup_methods ) );

			foreach ( $storage_objects_and_ids as $method => $method_information ) {

				$object = $method_information['object'];

				if ( ! method_exists( $object, 'listfiles' ) ) {
					continue;
				}

				// Support of multi_options is now required for storage methods that implement listfiles()
				if ( ! $object->supports_feature( 'multi_options' ) ) {
					BackupPlus_Manipulation_Functions::error_log( 'BackupPlus: Multi-options not supported by: ' . $method );
					continue;
				}

				foreach ( $method_information['instance_settings'] as $instance_id => $options ) {

					$object->set_options( $options, false, $instance_id );

					$files = $object->listfiles( 'backup_' );

					$method_description = $object->get_description();

					if ( is_array( $files ) ) {

						if ( $debug ) {
							$messages[] = array(
								'method'              => $method,
								'desc'                => $method_description,
								'code'                => 'file-listing',
								'message'             => '',
								'data'                => $files,
								'service_instance_id' => $instance_id,
							);
						}

						foreach ( $files as $entry ) {
							$filename = $entry['name'];
							if ( ! preg_match( '/^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-([\-a-z]+)([0-9]+)?(\.(zip|zip\.crypt|gz|gz\.crypt))?$/i', $filename, $matches ) ) {
								continue;
							}

							$nonce = $matches[2];
							$btime = strtotime( $matches[1] );
							// Of course, it's possible that the site doing the scanning has a different timezone from the site that the backups were created in, in which case, this calculation will have a confusing result to the user. That outcome cannot be completely eliminated (without making the filename to reflect UTC, which confuses more users).
							if ( ! empty( $gmt_offset ) ) {
								$btime -= $gmt_offset * 3600;
							}

							// Is the set already known?
							if ( isset( $backup_times_by_nonce[ $nonce ] ) ) {
								// N.B. With an incremental set, the newly found file may be earlier than the known elements, so tha the backup array should be re-keyed.
								$btime_exact = $backup_times_by_nonce[ $nonce ];
								if ( $btime > 100 && $btime_exact - $btime > 60 && ! empty( $backup_history[ $btime_exact ] ) ) {
									$changes                  = true;
									$backup_history[ $btime ] = $backup_history[ $btime_exact ];
									unset( $backup_history[ $btime_exact ] );
									$btime_exact                     = $btime;
									$backup_times_by_nonce[ $nonce ] = $btime;
								}
								$btime = $btime_exact;
							}
							if ( $btime <= 100 ) {
								continue;
							}

							// We need to set this so that if a second file is found in remote storage then the time will be picked up.
							$backup_times_by_nonce[ $nonce ] = $btime;

							if ( empty( $backup_history[ $btime ]['service_instance_ids'] ) || empty( $backup_history[ $btime ]['service_instance_ids'][ $method ] ) ) {
								$backup_history[ $btime ]['service_instance_ids'][ $method ] = array( $instance_id );
								$changes = true;
							} elseif ( ! in_array( $instance_id, $backup_history[ $btime ]['service_instance_ids'][ $method ] ) ) {
								$backup_history[ $btime ]['service_instance_ids'][ $method ][] = $instance_id;
								$changes = true;
							}

							if ( isset( $remote_files[ $filename ] ) ) {
								$remote_files[ $filename ][] = $method;
							} else {
								$remote_files[ $filename ] = array( $method );
							}

							if ( ! in_array( $nonce, $remote_nonces_found ) ) {
								$remote_nonces_found[] = $nonce;
							}

							if ( ! empty( $entry['size'] ) ) {
								if ( empty( $remote_sizes[ $filename ] ) || $remote_sizes[ $filename ] < $entry['size'] ) {
									$remote_sizes[ $filename ] = $entry['size'];
								}
							}
						}
					} elseif ( is_wp_error( $files ) ) {
						foreach ( $files->get_error_codes() as $code ) {
							// Skip various codes which are not conditions to show to the user
							if ( in_array( $code, array( 'no_settings', 'no_addon', 'insufficient_php', 'no_listing' ) ) ) {
								continue;
							}
							$messages[] = array(
								'method'              => $method,
								'desc'                => $method_description,
								'code'                => $code,
								'message'             => $files->get_error_message( $code ),
								'data'                => $files->get_error_data( $code ),
								'service_instance_id' => $instance_id,
							);
						}
					}
				}
			}
			$backupplus->register_wp_http_option_hooks( false );
		}

		// Thirdly, see if there are any more files in the local directory than the ones already known about (possibly subject to a limitation specified via $only_add_this_file)
		if ( ! $handle = opendir( $backupplus_dir ) ) {
			return array( "Failed to open the internal directory ($backupplus_dir)" );
		}

		while ( false !== ( $entry = readdir( $handle ) ) ) {

			if ( '.' == $entry || '..' == $entry ) {
				continue;
			}
			// The PITR scanner owns these files; a plaintext full snapshot is not a foreign backup.
			if ( 0 === strpos( $entry, 'backup_pitr_' ) ) {
				continue;
			}

			$accepted_foreign = false;
			$potmessage       = false;

			if ( false !== $only_add_this_file && $entry != $only_add_this_file['file'] ) {
				continue;
			}

			if ( preg_match( '/^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-([\-a-z]+)([0-9]+)?(\.(zip|zip\.crypt|gz|gz\.crypt))?$/i', $entry, $matches ) ) {
				// Interpret the time as one from the blog's local timezone, rather than as UTC
				// $matches[1] is YYYY-MM-DD-HHmm, to be interpreted as being the local timezone
				$btime = strtotime( $matches[1] );
				if ( ! empty( $gmt_offset ) ) {
					$btime -= $gmt_offset * 3600;
				}
				$nonce = $matches[2];
				$type  = $matches[3];
				if ( 'db' == $type ) {
					$type .= empty( $matches[4] ) ? '' : $matches[4];
					$index = 0;
				} else {
					$index = empty( $matches[4] ) ? '0' : max( (int) $matches[4] - 1, 0 );
				}
				$itext = ( 0 == $index ) ? '' : $index;
			} elseif ( false != ( $accepted_foreign = apply_filters( 'backupplus_accept_foreign', false, $entry ) ) && false !== ( $btime = apply_filters( 'backupplus_foreign_gettime', false, $accepted_foreign, $entry ) ) ) {
				$nonce      = substr( md5( $entry ), 0, 12 );
				$type       = ( preg_match( '/\.sql(\.(bz2|gz))?$/i', $entry ) || preg_match( '/-database-([-0-9]+)\.zip$/i', $entry ) || preg_match( '/backup_db_/', $entry ) ) ? 'db' : 'wpcore';
				$index      = apply_filters( 'backupplus_accepted_foreign_index', 0, $entry, $accepted_foreign );
				$itext      = $index ? $index : '';
				$potmessage = array(
					'code'    => 'foundforeign_' . md5( $entry ),
					'desc'    => $entry,
					'method'  => '',
					// translators: %s: The description of the accepted foreign backup type.
					'message' => sprintf( __( 'Backup created by: %s.', 'backupplus' ), $accept[ $accepted_foreign ]['desc'] ),
				);
			} elseif ( '.zip' == strtolower( substr( $entry, -4, 4 ) ) || preg_match( '/\.sql(\.(bz2|gz))?$/i', $entry ) ) {
				$potmessage                      = array(
					'code'    => 'possibleforeign_' . md5( $entry ),
					'desc'    => $entry,
					'method'  => '',
					'message' => __( 'This file does not appear to be a BackupPlus or UpdraftPlus backup archive (such files are .zip or .gz files which have a name like: backup_(time)_(site name)_(code)_(type).(zip|gz)).', 'backupplus' ),
				);
				$messages[ $potmessage['code'] ] = $potmessage;
				continue;
			} else {
				// The filename pattern does not indicate any sort of backup archive
				continue;
			}

			// The time from the filename does not include seconds. We need to identify the seconds to get the right time for storing it.
			if ( isset( $backup_times_by_nonce[ $nonce ] ) ) {
				$btime_exact = $backup_times_by_nonce[ $nonce ];
				// If the btime we had was more than 60 seconds earlier, then this must be an increment - we then need to change the $backup_history array accordingly. We can pad the '60 second' test, as there's no option to run an increment more frequently than every 4 hours (though someone could run one manually from the CLI)
				if ( $btime > 100 && $btime_exact - $btime > 60 && ! empty( $backup_history[ $btime_exact ] ) ) {
					$changes = true;
					// We assume that $backup_history[$btime] is presently empty (except that the 'service_instance_ids' key may have been set earlier
					// Re-key array, indicating the newly-found time to be the start of the backup set
					$merge_services = false;
					if ( ! empty( $backup_history[ $btime ]['service_instance_ids'] ) ) {
						$merge_services = $backup_history[ $btime ]['service_instance_ids'];
					}
					$backup_history[ $btime ] = $backup_history[ $btime_exact ];
					if ( is_array( $merge_services ) ) {
						if ( empty( $backup_history[ $btime ]['service_instance_ids'] ) ) {
							$backup_history[ $btime ]['service_instance_ids'] = $merge_services;
						} else {
							foreach ( $merge_services as $service => $instance_ids ) {
								if ( empty( $backup_history[ $btime ]['service_instance_ids'][ $service ] ) ) {
									$backup_history[ $btime ]['service_instance_ids'][ $service ] = $instance_ids;
								} else {
									$backup_history[ $btime ]['service_instance_ids'][ $service ] = array_unique( array_merge( $backup_history[ $btime ]['service_instance_ids'][ $service ], $instance_ids ) );
								}
							}
						}
					}
					unset( $backup_history[ $btime_exact ] );
					$backup_times_by_nonce[ $nonce ] = $btime;
					$btime_exact                     = $btime;
				}
				$btime = $btime_exact;
			} else {
				$backup_times_by_nonce[ $nonce ] = $btime;
			}
			if ( $btime <= 100 ) {
				continue;
			}
			$file_size = @filesize( $backupplus_dir . '/' . $entry );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.

			if ( ! isset( $backup_nonces_by_filename[ $entry ] ) ) {
				$changes = true;
				if ( is_array( $potmessage ) ) {
					$messages[ $potmessage['code'] ] = $potmessage;
				}
				if ( is_array( $only_add_this_file ) ) {
					if ( isset( $only_add_this_file['label'] ) ) {
						$backup_history[ $btime ]['label'] = $only_add_this_file['label'];
					}
					$backup_history[ $btime ]['native'] = false;
				} elseif ( 'db' == $type && ! $accepted_foreign ) {
					// we now that multiple databases will add its index number after the 'db' (e.g. 'db1'), however, the $type == 'db' here has nothing to do with our multiple databases addon because this block of code inside the 'if (!isset($backup_nonces_by_filename[$entry]))' will never be executed if multiple databases is found to be in the backup history and that our backup file pattern matches with them, so this is not the place where we should check for our multiple databases backup file, this is instead the place for handling foreign databases (e.g. Backup Buddy and our other competitors). The $type were previously set to 'db' when its file was found to be a foreign database
					list ($mess, $warn, $err, $info) = $backupplus->analyse_db_file( false, array(), $backupplus_dir . '/' . $entry, true );// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Unused parameter is present because the method returns an array.
					if ( ! empty( $info['label'] ) ) {
						$backup_history[ $btime ]['label'] = $info['label'];
					}
					if ( ! empty( $info['created_by_version'] ) ) {
						$backup_history[ $btime ]['created_by_version'] = $info['created_by_version'];
					}
				}
			}

			// Make sure we have the right list of services, as an array
			$current_services = ( ! empty( $backup_history[ $btime ] ) && ! empty( $backup_history[ $btime ]['service'] ) ) ? $backup_history[ $btime ]['service'] : array();
			if ( is_string( $current_services ) ) {
				$current_services = array( $current_services );
			}
			if ( ! is_array( $current_services ) ) {
				$current_services = array();
			}
			foreach ( $current_services as $k => $v ) {
				if ( 'none' === $v || '' == $v ) {
					unset( $current_services[ $k ] );
				}
			}

			// If the file (the one we just found locally) was also found in the scan of remote storage...
			if ( ! empty( $remote_files[ $entry ] ) ) {
				// ... store the record if all services which previously stored it still do
				if ( 0 == count( array_diff( $current_services, $remote_files[ $entry ] ) ) ) {
					if ( $current_services != $remote_files[ $entry ] ) {
						$changes = true;
					}
					$backup_history[ $btime ]['service'] = $remote_files[ $entry ];
				} else {
					// There are services in $current_services which are not in $remote_files[$entry]
					// This can be because the backup set files are split across different services
					$changes                             = true;
					$backup_history[ $btime ]['service'] = array_unique( array_merge( $current_services, $remote_files[ $entry ] ) );
				}
				// Get the right size (our local copy may be too small)
				if ( ! empty( $remote_sizes[ $entry ] ) && $remote_sizes[ $entry ] > $file_size ) {
					$file_size = $remote_sizes[ $entry ];
					$changes   = true;
				}
				// Remove from $remote_files, so that we can later see what was left over (i.e. $remote_files will exclude files which are present locally).
				unset( $remote_files[ $entry ] );

			} elseif ( $remote_scan && ! in_array( $nonce, $remote_nonces_found ) ) {
				// The file is not known remotely, and neither is any other from the same set, and a remote scan was done

				if ( ! empty( $backup_history[ $btime ] ) ) {
					if ( empty( $backup_history[ $btime ]['service'] ) || ( 'none' !== $backup_history[ $btime ]['service'] && '' !== $backup_history[ $btime ]['service'] && array( 'none' ) !== $backup_history[ $btime ]['service'] ) ) {
						$backup_history[ $btime ]['service'] = 'none';
						$changes                             = true;
					}
				} else {
					$backup_history[ $btime ]['service'] = 'none';
					$changes                             = true;
				}
			}

			$backup_history[ $btime ] = BackupPlus_Backup_Sources::archive_metadata( isset( $backup_history[ $btime ] ) ? $backup_history[ $btime ] : array(), $type, $backupplus_dir . '/' . $entry );
			if ( preg_match( '/^db[0-9]*$/i', $type ) ) { // make sure we also handle multiple databases, which has a bit different array structure
				$backup_history[ $btime ][ $type ] = $entry; // $backup_history[$btime][$type] is in string type not array
			} else {
				$backup_history[ $btime ][ $type ][ $index ] = $entry;
			}

			if ( ! empty( $backup_history[ $btime ][ $type . $itext . '-size' ] ) && $backup_history[ $btime ][ $type . $itext . '-size' ] < $file_size ) {
				$backup_history[ $btime ][ $type . $itext . '-size' ] = $file_size;
				$changes = true;
			} elseif ( empty( $backup_history[ $btime ][ $type . $itext . '-size' ] ) && $file_size > 0 ) {
				$backup_history[ $btime ][ $type . $itext . '-size' ] = $file_size;
				$changes = true;
			}

			$backup_history[ $btime ]['nonce'] = $nonce;
			if ( ! empty( $accepted_foreign ) ) {
				$backup_history[ $btime ]['meta_foreign'] = $accepted_foreign;
			}
		}

		// Fourthly: are there any files found in remote storage that are not stored locally?
		// If so, then we compare $remote_files with $backup_nonces_by_filename / $backup_times_by_nonce, and adjust $backup_history

		foreach ( $remote_files as $file => $services ) {
			if ( ! preg_match( '/^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-([\-a-z]+)([0-9]+)?(\.(zip|zip\.crypt|gz|gz\.crypt))?$/i', $file, $matches ) ) {
				continue;
			}

			$nonce = $matches[2];
			$type  = $matches[3];

			if ( 'db' == $type ) {
				$index = 0;
				$type .= ! empty( $matches[4] ) ? $matches[4] : '';
			} else {
				$index = empty( $matches[4] ) ? '0' : max( (int) $matches[4] - 1, 0 );
			}

			$itext = ( 0 == $index ) ? '' : $index;
			$btime = strtotime( $matches[1] );
			if ( ! empty( $gmt_offset ) ) {
				$btime -= $gmt_offset * 3600;
			}

			// N.B. We don't need to check if the backup set needs re-keying by an earlier time here, because that was already done when processing the whole list of remote files, above.
			if ( isset( $backup_times_by_nonce[ $nonce ] ) ) {
				$btime = $backup_times_by_nonce[ $nonce ];
			}

			if ( $btime <= 100 ) {
				continue;
			}

			// Remember that at this point, we already know that the file is not stored locally (else it would have been pruned earlier from $remote_files)
			// The check for a new set needs to take into account that $backup_history[$btime]['service_instance_ids'] may have been created further up this method
			if ( isset( $backup_history[ $btime ] ) && array( 'service_instance_ids' ) !== array_keys( $backup_history[ $btime ] ) ) {
				if ( ! isset( $backup_history[ $btime ]['service'] ) || ( is_array( $backup_history[ $btime ]['service'] ) && $backup_history[ $btime ]['service'] !== $services ) || ( is_string( $backup_history[ $btime ]['service'] ) && ( 1 != count( $services ) || $services[0] !== $backup_history[ $btime ]['service'] ) ) ) {
					$changes = true;
					if ( isset( $backup_history[ $btime ]['service'] ) ) {
						$existing_services                   = is_array( $backup_history[ $btime ]['service'] ) ? $backup_history[ $btime ]['service'] : array( $backup_history[ $btime ]['service'] );
						$backup_history[ $btime ]['service'] = array_unique( array_merge( $services, $existing_services ) );
						foreach ( $backup_history[ $btime ]['service'] as $k => $v ) {
							if ( 'none' === $v || '' == $v ) {
								unset( $backup_history[ $btime ]['service'][ $k ] );
							}
						}
					} else {
						$backup_history[ $btime ]['service'] = $services;
					}
					$backup_history[ $btime ]['nonce'] = $nonce;
				}

				if ( ! isset( $backup_history[ $btime ][ $type ] ) || ( ! preg_match( '/^db[0-9]*$/i', $type ) && ! isset( $backup_history[ $btime ][ $type ][ $index ] ) ) ) {
					$changes = true;
					if ( preg_match( '/^db[0-9]*$/i', $type ) ) {
						$backup_history[ $btime ][ $type ] = $file;
					} else {
						$backup_history[ $btime ][ $type ][ $index ] = $file;
					}
					$backup_history[ $btime ]['nonce'] = $nonce;
					if ( ! empty( $remote_sizes[ $file ] ) ) {
						$backup_history[ $btime ][ $type . $itext . '-size' ] = $remote_sizes[ $file ];
					}
				}
			} else {
				$changes                             = true;
				$backup_history[ $btime ]['service'] = $services;
				if ( preg_match( '/^db[0-9]*$/i', $type ) ) {
					$backup_history[ $btime ][ $type ] = $file;
				} else {
					$backup_history[ $btime ][ $type ][ $index ] = $file;
				}
				$backup_history[ $btime ]['nonce'] = $nonce;
				if ( ! empty( $remote_sizes[ $file ] ) ) {
					$backup_history[ $btime ][ $type . $itext . '-size' ] = $remote_sizes[ $file ];
				}
				$backup_history[ $btime ]['native'] = false;
				$messages['nonnative']              = array(
					'message' => __( 'One or more backups has been added from scanning remote storage; note that these backups will not be automatically deleted through the "retain" settings; if/when you wish to delete them then you must do so manually.', 'backupplus' ),
					'code'    => 'nonnative',
					'desc'    => '',
					'method'  => '',
				);
			}
		}

		// This is for consistency - if something is no longer present in the service list, then neither should it be in the ids list
		foreach ( $backup_history as $btime => $bdata ) {
			if ( ! empty( $bdata['service_instance_ids'] ) ) {
				foreach ( $bdata['service_instance_ids'] as $method => $instance_ids ) {
					if ( ( is_array( $bdata['service'] ) && ! in_array( $method, $bdata['service'] ) ) || ( is_string( $bdata['service'] ) && $method !== $bdata['service'] ) ) {
						unset( $backup_history[ $btime ]['service_instance_ids'][ $method ] );
						$changes = true;
					}
				}
			}
		}

		$more_backup_history = apply_filters( 'backupplus_more_rebuild', $backup_history );

		if ( $more_backup_history ) {
			$backup_history = $more_backup_history;
			$changes        = true;
		}

		if ( $changes ) {
			self::save_history( $backup_history );
		}

		return $messages;
	}
}
