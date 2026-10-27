<?php

/** Unpacking archives into the working directory. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Restorer_Unpack {

	/**
	 * This returns a WP_Filesystem location.
	 *
	 * This function is copied from class WP_Upgrader (WP 3.8 - no significant changes since 3.2 at least); we only had to fork it because it hard-codes using the basename of the zip file as its unpack directory; which can be long; and then combining that with long pathnames in the zip being unpacked can overflow a 256-character path limit (yes, they apparently still exist - amazing!)
	 * Subsequently, we have also added the ability to unpack tarballs
	 *
	 * In the 'ordinary' case of unzipping a UD zip backup, this method basically does some preparation, and then calls BackupPlus_Filesystem_Functions::unzip_file() for the actual unzipping
	 *
	 * @param  String         $package - specify package - relative to BackupPlus::backups_dir_location()
	 * @param  String|Boolean $type    - type of archive e.g. db.
	 *
	 * @return String|WP_Error If successful, then this indicates the working directory that the archive was unpacked in; a WP_Filesystem path
	 */
	private function unpack_package_archive( $package, $type = false ) {

		global $wp_filesystem, $backupplus;

		$delete_package = $this->delete_archives_upon_restoration;

		$package           = $backupplus->backups_dir_location() . '/' . $package;
		$encrypted_package = false;
		$archive_name      = $package;
		if ( BackupPlus_Encryption::is_file_encrypted( $package ) ) {
			$prepared = BackupPlus_Age_Restore::file( $package, isset( $this->restore_options['backupplus_age_ticket'] ) ? $this->restore_options['backupplus_age_ticket'] : '', $backupplus->nonce );
			if ( ! $prepared ) {
				return new WP_Error( 'decrypt_failed', __( 'The prepared encrypted archive is unavailable or has changed. Prepare the restore again.', 'backupplus' ) );
			}
			$encrypted_package = $package;
			$archive_name      = $prepared['basename'];
			$package           = $prepared['fullpath'];
		}

		// If it is a non-UD archive that is already unpacked, then don't re-run, but return the existing result
		if ( ! empty( $this->ud_foreign ) && ! empty( $this->ud_foreign_working_dir ) && $package == $this->ud_foreign_package ) {
			if ( is_dir( $this->ud_foreign_working_dir ) ) {
				return $this->ud_foreign_working_dir;
			} else {
				$backupplus->log( 'Previously unpacked directory seems to have disappeared; will unpack again' );
			}
		}

		$this->skin->feedback( $this->strings['unpack_package'] . ' (' . basename( $package ) . ', ' . round( filesize( $package ) / 1048576, 1 ) . ' MB)' );

		$upgrade_folder = $wp_filesystem->wp_content_dir() . 'upgrade/';

		$zip_starting_index = 0;

		// We need a working directory. This has a change from the WP core version - minimise path length
		// N.B. It is deterministic; the same package file will get the same working directory
		// $working_dir = $upgrade_folder . basename($package, '.zip');
		$working_dir = $upgrade_folder . substr( md5( $encrypted_package ? $encrypted_package : $package ), 0, 8 );

		if ( preg_match( '#\.(?:zip|tar\.zst)$#i', $archive_name ) ) {

			$last_index_key = BackupPlus_Filesystem_Functions::get_jobdata_progress_key( $package );

			// Turn off the feature with define('BACKUPPLUS_UNZIP_RESUME_ENABLED', false);
			if ( ( ! defined( 'BACKUPPLUS_UNZIP_RESUME_ENABLED' ) || BACKUPPLUS_UNZIP_RESUME_ENABLED ) && ! empty( $this->continuation_data[ $last_index_key ] ) && ! empty( $this->continuation_data[ $last_index_key ]['info']['name'] ) ) {

				$reached = $this->continuation_data[ $last_index_key ];

				$last_exists = $wp_filesystem->exists( $working_dir . '/' . $reached['info']['name'] );
				$last_size   = $last_exists ? $wp_filesystem->size( $working_dir . '/' . $reached['info']['name'] ) : 'n/a';

				if ( $last_exists && $last_size == $reached['info']['size'] && isset( $reached['index'] ) ) {
					$zip_starting_index = $reached['index'];
				}

				$backupplus->log( "Unpack resumption may be possible: zip_starting_index=$zip_starting_index, last_exists=$last_exists, last_size=$last_size, last_status=" . serialize( $this->continuation_data[ $last_index_key ] ) );
			}
		}

		if ( 0 == $zip_starting_index ) {
			// Clean up contents of upgrade directory beforehand.
			$upgrade_files = $wp_filesystem->dirlist( $upgrade_folder );
			if ( ! empty( $upgrade_files ) ) {
				foreach ( $upgrade_files as $file ) {
					if ( ! $wp_filesystem->delete( $upgrade_folder . $file['name'], true ) ) {
						$this->restore_log_permission_failure_message( $upgrade_folder, 'Delete ' . $upgrade_folder . $file['name'] );
					}
				}
			}

			// Clean up working directory - this is redundant, as we already cleared out the parent folder
			if ( $wp_filesystem->is_dir( $working_dir ) ) {
				if ( ! $wp_filesystem->delete( $working_dir, true ) ) {
					$this->restore_log_permission_failure_message( dirname( $working_dir ), 'Delete ' . $working_dir );
				}
			}
		}

		// Unzip package to working directory
		if ( preg_match( '#\.(?:zip|tar\.zst)$#i', $archive_name ) ) {

			// Extract the manifest as well as the selected components. Incremental
			// restores need it to remove files deleted after the base backup, and
			// the archive filter does not preserve top-level manifest files.
			// Component selection is enforced when files are moved into place.
			$result = BackupPlus_Filesystem_Functions::unzip_file( $package, $working_dir, $zip_starting_index );

		} elseif ( preg_match( '#\.tar(\.(gz|bz2))?$#i', $package ) ) {

			if ( ! class_exists( 'UpdraftPlus_Archive_Tar' ) ) {
				if ( false === strpos( get_include_path(), BACKUPPLUS_DIR . '/includes/PEAR' ) ) {
					set_include_path( BACKUPPLUS_DIR . '/includes/PEAR' . PATH_SEPARATOR . get_include_path() );
				}
				backupplus_try_include_file( 'includes/PEAR/Archive/Tar.php', 'include_once' );
			}

			$p_compress = null;
			if ( '.tar.gz' == strtolower( substr( $package, -7, 7 ) ) ) {
				$p_compress = 'gz';
			} elseif ( '.tar.bz2' == strtolower( substr( $package, -8, 8 ) ) ) {
				$p_compress = 'bz2';
			}

			// It's not pretty, but it works.
			if ( is_a( $wp_filesystem, 'WP_Filesystem_Direct' ) ) {
				$extract_dir = $working_dir;
			} else {
				$backupplus_dir = $backupplus->backups_dir_location();
				if ( ! BackupPlus_Filesystem_Functions::really_is_writable( $backupplus_dir ) ) {
					$backupplus->log_e( 'Backup directory (%s) is not writable, or does not exist.', $backupplus_dir );
					$result = new WP_Error( 'unpack_failed', $this->strings['unpack_failed'] );
				} else {
					$extract_dir = $backupplus_dir . '/' . basename( $working_dir ) . '-old';
					if ( file_exists( $extract_dir ) ) {
						BackupPlus_Filesystem_Functions::remove_local_directory( $extract_dir );
					}
					$backupplus->log( "Using a temporary folder to extract before moving over WPFS: $extract_dir" );
				}
			}

			// Slightly hackish - rather than re-write Archive_Tar to use wp_filesystem, we instead unpack into the location that we already require to be directly writable for other reasons, and then move from there.

			if ( empty( $result ) ) {

				$this->ud_extract_count = 0;
				$this->ud_working_dir   = trailingslashit( $working_dir );
				$this->ud_extract_dir   = untrailingslashit( $extract_dir );
				$this->ud_made_dirs     = array();
				add_filter( 'backupplus_tar_wrote', array( $this, 'tar_wrote' ), 10, 2 );
				$tar    = new UpdraftPlus_Archive_Tar( $package, $p_compress );
				$result = $tar->extract( $extract_dir, false );
				if ( ! is_a( $wp_filesystem, 'WP_Filesystem_Direct' ) ) {
					BackupPlus_Filesystem_Functions::remove_local_directory( $extract_dir );
				}
				if ( true != $result ) {
					$result = new WP_Error( 'unpack_failed', $this->strings['unpack_failed'], $result );
				} else {
					if ( ! is_a( $wp_filesystem, 'WP_Filesystem_Direct' ) ) {
						$backupplus->log( 'Moved unpacked tarball contents' );
					}
				}
				remove_filter( 'backupplus_tar_wrote', array( $this, 'tar_wrote' ), 10, 2 );
			}
		}

		// Keep prepared plaintext for interrupted extraction; remove it on success.
		if ( ! is_wp_error( $result ) && $result ) {
			if ( $encrypted_package || $delete_package ) {
				unlink( $package );
			}
			if ( $encrypted_package && $delete_package ) {
				unlink( $encrypted_package );
			}
		}

		if ( is_wp_error( $result ) ) {
			$wp_filesystem->delete( $working_dir, true );
			if ( 'incompatible_archive' == $result->get_error_code() ) {
				return new WP_Error( 'incompatible_archive', $this->wp_upgrader->strings['incompatible_archive'], $result->get_error_data() );
			}
			return $result;
		}

		if ( ! empty( $this->ud_foreign ) ) {
			$this->ud_foreign_working_dir = $working_dir;
			$this->ud_foreign_package     = $package;
			// Zip containing an SQL file. We try a default pattern.
			if ( 'db' === $type ) {
				$basepack = basename( $package, '.zip' );
				if ( $wp_filesystem->exists( $working_dir . '/' . $basepack . '.sql' ) ) {
					if ( ! $wp_filesystem->move( $working_dir . '/' . $basepack . '.sql', $working_dir . '/backup.db', true ) ) {
						$this->restore_log_permission_failure_message( $working_dir, 'Move ' . $working_dir . '/' . $basepack . '.sql' . ' -> ' . $working_dir . '/backup.db', 'Destination' );
					}
					$backupplus->log( "Moving database file $basepack.sql to backup.db" );
				}
			}
		}

		return $working_dir;
	}

	public function tar_wrote( $result, $file ) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Filter use
		if ( 0 !== strpos( $file, $this->ud_extract_dir ) ) {
			return false;
		}
		global $wp_filesystem, $backupplus;
		if ( ! is_a( $wp_filesystem, 'WP_Filesystem_Direct' ) ) {
			$modint    = 100;
			$leaf      = substr( $file, strlen( $this->ud_extract_dir ) );
			$dirname   = dirname( $leaf );
			$need_dirs = explode( '/', $dirname );
			if ( empty( $this->ud_made_dirs[ $dirname ] ) ) {
				$cdir = '';
				foreach ( $need_dirs as $ndir ) {
					$cdir .= ( $cdir ) ? '/' . $ndir : $ndir;
					if ( empty( $this->ud_made_dirs[ $cdir ] ) ) {
						if ( ! $wp_filesystem->mkdir( $this->ud_working_dir . $cdir, FS_CHMOD_DIR ) && ! $wp_filesystem->is_dir( $this->ud_working_dir . $cdir ) ) {
							$backupplus->log( 'Failed to create WPFS directory: ' . $this->ud_working_dir . $cdir );
							return false;
						} else {
							$this->ud_made_dirs[ $cdir ] = true;
						}
					}
				}
			}
			$put = $wp_filesystem->put_contents( $this->ud_working_dir . $leaf, file_get_contents( $file ) );
			if ( is_wp_error( $put ) ) {
				$backupplus->log_wp_error( $put );
			}
			@unlink( $file );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise if the file doesn't exist.
		} else {
			$modint = 500;
			$put    = true;
		}
		if ( $put ) {
			++$this->ud_extract_count;
			if ( 0 == $this->ud_extract_count % $modint ) {
				$backupplus->log_e( '%s files have been extracted', $this->ud_extract_count );
			}
		}
		return ( true == $put );
	}

	/**
	 * Unpack a database backup file. This returns a WP_Filesystem location.
	 * In the case of an encrypted database file it decrypts it and returns the path to the decrypted version.
	 *
	 * @uses self::delete_archives_upon_restoration
	 *
	 * @param String $package - file to unpack; relative filepath
	 *
	 * @return String|WP_Error If successful, then this indicates the working directory that the archive was unpacked in (a WP_Filesystem path)
	 */
	private function unpack_package_database( $package ) {

		global $wp_filesystem, $backupplus;

		$delete_package = $this->delete_archives_upon_restoration;

		$backupplus_dir = $backupplus->backups_dir_location();

		// The general shape of the following comes from class-wp-upgrader.php

		$backup_dir = $wp_filesystem->find_folder( $backupplus_dir );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 1800 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}
		$packsize = round( filesize( $backup_dir . $package ) / 1048576, 1 ) . ' Mb';

		$this->skin->feedback( $this->strings['unpack_package'] . ' (' . basename( $package ) . ', ' . $packsize . ')' );

		$upgrade_folder = $wp_filesystem->wp_content_dir() . 'upgrade/';
		@$wp_filesystem->mkdir( $upgrade_folder, octdec( $this->calculate_additive_chmod_oct( FS_CHMOD_DIR, 0775 ) ) );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.

		// Clean up contents of upgrade directory beforehand.
		$upgrade_files = $wp_filesystem->dirlist( $upgrade_folder );
		if ( ! empty( $upgrade_files ) ) {
			foreach ( $upgrade_files as $file ) {
				if ( ! $wp_filesystem->delete( $upgrade_folder . $file['name'], true ) ) {
					$this->restore_log_permission_failure_message( $upgrade_folder, 'Delete ' . $upgrade_folder . $file['name'] );
				}
			}
		}

		// We need a working directory
		$working_dir = $upgrade_folder . basename( $package, '.crypt' );

		// Clean up working directory
		if ( $wp_filesystem->is_dir( $working_dir ) ) {
			if ( ! $wp_filesystem->delete( $working_dir, true ) ) {
				$this->restore_log_permission_failure_message( dirname( $working_dir ), 'Delete ' . $working_dir );
			}
		}

		if ( ! $wp_filesystem->mkdir( $working_dir, octdec( $this->calculate_additive_chmod_oct( FS_CHMOD_DIR, 0775 ) ) ) ) {
			return new WP_Error( 'mkdir_failed', __( 'Failed to create a temporary directory', 'backupplus' ) . ' (' . $working_dir . ')' );
		}

		// Unpack package to working directory
		if ( BackupPlus_Encryption::is_file_encrypted( $package ) ) {
			$this->skin->feedback( $this->strings['decrypt_database'] );
			if ( ! empty( $this->restore_options['backupplus_age_ticket'] ) || BackupPlus_Age_Restore::is_age( $backup_dir . $package ) ) {
				$decrypted_file = BackupPlus_Age_Restore::file( $backup_dir . $package, isset( $this->restore_options['backupplus_age_ticket'] ) ? $this->restore_options['backupplus_age_ticket'] : '', $backupplus->nonce );
			} else {
				$encryption = empty( $this->restore_options['backupplus_encryptionphrase'] ) ? BackupPlus_Options::get_backupplus_option( 'backupplus_encryptionphrase' ) : $this->restore_options['backupplus_encryptionphrase'];

				if ( ! $encryption ) {
					return new WP_Error( 'no_encryption_key', __( 'Decryption failed.', 'backupplus' ) . ' ' . __( 'The database file is encrypted, but you have no encryption key entered.', 'backupplus' ) );
				}

				// function decrypt
				$decrypted_file = BackupPlus_Encryption::decrypt( $backup_dir . $package, $encryption );
			}

			if ( is_array( $decrypted_file ) ) {
				$this->skin->feedback( $this->strings['decrypted_database'] );
				if ( ! copy( $decrypted_file['fullpath'], $working_dir . '/backup.db.gz' ) ) { // phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- False positive: the 'copy()' function doesn't put the file in the plugin directory, it puts it in the 'upgrade' directory instead.
					return new WP_Error( 'write_failed', __( 'Failed to write out the decrypted database to the filesystem', 'backupplus' ) );
				} else {
					unlink( $decrypted_file['fullpath'] );
				}
			} else {
				return new WP_Error( 'decryption_failed', __( 'Decryption failed.', 'backupplus' ) . ' ' . __( 'The most likely cause is that you used the wrong key.', 'backupplus' ) );
			}
		} else {
			if ( preg_match( '/\.sql$/i', $package ) ) {
				if ( ! $wp_filesystem->copy( $backup_dir . $package, $working_dir . '/backup.db' ) ) {
					if ( $wp_filesystem->errors->get_error_code() ) {
						foreach ( $wp_filesystem->errors->get_error_messages() as $message ) {
							show_message( $message );
						}
					}
					return new WP_Error( 'copy_failed', $this->strings['copy_failed'] );
				}
			} elseif ( preg_match( '/\.bz2$/i', $package ) ) {
				if ( ! $wp_filesystem->copy( $backup_dir . $package, $working_dir . '/backup.db.bz2' ) ) {
					if ( $wp_filesystem->errors->get_error_code() ) {
						foreach ( $wp_filesystem->errors->get_error_messages() as $message ) {
							show_message( $message );
						}
					}
					return new WP_Error( 'copy_failed', $this->strings['copy_failed'] );
				}
			} elseif ( ! $wp_filesystem->copy( $backup_dir . $package, $working_dir . '/backup.db.gz' ) ) {
				if ( $wp_filesystem->errors->get_error_code() ) {
					foreach ( $wp_filesystem->errors->get_error_messages() as $message ) {
						show_message( $message );
					}
				}
				return new WP_Error( 'copy_failed', $this->strings['copy_failed'] );
			}
		}

		// Once extracted, delete the package if required (non-recursive, is a file)
		// if ($delete_package) $wp_filesystem->delete($decrypted_file['fullpath'], false, true);
		if ( $delete_package ) {
			if ( ! $wp_filesystem->delete( $backup_dir . $package, false, true ) ) {
				$this->restore_log_permission_failure_message( $backup_dir, 'Delete ' . $backup_dir . $package );
			}
		}

		$backupplus->log( 'Database successfully unpacked' );

		return $working_dir;
	}

	private function get_wp_filesystem_dir( $path ) {
		global $wp_filesystem;
		// Get the wp_filesystem location for the folder on the local install
		switch ( $path ) {
			case ABSPATH:
			case '':
				$wp_filesystem_dir = $wp_filesystem->abspath();
				break;
			case WP_CONTENT_DIR:
				$wp_filesystem_dir = $wp_filesystem->wp_content_dir();
				break;
			case WP_PLUGIN_DIR:
				$wp_filesystem_dir = $wp_filesystem->wp_plugins_dir();
				break;
			case WP_CONTENT_DIR . '/themes':
				$wp_filesystem_dir = $wp_filesystem->wp_themes_dir();
				// If the themes directory does not exist then it's possible to get a broken path, confirm and try to resolve this
				if ( ! $wp_filesystem->exists( $wp_filesystem_dir ) && $wp_filesystem_dir == $wp_filesystem->find_folder( WP_CONTENT_DIR . get_theme_root() ) && $wp_filesystem->exists( $wp_filesystem->wp_content_dir() ) ) {
					$wp_filesystem_dir = $wp_filesystem->find_folder( WP_CONTENT_DIR . '/themes' );
				}
				break;
			default:
				$wp_filesystem_dir = $wp_filesystem->find_folder( $path );
				break;
		}
		if ( ! $wp_filesystem_dir ) {
			return false;
		}
		return untrailingslashit( $wp_filesystem_dir );
	}

	private function search_for_folder( $folder, $startat ) {
		if ( ! is_dir( $startat ) ) {
			return false;
		}
		// Exists in this folder?
		if ( is_dir( $startat . '/' . $folder ) ) {
			return trailingslashit( $startat ) . $folder;
		}
		// Does not
		if ( $handle = opendir( $startat ) ) {
			while ( ( $file = readdir( $handle ) ) !== false ) {
				if ( '.' != $file && '..' != $file && is_dir( $startat ) . '/' . $file ) {
					$ss = $this->search_for_folder( $folder, trailingslashit( $startat ) . $file );
					if ( is_string( $ss ) ) {
						return $ss;
					}
				}
			}
			closedir( $handle );
		}
		return false;
	}

	/**
	 * This will return the path with the actual content we want to restore, ignoring any other files that may be in the top level of the zip file
	 * $dirnames: an array of preferred names
	 *
	 * @param  string $working_dir specify working directory
	 * @param  string $dirnames    directory names
	 * @return string the final path with the content we want to restore
	 */
	public function get_first_directory( $working_dir, $dirnames ) {
		global $wp_filesystem, $backupplus;
		$fdirnames = array_flip( $dirnames );
		$dirlist   = $wp_filesystem->dirlist( $working_dir, true, false );
		if ( is_array( $dirlist ) ) {
			$move_from = false;
			foreach ( $dirlist as $name => $struct ) {
				if ( isset( $struct['type'] ) && 'd' != $struct['type'] ) {
					continue;
				}
				if ( false === $move_from ) {
					if ( isset( $fdirnames[ $name ] ) ) {
						$move_from = $working_dir . '/' . $name;
					} elseif ( preg_match( '/^([^\.].*)$/', $name, $fmatch ) ) {
						// In the case of a third-party backup, the first entry may be the wrong entity. We could try a more sophisticated algorithm, but a third party backup requiring one has never been seen (and it is not easy to envisage what the algorithm might be).
						if ( empty( $this->ud_foreign ) ) {
							$first_entry = $working_dir . '/' . $fmatch[1];
						}
					}
				}
			}
			if ( false === $move_from && isset( $first_entry ) ) {
				$backupplus->log_e( 'Using directory from backup: %s', basename( $first_entry ) );
				$move_from = $first_entry;
			}
		} else {
			// That shouldn't happen. Fall back to default
			$move_from = $working_dir . '/' . $dirnames[0];
		}
		return $move_from;
	}
}
