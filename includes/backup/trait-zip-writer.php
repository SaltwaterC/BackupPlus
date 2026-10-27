<?php

/** Creating zip archives for an entity, splitting them by size and resuming across runs. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Backup_Zip_Writer {

	/**
	 * Make Zip File.
	 *
	 * @param Array|String $source               Caution: $source is allowed to be an array, not just a filename
	 * @param String       $backup_file_basename Name of backup file
	 * @param String       $whichone             Backup entity type (e.g. 'plugins')
	 * @param Boolean      $retry_on_error       Set to retry upon error
	 * @return Boolean
	 */
	private function make_zipfile( $source, $backup_file_basename, $whichone, $retry_on_error = true ) {

		global $backupplus;

		$original_index = $this->index;

		$itext            = empty( $this->index ) ? '' : ( $this->index + 1 );
		$destination_base = $backup_file_basename . '-' . $whichone . $itext . '.' . $this->archive_extension() . '.tmp';
		// $destination is the temporary file (ending in .tmp)
		$destination = $this->backupplus_dir . '/' . $destination_base;

		// When to prefer PCL:
		// - We were asked to
		// - No zip extension present and no relevant method present
		// The zip extension check is not redundant, because method_exists segfaults some PHP installs, leading to support requests

		// We need meta-info about $whichone
		$backupable_entities = $backupplus->get_backupable_file_entities( true, false );
		// This is only used by one corner-case in BinZip
		// $this->make_zipfile_source = (isset($backupable_entities[$whichone])) ? $backupable_entities[$whichone] : $source;
		$this->make_zipfile_source = ( is_array( $source ) && isset( $backupable_entities[ $whichone ] ) ) ? ( ( 'uploads' == $whichone ) ? dirname( $backupable_entities[ $whichone ] ) : $backupable_entities[ $whichone ] ) : dirname( $source );

		$this->existing_files = array();
		// Used for tracking compression ratios
		$this->existing_files_rawsize = 0;
		$this->existing_zipfiles_size = 0;

		// Enumerate existing files
		// Usually first_linked_index is zero; the exception being with more files, where previous zips' contents are irrelevant
		for ( $j = $this->first_linked_index; $j <= $this->index; $j++ ) {
			$jtext = ( 0 == $j ) ? '' : $j + 1;
			// This is, in a non-obvious way, compatible with filenames which indicate increments
			// $j does not need to start at zero; it should start at the index which the current entity split at. However, this is not directly known, and can only be deduced from examining the filenames. And, for other indexes from before the current increment, the searched-for filename won't exist (even if there is no cloud storage). So, this indirectly results in the desired outcome when we start from $j=0.
			$examine_zip = $this->backupplus_dir . '/' . $backup_file_basename . '-' . $whichone . $jtext . '.' . $this->archive_extension() . ( ( $j == $this->index ) ? '.tmp' : '' );

			// This comes from https://wordpress.org/support/topic/backupplus-not-moving-all-files-to-remote-server - where it appears that the jobdata's record of the split was done (i.e. database write), but the *earlier* rename of the .tmp file was not done (i.e. I/O lost). i.e. In theory, this should be impossible; but, the sychnronicity apparently cannot be fully relied upon in some setups. The check for the index being one behind is being conservative - there's no inherent reason why it couldn't be done for other indexes.
			// Note that in this 'impossible' case, no backup data was being lost - the design still ensures that the on-disk backup is fine. The problem was a gap in the sequence numbering of the zip files, leading to user confusion.
			// Other examples of this appear to be in HS#1001 and #1047
			if ( $j != $this->index && ! file_exists( $examine_zip ) ) {
				$alt_examine_zip = $this->backupplus_dir . '/' . $backup_file_basename . '-' . $whichone . $jtext . '.' . $this->archive_extension() . ( ( $j == $this->index - 1 ) ? '.tmp' : '' );
				if ( $alt_examine_zip != $examine_zip && file_exists( $alt_examine_zip ) && is_readable( $alt_examine_zip ) && filesize( $alt_examine_zip ) > 0 ) {
					$backupplus->log( "Looked-for zip file not found; but non-zero .tmp zip was, despite not being current index ($j != " . $this->index . " - renaming zip (assume previous resumption's IO was lost before kill)" );
					if ( rename( $alt_examine_zip, $examine_zip ) ) {
						clearstatcache();
					} else {
						$backupplus->log( 'Rename failed - backup zips likely to not have sequential numbers (does not affect backup integrity, but can cause user confusion)' );
					}
				}
			}

			// If the file exists, then we should grab its index of files inside, and sizes
			// Then, when we come to write a file, we should check if it's already there, and only add if it is not
			if ( file_exists( $examine_zip ) && is_readable( $examine_zip ) && filesize( $examine_zip ) > 0 ) {

				// Do not use (which also means do not create) a manifest if the file is still a .tmp file, since this may not be complete. If we are in this place in the code from a resumption, creating a manifest here will mean the manifest becomes out-of-date if further files are added.
				$this->populate_existing_files_list_from_zip( $examine_zip, substr( $examine_zip, -4 ) === '.' . $this->archive_extension() );

				// try_split is true if there have been no check-ins recently - or if it needs to be split anyway
				if ( $j == $this->index ) {
					if ( $this->try_split ) {
						if ( filesize( $examine_zip ) > 50 * 1048576 ) {
							// We could, as a future enhancement, save this back to the job data, if we see a case that needs it
							$this->zip_split_every = max(
								(int) $this->zip_split_every / 2,
								BACKUPPLUS_SPLIT_MIN * 1048576,
								min( filesize( $examine_zip ) - 1048576, $this->zip_split_every )
							);
							$backupplus->jobdata_set( 'split_every', (int) ( $this->zip_split_every / 1048576 ) );
							$backupplus->log( 'No check-in on last two runs; bumping index and reducing zip split to: ' . round( $this->zip_split_every / 1048576, 1 ) . ' MB' );
							$do_bump_index = true;
						}
						unset( $this->try_split );
					} elseif ( filesize( $examine_zip ) > $this->zip_split_every ) {
						$backupplus->log( sprintf( 'Zip size is at/near split limit (%s MB / %s MB) - bumping index (from: %d)', filesize( $examine_zip ), round( $this->zip_split_every / 1048576, 1 ), $this->index ) );
						$do_bump_index = true;
					}
				}
			} elseif ( file_exists( $examine_zip ) ) {
				$backupplus->log( 'Zip file already exists, but is not readable or was zero-sized; will remove: ' . basename( $examine_zip ) );
				@unlink( $examine_zip );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
			} elseif ( $backupplus->is_uploaded( basename( $examine_zip ) ) ) {
				$this->populate_existing_files_list_from_zip( $examine_zip, true );
			}
		}

		$this->zip_last_ratio = ( $this->existing_files_rawsize > 0 ) ? ( $this->existing_zipfiles_size / $this->existing_files_rawsize ) : 1;

		$this->zipfiles_added              = 0;
		$this->zipfiles_added_thisrun      = 0;
		$this->zipfiles_dirbatched         = array();
		$this->zipfiles_batched            = array();
		$this->zipfiles_skipped_notaltered = array();
		$this->zipfiles_lastwritetime      = time();
		$this->zip_basename                = $this->backupplus_dir . '/' . $backup_file_basename . '-' . $whichone;

		if ( ! empty( $do_bump_index ) ) {
			$this->bump_index();
		}

		$error_occurred = false;

		// Store this in its original form
		// $this->source = $source;

		// Reset. This counter is used only with PcLZip, to decide if it's better to do it all-in-one
		$this->makezip_recursive_batchedbytes = 0;
		if ( ! is_array( $source ) ) {
			$source = array( $source );
		}

		$exclude = $backupplus->get_exclude( $whichone );

		$files_enumerated_at = $backupplus->jobdata_get( 'files_enumerated_at' );
		if ( ! is_array( $files_enumerated_at ) ) {
			$files_enumerated_at = array();
		}
		$files_enumerated_at[ $whichone ] = time();
		$backupplus->jobdata_set( 'files_enumerated_at', $files_enumerated_at );

		$this->makezip_if_altered_since = is_array( $this->altered_since ) ? ( isset( $this->altered_since[ $whichone ] ) ? $this->altered_since[ $whichone ] : -1 ) : -1;

		// Reset
		$got_uploads_from_cache = false;

		// Uploads: can/should we get it back from the cache?
		// || 'others' == $whichone
		if ( ( 'uploads' == $whichone || 'others' == $whichone ) && function_exists( 'gzopen' ) && function_exists( 'gzread' ) ) {
			$use_cache_files = false;
			$cache_file_base = $this->zip_basename . '-cachelist-' . $this->makezip_if_altered_since . ( $backupplus->jobdata_get( 'incremental_configuration' ) ? '-' . $backupplus->nonce : '' );
			// Cache file suffixes: -zfd.gz.tmp, -zfb.gz.tmp, -info.tmp, (possible)-zfs.gz.tmp
			if ( file_exists( $cache_file_base . '-zfd.gz.tmp' ) && file_exists( $cache_file_base . '-zfb.gz.tmp' ) && file_exists( $cache_file_base . '-info.tmp' ) ) {
				// Cache files exist; shall we use them?
				$mtime = filemtime( $cache_file_base . '-zfd.gz.tmp' );
				// Require < 30 minutes old
				if ( time() - $mtime < 1800 ) {
					$use_cache_files = true;
				}
				$any_failures = false;
				if ( $use_cache_files ) {
					$var = $this->unserialize_gz_cache_file( $cache_file_base . '-zfd.gz.tmp' );
					if ( is_array( $var ) ) {
						$this->zipfiles_dirbatched = $var;
						$var                       = $this->unserialize_gz_cache_file( $cache_file_base . '-zfb.gz.tmp' );
						if ( is_array( $var ) ) {
							$this->zipfiles_batched = $var;
							if ( file_exists( $cache_file_base . '-info.tmp' ) ) {
								$var = $backupplus->unserialize( file_get_contents( $cache_file_base . '-info.tmp' ) );
								if ( is_array( $var ) && isset( $var['makezip_recursive_batchedbytes'] ) ) {
									$this->makezip_recursive_batchedbytes = $var['makezip_recursive_batchedbytes'];
									if ( file_exists( $cache_file_base . '-zfs.gz.tmp' ) ) {
										$var = $this->unserialize_gz_cache_file( $cache_file_base . '-zfs.gz.tmp' );
										if ( is_array( $var ) ) {
											$this->zipfiles_skipped_notaltered = $var;
										} else {
											$any_failures = true;
										}
									} else {
										$this->zipfiles_skipped_notaltered = array();
									}
								} else {
									$any_failures = true;
								}
							}
						} else {
							$any_failures = true;
						}
					} else {
						$any_failures = true;
					}
					if ( $any_failures ) {
						$backupplus->log( 'Failed to recover file lists from existing cache files' );
						// Reset it all
						$this->zipfiles_skipped_notaltered    = array();
						$this->makezip_recursive_batchedbytes = 0;
						$this->zipfiles_batched               = array();
						$this->zipfiles_dirbatched            = array();
					} else {
						$backupplus->log( 'File lists recovered from cache files; sizes: ' . count( $this->zipfiles_batched ) . ', ' . count( $this->zipfiles_batched ) . ', ' . count( $this->zipfiles_skipped_notaltered ) . ')' );
						$got_uploads_from_cache = true;
					}
				}
			}
		}

		$time_counting_began = time();

		$this->excluded_extensions = $this->get_excluded_extensions( $exclude );
		$this->excluded_prefixes   = $this->get_excluded_prefixes( $exclude );
		$this->excluded_wildcards  = $this->get_excluded_wildcards( $exclude );

		foreach ( $source as $element ) {
			// makezip_recursive_add($fullpath, $use_path_when_storing, $original_fullpath, $startlevels = 1, $exclude_array)
			if ( 'uploads' == $whichone ) {
				if ( empty( $got_uploads_from_cache ) ) {
					$dirname  = dirname( $element );
					$basename = $this->basename( $element );
					$add_them = $this->makezip_recursive_add( $element, basename( $dirname ) . '/' . $basename, $element, 2, $exclude );
				} else {
					$add_them = true;
				}
			} else {
				if ( empty( $got_uploads_from_cache ) ) {
					$add_them = $this->makezip_recursive_add( $element, $this->basename( $element ), $element, 1, $exclude );
				} else {
					$add_them = true;
				}
			}
			if ( is_wp_error( $add_them ) || false === $add_them ) {
				$error_occurred = true;
			}
		}

		$time_counting_ended = time();

		// Cache the file scan, if it looks like it'll be useful
		// We use gzip to reduce the size as on hosts which limit disk I/O, the caching may make things worse
		// || 'others' == $whichone
		if ( ( 'uploads' == $whichone || 'others' == $whichone ) && ! $error_occurred && function_exists( 'gzopen' ) && function_exists( 'gzwrite' ) ) {
			$cache_file_base = $this->zip_basename . '-cachelist-' . $this->makezip_if_altered_since . ( $backupplus->jobdata_get( 'incremental_configuration' ) ? '-' . $backupplus->nonce : '' );

			// Just approximate - we're trying to avoid an otherwise-unpredictable PHP fatal error. Caching only happens if file enumeration took a long time - so presumably there are very many.
			$memory_needed_estimate = 0;
			foreach ( $this->zipfiles_batched as $k => $v ) {
				$memory_needed_estimate += strlen( $k ) + strlen( $v ) + 12;
			}

			// We haven't bothered to check if we just fetched the files from cache, as that shouldn't take a long time and so shouldn't trigger this
			// Let us suppose we need 15% overhead for gzipping

			$memory_limit  = ini_get( 'memory_limit' );
			$memory_usage  = round( @memory_get_usage( false ) / 1048576, 1 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			$memory_usage2 = round( @memory_get_usage( true ) / 1048576, 1 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.

			if ( $time_counting_ended - $time_counting_began > 20 && $backupplus->verify_free_memory( $memory_needed_estimate * 0.15 ) && $whandle = gzopen( $cache_file_base . '-zfb.gz.tmp', 'w' ) ) {
				$backupplus->log( 'File counting took a long time (' . ( $time_counting_ended - $time_counting_began ) . "s); will attempt to cache results (memory_limit: $memory_limit (used: {$memory_usage}M | {$memory_usage2}M), estimated uncompressed bytes: " . round( $memory_needed_estimate / 1024, 1 ) . ' Kb)' );

				$buf = 'a:' . count( $this->zipfiles_batched ) . ':{';
				foreach ( $this->zipfiles_batched as $file => $add_as ) {
					$k    = addslashes( $file );
					$v    = addslashes( $add_as );
					$buf .= 's:' . strlen( $k ) . ':"' . $k . '";s:' . strlen( $v ) . ':"' . $v . '";';
					if ( strlen( $buf ) > 1048576 ) {
						gzwrite( $whandle, $buf, strlen( $buf ) );
						$buf = '';
					}
				}
				$buf  .= '}';
				$final = gzwrite( $whandle, $buf );
				unset( $buf );

				if ( ! $final ) {
					@unlink( $cache_file_base . '-zfb.gz.tmp' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
					@gzclose( $whandle );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
				} else {
					gzclose( $whandle );
					if ( ! empty( $this->zipfiles_skipped_notaltered ) ) {
						if ( $shandle = gzopen( $cache_file_base . '-zfs.gz.tmp', 'w' ) ) {
							if ( ! gzwrite( $shandle, serialize( $this->zipfiles_skipped_notaltered ) ) ) {
								$aborted_on_skipped = true;
							}
							gzclose( $shandle );
						} else {
							$aborted_on_skipped = true;
						}
					}
					if ( ! empty( $aborted_on_skipped ) ) {
						@unlink( $cache_file_base . '-zfs.gz.tmp' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
						@unlink( $cache_file_base . '-zfb.gz.tmp' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
					} else {
						$info_array = array( 'makezip_recursive_batchedbytes' => $this->makezip_recursive_batchedbytes );
						if ( ! file_put_contents( $cache_file_base . '-info.tmp', serialize( $info_array ) ) ) {
							@unlink( $cache_file_base . '-zfs.gz.tmp' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
							@unlink( $cache_file_base . '-zfb.gz.tmp' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
						}
						if ( $dhandle = gzopen( $cache_file_base . '-zfd.gz.tmp', 'w' ) ) {
							if ( ! gzwrite( $dhandle, serialize( $this->zipfiles_dirbatched ) ) ) {
								$aborted_on_dirbatched = true;
							}
							gzclose( $dhandle );
						} else {
							$aborted_on_dirbatched = true;
						}
						if ( ! empty( $aborted_on_dirbatched ) ) {
							// phpcs:disable Generic.PHP.NoSilencedErrors.Discouraged -- no error required if the file doesn't exist.
							@unlink( $cache_file_base . '-zfs.gz.tmp' );
							@unlink( $cache_file_base . '-zfd.gz.tmp' );
							@unlink( $cache_file_base . '-zfb.gz.tmp' );
							@unlink( $cache_file_base . '-info.tmp' );
						}
					}
				}
			}

			/*
			Class variables that get altered:
			zipfiles_batched
			makezip_recursive_batchedbytes
			zipfiles_skipped_notaltered
			zipfiles_dirbatched
			Class variables that the result depends upon (other than the state of the filesystem):
			makezip_if_altered_since
			existing_files
			*/

		}

		if ( 'wpcore' === $whichone || BackupPlus_Backup_Sources::custom( $whichone ) ) {
			$source_root = is_array( $source ) ? reset( $source ) : $source;
			$root        = basename( realpath( $source_root ) ?: $source_root );
			$prefix      = 'wpcore' === $whichone ? '' : $whichone . '/';
			$rewrite     = function ( $stored ) use ( $root, $prefix ) {
				return $prefix . ( $stored === $root ? '' : substr( $stored, strlen( $root ) + 1 ) );
			};
			foreach ( $this->zipfiles_batched as $path => &$stored ) {
				$stored = is_file( is_array( $source ) ? reset( $source ) : $source ) ? $prefix . 'payload' : $rewrite( $stored );
			}
			unset( $stored );
			$this->zipfiles_dirbatched = array_values( array_filter( array_map( $rewrite, $this->zipfiles_dirbatched ) ) );
		}
		$this->incremental_manifest  = null;
		$this->incremental_unchanged = false;
		if ( $backupplus->jobdata_get( 'incremental_configuration' ) ) {
			$this->incremental_manifest = BackupPlus_Incremental_Backups::scan( $whichone, $this->zipfiles_batched, $this->zipfiles_dirbatched, $this->zipfiles_skipped_notaltered, $this->incremental_unchanged );
		}
		if ( $this->incremental_unchanged ) {
			// The empty marker below is removed by the caller as 'not needed'; restores use the entity's latest earlier archive, which holds the same cumulative deletions.
			$backupplus->log( sprintf( 'No changes to %s since the previous backup: not writing an archive', $whichone ) );
		}

		// Any not yet dispatched? Under our present scheme, at this point nothing has yet been despatched. And since the enumerating of all files can take a while, we can at this point do a further modification check to reduce the chance of overlaps.
		// This relies on us *not* touch()ing the zip file to indicate to any resumption 'behind us' that we're already here. Rather, we're relying on the combined facts that a) if it takes us a while to search the directory tree, then it should do for the one behind us too (though they'll have the benefit of cache, so could catch very fast) and b) we touch *immediately* after finishing the enumeration of the files to add.
		// $retry_on_error is here being used as a proxy for 'not the second time around, when there might be the remains of the file on the first time around'
		if ( $retry_on_error ) {
			$backupplus->check_recent_modification( $destination );
		}
		// Here we're relying on the fact that both PclZip and ZipArchive will happily operate on an empty file. Note that BinZip *won't* (for that, may need a new strategy - e.g. add the very first file on its own, in order to 'lay down a marker')
		if ( empty( $do_bump_index ) ) {
			touch( $destination );
		}

		if ( ! $this->incremental_unchanged && ( $this->incremental_manifest || count( $this->zipfiles_dirbatched ) > 0 || count( $this->zipfiles_batched ) > 0 ) ) {

			$backupplus->log( sprintf( 'Total entities for the zip file: %d directories, %d files (%d skipped as non-modified), %s MB', count( $this->zipfiles_dirbatched ), count( $this->zipfiles_batched ), count( $this->zipfiles_skipped_notaltered ), round( $this->makezip_recursive_batchedbytes / 1048576, 1 ) ) );

			// No need to warn if we're going to retry anyway. (And if we get killed, the zip will be rescanned for its contents upon resumption).
			$warn_on_failures = ( $retry_on_error ) ? false : true;
			$add_them         = $this->makezip_addfiles( $warn_on_failures );

			if ( is_wp_error( $add_them ) ) {
				foreach ( $add_them->get_error_messages() as $msg ) {
					$backupplus->log( 'Error returned from makezip_addfiles: ' . $msg );
				}
				$error_occurred = true;
			} elseif ( false === $add_them ) {
				$backupplus->log( 'Error: makezip_addfiles returned false' );
				$error_occurred = true;
			}
		}

		// Reset these variables because the index may have changed since we began

		$itext            = empty( $this->index ) ? '' : $this->index + 1;
		$destination_base = $backup_file_basename . '-' . $whichone . $itext . '.' . $this->archive_extension() . '.tmp';
		$destination      = $this->backupplus_dir . '/' . $destination_base;

		// ZipArchive::addFile sometimes fails - there's nothing when we expected something.
		// Did not used to have || $error_occurred here. But it is better to retry, than to simply warn the user to check his logs.
		if ( ! $this->incremental_unchanged && ( ( ( file_exists( $destination ) || $this->index == $original_index ) && @filesize( $destination ) < 90 && 'BackupPlus_ZipArchive' == $this->use_zip_object ) || ( $error_occurred && $retry_on_error && 'BackupPlus_Tar' !== $this->use_zip_object ) ) ) {// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			// This can be made more sophisticated if feedback justifies it. Currently we just switch to PclZip. But, it may have been a BinZip failure, so we could then try ZipArchive if that is available. If doing that, make sure that an infinite recursion isn't made possible.
			$backupplus->log( 'makezip_addfiles(' . $this->use_zip_object . ') apparently failed (file=' . basename( $destination ) . ", type=$whichone, size=" . filesize( $destination ) . ') - retrying with PclZip' );
			$saved_zip_object     = $this->use_zip_object;
			$this->use_zip_object = 'BackupPlus_PclZip';
			$ret                  = $this->make_zipfile( $source, $backup_file_basename, $whichone, false );
			$this->use_zip_object = $saved_zip_object;
			return $ret;
		}

		// zipfiles_added > 0 means that $zip->close() has been called. i.e. An attempt was made to add something: something _should_ be there.
		// Why return true even if $error_occurred may be set? 1) Because in that case, a warning has already been logged. 2) Because returning false causes an error to be logged, which means it'll all be retried again. Also 3) this has been the pattern of the code for a long time, and the algorithm has been proven in the real-world: don't change what's not broken.
		// (file_exists($destination) || $this->index == $original_index) might be an alternative to $this->zipfiles_added > 0 - ? But, don't change what's not broken.
		if ( false == $error_occurred || $this->zipfiles_added > 0 ) {
			return true;
		} else {
			$backupplus->log( 'makezip failure: zipfiles_added=' . $this->zipfiles_added . ', error_occurred=' . $error_occurred . ' (method=' . $this->use_zip_object . ')' );
			return false;
		}
	}

	/**
	 * This function is an ugly, conservative workaround for https://bugs.php.net/bug.php?id=62119. It does not aim to always work-around, but to ensure that nothing is made worse.
	 *
	 * @param String $element
	 *
	 * @return String
	 */
	private function basename( $element ) {
		$dirname         = dirname( $element );
		$basename_manual = preg_replace( '#^[\\/]+#', '', substr( $element, strlen( $dirname ) ) );
		$basename        = basename( $element );
		if ( $basename_manual != $basename ) {
			$locale = setlocale( LC_CTYPE, '0' );
			if ( 'C' == $locale ) {
				setlocale( LC_CTYPE, 'en_US.UTF8' );
				$basename_new = basename( $element );
				if ( $basename_new == $basename_manual ) {
					$basename = $basename_new;
				}
				setlocale( LC_CTYPE, $locale );
			}
		}
		return $basename;
	}

	/**
	 * Determine if a file should be stored without compression
	 *
	 * @param String $file - the filename
	 *
	 * @return Boolean
	 */
	private function file_should_be_stored_without_compression( $file ) {
		if ( ! is_array( $this->extensions_to_not_compress ) ) {
			return false;
		}
		foreach ( $this->extensions_to_not_compress as $ext ) {
			$ext_len = strlen( $ext );
			if ( strtolower( substr( $file, -$ext_len, $ext_len ) ) == $ext ) {
				return true;
			}
		}
		return false;
	}
}
