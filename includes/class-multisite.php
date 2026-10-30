<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

/**
 * WordPress multisite. BackupPlus is network-activated and keeps one configuration for the whole network, in the main site's
 * options table. Super administrators manage it from Network Admin, and every backup, restore and scheduled event runs in the
 * main site's context; the other sites of the network have no BackupPlus screens or events of their own.
 */
class BackupPlus_Multisite {

	public static function register() {
		if ( ! is_multisite() ) {
			return;
		}
		add_filter( 'option_page_capability_backupplus-options-group', array( __CLASS__, 'capability' ), 1 );
		add_filter( 'schedule_event', array( __CLASS__, 'schedule_event' ), 1 );
		add_action( 'init', array( __CLASS__, 'clear_secondary_events' ), 0 );
		add_action( 'network_admin_menu', array( 'BackupPlus_Options', 'add_admin_pages' ) );
		add_action( 'network_admin_edit_backupplus', array( 'BackupPlus_Options', 'network_save' ) );
		add_action( 'all_admin_notices', array( __CLASS__, 'network_activation_notice' ) );
	}

	/** Only super administrators can manage a network's backups: they include every site's data and passwords. */
	public static function capability() {
		return 'manage_network_options';
	}

	/** Whether this request runs on a site of the network other than its main site. */
	public static function secondary() {
		return is_multisite() && ! is_main_site();
	}

	public static function main_site_id() {
		return function_exists( 'get_main_site_id' ) ? (int) get_main_site_id() : (int) get_current_site()->blog_id;
	}

	public static function network_active() {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active_for_network( BACKUPPLUS_PLUGIN_SLUG );
	}

	/** Whether BackupPlus screens, actions and events are available in this request. */
	public static function available() {
		return ! is_multisite() || ( self::network_active() && ! self::secondary() );
	}

	/** Run a callback with the main site as the current site. */
	public static function on_main_site( $callback ) {
		if ( ! self::secondary() ) {
			return call_user_func( $callback );
		}
		switch_to_blog( self::main_site_id() );
		try {
			return call_user_func( $callback );
		} finally {
			restore_current_blog();
		}
	}

	/** Activation hook: a network can only be backed up as a whole, so activating BackupPlus for a single site is refused. */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && ! $network_wide ) {
			wp_die(
				esc_html__( 'BackupPlus backs up and restores a WordPress network as a whole, so it can only be activated for the entire network. Activate it from Network Admin → Plugins.', 'backupplus' ),
				esc_html__( 'Plugin could not be activated', 'backupplus' ),
				array( 'back_link' => true )
			);
		}
	}

	/** BackupPlus events never go into the cron schedules of the network's other sites. */
	public static function schedule_event( $event ) {
		if ( is_object( $event ) && isset( $event->hook ) && 0 === strpos( $event->hook, 'backupplus_' ) && self::secondary() ) {
			return false;
		}
		return $event;
	}

	/**
	 * Remove BackupPlus events from the cron schedule of a site that is not the main site. Sites configured before BackupPlus
	 * managed networks as a whole, or while it was activated per site, may still have them; this runs before WP-Cron does.
	 */
	public static function clear_secondary_events() {
		if ( ! self::secondary() || ! function_exists( '_get_cron_array' ) ) {
			return;
		}
		$crons = _get_cron_array();
		if ( ! is_array( $crons ) ) {
			return;
		}
		foreach ( $crons as $timestamp => $hooks ) {
			foreach ( (array) $hooks as $hook => $events ) {
				if ( 0 !== strpos( $hook, 'backupplus_' ) ) {
					continue;
				}
				foreach ( (array) $events as $event ) {
					wp_unschedule_event( $timestamp, $hook, isset( $event['args'] ) ? $event['args'] : array() );
				}
			}
		}
	}

	/** Shown on the sites where BackupPlus was activated individually, which is no longer supported. */
	public static function network_activation_notice() {
		if ( self::network_active() || ! current_user_can( 'manage_network_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>BackupPlus:</strong> ' .
			esc_html__( 'On a WordPress network, BackupPlus must be activated for the entire network. It backs up and restores every site of the network, and is managed from Network Admin.', 'backupplus' ) .
			' <a href="' . esc_url( network_admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Go to Network Admin → Plugins', 'backupplus' ) . '</a></p></div>';
	}

	/**
	 * The network's sites, for choosing what to restore.
	 *
	 * @return Array - blog ID => array with the site's 'url' and 'name'
	 */
	public static function sites() {
		$sites = array();
		foreach ( get_sites(
			array(
				'number'     => 0,
				'network_id' => get_current_network_id(),
				'orderby'    => 'id',
			)
		) as $site ) {
			$sites[ (int) $site->blog_id ] = array(
				'url'  => untrailingslashit( $site->domain . $site->path ),
				'name' => $site->blogname,
			);
		}
		return $sites;
	}
}
