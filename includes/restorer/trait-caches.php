<?php

/** Clearing caches of WordPress and of third-party plugins after a restore. */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

trait BackupPlus_Restorer_Caches {

	/**
	 * First added in UD 1.9.47.
	 */
	private function clear_caches() {
		global $backupplus;

		// Functions called here need to not assume that the relevant plugin actually exists - they should check for any functions they intend to call, before calling them.
		$methods = array(
			'clear_cache_wpsupercache',
			'clear_avada_fusion_cache', // avada theme with its theme engine called fusion
			'clear_elementor_cache',
			'clear_divi_cache',
		);

		foreach ( $methods as $method ) {
			try {
				call_user_func( array( $this, $method ) );
			} catch ( Exception $e ) {
				$log_message = 'Exception (' . get_class( $e ) . ") occurred when cleaning up third-party cache ($method) during post-restore: " . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
				BackupPlus_Manipulation_Functions::error_log( $log_message );
				$backupplus->log( $log_message );
			} catch ( Error $e ) { // phpcs:ignore PHPCompatibility.Classes.NewClasses.errorFound -- The Error class does not exist in PHP below 5.6.
				$log_message = 'Error (' . get_class( $e ) . ") occurred when cleaning up third-party cache ($method) during post-restore: " . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
				BackupPlus_Manipulation_Functions::error_log( $log_message );
				$backupplus->log( $log_message );
			}
		}

		// It should be harmless to just purge the standard directory anyway (it's not backed up by default), and any others from other plugins
		$cache_sub_directories = array( 'cache', 'wphb-cache', 'endurance-page-cache' );
		foreach ( $cache_sub_directories as $sub_dir ) {
			if ( ! is_dir( WP_CONTENT_DIR . '/' . $sub_dir ) ) {
				continue;
			}
			$backupplus->log( 'Purging cache directory: ' . WP_CONTENT_DIR . '/' . $sub_dir );
			BackupPlus_Filesystem_Functions::remove_local_directory( WP_CONTENT_DIR . '/' . $sub_dir, true );
		}
	}

	/**
	 * Clear cached Fusion-theme's Dynamic CSS
	 */
	private function clear_avada_fusion_cache() {
		global $backupplus;
		$upload_dir     = $backupplus->wp_upload_dir();
		$fusion_css_dir = realpath( $upload_dir['basedir'] ) . DIRECTORY_SEPARATOR . 'fusion-styles';
		if ( is_dir( $fusion_css_dir ) ) {
			$backupplus->log( "Avada/Fusion's dynamic CSS folder exists, and will be emptied (so that it will be automatically regenerated)" );
			BackupPlus_Filesystem_Functions::remove_local_directory( $fusion_css_dir, true );
		}
	}

	/**
	 * Clear cached Divi Theme Dynamic CSS
	 */
	private function clear_divi_cache() {
		global $backupplus;
		$clear_cache    = true;
		$divi_cache_dir = ( defined( 'ET_CORE_CACHE_DIR' ) ) ? ET_CORE_CACHE_DIR : WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'et-cache';
		if ( apply_filters( 'backupplus_divi_direct_clear_cache', $clear_cache ) && class_exists( 'ET_Core_PageResource' ) && method_exists( 'ET_Core_PageResource', 'remove_static_resources' ) ) {
			$backupplus->log( "Divi's clear cache method exists and will be executed" );
			ET_Core_PageResource::remove_static_resources( 'all', 'all' );
			$clear_cache = false;
		}
		if ( apply_filters( 'backupplus_divi_manual_clear_cache', $clear_cache ) && is_dir( $divi_cache_dir ) ) {
			$backupplus->log( "Divi's cache directory exists, and will be emptied (so that it will be automatically regenerated)" );
			BackupPlus_Filesystem_Functions::remove_local_directory( $divi_cache_dir, true );
		}
	}

	/**
	 * Clear cached Elementor styles that are saved in CSS files in the uploads folder. Clearing the caches will regenerate new CSS files, according to the most recent settings.
	 */
	private function clear_elementor_cache() {
		global $backupplus;
		$cache_uncleared = true;
		if ( apply_filters( 'backupplus_elementor_direct_clear_cache', $cache_uncleared ) ) {
			if ( class_exists( '\Elementor\Plugin' ) ) {
				class_alias( '\Elementor\Plugin', 'BackupPlus_Elementor_Plugin' ); // phpcs:ignore PHPCompatibility.FunctionUse.NewFunctions.class_aliasFound -- Ignore class_alias function.
			}           if ( class_exists( 'BackupPlus_Elementor_Plugin' ) && isset( BackupPlus_Elementor_Plugin::$instance ) && isset( BackupPlus_Elementor_Plugin::$instance->files_manager ) && is_object( BackupPlus_Elementor_Plugin::$instance->files_manager ) && method_exists( BackupPlus_Elementor_Plugin::$instance->files_manager, 'clear_cache' ) ) {
				$backupplus->log( "Elementor's clear cache method exists and will be executed" );
				BackupPlus_Elementor_Plugin::$instance->files_manager->clear_cache();
				$cache_uncleared = false;
			}
		}
		if ( apply_filters( 'backupplus_elementor_manual_clear_cache', $cache_uncleared ) ) {
			$upload_dir        = $backupplus->wp_upload_dir();
			$elementor_css_dir = realpath( $upload_dir['basedir'] ) . DIRECTORY_SEPARATOR . 'elementor' . DIRECTORY_SEPARATOR . 'css';
			if ( is_dir( $elementor_css_dir ) ) {
				$backupplus->log( "Elementor's CSS directory exists, and will be emptied (so that it will be automatically regenerated)" );
				BackupPlus_Filesystem_Functions::remove_local_directory( $elementor_css_dir, true );
			}
			// deleting only the Elementor's CSS files won't make Elementor regenerate the new CSS files the next time the website is loaded/visited again
			// Elementor will regenerate the files only if these meta and options get deleted as well
			delete_post_meta_by_key( '_elementor_css' );
			delete_option( '_elementor_global_css' );
			delete_option( 'elementor-custom-breakpoints-files' );
		}
	}

	/**
	 * Adapted from wp_cache_clean_cache($file_prefix, $all = false) in WP Super Cache (wp-cache.php)
	 *
	 * @return boolean
	 */
	private function clear_cache_wpsupercache() {
		$all = true;

		global $backupplus, $cache_path, $wp_cache_object_cache;

		if ( $wp_cache_object_cache && function_exists( 'reset_oc_version' ) ) {
			reset_oc_version();
		}

		// Removed check: && wpsupercache_site_admin()
		if ( true == $all && function_exists( 'prune_super_cache' ) ) {
			if ( ! empty( $cache_path ) ) {
				$backupplus->log_e( 'Clearing cached pages (%s)...', 'WP Super Cache' );
				prune_super_cache( $cache_path, true );
			}
			return true;
		}
	}
}
