<?php

/** The database restore loop that reads the dump and applies it statement by statement. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Restorer_Database_Restore {

	private function has_table_selection() {
		return ( ! empty( $this->tables_to_restore ) && ! $this->include_unspecified_tables ) || ! empty( $this->tables_to_skip );
	}

	/** Check the selected tables exist before the importer changes any table. */
	private function validate_selected_dump_tables( $path, $is_plain, $is_bz2 ) {
		if ( empty( $this->tables_to_restore ) || $this->include_unspecified_tables ) { return true; }
		$missing = array_fill_keys( $this->tables_to_restore, true );
		try {
			$handle = $is_plain ? fopen( $path, 'rb' ) : ( $is_bz2 ? bzopen( $path, 'r' ) : BackupPlus_Archive_Compression::open_read( $path ) );
			if ( ! $handle ) { throw new RuntimeException( 'Could not open the database dump for table selection.' ); }
			$pending = '';
			try {
				do {
					$chunk = $is_plain ? fread( $handle, 1048576 ) : ( $is_bz2 ? bzread( $handle, 1048576 ) : gzread( $handle, 1048576 ) );
					if ( false === $chunk || ( $is_bz2 && bzerrno( $handle ) !== 0 ) ) { throw new RuntimeException( 'Could not read the database dump for table selection.' ); }
					$pending .= $chunk;
					$lines    = explode( "\n", $pending );
					$pending  = array_pop( $lines );
					if ( '' === $chunk ) { $lines[] = $pending; }
					foreach ( $lines as $line ) {
						if ( preg_match( '/^\s*create table `?([^`(]+)`?\s*\(/i', $line, $matches ) ) { unset( $missing[ rtrim( $matches[1] ) ] ); }
					}
					// A CREATE TABLE header is short; avoid retaining large INSERT lines.
					if ( strlen( $pending ) > 4096 ) { $pending = "\0"; }
				} while ( '' !== $chunk && $missing );
			} finally {
				if ( $is_plain ) { fclose( $handle );
				} elseif ( $is_bz2 ) { bzclose( $handle );
				} else { gzclose( $handle ); }
			}
			if ( $missing ) { throw new RuntimeException( 'The selected table was not found in the database dump: ' . implode( ', ', array_keys( $missing ) ) ); }
			return true;
		} catch ( Throwable $error ) { return new WP_Error( 'selected_table_missing', $error->getMessage() ); }
	}

	/**
	 * Restore the database backup
	 *
	 * @param  string $working_dir           specify working directory
	 * @param  string $working_dir_localpath specify working local directory
	 * @param  string $import_table_prefix   table prefix to use
	 * @return boolean|WP_Error
	 */
	private function restore_backup_db( $working_dir, $working_dir_localpath, $import_table_prefix ) {

		global $backupplus;

		do_action( 'backupplus_restore_db_pre' );

		// This is now a legacy option (at least on the front end), so we should not see it much
		$this->prior_upload_path = get_option( 'upload_path' );

		// There is a file backup.db(.gz) inside the working directory

		// The 'off' check is for badly configured setups - http://wordpress.org/support/topic/plugin-wp-super-cache-warning-php-safe-mode-enabled-but-safe-mode-is-off
		if ( @ini_get( 'safe_mode' ) && 'off' != strtolower( @ini_get( 'safe_mode' ) ) ) {// phpcs:ignore PHPCompatibility.IniDirectives.RemovedIniDirectives.safe_modeDeprecatedRemoved, Generic.PHP.NoSilencedErrors.Discouraged -- safe_mode check is kept for backward compatibility with very old PHP environments, silenced to suppress errors that may arise because of the function.
			$backupplus->log( __( 'Warning: PHP safe_mode is active on your server.', 'backupplus' ) . ' ' . __( 'Timeouts are much more likely.', 'backupplus' ) . ' ' . __( 'If these happen, then you will need to manually restore the file via phpMyAdmin or another method.', 'backupplus' ), 'notice-restore' );
		}

		$db_basename = 'backup.db.gz';
		if ( ! empty( $this->ud_foreign ) ) {
			$plugins = apply_filters( 'backupplus_accept_archivename', array() );
			/* translators: %s: Source of the database backup file */
			if ( empty( $plugins[ $this->ud_foreign ] ) ) {
				return new WP_Error( 'unknown', sprintf( __( 'Backup created by unknown source (%s) - cannot be restored.', 'backupplus' ), $this->ud_foreign ) );
			}

			if ( ! file_exists( $working_dir_localpath . '/' . $db_basename ) && file_exists( $working_dir_localpath . '/backup.db' ) ) {
				$db_basename = 'backup.db';
			} elseif ( ! file_exists( $working_dir_localpath . '/' . $db_basename ) && file_exists( $working_dir_localpath . '/backup.db.bz2' ) ) {
				$db_basename = 'backup.db.bz2';
			}

			if ( ! file_exists( $working_dir_localpath . '/' . $db_basename ) ) {
				$separatedb       = empty( $plugins[ $this->ud_foreign ]['separatedb'] ) ? false : true;
				$filtered_db_name = apply_filters( 'backupplus_foreign_dbfilename', false, $this->ud_foreign, $this->ud_backup_set, $working_dir_localpath, $separatedb );
				if ( is_string( $filtered_db_name ) ) {
					$db_basename = $filtered_db_name;
				}
			}
		}

		// wp_filesystem has no gzopen method, so we switch to using the local filesystem (which is harmless, since we are performing read-only operations)
		if ( false === $db_basename || ! is_readable( $working_dir_localpath . '/' . $db_basename ) ) {
			return new WP_Error( 'dbopen_failed', __( 'Failed to find database file', 'backupplus' ) . " ($working_dir/" . $db_basename . ')' );
		}

		global $wpdb, $backupplus;

		$this->skin->feedback( $this->strings['restore_database'] );

		$is_plain        = ( '.db' == substr( $db_basename, -3, 3 ) );
		$is_bz2          = ( '.db.bz2' == substr( $db_basename, -7, 7 ) );
		$selection_check = $this->validate_selected_dump_tables( $working_dir_localpath . '/' . $db_basename, $is_plain, $is_bz2 );
		if ( is_wp_error( $selection_check ) ) { return $selection_check; }

		// Read-only access: don't need to go through WP_Filesystem
		if ( $is_plain ) {
			$dbhandle = fopen( $working_dir_localpath . '/' . $db_basename, 'r' );
		} elseif ( $is_bz2 ) {
			if ( ! function_exists( 'bzopen' ) ) {
				$backupplus->log_e( "Your web server's PHP installation has these functions disabled: %s.", 'bzopen' );
				$backupplus->log_e( 'Your hosting company must enable these functions before %s can work.', __( 'restoration', 'backupplus' ) );
			}
			$dbhandle = bzopen( $working_dir_localpath . '/' . $db_basename, 'r' );
		} else {
			try { $dbhandle = BackupPlus_Archive_Compression::open_read( $working_dir_localpath . '/' . $db_basename ); }
			catch ( Throwable $error ) { return new WP_Error( 'dbopen_failed', $error->getMessage() ); }
		}
		if ( ! $dbhandle ) {
			return new WP_Error( 'dbopen_failed', __( 'Failed to open database file', 'backupplus' ) );
		}

		$this->line = 0;

		if ( $this->use_wpdb() ) {
			$backupplus->log_e( 'Database access: Direct MySQL access is not available, so we are falling back to wpdb (this will be considerably slower)' );
		} else {
			$backupplus->log( 'Using direct MySQL access; value of use_mysqli is: ' . ( $this->use_mysqli ? '1' : '0' ) );
			// Servers without a query cache reject this; that is fine.
			$this->sql()->execute( 'SET SESSION query_cache_type = OFF' );
		}

		// NO_BACKSLASH_ESCAPES is also in the default list, but it's good to make it explicit
		$set_sql_mode = BackupPlus_Database_Utility::set_sql_mode( array( 'NO_AUTO_VALUE_ON_ZERO' ), array( 'NO_BACKSLASH_ESCAPES' ), $this->use_wpdb() ? null : $this->mysql_dbh );
		if ( false === $set_sql_mode ) {
			return new WP_Error( 'set_sql_mode_failed', __( 'At least one incompatible SQL mode remains set; the  restore cannot continue' ) );
		} elseif ( null === $set_sql_mode && ( ! defined( 'BACKUPPLUS_ALLOW_SQL_MODE_FAILURE' ) || ! BACKUPPLUS_ALLOW_SQL_MODE_FAILURE ) ) {
			return new WP_Error( 'set_sql_mode_failed', __( 'It is not known whether the SQL mode could be correctly set; to continue the restore you must define BACKUPPLUS_ALLOW_SQL_MODE_FAILURE as true.' ) );
		}

		// Register restoration shutdown event so that we can set some MySQL global variables back to their original values
		register_shutdown_function( array( $this, 'on_shutdown' ) );

		// Find the supported engines - in case the dump had something else (case seen: saved from MariaDB with engine Aria; imported into plain MySQL without)
		$supported_engines    = array_change_key_case( (array) $wpdb->get_results( 'SHOW ENGINES', OBJECT_K ) );
		$supported_charsets   = array_change_key_case( (array) $wpdb->get_results( 'SHOW CHARACTER SET', OBJECT_K ) );
		$supported_collations = array_change_key_case( (array) $wpdb->get_results( 'SHOW COLLATION', OBJECT_K ) );

		$this->table_engine = '';

		$this->errors                = 0;
		$this->statements_run        = 0;
		$this->insert_statements_run = 0;
		$this->tables_created        = 0;

		$sql_line = '';
		$sql_type = -1;

		// This array has been created for clarity, to be able to refer to names and not numbers
		$sql_types = array(
			'DROP_TABLE'              => 1,
			'CREATE_TABLE'            => 2,
			'INSERT'                  => 3,
			'ALTER_OR_LOCK_TABLE'     => 4,
			'CREATE_OR_DROP_DATABASE' => 6,
			'USE'                     => 7,
			'SET_NAMES'               => 8,
			'CREATE_TRIGGER'          => 9,
			'DELIMITER'               => 10,
			'CREATE_FUNCTION'         => 12,
			'LOCK_OR_UNLOCK_TABLE'    => 15,
			'DROP_VIEW'               => 16,
		);

		$this->start_time = microtime( true );

		$this->old_siteurl      = '';
		$this->old_home         = '';
		$this->old_content      = '';
		$this->old_uploads      = '';
		$this->old_table_prefix = ( defined( 'BACKUPPLUS_OVERRIDE_IMPORT_PREFIX' ) && BACKUPPLUS_OVERRIDE_IMPORT_PREFIX ) ? BACKUPPLUS_OVERRIDE_IMPORT_PREFIX : null;
		$this->old_siteinfo     = array();
		$gathering_siteinfo     = true;

		$this->db_permissons_forbidden['create'] = false;
		$this->db_permissons_forbidden['drop']   = false;
		$this->db_permissons_forbidden['lock']   = false;
		$this->db_permissons_forbidden['rename'] = false;
		// This will get flipped if positive success is confirmed
		$this->db_permissons_forbidden['triggers'] = true;

		$this->last_error                     = '';
		$random_table_name                    = 'backupplus_tmp_' . wp_rand( 0, 9999999 ) . md5( microtime( true ) );
		$renamed_random_table_name            = 'backupplus_tmp_' . wp_rand( 0, 9999999 ) . md5( microtime( true ) );
		$last_created_generated_columns_table = '';

		// The only purpose in funnelling queries directly here is to be able to get the error number
		$sql = $this->sql();
		$req = false !== $sql->execute( 'CREATE TABLE %i (test INT)', array( $random_table_name ) );
		if ( ! $req ) {
			$this->last_error    = $sql->last_error();
			$this->last_error_no = $sql->last_errno();
		} elseif ( false !== $sql->execute( 'CREATE TRIGGER test_trigger BEFORE INSERT ON %i FOR EACH ROW SET @sum = @sum + NEW.test', array( $random_table_name ) ) ) {
			$this->db_permissons_forbidden['triggers'] = false;
		}
		if ( $this->use_wpdb() ) {
			$this->last_error_no = false;
		}

		if ( ! $req && ( $this->use_wpdb() || 1142 === $this->last_error_no ) ) {
			$this->db_permissons_forbidden['create'] = true;
			// If we can't create, then there's no point dropping
			$this->db_permissons_forbidden['drop'] = true;

			// abort dummy restore process
			if ( $this->is_dummy_db_restore ) {
				return new WP_Error( 'abort_dummy_restore', __( 'Your database user does not have permission to drop tables', 'backupplus' ) );
			}

			$backupplus->log( __( 'Your database user does not have permission to create tables.', 'backupplus' ) . ' ' . __( 'We will attempt to restore by simply emptying the tables; this should work as long as a) you are restoring from a WordPress version with the same database structure, and b) Your imported database does not contain any tables which are not already present on the importing site.', 'backupplus' ), 'warning-restore' );

			$backupplus->log( 'Your database user does not have permission to create tables. We will attempt to restore by simply emptying the tables; this should work as long as a) you are restoring from a WordPress version with the same database structure, and b) Your imported database does not contain any tables which are not already present on the importing site.' );

			$backupplus->log( 'Error was: ' . $this->last_error . ' (' . $this->last_error_no . ')' );
		} else {

			if ( 1142 === $this->rename_table( $random_table_name, $renamed_random_table_name ) ) {
				$this->db_permissons_forbidden['rename'] = true;
				$backupplus->log( 'Database user has no permission to rename tables - restoration will be non-atomic', 'warning-restore' );
			} else {
				// We renamed the table so update the $random_table_name
				$random_table_name = $renamed_random_table_name;
			}

			if ( 1142 === $this->lock_table( $random_table_name ) ) {
				$this->db_permissons_forbidden['lock'] = true;
				$backupplus->log( 'Database user has no permission to lock tables - will not lock after CREATE', 'warning-restore' );
			}

			// rename_table() runs through sql_exec(), which may have reconnected.
			$sql = $this->sql();
			$req = false !== $sql->execute( 'DROP TABLE %i', array( $random_table_name ) );
			if ( ! $req ) {
				$this->last_error    = $sql->last_error();
				$this->last_error_no = $sql->last_errno();
			}
			if ( $this->use_wpdb() ) {
				$this->last_error_no = false;
			}
			if ( ! $req && ( $this->use_wpdb() || 1142 === $this->last_error_no ) ) {
				$this->db_permissons_forbidden['drop']   = true;
				$this->db_permissons_forbidden['rename'] = true;

				// abort dummy restore process
				if ( $this->is_dummy_db_restore ) {
					return new WP_Error( 'abort_dummy_restore', __( 'Your database user does not have permission to drop tables', 'backupplus' ) );
				}

				$backupplus->log( sprintf( 'Your database user does not have permission to drop tables. We will attempt to restore by simply emptying the tables; this should work as long as you are restoring from a WordPress version with the same database structure (%s)', '(' . $this->last_error . ', ' . $this->last_error_no . ')' ) );

				$backupplus->log(
					__( 'Your database user does not have permission to drop tables.', 'backupplus' ) . ' ' .
					/* translators: %s: Error message */
					sprintf( __( 'We will attempt to restore by simply emptying the tables; this should work as long as you are restoring from a WordPress version with the same database structure (%s)', 'backupplus' ), '(' . $this->last_error . ', ' . $this->last_error_no . ')' ),
					'warning-restore'
				);

			}
		}

		if ( defined( 'BACKUPPLUS_ATOMIC_RESTORE_DISABLED' ) && BACKUPPLUS_ATOMIC_RESTORE_DISABLED ) {
			$backupplus->log( 'Atomic restore disabled by constant BACKUPPLUS_ATOMIC_RESTORE_DISABLED - restoration will be non-atomic', 'warning-restore' );
			$this->db_permissons_forbidden['rename'] = true;
		}

		$this->save_lock_rows();

		// If this is not a dummy restore or not importing a single site into a multisite and we can rename and drop tables then change the import prefix and proceed with atomic restore
		if ( empty( $this->db_permissons_forbidden['rename'] ) && ! $this->is_dummy_db_restore && empty( $this->ud_foreign ) ) {
			add_filter( 'backupplus_restore_table_prefix', array( $this, 'backupplus_random_restore_table_prefix' ) );
			$import_table_prefix       = isset( $this->continuation_data['temp_import_table_prefix'] ) ? $this->continuation_data['temp_import_table_prefix'] : apply_filters( 'backupplus_restore_table_prefix', $this->final_import_table_prefix );
			$this->import_table_prefix = $import_table_prefix;
			$backupplus->jobdata_set( 'temp_import_table_prefix', $this->import_table_prefix );
		} else {
			$this->db_permissons_forbidden['rename'] = true;
		}

		$this->restoring_table = '';

		$this->max_allowed_packet = $backupplus->max_packet_size();

		$backupplus->log( 'Entering maintenance mode' );
		$this->maintenance_mode( true );

		$delimiter             = ';';
		$delimiter_regex       = ';';
		$virtual_columns_exist = false;
		$native_trigger_prefix = '/^\s*\/\*![0-9]{5,6}\s+CREATE\s*\*\/\s*(?:\/\*![0-9]{5,6}\s+DEFINER=.*?\*\/\s*)?\/\*![0-9]{5,6}\s+TRIGGER\b/is';

		$old_log_bin_trust_function_creators = null;

		if ( is_null( $this->stored_routine_supported ) ) {
			$this->stored_routine_supported = BackupPlus_Database_Utility::is_stored_routine_supported();
		}
		if ( is_wp_error( $this->stored_routine_supported ) ) {
			$backupplus->log( 'is_stored_routine_supported(): ' . $this->stored_routine_supported->get_error_message() );
		}

		// N.B. There is no such function as bzeof() - we have to detect that another way
		while ( ( $is_plain && ! feof( $dbhandle ) ) || ( ! $is_plain && ( ( $is_bz2 ) || ( ! $is_bz2 && ! gzeof( $dbhandle ) ) ) ) ) {
			// Up to 1Mb
			if ( $is_plain ) {
				$buffer = rtrim( BackupPlus_SQL_Stream::line( $dbhandle, true ) );
			} elseif ( $is_bz2 ) {
				if ( ! isset( $bz2_buffer ) ) {
					$bz2_buffer = '';// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable -- The variable is defined after check.
				}               $buffer = '';
				if ( strlen( $bz2_buffer ) < 524288 ) {
					$bz2_buffer .= bzread( $dbhandle, 1048576 );
				}
				if ( bzerrno( $dbhandle ) !== 0 ) {
					$backupplus->log( 'bz2 error: ' . bzerrstr( $dbhandle ) . ' (code: ' . bzerrno( $dbhandle ) . ')' );
					break;
				}
				if ( false !== $bz2_buffer && '' !== $bz2_buffer ) {
					if ( false !== ( $p = strpos( $bz2_buffer, "\n" ) ) ) {
						$buffer    .= substr( $bz2_buffer, 0, $p + 1 );
						$bz2_buffer = substr( $bz2_buffer, $p + 1 );
					} else {
						$buffer    .= $bz2_buffer;
						$bz2_buffer = '';
					}
				} else {
					break;
				}
				$buffer = rtrim( $buffer );
			} else {
				$buffer = rtrim( BackupPlus_SQL_Stream::line( $dbhandle, false ) );
			}

			// Discard comments
			if ( empty( $buffer ) || '#' == substr( $buffer, 0, 1 ) || preg_match( '/^--(\s|$)/', substr( $buffer, 0, 3 ) ) ) {
				if ( '' == $this->old_siteurl && preg_match( '/^\# Backup of: (http(.*))$/', $buffer, $matches ) ) {
					$this->old_siteurl = untrailingslashit( $matches[1] );
					$backupplus->log( 'Backup of: ' . $this->old_siteurl );
					/* translators: %s: Old site */
					$backupplus->log( sprintf( __( 'Backup of: %s', 'backupplus' ), $this->old_siteurl ), 'notice-restore', 'backup-of' );
					do_action( 'backupplus_restore_db_record_old_siteurl', $this->old_siteurl );

					$this->save_configuration_bundle();

				} elseif ( false === $this->created_by_version && preg_match( '/^\# Created by (?:BackupPlus|UpdraftPlus) version ([\d\.]+)/', $buffer, $matches ) ) {
					$this->created_by_version = trim( $matches[1] );
					$backupplus->log( __( 'Backup created by:', 'backupplus' ) . ' ' . $this->created_by_version, 'notice-restore', 'created-by' );
					$backupplus->log( 'Backup created by: ' . $this->created_by_version );
				} elseif ( '' == $this->old_home && preg_match( '/^\# Home URL: (http(.*))$/', $buffer, $matches ) ) {
					$this->old_home = untrailingslashit( $matches[1] );
					if ( $this->old_siteurl && $this->old_home != $this->old_siteurl ) {
						$backupplus->log( __( 'Site home:', 'backupplus' ) . ' ' . $this->old_home, 'notice-restore', 'site-home' );
						$backupplus->log( 'Site home: ' . $this->old_home );
					}
					do_action( 'backupplus_restore_db_record_old_home', $this->old_home );
				} elseif ( '' == $this->old_content && preg_match( '/^\# Content URL: (http(.*))$/', $buffer, $matches ) ) {
					$this->old_content = untrailingslashit( $matches[1] );
					$backupplus->log( __( 'Content URL:', 'backupplus' ) . ' ' . $this->old_content, 'notice-restore', 'content-url' );
					$backupplus->log( 'Content URL: ' . $this->old_content );
					do_action( 'backupplus_restore_db_record_old_content', $this->old_content );
				} elseif ( '' == $this->old_uploads && preg_match( '/^\# Uploads URL: (http(.*))$/', $buffer, $matches ) ) {
					$this->old_uploads = untrailingslashit( $matches[1] );
					$backupplus->log( __( 'Uploads URL:', 'backupplus' ) . ' ' . $this->old_uploads, 'notice-restore', 'uploads-url' );
					$backupplus->log( 'Uploads URL: ' . $this->old_uploads );
					do_action( 'backupplus_restore_db_record_old_uploads', $this->old_uploads );
				} elseif ( null === $this->old_table_prefix && ( preg_match( '/^\# Table prefix: ?(\S*)$/', $buffer, $matches ) || preg_match( '/^-- Table Prefix: ?(\S*)$/i', $buffer, $matches ) ) ) {
					// We also support backwpup style:
					// -- Table Prefix: wp_
					$this->old_table_prefix = $matches[1];
					$backupplus->log( __( 'Old table prefix:', 'backupplus' ) . ' ' . $this->old_table_prefix, 'notice-restore', 'old-table-prefix' );
					$backupplus->log( 'Old table prefix: ' . $this->old_table_prefix );
				} elseif ( preg_match( '/^\# Skipped tables: (.*)$/', $buffer, $matches ) ) {
					$backupplus->log( __( 'Skipped tables:', 'backupplus' ) . ' ' . $matches[1], 'notice-restore', 'skipped-tables' );
					$backupplus->log( 'Skipped tables: ' . $matches[1] );
				} elseif ( $gathering_siteinfo && preg_match( '/^\# Site info: (\S+)$/', $buffer, $matches ) ) {
					if ( 'end' == $matches[1] ) {
						$gathering_siteinfo = false;
						// Sanity checks
						$importing = 'import' === BackupPlus_Multisite_Restore::mode( $this->restore_options );
						if ( isset( $this->old_siteinfo['multisite'] ) && ! $this->old_siteinfo['multisite'] && is_multisite() && ! $importing ) {
							return new WP_Error( 'unsupported_restore_target', __( 'A single-site backup can only be imported into one site of a WordPress network: choose the site in the database restoration options.', 'backupplus' ) );
						} elseif ( ! empty( $this->old_siteinfo['multisite'] ) && $importing ) {
							return new WP_Error( 'unsupported_restore_target', __( 'This is a backup of a network; choose one of its sites to restore instead of importing it.', 'backupplus' ) );
						}
					} elseif ( preg_match( '/^([^=]+)=(.*)$/', $matches[1], $kvmatches ) ) {
						$key = $kvmatches[1];
						$val = $kvmatches[2];
						$backupplus->log( __( 'Site information:', 'backupplus' ) . " $key = $val", 'notice-restore', 'site-information' );
						$backupplus->log( "Site information: $key=$val" );
						if ( 'multisite_data' == $key ) {
							$this->old_siteinfo[ $key ] = json_decode( $val, true );
						} else {
							$this->old_siteinfo[ $key ] = $val;
						}
						if ( 'multisite' == $key ) {
							$this->ud_backup_is_multisite = ( $val ) ? 1 : 0;
						}
					}
				} elseif ( '' == $this->old_abspath && preg_match( '/^\# ABSPATH: ?(.*)$/', $buffer, $matches ) ) {
					if ( ABSPATH != $matches[1] && '/' != $matches[1] ) {
						$this->old_abspath = $matches[1];
						$backupplus->log( __( 'Old ABSPATH:', 'backupplus' ) . ' ' . $this->old_abspath, 'notice-restore', 'old-abspath' );
						$backupplus->log( "Old ABSPATH: {$this->old_abspath}" );
						do_action( 'backupplus_restore_db_record_old_abspath', $this->old_abspath );
					}
				} elseif ( '' == $this->old_backupplus_plugin_slug && preg_match( '/^\# (?:BackupPlus|UpdraftPlus) plugin slug: ?(.*)$/', $buffer, $matches ) ) {
					$this->old_backupplus_plugin_slug = $matches[1];
					$backupplus->log( __( 'Plugin slug from backup:', 'backupplus' ) . ' ' . $this->old_backupplus_plugin_slug, 'notice-restore', 'backup-plugin-slug' );
					$backupplus->log( "Plugin slug from backup: {$this->old_backupplus_plugin_slug}" );
				}
				continue;
			}

			// Detect INSERT and various other commands early, so that we can split or combine them if necessary
			if ( preg_match( '/^\s*(insert\s\s*into(?:\s*`(.+?)`|[^\(]+)(?:\s*\(.+?\))?\s*(?:values|\())/i', $sql_line . $buffer, $matches ) ) {
				// https://regex101.com/r/zrQquQ/2
				$this->table_name = $matches[2];
				$sql_type         = $sql_types['INSERT'];
				$insert_prefix    = $matches[1];

				// if the current table is a generated columns table, that means at this stage the table creation is being postponed and the block of code below will get executed first to filter the create statement
				if ( ! empty( $this->generated_columns[ $this->table_name ] ) && ! $this->table_should_be_skipped( $this->table_name ) ) {
					// parse the generated columns insert statement so that later we can instantly retrieve the information needed when creating the table
					$this->generated_columns_exist_in_the_statement[ $this->table_name ] = BackupPlus_Database_Utility::generated_columns_exist_in_the_statement( $sql_line . $buffer, $this->generated_columns[ $this->table_name ]['column_names'] );
					if ( $this->table_name != $last_created_generated_columns_table ) {
						$create_statement = $this->prepare_create_table( $this->generated_columns[ $this->table_name ]['create_statement'], $import_table_prefix, $supported_engines, $supported_charsets, $supported_collations );
						// after getting the filtered create statement, continue with the table creation
						$do_exec                              = $this->sql_exec( $create_statement, 2, $import_table_prefix );
						$last_created_generated_columns_table = $this->table_name;
						if ( is_wp_error( $do_exec ) ) {
							return $do_exec;
						}
					}
					// on MySQL 5.7.x, we could get an error "the value specified for generated column is not allowed", disabling strict mode doesn't work, adding insert ignore doesn't work either
					// disabling strict mode works fine on MariaDB, it may be good if we can strengthen the insert statement by adding ignore keyword into it
					$sql_line      = preg_replace( '/^(\s*insert\s\s*into)(.+)$/is', 'insert ignore into$2', $sql_line );
					$insert_prefix = preg_replace( '/^(\s*insert\s\s*into)(.+)$/is', 'insert ignore into$2', $matches[0] );
				}
			} elseif ( preg_match( '/^\s*delimiter (\S+)\s*$/i', $sql_line . $buffer, $matches ) ) {
				// This also needs processing early so that the correct delimiter is used a few lines down
				$sql_type  = 10;
				$delimiter = $matches[1];
				// Obviously, what is supported here is quite limited
				$delimiter_regex = str_replace( array( '$', '#', '/' ), array( '\$', '\#', '\/' ), $delimiter );
			} elseif ( preg_match( '/^\s*create trigger /i', $sql_line . $buffer ) || preg_match( $native_trigger_prefix, $sql_line . $buffer ) ) {
				$sql_type = 9;
				$buffer   = $buffer . "\n";
			} elseif ( preg_match( "/^\s*CREATE\s\s*(?:DEFINER\s*=\s*(?:`.{1,17}`@`[^\s]+`\s*|'.{1,17}'@'[^\s]+'\s*|[^\s]+?\s))?(?:AGGREGATE\s\s*)?(?:PROCEDURE|FUNCTION)((?:\s\s*[^\(`]+|\s*`(?:[^`]|``)+`))\s*\(/is", $sql_line . $buffer ) ) {
				$sql_type = 12;
				$buffer   = $buffer . "\n"; // need to do this so that the functions/procedures which have double dash and/or shell comment style (i.e -- comment, # comment) doesn't block the rest of the code in the routines body and also because we want to keep the routines as it is or in multiline (in a form that people prefer) so they who will edit it later don't get surprised by the look of it in a single line/one line
			} elseif ( preg_match( '/^\s*create table \`?([^\`\(]*)\`?\s*\(/i', $sql_line . $buffer, $matches ) ) {
				$sql_type         = 2;
				$this->table_name = $matches[1];
				// check whether the column definition is a generated column
				$generated_column_info = BackupPlus_Database_Utility::get_generated_column_info( $buffer, strlen( $sql_line ) );
				if ( $generated_column_info ) {
					if ( ! isset( $this->generated_columns[ $this->table_name ] ) ) {
						$this->generated_columns[ $this->table_name ] = array();
					}
					if ( ! $virtual_columns_exist ) {
						$virtual_columns_exist = $generated_column_info['is_virtual'];
					}
					$this->generated_columns[ $this->table_name ]['columns'][]      = $generated_column_info;
					$this->generated_columns[ $this->table_name ]['column_names'][] = $generated_column_info['column_name'];
				}
				if ( ! empty( $this->generated_columns[ $this->table_name ] ) && substr( $sql_line . $buffer, -strlen( $delimiter ), strlen( $delimiter ) ) == $delimiter ) {
					$this->generated_columns[ $this->table_name ]['create_statement']      = $sql_line . $buffer;
					$this->generated_columns[ $this->table_name ]['virtual_columns_exist'] = $virtual_columns_exist;
					$virtual_columns_exist = false;
				}
			}

			// Deal with case where adding this line will take us over the MySQL max_allowed_packet limit - must split, if we can (if it looks like consecutive rows)
			// Allow a 100-byte margin for error (including searching/replacing table prefix)
			if ( $sql_type == $sql_types['INSERT'] && $sql_line && strlen( $sql_line . $buffer ) > ( $this->max_allowed_packet - 100 ) && preg_match( '/,\s*$/', $sql_line ) && preg_match( '/^\s*\(/', $buffer ) && BackupPlus_Database_Utility::sql_statement_is_finished( $sql_line ) ) {

				if ( $this->table_should_be_skipped( $this->table_name ) ) {
					// Reset - we need the insert prefix so following lines get detected correctly
					$sql_line = $insert_prefix . ' ';
					continue;
				}

				// Remove the final comma; replace with delimiter
				$sql_line = substr( rtrim( $sql_line ), 0, strlen( $sql_line ) - 1 ) . ';';
				if ( $import_table_prefix != $this->old_table_prefix ) {
					if ( '' != $this->old_table_prefix ) {
						$sql_line = BackupPlus_Manipulation_Functions::str_replace_once( $this->old_table_prefix, $import_table_prefix, $sql_line );
					} else {
						$sql_line = BackupPlus_Manipulation_Functions::str_replace_once( $this->table_name, $this->table_prefix . $this->table_name, $sql_line );
					}
				}

				// Run the SQL command; then set up for the next one.
				++$this->line;
				$backupplus->log( __( 'Split line to avoid exceeding maximum packet size', 'backupplus' ) . ' (' . strlen( $sql_line ) . ' + ' . strlen( $buffer ) . ' : ' . $this->max_allowed_packet . ')', 'notice-restore' );
				$backupplus->log( 'Split line to avoid exceeding maximum packet size (' . strlen( $sql_line ) . ' + ' . strlen( $buffer ) . ' : ' . $this->max_allowed_packet . ')' );
				$do_exec = $this->sql_exec( $sql_line, $sql_type, $import_table_prefix );
				if ( is_wp_error( $do_exec ) ) {
					return $do_exec;
				}
				// Reset, then carry on
				$sql_line = $insert_prefix . ' ';
			}

			$sql_line .= ( $sql_types['CREATE_TRIGGER'] == $sql_type && '' != $sql_line ) ? ' ' . $buffer : $buffer;

			// Do we have a complete line yet? We used to just test the final character for ';' here (up to 1.8.12), but that was too unsophisticated
			// From 1.16.16, we don't hard-code the delimiter here, and we also add the knowledge that CREATE TRIGGER statements finish with END
			// The aim, in order to get something valid to execute, is to get something like this, with the entire trigger on a single line:
			//
			// $wpdb->query("CREATE TRIGGER `civicrm_acl_after_insert` AFTER INSERT ON `civicrm_acl` FOR EACH ROW BEGIN IF (@civicrm_disable_logging IS NULL OR @civicrm_disable_logging = 0 ) THEN INSERT INTO log_civicrm_acl (`id`, `name`, `deny`, `entity_table`, `entity_id`, `operation`, `object_table`, `object_id`, `acl_table`, `acl_id`, `is_active`, log_conn_id, log_user_id, log_action) VALUES ( NEW.`id`, NEW.`name`, NEW.`deny`, NEW.`entity_table`, NEW.`entity_id`, NEW.`operation`, NEW.`object_table`, NEW.`object_id`, NEW.`acl_table`, NEW.`acl_id`, NEW.`is_active`, COALESCE(@uniqueID, LEFT(CONCAT('c_', unix_timestamp()/3600, CONNECTION_ID()), 17)), @civicrm_user_id, 'insert'); END IF; END"));

			if ( ( $sql_types['INSERT'] == $sql_type && ! preg_match( '/\)\s*' . $delimiter_regex . '$/', substr( $sql_line, -5, 5 ) ) )
				|| ( ! in_array( $sql_type, array( $sql_types['INSERT'], $sql_types['CREATE_TRIGGER'], $sql_types['CREATE_FUNCTION'], $sql_types['DELIMITER'] ) ) && substr( $sql_line, -strlen( $delimiter ), strlen( $delimiter ) ) != $delimiter )
				|| ( $sql_types['CREATE_TRIGGER'] == $sql_type && ! preg_match( '/(?:END)?\s*' . $delimiter_regex . '\s*$/', $sql_line ) )
			) {
				continue;
			}

			if ( ! BackupPlus_Database_Utility::sql_statement_is_finished( $sql_line ) ) {
				continue;
			}

			// Native dumpers wrap CREATE, DEFINER and the trigger body separately.
			// Recognize that form so table selection and atomic table names apply too.
			$native_trigger = preg_match( $native_trigger_prefix, $sql_line );
			if ( $native_trigger ) {
				$sql_line = preg_replace( $native_trigger_prefix, 'CREATE TRIGGER', $sql_line, 1 );
				$sql_line = preg_replace( '/\*\/(?=\s*' . $delimiter_regex . '\s*$)/', '', $sql_line, 1 );
			}
			$selective_tables = $this->has_table_selection();
			// These objects belong to the database as a whole, not the selected table.
			if ( $selective_tables && ( 12 === $sql_type || preg_match( '/^\s*(?:DROP\s+(?:VIEW|FUNCTION|PROCEDURE|TRIGGER)\b|CREATE(?:\s+ALGORITHM=\S+)?(?:\s+DEFINER=\S+)?(?:\s+SQL\s+SECURITY\s+\S+)?\s+VIEW\b)/i', $sql_line ) ) ) {
				$sql_line = '';
				$sql_type = -1;
				continue;
			}

			++$this->line;

			// We now have a complete line - process it

			if ( 3 == $sql_type && $sql_line && strlen( $sql_line ) > $this->max_allowed_packet ) {
				$this->log_oversized_packet( $sql_line );
				// Reset
				$sql_line = '';
				$sql_type = -1;
				// If this is the very first SQL line of the options table, we need to bail; it's essential
				if ( 0 == $this->insert_statements_run && $this->restoring_table && $this->restoring_table == $import_table_prefix . 'options' ) {
					$backupplus->log( 'Leaving maintenance mode' );
					$this->maintenance_mode( false );
					/* translators: %s: translated "INSERT (options)" */
					return new WP_Error( 'initial_db_error', sprintf( __( 'An error occurred on the first %s command - aborting run', 'backupplus' ), 'INSERT (options)' ) );
				}
				continue;
			}

			// The timed overhead of this is negligible
			if ( preg_match( '/^\s*drop view (if exists )?\`?([^\`]*)\`?\s*' . $delimiter_regex . '/i', $sql_line, $matches ) ) {
				$sql_type           = 16;
				$this->view_names[] = $matches[2];
				$this->view_names   = array_unique( $this->view_names );
			} elseif ( preg_match( '/^\s*drop table (if exists )?\`?([^\`]*)\`?\s*' . $delimiter_regex . '/i', $sql_line, $matches ) ) {
				$sql_type = 1;

				if ( ! $this->printed_new_table_prefix ) {
					$import_table_prefix = $this->pre_sql_actions( $import_table_prefix );
					if ( false === $import_table_prefix || is_wp_error( $import_table_prefix ) ) {
						return $import_table_prefix;
					}
					$this->printed_new_table_prefix = true;
				}

				$this->table_name = $matches[2];
				if ( $this->table_should_be_skipped( $this->table_name ) ) {
					// Reset
					$sql_line = '';
					$sql_type = -1;
					continue;
				}

				// Legacy, less reliable - in case it was not caught before
				if ( null === $this->old_table_prefix && preg_match( '/^([a-z0-9]+)_.*$/i', $this->table_name, $tmatches ) ) {
					$this->old_table_prefix = $tmatches[1] . '_';
					$backupplus->log( __( 'Old table prefix:', 'backupplus' ) . ' ' . $this->old_table_prefix, 'notice-restore', 'old-table-prefix' );
					$backupplus->log( 'Old table prefix (detected from first table): ' . $this->old_table_prefix );
				}

				$this->new_table_name = $this->old_table_prefix ? BackupPlus_Manipulation_Functions::str_replace_once( $this->old_table_prefix, $import_table_prefix, $this->table_name ) : $this->table_name;

				$non_wp_table = false;

				// if we have a different prefix but the table name has not changed after the replace then we are dealing with a table that does not use the WordPress table prefix, in order for an Atomic restore to work on this table we need to attach our temporary prefix
				if ( empty( $this->db_permissons_forbidden['rename'] ) && $this->old_table_prefix && $this->new_table_name == $this->table_name ) {
					$non_wp_table         = true;
					$this->new_table_name = $import_table_prefix . $this->table_name;
				}

				if ( $import_table_prefix != $this->old_table_prefix ) {
					if ( '' === $this->old_table_prefix || $non_wp_table ) {
						$sql_line = BackupPlus_Manipulation_Functions::str_replace_once( $this->table_name, $this->new_table_name, $sql_line );
					} else {
						$sql_line = BackupPlus_Manipulation_Functions::str_replace_once( $this->old_table_prefix, $import_table_prefix, $sql_line );
					}
				}

				if ( empty( $matches[1] ) ) {
					// Seen with some foreign backups
					$sql_line = preg_replace( '/drop table/i', 'drop table if exists', $sql_line, 1 );
				}

				$this->tables_been_dropped[] = $this->new_table_name;

			} elseif ( preg_match( '/^\s*create table \`?([^\`\(]*)\`?\s*\(/i', $sql_line, $matches ) ) {

				$sql_type                    = 2;
				$this->insert_statements_run = 0;
				$this->table_name            = $matches[1];

				// regardless of whether or not the table should be skipped, the table creation should also be postponed if the table contains one or more generated columns
				if ( $this->table_should_be_skipped( $this->table_name ) || ! empty( $this->generated_columns[ $this->table_name ] ) ) {
					// Reset
					$sql_line = '';
					$sql_type = -1;
					continue;
				}

				if ( ! $this->printed_new_table_prefix ) {
					$import_table_prefix = $this->pre_sql_actions( $import_table_prefix );
					if ( false === $import_table_prefix || is_wp_error( $import_table_prefix ) ) {
						return $import_table_prefix;
					}
					$this->printed_new_table_prefix = true;
				}

				$sql_line = $this->prepare_create_table( $sql_line, $import_table_prefix, $supported_engines, $supported_charsets, $supported_collations );

			} elseif ( preg_match( '/^\s*insert(?:\s\s*ignore)?\s\s*into(?:\s*`(.+?)`|[^\(]+)(?:\s*\(.+?\))?\s*(?:values|\()/i', $sql_line, $matches ) ) {
				$sql_type         = $sql_types['INSERT'];
				$this->table_name = $matches[1];
				if ( $this->table_should_be_skipped( $this->table_name ) ) {
					// Reset
					$sql_line = '';
					$sql_type = -1;
					continue;
				}

				$non_wp_table = false;

				$this->new_table_name = $this->old_table_prefix ? BackupPlus_Manipulation_Functions::str_replace_once( $this->old_table_prefix, $import_table_prefix, $this->table_name ) : $this->table_name;

				// if we have a different prefix but the table name has not changed after the replace then we are dealing with a table that does not use the WordPress table prefix, in order for an Atomic restore to work on this table we need to attach our temporary prefix
				if ( empty( $this->db_permissons_forbidden['rename'] ) && $this->old_table_prefix && $this->new_table_name == $this->table_name ) {
					$non_wp_table         = true;
					$this->new_table_name = $import_table_prefix . $this->table_name;
				}

				// If this is set then we have disabled atomic restores for this table so we need to make sure we are using the correct prefix when inserting the data
				$temp_insert_table_prefix = $this->disable_atomic_on_current_table ? $this->final_import_table_prefix : $import_table_prefix;
				if ( $temp_insert_table_prefix != $this->old_table_prefix ) {
					if ( '' === $this->old_table_prefix || $non_wp_table ) {
						$sql_line = BackupPlus_Manipulation_Functions::str_replace_once( $this->table_name, $this->new_table_name, $sql_line );
					} else {
						$sql_line = BackupPlus_Manipulation_Functions::str_replace_once( $this->old_table_prefix, $temp_insert_table_prefix, $sql_line );
					}
				}
			} elseif ( preg_match( '/^\s*(\/\*\!40000 )?(alter|lock) tables? \`?([^\`\(]*)\`?\s+(write|disable|enable)/i', $sql_line, $matches ) ) {
				// Only binary mysqldump produces this pattern (LOCK TABLES `table` WRITE, ALTER TABLE `table` (DISABLE|ENABLE) KEYS)
				$sql_type = 4;
				if ( ! empty( $matches[3] ) && $this->table_should_be_skipped( $matches[3] ) ) {
					// Reset
					$sql_line = '';
					$sql_type = -1;
					continue;
				}
				$temp_insert_table_prefix = $this->disable_atomic_on_current_table ? $this->final_import_table_prefix : $import_table_prefix;
				if ( $temp_insert_table_prefix != $this->old_table_prefix ) {
					if ( '' === $this->old_table_prefix || $non_wp_table ) {
						$sql_line = BackupPlus_Manipulation_Functions::str_replace_once( $this->table_name, $this->new_table_name, $sql_line );
					} else {
						$sql_line = BackupPlus_Manipulation_Functions::str_replace_once( $this->old_table_prefix, $temp_insert_table_prefix, $sql_line );
					}
				}
			} elseif ( preg_match( '/^(un)?lock tables/i', $sql_line ) ) {
				// BackWPup produces these
				$sql_type = 15;
			} elseif ( preg_match( '/^(create|drop) database /i', $sql_line ) ) {
				// WPB2D produces these, as do some phpMyAdmin dumps
				$sql_type = 6;
			} elseif ( preg_match( '/^use /i', $sql_line ) ) {
				// WPB2D produces these, as do some phpMyAdmin dumps
				$sql_type = 7;
			} elseif ( preg_match( '#^\s*/\*\!40\d+ (SET NAMES) (.*)\*\/#i', $sql_line, $smatches ) ) {
				$sql_type           = 8;
				$charset            = rtrim( $smatches[2] );
				$names              = $charset;
				$connection_charset = $backupplus->get_connection_charset();
				if ( 'utf8' === $charset && 'utf8mb4' === $connection_charset ) {
					$names = $connection_charset;
					/* translators: 1: Charset found, 2: Changed charset to */
					$backupplus->log( sprintf( __( 'Found SET NAMES %1$s, but changing to %2$s as suggested by WPDB::determine_charset().', 'backupplus' ), $charset, $connection_charset ), 'notice-restore' );
					$charset = $connection_charset;
				}
				$this->set_names = $charset;
				if ( ! isset( $supported_charsets[ strtolower( $charset ) ] ) ) {
					$names = (string) $this->restore_options['backupplus_restorer_charset'];
					/* translators: 1: Requested charset, 2: Fallback charset */
					$backupplus->log( 'Connection character set: ' . sprintf( __( 'Requested character set (%1$s) is not present - changing to %2$s.', 'backupplus' ), esc_html( $charset ), esc_html( $names ) ), 'notice-restore' );
				}
				// A changed character set replaces the dump's statement; an unchanged one runs as written.
				if ( '' !== $names && rtrim( $smatches[2] ) !== $names ) {
					$sql_line = BackupPlus_SQL::render( 'SET NAMES %i', array( $names ) );
				}
			} elseif ( preg_match( '/^\s*create trigger /i', $sql_line ) ) {
				$sql_type   = 9;
				$identifier = '(?:`(?:[^`]|``)+`|[A-Za-z0-9_$]+)';
				if ( preg_match( '/^\s*CREATE TRIGGER\s+(' . $identifier . ')\s+(?:BEFORE|AFTER)\s+(?:INSERT|UPDATE|DELETE)\s+ON\s+(' . $identifier . ')\s+FOR EACH ROW\b/is', $sql_line, $trigger ) ) {
					$owner = '`' === $trigger[2][0] ? str_replace( '``', '`', substr( $trigger[2], 1, -1 ) ) : $trigger[2];
					if ( $this->table_should_be_skipped( $owner ) ) {
						$sql_line = '';
						$sql_type = -1;
						continue;
					}
					if ( $native_trigger ) {
						$target = $this->old_table_prefix ? BackupPlus_Manipulation_Functions::str_replace_once( $this->old_table_prefix, $import_table_prefix, $owner ) : $import_table_prefix . $owner;
						if ( $target === $owner && $import_table_prefix !== $this->old_table_prefix && empty( $this->db_permissons_forbidden['rename'] ) ) {
							$target = $import_table_prefix . $owner;
						}
						$sql_line = preg_replace_callback(
							'/\bON\s+' . $identifier . '\s+FOR EACH ROW\b/i',
							function () use ( $target ) {
								return BackupPlus_SQL::render( 'ON %i FOR EACH ROW', array( $target ) );
							},
							$sql_line,
							1
						);
						if ( empty( $this->db_permissons_forbidden['triggers'] ) ) {
							$trigger_name = '`' === $trigger[1][0] ? str_replace( '``', '`', substr( $trigger[1], 1, -1 ) ) : $trigger[1];
							$dropped      = $this->sql_exec( BackupPlus_SQL::render( 'DROP TRIGGER IF EXISTS %i', array( $trigger_name ) ), 9, '', false );
							if ( is_wp_error( $dropped ) ) {
								return $dropped;
							}
						}
					}
				} elseif ( $native_trigger || $selective_tables ) {
					return new WP_Error( 'native_trigger', 'Cannot determine the owning table of a native trigger.' );
				}
				// If the statement is not yet complete, then continue (to get the next line)
				if ( ! preg_match( '/(?:END)?\s*' . $delimiter_regex . '\s*$/', $sql_line ) ) {
					continue;
				}
				// If it's a comment then continue;
				if ( ! $native_trigger && preg_match( '/(?:--|#).+?' . $delimiter_regex . '\s*$/i', $buffer ) ) {
					continue;
				}
				$backupplus->log_restore_update(
					array(
						'type'  => 'state',
						'stage' => 'db',
						'data'  => array(
							'stage' => 'trigger',
							'table' => '',
						),
					)
				);
				if ( ! $native_trigger && $import_table_prefix != $this->old_table_prefix ) {
					if ( '' != $this->old_table_prefix ) {
						$sql_line = BackupPlus_Manipulation_Functions::str_replace_once( $this->old_table_prefix, $import_table_prefix, $sql_line );
					} else {
						$sql_line = BackupPlus_Manipulation_Functions::str_replace_once( $this->table_name, $this->new_table_name, $sql_line );
					}
				}
				if ( ';' !== $delimiter ) {
					$sql_line = preg_replace( '/END\s*' . $delimiter_regex . '\s*$/', 'END', $sql_line );
					// handle trigger statement which doesn't include begin and end in the trigger body, and remove the delimiter
					$sql_line = preg_replace( '/\s*' . $delimiter_regex . '\s*$/', '', $sql_line );
				}
				if ( ! empty( $this->db_permissons_forbidden['triggers'] ) ) {
					$backupplus->log( "Database user lacks permission to create triggers; statement will not be executed ($sql_line)" );
				}
			} elseif ( preg_match( '/^\s*drop trigger /i', $sql_line ) ) {
				// Avoid sending unrecognised delimiters to the SQL server (this only affects backups created outside UD; we use ";;" which is cunningly compatible)
				if ( ';' !== $delimiter ) {
					$sql_line = preg_replace( '/' . $delimiter_regex . '\s*$/', '', $sql_line );
				}
			} elseif ( preg_match( "/^\s*CREATE\s\s*(?:OR\s\s*REPLACE\s\s*)?(?:DEFINER\s*=\s*(?:`.{1,17}`@`[^\s]+`\s*|'.{1,17}'@'[^\s]+'\s*|[^\s]+?\s))?(?:AGGREGATE\s\s*)?(?:PROCEDURE|FUNCTION)((?:\s\s*[^\(`]+|\s*`(?:[^`]|``)+`))\s*\(/is", $sql_line, $routine_matches ) ) {
				// ^\s*create\s\s*(?:or\s\s*replace\s\s*)?.*?(?:(?:aggregate\s\s*)?function|procedure)\s\s*`(.+)`(?:\s\s*if\s\s*not\s\s*exists\s*|\s*)?\(
				// ^[^'\"]*create[^'\"]*(?:function(?:\s\s*if\s\s*not\s\s*exists)?|procedure)\s*`([^\r\n]+)`
				$sql_type = 12;
				// it's possible that a routine doesn't have BEGIN and END statements in its routine body, unfortunately sometimes it does have the statement separator (;) at the end of the routine statement but sometimes it doesn't
				// if it has the statement separator (;) then we add the statement separator into the regex and the preceding delimiter, if it doesn't have statement separator then check only the delimiter
				if ( ! preg_match( '/END\s*(?:\*\/)?' . $delimiter_regex . '\s*$/is', rtrim( $sql_line ) ) && ! preg_match( '/\;\s*' . $delimiter_regex . '\s*$/is', rtrim( $sql_line ) ) && ! preg_match( '/\s*(?:\*\/)?' . $delimiter_regex . '\s*$/is', rtrim( $sql_line ) ) ) {
					continue;
				}
				// if it's already at the end of the statement check whether it's a comment or not (e.g. SET @VARIABLE = 'the value'; -- the comment END;; or SET @VARIABLE = 'the value'; # the comment END;;;)
				if ( preg_match( '/(?:--|#).+?END\s*' . $delimiter_regex . '\s*$/i', rtrim( $sql_line ) ) && preg_match( '/(?:--|#).+?' . $delimiter_regex . '\s*$/i', rtrim( $sql_line ) ) ) {
					continue;
				}
				if ( is_array( $this->stored_routine_supported ) && ! empty( $this->stored_routine_supported ) && ! is_wp_error( $old_log_bin_trust_function_creators ) ) {
					$backupplus->log_restore_update(
						array(
							'type'  => 'state',
							'stage' => 'db',
							'data'  => array(
								'stage'        => 'stored_routine',
								'routine_name' => preg_replace( '/^`?(.+?)`?$/i', '$1', trim( str_replace( '``', '`', $routine_matches[1] ) ) ),
							),
						)
					);
					if ( $this->stored_routine_supported['is_binary_logging_enabled'] && ! $this->stored_routine_supported['is_function_creators_trusted'] && ! isset( $this->continuation_data['old_log_bin_trust_function_creators'] ) && is_null( $old_log_bin_trust_function_creators ) ) {
						// it's a new restoration
						// no matter what the database server is and the privileges the current DB user has, if the binary logging is enabled, log_bin_trust_function_creators is set to off and DB current, we could end up getting the below error when restoring routines
						// ERROR 1418 (HY000): This function has none of DETERMINISTIC, NO SQL, or READS SQL DATA in its declaration and binary logging is enabled (you *might* want to use the less safe log_bin_trust_function_creators variable)
						// we need to set the log_bin_trust_function_creators "ON" so that the db server will treat all functions as deterministic safe functions.
						// https://mariadb.com/kb/en/library/binary-logging-of-stored-routines/
						// https://dev.mysql.com/doc/refman/8.0/en/stored-programs-logging.html
						// if the DB current user is a non super admin, binary logging is enabled and log_bin_trust_function_creators is set to OFF/0 it will produce this error "(You do not have the SUPER privilege and binary logging is enabled (you *might* want to use the less safe log_bin_trust_function_creators variable)"
						// the log_bin_trust_function_creators variable is a global variable that should only be changed with caution because other plugins may also use it for some purpose, this can lead to an inaccurate value of log_bin_trust_function_creators especially if the restoration failed and the resumption could not be triggered
						$old_log_bin_trust_function_creators = $this->set_log_bin_trust_function_creators( 'ON' );
						if ( is_wp_error( $old_log_bin_trust_function_creators ) ) {
							$backupplus->log( 'set_log_bin_trust_function_creators(ON): ' . $old_log_bin_trust_function_creators );
						} else {
							$backupplus->log( 'log_bin_trust_function_creators value has been set to: ON' );
							// we also need to store the original value of the log_bin_trust_function_creator variable to UDP jobdata so that if something goes wrong in the restoration, we're still able in a earlier time to set this global variable back to what it was (register_shutdown_function seems to be the good one)
							$backupplus->jobdata_set( 'old_log_bin_trust_function_creators', $old_log_bin_trust_function_creators );
							$backupplus->log( 'The original value of log_bin_trust_function_creators variable has been successfully added to UDP jobdata' );
						}
					} elseif ( $this->stored_routine_supported['is_binary_logging_enabled'] && ! $this->stored_routine_supported['is_function_creators_trusted'] && is_null( $old_log_bin_trust_function_creators ) && isset( $this->continuation_data['old_log_bin_trust_function_creators'] ) ) {
						// it's a resumption of the previous run, it can be recognised from the existence of old_log_bin_trust_function_creators index in $continuation_data variable
						$old_log_bin_trust_function_creators = $this->continuation_data['old_log_bin_trust_function_creators'];
						$backupplus->log( 'Running a resumption from the previous restoration' );
						$this->set_log_bin_trust_function_creators( 'ON' );
						$backupplus->log( 'log_bin_trust_function_creators value has been set to: ON' );
					}

					// the routine's definer in the backup file could be different with the user and host on the targeted restore site, so we need to replace the user and host information for the definer option with the current user account or remove the DEFINER clause and let the system use the default value (which is current user)
					// replace the user and host with db_user and db_host
					// $sql_line = preg_replace("/^([^'\"]*create[^'\"]*definer\s*=\s*)(?:`.{1,17}`@`[^\s]+`|'.{1,17}'@'[^\s]+')(.+?(?:function(?:\s\s*if\s\s*not\s\s*exists)?|procedure)\s*`)/is", "$1`".DB_USER."`@`".DB_HOST."`$2", $sql_line);
					// remove the DEFINER clause
					$sql_line = preg_replace( "/^\s*(CREATE(?:\s\s*OR\s\s*REPLACE)?)\s\s*DEFINER\s*=\s*(?:`.{1,17}`@`[^\s]+`\s*|'.{1,17}'@'[^\s]+'\s*|[^\s]+?\s)((?:AGGREGATE\s\s*)?(?:PROCEDURE|FUNCTION))/is", '$1 $2', $sql_line );

					if ( preg_match( '/^\s*CREATE(?:\s\s*OR\s\s*REPLACE)?\s\s*(?:DEFINER\s*=\s*(?:`.{1,17}`@`[^\s]+`\s*|\'.{1,17}\'@\'[^\s]+\'\s*|[^\s]+?\s))?PROCEDURE(?:\s*`(?:[^`]|``)+`\s*|\s[^\(]+)(?\'params\'(?:[^()]+|\((?1)*\)))(?:(.*?)COMMENT\s\s*\'[^\']+\'|COMMENT\s\s*\'[^\']+\'(.*?)|(.*?))(?:(.*?)BEGIN|([^\'"]+))/is', $sql_line, $sql_security_matches, PREG_OFFSET_CAPTURE ) || preg_match( '/^\s*CREATE(?:\s\s*OR\s\s*REPLACE)?\s\s*(?:DEFINER\s*=\s*(?:`.{1,17}`@`[^\s]+`\s*|\'.{1,17}\'@\'[^\s]+\'\s*|[^\s]+?\s))?(?:AGGREGATE\s\s*)?FUNCTION(?:\s*`(?:[^`]|``)+`\s*|\s[^\(]+)(?\'params\'(?:[^()]+|\((?1)*\)))\s*RETURNS\s[\w]+(?:\(.*?\))?\s*(?:CHARSET\s\s*[^\s]+\s\s*)?(?:COLLATE\s\s*[^\s]+\s\s*)?(?:(.*?)COMMENT\s\s*\'[^\']+\'|COMMENT\s\s*\'[^\']+\'(.*?)|(.*?))(?:(.*?)BEGIN|(.*?)RETURN)/is', $sql_line, $sql_security_matches, PREG_OFFSET_CAPTURE ) ) {
						// replace SQL SECURITY DEFINER and add SQL SECURITY INVOKER
						$is_last_index_replaced = false;
						$sql_security_matches   = array_reverse( $sql_security_matches );
						foreach ( $sql_security_matches as $key => $match ) {
							if ( (int) $match[1] <= 0 || 'params' === $key ) {
								continue;
							}
							$length   = strlen( $match[0] );
							$match[0] = preg_replace( '/SQL\s\s*SECURITY\s\s*(?:DEFINER|INVOKER)/is', ' ', $match[0] );
							if ( ! $is_last_index_replaced ) {
								// $match[0] .= ' SQL SECURITY INVOKER ';
								$match[0]               = ' SQL SECURITY INVOKER ' . $match[0];
								$is_last_index_replaced = true;
							}
							$sql_line = substr_replace( $sql_line, $match[0], $match[1], max( 0, $length ) );
						}
					}

					// there could be a text/varchar variable declaration in the routine body in which the charset is also being specified in it, this could lead to an error when restoring the routine because of unsupported charset. for example, the declaration for a varchar/text variable in the routine body or the function parameter with the uf8mb4 charset defined but the running DB doesn't support utf8mb4
					// to handle this we first check the routine creation statement and collect all the variable declarations which in relation with text/char variables that contain charset specification and then replace or remove the charset if necessary
					/* e.g:
					create procedure `test`(_user_id varchar(50) CHARSET utf8, _user_passwd varchar(255) CHARSET utf8, success_text text CHARSET utf8mb4, failure_text text CHARSET utf8)
					begin
						declare insert_status int default 0;
						declare _user_id varchar(2049) CHARSET utf8;
						declare _password varchar(255) CHARSET utf8mb4;
						declare _parent_nid bigint;
						declare _uid bigint;
						declare _node varchar(255) CHARSET utf8mb4;
					end ;;
					*/
					if ( preg_match_all( '/(\s(?:long|medium|tiny)?text\s*(?:\([0-9]+\))?|\s(?:var)?char\s*(?:\([0-9]+\))?).*?charset\s([^;,\)\s]+).*?(?:,|;|\))/is', $sql_line, $charset_matches ) ) {
						foreach ( (array) $charset_matches[2] as $key => $charset ) {
							$replaced_charset_declaration = $charset_matches[0][ $key ];
							if ( ! empty( $charset ) && ! isset( $supported_charsets[ strtolower( trim( $charset ) ) ] ) ) {
								$replaced_charset_declaration = ! empty( $this->restore_options['backupplus_restorer_charset'] ) ? str_ireplace( $charset, $this->restore_options['backupplus_restorer_charset'], $replaced_charset_declaration ) : str_ireplace( array( 'charset', $charset ), array( '', '' ), $replaced_charset_declaration );
								$sql_line                     = str_ireplace( $charset_matches[0][ $key ], $replaced_charset_declaration, $sql_line );
							}
						}
					}
					if ( ! $this->stored_routine_supported['is_create_or_replace_supported'] ) {
						// "create or replace" syntax is used by MariaDB only, in case we are restoring from the MariaDB backup file to MySQL database server, we need to remove the "or replace" syntax if any
						$sql_line = preg_replace( "/^([^'\"]*)create\s\s*or\s\s*replace([^'\"]*(?:function(?:\s\s*if\s\s*not\s\s*exists)?|procedure)\s*`)/is", '$1create$2', $sql_line );
					}
					if ( ! $this->stored_routine_supported['is_if_not_exists_function_supported'] ) {
						// MariaDB supports IF NOT EXISTS syntax after the FUNCTION keyword (e.g create function if not exists function_name), we need to remove it to ensure the syntax compatility with MySQL
						$sql_line = preg_replace( "/^([^'\"]*create[^'\"]*function)\s\s*if\s\s*not\s\s*exists(\s*`)/is", '$1$2', $sql_line );
					}
					if ( ! $this->stored_routine_supported['is_aggregate_function_supported'] && preg_match( "/^[^'\"]*create[^'\"]*(?:\baggregate\s\s*function\b)\s*`([^\r\n]+)`/is", $sql_line, $aggregate_matches ) ) {
						// there's no way that we can make mariadb aggregate function compatible with MySQL, the function itself must contain "FETCH GROUP NEXT ROW" in the routine body which mysql doesn't know what that is
						$aggregate_log = "Function {$aggregate_matches[1]} has been neglected due to the unsupported function type (aggregate)";
						$backupplus->log( $aggregate_log );
						$backupplus->log( $aggregate_log, 'notice-restore' );
						$sql_line = ''; // we ignore the routine and move to the next line
						$sql_type = -1;
						continue;
					}
				} else {
					$backupplus->log( "Stored function/procedure {$routine_matches[1]} has been neglected due to the unsupported database routine creation" );
					// move to the next line if stored routine is not supported
					$sql_line = '';
					$sql_type = -1;
					continue;
				}
			} elseif ( preg_match( '/^.*?drop\s\s*(?:function|procedure)\s\s*(?:if\s\s*exists\s\s*)?/i', $sql_line ) ) {
				$sql_type = 13;
				// if (';' !== $delimiter) $sql_line = preg_replace('/'.$delimiter_regex.'\s*$/', '', $sql_line);
			} elseif ( preg_match( '/^\s*delimiter (\S+)\s*$/i', $sql_line, $matches ) ) {
				// Nothing to do here - deliberate no-op (is processed earlier)
				$sql_type = 10;
			} elseif ( preg_match( '/^CREATE(\s+ALGORITHM=\S+)?(\s+DEFINER=\S+)?(\s+SQL SECURITY (\S+))?\s+VIEW/i', $sql_line, $matches ) ) {
				$sql_type = 11;
				// remove DEFINER clause from the create view statement and add or replace SQL SECURITY DEFINER with INVOKER
				// https://regex101.com/r/2tOEhe/4/
				$sql_line = preg_replace( '/^(\s*CREATE\s\s*(?\'or_replace\'OR\s\s*REPLACE\s\s*)?(?\'algorithm\'ALGORITHM\s*=\s*[^\s]+\s\s*)?)(?\'definer\'DEFINER\s*=\s*(?:`.{1,17}`@`[^\s]+`\s*|\'.{1,17}\'@\'[^\s]+\'\s*|[^\s]+?\s\s*))?(?\'sql_security\'SQL\s\s*SECURITY\s\s*[^\s]+?\s\s*)?(VIEW(?:\s\s*IF\s\s*NOT\s\s*EXISTS)?(?:\s*`(?:[^`]|``)+`\s*|\s\s*[^\s]+\s\s*)AS)/is', '$1 SQL SECURITY INVOKER $6', $sql_line );
				if ( null !== $this->old_table_prefix ) {
					foreach ( array_keys( $this->restore_this_table ) as $table_name ) {
						// Code for a view can contain pretty much anything. As such, we want to be minimise the risks of unwanted matches.
						if ( in_array( $table_name, $this->view_names ) ) {
							continue; // since DROP VIEW statement is preceded by DROP TABLE statement (@see BackupPlus_Backup::write_table_backup_beginning), it makes the view name to be recognised as a real table and to be added to the $this->restore_this_table variable when executing the DROP TABLE statement via BackupPlus_Restorer::sql_exec method. This line prevents a view name from being replaced and suffixed with $import_table_prefix during an atomic restoration and/or when the view uses the same table prefix
						}
						if ( false !== strpos( $sql_line, $table_name ) ) {
							$new_table_name = ( '' == $this->old_table_prefix ) ? $import_table_prefix . $table_name : BackupPlus_Manipulation_Functions::str_replace_once( $this->old_table_prefix, empty( $this->db_permissons_forbidden['rename'] ) ? $this->final_import_table_prefix : $import_table_prefix, $table_name );
							$sql_line       = str_replace( $table_name, $new_table_name, $sql_line );
						}
					}
					// by default during an atomic restoration, the last created table is renamed at the end of the restoration process (@see the `if ($this->restoring_table)` code at the end of this method), typically the DB server will throw an error when creating a view that requires a table that doesn't exist in the database
					if ( $this->restoring_table ) {
						$final_table_name = $this->maybe_rename_restored_table();
						$this->restored_table( $final_table_name, $this->final_import_table_prefix, $this->old_table_prefix, $this->table_engine );
						$this->restoring_table = ''; // reset this variable so that the same table renaming procedure at the end of this method doesn't get executed
					}
				}
			} elseif ( preg_match( '/^SET @@GLOBAL.GTID_PURGED/i', $sql_line ) ) {
				// skip the SET @@GLOBAL.GTID_PURGED command
				$sql_type = 17;
			} elseif ( preg_match( '/^\/\*\!\d+\s+SET\s+(?:[^,].*)?(?=SQL_MODE\s*=)/i', $sql_line ) ) {
				// skip the SET SQL_MODE command because we adjust the SQL mode dynamically during restoration and offer users the flexibility to change it themselves using action or filter, we don't use the SQL mode specified in the dumped file. Skipping this line prevents any unintended changes to the default SQL mode already set by our system or any custom mode chosen by the user.
				$sql_type = 18;
			} else {
				// Prevent the previous value of $sql_type being retained for an unknown type
				$sql_type = 0;
			}

			// Do not execute "USE" or "CREATE|DROP DATABASE" or "SET @@GLOBAL.GTID_PURGED" or "SET SQL_MODE" commands
			// Database-wide routines, views and unrecognised statements have no selected table owner.
			if ( $selective_tables && ( in_array( $sql_type, array( 0, 11, 12, 13, 16 ), true ) || preg_match( '/^\s*drop\s+trigger\b/i', $sql_line ) ) ) {
				$backupplus->log( 'Skipped database-wide statement during selected-table restore.' );
			} elseif ( ! in_array( $sql_type, array( 6, 7, 10, 17, 18 ) ) && ( 9 != $sql_type || empty( $this->db_permissons_forbidden['triggers'] ) ) ) {
				$do_exec = $this->sql_exec( $sql_line, $sql_type );
				if ( is_wp_error( $do_exec ) ) {
					return $do_exec;
				}
			} else {
				$backupplus->log( "Skipped execution of SQL statement (unwanted or internally handled type=$sql_type): $sql_line" );
			}

			// currently, the way UDP backups the generated column is different with the way mysqldump does it.
			// mysqldump doesn't include all of the columns/fields value into the insert statement but instead it specifies only non generated-columns to be included in the insert statement (i.e insert into `table`(`non-generated-column1`,`non-generated-column2`) values('value','value2'))
			// UDP includes all the columns (i.e insert into `table` values ('non-generated-column-value1', 'non-generated-column-value2', 'virtual-column-value', 'stored-column-value'))
			// the code below will only get executed if the running DB server is MySQL and the insert statement has all the columns included. If we change the way UDP backup the generated columns to be the same as mysqldump then the code below is no longer necessary and can be removed
			if ( $sql_types['INSERT'] == $sql_type && ! empty( $this->generated_columns[ $this->table_name ] ) ) {
				// MySQL doesn't allow the "generated columns" value takes its place in the insert statement, and because of that the only solution is to change the generated columns to standard columns which have been done previously
				// now we need to change it back to generated columns but unfortunately changing it to virtual type wont work, it can only be changed to stored type
				if ( ! isset( $this->supported_generated_column_engines[ strtolower( $this->table_engine ) ] ) ) {
					$this->supported_generated_column_engines[ strtolower( $this->table_engine ) ] = BackupPlus_Database_Utility::is_generated_column_supported( $this->table_engine );
				}
				if ( ( $generated_column_db_info = $this->supported_generated_column_engines[ strtolower( $this->table_engine ) ] ) && ! $generated_column_db_info['can_insert_ignore_to_generated_column'] && isset( $this->generated_columns_exist_in_the_statement[ $this->table_name ] ) && true === $this->generated_columns_exist_in_the_statement[ $this->table_name ] ) {
					foreach ( (array) $this->generated_columns[ $this->table_name ]['columns'] as $generated_column ) {
						// Column options after the name, from the table's own CREATE statement in the dump.
						$new_data_type_definition = '';
						foreach ( (array) $generated_column['column_data_type_definition'] as $key => $data_type_definition ) {
							if ( empty( $data_type_definition ) || 0 === strlen( trim( $data_type_definition[0] ) ) ) {
								continue;
							}
							if ( in_array( $key, array( 'DATA_TYPE_TOKEN', 'GENERATED_ALWAYS_TOKEN', 'COMMENT_TOKEN' ) ) ) {
								$new_data_type_definition .= ' ' . $data_type_definition[0];
								continue; // we only want the data type options after the "generated always as()", so we continue
							}
							// If the database server doesn't support either null or not null constraint on generated virtual/stored/persistent column then the constraints need to be removed
							$new_data_type_definition .= $generated_column_db_info['is_not_null_supported'] ? $data_type_definition[0] : preg_replace( '/\b(?:not\s+null|null)\b/i', '', $data_type_definition[0] );
							if ( ! $generated_column['is_virtual'] ) {
								// If the persistent type is not supported it likely means that the currently running db server is MySQL, Mariadb uses persistent as an alias for stored type so if the backup file is taken from MariaDB then it needs to be changed to stored
								$new_data_type_definition = $generated_column_db_info['is_persistent_supported'] ? $new_data_type_definition : preg_replace( '/\bpersistent\b/i', 'STORED', $new_data_type_definition );
							}
						}
						$new_data_type_definition = preg_replace( '/\bvirtual\b/i', 'STORED', $new_data_type_definition );
						// altering table could take minutes or hours to complete depending on the size of the rows of the table
						$statement = BackupPlus_SQL::render( 'ALTER TABLE %i CHANGE %i %i', array( $this->new_table_name, $generated_column['column_name'], $generated_column['column_name'] ) ) . $new_data_type_definition;
						$do_exec   = $this->sql_exec( $statement, -1 );
						if ( is_wp_error( $do_exec ) ) {
							return $do_exec;
						}
					}
				}
			}

			// Reset
			$sql_line = '';
			$sql_type = -1;

		}

		// This ought to only be possible with a corrupted backup file - if the above loop finally exited via a 'continue' which did not also reset $sql_line
		if ( '' !== $sql_line ) {

			$logit = substr( $sql_line, 0, 100 );
			$backupplus->log( sprintf( 'SQL remained at the end of the backup that was not executed: %s', '(' . strlen( $sql_line ) . ', ' . $logit . ' ...)' ) );

			$backupplus->log(
				__( 'Warning:', 'backupplus' ) . ' ' .
				sprintf(
					/* translators: %s: SQL line */
					__( 'SQL remained at the end of the backup that could not be executed: %s', 'backupplus' ),
					'(' . strlen( $sql_line ) . ', ' . $logit . ' ...)'
				),
				'notice-restore'
			);

		}

		if ( is_array( $this->stored_routine_supported ) && $this->stored_routine_supported['is_binary_logging_enabled'] ) {
			// if this condition is met, it means that db server binary logging is enabled and the value of the log_bin_trust_function_system_variable has previously been set to ON (1), and now the value must be changed back to what it originally was
			if ( isset( $this->continuation_data['old_log_bin_trust_function_creators'] ) ) { // it's a resumption
				$old_log_bin_trust_function_creators = $this->continuation_data['old_log_bin_trust_function_creators'];
			} else {
				$old_log_bin_trust_function_creators = $backupplus->jobdata_get( 'old_log_bin_trust_function_creators' );
			}
			if ( is_string( $old_log_bin_trust_function_creators ) && '' !== $old_log_bin_trust_function_creators ) {
				$this->set_log_bin_trust_function_creators( $old_log_bin_trust_function_creators );
				// no need to check the return value of the set_log_bin_trust_function_creators here as if it is an wp error it has been handled already and this block of code wont be executed
				$backupplus->log( 'log_bin_trust_function_creators variable has been reset: ' . $old_log_bin_trust_function_creators );
				// unset the old_log_bin_trust_function_creators index from the continuation_data so that the on_shutdown function won't check it again
				unset( $this->continuation_data['old_log_bin_trust_function_creators'] );
				// also delete the old_log_bin_trust_function_creators jobdata to prevent the on_shutdown function accessing and deleting it twice
				$backupplus->jobdata_delete( 'old_log_bin_trust_function_creators' );
				$backupplus->log( 'log_bin_trust_function_creators variable has successfully been removed from UDP jobdata' );
			}
		}

		// Rescan storage, but only if there was remote storage and a database; otherwise just re-scan locally
		if ( $this->has_table_selection() ) {
			// Rebuilding history writes to the options table, which may not be selected.
		} elseif ( ! empty( $this->ud_backup_set['db'] ) && ! empty( $this->ud_backup_set['service'] ) && ( 'none' !== $this->ud_backup_set['service'] && 'email' !== $this->ud_backup_set['service'] && array( '' ) !== $this->ud_backup_set['service'] && array( 'none' ) !== $this->ud_backup_set['service'] && array( 'email' ) !== $this->ud_backup_set['service'] ) ) {
			$only_add_this_file = array( 'file' => $this->ud_backup_set['db'] );
			BackupPlus_Backup_History::rebuild( true, $only_add_this_file );
		} else {
			BackupPlus_Backup_History::rebuild();
		}

		if ( ! empty( $this->db_permissons_forbidden['lock'] ) ) {
			$backupplus->log( 'Leaving maintenance mode' );
		} else {
			$backupplus->log( 'Unlocking database and leaving maintenance mode' );
			$this->unlock_tables();
		}
		$this->maintenance_mode( false );

		if ( $this->restoring_table ) {
			$final_table_name = $this->maybe_rename_restored_table();
			$this->restored_table( $final_table_name, $this->final_import_table_prefix, $this->old_table_prefix, $this->table_engine );
		}

		// drop the dummy restored tables
		if ( $this->is_dummy_db_restore ) {
			$this->drop_tables( $this->restored_table_names );
		}

		$time_taken = microtime( true ) - $this->start_time;
		$backupplus->log_e( 'Finished: lines processed: %d in %.2f seconds', $this->line, $time_taken );
		if ( $is_plain ) {
			fclose( $dbhandle );
		} elseif ( $is_bz2 ) {
			bzclose( $dbhandle );
		} else {
			gzclose( $dbhandle );
		}

		global $wp_filesystem;

		if ( ! $wp_filesystem->delete( $working_dir . '/' . $db_basename, false, 'f' ) ) {
			$this->restore_log_permission_failure_message( $working_dir, 'Delete ' . $working_dir . '/' . $db_basename );
		}

		return true;
	}
}
