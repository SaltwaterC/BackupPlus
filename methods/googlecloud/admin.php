<?php
if (!defined('ABSPATH')) die('No direct access allowed.');

/** Provider-owned authentication checks and dashboard notices. */
trait BackupPlus_googlecloud_Admin {
	public function authentication_required($settings) {
		if ((defined('BACKUPPLUS_CUSTOM_GOOGLECLOUD_APP') && BACKUPPLUS_CUSTOM_GOOGLECLOUD_APP) || !empty($settings['clientid'])) {
			return !empty($settings['clientid']) && empty($settings['token']);
		}
		return empty($settings['user_id']);
	}
}
