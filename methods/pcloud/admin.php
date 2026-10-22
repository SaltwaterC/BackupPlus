<?php
if (!defined('ABSPATH')) die('No direct access allowed.');

/** Provider-owned authentication checks and dashboard notices. */
trait BackupPlus_pcloud_Admin {
	public function authentication_required($settings) {
		return empty($settings['pclauth']);
	}
}
