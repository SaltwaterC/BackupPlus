<?php

if (!defined('ABSPATH')) die('No direct access allowed');

if (!class_exists('Updraft_Notices_1_3')) backupplus_try_include_file('vendor/team-updraft/common-libs/src/updraft-notices/updraft-notices.php', 'require_once');

class BackupPlus_Notices extends Updraft_Notices_1_3 {

	protected static $_instance = null;

	private $initialized = false;

	protected $notices_content = array();
	
	public static function instance() {
		if (empty(self::$_instance)) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * This method gets any parent notices and adds its own notices to the notice array
	 *
	 * @return array - an array of notices
	 */
	protected function populate_notices_content() {
		return array();
	}
	
	/**
	 * Call this method to setup the notices
	 */
	public function notices_init() {
		if ($this->initialized) return;
		$this->initialized = true;
		// parent::notices_init();
		$this->notices_content = array();
		global $backupplus;
		$enqueue_version = $backupplus->use_unminified_scripts() ? $backupplus->version.'.'.time() : $backupplus->version;
		$backupplus_min_or_not = $backupplus->get_backupplus_file_version();

		wp_enqueue_style('backupplus-notices-css',  BACKUPPLUS_URL.'/css/backupplus-notices'.$backupplus_min_or_not.'.css', array(), $enqueue_version);
	}

	protected function translation_needed($plugin_base_dir = null, $product_name = null) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable, Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Filter use
		return parent::translation_needed(BACKUPPLUS_DIR, 'backupplus');
	}

	/**
	 * This function will check if we should display the rate notice or not
	 *
	 * @return boolean - to indicate if we should show the notice or not
	 */
	protected function show_rate_notice() {
		global $backupplus;

		$backup_history = BackupPlus_Backup_History::get_history();
		
		$backup_dir = $backupplus->backups_dir_location();
		// N.B. Not an exact proxy for the installed time; they may have tweaked the expert option to move the directory
		$installed = @filemtime($backup_dir.'/index.html');// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		$installed_for = time() - $installed;

		if (!empty($backup_history) && $installed && $installed_for > 28*86400) {
			return true;
		}

		return false;
	}
	
	protected function wp_optimize_installed($plugin_base_dir = null, $product_name = null) {// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Filter use
		if (!function_exists('get_plugins')) include_once(ABSPATH.'wp-admin/includes/plugin.php');
		$plugins = get_plugins();

		foreach ($plugins as $value) {
			if ('wp-optimize' == $value['TextDomain']) {
				return false;
			}
		}
		return true;
	}
	
	protected function skip_seasonal_notices($notice_data) {
		global $backupplus;

		$time_now = defined('BACKUPPLUS_NOTICES_FORCE_TIME') ? BACKUPPLUS_NOTICES_FORCE_TIME : time();
		// Do not show seasonal notices when no add-ons are present.
		if (!file_exists(BACKUPPLUS_DIR.'/udaddons') || $backupplus->have_addons) {
			$valid_from = strtotime($notice_data['valid_from']);
			$valid_to = strtotime($notice_data['valid_to']);
			$dismiss = $this->check_notice_dismissed($notice_data['dismiss_time']);
			if (($time_now >= $valid_from && $time_now <= $valid_to) && !$dismiss) {
				// return true so that we return this notice to be displayed
				return true;
			}
		}
		
		return false;
	}
	
	protected function check_notice_dismissed($dismiss_time) {

		$time_now = defined('BACKUPPLUS_NOTICES_FORCE_TIME') ? BACKUPPLUS_NOTICES_FORCE_TIME : time();
	
		$notice_dismiss = ($time_now < BackupPlus_Options::get_backupplus_option('dismissed_general_notices_until', 0));
		$review_dismiss = ($time_now < BackupPlus_Options::get_backupplus_option('dismissed_review_notice', 0));
		$seasonal_dismiss = ($time_now < BackupPlus_Options::get_backupplus_option('dismissed_season_notices_until', 0));
		$autobackup_dismiss = ($time_now < BackupPlus_Options::get_backupplus_option('backupplus_dismissedautobackup', 0));

		$dismiss = false;

		if ('dismiss_notice' == $dismiss_time) $dismiss = $notice_dismiss;
		if ('dismiss_review_notice' == $dismiss_time) $dismiss = $review_dismiss;
		if ('dismiss_season' == $dismiss_time) $dismiss = $seasonal_dismiss;
		if ('dismissautobackup' == $dismiss_time) $dismiss = $autobackup_dismiss;

		return $dismiss;
	}

	protected function render_specified_notice($advert_information, $return_instead_of_echo = false, $position = 'top') {
	
		if ('bottom' == $position) {
			$template_file = 'bottom-notice.php';
		} elseif ('report' == $position) {
			$template_file = 'report.php';
		} elseif ('report-plain' == $position) {
			$template_file = 'report-plain.php';
		} elseif ('autobackup' == $position) {
			$template_file = 'autobackup-notice.php';
		} else {
			$template_file = 'horizontal-notice.php';
		}
		
		backupplus_try_include_file('admin.php', 'include_once');
		global $backupplus_admin;
		return $backupplus_admin->include_template('wp-admin/notices/'.$template_file, $return_instead_of_echo, $advert_information);
	}

	/**
	 * Checks if Google Drive is the currently selected remote storage service.
	 *
	 * @return bool True if Google Drive is selected, false otherwise.
	 */
	public function is_googledrive_in_use() {
		return in_array('googledrive', (array) BackupPlus_Options::get_backupplus_option('backupplus_service'));
	}

	/**
	 * Checks if Dropbox is the currently selected remote storage service.
	 *
	 * @return bool True if Dropbox is selected, false otherwise.
	 */
	public function is_dropbox_in_use() {
		return in_array('dropbox', (array) BackupPlus_Options::get_backupplus_option('backupplus_service'));
	}

	/**
	 * Checks if Amazon S3 is the currently selected remote storage service.
	 *
	 * @return bool True if Amazon S3 is selected, false otherwise.
	 */
	public function is_s3_in_use() {
		return in_array('s3', (array) BackupPlus_Options::get_backupplus_option('backupplus_service'));
	}
	
	protected function widget_enqueue() {
	}
}

$GLOBALS['BackupPlus_Notices'] = BackupPlus_Notices::instance();
