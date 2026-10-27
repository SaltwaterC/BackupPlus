<?php

/** Moving unpacked files into place and setting aside what they replace. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Restorer_File_Restore {

	/** Whether a top-level plugin or theme is included in this restore. */
	private function restore_component_selected( $type, $component ) {
		if ( 'plugins' === $type ) {
			return ! in_array( $component, $this->plugins_to_skip, true ) && ( empty( $this->plugins_to_restore ) || $this->include_unspecified_plugins || in_array( $component, $this->plugins_to_restore, true ) );
		}
		if ( 'themes' === $type ) {
			return ! in_array( $component, $this->themes_to_skip, true ) && ( empty( $this->themes_to_restore ) || $this->include_unspecified_themes || in_array( $component, $this->themes_to_restore, true ) );
		}
		return true;
	}

	/**
	 * For moving files out of a directory into their new location
	 * The purposes of the $type parameter are 1) to detect 'others' and apply a historical bugfix 2) to detect wpcore, and apply the setting for what to do with wp-config.php 3) to work out whether to delete the directory itself
	 * Must use only wp_filesystem
	 * $dest_dir must already have a trailing slash
	 *
	 * Public so restore integrations can reuse this method.
	 *
	 * @param  String  $working_dir       specify working directory
	 * @param  String  $dest_dir          specify destination directory (a WP_Filesystem path)
	 * @param  Integer $preserve_existing this setting only applies at the top level: 0 = overwrite with no backup; 1 = make backup of existing; 2 = do nothing if there is existing, 3 = do nothing to the top level directory, but do copy-in contents (and over-write files). Thus, on a multi-archive set where you want a backup, you'd do this: first call with $preserve_existing === 1, then on subsequent zips call with 3
	 * @param  Array   $do_not_overwrite  Specify files or directories not to overwrite
	 * @param  String  $type              specify type
	 * @param  Boolean $send_actions      send actions
	 * @param  Boolean $force_local       if set, then this will ensure that move operations are doing using rename() (i.e. direct filesystem access)
	 * @param  Boolean $moving_old        indicates if we are moving old files
	 *
	 * @return Boolean
	 */
	public function move_backup_in( $working_dir, $dest_dir, $preserve_existing = 1, $do_not_overwrite = array( 'plugins', 'themes', 'uploads', 'upgrade' ), $type = 'not-others', $send_actions = false, $force_local = false, $moving_old = false ) {

		global $wp_filesystem, $backupplus;
		$backupplus_dir = $backupplus->backups_dir_location();

		if ( true == $force_local || is_a( $wp_filesystem, 'WP_Filesystem_Direct' ) ) {
			$wpfs = new BackupPlus_WP_Filesystem_Direct( true );
		} else {
			$wpfs = $wp_filesystem;
		}

		// Get the content to be moved in. Include hidden files = true. Recursion is only required if we're likely to copy-in
		$recursive     = ( self::MOVEIN_COPY_IN_CONTENTS === $preserve_existing ) ? true : false;
		$upgrade_files = $wpfs->dirlist( $working_dir, true, $recursive );

		if ( empty( $upgrade_files ) ) {
			return true;
		}

		// check if our path is a file by looking to see if it's in the list of files we want to restore; if it is then remove the file from the path
		if ( isset( $upgrade_files[ basename( $dest_dir ) ] ) && 'f' === $upgrade_files[ basename( $dest_dir ) ]['type'] ) {
			$dest_dir = trailingslashit( dirname( $dest_dir ) );
		}

		if ( ! $wpfs->is_dir( $dest_dir ) ) {
			if ( $wpfs->is_dir( dirname( $dest_dir ) ) ) {
				if ( ! $wpfs->mkdir( $dest_dir, FS_CHMOD_DIR ) ) {
					return new WP_Error( 'mkdir_failed', __( 'The directory does not exist, and the attempt to create it failed', 'backupplus' ) . ' (' . $dest_dir . ')' );
				}
				$backupplus->log( "Destination directory did not exist, but was successfully created ($dest_dir)" );
			} else {
				return new WP_Error( 'no_such_dir', __( 'The directory does not exist', 'backupplus' ) . " ($dest_dir)" );
			}
		}

		if ( 'plugins' == $type || 'themes' == $type ) {
			$upgrade_files_log = implode( ', ', array_keys( $upgrade_files ) );
			if ( 'plugins' == $type && ! empty( $this->plugins_to_restore ) && ! $this->include_unspecified_plugins ) {
				$upgrade_files_log = implode( ', ', $this->plugins_to_restore );
			}
			if ( 'themes' == $type && ! empty( $this->themes_to_restore ) && ! $this->include_unspecified_themes ) {
				$upgrade_files_log = implode( ', ', $this->themes_to_restore );
			}
			$backupplus->log( 'Top-level entities being moved: ' . $upgrade_files_log );
		}

		$checksums = $backupplus->which_checksums();

		foreach ( $upgrade_files as $file => $filestruc ) {

			if ( empty( $file ) ) {
				continue;
			}

			if ( $dest_dir . $file == $backupplus_dir ) {
				$backupplus->log( 'Skipping attempt to replace backupplus_dir whilst processing ' . $type );
				continue;
			}

			// Handle various hard-wired and backwards-compatibility cases
			$special_case = $this->carry_out_restore_special_cases( $type, $file, $working_dir, $wpfs );
			if ( ! empty( $special_case['filename'] ) ) {
				$file = $special_case['filename'];
			}
			// The "do not overwrite" check is a sanity check, as these things ought to be excluded at backup time
			if ( ! empty( $special_case['result'] ) || in_array( $file, $do_not_overwrite ) ) {
				continue;
			}

			// Skip plugins which the user specified to be skipped. This may include deleting them from the source directory so that it is clean at the end.
			if ( 'plugins' == $type && in_array( $file, $this->plugins_to_skip ) ) {
				if ( ! $moving_old ) {
					$backupplus->log( "Skipping {$file} as user requested this plugin to not be restored.", 'notice-restore' );
					// Remove it from the temporary directory - so that it will be clean later
					@$wpfs->delete( $working_dir . '/' . $file, true );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.
				}
				continue;
			} elseif ( 'plugins' == $type && ! empty( $this->plugins_to_restore ) && ! in_array( $file, $this->plugins_to_restore ) && ! $this->include_unspecified_plugins ) {
				if ( ! $moving_old ) {
					$backupplus->log( "Skipping {$file} as it's not in the list of plugins to restore and we are not including unspecified plugins.", 'notice-restore' );
					// Remove it from the temporary directory - so that it will be clean later
					@$wpfs->delete( $working_dir . '/' . $file, true );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.
				}
				continue;
			}

			// Skip themes which the user specified to be skipped. This may include deleting them from the source directory so that it is clean at the end.
			if ( 'themes' == $type && in_array( $file, $this->themes_to_skip ) ) {
				if ( ! $moving_old ) {
					$backupplus->log( "Skipping {$file} as user requested this theme to not be restored.", 'notice-restore' );
					// Remove it from the temporary directory - so that it will be clean later
					@$wpfs->delete( $working_dir . '/' . $file, true );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.
				}
				continue;
			} elseif ( 'themes' == $type && ! empty( $this->themes_to_restore ) && ! in_array( $file, $this->themes_to_restore ) && ! $this->include_unspecified_themes ) {
				if ( ! $moving_old ) {
					$backupplus->log( "Skipping {$file} as it's not in the list of themes to restore and we are not including unspecified themes.", 'notice-restore' );
					// Remove it from the temporary directory - so that it will be clean later
					@$wpfs->delete( $working_dir . '/' . $file, true );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.
				}
				continue;
			}

			// If an existing destination is present, then move it
			if ( $wpfs->exists( $dest_dir . $file ) ) {

				if ( self::MOVEIN_MAKE_BACKUP_OF_EXISTING == $preserve_existing ) {
					if ( ! $wpfs->move( $dest_dir . $file, $dest_dir . $file . '-old', true ) ) {
						// The entity could not be moved out of the way, so is likely still there. Check its contents are already what was desired.
						if ( 'others' == $type && hash_file( $checksums[0], $working_dir . '/' . $file ) == hash_file( $checksums[0], $dest_dir . $file ) ) {
							$backupplus->log( $dest_dir . $file . ' could not be moved out of the way, but the new version had an identical hash, so the existing version will be left as-is' );
							@$wpfs->delete( $working_dir . '/' . $file, true );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced because failure to delete the source will be noted later and is not useful to record on individual files.
						} else {
							$this->restore_log_permission_failure_message( $dest_dir, 'Move ' . $dest_dir . $file . ' -> ' . $dest_dir . $file . '-old', 'Destination' );
							return new WP_Error( 'old_move_failed', $this->strings['old_move_failed'] . " ($dest_dir$file)" );
						}
					}
				} elseif ( self::MOVEIN_OVERWRITE_NO_BACKUP == $preserve_existing ) {
					if ( ! $wpfs->delete( $dest_dir . $file, true ) ) {
						if ( 'others' == $type && hash_file( $checksums[0], $working_dir . '/' . $file ) == hash_file( $checksums[0], $dest_dir . $file ) ) {
							$backupplus->log( $dest_dir . $file . ' could not be removed, but the new version had an identical hash, so the existing version will be left as-is' );
							@$wpfs->delete( $working_dir . '/' . $file, true );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced because failure to delete the source will be noted later and is not useful to record on individual files.
						} else {
							$this->restore_log_permission_failure_message( $dest_dir, 'Delete ' . $dest_dir . $file );
							return new WP_Error( 'old_delete_failed', $this->strings['old_delete_failed'] . " ($file)" );
						}
					}
				}
			}

			// Secondly, move in the new one
			$is_dir = $wpfs->is_dir( $working_dir . '/' . $file );

			if ( self::MOVEIN_DO_NOTHING_IF_EXISTING == $preserve_existing && $wpfs->exists( $dest_dir . $file ) ) {
				// Something exists - no move. Remove it from the temporary directory - so that it will be clean later
				@$wpfs->delete( $working_dir . '/' . $file, true );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.
				// The $is_dir check was added in version 1.11.18; without this, files in the top-level that weren't in the first archive didn't get over-written
			} elseif ( self::MOVEIN_COPY_IN_CONTENTS != $preserve_existing || ! $wpfs->exists( $dest_dir . $file ) || ! $is_dir ) {

				if ( $wpfs->move( $working_dir . '/' . $file, $dest_dir . $file, true ) ) {
					if ( $send_actions ) {
						do_action( 'backupplus_restored_' . $type . '_one', $file );
					}
					// Make sure permissions are at least as great as those of the parent
					if ( $is_dir ) {
						// This method is broken due to https://core.trac.wordpress.org/ticket/26598
						if ( empty( $chmod ) ) {
							$chmod = octdec( sprintf( '%04d', $this->get_current_chmod( $dest_dir, $wpfs ) ) );// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable -- The variable is defined after the check.
						}                       if ( ! empty( $chmod ) ) {
							$this->chmod_if_needed( $dest_dir . $file, $chmod, false, $wpfs );
						}
					}
				} else {

					if ( ! $is_dir ) {
						$this->restore_log_permission_failure_message( $dest_dir, 'Move ' . $working_dir . '/' . $file . ' -> ' . $dest_dir . $file, 'Destination' );
						return new WP_Error( 'move_failed', $this->strings['move_failed'], $working_dir . '/' . $file . ' -> ' . $dest_dir . $file );
					}

					$backupplus->log( sprintf( 'There was an error when performing the move operation by renaming or copying %s to %s: will attempt a recursive copy instead.', $working_dir . '/' . $file, $dest_dir . $file ), 'backupplus' );

					if ( empty( $chmod ) ) {
						$chmod = octdec( sprintf( '%04d', $this->get_current_chmod( $dest_dir, $wpfs ) ) );
					}

					if ( ! $wpfs->exists( $dest_dir . $file ) ) {
						$wpfs->mkdir( $dest_dir . $file, $chmod );
					}

					$delete_root = ( 'others' !== $type && 'wpcore' !== $type ) ? true : false;

					$copy_in = BackupPlus_Filesystem_Functions::copy_files_in( $working_dir . '/' . $file, $dest_dir . $file, $filestruc['files'], $chmod, $delete_root );

					if ( ! empty( $chmod ) ) {
						$this->chmod_if_needed( $dest_dir . $file, $chmod, false, $wpfs );
					}

					if ( is_wp_error( $copy_in ) || ! $copy_in ) {
						$this->restore_log_permission_failure_message( $dest_dir, 'Move ' . $working_dir . '/' . $file . ' -> ' . $dest_dir . $file, 'Destination' );
					}

					if ( is_wp_error( $copy_in ) ) {
						return $copy_in;
					}
					if ( ! $copy_in ) {
						return new WP_Error( 'move_failed', $this->strings['move_failed'], $working_dir . '/' . $file . ' -> ' . $dest_dir . $file );
					}

					if ( ! $wpfs->rmdir( $working_dir . '/' . $file ) ) {
						$this->restore_log_permission_failure_message( $working_dir, 'Delete ' . $working_dir . '/' . $file );
					}
				}
			} elseif ( self::MOVEIN_COPY_IN_CONTENTS == $preserve_existing && ! empty( $filestruc['files'] ) ) {
				// The directory ($dest_dir) already exists, and we've been requested to copy-in. We need to perform the recursive copy-in
				// $filestruc['files'] is then a new structure like $upgrade_files
				// First pass: create directory structure
				// Get chmod value for the parent directory, and re-use it (instead of passing false)

				// This method is broken due to https://core.trac.wordpress.org/ticket/26598
				if ( empty( $chmod ) ) {
					$chmod = octdec( sprintf( '%04d', $this->get_current_chmod( $dest_dir, $wpfs ) ) );
				}
				// Copy in the files. This also needs to make sure the directories exist, in case the zip file lacks entries
				$delete_root = ( 'others' == $type || 'wpcore' == $type ) ? false : true;

				$copy_in = BackupPlus_Filesystem_Functions::copy_files_in( $working_dir . '/' . $file, $dest_dir . $file, $filestruc['files'], $chmod, $delete_root );
				if ( ! empty( $chmod ) ) {
					$this->chmod_if_needed( $dest_dir . $file, $chmod, false, $wpfs );
				}

				if ( is_wp_error( $copy_in ) || ! $copy_in ) {
					$this->restore_log_permission_failure_message( $dest_dir, 'Move ' . $working_dir . '/' . $file . ' -> ' . $dest_dir . $file, 'Destination' );
				}
				if ( is_wp_error( $copy_in ) ) {
					return $copy_in;
				}
				if ( ! $copy_in ) {
					return new WP_Error( 'move_failed', $this->strings['move_failed'], '(2) ' . $working_dir . '/' . $file . ' -> ' . $dest_dir . $file );
				}

				if ( ! $wpfs->rmdir( $working_dir . '/' . $file ) ) {
					$this->restore_log_permission_failure_message( $working_dir, 'Delete ' . $working_dir . '/' . $file );
				}
			} else {
				if ( ! $wpfs->rmdir( $working_dir . '/' . $file ) ) {
					$this->restore_log_permission_failure_message( $working_dir, 'Delete ' . $working_dir . '/' . $file );
				}
			}
		}

		return true;
	}

	/**
	 * This method handles some special cases (the code is abstracted into this separate method for tidiness)
	 *
	 * @param String $type        - entity type, e.g. 'plugins', 'wpcore'
	 * @param String $file        - relative path
	 * @param String $working_dir - a WP_Filesystem path
	 * @param Object $wpfs        - a WP_Filesystem object to use
	 *
	 * @return Array {
	 * Array of information about the the special case that took place while moving out a file to its new location
	 * @type Boolean $result   - the only meaning of this is that if true, then the caller should carry out no further processing on the file (i.e. this method has done everything necessary)
	 * @type String  $filename - Base URL and subdirectory or absolute URL to upload directory.
	 * }
	 */
	private function carry_out_restore_special_cases( $type, $file, $working_dir, $wpfs ) {

		global $backupplus;
		static $wpcore_config_moved = false;

		$ret = array();

		// Correctly restore files in 'others' in no directory that were wrongly backed up in versions 1.4.0 - 1.4.48
		if ( ( 'others' == $type || 'wpcore' == $type ) && preg_match( '/^([\-_A-Za-z0-9]+\.php)$/i', $file, $matches ) && $wpfs->exists( $working_dir . "/$file/$file" ) ) {
			if ( 'others' == $type ) {
				$backupplus->log( "Found file: $file/$file: presuming this is a backup with a known fault (backup made with versions 1.4.0 - 1.4.48, and sometimes up to 1.6.55 on some Windows servers); will rename to simply $file", 'notice-restore' );
			} else {
				$backupplus->log( "Found file: $file/$file: presuming this is a backup with a known fault (backup made with versions before 1.6.55 in certain situations on Windows servers); will rename to simply $file", 'notice-restore' );
			}
			$backupplus->log( "$file/$file: rename to $file" );
			$file     = $matches[1];
			$tmp_file = wp_rand( 0, 999999999 ) . '.php';
			// Rename directory
			if ( ! $wpfs->move( $working_dir . "/$file", $working_dir . '/' . $tmp_file, true ) ) {
				$this->restore_log_permission_failure_message( $working_dir, 'Move ' . $working_dir . "/$file -> " . $working_dir . '/' . $tmp_file, 'Destination' );
			}
			if ( ! $wpfs->move( $working_dir . "/$tmp_file/$file", $working_dir . '/' . $file, true ) ) {
				$this->restore_log_permission_failure_message( $working_dir, 'Move ' . $working_dir . "/$tmp_file/$file -> " . $working_dir . '/' . $file, 'Destination' );
			}
			if ( ! $wpfs->rmdir( $working_dir . "/$tmp_file", false ) ) {
				$this->restore_log_permission_failure_message( $working_dir, 'Delete ' . $working_dir . "/$tmp_file" );
			}
		}

		if ( 'wp-config.php' == $file && 'wpcore' == $type ) {
			if ( empty( $this->restore_options['backupplus_restorer_wpcore_includewpconfig'] ) ) {
				$backupplus->log_e( 'wp-config.php from backup: will restore as wp-config-pre-ud-restore-backup.php', 'backupplus' );
				if ( ! $wpfs->move( $working_dir . "/$file", $working_dir . '/wp-config-pre-ud-restore-backup.php', true ) ) {
					$this->restore_log_permission_failure_message( $working_dir, 'Move ' . $working_dir . "/$file -> " . $working_dir . '/wp-config-pre-ud-restore-backup.php', 'Destination' );
					// rename failed - take no further action with this file; we should not restore unless rename successful
					$ret['result'] = true;
					return $ret;
				} else {
					$wpcore_config_moved = true;
					$ret['filename']     = 'wp-config-pre-ud-restore-backup.php';
				}
			} else {
				$backupplus->log_e( "wp-config.php from backup: restoring (as per user's request)", 'backupplus' );
			}
		} elseif ( 'wpcore' == $type && 'wp-config-pre-ud-restore-backup.php' == $file && $wpcore_config_moved ) {
			// The file is already gone; nothing to do
			$ret['result'] = true;
			return $ret;
		}

		if ( ( 'object-cache.php' == $file || 'advanced-cache.php' == $file ) && 'others' == $type ) {
			if ( false == apply_filters( 'backupplus_restorecachefiles', true, $file ) ) {
				$nfile = preg_replace( '/\.php$/', '-backup.php', $file );
				if ( ! $wpfs->move( $working_dir . "/$file", $working_dir . '/' . $nfile, true ) ) {
					$this->restore_log_permission_failure_message( $working_dir, 'Move ' . $working_dir . '/' . $file . ' -> ' . $working_dir . '/' . $nfile, 'Destination' );
					// rename failed - take no further action with this file, as `backupplus_restorecachefiles` indicated we should not restore the file without renaming it
					$ret['result'] = true;
					return $ret;
				} else {
					// the file was moved successfully, return the new filename so restore process can move it into place
					$ret['filename'] = $nfile;
				}
			}
		} elseif ( ( 'object-cache-backup.php' == $file || 'advanced-cache-backup.php' == $file ) && 'others' == $type ) {
			if ( ! $wpfs->delete( $working_dir . '/' . $file ) ) {
				$this->restore_log_permission_failure_message( $working_dir, 'Delete ' . $working_dir . '/' . $file );
			}
			$ret['result'] = true;
			return $ret;
		}

		$ret['result'] = false;
		return $ret;
	}

	/**
	 * Pre-flight check: chance to complain and abort before anything at all is done
	 *
	 * @param  Array  $backup_files - An array of backup files
	 * @param  String $type         - Type of file
	 * @param  Array  $info         - Information about the backup
	 *
	 * @return Boolean|WP_Error
	 */
	private function pre_restore_backup( $backup_files, $type, $info ) {

		if ( is_string( $backup_files ) ) {
			$backup_files = array( $backup_files );
		}

		// convert this to an array if it's not one as more files uses an array of paths
		if ( empty( $info['path'] ) ) {
			$info['path'] = array();
		}
		if ( ! is_array( $info['path'] ) ) {
			$info['path'] = array( $info['path'] );
		}

		// Ensure access to the indicated directory - and to WP_CONTENT_DIR (in which we use upgrade/)
		$need_these = array( WP_CONTENT_DIR );

		foreach ( $info['path'] as $path ) {
			$need_these[] = $path;
		}

		$res = $this->wp_upgrader->fs_connect( $need_these );
		if ( false === $res || is_wp_error( $res ) ) {
			return $res;
		}

		// Check upgrade directory is writable (instead of having non-obvious messages when we try to write)
		// In theory, this is redundant (since we already checked for access to WP_CONTENT_DIR); but in practice, this extra check has been needed

		global $wp_filesystem, $backupplus;

		if ( empty( $this->pre_restore_updatedir_writable ) ) {
			$upgrade_folder = $wp_filesystem->wp_content_dir() . 'upgrade/';
			@$wp_filesystem->mkdir( $upgrade_folder, octdec( $this->calculate_additive_chmod_oct( FS_CHMOD_DIR, 0775 ) ) );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.
			if ( ! $wp_filesystem->is_dir( $upgrade_folder ) ) {
				return new WP_Error(
					'no_dir',
					sprintf(
					/* translators: 1: String 'folder', 2: Upgrade folder path */
						__( 'BackupPlus needed to create a %1$s in your content directory, but failed - please check your file permissions and enable the access (%2$s)', 'backupplus' ),
						__( 'folder', 'backupplus' ),
						$upgrade_folder
					)
				);
			}
			$rand_file = 'testfile_' . wp_rand( 0, 9999999 ) . md5( microtime( true ) ) . '.txt';
			if ( $wp_filesystem->put_contents( $upgrade_folder . $rand_file, 'testing...' ) ) {
				@$wp_filesystem->delete( $upgrade_folder . $rand_file );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.
				$this->pre_restore_updatedir_writable = true;
			} else {
				$this->restore_log_permission_failure_message( $upgrade_folder, 'Put contents ' . $upgrade_folder . $rand_file, 'Destination' );
				return new WP_Error(
					'no_file',
					sprintf(
					/* translators: 1: String 'file', 2: Upgrade folder path with random file name */
						__( 'BackupPlus needed to create a %1$s in your content directory, but failed - please check your file permissions and enable the access (%2$s)', 'backupplus' ),
						__( 'file', 'backupplus' ),
						$upgrade_folder . $rand_file
					)
				);
			}
		}

		// Code below here assumes that we're dealing with file-based entities
		if ( 'db' == $type || preg_match( '/^db[1-9][0-9]*$/D', $type ) || BackupPlus_Backup_Sources::custom( $type ) ) {
			return true;
		}

		foreach ( $info['path'] as $path ) {
			$wp_filesystem_dir = $this->get_wp_filesystem_dir( $path );
			if ( false === $wp_filesystem_dir ) {
				return false;
			}

			$ret_val        = true;
			$backupplus_dir = $backupplus->backups_dir_location();

			if ( isset( $this->continuation_data['backupplus_ajax_restore'] ) && 'continue_ajax_restore' != $this->continuation_data['backupplus_ajax_restore'] && ( ( 'plugins' == $type || 'uploads' == $type || 'themes' == $type || 'more' == $type ) && ( ! is_multisite() || 0 !== $this->ud_backup_is_multisite ) ) ) {
				if ( file_exists( $backupplus_dir . '/' . basename( $wp_filesystem_dir ) . '-old' ) ) {
					/* translators: %s: Old path */
					$ret_val = new WP_Error( 'already_exists', sprintf( __( 'Existing unremoved folders from a previous restore exist (please use the "Delete old folders" button to delete them before trying again): %s', 'backupplus' ), $backupplus_dir . '/' . basename( $wp_filesystem_dir ) . '-old' ) );
				}
			}
		}

		if ( ! empty( $this->ud_foreign ) ) {
			$known_foreigners = apply_filters( 'backupplus_accept_archivename', array() );
			if ( ! is_array( $known_foreigners ) || empty( $known_foreigners[ $this->ud_foreign ] ) ) {
				return new WP_Error( 'uk_foreign', __( 'This version of BackupPlus does not know how to handle this type of foreign backup', 'backupplus' ) . ' (' . $this->ud_foreign . ')' );
			}
		}

		return $ret_val;
	}

	private function move_existing_to_old( $type, $get_dir, $wp_filesystem, $wp_filesystem_dir ) {

		if ( apply_filters( 'backupplus_move_existing_to_old_short_circuit', false, $type, $this->restore_options ) ) {
			// Users of the filter should do their own logging
			return;
		}

		global $backupplus;
		$backupplus_dir = $backupplus->backups_dir_location();

		// Firstly, if there's already an '-old' directory, get rid of it

		// Try filesystem-level move
		$old_dir = $backupplus_dir . '/' . $type . '-old';
		if ( is_dir( $old_dir ) ) {
			$backupplus->log_e( '%s: This directory already exists, and will be replaced', $old_dir );
			BackupPlus_Filesystem_Functions::remove_local_directory( $old_dir );
		}

		$move_old_destination = apply_filters( 'backupplus_restore_move_old_mode', 0, $type, $this->restore_options );

		if ( 0 == $move_old_destination && @mkdir( $old_dir ) ) {// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			$backupplus->log( 'Moving old data: filesystem method / backupplus_dir is potentially possible' );
			$move_old_destination = 1;
		}

		// Try wp_filesystem instead
		if ( $wp_filesystem->exists( $wp_filesystem_dir . '-old' ) ) {
			// Is better to warn and delete the restore than abort mid-restore and leave inconsistent site
			$backupplus->log_e( '%s: This directory already exists, and will be replaced', $wp_filesystem_dir . '-old' );
			// In theory, supplying true as the 3rd parameter achieves this; in practice, not always so (leads to support requests)
			$wp_filesystem->delete( $wp_filesystem_dir . '-old', true );
			if ( $wp_filesystem->exists( $wp_filesystem_dir . '-old' ) ) {
				$backupplus->log( 'Failed to remove existing directory (' . $wp_filesystem_dir . '-old' );
				$failed_to_remove = true;
			}
		}

		if ( -1 != $move_old_destination && empty( $failed_to_remove ) && @$wp_filesystem->mkdir( $wp_filesystem_dir . '-old' ) ) {// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.
			$backupplus->log( 'Moving old data: can potentially use wp_filesystem method / -old' );
			$move_old_destination += 2;
		}

		if ( 0 == $move_old_destination ) {
			$backupplus->log_e( 'File permissions do not allow the old data to be moved and retained; instead, it will be deleted.' );
		}

		$this->skin->feedback( $this->strings['moving_old'] );

		// Firstly, try direct filesystem method into backupplus_dir
		if ( $move_old_destination > 0 && 1 == $move_old_destination % 2 ) {
			// The final 'true' forces direct filesystem access
			$move_old = @$this->move_backup_in( $get_dir, $backupplus_dir . '/' . $type . '-old/', 3, array(), $type, false, true, true );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.
			if ( is_wp_error( $move_old ) ) {
				$backupplus->log_wp_error( $move_old );
			}
		}

		// Try wp_filesystem method into -old if that failed
		if ( 2 >= $move_old_destination && ( 0 == $move_old_destination % 2 || ( ! empty( $move_old ) && is_wp_error( $move_old ) ) ) ) {
			$move_old = @$this->move_backup_in( $wp_filesystem_dir, $wp_filesystem_dir . '-old/', 3, array(), $type, false, false, true );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.
			if ( is_wp_error( $move_old ) ) {
				$backupplus->log_wp_error( $move_old );
			}
		}

		// Finally, when all else fails, nuke it
		if ( -1 == $move_old_destination || 0 == $move_old_destination || ( ! empty( $move_old ) && is_wp_error( $move_old ) ) ) {
			if ( -1 == $move_old_destination ) {
				$backupplus->log( "$type: $wp_filesystem_dir: deleting contents" );
			} else {
				$backupplus->log( "$type: $wp_filesystem_dir: deleting contents (as attempts to copy failed)" );
			}
			$del_files = $wp_filesystem->dirlist( $wp_filesystem_dir, true, false );
			if ( empty( $del_files ) ) {
				$del_files = array();
			}
			foreach ( $del_files as $file => $filestruc ) {
				if ( empty( $file ) ) {
					continue;
				}
				if ( ! $this->restore_component_selected( $type, $file ) ) {
					continue;
				}
				if ( ! $wp_filesystem->delete( $wp_filesystem_dir . '/' . $file, true ) ) {
					$this->restore_log_permission_failure_message( $wp_filesystem_dir, 'Delete ' . $wp_filesystem_dir . '/' . $file );
				}
			}
		}
	}
}
