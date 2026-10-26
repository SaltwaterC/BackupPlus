<?php

/** Backups: starting them, running jobs and their log, and existing backup sets (listing, downloading, locking, deleting, rescanning). */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Commands_Backups {

	public function get_download_status( $items ) {
		// load global backupplus and admin
		if ( false === ( $backupplus_admin = $this->_load_ud_admin() ) ) {
			return new WP_Error( 'no_backupplus' );
		}

		if ( ! BackupPlus_Options::user_can_manage() ) {
			return new WP_Error( 'backupplus_permission_denied' );
		}

		if ( ! is_array( $items ) ) {
			$items = array();
		}

		return $backupplus_admin->get_download_statuses( $items );
	}

	/**
	 * Begin a download process
	 *
	 * @param Array $downloader_params - download parameters (findex, type, timestamp, stage)
	 *
	 * @return Array - as from BackupPlus_Admin::do_backupplus_download_backup() (with 'request' key added, with value $downloader_params)
	 */
	public function downloader( $downloader_params ) {

		if ( false === ( $backupplus_admin = $this->_load_ud_admin() ) ) {
			return new WP_Error( 'no_backupplus' );
		}

		if ( ! BackupPlus_Options::user_can_manage() ) {
			return new WP_Error( 'backupplus_permission_denied' );
		}

		$findex    = $downloader_params['findex'];
		$type      = $downloader_params['type'];
		$timestamp = $downloader_params['timestamp'];
		// Valid stages: 2='spool the data'|'delete'='delete local copy'|anything else='make sure it is present'
		$stage = empty( $downloader_params['stage'] ) ? false : $downloader_params['stage'];

		// This may, or may not, return, depending upon whether the files are already downloaded
		// The response is usually an array with key 'result', and values deleted|downloaded|needs_download|download_failed
		$response = $backupplus_admin->do_backupplus_download_backup( $findex, $type, $timestamp, $stage, array( $this->_uc_helper, '_backupplus_background_operation_started' ) );

		if ( is_array( $response ) ) {
			$response['request'] = $downloader_params;
		}

		return $response;
	}

	public function delete_downloaded( $set_info ) {
		$set_info['stage'] = 'delete';
		return $this->downloader( $set_info );
	}

	/**
	 * Get backup progress (as HTML) for a particular backup
	 *
	 * @param Array $params - should have a key 'job_id' with corresponding value
	 *
	 * @return String - the HTML
	 */
	public function backup_progress( $params ) {

		if ( false === ( $backupplus_admin = $this->_load_ud_admin() ) ) {
			return new WP_Error( 'no_backupplus' );
		}

		if ( ! BackupPlus_Options::user_can_manage() ) {
			return new WP_Error( 'backupplus_permission_denied' );
		}

		$request         = array(
			'thisjobonly' => $params['job_id'],
		);
		$activejobs_list = $backupplus_admin->get_activejobs_list( $request );

		return $activejobs_list;
	}

	/**
	 * Perform a backup
	 *
	 * @param Array $params
	 *
	 * @uses die()
	 *
	 * @return WP_Error - or, may die()
	 */
	public function backupnow( $params ) {

		if ( false === ( $backupplus_admin = $this->_load_ud_admin() ) || false === ( $backupplus = $this->_load_ud() ) ) {
			return new WP_Error( 'no_backupplus' );
		}

		if ( ! BackupPlus_Options::user_can_manage() ) {
			return new WP_Error( 'backupplus_permission_denied' );
		}

		$background_operation_started_method_name = empty( $params['background_operation_started_method_name'] ) ? '_backupplus_background_operation_started' : $params['background_operation_started_method_name'];
		$result                                   = $backupplus_admin->request_backupnow( $params, array( $this->_uc_helper, $background_operation_started_method_name ) );
		if ( is_wp_error( $result ) ) { return $result; }

		// Control returns when the backup finished; but, the browser connection should have been closed before
		die;
	}

	/**
	 * Mark a backup as "do not delete"
	 *
	 * @param array $params this is an array of parameters sent via ajax it can include the following:
	 * backup_key - Integer - backup timestamp
	 * always_keep - Boolean - "Always keep" value
	 * @return array which contains rawbackup html
	 */
	public function always_keep_this_backup( $params ) {
		if ( false === ( $backupplus_admin = $this->_load_ud_admin() ) ) {
			return new WP_Error( 'no_backupplus' );
		}
		$backup_key = $params['backup_key'];
		$result     = BackupPlus_Backup_History::set_always_keep( array( $backup_key ), ! empty( $params['always_keep'] ) );
		if ( $result['errors'] ) { return new WP_Error( 'always_keep_failed', reset( $result['errors'] ) ); }
		$backup_history = BackupPlus_Backup_History::get_history();
		$nonce          = $backup_history[ $backup_key ]['nonce'];
		$rawbackup      = $backupplus_admin->raw_backup_info( $backup_history, $backup_key, $nonce );
		return array(
			'rawbackup' => html_entity_decode( $rawbackup ),
		);
	}

	/**
	 * Lock or unlock several backups at once so that retention skips (or stops skipping) them
	 *
	 * @param array $params this is an array of parameters sent via ajax it can include the following:
	 * backup_keys - String - comma-separated backup timestamps
	 * always_keep - Boolean - "Always keep" value
	 * @return array with 'locked' (timestamp => new state) and 'errors' (timestamp => message)
	 */
	public function always_keep_backups( $params ) {
		$keys = isset( $params['backup_keys'] ) ? array_filter( explode( ',', (string) $params['backup_keys'] ) ) : array();
		foreach ( $keys as $key ) {
			if ( ! ctype_digit( $key ) ) { return new WP_Error( 'invalid_backup_key', 'Invalid backup key.' ); }
		}
		if ( ! $keys ) { return new WP_Error( 'no_backups_selected', 'No backups selected.' ); }
		return BackupPlus_Backup_History::set_always_keep( $keys, ! empty( $params['always_keep'] ) );
	}

	/**
	 * Function to retrieve raw backup history given a timestamp and nonce
	 *
	 * @param Array $data - Data parameter; keys: timestamp, nonce
	 *
	 * @return Array - An array that contains the raw backup history
	 */
	public function rawbackup_history( $data ) {
		if ( false === ( $backupplus_admin = $this->_load_ud_admin() ) ) {
			return new WP_Error( 'no_backupplus' );
		}

		$history = BackupPlus_Backup_History::get_history();

		$rawbackup = $backupplus_admin->raw_backup_info( $history, $data['timestamp'], $data['nonce'], null );

		return array(
			'rawbackup' => html_entity_decode( $rawbackup ),
		);
	}

	/**
	 * Function to retrieve list of existing backups with all of their data.
	 *
	 * @return Array - Array of existing backup data.
	 */
	public function get_existing_backups_data() {
		if ( false === ( $backupplus_admin = $this->_load_ud_admin() ) || false === $this->_load_ud() ) {
			return new WP_Error( 'no_backupplus' );
		}
		$history = BackupPlus_Backup_History::get_history();

		return array(
			'history'       => $history,
			'download_data' => $backupplus_admin->get_download_buttons_data( $history ),
		);
	}

	public function get_log( $job_id = '' ) {

		if ( false === ( $backupplus_admin = $this->_load_ud_admin() ) ) {
			return new WP_Error( 'no_backupplus' );
		}

		if ( ! BackupPlus_Options::user_can_manage() ) {
			return new WP_Error( 'backupplus_permission_denied' );
		}

		if ( '' != $job_id && ! preg_match( '/^[0-9a-f]{12}$/', $job_id ) ) {
			return new WP_Error( 'backupplus_permission_invalid_jobid' );
		}

		return $backupplus_admin->fetch_log( $job_id );
	}

	public function activejobs_delete( $job_id ) {

		if ( false === ( $backupplus_admin = $this->_load_ud_admin() ) ) {
			return new WP_Error( 'no_backupplus' );
		}

		if ( ! BackupPlus_Options::user_can_manage() ) {
			return new WP_Error( 'backupplus_permission_denied' );
		}

		return $backupplus_admin->activejobs_delete( (string) $job_id );
	}

	public function deleteset( $what ) {

		if ( false === ( $backupplus_admin = $this->_load_ud_admin() ) || false === ( $backupplus = $this->_load_ud() ) ) {
			return new WP_Error( 'no_backupplus' );// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Unused variable is for future use.
		}
		if ( ! BackupPlus_Options::user_can_manage() ) {
			return new WP_Error( 'backupplus_permission_denied' );
		}

		$results = $backupplus_admin->delete_set( $what );

		$get_history_opts = isset( $what['get_history_opts'] ) ? $what['get_history_opts'] : array();

		$backup_history = BackupPlus_Backup_History::get_history();

		$results['history'] = $backupplus_admin->settings_downloading_and_restoring( $backup_history, true, $get_history_opts );

		$results['backupnow_file_entities'] = apply_filters( 'backupplus_backupnow_file_entities', array() );
		$results['modal_afterfileoptions']  = apply_filters( 'backupplus_backupnow_modal_afterfileoptions', '', '' );

		$results['count_backups'] = count( $backup_history );

		return $results;
	}

	/**
	 * Slightly misnamed - this doesn't always rescan, but it does always return the history status (possibly after a rescan)
	 *
	 * @param  Array|String $data - with keys 'operation' and 'debug'; or, if a string (backwards compatibility), just the value of the 'operation' key (with debug assumed as 0)
	 *
	 * @return Array - returns an array of history statuses
	 */
	public function rescan( $data ) {

		if ( false === ( $backupplus_admin = $this->_load_ud_admin() ) ) {
			return new WP_Error( 'no_backupplus' );
		}

		if ( ! BackupPlus_Options::user_can_manage() ) {
			return new WP_Error( 'backupplus_permission_denied' );
		}

		$backup_count = 0;

		if ( is_array( $data ) ) {
			$operation    = empty( $data['operation'] ) ? '' : $data['operation'];
			$debug        = ! empty( $data['debug'] );
			$backup_count = empty( $data['backup_count'] ) ? 0 : $data['backup_count'];
		} else {
			$operation = $data;
			$debug     = false;
		}

		$remotescan = ( 'remotescan' == $operation );
		$rescan     = ( $remotescan || 'rescan' == $operation );

		$history_status                            = $backupplus_admin->get_history_status( $rescan, $remotescan, $debug, $backup_count );
		$history_status['backupnow_file_entities'] = apply_filters( 'backupplus_backupnow_file_entities', array() );
		$history_status['modal_afterfileoptions']  = apply_filters( 'backupplus_backupnow_modal_afterfileoptions', '', '' );

		return $history_status;
	}

	/**
	 * Display raw backup and file list
	 *
	 * @return string
	 */
	public function show_raw_backup_and_file_list() {
		if ( false === ( $backupplus_admin = $this->_load_ud_admin() ) ) {
			return new WP_Error( 'no_backupplus' );
		}

		/*
			Need to remove the pre tags as the modal assumes a <pre> is for a new box.
			This cause issues specifically with fetch log events. Do this by passing true
			to the method show_raw_backups
		 */

		$response = $backupplus_admin->show_raw_backups( true );

		return $response['html'];
	}

	/**
	 * This function will add some needed filters in order to be able to send a local backup to remote storage it will then boot the backup process.
	 *
	 * @param array $data - data sent from the front end, it includes the backup timestamp and nonce
	 *
	 * @return array      - the response to be sent back to the front end
	 */
	public function upload_local_backup( $data ) {
		if ( false === ( $backupplus_admin = $this->_load_ud_admin() ) || false === ( $backupplus = $this->_load_ud() ) ) {
			return new WP_Error( 'no_backupplus' );
		}

		add_filter( 'backupplus_initial_jobdata', array( $backupplus_admin, 'upload_local_backup_jobdata' ), 10, 3 );
		add_filter( 'backupplus_get_backup_file_basename_from_time', array( $backupplus_admin, 'upload_local_backup_name' ), 10, 3 );

		$background_operation_started_method_name = empty( $data['background_operation_started_method_name'] ) ? '_backupplus_background_operation_started' : $data['background_operation_started_method_name'];

		$msg = array(
			'nonce' => $data['use_nonce'],
			'm'     => apply_filters( 'backupplus_backupnow_start_message', '<strong>' . __( 'Start backup', 'backupplus' ) . ':</strong> ' . htmlspecialchars( __( 'OK.', 'backupplus' ) . ' ' . __( 'You should soon see activity in the "Last log message" field below.', 'backupplus' ) ), $data['use_nonce'] ),
		);

		$close_connection_callable = array( $this->_uc_helper, $background_operation_started_method_name );

		if ( is_callable( $close_connection_callable ) ) {
			call_user_func( $close_connection_callable, $msg );
		} else {
			$backupplus->close_browser_connection( json_encode( $msg ) );
		}

		do_action( 'backupplus_backupnow_backup_all', apply_filters( 'backupplus_backupnow_options', $data, array() ) );

		// Control returns when the backup finished; but, the browser connection should have been closed before
		die;
	}

	/**
	 * This function will get the restore resume notice
	 *
	 * @param array $params - the parameters for the call
	 *
	 * @return array|WP_Error - the response array that includes the restore resume notice
	 */
	public function get_restore_resume_notice( $params ) {
		if ( false === ( $backupplus_admin = $this->_load_ud_admin() ) || false === ( $backupplus = $this->_load_ud() ) ) {
			return new WP_Error( 'no_backupplus' );// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Unused variable is for future use.
		}       if ( ! BackupPlus_Options::user_can_manage() ) {
			return new WP_Error( 'backupplus_permission_denied' );
		}

		$job_id = empty( $params['job_id'] ) ? '' : $params['job_id'];

		$response = array(
			'status' => 'success',
		);

		if ( empty( $job_id ) ) {
			return new WP_Error( 'missing_parameter', 'Missing parameters.' );
		}

		$html = $backupplus_admin->get_restore_resume_notice( $job_id );

		if ( is_wp_error( $html ) ) {
			return $html;
		}

		$response['html'] = $html;

		return $response;
	}
}
