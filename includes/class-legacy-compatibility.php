<?php
/**
 * Migrate the fork's former option and cron identifiers to the BackupPlus namespace.
 */
if (!defined('ABSPATH')) die('No direct access allowed');

class BackupPlus_Legacy_Compatibility {
	const MIGRATION_OPTION = 'backupplus_legacy_identifier_migration';
	const MIGRATION_VERSION = 3;

	/**
	 * Run the identifier migration once per site.
	 *
	 * Old option names are read only here; settings-file compatibility is handled
	 * by BackupPlus_Settings_Importer.
	 *
	 * @return void
	 */
	public static function migrate() {
		if ((int) get_option(self::MIGRATION_OPTION, 0) >= self::MIGRATION_VERSION) return;

		self::migrate_options();
		self::migrate_network_options();
		self::migrate_cron_events();
		self::migrate_resume_transient();
		self::delete_removed_lock_admin_settings();
		self::delete_removed_remote_transfer_settings();

		update_option(self::MIGRATION_OPTION, self::MIGRATION_VERSION, false);
	}

	/**
	 * Remove the obsolete single-admin password lock option.
	 *
	 * @return void
	 */
	private static function delete_removed_lock_admin_settings() {
		delete_option('backupplus_adminlocking');
		delete_option('updraft_adminlocking');
		if (is_multisite()) {
			delete_site_option('backupplus_adminlocking');
			delete_site_option('updraft_adminlocking');
		}
	}

	/**
	 * Delete settings and schedules left by the removed direct-transfer feature.
	 *
	 * @return void
	 */
	private static function delete_removed_remote_transfer_settings() {
		$keys = array('backupplus_remotesend', 'backupplus_remotesites', 'backupplus_migrator_localkeys');
		foreach ($keys as $key) {
			delete_option($key);
			if (is_multisite()) delete_site_option($key);
		}

		wp_clear_scheduled_hook('backupplus_migration_token_cleanup');
	}

	/**
	 * Rename legacy plugin options without overwriting any BackupPlus values.
	 *
	 * @return void
	 */
	private static function migrate_options() {
		global $wpdb;
		if (!isset($wpdb->options)) return;

		$like_updraft = $wpdb->esc_like('updraft_').'%';
		$like_updraftplus = $wpdb->esc_like('updraftplus_').'%';
		$names = $wpdb->get_col($wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$like_updraft,
			$like_updraftplus
		));

