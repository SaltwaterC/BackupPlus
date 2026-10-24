<?php
/**
 * Uninstall BackupPlus Plugin
 *
 * Executed when the plugin is uninstalled.
 * Removes all plugin-related options, schedules, and temporary data.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct $wpdb query is required for this operation.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- some query operations need to always receive the most up-to-date or actual data directly from the database, reducing the risk of serving stale information.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$deinstall_option = get_site_option( 'backupplus_deinstall_option', 'no' );

/**
 * Respect user preference (if set to "no", keep data)
 */
if ( 'no' === $deinstall_option ) {
	return;
}

$class_backupplus_file_path = plugin_dir_path( __FILE__ ) . 'class-backupplus.php';

if ( ! is_file( $class_backupplus_file_path ) ) {
	return;
}

require_once $class_backupplus_file_path;
// The plugin bootstrap is not loaded during uninstall.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-sql.php';

if ( ! function_exists( 'backupplus_uninstall_clear_options_transients' ) ) {
	/**
	 * Clear options and transients from the option table
	 */
	function backupplus_uninstall_clear_options_transients() {
		global $udp_settings_keys, $wpdb;
		$sql = BackupPlus_SQL::wpdb();
		$sql->execute( 'DELETE FROM %i WHERE option_name IN (?)', array( $wpdb->options ), array( array_merge( $udp_settings_keys['options'], $udp_settings_keys['meta'] ) ) );

		foreach ( $udp_settings_keys['prefixed_options'] as $pattern ) {
			$sql->execute( 'DELETE FROM %i WHERE option_name LIKE ?', array( $wpdb->options ), array( $pattern ) );
		}

		foreach ( $udp_settings_keys['transients'] as $pattern ) {
			$sql->execute( 'DELETE FROM %i WHERE option_name = ? OR option_name = ? OR option_name = ? OR option_name = ?', array( $wpdb->options ), array( '_transient_' . $pattern, '_site_transient_' . $pattern, '_transient_timeout_' . $pattern, '_site_transient_timeout_' . $pattern ) );
		}

		foreach ( $udp_settings_keys['prefixed_transients'] as $pattern ) {
			$sql->execute( 'DELETE FROM %i WHERE option_name LIKE ? OR option_name LIKE ? OR option_name LIKE ? OR option_name LIKE ?', array( $wpdb->options ), array( '_transient_' . $pattern, '_site_transient_' . $pattern, '_transient_timeout_' . $pattern, '_site_transient_timeout_' . $pattern ) );
		}
	}
}

global $wpdb, $udp_settings_keys;

$udp_settings_keys = BackupPlus::get_system_identifiers_list();

backupplus_uninstall_clear_options_transients();

/**
 * If multisite: delete site-wide (network) options and subsite-specific ones
 */
if ( is_multisite() ) {

	// Delete options stored in wp_sitemeta
	array_walk( $udp_settings_keys['meta'], 'delete_site_option' );

	// remove any data in the sitemeta table that has meta_key prefixed with "backupplus_" (e.g. backupplus_options, backupplus_product_key, backupplus_product_keymeta, etc.)
	$sitemeta = BackupPlus_SQL::wpdb();
	foreach ( $udp_settings_keys['prefixed_options'] as $pattern ) {
		$sitemeta->execute( 'DELETE FROM %i WHERE meta_key LIKE ?', array( $wpdb->sitemeta ), array( $pattern ) );
	}

	foreach ( $udp_settings_keys['transients'] as $pattern ) {
		$sitemeta->execute( 'DELETE FROM %i WHERE meta_key = ? OR meta_key = ?', array( $wpdb->sitemeta ), array( '_site_transient_' . $pattern, '_site_transient_timeout_' . $pattern ) );
	}

	foreach ( $udp_settings_keys['prefixed_transients'] as $pattern ) {
		$sitemeta->execute( 'DELETE FROM %i WHERE meta_key LIKE ? OR meta_key LIKE ?', array( $wpdb->sitemeta ), array( '_site_transient_' . $pattern, '_site_transient_timeout_' . $pattern ) );
	}

	$main_blog_id = function_exists( 'get_main_site_id' ) ? get_main_site_id() : get_current_site()->blog_id;

	if ( function_exists( 'get_sites' ) ) {
		$subsites = get_sites(
			array(
				'fields'       => 'ids',
				'site__not_in' => array( $main_blog_id ),
			)
		);
	} else {
		$raw_sites = wp_get_sites(); // phpcs:ignore WordPress.WP.DeprecatedFunctions.wp_get_sitesFound -- wp_get_sites() is used as a fallback for WP < 4.6 legacy support only.
		$subsites  = array();
		foreach ( $raw_sites as $site ) {
			if ( (int) $site['blog_id'] !== (int) $main_blog_id ) {
				$subsites[] = $site['blog_id'];
			}
		}
	}

	foreach ( $subsites as $blog_id ) {
		switch_to_blog( $blog_id );

		backupplus_uninstall_clear_options_transients();

		restore_current_blog();
	}
}

/**
 * Clear scheduled crons
 */

foreach ( $udp_settings_keys['crons'] as $hook ) {
	wp_clear_scheduled_hook( $hook );
}

delete_site_option( 'backupplus_deinstall_option' );
