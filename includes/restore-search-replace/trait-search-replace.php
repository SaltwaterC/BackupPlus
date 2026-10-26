<?php

/** Search and replace of the old site locations in the restored database. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Restore_Search_Replace_Replace {

	/**
	 * Builds from supplied parameters and $this->(siteurl,home,content,uploads,abspath)
	 *
	 * @param String         $old_siteurl - the old site url
	 * @param String         $old_home    - the old home url
	 * @param Boolean|String $old_content - the old content url
	 * @param Boolean|String $old_uploads - the old upload url
	 * @param String         $old_abspath - the old abspath
	 *
	 * @return Array - itself containing two arrays, with corresponding 'search' and 'replace' items.
	 */
	private function build_searchreplace_array( $old_siteurl, $old_home, $old_content = false, $old_uploads = false, $old_abspath = '' ) {

		// The uploads parameter, if === false, should be ignored - it is only intended to be used in the special case of single-into-multisite imports (only in that case with $this->uploads get set)
		if ( false === $old_content && false === $old_uploads ) {
			$old_content = $old_siteurl . '/wp-content';
		}
		$from_array = array();
		$to_array   = array();

		if ( ! empty( $old_siteurl ) && $old_siteurl == $old_home ) {
			$from_array[] = $old_home;
			// Used to be site until Sep 2016, but that is wrong. Most likely it was the best possibility before the upload URL was also recorded/known.
			$to_array[] = $this->home;
		} elseif ( ! empty( $old_home ) && strpos( $old_siteurl, $old_home ) === 0 ) {
			// strpos: haystack, needle - i.e. old_home is a (proper, since they were not ==) substring of old_siteurl
			$from_array[] = $old_siteurl;
			$to_array[]   = $this->siteurl;
			$from_array[] = $old_home;
			$to_array[]   = $this->home;
			// If the source home URL is also a proper substring of the destination site URL, then this should be skipped
			if ( $old_home != $this->siteurl && strpos( $this->siteurl, $old_home ) === 0 ) {
				// Not pretty, but the only solution that can cope with content in posts that contains references to both site and home URLs in this case. This extra search URL un-does the adding of an unnecessary duplicate portion to site URLs in the case that is detected here.
				$from_array[] = $this->home . substr( $this->home, strlen( $old_home ) );
				$to_array[]   = $this->home;
			}
		} elseif ( ! empty( $old_siteurl ) && strpos( $old_home, $old_siteurl ) === 0 ) {
			// old_siteurl is a substring of old_home (weird!)
			$from_array[] = $old_home;
			$to_array[]   = $this->home;
			$from_array[] = $old_siteurl;
			$to_array[]   = $this->siteurl;
		} else {
			// neither contains the other
			if ( ! empty( $old_siteurl ) ) {
				$from_array[] = $old_siteurl;
				$to_array[]   = $this->siteurl;
			}
			if ( ! empty( $old_home ) ) {
				$from_array[] = $old_home;
				$to_array[]   = $this->home;
			}
		}
		// We now have a minimal array based on the site_url and home settings
		// The case we need to detect is: (site_url is a prefix of content_url and new_site_url is a prefix of new_content_url and the remains are the same.
		// We do [0] of the existing array, to handle the weird case where old_siteurl is a substring of old_home (i.e. we get the shortest possible match)
		// We will want to do the content URLs first, since they are likely to be longest
		if ( empty( $old_content ) || empty( $this->content ) || ( ! empty( $from_array ) && 0 === strpos( $old_content, $from_array[0] ) && 0 === strpos( $this->content, $to_array[0] ) && substr( $old_content, strlen( $from_array[0] ) ) === substr( $this->content, strlen( $to_array[0] ) ) ) ) {
			// OK - nothing to do - is already covered
		} else {
			// Search/replace needed
			array_unshift( $from_array, $old_content );
			array_unshift( $to_array, $this->content );
		}
		if ( empty( $old_uploads ) || empty( $this->uploads ) || ( ! empty( $from_array ) && 0 === strpos( $old_uploads, $from_array[0] ) && 0 === strpos( $this->uploads, $to_array[0] ) && substr( $old_uploads, strlen( $from_array[0] ) ) === substr( $this->uploads, strlen( $to_array[0] ) ) ) ) {
			// OK - nothing to do - is already covered or no data is present
		} else {
			// Search/replace needed
			array_unshift( $from_array, $old_uploads );
			array_unshift( $to_array, $this->uploads );
		}

		$schemes  = array( 'http', 'https' );
		$prefixes = array( 'www.', '' );
		// Add the opposite http version so that sites with mixed links are caught
		foreach ( $from_array as $key => $value ) {
			if ( preg_match( '#^https?://(?:www\.)?(.+)#i', $value, $matches ) ) {
				foreach ( $schemes as $scheme ) {
					foreach ( $prefixes as $prefix ) {
						if ( ! in_array( $scheme . '://' . $prefix . $matches[1], $from_array ) ) {
							$from_array[] = $scheme . '://' . $prefix . $matches[1];
							$to_array[]   = $to_array[ $key ];
						}
					}
				}
			}
		}

		if ( rtrim( $old_abspath, '/' ) !== '' ) {
			$from_array[] = rtrim( $old_abspath, '/' );
			$to_array[]   = rtrim( ABSPATH, '/' );
		}

		return array( $from_array, $to_array );
	}

	public function backupplus_restored_db( $info, $import_table_prefix ) {

		global $backupplus;

		if ( version_compare( PHP_VERSION, '5.3', '<' ) ) {
			$unsupported_search_replace_msg = 'The search and replace operation was disregarded due to the PHP version being unsupported (' . PHP_VERSION . ')';
			$backupplus->log_e( $unsupported_search_replace_msg );
			return;
		}
		$backupplus->log( 'Begin search and replace (backupplus_restored_db)' );
		$backupplus->log( __( 'Database: search and replace site URL', 'backupplus' ), 'database-replace-site-url' );

		if ( empty( $this->restore_options['backupplus_restorer_replacesiteurl'] ) ) {
			$backupplus->log_e( 'This option was not selected.' );
			return;
		}

		$replace_this_siteurl = isset( $this->old_siteurl ) ? $this->old_siteurl : '';

		// Don't call site_url() - the result may/will have been cached.
		$options_table      = 'options';
		$options_table_name = $this->base_prefix . $options_table;

		$db_siteurl_thissite = BackupPlus_SQL::wpdb()->value( "SELECT option_value FROM %i WHERE option_name='siteurl'", array( $options_table_name ) );

		$db_home_thissite = BackupPlus_SQL::wpdb()->value( "SELECT option_value FROM %i WHERE option_name='home'", array( $options_table_name ) );

		if ( ! $replace_this_siteurl ) {
			$replace_this_siteurl = $db_siteurl_thissite;
		}

		$replace_this_home = isset( $this->old_home ) ? $this->old_home : '';
		if ( ! $replace_this_home ) {
			$replace_this_home = $db_home_thissite;
		}

		$replace_this_content = isset( $this->old_content ) ? $this->old_content : '';
		if ( ! $replace_this_content ) {
			$replace_this_content = $replace_this_siteurl . '/wp-content';
		}

		$replace_this_uploads = isset( $this->old_uploads ) ? $this->old_uploads : false;

		$replace_this_abspath = $this->old_abspath;

		// Sanity checks
		if ( empty( $replace_this_siteurl ) ) {
			/* translators: 1: Parameter name, 2: Value */
			$backupplus->log( sprintf( __( 'Error: unexpected empty parameter (%1$s, %2$s)', 'backupplus' ), 'backup_siteurl', $this->siteurl ), 'warning-restore' );
			return;
		}
		if ( empty( $replace_this_home ) ) {
			/* translators: 1: Parameter name, 2: Value */
			$backupplus->log( sprintf( __( 'Error: unexpected empty parameter (%1$s, %2$s)', 'backupplus' ), 'backup_home', $this->home ), 'warning-restore' );
			return;
		}
		if ( empty( $replace_this_content ) ) {
			/* translators: 1: Parameter name, 2: Value */
			$backupplus->log( sprintf( __( 'Error: unexpected empty parameter (%1$s, %2$s)', 'backupplus' ), 'backup_content_url', $this->content ), 'warning-restore' );
			return;
		}

		if ( empty( $this->siteurl ) ) {
			/* translators: 1: Parameter name, 2: Value */
			$backupplus->log( sprintf( __( 'Error: unexpected empty parameter (%1$s, %2$s)', 'backupplus' ), 'new_siteurl', $replace_this_siteurl ), 'warning-restore' );
			return;
		}
		if ( empty( $this->home ) ) {
			/* translators: 1: Parameter name, 2: Value */
			$backupplus->log( sprintf( __( 'Error: unexpected empty parameter (%1$s, %2$s)', 'backupplus' ), 'new_home', $replace_this_home ), 'warning-restore' );
			return;
		}
		// Only complain about the empty content parameter if it's not the case where we use the uploads parameter instead
		if ( empty( $this->content ) && empty( $this->uploads ) ) {
			/* translators: 1: Parameter name, 2: Value */
			$backupplus->log( sprintf( __( 'Error: unexpected empty parameter (%1$s, %2$s)', 'backupplus' ), 'new_contenturl', $replace_this_content ), 'warning-restore' );
			return;
		}

		if ( $replace_this_siteurl == $this->siteurl && $replace_this_home == $this->home && $replace_this_content == $this->content ) {
			$this->is_migration = false;
			/* translators: %s: Site URL */
			$backupplus->log( sprintf( __( 'Nothing to do: the site URL is already: %s', 'backupplus' ), $this->siteurl ), 'notice-restore' );
			return;
		}

		$this->is_migration = true;

		do_action( 'backupplus_restored_db_is_migration' );

		// Detect situation where the database's siteurl in the header differs from that actual row data in the options table. This can occur if the options table was being over-ridden by a constant. In that case, the search/replace will have failed to set the option table's siteurl; and the result will be that that siteurl is hence wrong, leading to site breakage. The solution is to re-set it.
		// $info['expected_oldsiteurl'] is from the db.gz file header
		if ( isset( $info['expected_oldsiteurl'] ) && $info['expected_oldsiteurl'] != $db_siteurl_thissite && $db_siteurl_thissite != $this->siteurl ) {
			/* translators: 1: Actual database site URL, 2: Expected site URL */
			$backupplus->log_e( sprintf( __( 'Warning: the database\'s site URL (%1$s) is different to what we expected (%2$s)', 'backupplus' ), $db_siteurl_thissite, $info['expected_oldsiteurl'] ) );
			// Here, we change only the site URL entry; we don't run a full search/replace based on it. In theory, if someone developed using two different URLs, then this might be needed.
			if ( ! empty( $this->base_prefix ) && ! empty( $this->siteurl ) ) {
				BackupPlus_SQL::wpdb()->execute( "UPDATE %i SET option_value = ? WHERE option_name='siteurl'", array( $options_table_name ), array( $this->siteurl ) );
			}
		}

		if ( isset( $info['expected_oldhome'] ) && $info['expected_oldhome'] != $db_home_thissite && $db_home_thissite != $this->home ) {
			/* translators: 1: Actual database home URL, 2: Expected home URL */
			$backupplus->log_e( sprintf( __( 'Warning: the database\'s home URL (%1$s) is different to what we expected (%2$s)', 'backupplus' ), $db_home_thissite, $info['expected_oldhome'] ) );
			if ( ! empty( $this->base_prefix ) && ! empty( $this->home ) ) {
				BackupPlus_SQL::wpdb()->execute( "UPDATE %i SET option_value = ? WHERE option_name='home'", array( $options_table_name ), array( $this->home ) );
			}
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 1800 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}
		list($from_array, $to_array) = $this->build_searchreplace_array( $replace_this_siteurl, $replace_this_home, $replace_this_content, $replace_this_uploads, $replace_this_abspath );

		foreach ( $from_array as $ind => $from_url ) {
			$backupplus->log_e( 'Database search and replace: replace %s in backup dump with %s', $from_url, $to_array[ $ind ] );
		}

		return $this->backupplus_restored_db_dosearchreplace( $from_array, $to_array, $import_table_prefix );
	}

	private function backupplus_restored_db_dosearchreplace( $from_array, $to_array, $import_table_prefix, $examine_siteurls = true ) {

		global $backupplus, $wpdb, $backupplus_restorer;

		// Now, get an array of tables and then send it off to $backupplus_restorer->search_replace_obj->icit_srdb_replacer()
		// Code modified from searchreplacedb2.php version 2.1.0 from http://www.davidcoveney.com

		// Do we have any tables and if so build the all tables array
		$tables = array();

		// We use $wpdb for non-performance-sensitive operations (e.g. one-time calls)
		$tables_mysql = $wpdb->get_results( 'SHOW TABLES', ARRAY_N );

		$is_multisite = is_multisite();
		if ( $examine_siteurls && $is_multisite ) {
			$sites  = BackupPlus_SQL::wpdb()->rows( 'SELECT id, domain, path FROM %i ORDER BY id ASC', array( $import_table_prefix . 'site' ), array(), ARRAY_N );
			$nsites = array();
			foreach ( (array) $sites as $site ) {
				$nsites[ $site[0] ] = array(
					'dom'  => $site[1],
					'path' => $site[2],
				);
			}
			$blogs  = BackupPlus_SQL::wpdb()->rows( 'SELECT blog_id, domain, path, site_id FROM %i ORDER BY blog_id ASC', array( $import_table_prefix . 'blogs' ), array(), ARRAY_N );
			$nblogs = array();
			foreach ( (array) $blogs as $blog ) {
				$nblogs[ $blog[0] ] = array(
					'dom'     => $blog[1],
					'path'    => $blog[2],
					'site_id' => $blog[3],
				);
			}
		}

		if ( ! $tables_mysql ) {
			$backupplus->log( __( 'Error:', 'backupplus' ) . ' ' . __( 'Could not get list of tables', 'backupplus' ), 'warning-restore' );
			$backupplus->log( 'Could not get list of tables' );
			$backupplus_restorer->search_replace_obj->print_error( 'SHOW TABLES' );
			return false;
		} else {
			// Run through the array - each element a numerically-indexed array

			$multisite_processed_sites = array();

			foreach ( $tables_mysql as $table ) {

				// Type equality is necessary, as we don't want to match false
				// "Warning: strpos(): Empty delimiter" means that the second parameter is a zero-length string
				if ( 0 === strpos( $table[0], $import_table_prefix ) ) {
					$tablename = $table[0];

					$stripped_table = substr( $tablename, strlen( $import_table_prefix ) );
					// Remove multisite site number prefix, if relevant
					if ( is_multisite() && preg_match( '/^(\d+)_(.*)$/', $stripped_table, $matches ) ) {
						$stripped_table = $matches[2];
					}

					if ( ! empty( $this->which_tables ) && is_array( $this->which_tables ) ) {
						if ( ! in_array( $tablename, $this->which_tables ) ) {
							$backupplus->log( __( 'Search and replacing table:', 'backupplus' ) . ': ' . __( 'skipped (not in list)', 'backupplus' ), 'notice-restore', 'restore-skipped-' . $tablename );
							continue;
						}
					}

					$still_needs_doing = empty( $this->tables_replaced[ $tablename ] );

					// Looking for site tables on multisite
					if ( $examine_siteurls && $is_multisite && ! empty( $this->restored_blogs ) && preg_match( '/^(\d+)_(.*)$/', substr( $tablename, strlen( $import_table_prefix ) ), $tmatches ) && is_numeric( $tmatches[1] ) && ! empty( $this->restored_blogs[ $tmatches[1] ] ) && ! empty( $nblogs[ $tmatches[1] ] ) && ( preg_match( '#^((https?://)([^/]+))#i', $this->home, $matches ) || preg_match( '#^((https?://)([^/]+))#i', $this->siteurl, $matches ) ) ) {
						// If the database file was not created by UD, then it may be out of order. Specifically, the 'blogs' table might have come *after* the tables for the individual sites. As a result, the tables for those sites may not have been fully searched + replaced... so we need to check that.
						// What are we expecting the site_url to be?
						$blog_id = $tmatches[1];
						if ( empty( $multisite_processed_sites[ $blog_id ] ) ) {
							$multisite_processed_sites[ $blog_id ] = true;
							$site_url_current                      = BackupPlus_SQL::wpdb()->value( "SELECT option_value FROM %i WHERE option_name='siteurl'", array( $import_table_prefix . $blog_id . '_options' ) );
							if ( is_string( $site_url_current ) ) {
								$bpath = $this->restored_blogs[ $blog_id ]['path'];
								// Jan 2016: This line is old, and removes the main site's path, if present, from the front of this site's path - but why? I suspect it was so that images could be referenced directly without help from .htaccess - perhaps from when media used to be differently organised?
								// $bpathroot = $this->restored_blogs[1]['path'];
								// if (substr($bpath, 0, strlen($bpathroot)) == $bpathroot) $bpath = substr($bpath, strlen($bpathroot)-1);

								$proto = $matches[2];

								$site_url_target = $proto . $nblogs[ $blog_id ]['dom'] . untrailingslashit( $bpath );
								if ( $site_url_target != $site_url_current ) {
									$backupplus->log( "Site url ($site_url_current) for this blog (blog_id=$blog_id) did not match the expected value ($site_url_target) - replacing" );
									$multisite_processed_sites[ $blog_id ] = 1;
									$still_needs_doing                     = true;
									$from_array[]                          = $site_url_current;
									$to_array[]                            = $site_url_target;
								}
							}
						} elseif ( ! $still_needs_doing && 1 === $multisite_processed_sites[ $blog_id ] ) {
							$still_needs_doing = true;
						}
					}

					if ( $still_needs_doing ) {
						$tables[ $tablename ] = $stripped_table;
					} else {
						$backupplus->log( sprintf( __( 'Search and replacing table:', 'backupplus' ) ) . ' ' . $tablename . ': ' . __( 'already done', 'backupplus' ), 'notice-restore', 'restore-table-already-done-' . $tablename );
						$backupplus->log( 'Search and replacing table: ' . $tablename . ': already done' );
					}
				}
			}
		}

		$final_report = $this->report;

		if ( ! empty( $tables ) ) {

			$report = $backupplus_restorer->search_replace_obj->icit_srdb_replacer( $from_array, $to_array, $tables, $this->page_size );

			// Output any errors encountered during the db work.
			if ( ! empty( $report['errors'] ) && is_array( $report['errors'] ) ) {

				$backupplus->log( __( 'Error:', 'backupplus' ), 'warning-restore', 'db-replace-error' );

				$processed_errors = array();
				foreach ( $report['errors'] as $error ) {
					if ( in_array( $error, $processed_errors ) ) {
						continue;
					}
					$processed_errors[] = $error;
					$num                = count( array_keys( $report['errors'], $error ) );
					$error_msg          = $error;
					if ( $num > 1 ) {
						$error_msg .= ' (x' . $num . ')';
					}
					$backupplus->log( $error_msg, 'warning-restore' );
				}
			}

			if ( false == $report ) {
				/* translators: %s: Operation name */
				$backupplus->log( sprintf( __( 'Failed: the %s operation was not able to start.', 'backupplus' ), 'search and replace' ), 'warning-notice' );
			} elseif ( ! is_array( $report ) ) {
				/* translators: %s: Operation name */
				$backupplus->log( sprintf( __( 'Failed: we did not understand the result returned by the %s operation.', 'backupplus' ), 'search and replace' ), 'warning-notice' );
			}

			// Calc the time taken.
			foreach ( array( 'tables', 'rows', 'change', 'updates' ) as $key ) {
				$final_report[ $key ] += $report[ $key ];
			}
			$final_report['timetaken'] += $report['end'] - $report['start'];
			foreach ( $report['errors'] as $error ) {
				$final_report['errors'][] = $error;
			}
		}

		$backupplus->log( __( 'Tables examined:', 'backupplus' ) . ' ' . $final_report['tables'], 'notice-restore', 'restore-tables-examined' );
		$backupplus->log( __( 'Rows examined:', 'backupplus' ) . ' ' . $final_report['rows'], 'notice-restore', 'restore-rows-examined' );
		$backupplus->log( __( 'Changes made:', 'backupplus' ) . ' ' . $final_report['change'], 'notice-restore', 'restore-changes-made' );
		$backupplus->log( __( 'SQL update commands run:', 'backupplus' ) . ' ' . $final_report['updates'], 'notice-restore', 'restore-sql-commands-run' );
		$backupplus->log( __( 'Errors:', 'backupplus' ) . ' ' . count( $final_report['errors'] ), 'notice-restore', 'restore-tables-errors' );
		$backupplus->log( __( 'Time taken (seconds):', 'backupplus' ) . ' ' . round( $final_report['timetaken'], 3 ), 'notice-restore', 'restore-tables-time-taken' );

		// Keep the source site URL so the admin notice can check for stale references in .htaccess after the restore-time replacement.
		if ( ! empty( $this->old_siteurl ) ) {
			update_site_option( 'backupplus_migrated_site_domain', rtrim( str_ireplace( array( 'http://', 'https://' ), '', $this->old_siteurl ), '/' ) );
		}
	}
}
