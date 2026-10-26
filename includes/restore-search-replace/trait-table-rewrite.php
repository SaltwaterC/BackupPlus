<?php

/** Adjusting a table while it is restored: prefixes, options and user meta keys. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Restore_Search_Replace_Table_Rewrite {

	public function backupplus_restored_db_table( $table, $import_table_prefix, $engine = '' ) {

		global $backupplus, $backupplus_restorer;

		// Anything else to do?
		if ( empty( $this->restore_options['backupplus_restorer_replacesiteurl'] ) || version_compare( PHP_VERSION, '5.3', '<' ) ) {
			return;
		}

		// Can only do something if the old siteurl is known
		$old_siteurl = isset( $this->old_siteurl ) ? $this->old_siteurl : '';
		$old_home    = isset( $this->old_home ) ? $this->old_home : '';
		$old_content = isset( $this->old_content ) ? $this->old_content : $old_siteurl . '/wp-content';
		// This wasn't stored in the backup header until 1.11.20. It's usually $old_content.'/uploads', but there's no need to force that, as on a default setup, the search/replace is caught by the content replace anyway
		$old_uploads = isset( $this->old_uploads ) ? $this->old_uploads : false;
		if ( ! $old_home && ! $old_siteurl ) {
			return;
		}

		$old_abspath = $this->old_abspath;

		if ( empty( $this->tables_replaced ) ) {
			$this->tables_replaced = array();
		}

		// Already done?
		if ( ! empty( $this->tables_replaced[ $table ] ) ) {
			return;
		}

		// If not done already, then search & replace this table, + record that it is done
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 1800 );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}
		$stripped_table = substr( $table, strlen( $import_table_prefix ) );
		// Remove multisite site number prefix, if relevant
		if ( is_multisite() && preg_match( '/^(\d+)_(.*)$/', $stripped_table, $matches ) ) {
			$stripped_table = $matches[2];
		}

		// Skip tables that do not need URL replacement and are large enough to risk timeouts on hosts that enforce them.
		// The term_relationships table contains 3 columns, all integers. Therefore, we can skip it. It can easily get big, so this is a good time-saver.
		$skip_tables = array( 'slim_stats', 'statpress', 'term_relationships', 'icl_languages_translations', 'icl_string_positions', 'icl_string_translations', 'icl_strings', 'redirection_logs', 'Counterize', 'Counterize_UserAgents', 'Counterize_Referers', 'adrotate_stats', 'login_security_solution_fail', 'wfHits', 'wfhits', 'wbz404_logs', 'wbz404_redirects', 'wfFileMods', 'wffilemods', 'tts_trafficstats', 'tts_referrer_stats', 'dmsguestbook', 'relevanssi', 'wponlinebackup_generations', 'svisitor_stat', 'simple_feed_stats', 'itsec_log', 'rp_tags', 'woocommerce_order_items', 'relevanssi_log', 'blc_instances', 'wysija_email_user_stat', 'woocommerce_sessions', 'et_bloom_stats', 'redirection_404', 'lbakut_activity_log', 'stream_meta', 'wfBlockedIPLog', 'wfblockediplog', 'page_visit_history', 'strack_st', 'eum_logs' );

		if ( in_array( $stripped_table, $skip_tables ) ) {
			$this->tables_replaced[ $table ] = true;
			$backupplus->log_e( 'Skipping this table: data in this table (%s) should not be search/replaced', $table );
			return;
		}

		if ( 'ARCHIVE' == $engine ) {
			$this->tables_replaced[ $table ] = true;
			$backupplus->log_e( 'Skipping this table: this table (%s) should not be search/replaced, as it uses the %s engine', $table, $engine );
			return;
		}

		// Blogs table on multisite doesn't contain the full URL
		if ( is_multisite() && ( $table == $this->base_prefix . 'blogs' || $table == $this->base_prefix . 'site' ) && ( preg_match( '#^https?://([^/]+)#i', $this->home, $matches ) || preg_match( '#^https?://([^/]+)#i', $this->siteurl, $matches ) ) && ( preg_match( '#^https?://([^/]+)#i', $old_home, $omatches ) || preg_match( '#^https?://([^/]+)#i', $old_siteurl, $omatches ) ) ) {
			$from_array = strtolower( $omatches[1] );
			$to_array   = strtolower( $matches[1] );
			$backupplus->log_e( 'Replacing in blogs/site table: from: %s to: %s', htmlspecialchars( $from_array ), htmlspecialchars( $to_array ) );
			$try_site_blog_replace = true;
		} else {

			list($from_array, $to_array) = $this->build_searchreplace_array( $old_siteurl, $old_home, $old_content, $old_uploads, $old_abspath );

			// This block is for multisite installs, to do the search/replace of each site's URL individually. We want to try to do it here for efficiency - i.e. so that we don't have to double-pass tables
			if ( ! empty( $this->restored_blogs ) && preg_match( '/^(\d+)_(.*)$/', substr( $table, strlen( $import_table_prefix ) ), $tmatches ) && ( preg_match( '#^((https?://)([^/]+))#i', $this->home, $matches ) || preg_match( '#^((https?://)([^/]+))#i', $this->siteurl, $matches ) ) && ( preg_match( '#^((https?://)([^/]+))#i', $old_home, $omatches ) || preg_match( '#^((https?://)([^/]+))#i', $old_siteurl, $omatches ) ) ) {
				$old_home_domain = strtolower( $omatches[3] );
				$new_home_domain = strtolower( $matches[3] );
				$blog_id         = $tmatches[1];
				if ( $old_home_domain == $this->restored_blogs[1]['domain'] && isset( $this->restored_blogs[ $blog_id ] ) ) {
					$bdom  = $this->restored_blogs[ $blog_id ]['domain'];
					$bpath = $this->restored_blogs[ $blog_id ]['path'];
					$sblog = $omatches[2] . $bdom . untrailingslashit( $bpath );
					$rblog = $omatches[2] . str_replace( $old_home_domain, $new_home_domain, $bdom ) . untrailingslashit( $bpath );
					if ( ! in_array( $sblog, $from_array ) ) {
						$from_array[] = $sblog;
						$to_array[]   = $rblog;
					}
				}
			}
		}

		// The search/replace parameters are allowed to be either strings or arrays
		$report = $backupplus_restorer->search_replace_obj->icit_srdb_replacer( $from_array, $to_array, array( $table => $stripped_table ), 5000 );

		// If we just replaced either the blogs or site table, then populate our records of what is *now* (i.e. post-restore) in them
		if ( ! empty( $try_site_blog_replace ) ) {
			if ( $table == $this->base_prefix . 'blogs' ) {
				$blogs = BackupPlus_SQL::wpdb()->rows( 'SELECT blog_id, domain, path, site_id FROM %i', array( $table ), array(), ARRAY_N );
				if ( is_array( $blogs ) ) {
					$nblogs = array();
					foreach ( $blogs as $blog ) {
						$nblogs[ $blog[0] ] = array(
							'domain'  => $blog[1],
							'path'    => $blog[2],
							'site_id' => $blog[3],
						);
					}
					$this->restored_blogs = $nblogs;
				}
			} elseif ( $table == $this->base_prefix . 'site' ) {
				$sites = BackupPlus_SQL::wpdb()->rows( 'SELECT id, domain, path FROM %i ORDER BY id ASC', array( $table ), array(), ARRAY_N );
				if ( is_array( $sites ) ) {
					$nsites = array();
					foreach ( $sites as $site ) {
						$nsites[ $site[0] ] = array( $site[1], $site[2] );
					}
					$this->restored_sites = $nsites;
				}
			}
			if ( ! empty( $this->restored_sites ) && ! empty( $this->restored_blogs ) && ! empty( $this->original_sites ) ) {
				// Adjust paths
				// Domain, path
				$any_site_changes = false;
				foreach ( $this->original_sites as $oid => $osite ) {
					if ( empty( $this->restored_sites[ $oid ] ) ) {
						continue;
					}
					$rsite = $this->restored_sites[ $oid ];
					// Task: 1) Replace the site path with the previous site path 2) Replace all the blog path prefixes from the same blog
					if ( $rsite[1] != $osite[1] ) {
						$any_site_changes                = true;
						$sitepath                        = $osite[1];
						$this->restored_sites[ $oid ][1] = $sitepath;
						foreach ( $this->restored_blogs as $blog_id => $blog ) {
							// From this site?
							if ( $blog['site_id'] != $oid ) {
								continue;
							}
							// Replace the prefix according to the change in prefix for the site
							$this->restored_blogs[ $blog_id ] = array(
								'domain'  => $blog['domain'],
								'path'    => $sitepath . substr( $blog['path'], strlen( $rsite[1] ) ),
								'site_id' => $oid,
							);
						}
					}
				}
				if ( $any_site_changes ) {
					$backupplus->log_e( 'Adjusting multisite paths' );
					foreach ( $this->restored_sites as $site_id => $osite ) {
						BackupPlus_SQL::wpdb()->execute( 'UPDATE %i SET path = ? WHERE id = ?', array( $this->base_prefix . 'site' ), array( $osite[1], (int) $site_id ) );
					}
					foreach ( $this->restored_blogs as $blog_id => $blog ) {
						BackupPlus_SQL::wpdb()->execute( 'UPDATE %i SET path = ? WHERE blog_id = ?', array( $this->base_prefix . 'blogs' ), array( $blog['path'], (int) $blog_id ) );
					}
				}
			}
		}

		// Output any errors encountered during the db work.
		if ( ! empty( $report['errors'] ) && is_array( $report['errors'] ) ) {
			$backupplus->log( __( 'Error:', 'backupplus' ), 'warning-restore', 'restore-db-error' );
			$processed_errors = array();
			foreach ( $report['errors'] as $error ) {
				if ( in_array( $error, $processed_errors ) ) {
					continue;
				}
				$processed_errors[] = $error;
				$num                = count( array_keys( $report['errors'], $error ) );
				$err_string         = $error;
				if ( $num > 1 ) {
					$err_string .= ' (x' . $num . ')';
				}
				$backupplus->log( $err_string, 'warning-restore' );
			}
		}

		if ( false == $report ) {
			$backupplus->log(
				sprintf(
				/* translators: %s: Operation name */
					__( 'Failed: the %s operation was not able to start.', 'backupplus' ),
					__( 'search and replace', 'backupplus' )
				),
				'warning-restore'
			);
		} elseif ( ! is_array( $report ) ) {
			$backupplus->log(
				sprintf(
				/* translators: %s: Operation name */
					__( 'Failed: we did not understand the result returned by the %s operation.', 'backupplus' ),
					__( 'search and replace', 'backupplus' )
				),
				'warning-restore'
			);
		} else {

			$this->tables_replaced[ $table ] = true;

			// Calc the time taken.
			foreach ( array( 'tables', 'rows', 'change', 'updates' ) as $key ) {
				$this->report[ $key ] += $report[ $key ];
			}
			$this->report['timetaken'] += $report['end'] - $report['start'];
		}
	}
}
