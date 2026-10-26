<?php

/** Backup entities, their directories and the locations backups are written to. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Core_Entities {

	/**
	 * This function will use the passed in information to prepare a pretty string describing the backup from the raw backup history
	 *
	 * @param String  $file      - the backup file
	 * @param Array   $history   - the backup history
	 * @param String  $entity    - the backup entity
	 * @param Array   $checksums - checksums for the backup file
	 * @param Array   $jobdata   - the jobdata for this backup
	 * @param Integer $ind       - the index of the file
	 *
	 * @return String            - returns the entity output string
	 */
	public function get_entity_row( $file, $history, $entity, $checksums, $jobdata, $ind ) {
		$op   = htmlspecialchars( $file );
		$skey = $entity . ( ( 0 == $ind ) ? '' : $ind ) . '-size';

		$op = apply_filters( 'backupplus_report_downloadable_file_link', $op, $entity, $ind, $jobdata );

		$op .= "\n";

		$meta = '';
		if ( 'db' == substr( $entity, 0, 2 ) && 'db' != $entity ) {
			$dind = substr( $entity, 2 );
			if ( is_array( $jobdata ) && ! empty( $jobdata['backup_database'] ) && is_array( $jobdata['backup_database'] ) && ! empty( $jobdata['backup_database'][ $dind ] ) && is_array( $jobdata['backup_database'][ $dind ]['dbinfo'] ) && ! empty( $jobdata['backup_database'][ $dind ]['dbinfo']['host'] ) ) {
				$dbinfo = $jobdata['backup_database'][ $dind ]['dbinfo'];
				/* translators: %s: Database connection details */
				$meta .= sprintf( __( 'External database (%s)', 'backupplus' ), $dbinfo['user'] . '@' . $dbinfo['host'] . '/' . $dbinfo['name'] ) . '<br>';
			}
		}
		/* translators: %s: Database size in MB */
		if ( isset( $history[ $skey ] ) ) {
			$meta .= sprintf( __( 'Size: %s MB', 'backupplus' ), round( $history[ $skey ] / 1048576, 1 ) );
		}
		$ckey = $entity . $ind;
		foreach ( $checksums as $ck ) {
			$ck_plain = false;
			if ( isset( $history['checksums'][ $ck ][ $ckey ] ) ) {
				/* translators: 1: Checksum type, 2: Checksum value */
				$meta    .= ( ( $meta ) ? ', ' : '' ) . sprintf( __( '%1$s checksum: %2$s', 'backupplus' ), strtoupper( $ck ), $history['checksums'][ $ck ][ $ckey ] );
				$ck_plain = true;
			}
			if ( isset( $history['checksums'][ $ck ][ $ckey . '.crypt' ] ) ) {
				if ( $ck_plain ) {
					$meta .= ' ' . __( '(when decrypted)', 'backupplus' );
				}
				/* translators: 1: Checksum type, 2: Checksum value */
				$meta .= ( ( $meta ) ? ', ' : '' ) . sprintf( __( '%1$s checksum: %2$s', 'backupplus' ), strtoupper( $ck ), $history['checksums'][ $ck ][ $ckey . '.crypt' ] );
			}
		}

		$fileinfo = apply_filters( "backupplus_fileinfo_$entity", array(), $ind );
		if ( is_array( $fileinfo ) && ! empty( $fileinfo ) ) {
			if ( isset( $fileinfo['html'] ) ) {
				$meta .= $fileinfo['html'];
			}
		}

		// if ($meta) $meta = " ($meta)";
		if ( $meta ) {
			$meta = "<br><em>$meta</em>";
		}

		return $op . $meta;
	}

	/**
	 * This important function returns a list of file entities that can potentially be backed up (subject to users settings), and optionally further meta-data about them
	 *
	 * @param boolean $include_others Whether to include "Others" in the list of entities to backup.
	 * @param boolean $full_info      Whether to include additional metadata about each entity.
	 *
	 * @return array An associative array containing information about the backupable file entities.
	 */
	public function get_backupable_file_entities( $include_others = true, $full_info = false ) {

		$wp_upload_dir = $this->wp_upload_dir();

		if ( $full_info ) {
			$arr = array(
				'plugins'    => array(
					'path'                 => untrailingslashit( WP_PLUGIN_DIR ),
					'description'          => __( 'Plugins', 'backupplus' ),
					'singular_description' => __( 'Plugin', 'backupplus' ),
				),
				'themes'     => array(
					'path'                 => WP_CONTENT_DIR . '/themes',
					'description'          => __( 'Themes', 'backupplus' ),
					'singular_description' => __( 'Theme', 'backupplus' ),
				),
				'uploads'    => array(
					'path'        => untrailingslashit( $wp_upload_dir['basedir'] ),
					'description' => __( 'Uploads', 'backupplus' ),
				),
				'mu-plugins' => array(
					'path'        => WPMU_PLUGIN_DIR,
					'description' => __( 'Must-use plugins', 'backupplus' ),
				),
			);
		} else {
			$arr = array(
				'plugins'    => untrailingslashit( WP_PLUGIN_DIR ),
				'themes'     => WP_CONTENT_DIR . '/themes',
				'uploads'    => untrailingslashit( $wp_upload_dir['basedir'] ),
				'mu-plugins' => WPMU_PLUGIN_DIR,
			);
		}

		$arr = apply_filters( 'backupplus_backupable_file_entities', $arr, $full_info );

		// We then add 'others' on to the end
		if ( $include_others ) {
			if ( $full_info ) {
				$arr['others'] = array(
					'path'        => WP_CONTENT_DIR,
					'description' => __( 'Others', 'backupplus' ),
				);
			} else {
				$arr['others'] = WP_CONTENT_DIR;
			}
		}

		// Entries that should be added after 'others'
		$arr = apply_filters( 'backupplus_backupable_file_entities_final', $arr, $full_info );

		return $arr;
	}

	/**
	 * For detecting another run, and aborting if one was found
	 *
	 * @param String $file - full file path of the file to check
	 */
	public function check_recent_modification( $file ) {
		if ( file_exists( $file ) ) {
			$time_mod = (int) @filemtime( $file );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			$time_now = time();
			if ( $time_mod > 100 && ( $time_now - $time_mod ) < 30 ) {
				BackupPlus_Job_Scheduler::terminate_due_to_activity( $file, $time_now, $time_mod );
			}
		}
	}

	public function get_exclude( $whichone ) {
		$frozen = $this->jobdata_get( 'file_exclusions', array() );
		if ( isset( $frozen[ $whichone ] ) ) { return $frozen[ $whichone ]; }
		if ( 'uploads' == $whichone ) {
			$exclude = explode( ',', BackupPlus_Options::get_backupplus_option( 'backupplus_include_uploads_exclude', BACKUPPLUS_DEFAULT_UPLOADS_EXCLUDE ) );
		} elseif ( 'others' == $whichone ) {
			$exclude = explode( ',', BackupPlus_Options::get_backupplus_option( 'backupplus_include_others_exclude', BACKUPPLUS_DEFAULT_OTHERS_EXCLUDE ) );
		} elseif ( 'wpcore' === $whichone || BackupPlus_Backup_Sources::custom( $whichone ) ) {
			$exclude = explode( ',', BackupPlus_Options::get_backupplus_option( 'backupplus_include_' . ( 'wpcore' === $whichone ? 'wpcore' : 'more' ) . '_exclude', '' ) );
		} else {
			$exclude = apply_filters( 'backupplus_include_' . $whichone . '_exclude', array() );
		}
		return ( empty( $exclude ) || ! is_array( $exclude ) ) ? array() : $exclude;
	}

	/**
	 * Indicate the current blog's upload directory
	 *
	 * @return String
	 */
	public function wp_upload_dir() {
		if ( is_multisite() ) {
			global $current_site;
			switch_to_blog( $current_site->blog_id );
		}

		$wp_upload_dir = wp_upload_dir();

		if ( is_multisite() ) {
			restore_current_blog();
		}

		return $wp_upload_dir;
	}

	public function backup_uploads_dirlist( $log_it = false ) {
		// Create an array of directories to be skipped
		// Make the values into the keys
		$exclude = implode( ',', $this->get_exclude( 'uploads' ) );
		if ( $log_it ) {
			$this->log( 'Exclusion option setting (uploads): ' . $exclude );
		}
		$skip          = array_flip( preg_split( '/,/', $exclude ) );
		$wp_upload_dir = $this->wp_upload_dir();
		$paths         = $this->get_backupable_file_entities( true );
		$uploads_dir   = isset( $paths['uploads'] ) ? $paths['uploads'] : $wp_upload_dir['basedir'];
		return $this->compile_folder_list_for_backup( $uploads_dir, array(), $skip );
	}

	public function backup_others_dirlist( $log_it = false ) {
		// Create an array of directories to be skipped
		// Make the values into the keys
		$exclude = implode( ',', $this->get_exclude( 'others' ) );
		if ( $log_it ) {
			$this->log( 'Exclusion option setting (others): ' . $exclude );
		}
		$skip          = array_flip( preg_split( '/,/', $exclude ) );
		$file_entities = $this->get_backupable_file_entities( false );

		// Keys = directory names to avoid; values = the label for that directory (used only in log files)
		// $avoid_these_dirs = array_flip($file_entities);
		$avoid_these_dirs = array();
		foreach ( $file_entities as $type => $dirs ) {
			if ( is_string( $dirs ) ) {
				$avoid_these_dirs[ $dirs ] = $type;
			} elseif ( is_array( $dirs ) ) {
				foreach ( $dirs as $dir ) {
					$avoid_these_dirs[ $dir ] = $type;
				}
			}
		}
		$paths = $this->get_backupable_file_entities( true );
		return $this->compile_folder_list_for_backup( isset( $paths['others'] ) ? $paths['others'] : WP_CONTENT_DIR, $avoid_these_dirs, $skip );
	}

	/**
	 * avoid_these_dirs and skip_these_dirs ultimately do the same thing; but avoid_these_dirs takes full paths whereas skip_these_dirs takes basenames; and they are logged differently (dirs in avoid_these_dirs are potentially dangerous to include; skip is just a user-level preference). They are allowed to overlap.
	 *
	 * @param String $backup_from_inside_dir
	 * @param Array  $avoid_these_dirs
	 * @param Array  $skip_these_dirs
	 *
	 * @return Array
	 */
	public function compile_folder_list_for_backup( $backup_from_inside_dir, $avoid_these_dirs, $skip_these_dirs ) {

		// Entries in $skip_these_dirs are allowed to end in *, which means "and anything else as a suffix". It's not a full shell glob, but it covers what is needed to-date.

		$dirlist          = array();
		$added            = 0;
		$log_skipped      = 0;
		$log_skipped_last = '';

		$this->log( 'Looking for candidates to backup in: ' . $backup_from_inside_dir );
		$backupplus_dir = $this->backups_dir_location();

		if ( is_file( $backup_from_inside_dir ) ) {
			array_push( $dirlist, $backup_from_inside_dir );
			++$added;
			$this->log( "finding files: $backup_from_inside_dir: adding to list ($added)" );
		} elseif ( $handle = opendir( $backup_from_inside_dir ) ) {

			while ( false !== ( $entry = readdir( $handle ) ) ) {

				if ( '.' == $entry || '..' == $entry ) {
					continue;
				}

				// $candidate: full path; $entry = one-level
				$candidate = $backup_from_inside_dir . '/' . $entry;

				if ( isset( $avoid_these_dirs[ $candidate ] ) ) {
					$this->log( "finding files: $entry: skipping: this is the " . $avoid_these_dirs[ $candidate ] . ' directory' );
				} elseif ( $candidate == $backupplus_dir ) {
					$this->log( "finding files: $entry: skipping: this is the BackupPlus directory" );
				} elseif ( isset( $skip_these_dirs[ $entry ] ) ) {
					$this->log( "finding files: $entry: skipping: excluded by options" );
				} else {
					$add_to_list = true;
					// Now deal with entries in $skip_these_dirs ending in * or starting with *
					foreach ( $skip_these_dirs as $skip => $sind ) {
						if ( '*' == substr( $skip, -1, 1 ) && '*' == substr( $skip, 0, 1 ) && strlen( $skip ) > 2 ) {
							if ( strpos( $entry, substr( $skip, 1, strlen( $skip ) - 2 ) ) !== false ) {
								$this->log( "finding files: $entry: skipping: excluded by options (glob)" );
								$add_to_list = false;
							}
						} elseif ( '*' == substr( $skip, -1, 1 ) && strlen( $skip ) > 1 ) {
							if ( substr( $entry, 0, strlen( $skip ) - 1 ) == substr( $skip, 0, strlen( $skip ) - 1 ) ) {
								$this->log( "finding files: $entry: skipping: excluded by options (glob)" );
								$add_to_list = false;
							}
						} elseif ( '*' == substr( $skip, 0, 1 ) && strlen( $skip ) > 1 ) {
							if ( strlen( $entry ) >= strlen( $skip ) - 1 && substr( $entry, ( strlen( $skip ) - 1 ) * -1 ) == substr( $skip, 1 ) ) {
								$this->log( "finding files: $entry: skipping: excluded by options (glob)" );
								$add_to_list = false;
							}
						}
					}
					if ( $add_to_list ) {
						array_push( $dirlist, $candidate );
						++$added;
						if ( $added > 500 ) {
							if ( $log_skipped >= 500 ) {
								$this->log( "finding files: $entry: adding to list ($added, $log_skipped log lines skipped)" );
								$log_skipped      = 0;
								$log_skipped_last = '';
							} else {
								++$log_skipped;
								$log_skipped_last = $entry;
							}
						} else {
							$skip_dblog = ( ( $added > 50 && 0 != $added % 100 ) || ( $added > 2000 && 0 != $added % 500 ) );
							$this->log( "finding files: $entry: adding to list ($added)", 'notice', false, $skip_dblog );
						}
					}
				}
			}
			@closedir( $handle );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			if ( $log_skipped > 0 ) {
				$this->log( "finding files: $log_skipped_last: adding to list ($added, last; $log_skipped log lines skipped)" );
			}
		} else {
			$this->log( 'ERROR: Could not read the directory: ' . $backup_from_inside_dir );
			$this->log( __( 'Could not read the directory', 'backupplus' ) . ': ' . $backup_from_inside_dir, 'error' );
		}

		return $dirlist;
	}

	/**
	 * Get the location of BackupPlus's internal directory
	 *
	 * @param  Boolean $allow_cache
	 * @return String - the directory path. Returns without any trailing slash.
	 */
	public function backups_dir_location( $allow_cache = true ) {

		if ( $allow_cache && ! empty( $this->backup_dir ) ) {
			return $this->backup_dir;
		}
		$backupplus_dir = BackupPlus_Options::get_backupplus_option( 'backupplus_dir' );
		if ( ! is_string( $backupplus_dir ) ) {
			$backupplus_dir = '';
		}
		$backupplus_dir = untrailingslashit( $backupplus_dir );

		// Normalize historical settings that included the wp-content prefix before saving them.
		if ( preg_match( '/^wp-content\/(.*)$/', $backupplus_dir, $matches ) && ABSPATH . 'wp-content' === WP_CONTENT_DIR ) {
			BackupPlus_Options::update_backupplus_option( 'backupplus_dir', $matches[1] );
			$backupplus_dir = WP_CONTENT_DIR . '/' . $matches[1];
		}

		// Preserve historical installations; new default folders get a persistent random name.
		if ( ! $backupplus_dir ) {
			require_once BACKUPPLUS_DIR . '/includes/class-storage-directory.php';
			foreach ( BackupPlus_Storage_Directory::existing( WP_CONTENT_DIR, 'backupplus' ) as $candidate ) {
				if ( is_dir( $candidate ) ) {
					$backupplus_dir = basename( $candidate );
					break;
				}
			}
			if ( ! $backupplus_dir ) {
				$backupplus_dir = BackupPlus_Storage_Directory::name( 'backupplus' );
			}
			BackupPlus_Options::update_backupplus_option( 'backupplus_dir', $backupplus_dir );
			$backupplus_dir = WP_CONTENT_DIR . '/' . $backupplus_dir;
		}

		// Do a test for a relative path
		if ( '/' != substr( $backupplus_dir, 0, 1 ) && '\\' != substr( $backupplus_dir, 0, 1 ) && ! preg_match( '/^[a-zA-Z]:/', $backupplus_dir ) ) {
			// Legacy - file paths stored related to ABSPATH
			if ( is_dir( ABSPATH . $backupplus_dir ) && is_file( ABSPATH . $backupplus_dir . '/index.html' ) && is_file( ABSPATH . $backupplus_dir . '/.htaccess' ) && ! is_file( ABSPATH . $backupplus_dir . '/index.php' ) && false !== strpos( file_get_contents( ABSPATH . $backupplus_dir . '/.htaccess', false, null, 0, 20 ), 'deny from all' ) ) {
				$backupplus_dir = ABSPATH . $backupplus_dir;
			} else {
				// File paths stored relative to WP_CONTENT_DIR
				$backupplus_dir = trailingslashit( WP_CONTENT_DIR ) . $backupplus_dir;
			}
		}

		// Check for the existence of the dir and prevent enumeration
		// index.php is for a sanity check - make sure that we're not somewhere unexpected
		if ( ( ! is_dir( $backupplus_dir ) || ! is_file( $backupplus_dir . '/index.html' ) || ! is_file( $backupplus_dir . '/.htaccess' ) ) && ! is_file( $backupplus_dir . '/index.php' ) || ! is_file( $backupplus_dir . '/web.config' ) ) {
			@mkdir( $backupplus_dir, 0775, true );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			@file_put_contents( $backupplus_dir . '/index.html', '<html><body>WordPress backups by BackupPlus</body></html>' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Silenced to suppress errors that may arise because of the function. False positive: the 'file_put_contents()' function doesn't put the file in the plugin directory, it puts it in the BackupPlus backup directory instead.
			if ( ! is_file( $backupplus_dir . '/.htaccess' ) ) {
				@file_put_contents( $backupplus_dir . '/.htaccess', 'deny from all' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Silenced to suppress errors that may arise because of the function. False positive: the 'file_put_contents()' function doesn't put the file in the plugin directory, it puts it in the BackupPlus backup directory instead.
			}           if ( ! is_file( $backupplus_dir . '/web.config' ) ) {
				@file_put_contents( $backupplus_dir . '/web.config', "<configuration>\n<system.webServer>\n<authorization>\n<deny users=\"*\" />\n</authorization>\n</system.webServer>\n</configuration>\n" );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Silenced to suppress errors that may arise because of the function. False positive: the 'file_put_contents()' function doesn't put the file in the plugin directory, it puts it in the BackupPlus backup directory instead.
			}
		}

		$this->backup_dir = $backupplus_dir;

		return $backupplus_dir;
	}

	/**
	 * This function will work out the total size of the passed in backup and return it.
	 *
	 * @param array $backup - an array of information about this backup set
	 *
	 * @return integer - the total size of the backup in bytes
	 */
	public function get_total_backup_size( $backup ) {

		$backupable_entities = $this->get_backupable_file_entities( true, true );

		// Add the database to the entities array ready to loop over
		$backupable_entities['db'] = '';

		$total_size = 0;
		foreach ( $backup as $ekey => $files ) {
			if ( ! isset( $backupable_entities[ $ekey ] ) ) {
				continue;
			}
			if ( is_string( $files ) ) {
				$files = array( $files );
			}
			foreach ( $files as $findex => $file ) {
				$size_key   = ( 0 == $findex ) ? $ekey . '-size' : $ekey . $findex . '-size';
				$total_size = ( false === $total_size || ! isset( $backup[ $size_key ] ) || ! is_numeric( $backup[ $size_key ] ) ) ? false : $total_size + $backup[ $size_key ];
			}
		}

		return $total_size;
	}

	/**
	 * A function that works through the array passed to it and gets a list of all the tables from that database and puts the information in an array ready to be parsed and output to html.
	 *
	 * @param  Array $dbsinfo an array that contains information about each database, the default 'wp' array is just an empty array, but other entries can be added so that this method can get tables from other databases the array structure for this would be array('wp' => array(), 'TestDB' => array('host' => '', 'user' => '', 'pass' => '', 'name' => '', 'prefix' => ''))
	 *                          note that the extra tables array key must match the database name in the array note that the extra tables array key must match the database name in the array
	 * @return Array - databases and their table names
	 */
	public function get_database_tables( $dbsinfo = array( 'wp' => array() ) ) {

		global $wpdb;

		if ( ! class_exists( 'BackupPlus_Database_Utility' ) ) {
			backupplus_try_include_file( 'includes/class-database-utility.php', 'include_once' );
		}

		$dbhandle        = '';
		$db_tables_array = array();

		foreach ( $dbsinfo as $key => $value ) {
			if ( 'wp' == $key ) {
				// The unfiltered table prefix - i.e. the real prefix that things are relative to
				$table_prefix_raw = $this->get_table_prefix( false );
				$dbhandle         = $wpdb;
			} else {
				$dbhandle = new BackupPlus_WPDB_OtherDB_Utility( $dbsinfo[ $key ]['user'], $dbsinfo[ $key ]['pass'], $dbsinfo[ $key ]['name'], $dbsinfo[ $key ]['host'] );
				if ( ! empty( $dbhandle->error ) ) {
					return $this->log_wp_error( $dbhandle->error );
				}
				$table_prefix_raw = $dbsinfo[ $key ]['prefix'];
			}

			// SHOW FULL - so that we get to know whether it's a BASE TABLE or a VIEW
			$all_tables = $dbhandle->get_results( 'SHOW FULL TABLES', ARRAY_N );

			if ( empty( $all_tables ) && ! empty( $dbhandle->last_error ) ) {
				$all_tables = $dbhandle->get_results( 'SHOW TABLES', ARRAY_N );
				$all_tables = array_map( array( $this, 'cb_get_name_base_type' ), $all_tables );
			} else {
				$all_tables = array_map( array( $this, 'cb_get_name_type' ), $all_tables );
			}

			// If this is not the WP database, then we do not consider it a fatal error if there are no tables
			if ( 'wp' == $key && 0 == count( $all_tables ) ) {
				return $this->log_wp_error( 'No tables found in wp database.' );
				die;
			}

			// Put the options table first
			BackupPlus_Database_Utility::init( $key, $table_prefix_raw, $dbhandle );
			usort( $all_tables, array( 'BackupPlus_Database_Utility', 'backup_db_sorttables' ) );

			$all_table_names         = array_map( array( $this, 'cb_get_name' ), $all_tables );
			$db_tables_array[ $key ] = $all_table_names;
		}

		return $db_tables_array;
	}

	/**
	 * Returns the member of the array with key (int)0, as a new array. This function is used as a callback for array_map().
	 *
	 * @param Array $a - the array
	 *
	 * @return Array - with keys 'name' and 'type'
	 */
	private function cb_get_name_base_type( $a ) {
		return array(
			'name' => $a[0],
			'type' => 'BASE TABLE',
		);
	}

	/**
	 * Returns the members of the array with keys (int)0 and (int)1, as part of a new array.
	 *
	 * @param Array $a - the array
	 *
	 * @return Array - keys are 'name' and 'type'
	 */
	private function cb_get_name_type( $a ) {
		return array(
			'name' => $a[0],
			'type' => $a[1],
		);
	}

	/**
	 * Returns the member of the array with key (string)'name'. This function is used as a callback for array_map().
	 *
	 * @param Array $a - the array
	 *
	 * @return Mixed - the value with key (string)'name'
	 */
	private function cb_get_name( $a ) {
		return $a['name'];
	}
}