		if (!is_array($names)) return;
		foreach ($names as $old_name) {
			if (!is_string($old_name) || '' === $old_name) continue;
			if (self::is_removed_feature_option($old_name)) {
				delete_option($old_name);
				continue;
			}

			$new_name = self::renamed_identifier($old_name);
			$value = get_option($old_name, null);
			if (null === $value) continue;
			if (false !== strpos($old_name, 'jobdata_') && is_array($value)) $value = self::rename_nested_keys($value);

			$missing = new stdClass();
			if ($missing === get_option($new_name, $missing)) add_option($new_name, $value, '', 'no');
			delete_option($old_name);
		}
	}

	/**
	 * Rename network-wide plugin options on multisite installations.
	 *
	 * @return void
	 */
	private static function migrate_network_options() {
		if (!is_multisite()) return;
		global $wpdb;
		if (!isset($wpdb->sitemeta)) return;

		$like_updraft = $wpdb->esc_like('updraft_').'%';
		$like_updraftplus = $wpdb->esc_like('updraftplus_').'%';
		$names = $wpdb->get_col($wpdb->prepare(
			"SELECT meta_key FROM {$wpdb->sitemeta} WHERE site_id = %d AND (meta_key LIKE %s OR meta_key LIKE %s)",
			get_current_network_id(),
			$like_updraft,
			$like_updraftplus
		));

		if (!is_array($names)) return;
		foreach ($names as $old_name) {
			if (!is_string($old_name) || '' === $old_name) continue;
			if (self::is_removed_feature_option($old_name)) {
				delete_site_option($old_name);
				continue;
			}

			$new_name = self::renamed_identifier($old_name);
			$value = get_site_option($old_name, null);
			if (null === $value) continue;
			if (false !== strpos($old_name, 'jobdata_') && is_array($value)) $value = self::rename_nested_keys($value);
			$missing = new stdClass();
			if ($missing === get_site_option($new_name, $missing)) add_site_option($new_name, $value);
			delete_site_option($old_name);
		}
	}

	/**
	 * Move scheduled BackupPlus work from the prior hook names.
	 *
	 * @return void
	 */
	private static function migrate_cron_events() {
		if (!function_exists('_get_cron_array') || !function_exists('wp_schedule_event')) return;

		$hook_map = array(
				'updraft_backup' => 'backupplus_backup',
				'updraft_backup_database' => 'backupplus_backup_database',
				'updraft_backup_increments' => 'backupplus_backup_increments',
				'updraft_backup_resume' => 'backupplus_backup_resume',
		);
		$cron = _get_cron_array();
		if (!is_array($cron)) return;

		foreach ($cron as $timestamp => $hooks) {
			foreach ($hook_map as $old_hook => $new_hook) {
				if (empty($hooks[$old_hook]) || !is_array($hooks[$old_hook])) continue;
				foreach ($hooks[$old_hook] as $event) {
					$args = isset($event['args']) && is_array($event['args']) ? $event['args'] : array();
					$scheduled = false;
					if (!empty($event['schedule'])) {
						$scheduled = wp_schedule_event((int) $timestamp, $event['schedule'], $new_hook, $args, true);
					} else {
						$scheduled = wp_schedule_single_event((int) $timestamp, $new_hook, $args, true);
					}

					if (true === $scheduled || is_wp_error($scheduled) && false !== wp_next_scheduled($new_hook, $args)) {
						wp_unschedule_event((int) $timestamp, $old_hook, $args);
					}
				}
			}
		}
	}

	/**
	 * Transfer the only legacy resume transient still read by the scheduler.
	 *
	 * @return void
	 */
	private static function migrate_resume_transient() {
		$old_value = get_site_transient('updraft_initial_resume_interval');
		if (false === $old_value) return;
		if (false === get_site_transient('backupplus_initial_resume_interval')) set_site_transient('backupplus_initial_resume_interval', $old_value);
		delete_site_transient('updraft_initial_resume_interval');
	}

	/**
	 * Identify settings for services removed from this fork.
	 *
	 * @param string $key The option or site-option key.
	 * @return bool
	 */
	private static function is_removed_feature_option($key) {
		return (bool) preg_match('/^(?:updraft_updraftvault|updraft_remotesend|updraft_remotesites|updraft_migrator_localkeys|updraft_central_localkeys|updraftvault_|updraftcentral_|updraftplus_clone_)/', $key);
	}

	/**
	 * Convert an old plugin option or hook prefix to BackupPlus.
	 *
	 * @param string $identifier Old identifier.
	 * @return string
	 */
	private static function renamed_identifier($identifier) {
		if (0 === strpos($identifier, 'updraftplus_')) return 'backupplus_'.substr($identifier, strlen('updraftplus_'));
		if (0 === strpos($identifier, 'updraft_')) return 'backupplus_'.substr($identifier, strlen('updraft_'));
		return $identifier;
	}

	/**
	 * Rename old keys inside an in-progress job record while leaving values intact.
	 *
	 * @param array $value Job data.
	 * @return array
	 */
	private static function rename_nested_keys($value) {
		$renamed = array();
		foreach ($value as $key => $item) {
			$new_key = is_string($key) ? self::renamed_identifier($key) : $key;
			$renamed[$new_key] = is_array($item) ? self::rename_nested_keys($item) : $item;
		}
		return $renamed;
	}
}
