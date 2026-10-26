<?php

/** Dismissing admin notices and the tour state. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_WPAdmin_Commands_Notices {

	/**
	 * This function is called via ajax and will update the autobackup notice dismiss time
	 *
	 * @return array - an empty array
	 */
	public function dismissautobackup() {
		BackupPlus_Options::update_backupplus_option( 'backupplus_dismissedautobackup', time() + 84 * 86400 );
		return array();
	}

	/**
	 * This function is called via ajax and will update the general notice dismiss time
	 *
	 * @return array - an empty array
	 */
	public function dismiss_notice() {
		BackupPlus_Options::update_backupplus_option( 'dismissed_general_notices_until', time() + 84 * 86400 );
		return array();
	}

	/**
	 * This function is called via ajax and will update the review notice dismiss time
	 *
	 * @param array $data - an array that contains the dismiss notice for time
	 *
	 * @return array - an empty array
	 */
	public function dismiss_review_notice( $data ) {
		if ( empty( $data['dismiss_forever'] ) ) {
			BackupPlus_Options::update_backupplus_option( 'dismissed_review_notice', time() + 84 * 86400 );
		} else {
			BackupPlus_Options::update_backupplus_option( 'dismissed_review_notice', 100 * ( 365.25 * 86400 ) );
		}
		return array();
	}

	/**
	 * This function is called via ajax and will update the season notice dismiss time
	 *
	 * @return array - an empty array
	 */
	public function dismiss_season() {
		BackupPlus_Options::update_backupplus_option( 'dismissed_season_notices_until', time() + 366 * 86400 );
		return array();
	}

	/**
	 * Update and set dismiss_phpseclib_notice option name to the minimum recommended PHP version
	 *
	 * @return array - an associative array containing a key named 'success' with 1 value which indicates the successful of updating the option
	 */
	public function dismiss_phpseclib_notice() {
		// Store the minimum recommended PHP version dismissed for (previously was boolean `true`), so a later version bump re-shows the notice.
		BackupPlus_Options::update_backupplus_option( 'backupplus_dismiss_phpseclib_notice', BACKUPPLUS_PHPSECLIB_MIN_PHP_VERSION );
		return array( 'success' => 1 );
	}

	public function set_autobackup_default( $params ) {
		$default = empty( $params['default'] ) ? 0 : 1;
		BackupPlus_Options::update_backupplus_option( 'backupplus_autobackup_default', $default );
		return array();
	}

	public function dismissexpiry() {
		BackupPlus_Options::update_backupplus_option( 'backupplus_dismissedexpiry', time() + 14 * 86400 );
		return array();
	}

	public function dismissdashnotice() {
		BackupPlus_Options::update_backupplus_option( 'backupplus_dismisseddashnotice', time() + 366 * 86400 );
		return array();
	}

	/**
	 * Dismiss the Azure Legacy Storage notice.
	 *
	 * This function updates the option to indicate that the user has dismissed
	 * the Azure legacy storage notice, preventing it from being shown again.
	 *
	 * @return array Associative array with a 'success' key indicating the operation status.
	 */
	public function dismiss_azure_legacy_storage_notice() {
		BackupPlus_Options::update_backupplus_option( 'backupplus_dismiss_azure_legacy_storage_notice', 1 );
		return array( 'success' => 1 );
	}

	/**
	 * Dismiss the notice  which will if .htaccess have any old migrated site reference.
	 *
	 * @return Boolean Return true if migration notice is dismissed
	 */
	public function dismiss_migration_notice_for_old_site_reference() {
		delete_site_option( 'backupplus_migrated_site_domain' );
		return true;
	}

	/**
	 * Set the Tour status
	 *
	 * @param array $params - the $_REQUEST. We're looking for 'current_step'
	 * @return bool
	 */
	public function set_tour_status( $params ) {
		return class_exists( 'BackupPlus_Tour' ) ? BackupPlus_Tour::get_instance()->set_tour_status( $params ) : false;
	}

	/**
	 * Resets the tour status
	 *
	 * @return bool
	 */
	public function reset_tour_status() {
		return class_exists( 'BackupPlus_Tour' ) ? BackupPlus_Tour::get_instance()->reset_tour_status() : false;
	}
}
