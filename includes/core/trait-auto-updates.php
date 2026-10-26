<?php

/** Automatic update settings for the plugin and WordPress core. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Core_Auto_Updates {

	/**
	 * Enables automatic updates for the plugin.
	 *
	 * @access public
	 * @see __construct
	 * @internal uses auto_update_plugin filter
	 *
	 * @param Bool   $update Whether the item has automatic updates enabled
	 * @param Object $item   Object holding the asset to be updated
	 * @return bool True of automatic updates enabled, false if not
	 */
	public function maybe_auto_update_plugin( $update, $item ) {
		if ( ! isset( $item->plugin ) || BACKUPPLUS_PLUGIN_SLUG !== $item->plugin ) {
			return $update;
		}
		$option_auto_update_settings = (array) get_site_option( 'auto_update_plugins', array() );
		return in_array( $item->plugin, $option_auto_update_settings, true );
	}

	/**
	 * Maintain compatibility on all versions between WordPress and BackupPlus, specifically since WordPress 5.5
	 */
	public function wordpress_55_updates_potential_migration() {
		// Due to the new WP's auto-updates interface in WordPress version 5.5, we need to maintain the auto update compatibility on all versions of WordPress and BackupPlus
		$udp_saved_version       = BackupPlus_Options::get_backupplus_option( 'backupplus_version' );
		$backupplus_auto_updates = BackupPlus_Options::get_backupplus_option( 'backupplus_auto_updates' );
		if ( ! $udp_saved_version || version_compare( $udp_saved_version, '1.16.34', '<=' ) || ( version_compare( $udp_saved_version, '2.0.0', '>=' ) && version_compare( $udp_saved_version, '2.16.34', '<=' ) ) || null !== $backupplus_auto_updates ) {
			$this->replace_auto_updates_option();
		}
	}

	/**
	 * Remove the use of backupplus_auto_updates option/meta (single & multisite) and replace it with auto_update_plugins site option that is used in WordPress's core since version 5.5
	 * This needs to be done in order to maintain auto-updates compatibility between WordPress and BackupPlus and to synchronise the auto-updates setting for both
	 */
	private function replace_auto_updates_option() {
		$old_setting_value = BackupPlus_Options::get_backupplus_option( 'backupplus_auto_updates' );
		BackupPlus_Options::delete_backupplus_option( 'backupplus_auto_updates' );
		$new_setting_value = (array) get_site_option( 'auto_update_plugins', array() );
		if ( ! empty( $old_setting_value ) ) {
			$new_setting_value[] = BACKUPPLUS_PLUGIN_SLUG;
		}
		$new_setting_value = array_unique( $new_setting_value );
		update_site_option( 'auto_update_plugins', $new_setting_value );
	}

	/**
	 * Set the plugin's automatic updates setting to either on or off by removing/adding plugin basename from/into the auto_update_plugins option
	 *
	 * @param Mixed $value The new value which auto_update_plugins option value is replaced with
	 */
	public function set_automatic_updates( $value ) {
		$auto_update_plugins = (array) get_site_option( 'auto_update_plugins', array() );
		if ( ! empty( $value ) ) {
			$auto_update_plugins[] = BACKUPPLUS_PLUGIN_SLUG;
			$auto_update_plugins   = array_unique( $auto_update_plugins );
		} else {
			$auto_update_plugins = array_diff( $auto_update_plugins, array( BACKUPPLUS_PLUGIN_SLUG ) );
		}
		update_site_option( 'auto_update_plugins', $auto_update_plugins );
	}

	/**
	 * Check whether the automatic-updates is set for BackupPlus
	 *
	 * @return Boolean True if set, false otherwise
	 */
	public function is_automatic_updating_enabled() {
		$auto_update_plugins = (array) get_site_option( 'auto_update_plugins', array() );
		return in_array( BACKUPPLUS_PLUGIN_SLUG, $auto_update_plugins, true );
	}
}
