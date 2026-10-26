<?php

/** Restore preparation, the restore screen and its in-progress notices. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Admin_Restore {

	/**
	 * Called via the ajax_restore actions to prepare the restore over AJAX
	 *
	 * @return void
	 */
	public function backupplus_ajaxrestore() {
		$request_action = BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'action' );
		$request_nonce  = BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'nonce' );
		if ( 'backupplus_ajaxrestore' === $request_action && ( empty( $request_nonce ) || ! wp_verify_nonce( $request_nonce, 'backupplus-credentialtest-nonce' ) ) ) {
			die( 'Security Check' );
		}
		$this->prepare_restore();
		die();
	}

	/**
	 * Potentially register an action for showing restore progress
	 */
	private function print_restore_in_progress_box_if_needed() {
		global $backupplus;
		$check_restore_progress = $backupplus->check_restore_progress();
		// Check to see if the restore is still in progress
		if ( is_array( $check_restore_progress ) && true == $check_restore_progress['status'] ) {

			$restore_jobdata                   = $check_restore_progress['restore_jobdata'];
			$restore_jobdata['jobid']          = $check_restore_progress['restore_in_progress'];
			$this->restore_in_progress_jobdata = $restore_jobdata;

			add_action( 'all_admin_notices', array( $this, 'show_admin_restore_in_progress_notice' ) );
			add_action( 'admin_print_footer_scripts', array( $this, 'print_unfinished_restoration_dialog_scripts' ) );
			add_action( 'admin_print_styles', array( $this, 'print_unfinished_restoration_dialog_styles' ) );
		}
	}

	/**
	 * This function is called via the command class, it will get the resume restore notice to be shown when a restore is taking place over AJAX
	 *
	 * @param string $job_id - the id of the job
	 *
	 * @return WP_Error|string - can return a string containing html or a WP_Error
	 */
	public function get_restore_resume_notice( $job_id ) {
		global $backupplus;

		if ( empty( $job_id ) ) {
			return new WP_Error( 'missing_parameter', 'Missing parameters.' );
		}

		$restore_jobdata = $backupplus->jobdata_getarray( $job_id );

		if ( ! is_array( $restore_jobdata ) || empty( $restore_jobdata ) ) {
			return new WP_Error( 'missing_jobdata', 'Job data not found.' );
		}

		$restore_jobdata['jobid']          = $job_id;
		$this->restore_in_progress_jobdata = $restore_jobdata;

		$html = $this->show_admin_restore_in_progress_notice( true );

		if ( empty( $html ) ) {
			return new WP_Error( 'job_aborted', 'Job aborted.' );
		}

		return $html;
	}

	/**
	 * If added, then runs upon the WP action all_admin_notices, or can be called via get_restore_resume_notice() for when a restore is running over AJAX
	 *
	 * @param Boolean $return_instead_of_echo - indicates if we want to add the tfa UI
	 * @param Boolean $exclude_js             - indicates if we want to exclude the js in the returned html
	 *
	 * @return void|string - can return a string containing html or echo the html to page
	 */
	public function show_admin_restore_in_progress_notice( $return_instead_of_echo = false ) {

		if ( isset( $_REQUEST['action'] ) && 'backupplus_restore_abort' === $_REQUEST['action'] && ! empty( $_REQUEST['job_id'] ) && is_string( $_REQUEST['job_id'] ) ) {

			if ( empty( $this->restore_in_progress_jobdata ) || ! is_array( $this->restore_in_progress_jobdata ) || ! isset( $this->restore_in_progress_jobdata['jobid'] ) || $this->restore_in_progress_jobdata['jobid'] != $_REQUEST['job_id'] ) {
				$html = 'Job ID not found.<br>';
			} else {
				$html = '';
				delete_site_option( 'backupplus_restore_in_progress' );
			}
			if ( $return_instead_of_echo ) {
				return $html;
			}
			echo $html;
			return;
		}

		$restore_jobdata = $this->restore_in_progress_jobdata;
		$seconds_ago     = time() - (int) $restore_jobdata['job_time_ms'];
		$minutes_ago     = floor( $seconds_ago / 60 );
		$seconds_ago     = $seconds_ago - $minutes_ago * 60;
		/* translators: 1: Minutes, 2: Seconds */
		$time_ago = sprintf( __( '%1$s minutes, %2$s seconds', 'backupplus' ), $minutes_ago, $seconds_ago );

		$html  = '<div class="updated show_admin_restore_in_progress_notice"><div class="backupplus_admin_restore_dialog">';
		$html .= '<span class="unfinished-restoration"><strong>BackupPlus: ' . __( 'Unfinished restoration', 'backupplus' ) . '</strong></span><br>';
		/* translators: %s: Time ago */
		$html .= '<p>' . sprintf( __( 'You have an unfinished restoration operation, begun %s ago.', 'backupplus' ), $time_ago ) . '</p>';
		$html .= '<form method="post" action="' . esc_url( BackupPlus_Options::admin_page_url() ) . '?page=backupplus">';
		$html .= wp_nonce_field( 'backupplus-credentialtest-nonce', 'nonce' );
		$html .= '<input id="backupplus_restore_continue_action" type="hidden" name="action" value="backupplus_restore_continue">';
		$html .= '<input type="hidden" name="backupplus_ajax_restore" value="continue_ajax_restore">';
		$html .= '<input type="hidden" name="job_id" value="' . esc_attr( $restore_jobdata['jobid'] ) . '">';
		$html .= '<button id="backupplus_restore_resume" type="submit" class="button-primary">' . __( 'Continue restoration', 'backupplus' ) . '</button>';
		$html .= '<button id="backupplus_restore_abort" class="button-secondary">' . __( 'Dismiss', 'backupplus' ) . '</button>';
		$html .= '</form></div></div>';

		if ( $return_instead_of_echo ) {
			return $html;
		}

		add_filter( 'wp_kses_allowed_html', array( $this, 'kses_allow_input_tags_on_unfinished_restoration_dialog' ) );
		echo wp_kses_post( $html );
		remove_filter( 'wp_kses_allowed_html', array( $this, 'kses_allow_input_tags_on_unfinished_restoration_dialog' ) );
	}

	/**
	 * This function starts the backupplus restore process it processes $_REQUEST
	 * (keys: backupplus_*, meta_foreign, backup_timestamp and job_id)
	 *
	 * @return void
	 */
	public function prepare_restore() {

		global $backupplus;
		$request_job_id = BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'job_id' );
		// on restore start job_id is empty but if we needed file system permissions or this is a resumption then we have already started a job so reuse it
		$restore_job_id = empty( $request_job_id ) ? false : stripslashes( $request_job_id );

		if ( false !== $restore_job_id && ! preg_match( '/^[0-9a-f]+$/', $restore_job_id ) ) {
			die( 'Invalid request (restore_job_id).' );
		}

		if ( isset( $_REQUEST['action'] ) && 'backupplus_ajaxrestore_continue' === $_REQUEST['action'] ) { // unlike backupplus_ajaxrestore which requires nonce to start a new restoration, backupplus_ajaxrestore_continue doesn't require nonces at all so additional checks are required
			$restore_in_progress = get_site_option( 'backupplus_restore_in_progress' );
			if ( empty( $restore_in_progress ) || ! $restore_job_id || $restore_job_id !== $restore_in_progress ) {
				die; // continuation requires a job ID, and if it's not presented then just abort without showing anything
			}
		}

		// Set up nonces, log files etc.
		$backupplus->initiate_restore_job( $restore_job_id );

		// If this is the start of a restore then get the restore data from the posted data and put it into jobdata.
		if ( isset( $_REQUEST['action'] ) && 'backupplus_restore' == $_REQUEST['action'] ) {

			if ( empty( $restore_job_id ) ) {
				$jobdata_to_save = array();
				foreach ( $_REQUEST as $key => $value ) {
					if ( in_array( $key, array( 'backupplus_age_identity', 'backupplus_age_key_passphrase' ), true ) ) {
						continue;
					}
					if ( false !== strpos( $key, 'backupplus_' ) || 'backup_timestamp' == $key || 'meta_foreign' == $key ) {
						if ( 'backupplus_restorer_restore_options' == $key ) {
							parse_str( stripslashes( $value ), $value );
						}
						if ( is_array( $value ) ) {
							unset( $value['backupplus_age_identity'], $value['backupplus_age_key_passphrase'] );
						}
						$jobdata_to_save[ $key ] = $value;
					}
				}

				$selective_restore_types = array(
					'tables',
					'plugins',
					'themes',
				);

				foreach ( $selective_restore_types as $type ) {
					if ( isset( $jobdata_to_save['backupplus_restorer_restore_options'][ 'backupplus_restore_' . $type . '_options' ] ) && ! empty( $jobdata_to_save['backupplus_restorer_restore_options'][ 'backupplus_restore_' . $type . '_options' ] ) ) {

						$restore_entities_options = $jobdata_to_save['backupplus_restorer_restore_options'][ 'backupplus_restore_' . $type . '_options' ];

						$include_unspecified_entities = false;
						$entities_to_restore          = array();
						$entities_to_skip             = array();

						foreach ( $restore_entities_options as $entity ) {
							if ( 'udp_all_other_' . $type == $entity ) {
								$include_unspecified_entities = true;
							} elseif ( substr( $entity, 0, strlen( 'udp-skip-' . $type ) ) == 'udp-skip-' . $type ) {
								$entities_to_skip[] = substr( $entity, strlen( 'udp-skip-' . $type ) + 1 );
							} else {
								$entities_to_restore[] = $entity;
							}
						}

						$jobdata_to_save['backupplus_restorer_restore_options'][ 'include_unspecified_' . $type ] = $include_unspecified_entities;
						$jobdata_to_save['backupplus_restorer_restore_options'][ $type . '_to_restore' ]          = $entities_to_restore;
						$jobdata_to_save['backupplus_restorer_restore_options'][ $type . '_to_skip' ]             = $entities_to_skip;
						unset( $jobdata_to_save['backupplus_restorer_restore_options'][ 'backupplus_restore_' . $type . '_options' ] );
					}
				}

				$backupplus->jobdata_set_multi( $jobdata_to_save );

				// Use a site option, as otherwise on multisite when all the array of options is updated via BackupPlus_Options::update_site_option(), it will over-write any restored UD options from the backup
				update_site_option( 'backupplus_restore_in_progress', $backupplus->nonce );
			}
		}

		// If this is the start of an ajax restore then end execution here so it can then be booted over ajax
		if ( isset( $_REQUEST['backupplus_ajax_restore'] ) && 'start_ajax_restore' == $_REQUEST['backupplus_ajax_restore'] ) {
			// return to prevent any more code from running
			return $this->prepare_ajax_restore();

		} elseif ( isset( $_REQUEST['backupplus_ajax_restore'] ) && 'continue_ajax_restore' == $_REQUEST['backupplus_ajax_restore'] ) {
			// If we enter here then in order to restore we needed to require the filesystem credentials we should save these before returning back to the browser and load them back after the AJAX call, this prevents us asking for the filesystem credentials again
			$filesystem_credentials = array(
				'hostname'        => '',
				'username'        => '',
				'password'        => '',
				'connection_type' => '',
				'upgrade'         => '',
			);

			$credentials_found = false;

			foreach ( $_REQUEST as $key => $value ) {
				if ( array_key_exists( $key, $filesystem_credentials ) ) {
					$filesystem_credentials[ $key ] = stripslashes( $value );
					$credentials_found              = true;
				}
			}

			if ( $credentials_found ) {
				$backupplus->jobdata_set( 'filesystem_credentials', $filesystem_credentials );
			}

			// return to prevent any more code from running
			return $this->prepare_ajax_restore();
		}

		if ( ! empty( $_REQUEST['backupplus_ajax_restore'] ) ) {
			add_filter( 'backupplus_logline', array( $this, 'backupplus_logline' ), 10, 5 );
		}

		$is_continuation = ( 'backupplus_ajaxrestore_continue' == $_REQUEST['action'] ) ? true : false;

		if ( $is_continuation ) {
			$restore_in_progress = get_site_option( 'backupplus_restore_in_progress' );
			if ( $restore_in_progress != $_REQUEST['job_id'] ) {
				$abort_restore_already = true;
				$backupplus->log( __( 'Sufficient information about the in-progress restoration operation could not be found.', 'backupplus' ) . ' (job_id_mismatch)', 'error', 'job_id_mismatch' );
			} else {
				$restore_jobdata = $backupplus->jobdata_getarray( $restore_in_progress );
				if ( is_array( $restore_jobdata ) && isset( $restore_jobdata['job_type'] ) && 'restore' == $restore_jobdata['job_type'] && isset( $restore_jobdata['second_loop_entities'] ) && ! empty( $restore_jobdata['second_loop_entities'] ) && isset( $restore_jobdata['job_time_ms'] ) && isset( $restore_jobdata['backup_timestamp'] ) ) {
					$backup_timestamp                             = $restore_jobdata['backup_timestamp'];
					$continuation_data                            = $restore_jobdata;
					$continuation_data['backupplus_ajax_restore'] = 'continue_ajax_restore';
				} else {
					$abort_restore_already = true;
					$backupplus->log( __( 'Sufficient information about the in-progress restoration operation could not be found.', 'backupplus' ) . ' (job_id_nojobdata)', 'error', 'job_id_nojobdata' );
				}
			}
		} elseif ( isset( $_REQUEST['backupplus_ajax_restore'] ) && 'do_ajax_restore' == $_REQUEST['backupplus_ajax_restore'] ) {
			$backup_timestamp  = $backupplus->jobdata_get( 'backup_timestamp' );
			$continuation_data = array( 'backupplus_ajax_restore' => 'do_ajax_restore' );
		} else {
			$backup_timestamp  = BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'backup_timestamp', 0, false, 'integer', 'intval' );
			$continuation_data = null;
		}

		$filesystem_credentials = $backupplus->jobdata_get( 'filesystem_credentials', array() );

		if ( ! empty( $filesystem_credentials ) ) {
			$continuation_data['backupplus_ajax_restore'] = 'continue_ajax_restore';
			// If the filesystem credentials are not empty then we now need to load these back into $_POST so that WP_Filesystem can access them
			foreach ( $filesystem_credentials as $key => $value ) {
				$_POST[ $key ] = $value;
			}
		}

		if ( empty( $abort_restore_already ) ) {
			$backup_success = $this->restore_backup( $backup_timestamp, $continuation_data );
		} else {
			$backup_success = false;
		}

		if ( empty( $backupplus->errors ) && true === $backup_success ) {
			// TODO: Deal with the case of some of the work having been deferred
			echo '<p class="backupplus_restore_successful"><strong>';
			$backupplus->log_e( 'Restore successful!' );
			echo '</strong></p>';
			$backupplus->log( 'Restore successful' );
			$s_val = 1;
			if ( ! empty( $this->entities_to_restore ) && is_array( $this->entities_to_restore ) ) {
				foreach ( $this->entities_to_restore as $v ) {
					if ( 'db' != $v ) {
						$s_val = 2;
					}
				}
			}
			echo '<strong>' . esc_html__( 'Actions', 'backupplus' ) . ':</strong> <a href="' . esc_url( BackupPlus_Options::admin_page_url() . '?page=backupplus&backupplus_restore_success=' . $s_val ) . '">' . esc_html__( 'Return to BackupPlus configuration', 'backupplus' ) . '</a>';
			return;

		} elseif ( is_wp_error( $backup_success ) ) {
			echo '<p class="backupplus_restore_error">';
			$backupplus->log_e( 'Restore failed...' );
			echo '</p>';
			$backupplus->log_wp_error( $backup_success, false, true );
			$backupplus->log( 'Restore failed' );
			echo '<div class="backupplus_restore_errors">';
			$backupplus->list_errors();
			echo '</div>';
			echo '<strong>' . esc_html__( 'Actions', 'backupplus' ) . ':</strong> <a href="' . esc_url( BackupPlus_Options::admin_page_url() . '?page=backupplus' ) . '">' . esc_html__( 'Return to BackupPlus configuration', 'backupplus' ) . '</a>';
			return;
		} elseif ( false === $backup_success ) {
			// This means, "not yet - but stay on the page because we may be able to do it later, e.g. if the user types in the requested information"
			echo '<p class="backupplus_restore_error">';
			$backupplus->log_e( 'Restore failed...' );
			echo '</p>';
			$backupplus->log( 'Restore failed' );
			echo '<div class="backupplus_restore_errors">';
			$backupplus->list_errors();
			echo '</div>';
			echo '<strong>' . esc_html__( 'Actions', 'backupplus' ) . ':</strong> <a href="' . esc_url( BackupPlus_Options::admin_page_url() ) . '?page=backupplus">' . esc_html__( 'Return to BackupPlus configuration', 'backupplus' ) . '</a>';
			return;
		}
	}

	/**
	 * This function will load the required ajax and output any relevant html for the ajax restore
	 *
	 * @return void
	 */
	private function prepare_ajax_restore() {
		global $backupplus;

		$debug                   = $backupplus->use_unminified_scripts();
		$enqueue_version         = $debug ? $backupplus->version . '.' . time() : $backupplus->version;
		$backupplus_min_or_not   = $backupplus->get_backupplus_file_version();
		$request_action          = BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'action' );
		$backupplus_ajax_restore = BackupPlus_Manipulation_Functions::fetch_superglobal( 'request', 'backupplus_ajax_restore' );
		$ajax_action             = isset( $backupplus_ajax_restore ) && 'continue_ajax_restore' == $backupplus_ajax_restore && 'backupplus_restore' != $request_action ? 'backupplus_ajaxrestore_continue' : 'backupplus_ajaxrestore';

		// get the entities info
		$jobdata            = $backupplus->jobdata_getarray( $backupplus->nonce );
		$restore_components = $jobdata['backupplus_restore'];
		usort( $restore_components, array( 'BackupPlus_Manipulation_Functions', 'sort_restoration_entities' ) );

		$backupable_entities = $backupplus->get_backupable_file_entities( true, true );
		$pretty_date         = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $jobdata['backup_timestamp'] ), 'M d, Y G:i' );

		wp_enqueue_script( 'backupplus-admin-restore', BACKUPPLUS_URL . '/js/backupplus-admin-restore' . $backupplus_min_or_not . '.js', array(), $enqueue_version );

		$backupplus->log( 'Restore setup, now closing connection and starting restore over AJAX.' );

		echo '<div class="backupplus_restore_container">';
		echo '<div class="error" id="backupplus-restore-hidethis">';
		echo '<p><strong>' . esc_html__( 'Warning: If you can still read these words after the page finishes loading, then there is a JavaScript or jQuery problem in the site.', 'backupplus' ) . ' ' . esc_html__( 'This may prevent the restore procedure from being able to proceed.', 'backupplus' ) . '</strong>';
		echo ' ' . esc_html__( 'Check the browser console for JavaScript errors and confirm that this site does not block its admin scripts.', 'backupplus' ) . '</p>';
		echo '</div>';
		echo '<div class="backupplus_restore_main--header">' . esc_html__( 'BackupPlus Restoration', 'backupplus' ) . ' - ' . esc_html__( 'Backup', 'backupplus' ) . ' ' . esc_html( $pretty_date ) . '</div>';
		echo '<div class="backupplus_restore_main">';

		if ( $debug ) {
			echo '<input type="hidden" id="backupplus_ajax_restore_debug" name="backupplus_ajax_restore_debug" value="1">';
		}
		echo '<input type="hidden" id="backupplus_ajax_restore_job_id" name="backupplus_restore_job_id" value="' . esc_attr( $backupplus->nonce ) . '">';
		echo '<input type="hidden" id="backupplus_ajax_restore_action" name="backupplus_restore_action" value="' . esc_attr( $ajax_action ) . '">';
		echo '<div id="backupplus_ajax_restore_progress" style="display: none;"></div>';

		echo '<div class="backupplus_restore_main--components">';
		/* translators: %s: Operation nonce */
		echo '<p>' . esc_html( sprintf( __( 'The restore operation has begun (%s).', 'backupplus' ), $backupplus->nonce ) ) . ' ' .
		esc_html__( 'Do not close this page until it reports itself as having finished.', 'backupplus' ) . '</p>';
		echo '<h2>' . esc_html__( 'Restoration progress:', 'backupplus' ) . '</h2>';
		echo '	<div class="backupplus_restore_result"><span class="dashicons"></span><pan class="backupplus_restore_result--text"></span></div>';
		echo '	<ul class="backupplus_restore_components_list">';
		echo '<li data-component="verifying" class="active"><span class="backupplus_component--description">' . esc_html__( 'Verifying', 'backupplus' ) . '</span><span class="backupplus_component--progress"></span></li>';
		foreach ( $restore_components as $restore_component ) {
			// Set Database description
			if ( 'db' == $restore_component && ! isset( $backupable_entities[ $restore_component ]['description'] ) ) {
				$backupable_entities[ $restore_component ]['description'] = __( 'Database', 'backupplus' );
			}
			if ( ! isset( $backupable_entities[ $restore_component ] ) ) {
				die( 'Abort: invalid data' );
			}
			echo '<li data-component="' . esc_attr( $restore_component ) . '"><span class="backupplus_component--description">' . ( isset( $backupable_entities[ $restore_component ]['description'] ) ? esc_html( $backupable_entities[ $restore_component ]['description'] ) : esc_html( $restore_component ) ) . '</span><span class="backupplus_component--progress"></span></li>';
		}
		echo '<li data-component="cleaning"><span class="backupplus_component--description">' . esc_html__( 'Cleaning', 'backupplus' ) . '</span><span class="backupplus_component--progress"></span></li>';
		echo '<li data-component="finished"><span class="backupplus_component--description">' . esc_html__( 'Finished', 'backupplus' ) . '</span><span class="backupplus_component--progress"></span></li>';
		echo '	</ul>'; // end ul.backupplus_restore_components_list
		// Provide download link for the log file
		echo '<p><a target="_blank" href="?action=downloadlog&page=backupplus&backupplus_backup_nonce=' . esc_attr( $backupplus->nonce ) . '">' . esc_html__( 'Follow this link to download the log file for this restoration (needed for any support requests).', 'backupplus' ) . '</a></p>';
		echo '</div>'; // end .backupplus_restore_main--components
		echo '<div class="backupplus_restore_main--activity">';
		echo '<h2 class="backupplus_restore_main--activity-title">' . esc_html__( 'Activity log', 'backupplus' ) . ' <i id="activity-full-log" title="' . esc_html__( 'Full-screen', 'backupplus' ) . '" class="dashicons dashicons-fullscreen-alt" style="float: right; cursor: pointer; margin-left: 7px;"></i> <span id="backupplus_ajax_restore_last_activity"></span></h2>';
		echo '	<div id="backupplus_ajax_restore_output"></div>';
		echo '</div>'; // end .backupplus_restore_main--activity
		echo '
			<div class="backupplus-restore--footer">
				<ul class="backupplus-restore--stages">
					<li><span>1. ' . esc_html__( 'Component selection', 'backupplus' ) . '</span></li>
					<li><span>2. ' . esc_html__( 'Verifications', 'backupplus' ) . '</span></li>
					<li class="active"><span>3. ' . esc_html__( 'Restoration', 'backupplus' ) . '</span></li>
				</ul>
			</div>';
		echo '</div>'; // end .backupplus_restore_main
		echo '</div>'; // end .backupplus_restore_container
	}

	/**
	 * Processes the jobdata to build an array of entities to restore.
	 *
	 * @param Array $backup_set - information on the backup to restore
	 *
	 * @return Array - the entities to restore built from the restore jobdata
	 */
	private function get_entities_to_restore_from_jobdata( $backup_set ) {

		global $backupplus;

		$backupplus_restore = $backupplus->jobdata_get( 'backupplus_restore' );

		if ( empty( $backupplus_restore ) || ( ! is_array( $backupplus_restore ) ) ) {
			$backupplus_restore = array();
		}

		$entities_to_restore = array();
		$foreign_known       = apply_filters( 'backupplus_accept_archivename', array() );

		foreach ( $backupplus_restore as $entity ) {
			if ( empty( $backup_set['meta_foreign'] ) ) {
				$entities_to_restore[ $entity ] = $entity;
			} else {
				if ( 'db' == $entity && ! empty( $foreign_known[ $backup_set['meta_foreign'] ] ) && ! empty( $foreign_known[ $backup_set['meta_foreign'] ]['separatedb'] ) ) {
					$entities_to_restore[ $entity ] = 'db';
				} else {
					$entities_to_restore[ $entity ] = 'wpcore';
				}
			}
		}

		return $entities_to_restore;
	}

	/**
	 * Processes the jobdata to build an array of restoration options
	 *
	 * @return Array - the restore options built from the restore jobdata
	 */
	private function get_restore_options_from_jobdata() {

		global $backupplus;

		$restore_options = $backupplus->jobdata_get( 'backupplus_restorer_restore_options' );
		if ( ! is_array( $restore_options ) ) {
			$restore_options = array();
		}
		$pitr_point = $backupplus->jobdata_get( 'backupplus_pitr_restore_point', '' );
		if ( '' !== $pitr_point ) {
			$restore_options['backupplus_pitr_restore_point'] = $pitr_point;
		}
		$age_ticket = $backupplus->jobdata_get( 'backupplus_age_ticket', '' );
		if ( '' !== $age_ticket ) {
			try {
				BackupPlus_Age_Restore::claim( $age_ticket, $backupplus->jobdata_get( 'backup_timestamp' ), $backupplus->nonce ); } catch ( Exception $e ) {
				wp_die( esc_html__( 'The prepared encrypted restore has expired or belongs to another restore. Prepare the archives again.', 'backupplus' ) ); }
				$restore_options['backupplus_age_ticket'] = $age_ticket;
		}
		foreach ( array( 'backupplus_source_destinations', 'backupplus_external_targets' ) as $field ) {
			$restore_options[ $field ] = $backupplus->jobdata_get( $field, array() );
		}
		foreach ( $restore_options['backupplus_external_targets'] as $id => &$mapping ) {
			if ( ! empty( $mapping['saved'] ) ) { $mapping = array( 'connection' => BackupPlus_Source_Restore::connection( $id, $restore_options ) ); }
		}
		unset( $mapping );
		$backupplus_encryptionphrase = $backupplus->jobdata_get( 'backupplus_encryptionphrase' );
		$include_wpconfig            = $backupplus->jobdata_get( 'backupplus_restorer_wpcore_includewpconfig' );

		$restore_options['backupplus_encryptionphrase'] = empty( $backupplus_encryptionphrase ) ? '' : $backupplus_encryptionphrase;

		$restore_options['backupplus_restorer_wpcore_includewpconfig'] = ! empty( $include_wpconfig );

		$restore_options['backupplus_incremental_restore_point'] = empty( $restore_options['backupplus_incremental_restore_point'] ) ? -1 : (int) $restore_options['backupplus_incremental_restore_point'];

		return $restore_options;
	}

	/**
	 * Carry out the restore process within the WP admin dashboard, using data from $_POST
	 *
	 * @param  Integer    $timestamp         Identifying the backup to be restored
	 * @param  Array|null $continuation_data For continuing a multi-stage restore; this is the saved jobdata for the job; in this method the keys used are second_loop_entities, restore_options; but it is also passed on to BackupPlus_Restorer::perform_restore()
	 * @return Boolean|WP_Error - a WP_Error indicates a terminal failure; false indicates not-yet complete (not necessarily terminal); true indicates complete.
	 */
	private function restore_backup( $timestamp, $continuation_data = null ) {

		global $backupplus, $backupplus_restorer;

		$second_loop_entities = empty( $continuation_data['second_loop_entities'] ) ? array() : $continuation_data['second_loop_entities'];

		// If this is a resumption and we still need to restore the database we should rebuild the backup history to ensure the database is in there.
		if ( ! empty( $second_loop_entities['db'] ) ) {
			BackupPlus_Backup_History::rebuild();
		}

		$backup_set = BackupPlus_Backup_History::get_history( $timestamp );

		if ( empty( $backup_set ) ) {
			echo '<p>' . esc_html__( 'This backup does not exist in the backup history - restoration aborted.', 'backupplus' ) . ' ' . esc_html__( 'Timestamp:', 'backupplus' ) . ' ' . esc_html( $timestamp ) . '</p><br>';
			return new WP_Error( 'does_not_exist', __( 'Backup does not exist in the backup history', 'backupplus' ) . " ($timestamp)" );
		}

		$backup_set['timestamp'] = $timestamp;

		$url_parameters = array(
			'backup_timestamp' => $timestamp,
			'job_id'           => $backupplus->nonce,
		);

		if ( ! empty( $continuation_data['backupplus_ajax_restore'] ) ) {
			$url_parameters['backupplus_ajax_restore'] = 'continue_ajax_restore';
			$backupplus->output_to_browser( '' ); // Start timer
			// Force output buffering off so that we get log lines sent to the browser as they come not all at once at the end of the ajax restore
			// zlib creates an output buffer, and waits for the entire page to be generated before it can send it to the client try to turn it off
			@ini_set( 'zlib.output_compression', '0' );// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			// Turn off PHP output buffering for NGINX
			header( 'X-Accel-Buffering: no' );
			header( 'Content-Encoding: none' );
			while ( ob_get_level() ) {
				ob_end_flush();
			}
			if ( version_compare( PHP_VERSION, '8.0.0', '>=' ) ) {
				$flush = true; // PHP 8.0.0 or newer, use bool
			} else {
				$flush = 1; // PHP older than 8.0.0, use int
			}
			ob_implicit_flush( $flush );
		}

		$backupplus->log( 'Ensuring WP_Filesystem is setup for a restore' );

		// This will print HTML and die() if necessary
		BackupPlus_Filesystem_Functions::ensure_wp_filesystem_set_up_for_restore( $url_parameters );

		$backupplus->log( 'WP_Filesystem is setup and ready for a restore' );

		$entities_to_restore = $this->get_entities_to_restore_from_jobdata( $backup_set );

		if ( empty( $entities_to_restore ) ) {
			$restore_jobdata = $backupplus->jobdata_getarray( $backupplus->nonce );
			echo '<p>' . esc_html__( 'ABORT: Could not find the information on which entities to restore.', 'backupplus' ) . '</p><p>' . esc_html__( 'If making a request for support, please include this information:', 'backupplus' ) . ' ' . count( $restore_jobdata ) . ' : ' . esc_html( serialize( $restore_jobdata ) ) . '</p>';
			return new WP_Error( 'missing_info', 'Backup information not found' );
		}

		// This is used in painting the admin page after a successful restore
		$this->entities_to_restore = $entities_to_restore;

		$error_levels = version_compare( PHP_VERSION, '8.4.0', '>=' ) ? E_ALL : E_ALL & ~E_STRICT;
		// This will be removed by BackupPlus_Restorer::post_restore_clean_up()
		set_error_handler( array( $backupplus, 'php_error' ), $error_levels );

		// Set $restore_options, either from the continuation data, or from $_POST
		if ( ! empty( $continuation_data['restore_options'] ) ) {
			$restore_options = $continuation_data['restore_options'];
		} else {
			// Gather the restore options into one place - code after here should read the options
			$restore_options = $this->get_restore_options_from_jobdata();
			$backupplus->jobdata_set( 'restore_options', $restore_options );
		}

		add_action( 'backupplus_restoration_title', array( $this, 'restoration_title' ) );

		$backupplus->log_restore_update(
			array(
				'type'  => 'state',
				'stage' => 'started',
				'data'  => array(),
			)
		);

		// We use a single object for each entity, because we want to store information about the backup set
		$backupplus_restorer = new BackupPlus_Restorer( new BackupPlus_Restorer_Skin(), $backup_set, false, $restore_options, $continuation_data );

		$restore_result = $backupplus_restorer->perform_restore( $entities_to_restore, $restore_options );

		$backupplus_restorer->post_restore_clean_up( $restore_result );

		$sval = ( true === $restore_result ) ? 1 : 0;

		$pages     = get_pages( array( 'number' => 2 ) );
		$page_urls = array(
			'home' => get_home_url(),
		);

		foreach ( $pages as $page_info ) {
			$page_urls[ $page_info->post_name ] = get_page_link( $page_info->ID );
		}

		$backupplus->log_restore_update(
			array(
				'type'  => 'state',
				'stage' => 'finished',
				'data'  => array(
					'actions' => array(
						__( 'Return to BackupPlus configuration', 'backupplus' ) => BackupPlus_Options::admin_page_url() . '?page=backupplus&backupplus_restore_success=' . $sval,
					),
					'urls'    => $page_urls,
				),
			)
		);

		return $restore_result;
	}

	/**
	 * Called when the restore process wants to print a title
	 *
	 * @param String $title - title
	 */
	public function restoration_title( $title ) {
		echo '<h2>' . esc_html( $title ) . '</h2>';
	}

	/**
	 * Logs a line from the restore process, being called from BackupPlus::log().
	 * Hooks the WordPress filter backupplus_logline
	 * In future, this can get more sophisticated. For now, things are funnelled through here, giving the future possibility.
	 *
	 * @param String         $line        - the line to be logged
	 * @param String         $nonce       - the job ID of the restore job
	 * @param String         $level       - the level of the log notice
	 * @param String|Boolean $uniq_id     - a unique ID for the log if it should only be logged once; or false otherwise
	 * @param String         $destination - the type of job ongoing. If it is not 'restore', then we will skip the logging.
	 *
	 * @return String|Boolean - the filtered value. If set to false, then BackupPlus::log() will stop processing the log line.
	 */
	public function backupplus_logline( $line, $nonce, $level, $uniq_id, $destination ) {// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Unused parameter is present because the method is used as a WP filter.

		if ( 'progress' != $destination || ( defined( 'WP_CLI' ) && WP_CLI ) || false === $line || false === strpos( $line, 'RINFO:' ) ) {
			return $line;
		}

		global $backupplus;

		$backupplus->output_to_browser( $line );

		// Indicate that we have completely handled all logging needed
		return false;
	}
}
