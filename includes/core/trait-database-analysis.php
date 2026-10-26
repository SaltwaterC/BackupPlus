<?php

/** Scanning a database dump for the facts a restore needs, and collation fallbacks. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Core_Database_Analysis {

	/**
	 * Analyse a database file and return information about it
	 *
	 * @param Integer        $timestamp   - the database time in the backup history
	 * @param Array          $res         - accompanying data. The key 'backupplus_encryptionphrase' will be used for decryption if relevant.
	 * @param Boolean|String $db_file     - the path to the file to analyse; if not specified (false), then it will be obtained from the backup history
	 * @param Boolean        $header_only - whether or not to stop analysis once the header ends
	 *
	 * @return Array - containing arrays for the resulting messages, warnings, errors and meta information
	 */
	public function analyse_db_file( $timestamp, $res, $db_file = false, $header_only = false ) {

		$mess       = array();
		$warn       = array();
		$err        = array();
		$info       = array();
		$wp_version = $this->get_wordpress_version();
		global $wpdb;

		if ( ! class_exists( 'BackupPlus_Database_Utility' ) ) {
			backupplus_try_include_file( 'includes/class-database-utility.php', 'include_once' );
		}

		$backupplus_dir = $this->backups_dir_location();

		if ( false === $db_file ) {
			// This attempts to raise the maximum packet size. This can't be done within the session, only globally. Therefore, it has to be done before the session starts; in our case, during the pre-analysis.
			$this->max_packet_size();

			$backup = BackupPlus_Backup_History::get_history( $timestamp );
			if ( ! isset( $backup['nonce'] ) || ! isset( $backup['db'] ) ) {
				return array( $mess, $warn, $err, $info );
			}

			$db_file = is_string( $backup['db'] ) ? $backupplus_dir . '/' . $backup['db'] : $backupplus_dir . '/' . $backup['db'][0];
		}

		if ( ! is_readable( $db_file ) ) {
			return array( $mess, $warn, $err, $info );
		}

		// Encrypted - decrypt it
		if ( BackupPlus_Encryption::is_file_encrypted( $db_file ) ) {
			if ( ! empty( $res['backupplus_age_ticket'] ) || BackupPlus_Age_Restore::is_age( $db_file ) ) {
				$decrypted_file = BackupPlus_Age_Restore::file( $db_file, isset( $res['backupplus_age_ticket'] ) ? $res['backupplus_age_ticket'] : '', '', $timestamp );
			} else {
				$encryption = empty( $res['backupplus_encryptionphrase'] ) ? BackupPlus_Options::get_backupplus_option( 'backupplus_encryptionphrase' ) : $res['backupplus_encryptionphrase'];

				if ( ! $encryption ) {
					$err[] = sprintf(
						/* translators: %s: Error message */
						__( 'Error: %s', 'backupplus' ),
						__( 'Decryption failed.', 'backupplus' ) . ' ' . __( 'The database file is encrypted, but no encryption key is configured.', 'backupplus' )
					);
					return array( $mess, $warn, $err, $info );
				}

				$decrypted_file = BackupPlus_Encryption::decrypt( $db_file, $encryption );
			}

			if ( is_array( $decrypted_file ) ) {
				$db_file = $decrypted_file['fullpath'];
			} else {
				$err[] = __( 'Decryption failed.', 'backupplus' ) . ' ' . __( 'The most likely cause is that you used the wrong key.', 'backupplus' );
				return array( $mess, $warn, $err, $info );
			}
		}

		// Even the empty schema when gzipped comes to 1565 bytes; a blank WP 3.6 install at 5158. But we go low, in case someone wants to share single tables.
		if ( filesize( $db_file ) < 1000 && empty( $backup['database_coverage']['wp']['partial'] ) && ( empty( $backup['database_coverage']['wp']['mode'] ) || 'selected' !== $backup['database_coverage']['wp']['mode'] ) ) {
			/* translators: %s: Database size in KB */
			$err[] = sprintf( __( 'The database is too small to be a valid WordPress database (size: %s Kb).', 'backupplus' ), round( filesize( $db_file ) / 1024, 1 ) );
			return array( $mess, $warn, $err, $info );
		}

		// If the backup is not from BackupPlus or a compatible UpdraftPlus archive and it's not a simple SQL file then we don't want to scan
		if ( ! empty( $backup['meta_foreign'] ) && 'genericsql' != $backup['meta_foreign'] ) {
			$info['skipped_db_scan'] = 1;
			return array( $mess, $warn, $err, $info );
		}

		$is_plain = ( '.gz' == substr( isset( $decrypted_file['basename'] ) ? $decrypted_file['basename'] : $db_file, -3, 3 ) ) ? false : true;

		$dbhandle = $is_plain ? fopen( $db_file, 'r' ) : BackupPlus_Filesystem_Functions::gzopen_for_read( $db_file, $warn, $err );
		if ( ! is_resource( $dbhandle ) ) {
			$err[] = __( 'Failed to open database file.', 'backupplus' );
			return array( $mess, $warn, $err, $info );
		}

		$info['timestamp'] = $timestamp;

		// Analyse the file, print the results.

		$line               = 0;
		$old_siteurl        = '';
		$old_home           = '';
		$old_table_prefix   = null;
		$old_siteinfo       = array();
		$gathering_siteinfo = true;
		$old_wp_version     = '';
		$old_php_version    = '';

		$tables_found      = array();
		$db_charsets_found = array();

		$db_scan_timed_out           = false;
		$php_max_input_vars_exceeded = false;

		// TODO: If the backup is the right size/checksum, then we could restore the $line <= 100 in the 'while' condition and not bother scanning the whole thing? Or better: sort the core tables to be first so that this usually terminates early

		$wanted_tables = array( 'terms', 'term_taxonomy', 'term_relationships', 'commentmeta', 'comments', 'links', 'options', 'postmeta', 'posts', 'users', 'usermeta' );

		$migration_warning  = false;
		$processing_create  = false;
		$processing_routine = false;
		$db_version         = $wpdb->db_version();

		// Don't set too high - we want a timely response returned to the browser
		// Until April 2015, this was always 90. But we've seen a few people with ~1GB databases (uncompressed), and 90s is not enough. Note that we don't bother checking here if it's compressed - having a too-large timeout when unexpected is harmless, as it won't be hit. On very large dbs, they're expecting it to take a while.
		// "120 or 240" is a first attempt at something more useful than just fixed at 90 - but should be sufficient (as 90 was for everyone without ~1GB databases)
		$default_dbscan_timeout = ( filesize( $db_file ) < 31457280 ) ? 120 : 240;
		$dbscan_timeout         = ( defined( 'BACKUPPLUS_DBSCAN_TIMEOUT' ) && is_numeric( BACKUPPLUS_DBSCAN_TIMEOUT ) ) ? BACKUPPLUS_DBSCAN_TIMEOUT : $default_dbscan_timeout;
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $dbscan_timeout );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}
		// We limit the time that we spend scanning the file for character sets
		$db_charset_collate_scan_timeout                         = ( defined( 'BACKUPPLUS_DB_CHARSET_COLLATE_SCAN_TIMEOUT' ) && is_numeric( BACKUPPLUS_DB_CHARSET_COLLATE_SCAN_TIMEOUT ) ) ? BACKUPPLUS_DB_CHARSET_COLLATE_SCAN_TIMEOUT : 10;
		$charset_scan_start_time                                 = microtime( true );
		$db_supported_character_sets                             = (array) $GLOBALS['wpdb']->get_results( 'SHOW CHARACTER SET', OBJECT_K );
		$db_supported_collations                                 = (array) $GLOBALS['wpdb']->get_results( 'SHOW COLLATION', OBJECT_K );
		$db_charsets_found                                       = array();
		$db_collates_found                                       = array();
		$db_supported_charset_related_to_unsupported_collation   = false;
		$db_supported_charsets_related_to_unsupported_collations = array();
		while ( ( ( $is_plain && ! feof( $dbhandle ) ) || ( ! $is_plain && ! gzeof( $dbhandle ) ) ) && ( $line < 100 || ( ! $header_only && count( $wanted_tables ) > 0 ) || ( ( microtime( true ) - $charset_scan_start_time ) < $db_charset_collate_scan_timeout && ! empty( $db_supported_character_sets ) ) ) ) {
			++$line;
			// Up to 1MB
			$buffer = $is_plain ? rtrim( fgets( $dbhandle, 1048576 ) ) : rtrim( gzgets( $dbhandle, 1048576 ) );
			// Comments are what we are interested in
			if ( substr( $buffer, 0, 1 ) == '#' ) {
				$processing_create  = false;
				$processing_routine = false;
				if ( '' == $old_siteurl && preg_match( '/^\# Backup of: (http(.*))$/', $buffer, $matches ) ) {
					$old_siteurl = untrailingslashit( $matches[1] );
					$mess[]      = __( 'Backup of:', 'backupplus' ) . ' ' . htmlspecialchars( $old_siteurl ) .
								/* translators: %s: Old WP versions */
								( ( ! empty( $old_wp_version ) ) ? ' ' . sprintf( __( '(version: %s)', 'backupplus' ), $old_wp_version ) : '' );
					// Check for should-be migration
					if ( untrailingslashit( site_url() ) != $old_siteurl ) {
						if ( ! $migration_warning ) {
							$migration_warning = true;
							$info['migration'] = true;
							if ( BackupPlus_Manipulation_Functions::normalise_url( $old_siteurl ) == BackupPlus_Manipulation_Functions::normalise_url( site_url() ) ) {
								// Same site migration with only http/https difference
								$info['same_url']          = false;
								$info['url_scheme_change'] = true;
								$old_siteurl_parsed        = parse_url( $old_siteurl );
								$actual_siteurl_parsed     = parse_url( site_url() );
								if ( ( stripos( $old_siteurl_parsed['host'], 'www.' ) === 0 && stripos( $actual_siteurl_parsed['host'], 'www.' ) !== 0 ) || ( stripos( $old_siteurl_parsed['host'], 'www.' ) !== 0 && stripos( $actual_siteurl_parsed['host'], 'www.' ) === 0 ) ) {
									/* translators: 1: Old site URL, 2: Current site URL */
									$powarn = sprintf( __( 'The website address in the backup set (%1$s) is slightly different from that of the site now (%2$s).', 'backupplus' ), $old_siteurl, site_url() ) . ' ' .
											__( 'This is not expected to be a problem for restoring the site, as long as visits to the former address still reach the site.', 'backupplus' ) . ' ';
								} else {
									$powarn = '';
								}
								if ( ( 'https' == $old_siteurl_parsed['scheme'] && 'http' == $actual_siteurl_parsed['scheme'] ) || ( 'http' == $old_siteurl_parsed['scheme'] && 'https' == $actual_siteurl_parsed['scheme'] ) ) {
									/* translators: 1: Old scheme, 2: Current scheme */
									$powarn .= sprintf( __( 'This backup set is of this site, but at the time of the backup you were using %1$s, whereas the site now uses %2$s.', 'backupplus' ), $old_siteurl_parsed['scheme'], $actual_siteurl_parsed['scheme'] );
									if ( 'https' == $old_siteurl_parsed['scheme'] ) {
										$powarn .= ' ' . apply_filters( 'backupplus_https_to_http_additional_warning', '' );
									} else {
										$powarn .= ' ' . apply_filters( 'backupplus_http_to_https_additional_warning', '' );
									}
								} else {
									$powarn .= apply_filters( 'backupplus_dbscan_urlchange_www_append_warning', '' );
								}
								$warn[] = $powarn;
							} else {
								// For completely different site migration
								$info['same_url']          = false;
								$info['url_scheme_change'] = false;
								$warn[]                    = apply_filters(
									'backupplus_dbscan_urlchange',
									sprintf(
									/* translators: %s: Old and current site URLs */
										__( 'This backup set is from a different site (%s). After restoring, check whether any site URLs need to be updated.', 'backupplus' ),
										htmlspecialchars( $old_siteurl . ' / ' . untrailingslashit( site_url() ) )
									),
									$old_siteurl,
									$res
								);
							}
						}

						if ( $this->mod_rewrite_unavailable( false ) ) {
							/* translators: 1: Webserver type, 2: Missing module */
							$warn[] = sprintf( __( 'You are using the %1$s webserver, but do not seem to have the %2$s module loaded.', 'backupplus' ), 'Apache', 'mod_rewrite' ) . ' ' .
									/* translators: 1: Module name, 2: Sample url */
									sprintf( __( 'You should enable %1$s to make any pretty permalinks (e.g. %2$s) work', 'backupplus' ), 'mod_rewrite', 'http://example.com/my-page/' );
						}
					} else {
						// For exactly same URL site restoration
						$info['same_url']          = true;
						$info['url_scheme_change'] = false;
					}
				} elseif ( '' == $old_home && preg_match( '/^\# Home URL: (http(.*))$/', $buffer, $matches ) ) {
					$old_home = untrailingslashit( $matches[1] );
					// Check for should-be migration
					if ( ! $migration_warning && BackupPlus_Manipulation_Functions::normalise_url( home_url() ) != BackupPlus_Manipulation_Functions::normalise_url( $old_home ) ) {
						$migration_warning = true;
							$powarn        = apply_filters(
								'backupplus_dbscan_urlchange',
								sprintf(
								/* translators: %s: Old and current home URLs */
									__( 'This backup set is from a different site (%s). After restoring, check whether any site URLs need to be updated.', 'backupplus' ),
									htmlspecialchars( $old_home . ' / ' . home_url() )
								),
								$old_home,
								$res
							);
						if ( ! empty( $powarn ) ) {
							$warn[] = $powarn;
						}
					}
				} elseif ( ! isset( $info['created_by_version'] ) && preg_match( '/^\# Created by (?:BackupPlus|UpdraftPlus) version ([\d\.]+)/', $buffer, $matches ) ) {
					$info['created_by_version'] = trim( $matches[1] );
				} elseif ( '' == $old_wp_version && preg_match( '/^\# WordPress Version: ([0-9]+(\.[0-9]+)+)(-[-a-z0-9]+,)?(.*)$/', $buffer, $matches ) ) {
					$old_wp_version = $matches[1];
					if ( ! empty( $matches[3] ) ) {
						$old_wp_version .= substr( $matches[3], 0, strlen( $matches[3] ) - 1 );
					}
					if ( version_compare( $old_wp_version, $wp_version, '>' ) ) {
						// $mess[] = sprintf(__('%s version: %s', 'backupplus'), 'WordPress', $old_wp_version);
						$warn[] = sprintf(
							/* translators: 1: Old WP version, 2: Current WP version */
							__( 'You are importing from a newer version of WordPress (%1$s) into an older one (%2$s).', 'backupplus' ),
							$old_wp_version,
							$wp_version
						) . ' ' . __( 'There are no guarantees that WordPress can handle this.', 'backupplus' );
					}
					if ( preg_match( '/running on PHP ([0-9]+\.[0-9]+)(\s|\.)/', $matches[4], $nmatches ) && preg_match( '/^([0-9]+\.[0-9]+)(\s|\.)/', PHP_VERSION, $cmatches ) ) {
						$old_php_version     = $nmatches[1];
						$current_php_version = $cmatches[1];
						if ( version_compare( $old_php_version, $current_php_version, '>' ) ) {
							/* translators: 1: Old PHP version, 2: PHP */
							$warn[] = sprintf( __( 'The site in this backup was running on a webserver with version %1$s of %2$s.', 'backupplus' ), $old_php_version, 'PHP' ) . ' ' .
									/* translators: %s: Current PHP version */
									sprintf( __( 'This is significantly newer than the server which you are now restoring onto (version %s).', 'backupplus' ), PHP_VERSION ) . ' ' .
									/* translators: %s: PHP */
									sprintf( __( 'You should only proceed if you cannot update the current server and are confident (or willing to risk) that your plugins/themes/etc are compatible with the older %s version.', 'backupplus' ), 'PHP' ) . ' ' .
									/* translators: %s: PHP */
									sprintf( __( 'Any support requests to do with %s should be raised with your web hosting company.', 'backupplus' ), 'PHP' );
						} elseif ( version_compare( $old_php_version, $current_php_version, '<' ) ) {
							/* translators: 1: PHP version, 2: PHP */
							$warn[] = sprintf( __( 'The site in this backup was running on a webserver with version %1$s of %2$s.', 'backupplus' ), $old_php_version, 'PHP' ) . ' ' .
									/* translators: %s: Current PHP version */
									sprintf( __( 'This is older than the server which you are now restoring onto (version %s).', 'backupplus' ), PHP_VERSION ) . ' ' .
									/* translators: %s: PHP */
									sprintf( __( 'You should only proceed if you have checked and are confident (or willing to risk) that your plugins/themes/etc are compatible with the new %s version.', 'backupplus' ), 'PHP' ) . ' ' .
									/* translators: %s: PHP */
									sprintf( __( 'Any support requests to do with %s should be raised with your web hosting company.', 'backupplus' ), 'PHP' );
						}
					}
				} elseif ( null === $old_table_prefix && ( preg_match( '/^\# Table prefix: ?(\S*)$/', $buffer, $matches ) || preg_match( '/^-- Table prefix: ?(\S*)$/i', $buffer, $matches ) ) ) {
					$old_table_prefix = $matches[1];
					// echo '<strong>'.__('Old table prefix:', 'backupplus').'</strong> '.htmlspecialchars($old_table_prefix).'<br>';
				} elseif ( empty( $info['label'] ) && preg_match( '/^\# Label: (.*)$/', $buffer, $matches ) ) {
					$info['label'] = $matches[1];
					$mess[]        = __( 'Backup label:', 'backupplus' ) . ' ' . htmlspecialchars( $info['label'] );
				} elseif ( $gathering_siteinfo && preg_match( '/^\# Site info: (\S+)$/', $buffer, $matches ) ) {
					if ( 'end' == $matches[1] ) {
						$gathering_siteinfo = false;
						// Sanity checks
						if ( isset( $old_siteinfo['multisite'] ) && ! $old_siteinfo['multisite'] && is_multisite() ) {
							$err[] = __( 'Restoring a single-site backup into a WordPress multisite network is not supported.', 'backupplus' );
							return array( $mess, $warn, $err, $info );
						} elseif ( isset( $old_siteinfo['multisite'] ) && $old_siteinfo['multisite'] && ! is_multisite() ) {
							$warn[] = __( 'Warning:', 'backupplus' ) . ' ' . __( 'Your backup is of a WordPress multisite install; but this site is not.', 'backupplus' ) . ' ' . __( 'Only the first site of the network will be accessible.', 'backupplus' ) . ' <a href="https://codex.wordpress.org/Create_A_Network" target="_blank">' . __( 'If you want to restore a multisite backup, you should first set up your WordPress installation as a multisite.', 'backupplus' ) . '</a>';
						}
					} elseif ( preg_match( '/^([^=]+)=(.*)$/', $matches[1], $kvmatches ) ) {
						$key = $kvmatches[1];
						$val = $kvmatches[2];
						if ( 'multisite' == $key ) {
							$info['multisite'] = $val ? true : false;
							if ( $val ) {
								$mess[] = '<strong>' . __( 'Site information:', 'backupplus' ) . '</strong> ' . 'backup is of a WordPress Network';
							}
						}
						$old_siteinfo[ $key ] = $val;
					}
				} elseif ( 0 === strpos( $buffer, '# BackupPlus database coverage: ' ) ) {
					$coverage = json_decode( substr( $buffer, strlen( '# BackupPlus database coverage: ' ) ), true );
					if ( is_array( $coverage ) ) {
						$info['database_coverage'] = $coverage;
						if ( ! empty( $coverage['partial'] ) || 'selected' === $coverage['mode'] ) { $mess[] = esc_html__( 'This is an intentionally partial database backup. Only tables present in the dump will be restored.', 'backupplus' ); }
					}
				} elseif ( preg_match( '/^\# Skipped tables: (.*)$/', $buffer, $matches ) ) {
					$skipped_tables = array_map( 'trim', explode( ',', $matches[1] ) );
				}
			} elseif ( preg_match( '#^\s*/\*\!40\d+ SET NAMES (.*)\*\/#i', $buffer, $smatches ) ) {
				$db_charsets_found[] = rtrim( $smatches[1] );
			} elseif ( ! $processing_routine && ! $processing_create && preg_match( "/^[^'\"]*create[^'\"]*(?:definer\s*=\s*(?:`.{1,17}`@`[^\s]+`|'.{1,17}'@'[^\s]+'))?.+?(?:function(?:\s\s*if\s\s*not\s\s*exists)?|procedure)\s*`([^\r\n]+)`/is", $buffer, $matches ) ) {
				// ^\s*create\s\s*(?:or\s\s*replace\s\s*)?.*?(?:aggregate\s\s*function|function|procedure)\s\s*`(.+)`(?:\s\s*if\s\s*not\s\s*exists\s*|\s*)?\(
				if ( ! preg_match( '/END\s*(?:\*\/)?;;\s*$/is', $buffer ) && ! preg_match( '/\;\s*;;\s*$/is', $buffer ) && ! preg_match( '/\s*(?:\*\/)?;;\s*$/is', $buffer ) ) {
					$processing_routine = true;
				}
			} elseif ( ! $processing_routine && preg_match( '/^\s*create table \`?([^\`\(]*)\`?\s*\(/i', $buffer, $matches ) ) {
				$table          = $matches[1];
				$tables_found[] = $table;
				if ( null !== $old_table_prefix ) {
					// Remove prefix
					$table = $old_table_prefix ? BackupPlus_Manipulation_Functions::str_replace_once( $old_table_prefix, '', $table ) : $table;
					if ( in_array( $table, $wanted_tables ) ) {
						$wanted_tables = array_diff( $wanted_tables, array( $table ) );
					}
				}
				if ( empty( $old_siteurl ) && ! empty( $backup['meta_foreign'] ) ) {
					$info['migration'] = true;
				}
				if ( ';' != substr( $buffer, -1, 1 ) ) {
					$processing_create                                     = true;
					$db_supported_charset_related_to_unsupported_collation = true;
				}
			} elseif ( $processing_create ) {
				if ( ! empty( $db_supported_collations ) ) {
					if ( preg_match( '/ COLLATE=([^\s;]+)/i', $buffer, $collate_match ) ) {
						$db_collates_found[] = $collate_match[1];
						if ( ! isset( $db_supported_collations[ $collate_match[1] ] ) ) {
							$db_supported_charset_related_to_unsupported_collation = true;
						}
					}
					if ( preg_match( '/ COLLATE ([a-zA-Z0-9._-]+),/i', $buffer, $collate_match ) ) {
						$db_collates_found[] = $collate_match[1];
						if ( ! isset( $db_supported_collations[ $collate_match[1] ] ) ) {
							$db_supported_charset_related_to_unsupported_collation = true;
						}
					}
					if ( preg_match( '/ COLLATE ([a-zA-Z0-9._-]+) /i', $buffer, $collate_match ) ) {
						$db_collates_found[] = $collate_match[1];
						if ( ! isset( $db_supported_collations[ $collate_match[1] ] ) ) {
							$db_supported_charset_related_to_unsupported_collation = true;
						}
					}
				}
				if ( ! empty( $db_supported_character_sets ) ) {
					if ( preg_match( '/\b(?:CHARSET|CHARACTER SET)\b\s*=?\s*([^\s;,]+)/i', $buffer, $charset_match ) ) {
						$db_charsets_found[] = $charset_match[1];
						if ( $db_supported_charset_related_to_unsupported_collation && ! in_array( $charset_match[1], $db_supported_charsets_related_to_unsupported_collations ) ) {
							$db_supported_charsets_related_to_unsupported_collations[] = $charset_match[1];
						}
					}
				}
				if ( ';' == substr( $buffer, -1, 1 ) ) {
					$processing_create                                     = false;
					$db_supported_charset_related_to_unsupported_collation = false;
				}
				static $mysql_version_warned = false;
				if ( ! $mysql_version_warned && version_compare( $db_version, '5.2.0', '<' ) && preg_match( '/(CHARSET|COLLATE)[= ]utf8mb4/', $buffer ) ) {
					$mysql_version_warned = true;
					$err[]                = sprintf(
						/* translators: %s: Error message */
						__( 'Error: %s', 'backupplus' ),
						/* translators: %s: MySQL version */
						sprintf( __( 'The database backup uses MySQL features not available in the old MySQL version (%s) that this site is running on.', 'backupplus' ), $db_version ) . ' ' .
						__( 'You must upgrade MySQL to be able to use this database.', 'backupplus' )
					);
				}
			} elseif ( $processing_routine ) {
				if ( ( preg_match( '/END\s*(?:\*\/)?;;\s*$/is', $buffer ) || preg_match( '/\;\s*;;\s*$/is', $buffer ) || preg_match( '/\s*(?:\*\/)?;;\s*$/is', $buffer ) ) && ! preg_match( '/(?:--|#).+?;;\s*$/i', $buffer ) ) {
					$processing_routine = false;
				}
			}
		}
		if ( $is_plain ) {
			if ( ! feof( $dbhandle ) ) {
				$db_scan_timed_out = true;
			}
			@fclose( $dbhandle );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		} else {
			if ( ! gzeof( $dbhandle ) ) {
				$db_scan_timed_out = true;
			}
			@gzclose( $dbhandle );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		}
		if ( ! empty( $db_supported_character_sets ) ) {
			$db_charsets_found_unique = array_unique( $db_charsets_found );
			$db_unsupported_charset   = array();
			$db_charset_forbidden     = false;
			foreach ( $db_charsets_found_unique as $db_charset ) {
				if ( ! isset( $db_supported_character_sets[ $db_charset ] ) ) {
					$db_unsupported_charset[] = $db_charset;
					$db_charset_forbidden     = true;
				}
			}
			if ( $db_charset_forbidden ) {
				$db_unsupported_charset_unique = array_unique( $db_unsupported_charset );
				$warn[]                        = sprintf(
					/* translators: %s: Character sets */
					_n( "The database server that this WordPress site is running on doesn't support the character set (%s) which you are trying to import.", "The database server that this WordPress site is running on doesn't support the character sets (%s) which you are trying to import.", count( $db_unsupported_charset_unique ), 'backupplus' ),
					implode( ', ', $db_unsupported_charset_unique )
				) . ' ' .
				__( 'Choose a character set supported by the target database before continuing; changing it may alter how imported text is stored.', 'backupplus' );
				$db_supported_character_sets = array_keys( $db_supported_character_sets );
				$similar_type_charset        = BackupPlus_Manipulation_Functions::get_matching_str_from_array_elems( $db_unsupported_charset_unique, $db_supported_character_sets, true );
				if ( empty( $similar_type_charset ) ) {
					$row                  = $GLOBALS['wpdb']->get_row( 'show variables like "character_set_database"' );
					$similar_type_charset = ( null !== $row ) ? $row->Value : '';
				}
				if ( empty( $similar_type_charset ) && ! empty( $db_supported_character_sets[0] ) ) {
					$similar_type_charset = $db_supported_character_sets[0];
				}
				$charset_select_html  = '<label>' . __( 'Your chosen character set to use instead:', 'backupplus' ) . '</label> ';
				$charset_select_html .= '<select name="backupplus_restorer_charset" id="backupplus_restorer_charset">';
				if ( is_array( $db_supported_character_sets ) ) {
					foreach ( $db_supported_character_sets as $character_set ) {
						if ( $character_set == $similar_type_charset ) {
							$info['supported_charset'] = $character_set;
						}
						$charset_select_html .= '<option value="' . esc_attr( $character_set ) . '" ' . selected( $character_set, $similar_type_charset, false ) . '>' . esc_html( $character_set ) . '</option>';
					}
				}
				$charset_select_html .= '</select>';
				if ( empty( $info['addui'] ) ) {
					$info['addui'] = '';
				}
				$info['addui'] .= $charset_select_html;
			}
		}
		if ( ! empty( $db_supported_collations ) ) {
			$db_collates_found_unique = array_unique( $db_collates_found );
			$db_unsupported_collate   = array();
			$db_collate_forbidden     = false;
			foreach ( $db_collates_found_unique as $db_collate ) {
				if ( ! isset( $db_supported_collations[ $db_collate ] ) ) {
					$db_unsupported_collate[] = $db_collate;
					$db_collate_forbidden     = true;
				}
			}
			if ( $db_collate_forbidden ) {
				$db_unsupported_collate_unique = array_unique( $db_unsupported_collate );
				$warn[]                        = sprintf(
					/* translators: %s: Collations */
					_n( "The database server that this WordPress site is running on doesn't support the collation (%s) used in the database which you are trying to import.", "The database server that this WordPress site is running on doesn't support multiple collations (%s) used in the database which you are trying to import.", count( $db_unsupported_collate_unique ), 'backupplus' ),
					implode( ', ', $db_unsupported_collate_unique )
				) . ' ' .
				__( 'You can choose another suitable collation instead and continue with the restoration (at your own risk).', 'backupplus' );
				$similar_type_collate = '';
				if ( $db_charset_forbidden && ! empty( $similar_type_charset ) ) {
					$similar_type_collate = $this->get_similar_collate_related_to_charset( $db_supported_collations, $db_unsupported_collate_unique, $similar_type_charset );
				}
				if ( empty( $similar_type_collate ) && ! empty( $db_supported_charsets_related_to_unsupported_collations ) ) {
					$db_supported_collations_related_to_charset = array();
					foreach ( $db_supported_collations as $db_supported_collation => $db_supported_collations_info_obj ) {
						if ( isset( $db_supported_collations_info_obj->Charset ) && in_array( $db_supported_collations_info_obj->Charset, $db_supported_charsets_related_to_unsupported_collations ) ) {
							$db_supported_collations_related_to_charset[] = $db_supported_collation;
						}
					}
					if ( ! empty( $db_supported_collations_related_to_charset ) ) {
						$similar_type_collate = BackupPlus_Manipulation_Functions::get_matching_str_from_array_elems( $db_unsupported_collate_unique, $db_supported_collations_related_to_charset, false );
					}
				}
				if ( empty( $similar_type_collate ) ) {
					$similar_type_collate = $this->get_similar_collate_based_on_ocuurence_count( $db_collates_found, $db_supported_collations, $db_supported_charsets_related_to_unsupported_collations );
				}
				if ( empty( $similar_type_collate ) ) {
					$similar_type_collate = BackupPlus_Manipulation_Functions::get_matching_str_from_array_elems( $db_unsupported_collate_unique, array_keys( $db_supported_collations ), false );
				}

				$collate_select_html      = '<div class="udp-notice below-h2 backupplus-restore-option"><label>' . __( 'Your chosen replacement collation', 'backupplus' ) . ':</label>';
				$collate_select_html     .= '<select name="backupplus_restorer_collate" id="backupplus_restorer_collate">';
				$db_charsets_found_unique = array_unique( $db_charsets_found );
				foreach ( $db_supported_collations as $collate => $collate_info_obj ) {
					$option_other_attr = array();
					if ( $db_charset_forbidden && isset( $collate_info_obj->Charset ) ) {
						$option_other_attr[] = 'data-charset=' . esc_attr( $collate_info_obj->Charset );
						if ( $similar_type_charset != $collate_info_obj->Charset ) {
							$option_other_attr[] = 'style="display:none;"';
						}
					} else {
						if ( 1 == count( $db_charsets_found_unique ) ) {
							if ( ! in_array( $collate_info_obj->Charset, $db_charsets_found_unique ) ) {
								$option_other_attr[] = 'style="display:none;"';
							}
						} else {
							$option_other_attr[] = 'style="display:none;"';
						}
					}
					$collate_select_html .= '<option value="' . esc_attr( $collate ) . '" ' . selected( $collate, $similar_type_collate, false ) . ' ' . implode( ' ', $option_other_attr ) . '>' . esc_html( $collate ) . '</option>';
				}

				if ( count( $db_charsets_found_unique ) > 1 && ! $db_charset_forbidden ) {
					$collate_select_html .= '<option value="choose_a_default_for_each_table" selected="selected">' . __( 'Choose a default for each table', 'backupplus' ) . '</option>';
				}
				$collate_select_html .= '</select>';
				$collate_select_html .= '</div>';

				$info['addui'] = empty( $info['addui'] ) ? $collate_select_html : $info['addui'] . '<br>' . $collate_select_html;

				if ( $db_charset_forbidden ) {
					$collate_change_on_charset_selection_data = array(
						'db_supported_collations'       => $db_supported_collations,
						'db_unsupported_collate_unique' => $db_unsupported_collate_unique,
						'db_collates_found'             => $db_collates_found,
					);
					$info['addui']                           .= '<input type="hidden" name="collate_change_on_charset_selection_data" id="collate_change_on_charset_selection_data" value="' . esc_attr( json_encode( $collate_change_on_charset_selection_data ) ) . '">';
				}
			}
		}
		/*        $blog_tables = "CREATE TABLE $wpdb->terms (
		CREATE TABLE $wpdb->term_taxonomy (
		CREATE TABLE $wpdb->term_relationships (
		CREATE TABLE $wpdb->commentmeta (
		CREATE TABLE $wpdb->comments (
		CREATE TABLE $wpdb->links (
		CREATE TABLE $wpdb->options (
		CREATE TABLE $wpdb->postmeta (
		CREATE TABLE $wpdb->posts (
				$users_single_table = "CREATE TABLE $wpdb->users (
				$users_multi_table = "CREATE TABLE $wpdb->users (
				$usermeta_table = "CREATE TABLE $wpdb->usermeta (
				$ms_global_tables = "CREATE TABLE $wpdb->blogs (
		CREATE TABLE $wpdb->blog_versions (
		CREATE TABLE $wpdb->registration_log (
		CREATE TABLE $wpdb->site (
		CREATE TABLE $wpdb->sitemeta (
		CREATE TABLE $wpdb->signups (
		*/
		if ( ! isset( $skipped_tables ) ) {
			$skipped_tables = array();
		}
		$missing_tables = array();

		if ( null !== $old_table_prefix ) {

			if ( '' === $old_table_prefix ) {
				$warn[] = __( 'This backup is of a site with an empty table prefix, which WordPress does not officially support; the results may be unreliable.', 'backupplus' );
			}

			if ( ! $header_only ) {
				foreach ( $wanted_tables as $table ) {
					if ( ! in_array( $old_table_prefix . $table, $tables_found ) ) {
						$missing_tables[] = $table;
					}
				}

				foreach ( $missing_tables as $key => $value ) {
					if ( in_array( $old_table_prefix . $value, $skipped_tables ) ) {
						$key_to_unset_skipped_tables = array_search( $old_table_prefix . $value, $skipped_tables );
						unset( $skipped_tables[ $key_to_unset_skipped_tables ] );
					}
				}

				if ( count( $missing_tables ) > 0 && empty( $info['database_coverage']['partial'] ) && ( empty( $info['database_coverage']['mode'] ) || 'selected' !== $info['database_coverage']['mode'] ) ) {
					/* translators: %s: Missing WordPress tables */
					$warn[] = sprintf( __( 'This database backup is missing core WordPress tables: %s', 'backupplus' ), implode( ', ', $missing_tables ) );
				}
				if ( count( $skipped_tables ) > 0 ) {
					/* translators: %s: Excluded database tables */
					$warn[] = sprintf( __( 'This database backup has the following non-core WordPress database tables excluded: %s', 'backupplus' ), implode( ', ', $skipped_tables ) );
				}
			}
		} else {
			if ( empty( $backup['meta_foreign'] ) ) {
				$warn[] = __( 'BackupPlus was unable to find the table prefix when scanning the database backup.', 'backupplus' );
			}
		}

		$php_max_input_vars = ini_get( 'max_input_vars' ); // phpcs:ignore PHPCompatibility.IniDirectives.NewIniDirectives.max_input_varsFound -- does not exist in PHP 5.2

		if ( false == $php_max_input_vars ) {
			$php_max_input_vars_exceeded = true;
		} elseif ( count( $tables_found ) >= 0.90 * $php_max_input_vars ) {
			$php_max_input_vars_exceeded = true;
			// If the amount of tables exceed 90% of the php max input vars then truncate the list to 50% of the php max input vars value
			$tables_found = array_splice( $tables_found, 0, intval( $php_max_input_vars / 2 ) );
		}

		$php_max_input_vars_value   = false == $php_max_input_vars ? 0 : $php_max_input_vars;
		$info['php_max_input_vars'] = $php_max_input_vars_value;

		// On UD 1.16.30 - 1.16.34 there was a serious bug that did not backup all content in composite key tables, if this is not a migration and the backup was created on one of these versions do not restore this table.
		$skip_composite_tables = ( ! empty( $info['created_by_version'] ) && version_compare( '1' . substr( $info['created_by_version'], 1 ), '1.16.30', '>=' ) && version_compare( '1' . substr( $info['created_by_version'], 1 ), '1.16.34', '<=' ) ) ? true : false;

		// Check if this is a single site to multisite restore
		$skip_user_tables = ( isset( $old_siteinfo['multisite'] ) && ! $old_siteinfo['multisite'] && is_multisite() ) ? true : false;

		if ( $skip_composite_tables ) {
			if ( ! empty( $info['migration'] ) ) {
				$skip_composite_tables = false;
				$warn[]                = sprintf(
					/* translators: %s: BackupPlus or UpdraftPlus version */
					__( 'This backup was created on a previous BackupPlus or UpdraftPlus version (%s) which did not correctly backup tables with composite primary keys (such as the term_relationships table, which records tags and product attributes).', 'backupplus' ) . ' ' .
					__( 'Therefore it is advised that you take a fresh backup on the source site, using a later version.', 'backupplus' ),
					$info['created_by_version']
				);
			} else {
				$warn[] = sprintf(
					/* translators: %s: BackupPlus or UpdraftPlus version */
					__( 'This backup was created on a previous BackupPlus or UpdraftPlus version (%s) which did not correctly backup tables with composite primary keys (such as the term_relationships table, which records tags and product attributes).', 'backupplus' ) . ' ' .
					__( 'Therefore, affected tables on the current site which already exist will not be replaced by default, to avoid corrupting them (you can review this in the list of tables below).', 'backupplus' ),
					$info['created_by_version']
				);
			}
		}

		if ( $skip_user_tables ) {
			$warn[] = __( 'You are restoring a single site backup into a multisite installation.', 'backupplus' ) . ' ' . __( 'The users and usermeta tables will be excluded from the restore to prevent conflicts with the existing multisite user structure.', 'backupplus' );
		}

		if ( empty( $tables_found ) ) {
			$warn[] = __( 'BackupPlus was unable to find any tables when scanning the database backup; it maybe corrupt.', 'backupplus' );
		} else {
			$select_restore_tables  = '<div class="udp-notice below-h2 backupplus-restore-option">';
			$select_restore_tables .= '<p>' . __( 'If you do not want to restore all your database tables, then choose some to exclude here.', 'backupplus' ) . '(<a href="#" id="backupplus_restore_tables_showmoreoptions">...</a>)</p>';

			$select_restore_tables .= '<div class="backupplus_restore_tables_options_container" style="display:none;">';

			$select_table_button    = '<p><a href="#" class="backupplus-select-all-tables">' . __( 'Select All', 'backupplus' ) . '</a> | <a href="#" class="backupplus-deselect-all-tables">' . __( 'Deselect All', 'backupplus' ) . '</a></p>';
			$select_restore_tables .= $select_table_button;

			if ( $db_scan_timed_out || $php_max_input_vars_exceeded ) {
				if ( $db_scan_timed_out ) {
					$all_other_table_title = __( 'The database scan was taking too long and consequently the list of all tables in the database could not be completed.', 'backupplus' ) . ' ' . __( 'This option will ensure all tables not found will be backed up.', 'backupplus' );
				}
				if ( $php_max_input_vars_exceeded ) {
					$all_other_table_title = __( 'The amount of database tables scanned is near or over the php_max_input_vars value so some tables maybe truncated.', 'backupplus' ) . ' ' . __( 'This option will ensure all tables not found will be backed up.', 'backupplus' );
				}
				$select_restore_tables .= '<div style="margin-bottom:0.7rem;margin-top:0.7rem;"><input class="backupplus_restore_tables_options" id="backupplus_restore_table_udp_all_other_tables" checked="checked" type="checkbox" name="backupplus_restore_tables_options[]" value="udp_all_other_tables"> ';
				$select_restore_tables .= '<label for="backupplus_restore_table_udp_all_other_tables" title="' . $all_other_table_title . '">' . __( 'Include all tables not listed below', 'backupplus' ) . '</label></div>';
			}

			foreach ( $tables_found as $table ) {
				// Check if table should be skipped (composite key tables or user tables in single-to-multisite restore)
				$is_user_table = ( $skip_user_tables && isset( $old_table_prefix ) && '' !== $old_table_prefix && ( ( $table === $old_table_prefix . 'users' ) || ( $table === $old_table_prefix . 'usermeta' ) ) );
				$should_skip   = ( $skip_composite_tables && BackupPlus_Database_Utility::table_has_composite_private_key( $table ) ) || $is_user_table;

				$checked  = $should_skip ? '' : 'checked="checked"';
				$disabled = $is_user_table ? ' disabled="disabled"' : '';
				$style    = $is_user_table ? ' style="opacity: 0.5;"' : '';

				$select_restore_tables .= '<input class="backupplus_restore_tables_options" id="backupplus_restore_table_' . esc_attr( $table ) . '" ' . $checked . $disabled . ' type="checkbox" name="backupplus_restore_tables_options[]" value="' . esc_attr( $table ) . '"> ';
				$select_restore_tables .= '<label for="backupplus_restore_table_' . esc_attr( $table ) . '"' . $style . '>' . esc_html( $table ) . '</label><br>';
			}

			$select_restore_tables .= $select_table_button;

			$select_restore_tables .= '</div></div>';

			$info['addui'] = empty( $info['addui'] ) ? $select_restore_tables : $info['addui'] . '<br>' . $select_restore_tables;
		}

		// //need to make sure that we reset the file back to .crypt before clean temp files
		// $db_file = $decrypted_file['fullpath'].'.crypt';
		// unlink($decrypted_file['fullpath']);

		return array( $mess, $warn, $err, $info );
	}

	/**
	 * Get default substitute similar collate related to charset
	 *
	 * @param array  $db_supported_collations       Supported collations. It should contain result of 'SHOW COLLATION' query
	 * @param array  $db_unsupported_collate_unique Unsupported unique collates collection
	 * @param String $similar_type_charset          Charset for which need to get default collate substitution
	 * @return string $similar_type_collate default substitute collate which is best suitable or blank string
	 */
	public function get_similar_collate_related_to_charset( $db_supported_collations, $db_unsupported_collate_unique, $similar_type_charset ) {
		$similar_type_collate                       = '';
		$db_supported_collations_related_to_charset = array();
		foreach ( $db_supported_collations as $db_supported_collation => $db_supported_collations_info_obj ) {
			if ( isset( $db_supported_collations_info_obj->Charset ) && $db_supported_collations_info_obj->Charset == $similar_type_charset ) {
				$db_supported_collations_related_to_charset[] = $db_supported_collation;
			}
		}
		if ( ! empty( $db_supported_collations_related_to_charset ) ) {
			$similar_type_collate = BackupPlus_Manipulation_Functions::get_matching_str_from_array_elems( $db_unsupported_collate_unique, $db_supported_collations_related_to_charset, false );
		}
		return $similar_type_collate;
	}

	/**
	 * Get default substitute similar collate based on existing supported collates count in database backup file
	 *
	 * @param array $db_collates_found                                       All collates which have found in database backup file regardless whether they are supported or unsupported
	 * @param array $db_supported_collations                                 Supported collations. It should contain result of 'SHOW COLLATION' query
	 * @param array $db_supported_charsets_related_to_unsupported_collations All charset which are related to unsupported collation
	 *
	 * @return string $similar_type_collate default substitute collate which is best suitable or blank string
	 */
	public function get_similar_collate_based_on_ocuurence_count( $db_collates_found, $db_supported_collations, $db_supported_charsets_related_to_unsupported_collations ) {
		$similar_type_collate                        = '';
		$db_supported_collates_found_with_occurrence = array();
		foreach ( $db_collates_found as $db_collate_found ) {
			if ( isset( $db_supported_collations[ $db_collate_found ] ) ) {
				if ( isset( $db_supported_collates_found_with_occurrence[ $db_collate_found ] ) ) {
					$db_supported_collates_found_with_occurrence[ $db_collate_found ] = (int) $db_supported_collates_found_with_occurrence[ $db_collate_found ] + 1;
				} else {
					$db_supported_collates_found_with_occurrence[ $db_collate_found ] = 1;
				}
			}
		}
		if ( ! empty( $db_supported_collates_found_with_occurrence ) ) {
			arsort( $db_supported_collates_found_with_occurrence );
			if ( ! empty( $db_supported_charsets_related_to_unsupported_collations ) ) {
				foreach ( $db_supported_collates_found_with_occurrence as $db_supported_collate_with_occurrence => $occurrence_count ) {
					if ( isset( $db_supported_collations[ $db_supported_collate_with_occurrence ] ) && isset( $db_supported_collations[ $db_supported_collate_with_occurrence ]->Charset ) && in_array( $db_supported_collations[ $db_supported_collate_with_occurrence ]->Charset, $db_supported_charsets_related_to_unsupported_collations ) ) {
						$similar_type_collate = $db_supported_collate_with_occurrence;
						break;
					}
				}
			} else {
				$similar_type_collate = array_search( max( $db_supported_collates_found_with_occurrence ), $db_supported_collates_found_with_occurrence );
			}
		}
		return $similar_type_collate;
	}
}
