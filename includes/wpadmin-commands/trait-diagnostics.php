<?php

/** Diagnostics and scheduling: ping, PHP info, database size and collation, raw backup history, cron checks and forced resumptions. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_WPAdmin_Commands_Diagnostics {

	/**
	 * Forces a resumption of a backup where the resumption is overdue (so apparently cron is not working)
	 *
	 * @param Array $info - keys 'job_id' and 'resumption'
	 *
	 * @return Array - if there is an error. Otherwise, dies.
	 */
	public function forcescheduledresumption( $info ) {

		// Casting $resumption to int is absolutely necessary, as the WP cron system uses a hashed serialisation of the parameters for identifying jobs. Different type => different hash => does not match
		$resumption = (int) $info['resumption'];
		$job_id     = $info['job_id'];
		$get_cron   = $this->_backupplus_admin->get_cron( $job_id );
		if ( ! is_array( $get_cron ) ) {
			return array( 'r' => false );
		} else {
			$this->_backupplus->log( "Forcing resumption: job id=$job_id, resumption=$resumption" );
			wp_clear_scheduled_hook( 'backupplus_backup_resume', array( $resumption, $job_id ) );
			$this->_backupplus->close_browser_connection( json_encode( array( 'r' => true ) ) );
			$this->_backupplus->jobdata_set_from_array( $get_cron[1] );
			$this->_backupplus->backup_resume( $resumption, $job_id );
			// We don't want to return. The close_browser_connection call already returned a result.
			die;
		}
	}

	/**
	 * Calls a WordPress action and dies
	 *
	 * @param Array $data - must have at least the key 'wpaction' with a string value
	 *
	 * @return WP_Error if no command was included
	 */
	public function call_wordpress_action( $data ) {

		if ( empty( $data['wpaction'] ) ) {
			return new WP_Error( 'error', '', 'no command sent' );
		}

		$response = $this->_backupplus_admin->call_wp_action( $data, array( $this->_uc_helper, '_backupplus_background_operation_started' ) );// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Unused variable is for future use.

		die;

		// return array('response' => $response['response'], 'status' => $response['status'], 'log' => $response['log'] );
	}

	/**
	 * The purpose of this is to detect brokenness caused by extra line feeds in plugins/themes - before it breaks other AJAX operations and leads to support requests
	 *
	 * @return string
	 */
	public function ping() {
		return 'pong';
	}

	public function rawbackuphistory() {
		// This is used for iframe source; hence, returns a string
		$show_raw_data = $this->_backupplus_admin->show_raw_backups();
		return $show_raw_data['html'];
	}

	/**
	 * N.B. This differs from the phpinfo output in WordPress core.
	 * Returns a string, as it is directly fetched as the source of an iframe
	 *
	 * @return String - returns the resulting HTML
	 */
	public function phpinfo() {

		ob_start();

		if ( function_exists( 'phpinfo' ) ) {
			phpinfo( INFO_ALL ^ ( INFO_CREDITS | INFO_LICENSE ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_phpinfo -- we call the phpinfo() function to display PHP information in the advanced tools.
		}
		echo '<h3 id="ud-debuginfo-constants">' . esc_html__( 'Constants', 'backupplus' ) . '</h3>';
		$opts = @get_defined_constants();// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		ksort( $opts );
		echo '<table><thead></thead><tbody>';
		foreach ( $opts as $key => $opt ) {
			// Administrators can already read these in other ways, but we err on the side of caution
			if ( is_string( $opt ) && false !== stripos( $opt, 'api_key' ) ) {
				$opt = '***';
			}
			echo '<tr><td>' . esc_html( $key ) . '</td><td>' . esc_html( print_r( $opt, true ) ) . '</td>';
		}
		echo '</tbody></table>';

		$ret = ob_get_contents();
		ob_end_clean();

		return $ret;
	}

	/**
	 * Return messages if there are more than 4 overdue cron jobs
	 *
	 * @return Array - the messages are stored in an associative array and are indexed with key 'm'
	 */
	public function check_overdue_crons() {
		$messages         = array();
		$how_many_overdue = $this->_backupplus_admin->howmany_overdue_crons();
		if ( $how_many_overdue >= 4 ) {
			$messages['m']   = array();
			$messages['m'][] = $this->_backupplus_admin->show_admin_warning_overdue_crons( $how_many_overdue );
			if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON && ( ! defined( 'BACKUPPLUS_DISABLE_WP_CRON_NOTICE' ) || ! BACKUPPLUS_DISABLE_WP_CRON_NOTICE ) ) {
				$messages['m'][] = $this->_backupplus_admin->show_admin_warning_disabledcron();
			}
		}
		return $messages;
	}

	/**
	 * When character set and collate both are unsupported at restoration time and if user change anyone substitution dropdown from both, Other substitution select box value should be change respectively. To achieve this functionality, Ajax calls comes here.
	 *
	 * @param  Array $params this is an array of parameters sent via ajax it can include the following:
	 * collate_change_on_charset_selection_data - It is data in serialize form which is need for choose other dropdown option value. It contains below elements data:
	 *  db_supported_collations - All collations supported by current database. This is result of 'SHOW COLLATION' query
	 *  db_unsupported_collate_unique - Unsupported collates unique array
	 *  db_collates_found - All collates found in database backup file
	 * event_source_elem - Dropdown elemtn id which trigger the ajax request
	 * backupplus_restorer_charset - Charset dropdown selected value option
	 * backupplus_restorer_collate - Collate dropdown selected value option
	 *
	 * @return array - $action_data which contains following data:
	 * is_action_required - 1 or 0 Whether or not change other dropdown value
	 * elem_id - Dropdown element id which value need to change. The other dropdown element id
	 * elem_val - Dropdown element value which should be selected for other drodown
	 */
	public function collate_change_on_charset_selection( $params ) {
		$collate_change_on_charset_selection_data = json_decode( BackupPlus_Manipulation_Functions::wp_unslash( $params['collate_change_on_charset_selection_data'] ), true );
		$backupplus_restorer_collate              = $params['backupplus_restorer_collate'];
		$backupplus_restorer_charset              = $params['backupplus_restorer_charset'];

		$db_supported_collations       = $collate_change_on_charset_selection_data['db_supported_collations'];
		$db_unsupported_collate_unique = $collate_change_on_charset_selection_data['db_unsupported_collate_unique'];
		$db_collates_found             = $collate_change_on_charset_selection_data['db_collates_found'];

		$action_data = array(
			'is_action_required' => 0,
		);
		// No need to change other dropdown value
		if ( isset( $db_supported_collations[ $backupplus_restorer_collate ]->Charset ) && $backupplus_restorer_charset == $db_supported_collations[ $backupplus_restorer_collate ]->Charset ) {
			return $action_data;
		}
		$similar_type_collate = $this->_backupplus->get_similar_collate_related_to_charset( $db_supported_collations, $db_unsupported_collate_unique, $backupplus_restorer_charset );
		if ( empty( $similar_type_collate ) ) {
			$similar_type_collate = $this->_backupplus->get_similar_collate_based_on_ocuurence_count( $db_collates_found, $db_supported_collations, $backupplus_restorer_collate );
		}
		// Default collation for changed character set
		if ( empty( $similar_type_collate ) ) {
			$charset_row = BackupPlus_SQL::wpdb()->row( 'SELECT DEFAULT_COLLATE_NAME AS `Default collation` FROM information_schema.CHARACTER_SETS WHERE CHARACTER_SET_NAME = ?', array(), array( $backupplus_restorer_charset ) );
			if ( is_object( $charset_row ) && ! empty( $charset_row->{'Default collation'} ) ) {
				$similar_type_collate = $charset_row->{'Default collation'};
			}
		}
		if ( empty( $similar_type_collate ) ) {
			foreach ( $db_supported_collations as $db_supported_collation => $db_supported_collation_info ) {
				if ( isset( $db_supported_collation_info->Charset ) && $backupplus_restorer_charset == $db_supported_collation_info->Charset ) {
					$similar_type_collate = $db_supported_collation;
					break;
				}
			}
		}
		if ( ! empty( $similar_type_collate ) ) {
			$action_data['is_action_required']   = 1;
			$action_data['similar_type_collate'] = $similar_type_collate;
		}
		return $action_data;
	}

	/**
	 * Return the database information
	 *
	 * @param bool $raw_data_only If true, returns array of data; if false, returns HTML
	 * @return array If $raw_data_only is true, returns array with 'tables' and 'size'. If false, returns array with 'size' and 'html'
	 */
	public function db_size( $raw_data_only = false ) {
		global $wpdb;

		$db_table_res  = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
		$db_table_size = 0;
		$db_size_info  = array();

		if ( $wpdb->num_rows > 0 ) {
			$sql = BackupPlus_SQL::wpdb();

			foreach ( $db_table_res as $row ) {
				// Try search from transient
				$rows_count = get_transient( 'wpo_' . $row['Name'] . '_count' );
				if ( false === $rows_count ) {
					// If not found, try search primary key first
					$primary_key = $sql->row( "SHOW COLUMNS FROM %i WHERE `Key` = 'PRI'", array( $row['Name'] ), array(), ARRAY_A );
					if ( $primary_key ) {
						// Count rows by primary key
						$rows_count = $sql->value( 'SELECT COUNT(%i) FROM %i', array( $primary_key['Field'], $row['Name'] ) );
					}

					if ( is_null( $rows_count ) || false === $rows_count ) {
						$rows_count = $sql->value( 'SELECT COUNT(*) FROM %i', array( $row['Name'] ) );
						// No count is reported as null, as before
						$rows_count = false === $rows_count ? null : $rows_count;
					}
				}

				$data_length  = isset( $row['Data_length'] ) ? (int) $row['Data_length'] : 0;
				$index_length = isset( $row['Index_length'] ) ? (int) $row['Index_length'] : 0;

				$db_size_info[] = array(
					'name'         => $row['Name'],
					'records'      => $rows_count,
					'data_length'  => $data_length,
					'index_length' => $index_length,
					'type'         => $row['Engine'],
				);

				$db_table_size += $data_length + $index_length;
			}
		}

		$total_size_formatted = size_format( (int) $db_table_size, 2 );

		// Return raw data if requested
		if ( $raw_data_only ) {
			return array(
				'tables' => $db_size_info,
				'size'   => $total_size_formatted,
			);
		}

		// Otherwise, construct HTML from the collected data
		$db_table_html = '';
		foreach ( $db_size_info as $table_info ) {
			$db_table_html .= '<tr>';
			$db_table_html .= sprintf( '<td>%s</td>', esc_html( $table_info['name'] ) );
			$db_table_html .= sprintf( '<td>%s</td>', esc_html( $table_info['records'] ) );
			$db_table_html .= sprintf( '<td>%s</td>', esc_html( $table_info['data_size'] ) );
			$db_table_html .= sprintf( '<td>%s</td>', esc_html( $table_info['index_size'] ) );
			$db_table_html .= sprintf( '<td>%s</td>', esc_html( $table_info['type'] ) );
			$db_table_html .= '</tr>';
		}

		return array(
			'size' => $total_size_formatted,
			'html' => $db_table_html,
		);
	}

	/**
	 * Retrieves the scheduled BackupPlus cron events.
	 *
	 * This function fetches all cron events and filters those related to BackupPlus.
	 * It then formats the schedule information, calculates the time difference,
	 * and returns a structured array of cron details.
	 *
	 * @return array[] An array of cron event details, where each event contains:
	 *                - `overdue` (int): Whether the event is overdue (1) or not (0).
	 *                - `hook` (string): The name of the cron hook.
	 *                - `name` (string): The display name of the schedule.
	 *                - `time` (string): The formatted execution time.
	 *                - `interval` (string): The time remaining or overdue duration.
	 */
	public function get_cron_events() {
		$data = array();

		$schedules = wp_get_schedules();
		$cron      = _get_cron_array();

		$date_format = get_option( 'date_format' );
		$time_format = get_option( 'time_format' );

		// Loop through the cron schedules
		foreach ( $cron as $timestamp => $cron_hooks ) {
			foreach ( $cron_hooks as $hook => $events ) {
				// Every BackupPlus hook shares this prefix, so new schedules (PITR capture, pre-update backups) are listed too.
				if ( 0 !== strpos( $hook, 'backupplus_' ) ) {
					continue;
				}

				sort( $events );

				$schedule      = $events[0]['schedule'];
				$schedule_name = isset( $schedules[ $schedule ] ) ? $schedules[ $schedule ]['display'] : __( 'Non-repeating', 'backupplus' );
				// wp_date() is available on WP 5.3+, it performs locale translation.
				$formatted_date = function_exists( 'wp_date' ) ? wp_date( $date_format . ' ' . $time_format, $timestamp ) : get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ), $date_format . ' ' . $time_format );

				$difference            = $timestamp - time();
				$difference_in_seconds = abs( $difference );
				$overdue               = $difference < 0 ? 1 : 0;

				$hours   = floor( $difference_in_seconds / 3600 );
				$minutes = floor( ( $difference_in_seconds % 3600 ) / 60 );

				if ( $overdue ) {
					/* translators: 1: Number of hours, 2: Number of minutes */
					$interval = sprintf( __( '%1$d hours and %2$d minutes ago', 'backupplus' ), $hours, $minutes );
				} else {
					/* translators: 1: Number of hours, 2: Number of minutes */
					$interval = sprintf( __( '%1$d hours and %2$d minutes', 'backupplus' ), $hours, $minutes );
				}

				$data[] = array(
					'overdue'  => $overdue,
					'hook'     => $hook,
					'name'     => $schedule_name,
					'time'     => $formatted_date,
					'interval' => $interval,
				);
			}
		}

		return $data;
	}
}
