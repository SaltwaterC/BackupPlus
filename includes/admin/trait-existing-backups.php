<?php

/** The existing backups table: rows, buttons and recovery points. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Admin_Existing_Backups {

	/**
	 * Get backup information in HTML format for a specific backup
	 *
	 * @param Array      $backup_history all backups history
	 * @param String     $key            backup timestamp
	 * @param String     $nonce          backup nonce (job ID)
	 * @param Array|Null $job_data       if an array, then use this as the job data (if null, then it will be fetched directly)
	 *
	 * @return string HTML-formatted backup information
	 */
	public function raw_backup_info( $backup_history, $key, $nonce, $job_data = null ) {

		global $backupplus;

		$backup = $backup_history[ $key ];

		$pretty_date = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $key ), 'M d, Y G:i' );

		$rawbackup = "<h2 title=\"$key\">$pretty_date</h2>";

		if ( ! empty( $backup['label'] ) ) {
			$rawbackup .= '<span class="raw-backup-info">' . $backup['label'] . '</span>';
		}

		if ( null === $job_data ) {
			$job_data = empty( $nonce ) ? array() : $backupplus->jobdata_getarray( $nonce );
		}

		$rawbackup .= '<hr>';
		$rawbackup .= '<input type="checkbox" name="always_keep_this_backup" id="always_keep_this_backup" data-backup_key="' . $key . '" ' . ( empty( $backup['always_keep'] ) ? '' : 'checked ' ) . '><label for="always_keep_this_backup">' . __( 'Only allow this backup to be deleted manually (i.e. keep it even if retention limits are hit).', 'backupplus' ) . '</label>';

		foreach ( isset( $backup['database_coverage'] ) ? $backup['database_coverage'] : array() as $database_id => $coverage ) {
			if ( ! empty( $coverage['partial'] ) || 'selected' === $coverage['mode'] ) { $rawbackup .= '<p>' . esc_html( 'Partial database backup (' . $database_id . '): ' . implode( ', ', $coverage['tables'] ) ) . '</p>'; }
		}
		$rawbackup .= '<hr><p>';

		$backupable_entities = BackupPlus_Backup_Sources::restore_entities( $backupplus->get_backupable_file_entities( true, true ), $backup );

		$checksums = $backupplus->which_checksums();

		foreach ( $backupable_entities as $type => $info ) {
			if ( ! isset( $backup[ $type ] ) ) {
				continue;
			}

			$rawbackup .= $backupplus->printfile( $info['description'], $backup, $type, $checksums, $job_data, true );
		}

		$total_size = 0;
		foreach ( $backup as $ekey => $files ) {
			if ( 'db' == strtolower( substr( $ekey, 0, 2 ) ) && '-size' != substr( $ekey, -5, 5 ) ) {
				$rawbackup .= $backupplus->printfile( __( 'Database', 'backupplus' ), $backup, $ekey, $checksums, $job_data, true );
			}
			if ( ! isset( $backupable_entities[ $ekey ] ) && ( 'db' != substr( $ekey, 0, 2 ) || '-size' == substr( $ekey, -5, 5 ) ) ) {
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

		$services = empty( $backup['service'] ) ? array( 'none' ) : $backup['service'];
		if ( ! is_array( $services ) ) {
			$services = array( 'none' );
		}

		$rawbackup .= '<strong>' . __( 'Uploaded to:', 'backupplus' ) . '</strong> ';

		$show_services = '';
		foreach ( $services as $serv ) {
			if ( 'none' == $serv || '' == $serv ) {
				$add_none = true;
			} elseif ( isset( $backupplus->backup_methods[ $serv ] ) ) {
				$show_services .= $show_services ? ', ' . $backupplus->backup_methods[ $serv ] : $backupplus->backup_methods[ $serv ];
			} else {
				$show_services .= $show_services ? ', ' . $serv : $serv;
			}
		}
		if ( '' == $show_services && $add_none ) {
			$show_services .= __( 'None', 'backupplus' );
		}

		$rawbackup .= $show_services;

		if ( false !== $total_size ) {
			$rawbackup .= '</p><strong>' . __( 'Total backup size:', 'backupplus' ) . '</strong> ' . BackupPlus_Manipulation_Functions::convert_numeric_size_to_text( $total_size ) . '<p>';
		}

		$rawbackup .= '</p><hr><p><pre>' . print_r( $backup, true ) . '</pre></p>';

		if ( ! empty( $job_data ) && is_array( $job_data ) ) {
			$rawbackup .= '<p><pre>' . htmlspecialchars( print_r( $job_data, true ) ) . '</pre></p>';
		}

		return esc_attr( $rawbackup );
	}

	/**
	 * Display button to download the DB backup in the existing backups table.
	 *
	 * @param string $bkey            Backup type.
	 * @param string $key             Backup timestamp (epoch time).
	 * @param string $esc_pretty_date Escaped pretty date.
	 * @param array  $backup          Backup Instance.
	 * @param array  $accept          Accepted archive names.
	 * @return void - Display download button for DB backup.
	 */
	private function download_db_button( $bkey, $key, $esc_pretty_date, $backup, $accept = array() ) {

		if ( ! empty( $backup['meta_foreign'] ) && isset( $accept[ $backup['meta_foreign'] ] ) ) {
			$desc_source = $accept[ $backup['meta_foreign'] ]['desc'];
		} else {
			$desc_source = __( 'unknown source', 'backupplus' );
		}

		if ( 'db' == $bkey ) {
			if ( empty( $backup['meta_foreign'] ) ) {
				$dbt = esc_attr( __( 'Database', 'backupplus' ) );
			} else {
				/* translators: %s: Database Source. */
				$dbt = esc_attr( sprintf( __( 'Database (created by %s)', 'backupplus' ), $desc_source ) );
			}
		} else {
			$dbt = __( 'External database', 'backupplus' ) . ' (' . substr( $bkey, 2 ) . ')';
		}

		$files = $this->download_files( $backup, $bkey );
		$this->download_button( $bkey, $key, 0, null, '', $dbt, $esc_pretty_date, implode( ',', array_keys( $files ) ), $files );
	}

	/**
	 * Go through each of the file entities
	 *
	 * @param Array   $backup          An array of meta information
	 * @param Integer $key             Backup timestamp (epoch time)
	 * @param Array   $accept          An array of values to be accepted from values within $backup
	 * @param String  $entities        Entities to be added
	 * @param String  $esc_pretty_date Escaped pretty date
	 * @return Void - Display download buttons in existing backups table
	 */
	public function download_buttons( $backup, $key, $accept, &$entities, $esc_pretty_date ) {
		global $backupplus;
		$backupable_entities = BackupPlus_Backup_Sources::restore_entities( $backupplus->get_backupable_file_entities( true, true ), $backup );

		$first_entity = true;// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- template

		foreach ( $backupable_entities as $type => $info ) {
			if ( preg_match( '/^db[1-9][0-9]*$/D', $type ) ) { $entities .= '/' . $type . '=0/';
				continue; }
			if ( ! empty( $backup['meta_foreign'] ) && 'wpcore' != $type ) {
				continue;
			}

			$ide = '';

			if ( empty( $backup['meta_foreign'] ) ) {
				$sdescrip = preg_replace( '/ \(.*\)$/', '', $info['description'] );
				if ( strlen( $sdescrip ) > 20 && isset( $info['shortdescription'] ) ) {
					$sdescrip = $info['shortdescription'];
				}
			} else {
				$info['description'] = 'WordPress';

				if ( isset( $accept[ $backup['meta_foreign'] ] ) ) {
					$desc_source = $accept[ $backup['meta_foreign'] ]['desc'];
					/* translators: %s: Backup Source. */
					$ide .= sprintf( __( 'Backup created by: %s.', 'backupplus' ), $accept[ $backup['meta_foreign'] ]['desc'] ) . ' ';
				} else {
					$desc_source = __( 'unknown source', 'backupplus' );
					/* translators: %s: Unknown Source. */
					$ide .= __( 'Backup created by unknown source (%s) - cannot be restored.', 'backupplus' ) . ' ';
				}

				/* translators: %s: Backup Source. */
				$sdescrip = ( empty( $accept[ $backup['meta_foreign'] ]['separatedb'] ) ) ? sprintf( __( 'Files and database WordPress backup (created by %s)', 'backupplus' ), $desc_source ) : sprintf( __( 'Files backup (created by %s)', 'backupplus' ), $desc_source );
			}
			if ( isset( $backup[ $type ] ) ) {
				if ( ! is_array( $backup[ $type ] ) ) {
					$backup[ $type ] = array( $backup[ $type ] );
				}
				$howmanyinset   = count( $backup[ $type ] );
				$expected_index = 0;
				$index_missing  = false;
				$set_contents   = '';
				$entities      .= "/$type=";
				$whatfiles      = $backup[ $type ];
				ksort( $whatfiles );
				$total_file_size = 0;
				foreach ( $whatfiles as $findex => $bfile ) {
					$set_contents .= ( '' == $set_contents ) ? $findex : ",$findex";
					if ( $findex != $expected_index ) {
						$index_missing = true;
					}
					++$expected_index;

					if ( $howmanyinset > 0 ) {
						if ( ! empty( $backup[ $type . ( ( $findex > 0 ) ? $findex : '' ) . '-size' ] ) && $findex < $howmanyinset ) {
							$total_file_size += $backup[ $type . ( ( $findex > 0 ) ? $findex : '' ) . '-size' ];
						}
					}
				}

				$ide .= __( 'Press here to download or browse', 'backupplus' ) . ' ' . strtolower( $info['description'] );
				$ide .= ' ' . $this->archive_set_summary( $backup, $type, $howmanyinset, $total_file_size );
				if ( $index_missing ) {
					$ide .= ' ' . __( 'You are missing one or more archives from this multi-archive set.', 'backupplus' );
				}

				$entities .= $set_contents . '/';
				if ( ! empty( $backup['meta_foreign'] ) ) {
					$entities .= '/plugins=0//themes=0//uploads=0//others=0/';
				}

				$this->download_button( $type, $key, 0, null, $ide, $sdescrip, $esc_pretty_date, $set_contents );
			}
		}
	}

	/**
	 * Function to get the download button data from a list of backups
	 *
	 * @param Array $backup_history the backup history.
	 * @return Array An array of download button data from the passed in backup history.
	 */
	public function get_download_buttons_data( $backup_history ) {
		global $backupplus;

		$accept = apply_filters( 'backupplus_accept_archivename', array() );
		if ( ! is_array( $accept ) ) {
			$accept = array();
		}

		$backupable_entities = $backupplus->get_backupable_file_entities( true, true );

		$button_data = array();

		foreach ( $backup_history as $key => $backup ) {
			$backupable_entities = BackupPlus_Backup_Sources::restore_entities( $backupplus->get_backupable_file_entities( true, true ), $backup );
			$button_data[ $key ] = array(
				'downloads' => array(),
			);

			// https://core.trac.wordpress.org/ticket/25331 explains why the following line is wrong
			// $pretty_date = date_i18n('Y-m-d G:i',$key);
			// Convert to blog time zone
			// $pretty_date = get_date_from_gmt(gmdate('Y-m-d H:i:s', (int)$key), 'Y-m-d G:i');
			$pretty_date = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $key ), 'M d, Y G:i' );

			if ( empty( $backup['meta_foreign'] ) || ! empty( $accept[ $backup['meta_foreign'] ]['separatedb'] ) ) {

				if ( isset( $backup['db'] ) ) {
					$entities = '/db=0/';

					// Set a flag according to whether or not $backup['db'] ends in .crypt, then pick this up in the display of the decrypt field.
					$db = is_array( $backup['db'] ) ? $backup['db'][0] : $backup['db'];
					if ( BackupPlus_Encryption::is_file_encrypted( $db ) ) {
						$entities .= '/dbcrypted=1/';
					}
					$button_data[ $key ]['downloads']['db'] = array(
						'pretty_date'    => $pretty_date,
						'entities'       => $entities,
						'set_contents'   => implode( ',', array_keys( $this->download_files( $backup, 'db' ) ) ),
						'download_files' => $this->download_files( $backup, 'db' ),
						'title'          => __( 'Database', 'backupplus' ),
					);
				}

				// External databases
				foreach ( $backup as $bkey => $binfo ) {
					if ( 'db' == $bkey || 'db' != substr( $bkey, 0, 2 ) || '-size' == substr( $bkey, -5, 5 ) ) {
						continue;
					}
					$entities                                  = '/' . $bkey . '=0/';
					$button_data[ $key ]['downloads'][ $bkey ] = array(
						'pretty_date'  => $pretty_date,
						'entities'     => $entities,
						'set_contents' => '0',
						'title'        => __( 'External database', 'backupplus' ) . ' (' . substr( $bkey, 2 ) . ')',
					);
				}
			} else {
				// Foreign without separate db
				$entities                               = '/db=0/meta_foreign=1/';
				$button_data[ $key ]['downloads']['db'] = array(
					'pretty_date'  => $pretty_date,
					'entities'     => $entities,
					'set_contents' => '0',
					'title'        => __( 'Database', 'backupplus' ),
				);
			}

			if ( ! empty( $backup['meta_foreign'] ) && ! empty( $accept[ $backup['meta_foreign'] ] ) && ! empty( $accept[ $backup['meta_foreign'] ]['separatedb'] ) ) {
				$entities                               = '/db=0/meta_foreign=2/';
				$button_data[ $key ]['downloads']['db'] = array(
					'pretty_date'  => $pretty_date,
					'entities'     => $entities,
					'set_contents' => '0',
					'title'        => __( 'Database', 'backupplus' ),
				);
			}

			foreach ( $backupable_entities as $type => $info ) {
				if ( preg_match( '/^db[1-9][0-9]*$/D', $type ) ) { $entities .= '/' . $type . '=0/';
					continue; }
				if ( ! empty( $backup['meta_foreign'] ) && 'wpcore' != $type ) {
					continue;
				}

				$ide = '';

				if ( empty( $backup['meta_foreign'] ) ) {
					$sdescrip = preg_replace( '/ \(.*\)$/', '', $info['description'] );
					if ( strlen( $sdescrip ) > 20 && isset( $info['shortdescription'] ) ) {
						$sdescrip = $info['shortdescription'];
					}
				} else {
					$info['description'] = 'WordPress';

					if ( isset( $accept[ $backup['meta_foreign'] ] ) ) {
						$desc_source = $accept[ $backup['meta_foreign'] ]['desc'];
						/* translators: %s: Backup Source. */
						$ide .= sprintf( __( 'Backup created by: %s.', 'backupplus' ), $accept[ $backup['meta_foreign'] ]['desc'] ) . ' ';
					} else {
						$desc_source = __( 'unknown source', 'backupplus' );
						/* translators: %s: Unknown Source. */
						$ide .= __( 'Backup created by unknown source (%s) - cannot be restored.', 'backupplus' ) . ' ';
					}

					/* translators: %s: Backup Source. */
					$sdescrip = ( empty( $accept[ $backup['meta_foreign'] ]['separatedb'] ) ) ? sprintf( __( 'Files and database WordPress backup (created by %s)', 'backupplus' ), $desc_source ) : sprintf( __( 'Files backup (created by %s)', 'backupplus' ), $desc_source );
				}
				if ( isset( $backup[ $type ] ) ) {
					if ( ! is_array( $backup[ $type ] ) ) {
						$backup[ $type ] = array( $backup[ $type ] );
					}
					$howmanyinset   = count( $backup[ $type ] );
					$expected_index = 0;
					$index_missing  = false;
					$set_contents   = '';
					$entities       = "/$type=";
					$whatfiles      = $backup[ $type ];
					ksort( $whatfiles );
					$total_file_size = 0;
					foreach ( $whatfiles as $findex => $bfile ) {
						$set_contents .= ( '' == $set_contents ) ? $findex : ",$findex";
						if ( $findex != $expected_index ) {
							$index_missing = true;
						}
						++$expected_index;

						if ( $howmanyinset > 0 ) {
							if ( ! empty( $backup[ $type . ( ( $findex > 0 ) ? $findex : '' ) . '-size' ] ) && $findex < $howmanyinset ) {
								$total_file_size += $backup[ $type . ( ( $findex > 0 ) ? $findex : '' ) . '-size' ];
							}
						}
					}

					$ide .= __( 'Press here to download or browse', 'backupplus' ) . ' ' . strtolower( $info['description'] );
					$ide .= ' ' . $this->archive_set_summary( $backup, $type, $howmanyinset, $total_file_size );
					if ( $index_missing ) {
						$ide .= ' ' . __( 'You are missing one or more archives from this multi-archive set.', 'backupplus' );
					}

					$entities .= $set_contents . '/';
					if ( ! empty( $backup['meta_foreign'] ) ) {
						$entities .= '/plugins=0//themes=0//uploads=0//others=0/';
					}
					$button_data[ $key ]['downloads'][ $type ] = array(
						'pretty_date'  => $pretty_date,
						'entities'     => $entities,
						'set_contents' => $set_contents,
						'title'        => $sdescrip,
						'ide'          => $ide,
					);
				}
			}
		}

		return $button_data;
	}

	/**
	 * Count the incremental backups that were added to a full backup.
	 *
	 * @param array $backup Backup instance, including its runtime 'incremental_sets'.
	 * @return int - Number of incremental backups chained to the full backup; 0 if there are none.
	 */
	public function incremental_count( $backup ) {
		if ( empty( $backup['incremental'] ) || empty( $backup['incremental_sets'] ) || ! is_array( $backup['incremental_sets'] ) ) {
			return 0;
		}
		return max( 0, count( $backup['incremental_sets'] ) - 1 );
	}

	/**
	 * Describe the archives of one entity in a backup, separating the full backup from its increments when there are any.
	 *
	 * @param array  $backup          Backup instance, including its runtime 'incremental_sets'.
	 * @param string $type            Entity key, e.g. plugins.
	 * @param int    $archive_count   Number of archives of the entity in the backup.
	 * @param int    $total_file_size Total size of those archives in bytes.
	 * @return string
	 */
	private function archive_set_summary( $backup, $type, $archive_count, $total_file_size ) {
		$total = BackupPlus_Manipulation_Functions::convert_numeric_size_to_text( $total_file_size );

		$increments = $this->incremental_count( $backup );
		if ( $increments < 1 ) {
			/* translators: 1: Archive count, 2: Total size.*/
			return sprintf( __( '(%1$d archive(s) in set, total %2$s).', 'backupplus' ), $archive_count, $total );
		}

		// The earliest restore point is the full backup; every later one is an increment.
		$full_files     = reset( $backup['incremental_sets'] );
		$full_file_size = 0;
		if ( isset( $full_files[ $type ] ) ) {
			foreach ( array_keys( $full_files[ $type ] ) as $findex ) {
				$size_key        = $type . ( ( $findex > 0 ) ? $findex : '' ) . '-size';
				$full_file_size += isset( $backup[ $size_key ] ) ? $backup[ $size_key ] : 0;
			}
		}

		/* translators: 1: Archive count, 2: Total size, 3: Full backup size, 4: Number of incremental backups, 5: Size of the incremental backups. */
		return sprintf( __( '(%1$d archive(s) in chain, total %2$s: full backup %3$s plus %4$d incremental backup(s) %5$s).', 'backupplus' ), $archive_count, $total, BackupPlus_Manipulation_Functions::convert_numeric_size_to_text( $full_file_size ), $increments, BackupPlus_Manipulation_Functions::convert_numeric_size_to_text( max( 0, $total_file_size - $full_file_size ) ) );
	}

	/**
	 * List the points in time that a backup chain can be restored to, newest first.
	 *
	 * @param array $backup Backup instance, including its runtime 'incremental_sets'.
	 * @return array - Restore points as arrays with keys 'timestamp' and 'label'; empty if the backup has no increments.
	 */
	public function restore_points( $backup ) {
		if ( ! empty( $backup['pitr'] ) ) {
			$points = array();
			$stored = isset( $backup['pitr']['points'] ) ? $backup['pitr']['points'] : array();
			foreach ( $stored as $point ) {
				$label  = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $point['timestamp'] ), 'M d, Y G:i:s' ) . ' - ';
				$label .= $point['increment'] ? sprintf( __( 'database incremental %d', 'backupplus' ), $point['increment'] ) : __( 'full database backup', 'backupplus' );
				if ( $point === end( $stored ) ) { $label .= ' (' . __( 'latest', 'backupplus' ) . ')'; }
				$points[] = array_merge( $point, array( 'label' => $label ) );
			}
			return array_reverse( $points );
		}
		if ( $this->incremental_count( $backup ) < 1 ) {
			return array();
		}
		$timestamps = array_keys( $backup['incremental_sets'] );
		$points     = array();
		$encrypted  = false;
		foreach ( $timestamps as $index => $timestamp ) {
			// A restore point needs the full backup and every increment up to that point.
			// Keep the status cumulative so a later unencrypted increment does not hide
			// encryption in an earlier archive required for the restore.
			foreach ( $backup['incremental_sets'][ $timestamp ] as $entity_archives ) {
				foreach ( (array) $entity_archives as $filename ) {
					if ( BackupPlus_Encryption::is_file_encrypted( $filename ) ) {
						$encrypted = true;
						break 2;
					}
				}
			}

			$label = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $timestamp ), 'M d, Y G:i' ) . ' - ';
			if ( 0 === $index ) {
				$label .= __( 'full backup', 'backupplus' );
			} else {
				/* translators: %d: Position of the incremental backup in its chain */
				$label .= sprintf( __( 'incremental backup %d', 'backupplus' ), $index );
			}
			if ( count( $timestamps ) - 1 === $index ) {
				$label .= ' (' . __( 'latest', 'backupplus' ) . ')';
			}
			$points[] = array(
				'timestamp' => (int) $timestamp,
				'label'     => $label,
				'encrypted' => $encrypted,
			);
		}
		return array_reverse( $points );
	}

	/**
	 * Short label for a backup that is a full backup with increments.
	 *
	 * @param int $increments Number of incremental backups in the chain.
	 * @return string
	 */
	public function chain_label( $increments ) {
		/* translators: %d: Number of incremental backups */
		return sprintf( _n( 'Full + %d incremental', 'Full + %d incrementals', $increments, 'backupplus' ), $increments );
	}

	/**
	 * Explain what removing a backup that is a full backup with increments does.
	 *
	 * @param int $increments Number of incremental backups in the chain.
	 * @return string
	 */
	public function chain_description( $increments ) {
		/* translators: 1: Number of incremental backups, 2: Number of restore points */
		return sprintf( _n( 'This full backup has %1$d incremental backup chained to it (%2$d restore points). Deleting it deletes all of them.', 'This full backup has %1$d incremental backups chained to it (%2$d restore points). Deleting it deletes all of them.', $increments, 'backupplus' ), $increments, $increments + 1 );
	}

	/**
	 * Return HTML for 'Backup date' column in existing backups data table.
	 *
	 * @param string $pretty_date   Pretty date.
	 * @param string $key           Backup timestamp (epoch time).
	 * @param array  $backup        Backup instance.
	 * @param array  $jobdata       Job data.
	 * @param string $nonce         Nonce.
	 * @param bool   $simple_format Whether to display simple format or not. Default false.
	 * @return string - Return HTML for date label.
	 */
	public function date_label( $pretty_date, $key, $backup, $jobdata, $nonce, $simple_format = false ) {

		$pretty_date = $simple_format ? $pretty_date : '<div class="clear-right">' . $pretty_date . '</div>';
		if ( ! empty( $backup['pitr'] ) && ! $simple_format ) {
			$increments = max( 0, count( isset( $backup['pitr']['points'] ) ? $backup['pitr']['points'] : array() ) - 1 );
			// Not "Full backup": that reads as an ordinary database dump, which restores without a database login.
			/* translators: %d: Number of incremental database recovery points */
			$label       = $increments ? sprintf( _n( 'Recovery chain + %d incremental', 'Recovery chain + %d incrementals', $increments, 'backupplus' ), $increments ) : __( 'Recovery chain', 'backupplus' );
			$description = __( 'Database point-in-time recovery', 'backupplus' );
			if ( ! empty( $backup['pitr']['points'] ) ) {
				$latest       = end( $backup['pitr']['points'] );
				$description .= '. ' . sprintf( __( 'Latest recovery point: %s', 'backupplus' ), get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $latest['timestamp'] ), 'M d, Y G:i:s' ) );
			}
			$pretty_date .= '<span class="backupplus-chain-badge backupplus-pitr-badge" title="' . esc_attr( $description ) . '">' . esc_html( $label ) . '</span>';
			if ( in_array( $backup['pitr']['state'], array( 'deleting', 'unavailable' ), true ) ) {
				$pretty_date .= '<div role="alert">' . esc_html__( 'Database recovery chain unavailable', 'backupplus' ) . '</div>';
			}
		}
		$increments = $this->incremental_count( $backup );
		if ( $increments > 0 && ! $simple_format ) {
			$pretty_date .= '<span class="backupplus-chain-badge" title="' . esc_attr( $this->chain_description( $increments ) ) . '">' . esc_html( $this->chain_label( $increments ) ) . '</span>';
		}

		$ret = apply_filters( 'backupplus_showbackup_date', $pretty_date, $backup, $jobdata, (int) $key, $simple_format );
		if ( is_array( $jobdata ) && ! empty( $jobdata['resume_interval'] ) && ( empty( $jobdata['jobstatus'] ) || 'finished' != $jobdata['jobstatus'] ) ) {
			if ( $simple_format ) {
				$ret .= ' ' . __( '(Not finished)', 'backupplus' );
			} else {
				$ret .= apply_filters( 'backupplus_msg_unfinishedbackup', '<br><span title="' . esc_attr( __( 'If you are seeing more backups than you expect, then it is probably because the deletion of old backup sets does not happen until a fresh backup completes.', 'backupplus' ) ) . '">' . __( '(Not finished)', 'backupplus' ) . '</span>', $jobdata, $nonce );
			}
		}
		return $ret;
	}

	/**
	 * Display download button for backups in existing backups table.
	 *
	 * @param string $type             Backup type.
	 * @param string $backup_timestamp Backup timestamp (epoch time).
	 * @param int    $findex           File index.
	 * @param string $info             Additional Info.
	 * @param string $title            Title attribute contents.
	 * @param string $pdescrip         Button text.
	 * @param string $esc_pretty_date  Escaped pretty date.
	 * @param string $set_contents     Custom data attribute called set.
	 * @param array  $download_files   Download file metadata, including PITR artifacts.
	 * @return void - Display download button for backups.
	 */
	public function download_button( $type, $backup_timestamp, $findex, $info, $title, $pdescrip, $esc_pretty_date, $set_contents, $download_files = array() ) {// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Filter use

		$wp_nonce = wp_create_nonce( 'backupplus_download' );

		// backupplus_downloader(base, backup_timestamp, what, whicharea, set_contents, prettydate, async)
			echo '<button data-download_files="' . esc_attr( wp_json_encode( $download_files ) ) . '" data-wp_nonce="' . esc_attr( $wp_nonce ) . '" data-backup_timestamp="' . esc_attr( $backup_timestamp ) . '" data-what="' . esc_attr( $type ) . '" data-set_contents="' . esc_attr( $set_contents ) . '" data-prettydate="' . esc_attr( $esc_pretty_date ) . '" type="button" class="button backupplus_download_button ' . esc_attr( "uddownloadform_{$type}_{$backup_timestamp}_{$findex}" ) . '" title="' . esc_attr( $title ) . '">' . esc_html( $pdescrip ) . '</button>';
		// onclick="'."return backupplus_downloader('uddlstatus_', '$backup_timestamp', '$type', '.ud_downloadstatus', '$set_contents', '$esc_pretty_date', true)".'"
	}

	/**
	 * Display 'Restore' button for backup in existing backups table.
	 *
	 * @param array  $backup      Backup instance.
	 * @param string $key         Backup timestamp (epoch time).
	 * @param string $pretty_date Pretty date.
	 * @param string $entities    Entities backed-up in this backup.
	 * @return void - Display 'Restore' button.
	 */
	public function restore_button( $backup, $key, $pretty_date, $entities = '' ) {
		?>
		<div class="restore-button">
		<?php

		if ( $entities ) {
			$show_data = $pretty_date;
			if ( isset( $backup['native'] ) && false == $backup['native'] ) {
				$show_data .= ' ' . __( '(backup set imported from remote location)', 'backupplus' );
			}
			// Only encrypted sets ask for a private key.
			if ( BackupPlus_Age_Restore::has_encrypted( $backup ) ) {
				$entities .= '/crypted=1/';
			}
			?>
			<button data-pitr="<?php echo empty( $backup['pitr'] ) ? '0' : '1'; ?>" data-showdata="<?php echo esc_attr( $show_data ); ?>" data-backup_timestamp="<?php echo esc_attr( $key ); ?>" data-entities="<?php echo esc_attr( $entities ); ?>" data-restorepoints="<?php echo esc_attr( wp_json_encode( $this->restore_points( $backup ) ) ); ?>" title="<?php esc_attr_e( 'After pressing this button, you will be given the option to choose which components you wish to restore', 'backupplus' ); ?>" type="button" class="button button-primary choose-components-button">
				<?php esc_html_e( 'Restore', 'backupplus' ); ?>
			</button>
			<?php
		}
		?>
		</div>
		<?php
	}

	/**
	 * Display HTML for the 'Upload' button for a particular backup in the 'Existing backups' tab
	 *
	 * @param Integer    $backup_time - backup timestamp (epoch time)
	 * @param String     $nonce       - backup nonce
	 * @param Array      $backup      - backup information array
	 * @param Null|Array $jobdata     - if not null, then use as the job data instead of fetching
	 *
	 * @return Void - Display 'Upload' button in existing backup table.
	 */
	public function upload_button( $backup_time, $nonce, $backup, $jobdata = null ) {
		global $backupplus;

		// Check the job is not still running.
		if ( null === $jobdata ) {
			$jobdata = $backupplus->jobdata_getarray( $nonce );
		}

		if ( ! empty( $jobdata ) && 'finished' != $jobdata['jobstatus'] ) {
			return '';
		}

		// Check that the user has remote storage setup.
		$services = (array) $backupplus->just_one( $backupplus->get_canonical_service_list() );
		if ( empty( $services ) ) {
			return '';
		}

		$show_upload = false;

		// Check that the backup has not already been sent to remote storage before.
		if ( empty( $backup['service'] ) || array( 'none' ) == $backup['service'] || array( '' ) == $backup['service'] || 'none' == $backup['service'] ) {
			$show_upload = true;
			// If it has been uploaded then check if there are any new remote storage options that it has not yet been sent to.
		} elseif ( ! empty( $backup['service'] ) && array( 'none' ) != $backup['service'] && array( '' ) != $backup['service'] && 'none' != $backup['service'] ) {

			foreach ( $services as $key => $value ) {
				if ( is_string( $backup['service'] ) ) {
					$backup['service'] = array( $backup['service'] );
				}
				if ( in_array( $value, $backup['service'] ) ) {
					unset( $services[ $key ] );
				}
			}

			if ( ! empty( $services ) ) {
				$show_upload = true;
			}
		}

		if ( $show_upload ) {

			$backup_local = $this->check_backup_is_complete( $backup, false, true );

			if ( $backup_local ) {
				$service_list         = '';
				$service_list_display = '';
				$is_first_service     = true;

				foreach ( $services as $key => $service ) {
					if ( ! $is_first_service ) {
						$service_list         .= ',';
						$service_list_display .= ', ';
					}
					$service_list         .= $service;
					$service_list_display .= $backupplus->backup_methods[ $service ];

					$is_first_service = false;
				}

				?>
				<div class="backupplus-upload">
					<button data-nonce="<?php echo esc_attr( $nonce ); ?>" data-key="<?php echo esc_attr( $backup_time ); ?>" data-services="<?php echo esc_attr( $service_list ); ?>" title="<?php echo esc_attr( __( 'After pressing this button, you can select where to upload your backup from a list of your currently saved remote storage locations', 'backupplus' ) . ' (' . $service_list_display . ')' ); ?>" type="button" class="button button-primary backupplus-upload-link">
						<?php esc_html_e( 'Upload', 'backupplus' ); ?>
					</button>
				</div>
				<?php
			}

			return;
		}
	}

	/**
	 * Display HTML for the 'Delete' button for a particular backup in the 'Existing backups' tab
	 *
	 * @param Integer $backup_time - backup timestamp (epoch time)
	 * @param String  $nonce       - backup nonce
	 * @param Array   $backup      - backup information array
	 *
	 * @return Void - Display delete button for backup.
	 */
	public function delete_button( $backup_time, $nonce, $backup ) {
		global $backupplus;
		$services   = $backupplus->get_canonical_service_list( $backup['service'] );
		$sval       = array_diff( $services, array( 'email' ) ) ? 1 : 0;
		$increments = $this->incremental_count( $backup );
		if ( ! empty( $backup['pitr']['points'] ) ) { $increments = max( 0, count( $backup['pitr']['points'] ) - 1 ); }
		$chain_label = $increments > 0 ? $this->chain_label( $increments ) : ( ! empty( $backup['pitr'] ) ? __( 'Full database recovery backup', 'backupplus' ) : '' );
		?>
		<div class="backupplus-remove" data-hasremote="<?php echo esc_attr( $sval ); ?>">
			<a data-hasremote="<?php echo esc_attr( $sval ); ?>" data-chain="<?php echo esc_attr( $chain_label ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $backup_time ), 'M d, Y G:i' ) . ': ' . $chain_label : '' ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-key="<?php echo esc_attr( $backup_time ); ?>" class="button button-remove no-decoration backupplus-delete-link" href="<?php echo esc_url( BackupPlus::get_current_clean_url() ); ?>" title="<?php esc_attr_e( 'Delete this backup set', 'backupplus' ); ?>">
				<?php esc_html_e( 'Delete', 'backupplus' ); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Display 'View Log' button in existing backups table.
	 *
	 * @param array $backup Backup instance.
	 * @return void - Display 'View Log' button.
	 */
	public function log_button( $backup ) {
		global $backupplus;
		$backupplus_dir = $backupplus->backups_dir_location();
		if ( isset( $backup['nonce'] ) && preg_match( '/^[0-9a-f]{12}$/', $backup['nonce'] ) && is_readable( $backupplus_dir . '/log.' . $backup['nonce'] . '.txt' ) ) {
			$nval = $backup['nonce'];
			$url  = BackupPlus_Options::admin_page() . "?page=backupplus&action=downloadlog&amp;backupplus_backup_nonce=$nval";
			?>
			<div style="clear:none;" class="backupplus-viewlogdiv">
					<a class="button no-decoration backupplus-log-link" href="<?php echo esc_attr( $url ); ?>" data-jobid="<?php echo esc_attr( $nval ); ?>">
						<?php esc_html_e( 'View Log', 'backupplus' ); ?>
					</a>
					<!--
					<form action="$url" method="get">
						<input type="hidden" name="action" value="downloadlog" />
						<input type="hidden" name="page" value="backupplus" />
						<input type="hidden" name="backupplus_backup_nonce" value="$nval" />
						<input type="submit" value="$lt" class="backupplus-log-link" onclick="event.preventDefault(); backupplus_popuplog(\''.esc_attr($nval).'\');" />
					</form>
					-->
			</div>
			<?php
		}
	}

	/**
	 * This will return all the details for raw backup and file list, in HTML format
	 *
	 * @param Boolean $no_pre_tags - if set, then <pre></pre> tags will be removed from the output
	 *
	 * @return String
	 */
	public function show_raw_backups( $no_pre_tags = false ) {
		global $backupplus;

		$response = array();

		$response['html'] = '<h3 id="ud-debuginfo-rawbackups">' . __( 'Known backups (raw)', 'backupplus' ) . '</h3><pre>';
		ob_start();
		$history = BackupPlus_Backup_History::get_history();
		var_dump( $history ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_dump -- we use the var_dump() to show the raw backup list in the advanced tools.
		$response['html'] .= ob_get_clean();
		$response['html'] .= '</pre>';

		$response['html'] .= '<h3 id="ud-debuginfo-files">' . __( 'Files', 'backupplus' ) . '</h3><pre>';
		$backupplus_dir    = $backupplus->backups_dir_location();
		$raw_output        = array();
		$d                 = dir( $backupplus_dir );
		while ( false !== ( $entry = $d->read() ) ) {
			$fp    = $backupplus_dir . '/' . $entry;
			$mtime = filemtime( $fp );
			if ( is_dir( $fp ) ) {
				$size = '       d';
			} elseif ( is_link( $fp ) ) {
				$size = '       l';
			} elseif ( is_file( $fp ) ) {
				$size = sprintf( '%8.1f', round( filesize( $fp ) / 1024, 1 ) ) . ' ' . gmdate( 'r', $mtime );
			} else {
				$size = '       ?';
			}
			if ( preg_match( '/^log\.(.*)\.txt$/', $entry, $lmatch ) ) {
				$entry = '<a target="_top" href="?action=downloadlog&amp;page=backupplus&amp;backupplus_backup_nonce=' . htmlspecialchars( $lmatch[1] ) . '">' . $entry . '</a>';
			}
			$raw_output[ $mtime ] = empty( $raw_output[ $mtime ] ) ? sprintf( "%s %s\n", $size, $entry ) : $raw_output[ $mtime ] . sprintf( "%s %s\n", $size, $entry );
		}
		@$d->close();// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the method.
		krsort( $raw_output, SORT_NUMERIC );

		foreach ( $raw_output as $line ) {
			$response['html'] .= $line;
		}

		$response['html'] .= '</pre>';

		$response['html'] .= '<h3 id="ud-debuginfo-options">' . __( 'Options (raw)', 'backupplus' ) . '</h3>';
		$opts              = $backupplus->get_settings_keys();
		asort( $opts );
		// <tr><th>'.__('Key', 'backupplus').'</th><th>'.__('Value', 'backupplus').'</th></tr>
		$response['html'] .= '<table><thead></thead><tbody>';
		foreach ( $opts as $opt ) {
			$pitr_credentials = in_array( $opt, array( 'backupplus_pitr_capture', 'backupplus_pitr_recovery' ), true );
			$value            = $pitr_credentials ? get_option( $opt ) : BackupPlus_Options::get_backupplus_option( $opt );
			if ( $pitr_credentials && $value ) { $value = '[redacted]'; }
			$response['html'] .= '<tr><td>' . htmlspecialchars( $opt ) . '</td><td>' . htmlspecialchars( print_r( $value, true ) ) . '</td>';
		}

		// Get the option saved by yahnis-elsts/plugin-update-checker
		$response['html'] .= '<tr><td>external_updates-backupplus</td><td><pre>' . htmlspecialchars( print_r( get_site_option( 'external_updates-backupplus' ), true ) ) . '</pre></td>';

		$response['html'] .= '</tbody></table>';

		ob_start();
		do_action( 'backupplus_showrawinfo' );
		$response['html'] .= ob_get_clean();

		if ( true == $no_pre_tags ) {
			$response['html'] = str_replace( '<pre>', '', $response['html'] );
			$response['html'] = str_replace( '</pre>', '', $response['html'] );
		}

		return $response;
	}
}
