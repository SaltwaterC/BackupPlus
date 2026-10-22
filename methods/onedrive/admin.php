<?php
if (!defined('ABSPATH')) die('No direct access allowed.');

/** Provider-owned authentication checks and dashboard notices. */
trait BackupPlus_onedrive_Admin {
	public function authentication_required($settings) {
		return empty($settings['refresh_token']);
	}

	public function get_admin_notices($settings) {
		$notices = array();
		if ('de' === ($settings['endpoint_tld'] ?? '')) {
			$notices[] = array(
				'message' => '<strong>'.__('BackupPlus notice', 'backupplus').':</strong> '.
				/* translators: 1: Endpoint name */
				sprintf(__('Due to the shutdown of the %1$s endpoint, support for %1$s will be ending soon.', 'backupplus'), 'OneDrive Germany').' '.
				__('You will need to migrate to the Global endpoint in your BackupPlus settings.', 'backupplus').' '.
				/* translators: %s: OneDrive link */
				sprintf(__('For more information, please see: %s', 'backupplus'), '<a href="https://www.microsoft.com/en-us/cloud-platform/germany-cloud-regions" target="_blank">https://www.microsoft.com/en-us/cloud-platform/germany-cloud-regions</a>'),
				'class' => 'updated'
			);
		}
		return $notices;
	}
}
