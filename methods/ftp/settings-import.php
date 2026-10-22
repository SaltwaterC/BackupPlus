<?php
if (!defined('ABSPATH')) die('No direct access allowed.');

class BackupPlus_FTP_Settings_Importer {
	/**
	 * Convert pre-2014 individual FTP fields into the current structured option.
	 *
	 * @param array $settings Settings parsed from an export.
	 * @return array
	 */
	public static function normalize($settings) {
		if (!is_array($settings)) return array();

		$legacy_keys = array('updraft_server_address', 'updraft_ftp_login', 'updraft_ftp_pass', 'updraft_ftp_remote_path');
		$has_legacy_ftp = false;
		foreach ($legacy_keys as $key) {
			if (array_key_exists($key, $settings)) {
				$has_legacy_ftp = true;
				break;
			}
		}

		if ($has_legacy_ftp && !isset($settings['updraft_ftp']) && !isset($settings['backupplus_ftp'])) {
			$settings['updraft_ftp'] = array(
				'host' => isset($settings['updraft_server_address']) ? $settings['updraft_server_address'] : '',
				'user' => isset($settings['updraft_ftp_login']) ? $settings['updraft_ftp_login'] : '',
				'pass' => isset($settings['updraft_ftp_pass']) ? $settings['updraft_ftp_pass'] : '',
				'path' => isset($settings['updraft_ftp_remote_path']) ? $settings['updraft_ftp_remote_path'] : '',
				'passive' => true,
			);
		}

		foreach ($legacy_keys as $key) unset($settings[$key]);
		return $settings;
	}

}
