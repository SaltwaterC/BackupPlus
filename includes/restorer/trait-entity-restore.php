<?php

/** Restoring one entity, including pruning files an incremental chain deleted. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Restorer_Entity_Restore {

	/**
	 * $backup_file is just the basename, and must be a string; we expect the caller to deal with looping over an array (multi-archive sets). We do, however, record whether we have already unpacked an entity of the same type - so that we know to add (not replace).
	 *
	 * @param String  $backup_file name of file being backed up
	 * @param String  $type        type of file
	 * @param Array   $info        information array
	 * @param Boolean $last_one    indicate if this is the last file to be restored
	 * @param Boolean $last_entity indicate if this is the last entity of this type to be restored
	 * @return WP_Error|Boolean - true if successful; otherwise false or an error
	 */
	private function restore_backup( $backup_file, $type, $info, $last_one = false, $last_entity = false ) {

		global $wp_filesystem, $backupplus;

		if ( preg_match( '/^db[1-9][0-9]*$/D', $type ) ) {
			try {
				$connection = BackupPlus_Source_Restore::connection( $type, $this->restore_options );
				$path       = $backupplus->backups_dir_location() . '/' . basename( $backup_file );
				if ( BackupPlus_Encryption::is_file_encrypted( $backup_file ) ) {
					$prepared = BackupPlus_Age_Restore::file( $path, isset( $this->restore_options['backupplus_age_ticket'] ) ? $this->restore_options['backupplus_age_ticket'] : '', $backupplus->nonce );
					if ( ! $prepared ) { return new WP_Error( 'decrypt_failed', 'Prepare the encrypted external dump again.' ); }
					$path = $prepared['fullpath'];
				}
				return BackupPlus_External_Importer::restore( $path, $type, $connection );
			} catch ( Exception $e ) { return new WP_Error( 'external_restore_failed', $e->getMessage() ); }
		}
		if ( BackupPlus_Backup_Sources::custom( $type ) ) {
			$working = $this->unpack_package_archive( $backup_file, $type );
			if ( is_wp_error( $working ) ) { return $working; }
			try { return BackupPlus_Source_Restore::restore( $working, $type, $this->ud_backup_set, $this->restore_options, $last_entity ); }
			catch ( Exception $e ) { return new WP_Error( 'custom_restore_failed', $e->getMessage() ); }
		}
		$backupplus->log( "restore_backup(backup_file=$backup_file, type=$type, info=" . serialize( $info ) . ", last_one=$last_one)" );

		if ( 'db' == $type ) {
			$get_dir = '';
		} else {
			$entity_path = isset( $info['path'] ) ? $info['path'] : '';

			$path = apply_filters( 'backupplus_restore_path', $entity_path, $backup_file, $this->ud_backup_set, $type );

			$get_dir = empty( $path ) ? '' : $path;
		}

		if ( false === ( $wp_filesystem_dir = $this->get_wp_filesystem_dir( $get_dir ) ) ) {
			return false;
		}

		if ( empty( $this->abspath ) ) {
			$this->abspath = trailingslashit( $wp_filesystem->abspath() );
		}

		// We copy the variable because we may be importing with a different prefix (e.g. on multisite imports of individual blog data)
		// The filter allows you to restore to a completely different prefix - i.e. don't replace this site; possibly useful for testing the restore process (but not yet tested)
		$import_table_prefix = apply_filters( 'backupplus_restore_table_prefix', $backupplus->get_table_prefix( false ) );

		$this->import_table_prefix       = $import_table_prefix;
		$this->final_import_table_prefix = $backupplus->get_table_prefix( false );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 1800 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}
		// Based upon how WP updates work, the following code unpacks the "package" (the backup archive) and then later its contents are moved to their destination.

		// If successful, this returns the WP_Filesystem path for the unpacked zip or unencrypted database
		if ( preg_match( '/-db(\.gz(\.crypt)?)?$/i', $backup_file ) || preg_match( '/\.sql(\.gz|\.bz2|\.zst)?(\.crypt)?$/i', $backup_file ) ) {
			$working_dir = $this->unpack_package_database( $backup_file );
		} else {
			$working_dir = $this->unpack_package_archive( $backup_file, $type );
		}

		if ( is_wp_error( $working_dir ) ) {
			return $working_dir;
		}

		// Allow developers to modify the temporary unzip path
		$working_dir_localpath = apply_filters( 'backupplus_working_dir_localpath', WP_CONTENT_DIR . '/upgrade/' . basename( $working_dir ) );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 1800 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- The call is speculative
		}
		// Allow extensions to either perform the move-in instead of the code below, or to modify the destination directory
		$now_done = apply_filters( 'backupplus_pre_restore_move_in', false, $type, $working_dir, $info, $this->ud_backup_set, $this, $wp_filesystem_dir );

		if ( is_wp_error( $now_done ) ) {
			return $now_done;
		}

		// If a string is returned from the filter, then this indicates to proceed but with a different destination directory (e.g. restoring into a sub-site's site-specific directory)
		if ( is_string( $now_done ) ) {
			$wp_filesystem_dir = $now_done;
			$now_done          = false;
			$do_not_move_old   = true;
		}

		if ( ! $now_done ) {

			if ( 'db' == $type ) {
				$backupplus->log_restore_update(
					array(
						'type'  => 'state',
						'stage' => 'db',
						'data'  => array(
							'stage' => 'begun',
							'table' => '',
						),
					)
				);
				$restored_db = $this->restore_backup_db( $working_dir, $working_dir_localpath, $import_table_prefix );
				if ( false === $restored_db || is_wp_error( $restored_db ) ) {
					return $restored_db;
				}
				$backupplus->log_restore_update(
					array(
						'type'  => 'state',
						'stage' => 'db',
						'data'  => array(
							'stage' => 'finished',
							'table' => '',
						),
					)
				);
			} elseif ( 'wpcore' === $type && empty( $this->ud_foreign ) ) {
				$move_in = $this->move_backup_in( $working_dir, trailingslashit( $wp_filesystem_dir ), self::MOVEIN_COPY_IN_CONTENTS, array( 'backupplus-manifest.json' ), 'wpcore' );
				if ( is_wp_error( $move_in ) || ! $move_in ) { return $move_in ?: new WP_Error( 'core_move_failed', 'Could not restore WordPress core files.' ); }
				$this->been_restored['wpcore'] = true;
			} elseif ( 'others' == $type ) {

				// For foreign 'Simple Backup', we need to keep going down until we find wp-content
				if ( empty( $this->ud_foreign ) ) {
					$move_from = $working_dir;
				} else {
					$move_from = $this->search_for_folder( 'wp-content', $working_dir );
					if ( ! is_string( $move_from ) ) {
						return new WP_Error( 'not_found', __( 'The WordPress content folder (wp-content) was not found in this zip file.', 'backupplus' ) );
					}
				}

				// In this special case, the backup contents are not in a folder, so it is not simply a case of moving the folder around, but rather looping over all that we find

				// On subsequent archives of a multi-archive set, don't move anything; but do on the first
				$preserve_existing = isset( $this->been_restored['others'] ) ? self::MOVEIN_COPY_IN_CONTENTS : self::MOVEIN_MAKE_BACKUP_OF_EXISTING;

				$preserve_existing = apply_filters( 'backupplus_move_others_preserve_existing', $preserve_existing, $this->been_restored, $this->restore_options, $this->ud_backup_set );

				$new_move_from = apply_filters( 'backupplus_restore_backup_move_from', $move_from, 'others', $this->restore_options, $this->ud_backup_set );

				if ( $new_move_from != $move_from && 0 === strpos( $new_move_from, $move_from ) ) {
					$new_suffix         = substr( $new_move_from, strlen( $move_from ) );
					$wp_filesystem_dir .= $new_suffix;
					$move_from          = $new_move_from;
				}

				$move_in = $this->move_backup_in( $move_from, trailingslashit( $wp_filesystem_dir ), $preserve_existing, array( 'plugins', 'themes', 'uploads', 'upgrade', 'backupplus-manifest.json' ), 'others' );

				if ( is_wp_error( $move_in ) ) {
					return $move_in;
				}
				if ( ! $move_in ) {
					return new WP_Error( 'new_move_failed', $this->strings['new_move_failed'] );
				}

				$this->been_restored['others'] = true;

			} else {

				// Default action: used for plugins, themes and uploads (and wpcore, via a filter)
				// Multi-archive sets: we record what we've already begun on, and on subsequent runs, copy in instead of replacing
				$movedin = apply_filters( 'backupplus_restore_movein_' . $type, $working_dir, $this->abspath, $wp_filesystem_dir );

				// A filter, to allow add-ons to perform the install of non-standard entities, or to indicate that it's not possible
				if ( false === $movedin ) {
					$this->skin->feedback( $this->strings['not_possible'] );
				} elseif ( is_wp_error( $movedin ) ) {
					return $movedin;
				} elseif ( true !== $movedin ) {

					// We get the directory to move from early, in case there is a problem with the backup that affects the result - we want to detect that before moving existing data out of the way

					$short_circuit = false;

					// For foreign 'Simple Backup', we need to keep going down until we find wp-content
					if ( empty( $this->ud_foreign ) ) {
						$working_dir_use = $working_dir;
					} else {
						$working_dir_use = $this->search_for_folder( 'wp-content', $working_dir );
						if ( ! is_string( $working_dir_use ) ) {
							if ( empty( $this->ud_foreign ) || ! apply_filters( 'backupplus_foreign_allow_missing_entity', false, $type, $this->ud_foreign ) ) {
								return new WP_Error( 'not_found', __( 'The WordPress content folder (wp-content) was not found in this zip file.', 'backupplus' ) );
							} else {
								$short_circuit = true;
							}
						}
					}

					// The backup may not actually have /$type, since that is info from the present site
					$move_from = $this->get_first_directory( $working_dir_use, array( basename( $path ), $type ) );
					if ( false !== $move_from ) {
						$move_from = apply_filters( 'backupplus_restore_backup_move_from', $move_from, $type, $this->restore_options, $this->ud_backup_set );
					}

					if ( false === $move_from ) {
						if ( ! empty( $this->ud_foreign ) && ! apply_filters( 'backupplus_foreign_allow_missing_entity', false, $type, $this->ud_foreign ) ) {
							return new WP_Error( 'new_move_failed', $this->strings['new_move_failed'] );
						}
					}

					// On the first time, create the -old directory in backupplus_dir
					// (Old style was: On the first time, move the existing data to -old)
					if ( ! isset( $this->been_restored[ $type ] ) && empty( $do_not_move_old ) ) {
						$this->move_existing_to_old( $type, $get_dir, $wp_filesystem, $wp_filesystem_dir );
					}

					if ( empty( $short_circuit ) ) {

						if ( false === $move_from ) {
							if ( ! empty( $this->ud_foreign ) && ! apply_filters( 'backupplus_foreign_allow_missing_entity', false, $type, $this->ud_foreign ) ) {
								return new WP_Error( 'new_move_failed', $this->strings['new_move_failed'] );
							}
						} else {

							$this->skin->feedback( $this->strings['moving_backup'] );

							$move_in = $this->move_backup_in( $move_from, trailingslashit( $wp_filesystem_dir ), self::MOVEIN_COPY_IN_CONTENTS, array(), $type );

							if ( is_wp_error( $move_in ) ) {
								return $move_in;
							}
							if ( ! $move_in ) {
								return new WP_Error( 'new_move_failed', $this->strings['new_move_failed'] );
							}

							if ( ! $wp_filesystem->rmdir( $move_from ) ) {
								$this->restore_log_permission_failure_message( dirname( $move_from ), 'Delete ' . $move_from );
							}
						}
					}
				}

				$this->been_restored[ $type ] = true;

			}
		}

		// Attempt deletion if relevant
		if ( empty( $this->ud_foreign ) || $last_one ) {

			// Non-recursive, so the directory needs to be empty
			$this->skin->feedback( $this->strings['cleaning_up'] );

			if ( ! empty( $do_not_move_old ) ) {
				@$wp_filesystem->delete( $working_dir . '/' . $type );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.
			}
			// Foreign backups can contain extra data and thus leave stuff behind, thus causing errors
			$recurse = empty( $this->ud_foreign ) ? false : true;
			$recurse = apply_filters( 'backupplus_restore_delete_recursive', $recurse, $this->ud_foreign, $this->restore_options, $type );

			if ( ! $wp_filesystem->delete( $working_dir, $recurse ) ) {

				// Can remove this after 1-Jan-2015; or at least, make it so that it requires the version number to be present.
				$fixed_it_now = false;
				// Deal with a corner-case in version 1.8.5
				if ( 'uploads' == $type && ( empty( $this->created_by_version ) || ( version_compare( $this->created_by_version, '1.8.5', '>=' ) && version_compare( $this->created_by_version, '1.8.8', '<' ) ) ) ) {
					$backupplus->log( 'Clean-up failed with uploads: will attempt 1.8.5-1.8.7 fix (' . $this->created_by_version . ')' );
					$move_in = @$this->move_backup_in( dirname( $move_from ), trailingslashit( $wp_filesystem_dir ), 3, array(), $type );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.
					$backupplus->log( 'Result: ' . serialize( $move_in ) );
					if ( $wp_filesystem->delete( $working_dir ) ) {
						$fixed_it_now = true;
					}
				}

				if ( file_exists( $working_dir . DIRECTORY_SEPARATOR . 'backupplus-manifest.json' ) ) {
					// Before we cleanup and remove the manifest check if this is the last entity of this type, if it is then we want to remove anything that no longer exists in this manifest
					if ( $last_entity ) {
						$incremental_restore_prune = $this->incremental_restore_prune_files( $working_dir, $type, $wp_filesystem_dir );
						if ( is_wp_error( $incremental_restore_prune ) ) {
							return $incremental_restore_prune;
						}
					}

					$wp_filesystem->delete( $working_dir . DIRECTORY_SEPARATOR . 'backupplus-manifest.json' );
					if ( $wp_filesystem->delete( $working_dir ) ) {
						$fixed_it_now = true;
					}
				}

				if ( ! $fixed_it_now ) {
					$backupplus->log_e( 'Error: %s', $this->strings['delete_failed'] . ' (' . $working_dir . ')' );
					// List contents
					// No need to make this a restoration-aborting error condition - it's not
					$dirlist = $wp_filesystem->dirlist( $working_dir, true, true );
					if ( is_array( $dirlist ) ) {
						$backupplus->log( __( 'Files found:', 'backupplus' ), 'notice-restore' );
						foreach ( array_keys( $dirlist ) as $name ) {
							$backupplus->log( "* $name", 'notice-restore' );
						}
					} else {
						$backupplus->log_e( 'Unable to enumerate files in that directory.' );
					}
				}
			}
		}

		// Permissions changes (at the top level - i.e. this does not apply if using recursion) are now *additive* - i.e. there's no danger of permissions being removed from what's on-disk
		switch ( $type ) {
			case 'wpcore':
				$this->adjust_auto_prepend_directive();
				$this->chmod_if_needed( $wp_filesystem_dir, FS_CHMOD_DIR, false, $wp_filesystem );
				// In case we restored a .htaccess which is incorrect for the local setup
				$this->flush_rewrite_rules();
				break;
			case 'uploads':
				$this->chmod_if_needed( $wp_filesystem_dir, FS_CHMOD_DIR, false, $wp_filesystem );
				break;
			case 'themes':
				// Cherry Framework needs its cache files removing after migration
				if ( ( empty( $this->old_siteurl ) || ( $this->old_siteurl != $this->our_siteurl ) ) && function_exists( 'glob' ) ) {
					$cherry_child = glob( WP_CONTENT_DIR . '/themes/theme*' );
					if ( is_array( $cherry_child ) ) {
						foreach ( $cherry_child as $theme ) {
							if ( file_exists( $theme . '/style.less.cache' ) ) {
								unlink( $theme . '/style.less.cache' );
							}
							if ( file_exists( $theme . '/bootstrap/less/bootstrap.less.cache' ) ) {
								unlink( $theme . '/bootstrap/less/bootstrap.less.cache' );
							}
						}
					}
				}
				break;
			case 'db':
				if ( function_exists( 'wp_cache_flush' ) ) {
					wp_cache_flush();
				}
				do_action(
					'backupplus_restored_db',
					array(
						'expected_oldsiteurl' => $this->old_siteurl,
						'expected_oldhome'    => $this->old_home,
						'expected_oldcontent' => $this->old_content,
					),
					$import_table_prefix
				);

				// N.B. flush_rewrite_rules() causes $wp_rewrite to become up to date again - important for the no_mod_rewrite() call
				$this->flush_rewrite_rules();

				if ( $backupplus->mod_rewrite_unavailable() ) {
					$backupplus->log( 'Using Apache, with permalinks (' . get_option( 'permalink_structure' ) . ') but no mod_rewrite enabled - enable it to make your permalinks work' );
					/* translators: 1: String 'Apache', 2: String 'mod_rewrite' */
					$warn_no_rewrite = sprintf( __( 'You are using the %1$s webserver, but do not seem to have the %2$s module loaded.', 'backupplus' ), 'Apache', 'mod_rewrite' ) . ' ' .
						/* translators: 1: String 'mod_rewrite', 2: String 'http://example.com/my-page/' */
						sprintf( __( 'You should enable %1$s to make any pretty permalinks (e.g. %2$s) work', 'backupplus' ), 'mod_rewrite', 'http://example.com/my-page/' );
					$backupplus->log( $warn_no_rewrite, 'warning-restore' );
				}
				break;
			default:
				$this->chmod_if_needed( $wp_filesystem_dir, FS_CHMOD_DIR, false, $wp_filesystem );
		}
		// db was already done
		if ( 'db' != $type ) {
			do_action( 'backupplus_restored_' . $type );
		}

		return true;
	}

	/**
	 * This method will read in the latest manifest file for an entity type and start the file prune.
	 *
	 * @param  string $working_dir - the directory we are working in
	 * @param  string $type        - the type of file
	 * @return boolean|WP_Error
	 */
	private function incremental_restore_prune_files( $working_dir, $type, $destination = null ) {
		// Check file exists again just in case it some how got removed
		$manifest_file = $working_dir . DIRECTORY_SEPARATOR . 'backupplus-manifest.json';
		if ( file_exists( $manifest_file ) && filesize( $manifest_file ) > 0 ) {
			$entity_manifest  = file_get_contents( $working_dir . DIRECTORY_SEPARATOR . 'backupplus-manifest.json' );
			$decoded_manifest = json_decode( $entity_manifest, true );
			if ( null === $decoded_manifest ) {
				// 2.16.0 could fail to put a comma after the first 'files' item
				$entity_manifest  = preg_replace( '/files":(.*)""/', 'files":$1","', $entity_manifest );
				$decoded_manifest = json_decode( $entity_manifest, true );
				if ( null === $decoded_manifest ) {
					return new WP_Error( 'decode_manifest_failed', 'Failed to JSON-decode the manifest file' );
				}
				global $backupplus;
				$backupplus->log( 'Manifest file had invalid JSON, but it was successfully patched' );
			}
			if ( isset( $decoded_manifest['version'] ) && 2 === $decoded_manifest['version'] ) {
				return $this->incremental_restore_deleted_paths( $decoded_manifest, $type, $destination );
			}
			$base_path = trailingslashit( WP_CONTENT_DIR );
			$path      = $base_path . $type;
			return $this->incremental_restore_scan_dir( $base_path, $path, 1, $decoded_manifest );
		} else {
			return new WP_Error( 'manifest_not_found', $this->strings['manifest_not_found'] );
		}
	}

	/** Apply cumulative deletions only within this entity's actual restore destination. */
	private function incremental_restore_deleted_paths( $manifest, $type, $destination ) {
		global $wp_filesystem;
		if ( ! $destination || ! isset( $manifest['type'], $manifest['root'], $manifest['deleted'] ) || $type !== $manifest['type'] || ! is_array( $manifest['deleted'] ) ) {
			return new WP_Error( 'read_manifest_failed', 'Invalid incremental restore manifest.' );
		}
		$paths = array();
		foreach ( $manifest['deleted'] as $relative ) {
			if ( ! is_string( $relative ) || preg_match( '#(^/|[\\\\:\x00]|(^|/)\.\.?(/|$)|//)#', $relative ) ) {
				return new WP_Error( 'read_manifest_failed', 'Unsafe path in incremental restore manifest.' );
			}
			if ( ! in_array( $type, array( 'others', 'wpcore' ), true ) ) {
				$prefix = $manifest['root'] . '/';
				if ( 0 !== strpos( $relative, $prefix ) ) {
					return new WP_Error( 'read_manifest_failed', 'Incremental deletion is outside the restored component.' );
				}
				$relative = substr( $relative, strlen( $prefix ) );
			}
			if ( '' === $relative ) {
				return new WP_Error( 'read_manifest_failed', 'Invalid incremental deletion path.' );
			}
			$component = explode( '/', $relative )[0];
			if ( 'plugins' === $type && ( in_array( $component, $this->plugins_to_skip, true ) || ( ! empty( $this->plugins_to_restore ) && ! $this->include_unspecified_plugins && ! in_array( $component, $this->plugins_to_restore, true ) ) ) ) {
				continue;
			}
			if ( 'themes' === $type && ( in_array( $component, $this->themes_to_skip, true ) || ( ! empty( $this->themes_to_restore ) && ! $this->include_unspecified_themes && ! in_array( $component, $this->themes_to_restore, true ) ) ) ) {
				continue;
			}
			if ( 'wpcore' === $type && 'wp-config.php' === $relative && empty( $this->restore_options['backupplus_restorer_wpcore_includewpconfig'] ) ) { continue; }
			$paths[] = trailingslashit( $destination ) . $relative;
		}
		// Children precede their deleted parents. Never follow a parent symlink outside the destination.
		usort(
			$paths,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		foreach ( $paths as $path ) {
			$parent = dirname( $path );
			while ( strlen( $parent ) > strlen( untrailingslashit( $destination ) ) ) {
				if ( is_link( $parent ) ) {
					return new WP_Error( 'read_manifest_failed', 'Incremental deletion traverses a symbolic link.' );
				}
				$parent = dirname( $parent );
			}
			if ( $wp_filesystem->exists( $path ) && ! $wp_filesystem->delete( $path, true ) ) {
				return new WP_Error( 'incremental_delete_failed', 'Could not remove a deleted file during incremental restore.' );
			}
		}
		return true;
	}

	/**
	 * This method will recursively scan each directory to the given listed_level which is located in the manifest and prune and files or folders that do not exist in the manifest.
	 *
	 * @param string  $base_path       - the base path of the entity type
	 * @param string  $path            - the current path we are scanning
	 * @param integer $current_level   - the level we are currently scanning at
	 * @param array   $entity_manifest - the manifest array which includes the listed_level, directories and files to keep
	 * @return boolean|WP_Error
	 */
	private function incremental_restore_scan_dir( $base_path, $path, $current_level, $entity_manifest ) {

		global $wp_filesystem;

		$directory_level    = $entity_manifest['listed_levels'];
		$entity_directories = $entity_manifest['contents']['directories'];
		$entity_files       = $entity_manifest['contents']['files'];

		if ( ! isset( $directory_level ) || ! isset( $entity_directories ) || ! isset( $entity_files ) ) {
			return new WP_Error( 'read_manifest_failed', $this->strings['read_manifest_failed'] );
		}

		$directory_files = $wp_filesystem->dirlist( $path );

		if ( isset( $directory_files ) ) {
			foreach ( $directory_files as $file => $filestruc ) {
				if ( $wp_filesystem->is_dir( $path . DIRECTORY_SEPARATOR . $file ) ) {
					$directory = $path . DIRECTORY_SEPARATOR . $file;
					// Check if we should go deeper in the file path, if not then check if this directory exists in the manifest, if not then remove it.
					if ( $current_level + 1 < $directory_level ) {
						$incremental_restore_prune = $this->incremental_restore_scan_dir( $base_path, $directory, $current_level + 1, $entity_manifest );
						if ( is_wp_error( $incremental_restore_prune ) ) {
							return $incremental_restore_prune;
						}
					} else {
						$directory = str_replace( $base_path, '', $directory );
						if ( ! in_array( $directory, $entity_directories ) ) {
							$wp_filesystem->delete( $base_path . $directory, true );
						}
					}
				} else {
					$file = str_replace( $base_path, '', $path . DIRECTORY_SEPARATOR . $file );
					if ( ! in_array( $file, $entity_files ) ) {
						$wp_filesystem->delete( $base_path . $file, false );
					}
				}
			}

			return true;
		} else {
			return new WP_Error( 'read_working_dir_failed', $this->strings['read_working_dir_failed'] );
		}
	}
}
