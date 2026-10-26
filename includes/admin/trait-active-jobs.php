<?php

/** Running jobs, their progress display and their log. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Admin_Active_Jobs {

	/**
	 * Stop an active backup job
	 *
	 * @param String $job_id - job ID of the job to stop
	 *
	 * @return Array - information on the outcome of the attempt
	 */
	public function activejobs_delete( $job_id ) {

		if ( preg_match( '/^[0-9a-f]{12}$/', $job_id ) ) {

			global $backupplus;
			$cron = get_option( 'cron', array() );

			$jobdata = $backupplus->jobdata_getarray( $job_id );

			$backupplus_dir = $backupplus->backups_dir_location();
			if ( file_exists( $backupplus_dir . '/log.' . $job_id . '.txt' ) ) {
				touch( $backupplus_dir . '/deleteflag-' . $job_id . '.txt' );
			}

			foreach ( $cron as $time => $job ) {
				if ( ! isset( $job['backupplus_backup_resume'] ) ) {
					continue;
				}
				foreach ( $job['backupplus_backup_resume'] as $hook => $info ) {
					if ( isset( $info['args'][1] ) && $info['args'][1] == $job_id ) {
						$args = $cron[ $time ]['backupplus_backup_resume'][ $hook ]['args'];
						wp_unschedule_event( $time, 'backupplus_backup_resume', $args );

						if ( ! class_exists( 'BackupPlus_Semaphore_3_0' ) ) {
							backupplus_try_include_file( 'includes/class-backupplus-semaphore.php', 'include_once' );
						}

						$backupplus_semaphore = new BackupPlus_Semaphore_3_0( 'udp_backupjob_' . $job_id );
						$backupplus_semaphore->delete();

						return array(
							'ok' => 'Y',
							'c'  => 'deleted',
							'm'  => __( 'Job deleted', 'backupplus' ),
						);
					}
				}
			}
		}

		return array(
			'ok' => 'N',
			'c'  => 'not_found',
			'm'  => __( 'Could not find that job - perhaps it has already finished?', 'backupplus' ),
		);
	}

	/**
	 * Get, as HTML output, a list of active jobs
	 *
	 * @param Array $request - details on the request being made (e.g. extra info to include)
	 *
	 * @return String
	 */
	public function get_activejobs_list( $request ) {

		global $backupplus;

		$download_status = empty( $request['downloaders'] ) ? array() : $this->get_download_statuses( explode( ':', $request['downloaders'] ) );

		if ( ! empty( $request['oneshot'] ) ) {
			$job_id = get_site_option( 'backupplus_oneshotnonce', false );
			// print_active_job() for one-shot jobs that aren't in cron
			$active_jobs = ( false === $job_id ) ? '' : $this->print_active_job( $job_id, true );
		} elseif ( ! empty( $request['thisjobonly'] ) ) {
			// print_active_jobs() is for resumable jobs where we want the cron info to be included in the output
			$active_jobs = $this->print_active_jobs( $request['thisjobonly'] );
		} else {
			$active_jobs = $this->print_active_jobs();
		}
		$logupdate_array = array();
		if ( ! empty( $request['log_fetch'] ) ) {
			if ( isset( $request['log_nonce'] ) ) {
				$log_nonce       = $request['log_nonce'];
				$log_pointer     = isset( $request['log_pointer'] ) ? absint( $request['log_pointer'] ) : 0;
				$logupdate_array = $this->fetch_log( $log_nonce, $log_pointer );
			}
		}
		$res = array(
			// We allow the front-end to decide what to do if there's nothing logged - we used to (up to 1.11.29) send a pre-defined message
			'l'                 => htmlspecialchars( BackupPlus_Options::get_backupplus_lastmessage() ),
			'j'                 => $active_jobs,
			'ds'                => $download_status,
			'u'                 => $logupdate_array,
			'automatic_updates' => $backupplus->is_automatic_updating_enabled(),
		);

		$res['hosting_restriction'] = $backupplus->is_hosting_backup_limit_reached();

		return $res;
	}

	/**
	 * Get the contents of a log file
	 *
	 * @param String  $backup_nonce  - the backup id; or empty, for the most recently modified
	 * @param Integer $log_pointer   - the byte count to fetch from
	 * @param String  $output_format - the format to return in; allowed as 'html' (which will escape HTML entities in what is returned) and 'raw'
	 *
	 * @return String
	 */
	public function fetch_log( $backup_nonce = '', $log_pointer = 0, $output_format = 'html' ) {
		global $backupplus;

		if ( empty( $backup_nonce ) ) {
			list($mod_time, $log_file, $nonce) = $backupplus->last_modified_log();// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Unused parameter is present because the method returns an array.
		} else {
			$nonce = $backup_nonce;
		}

		if ( ! preg_match( '/^[0-9a-f]+$/', $nonce ) ) {
			die( 'Security check' );
		}

		$log_content = '';
		$new_pointer = $log_pointer;

		if ( ! empty( $nonce ) ) {
			$backupplus_dir = $backupplus->backups_dir_location();

			$potential_log_file = $backupplus_dir . '/log.' . $nonce . '.txt';

			if ( is_readable( $potential_log_file ) ) {

				$templog_array = array();
				$log_file      = fopen( $potential_log_file, 'r' );
				if ( $log_pointer > 0 ) {
					fseek( $log_file, $log_pointer );
				}

				while ( ( $buffer = fgets( $log_file, 4096 ) ) !== false ) {
					$templog_array[] = $buffer;
				}
				if ( ! feof( $log_file ) ) {
					$templog_array[] = __( 'Error: unexpected file read fail', 'backupplus' );
				}

				$new_pointer = ftell( $log_file );
				$log_content = implode( '', $templog_array );

			} else {
				$log_content .= __( 'The log file could not be read.', 'backupplus' );
			}
		} else {
			$log_content .= __( 'The log file could not be read.', 'backupplus' );
		}

		if ( 'html' == $output_format ) {
			$log_content = htmlspecialchars( $log_content );
		}

		$ret_array = array(
			'log'     => $log_content,
			'nonce'   => $nonce,
			'pointer' => $new_pointer,
		);

		return $ret_array;
	}

	/**
	 * Also used by the auto-backups add-on
	 *
	 * @param  Boolean $wide_format       Whether to return data in a wide format
	 * @param  Boolean $print_active_jobs Whether to include currently active jobs
	 * @return String - the HTML output
	 */
	public function render_active_jobs_and_log_table( $wide_format = false, $print_active_jobs = true ) {
		global $backupplus;
		?>
		<div id="backupplus_activejobs_table">
			<?php $active_jobs = ( $print_active_jobs ) ? $this->print_active_jobs() : ''; ?>
			<div id="backupplus_activejobsrow" class="
			<?php
			if ( ! $active_jobs && ! $wide_format ) {
				echo 'hidden';
			}
			if ( $wide_format ) {
				echo 'minimum-height';
			}
			?>
			">
				<div id="backupplus_activejobs" class="<?php echo $wide_format ? 'wide-format' : ''; ?>">
					<?php echo wp_kses_post( $active_jobs ); ?>
				</div>
			</div>
			<div id="backupplus_lastlogmessagerow">
				<?php
				if ( $wide_format ) {
					// Hide for now - too ugly
					?>
					<div class="last-message"><strong><?php esc_html_e( 'Last log message', 'backupplus' ); ?>:</strong><br>
						<span id="backupplus_lastlogcontainer"><?php echo wp_kses_post( BackupPlus_Options::get_backupplus_lastmessage() ); ?></span><br>
						<?php $this->most_recently_modified_log_link(); ?>
					</div>
				<?php } else { ?>
					<div>
						<strong><?php esc_html_e( 'Last log message', 'backupplus' ); ?>:</strong>
						<span id="backupplus_lastlogcontainer"><?php echo wp_kses_post( BackupPlus_Options::get_backupplus_lastmessage() ); ?></span><br>
						<?php $this->most_recently_modified_log_link(); ?>
					</div>
				<?php } ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Output directly a link allowing download of the most recently modified log file
	 */
	private function most_recently_modified_log_link() {

		global $backupplus;
		list($mod_time, $log_file, $nonce) = $backupplus->last_modified_log();// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Unused parameter is present because the method returns an array.

		?>
			<a href="?page=backupplus&amp;action=downloadlatestmodlog&amp;wpnonce=<?php echo esc_attr( wp_create_nonce( 'backupplus_download' ) ); ?>" 
			<?php
			if ( ! $mod_time ) {
				echo 'style="display:none;"';}
			?>
			class="backupplus-log-link" onclick="event.preventDefault(); backupplus_popuplog('');"><?php esc_html_e( 'Download most recently modified log file', 'backupplus' ); ?></a>
		<?php
	}

	/**
	 * Return cron status information about a specified in-progress job
	 *
	 * @param Boolean|String $job_id - the job to get information about; or, if not specified, all jobs
	 *
	 * @return Array|Boolean - the requested information, or false if it was not found. Format differs depending on whether info on all jobs, or a single job, was requested.
	 */
	public function get_cron( $job_id = false ) {

		$cron = get_option( 'cron' );
		if ( ! is_array( $cron ) ) {
			$cron = array();
		}
		if ( false === $job_id ) {
			return $cron;
		}

		foreach ( $cron as $time => $job ) {
			if ( ! isset( $job['backupplus_backup_resume'] ) ) {
				continue;
			}
			foreach ( $job['backupplus_backup_resume'] as $info ) {
				if ( isset( $info['args'][1] ) && $job_id == $info['args'][1] ) {
					global $backupplus;
					$jobdata = $backupplus->jobdata_getarray( $job_id );
					return is_array( $jobdata ) ? array( $time, $jobdata ) : false;
				}
			}
		}
	}

	/**
	 * Gets HTML describing the active jobs
	 *
	 * @param  Boolean $this_job_only A value for $this_job_only also causes something non-empty to always be returned (to allow detection of the job having started on the front-end)
	 *
	 * @return String - the HTML
	 */
	private function print_active_jobs( $this_job_only = false ) {
		$cron = $this->get_cron();
		$ret  = '';

		foreach ( $cron as $time => $job ) {
			if ( ! isset( $job['backupplus_backup_resume'] ) ) {
				continue;
			}
			foreach ( $job['backupplus_backup_resume'] as $info ) {
				if ( isset( $info['args'][1] ) ) {
					$job_id = $info['args'][1];
					if ( false === $this_job_only || $job_id == $this_job_only ) {
						$ret .= $this->print_active_job( $job_id, false, $time, $info['args'][0] );
					}
				}
			}
		}
		// A value for $this_job_only implies that output is required
		if ( false !== $this_job_only && ! $ret ) {
			$ret = $this->print_active_job( $this_job_only );
			if ( '' == $ret ) {
				global $backupplus;
				$log_file = $backupplus->get_logfile_name( $this_job_only );
				// if the file exists, the backup was booted. Check if the information about completion is found in the log, or if it was modified at least 2 minutes ago.
				if ( file_exists( $log_file ) && ( $backupplus->found_backup_complete_in_logfile( $this_job_only ) || ( time() - filemtime( $log_file ) ) > 120 ) ) {
					// The presence of the exact ID matters to the front-end - indicates that the backup job has at least begun
					$ret = '<div class="active-jobs backupplus_finished" id="backupplus-jobid-' . esc_attr( $this_job_only ) . '"><em>' . __( 'The backup has finished running', 'backupplus' ) . '</em> - <a class="backupplus-log-link" data-jobid="' . esc_attr( $this_job_only ) . '">' . __( 'View Log', 'backupplus' ) . '</a></div>';
				}
			}
		}

		return $ret;
	}

	/**
	 * Print the HTML for a particular job
	 *
	 * @param String          $job_id          - the job identifier/nonce
	 * @param Boolean         $is_oneshot      - whether this backup should be 'one shot', i.e. no resumptions
	 * @param Boolean|Integer $time
	 * @param Integer         $next_resumption
	 *
	 * @return String
	 */
	private function print_active_job( $job_id, $is_oneshot = false, $time = false, $next_resumption = false ) {

		$ret = '';

		global $backupplus;
		$jobdata = $backupplus->jobdata_getarray( $job_id );

		if ( false == apply_filters( 'backupplus_print_active_job_continue', true, $is_oneshot, $next_resumption, $jobdata ) ) {
			return '';
		}

		// Retention cleanup jobs are reported in the next scheduled backups box.
		if ( ! isset( $jobdata['backup_time'] ) || ( isset( $jobdata['job_type'] ) && 'cleanup' === $jobdata['job_type'] ) ) {
			return '';
		}

		$backupable_entities = $backupplus->get_backupable_file_entities( true, true );

		$began_at = isset( $jobdata['backup_time'] ) ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $jobdata['backup_time'] ), 'D, F j, Y H:i' ) : '?';

		$backup_label = ! empty( $jobdata['label'] ) ? $jobdata['label'] : '';

		$jobstatus = empty( $jobdata['jobstatus'] ) ? 'unknown' : $jobdata['jobstatus'];
		$stage     = 0;
		switch ( $jobstatus ) {
			// Stage 0
			case 'begun':
				$curstage = __( 'Backup begun', 'backupplus' );
				break;
			case 'pitrcreating':
				$stage    = 2;
				$curstage = __( 'Capturing database recovery point', 'backupplus' );
				break;
			// Stage 1
			case 'filescreating':
				$stage    = 1;
				$curstage = __( 'Creating file backup zips', 'backupplus' );
				if ( ! empty( $jobdata['filecreating_substatus'] ) && isset( $backupable_entities[ $jobdata['filecreating_substatus']['e'] ]['description'] ) ) {

					$sdescrip = preg_replace( '/ \(.*\)$/', '', $backupable_entities[ $jobdata['filecreating_substatus']['e'] ]['description'] );
					if ( strlen( $sdescrip ) > 20 && isset( $jobdata['filecreating_substatus']['e'] ) && is_array( $jobdata['filecreating_substatus']['e'] ) && isset( $backupable_entities[ $jobdata['filecreating_substatus']['e'] ]['shortdescription'] ) ) {
						$sdescrip = $backupable_entities[ $jobdata['filecreating_substatus']['e'] ]['shortdescription'];
					}
					$curstage .= ' (' . $sdescrip . ')';
					if ( isset( $jobdata['filecreating_substatus']['i'] ) && isset( $jobdata['filecreating_substatus']['t'] ) ) {
						$stage = min( 2, 1 + ( $jobdata['filecreating_substatus']['i'] / max( $jobdata['filecreating_substatus']['t'], 1 ) ) );
					}
				}
				break;
			case 'filescreated':
				$stage    = 2;
				$curstage = __( 'Created file backup zips', 'backupplus' );
				break;
			// Stage 4
			case 'partialclouduploading':
			case 'clouduploading':
				$stage    = 'clouduploading' == $jobstatus ? 4 : 2;
				$curstage = __( 'Uploading files to remote storage', 'backupplus' );
				if ( isset( $jobdata['uploading_substatus']['t'] ) && isset( $jobdata['uploading_substatus']['i'] ) ) {
					$t     = max( (int) $jobdata['uploading_substatus']['t'], 1 );
					$i     = min( $jobdata['uploading_substatus']['i'] / $t, 1 );
					$p     = min( $jobdata['uploading_substatus']['p'], 1 );
					$pd    = $i + $p / $t;
					$stage = 'clouduploading' == $jobstatus ? $stage + $pd : $stage;
					/* translators: 1: File number, 2: Total files */
					$curstage .= ' (' . floor( 100 * $pd ) . '%, ' . sprintf( __( 'file %1$d of %2$d', 'backupplus' ), (int) $jobdata['uploading_substatus']['i'] + 1, $t ) . ')';
				}
				break;
			case 'pruning':
				$stage    = 5;
				$curstage = __( 'Pruning old backup sets', 'backupplus' );
				break;
			case 'resumingforerrors':
				$stage    = -1;
				$curstage = __( 'Waiting until scheduled time to retry because of errors', 'backupplus' );
				break;
			// Stage 6
			case 'finished':
				$stage    = 6;
				$curstage = ! empty( $jobdata['pitr_error'] ) ? sprintf( __( 'Database recovery point failed: %s', 'backupplus' ), $jobdata['pitr_error'] ) : __( 'Backup finished', 'backupplus' );
				break;
			default:
				// Database creation and encryption occupies the space from 2 to 4. Databases are created then encrypted, then the next database is created/encrypted, etc.
				if ( 'dbcreated' == substr( $jobstatus, 0, 9 ) ) {
					$jobstatus = 'dbcreated';
					$whichdb   = substr( $jobstatus, 9 );
					if ( ! is_numeric( $whichdb ) ) {
						$whichdb = 0;
					}
					$howmanydbs = max( ( empty( $jobdata['backup_database'] ) || ! is_array( $jobdata['backup_database'] ) ) ? 1 : count( $jobdata['backup_database'] ), 1 );
					$perdbspace = 2 / $howmanydbs;

					$stage = min( 4, 2 + ( $whichdb + 2 ) * $perdbspace );

					$curstage = __( 'Created database backup', 'backupplus' );

				} elseif ( 'dbcreating' == substr( $jobstatus, 0, 10 ) ) {
					$whichdb = substr( $jobstatus, 10 );
					if ( ! is_numeric( $whichdb ) ) {
						$whichdb = 0;
					}
					$howmanydbs = ( empty( $jobdata['backup_database'] ) || ! is_array( $jobdata['backup_database'] ) ) ? 1 : count( $jobdata['backup_database'] );
					$perdbspace = 2 / $howmanydbs;
					$jobstatus  = 'dbcreating';

					$stage = min( 4, 2 + $whichdb * $perdbspace );

					$curstage = __( 'Creating database backup', 'backupplus' );
					if ( ! empty( $jobdata['dbcreating_substatus']['t'] ) ) {
						/* translators: %s: Table name */
						$curstage .= ' (' . sprintf( __( 'table: %s', 'backupplus' ), $jobdata['dbcreating_substatus']['t'] ) . ')';
						if ( ! empty( $jobdata['dbcreating_substatus']['i'] ) && ! empty( $jobdata['dbcreating_substatus']['a'] ) ) {
							$substage = max( 0.001, ( $jobdata['dbcreating_substatus']['i'] / max( $jobdata['dbcreating_substatus']['a'], 1 ) ) );
							$stage   += $substage * $perdbspace * 0.5;
						}
					}
				} elseif ( 'dbencrypting' == substr( $jobstatus, 0, 12 ) ) {
					$whichdb = substr( $jobstatus, 12 );
					if ( ! is_numeric( $whichdb ) ) {
						$whichdb = 0;
					}
					$howmanydbs = ( empty( $jobdata['backup_database'] ) || ! is_array( $jobdata['backup_database'] ) ) ? 1 : count( $jobdata['backup_database'] );
					$perdbspace = 2 / $howmanydbs;
					$stage      = min( 4, 2 + $whichdb * $perdbspace + $perdbspace * 0.5 );
					$jobstatus  = 'dbencrypting';
					$curstage   = __( 'Encrypting database', 'backupplus' );
				} elseif ( 'dbencrypted' == substr( $jobstatus, 0, 11 ) ) {
					$whichdb = substr( $jobstatus, 11 );
					if ( ! is_numeric( $whichdb ) ) {
						$whichdb = 0;
					}
					$howmanydbs = ( empty( $jobdata['backup_database'] ) || ! is_array( $jobdata['backup_database'] ) ) ? 1 : count( $jobdata['backup_database'] );
					$jobstatus  = 'dbencrypted';
					$perdbspace = 2 / $howmanydbs;
					$stage      = min( 4, 2 + $whichdb * $perdbspace + $perdbspace );
					$curstage   = __( 'Encrypted database', 'backupplus' );
				} else {
					$curstage = __( 'Unknown', 'backupplus' );
				}
		}

		$runs_started     = empty( $jobdata['runs_started'] ) ? array() : $jobdata['runs_started'];
		$time_passed      = empty( $jobdata['run_times'] ) ? array() : $jobdata['run_times'];
		$last_checkin_ago = -1;
		if ( is_array( $time_passed ) ) {
			foreach ( $time_passed as $run => $passed ) {
				if ( isset( $runs_started[ $run ] ) ) {
					$time_ago = microtime( true ) - ( $runs_started[ $run ] + $time_passed[ $run ] );
					if ( $time_ago < $last_checkin_ago || -1 == $last_checkin_ago ) {
						$last_checkin_ago = $time_ago;
					}
				}
			}
		}

		$next_res_after = (int) $time - time();
		if ( $is_oneshot ) {
			$next_res_txt = '';
		} else {
			/* translators: %d: Resumption count */
			$next_res_txt = sprintf( __( 'next resumption: %d', 'backupplus' ), $next_resumption ) .
							/* translators: %s: Seconds until resumption */
							( $next_resumption ? ' ' . sprintf( __( '(after %ss)', 'backupplus' ), $next_res_after ) : '' ) . ' ';
		}

		/* translators: %s: Last activity */
		$last_activity_txt = ( $last_checkin_ago >= 0 ) ? sprintf( __( 'last activity: %ss ago', 'backupplus' ), floor( $last_checkin_ago ) ) . ' ' : '';

		if ( ( $last_checkin_ago < 50 && $next_res_after > 30 ) || $is_oneshot ) {
			$show_inline_info = $last_activity_txt;
			$title_info       = $next_res_txt;
		} else {
			$show_inline_info = $next_res_txt;
			$title_info       = $last_activity_txt;
		}

		$ret .= '<div class="backupplus_row">';

		$ret .= '<div class="backupplus_col"><div class="backupplus_jobtimings next-resumption"';

		if ( ! empty( $jobdata['is_autobackup'] ) ) {
			$ret .= ' isautobackup';
		}

		/* translators: %s: Job ID */
		$ret .= ' data-jobid="' . $job_id . '" data-lastactivity="' . (int) $last_checkin_ago . '" data-nextresumption="' . $next_resumption . '" data-nextresumptionafter="' . $next_res_after . '" title="' . esc_attr( sprintf( __( 'Job ID: %s', 'backupplus' ), $job_id ) ) . $title_info . '">' . ( ! empty( $backup_label ) ? esc_html( $backup_label ) : $began_at ) .
		'</div></div>';

		$ret .= '<div class="backupplus_col backupplus_progress_container">';
			// Existence of the 'backupplus-jobid-(id)' id is checked for in other places, so do not modify this
			$ret .= '<div class="job-id" id="backupplus-jobid-' . esc_attr( $job_id ) . '">';

			$ret .= apply_filters( 'backupplus_printjob_beforewarnings', '', $jobdata, $job_id );

		if ( ! empty( $jobdata['warnings'] ) && is_array( $jobdata['warnings'] ) ) {
			$ret .= '<ul class="disc">';
			foreach ( $jobdata['warnings'] as $warning ) {
				/* translators: %s: Warning message */
				$ret .= '<li>' . sprintf( __( 'Warning: %s', 'backupplus' ), make_clickable( htmlspecialchars( $warning ) ) ) . '</li>';
			}
			$ret .= '</ul>';
		}

			$ret .= '<div class="curstage">';
			// $ret .= '<span class="curstage-info">'.htmlspecialchars($curstage).'</span>';
			$ret .= htmlspecialchars( $curstage );
			// we need to add this data-progress attribute in order to be able to update the progress bar in UDC

			$ret .= '<div class="backupplus_percentage" data-info="' . esc_attr( $curstage ) . '" data-progress="' . ( ( $stage > 0 ) ? ( ceil( ( 100 / 6 ) * $stage ) ) : '0' ) . '" style="height: 100%; width:' . ( ( $stage > 0 ) ? ( ceil( ( 100 / 6 ) * $stage ) ) : '0' ) . '%"></div>';
			$ret .= '</div></div>';

			$ret .= '<div class="backupplus_last_activity">';

			$ret .= $show_inline_info;
		if ( ! empty( $show_inline_info ) ) {
			$ret .= ' - ';
		}

			$file_nonce = empty( $jobdata['file_nonce'] ) ? $job_id : $jobdata['file_nonce'];

			$ret .= '<a data-fileid="' . $file_nonce . '" data-jobid="' . $job_id . '" href="' . BackupPlus_Options::admin_page_url() . '?page=backupplus&action=downloadlog&backupplus_backup_nonce=' . $file_nonce . '" class="backupplus-log-link">' . __( 'show log', 'backupplus' ) . '</a>';
		if ( ! $is_oneshot ) {
			$ret .= ' - <a href="#" data-jobid="' . $job_id . '" title="' . esc_attr( __( 'Note: the progress bar below is based on stages, NOT time.', 'backupplus' ) . ' ' . __( 'Do not stop the backup simply because it seems to have remained in the same place for a while - that is normal.', 'backupplus' ) ) . '" class="backupplus_jobinfo_delete">' . __( 'stop', 'backupplus' ) . '</a>';
		}
			$ret .= '</div>';

		$ret .= '</div></div>';

		return $ret;
	}
}
