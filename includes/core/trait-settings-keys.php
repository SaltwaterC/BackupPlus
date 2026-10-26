<?php

/** The option, meta and transient names that belong to the plugin. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Core_Settings_Keys {

	public function option_filter_get( $which ) {
		global $wpdb;
		$row = BackupPlus_SQL::wpdb()->row( 'SELECT option_value FROM %i WHERE option_name = ? LIMIT 1', array( $wpdb->options ), array( $which ) );
		// Has to be get_row instead of get_var because of funkiness with 0, false, null values
		return ( is_object( $row ) ) ? $row->option_value : false;
	}

	/**
	 * TODO: Remove legacy storage setting keys from here
	 * These are used in 4 places (Feb 2016 - of course, you should re-scan the code to check if relying on this): showing current settings on the debug modal, wiping all current settings, getting a settings bundle to restore when migrating, and for relevant keys in POST-ed data when saving settings over AJAX
	 *
	 * @return Array - the list of keys
	 */
	public function get_settings_keys() {
		$general_keys = self::get_system_identifiers_list();
		$general_keys = array_merge(
			$general_keys['options'],
			array(
				'backupplus_dismiss_azure_legacy_storage_notice',
				'backupplus_tmp_googledrive_access_token',
				'backupplus_dismissedautobackup',
				'backupplus_dismissedexpiry',
				'backupplus_dismisseddashnotice',
				'backupplus_tour_cancelled_on',
				'backupplus_version',
			)
		);
		// N.B. backupplus_backup_history is not included here, as we don't want that wiped
		return array_diff( $general_keys, array( 'backupplus_restore_in_progress', 'backupplus_backup_history' ) );
	}

	/**
	 * Get a collection of unique names or keys used by the plugin to identify specific settings, options, scheduled tasks (crons), metadata, and transient data
	 *
	 * Note that the following list of setting keys are equivalent to the keys used in our code
	 * Adding a new one to the list below means there's already a key used somewhere else in the codebase, or it will be added later (i.e. it's reserved)
	 * Editing one of the keys below means that the corresponding key in the codebase should also be edited or updated for consistency
	 *
	 * @return Void
	 */
	public static function get_system_identifiers_list() {
		$list = array(
			'options'             => array(
				'backupplus_autobackup_default',
				'backupplus_dropbox',
				'backupplus_pcloud',
				'backupplus_googledrive',
				'dismissed_general_notices_until',
				'dismissed_review_notice',
				'dismissed_season_notices_until',
				'backupplus_interval',
				'backupplus_interval_increments',
				'backupplus_interval_database',
				'backupplus_retain',
				'backupplus_retain_db',
				'backupplus_database_encryption',
				'backupplus_pitr',
				'backupplus_pitr_capture',
				'backupplus_pitr_controls',
				'backupplus_service',
				'backupplus_googledrive_clientid',
				'backupplus_googledrive_secret',
				'backupplus_googledrive_remotepath',
				'backupplus_ftp',
				'backupplus_backblaze',
				'backupplus_server_address',
				'backupplus_dir',
				'backupplus_email',
				'backupplus_delete_local',
				'backupplus_debug_mode',
				'backupplus_include_plugins',
				'backupplus_include_themes',
				'backupplus_include_uploads',
				'backupplus_include_others',
				'backupplus_include_credential-keys',
				'backupplus_include_wpcore',
				'backupplus_include_wpcore_exclude',
				'backupplus_include_more',
				'backupplus_include_blogs',
				'backupplus_include_mu-plugins',
				'backupplus_auto_updates', // since WordPress 5.5, backupplus_auto_updates option is no longer used and has been removed from the code, but the HTML IDs which use the same name that represent the automatic update setting are still zealously preserved so this one cannot be removed
				'backupplus_include_others_exclude',
				'backupplus_include_uploads_exclude',
				'backupplus_lastmessage',
				'backupplus_googledrive_token',
				'backupplus_dropboxtk_request_token',
				'backupplus_dropboxtk_access_token',
				'backupplus_retain_extrarules',
				'backupplus_googlecloud',
				'backupplus_include_more_path',
				'backupplus_split_every',
				'backupplus_ssl_nossl',
				'backupplus_backupdb_nonwp',
				'backupplus_extradbs',
				'backupplus_database_selection',
				'backupplus_database_next_id',
				'backupplus_include_more_exclude',
				'backupplus_combine_jobs_around',
				'backupplus_last_backup',
				'backupplus_starttime_files',
				'backupplus_starttime_db',
				'backupplus_startday_db',
				'backupplus_startday_files',
				'backupplus_schedule_exact_files',
				'backupplus_schedule_exact_db',
				'backupplus_schedule_exact_increments',
				'backupplus_starttime_increments',
				'backupplus_retention_cleanup_mode',
				'backupplus_retention_cleanup_time',
				'backupplus_sftp',
				'backupplus_s3',
				'backupplus_s3generic',
				'backupplus_dreamhost',
				'backupplus_s3generic_login',
				'backupplus_s3generic_pass',
				'backupplus_s3generic_remote_path',
				'backupplus_s3generic_endpoint',
				'backupplus_webdav',
				'backupplus_openstack',
				'backupplus_onedrive',
				'backupplus_azure',
				'backupplus_cloudfiles',
				'backupplus_cloudfiles_user',
				'backupplus_cloudfiles_apikey',
				'backupplus_cloudfiles_path',
				'backupplus_cloudfiles_authurl',
				'backupplus_ssl_useservercerts',
				'backupplus_ssl_disableverify',
				'backupplus_s3_login',
				'backupplus_s3_pass',
				'backupplus_s3_remote_path',
				'backupplus_dreamobjects_login',
				'backupplus_dreamobjects_pass',
				'backupplus_dreamobjects_remote_path',
				'backupplus_dreamobjects',
				'backupplus_report_warningsonly',
				'backupplus_report_wholebackup',
				'backupplus_report_dbbackup',
				'backupplus_log_syslog',
				'backupplus_extradatabases',
				'backupplus_dismiss_admin_warning_litespeed',
				'backupplus_dismiss_admin_warning_pclzip',
				'backupplus_dismiss_phpseclib_notice',
				'backupplus_restore_in_progress',
				'backupplus_backup_history',
			),
			'prefixed_options'    => array(
				'backupplus_jobdata_%',
				'backupplus_last_scheduled_%',
				'backupplus_lock_%',
				'backupplus_%',
			),
			'meta'                => array(
				'backupplus_oneshotnonce',
				'backupplus-addons_options',
				// leave the site ID until the x-spm-duplicate-of handler is implemented in our plugin
				// 'backupplus-addons_siteid',
				'external_updates-backupplus',
				'backupplus_lastmessage',
			),
			'transients'          => array(
				'backupplus_initial_resume_interval',
				'upaddons_remote',
				'backupplus_dashboard_news',
			),
			'prefixed_transients' => array(
				'ud_forgt_%',
				'ud_rsenddck_%',
				'backupplus_%',
			),
			'crons'               => array(
				'backupplus_backup',
				'backupplus_backup_database',
				'backupplus_backup_increments',
				'backupplus_pitr_capture',
				'backupplus_retention_cleanup',
				'backupplus_retention_cleanup_retry',
				'backupplus_clean_temporary_files',
				'puc_cron_check_updates-backupplus',
				'backupplus_backup_resume',
				'ud_wp_maybe_auto_update',
			),
		);

		// Provider settings use BackupPlus-prefixed option names.
		global $backupplus;
		if ( isset( $backupplus->backup_methods ) && is_array( $backupplus->backup_methods ) ) {
			foreach ( array_keys( $backupplus->backup_methods ) as $service ) {
				$list['options'][] = 'backupplus_' . $service;
			}
			$list['options'] = array_values( array_unique( $list['options'] ) );
		}

		$udaddons2_options = get_site_option( 'backupplus-addons_options' );
		if ( ! empty( $udaddons2_options['email'] ) ) {
			$list['transients'][] = 'udaddons_connect_' . substr( md5( $udaddons2_options['email'] ), 0, 23 );
		}
		return $list;
	}
}
