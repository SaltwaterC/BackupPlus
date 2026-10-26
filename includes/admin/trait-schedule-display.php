<?php

/** Next-run and last-backup summaries shown in the admin UI. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Admin_Schedule_Display {

	/**
	 * Used to output the information for the next scheduled backup.
	 * moved to function for the ajax saves
	 */
	public function next_scheduled_backups_output() {
		// UNIX timestamp
		$next_scheduled_backup = wp_next_scheduled( 'backupplus_backup' );
		if ( $next_scheduled_backup ) {
			// Convert to GMT
			$next_scheduled_backup_gmt = gmdate( 'Y-m-d H:i:s', $next_scheduled_backup );
			// Convert to blog time zone
			$next_scheduled_backup = get_date_from_gmt( $next_scheduled_backup_gmt, 'D, F j, Y H:i' );
			// $next_scheduled_backup = date_i18n('D, F j, Y H:i', $next_scheduled_backup);
		} else {
			$next_scheduled_backup = __( 'Nothing currently scheduled', 'backupplus' );
			$files_not_scheduled   = true;
		}

		$next_scheduled_backup_database = wp_next_scheduled( 'backupplus_backup_database' );
		if ( BackupPlus_Options::get_backupplus_option( 'backupplus_interval_database', BackupPlus_Options::get_backupplus_option( 'backupplus_interval' ) ) == BackupPlus_Options::get_backupplus_option( 'backupplus_interval' ) && BackupPlus_Backup_Schedule::instance()->same_schedule() ) {
			if ( isset( $files_not_scheduled ) ) {
				$next_scheduled_backup_database = $next_scheduled_backup;
				$database_not_scheduled         = true;
			} else {
				$next_scheduled_backup_database           = __( 'At the same time as the files backup', 'backupplus' );
				$next_scheduled_backup_database_same_time = true;
			}
		} else {
			if ( $next_scheduled_backup_database ) {
				// Convert to GMT
				$next_scheduled_backup_database_gmt = gmdate( 'Y-m-d H:i:s', $next_scheduled_backup_database );
				// Convert to blog time zone
				$next_scheduled_backup_database = get_date_from_gmt( $next_scheduled_backup_database_gmt, 'D, F j, Y H:i' );
				// $next_scheduled_backup_database = date_i18n('D, F j, Y H:i', $next_scheduled_backup_database);
			} else {
				$next_scheduled_backup_database = __( 'Nothing currently scheduled', 'backupplus' );
				$database_not_scheduled         = true;
			}
		}

		if ( isset( $files_not_scheduled ) && isset( $database_not_scheduled ) ) {
			?>
			<span class="not-scheduled"><?php esc_html_e( 'Nothing currently scheduled', 'backupplus' ); ?></span>
			<?php
		} else {
			echo empty( $next_scheduled_backup_database_same_time ) ? esc_html__( 'Files', 'backupplus' ) : esc_html__( 'Files and database', 'backupplus' );
			?>
			: 
			<span class="backupplus_all-files">
				<?php
					echo esc_html( $next_scheduled_backup );
				?>
			</span>
			<?php
			if ( empty( $next_scheduled_backup_database_same_time ) ) {
				esc_html_e( 'Database', 'backupplus' );
				?>
			: 
			<span class="backupplus_all-files">
				<?php
				echo esc_html( $next_scheduled_backup_database );
				?>
			</span>
				<?php
			}
		}
	}

	/**
	 * Used to output the information for the next scheduled  file backup.
	 * moved to function for the ajax saves
	 *
	 * @param Boolean $return_instead_of_echo Whether to return or echo the results. N.B. More than just the results to echo will be returned
	 * @return Void|String If $return_instead_of_echo parameter is true, It returns html string
	 */
	public function next_scheduled_files_backups_output( $return_instead_of_echo = false ) {
		if ( $return_instead_of_echo ) {
			ob_start();
		}
		// UNIX timestamp
		$next_scheduled_backup = wp_next_scheduled( 'backupplus_backup' );
		if ( $next_scheduled_backup ) {
			// Convert to blog time zone. wp_date() (WP 5.3+) also performs locale translation.
			$next_scheduled_backup = function_exists( 'wp_date' ) ? wp_date( 'D, F j, Y H:i', $next_scheduled_backup ) : get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_scheduled_backup ), 'D, F j, Y H:i' );
			$files_not_scheduled   = false;
		} else {
			$next_scheduled_backup = __( 'Nothing currently scheduled', 'backupplus' );
			$files_not_scheduled   = true;
		}

		if ( $files_not_scheduled ) {
			echo '<span>' . esc_html( $next_scheduled_backup ) . '</span>';
		} else {
			echo '<span class="backupplus_next_scheduled_date_time">' . esc_html( $next_scheduled_backup ) . '</span>';
		}

		if ( $return_instead_of_echo ) {
			return ob_get_clean();
		}
	}

	/**
	 * Used to output the information for the next scheduled incremental backup.
	 *
	 * @param Boolean $return_instead_of_echo Whether to return or echo the results
	 * @return Void|String If $return_instead_of_echo parameter is true, It returns html string
	 */
	public function next_scheduled_incremental_backups_output( $return_instead_of_echo = false ) {
		if ( $return_instead_of_echo ) {
			ob_start();
		}
		$next_increment = wp_next_scheduled( 'backupplus_backup_increments' );
		if ( $next_increment ) {
			$increment_date = function_exists( 'wp_date' ) ? wp_date( 'D, F j, Y H:i', $next_increment ) : get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_increment ), 'D, F j, Y H:i' );
			echo '<span class="backupplus_next_scheduled_date_time">' . esc_html( $increment_date ) . '</span>';
		} else {
			echo '<span>' . esc_html__( 'Nothing currently scheduled', 'backupplus' ) . '</span>';
		}

		if ( $return_instead_of_echo ) {
			return ob_get_clean();
		}
	}

	/** Render the next scheduled PITR worker using the same date style as other backups. */
	public function next_scheduled_pitr_backups_output( $return_instead_of_echo = false ) {
		$config = BackupPlus_PITR::config();
		$next   = empty( $config['enabled'] ) ? false : wp_next_scheduled( BackupPlus_PITR::HOOK );
		if ( $next ) {
			$date = function_exists( 'wp_date' ) ? wp_date( 'D, F j, Y H:i', $next ) : get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ), 'D, F j, Y H:i' );
			$html = '<span class="backupplus_next_scheduled_date_time">' . esc_html( $date ) . '</span>';
		} else { $html = '<span>' . esc_html__( 'Nothing currently scheduled', 'backupplus' ) . '</span>'; }
		if ( $return_instead_of_echo ) { return $html; }
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Date and fallback text are escaped above.
	}

	/**
	 * Used to output the information for the next scheduled database backup.
	 * moved to function for the ajax saves
	 *
	 * @param Boolean $return_instead_of_echo Whether to return or echo the results. N.B. More than just the results to echo will be returned
	 * @return Void|String If $return_instead_of_echo parameter is true, It returns html string
	 */
	public function next_scheduled_database_backups_output( $return_instead_of_echo = false ) {
		if ( $return_instead_of_echo ) {
			ob_start();
		}

		$next_scheduled_backup_database = wp_next_scheduled( 'backupplus_backup_database' );
		if ( $next_scheduled_backup_database && wp_next_scheduled( 'backupplus_backup' ) && BackupPlus_Backup_Schedule::instance()->same_schedule() ) {
			$next_scheduled_backup_database = __( 'At the same time as the files backup', 'backupplus' );
			$database_not_scheduled         = true;
		} elseif ( $next_scheduled_backup_database ) {
			// Convert to GMT
			$next_scheduled_backup_database_gmt = gmdate( 'Y-m-d H:i:s', $next_scheduled_backup_database );
			// Convert to blog time zone. wp_date() (WP 5.3+) also performs locale translation.
			$next_scheduled_backup_database = function_exists( 'wp_date' ) ? wp_date( 'D, F j, Y H:i', $next_scheduled_backup_database ) : get_date_from_gmt( $next_scheduled_backup_database_gmt, 'D, F j, Y H:i' );
			$database_not_scheduled         = false;
		} else {
			$next_scheduled_backup_database = __( 'Nothing currently scheduled', 'backupplus' );
			$database_not_scheduled         = true;
		}

		if ( $database_not_scheduled ) {
			echo '<span>' . esc_html( $next_scheduled_backup_database ) . '</span>';
		} else {
			echo '<span class="backupplus_next_scheduled_date_time">' . esc_html( $next_scheduled_backup_database ) . '</span>';
		}

		if ( $return_instead_of_echo ) {
			return ob_get_clean();
		}
	}

	/**
	 * Get HTML suitable for the admin area for the status of the last backup
	 *
	 * @return String
	 */
	public function last_backup_html() {

		global $backupplus;

		$backupplus_last_backup = BackupPlus_Options::get_backupplus_option( 'backupplus_last_backup' );

		if ( $backupplus_last_backup ) {

			// Convert to GMT, then to blog time
			$backup_time = (int) $backupplus_last_backup['backup_time'];

			$print_time = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $backup_time ), 'D, F j, Y H:i' );

			if ( empty( $backupplus_last_backup['backup_time_incremental'] ) ) {
				$last_backup_text = '<span style="color:' . ( ( $backupplus_last_backup['success'] ) ? 'green' : 'black' ) . ';">' . $print_time . '</span>';
			} else {
				$inc_time = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $backupplus_last_backup['backup_time_incremental'] ), 'D, F j, Y H:i' );
				/* translators: %s: Time of backup. */
				$last_backup_text = '<span style="color:' . ( ( $backupplus_last_backup['success'] ) ? 'green' : 'black' ) . ";\">$inc_time</span> (" . sprintf( __( 'incremental backup; base backup: %s', 'backupplus' ), $print_time ) . ')';
			}

			$last_backup_text .= '<br>';

			// Show errors + warnings
			if ( is_array( $backupplus_last_backup['errors'] ) ) {
				foreach ( $backupplus_last_backup['errors'] as $err ) {
					$level             = ( is_array( $err ) ) ? $err['level'] : 'error';
					$message           = ( is_array( $err ) ) ? $err['message'] : $err;
					$last_backup_text .= ( 'warning' == $level ) ? '<span style="color:orange;">' : '<span style="color:red;">';
					if ( 'warning' == $level ) {
						/* translators: %s: Warning message. */
						$message = sprintf( __( 'Warning: %s', 'backupplus' ), make_clickable( htmlspecialchars( $message ) ) );
					} else {
						$message = htmlspecialchars( $message );
					}
					$last_backup_text .= $message;
					$last_backup_text .= '</span><br>';
				}
			}

			// Link log
			if ( ! empty( $backupplus_last_backup['backup_nonce'] ) ) {
				$backupplus_dir = $backupplus->backups_dir_location();

				$potential_log_file = $backupplus_dir . '/log.' . $backupplus_last_backup['backup_nonce'] . '.txt';
				if ( is_readable( $potential_log_file ) ) {
					$last_backup_text .= '<a href="?page=backupplus&action=downloadlog&backupplus_backup_nonce=' . $backupplus_last_backup['backup_nonce'] . "\" class=\"backupplus-log-link\" onclick=\"event.preventDefault(); backupplus_popuplog('" . $backupplus_last_backup['backup_nonce'] . "');\">" . __( 'Download log file', 'backupplus' ) . '</a>';
				}
			}
		} else {
			$last_backup_text = '<span style="color:blue;">' . __( 'No backup has been completed', 'backupplus' ) . '</span>';
		}

		return $last_backup_text;
	}

	/**
	 * Get a list of backup intervals
	 *
	 * @param String $what_for - 'files' or 'db'
	 *
	 * @return Array - keys are used as identifiers in the UI drop-down; values are user-displayed text describing the interval
	 */
	public function get_intervals( $what_for = 'db' ) {
		global $backupplus;

		$intervals = wp_get_schedules();

		if ( $backupplus->is_restricted_hosting( 'only_one_backup_per_month' ) ) {
			$intervals = array_intersect_key( $intervals, array( 'monthly' => array() ) );
		} else {
			if ( 'db' != $what_for ) {
				unset( $intervals['everyhour'] );
			}
		}
		$intervals = array_intersect_key( backupplus_list_cron_schedules(), $intervals ); // update schedule descriptions for the UI of the backup schedule drop-down and rearrange schedule order

		foreach ( $intervals as $interval => $data ) {
			$intervals[ $interval ] = $data['display'];
		}

		$intervals = array( 'manual' => _x( 'Manual', 'i.e. Non-automatic', 'backupplus' ) ) + $intervals;

		return apply_filters( 'backupplus_backup_intervals', $intervals, $what_for );
	}
}
