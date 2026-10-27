<?php

/** Backing up directories into archives, with optional encryption of the result. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Backup_Files {

	/**
	 * Public, because called from the 'More Files' add-on
	 *
	 * @param String|Array    $create_from_dir      Directory/ies to create the zip
	 * @param String          $whichone             Entity being backed up (e.g. 'plugins', 'uploads')
	 * @param String          $backup_file_basename Name of backup file
	 * @param Integer         $index                Index of zip in the sequence
	 * @param Integer|Boolean $first_linked_index   First linked index in the sequence, or false
	 *
	 * @return Boolean|Array - list of files, or false for failure
	 */
	public function create_zip( $create_from_dir, $whichone, $backup_file_basename, $index, $first_linked_index = false ) {
		// Note: $create_from_dir can be an array or a string

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( BACKUPPLUS_SET_TIME_LIMIT );
		}

		if ( $this->archive_compression_enabled() ) { $this->use_zip_object = 'BackupPlus_Tar';
			$this->binzip = false; }
		$original_index = $index;

		$this->index              = $index;
		$this->first_linked_index = ( false === $first_linked_index ) ? 0 : $first_linked_index;
		$this->whichone           = $whichone;

		global $backupplus;
		$previous = $backupplus->jobdata_get( 'previous_backup_files_array', array() );
		if ( isset( $previous[ $whichone ] ) ) {
			$this->first_linked_index = max( $this->first_linked_index, max( array_keys( $previous[ $whichone ] ) ) + 1 );
		}

		$this->zip_split_every = max( (int) $backupplus->jobdata_get( 'split_every' ), BACKUPPLUS_SPLIT_MIN ) * 1048576;

		if ( 'others' != $whichone ) {
			$backupplus->log( "Beginning creation of dump of $whichone (split every: " . round( $this->zip_split_every / 1048576, 1 ) . ' MB)' );
		}

		if ( is_string( $create_from_dir ) && ! file_exists( $create_from_dir ) ) {
			$flag_error = true;
			$backupplus->log( "Does not exist: $create_from_dir" );
			if ( 'mu-plugins' == $whichone ) {
				if ( ! function_exists( 'get_mu_plugins' ) ) {
					include_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				$mu_plugins = get_mu_plugins();
				if ( count( $mu_plugins ) == 0 ) {
					$backupplus->log( 'There are no mu-plugins to backup. Will not raise an error.' );
					$flag_error = false;
				}
			}
			/* translators: 1: Entity name, 2: Directory path */
			if ( $flag_error ) {
				$backupplus->log( sprintf( __( '%1$s - could not back this entity up; the corresponding directory does not exist (%2$s)', 'backupplus' ), $whichone, $create_from_dir ), 'error' );
			}
			if ( ! $flag_error && $backupplus->jobdata_get( 'incremental_configuration' ) ) {
				$create_from_dir = array();
			} else {
				return false;
			}
		}

		$itext     = empty( $index ) ? '' : $index + 1;
		$base_path = $backup_file_basename . '-' . $whichone . $itext . '.' . $this->archive_extension();
		$full_path = $this->backupplus_dir . '/' . $base_path;
		$time_now  = time();

		// This is compatible with filenames which indicate increments, as it is looking only for the current increment
		if ( file_exists( $full_path ) || $backupplus->is_uploaded( $base_path ) ) {
			// Gather any further files that may also exist
			$files_existing = array();
			while ( file_exists( $full_path ) || $backupplus->is_uploaded( $base_path ) ) {
				$files_existing[] = $base_path;
				if ( file_exists( $full_path ) ) {
					$time_mod = (int) @filemtime( $full_path );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
					$backupplus->log( $base_path . ': this file has already been created (age: ' . round( $time_now - $time_mod, 1 ) . ' s)' );
					if ( $time_mod > 100 && ( $time_now - $time_mod ) < 30 ) {
						BackupPlus_Job_Scheduler::terminate_due_to_activity( $base_path, $time_now, $time_mod );
					}
				}
				++$index;
				// This is compatible with filenames which indicate increments, as it is looking only for the current increment
				$base_path = $backup_file_basename . '-' . $whichone . ( $index + 1 ) . '.' . $this->archive_extension();
				$full_path = $this->backupplus_dir . '/' . $base_path;
			}
		}

		// Temporary file, to be able to detect actual completion (upon which, it is renamed)

		// Jun-13 - be more aggressive in removing temporary files from earlier attempts - anything >=600 seconds old of this kind
		BackupPlus_Filesystem_Functions::clean_temporary_files( '_' . $backupplus->file_nonce . "-$whichone", 600 );

		// Firstly, make sure that the temporary file is not already being written to - which can happen if a resumption takes place whilst an old run is still active
		$zip_name = $full_path . '.tmp';
		$time_mod = file_exists( $zip_name ) ? filemtime( $zip_name ) : 0;
		if ( file_exists( $zip_name ) && $time_mod > 100 && ( $time_now - $time_mod ) < 30 ) {
			BackupPlus_Job_Scheduler::terminate_due_to_activity( $zip_name, $time_now, $time_mod );
		}

		if ( file_exists( $zip_name ) ) {
			$backupplus->log( "File exists ($zip_name), but was not modified within the last 30 seconds, so we assume that any previous run has now terminated (time_mod=$time_mod, time_now=$time_now, diff=" . ( $time_now - $time_mod ) . ')' );
		}

		// Now, check for other forms of temporary file, which would indicate that some activity is going on (even if it hasn't made it into the main zip file yet)
		// Note: this doesn't catch PclZip temporary files
		$d     = dir( $this->backupplus_dir );
		$match = '_' . $backupplus->file_nonce . '-' . $whichone;
		while ( false !== ( $e = $d->read() ) ) {
			if ( '.' == $e || '..' == $e || ! is_file( $this->backupplus_dir . '/' . $e ) ) {
				continue;
			}
			$ziparchive_match = preg_match( "/$match(?:[0-9]*)\.zip\.tmp\.[A-Za-z0-9]+$/i", $e );
			$binzip_match     = preg_match( '/^zi([A-Za-z0-9]){6}$/', $e );
			$pclzip_match     = preg_match( '/^pclzip-[a-z0-9]+.(?:gz|tmp)$/', $e );
			if ( $time_now - filemtime( $this->backupplus_dir . '/' . $e ) < 30 && ( $ziparchive_match || ( 0 != $backupplus->current_resumption && ( $binzip_match || $pclzip_match ) ) ) ) {
				BackupPlus_Job_Scheduler::terminate_due_to_activity( $this->backupplus_dir . '/' . $e, $time_now, filemtime( $this->backupplus_dir . '/' . $e ) );
			}
		}
		@$d->close();// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.
		clearstatcache();

		if ( isset( $files_existing ) ) {
			// Because of zip-splitting, the mere fact that files exist is not enough to indicate that the entity is finished. For that, we need to also see that no subsequent file has been started.
			// Q. What if the previous runner died in between zips, and it is our job to start the next one? A. The next temporary file is created before finishing the former zip, so we are safe (and we are also safe-guarded by the updated value of the index being stored in the database).
			return $files_existing;
		}

		$this->log_account_space();

		$this->zip_microtime_start = microtime( true );

		// Mapped namespaces and manifests require a writer that supports stored filenames independent of source paths.
		if ( 'BackupPlus_BinZip' === $this->use_zip_object && ( 'wpcore' === $whichone || BackupPlus_Backup_Sources::custom( $whichone ) || $backupplus->jobdata_get( 'incremental_configuration' ) ) ) {
			$this->binzip         = false;
			$this->use_zip_object = class_exists( 'BackupPlus_ZipArchive' ) ? 'BackupPlus_ZipArchive' : 'BackupPlus_PclZip';
		}

		// The paths in the zip should then begin with '$whichone', having removed WP_CONTENT_DIR from the front
		$zipcode = $this->make_zipfile( $create_from_dir, $backup_file_basename, $whichone );
		if ( true !== $zipcode ) {
			$backupplus->log( "ERROR: Zip failure: Could not create $whichone zip (" . $this->index . " / $index)" );
			$backupplus->log(
				sprintf(
					/* translators: %s: Entity name */
					__( 'Could not create %s zip.', 'backupplus' ),
					$whichone
				) . ' ' .
				__( 'Consult the log file for more information.', 'backupplus' ),
				'error'
			);
			// The caller is required to update $index from $this->index
			return false;
		} else {
			$itext     = empty( $this->index ) ? '' : $this->index + 1;
			$full_path = $this->backupplus_dir . '/' . $backup_file_basename . '-' . $whichone . $itext . '.' . $this->archive_extension();
			if ( file_exists( $full_path . '.tmp' ) ) {
				if ( @filesize( $full_path . '.tmp' ) === 0 ) {// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
					$backupplus->log( "Did not create $whichone zip (" . $this->index . ') - not needed' );
					@unlink( $full_path . '.tmp' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
				} else {

					$checksum_description = '';

					$checksums = $backupplus->which_checksums();

					foreach ( $checksums as $checksum ) {

						$cksum = hash_file( $checksum, $full_path . '.tmp' );
						$backupplus->jobdata_set( $checksum . '-' . $whichone . $this->index, $cksum );
						if ( $checksum_description ) {
							$checksum_description .= ', ';
						}
						$checksum_description .= "$checksum: $cksum";

					}

					@rename( $full_path . '.tmp', $full_path );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
					$timetaken = max( microtime( true ) - $this->zip_microtime_start, 0.000001 );
					$kbsize    = filesize( $full_path ) / 1024;
					$backupplus->jobdata_set( 'filesize-' . $whichone . $this->index, filesize( $full_path ) );
					$rate = round( $kbsize / $timetaken, 1 );
					$backupplus->log( "Created $whichone zip (" . $this->index . ') - ' . round( $kbsize, 1 ) . ' KB in ' . round( $timetaken, 1 ) . " s ($rate KB/s) ($checksum_description)" );
					// We can now remove any left-over temporary files from this job
				}
			} elseif ( $this->index > $original_index ) {
				$backupplus->log( "Did not create $whichone zip (" . $this->index . ') - not needed (2)' );
				// Added 12-Feb-2014 (to help multiple morefiles)
				--$this->index;
			} else {
				$backupplus->log( "Looked-for $whichone zip (" . $this->index . ') was not found (' . basename( $full_path ) . '.tmp)', 'warning' );
			}
			BackupPlus_Filesystem_Functions::clean_temporary_files( '_' . $backupplus->file_nonce . "-$whichone", 0 );
			$this->maybe_cloud_backup( basename( $full_path ), $whichone, $this->index );
		}

		// Remove cache list files as well, if there are any
		BackupPlus_Filesystem_Functions::clean_temporary_files( '_' . $backupplus->file_nonce . "-$whichone", 0, true );

		// Create the results array to send back (just the new ones, not any prior ones)
		$files_existing = array();
		$res_index      = $original_index;
		for ( $i = $original_index; $i <= $this->index; $i++ ) {
			$itext     = empty( $i ) ? '' : ( $i + 1 );
			$full_path = $this->backupplus_dir . '/' . $backup_file_basename . '-' . $whichone . $itext . '.' . $this->archive_extension();
			if ( file_exists( $full_path ) || $backupplus->is_uploaded( $backup_file_basename . '-' . $whichone . $itext . '.' . $this->archive_extension() ) ) {
				$files_existing[ $res_index ] = $backup_file_basename . '-' . $whichone . $itext . '.' . $this->archive_extension();
			}
			++$res_index;
		}
		return $files_existing;
	}

	/**
	 * Returns the basename up to and including the nonce (but not the entity)
	 *
	 * @param Integer $use_time epoch time to use
	 * @return String
	 */
	private function get_backup_file_basename_from_time( $use_time ) {
		global $backupplus;
		return apply_filters( 'backupplus_get_backup_file_basename_from_time', 'backup_' . get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $use_time ), 'Y-m-d-Hi' ) . '_' . $this->site_name . '_' . $backupplus->file_nonce, $use_time, $this->site_name );
	}

	/**
	 * Find the zip files in a given directory for a given nonce
	 *
	 * @param String $dir         - directory to look in
	 * @param String $match_nonce - backup ID to match
	 *
	 * @return Array
	 */
	private function find_existing_zips( $dir, $match_nonce ) {
		$zips = array();
		if ( ! $handle = opendir( $dir ) ) {
			return $zips;
		}
		while ( false !== ( $entry = readdir( $handle ) ) ) {
			if ( '.' == $entry || '..' == $entry ) {
				continue;
			}
			if ( preg_match( '/^backup_(\d{4})-(\d{2})-(\d{2})-(\d{2})(\d{2})_.*_([0-9a-f]{12})-([\-a-z]+)([0-9]+)?\.(?:zip|tar(?:\.zst)?)(?:\.crypt)?$/i', $entry, $matches ) ) {
				if ( $matches[6] !== $match_nonce ) {
					continue;
				}
				$timestamp                 = mktime( $matches[4], $matches[5], 0, $matches[2], $matches[3], $matches[1] );
				$entity                    = $matches[7];
				$index                     = empty( $matches[8] ) ? '0' : $matches[8];
				$zips[ $entity ][ $index ] = array( $timestamp, $entry );
			}
		}
		return $zips;
	}

	/**
	 * Get information on whether a particular file exists in a set
	 *
	 * @param  Array   $files  should be an array as returned by find_existing_zips()]
	 * @param  String  $entity entty of the file (e.g. 'plugins')
	 * @param  Integer $index  Index within the files array
	 * @return String|Boolean - false if the file does not exist; otherwise, the basename
	 */
	private function file_exists( $files, $entity, $index = 0 ) {
		if ( isset( $files[ $entity ] ) && isset( $files[ $entity ][ $index ] ) ) {
			$file = $files[ $entity ][ $index ];
			// Return the filename
			return $file[1];
		} else {
			return false;
		}
	}

	/**
	 * This function is resumable
	 *
	 * @param String $job_status Current status
	 *
	 * @return Array - array of backed-up files
	 */
	private function backup_dirs( $job_status ) {

		global $backupplus;

		if ( ! $backupplus->backup_time ) {
			$backupplus->backup_time_nonce();
		}

		$use_time             = $backupplus->backup_time;
		$backup_file_basename = $this->get_backup_file_basename_from_time( $use_time );

		$backup_array = array();

		// Was there a check-in last time? If not, then reduce the amount of data attempted
		if ( 'finished' != $job_status && $backupplus->current_resumption >= 2 ) {

			// NOTYET: Possible amendment to original algorithm; not just no check-in, but if the check in was very early (can happen if we get a very early checkin for some trivial operation, then attempt something too big)

			// 03-Sep-2015 - came across a case (HS#2052) where there apparently was a check-in 'last time', but no resumption was scheduled because the 'useful_checkin' jobdata was *not* last time - which must indicate dying at a very unfortunate/unlikely point in the code. As a result, the split was not auto-reduced. Consequently, we've added !$backupplus->newresumption_scheduled as a condition on the first check here (it was already on the second), as if no resumption is scheduled then whatever checkin there was last time was only partial. This was on GoDaddy, for which a number of curious I/O event combinations have been seen in recent months - their platform appears to have some odd behaviour when PHP is killed off.
			// 04-Sep-2015 - move the '$backupplus->current_resumption<=10' check to the inner loop (instead of applying to this whole section), as I see no reason for that restriction (case seen in HS#2064 where it was required on resumption 15)
			if ( $backupplus->no_checkin_last_time || ! $backupplus->newresumption_scheduled || $backupplus->resumption_scheduled_for_cleanup ) {
				// Apr 2015: !$backupplus->newresumption_scheduled added after seeing a log where there was no activity on resumption 9, and extra resumption 10 then tried the same operation.
				if ( $backupplus->current_resumption - $backupplus->last_successful_resumption > 2 || ! $backupplus->newresumption_scheduled ) {
					$this->try_split = true;
				} elseif ( $backupplus->current_resumption <= 10 ) {
					$maxzipbatch = $backupplus->jobdata_get( 'maxzipbatch', 26214400 );
					if ( (int) $maxzipbatch < 1 ) {
						$maxzipbatch = 26214400;
					}
					$new_maxzipbatch = max( floor( $maxzipbatch * 0.75 ), 20971520 );
					if ( $new_maxzipbatch < $maxzipbatch ) {
						$backupplus->log( "No check-in was detected on the previous run - as a result, we are reducing the batch amount (old=$maxzipbatch, new=$new_maxzipbatch)" );
						$backupplus->jobdata_set( 'maxzipbatch', $new_maxzipbatch );
						$backupplus->jobdata_set( 'maxzipbatch_ceiling', $new_maxzipbatch );
					}
				}
			}
		}

		if ( 'finished' != $job_status && ! BackupPlus_Filesystem_Functions::really_is_writable( $this->backupplus_dir ) ) {
			$backupplus->log( 'Backup directory (' . $this->backupplus_dir . ') is not writable, or does not exist' );
			/* translators: %s: Backup directory path */
			$backupplus->log( sprintf( __( 'Backup directory (%s) is not writable, or does not exist.', 'backupplus' ), $this->backupplus_dir ), 'error' );
			return array();
		}

		$this->job_file_entities = $backupplus->jobdata_get( 'job_file_entities' );

		// This is just used for the visual feedback (via the 'substatus' key)
		$which_entity = 0;
		// e.g. plugins, themes, uploads, others
		// $whichdir might be an array (if $youwhat is 'more')

		// Returns an array (keyed off the entity) of ($timestamp, $filename) arrays
		$existing_zips = $this->find_existing_zips( $this->backupplus_dir, $backupplus->file_nonce );

		$possible_backups = $backupplus->get_backupable_file_entities( true );

		foreach ( $possible_backups as $youwhat => $whichdir ) {

			if ( ! isset( $this->job_file_entities[ $youwhat ] ) ) {
				$backupplus->log( "No backup of $youwhat: excluded by user's options" );
				continue;
			}

			$index = (int) $this->job_file_entities[ $youwhat ]['index'];
			if ( empty( $index ) ) {
				$index = 0;
			}
			$indextext = ( 0 == $index ) ? '' : ( 1 + $index );

			$zip_file = $this->backupplus_dir . '/' . $backup_file_basename . '-' . $youwhat . $indextext . '.' . $this->archive_extension();

			// Split needed?
			$split_every = max( (int) $backupplus->jobdata_get( 'split_every' ), 250 );

			if ( false != ( $existing_file = $this->file_exists( $existing_zips, $youwhat, $index ) ) && filesize( $this->backupplus_dir . '/' . $existing_file ) > $split_every * 1048576 ) {
				++$index;
				$this->job_file_entities[ $youwhat ]['index'] = $index;
				$backupplus->jobdata_set( 'job_file_entities', $this->job_file_entities );
			}

			// Populate prior parts of $backup_array, if we're on a subsequent zip file
			if ( $index > 0 ) {
				for ( $i = 0; $i < $index; $i++ ) {
					$itext = ( 0 == $i ) ? '' : ( $i + 1 );
					// Get the previously-stored filename if possible (which should be always); failing that, base it on the current run

					$zip_file = ( isset( $this->backup_files_array[ $youwhat ] ) && isset( $this->backup_files_array[ $youwhat ][ $i ] ) ) ? $this->backup_files_array[ $youwhat ][ $i ] : $backup_file_basename . '-' . $youwhat . $itext . '.' . $this->archive_extension();

					$backup_array[ $youwhat ][ $i ] = $zip_file;
					$z                              = $this->backupplus_dir . '/' . $zip_file;
					$itext                          = ( 0 == $i ) ? '' : $i;

					$fs_key = $youwhat . $itext . '-size';
					if ( file_exists( $z ) ) {
						$backup_array[ $fs_key ] = filesize( $z );
					} elseif ( isset( $this->backup_files_array[ $fs_key ] ) ) {
						$backup_array[ $fs_key ] = $this->backup_files_array[ $fs_key ];
					}
				}
			}

			// I am not certain that all the conditions in here are possible. But there's no harm.
			if ( 'finished' == $job_status ) {
				// Add the final part of the array
				if ( $index > 0 ) {
					$zip_file                           = ( isset( $this->backup_files_array[ $youwhat ] ) && isset( $this->backup_files_array[ $youwhat ][ $index ] ) ) ? $this->backup_files_array[ $youwhat ][ $index ] : $backup_file_basename . '-' . $youwhat . ( $index + 1 ) . '.' . $this->archive_extension();
					$z                                  = $this->backupplus_dir . '/' . $zip_file;
					$fs_key                             = $youwhat . $index . '-size';
					$backup_array[ $youwhat ][ $index ] = $zip_file;
					if ( file_exists( $z ) ) {
						$backup_array[ $fs_key ] = filesize( $z );
					} elseif ( isset( $this->backup_files_array[ $fs_key ] ) ) {
						$backup_array[ $fs_key ] = $this->backup_files_array[ $fs_key ];
					}
				} else {
					$zip_file = ( isset( $this->backup_files_array[ $youwhat ] ) && isset( $this->backup_files_array[ $youwhat ][0] ) ) ? $this->backup_files_array[ $youwhat ][0] : $backup_file_basename . '-' . $youwhat . '.' . $this->archive_extension();

					$backup_array[ $youwhat ] = $zip_file;
					$fs_key                   = $youwhat . '-size';

					if ( file_exists( $zip_file ) ) {
						$backup_array[ $fs_key ] = filesize( $zip_file );
					} elseif ( isset( $this->backup_files_array[ $fs_key ] ) ) {
						$backup_array[ $fs_key ] = $this->backup_files_array[ $fs_key ];
					}
				}
			} else {

				++$which_entity;
				$backupplus->jobdata_set(
					'filecreating_substatus',
					array(
						'e' => $youwhat,
						'i' => $which_entity,
						't' => count( $this->job_file_entities ),
					)
				);

				if ( 'others' == $youwhat ) {
					$backupplus->log( "Beginning backup of other directories found in the content directory (index: $index)" );
				}

				// Apply a filter to allow add-ons to provide their own method for creating a zip of the entity
				$created = apply_filters( 'backupplus_backup_makezip_' . $youwhat, $whichdir, $backup_file_basename, $index );

				// If the filter did not lead to something being created, then use the default method
				if ( $created === $whichdir ) {

					// http://www.phpconcept.net/pclzip/user-guide/53
					/* First parameter to create is:
						An array of filenames or dirnames,
						or
						A string containing the filename or a dirname,
						or
						A string containing a list of filename or dirname separated by a comma.
					*/

					if ( 'others' == $youwhat ) {
						$dirlist = $backupplus->backup_others_dirlist( true );
					} elseif ( 'uploads' == $youwhat ) {
						$dirlist = $backupplus->backup_uploads_dirlist( true );
					} else {
						$dirlist = $whichdir;
						if ( is_array( $dirlist ) ) {
							$dirlist = array_shift( $dirlist );
						}
					}

					if ( ! empty( $dirlist ) || $backupplus->jobdata_get( 'incremental_configuration' ) ) {
						$created = $this->create_zip( $dirlist, $youwhat, $backup_file_basename, $index );
						// Now, store the results
						if ( ! is_string( $created ) && ! is_array( $created ) ) {
							$backupplus->log( "$youwhat: create_zip returned an error" );
						}
					} else {
						$backupplus->log( "No backup of $youwhat: there was nothing found to backup" );
					}
				}

				if ( $created != $whichdir && ( is_string( $created ) || is_array( $created ) ) ) {
					if ( is_string( $created ) ) {
						$created = array( $created );
					}
					foreach ( $created as $fname ) {
						if ( isset( $backup_array[ $youwhat ] ) && in_array( $fname, $backup_array[ $youwhat ] ) ) {
							continue;
						}
						$backup_array[ $youwhat ][ $index ] = $fname;
						$itext                              = ( 0 == $index ) ? '' : $index;
						// File may have already been uploaded and removed so get the size from jobdata
						if ( file_exists( $this->backupplus_dir . '/' . $fname ) ) {
							$backup_array[ $youwhat . $itext . '-size' ] = filesize( $this->backupplus_dir . '/' . $fname );
						} else {
							$backup_array[ $youwhat . $itext . '-size' ] = $backupplus->jobdata_get( 'filesize-' . $youwhat . $index );
						}
						++$index;
					}
				}

				$this->job_file_entities[ $youwhat ]['index'] = $this->index;
				$backupplus->jobdata_set( 'job_file_entities', $this->job_file_entities );

			}
		}

		return $backup_array;
	}

	/**
	 * This uses a saved status indicator; its only purpose is to indicate *total* completion; there is no actual danger, just wasted time, in resuming when it was not needed. So the saved status indicator just helps save resources.
	 *
	 * @param Integer $resumption_no Check for first run
	 *
	 * @return Array
	 */
	public function resumable_backup_of_files( $resumption_no ) {
		global $backupplus;
		// Backup directories and return a numerically indexed array of file paths to the backup files
		$bfiles_status            = $backupplus->jobdata_get( 'backup_files' );
		$this->backup_files_array = $backupplus->jobdata_get( 'backup_files_array' );

		if ( ! is_array( $this->backup_files_array ) ) {
			$this->backup_files_array = array();
		}
		if ( 'finished' == $bfiles_status ) {
			$backupplus->log( 'Creation of backups of directories: already finished' );
			// Check for recent activity
			foreach ( $this->backup_files_array as $files ) {
				if ( ! is_array( $files ) ) {
					$files = array( $files );
				}
				foreach ( $files as $file ) {
					$backupplus->check_recent_modification( $this->backupplus_dir . '/' . $file );
				}
			}
		} elseif ( 'begun' == $bfiles_status ) {
			$this->first_run = apply_filters( 'backupplus_filerun_firstrun', 0 );
			if ( $resumption_no > $this->first_run ) {
				$backupplus->log( 'Creation of backups of directories: had begun; will resume' );
			} else {
				$backupplus->log( 'Creation of backups of directories: beginning' );
			}
			$backupplus->jobdata_set( 'jobstatus', 'filescreating' );
			$this->backup_files_array = $this->backup_dirs( $bfiles_status );
			$backupplus->jobdata_set( 'backup_files_array', $this->backup_files_array );
			$backupplus->jobdata_set( 'backup_files', 'finished' );
			$backupplus->jobdata_set( 'jobstatus', 'filescreated' );
		} else {
			// This is not necessarily a backup run which is meant to contain files at all
			$backupplus->log( 'This backup run is not intended for files - skipping' );
			return array();
		}

		/*
		// DOES NOT WORK: there is no crash-safe way to do this here - have to be renamed at cloud-upload time instead
		$new_backup_array = array();
		foreach ($backup_array as $entity => $files) {
			if (!is_array($files)) $files=array($files);
			$outof = count($files);
			foreach ($files as $ind => $file) {
				$nval = $file;
				if (preg_match('/^(backup_[\-0-9]{15}_.*_[0-9a-f]{12}-[\-a-z]+)([0-9]+)?\.(?:zip|tar(?:\.zst)?)(?:\.crypt)?$/i', $file, $matches)) {
					$num = max((int)$matches[2],1);
					$new = $matches[1].$num.'of'.$outof.'.zip';
					if (file_exists($this->backupplus_dir.'/'.$file)) {
						if (@rename($this->backupplus_dir.'/'.$file, $this->backupplus_dir.'/'.$new)) {
							$backupplus->log(sprintf("Renaming: %s to %s", $file, $new));
							$nval = $new;
						}
					} elseif (file_exists($this->backupplus_dir.'/'.$new)) {
						$nval = $new;
					}
				}
				$new_backup_array[$entity][$ind] = $nval;
			}
		}
		*/
		return $this->encrypt_file_archives( $this->compress_file_archives( $this->backup_files_array ) );
	}

	/** Freeze the representation for retries; jobs begun before this feature stay compatible. */
	private function archive_compression_enabled() {
		global $backupplus;
		$enabled = $backupplus->jobdata_get( 'archive_zstd', null );
		if ( null === $enabled ) {
			$enabled = empty( $backupplus->current_resumption ) && BackupPlus_Archive_Compression::enabled();
			$backupplus->jobdata_set( 'archive_zstd', (bool) $enabled );
		}
		return (bool) $enabled;
	}
	private function archive_extension() {
		return $this->archive_compression_enabled() ? 'tar' : 'zip'; }

	/** Finish each tar part before encryption/upload, checkpointing names before cleanup. */
	private function compress_file_archives( $files ) {
		global $backupplus;
		if ( ! $this->archive_compression_enabled() ) { return $files; }
		$directory = $backupplus->backups_dir_location();
		$previous  = $backupplus->jobdata_get( 'previous_backup_files_array', array() );
		foreach ( $backupplus->get_backupable_file_entities( true ) as $entity => $unused ) {
			if ( ! isset( $files[ $entity ] ) ) { continue; }
			$files[ $entity ] = (array) $files[ $entity ];
			foreach ( $files[ $entity ] as $index => $file ) {
				if ( ! is_string( $file ) || basename( $file ) !== $file ) { throw new RuntimeException( 'Invalid archive filename for compression.' ); }
				if ( isset( $previous[ $entity ] ) && in_array( $file, $previous[ $entity ], true ) ) { continue; }
				if ( preg_match( '/\.tar\.zst(?:\.crypt)?$/D', $file ) ) {
					$source = $directory . '/' . preg_replace( '/\.zst(?:\.crypt)?$/D', '', $file );
					foreach ( array( $source, $source . '.list.tmp' ) as $staging ) { if ( is_file( $staging ) && ! unlink( $staging ) ) { throw new RuntimeException( 'Could not remove completed tar staging files.' ); }
					}
					continue;
				}
				if ( basename( $file ) !== $file || ! preg_match( '/\.tar$/D', $file ) ) { throw new RuntimeException( 'Invalid tar archive for compression.' ); }
				$path                       = $directory . '/' . $file;
				$compressed                 = BackupPlus_Archive_Compression::compress_file( $path );
				$files[ $entity ][ $index ] = basename( $compressed );
				$size                       = filesize( $compressed );
				$files[ $entity . ( 0 == $index ? '' : $index ) . '-size' ] = $size;
				$backupplus->jobdata_set( 'filesize-' . $entity . $index, $size );
				foreach ( $backupplus->which_checksums() as $checksum ) { $backupplus->jobdata_set( $checksum . '-' . $entity . $index, hash_file( $checksum, $compressed ) ); }
				$backupplus->jobdata_set( 'backup_files_array', $files );
				if ( ! unlink( $path ) ) { throw new RuntimeException( 'Could not remove the completed tar staging file.' ); }
				if ( is_file( $path . '.list.tmp' ) ) { unlink( $path . '.list.tmp' ); }
			}
		}
		return $files;
	}

	/** Whether this job's saved encryption setting covers all backup archives. */
	private function archive_encryption_enabled() {
		global $backupplus;
		$config = $backupplus->get_job_option( 'backupplus_database_encryption' );
		return is_array( $config ) && isset( $config['mode'] ) && 'none' !== $config['mode'];
	}

	/** Encrypt each completed ZIP part and checkpoint before removing its plaintext. */
	public function encrypt_file_archives( $files ) {
		global $backupplus;
		if ( false === $files ) { return false; }
		if ( ! $this->archive_encryption_enabled() ) {
			return $files;
		}
		$config = $backupplus->get_job_option( 'backupplus_database_encryption' );
		if ( 'age' !== $config['mode'] ) {
			$backupplus->log( 'Unsupported backup encryption mode. Choose Disabled or age public keys in Settings.', 'error' );
			return false;
		}
		$directory = $backupplus->backups_dir_location();
		foreach ( $backupplus->get_backupable_file_entities( true ) as $entity => $unused ) {
			if ( ! isset( $files[ $entity ] ) ) {
				continue;
			}
			$files[ $entity ] = (array) $files[ $entity ];
			foreach ( $files[ $entity ] as $index => $file ) {
				if ( ! is_string( $file ) || basename( $file ) !== $file || ! preg_match( '/\.(?:zip|tar\.zst)(?:\.crypt)?$/i', $file ) ) {
					return false;
				}
				$previous = $backupplus->jobdata_get( 'previous_backup_files_array', array() );
				if ( isset( $previous[ $entity ] ) && in_array( $file, $previous[ $entity ], true ) ) {
					continue;
				}
				$encrypted = BackupPlus_Encryption::is_file_encrypted( $file );
				$source    = $encrypted ? substr( $file, 0, -6 ) : $file;
				$path      = $directory . '/' . $file;
				if ( ! $encrypted ) {
					if ( is_link( $path ) || ! is_file( $path ) ) {
						return false;
					}
					$file = $this->encrypt_file( $file );
					if ( false === $file || is_wp_error( $file ) ) {
						return false;
					}
					$path                       = $directory . '/' . $file;
					$files[ $entity ][ $index ] = $file;
				}
				if ( is_file( $path ) && ! is_link( $path ) ) {
					$size = filesize( $path );
					if ( false === $size ) {
						return false;
					}
					$files[ $entity . ( 0 == $index ? '' : $index ) . '-size' ] = $size;
					$backupplus->jobdata_set( 'filesize-' . $entity . $index, $size );
					foreach ( $backupplus->which_checksums() as $checksum ) {
						$hash = hash_file( $checksum, $path );
						if ( false === $hash ) {
							return false;
						}
						$recorded = $backupplus->jobdata_get( $checksum . '-' . $entity . $index . '.crypt' );
						if ( $encrypted && is_string( $recorded ) && ! hash_equals( $recorded, $hash ) ) {
							return false;
						}
						$backupplus->jobdata_set( $checksum . '-' . $entity . $index . '.crypt', $hash );
					}
				} elseif ( ! $encrypted || ! $backupplus->is_uploaded( $file ) ) {
					return false;
				}
				// A retry reads encrypted names even if interrupted during cleanup.
				$backupplus->jobdata_set( 'backup_files_array', $files );
				foreach ( array( $directory . '/' . $source, $directory . '/' . $source . '.list.tmp' ) as $plaintext ) {
					if ( ( is_file( $plaintext ) || is_link( $plaintext ) ) && ! unlink( $plaintext ) ) {
						return false;
					}
				}
			}
		}
		$this->backup_files_array = $files;
		return $files;
	}

	/**
	 * Encrypts the file if the option is set; returns the basename of the file (according to whether it was encrypted or not)
	 *
	 * @param String $file - file to encrypt
	 *
	 * @return array
	 */
	public function encrypt_file( $file ) {
		global $backupplus;
		$config = $backupplus->get_job_option( 'backupplus_database_encryption' );
		if ( ! is_array( $config ) || ! isset( $config['mode'] ) || 'none' === $config['mode'] ) {
			return basename( $file );
		}
		if ( 'age' !== $config['mode'] ) {
			$backupplus->log( 'Unsupported backup encryption mode. Choose Disabled or age public keys in Settings.', 'error' );
			return false;
		}
		$recipients = isset( $config['recipients'] ) ? $config['recipients'] : array();
		$result     = BackupPlus_Encryption::encrypt( $backupplus->backups_dir_location() . '/' . basename( $file ), $recipients );
		return false === $result ? false : basename( $result );
	}

	/**
	 * This function recursively packs the zip, dereferencing symlinks but packing into a single-parent tree for universal unpacking
	 *
	 * @param String  $fullpath              Full path
	 * @param String  $use_path_when_storing Controls the path to use when storing in the zip file
	 * @param String  $original_fullpath     Original path
	 * @param Integer $startlevels           How deep within the directory structure the recursive operation has gone
	 * @param Array   $exclude               passed by reference so that we can remove elements as they are matched - saves time checking against already-dealt-with objects]
	 * @return Boolean
	 */
	private function makezip_recursive_add( $fullpath, $use_path_when_storing, $original_fullpath, $startlevels, &$exclude ) {

		global $backupplus;

		// Only BinZip supports symlinks. This means that as a consistent outcome, the only think that can be done with directory symlinks is either a) potentially duplicate the data or b) skip it. Whilst with internal WP entities (e.g. plugins) we definitely want the data, in the case of user-selected directories, we assume the user knew what they were doing when they chose the directory - i.e. we can skip symlink-accessed data that's outside.
		if ( is_link( $fullpath ) && is_dir( $fullpath ) && 'more' == $this->whichone ) {
			$backupplus->log( "Directory symlink encounted in more files backup: $use_path_when_storing -> " . readlink( $fullpath ) . ': skipping' );
			return true;
		}

		static $backupplus_dir_realpath;

		$backupplus_dir_realpath = realpath( $this->backupplus_dir );

		// De-reference. Important to do to both, because on Windows only doing it to one can make them non-equal, where they were previously equal - something which we later rely upon
		$fullpath          = realpath( $fullpath );
		$original_fullpath = realpath( $original_fullpath );

		if ( BackupPlus_Backup_Sources::exclude( false, $fullpath ) || BackupPlus_Credential_Key_Store::exclude_from_backup( false, $fullpath ) ) { return true; }

		// Is the place we've ended up above the original base? That leads to infinite recursion
		if ( ( $fullpath !== $original_fullpath && strpos( $original_fullpath, $fullpath ) === 0 ) || ( $original_fullpath == $fullpath && ( ( 1 == $startlevels && strpos( $use_path_when_storing, '/' ) !== false ) || ( 2 == $startlevels && substr_count( $use_path_when_storing, '/' ) > 1 ) ) ) ) {
			$backupplus->log( "Infinite recursion: symlink led us to $fullpath, which is within $original_fullpath" );
			$backupplus->log( __( 'Infinite recursion: consult your log for more information', 'backupplus' ), 'error' );
			return false;
		}

		// This is sufficient for the ones we have exclude options for - uploads, others, wpcore
		$stripped_storage_path = ( 1 == $startlevels ) ? $use_path_when_storing : substr( $use_path_when_storing, strpos( $use_path_when_storing, '/' ) + 1 );
		if ( false !== ( $fkey = array_search( $stripped_storage_path, $exclude ) ) ) {
			$backupplus->log( "Entity excluded by configuration option: $stripped_storage_path" );
			unset( $exclude[ $fkey ] );
			return true;
		}

		$if_altered_since = $this->makezip_if_altered_since;

		if ( is_file( $fullpath ) ) {
			if ( ! empty( $this->excluded_extensions ) && $this->is_entity_excluded_by_extension( $fullpath ) ) {
				$backupplus->log( 'Entity excluded by configuration option (extension): ' . basename( $fullpath ) );
			} elseif ( ! empty( $this->excluded_prefixes ) && $this->is_entity_excluded_by_prefix( $fullpath ) ) {
				$backupplus->log( 'Entity excluded by configuration option (prefix): ' . basename( $fullpath ) );
			} elseif ( ! empty( $this->excluded_wildcards ) && $this->is_entity_excluded_by_wildcards( basename( $fullpath ) ) ) {
				$backupplus->log( 'Entity excluded by configuration option (wildcards): ' . basename( $fullpath ) );
			} elseif ( apply_filters( 'backupplus_exclude_file', false, $fullpath ) ) {
				$backupplus->log( 'Entity excluded by filter: ' . basename( $fullpath ) );
			} elseif ( is_readable( $fullpath ) ) {
				$mtime = filemtime( $fullpath );
				$key   = ( $fullpath == $original_fullpath ) ? ( ( 2 == $startlevels ) ? $use_path_when_storing : $this->basename( $fullpath ) ) : $use_path_when_storing . '/' . $this->basename( $fullpath );
				if ( $backupplus->jobdata_get( 'incremental_configuration' ) || ( $mtime > 0 && $mtime > $if_altered_since ) ) {
					$this->zipfiles_batched[ $fullpath ]   = $key;
					$this->makezip_recursive_batchedbytes += @filesize( $fullpath );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.
					// @touch($zipfile);
				} else {
					$this->zipfiles_skipped_notaltered[ $fullpath ] = $key;
				}
			} else {
				$backupplus->log( "$fullpath: unreadable file" );
				/* translators: %s: File path */
				$backupplus->log( sprintf( __( '%s: unreadable file - could not be backed up (check the file permissions and ownership)', 'backupplus' ), $fullpath ), 'warning' );
			}
		} elseif ( is_dir( $fullpath ) ) {
			if ( $fullpath == $backupplus_dir_realpath ) {
				$backupplus->log( "Skip directory (BackupPlus backup directory): $use_path_when_storing" );
				return true;
			}

			if ( apply_filters( 'backupplus_exclude_directory', false, $fullpath, $use_path_when_storing ) ) {
				$backupplus->log( "Skip filtered directory: $use_path_when_storing" );
				return true;
			}

			if ( file_exists( $fullpath . '/.donotbackup' ) && ! BackupPlus_Credential_Key_Store::include_private_directory_in_age_backup( $fullpath ) ) {
				$backupplus->log( "Skip directory (.donotbackup file found): $use_path_when_storing" );
				return true;
			}

			if ( $backupplus->jobdata_get( 'incremental_configuration' ) || ! isset( $this->existing_files[ $use_path_when_storing ] ) ) {
				$this->zipfiles_dirbatched[] = $use_path_when_storing;
			}

			if ( ! $dir_handle = @opendir( $fullpath ) ) {// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
				$backupplus->log( "Failed to open directory: $fullpath" );
				/* translators: %s: Directory path */
				$backupplus->log( sprintf( __( 'Failed to open directory (check the file permissions and ownership): %s', 'backupplus' ), $fullpath ), 'error' );
				return false;
			}

			while ( false !== ( $e = readdir( $dir_handle ) ) ) {
				if ( '.' == $e || '..' == $e ) {
					continue;
				}

				if ( is_link( $fullpath . '/' . $e ) ) {

					$deref = realpath( $fullpath . '/' . $e );

					if ( false === $deref ) {
						$backupplus->log( "$fullpath/$e: unfollowable link" );
						$backupplus->log(
							sprintf(
								/* translators: 1: File path, 2: Readlink output */
								__( '%1$s: unfollowable link - could not be followed to back up (readlink=%2$s).', 'backupplus' ),
								$use_path_when_storing . '/' . $e,
								readlink( $fullpath . '/' . $e )
							) . ' ' .
							__( 'Possible causes include that the link points to an invalid or inaccessible location.', 'backupplus' ),
							'warning',
							"unrlink-$e"
						);
					} elseif ( is_file( $deref ) ) {
						$use_stripped = $stripped_storage_path . '/' . $e;
						if ( false !== ( $fkey = array_search( $use_stripped, $exclude ) ) ) {
							$backupplus->log( "Entity excluded by configuration option: $use_stripped" );
							unset( $exclude[ $fkey ] );
						} elseif ( ! empty( $this->excluded_extensions ) && $this->is_entity_excluded_by_extension( $e ) ) {
							$backupplus->log( "Entity excluded by configuration option (extension): $use_stripped" );
						} elseif ( ! empty( $this->excluded_prefixes ) && $this->is_entity_excluded_by_prefix( $e ) ) {
							$backupplus->log( "Entity excluded by configuration option (prefix): $use_stripped" );
						} elseif ( ! empty( $this->excluded_wildcards ) && $this->is_entity_excluded_by_wildcards( $use_stripped ) ) {
							$backupplus->log( "Entity excluded by configuration option (wildcards): $use_stripped" );
						} elseif ( apply_filters( 'backupplus_exclude_file', false, $deref, $use_stripped ) ) {
							$backupplus->log( "Entity excluded by filter: $use_stripped" );
						} elseif ( is_readable( $deref ) ) {
							$mtime = filemtime( $deref );
							if ( $backupplus->jobdata_get( 'incremental_configuration' ) || ( $mtime > 0 && $mtime > $if_altered_since ) ) {
								$this->zipfiles_batched[ $deref ]      = $use_path_when_storing . '/' . $e;
								$this->makezip_recursive_batchedbytes += @filesize( $deref );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
								// @touch($zipfile);
							} else {
								$this->zipfiles_skipped_notaltered[ $deref ] = $use_path_when_storing . '/' . $e;
							}
						} else {
							$backupplus->log( "$deref: unreadable file (de-referenced from the link $e in $fullpath)" );
							/* translators: %s: File path */
							$backupplus->log( sprintf( __( '%s: unreadable file - could not be backed up', 'backupplus' ), $deref ), 'warning' );
						}
					} elseif ( is_dir( $deref ) ) {
						$this->symlink_reversals[ $deref ] = $fullpath . '/' . $e;
						$this->makezip_recursive_add( $deref, $use_path_when_storing . '/' . $e, $original_fullpath, $startlevels, $exclude );
					}
				} elseif ( is_file( $fullpath . '/' . $e ) ) {
					$use_stripped = $stripped_storage_path . '/' . $e;
					if ( false !== ( $fkey = array_search( $use_stripped, $exclude ) ) ) {
						$backupplus->log( "Entity excluded by configuration option: $use_stripped" );
						unset( $exclude[ $fkey ] );
					} elseif ( ! empty( $this->excluded_extensions ) && $this->is_entity_excluded_by_extension( $e ) ) {
						$backupplus->log( "Entity excluded by configuration option (extension): $use_stripped" );
					} elseif ( ! empty( $this->excluded_prefixes ) && $this->is_entity_excluded_by_prefix( $e ) ) {
						$backupplus->log( "Entity excluded by configuration option (prefix): $use_stripped" );
					} elseif ( ! empty( $this->excluded_wildcards ) && $this->is_entity_excluded_by_wildcards( $use_stripped ) ) {
						$backupplus->log( "Entity excluded by configuration option (wildcards): $use_stripped" );
					} elseif ( apply_filters( 'backupplus_exclude_file', false, $fullpath . '/' . $e ) ) {
						$backupplus->log( "Entity excluded by filter: $use_stripped" );
					} elseif ( is_readable( $fullpath . '/' . $e ) ) {
						$mtime = filemtime( $fullpath . '/' . $e );
						if ( $backupplus->jobdata_get( 'incremental_configuration' ) || ( $mtime > 0 && $mtime > $if_altered_since ) ) {
							$this->zipfiles_batched[ $fullpath . '/' . $e ] = $use_path_when_storing . '/' . $e;
							$this->makezip_recursive_batchedbytes          += @filesize( $fullpath . '/' . $e );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
						} else {
							$this->zipfiles_skipped_notaltered[ $fullpath . '/' . $e ] = $use_path_when_storing . '/' . $e;
						}
					} else {
						$backupplus->log( "$fullpath/$e: unreadable file" );
						/* translators: %s: File path */
						$backupplus->log( sprintf( __( '%s: unreadable file - could not be backed up', 'backupplus' ), $use_path_when_storing . '/' . $e ), 'warning', "unrfile-$e" );
					}
				} elseif ( is_dir( $fullpath . '/' . $e ) ) {
					$use_stripped = $stripped_storage_path . '/' . $e;
					if ( 'wpcore' == $this->whichone && 'updraft' == $e && basename( $use_path_when_storing ) == 'wp-content' && ( ! defined( 'BACKUPPLUS_WPCORE_INCLUDE_BACKUPPLUS_DIRS' ) || ! BACKUPPLUS_WPCORE_INCLUDE_BACKUPPLUS_DIRS ) ) {
						// This test, of course, won't catch everything - it just aims to make things better by default
						$backupplus->log( "Directory excluded for looking like a sub-site's internal BackupPlus directory (enable by defining BACKUPPLUS_WPCORE_INCLUDE_BACKUPPLUS_DIRS): " . $use_path_when_storing . '/' . $e );
					} elseif ( ! empty( $this->excluded_wildcards ) && $this->is_entity_excluded_by_wildcards( $use_stripped ) ) {
						$backupplus->log( "Entity excluded by configuration option (wildcards): $use_stripped" );
					} else {
						// no need to add_empty_dir here, as it gets done when we recurse
						$this->makezip_recursive_add( $fullpath . '/' . $e, $use_path_when_storing . '/' . $e, $original_fullpath, $startlevels, $exclude );
					}
				}
			}
			closedir( $dir_handle );
		} else {
			$backupplus->log( "Unexpected: path ($use_path_when_storing) fails both is_file() and is_dir()" );
		}

		return true;
	}
}
