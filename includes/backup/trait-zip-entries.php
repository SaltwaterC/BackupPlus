<?php

/** Adding files to the archive being written and tracking its errors and index. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Backup_Zip_Entries {

	// Q. Why don't we only open and close the zip file just once?
	// A. Because apparently PHP doesn't write out until the final close, and it will return an error if anything file has vanished in the meantime. So going directory-by-directory reduces our chances of hitting an error if the filesystem is changing underneath us (which is very possible if dealing with e.g. 1GB of files)

	/**
	 * We batch up the files, rather than do them one at a time. So we are more efficient than open,one-write,close.
	 * To call into here, the array $this->zipfiles_batched must be populated (keys=paths, values=add-to-zip-as values). It gets reset upon exit from here.
	 *
	 * @param Boolean $warn_on_failures - If set, then log at the 'warning' level
	 *
	 * @return Boolean|WP_Error
	 */
	private function makezip_addfiles( $warn_on_failures ) {

		global $backupplus;

		// Used to detect requests to bump the size
		$bump_index = false;
		$ret        = true;

		$zipfile = $this->zip_basename . ( ( 0 == $this->index ) ? '' : ( $this->index + 1 ) ) . '.' . $this->archive_extension() . '.tmp';

		$maxzipbatch = $backupplus->jobdata_get( 'maxzipbatch', 26214400 );
		if ( (int) $maxzipbatch < 1024 ) {
			$maxzipbatch = 26214400;
		}

		// Short-circuit the null case, because we want to detect later if something useful happened
		if ( 0 == count( $this->zipfiles_dirbatched ) && 0 == count( $this->zipfiles_batched ) ) {
			return true;
		}

		// If on PclZip, then if possible short-circuit to a quicker method (makes a huge time difference - on a folder of 1500 small files, 2.6s instead of 76.6)
		// This assumes that makezip_addfiles() is only called once so that we know about all needed files (the new style)
		// This is rather conservative - because it assumes zero compression. But we can't know that in advance.
		$force_allinone = false;
		if ( 0 == $this->index && $this->makezip_recursive_batchedbytes < $this->zip_split_every ) {
			// So far, we only have a processor for this for PclZip; but that check can be removed - need to address the below items
			// TODO: Is this really what we want? Always go all-in-one for < 500MB???? Should be more conservative? Or, is it always faster to go all-in-one? What about situations where we might want to auto-split because of slowness - check that that is still working.
			// TODO: Test this new method for PclZip - are we still getting the performance gains? Test for ZipArchive too.
			if ( 'BackupPlus_PclZip' == $this->use_zip_object && ( $this->makezip_recursive_batchedbytes < 512 * 1048576 || ( defined( 'BACKUPPLUS_PCLZIP_FORCEALLINONE' ) && BACKUPPLUS_PCLZIP_FORCEALLINONE == true && 'BackupPlus_PclZip' == $this->use_zip_object ) ) ) {
				$backupplus->log( 'Only one archive required (' . $this->use_zip_object . ') - will attempt to do in single operation (data: ' . round( $this->makezip_recursive_batchedbytes / 1024, 1 ) . ' KB, split: ' . round( $this->zip_split_every / 1024, 1 ) . ' KB)' );
				// $backupplus->log("PclZip, and only one archive required - will attempt to do in single operation (data: ".round($this->makezip_recursive_batchedbytes/1024, 1)." KB, split: ".round($this->zip_split_every/1024, 1)." KB)");
								$force_allinone = true;
				// if(!class_exists('PclZip')) require_once(ABSPATH.'/wp-admin/includes/class-pclzip.php');
				// $zip = new PclZip($zipfile);
				// $remove_path = ($this->whichone == 'wpcore') ? untrailingslashit(ABSPATH) : WP_CONTENT_DIR;
				// $add_path = false;
				// Remove prefixes
				// $backupable_entities = $backupplus->get_backupable_file_entities(true);
				// if (isset($backupable_entities[$this->whichone])) {
				// if ('plugins' == $this->whichone || 'themes' == $this->whichone || 'uploads' == $this->whichone) {
				// $remove_path = dirname($backupable_entities[$this->whichone]);
				// To normalise instead of removing (which binzip doesn't support, so we don't do it), you'd remove the dirname() in the above line, and uncomment the below one.
				// #$add_path = $this->whichone;
				// } else {
				// $remove_path = $backupable_entities[$this->whichone];
				// }
				// }
				// if ($add_path) {
				// $zipcode = $zip->create($this->source, PCLZIP_OPT_REMOVE_PATH, $remove_path, PCLZIP_OPT_ADD_PATH, $add_path);
				// } else {
				// $zipcode = $zip->create($this->source, PCLZIP_OPT_REMOVE_PATH, $remove_path);
				// }
				// if ($zipcode == 0) {
				// $backupplus->log("PclZip Error: ".$zip->errorInfo(true), 'warning');
				// return $zip->errorCode();
				// } else {
				// BackupPlus_Job_Scheduler::something_useful_happened();
				// return true;
				// }
			}
		}

		// 05-Mar-2013 - added a new check on the total data added; it appears that things fall over if too much data is contained in the cumulative total of files that were addFile'd without a close-open cycle; presumably data is being stored in memory. In the case in question, it was a batch of MP3 files of around 100MB each - 25 of those equals 2.5GB!

		$data_added_since_reopen = 0;
		// static $data_added_this_resumption = 0;
		// $max_data_added_any_resumption = $backupplus->jobdata_get('max_data_added_any_resumption', 0);

		// The following array is used only for error reporting if ZipArchive::close fails (since that method itself reports no error messages - we have to track manually what we were attempting to add)
		$files_zipadded_since_open = array();

		$zip = new $this->use_zip_object();
		if ( file_exists( $zipfile ) ) {
			$original_size = filesize( $zipfile );
			// PHP 8.1 throws a deprecation notice if opening a zero-size file with ZipArchive, so in that situation, we remove and re-create it
			if ( $original_size > 0 ) {
				$opencode = $zip->open( $zipfile );
				clearstatcache();
			} elseif ( 0 === $original_size ) {
				unlink( $zipfile );
			} else {
				$opencode = false;
			}
		} else {
			$original_size = 0;
		}

		if ( 0 === $original_size ) {
			$create_code = ( version_compare( PHP_VERSION, '5.2.12', '>' ) && defined( 'ZIPARCHIVE::CREATE' ) ) ? ZIPARCHIVE::CREATE : 1;
			$opencode    = $zip->open( $zipfile, $create_code );
		}

		/* translators: 1: Zip file path, 2: Error message */
		if ( true !== $opencode ) {
			return new WP_Error( 'no_open', sprintf( __( 'Failed to open the zip file (%1$s) - %2$s', 'backupplus' ), $zipfile, $zip->last_error ) );
		}

		if ( $this->incremental_manifest || apply_filters( 'backupplus_include_manifest', false, $this->whichone, $this ) ) {
			$this->backupplus_include_manifest( $this->whichone );
		}

		// Give binzip the help it needs to deal with directory symlinks
		if ( ! empty( $this->symlink_reversals ) && is_callable( array( $zip, 'ud_notify_symlink_reversals' ) ) ) {
			$zip->ud_notify_symlink_reversals( $this->symlink_reversals );
		}

		// Make sure all directories are created before we start creating files
		while ( $dir = array_pop( $this->zipfiles_dirbatched ) ) {
			$zip->addEmptyDir( $dir );
		}

		$zipfiles_added_thisbatch = 0;

		// Go through all those batched files
		foreach ( $this->zipfiles_batched as $file => $add_as ) {

			if ( ! file_exists( $file ) ) {
				$backupplus->log( "File has vanished from underneath us; dropping: $add_as" );
				continue;
			}

			$fsize = filesize( $file );

			$large_file_warning_key = 'vlargefile_' . md5( $this->whichone . '#' . $add_as );

			if ( defined( 'BACKUPPLUS_SKIP_FILE_OVER_SIZE' ) && BACKUPPLUS_SKIP_FILE_OVER_SIZE && $fsize > BACKUPPLUS_SKIP_FILE_OVER_SIZE ) {// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the check.
				$backupplus->log( 'File is larger than the user-configured (BACKUPPLUS_SKIP_FILE_OVER_SIZE) maximum (is: ' . round( $fsize / 1024, 1 ) . ' KB); will skip: ' . $add_as );
				continue;
			} elseif ( $fsize > BACKUPPLUS_WARN_FILE_SIZE ) {

				/* translators: 1: File name, 2: File size in MB */
				$log_msg = __( 'A very large file was encountered: %1$s (size: %2$s Mb)', 'backupplus' );

				// Was this warned about on a previous run?
				if ( $backupplus->warning_exists( $large_file_warning_key ) ) {
					$backupplus->log_remove_warning( $large_file_warning_key );
					$large_file_warning_key .= '-2';
					$log_msg                .= ' - ' . __( 'a second attempt is being made (upon further failure it will be skipped)', 'backupplus' );
				} elseif ( $backupplus->warning_exists( $large_file_warning_key . '-2' ) || $backupplus->warning_exists( $large_file_warning_key . '-final' ) ) {
					$backupplus->log_remove_warning( $large_file_warning_key . '-2' );
					$large_file_warning_key .= '-final';
					$log_msg                .= ' - ' . __( 'two unsuccessful attempts were made to include it, and it will now be omitted from the backup', 'backupplus' );
				}

				$backupplus->log( sprintf( $log_msg, $add_as, round( $fsize / 1048576, 1 ) ), 'warning', $large_file_warning_key );

				if ( preg_match( '/-final$/', $large_file_warning_key ) ) {
					continue;
				}
			}

			// Skips files that are already added
			if ( ! isset( $this->existing_files[ $add_as ] ) || $this->existing_files[ $add_as ] != $fsize ) {

				@touch( $zipfile );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.

				$zip->addFile( $file, $add_as );
				++$zipfiles_added_thisbatch;

				if ( method_exists( $zip, 'setCompressionName' ) && $this->file_should_be_stored_without_compression( $add_as ) && false == $zip->setCompressionName( $add_as, ZipArchive::CM_STORE ) ) {
					$backupplus->log( "Zip: setCompressionName failed on: $add_as" );
				}

				// N.B., Since makezip_addfiles() can get called more than once if there were errors detected, potentially $zipfiles_added_thisrun can exceed the total number of batched files (if they get processed twice).
				++$this->zipfiles_added_thisrun;
				$files_zipadded_since_open[] = array(
					'file'  => $file,
					'addas' => $add_as,
				);

				$data_added_since_reopen += $fsize;
				// $data_added_this_resumption += $fsize;
				/* Conditions for forcing a write-out and re-open:
				- more than $maxzipbatch bytes have been batched
				- more than 2.0 seconds have passed since the last time we wrote
				- that adding this batch of data is likely already enough to take us over the split limit (and if that happens, then do actually split - to prevent a scenario of progressively tinier writes as we approach but don't actually reach the limit)
				- more than 500 files batched (should perhaps intelligently lower this as the zip file gets bigger - not yet needed)
				*/

				// Add 10% margin. It only really matters when the OS has a file size limit, exceeding which causes failure (e.g. 2GB on 32-bit)
				// Since we don't test before the file has been created (so that zip_last_ratio has meaningful data), we rely on max_zip_batch being less than zip_split_every - which should always be the case
				$reaching_split_limit = ( $this->zip_last_ratio > 0 && $original_size > 0 && ( $original_size + 1.1 * $data_added_since_reopen * $this->zip_last_ratio ) > $this->zip_split_every ) ? true : false;

				if ( ! $force_allinone && ( $zipfiles_added_thisbatch > BACKUPPLUS_MAXBATCHFILES || $reaching_split_limit || $data_added_since_reopen > $maxzipbatch || ( time() - $this->zipfiles_lastwritetime ) > 2 ) ) {

					// We are coming towards a limit and about to close the zip, check if this is a more file backup and the manifest file has made it into this zip if not add it
					if ( $this->incremental_manifest || apply_filters( 'backupplus_include_manifest', false, $this->whichone, $this ) ) {

						$manifest = false;

						foreach ( $files_zipadded_since_open as $info ) {
							if ( 'backupplus-manifest.json' == $info['file'] ) {
								$manifest = true;
							}
						}

						if ( ! $manifest ) {
							@touch( $zipfile );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
							$path = array_search( 'backupplus-manifest.json', $this->zipfiles_batched );
							$zip->addFile( $path, 'backupplus-manifest.json' );
							++$zipfiles_added_thisbatch;

							if ( method_exists( $zip, 'setCompressionName' ) && $this->file_should_be_stored_without_compression( $this->zipfiles_batched[ $path ] ) ) {
								if ( false == $zip->setCompressionName( $this->zipfiles_batched[ $path ], ZipArchive::CM_STORE ) ) {
									$backupplus->log( "Zip: setCompressionName failed on: $this->zipfiles_batched[$path]" );
								}
							}

							// N.B., Since makezip_addfiles() can get called more than once if there were errors detected, potentially $zipfiles_added_thisrun can exceed the total number of batched files (if they get processed twice).
							++$this->zipfiles_added_thisrun;
							$files_zipadded_since_open[] = array(
								'file'  => $path,
								'addas' => 'backupplus-manifest.json',
							);
							$data_added_since_reopen    += filesize( $path );
							// $data_added_this_resumption += filesize($path);
						}
					}

					if ( function_exists( 'set_time_limit' ) ) {
						@set_time_limit( BACKUPPLUS_SET_TIME_LIMIT );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
					}                   $something_useful_sizetest = false;

					if ( $data_added_since_reopen > $maxzipbatch ) {
						$something_useful_sizetest = true;
						$backupplus->log( 'Adding batch to zip file (' . $this->use_zip_object . '): over ' . round( $maxzipbatch / 1048576, 1 ) . ' MB added on this batch (' . round( $data_added_since_reopen / 1048576, 1 ) . ' MB, ' . count( $this->zipfiles_batched ) . " files batched, $zipfiles_added_thisbatch (" . $this->zipfiles_added_thisrun . ') added so far); re-opening (prior size: ' . round( $original_size / 1024, 1 ) . ' KB)' );
					} elseif ( $zipfiles_added_thisbatch > BACKUPPLUS_MAXBATCHFILES ) {
						$backupplus->log( 'Adding batch to zip file (' . $this->use_zip_object . '): over ' . BACKUPPLUS_MAXBATCHFILES . ' files added on this batch (' . round( $data_added_since_reopen / 1048576, 1 ) . ' MB, ' . count( $this->zipfiles_batched ) . " files batched, $zipfiles_added_thisbatch (" . $this->zipfiles_added_thisrun . ') added so far); re-opening (prior size: ' . round( $original_size / 1024, 1 ) . ' KB)' );
					} elseif ( ! $reaching_split_limit ) {
						$backupplus->log( 'Adding batch to zip file (' . $this->use_zip_object . '): over 2.0 seconds have passed since the last write (' . round( $data_added_since_reopen / 1048576, 1 ) . " MB, $zipfiles_added_thisbatch (" . $this->zipfiles_added_thisrun . ') files added so far); re-opening (prior size: ' . round( $original_size / 1024, 1 ) . ' KB)' );
					} else {
						$backupplus->log( 'Adding batch to zip file (' . $this->use_zip_object . '): possibly approaching split limit (' . round( $data_added_since_reopen / 1048576, 1 ) . " MB, $zipfiles_added_thisbatch (" . $this->zipfiles_added_thisrun . ') files added so far); last ratio: ' . round( $this->zip_last_ratio, 4 ) . '; re-opening (prior size: ' . round( $original_size / 1024, 1 ) . ' KB)' );
					}

					if ( ! $zip->close() ) {
						// Though we will continue processing the files we've got, the final error code will be false, to allow a second attempt on the failed ones. This also keeps us consistent with a negative result for $zip->close() further down. We don't just retry here, because we have seen cases (with BinZip) where upon failure, the existing zip had actually been deleted. So, to be safe we need to re-scan the existing zips.
						$ret = false;
						$this->record_zip_error( $files_zipadded_since_open, $zip->last_error, $warn_on_failures );
					}

					$zipfiles_added_thisbatch = 0;

					// This triggers a re-open, later
					unset( $zip );
					$files_zipadded_since_open = array();
					// Call here, in case we've got so many big files that we don't complete the whole routine
					if ( filesize( $zipfile ) > $original_size ) {

						// It is essential that this does not go above 1, even though in reality (and this can happen at the start, if just 1 file is added (e.g. due to >2.0s detection) the 'compressed' zip file may be *bigger* than the files stored in it. When that happens, if the ratio is big enough, it can then fire the "approaching split limit" detection (very) prematurely
						$this->zip_last_ratio = ( $data_added_since_reopen > 0 ) ? min( ( filesize( $zipfile ) - $original_size ) / $data_added_since_reopen, 1 ) : 1;

						// We need a rolling update of this
						$original_size = filesize( $zipfile );

						// Move on to next zip?
						if ( $reaching_split_limit || filesize( $zipfile ) > $this->zip_split_every ) {
							$bump_index = true;
							// Take the filesize now because later we wanted to know we did clearstatcache()
							$bumped_at = round( filesize( $zipfile ) / 1048576, 1 );
						}

						// Need to make sure that something_useful_happened() is always called

						// How long since the current run began? If it's taken long (and we're in danger of not making it at all), or if that is foreseeable in future because of general slowness, then we should reduce the parameters.
						if ( ! $something_useful_sizetest ) {
							BackupPlus_Job_Scheduler::something_useful_happened();
						} else {

							// Do this as early as possible
							BackupPlus_Job_Scheduler::something_useful_happened();

							$time_since_began            = max( microtime( true ) - $this->zipfiles_lastwritetime, 0.000001 );
							$normalised_time_since_began = $time_since_began * ( $maxzipbatch / $data_added_since_reopen );

							// Don't measure speed until after ZipArchive::close()
							$rate = round( $data_added_since_reopen / $time_since_began, 1 );

							$backupplus->log( sprintf( 'A useful amount of data was added after this amount of zip processing: %s s (normalised: %s s, rate: %s KB/s)', round( $time_since_began, 1 ), round( $normalised_time_since_began, 1 ), round( $rate / 1024, 1 ) ) );

							// We want to detect not only that we need to reduce the size of batches, but also the capability to increase them. This is particularly important because of ZipArchive()'s (understandable, given the tendency of PHP processes being terminated without notice) practice of first creating a temporary zip file via copying before acting on that zip file (so the information is atomic). Unfortunately, once the size of the zip file gets over 100MB, the copy operation beguns to be significant. By the time you've hit 500MB on many web hosts the copy is the majority of the time taken. So we want to do more in between these copies if possible.

							/* "Could have done more" - detect as:
							- A batch operation would still leave a "good chunk" of time in a run
							- "Good chunk" means that the time we took to add the batch is less than 50% of a run time
							- We can do that on any run after the first (when at least one ceiling on the maximum time is known)
							- But in the case where a max_execution_time is long (so that resumptions are never needed), and we're always on run 0, we will automatically increase chunk size if the batch took less than 6 seconds.
							*/

							// At one stage we had a strategy of not allowing check-ins to have more than 20s between them. However, once the zip file got to a certain size, PHP's habit of copying the entire zip file first meant that it *always* went over 18s, and thence a drop in the max size was inevitable - which was bad, because with the copy time being something that only grew, the outcome was less data being copied every time

							// Gather the data. We try not to do this unless necessary (may be time-sensitive)
							if ( $backupplus->current_resumption >= 1 ) {
								$time_passed = $backupplus->jobdata_get( 'run_times' );
								if ( ! is_array( $time_passed ) ) {
									$time_passed = array();
								}
								list($max_time, $timings_string, $run_times_known) = BackupPlus_Manipulation_Functions::max_time_passed( $time_passed, $backupplus->current_resumption - 1, $this->first_run );// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Unused parameter is present because the method returns an array.
							} else {
								// $run_times_known = 0;
								// $max_time = -1;
								$run_times_known = 1;
								$max_time        = microtime( true ) - $backupplus->opened_log_time;
							}

							if ( $normalised_time_since_began < 6 || ( $backupplus->current_resumption >= 1 && $run_times_known >= 1 && $time_since_began < 0.6 * $max_time ) || ( 0 == $backupplus->current_resumption && $max_time > 240 ) ) {

								// How much can we increase it by?
								if ( $normalised_time_since_began < 6 || 0 == $backupplus->current_resumption ) {
									if ( $run_times_known > 0 && $max_time > 0 ) {
										$new_maxzipbatch = min( floor( max( $maxzipbatch * 6 / $normalised_time_since_began, $maxzipbatch * ( ( 0.6 * $max_time ) / $normalised_time_since_began ) ) ), $this->zip_batch_ceiling );
									} else {
										// Maximum of 200MB in a batch
										$new_maxzipbatch = min( floor( $maxzipbatch * 6 / $normalised_time_since_began ), $this->zip_batch_ceiling );
									}
								} else {
									// Use up to 60% of available time
									$new_maxzipbatch = min( floor( $maxzipbatch * ( ( 0.6 * $max_time ) / $normalised_time_since_began ) ), $this->zip_batch_ceiling );
								}

								// Throttle increases - don't increase by more than 2x in one go - ???
								// $new_maxzipbatch = floor(min(2*$maxzipbatch, $new_maxzipbatch));
								// Also don't allow anything that is going to be more than 18 seconds - actually, that's harmful because of the basically fixed time taken to copy the file
								// $new_maxzipbatch = floor(min(18*$rate ,$new_maxzipbatch));

								// Don't go above the split amount (though we expect that to be higher anyway, unless sending via email)
								$new_maxzipbatch = min( $new_maxzipbatch, $this->zip_split_every );

								// Don't raise it above a level that failed on a previous run
								$maxzipbatch_ceiling = $backupplus->jobdata_get( 'maxzipbatch_ceiling' );
								if ( is_numeric( $maxzipbatch_ceiling ) && $maxzipbatch_ceiling > 20 * 1048576 && $new_maxzipbatch > $maxzipbatch_ceiling ) {
									$backupplus->log( "Was going to raise maxzipbytes to $new_maxzipbatch, but this is too high: a previous failure led to the ceiling being set at $maxzipbatch_ceiling, which we will use instead" );
									$new_maxzipbatch = $maxzipbatch_ceiling;
								}

								// Final sanity check
								if ( $new_maxzipbatch > 1048576 ) {
									$backupplus->jobdata_set( 'maxzipbatch', $new_maxzipbatch );
								}

								if ( $new_maxzipbatch <= 1048576 ) {
									$backupplus->log( "Unexpected new_maxzipbatch value obtained (time=$time_since_began, normalised_time=$normalised_time_since_began, max_time=$max_time, data points known=$run_times_known, old_max_bytes=$maxzipbatch, new_max_bytes=$new_maxzipbatch)" );
								} elseif ( $new_maxzipbatch > $maxzipbatch ) {
									$backupplus->log( "Performance is good - will increase the amount of data we attempt to batch (time=$time_since_began, normalised_time=$normalised_time_since_began, max_time=$max_time, data points known=$run_times_known, old_max_bytes=$maxzipbatch, new_max_bytes=$new_maxzipbatch)" );
								} elseif ( $new_maxzipbatch < $maxzipbatch ) {
									// Ironically, we thought we were speedy...
									$backupplus->log( "Adjust: Reducing maximum amount of batched data (time=$time_since_began, normalised_time=$normalised_time_since_began, max_time=$max_time, data points known=$run_times_known, new_max_bytes=$new_maxzipbatch, old_max_bytes=$maxzipbatch)" );
								} else {
									$backupplus->log( "Performance is good - but we will not increase the amount of data we batch, as we are already at the present limit (time=$time_since_began, normalised_time=$normalised_time_since_began, max_time=$max_time, data points known=$run_times_known, max_bytes=$maxzipbatch)" );
								}

								if ( $new_maxzipbatch > 1048576 ) {
									$maxzipbatch = $new_maxzipbatch;
								}
							}

							// Detect excessive slowness
							// Don't do this until we're on at least resumption 7, as we want to allow some time for things to settle down and the maximum time to be accurately known (since reducing the batch size unnecessarily can itself cause extra slowness, due to PHP's usage of temporary zip files)

							// We use a percentage-based system as much as possible, to avoid the various criteria being in conflict with each other (i.e. a run being both 'slow' and 'fast' at the same time, which is increasingly likely as max_time gets smaller).

							if ( ! $backupplus->something_useful_happened && $backupplus->current_resumption >= 7 ) {

								BackupPlus_Job_Scheduler::something_useful_happened();

								if ( $run_times_known >= 5 && ( $time_since_began > 0.8 * $max_time || $time_since_began + 7 > $max_time ) ) {

									$new_maxzipbatch = max( floor( $maxzipbatch * 0.8 ), 20971520 );
									if ( $new_maxzipbatch < $maxzipbatch ) {
										$maxzipbatch = $new_maxzipbatch;
										$backupplus->jobdata_set( 'maxzipbatch', $new_maxzipbatch );
										$backupplus->log( "We are within a small amount of the expected maximum amount of time available; the zip-writing thresholds will be reduced (time_passed=$time_since_began, normalised_time_passed=$normalised_time_since_began, max_time=$max_time, data points known=$run_times_known, old_max_bytes=$maxzipbatch, new_max_bytes=$new_maxzipbatch)" );
									} else {
										$backupplus->log( "We are within a small amount of the expected maximum amount of time available, but the zip-writing threshold is already at its lower limit (20MB), so will not be further reduced (max_time=$max_time, data points known=$run_times_known, max_bytes=$maxzipbatch)" );
									}
								}
							} else {
								BackupPlus_Job_Scheduler::something_useful_happened();
							}
						}
						$data_added_since_reopen = 0;
					} else {
						// ZipArchive::close() can take a very long time, which we want to know about
						BackupPlus_Job_Scheduler::record_still_alive();
					}

					clearstatcache();
					$this->zipfiles_lastwritetime = time();
				}
			} elseif ( 0 == $this->zipfiles_added_thisrun ) {
				// Update lastwritetime, because otherwise the 2.0-second-activity detection can fire prematurely (e.g. if it takes >2.0 seconds to process the previously-written files, then the detector fires after 1 file. This then can have the knock-on effect of having something_useful_happened() called, but then a subsequent attempt to write out a lot of meaningful data fails, and the maximum batch is not then reduced.
				// Testing shows that calling time() 1000 times takes negligible time
				$this->zipfiles_lastwritetime = time();
			}

			++$this->zipfiles_added;

			// Don't call something_useful_happened() here - nothing necessarily happens until close() is called
			if ( 0 == $this->zipfiles_added % 100 ) {
				$skip_dblog = ( $this->zipfiles_added_thisrun > 0 || 0 == $this->zipfiles_added % 1000 ) ? false : true;
				$backupplus->log( 'Zip: ' . basename( $zipfile ) . ': ' . $this->zipfiles_added . ' files added (on-disk size: ' . round( @filesize( $zipfile ) / 1024, 1 ) . ' KB)', 'notice', false, $skip_dblog );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			}

			if ( $bump_index ) {
				$backupplus->log( sprintf( 'Zip size is at/near split limit (%s MB / %s MB) - bumping index (from: %d)', $bumped_at, round( $this->zip_split_every / 1048576, 1 ), $this->index ) );
				$bump_index = false;
				$this->bump_index();
				$zipfile = $this->zip_basename . ( $this->index + 1 ) . '.' . $this->archive_extension() . '.tmp';
			}

			if ( empty( $zip ) ) {
				$zip = new $this->use_zip_object();

				// Give binzip the help it needs to deal with directory symlinks
				if ( ! empty( $this->symlink_reversals ) && is_callable( array( $zip, 'ud_notify_symlink_reversals' ) ) ) {
					$zip->ud_notify_symlink_reversals( $this->symlink_reversals );
				}

				if ( file_exists( $zipfile ) ) {
					$original_size = filesize( $zipfile );
					// PHP 8.1 throws a deprecation notice if opening a zero-size file with ZipArchive, so in that situation, we remove and re-create it
					if ( $original_size > 0 ) {
						$opencode = $zip->open( $zipfile );
						clearstatcache();
					} elseif ( 0 === $original_size ) {
						unlink( $zipfile );
					} else {
						$opencode = false;
					}
				} else {
					$original_size = 0;
				}

				if ( 0 === $original_size ) {
					$create_code = ( version_compare( PHP_VERSION, '5.2.12', '>' ) && defined( 'ZIPARCHIVE::CREATE' ) ) ? ZIPARCHIVE::CREATE : 1;
					$opencode    = $zip->open( $zipfile, $create_code );
				}

				/* translators: 1: Zip file path, 2: Error message */
				if ( true !== $opencode ) {
					return new WP_Error( 'no_open', sprintf( __( 'Failed to open the zip file (%1$s) - %2$s', 'backupplus' ), $zipfile, $zip->last_error ) );
				}
			}
		}

		// Reset array
		$this->zipfiles_batched            = array();
		$this->zipfiles_skipped_notaltered = array();

		if ( false == ( $nret = $zip->close() ) ) {
			$this->record_zip_error( $files_zipadded_since_open, $zip->last_error, $warn_on_failures );
		}

		if ( $this->incremental_manifest || apply_filters( 'backupplus_include_manifest', false, $this->whichone, $this ) ) {
			if ( ! empty( $this->manifest_path ) && file_exists( $this->manifest_path ) ) {
				$backupplus->log( 'Removing manifest file: ' . basename( $this->manifest_path ) . ': ' . ( @unlink( $this->manifest_path ) ? 'OK' : 'failed' ) );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
			}
		}

		$this->zipfiles_lastwritetime = time();
		// May not exist if the last thing we did was bump
		if ( file_exists( $zipfile ) && filesize( $zipfile ) > $original_size ) {
			BackupPlus_Job_Scheduler::something_useful_happened();
		}

		// Move on to next archive?
		if ( file_exists( $zipfile ) && filesize( $zipfile ) > $this->zip_split_every ) {
			$backupplus->log( sprintf( 'Zip size has gone over split limit (%s, %s) - bumping index (%d)', round( filesize( $zipfile ) / 1048576, 1 ), round( $this->zip_split_every / 1048576, 1 ), $this->index ) );
			$this->bump_index();
		}

		$manifest = preg_replace( '/\.tmp$/', '.list.tmp', $zipfile );
		if ( ! file_exists( $manifest ) ) {
			$this->write_zip_manifest_from_zip( $zipfile );
		}

		clearstatcache();

		return ( false == $ret ) ? false : $nret;
	}

	private function record_zip_error( $files_zipadded_since_open, $msg, $warn = true ) {
		global $backupplus;

		if ( ! empty( $backupplus->cpanel_quota_readable ) ) {
			$hosting_bytes_free = $backupplus->get_hosting_disk_quota_free();
			if ( is_array( $hosting_bytes_free ) ) {
				$perc           = round( 100 * $hosting_bytes_free[1] / ( max( $hosting_bytes_free[2], 1 ) ), 1 );
				$quota_free_msg = sprintf( 'Free disk space in account: %s (%s used)', round( $hosting_bytes_free[3] / 1048576, 1 ) . ' MB', "$perc %" );
				$backupplus->log( $quota_free_msg );
				if ( $hosting_bytes_free[3] < 1048576 * 50 ) {
					$quota_low     = true;
					$quota_free_mb = round( $hosting_bytes_free[3] / 1048576, 1 );
					/* translators: %s: Remaining free space in MB */
					$backupplus->log( sprintf( __( 'Your free space in your hosting account is very low - only %s Mb remain', 'backupplus' ), $quota_free_mb ), 'warning', 'lowaccountspace' . $quota_free_mb );
				}
			}
		}

		// Always warn of this
		if ( is_string( $msg ) && strpos( $msg, 'File Size Limit Exceeded' ) !== false && 'BackupPlus_BinZip' == $this->use_zip_object ) {
			$backupplus->log(
				sprintf(
					/* translators: %s: Error message */
					__( 'The zip engine returned the message: %s.', 'backupplus' ),
					'File Size Limit Exceeded'
				) .
				__( 'Check the backup log for file size limits and verify that the destination has enough free space.', 'backupplus' ),
				'warning',
				'zipcloseerror-filesizelimit'
			);
		} elseif ( $warn ) {
			$warn_msg = __( 'A zip error occurred', 'backupplus' ) . ' - ';
			if ( ! empty( $quota_low ) ) {
				$warn_msg = __( 'Your web hosting account is full; free disk space before trying again.', 'backupplus' );
			} else {
				$warn_msg .= __( 'check your log for more details.', 'backupplus' );
			}
			$backupplus->log( $warn_msg, 'warning', 'zipcloseerror-' . $this->whichone );
		}

		$backupplus->log( "The attempt to close the zip file returned an error ($msg). List of files we were trying to add follows (check their permissions)." );

		foreach ( $files_zipadded_since_open as $ffile ) {
			$backupplus->log( 'File: ' . $ffile['addas'] . ' (exists: ' . (int) @file_exists( $ffile['file'] ) . ', is_readable: ' . (int) @is_readable( $ffile['file'] ) . ' size: ' . @filesize( $ffile['file'] ) . ')', 'notice', false, true );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}
	}

	/**
	 * Bump the zip index number. No parameters or returned value, since it is dealing with class variables.
	 */
	private function bump_index() {
		global $backupplus;
		$youwhat = $this->whichone;

		$timetaken = max( microtime( true ) - $this->zip_microtime_start, 0.000001 );

		$itext     = ( 0 == $this->index ) ? '' : ( $this->index + 1 );
		$full_path = $this->zip_basename . $itext . '.' . $this->archive_extension();

		$checksums = $backupplus->which_checksums();

		$checksum_description = '';

		foreach ( $checksums as $checksum ) {

			$cksum = hash_file( $checksum, $full_path . '.tmp' );
			$backupplus->jobdata_set( $checksum . '-' . $youwhat . $this->index, $cksum );
			if ( $checksum_description ) {
				$checksum_description .= ', ';
			}
			$checksum_description .= "$checksum: $cksum";

		}

		$next_full_path = $this->zip_basename . ( $this->index + 2 ) . '.' . $this->archive_extension();
		// We touch the next zip before renaming the temporary file; this indicates that the backup for the entity is not *necessarily* finished
		touch( $next_full_path . '.tmp' );

		if ( file_exists( $full_path . '.tmp' ) && filesize( $full_path . '.tmp' ) > 0 ) {
			if ( ! rename( $full_path . '.tmp', $full_path ) ) {
				$backupplus->log( "Rename failed for $full_path.tmp" );
			} else {
				$manifest = $full_path . '.list.tmp';
				if ( ! file_exists( $manifest ) ) {
					$this->write_zip_manifest_from_zip( $full_path );
				}
				BackupPlus_Job_Scheduler::something_useful_happened();
			}
		}

		$kbsize = filesize( $full_path ) / 1024;
		$rate   = round( $kbsize / $timetaken, 1 );
		$backupplus->log( 'Created ' . $this->whichone . ' zip (' . $this->index . ') - ' . round( $kbsize, 1 ) . ' KB in ' . round( $timetaken, 1 ) . " s ($rate KB/s) (checksums: $checksum_description)" );
		$this->zip_microtime_start = microtime( true );

		// No need to add $itext here - we can just delete any temporary files for this zip
		BackupPlus_Filesystem_Functions::clean_temporary_files( '_' . $backupplus->file_nonce . '-' . $youwhat, 600 );

		$prior_index = $this->index;
		++$this->index;
		$this->job_file_entities[ $youwhat ]['index'] = $this->index;
		$backupplus->jobdata_set( 'job_file_entities', $this->job_file_entities );
		$this->maybe_cloud_backup( basename( $full_path ), $this->whichone, $prior_index );
	}
}
